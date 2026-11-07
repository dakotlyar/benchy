# Pre-Scripted Phoronix Test Suite / Phoromatic Deployments

## deb-package: Generate A Debian/Ubuntu Package

`deploy/deb-package/build-package-deb.php` turns a checkout of Benchy into two packages, `benchy_<version>_all.deb` and `benchy-server_<version>_all.deb` (built together so their versions cannot drift). Run it from the top of the checkout:

    php deploy/deb-package/build-package-deb.php --output /some/dir

It needs `php-cli`, `php-xml`, `dpkg-dev` (for `dpkg-deb`) and `fakeroot`. Without `--output` the packages land in the current directory. The builder works in a temporary directory, runs `install-sh` against it, and stops with the failing command's output if any step goes wrong, so a .deb that exists is a .deb that was built. It never touches your home directory, so building on a desktop is safe, but a throwaway container is the cleanest place: an `ubuntu:26.04` container with the checkout mounted read-only and an output directory mounted read-write is all it takes.

The package installs the same tree as `./install-sh /usr`: the `benchy` launcher in `/usr/bin`, the program in `/usr/share/benchy`, the man page, bash completion, a desktop entry, and the three systemd unit files in `/usr/lib/systemd/system`. It has no maintainer scripts. In particular the units are only copied into place: the Phoromatic server is not enabled and not started by the package, so installing it on a server does nothing until you ask for it.

### What it depends on

The package is meant to install on a headless server and then work: `benchy version`, `benchy system-info`, and installing and running a test that has to be compiled, such as `pts/sysbench`. Nothing it depends on pulls in X11, Wayland, a desktop or a display server. The dependency list was worked out from the code (the `extension_loaded` and `function_exists` checks, the commands Benchy shells out to) and then proved on a minimal Ubuntu 26.04 image.

| Package | Why it is there |
| --- | --- |
| `php-cli`, `php-xml`, `php-zip`, `php-curl`, `php-mbstring` | The interpreter and the extensions Benchy needs: dom and simplexml for the test and result XML, ZipArchive for result archives, curl for downloads, mbstring for the Phoromatic JSON handling. The other extensions Benchy checks for (openssl, zlib, bz2, posix, pcntl, sockets, readline, json) are already part of Debian and Ubuntu's `php-cli`. |
| `build-essential`, `pkgconf` (or `pkg-config`) | Most tests are compiled when they are installed. `build-essential` is the compiler and make; `pkgconf` is separate and autotools tests such as `pts/sysbench` fail without it. |
| `tar`, `gzip`, `bzip2`, `xz-utils`, `unzip` | Unpacking test downloads. |
| `ca-certificates`, `procps` | https downloads, and `ps` for the system probing. |

Recommended, so a normal `apt install` brings them along but a server admin can leave them out with `--no-install-recommends`:

| Package | What it is for |
| --- | --- |
| `php-sqlite3` | The Phoromatic server's database. Without it `start-phoromatic-server` stops and says so. (The `benchy-server` package depends on it.) |
| `php-bz2` | `bzcompress`. Ubuntu's `php-cli` lacks the bz2 extension, and without it every command prints `MISSING Bzip2`. |
| `php-gd` | Rendering result graphs to PNG and PDF. The SVG graphs in the result viewer need nothing. It drags in the X11 client libraries (`libx11-6`, `libxcb1`, `libxpm4`, a few fonts) because that is how the distribution builds libgd. These are only shared libraries, not an X server, but if you want none of them, install with `--no-install-recommends`. |
| `pciutils`, `iproute2` | `lspci` and `ip`, for the hardware and network lines in `system-info`. |

Not packaged: `benchy-engine` (a separate download), the Caddy web server for the Phoromatic server's built-in HTTPS, and anything graphical or hardware specific, such as `mesa-utils`, `vulkan-tools`, `dmidecode`, `ipmitool` or the PHP `ssh2` extension. Benchy notices when they are missing and carries on.

### The benchy-server package

`benchy-server_<version>_all.deb` sets a machine up to run the Phoromatic server the way the reference server is run. It depends on exactly the same version of `benchy`, on `php-sqlite3`, `adduser`, `systemd`, `init-system-helpers`, `iproute2` and `curl`. The source is in `deploy/deb-package/benchy-server/`. `install-sh` does not install any of it, so a server installed from a source tree is not affected.

What installing it does (with no network and no questions): it creates the system user `phoromatic` (home `/var/lib/phoromatic`, no login shell) unless a user of that name already exists, creates `/var/lib/phoromatic` and `/var/lib/phoromatic/storage` as `phoromatic` with mode 0750 when they are missing, and installs a systemd drop-in, `/usr/lib/systemd/system/phoromatic-server.service.d/10-benchy-server.conf`, that makes the unit run as that user with `HOME=/var/lib/phoromatic`, `PHOROMATIC_HTTP_PORT=39080` and `PTS_FORCE_DAEMONIZED=1`, waits five seconds between restarts and does not restart after a refusal (exit status 78). Installing it enables nothing, starts nothing and writes no settings. The one exception is a server that is already running as the `phoromatic` user (one set up by hand): that is restarted onto the new code. A server running as root (0.9.7's stock unit) is left alone, with a warning, because restarting it under the new settings would bring up an empty server. Your own overrides go in `/etc/systemd/system/phoromatic-server.service.d/`.

The rest is `/usr/sbin/benchy-server-setup`, which you run as root once: it writes the settings file, creates the database (`benchy server-init`), installs Caddy, turns on built-in HTTPS (`internal`, the names you give; plain HTTP stays served on 39080 for older clients unless you pass `--http-mode redirect` or `off`), closes registration and creates the first account, then enables and starts the service and waits until HTTPS answers. It is safe to run again: every step checks whether it is already done. `sudo benchy-server-setup --check` (it needs root, to run Benchy as `phoromatic`) changes nothing and reports whether a server matches the reference layout, which also works on a server installed from a source tree (`sudo sh deploy/deb-package/benchy-server/benchy-server-setup --check`).

Upgrading a running server restarts it onto the new code (`try-restart`); a stopped server stays stopped. `apt remove benchy-server` stops and disables the service and keeps the user, `/var/lib/phoromatic` and the settings. **`apt purge benchy-server` deletes `/var/lib/phoromatic`**: the database, the results and the certificate authority, and prints a warning when it does. The `phoromatic` user stays either way. Purge does not delete while the service or any process of the `phoromatic` user is still running: it prints the path and the `rm -rf` command and leaves the data. A custom `--storage-path` outside `/var/lib/phoromatic` is never touched by purge; delete it yourself.

`--storage-path` must be a folder whose parent `phoromatic` can write to (for example `sudo chown phoromatic: /srv/phoromatic`, then `--storage-path /srv/phoromatic/data`): Benchy silently falls back to `/var/lib/phoromatic/.benchy/phoromatic/` when it cannot, so the setup script refuses such a path up front. If a database already exists in that fallback folder, the script refuses to start a second server beside it unless you say `--storage-path /var/lib/phoromatic/.benchy/phoromatic/` (or move the old one away).

Never run `benchy` as root on a server host: the first command run as root creates `/etc/benchy.xml`, `/var/lib/benchy` and `/var/cache/benchy`, none of which a server running as `phoromatic` reads. The setup script runs every Benchy step as `phoromatic`.

### Headless notes

- **GPU tools are skipped on headless machines.** Whenever Benchy installs the system packages a test needs, it also checks a short list it calls its common dependencies. For Ubuntu and Debian that list is `mesa-utils`, `vulkan-tools`, `unzip` and `apt-file`, and `mesa-utils` and `vulkan-tools` alone drag in around 130 packages (GTK, Mesa, Wayland). Benchy now leaves the OpenGL and Vulkan info tools out of that list (on every distribution that carries them) when the machine is headless, meaning there is no `DISPLAY` or `WAYLAND_DISPLAY` and also no GPU: no `/dev/dri/renderD*` node and no NVIDIA device. A desktop you reach over SSH has no `DISPLAY` but does have its render node, so it still gets the tools; a plain server does not. Inside Docker Benchy's display check always answers "no display", even if `DISPLAY` is set in the container, so a container counts as headless unless it has a render node passed through (for example `--device /dev/dri`). A server with a GPU that you want kept lean can install the packages it needs by hand; a machine with only a basic VGA/BMC display (no render node) counts as headless. `unzip` and `apt-file` stay in the list either way. `apt-file` is small, and Benchy uses it to work out which package supplies a missing file.
- **Caddy.** `benchy server-https-set` needs a Caddy 2.8 or newer. Ubuntu 26.04 only ships 2.6, which is too old, and the distribution's own `caddy` package also starts a web server of its own. Run `benchy server-caddy-install` instead: it downloads the Caddy release pinned by Benchy from GitHub, checks its size and sha256, and keeps it under Benchy's own data directory. It needs outbound HTTPS to GitHub. If the server cannot reach GitHub, download the archive elsewhere and pass it with `--archive`.
- **Running the Phoromatic server.** `systemctl enable --now phoromatic-server` when you want it. For a quick look without systemd, `benchy start-phoromatic-server` runs in the foreground and needs its standard input left open (under a container, `sleep 600 | benchy start-phoromatic-server`), because it treats end-of-input as a request to stop.
- **Batch mode.** `benchy batch-setup` asks a handful of questions. For unattended runs, answer them once by hand (or feed it a stream of answers that does not end) and then use `batch-install` and `batch-run`. A test that offers options needs them preset, for example `PRESET_OPTIONS="sysbench.run-test=cpu" benchy batch-run pts/sysbench`.

### What has been tested

The .deb has been built and installed on a minimal Ubuntu 26.04 container with no display, where `benchy version`, `benchy system-info`, a batch install and run of `pts/sysbench`, the Phoromatic server over plain HTTP and over its built-in HTTPS all worked. A smoke install (the package installs, `benchy version` and `system-info` run) also passes on Debian 13 and Ubuntu 24.04. Debian 12 has not been tried.

## rpm-package: Generate A RedHat/Fedora RPM Package

`deploy/rpm-package/build-package-rpm.php` builds `benchy-<version>-1.noarch.rpm` in the same way, from the top of the checkout, with `--output DIR` to choose where it goes:

    php deploy/rpm-package/build-package-rpm.php --output /some/dir

It needs `rpmbuild` (`rpm-build`), `php-cli`, `php-xml` and `bzip2`. The build runs in a private temporary directory, which it also hands to `rpmbuild` as `HOME` and as `_topdir`, so rpm never reads, moves or writes your own `~/.rpmmacros`. Like the deb it installs the units without enabling them (there is no `%post`).

The Requires and Recommends mirror the deb, with Fedora and Enterprise Linux names: `php-cli`, `php-xml`, `php-pecl-zip` (or `php-zip`), `php-mbstring`, `php-process` (pcntl and posix live there), `gcc`, `gcc-c++`, `make`, `pkgconfig`, the unpacking tools, `ca-certificates`, `procps-ng` and `which` (the launcher uses it, and minimal Fedora images lack it); weak dependencies are `php-pdo` (sqlite for the Phoromatic server), `php-gd`, `pciutils` and `iproute`.

**The rpm is much less tested than the deb.** It builds, and the result installs and runs `benchy version` on Fedora, but nobody has yet run a test or the Phoromatic server from it, and it has not been tried on AlmaLinux or Rocky. On Enterprise Linux 9 the default PHP module stream is 8.0; enabling a newer one (`dnf module enable php:8.3`) before installing is likely what you want, and Caddy for the HTTPS server is not in the base repositories there either.

## phoromatic-upstart: Reference Upstart job files for Phoromatic

The `*.conf` files provide basic `phoromatic-client` and `phoromatic-server` job files for Upstart-powered Linux systems looking to deploy the Phoromatic on either the front or back-end. Read the Phoronix Test Suite documentation for more details.

## phoromatic-systemd: Reference systemd service files for Phoromatic

The `*.service` files provide basic `phoromatic-client` and `phoromatic-server` job files for systemd-based Linux systems looking to deploy Phoromatic for controlling the Phoronix Test Suite.

## phoromatic-initd: Reference sysvinit script for Phoromatic

The files provide basic `phoromatic-client` /etc/init.d implementation for older Linux systems.

## JuJu

Ubuntu JuJu deployment charm for the Phoronix Test Suite.

## farm-system-customizations: Example files of common system changes made to systems in the LinuxBenchmarking.com farm

Various scripts commonly used by the Phoronix reference farm / LinuxBenchmarking.com for reference purposes.

## Windows Installer: install.ps1 / install.bat / benchy.bat

`install.bat` (repo root) is a thin double-click entry point that hands off to
`install.ps1` (bypassing PowerShell's default execution-policy prompt so it
works without any manual setup). `install.ps1` does a per-user install to
`%LOCALAPPDATA%\Benchy` -- no admin rights needed for the install itself:
downloads and unpacks a current PHP NTS x64 build from windows.php.net if no
usable `php.exe` is found (writing a minimal `php.ini` so `openssl`/`curl`/
`mbstring`/`zip` are actually enabled, since a fresh PHP zip ships with none
of them on), copies the Benchy tree into place, adds it to the user's `PATH`,
creates a Start Menu shortcut, offers to register a Phoromatic server, and
finishes by running `benchy.bat php-conf` as a self-check.

`benchy.bat` (repo root) also keeps its own independent PHP-fetch fallback
for anyone running it directly without having gone through the installer
first (e.g. `C:\PHP` already present, or a portable/no-install usage). Keep
its pinned PHP version and download URL in sync with `install.ps1` when
either one is updated.
