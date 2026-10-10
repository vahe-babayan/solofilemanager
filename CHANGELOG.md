# Changelog

All notable changes to SoloFileManager are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and versions follow [Semantic Versioning](https://semver.org/).

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
