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

- `unit.php` loads the product file without handling a request (`SOLOFM_TEST_INCLUDE=1`) and calls its functions: root folder boundary (`..`, links, similar folder names), upload relative paths, IP allowlist and CIDR matching, client IP behind trusted proxies, archive entry checks (traversal, absolute paths, links, write-through existing links), the built-in TAR reader, upload name conflicts, session name and password fingerprint, search filters (text / wildcard / regex, case, type, size, date; links not followed; Trash and hidden names skipped), the `find` arguments, and item paths from `names[]`. On Linux / macOS it also checks that `find` and PHP return the same results.
- `http.php` installs temporary copies with their own passwords, starts PHP's built-in server and uses the API like a browser: login and CSRF, separate sessions per installation, logout of other sessions after a password change, 403 for an IP not on the allowlist, upload with Replace / Skip / Keep both, chunked upload, a hostile archive, search and copy / move / Trash / delete on search results (including refused `..` and link paths), and switching the trash folder to a random name and back.

## Files

| File | Purpose |
|------|---------|
| `run.php` | Runs `unit.php` and `http.php` |
| `unit.php` | Unit checks of the helper functions |
| `http.php` | HTTP checks against temporary copies |
| `lib.php` | Shared helpers: `section()`, `check()`, scratch folders, folder links, TAR building |
| `make-instance.php` | Builds a ready copy for manual testing in a browser |

Temporary files go to `tests/.tmp/` (ignored by git) and are removed after each run.

## Manual testing in a browser

Do not open the original `solofilemanager.php` in a browser: the first-run rename and every saved setting (Configuration, password change) rewrite that file. Build a copy instead:

```bash
# new copy with a random name; prints the name and the password
php tests/make-instance.php --out-dir=/path/in/web/root/test-fm --password=secret123 [--allowed-ips=203.0.113.5] [--ops-mode=php]

# after changing the code: update the copy, keep its name and settings
php tests/make-instance.php --out-dir=/path/in/web/root/test-fm --keep-name
```

The name and password are also saved in `.instance.json` next to the copy. Delete the folder when you are done, especially on a public server.

## Adding a check

```php
section('My feature');
check('what should be true', $condition, 'details shown when it fails');
```

End a new suite with `exit(summary('name'));` and add it to the list in `run.php`.
