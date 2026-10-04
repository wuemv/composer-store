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
    ...package files...
    .store-meta.json        # tree hash, created_at, source dist url
  projects.json             # absolute paths of projects using the store
  .lock
```

Implementation outline:

- `composer.json`: `"type": "composer-plugin"`, requires `composer-plugin-api: ^2.0`, PHP 8.1+.
- Plugin class implements `PluginInterface` (and `Capable` for commands).
- Register a custom installer extending `Composer\Installer\LibraryInstaller` that handles the `library` package type only. Other types (composer-plugin, metapackage, custom installer types from `composer/installers`) are left to their existing installers.
- Override the install / update / remove code steps. In Composer 2 these return promises, so stay async-compatible.
- Remove = delete `vendor/<pkg>` only. Never delete from the store during a project operation.

Packages that must be **copied, not linked** (skip rules):

- source installs (`--prefer-source`, git clones, `dist` missing)
- `path` repositories
- packages patched via `cweagans/composer-patches` (check `extra.patches` in root package)
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

`mode`: `auto` | `reflink` | `hardlink` | `copy`. `auto` picks reflink if supported, else hardlink if store and project share a filesystem (compare device IDs), else copy with a warning.

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

### Phase 4: Reflinks + cross-platform

- macOS APFS: `cp -c` / `cp -cR`.
- Linux Btrfs/XFS: `cp --reflink=auto`.
- Windows NTFS: hard links only.
- Auto-detect per machine.

### Phase 5: Release

- README: install (`composer global require`), `allow-plugins` line, config, uninstall steps, limitations.
- Benchmarks in `benchmarks/`: disk usage and install time across 5 Laravel projects, with and without the plugin.

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

## Conventions

- PSR-12, strict types, small focused classes.
- Namespace: `ComposerStore\`.
- Every phase ends with passing tests and a short summary of what changed and any open questions.
- Don't add dependencies beyond `composer-plugin-api` and dev tooling without asking.
