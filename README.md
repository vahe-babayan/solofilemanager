# SoloFileManager

### A powerful single-file PHP file manager for your server.

Manage files directly from your browser — without installing WordPress, Laravel, a database, Node.js or any other framework.

### One PHP file. No installation. No database.

Current version: **1.8.1** — see [CHANGELOG.md](CHANGELOG.md)

## Why SoloFileManager?

- 🚀 Single PHP file
- 📦 No database required
- 🔧 Works on shared hosting, VPS and XAMPP
- 📁 Upload, download, copy, move and rename files
- 🔍 Find files and folders in all subfolders, by name or text inside files
- 🔤 Convert text files to UTF-8 (or another encoding), one by one or a whole folder
- 🗑️ Trash and restore
- 📦 ZIP compression and extraction
- 🔐 Built-in authentication
- 🖥️ Optional terminal
- ⚡ Fast and lightweight
- 🆓 MIT License

## Screenshots

<table>
    <tr>
        <td width="100%" colspan="2">
            <img src="./screenshots/main.png" alt="SoloFileManager: folder tree, bookmarks, file list with folder sizes and a folder link" width="100%">
            <br><sub>Folder tree, bookmarks and the file list with folder sizes, a folder link and Trash</sub>
        </td>
    </tr>
    <tr>
        <td width="50%" valign="top">
            <img src="./screenshots/find.png" alt="Find: name, text inside files, type, size and modified date, in this folder and all subfolders" width="100%">
            <br><sub>Find by name, text inside files, type, size and date</sub>
        </td>
        <td width="50%" valign="top">
            <img src="./screenshots/search-results.png" alt="Search results from several folders, each with its folder and the matching line with the text marked" width="100%">
            <br><sub>Search results from all subfolders, matching line marked</sub>
        </td>
    </tr>
    <tr>
        <td width="50%" valign="top">
            <img src="./screenshots/large-folder-pages.png" alt="Large folder shown in pages: 2,400 files, page 2 of 24" width="100%">
            <br><sub>Large folders are shown in pages</sub>
        </td>
        <td width="50%" valign="top">
            <img src="./screenshots/trash.png" alt="Trash: deleted items with original location, Restore and Delete forever" width="100%">
            <br><sub>Trash: original location, Restore and Delete forever</sub>
        </td>
    </tr>
    <tr>
        <td width="50%" valign="top">
            <img src="./screenshots/upload.png" alt="Upload: a large file sent in parts, small files done" width="100%">
            <br><sub>Upload: large files are sent in parts</sub>
        </td>
        <td width="50%" valign="top">
            <img src="./screenshots/upload-conflict.png" alt="Upload: files that already exist, Skip / Replace / Keep both" width="100%">
            <br><sub>Files that already exist: Skip, Replace or Keep both</sub>
        </td>
    </tr>
    <tr>
        <td width="50%" valign="top">
            <img src="./screenshots/copy.png" alt="Copy to folder" width="100%">
            <br><sub>Copy or move to another folder</sub>
        </td>
        <td width="50%" valign="top">
            <img src="./screenshots/bulk-rename.png" alt="Bulk rename with a regular expression and live preview" width="100%">
            <br><sub>Bulk rename with regular expressions and live preview</sub>
        </td>
    </tr>
    <tr>
        <td width="50%" valign="top">
            <img src="./screenshots/extract.png" alt="Extract / Unzip" width="100%">
            <br><sub>Extract ZIP / TAR / TAR.GZ</sub>
        </td>
        <td width="50%" valign="top">
            <img src="./screenshots/extract-unsafe-entries.png" alt="Extract: unsafe archive entries skipped and listed" width="100%">
            <br><sub>Unsafe archive entries are skipped and listed</sub>
        </td>
    </tr>
    <tr>
        <td width="50%" valign="top">
            <img src="./screenshots/permissions.png" alt="Change permissions for folders and files" width="100%">
            <br><sub>Change permissions for folders and files</sub>
        </td>
        <td width="50%" valign="top">
            <img src="./screenshots/convert-encoding.png" alt="Convert encoding: old pages in Windows-1251, KOI8-R and Windows-1252 found in a folder, shown now and after converting to UTF-8" width="100%">
            <br><sub>Convert encoding: find legacy files in a folder, preview, convert to UTF-8</sub>
        </td>
    </tr>
    <tr>
        <td width="50%" valign="top">
            <img src="./screenshots/config-display.png" alt="Configuration: Display tab with items per page" width="100%">
            <br><sub>Configuration: display settings</sub>
        </td>
        <td width="50%" valign="top">
            <img src="./screenshots/config-server.png" alt="Configuration: Server tab with file operations, idle lock, IP allowlist and trash folder name" width="100%">
            <br><sub>Configuration: file ops, idle lock, IP allowlist, Trash</sub>
        </td>
    </tr>
    <tr>
        <td width="50%" valign="top">
            <img src="./screenshots/server-info.png" alt="Server info" width="100%">
            <br><sub>Server info</sub>
        </td>
        <td width="50%" valign="top">
            <img src="./screenshots/keyboard-shortcuts.png" alt="Keyboard shortcuts" width="100%">
            <br><sub>Keyboard shortcuts</sub>
        </td>
    </tr>
    <tr>
        <td width="50%" valign="top">
            <img src="./screenshots/session-locked.png" alt="Session locked after idle time" width="100%">
            <br><sub>Session locked after idle time</sub>
        </td>
        <td width="50%" valign="top">
            <img src="./screenshots/terminal.png" alt="Terminal" width="100%">
            <br><sub>Optional terminal (off by default)</sub>
        </td>
    </tr>
</table>

## Quick start

1. Copy `solofilemanager.php` into the directory you want to manage (or into a web-accessible folder pointing at your target root).
2. Open it in the browser, e.g. `http://localhost/solofilemanager.php`
3. On first run, SoloFileManager asks you to **rename** the file. You can pick a pure random name (harder to find) or a `solofilemanager_`-prefixed name (easier to spot in the folder).
4. Log in with the default password **`admin`**. SoloFileManager then asks you to **set a new password** (new + confirm only — no need to retype `admin`). It tries to update `$PASSWORD_HASH` in the same PHP file; if the file is not writable, it shows a hash to paste manually. You can change the password later from the key icon in the header.

### Requirements

**PHP**

- PHP **7.4+** (`declare(strict_types=1)`)
- Extensions: **`json`**, **`session`** (required for the UI/API and login)
- **`mbstring`** recommended (better text encoding handling; SoloFileManager degrades without it)
- Compress / extract: **`zip`** (`ZipArchive`) and/or **`phar`** (`Phar` / `PharData`)
- Download as archive (folders / multiple items): streams through OS `zip` / `tar` via **`proc_open`** when available, otherwise a built-in PHP writer that needs **`zlib`** for ZIP and TAR.GZ (TAR works without it)
- **`exec()`** / **`proc_open()`** optional — when available and not in `disable_functions`, SoloFileManager can use OS tools for faster copy/move/delete/compress/extract/folder-size and streamed archive downloads (`$FM_FILE_OPS_MODE = 'auto'`). Without them, use `'php'` mode (or leave `'auto'` and rely on PHP fallbacks)

**Filesystem**

- The web server user must be able to **read** `$ROOT_DIR` and **write** where you create/upload/rename/delete files
- Uploads larger than PHP **`upload_max_filesize`** / **`post_max_size`** are sent in parts below those limits, so php.ini does not cap the file size. Each part must still fit the web server's request limit (e.g. nginx `client_max_body_size`); a part rejected with HTTP 413 is halved automatically. Unfinished parts are kept in a hidden `.fm-upload-tmp` folder under the root, so the root must be writable and have room for the file
- First-run self-rename needs write permission on the directory that contains `solofilemanager.php`
- Trash uses a hidden folder under root (default `.trash`); that path must be creatable/writable. It exists only while Trash holds items (created on the first delete, removed when Trash becomes empty). Deleted items are stored in `.trash/files/`, their original location in `.trash/info/`. On Apache an `.htaccess` blocks web access to it; on nginx / IIS deny that folder yourself (see [SECURITY.md](SECURITY.md))
- When you finish with SoloFileManager, **empty Trash** so removed files are not left on the server. The Trash button shows how many items are left, and Logout offers "Empty Trash and log out" when Trash is not empty

**Browser & network**

- A modern browser with **JavaScript** enabled
- Default UI loads **CDN** assets (Normalize CSS, Bootstrap Icons, Google Fonts). Offline or locked-down hosts need network access to those CDNs, or you must vendor the assets yourself

**Operating system notes**

- Full feature set (especially **chmod** / Unix permissions) works best on **Linux** and similar hosts
- On **Windows** (e.g. XAMPP), file management still works; permission bits are limited by the OS, and some shell/archive helpers may differ

## Features

- Folder tree (sidebar) with bookmarks and trash
- File/folder listing with sortable columns; large folders are shown in pages (100 items by default, set in Configuration → Display); optional Encoding column for text files (Configuration → Display, read in the background for the shown page); in narrow windows less important columns are hidden so names stay readable, and the toolbar keeps one line (buttons that do not fit are in a "More" menu)
- Remembers the last opened folder in the browser: reopen without a URL hash and you land there again (hash in the URL still wins)
- Optional session idle lock (off by default): lock after 15–120 minutes of idle time; unlock with the password without losing the current folder view. Long jobs keep the session alive
- Optional IP allowlist (off by default): only listed IPs / CIDR ranges (IPv4 and IPv6) can open the file manager; everyone else gets 403 before the login page
- Symlinks and Windows junctions shown as links (folder-link icon, arrow badge on file links); tooltip and Info show the target; links outside the root are dimmed and cannot be opened. Windows `.lnk` shortcuts to a folder inside the root open like a Folder link. Size, counts, PHP copy and archives do not follow links; copying a link recreates the link
- Create folder/file, rename, bulk rename (find / replace with optional regular expressions, numbering, case), duplicate
- Copy / move; drag and drop
- Long jobs (copy, move, duplicate, delete forever, archive, extract) show one progress window that keeps its size: what is happening now, items done of the total with the percent, the current file and the elapsed time — also when the server uses its own commands (`cp`, `rm`, `unzip`, `tar` on Linux report every file)
- Delete to Trash or delete forever. Trash works like the Windows Recycle Bin: a flat list showing each item's original name, the folder it came from, deletion date and size, with Restore (back to where it was, renamed to "name (restored)" if that name is taken) and Delete forever; Empty Trash (with confirm); item count on the Trash button, a reminder on Logout, and Server info shows how many items / how much space Trash holds; the `.trash` folder is removed as soon as it is empty
- Filter the current folder listing by name (search field in the breadcrumb bar)
- Find in the current folder and all subfolders by name (text, wildcard or regular expression), text inside files of any encoding (Windows-1251, UTF-16… are detected; the matching line is shown), encoding (e.g. everything that is not UTF-8), type, size and modified date; results show where each item is and can be opened, downloaded, copied, moved, deleted, bulk renamed or have their permissions changed; Back returns to the results without searching again. Uses `find` on Linux / macOS when OS file ops are allowed, PHP otherwise
- Compress / extract archives; big archives show files done and keep going when the hosting drops a long request (the dialog then waits for the result); entries that would land outside the root folder (links, `..`, absolute paths) are skipped and listed
- Change permissions (chmod) for folders and files, including recursive 0755 / 0644 style fixes
- Convert encoding of text files (selected files, search results, or every text file in a folder with a name mask and "only not UTF-8"): detects BOM, UTF-8, UTF-16 and guesses legacy encodings (Windows-1251, KOI8-R, CP866, Windows-1252, ARMSCII-8), shows the file before and after, converts to UTF-8 or another encoding with optional BOM and LF / CRLF line endings, replacing the file or saving a copy next to it. Needs the `iconv` or `mbstring` PHP extension
- Upload (large files in parts, with retries on connection problems; asks Replace / Skip / Keep both when names already exist), get info (for several selected items: count, total size with everything inside, file types, modified range, permissions), copy paths
- Popups can be moved by dragging their title bar (mouse or touch); double-click the title bar to centre a popup again
- Download files, or folders and multiple items as ZIP / TAR / TAR.GZ — the browser download starts immediately while the archive is built; failures show the real reason
- Configuration popup (columns, permissions display, folder-size behavior, heavy file-ops mode)
- Change password from the UI (updates `$PASSWORD_HASH` in the same PHP file when writable)
- Keyboard shortcuts, server info
- Optional terminal-here (disabled by default — open the terminal icon for Terminal settings: Standard / Manual / Advanced, password to save; gear inside the terminal popup; or edit the `$FM_ENABLE_TERMINAL_*` flags; trusted hosts only)

## Configuration

Edit the config block at the top of `solofilemanager.php`:

| Setting | Purpose |
|---------|---------|
| `$ROOT_DIR` | Root path users may browse (default: script directory) |
| `$ENABLE_AUTH` / `$PASSWORD_HASH` | Session login (keep auth on). Prefer changing the password in the UI (key icon); manual hash paste is the fallback |
| `$FM_FILE_OPS_MODE` | `'auto'`, `'php'`, or `'os'` for heavy file operations (and for Find: `find` on Linux / macOS in `auto` / `os`). Prefer changing from **Configuration** (password to save); file edit is the fallback |
| `$FM_IDLE_TIMEOUT_MINUTES` | Idle session lock: `0` (off) or `15` / `30` / `60` / `120`. Prefer **Configuration** (password); file edit is the fallback |
| `$FM_ALLOWED_IPS` | IP allowlist, e.g. `['203.0.113.5', '192.168.1.0/24']`. Empty `[]` = everyone (default). Editable in **Configuration → Server** (password); if you lock yourself out, edit this line in the PHP file |
| `$FM_TRUSTED_PROXIES` | Reverse proxies (e.g. Cloudflare ranges) allowed to pass the real client IP in `X-Forwarded-For`. Empty = use the direct connection IP only (default). File edit only |
| `$FM_TRASH_BASENAME` | Hidden recycle folder under root (default `.trash`). **Configuration → Server** (password) can switch to a random `.trash-…` name and renames the folder with it |
| `$FM_ENABLE_TERMINAL_HERE` | Standard terminal-here (**false** by default). Prefer Terminal settings from the terminal icon (password); or set `true` in the file on trusted hosts only |
| `$FM_ENABLE_TERMINAL_MANUAL` / `$FM_ENABLE_TERMINAL_ADVANCED` | Manual / advanced modes (**false** by default). Same Terminal settings UI (gear in the terminal popup), or edit the file |

Manual password-hash recovery (only if the in-app update cannot write the file):

```php
echo password_hash('your-strong-password', PASSWORD_DEFAULT);
```

Paste the result into `$PASSWORD_HASH` near the top of the script.

## Security

SoloFileManager can create, rename, and delete files. Treat it as **high risk** if exposed on the public internet.

- Keep `$ENABLE_AUTH = true` and change the default `admin` password on first login
- Do not rely on the random filename alone
- Prefer HTTPS, IP allowlists, and/or HTTP Basic Auth at the web server. If you cannot change the web server config (shared hosting), use the built-in `$FM_ALLOWED_IPS` allowlist
- Built in: CSRF token on every state-changing request; session cookie is `HttpOnly` + `SameSite=Lax` and separate for each installation; changing the password logs out other sessions

See [SECURITY.md](SECURITY.md) for more.

## Tests (development)

The `tests/` folder is for development only (it is not included in release archives). Run `php tests/run.php`; see [tests/README.md](tests/README.md) for details and for building a copy to test in a browser.

## Changelog

See [CHANGELOG.md](CHANGELOG.md). Latest: **1.8.1** — popups can be moved by their title bar, Get info shows a summary for several selected items, and actions on more than about 1,000 selected items no longer handle only the first ~1,000.

## License

MIT — see [LICENSE](LICENSE). Others may use, modify, and redistribute SoloFileManager (including commercially), as long as they keep the copyright and license notice. The software is provided **as is**, without warranty.
