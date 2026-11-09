# SoloFileManager tests

Development only. To run SoloFileManager you need just `solofilemanager.php`; this folder is not part of the release archives.

## Requirements

- PHP 8 command line (`php`), with the `zip` (ZipArchive) and `zlib` extensions
- A free local port for PHP's built-in server (default `8765`)
- On Windows, folder links are created as junctions (`mklink /J`), no administrator rights needed

## Run

```bash
php tests/run.php          # all suites; exit code 0 when everything passes
php tests/unit.php         # helper functions only
php tests/http.php         # HTTP checks only
php tests/http.php --source=path/to/solofilemanager.php --port=8766
```

`--source` tests another build (for example an older release, to see which checks it fails). On Windows with XAMPP:

```powershell
& "C:\xampp\php\php.exe" tests/run.php
```

## What is checked

`unit.php` loads the product file without handling a request (`SOLOFM_TEST_INCLUDE=1`) and calls its functions:

- **Paths and access:** root folder boundary (`..`, links, similar folder names), upload relative paths, item paths from `names[]` (search results: validated subpaths), IP allowlist and CIDR matching, client IP behind trusted proxies.
- **Sessions:** session name per installation, password fingerprint, the default-password check (also when asked again).
- **Archives:** entry checks (traversal, absolute paths, links, write-through existing links), the built-in TAR reader, archive job status files, the archiver runner (exit codes, no leftover files), gzip in PHP with progress.
- **Archive filter rules:** masks like `.gitignore` (name at any depth, path from the top, `**`, `?`, `name/` folders, leading `/`), Include / Exclude logic, filtering an entry list, regular-expression mode, bad patterns and limits.
- **Listing and search:** folder listing, search filters (text / wildcard / regex, case, type, size, date; links not followed; Trash and hidden names skipped), text inside files, the `find` arguments. On Linux / macOS it also checks that `find` and PHP return the same results.
- **Encodings:** detection (BOM, UTF-8, UTF-16, legacy guesses), strict conversion, line endings.
- **Upload name conflicts**, **long selections in one field** (`fm_lists`), **progress of OS commands** (verbose `rm` / `cp` / `unzip` / `tar` / `xcopy` lines counted).

`http.php` installs temporary copies with their own passwords (one in auto file ops mode, one in PHP mode, one OS-only, one behind an IP allowlist, one fresh first-run copy), starts PHP's built-in server with `max_input_vars=1000` and uses the API like a browser:

- **Login and sessions:** login and CSRF, separate sessions per installation, logout of other sessions after a password change, 403 for an IP not on the allowlist, server capabilities kept in the session.
- **First run:** a copy not renamed yet refuses every file action (403); after the rename the password is set without logging in with `admin`, the browser that set it is logged in, a second browser cannot set it again.
- **Files:** upload with Replace / Skip / Keep both, chunked upload, search and copy / move / Trash / delete on search results (including refused `..` and link paths), Undo of Move to Trash (the trash ids in the answer restore exactly those items), switching the trash folder to a random name and back.
- **Archives:** a hostile archive, compress jobs with status after a dropped connection, PHP TAR / TAR.GZ, the archive filter in Archive, Download as archive and Extract (Check preview, nothing matches, bad regex, OS-only refusal, same result in auto mode).
- **Long jobs:** progress of copy / delete / extract in PHP and auto mode, selections of more than 1,000 items (PHP truncation vs `fm_lists`), copies keep the modified time.
- **Get info** for one folder (size, counts and file types with their size in one answer) and for several items (summary and types).
- **Convert encoding:** detection, conversion, copies, refused links and paths outside the root, Find with the encoding filter.

## Files

| File | Purpose |
|------|---------|
| `run.php` | Runs `unit.php` and `http.php` |
| `unit.php` | Unit checks of the helper functions |
| `http.php` | HTTP checks against temporary copies |
| `lib.php` | Shared helpers: `section()`, `check()`, scratch folders, folder links, TAR building |

Temporary files go to `tests/.tmp/` (ignored by git). Each suite removes its own folder at the end, and `run.php` removes `tests/.tmp` itself after a run where everything passed; after a failure it is kept for a look (the next run starts from an empty folder anyway).

## Manual testing in a browser

Do not open the original `solofilemanager.php` in a browser: the first-run rename and every saved setting (Configuration, password change) rewrite that file. Test a copy instead: copy the file to a test folder in your web root under another name and open that. (The maintainers build test copies with test data using local tools that are not part of the repository.)

## Adding a check

```php
section('My feature');
check('what should be true', $condition, 'details shown when it fails');
```

End a new suite with `exit(summary('name'));` and add it to the list in `run.php`.
