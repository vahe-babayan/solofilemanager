# SoloFM

**SoloFM** is a single-file PHP file manager for small self-hosted setups (XAMPP, shared hosting, VPS, etc.). Drop one PHP file into a directory and manage files from the browser.

## Quick start

1. Copy `solofm.php` into the directory you want to manage (or into a web-accessible folder pointing at your target root).
2. Open it in the browser, e.g. `http://localhost/solofm.php`
3. On first run, SoloFM asks you to **rename** the file to a random 30–40 character name (security / obscurity).
4. Log in with the default password **`admin`**, then immediately change `$PASSWORD_HASH` at the top of the script.

### Requirements

**PHP**

- PHP **7.4+** (`declare(strict_types=1)`)
- Extensions: **`json`**, **`session`** (required for the UI/API and login)
- **`mbstring`** recommended (better text encoding handling; SoloFM degrades without it)
- Archives (compress / extract / multi-download ZIP): **`zip`** (`ZipArchive`) and/or **`phar`** (`Phar` / `PharData`)
- **`exec()`** optional — when available and not in `disable_functions`, SoloFM can use OS tools for faster copy/move/delete/compress/extract (`$FM_FILE_OPS_MODE = 'auto'`). Without `exec()`, use `'php'` mode (or leave `'auto'` and rely on PHP fallbacks)

**Filesystem**

- The web server user must be able to **read** `$ROOT_DIR` and **write** where you create/upload/rename/delete files
- First-run self-rename needs write permission on the directory that contains `solofm.php`
- Trash uses a hidden folder under root (default `.trash`); that path must be creatable/writable

**Browser & network**

- A modern browser with **JavaScript** enabled
- Default UI loads **CDN** assets (Normalize CSS, Bootstrap Icons, Google Fonts). Offline or locked-down hosts need network access to those CDNs, or you must vendor the assets yourself

**Operating system notes**

- Full feature set (especially **chmod** / Unix permissions) works best on **Linux** and similar hosts
- On **Windows** (e.g. XAMPP), file management still works; permission bits are limited by the OS, and some shell/archive helpers may differ

## Features

- Folder tree (sidebar) with bookmarks and trash
- File/folder listing with sortable columns
- Create folder/file, rename, bulk rename, duplicate
- Copy / move with progress; drag and drop
- Delete to trash (recycle) or delete forever; restore
- Compress / extract archives
- Change permissions (chmod) for folders and files, including recursive 0755 / 0644 style fixes
- Upload, download, get info, copy paths
- Configuration popup (columns, permissions display, folder-size behavior)
- Keyboard shortcuts, server info
- Optional terminal-here (disabled by default — enable only on trusted hosts)

## Configuration

Edit the config block at the top of `solofm.php`:

| Setting | Purpose |
|---------|---------|
| `$ROOT_DIR` | Root path users may browse (default: script directory) |
| `$ENABLE_AUTH` / `$PASSWORD_HASH` | Session login (keep auth on) |
| `$FM_FILE_OPS_MODE` | `'auto'`, `'php'`, or `'os'` for heavy file operations |
| `$FM_VERBOSE_PROGRESS_MIN_ITEMS` | When to show detailed progress for large jobs |
| `$FM_TRASH_BASENAME` | Hidden recycle folder under root (default `.trash`) |
| `$FM_ENABLE_TERMINAL_*` | Terminal features (all **false** by default) |

Generate a new password hash with:

```php
echo password_hash('your-strong-password', PASSWORD_DEFAULT);
```

## Security

SoloFM can create, rename, and delete files. Treat it as **high risk** if exposed on the public internet.

- Keep `$ENABLE_AUTH = true` and set your own `$PASSWORD_HASH`
- Do not rely on the random filename alone
- Prefer HTTPS, IP allowlists, and/or HTTP Basic Auth at the web server

See [SECURITY.md](SECURITY.md) for more.

## License

MIT — see [LICENSE](LICENSE).
