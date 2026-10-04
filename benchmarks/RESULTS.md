# Benchmark results

Five Laravel projects, 556 package installs of 148 different package versions, installed with `run.php` (see [README.md](README.md) for the method). Times are medians of 3 runs of offline installs from a warm Composer cache, with `--no-scripts`.

The numbers come from two versions of the plugin. Commit 5d78b71 cloned one package after the other: a run in the development sandbox, and [Benchmarks run 37191300017](https://github.com/wuemv/composer-store/actions/runs/37191300017) on GitHub Actions. Commit c83f626 clones packages in parallel: [Benchmarks run 37193665389](https://github.com/wuemv/composer-store/actions/runs/37193665389). Hard links work the same in both.

## Summary

Disk space taken by the five projects, store included:

| | No plugin | With the plugin | Saved |
|---|---:|---:|---:|
| Linux, ext4, hard links | 375 MiB | 176 MiB | 53% |
| Linux, Btrfs, reflinks or hard links | 236 MiB | 82 MiB | 65% |
| macOS, APFS, reflinks | 385 MiB | 154 MiB | 60% |
| macOS, APFS, hard links | 385 MiB | 161 MiB | 58% |

Free space on APFS moves by tens of MiB from one run to the next: the earlier run measured 49% for reflinks and 57% for hard links.

Time to install all five projects, compared with no plugin, with packages cloned in parallel:

| | No plugin | Empty store | | Warm store | |
|---|---:|---:|---:|---:|---:|
| Linux VM (sandbox), ext4, hard links | 16.03 s | 16.57 s | +3% | 13.76 s | −14% |
| GitHub Actions, Linux, ext4, hard links | 13.78 s | 13.35 s | −3% | 11.87 s | −14% |
| GitHub Actions, Linux, Btrfs, hard links | 14.71 s | 13.89 s | −6% | 11.70 s | −20% |
| GitHub Actions, Linux, Btrfs, reflinks | 14.71 s | 17.16 s | +17% | 14.69 s | 0% |
| GitHub Actions, macOS, APFS, reflinks | 9.78 s | 16.84 s | +72% | 14.71 s | +50% |
| GitHub Actions, macOS, APFS, hard links | 9.78 s | 21.59 s | +121% | 18.54 s | +90% |

Cloning in parallel took macOS from +88% to +50% with a warm store, and from +98% to +26% when the autoloader is left out (`--no-autoloader`); see the macOS section below.

"Empty store" is the first time: each package is extracted into the store by the first project that needs it, and linked by the others. "Warm store" is every later install: everything is linked.

- **Disk space**: the plugin halves what five Laravel projects take, or better. Every further project on the same versions only adds its directories, `vendor/composer/` and its copied binaries, plus a little per file for clones. Btrfs keeps small files inline, which is why it needs less space to begin with.
- **Linux with hard links**: installs from a warm store are 14 to 20% faster, and filling an empty store costs about as much as installing without the plugin.
- **Reflinks on Linux** are about as fast as installing without the plugin, 20% faster without the autoloader: `cp` runs once per package, and the projects then read their clones from disk (below).
- **macOS** installs from a warm store take about 50% longer than without the plugin, with reflinks, which `auto` picks on APFS. Hard links are slower there: linking file by file is slow on APFS. Until commit c83f626 the plugin also cloned one package after the other, where Composer extracts several archives at once; it now runs its `cp` processes in parallel the same way.
- **Clones are read from disk once.** Generating the optimized autoloader reads every class file: right after an unzip those files are in memory, but a clone shares blocks on disk, not the page cache, so its first read goes to the disk. That is most of what is left of the gap on macOS (about 6 s instead of 3 s for five projects), and costs about 2 s on Btrfs. Cloning each package with one `clonefile(2)` call through FFI, which works on the macOS runners, may shave off more.
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

## GitHub Actions, Linux, Btrfs, packages cloned one after the other

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

## GitHub Actions, macOS, APFS, packages cloned in parallel

[Benchmarks run 37193665389](https://github.com/wuemv/composer-store/actions/runs/37193665389), commit c83f626: `macos-latest`, Darwin 25.6 arm64, PHP 8.4.26, Composer 2.10.3.

| Install time | Packages | No plugin | Reflinks (auto), empty store | Reflinks (auto), warm store | Hard links, empty store | Hard links, warm store |
|---|---:|---:|---:|---:|---:|---:|
| laravel-11 | 110 | 1.89 s | 3.99 s | 2.85 s | 5.14 s | 3.59 s |
| laravel-12 | 111 | 1.94 s | 3.46 s | 2.92 s | 3.97 s | 3.80 s |
| laravel-13-api | 114 | 1.95 s | 3.46 s | 2.98 s | 4.50 s | 3.75 s |
| laravel-13-livewire | 111 | 1.98 s | 2.96 s | 3.01 s | 4.13 s | 4.01 s |
| laravel-13 | 110 | 2.01 s | 2.95 s | 2.88 s | 3.92 s | 3.78 s |
| **All 5** | 556 | **9.78 s** | **16.84 s** | **14.71 s** | **21.59 s** | **18.54 s** |

| Install time, --no-autoloader | Packages | No plugin | Reflinks (auto), empty store | Reflinks (auto), warm store | Hard links, empty store | Hard links, warm store |
|---|---:|---:|---:|---:|---:|---:|
| laravel-11 | 110 | 1.27 s | 2.77 s | 1.59 s | 4.10 s | 2.95 s |
| laravel-12 | 111 | 1.27 s | 1.97 s | 1.60 s | 3.21 s | 2.82 s |
| laravel-13-api | 114 | 1.32 s | 2.17 s | 1.73 s | 3.57 s | 2.89 s |
| laravel-13-livewire | 111 | 1.31 s | 1.72 s | 1.75 s | 3.32 s | 2.84 s |
| laravel-13 | 110 | 1.49 s | 1.55 s | 1.71 s | 3.12 s | 3.06 s |
| **All 5** | 556 | **6.64 s** | **10.16 s** | **8.38 s** | **17.33 s** | **14.56 s** |

| Disk space | No plugin | Reflinks (auto) | Hard links |
|---|---:|---:|---:|
| 5 projects | 385 MiB | 154 MiB (−60%) | 161 MiB (−58%) |

## GitHub Actions, Linux, Btrfs, packages cloned in parallel

The same run, on the Btrfs image.

| Install time | Packages | No plugin | Reflinks (auto), empty store | Reflinks (auto), warm store | Hard links, empty store | Hard links, warm store |
|---|---:|---:|---:|---:|---:|---:|
| **All 5** | 556 | **14.71 s** | **17.16 s** | **14.69 s** | **13.89 s** | **11.70 s** |

| Install time, --no-autoloader | Packages | No plugin | Reflinks (auto), empty store | Reflinks (auto), warm store | Hard links, empty store | Hard links, warm store |
|---|---:|---:|---:|---:|---:|---:|
| **All 5** | 556 | **10.11 s** | **10.48 s** | **8.08 s** | **9.06 s** | **6.99 s** |

| Disk space | No plugin | Reflinks (auto) | Hard links |
|---|---:|---:|---:|
| 5 projects | 236 MiB | 82 MiB (−65%) | 82 MiB (−65%) |

## GitHub Actions, macOS, APFS, packages cloned one after the other

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
