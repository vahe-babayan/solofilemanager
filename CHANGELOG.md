# Changelog

All notable changes to SoloFileManager are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and versions follow [Semantic Versioning](https://semver.org/).

## [1.3.5] - 2026-10-22

### Added

- Optional IP allowlist (`$FM_ALLOWED_IPS`, empty = everyone by default): single IPs and CIDR ranges, IPv4 and IPv6. Other IPs get a plain 403 before the login page and before any API action. Editable on the Configuration → Server tab (password), with an "Add my IP" button; a list that does not contain your current IP is refused so you cannot lock yourself out from the UI. Entries that are not valid IPs never match, so a typo does not silently switch the allowlist off
- `$FM_TRUSTED_PROXIES` (file only, empty by default): reverse proxies such as Cloudflare whose `X-Forwarded-For` is used to find the real client IP; from anyone else that header is ignored
- Server info shows the IP allowlist state and your IP
- Chunked upload: files larger than the PHP limits (`upload_max_filesize` / `post_max_size`) are sent in parts below those limits, so the file size is no longer capped by php.ini. Parts are retried for about a minute on connection problems, a part the web server rejects as too large (HTTP 413) is halved automatically, and the progress bar covers the whole file. Unfinished parts wait in a hidden `.fm-upload-tmp` folder under the root (blocked from the web, not shown in the list, removed when empty; abandoned uploads are deleted after 24 hours). Small files are still sent in one request

### Fixed

- Session idle lock could lock the session in the middle of a long job: copy / move / compress / extract / delete progress streams and uploads did not pause the idle timer, and a long upload could be rejected at the end as "Unauthorized". The timer now pauses while any request to the server is running, until its whole response has arrived
- A running copy / compress / delete stream held the PHP session lock for its whole duration, so every other request of the same session (opening a folder, Server info, keepalives) waited until it finished, and a job longer than the idle timeout could log you out right after it ended. API requests now release the session lock as soon as authentication is checked

## [1.3.4] - 2026-10-16

### Added

- Remember the last opened folder across reloads: opening the file manager without a URL hash restores that folder (stored in the browser); an explicit hash still wins, and a missing folder falls back to the root
- Optional session idle lock (`$FM_IDLE_TIMEOUT_MINUTES`, off by default; 15 / 30 / 60 / 120): after idle time the session is cleared and a lock overlay asks for the password; Configuration can change it (password). Long copy / delete / upload / download keep the session alive (busy pause + `session-touch` keepalive)

### Changed

- Configuration popup: Display / Server tabs (browser settings vs PHP-file settings with password); shared fieldset layout for all sections

## [1.3.3] - 2026-10-16

### Added

- Symlinks and Windows junctions are marked in the file list (folder-link icon, small arrow badge on file icons, Type "Folder link" / "txt link") and in the folder tree; the tooltip and Info show where the link points. Links to a location outside the root are dimmed and say they cannot be opened; Info now works for them too
- Windows Explorer shortcuts (`.lnk`) that point to a folder inside the root are shown as Folder links (same icon) and open the target folder instead of downloading the `.lnk` file

### Fixed

- Deleting a link to a folder (Delete forever, Empty Trash, or deleting a folder that contains such a link) also deleted the files inside the linked folder when file operations ran in PHP (no `exec()`); now only the link itself is removed
- Empty Trash skipped links ("skipped"), so they stayed in Trash forever
- Deleting a Windows junction with OS commands reported "Could not delete" although it was removed
- Breadcrumbs and the URL hash now use the resolved target folder after opening a symlink / junction / `.lnk`, so the path shows the real folder name (not the link name)
- Folder size / file counts / PHP copy / archive walk no longer follow symlinks or Windows junctions (a link to a parent folder can no longer inflate size, hang, or fill the disk); copying a link recreates the link instead of copying its target

## [1.3.2] - 2026-10-14

### Security

- Root folder boundary for new files: a path that did not exist yet (upload target, new folder / file, rename target) was checked by its text only, so a logged-in user could write outside the root through a symlink or junction inside it (e.g. upload `link/shell.php` where `link` points to a web folder outside the root, then run it), through a dangling link, or with `../` in the new name on rename. Such paths are now judged by the real location of their nearest existing parent; `.`, `..` and dangling links are rejected, and rename refuses names with `\ / : * ? " < > |`. Reported by levanduy181 - thank you

### Fixed

- Error messages in the Rename, Create new folder, Create new file and New folder from selection popups were never shown (e.g. a name with `/` or an existing name did nothing); they now appear under the field

- After deleting (or moving out, or restoring from Trash) nothing stays selected; the neighbouring row only gets the keyboard focus frame, so the arrow keys continue from there
- Trash: the "from /folder" line under a selected item is now light and readable on the highlighted row (was grey)
- Bulk rename: the popup no longer changes height while you edit the rules. The red "Every new name matches the current name…" line is gone; Apply is simply disabled until at least one name changes and the preview has no invalid / duplicate names, with a short static hint under the counter row. Server errors on Apply are shown in a notice

## [1.3.1] - 2026-10-10

### Security

- Root folder boundary: a folder next to the root whose name starts with the root's name (e.g. `files-private` next to root `files`) was treated as inside the root, so a logged-in user could list, download, upload into, delete or change files there. Paths now count as inside only when they are the root itself or below `root/`. Reported by [@HuzaifaDal](https://github.com/HuzaifaDal) - thank you

## [1.3.0] - 2026-10-08

### Added

- Server info: "Trash" row with the trash folder name and how many items / how much space it holds (size counted in the background, capped at 5000 files); click the name to open Trash
- Trash button in the sidebar shows how many items Trash holds (badge, highlighted button, "N items still stored on the server" hint), updated after every action
- Logout with a non-empty Trash asks first: "Empty Trash and log out", "Log out" or "Cancel", showing the item count and size

### Changed

- Trash works like the Windows Recycle Bin: one flat list of deleted items with their original name, the folder they came from, the deletion date and size. Each item has Restore (back to where it was) and Delete forever; Empty Trash clears everything
- Deleting the same name twice keeps both copies in Trash; restoring onto an existing name gives "name (restored).ext", "name (restored 2).ext" and says so; a missing original folder is recreated on restore
- Trash storage: items are kept in `.trash/files/` and their original location in `.trash/info/` (on the server, not in the browser); next to them there is only the protective `.htaccess` and `index.html`. An old-style `.trash` (mirror of the folder tree) is converted automatically the first time Trash is used, including any `.htaccess` / `index.html` that had been deleted into it, which become normal restorable items
- The trash folder only exists while it holds items: it is created on the first delete to Trash and removed again once Restore, Delete forever or Empty Trash leaves it empty (opening Trash no longer creates it)
- Upload: files larger than the server limit (`upload_max_filesize` / `post_max_size`) are flagged in the queue and not sent, with the limit shown
- Upload errors are readable: PHP upload error codes become plain reasons (e.g. "larger than the server limit upload_max_filesize (40M)", "could not write the file to disk"), and web-server rejections (HTTP 413) are explained

### Fixed

- An upload over `post_max_size` returned the HTML page and showed "Invalid response"; the server now answers with a clear JSON error (HTTP 413)
- Row highlight after create / upload / extract etc.: the item icon no longer flashes green between pulses (it keeps its normal colour)

### Removed

- "Show trashed" checkbox and the merged view of trashed items inside normal folders (with "Original location (merged)" / "Open in Trash" links): everything deleted is now in the Trash list only
- Browsing, renaming, downloading and editing items inside Trash: restore an item first

### Security

- With `$ENABLE_AUTH = false`: a permanent warning in the header, "Authentication: Off" in Server info, and terminal modes can no longer be enabled from the UI (turning them off still works; enabling requires editing the PHP file)
- The trash folder gets an `.htaccess` (deny all, Apache 2.2 and 2.4) and empty `index.html` files, so deleted files cannot be downloaded by URL on Apache. On nginx / IIS deny the trash folder in the server config (see SECURITY.md)

## [1.2.0] - 2026-10-07

### Added

- Streaming downloads: the browser download starts immediately and the archive (ZIP / TAR / TAR.GZ) is built on the fly — OS `zip` / `tar` via `proc_open` on Linux, built-in streaming PHP writer elsewhere (ZIP64 and long TAR names supported)
- Download dialog suggests the folder name when one folder is selected; several items keep the timestamped `download-YYYY-MM-DD-HHMMSS` name

### Changed

- Readable download errors ("Download failed: …", list of skipped files) instead of "Failed to fetch"
- No progress toasts for downloads — the browser's downloads list shows progress
- Single-file downloads stream in chunks and release the session, so the app stays usable during downloads

### Fixed

- Large folders and archives failing to download with "Failed to fetch"

### Security

- CSRF token required on every POST (`X-CSRF-Token` header, or `csrf_token` field for the native download form). Pages opened before the update need a reload
- Session cookie is `HttpOnly` + `SameSite=Lax`, and `Secure` over HTTPS

## [1.1.0] - 2026-08-12

### Added

- Filter current folder by name (search field in the breadcrumb bar)
- Empty Trash (toolbar confirm + API)
- Delete forever confirm for dimmed trashed rows; archive overwrite confirm
- Keyboard shortcut labels on actions; F5 refresh

### Changed

- Renamed from SoloFM to SoloFileManager
- Clearer Restore visibility with Show trashed
- Faster folder size via OS tools when `exec()` / file-ops mode allows
- Extract syncs the sidebar tree
- Scrollbars: show on hover (fine pointer); always visible on touch

### Fixed

- Restore / empty trash update the sidebar without a full tree reload (avoids lag)

## [1.0.2] - 2026-08-09

### Fixed

- After changing password from the header, the page reloads as promised

### Changed

- Docs: clarify setup, Configuration (file-ops mode), and terminal settings UX

## [1.0.1] - 2026-08-07

### Added

- Dual first-run rename suggestions (random / `solofilemanager_` prefix)
- In-app password change and Terminal settings (Standard / Manual / Advanced)
- Configuration can set `$FM_FILE_OPS_MODE` with password confirmation

## [1.0.0] - 2026-08-04

- Initial release
