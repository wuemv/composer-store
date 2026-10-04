#!/usr/bin/env bash
# Phase 0 spike: does a Laravel app still work when every package file in vendor/
# is a hard link into a shared store? Findings: spike/RESULTS.md.
#
#   spike/run.sh [work-dir]
#
# Creates three fresh apps in the work dir (default: a new temp dir):
#   app-a  laravel/laravel (latest)  its packages are moved into the store, then hard-linked back
#   app-b  laravel/laravel (latest)  same versions: linked from the store (store hits only)
#   app-c  laravel/laravel ^12.0     older versions: partly shared, partly new store entries
# Each app runs the same checks before ("baseline") and after ("linked") linking.
# Needs network access to Packagist/GitHub. Running Composer as root needs COMPOSER_ALLOW_SUPERUSER=1
# for global plugins to load.

set -uo pipefail

SPIKE_DIR=$(cd "$(dirname "$0")" && pwd)
WORK=${1:-$(mktemp -d "${TMPDIR:-/tmp}/composer-store-spike.XXXXXX")}
mkdir -p "$WORK" && WORK=$(cd "$WORK" && pwd)
STORE=$WORK/store
LOGS=$WORK/logs
RESULTS=$WORK/results.tsv
FACTS=$WORK/facts.tsv
mkdir -p "$LOGS" && : > "$RESULTS" && : > "$FACTS"

spike() { php "$SPIKE_DIR/spike.php" "$@"; }
fact() { printf '%s\t%s\n' "$1" "$2" >> "$FACTS"; }
now_ms() { echo $(( $(date +%s%N) / 1000000 )); }
step() { printf '\n==> %s\n' "$*"; }

# check <project> <phase> <name> <command...>: run in the project, record exit code and duration.
check() {
  local project=$1 phase=$2 name=$3 app start rc
  shift 3
  app=$(basename "$project")
  start=$(now_ms)
  (cd "$project" && "$@") > "$LOGS/$app.$phase.$name.log" 2>&1
  rc=$?
  printf '%s\t%s\t%s\t%s\t%s\n' "$app" "$phase" "$name" "$rc" $(( $(now_ms) - start )) >> "$RESULTS"
  printf '  %-18s %s\n' "$name" "$([ $rc -eq 0 ] && echo pass || echo "FAIL ($rc), see $LOGS/$app.$phase.$name.log")"
}

# http_check <port> <path> <expected status>
http_check() {
  local code
  code=$(curl --noproxy '*' -s -o /dev/null -w '%{http_code}' --retry 30 --retry-connrefused --retry-delay 1 \
    "http://127.0.0.1:$1$2")
  echo "GET $2 -> $code (expected $3)"
  [ "$code" = "$3" ]
}

# serve_check <port>: `artisan serve`, then the welcome page and a 404 page (rendered from framework views in vendor/).
serve_check() {
  local port=$1 rc=0 pid
  setsid php artisan serve --host=127.0.0.1 --port="$port" --no-reload &
  pid=$!
  http_check "$port" / 200 || rc=1
  http_check "$port" /spike-missing-page 404 || rc=1
  kill -TERM -- "-$pid" 2>/dev/null
  wait "$pid" 2>/dev/null
  return $rc
}

run_checks() {
  local p=$1 phase=$2 port=$3 app
  app=$(basename "$p")
  step "$app: $phase checks"
  check "$p" "$phase" about             php artisan about
  check "$p" "$phase" package-discover  php artisan package:discover
  check "$p" "$phase" artisan-test      php artisan test
  check "$p" "$phase" phpunit           vendor/bin/phpunit
  check "$p" "$phase" pint              vendor/bin/pint --test
  check "$p" "$phase" migrate-fresh     php artisan migrate:fresh --force
  check "$p" "$phase" optimize          php artisan optimize
  check "$p" "$phase" optimize-clear    php artisan optimize:clear
  check "$p" "$phase" tinker            php artisan tinker --execute='echo app()->version();'
  check "$p" "$phase" serve             serve_check "$port"
  check "$p" "$phase" vendor-publish    php artisan vendor:publish --tag=laravel-errors --force
  check "$p" "$phase" lang-publish      php artisan lang:publish --force
  check "$p" "$phase" stub-publish      php artisan stub:publish --force
  check "$p" "$phase" composer-dump     composer dump-autoload --optimize --no-interaction
  check "$p" "$phase" composer-install  composer install --no-interaction
  check "$p" "$phase" probe             spike probe "$p" "$WORK/$app.$phase.probe.json"
}

# new_app <dir> <version constraint>: fresh app, committed so it can be reset between phases.
new_app() {
  step "create-project laravel/laravel$2 -> $(basename "$1")"
  if ! composer create-project "laravel/laravel$2" "$1" --no-interaction --no-progress \
    > "$LOGS/$(basename "$1").create-project.log" 2>&1; then
    echo "create-project failed, see $LOGS/$(basename "$1").create-project.log" >&2
    exit 1
  fi
  git -C "$1" init -q && git -C "$1" add -A &&
    git -C "$1" -c user.name=spike -c user.email=spike@example.invalid commit -qm "fresh app"
  fact "$(basename "$1")" "$(cd "$1" && php artisan --version), $(php -r \
    'echo count(json_decode(file_get_contents($argv[1]), true)["packages"]);' "$1/vendor/composer/installed.json") packages"
}

# Undo what the checks wrote to the app (published files, caches); vendor/ is ignored by git.
reset_app() { git -C "$1" checkout -q -- . && git -C "$1" clean -fdq; }

# link_app <dir>: move/link its packages via the store, then check the links and the store.
link_app() {
  local p=$1 app
  app=$(basename "$p")
  step "$app: link vendor/ from the store"
  spike link "$p" "$STORE" "$WORK/$app.links.json" || exit 1
  spike verify "$WORK/$app.links.json" "$WORK/$app.verify-linked.json"
  spike snapshot "$STORE" "$WORK/$app.store-snapshot.json"
}

# after_checks <dir>: links still intact, store untouched, published files are not links.
after_checks() {
  local p=$1 app
  app=$(basename "$p")
  step "$app: integrity after linked checks"
  spike verify "$WORK/$app.links.json" "$WORK/$app.verify-after.json"
  spike compare "$STORE" "$WORK/$app.store-snapshot.json" "$WORK/$app.store-compare.json"
  spike leaks "$p" "$WORK/$app.links.json" "$WORK/$app.leaks.json"
}

echo "work dir: $WORK"
fact php "$(php -r 'echo PHP_VERSION;')"
fact composer "$(composer --version 2>/dev/null | head -n1)"
fact os "$(uname -sr)"
fact filesystem "$(findmnt -no FSTYPE -T "$WORK" 2>/dev/null || stat -f -c %T "$WORK")"

A=$WORK/app-a B=$WORK/app-b C=$WORK/app-c

new_app "$A" ""
run_checks "$A" baseline 8701
reset_app "$A"
link_app "$A"
run_checks "$A" linked 8701
after_checks "$A"
reset_app "$A"

new_app "$B" ""
run_checks "$B" baseline 8702
reset_app "$B"
step "app-b: time a normal install from the warm Composer cache"
rm -rf "$B/vendor"
start=$(now_ms)
(cd "$B" && composer install --no-interaction --no-scripts --no-autoloader) > "$LOGS/app-b.timed-install.log" 2>&1
fact time.app-b-composer-install-no-scripts-no-autoloader-ms $(( $(now_ms) - start ))
(cd "$B" && composer dump-autoload --no-interaction) >> "$LOGS/app-b.timed-install.log" 2>&1
link_app "$B"
run_checks "$B" linked 8702
after_checks "$B"
reset_app "$B"

new_app "$C" ":^12.0"
run_checks "$C" baseline 8703
reset_app "$C"
link_app "$C"
run_checks "$C" linked 8703
after_checks "$C"
reset_app "$C"

step "disk usage"
for app in app-a app-b app-c; do
  fact "du.$app-vendor-kb" "$(du -sk "$WORK/$app/vendor" | cut -f1)"
  # Directories cannot be hard-linked, so each project keeps its own tree of them.
  fact "count.$app-vendor-dirs" "$(find "$WORK/$app/vendor" -type d | wc -l)"
  fact "du.$app-vendor-composer-kb" "$(du -sk "$WORK/$app/vendor/composer" | cut -f1)"
done
fact du.store-kb "$(du -sk "$STORE" | cut -f1)"
fact du.store-plus-all-vendors-kb "$(du -sk --total "$STORE" "$A/vendor" "$B/vendor" "$C/vendor" | tail -n1 | cut -f1)"

step "app-a: plain Composer reinstall of a linked package (what happens without the plugin)"
spike snapshot "$STORE" "$WORK/app-a.reinstall.store-snapshot.json"
check "$A" after composer-reinstall composer reinstall laravel/framework --no-interaction
spike compare "$STORE" "$WORK/app-a.reinstall.store-snapshot.json" "$WORK/app-a.reinstall.store-compare.json"
spike verify "$WORK/app-a.links.json" "$WORK/app-a.verify-after-reinstall.json"
check "$A" after artisan-test php artisan test

spike report "$WORK"
