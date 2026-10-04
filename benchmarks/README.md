# Benchmarks

`run.php` installs five Laravel projects without composer-store, then with it, and reports how long each install takes and how much disk space the five take together. [RESULTS.md](RESULTS.md) has the numbers.

## The projects

`projects/` holds the `composer.json` and `composer.lock` of each, so every run installs exactly the same packages:

| Project | | Packages |
|---|---|---:|
| `laravel-13` | `laravel/laravel` 13 as created | 110 |
| `laravel-13-livewire` | with `livewire/livewire` | 111 |
| `laravel-13-api` | with `laravel/sanctum`, `spatie/laravel-permission`, `spatie/laravel-query-builder` | 114 |
| `laravel-12` | `laravel/laravel` 12 | 111 |
| `laravel-11` | `laravel/laravel` 11 | 110 |

That is 556 package installs, of 148 different package versions: 78 versions are in all five projects.

## What is measured

1. A warm-up installs each project once, through your own Composer setup and the network, to fill a Composer cache in the work directory. It is not measured.
2. Each run then installs all five projects, after deleting their `vendor/`, in these scenarios:
   - without the plugin;
   - for each `--mode`: with the plugin and an empty store, so the first project to need a package extracts it into the store and the others link it;
   - again with the store that left, as when every package is already in the store.
3. Every scenario runs twice: as a whole `composer install`, and with `--no-autoloader`, which leaves out generating the autoloader. The Laravel projects ask for an optimized autoloader, which takes the same time with or without the plugin.

The installs run offline (`COMPOSER_DISABLE_NETWORK=1`) from the warm cache, and with `--no-scripts`: the numbers leave out downloads and Laravel's package discovery. Times are medians over the runs.

Disk space is the drop in free space on the filesystem during the five installs. Unlike `du`, it also counts the space reflinks share. The store's own size comes from `du`.

## Running it

```sh
php benchmarks/run.php [--work=DIR] [--runs=3] [--mode=auto] [--mode=hardlink] [--composer=PATH]
```

- `--work` is where everything goes, about 1 GB. The default is a directory in the system temp dir. The Composer cache stays there between runs.
- `--mode` can be given several times. The default is `auto`.
- The results go to stdout as Markdown, the progress to stderr.

The plugin under test is the checkout `run.php` belongs to, installed in a Composer home of its own. Your Composer home is only used by the warm-up.

The [Benchmarks workflow](../.github/workflows/benchmarks.yml) runs it on GitHub Actions on Linux (ext4, and Btrfs for reflinks) and macOS (APFS).
