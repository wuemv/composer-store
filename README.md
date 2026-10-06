# composer-store

A Composer 2 plugin that gives PHP a pnpm-style global package store.

Every package version is extracted **once** into a store on your machine. Each project's `vendor/` is then filled with links to those files instead of fresh copies: copy-on-write clones (reflinks) where the filesystem supports them, hard links elsewhere. Ten projects on the same Laravel version share one copy of `laravel/framework`.

PHP still sees ordinary files inside `vendor/`: `__DIR__`, relative includes, autoloading and Laravel package discovery work as before. `composer.json` and `composer.lock` are not touched, so teammates and CI without the plugin use the same lock file.

## Requirements

- Composer 2.0 or later. Tested with 2.0, 2.2, 2.8 and 2.10.
- PHP 8.1 or later.
- Linux, macOS or Windows.
- On macOS, PHP's FFI extension, so the plugin can clone each package with one `clonefile(2)` call. Without it, the plugin runs `cp` for each package, which places the files ten times slower: 2.3 s instead of 0.23 s for the 148 packages of the benchmarks. `php -m` lists `FFI` when you have it; PHP allows it on the command line by default. `store:monitor` needs it too, to tell clones from copies.
- The store and your projects on the same filesystem, since links cannot cross filesystems. Elsewhere, Composer installs as usual, with a warning.

## Install

Install it globally, so it works in every project without being added to them. The install script runs the commands below, then checks that Composer loads the plugin:

```sh
curl -fsSL https://raw.githubusercontent.com/wuemv/composer-store/HEAD/install.php | php
```

On Windows, in PowerShell:

```powershell
irm https://raw.githubusercontent.com/wuemv/composer-store/HEAD/install.php | php
```

Add `-- --dry-run` after `php` to see the commands without running them. In a clone, `php install.php --help` lists the other options: where to install from, and which Composer to run.

Or run the commands yourself. The package is not on Packagist yet, so they install it from GitHub:

```sh
composer global config repositories.composer-store vcs https://github.com/wuemv/composer-store
# Composer 2.2+ asks before running a new plugin: allow this one first.
composer global config allow-plugins.wuemv/composer-store true
composer global require wuemv/composer-store:@dev
```

Once it is on Packagist, `composer global require wuemv/composer-store` after the `allow-plugins` line will do, or `--from=packagist` for the script.

From then on, `composer install`, `update`, `require` and `remove` go through the store:

```
  - Installing laravel/framework (v13.34.0): Extracting archive into the store
  ...
  - Installing laravel/framework (v13.34.0): Linking from store
```

The first project to install a version extracts it into the store. Every other project links it.

You can also require it in a single project (`composer require wuemv/composer-store`), but then it is part of that project's `composer.json` and `composer.lock`, which the global install avoids.

## How it works

Composer still resolves dependencies, writes the lock file and downloads archives into its cache as usual. The plugin replaces the step that puts a package's files into `vendor/<package>`, and leaves out the download when the store already holds that version:

1. The store key is the package name, version and `dist.reference` (usually a commit hash).
2. If the store does not hold that version yet, the archive is extracted into a temp dir in the store, hashed, and renamed into place in one step.
3. The store's files are linked into `vendor/<package>`: cloned, hard-linked, or copied as a last resort.

The store lives in `$COMPOSER_HOME/store` (`composer global config home` prints that directory), or wherever `COMPOSER_STORE_DIR` points:

```
packages/<vendor>/<name>/<version>-<reference>/
    files/              the package, as Composer extracts it
    .store-meta.json    name, version, full reference, tree hash, creation time, dist URL
tmp/                    where entries are built
projects.json           the projects that use the store
```

Concurrent installs are safe: entries appear with an atomic rename, and installs share a lock that only `store:prune` takes exclusively.

## Configuration

Settings go in `extra.composer-store`, either in a project's `composer.json` or in the global one, `$COMPOSER_HOME/composer.json`:

```json
{
    "extra": {
        "composer-store": {
            "mode": "auto",
            "exclude": ["acme/patched-in-place", "legacy/*"],
            "read-only": false
        }
    }
}
```

From the command line: `composer global config extra.composer-store.mode hardlink`, or `composer global config --json extra.composer-store.exclude '["legacy/*"]'` for a list. A project's settings replace the global ones key by key, except `exclude`, where both lists apply.

| Setting | Values | |
|---|---|---|
| `mode` | `auto` (default) | Reflinks where the filesystem supports them, hard links elsewhere. |
| | `reflink` | Reflinks only. Where they are not supported, Composer installs as usual, with a warning. |
| | `hardlink` | Hard links, even where reflinks work. |
| | `copy` | Turns the plugin off: Composer installs as usual. |
| `exclude` | package names, `*` matches anything | Installed as usual, never linked. |
| `read-only` | `true`, `false` (default) | Removes the write bits of the store's files, so editing a linked file fails instead of changing it for every project. |

Reflinks work on APFS (macOS), and on Linux filesystems that have them, such as Btrfs and XFS (with GNU cp). Windows uses hard links.

With **reflinks**, every file in `vendor/` is a file of its own that shares its data with the store until it changes: editing it changes nothing else. With **hard links**, a file in `vendor/` *is* the store's file, so editing it changes it in every project that uses that version (most editors write files in place). Turn on `read-only` to make such edits fail, and see `store:verify` below.

Environment variables:

| Variable | |
|---|---|
| `COMPOSER_STORE_DIR` | Where the store lives. Default: `$COMPOSER_HOME/store`. |
| `COMPOSER_STORE_LOCK_TIMEOUT` | Seconds an install waits for `store:prune` to finish before installing without the store, and `store:prune --force` waits for running installs. Default: 60. |

### Packages that are installed as usual

These are never linked. Composer installs them as it would without the plugin:

- source installs (`--prefer-source`, or packages without a dist archive)
- packages from `path` repositories, and dists other than zip and tar
- packages patched by [cweagans/composer-patches](https://github.com/cweagans/composer-patches), since patches are applied in place
- types other than `library` and `project`: Composer plugins, metapackages, and types with an installer of their own, such as those of `composer/installers`
- anything in `exclude`

## Commands

```sh
composer store:status            # where the store is, its size, and the disk space it saves
composer store:verify            # re-hashes the store, reports files that changed
composer store:prune             # lists the versions no project uses; --force deletes them
composer store:monitor [dir]     # watches the projects in a directory: what each takes on disk, live
composer store:dashboard [dir]   # the same, as live charts in your browser
```

All of them work inside or outside a project, and all but `store:dashboard` take `--format=json`.

A global install also adds a `composer-store` command, in Composer's global `vendor/bin` next to `laravel` and `valet`, which must be on your PATH (it is when those work): `composer-store dashboard` runs `composer store:dashboard`, and so on, with the same options. On macOS, when the PHP it runs on has no FFI, it runs Composer with a PHP of the same version on your PATH that has it, such as Herd's, so the monitor can tell clones from copies. `COMPOSER_STORE_PHP` and `COMPOSER_STORE_COMPOSER` name the PHP and Composer to use instead.

```
$ composer store:status
Store:        /home/me/.config/composer/store
Packages:     110 packages, 110 versions
Size:         66.6 MiB in 8,799 files
Linked:       8,792 files, by 17,584 hard links from vendor/ directories
Saved:        126.0 MiB
Projects:     2 projects registered
This project: links from the store with hard links
Settings:     mode auto, read-only off
```

`store:verify [packages...]` exits with 1 when a store entry no longer matches the hash taken when it was stored, and lists the files modified since. To repair one, delete its directory, then run `composer reinstall <package>` in each project that uses it.

`store:prune` only lists what it would delete. With `--force`, it deletes the versions no project uses: none of their files is linked from a `vendor/`, and no project in `projects.json` lists them. It also cleans up leftovers of interrupted installs, and forgets registered projects whose directory is gone. It waits for running installs, and installs that start meanwhile wait for it.

`store:monitor <dir>` watches the Composer projects in a directory and up to three levels below it, and redraws every 2 seconds (`--interval`) while you install, update or remove packages in another terminal. `--once` measures once. It looks at the files themselves rather than at what the store remembers, so a project installed without the plugin shows as copies, like `app-3` here:

```
$ composer store:monitor ~/Sites
Watching for 00:00:12, every 2 s. Ctrl+C stops.
Projects in /Users/me/Sites

Disk:    34.8 GiB free, 29.1 MiB used since the monitor started
Store:   103.9 MiB in 180 package versions, 103.9 MiB of it used by these projects

Project      Packages   From the store   Finder size    Own data
app-1             132   131 (reflinks)      78.1 MiB     2.8 MiB
app-2             132   131 (reflinks)      78.1 MiB     2.8 MiB
app-3             132                0      78.1 MiB    78.1 MiB
laravel-12        111   111 (reflinks)      64.7 MiB   428.0 KiB
Total             507              373     299.1 MiB    84.1 MiB

Without the store, these vendor/ directories take 299.1 MiB.
With the store, they take 188.0 MiB: 103.9 MiB of store data they share, plus 84.1 MiB of their own.
Saved: 111.1 MiB (37%)
```

*Finder size* is what Finder and `du` show for a `vendor/`: every file at its full size, clones included. *Own data* is what the project takes that no other file shares, such as Composer's autoloader. The disk line is measured, not computed: the free space, and how much it changed since the monitor started. The monitor takes no lock, so installs never wait for it.

Without a directory, both ask for one and suggest `~/Developer` (or, where there is none, the current directory): press Enter to take it. Without a terminal to ask in, they take the suggestion.

The first measurement reads every file, which takes a while for many projects: about 35 seconds for 100 projects and a million files on an Apple-silicon Mac, with its progress shown. After that, they measure again only the projects whose `vendor/` changed or that lost a store entry they linked, plus one other per refresh, so a refresh takes a fraction of a second.

A package comes from the store when its files are hard links of the store's (same inode) or clones of them (same clone ID). On macOS, telling a clone from a copy takes PHP's FFI extension: without it, the monitor shows `?` in those columns. On Linux, it sees hard links, not yet reflinks.

`store:dashboard <dir>` measures the same way and shows it as live charts in your browser: the space saved, the projects' `vendor/` directories with and without the store over time, the measured free space, and each project's size against the data it holds of its own, with a table under each chart. It serves the page on `127.0.0.1` only, at any free port (`--port` picks one), and opens it unless you pass `--no-open`. It measures only while it runs: Ctrl+C stops the measuring and the page together, and nothing stays in the background. The page needs nothing from the internet.

## Uninstall

```sh
composer global remove wuemv/composer-store
```

Your projects keep working: the files in their `vendor/` are ordinary files that share their data with the store. To give a project its own copies again, reinstall its dependencies:

```sh
rm -rf vendor && composer install
```

Then delete the store, `$COMPOSER_HOME/store` or your `COMPOSER_STORE_DIR`. Deleting it never breaks a project either: a file's data stays on disk until its last link is gone.

## Limitations

- The store must be on the same filesystem as `vendor/`. On Btrfs, subvolumes count as separate filesystems. In a container, a project in a bind mount is on another filesystem than the container's Composer home: point `COMPOSER_STORE_DIR` at a directory on the project's filesystem.
- Windows has hard links only: Dev Drive and ReFS block cloning are not used. `read-only` is ignored on Windows, where a read-only file cannot be deleted.
- Filesystems limit the links to one file (65,000 on ext4, 1,023 on NTFS). Past that, the package is copied, with a warning.
- Filling the store takes time: a version installed for the first time is extracted into the store, then linked. In the benchmarks, the first project installed into an empty store took from 28% longer to two and a half times as long as without the plugin. Installs from a warm store took 3 to 33% less time, except with hard links on macOS, which took 15 to 36% more: APFS is slow at making hard links, so `auto` clones there.
- `store:prune` keeps a version while any registered project lists it, including projects that no longer use the plugin, until their directory is deleted.
- Composer's own cache still holds the downloaded archives: `composer clear-cache` frees it.
- One store shared by several users of a machine is not supported.

## Benchmarks

Five Laravel projects (Laravel 11, 12 and 13, two with extra packages) install 556 packages, 148 different ones. With the plugin they take about half to two thirds less disk space, store included. Installing them from a warm store took 3 to 27% less time than without the plugin on Linux, depending on the machine and filesystem, and 27 to 33% less on macOS.

[benchmarks/RESULTS.md](benchmarks/RESULTS.md) has the numbers for Linux (ext4 and Btrfs) and macOS, and [benchmarks/](benchmarks/) explains how to run the benchmark.

## Development

`composer test` runs the unit and integration tests, `composer test:network` the tests that need Packagist and GitHub, and `composer lint` PHPCS and PHPStan. [CLAUDE.md](CLAUDE.md) describes the design and the test setup.
