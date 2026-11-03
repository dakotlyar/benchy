# Installing Benchy

Benchy runs from a folder, so installing it is mostly a matter of getting PHP on the machine and putting the folder somewhere sensible. This page covers Linux and Windows, and ends with a quick check that it works. It is written for Benchy 0.9.3.

## Before you start

You need a copy of Benchy 0.9.3. On Linux that is a folder (or archive) containing the files `benchy` and `install-sh`. On Windows it is the installer, `Benchy-Setup-0.9.3.exe`. Both are attached to the releases on the project's GitHub page, [github.com/dakotlyar/benchy](https://github.com/dakotlyar/benchy), under **Releases**. The repository is private for now; it is meant to become public, possibly with a separate public download page, and this guide will point there when it does.

You also need a few gigabytes of free disk space for the tests you download, and an internet connection the first time, because Benchy fetches its list of tests and each test's files from OpenBenchmarking.org. This is a request for files only; see [what Benchy sends out](good-to-know.md#what-benchy-does-and-does-not-send-out).

## Installing on Linux

Benchy needs only the command-line version of PHP, not a web server. The package is usually called `php-cli` or `php`. It also needs a handful of PHP extensions. The launcher prints the likely command if PHP is missing; for the common families it is:

| Distribution | Command |
|---|---|
| Debian, Ubuntu | `sudo apt-get install php-cli php-xml` |
| Fedora, Red Hat | `sudo dnf install php-cli php-xml php-json` |
| Arch | `sudo pacman -S php` |

Four extensions are required: DOM, ZIP (or zlib), JSON and SimpleXML. Benchy also wants OpenSSL, because it is how it talks to HTTPS servers, including your Phoromatic server. Curl, GD, POSIX and PCNTL are recommended. Each time you start Benchy it lists any that are missing, marked `REQUIRED` or `OPTIONAL`, so you do not have to guess.

### Option 1: run it from its folder

Unpack Benchy anywhere you like and run it in place:

    cd benchy
    ./benchy version

Nothing is installed system-wide. This is the easiest way to try it. To type just `benchy` from anywhere, add the folder to your `PATH` (for example `export PATH="$PATH:$HOME/benchy"` in your shell's startup file).

One habit to learn straight away: Benchy changes into its own folder when it starts, so a file you name on its command line must be given with a full path (`/home/you/ca.pem`), not a relative one (`ca.pem`). Result names and test names are unaffected.

### Option 2: install it

From the Benchy folder, run `install-sh` as root to put the `benchy` command in `/usr/bin`:

    sudo ./install-sh

That puts the command in `/usr/bin/benchy`, the program files in `/usr/share/benchy/` and the documentation in `/usr/share/doc/benchy/`. If you cannot or would rather not use root, use Option 1 and add the Benchy folder to your `PATH` instead. (`install-sh` does accept a folder of your own as its first argument, but it also writes a few files in the folder above it, so it is tidier for a package build than for a home directory.) The install folder must not contain a space, `&` or `|`; `install-sh` stops with an error if it does.

If `install-sh` prints a line beginning `ERROR:`, it did not replace the `benchy` command you already had. Read the message; it is usually a folder it cannot write to.

To upgrade, unpack the new version and run `./install-sh` again. Your settings and saved results are in your home folder and are not touched.

## Installing on Windows

Run `Benchy-Setup-0.9.3.exe`. The installer works for your user account only, so it does not need administrator rights.

1. Start the installer. The first page names what it is about to do: install, reinstall, upgrade or (after a confirmation) downgrade Benchy.
2. Optionally fill in the **Phoromatic server** field with your server's address, in the form `ip:port/accountid` or, for a server that uses HTTPS, `https://host:port/accountid`. Leave it blank to set that up later; [the Phoromatic server chapter](phoromatic-server.md) explains where the address comes from. The Install button stays greyed out while the text in the box is not a valid address.
3. Click **Install**. Windows may ask once for permission to install the Microsoft Visual C++ Runtime if it is missing or too old. Say yes. If it asks for a restart, restart and run the installer again.
4. On the last page, tick **Open Benchy now** if you like, and click **Finish**.

Benchy goes into `%LOCALAPPDATA%\Benchy`. The installer brings its own copy of PHP, adds Benchy to your `PATH`, and creates two Start Menu shortcuts:

| Shortcut | What it opens |
|---|---|
| Benchy | The desktop client (see below). |
| Benchy Command Prompt | A command prompt where the `benchy` command works. |

Open a new terminal after installing, so that it sees the new `PATH`, and use `benchy` exactly as the Linux examples in this guide do. Where a Windows difference matters, the chapters say so.

Installing over an existing Benchy upgrades it and keeps your settings and results. The installer will not run while Benchy is running; close any open Benchy windows, including the desktop client, first.

The first time you run a test that needs one, Benchy sets up Cygwin (a set of Unix tools) in `C:\cygwin64` and may ask for permission. About a hundred of the tests have Windows versions; the rest are Linux only.

### The desktop client

Benchy Client 0.9.0 is a desktop app. On Windows the installer includes it. On Linux it is distributed as an AppImage (a single file you make executable and run) attached to the same GitHub releases; the Linux build has not been checked for this guide, so treat that part as unverified. The client lets you browse the tests, set a test's options, run it with a live progress bar and log, cancel it, and upload the result. It does not replace the command line. It drives the same `benchy` underneath, so everything it does is stored in the same `.benchy` folder. This guide describes the command line, which does everything; the client is a friendlier way to do the "find and run" steps. Two things to know: it runs one test at a time, and trusting an HTTPS server is still done with the `benchy server-trust` command, not in the client ([the Phoromatic server chapter](phoromatic-server.md#1-trust-the-servers-certificate-once)).

## Check that it works

On either system, open a terminal and run:

    benchy version

It should print `Benchy v0.9.3`. Then try:

    benchy system-info

This prints what Benchy detected about your machine: processor, graphics, memory, disk, operating system and so on. It does not change anything, so it is a good first check that detection works.

## Where Benchy keeps things

When you run Benchy as an ordinary user, everything it keeps is under `~/.benchy/` (on Windows, `.benchy` in your user folder).

| Path | What is in it |
|---|---|
| `~/.benchy/user-config.xml` | Your settings. |
| `~/.benchy/installed-tests/` | Tests you have installed. |
| `~/.benchy/test-results/` | Saved results, one folder per result. |
| `~/.benchy/download-cache/` | Files downloaded for tests, kept so a reinstall is quick. |

The folders appear when Benchy first needs them, not at install time.

**Do not run benchmarks as root.** When Benchy runs as root it switches to system locations instead (`/var/lib/benchy/` for data and `/etc/phoronix-test-suite.xml` for settings), so you would end up with a second, separate set of tests and results. Run it as yourself. When a test needs a system package, Benchy asks for the root password at that moment.

## Removing Benchy

On Linux, delete the files `install-sh` listed at the end of its output, and `~/.benchy` if you also want to drop your results. On Windows, use **Apps & Features** (Settings, Apps). It removes the program and its shortcuts and asks before it touches your `.benchy` folder; the shared Visual C++ Runtime and Cygwin stay.
