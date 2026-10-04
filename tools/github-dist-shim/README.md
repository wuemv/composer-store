# github-dist-shim (sandbox-only dev tool)

Some sandboxes, including the Claude Code cloud environment this project was started in,
allow `git` over HTTPS to github.com but block GitHub's archive endpoints
(`api.github.com/repos/*/zipball/*`, `codeload.github.com`). Composer downloads almost
every Packagist dist from those endpoints, so `composer install` fails there.

This global Composer plugin catches those downloads (`PRE_FILE_DOWNLOAD`). For each one it
shallow-fetches the exact commit and builds the zip with `git archive`, which is how GitHub
builds zipballs too, so `export-ignore` and `export-subst` behave the same. Composer then
installs from that local zip as a normal dist install. The Composer cache key stays the same,
and `composer.lock`, `installed.json` and `vendor/` are what you would get without the shim.

It is not part of composer-store. Don't install it on machines that can reach GitHub.

## Install

```sh
export COMPOSER_ALLOW_SUPERUSER=1   # only when running Composer as root: otherwise plugins are disabled
composer global config repositories.github-dist-shim \
  '{"type":"path","url":"'"$PWD"'/tools/github-dist-shim","options":{"symlink":true}}'
composer global config allow-plugins.composer-store/github-dist-shim true
composer global require composer-store/github-dist-shim:@dev
```

Run that from the repository root, because the path repository uses `$PWD`.

- Archives and shallow repos are kept in `$COMPOSER_CACHE_DIR/github-dist-shim`. Set `GITHUB_DIST_SHIM_DIR` to put them somewhere else.
- `GITHUB_DIST_SHIM_DISABLE=1` turns the shim off for one command.
- `composer global remove composer-store/github-dist-shim` uninstalls it.
