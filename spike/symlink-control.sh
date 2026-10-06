#!/usr/bin/env bash
# Control run for the no-symlinks rule: does a Laravel app still work when each package dir in vendor/ is
# a symlink into a shared store, instead of a dir of links or clones? Findings: spike/RESULTS.md.
#
#   spike/symlink-control.sh <laravel-app> [work-dir]
#
# Copies the app (without vendor/, node_modules/ and .git/) to app-a and installs it with plain Composer:
# in a Composer home of its own, so no global plugin such as composer-store takes part. app-a runs the
# checks as installed ("baseline"). Then every dist package dir is moved into a store and replaced by a
# symlink to it, app-a is copied to app-b with the same symlinks, so both apps use one store, and app-a
# runs the checks again ("symlinked"). Last: an edit in app-a, a plain `composer reinstall`, and app-b with
# the store moved away. Runs on macOS and Linux.

set -uo pipefail

if [ $# -lt 1 ] || [ ! -f "$1/artisan" ]; then
  echo "usage: $0 <laravel-app> [work-dir]" >&2
  exit 2
fi
SPIKE_DIR=$(cd "$(dirname "$0")" && pwd)
SOURCE=$(cd "$1" && pwd)
WORK=${2:-$(mktemp -d "${TMPDIR:-/tmp}/composer-store-symlinks.XXXXXX")}
mkdir -p "$WORK" && WORK=$(cd "$WORK" && pwd)
A=$WORK/app-a B=$WORK/app-b STORE=$WORK/store LOGS=$WORK/logs
mkdir -p "$LOGS"

# The usual download cache, but a Composer home without global plugins.
COMPOSER_CACHE_DIR=${COMPOSER_CACHE_DIR:-$(composer config --global cache-dir 2>/dev/null)}
export COMPOSER_CACHE_DIR COMPOSER_HOME=$WORK/composer-home

step() { printf '\n==> %s\n' "$*"; }

# check <app-dir> <phase> <name> <command...>: pass, or FAIL with the first line that says why.
check() {
  local dir=$1 phase=$2 name=$3 log rc reason
  shift 3
  log=$LOGS/$(basename "$dir").$phase.$name.log
  (cd "$dir" && "$@") > "$log" 2>&1
  rc=$?
  if [ $rc -eq 0 ]; then
    printf '  %-18s pass\n' "$name"
  else
    reason=$(grep -m1 -iE 'error|exception|warning|fail|could not|unable|need to' "$log" | sed "s|$WORK/||g" | cut -c1-150)
    printf '  %-18s FAIL (exit %d): %s\n' "$name" $rc "${reason:-see ${log#"$WORK"/}}"
  fi
}

run_checks() {
  local dir=$1 phase=$2
  step "$(basename "$dir"): $phase checks"
  check "$dir" "$phase" about            php artisan about
  check "$dir" "$phase" package-discover php artisan package:discover
  check "$dir" "$phase" artisan-test     php artisan test
  if [ -f "$dir/vendor/bin/pest" ]; then
    check "$dir" "$phase" bin-pest       vendor/bin/pest
  else
    check "$dir" "$phase" bin-phpunit    vendor/bin/phpunit
  fi
  check "$dir" "$phase" pint             vendor/bin/pint --test
  check "$dir" "$phase" migrate-fresh    php artisan migrate:fresh --force
  check "$dir" "$phase" optimize         php artisan optimize
  check "$dir" "$phase" optimize-clear   php artisan optimize:clear
  check "$dir" "$phase" tinker           php artisan tinker --execute='echo app()->version();'
  check "$dir" "$phase" vendor-publish   php artisan vendor:publish --tag=laravel-errors --force
  check "$dir" "$phase" lang-publish     php artisan lang:publish --force
  check "$dir" "$phase" stub-publish     php artisan stub:publish --force
  check "$dir" "$phase" composer-dump    composer dump-autoload --optimize --no-interaction
  check "$dir" "$phase" composer-install composer install --no-interaction
  check "$dir" "$phase" probe            php "$SPIKE_DIR/spike.php" probe "$dir" "$LOGS/$(basename "$dir").$phase.probe.json"
  php -r '$p = json_decode((string) @file_get_contents($argv[1]), true);
    echo "  __DIR__ of Laravel: ", str_replace($argv[2] . "/", "", $p["application_file"] ?? "?"), "\n";' \
    "$LOGS/$(basename "$dir").$phase.probe.json" "$WORK"
}

# Undo what the checks wrote to the app (published files, caches); vendor/ is ignored by git.
reset_app() { git -C "$1" checkout -q -- . && git -C "$1" clean -fdq; }

# Moves each dist package of a library or project type into the store, as composer-store would store it,
# and leaves a symlink to it in vendor/.
symlink_packages() {
  php -r '
    [$app, $store] = [$argv[1], $argv[2]];
    $moved = 0;
    $kept = [];
    foreach (json_decode(file_get_contents("$app/vendor/composer/installed.json"), true)["packages"] as $p) {
        $reference = $p["dist"]["reference"] ?? "";
        $dir = realpath("$app/vendor/composer/" . ($p["install-path"] ?? ""));
        if (!in_array($p["type"] ?? "library", ["library", "project"], true)
            || ($p["installation-source"] ?? "") !== "dist" || $reference === "" || $dir === false) {
            $kept[] = $p["name"];
            continue;
        }
        $version = preg_replace("{[^A-Za-z0-9._+-]}", "_", $p["version"]);
        $entry = "$store/packages/{$p["name"]}/$version-" . substr($reference, 0, 12) . "/files";
        @mkdir(dirname($entry), 0777, true);
        if (!rename($dir, $entry) || !symlink($entry, $dir)) {
            fwrite(STDERR, "cannot move {$p["name"]} into the store\n");
            exit(1);
        }
        $moved++;
    }
    printf("  %d package dirs moved into the store and replaced by symlinks; left as installed: %s\n",
        $moved, $kept === [] ? "none" : implode(", ", $kept));
  ' "$A" "$STORE"
}

echo "work dir: $WORK"
echo "php $(php -r 'echo PHP_VERSION;'), $(composer --version 2>/dev/null | head -n1), $(uname -sr)"

step "app-a: copy of $SOURCE, installed with plain Composer"
rsync -a --exclude=/vendor --exclude=/node_modules --exclude=/.git "$SOURCE/" "$A/"
git -C "$A" init -q && git -C "$A" add -A &&
  git -C "$A" -c user.name=spike -c user.email=spike@example.invalid commit -qm "app as copied"
if ! (cd "$A" && composer install --no-interaction) > "$LOGS/app-a.install.log" 2>&1; then
  echo "composer install failed, see $LOGS/app-a.install.log" >&2
  exit 1
fi
echo "  $(cd "$A" && php artisan --version)"

run_checks "$A" baseline
reset_app "$A"

step "app-a: vendor/ package dirs become symlinks into the store; app-b is a copy of app-a"
symlink_packages || exit 1
rsync -a "$A/" "$B/"
ls -l "$A/vendor/laravel" | awk '/framework/ { print "  vendor/laravel/" $9, $10, $11 }' | sed "s|$WORK/||g"
sleep 1 && touch "$WORK/marker"

run_checks "$A" symlinked
reset_app "$A"

step "written into package dirs during the symlinked checks, so now into the store that app-b uses too"
written=$(find "$STORE" -newer "$WORK/marker" 2>/dev/null | sed "s|$WORK/||")
echo "${written:-  nothing}" | head -n 10

step "edit vendor/laravel/framework/LICENSE.md in app-a"
FILE=vendor/laravel/framework/LICENSE.md
echo "edited in app-a" >> "$A/$FILE"
echo "  app-b's copy changed:     $(grep -q 'edited in app-a' "$B/$FILE" && echo yes || echo no)"
echo "  the store's copy changed: $(grep -qs 'edited in app-a' "$STORE"/packages/laravel/framework/*/files/LICENSE.md && echo yes || echo no)"

step "app-a: plain composer reinstall laravel/framework"
check "$A" after composer-reinstall composer reinstall laravel/framework --no-interaction
echo "  store entry still complete: $(ls "$STORE"/packages/laravel/framework/*/files/composer.json > /dev/null 2>&1 && echo yes || echo no)"
echo "  app-a's vendor/laravel/framework: $([ -L "$A/vendor/laravel/framework" ] && echo 'still a symlink' || echo 'a real dir again')"

step "app-b with the store moved away (a pruned entry, or a container that cannot see the store)"
mv "$STORE" "$STORE.away"
check "$B" store-away about php artisan about
mv "$STORE.away" "$STORE"
check "$B" store-back about php artisan about
