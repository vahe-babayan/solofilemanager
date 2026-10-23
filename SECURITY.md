# Security Policy / Hardening Guide

## Important

SoloFileManager is a file manager with destructive operations (delete, rename, upload, chmod, optional shell). Treat it as **high risk** if exposed publicly.

## Built-in protections

- **CSRF protection**: every state-changing request (POST) must carry a per-session token, so other websites cannot trigger actions through a logged-in browser. Pages opened before an update need a reload.
- **Session cookie** is `HttpOnly` and `SameSite=Lax`, and `Secure` when served over HTTPS.
- **Separate session per installation**: each copy uses its own session cookie (`SOLOFM_` + a hash of the script path), so a login to one copy on a domain never opens another copy and FM does not share session data with other PHP apps (`PHPSESSID`). The session id is renewed on login, and a login stays valid only for the password it was made with: changing the password logs out every other open session.
- **Login delay** of ~200 ms on wrong passwords (a light brute-force slowdown — still use IP restrictions / Basic Auth for real protection).
- **Optional idle lock**: `$FM_IDLE_TIMEOUT_MINUTES` (0 = off; or 15 / 30 / 60 / 120). After that many minutes without an authenticated request, the session is cleared and the UI asks for the password again. Long file operations keep the session alive: the client timer pauses while any request is running (until its whole response, including progress streams, has arrived) and sends keepalives meanwhile; API requests release the PHP session lock right after the auth check, so those keepalives are not blocked by the running job. Change from Configuration (password) or edit the PHP file. Only applies when authentication is on.
- **Optional IP allowlist**: `$FM_ALLOWED_IPS` (empty = everyone). Single IPs and CIDR ranges, IPv4 and IPv6. Any other IP gets a plain `403 Forbidden` before sessions, the login page, the first-run rename and every API action, so a leaked URL and password are not enough from elsewhere. The client IP is the direct connection address (`REMOTE_ADDR`); `X-Forwarded-For` is used only when the request comes from an address in `$FM_TRUSTED_PROXIES`, because anyone can send that header. The Configuration UI refuses a list without your current IP; entries that are not valid IPs never match (a typo does not switch the allowlist off). Dynamic home / mobile IPs change — prefer a range, and if you are locked out, edit `$FM_ALLOWED_IPS` in the PHP file over FTP or the hosting panel. A rule in the web server is still stronger (it stops the request before PHP runs); use the built-in allowlist when you cannot change the server config.
- **Root folder boundary**: every path is resolved (`realpath`, so `..`, symlinks and junctions are followed) and must be the root folder itself or inside it; neighbouring folders with a similar name (e.g. `files-private` next to `files`) are rejected. Paths that do not exist yet (upload, create, rename targets) are judged by the real location of their nearest existing parent, and `.`, `..` or dangling links in the missing part are refused.
- **Links are not followed on walks**: folder size, file counts, PHP copy and archive building skip symlinks and Windows junctions (so a link to a parent cannot inflate size, hang, or fill the disk). Deleting a link removes only the link, not its target; copying a link recreates the link. Links whose target is outside the root are shown but cannot be opened from the UI. A link that points *inside* the root still lets the web server serve that target (and may bypass the target's own `.htaccess` if the link is under a different URL path) — treat existing links in the managed tree as part of the exposed surface.
- **Trash not reachable by URL**: the trash folder (default `.trash`) gets an `.htaccess` that denies all requests (Apache 2.2 and 2.4) plus empty `index.html` files. Restored paths are validated so an item can only go back inside the root folder.
- **Unfinished uploads not reachable by URL**: parts of a chunked upload wait in `.fm-upload-tmp` under the root, protected the same way as the trash (`.htaccess` deny + `index.html`). Each upload gets a random server-side id; the destination is validated when the upload starts and again before the file is moved into place (so a link created meanwhile cannot redirect it outside the root). Parts must continue exactly where the server's copy ends and cannot exceed the announced size. The folder is removed when empty, and uploads with no new part for 24 hours are deleted.
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

- **Restrict by IP** (allowlist) at the web server, or with the built-in `$FM_ALLOWED_IPS` when the server config is out of reach
- Add **HTTP Basic Auth** at the web server level
- Serve over **HTTPS** only (this also turns on the `Secure` flag for the session cookie)
- Disable directory listing in the web server
- On **nginx** or **IIS** (which ignore `.htaccess`), deny the trash and unfinished-upload folders yourself when the root folder is inside the web root, e.g. nginx `location ~ /\.(trash|fm-upload-tmp)(/|$) { deny all; }`; or deny all dot-folders
- Place the script **outside the public web root** if possible, or behind a private admin route
- Ensure PHP errors are not displayed publicly (`display_errors=Off` — SoloFileManager ships with this off)

## Threat model notes

- A unique filename helps reduce casual discovery but **does not prevent**:
  - URL leaks (referrers, logs, browser history, screenshots)
  - local malware reading history/autocomplete
  - targeted scanning if the attacker already knows your host/path

Use auth + network restrictions. Filename obscurity is only a thin first layer.
