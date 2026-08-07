# Security Policy / Hardening Guide

## Important

SoloFM is a file manager with destructive operations (delete, rename, upload, chmod, optional shell). Treat it as **high risk** if exposed publicly.

## Minimum recommendations

- Keep authentication enabled in `solofm.php`:
  - `$ENABLE_AUTH = true`
  - change the default `admin` password via the in-app **Change password** flow (updates `$PASSWORD_HASH` in the same file when writable; otherwise paste the shown hash manually)
- Complete the first-run **self-rename** away from `solofm.php` (pure random or `solofm_` + random — both are accepted)
- Leave `$FM_ENABLE_TERMINAL_HERE`, `$FM_ENABLE_TERMINAL_MANUAL`, and `$FM_ENABLE_TERMINAL_ADVANCED` at **`false`** unless you fully trust the host and operators. All three can be set from **Terminal settings** in the UI (terminal icon / gear → password → writes the flags in the same PHP file) or by editing the file manually.
- Do not deploy it publicly without additional controls

## Strong recommendations (production)

- **Restrict by IP** (allowlist) at the web server
- Add **HTTP Basic Auth** at the web server level
- Serve over **HTTPS** only
- Disable directory listing in the web server
- Place the script **outside the public web root** if possible, or behind a private admin route
- Ensure PHP errors are not displayed publicly (`display_errors=Off` — SoloFM ships with this off)

## Threat model notes

- A unique filename helps reduce casual discovery but **does not prevent**:
  - URL leaks (referrers, logs, browser history, screenshots)
  - local malware reading history/autocomplete
  - targeted scanning if the attacker already knows your host/path

Use auth + network restrictions. Filename obscurity is only a thin first layer.
