# Changelog

All notable changes to SoloFileManager are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and versions follow [Semantic Versioning](https://semver.org/).

## [1.8.1] - 2026-11-07

### Added

- Popups can be moved: drag the title bar (mouse or touch). A moved popup stays where it was put, also when it grows, and stays inside the window; double-click the title bar to centre it again. Every popup opens centred
- Get info for several selected items (also in search results): number of folders, files and links, total size with everything inside the folders, how many files and folders they hold in total, file types (the 8 most common, the full list on hover), modified range, permissions and the folder they are in. Large selections show how far the counting got
- Server info shows PHP's `max_input_vars`

### Fixed

- Actions on more than about 1,000 selected items (delete forever, move to Trash, restore, copy, move, archive, permissions, download) handled only the first ~1,000: PHP's `max_input_vars` (1000 on most hosts) silently drops the rest of the form. Long selections are now sent as one field. This happens with large pages ("Items per page" 1,000 / 5,000 / All) and large search results
- Copy, move and delete of many single files in auto file ops mode took minutes (one OS command per file, each waited at least 0.25 s, since 1.8.0); plain files are now handled in PHP and OS commands are checked more often while they run. A copied file keeps its modified time and permissions
- After saving a setting in Configuration (or Terminal settings, the password) the page could still show the old value for a few seconds on hosts with PHP OPcache; the cached script is now refreshed right after the file is written
- Get info of one folder took a second or more on large folders: the size and the counts of what it holds were found by two separate passes (`du`, then a second request). They now come in the same answer from one pass. The folder count no longer includes the folder itself
- Every request (Info, folder list, folder size, any action) spent about 0.3 s before doing its work on Windows: it started four programs to see which archive tools the server has, and checked the default password twice (each check is deliberately slow). Both answers are now kept in the session: the tools are checked again after 10 minutes and whenever Server info is opened. On XAMPP for Windows Info of a file now takes about 25 ms instead of 330 ms
- After a hard refresh (or the first visit) the toolbar could be cut off on the right with no "More" (⋯) button: it was measured before the icon font had arrived, and the icons are wider once it has. The toolbar is measured again when the fonts have loaded

## [1.8.0] - 2026-11-07

### Changed

- One progress window for long jobs (copy, move, duplicate, new folder from selection, delete forever, archive, extract): what is happening now, items done of the total with the percent, the current file and the elapsed time. Every line keeps its height, so the window does not change size (and does not jump) while progress comes in. Delete forever and copy / move no longer open a separate "Counting items…" window first: the server counts and the same window goes on
- Progress also when the server uses its own commands: on Linux `rm`, `cp`, `unzip` and `tar` run with verbose output and every file they report is counted; commands that print nothing (Windows `rmdir`, `move`) count each selected item when it is done. Extract reports every entry in PHP too (ZIP in parts, TAR per entry) instead of one step per archive
- Long delete, copy, move and extract jobs send a keep-alive line every 5 s while a command is silent, like archives, so hosting proxies do not close the connection
- Rename, new folder, new file and new folder from selection keep a line for the error message, so an error does not change the popup's size (the centred popup no longer jumps); a long message ends with "…" and shows in full on hover. The error line under the Configuration password is reserved the same way

### Removed

- `$FM_VERBOSE_PROGRESS_MIN_ITEMS`: progress details are always shown (jobs under 800 items used to show only a moving bar). The line can stay in old copies; it is ignored

### Fixed

- Copy / move progress never showed the "n / total" count (a style hid it)
- The Upload dialog's status line ("Ready — 2 file(s) to upload", "Batch finished…") never appeared
- Delete forever, copy / move and extract could leave the progress window open forever when the connection ended without a result; they now say so and ask to refresh the folder

## [1.7.0] - 2026-11-06

### Added

- Convert encoding (toolbar, context menu): converts text files to UTF-8 or another encoding. For selected files it shows each file's encoding right away; for a folder (right-click a folder, or nothing selected for the current one) it first scans for text files, with a name mask (e.g. `*.txt;*.php`), "Include subfolders" and "Only files that are not UTF-8", streamed with Stop, and a summary by encoding to filter on. Each file shows its detected encoding (BOM, UTF-8 and ASCII for sure; UTF-16 without BOM and legacy Windows-1251, KOI8-R, CP866, Windows-1252, ARMSCII-8 marked as a guess) and line endings; clicking a file shows its first lines now and after the conversion. Options: From (auto or a chosen encoding), To, Add BOM, line endings (keep / LF / CRLF), replace the file or save a copy next to it (`report.utf8.txt`). Files are converted in batches with progress and Stop; each file reports converted / already in this encoding / skipped / failed with the reason. Works on search results too (selected results, or all found files). Only encodings the server can convert are offered
- Find: Encoding filter (Any / Not UTF-8 / one encoding); each result shows its detected encoding and line endings, so files in legacy encodings can be found and then converted
- Optional Encoding column in the file list (Configuration → Display, off by default): the encoding of text files (by extension) on the shown page is read in the background with one request, kept in the browser until the file changes; other files show "—". With the column on, narrow lists hide Perms first, then Encoding. Get info shows a file's encoding and line endings too

### Changed

- Find, "Contains text": files that are not UTF-8 are read in their own encoding. The encoding is detected from the first 64 KB of each file (as in Convert encoding), lines of a legacy encoding (Windows-1251, KOI8-R, CP866, Windows-1252, ARMSCII-8…) are decoded before matching and UTF-16 files are searched too, so Cyrillic or Armenian words are found in old files and the matching line is shown as text instead of "?". Such results show the encoding next to their folder
- Toolbar stays on one line: buttons that do not fit go into a "More" (⋯) menu at the end, the least used first (Duplicate, New folder from selection, Copy relative path, Open in new tab, terminal…), with their shortcuts and greyed when not available; it adapts when the window is resized. Every action has a priority, so new ones fit in the same way
- Archive: the default name is the name of the current folder (at the root: the root folder's own name, e.g. `example.com.zip`) instead of `archive.zip`, with the date added when that file already exists. An archive name with another archive extension gets the right one (`site.zip` as TAR.GZ becomes `site.tar.gz`, not `site.zip.tar.gz`)
- Archive progress: the dialog shows "N of M files" and the file being added (zip / tar report every file), and the progress line fills up instead of only moving. The compressing step shows its own percent: gzip of a `.tar.gz` made by the system `tar` now runs in PHP (zlib) and reports how much is done, and in PHP-only mode ZIP shows the percent of data compressed while the archive is written. In PHP-only mode TAR and TAR.GZ are written in one pass (with gzip on the fly) and count every file

### Fixed

- Archiving a big folder showed "Could not create archive." while the archive was still being built and then finished fine: zip / tar ran as one silent command for minutes, so the hosting proxy closed the connection. They now report every file and send a keep-alive every 5 s (also while gzip or ZipArchive is compressing); the job continues if the connection breaks anyway, and the dialog then waits for its result on the server instead of reporting an error. When both the system archiver and PHP fail, the message includes the archiver's own reason (e.g. "Disk quota exceeded")
- Downloading a big file could show "Download failed" after about 15 s while the file was still downloading: on hostings where PHP's temp folder cannot be written (open_basedir, separate /tmp) the download status was never found. Status files now go to the first writable of the temp folder, upload_tmp_dir and the session folder, or else to a guarded `.fm-state` folder in the root (hidden like the other service folders); when no status can be kept the download is simply left to the browser. Server info shows where status files go
- PHP-only TAR / TAR.GZ archives put the content of a selected folder at the top of the archive without the folder's name, and grew slower with every file added (each file rewrote the whole archive); a `.tar.gz` could also get the name of the temporary `.tar` (`name_1.tar.gz`) when a `.tar` with the same name existed
- Configuration: the password needed for Server settings was at the bottom of the Server tab, so Apply seemed to do nothing when it was empty (the message was out of sight as well). It now appears pinned above the buttons as soon as a server setting is changed, on either tab; Apply without it, or with a wrong one, highlights the field, puts the cursor in it and shows the reason right under it
- Bulk rename preview: a thin line under the column headers let the scrolled rows show through; the header is now solid
- Get info and Server info no longer open with a "Loading…" line and then grow: they open once the info has arrived (normally well under a second), or with a loading area of about the final height when the server is slow

### Security

- Convert encoding reads and writes only files inside the root (links are skipped), writes through a temporary file renamed over the original, and never drops or replaces characters: a file that does not fit the source or target encoding is reported and left unchanged (see SECURITY.md)

## [1.6.0] - 2026-11-04

### Added

- Find: "Contains text" searches inside files (a line contains the text, or a regular expression on one line; ignore case on by default, also for non-Latin letters in UTF-8). Each result shows the line number and the matching line with the text marked. Text files up to 10 MB are read; binary files (a NUL byte in the first 8 KB), linked files outside the root and folders are left out. A search stops after reading 1 GB of files and says so. Works the same on Windows and Linux (read in PHP; on Linux `find` still walks the folders)
- Search results: Bulk rename and Change permissions work on items from different folders (toolbar and context menu). Bulk rename changes only the name, so each item stays in its folder; the preview shows the folder under each name, and duplicate new names are refused only within one folder. Every folder is checked before anything is renamed, so a conflict in one folder renames nothing; a selected folder and items inside it can be renamed together. The results refresh afterwards

### Changed

- Bulk rename and Change permissions accept items in subfolders of the request folder (needed for search results); every part of the path is validated and the full path must stay inside the root folder, like Copy, Move and Delete
- Narrow windows: when the list gets too narrow for the file name, Perms, Type, Last modified and finally Actions are hidden one by one (Actions stay in the context menu and toolbar), so the Name column keeps about 220 px instead of a few characters

### Fixed

- The column headers were shifted against the rows by the width of the list's scrollbar (about 10 px); headers and rows are now aligned
- Configuration → "Show row actions column" and "Show last modified column" had no effect (the columns were always shown); "Automatic get folders size" now applies without a reload
- Opening folders quickly one after another (or right after closing search results) could show the earlier folder when its listing arrived last; only the latest request now updates the list

### Security

- Find: text inside files is read only from files inside the root (linked files outside it are not opened), with per-file and per-search read limits; Bulk rename and Change permissions validate every part of a result path like the other actions (see SECURITY.md)

## [1.5.0] - 2026-11-03

### Added

- Find (toolbar search icon, Ctrl + Alt + S): search the current folder and all its subfolders by name (contains the text, wildcard `*` / `?`, or regular expression; ignore case on by default), type, size range and modified date. Results stream in while the search runs and can be stopped; the search ends after 5,000 results or 2 minutes and says so. Links are listed but not searched, and Trash, unfinished uploads and the names the listing hides are left out
- Search results view: each item shows the folder it is in; filter, sort, Get info, Copy path, Open containing folder, open found folders, Download (one file, or several items as an archive that keeps their folders), Copy, Move and Delete (to Trash or forever) work on items from different folders. Refresh / F5 runs the search again, and actions refresh the results. Backspace, Back or Close return to the folder
- Back to the search results: after opening a found folder (or going anywhere else), Back or the "Search results (N)" button next to the navigation buttons shows the results again from memory, with the opened item focused, without searching again. If files were changed meanwhile (delete, move, upload, rename…), the search runs again instead. Close or a new search forgets the kept results
- On Linux / macOS in `auto` / `os` file ops mode the search uses `find` to walk the folders (much faster on large trees); PHP checks every candidate with the same rules, so the results are the same as with PHP. On Windows the search always runs in PHP
- Large folders and search results are shown in pages (100 items per page by default; 100 / 250 / 500 / 1,000 / 2,000 or All in Configuration → Display). Sorting, the name filter and counts cover the whole folder; a pager under the list shows "1–100 of 60,000" with first / previous / page number / next / last; Arrow / Page keys move on to the next or previous page; Ctrl+A selects the page and "Select all N" the whole folder. Finding an item (Open containing folder, highlight after create / rename) opens its page

### Changed

- Opening a folder is much faster on large folders: the listing reads each entry once (`lstat`) and checks for links only where they can be, instead of several file system calls per entry (60,000 files: about 36 s → 8 s on a slow Windows disk, about 0.6 s on a Linux host; the browser draws one page of rows instead of 60,000)
- Copy, Move, Delete, Move to Trash and archive downloads accept items in subfolders of the request folder (needed for search results); every part of the path is validated and the full path must stay inside the root folder, as before. Copying or moving two items with the same name into one folder is refused

### Fixed

- "Rows per Page Up / Down" from Configuration was ignored after a page reload until Configuration was applied again
- Configuration (Apply without server changes) and Terminal settings (Save) left their closed dialog hidden in the page, so every Apply added another copy with the same element ids; the dialog is now removed when it closes

## [1.4.0] - 2026-11-02

### Security

- Extract: an archive entry could be written outside the root folder through a symlink or junction that already existed in the destination (e.g. `jx/file.txt` when `jx` links elsewhere). Every entry is now checked before extraction: links, special files, `..`, absolute paths, drive letters and targets that resolve outside the root are skipped, the rest is extracted, and the skipped names are shown afterwards. In OS-only file ops mode such an archive is refused instead
- Extract: symlinks stored in ZIP and TAR archives are no longer created

### Added

- Upload no longer overwrites silently: when files with the same names already exist, a dialog offers Replace, Skip or Keep both ("name (1).ext"), for normal and chunked uploads
- Bulk rename: Find can be a regular expression (`$1`, `$2` for groups, `$&` for the match) and can ignore case; an invalid expression is shown in the preview and blocks Apply
- Optional random trash folder name (Configuration → Server, password): `.trash-` + 12 random hex characters instead of `.trash`, harder to guess on servers that ignore `.htaccess`. The existing trash folder is renamed with the setting, and you can switch back to `.trash`
- Automated tests in `tests/` (PHP command line: unit checks of the security helpers and HTTP checks against temporary copies), with `tests/make-instance.php` to build a copy for manual testing; see `tests/README.md`. Development only: the folder is left out of release archives (`.gitattributes` `export-ignore`)

### Fixed

- PHP TAR / TAR.GZ extraction (PHP file ops mode or when OS `tar` is unavailable) failed on PHP 8, and long names stored in PAX headers were lost. TAR files are now read by a built-in streaming reader

## [1.3.6] - 2026-10-22

### Security

- Separate login per installation: all copies of SoloFileManager on the same domain shared PHP's default session cookie (`PHPSESSID`), so logging in to one copy also opened every other copy, even with a different password. Each installation now uses its own session cookie (`SOLOFM_` + a hash of the script path) and no longer shares session data with other PHP apps on the domain
- A login is now tied to the current password: after a password change every other open session has to log in again (the session that made the change stays logged in), and the session id is renewed on login
- After updating you need to log in once more (new cookie name)

### Fixed

- Trash: with image previews on, deleted images showed an empty icon; they now show the image type icon
- Copies on the same domain shared bookmarks, the last opened folder and the Copy/Move and terminal state (browser storage is per domain). These are now kept per installation; existing bookmarks are moved over once, keeping only the folders inside this copy's root. Display settings (columns, permissions view, sidebar width) stay shared

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
