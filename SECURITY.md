# Security Policy / Hardening Guide

## Important

SoloFileManager is a file manager with destructive operations (delete, rename, upload, chmod, optional shell). Treat it as **high risk** if exposed publicly.

## Built-in protections

- **CSRF protection**: every state-changing request (POST) must carry a per-session token, so other websites cannot trigger actions through a logged-in browser. Pages opened before an update need a reload.
- **Session cookie** is `HttpOnly` and `SameSite=Lax`, and `Secure` when served over HTTPS.
- **Login delay** of ~200 ms on wrong passwords (a light brute-force slowdown — still use IP restrictions / Basic Auth for real protection).

## Minimum recommendations

- Keep authentication enabled in `solofilemanager.php`:
  - `$ENABLE_AUTH = true`
  - change the default `admin` password via the in-app **Change password** flow (updates `$PASSWORD_HASH` in the same file when writable; otherwise paste the shown hash manually)
- Complete the first-run **self-rename** away from `solofilemanager.php` (pure random or `solofilemanager_` + random — both are accepted)
- Leave `$FM_ENABLE_TERMINAL_HERE`, `$FM_ENABLE_TERMINAL_MANUAL`, and `$FM_ENABLE_TERMINAL_ADVANCED` at **`false`** unless you fully trust the host and operators. All three can be set from **Terminal settings** in the UI (terminal icon / gear → password → writes the flags in the same PHP file) or by editing the file manually.
- When you finish using SoloFileManager, **empty Trash** or delete the trash folder (default `$FM_TRASH_BASENAME` / `.trash`) so deleted files are not left on disk
- Do not deploy it publicly without additional controls

## Strong recommendations (production)

- **Restrict by IP** (allowlist) at the web server
- Add **HTTP Basic Auth** at the web server level
- Serve over **HTTPS** only (this also turns on the `Secure` flag for the session cookie)
- Disable directory listing in the web server
- Place the script **outside the public web root** if possible, or behind a private admin route
- Ensure PHP errors are not displayed publicly (`display_errors=Off` — SoloFileManager ships with this off)

## Threat model notes

- A unique filename helps reduce casual discovery but **does not prevent**:
  - URL leaks (referrers, logs, browser history, screenshots)
  - local malware reading history/autocomplete
  - targeted scanning if the attacker already knows your host/path

Use auth + network restrictions. Filename obscurity is only a thin first layer.
