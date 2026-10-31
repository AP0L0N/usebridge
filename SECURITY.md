# Security policy

## Reporting a vulnerability

Please report security vulnerabilities **privately** by email to **support@usebridge.si**. Do not open a public GitHub
issue, pull request or discussion for a vulnerability, and do not share details publicly until a fix is released.

Please include:

- a description of the issue and its impact (for example: data of another workspace readable, token or session misuse,
  authentication bypass);
- the affected version or commit, and the endpoint, page or MCP tool involved;
- step-by-step reproduction (requests, payloads, screenshots) or a proof of concept;
- any suggested fix or mitigation;
- how you would like to be credited, if at all.

Only test against your own installation or your own account and workspace. Do not access, modify or delete other
users' data, and do not run denial-of-service or spam tests.

## What to expect

- We acknowledge your report within **3 working days**.
- We send a first assessment (confirmed or not, severity, next steps) within **10 working days**.
- We keep you updated until the issue is fixed, and we coordinate the disclosure date with you. Fixes for confirmed
  issues are released as soon as practical; critical issues come first.

## Scope

In scope: this repository (the Bridge web app, REST API, MCP server and their authentication, authorisation and
workspace isolation). Out of scope: third-party services (for example Cloudflare Turnstile or HIBP) and issues that
need a compromised device or server.
