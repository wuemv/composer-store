<?php

declare(strict_types=1);

/*
 * Phase 0 spike helper, driven by spike/run.sh. Findings are in spike/RESULTS.md.
 *
 *   spike.php link     <project> <store> <manifest.json>   move vendor packages into the store, hard-link them back
 *   spike.php verify   <manifest.json> <out.json>          every package file is a hard link to its store copy
 *   spike.php snapshot <store> <snapshot.json>             hash every file in the store
 *   spike.php compare  <store> <snapshot.json> <out.json>  detect store files changed through a vendor/ link
 *   spike.php leaks    <project> <manifest.json> <out.json> files outside linked package dirs that share a store inode
 *   spike.php probe    <project> <out.json>                what PHP and Laravel see for a linked vendor file
 *   spike.php report   <work-dir>                          write <work-dir>/results.md
 */

// Namespaced so these helpers can never shadow function_exists()-guarded helpers when `probe` loads an app.
namespace ComposerStore\Spike;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

const META_FILE = '.store-meta.json';

exit(main($argv));

function main(array $argv): int
{
    $args = array_slice($argv, 2);

    return match ($argv[1] ?? '') {
        'link' => cmdLink(...$args),
        'verify' => cmdVerify(...$args),
        'snapshot' => cmdSnapshot(...$args),
        'compare' => cmdCompare(...$args),
        'leaks' => cmdLeaks(...$args),
        'probe' => cmdProbe(...$args),
        'report' => cmdReport(...$args),
        default => fail('usage: spike.php link|verify|snapshot|compare|leaks|probe|report ...'),
    };
}

function cmdLink(string $project, string $store, string $manifestFile): int
{
    $vendor = realpath($project . '/vendor') ?: fail("no vendor/ in {$project}");
    $store = ensureDir($store);
    if (stat($vendor)['dev'] !== stat($store)['dev']) {
        fail('the store and the project must be on the same filesystem for hard links');
    }

    $installed = readJson($vendor . '/composer/installed.json');
    $entries = [];
    foreach ($installed['packages'] as $package) {
        $entries[] = linkPackage($package, $vendor, $store);
    }
    writeJson($manifestFile, $entries);

    $actions = array_count_values(array_column($entries, 'action'));
    ksort($actions);
    printf(
        "%d packages: %s; %d files linked in %.0f ms (+%.0f ms tree hashing)\n",
        count($entries),
        http_build_query($actions, '', ', '),
        array_sum(array_column($entries, 'files')),
        array_sum(array_column($entries, 'link_ms')),
        array_sum(array_column($entries, 'hash_ms'))
    );

    return 0;
}

/**
 * @param array<string, mixed> $package an entry of vendor/composer/installed.json
 *
 * @return array<string, mixed>
 */
function linkPackage(array $package, string $vendor, string $store): array
{
    $entry = ['name' => $package['name'], 'version' => $package['version'], 'type' => $package['type'] ?? 'library'];
    $reference = $package['dist']['reference'] ?? null;

    if (!isset($package['install-path']) || $entry['type'] === 'metapackage') {
        return $entry + ['action' => 'skipped', 'reason' => 'no files'];
    }
    if (($package['installation-source'] ?? null) !== 'dist' || !is_string($reference) || $reference === '') {
        return $entry + ['action' => 'skipped', 'reason' => 'not a dist install'];
    }

    $dir = realpath($vendor . '/composer/' . $package['install-path']);
    if ($dir === false || !is_dir($dir)) {
        return $entry + ['action' => 'skipped', 'reason' => 'install path missing'];
    }
    $odd = firstNonRegularEntry($dir);
    if ($odd !== null) {
        return $entry + ['action' => 'skipped', 'reason' => "non-regular entry {$odd}"];
    }

    // Store key: name + version + dist reference (CLAUDE.md layout).
    $key = sprintf('%s/packages/%s/%s-%s', $store, $package['name'], safeName($package['version']), substr($reference, 0, 12));
    $entry += ['store' => $key, 'vendor' => $dir];

    $start = hrtime(true);
    $tree = treeHash($dir);
    $entry['hash_ms'] = elapsedMs($start);

    if (is_dir($key)) {
        // Same key from another project: the contents must be identical, or the key is not good enough.
        $meta = readJson($key . '/' . META_FILE);
        if ($meta['tree_hash'] !== $tree['hash']) {
            return $entry + ['action' => 'skipped', 'reason' => 'differs from the store entry with the same key'];
        }
        removeTree($dir);
        $entry['action'] = 'hit';
    } else {
        ensureDir(dirname($key));
        if (!rename($dir, $key)) {
            fail("cannot move {$dir} to {$key}");
        }
        writeJson($key . '/' . META_FILE, [
            'tree_hash' => $tree['hash'],
            'files' => $tree['files'],
            'bytes' => $tree['bytes'],
            'created_at' => date(DATE_ATOM),
            'dist_url' => $package['dist']['url'] ?? null,
        ]);
        $entry['action'] = 'moved';
    }

    $start = hrtime(true);
    $entry['files'] = mirrorWithHardLinks($key, $dir);
    $entry['link_ms'] = elapsedMs($start);
    $entry['bytes'] = $tree['bytes'];
    $entry['tree_hash'] = $tree['hash'];

    return $entry;
}

function cmdVerify(string $manifestFile, string $outFile): int
{
    $result = ['packages' => 0, 'linked_files' => 0, 'missing' => [], 'not_linked' => [], 'extra' => []];

    foreach (linkedEntries($manifestFile) as $entry) {
        $result['packages']++;
        $storeDev = stat($entry['store'])['dev'];

        foreach (walk($entry['store']) as $rel => $info) {
            if ($rel === META_FILE) {
                continue;
            }
            $stat = @lstat($entry['vendor'] . '/' . $rel);
            if ($stat === false) {
                $result['missing'][] = "{$entry['name']}: {$rel}";
            } elseif ($info->isFile() && ($stat['ino'] !== $info->getInode() || $stat['dev'] !== $storeDev)) {
                $result['not_linked'][] = "{$entry['name']}: {$rel}";
            } elseif ($info->isFile()) {
                $result['linked_files']++;
            }
        }

        foreach (walk($entry['vendor']) as $rel => $info) {
            if (@lstat($entry['store'] . '/' . $rel) === false) {
                $result['extra'][] = "{$entry['name']}: {$rel}";
            }
        }
    }

    writeJson($outFile, $result);
    printf(
        "%d packages, %d files linked, %d missing, %d not linked, %d extra\n",
        $result['packages'],
        $result['linked_files'],
        count($result['missing']),
        count($result['not_linked']),
        count($result['extra'])
    );

    return $result['missing'] === [] && $result['not_linked'] === [] ? 0 : 1;
}

function cmdSnapshot(string $store, string $snapshotFile): int
{
    $snapshot = [];
    foreach (walk($store) as $rel => $info) {
        if ($info->isFile()) {
            $snapshot[$rel] = [hash_file('sha256', $info->getPathname()), decoct($info->getPerms() & 07777)];
        }
    }
    writeJson($snapshotFile, $snapshot);
    printf("%d store files hashed\n", count($snapshot));

    return 0;
}

function cmdCompare(string $store, string $snapshotFile, string $outFile): int
{
    $before = readJson($snapshotFile);
    $result = ['files' => count($before), 'content_changed' => [], 'mode_changed' => [], 'missing' => [], 'added' => []];

    foreach (walk($store) as $rel => $info) {
        if (!$info->isFile()) {
            continue;
        }
        if (!isset($before[$rel])) {
            $result['added'][] = $rel;
            continue;
        }
        [$hash, $mode] = $before[$rel];
        unset($before[$rel]);
        if (hash_file('sha256', $info->getPathname()) !== $hash) {
            $result['content_changed'][] = $rel;
        }
        $nowMode = decoct($info->getPerms() & 07777);
        if ($nowMode !== $mode) {
            $result['mode_changed'][] = "{$rel} ({$mode} -> {$nowMode})";
        }
    }
    $result['missing'] = array_keys($before);

    writeJson($outFile, $result);
    printf(
        "%d store files: %d content changed, %d mode changed, %d missing, %d added\n",
        $result['files'],
        count($result['content_changed']),
        count($result['mode_changed']),
        count($result['missing']),
        count($result['added'])
    );

    return $result['content_changed'] === [] && $result['mode_changed'] === [] && $result['missing'] === [] ? 0 : 1;
}

function cmdLeaks(string $project, string $manifestFile, string $outFile): int
{
    $project = realpath($project) ?: fail("no project at {$project}");
    $linkedDirs = [];
    $storeInodes = [];
    foreach (linkedEntries($manifestFile) as $entry) {
        $linkedDirs[$entry['vendor']] = true;
        foreach (walk($entry['store']) as $info) {
            if ($info->isFile()) {
                $storeInodes[$info->getInode()] = true;
            }
        }
    }

    // Everything outside the linked package dirs (app code, published files, vendor/composer, vendor/bin)
    // must be an independent file, never a link into the store.
    $files = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($project, FilesystemIterator::SKIP_DOTS),
        static fn (SplFileInfo $info, string $path): bool => !isset($linkedDirs[$path])
    ));
    $result = ['checked' => 0, 'leaks' => []];
    foreach ($files as $path => $info) {
        if ($info->isFile() && !$info->isLink()) {
            $result['checked']++;
            if (isset($storeInodes[$info->getInode()])) {
                $result['leaks'][] = substr($path, strlen($project) + 1);
            }
        }
    }

    writeJson($outFile, $result);
    printf("%d files outside linked package dirs, %d share an inode with the store\n", $result['checked'], count($result['leaks']));

    return $result['leaks'] === [] ? 0 : 1;
}

function cmdProbe(string $project, string $outFile): int
{
    $root = realpath($project) ?: fail("no project at {$project}");
    chdir($root);
    require $root . '/vendor/autoload.php';

    // ReflectionClass::getFileName() is the path PHP compiled the file from, i.e. what __FILE__ / __DIR__ see.
    $file = (new ReflectionClass(\Illuminate\Foundation\Application::class))->getFileName();
    $app = require $root . '/bootstrap/app.php';
    $providers = $app->make(\Illuminate\Foundation\PackageManifest::class)->providers();

    $result = [
        'project' => $root,
        'application_file' => $file,
        'application_file_in_project_vendor' => str_starts_with($file, $root . '/vendor/'),
        'application_file_nlink' => stat($file)['nlink'],
        'base_path' => $app->basePath(),
        'base_path_is_project' => $app->basePath() === $root,
        'install_path' => realpath(\Composer\InstalledVersions::getInstallPath('laravel/framework')),
        'discovered_providers' => $providers,
    ];
    writeJson($outFile, $result);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";

    return $result['application_file_in_project_vendor'] && $result['base_path_is_project'] ? 0 : 1;
}

function cmdReport(string $work): int
{
    $facts = [];
    foreach (readTsv($work . '/facts.tsv') as [$key, $value]) {
        $facts[$key] = $value;
    }

    // Plain keys describe the environment, dotted keys (du.*, time.*, count.*) are measurements.
    $out = ["# Spike run (generated by spike/run.sh)", ''];
    $out[] = '## Environment';
    $out[] = '';
    foreach ($facts as $key => $value) {
        if (!str_contains($key, '.')) {
            $out[] = "- {$key}: {$value}";
        }
    }

    // Check matrix: one row per check, one column per project phase.
    $cells = [];
    $columns = [];
    foreach (readTsv($work . '/results.tsv') as [$app, $phase, $check, $rc, $ms]) {
        $columns["{$app} {$phase}"] = true;
        $cells[$check]["{$app} {$phase}"] = sprintf('%s (%.1fs)', $rc === '0' ? 'pass' : "FAIL rc={$rc}", (int) $ms / 1000);
    }
    $out[] = '';
    $out[] = '## Checks';
    $out[] = '';
    $out[] = '| check | ' . implode(' | ', array_keys($columns)) . ' |';
    $out[] = '|---' . str_repeat('|---', count($columns)) . '|';
    foreach ($cells as $check => $row) {
        $values = array_map(static fn (string $column): string => $row[$column] ?? '-', array_keys($columns));
        $out[] = "| {$check} | " . implode(' | ', $values) . ' |';
    }

    $out[] = '';
    $out[] = '## Linking';
    $out[] = '';
    $out[] = '| project | packages | moved to store | store hits | skipped | files linked | MB | link ms | tree-hash ms |';
    $out[] = '|---|---|---|---|---|---|---|---|---|';
    $skipped = [];
    $users = [];
    foreach (glob($work . '/*.links.json') ?: [] as $manifestFile) {
        $app = basename($manifestFile, '.links.json');
        $entries = readJson($manifestFile);
        $actions = array_count_values(array_column($entries, 'action'));
        $out[] = sprintf(
            '| %s | %d | %d | %d | %d | %d | %.1f | %.0f | %.0f |',
            $app,
            count($entries),
            $actions['moved'] ?? 0,
            $actions['hit'] ?? 0,
            $actions['skipped'] ?? 0,
            array_sum(array_column($entries, 'files')),
            array_sum(array_column($entries, 'bytes')) / 1048576,
            array_sum(array_column($entries, 'link_ms')),
            array_sum(array_column($entries, 'hash_ms'))
        );
        foreach ($entries as $entry) {
            if ($entry['action'] === 'skipped') {
                $skipped[] = "- {$app}: {$entry['name']} {$entry['version']}: {$entry['reason']}";
            } else {
                $users[$entry['store']][$app] = true;
            }
        }
    }
    $shared = count(array_filter($users, static fn (array $apps): bool => count($apps) > 1));
    $out[] = '';
    $out[] = sprintf('Store entries: %d, used by more than one project: %d.', count($users), $shared);
    $out[] = '';
    $out[] = 'Skipped (left as normal copies):';
    $out[] = $skipped === [] ? '- none' : implode("\n", $skipped);

    $out[] = '';
    $out[] = '## Integrity';
    $out[] = '';
    foreach (glob($work . '/*.json') ?: [] as $file) {
        $name = basename($file, '.json');
        if (preg_match('{\.(verify-[\w-]+|store-compare|leaks)$}', $name)) {
            $data = readJson($file);
            $summary = [];
            foreach ($data as $key => $value) {
                $summary[] = $key . '=' . (is_array($value) ? count($value) : $value);
            }
            $out[] = "- {$name}: " . implode(', ', $summary);
            foreach ($data as $key => $value) {
                if (is_array($value) && $value !== []) {
                    $out[] = "  - {$key}: " . implode('; ', array_slice($value, 0, 10)) . (count($value) > 10 ? '; ...' : '');
                }
            }
        }
    }

    $out[] = '';
    $out[] = '## Disk usage and timing';
    $out[] = '';
    foreach ($facts as $key => $value) {
        if (str_contains($key, '.')) {
            $out[] = "- {$key}: {$value}";
        }
    }
    // du counts a hard-linked inode once per invocation: vendors measured one by one are what
    // separate installs would use, the store measured together with all vendors is the real usage.
    $separate = array_sum(array_intersect_key($facts, array_flip(preg_grep('{^du\.app-\w+-vendor-kb$}', array_keys($facts)))));
    if ($separate > 0 && isset($facts['du.store-plus-all-vendors-kb'])) {
        $together = (int) $facts['du.store-plus-all-vendors-kb'];
        $out[] = sprintf(
            '- without a store: %.1f MB, with the store: %.1f MB (%.0f%% less)',
            $separate / 1024,
            $together / 1024,
            100 * (1 - $together / $separate)
        );
    }

    file_put_contents($work . '/results.md', implode("\n", $out) . "\n");
    echo "wrote {$work}/results.md\n";

    return 0;
}

/**
 * @return list<array<string, mixed>> manifest entries whose package was linked
 */
function linkedEntries(string $manifestFile): array
{
    return array_values(array_filter(
        readJson($manifestFile),
        static fn (array $entry): bool => in_array($entry['action'], ['moved', 'hit'], true)
    ));
}

/**
 * Recreate $from at $to: real directories, and a hard link for every file.
 */
function mirrorWithHardLinks(string $from, string $to): int
{
    $linked = 0;
    makeDir($to, fileperms($from));
    foreach (walk($from) as $rel => $info) {
        if ($rel === META_FILE) {
            continue;
        }
        $target = $to . '/' . $rel;
        if ($info->isDir()) {
            makeDir($target, $info->getPerms());
        } elseif (link($info->getPathname(), $target)) {
            $linked++;
        } else {
            fail("cannot link {$target}");
        }
    }

    return $linked;
}

/**
 * Hash of relative paths, exec bits and file contents. Independent of mtimes, owners and umask.
 *
 * @return array{hash: string, files: int, bytes: int}
 */
function treeHash(string $root): array
{
    $context = hash_init('sha256');
    $files = 0;
    $bytes = 0;
    foreach (walk($root) as $rel => $info) {
        if ($rel === META_FILE) {
            continue;
        }
        if ($info->isDir()) {
            hash_update($context, "d {$rel}\n");
            continue;
        }
        $exec = ($info->getPerms() & 0111) !== 0 ? 'x' : '-';
        hash_update($context, sprintf("f %s %s %s\n", $exec, hash_file('sha256', $info->getPathname()), $rel));
        $files++;
        $bytes += $info->getSize();
    }

    return ['hash' => hash_final($context), 'files' => $files, 'bytes' => $bytes];
}

/**
 * Anything that is not a plain file or directory makes the package fall back to a normal copy.
 */
function firstNonRegularEntry(string $root): ?string
{
    foreach (walk($root) as $rel => $info) {
        if ($info->isLink() || (!$info->isFile() && !$info->isDir())) {
            return $rel;
        }
    }

    return null;
}

/**
 * @return array<string, SplFileInfo> relative path => entry, parents before children
 */
function walk(string $root): array
{
    $entries = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $path => $info) {
        $entries[substr($path, strlen($root) + 1)] = $info;
    }
    ksort($entries, SORT_STRING);

    return $entries;
}

function removeTree(string $dir): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $path => $info) {
        $info->isDir() && !$info->isLink() ? rmdir($path) : unlink($path);
    }
    rmdir($dir);
}

function makeDir(string $dir, int $perms): void
{
    if (!mkdir($dir) || !chmod($dir, $perms & 07777)) {
        fail("cannot create {$dir}");
    }
}

function ensureDir(string $dir): string
{
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        fail("cannot create {$dir}");
    }

    return realpath($dir);
}

function safeName(string $value): string
{
    return (string) preg_replace('{[^A-Za-z0-9._+-]}', '_', $value);
}

function elapsedMs(int|float $start): float
{
    return round((hrtime(true) - $start) / 1e6, 2);
}

function readJson(string $file): array
{
    return json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
}

function writeJson(string $file, array $data): void
{
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}

/**
 * @return list<list<string>>
 */
function readTsv(string $file): array
{
    $rows = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $rows[] = explode("\t", $line);
    }

    return $rows;
}

function fail(string $message): never
{
    fwrite(STDERR, "spike: {$message}\n");
    exit(2);
}
