# OAuth for the MCP server

Bridge's MCP server (`POST /mcp`) accepts two kinds of bearer token:

- **API tokens** (Account → API tokens & MCP), as before. Nothing changes for existing agents and clients.
- **OAuth access tokens**, for apps that only connect through "standard OAuth", such as Gemini's "Connect to an MCP
  server" dialog.

This page explains how the OAuth side works and how to connect Gemini step by step.

## Connecting Gemini, step by step

### (a) The operator creates a client on production

Gemini asks for a client ID and a client secret, so it needs a pre-registered client. In Gemini, open "Connect to an
MCP server" first and copy the **redirect URI** it shows. Then run on the production server:

```bash
php artisan bridge:mcp-oauth-client "Gemini" --redirect="<the redirect URI Gemini shows>"
```

The command prints the MCP server URL, the client ID and the client secret. The secret is shown only this once
(Bridge stores its sha256 hash), so pass it to the owner over a safe channel. If Gemini shows more than one redirect
URI, repeat `--redirect=` for each. The redirect URI must match exactly, character for character.

Other uses of the command:

```bash
php artisan bridge:mcp-oauth-client --list               # all clients (never the secrets)
php artisan bridge:mcp-oauth-client --revoke=<client id> # refuse the client and end all its connections
php artisan bridge:mcp-oauth-client "Some app" --redirect=<uri> --public   # no secret, PKCE only
```

Lost the secret? Revoke the client and create a new one.

### (b) The owner fills in Gemini's dialog

| Field in Gemini | Value |
| --- | --- |
| Server URL | `https://usebridge.si/mcp` |
| Client ID | the client ID the command printed |
| Client secret | the client secret the command printed |
| Redirect URI | shown by Gemini; it must be the one given to `--redirect` |

If Gemini asks for the endpoints instead of discovering them: authorization URL `https://usebridge.si/oauth/authorize`,
token URL `https://usebridge.si/oauth/token`, scope `mcp:use`.

### (c) The consent screen

Gemini opens Bridge in the browser. If you are not logged in, you log in as usual and land on the consent screen
afterwards. It shows the app's name ("Gemini"), your email and workspace, and the redirect URI you will be sent back
to. Choose **Approve** to connect or **Deny** to cancel. Your email address must be verified to approve.

After approval the app can read and change your projects, boards and tickets and post status updates in your
workspace (the same as an API token with `read & write`). Its writes are recorded as its own agent, named
"Gemini (OAuth)".

### (d) Revoking

- **As the user:** Account → API tokens & MCP. Every connection is listed as a token named "<app> (OAuth)". Revoke it
  there, and the app loses access at once. Connecting again asks for consent again.
- **As the operator:** `php artisan bridge:mcp-oauth-client --revoke=<client id>` refuses the client and ends every
  connection made through it, for all users.
- Disabling a user account blocks their connections too (403), exactly like their API tokens.

## Deploying

Run the migrations, nothing else:

```bash
php artisan migrate --force
```

- The migration `2026_11_19_100000_mcp_oauth` only creates three new tables (`mcp_oauth_clients`, `mcp_oauth_codes`,
  `mcp_oauth_tokens`). It does not change any existing table or row.
- There are no signing keys to generate or store. Bridge does not use Laravel Passport; tokens are random strings
  stored as sha256 hashes, like the API tokens. No new package was added.
- `APP_URL` must be the public URL (`https://usebridge.si`): every URL in the discovery documents and in the 401
  challenge is built from it. After changing it, run `php artisan config:cache` as usual.
- Optional: `BRIDGE_MCP_OAUTH_DCR=false` switches off dynamic client registration (see below). Default is on.
- The web server must pass `/.well-known/oauth-protected-resource` and `/.well-known/oauth-authorization-server`
  (and the same paths ending in `/mcp`) to Laravel. Some server configs block or special-case `/.well-known/`.

Quick check after the deploy:

```bash
curl -s https://usebridge.si/.well-known/oauth-protected-resource
curl -s https://usebridge.si/.well-known/oauth-authorization-server
curl -si -X POST https://usebridge.si/mcp | grep -i www-authenticate
```

## How it works

Bridge follows the MCP authorization spec (2025-06-18): OAuth 2.1 with the authorization code grant and PKCE.

1. **Discovery.** A request to `/mcp` without a valid token answers 401 with
   `WWW-Authenticate: Bearer realm="mcp", resource_metadata="<APP_URL>/.well-known/oauth-protected-resource"`.
   That document (RFC 9728) names `<APP_URL>/mcp` as the resource and `<APP_URL>` as the authorization server.
   `/.well-known/oauth-authorization-server` (RFC 8414) lists the endpoints below.
2. **Authorization** (`GET /oauth/authorize`). The client sends `client_id`, `redirect_uri`, `response_type=code`,
   `state` and a PKCE `code_challenge` with `code_challenge_method=S256`. `plain` and requests without PKCE are refused.
   The redirect URI must match a registered one exactly. An unknown client or redirect URI gets an error page and is
   never redirected. After Approve, the browser returns to the redirect URI with a one-time `code` (valid 5 minutes).
3. **Token** (`POST /oauth/token`). `grant_type=authorization_code` with `code`, the same `redirect_uri` and the
   `code_verifier`. Confidential clients authenticate with `client_secret_post` or `client_secret_basic`; public
   clients send only `client_id`. The answer has an `access_token` (1 hour) and a `refresh_token` (60 days).
4. **Refresh.** `grant_type=refresh_token` returns a new pair and ends the old one (rotation). If an already used
   refresh token shows up again, the whole connection is revoked. The same happens to the connection made from an
   authorization code that is presented a second time.
5. **Calling `/mcp`.** `Authorization: Bearer <access_token>`.

### What a connection is

When a code is exchanged, Bridge creates a normal API token row for the approving user (scope `read & write`, bound to
the workspace they approved in, with its own agent). Nobody ever sees that token's plaintext. OAuth access tokens
resolve to this row, so `/mcp` treats them exactly like API tokens: same workspace resolution
(`ResolveTokenWorkspace`), verified email (`RequireVerifiedEmail`), abilities per tool and the 120 requests per minute
limit. This is also why revoking works from the existing token list.

### Dynamic client registration

`POST /oauth/register` (RFC 7591) lets an MCP client register itself: `{"client_name": "...", "redirect_uris": [...]}`.
Such clients are always public (no secret, `token_endpoint_auth_method: none`) and PKCE stays required. Redirect URIs
must be `https`, `http` on localhost, or an app scheme such as `cursor://`. The consent screen marks these apps as
self-registered and not verified. The endpoint is limited to 10 registrations per minute per IP. Set
`BRIDGE_MCP_OAUTH_DCR=false` to allow only clients made with the artisan command.

### Endpoints

| Endpoint | Purpose |
| --- | --- |
| `GET /.well-known/oauth-protected-resource` (also `/mcp` suffix) | Protected resource metadata (RFC 9728) |
| `GET /.well-known/oauth-authorization-server` (also `/mcp` suffix) | Authorization server metadata (RFC 8414) |
| `GET /oauth/authorize` | Consent screen (needs a Bridge login) |
| `POST /oauth/token` | Code exchange and refresh |
| `POST /oauth/register` | Dynamic client registration |

### Limits to know about

- One scope, `mcp:use`. A connection always gets `read & write`; for a read-only bot use an API token.
- The token and registration endpoints send no CORS headers, so browser-only MCP clients cannot call them directly.
- Code: `app/Services/Auth/McpOAuth.php`, `app/Http/Controllers/Auth/McpOAuthController.php`, routes in
  `routes/ai.php` and `routes/web.php`, tests in `tests/Feature/Mcp/McpOAuthTest.php`.
