# Changelog

All notable changes to SoloFileManager are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and versions follow [Semantic Versioning](https://semver.org/).

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
