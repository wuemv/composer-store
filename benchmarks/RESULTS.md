# Benchmark results

Five Laravel projects, 556 package installs of 148 different package versions, installed with `run.php` (see [README.md](README.md) for the method). Times are medians of 3 runs of offline installs from a warm Composer cache, with `--no-scripts`.

The numbers come from three versions of the plugin, which differ in how they clone:

| Commit | Clones | Runs | GitHub's Linux runners |
|---|---|---|---|
| 5d78b71 | `cp`, one package after the other | the development sandbox, and [Benchmarks run 37191300017](https://github.com/wuemv/composer-store/actions/runs/37191300017) | 2 CPUs |
| c83f626 | `cp`, several packages at once | [Benchmarks run 37193665389](https://github.com/wuemv/composer-store/actions/runs/37193665389) | 2 CPUs |
| 3d528dd | on macOS, `clonefile(2)` through FFI, one call per package | [Benchmarks runs 37210521392](https://github.com/wuemv/composer-store/actions/runs/37210521392) (A) and [37211538803](https://github.com/wuemv/composer-store/actions/runs/37211538803) (B) | 4 CPUs |

Hard links work the same in all three. Compare times within a run, not across runs, because the runners differ. GitHub gives private repositories Linux runners with 2 CPUs and public ones runners with 4, and this repository went public between c83f626 and 3d528dd. macOS runners are the same size either way and still vary: without the plugin, the five installs took from 9.78 s to 25.02 s, depending on the run.

## Summary

Disk space taken by the five projects, store included:

| | No plugin | With the plugin | Saved |
|---|---:|---:|---:|
| Linux, ext4, hard links | 375 MiB | 176 MiB | 53% |
| Linux, Btrfs, reflinks or hard links | 236 to 238 MiB | 72 to 84 MiB | 64 to 70% |
| macOS, APFS, reflinks | 372 to 403 MiB | 133 to 195 MiB | 49 to 67% |
| macOS, APFS, hard links | 372 to 403 MiB | 161 to 214 MiB | 42 to 58% |

The ranges span the runs: free space on APFS moves by tens of MiB from one run to the next.

Installing all five projects from a warm store, with every package already in the store, compared with no plugin in the same run:

| | 5d78b71 | c83f626 | 3d528dd, A | 3d528dd, B |
|---|---:|---:|---:|---:|
| Linux VM (sandbox, 4 vCPUs), ext4, hard links | −14% | | | |
| GitHub Actions, Linux, ext4, hard links | −14% | −8% | +8% | +14% |
| GitHub Actions, Linux, Btrfs, hard links | −22% | −20% | −5% | −2% |
| GitHub Actions, Linux, Btrfs, reflinks | +2% | 0% | +4% | +9% |
| GitHub Actions, macOS, APFS, reflinks | +88% | +50% | **+11%** | **+15%** |
| GitHub Actions, macOS, APFS, hard links | +87% | +90% | +39% | +25% |

The same with `--no-autoloader`, which leaves out generating the optimized autoloader:

| | 5d78b71 | c83f626 | 3d528dd, A | 3d528dd, B |
|---|---:|---:|---:|---:|
| Linux VM (sandbox, 4 vCPUs), ext4, hard links | −12% | | | |
| GitHub Actions, Linux, ext4, hard links | −13% | −13% | +14% | +24% |
| GitHub Actions, Linux, Btrfs, hard links | −29% | −31% | −9% | −7% |
| GitHub Actions, Linux, Btrfs, reflinks | −10% | −20% | −13% | −11% |
| GitHub Actions, macOS, APFS, reflinks | +98% | +26% | **−21%** | **−19%** |
| GitHub Actions, macOS, APFS, hard links | +127% | +119% | +96% | +90% |

- **Disk space**: the plugin takes about half to two thirds off what five Laravel projects take. Every further project on the same versions only adds its directories, `vendor/composer/` and its copied binaries, plus a little per file for clones. Btrfs keeps small files inline, which is why it needs less space to begin with.
- **macOS**: cloning each package with one `clonefile(2)` call took installs from a warm store from 50% slower than without the plugin to 11 and 15% slower in runs A and B, and to about 20% faster without the autoloader. Hard links stay slow on APFS.
- **Clones are read from disk once.** Generating the optimized autoloader reads every class file: right after an unzip those files are in memory, but a clone shares blocks on disk, not the page cache, so its first read goes to the disk. On macOS that made the autoloader step take 11.8 s instead of 7.2 s for the five projects in run A, and 16.4 s instead of 9.7 s in run B: it is what is left of the gap.
- **Linux with hard links**: the plugin links file by file in one process, while Composer unzips ten archives at once, so plain Composer gains more from more CPUs. On the 2-CPU runners, installs from a warm store were 8 to 22% faster with the plugin; on the 4-CPU runners they range from 5% faster to 14% slower. Running several `cp -al` at once, which placed the files 2.5 times faster than PHP's `link()` on ext4 (see [Placing package files](#placing-package-files)), could bring the advantage back.
- **Reflinks on Linux** take 0 to 9% longer than installing without the plugin, and 10 to 20% less time without the autoloader: `cp` runs once per package, several at once, and the projects then read their clones from disk.
- **Generating the autoloader**, which these projects optimize, takes 0.7 to 2 s per project without the plugin, depending on the machine: compare the two time tables of each run.

## GitHub Actions, commit 3d528dd

`clonefile(2)` on macOS, and the 4-CPU Linux runners of a public repository: [Benchmarks runs 37210521392](https://github.com/wuemv/composer-store/actions/runs/37210521392) (A) and [37211538803](https://github.com/wuemv/composer-store/actions/runs/37211538803) (B). PHP 8.4.26 with FFI, Composer 2.10.3. All five projects together; each run's job summaries have the times per project.

| All 5 projects | Run | No plugin | Reflinks, empty store | Reflinks, warm store | Hard links, empty store | Hard links, warm store |
|---|---|---:|---:|---:|---:|---:|
| macOS, install | A | 18.74 s | 26.45 s | 20.80 s | 32.19 s | 26.07 s |
| macOS, install | B | 25.02 s | 33.00 s | 28.69 s | 42.17 s | 31.25 s |
| macOS, `--no-autoloader` | A | 11.51 s | 12.00 s | 9.05 s | 24.46 s | 22.55 s |
| macOS, `--no-autoloader` | B | 15.28 s | 14.78 s | 12.32 s | 26.89 s | 29.00 s |
| Linux, Btrfs, install | A | 9.45 s | 12.20 s | 9.82 s | 9.96 s | 9.01 s |
| Linux, Btrfs, install | B | 11.61 s | 14.23 s | 12.69 s | 12.59 s | 11.32 s |
| Linux, Btrfs, `--no-autoloader` | A | 6.01 s | 7.27 s | 5.20 s | 6.32 s | 5.46 s |
| Linux, Btrfs, `--no-autoloader` | B | 7.11 s | 7.79 s | 6.34 s | 7.82 s | 6.58 s |
| Linux, ext4, install | A | 8.32 s | — | — | 9.88 s | 9.01 s |
| Linux, ext4, install | B | 7.89 s | — | — | 9.63 s | 9.01 s |
| Linux, ext4, `--no-autoloader` | A | 4.70 s | — | — | 6.09 s | 5.37 s |
| Linux, ext4, `--no-autoloader` | B | 4.50 s | — | — | 6.26 s | 5.58 s |

On ext4, which has no reflinks, `auto` uses hard links. Disk space without and with the plugin: 375 and 176 MiB on ext4 in both runs; 237 and 82 MiB with reflinks on Btrfs in both runs; 372 and 144 MiB with reflinks on macOS in run A, 403 and 133 MiB in run B.

## Linux VM (development sandbox), ext4, commit 5d78b71

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

## GitHub Actions, Linux, ext4, commit 5d78b71

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

## GitHub Actions, Linux, Btrfs, commit 5d78b71: packages cloned one after the other

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

## GitHub Actions, macOS, APFS, commit c83f626: packages cloned in parallel

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

## GitHub Actions, Linux, Btrfs, commit c83f626: packages cloned in parallel

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

## GitHub Actions, macOS, APFS, commit 5d78b71: packages cloned one after the other

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

## Placing package files

`run-placement.php` times only the placing of files, on the store and Composer cache of one `run.php` run: the 148 package versions of the five projects, 14,229 files, each package placed once into an empty directory. Median of 3 rounds, PHP 8.4.26, [Placement run 37209478073](https://github.com/wuemv/composer-store/actions/runs/37209478073) at commit 2cca633, on the same runners as above.

| Method | Linux, ext4 | Linux, Btrfs | macOS, APFS |
|---|---:|---:|---:|
| `unzip`, one package at a time | 1.81 s | 1.93 s | 5.05 s |
| `unzip`, 10 at a time (Composer without the plugin) | 0.55 s | 1.28 s | 2.57 s |
| PHP `copy()` per file | 1.09 s | 1.28 s | 3.86 s |
| PHP `link()` per file (the plugin's hard links) | 0.48 s | 0.56 s | 4.58 s |
| `cp -al` (Linux) or `pax -rwl` (macOS), one package at a time | 0.69 s | 0.75 s | 7.49 s |
| `cp -al` or `pax -rwl`, 10 at a time | 0.19 s | 0.43 s | 2.47 s |
| `cp` clones, one package at a time | — | 1.04 s | 4.21 s |
| `cp` clones, 4 at a time | — | 0.58 s | 1.72 s |
| `cp` clones, 10 at a time (the plugin's reflinks on Linux, and on macOS without FFI) | — | 0.75 s | 2.30 s |
| `clonefile(2)` through FFI, one call per package (the plugin's reflinks on macOS) | — | — | **0.23 s** |

- On macOS, one `clonefile(2)` call clones a whole package inside the kernel, about 1.6 ms per package: ten times faster than the fastest `cp`, and eleven times faster than Composer's own unzip. The plugin clones that way when PHP has FFI.
- Hard links are slow on APFS: PHP's `link()` takes longer than copying the files.
- On Linux, `cp -al` ten at a time hard-links 2.5 times faster than PHP's `link()` on ext4, which could speed up hard links later. On Btrfs, `cp` clones are fastest four at a time.
