#!/usr/bin/env bash
# usebridge-cd.sh <prod|staging> — GitHub Actions side of Bridge CD (called by .github/workflows/usebridge-*.yml).
#
# Talks to xerxes only through the deploy key's forced command (/usr/local/sbin/usebridge-release-ssh), which runs
# `sudo -n /usr/local/sbin/usebridge-release <env> <action> ...` with validated args. No shell on xerxes.
#   preflight <sha> -> @@NOOP=1: smoke only (already live = success)
#   test <sha>      -> exit 6 = tests failed, nothing deployed
#   deploy <sha> --expect-current <@@CUR> --prune
#   external smoke + postcheck --mark <@@LOGMARK>; smoke failure or @@LOG_ERRORS != 0 -> rollback, smoke, fail.
# Never passes --skip-tests or --force-redeploy. Migrations are never rolled back (the core does not do that).
#
# Env: GITHUB_SHA, GITHUB_REF, USEBRIDGE_DEPLOY_SSH_KEY; staging also USEBRIDGE_STAGING_BASIC_AUTH_USER / _PASS.
# Secrets go to 0600 files under a private temp dir (removed on exit); never on argv, never printed.
set -euo pipefail

XERXES_IP=157.180.117.80
XERXES_PORT=2244
XERXES_USER=usebridge-deploy
REPO=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
KNOWN_HOSTS=$REPO/.github/ssh/xerxes_known_hosts
SHA_RE='^[0-9a-f]{40}$'
MARK_RE='^[0-9]+(:laravel-[0-9A-Za-z_.-]+\.log=[0-9]+)*$'

ENV=${1:-}
case $ENV in
  prod)    BRANCH=main;        HOST=usebridge.si ;;
  staging) BRANCH=development; HOST=stage.usebridge.si ;;
  *) echo "usage: usebridge-cd.sh <prod|staging>" >&2; exit 2 ;;
esac

fail() { echo "::error::FAIL usebridge-$ENV: $*"; exit 1; }
pass() { echo "PASS usebridge-$ENV: $*"; exit 0; }

SHA=${GITHUB_SHA:-}
[[ $SHA =~ $SHA_RE ]] || fail "GITHUB_SHA is not a 40-hex sha"
[ "${GITHUB_REF:-}" = "refs/heads/$BRANCH" ] || fail "$ENV deploys only from $BRANCH (this run is for ${GITHUB_REF:-?})"
[ -s "$KNOWN_HOSTS" ] || fail "pinned host keys missing: .github/ssh/xerxes_known_hosts"
[ -n "${USEBRIDGE_DEPLOY_SSH_KEY:-}" ] || fail "secret USEBRIDGE_DEPLOY_SSH_KEY is not set"

WORK=$(mktemp -d "${RUNNER_TEMP:-/tmp}/usebridge-cd.XXXXXX")
chmod 700 "$WORK"
trap 'rm -rf -- "$WORK"' EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

KEY=$WORK/deploy_key
( umask 077; printf '%s\n' "$USEBRIDGE_DEPLOY_SSH_KEY" > "$KEY" )
unset USEBRIDGE_DEPLOY_SSH_KEY

CURLCFG=""
if [ "$ENV" = staging ]; then
  u=${USEBRIDGE_STAGING_BASIC_AUTH_USER:-}; p=${USEBRIDGE_STAGING_BASIC_AUTH_PASS:-}
  { [ -n "$u" ] && [ -n "$p" ]; } || fail "secrets USEBRIDGE_STAGING_BASIC_AUTH_USER / USEBRIDGE_STAGING_BASIC_AUTH_PASS are not set"
  case "$u$p" in *$'\n'* | *$'\r'*) fail "staging basic auth secrets must be single-line" ;; esac
  [[ $u != *:* ]] || fail "staging basic auth user must not contain ':'"
  # curl config quoting: \ -> \\ and " -> \"
  cfg_quote() { local s=${1//\\/\\\\}; s=${s//\"/\\\"}; printf '%s' "$s"; }
  CURLCFG=$WORK/curl-auth.cfg
  ( umask 077; printf 'user = "%s:%s"\n' "$(cfg_quote "$u")" "$(cfg_quote "$p")" > "$CURLCFG" )
  unset u p USEBRIDGE_STAGING_BASIC_AUTH_USER USEBRIDGE_STAGING_BASIC_AUTH_PASS
fi

# ---------- xerxes (forced command) ----------
SSH_OPTS=(-F /dev/null -T -i "$KEY" -p "$XERXES_PORT"
  -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes
  -o UserKnownHostsFile="$KNOWN_HOSTS" -o GlobalKnownHostsFile=/dev/null -o UpdateHostKeys=no
  -o ConnectTimeout=20 -o ServerAliveInterval=30 -o ServerAliveCountMax=10 -o LogLevel=ERROR)

# show: human core lines as-is; @@ lines only from a small allowlist
show() {
  local line k
  while IFS= read -r line || [ -n "$line" ]; do
    line=${line%$'\r'}
    if [[ $line == @@* ]]; then
      k=${line#@@}; k=${k%%=*}
      case $k in
        CUR|NEW|NOOP|PREFLIGHT|TESTS|SWITCHED|LOG_ERRORS|ABORT|STATUS|ROLLED_BACK) printf '%s\n' "$line" ;;
      esac
    else
      printf '%s\n' "$line"
    fi
  done
}

# core NAME ACTION ARGS... : run usebridge-release on xerxes; full output kept in $WORK/NAME.out for kv
core() {
  local name=$1 rc tok; shift
  tok=$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')
  echo "::group::xerxes: usebridge-release $ENV $*"
  echo "::stop-commands::$tok"    # remote output (e.g. commit subjects) must not act as workflow commands
  set +e
  ssh "${SSH_OPTS[@]}" "$XERXES_USER@$XERXES_IP" "usebridge-release $ENV $*" </dev/null 2>&1 | tee "$WORK/$name.out" | show
  rc=${PIPESTATUS[0]}
  set -e
  echo "::$tok::"
  echo "(usebridge-release $ENV $1: exit $rc)"
  echo "::endgroup::"
  [ "$rc" != 255 ] || echo "ssh to $XERXES_USER@$XERXES_IP:$XERXES_PORT failed (network, host key or deploy key)"
  return "$rc"
}

# kv NAME KEY : last @@KEY=value from that core run
kv() { sed -n "s/^@@$2=//p" "$WORK/$1.out" 2>/dev/null | tr -d '\r' | tail -n 1; }

# ---------- external smoke (origin pinned to xerxes, no -v, credentials only via the 0600 -K file) ----------
RES=(--resolve "$HOST:443:$XERXES_IP" --resolve "$HOST:80:$XERXES_IP")
[ "$ENV" != prod ] || RES+=(--resolve "www.$HOST:443:$XERXES_IP")
cu()  { curl -q -sS --max-time 30 "${RES[@]}" "$@"; }
cua() { curl -q -sS --max-time 30 -K "$CURLCFG" "${RES[@]}" "$@"; }
# status_loc: "<last status> <Location>" from a header dump, with any user:pass@ removed from the Location
status_loc() {
  tr -d '\r' \
    | awk 'tolower($0) ~ /^http\//{c=$2; l=""} tolower($0) ~ /^location:/{sub(/^[^:]*:[ \t]*/,""); l=$0} END{print c" "l}' \
    | sed -E 's#^([0-9]+ [a-zA-Z][a-zA-Z0-9+.-]*://)[^/@ ]*@#\1#'
}
s_code()   { local c; c=$("$1" -o /dev/null -w '%{http_code}' "$2") || true; echo "       $2 -> ${c:-000}"; [ "$c" = "$3" ]; }
s_redir()  { local o; o=$(cu -o /dev/null -D - "$1" | status_loc) || true; echo "       $1 -> $o"; [ "$o" = "$2" ]; }
s_robots() {
  local h; h=$(cua -o /dev/null -D - "https://$HOST/") || true
  if grep -qiE '^x-robots-tag:.*noindex' <<< "$h"; then echo "       X-Robots-Tag: noindex"; else echo "       X-Robots-Tag noindex missing"; return 1; fi
}

SMOKE_OK=0; SMOKE_TOTAL=0
check() {
  local label=$1; shift
  SMOKE_TOTAL=$((SMOKE_TOTAL + 1))
  if "$@"; then SMOKE_OK=$((SMOKE_OK + 1)); echo "  ok   $label"; else echo "  FAIL $label"; fi
}
smoke() {
  SMOKE_OK=0; SMOKE_TOTAL=0
  echo "::group::smoke $HOST ($1)"
  if [ "$ENV" = prod ]; then
    check "https://$HOST/up -> 200"            s_code cu "https://$HOST/up" 200
    check "https://$HOST/ -> 200"              s_code cu "https://$HOST/" 200
    check "http://$HOST/ -> 301 https"         s_redir "http://$HOST/" "301 https://$HOST/"
    check "https://www.$HOST/ -> 301 apex"     s_redir "https://www.$HOST/" "301 https://$HOST/"
  else
    check "https://$HOST/ without auth -> 401" s_code cu  "https://$HOST/" 401
    check "https://$HOST/ with auth -> 200"    s_code cua "https://$HOST/" 200
    check "https://$HOST/up with auth -> 200"  s_code cua "https://$HOST/up" 200
    check "X-Robots-Tag contains noindex"      s_robots
    check "http://$HOST/ -> 301 https"         s_redir "http://$HOST/" "301 https://$HOST/"
  fi
  echo "::endgroup::"
  echo "smoke ($1): $SMOKE_OK/$SMOKE_TOTAL"
}
smoke_passed() { [ "$SMOKE_TOTAL" -gt 0 ] && [ "$SMOKE_OK" = "$SMOKE_TOTAL" ]; }

echo "usebridge-$ENV: ${SHA:0:7} ($BRANCH) -> $XERXES_USER@$XERXES_IP:$XERXES_PORT, host $HOST"

# ---------- 1. preflight ----------
rc=0; core pre preflight "$SHA" || rc=$?
CUR=$(kv pre CUR); NEW=$(kv pre NEW)
if [ "$rc" != 0 ] || [ "$(kv pre PREFLIGHT)" != ok ]; then
  ABORT=$(kv pre ABORT)
  case $ABORT in *"staging vhosts disabled"*)
    echo "staging is parked: the stage vhosts must be enabled on xerxes by hand first (CD never runs a2ensite)" ;;
  esac
  [ "$rc" != 4 ] || echo "layout not migrated on xerxes: the one-time --migrate-layout has to be done from the box first"
  fail "preflight refused (exit $rc): ${ABORT:-no @@ABORT}; nothing deployed"
fi
[ "$NEW" = "$SHA" ] || fail "preflight resolved ${NEW:-nothing}, expected $SHA; nothing deployed"

if [ "$(kv pre NOOP)" = 1 ]; then
  echo "${SHA:0:7} is already live: no test, no deploy; read-only smoke only"
  smoke "already live, read-only"
  smoke_passed && pass "${SHA:0:7} already live (no-op), smoke $SMOKE_OK/$SMOKE_TOTAL"
  fail "${SHA:0:7} is already live but the smoke failed ($SMOKE_OK/$SMOKE_TOTAL); nothing was changed"
fi
[[ $CUR =~ $SHA_RE ]] || fail "preflight gave no usable @@CUR; nothing deployed"

# ---------- 2. tests on xerxes ----------
rc=0; core test test "$SHA" || rc=$?
if [ "$rc" != 0 ] || [ "$(kv test TESTS)" != ok ]; then
  [ "$rc" != 6 ] || fail "tests failed for ${SHA:0:7} on xerxes (phpunit/typecheck/build); nothing deployed"
  fail "test step refused (exit $rc): $(kv test ABORT); nothing deployed"
fi

# ---------- 3. deploy ----------
rc=0; core dep deploy "$SHA" --expect-current "$CUR" --prune || rc=$?
if [ "$(kv dep SWITCHED)" != 1 ]; then
  smoke "live unchanged, read-only"
  fail "deploy stopped before the switch (exit $rc): $(kv dep ABORT); live unchanged (${CUR:0:7}); smoke $SMOKE_OK/$SMOKE_TOTAL"
fi
if [ "$rc" = 5 ] || [ -n "$(kv dep ROLLED_BACK)" ]; then
  # the core already switched back; do NOT deploy again
  smoke "after the core's automatic rollback"
  fail "${SHA:0:7} failed the local check after the switch; core rolled back to $(kv dep ROLLED_BACK | cut -c1-7); smoke $SMOKE_OK/$SMOKE_TOTAL; migrations (if any) were NOT rolled back"
fi

# ---------- 4. external smoke + server postcheck ----------
smoke "after deploy"
SMOKE_DEP="$SMOKE_OK/$SMOKE_TOTAL"
MARK=$(kv dep LOGMARK); LIVE=""; ERRS=""
if [[ $MARK =~ $MARK_RE ]]; then
  core post postcheck --mark "$MARK" || true
  LIVE=$(kv post CUR); ERRS=$(kv post LOG_ERRORS)
else
  echo "no usable @@LOGMARK from deploy; postcheck skipped (counts as failed)"
fi
echo "postcheck: live ${LIVE:0:7}, new ERROR/CRITICAL log lines: ${ERRS:-?}"

if [ -n "$LIVE" ] && [ "$LIVE" != "$SHA" ]; then
  fail "live is ${LIVE:0:7}, not ${SHA:0:7}: something else switched it; not rolling back automatically"
fi
if smoke_passed && [ "$ERRS" = 0 ]; then
  [ "$rc" = 0 ] && pass "${CUR:0:7}->${SHA:0:7} deployed, smoke $SMOKE_DEP, log errors 0"
  fail "${SHA:0:7} is live and healthy, but deploy exited $rc after the switch: $(kv dep ABORT)"
fi

# ---------- 5. rollback ----------
echo "post-deploy checks failed (smoke $SMOKE_DEP, log errors ${ERRS:-?}) -> rollback to ${CUR:0:7}"
rrc=0; core rb rollback || rrc=$?
smoke "after rollback"
if [ "$(kv rb ROLLED_BACK_TO)" = "$CUR" ]; then RBN="rolled back to ${CUR:0:7}"; else RBN="ROLLBACK FAILED (exit $rrc): $(kv rb ABORT)"; fi
fail "${SHA:0:7} failed post-deploy checks (smoke $SMOKE_DEP, log errors ${ERRS:-?}); $RBN; post-rollback smoke $SMOKE_OK/$SMOKE_TOTAL; migrations were NOT rolled back"
