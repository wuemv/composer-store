# Benchmark results

Five Laravel projects, 556 package installs of 148 different package versions, installed with `run.php` (see [README.md](README.md) for the method). Times are medians of 3 runs of offline installs from a warm Composer cache, with `--no-scripts`. The numbers come from commit 5d78b71: a run in the development sandbox, and the [Benchmarks workflow run](https://github.com/wuemv/composer-store/actions/runs/37191300017) on GitHub Actions.

## Summary

Disk space taken by the five projects, store included:

| | No plugin | With the plugin | Saved |
|---|---:|---:|---:|
| Linux, ext4, hard links | 375 MiB | 176 MiB | 53% |
| Linux, Btrfs, reflinks or hard links | 238 MiB | 82 MiB | 66% |
| macOS, APFS, reflinks | 384 MiB | 195 MiB | 49% |
| macOS, APFS, hard links | 384 MiB | 164 MiB | 57% |

Time to install all five projects, compared with no plugin:

| | No plugin | Empty store | | Warm store | |
|---|---:|---:|---:|---:|---:|
| Linux VM (sandbox), ext4, hard links | 16.03 s | 16.57 s | +3% | 13.76 s | −14% |
| GitHub Actions, Linux, ext4, hard links | 13.78 s | 13.35 s | −3% | 11.87 s | −14% |
| GitHub Actions, Linux, Btrfs, hard links | 15.58 s | 14.49 s | −7% | 12.11 s | −22% |
| GitHub Actions, Linux, Btrfs, reflinks | 15.58 s | 18.17 s | +17% | 15.84 s | +2% |
| GitHub Actions, macOS, APFS, reflinks | 9.85 s | 21.15 s | +115% | 18.47 s | +88% |
| GitHub Actions, macOS, APFS, hard links | 9.85 s | 21.39 s | +117% | 18.46 s | +87% |

"Empty store" is the first time: each package is extracted into the store by the first project that needs it, and linked by the others. "Warm store" is every later install: everything is linked.

- **Disk space**: the plugin halves what five Laravel projects take, or better. With hard links, every further project on the same versions only adds its directories, `vendor/composer/` and its copied binaries; clones take a little space per file on top of that. Btrfs keeps small files inline, which is why it needs less space to begin with.
- **Linux with hard links**: installs from a warm store are 14 to 22% faster, and filling an empty store costs about as much as installing without the plugin.
- **Reflinks on Linux** are no faster than installing without the plugin: `cp` runs once per package.
- **macOS** installs take about twice as long as without the plugin, with reflinks or hard links. Composer extracts several archives at once, while the plugin links or clones one package after the other in a single process, and doing that file by file is slow on APFS. Running that work in parallel, as Composer does for extraction, or cloning each package with one `clonefile(2)` call, are the likely ways to close the gap.
- **Generating the autoloader**, which these projects optimize, takes 1 to 1.5 s per project with or without the plugin: compare the two time tables of each machine below.

## Linux VM (development sandbox), ext4

Firecracker VM, 4 vCPUs, ext4 on a virtio disk. Linux 6.18.44 x86_64, PHP 8.3.6, Composer 2.8.12. In this VM, creating a hard link or a directory takes about 38 µs.

| Install time | Packages | No plugin | Hard links (auto), empty store | Hard links (auto), warm store |
|---|---:|---:|---:|---:|
| laravel-11 | 110 | 3.24 s | 4.14 s | 2.41 s |
| laravel-12 | 111 | 2.93 s | 3.10 s | 2.66 s |
| laravel-13-api | 114 | 3.22 s | 3.32 s | 2.85 s |
| laravel-13-livewire | 111 | 3.23 s | 2.90 s | 2.85 s |
| laravel-13 | 110 | 3.20 s | 3.00 s | 2.86 s |
| **All 5** | 556 | **16.03 s** | **16.57 s** | **13.76 s** |

| Install time, --no-autoloader | Packages | No plugin | Hard links (auto), empty store | Hard links (auto), warm store |
|---|---:|---:|---:|---:|
| laravel-11 | 110 | 1.97 s | 2.68 s | 1.56 s |
| laravel-12 | 111 | 2.28 s | 2.07 s | 1.68 s |
| laravel-13-api | 114 | 2.01 s | 2.18 s | 1.74 s |
| laravel-13-livewire | 111 | 1.79 s | 1.77 s | 1.76 s |
| laravel-13 | 110 | 1.51 s | 1.56 s | 1.63 s |
| **All 5** | 556 | **9.55 s** | **10.30 s** | **8.38 s** |

| Disk space | No plugin | Hard links (auto) |
|---|---:|---:|
| 5 projects | 375 MiB | 176 MiB (−53%) |

The store alone, per `du`: 121 MiB.

## GitHub Actions, Linux, ext4

`ubuntu-latest`: Linux 6.17 (Azure) x86_64, PHP 8.4.26, Composer 2.10.3.

| Install time | Packages | No plugin | Hard links (auto), empty store | Hard links (auto), warm store |
|---|---:|---:|---:|---:|
| laravel-11 | 110 | 2.47 s | 3.30 s | 2.24 s |
| laravel-12 | 111 | 2.52 s | 2.39 s | 2.30 s |
| laravel-13-api | 114 | 2.88 s | 2.77 s | 2.51 s |
| laravel-13-livewire | 111 | 2.85 s | 2.41 s | 2.41 s |
| laravel-13 | 110 | 2.60 s | 2.51 s | 2.37 s |
| **All 5** | 556 | **13.78 s** | **13.35 s** | **11.87 s** |

| Install time, --no-autoloader | Packages | No plugin | Hard links (auto), empty store | Hard links (auto), warm store |
|---|---:|---:|---:|---:|
| laravel-11 | 110 | 1.58 s | 2.41 s | 1.37 s |
| laravel-12 | 111 | 1.59 s | 1.50 s | 1.40 s |
| laravel-13-api | 114 | 1.71 s | 1.86 s | 1.54 s |
| laravel-13-livewire | 111 | 1.69 s | 1.45 s | 1.44 s |
| laravel-13 | 110 | 1.66 s | 1.56 s | 1.40 s |
| **All 5** | 556 | **8.27 s** | **8.83 s** | **7.19 s** |

| Disk space | No plugin | Hard links (auto) |
|---|---:|---:|
| 5 projects | 375 MiB | 176 MiB (−53%) |

The store alone, per `du`: 121 MiB.

## GitHub Actions, Linux, Btrfs

The same runner, with the work directory on a loop-mounted Btrfs image.

| Install time | Packages | No plugin | Reflinks (auto), empty store | Reflinks (auto), warm store | Hard links, empty store | Hard links, warm store |
|---|---:|---:|---:|---:|---:|---:|
| laravel-11 | 110 | 3.42 s | 4.66 s | 3.09 s | 3.67 s | 2.34 s |
| laravel-12 | 111 | 2.98 s | 3.25 s | 3.10 s | 2.59 s | 2.35 s |
| laravel-13-api | 114 | 3.10 s | 3.76 s | 3.20 s | 3.06 s | 2.49 s |
| laravel-13-livewire | 111 | 3.12 s | 3.33 s | 3.36 s | 2.59 s | 2.45 s |
| laravel-13 | 110 | 3.01 s | 3.14 s | 3.12 s | 2.42 s | 2.43 s |
| **All 5** | 556 | **15.58 s** | **18.17 s** | **15.84 s** | **14.49 s** | **12.11 s** |

| Install time, --no-autoloader | Packages | No plugin | Reflinks (auto), empty store | Reflinks (auto), warm store | Hard links, empty store | Hard links, warm store |
|---|---:|---:|---:|---:|---:|---:|
| laravel-11 | 110 | 2.49 s | 3.19 s | 1.83 s | 2.85 s | 1.45 s |
| laravel-12 | 111 | 1.94 s | 1.99 s | 1.87 s | 1.72 s | 1.47 s |
| laravel-13-api | 114 | 2.01 s | 2.42 s | 1.92 s | 2.09 s | 1.54 s |
| laravel-13-livewire | 111 | 2.08 s | 1.92 s | 1.92 s | 1.58 s | 1.51 s |
| laravel-13 | 110 | 1.96 s | 1.79 s | 1.84 s | 1.50 s | 1.47 s |
| **All 5** | 556 | **10.49 s** | **11.34 s** | **9.47 s** | **9.78 s** | **7.46 s** |

| Disk space | No plugin | Reflinks (auto) | Hard links |
|---|---:|---:|---:|
| 5 projects | 238 MiB | 82 MiB (−66%) | 82 MiB (−66%) |

The store alone, per `du`: 111 MiB, more than the whole installs took: `du` counts whole blocks for the small files Btrfs keeps inline.

## GitHub Actions, macOS, APFS

`macos-latest`: Darwin 25.6 arm64, PHP 8.4.26, Composer 2.10.3.

| Install time | Packages | No plugin | Reflinks (auto), empty store | Reflinks (auto), warm store | Hard links, empty store | Hard links, warm store |
|---|---:|---:|---:|---:|---:|---:|
| laravel-11 | 110 | 1.95 s | 5.01 s | 3.64 s | 4.84 s | 3.63 s |
| laravel-12 | 111 | 1.92 s | 3.98 s | 3.57 s | 4.04 s | 3.59 s |
| laravel-13-api | 114 | 1.98 s | 4.66 s | 3.88 s | 4.42 s | 3.80 s |
| laravel-13-livewire | 111 | 1.99 s | 4.17 s | 3.80 s | 4.14 s | 3.82 s |
| laravel-13 | 110 | 1.96 s | 3.51 s | 3.57 s | 3.89 s | 3.80 s |
| **All 5** | 556 | **9.85 s** | **21.15 s** | **18.47 s** | **21.39 s** | **18.46 s** |

| Install time, --no-autoloader | Packages | No plugin | Reflinks (auto), empty store | Reflinks (auto), warm store | Hard links, empty store | Hard links, warm store |
|---|---:|---:|---:|---:|---:|---:|
| laravel-11 | 110 | 1.24 s | 3.55 s | 2.72 s | 4.10 s | 2.92 s |
| laravel-12 | 111 | 1.28 s | 2.57 s | 2.56 s | 3.13 s | 2.99 s |
| laravel-13-api | 114 | 1.31 s | 3.02 s | 2.68 s | 3.60 s | 3.05 s |
| laravel-13-livewire | 111 | 1.43 s | 2.59 s | 2.65 s | 3.34 s | 3.06 s |
| laravel-13 | 110 | 1.33 s | 2.53 s | 2.18 s | 3.12 s | 2.90 s |
| **All 5** | 556 | **6.56 s** | **14.40 s** | **12.97 s** | **17.27 s** | **14.91 s** |

| Disk space | No plugin | Reflinks (auto) | Hard links |
|---|---:|---:|---:|
| 5 projects | 384 MiB | 195 MiB (−49%) | 164 MiB (−57%) |

The store alone, per `du`: 111 MiB.
