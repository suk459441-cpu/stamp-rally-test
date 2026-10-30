# Security Review

## Findings

| # | Severity | File | Lines | Vulnerability | Confidence |
|---|----------|------|-------|---------------|------------|
| 1 | HIGH | `.env` | 2-14 | Exment API and LINE authentication credentials are stored in plaintext. Although the file is Git-ignored, it may be exposed through backups, archives, deployment packages, or shared workspaces. | 10/10 |
| 2 | MEDIUM | `src/Services/Auth/LineUserIdResolver.php` | 12-14 | PHP session cookies are started without explicitly enforcing `Secure`, `HttpOnly`, and `SameSite` attributes. This can expose authenticated sessions over HTTP or to same-origin scripts. | 9/10 |

## Recommended actions

1. Rotate all credentials currently present in `.env`.
2. Store secrets in a deployment secret manager or protected server configuration.
3. Ensure deployment artifacts exclude `.env` and other local secret files.
4. Configure session cookies with `Secure`, `HttpOnly`, and an appropriate `SameSite` value before calling `session_start()`.
5. Enforce HTTPS for the production site and redirect HTTP requests.

## Verified protections

- LINE OAuth state and nonce validation is implemented.
- LINE ID token verification and session ID regeneration are implemented.
- Mutating API endpoints check the request origin.
- Stamp tokens are validated server-side.
- `.env` is not tracked by Git.

## Scope and limitations

This review covers the application source and local configuration available in the repository. It does not verify production server configuration, secret rotation status, hosting access controls, or external service permissions.
