# Contributing to composer-store

Thank you for helping. Bug reports, fixes, docs and tests are all welcome. For a larger change, such as a new command, a new link method or a different store layout, please open an issue first so we can agree on the approach before you write it.

## Reporting a bug

Please include:

- `composer --version` and `php -v`, and whether `php -m` lists `FFI` (macOS).
- Your OS and the filesystem of the store and of the project (APFS, ext4, Btrfs, XFS, NTFS).
- The output of `composer store:status`, run inside the project.
- Your `composer-store` settings, if any (`extra.composer-store` in the project's or the global `composer.json`).
- The output of the failing command with `-v`. With it, the installer says how it links packages and why it skips any.

## Setting up

You need PHP 8.1 or later, Composer 2 and git. On macOS, enable PHP's FFI extension so the clone tests run the way the plugin runs.

```sh
git clone https://github.com/wuemv/composer-store.git
cd composer-store
composer install
```

The tests never touch your own Composer setup. The integration tests build a git-backed package repository from `tests/Fixtures/packages/`, install the plugin into a throwaway Composer home, and run real `composer` commands against fixture projects in a temp dir.

## Running the checks

| Command | What it runs |
|---|---|
| `composer test` | Unit and integration tests, without network |
| `composer test:network` | The tests that need Packagist and GitHub: a fresh Laravel app, composer-patches |
| `composer lint` | PHPCS (PSR-12) and PHPStan at max level, for PHP 8.1 to 8.6 |

Environment variables for the tests:

| Variable | Use |
|---|---|
| `COMPOSER_STORE_TEST_COMPOSER` | The Composer to test with. Default: `composer` on the PATH. |
| `COMPOSER_STORE_TEST_KEEP=1` | Keep the temp dirs of the tests, to look at what they left. |
| `COMPOSER_STORE_TEST_OTHER_FS` | A directory on another filesystem, for the copy-fallback tests. Default: `/dev/shm`. |
| `COMPOSER_STORE_TEST_REFLINK_DIR` | A directory on Btrfs or XFS, for real reflinks on Linux. On APFS they are real anyway. |
| `COMPOSER_ALLOW_SUPERUSER=1` | Needed when you run the tests as root, or Composer disables plugins. |

Tests that need another OS or filesystem are skipped, so a local run shows some skips. CI runs them all: on Ubuntu, PHP 8.1 to 8.6 with the newest Composer and 8.1 to 8.4 with Composer 2.2, plus a Btrfs job, and one job each on macOS and Windows.

If your sandbox blocks GitHub's archive downloads but allows `git`, see [tools/github-dist-shim](tools/github-dist-shim/README.md).

## Ground rules

These keep the plugin safe to run on people's projects. [CLAUDE.md](CLAUDE.md) explains the design behind them: the store layout, locking and concurrency, and the skip rules.

- **No symlinks for package files.** PHP resolves `__DIR__` to the real path, which breaks packages that look for `vendor/` above themselves. Use clones or hard links, with copying as the fallback.
- **Never change `composer.lock`** or the format of `composer.json`. A project with the plugin and one without must share the same lock file.
- **Store writes are atomic:** build the entry in the store's `tmp/`, then `rename()` it into place, under the store lock.
- **When in doubt, fall back** to Composer's normal install instead of failing it.
- **No new dependencies** beyond `composer-plugin-api` and dev tools without an issue first.

## Code and tests

- PSR-12, `declare(strict_types=1)`, the `ComposerStore\` namespace, and small classes that do one thing.
- PHPStan runs at max level with no baseline and no ignored errors. Fix the types rather than silencing it.
- Every change comes with tests. A change to how packages get into `vendor/` needs an integration test that runs Composer.
- Never commit two paths that differ only by case. macOS and Windows keep only one of them, and CI checks for it.

## Commits and pull requests

- Write commit messages as [Conventional Commits](https://www.conventionalcommits.org/): `feat:`, `fix:`, `docs:`, `test:`, `ci:`, `chore:` and so on, in the imperative (`fix: keep bin files out of hard links`).
- Keep a pull request to one change, and say what it changes and why. Include the output that shows it working when you can.
- CI must pass. Pushes that only change Markdown skip it.

## License

By contributing, you agree that your contributions are licensed under the [MIT License](LICENSE).
