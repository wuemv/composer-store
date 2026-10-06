# Phase 0 spike: results

Run on 2026-10-04 with `spike/run.sh` (driver) and `spike/spike.php` (store, link, verify, report).
The full run was done twice from scratch. Both runs gave the same results; only the timings moved.
A control run with symlinked package dirs followed on 2026-10-05, with `spike/symlink-control.sh`:
see [Control run: symlinked package dirs](#control-run-symlinked-package-dirs).

## Verdict

**The theory holds.** A Laravel app whose `vendor/` package files are hard links into a shared store
behaves exactly like a normal install:

- In 3 fresh apps, 16 checks each, all passed before and after linking (98 of 98 runs).
- No package broke, and no package needed special handling.
- Nothing the apps or Composer did wrote into the store, and files copied out of `vendor/` came out as independent files.
- The control, symlinked package dirs, fails: `php artisan test` breaks in both apps tried. The store links
  files, never package directories.

## Setup

- PHP 8.3.6, Composer 2.8.12, Linux 6.18, ext4 (no reflinks), running as root. The store and the apps were on one filesystem.
- Three apps from `composer create-project`:

  | app | command | result |
  |---|---|---|
  | app-a | `laravel/laravel` | Laravel 13.34.0, 110 packages: all moved into the empty store, then hard-linked back |
  | app-b | `laravel/laravel` again | the same 110 versions: all store hits |
  | app-c | `laravel/laravel:^12.0` | Laravel 12.69.3, 111 packages: 79 store hits, 32 new entries |

- Store layout and key are the ones in CLAUDE.md:
  - Each entry lives at `store/packages/<vendor>/<name>/<version>-<reference[0:12]>/`.
  - `.store-meta.json` (tree hash, created_at, dist url) sits inside the entry.
  - The tree hash is sha256 over relative path, exec bit and content. It ignores mtimes, owners and umask.
- Linking: directories are recreated as real directories with the same mode, and every file gets one `link()`.
  A package containing anything other than plain files and directories would have been left as a normal copy. None did.
- On a store hit, the spike hashed the project's own freshly extracted copy and compared it with the store
  entry before replacing it. That tests whether `name + version + reference` really pins the content.
- Each app ran the same checks before linking (baseline) and after (linked). The app was reset with git
  between phases so published files and caches could not carry over.

| check | what it exercises |
|---|---|
| `php artisan about` | boot, config, drivers |
| `php artisan package:discover` | reads `vendor/composer/installed.json`, writes the package manifest |
| `php artisan test` | Collision starting PHPUnit in a subprocess |
| `vendor/bin/phpunit` | Composer bin proxy → PHPUnit |
| `vendor/bin/pint --test` | the Pint PHAR inside `vendor/` |
| `php artisan migrate:fresh` | database, migrator |
| `php artisan optimize`, `optimize:clear` | config/route/view/event caches (compiled views embed vendor paths) |
| `php artisan tinker --execute=…` | PsySH |
| `php artisan serve`, then `GET /` → 200 and `GET /missing` → 404 | router script in `vendor/`, error page rendered from framework views |
| `vendor:publish --tag=laravel-errors`, `lang:publish`, `stub:publish` | copying files out of `vendor/` into the app |
| `composer dump-autoload -o`, `composer install` | Composer on a linked `vendor/` (no-op install, scripts, autoloader) |
| probe (`spike.php probe`) | what PHP and Laravel see for a linked file: `__FILE__`, nlink, `basePath()`, `InstalledVersions`, discovered providers |

## Results

### Checks

All 16 checks passed in all six runs (3 apps × baseline and linked). The plain-Composer reinstall
below added two more passing runs. Linked and baseline durations match within noise: about 0.1–0.4 s per
artisan command, 1.3 s for `serve`, about 2 s for each Composer command.

### Packages that broke

None. All 110 Laravel 13 packages and all 111 Laravel 12 packages were linked, and nothing failed afterwards.

### What PHP sees

The probe, run in each app after linking, reported:

- `ReflectionClass(Application::class)->getFileName()`, which is the path behind `__FILE__` and `__DIR__`, is
  `<app>/vendor/laravel/framework/src/Illuminate/Foundation/Application.php`.
  So `__DIR__` stays inside the project.
- That file's link count is 2 in app-a (store + app-a) and 3 in app-b (store + app-a + app-b).
- `basePath()` is the app, and `InstalledVersions::getInstallPath('laravel/framework')` is the app's `vendor/laravel/framework`.
- Package discovery finds the same 6 providers as the baseline (Pail, Pao, Tinker, Carbon, Collision, Termwind).

### Integrity

| check | app-a | app-b | app-c |
|---|---|---|---|
| package files that share their inode with the store copy, right after linking and again after all checks | 8,799 / 8,799 | 8,799 / 8,799 | 8,519 / 8,519 |
| files that appeared inside linked package dirs during the checks | 0 | 0 | 0 |
| store files with changed content or mode after the checks (of 8,909 / 8,909 / 12,227) | 0 | 0 | 0 |
| files outside linked package dirs that share an inode with the store (app code, published files, `vendor/composer`, `vendor/bin`) | 0 of 254 | 0 of 254 | 0 of 253 |
| store hits whose independently extracted copy hashed identical to the store entry | n/a | 110 / 110 | 79 / 79 |

### Plain Composer on a linked `vendor/` (the uninstall path)

On linked app-a, `composer reinstall laravel/framework` was run without any plugin:

- Composer removed the package by unlinking it, so the store kept its copy. Then it extracted a normal copy, so its 1,897 files no longer share inodes with the store.
- All 12,227 store files were unchanged afterwards, and `php artisan test` still passed.

Composer never writes into existing package files. It deletes them and extracts new ones, so it can't damage the store.

### Disk usage

Measured with `du` (ext4, 4 KiB blocks). `du` counts a hard-linked inode once per invocation.

| | MB |
|---|---|
| the three `vendor/` dirs measured one by one (what three normal installs use) | 219.4 |
| the store and all three `vendor/` dirs measured together (what they use with the store) | 118.4 |

That is **46% less** for this mix of two identical apps and one app on an older major version.

- The store holds 142 entries in 96.5 MB, and 110 of those entries are used by more than one app.
- Each linked `vendor/` still costs about 7.3 MB of its own:
  - its 1,303 directories (about 5.1 MB), because directories can't be hard-linked;
  - `vendor/composer` (about 2.2 MB with an optimized classmap).
- So one more app on versions already in the store costs about 7 MB instead of about 74 MB.

### Timing (indicative only, Phase 5 does the real benchmarks)

- Putting app-b's 110 packages (8,799 files) into `vendor/`:
  - hard-linking from the store took 130–270 ms;
  - `composer install --no-scripts --no-autoloader` from a warm Composer cache took 1.1–1.4 s (two runs).

  The Composer figure includes its own startup and lock handling. Read it only as "linking is far from being the bottleneck".
- Tree-hashing a new store entry costs about as much as linking: 286–355 ms for about 44 MB. It is paid once, when an entry is first stored.

## Findings for the plugin design (open questions)

1. **Composer chmods `bin` files through the link.** I found this by reading Composer 2.8's source. The spike
   did not hit it, because Composer was not doing the linking.
   - When Composer installs or updates a package, `BinaryInstaller::installBinaries()` runs `chmod($binPath, 0777 & ~umask())` on each of the package's bin files.
   - A no-op `composer install` skips bin proxies that already exist, so it does not chmod.
   - The custom installer will extend `LibraryInstaller`, whose `install()` and `update()` call `installBinaries()` after the code step. So the chmod lands on the shared store inode. Three consequences:
     - it undoes read-only mode for those files;
     - a project with a different umask (002 vs 022) flips the mode for every project;
     - a tree hash that includes exec bits has to be taken after this chmod.

   Proposal: copy rather than link the files listed in a package's `bin` (there are few, and they are small),
   or normalise their mode in the store before hashing.
2. **`.store-meta.json` inside the entry works, but has costs.** The linker and the hasher must both skip it,
   and a package that ships its own top-level `.store-meta.json` would collide with it. Alternatives:
   - put package files under `<entry>/files/`;
   - or keep the metadata next to the entry, as `<version>-<ref>.json`.

   This is worth deciding before Phase 1 fixes the layout.
3. **In-place writes are the real hazard.** Anything that writes into a vendor file in place changes it for
   every project and for the store. Examples: an editor that saves in place, a debugging `file_put_contents()`.
   None of the checks did this.
   - Tools that save by writing a new file and renaming it only detach that one file from the store.
   - Read-only mode (Phase 2) turns in-place writes into loud failures. Because chmod is per inode, it covers every project at once.
4. **Pruning in hardlink mode cannot break a project.** A project's links keep the data alive after the store
   entry is deleted. The project only stops sharing.
   - A link count of 1 means no project uses the file, as planned.
   - A project that removes the plugin but keeps its linked files still counts as a user until it reinstalls.
5. **The library-only installer skips one package.** In the Laravel 13 tree, 109 of the 110 packages have
   `"type": "library"` and one declares `"type": "project"`. A library-only installer leaves that one to
   Composer, which copies it. That is fine, but worth confirming it's intended.
6. **Composer as root disables plugins** in non-interactive runs unless `COMPOSER_ALLOW_SUPERUSER=1` is set.
   That happened here. Docker and CI users will hit it, so the README (Phase 5) should mention it.
7. **Some sandboxes block GitHub's archive endpoints.** This one did, while `git` over HTTPS still worked, so
   every Packagist dist download failed.
   - `tools/github-dist-shim` (a sandbox-only global plugin) builds the same zips with `git archive` from the same commits.
   - The integration tests (real `composer install` runs) will need it in such sandboxes. GitHub Actions won't.

## Control run: symlinked package dirs

Run on 2026-10-05 with `spike/symlink-control.sh` on macOS (APFS), PHP 8.4.25, Composer 2.10.2. The spike
above kept package directories real and linked their files. This run tests the alternative, the one the
no-symlinks rule had only argued against: `vendor/<vendor>/` stays a real directory, and each
`vendor/<vendor>/<name>` is a symlink into a shared store. From the outside, that is how pnpm's
`node_modules` looks.

- Two apps, each copied and installed with plain Composer, in a Composer home without global plugins:

  | app | test runner | package dirs turned into symlinks |
  |---|---|---|
  | fresh `laravel/laravel`, Laravel 13.34.0 | PHPUnit 12.5.38 | 109 of 109 |
  | an app with Pest, Boost and Pail, Laravel 13.34.0 | Pest 5.3.0 | 131 of 132 (`pestphp/pest-plugin` is a Composer plugin) |

- Each app ran the spike's checks (all but `serve`) as installed, then again with every dist package dir
  moved into a store (same layout as composer-store's) and replaced by a symlink to it. A copy of each app
  used the same symlinks, so two apps shared one store.

| check | as installed | symlinked, PHPUnit app | symlinked, Pest app |
|---|---|---|---|
| `php artisan test` | pass | **fail** | **fail** |
| `vendor/bin/phpunit`, or `vendor/bin/pest` in the Pest app | pass | pass | **fail** |
| probe: Laravel's files are inside the app's `vendor/` | pass | **fail** | **fail** |
| the other 12: `about`, `package:discover`, Pint, `migrate:fresh`, `optimize`, `optimize:clear`, Tinker, three publish commands, `composer dump-autoload -o`, `composer install` | pass | pass | pass |

### Why the test runners fail

- PHP resolves symlinks in `__FILE__` and `__DIR__`, whichever directories above them are real. The probe
  found `Application.php` at `store/packages/laravel/framework/v13.34.0-c829b4982d29/files/src/…`. `basePath()`
  was still the app, and discovery still found the same 6 providers.
- Test runners find the app's autoloader by counting directories up from their own file, and from the store
  that count lands outside the app:
  - `php artisan test` (Collision 8.9.5) starts `vendor/phpunit/phpunit/phpunit` or
    `vendor/pestphp/pest/bin/pest` directly, not through Composer's bin proxy.
  - PHPUnit uses the autoloader path that Composer's proxy hands it in `$GLOBALS['_composer_autoload_path']`,
    so `vendor/bin/phpunit` works. Started directly, it tries `__DIR__ . '/../../autoload.php'` and stops with
    "You need to set up the project dependencies using Composer".
  - Pest never reads the proxy's path. It tries `dirname(__DIR__, 4).'/vendor/autoload.php'`, then
    `dirname(__DIR__).'/vendor/autoload.php'`, so both ways end in `include_once(store/packages/pestphp/pest/v5.3.0-14a3efd918e1/files/vendor/autoload.php)`
    and `Class "Symfony\Component\Console\Input\ArgvInput" not found`.
- No layout of symlinks fixes this: these runners expect their real directory at exactly
  `vendor/<vendor>/<name>`. pnpm's way, symlinks to directories elsewhere inside the project, moves the real
  directory just the same.

### Sharing and lifetime

- An appended line in one app's `vendor/laravel/framework/LICENSE.md` showed up in the other app and in the
  store. Hard links share edits the same way (finding 3); clones do not.
- None of the checks wrote into a package directory, so the store got no new files.
- `composer reinstall laravel/framework` without the plugin removed the symlink, left the store entry
  complete, and extracted a normal copy in its place.
- With the store moved away, the second app failed at once:
  `require(…/vendor/composer/../symfony/polyfill-mbstring/bootstrap.php): Failed to open stream`. It worked
  again once the store was back. A hard link or a clone keeps its data when the store entry goes, so pruning,
  deleting the store, or running the app where the store's path does not exist (a container that mounts only
  the project, a copy on another machine) cannot break it. A symlink can.

Symlinks would save the per-app directory tree that hard links and clones still need (about 5.1 MB per app above), and
installs would make one symlink per package instead of one link per file. They would cost the test runners
and tie every app to the store's path. The rule stands: each package directory is real, and its files are
hard links or clones.

## Not covered here

- Composer doing the linking itself (custom installer, promises): Phase 1.
- Lock and atomic rename under concurrent installs; skip rules for patches, path repos and `--prefer-source`; cross-filesystem copy fallback: Phase 2 and the integration tests.
- Reflinks (ext4 has none), macOS and Windows: Phase 4.

## Reproducing

```sh
spike/run.sh [work-dir]   # prints a summary, writes <work-dir>/results.md and <work-dir>/logs/
spike/symlink-control.sh <laravel-app> [work-dir]   # the control run, on a copy of any Laravel app
```

- `run.sh` needs network access to Packagist and GitHub and takes about 5 minutes with cold caches. It runs on Linux.
- `symlink-control.sh` runs on macOS and Linux and needs git and rsync. It installs a copy of the app from the
  Composer cache, or the network where the cache lacks a package, and never changes the app itself. For the PHPUnit app above:
  `composer create-project laravel/laravel app`, then `spike/symlink-control.sh app`.
- When running as root, prefix the command with `COMPOSER_ALLOW_SUPERUSER=1`.
- Where GitHub archive downloads are blocked, install `tools/github-dist-shim` first.
