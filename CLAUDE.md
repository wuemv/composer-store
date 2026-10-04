# composer-store

A Composer 2 plugin that gives PHP a pnpm-style global package store.

Every package version is extracted **once** into a global store on disk. Each project's `vendor/` is then populated by **linking** files from that store (reflink → hardlink → copy fallback). Projects keep their own versions from their own `composer.lock`, and PHP sees normal files inside `vendor/`, so `__DIR__`, relative includes, autoloading and Laravel package discovery all behave exactly as today.

## Goals

- One copy on disk per package version, shared by all projects on the machine.
- Faster installs once a version is in the store (link instead of unzip).
- Zero changes to `composer.lock` or `composer.json` format. A project with the plugin and one without must use the exact same lockfile.
- Uninstalling the plugin and running `composer install` returns a project to normal.

## Non-goals

- Multiple versions of the same package inside ONE project. PHP has a single global class table per process, so this is impossible and out of scope.
- Replacing Composer's resolver, lockfile, downloader or autoloader.
- Changing `composer.lock`.

## Hard rules

- **Never use symlinks for package files.** PHP resolves `__DIR__` to the real path, which breaks packages that walk up to find `vendor/`. Use reflinks or hard links only, with copy as a fallback.
- Do not modify `composer.lock` or write plugin data into it. Plugin metadata lives in the store only.
- Writes to the store must be atomic: extract to a temp dir inside the store, then `rename()` into place, guarded by a lock file.
- If anything is uncertain (unknown install type, different filesystem, failure mid-link), fall back to Composer's normal copy behaviour rather than failing the install.

## Architecture

```
composer install
  ├─ Composer resolves + downloads as normal (zip in Composer cache)
  └─ Plugin replaces the "put files in vendor/" step:
       1. key = name + version + dist.reference (git commit hash)
       2. in store? no → extract to temp, atomic rename into store
       3. link store → vendor/<pkg>  (reflink → hardlink → copy)
```

Store key uses `dist.reference`, not `dist.shasum`. Packagist's `shasum` is usually empty because GitHub zipballs are not byte-stable. The plugin computes its own tree hash on first store and saves it in `.store-meta.json`.

Store layout:

```
$COMPOSER_STORE_DIR (default: $COMPOSER_HOME/store)
  packages/<vendor>/<name>/<version>-<reference-short>/
    files/                  # the package exactly as Composer extracts it; this is what gets linked
    .store-meta.json        # name, version, full reference, tree hash, created_at, source dist url
  tmp/                      # entries are built here, then renamed into packages/ in one step
  projects.json             # projects that linked from the store, with their vendor dir (read by store:prune)
  .lock
```

`<reference-short>` is the first 12 characters of a commit hash, or a hash of the reference when it is not one. The full reference is in `.store-meta.json` and is checked before an entry is used. Package files live in `files/` so the metadata never mixes with them and an entry appears with a single `rename()`.

Concurrency:

- Installs hold `.lock` **shared** for the whole run, so they never wait for each other. Anything that deletes from the store (prune) must hold it **exclusively**, so it never removes an entry an install is about to link. An install waits up to `COMPOSER_STORE_LOCK_TIMEOUT` seconds (default 60) for an exclusive holder, then installs without the store.
- Adding an entry is one `rename()` of a complete temp dir. If another install published the same entry first, the loser uses it when the tree hashes match, and otherwise installs its own extraction without the store.
- There is deliberately no blocking per-entry lock: Composer extracts packages concurrently inside one process, so two installs each holding one entry while waiting for the other's would deadlock.
- `store:verify` holds `.lock` shared. `store:prune --force` holds it exclusively, plans only once it has it, and deletes an entry by first renaming it into `tmp/`, so an interrupted prune never leaves a partial entry for an install to link.
- `projects.json` is a read-modify-write under its own `projects.json.lock`, replaced with an atomic rename. Installs register their project after taking the store lock, best effort.

Implementation outline:

- `composer.json`: `"type": "composer-plugin"`, requires `composer-plugin-api: ^2.0`, PHP 8.1+.
- Plugin class implements `PluginInterface` (and `Capable` for commands).
- Register a custom installer extending `Composer\Installer\LibraryInstaller` that handles the `library` and `project` package types: Composer's default installer puts both in `vendor/<name>`, and tools such as `laravel/pint` are of type `project`. Other types (composer-plugin, metapackage, custom installer types from `composer/installers`) are left to their existing installers.
- Override the install / update / remove code steps. In Composer 2 these return promises, so stay async-compatible.
- `download()` is overridden too: when Composer would install a package from its dist and the store holds a valid entry for it, the archive is neither downloaded nor copied out of Composer's cache into `vendor/composer/tmp-*`, which the install would leave unused. Composer's dist-or-source choice is private (`DownloadManager::getAvailableSources()`, unchanged from 2.0 to 2.10; `InstallTest` fails if a later Composer breaks the skip), so the installer asks it through reflection and downloads as usual when that fails, and sets the installation source to `dist` itself, as Composer would. The store lock is taken there at the latest, so prune cannot remove the entry before the install; should it go anyway (by hand), the install fetches the archive after all.
- Remove = delete `vendor/<pkg>` only. Never delete from the store during a project operation.
- With hard links, files listed in a package's `bin` are copied, not linked: Composer chmods them in place on install and update, which through a hard link would change the store's copy for every project. Clones are files of their own, so with reflinks they are cloned like the rest.
- On Linux, hard links are made by one `cp -R -l -P` per package (`Link\HardLinker`), several at once through Composer's async ProcessExecutor like the `cp` clones, and the `bin` files are then replaced by copies (`Linker::linkAsync()`). `-P` keeps symlinks as symlinks, hard links to themselves: without it GNU cp turns a package's symlinks into hard links to their targets, and fails on dangling ones. A probe under the store lock links a small tree with a file and a symlink and checks both, so a cp that behaves otherwise, or none, leaves hard links to PHP, file by file, as on macOS and Windows. With `-v` the installer says "with hard links, through cp" or "file by file".

Packages that must be **copied, not linked** (skip rules):

- source installs (`--prefer-source`, git clones, `dist` missing)
- `path` repositories
- packages patched via `cweagans/composer-patches`: targets of the root `extra.patches`, of `extra.patches-file` (1.x), of `extra.composer-patches.patches-file` or `patches.json` and `patches.lock.json` (2.x), and of any dependency's `extra.patches`
- anything in the user's `exclude` list

Config (root `composer.json` `extra`, also readable from global Composer config):

```json
"extra": {
  "composer-store": {
    "mode": "auto",
    "exclude": ["vendor/package"],
    "read-only": false
  }
}
```

`mode`: `auto` | `reflink` | `hardlink` | `copy`. `auto` picks reflink if supported, else hardlink if store and project share a filesystem (compare device IDs), else copy with a warning. `reflink` never falls back to hard links: where reflinks are not supported it warns and leaves installs to Composer. `copy` does not use the store.

`exclude`: package names, `*` matches any characters. The global and project lists are combined; other keys from the project replace the global ones.

`read-only`: removes the write bits of store files, so editing a linked file in `vendor/` fails. Hard links share permissions, so it applies to every project linking those versions, and existing entries become read-only when a read-only project links them. Clones keep the permissions of the store's files, so they are read-only too, although an edit to a clone could not reach the store anyway. Root ignores file permissions. Not supported on Windows yet (a read-only file cannot be deleted there): ignored with a warning. Turning it off does not restore write bits; `chmod -R u+w "$COMPOSER_STORE_DIR/packages"` does.

## Phases

### Phase 0: Spike (do this first)

Before writing the plugin, prove the theory with a standalone script in `spike/`:

1. Create a fresh Laravel app (`composer create-project laravel/laravel`).
2. Move its `vendor/` package dirs into a fake store, hard-link them back file by file.
3. Run: `php artisan about`, `php artisan package:discover`, `php artisan test`, `vendor/bin/phpunit`, `vendor/bin/pint --test`.
4. Report results in `spike/RESULTS.md`, including any package that broke and why.

Stop and report back after Phase 0 before continuing.

### Phase 1: MVP plugin

- Plugin + custom `library` installer.
- Store extraction with key `name + version + reference`.
- Hard-link file by file into `vendor/`, copy fallback on different filesystem.
- Works for `install`, `update`, `require`, `remove`.

### Phase 2: Safety

- Lock file + temp dir + atomic `rename()` for concurrent installs.
- Skip rules listed above.
- Tree hash in `.store-meta.json`.
- Optional read-only mode (chmod store files read-only so accidental edits in `vendor/` fail loudly).

### Phase 3: Commands

Via `CommandProvider`:

- `composer store:status`: store path, size, package count, estimated disk saved.
- `composer store:verify`: re-hash store entries, report corruption.
- `composer store:prune`: remove unused entries. Hardlink mode: a file with link count 1 is unused. Other modes: scan projects in `projects.json` and their `vendor/composer/installed.json`. Default to dry-run, require `--force` to delete.

As built:

- All three take `--format=text|json` (JSON keys are kebab-case, like Composer's own JSON output), print results on stdout, and work outside a project too. Inside one, `store:status` also says whether that project links from the store and why not.
- Disk saved = for each store file, size on disk × (link count − 1): what the `vendor/` links would take as copies.
- `store:verify [packages...]` (names, `*` wildcard) exits 1 when an entry is changed (files differ from the tree hash; files modified after `created_at` are listed) or invalid (missing `files/` or metadata, or metadata for another key). Entries without a tree hash are counted, and listed with `-v`. Repair: delete the entry, then `composer reinstall <package>` in each project using it.
- `store:prune` works per entry, not per file: an entry is in use while any of its files has a link count above 1 (a package's `bin` files are copies, so they cannot count), or while a registered project's `installed.json` lists that name, version and dist reference. Both rules always apply, so a project that copied (or, later, reflinked) keeps its entries. It also deletes `tmp/` leftovers and forgets registered projects whose directory is gone. With `--force` it waits up to `COMPOSER_STORE_LOCK_TIMEOUT` for running installs, then gives up without deleting anything (exit 1).

### Phase 4: Reflinks + cross-platform

- macOS APFS: `cp -c` / `cp -cR`.
- Linux Btrfs/XFS: `cp --reflink=auto`.
- Windows NTFS: hard links only.
- Auto-detect per machine.

As built:

- `Link\Cloner` clones a package at a time. On macOS it calls clonefile(2) through PHP's FFI extension (`Link\CloneFile`; `ffi.enable` allows FFI on the command line by default): one call clones a whole package inside the kernel, in about 1.6 ms, so the installer clones in Composer's process, and the probe uses it too. Elsewhere, or without FFI, it runs the system `cp` once per package, never through a shell, several at once through Composer's async ProcessExecutor (`installCode()` returns their promises): `cp -R -T --reflink=always --preserve=mode,timestamps` on Linux (GNU cp; fails where the filesystem has no reflinks), `cp -c -R -p` on macOS. `cp -c` quietly copies on volumes without clonefile, so that path also requires the store's volume to be APFS (from `mount`, longest mount point containing it); clonefile(2) fails there instead. Anything else, Windows included, has no clones. With `-v`, the installer says which way it clones ("with reflinks, through clonefile(2)" or "through cp").
- Detection runs once per install, under the store lock: same filesystem (device IDs), then a clone of a small probe file in the store's `tmp/` for `auto` and `reflink`. A clone that fails for one package falls back to copying that package from the store, with a warning, like a failed hard link.
- `projects.json` records each project's method (`reflink` or `hardlink`). Prune keeps an entry while a registered project lists it, which is the only sign of a clone: link counts stay at 1. `store:status` adds the size of entries that reflinked projects list (dist installs) to the saved space, as an estimate: a clone stops sharing a file once it is edited.
- The integration tests pin `mode: hardlink` in their global Composer config, so the existing tests mean the same on APFS. `ReflinkTest` sets the mode per project and clones for real on APFS or in `COMPOSER_STORE_TEST_REFLINK_DIR`; elsewhere on Linux it puts a stand-in `cp` first on the PATH, which copies, to run the same code. CI mounts a Btrfs image for one Linux job, and loads FFI on macOS (a step fails without it), so the macOS job clones through clonefile(2); `ClonerTest` covers the macOS `cp` path with `new Cloner('Darwin', inProcess: false)`.

### Phase 5: Release

- README: install (`composer global require`), `allow-plugins` line, config, uninstall steps, limitations.
- Benchmarks in `benchmarks/`: disk usage and install time across 5 Laravel projects, with and without the plugin.

As built:

- `README.md` covers install (Packagist, and from GitHub until it is published there), settings, commands, uninstall and limitations. `.gitattributes` keeps everything but `src/`, `composer.json` and the README out of the dist archives.
- `benchmarks/run.php` (with `Benchmark.php`) installs the five projects in `benchmarks/projects/`, whose `composer.json` and `composer.lock` pin every package: offline from a warm Composer cache, `--no-scripts`, with and without `--no-autoloader`, without the plugin and per mode from an empty and a warm store. Disk space is the drop in free space, which counts reflinks right where `du` cannot. `benchmarks/README.md` explains the method, `benchmarks/RESULTS.md` has the numbers.
- The Benchmarks workflow runs it on ext4, Btrfs and APFS, by hand only (workflow_dispatch), with the results in the job summaries. PHPCS and PHPStan cover `benchmarks/` too.
- Results (compare within a run: GitHub's runners vary, and its Linux runners have 2 CPUs for private repositories, 4 for public ones): about half to two thirds less disk space everywhere. On macOS, installs from a warm store went from +88% over no plugin (one `cp` clone after the other) to +50% (`cp` clones in parallel) to between −10% and +15% over four runs (clonefile(2) through FFI, once `benchmarks/run-placement.php` showed it placing the 148 packages in 0.23 s against 1.7 to 2.3 s for `cp` clones and 2.6 s for Composer's parallel unzip), and without the autoloader they are 19 to 38% faster than no plugin. What is left is the optimized autoloader reading clones from disk: a clone shares disk blocks, not the page cache. Hard links are slow on APFS. On Linux, hard links made file by file in one process were 8 to 22% faster than no plugin on 2-CPU runners but from 5% faster to 14% slower on 4-CPU ones, where Composer's ten parallel unzips gain more; one `cp -R -l -P` per package, several at once (95b5889), made them 2 to 4% slower on ext4 and 8 to 23% faster on Btrfs on the 4-CPU runners, and 17% faster in the sandbox. Reflinks on Linux range from 8% faster to 11% slower.
- Never commit two paths that differ only by case: macOS and Windows keep one of them (the placement runner once required itself on APFS). The static CI job checks.

## Testing

Use PHPUnit. Integration tests run real `composer` commands against fixture projects in a temp dir.

Must cover:

- Fresh Laravel app: artisan, tests, package discovery, `vendor:publish`.
- `install`, `update`, `remove`, `require`, install from lockfile on an empty store.
- Two projects on different versions of the same package.
- Two concurrent `composer install` runs sharing one store.
- Patched package, path repo, `--prefer-source` (all must be copied, not linked).
- Store on a different filesystem → copy fallback with warning.
- Uninstall plugin + `composer install` → normal `vendor/`.

CI (GitHub Actions): Ubuntu + macOS + Windows, PHP 8.1 to latest, at least two Composer 2.x minor versions.

## Development

- `composer test` runs the unit and integration suites (no network). `composer test:network` runs the fresh-Laravel-app test, which needs Packagist and GitHub. `composer lint` runs PHPCS (PSR-12) and PHPStan (max level).
- Integration tests use the `composer` on the PATH, or `COMPOSER_STORE_TEST_COMPOSER=/path/to/composer`. They build a local git-backed fixture repository from `tests/Fixtures/packages/` and install the plugin globally into a throwaway Composer home.
- Set `COMPOSER_STORE_TEST_KEEP=1` to keep the test temp dirs. `COMPOSER_STORE_TEST_OTHER_FS` points the cross-filesystem tests at a directory on another filesystem (default `/dev/shm`). `COMPOSER_STORE_TEST_REFLINK_DIR` points `ReflinkTest` at a directory with reflinks (Btrfs, XFS); without it, they are real only on APFS.
- Running Composer as root needs `COMPOSER_ALLOW_SUPERUSER=1`, otherwise plugins are disabled.
- The repository is public, so GitHub's standard runners are free. While it was private, the organization's 2,000 free minutes a month (a macOS minute counts ten, a Windows minute two) ran out in a day: jobs then fail within seconds without a runner, annotated "The job was not started because recent account payments have failed or your spending limit needs to be increased", which is not a code failure. CI runs one test job each on macOS and Windows, with the newest PHP and Composer, while Ubuntu runs PHP 8.1 to 8.5 with the newest Composer 2.x and 8.1 to 8.4 with Composer 2.2. It skips pushes that only change Markdown and cancels a run that a newer push replaces, every job has a timeout, and the Benchmarks and Placement workflows run only by hand.
- `install.php` (repository root, kept out of the dist archives) runs the README's install commands and checks with `composer store:status` that Composer loads the plugin: `curl -fsSL https://raw.githubusercontent.com/wuemv/composer-store/HEAD/install.php | php`. Options: `--from=github` (default) `|packagist|URL|DIR`, `--composer=PATH`, `--dry-run`. It skips `allow-plugins` before Composer 2.2, is written in syntax PHP 7.2 parses so old PHP gets a message, and must stay ASCII (Windows PowerShell 5.1 pipes ASCII). A `--from` directory goes with forward slashes on Windows: `composer global` reads its arguments again with Symfony's `StringInput`, which unescapes backslashes. Output that goes to a file passes through temp files: before `proc_open()` hands a file on, PHP seeks it back to the end of the script's own writes, so each Composer would write over the one before. `InstallScriptTest` runs it offline against a Composer home of its own. When the package is on Packagist, make `packagist` the default there and in the README.
- In sandboxes that block GitHub's archive downloads but allow `git`, install `tools/github-dist-shim` globally (see its README).

## Conventions

- PSR-12, strict types, small focused classes.
- Namespace: `ComposerStore\`.
- Every phase ends with passing tests and a short summary of what changed and any open questions.
- Don't add dependencies beyond `composer-plugin-api` and dev tooling without asking.
