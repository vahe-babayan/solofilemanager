# Security Policy / Hardening Guide

## Important

SoloFileManager is a file manager with destructive operations (delete, rename, upload, chmod, optional shell). Treat it as **high risk** if exposed publicly.

## Built-in protections

- **CSRF protection**: every state-changing request (POST) must carry a per-session token, so other websites cannot trigger actions through a logged-in browser. Pages opened before an update need a reload.
- **Session cookie** is `HttpOnly` and `SameSite=Lax`, and `Secure` when served over HTTPS.
- **Login delay** of ~200 ms on wrong passwords (a light brute-force slowdown — still use IP restrictions / Basic Auth for real protection).
- **Trash not reachable by URL**: the trash folder (default `.trash`) gets an `.htaccess` that denies all requests (Apache 2.2 and 2.4) plus empty `index.html` files. Restored paths are validated so an item can only go back inside the root folder.
- **Auth off guard**: with `$ENABLE_AUTH = false`, the header shows a permanent warning, Server info shows "Authentication: Off", and terminal modes cannot be enabled from the UI — so a leaked URL cannot be used to switch on command execution. Turning terminal modes off still works; enabling them without auth requires editing the PHP file.

## Minimum recommendations

- Keep authentication enabled in `solofilemanager.php`:
  - `$ENABLE_AUTH = true`
  - change the default `admin` password via the in-app **Change password** flow (updates `$PASSWORD_HASH` in the same file when writable; otherwise paste the shown hash manually)
- Complete the first-run **self-rename** away from `solofilemanager.php` (pure random or `solofilemanager_` + random — both are accepted)
- Leave `$FM_ENABLE_TERMINAL_HERE`, `$FM_ENABLE_TERMINAL_MANUAL`, and `$FM_ENABLE_TERMINAL_ADVANCED` at **`false`** unless you fully trust the host and operators. All three can be set from **Terminal settings** in the UI (terminal icon / gear → password → writes the flags in the same PHP file) or by editing the file manually.
- When you finish using SoloFileManager, **empty Trash** so deleted files are not left on disk (the Trash button shows the item count, Logout offers "Empty Trash and log out", and the trash folder `$FM_TRASH_BASENAME` / `.trash` disappears once it is empty)
- Do not deploy it publicly without additional controls

## Strong recommendations (production)

- **Restrict by IP** (allowlist) at the web server
- Add **HTTP Basic Auth** at the web server level
- Serve over **HTTPS** only (this also turns on the `Secure` flag for the session cookie)
- Disable directory listing in the web server
- On **nginx** or **IIS** (which ignore `.htaccess`), deny the trash folder yourself when the root folder is inside the web root, e.g. nginx `location ~ /\.trash(/|$) { deny all; }`; or deny all dot-folders
- Place the script **outside the public web root** if possible, or behind a private admin route
- Ensure PHP errors are not displayed publicly (`display_errors=Off` — SoloFileManager ships with this off)

## Threat model notes

- A unique filename helps reduce casual discovery but **does not prevent**:
  - URL leaks (referrers, logs, browser history, screenshots)
  - local malware reading history/autocomplete
  - targeted scanning if the attacker already knows your host/path

Use auth + network restrictions. Filename obscurity is only a thin first layer.
