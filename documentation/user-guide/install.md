# Installing Benchy

Benchy runs from a folder, so installing it is mostly a matter of getting PHP on the machine and putting the folder somewhere sensible. This page covers Linux and Windows, and ends with a quick check that it works. It is written for Benchy 0.9.6.

## Before you start

You need a copy of Benchy 0.9.6. On Linux that is a folder (or archive) containing the files `benchy` and `install-sh`. On Windows it is the installer, `Benchy-Setup-0.9.6.exe`, which brings the desktop client (0.10.1) and the native engine (0.3.1) with it. Both are attached to the releases on the project's GitHub page, [github.com/dakotlyar/benchy](https://github.com/dakotlyar/benchy), under **Releases**. The Linux desktop client is a separate download from the releases of [github.com/dakotlyar/benchy-client](https://github.com/dakotlyar/benchy-client) (see [below](#the-linux-desktop-client)). The repositories are private for now; they are meant to become public, possibly with a separate public download page, and this guide will point there when they do.

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

One thing to know about older versions: before 0.9.5, Benchy changed into its own folder when it started, so a file you named on its command line had to be given with a full path (`/home/you/ca.pem`), not a relative one (`ca.pem`). From 0.9.5 a relative name is read from the folder you ran Benchy in, for the commands that take a file (`server-trust`, `server-https-set`, `server-caddy-install`). Result names and test names are unaffected.

### Option 2: install it

From the Benchy folder, run `install-sh` as root to put the `benchy` command in `/usr/bin`:

    sudo ./install-sh

That puts the command in `/usr/bin/benchy`, the program files in `/usr/share/benchy/` and the documentation in `/usr/share/doc/benchy/`. If you cannot or would rather not use root, use Option 1 and add the Benchy folder to your `PATH` instead. (`install-sh` does accept a folder of your own as its first argument. From 0.9.5 it keeps everything inside that folder, but it installs the bash completion and the systemd units only for `/usr` and `/usr/local`; for any other folder it says so and skips them.) The install folder must not contain a space, `&` or `|`; `install-sh` stops with an error if it does.

If `install-sh` prints a line beginning `ERROR:`, it did not replace the `benchy` command you already had. Read the message; it is usually a folder it cannot write to.

To upgrade, unpack the new version and run `./install-sh` again. Your settings and saved results are in your home folder and are not touched.

## Installing on Windows

Run `Benchy-Setup-0.9.6.exe`. The installer works for your user account only, so it does not need administrator rights.

1. Start the installer. The first page names what it is about to do: install, reinstall, upgrade or (after a confirmation) downgrade Benchy.

![The first page of the installer, here offering to reinstall Benchy 0.9.6.](images/win-installer-reinstall.png)

2. Optionally fill in the **Phoromatic server** field with your server's address, in the form `ip:port/accountid` or, for a server that uses HTTPS, `https://host:port/accountid`. Leave it blank to set that up later; [the Phoromatic server chapter](phoromatic-server.md) explains where the address comes from. The Install button stays greyed out while the text in the box is not a valid address.
3. Click **Install**. Windows may ask once for permission to install the Microsoft Visual C++ Runtime if it is missing or too old. Say yes. If it asks for a restart, restart and run the installer again.
4. On the last page, tick **Open Benchy now** if you like, and click **Finish**.

Benchy goes into `%LOCALAPPDATA%\Benchy`. The installer brings its own copy of PHP, the desktop client, and the native engine (in the install folder under `engine\native`, where the client finds it without being told). It adds Benchy to your `PATH` and creates two Start Menu shortcuts:

| Shortcut | What it opens |
|---|---|
| Benchy | The desktop client (see below). |
| Benchy Command Prompt | A command prompt where the `benchy` command works. |

Open a new terminal after installing, so that it sees the new `PATH`, and use `benchy` exactly as the Linux examples in this guide do. Where a Windows difference matters, the chapters say so.

Installing over an existing Benchy upgrades it and keeps your settings, your results and, if the machine was paired with your Phoromatic server, the pairing. The installer will not run while Benchy is running. That includes the desktop client in the tray, and a server agent that was left running in the background with no icon. If the installer stops, quit Benchy first: right-click its tray icon and choose **Quit**. If there is no tray icon, open Benchy from the Start menu, choose Quit and pick **Quit and stop the agent**, then run the installer again. The same goes for the uninstaller. [When something goes wrong](troubleshooting.md#windows) has the exact messages.

The engine keeps its own data (saved native results and the server pairing) in `%LOCALAPPDATA%\benchy-engine`, outside the install folder on purpose, so upgrading and uninstalling both leave it alone. If you retire a paired machine, read [Forget the server before you retire a machine](pair-this-machine.md#forget-the-server-before-you-retire-a-machine) before you uninstall.

The first time you run a test that needs one, Benchy sets up Cygwin (a set of Unix tools) in `C:\cygwin64` and may ask for permission. About a hundred of the tests have Windows versions; the rest are Linux only.

### The desktop client

Benchy Client is a desktop app. On Windows the installer includes it, as version 0.10.1, and the Start Menu shortcut **Benchy** opens it. The client lets you browse the tests, set a test's options, run it with a live progress bar and log, stop it, and upload the result. It does not replace the command line. It drives the same `benchy` underneath, so everything it does is stored in the same `.benchy` folder. This guide describes the command line, which does everything; the client is a friendlier way to do the "find and run" steps. Two things to know: it runs one test at a time, and trusting an HTTPS server for uploads is still done with the `benchy server-trust` command, not in the client ([the Phoromatic server chapter](phoromatic-server.md#1-trust-the-servers-certificate-once)). Pairing a machine so the server can send it work is different, and is done in the client; that is the subject of [Pair this machine](pair-this-machine.md).

The client also lives in the system tray, the row of small icons at the right of the taskbar. Closing its window does not quit it; right-click the tray icon and choose **Quit** for that. [Pair this machine](pair-this-machine.md#the-tray-icon) explains the icon.

### The Linux desktop client

On Linux the client is an AppImage, a single file that you make executable and run, so it needs no installation. Download `BenchyClient-0.10.1-x86_64.AppImage` from the Releases page of [github.com/dakotlyar/benchy-client](https://github.com/dakotlyar/benchy-client) (the same page has a `SHA256SUMS` file to check it against), then:

    chmod +x BenchyClient-0.10.1-x86_64.AppImage
    ./BenchyClient-0.10.1-x86_64.AppImage

The client looks for the command-line Benchy (`benchy` on your `PATH`, then `/usr/bin/benchy`) to run tests, so install Benchy as above first. To pair a Linux machine with the server it also needs the native engine, `benchy-engine`, which is a separate download from the releases of [github.com/dakotlyar/benchy-engine](https://github.com/dakotlyar/benchy-engine). Put it on your `PATH` (or in `/usr/lib/benchy/engine/`). If the client cannot find an engine it opens the **Benchy engines** dialog, where you can browse for the file. The top bar shows which engine is in use ("Benchy (PHP)" or "Benchy native"). Everything about pairing is in [Pair this machine](pair-this-machine.md#linux-the-terminal-route-and-keeping-the-agent-running).

## Check that it works

On either system, open a terminal and run:

    benchy version

It should print `Benchy v0.9.6`. Then try:

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

**Do not run benchmarks as root.** When Benchy runs as root it switches to system locations instead (`/var/lib/benchy/` for data and `/etc/benchy.xml` for settings), so you would end up with a second, separate set of tests and results. Run it as yourself. When a test needs a system package, Benchy asks for the root password at that moment.

## Removing Benchy

On Linux, delete the files `install-sh` listed at the end of its output, and `~/.benchy` if you also want to drop your results. The desktop client AppImage is a single file: delete it. The engine's data, if you used it, is in `~/.local/share/benchy-engine`.

On Windows, use **Apps & Features** (Settings, Apps). Quit Benchy first, as for the installer. The uninstaller removes the program, its shortcuts, the desktop app's own settings and the "start when I log in" entry, and asks before it touches your `.benchy` folder. The shared Visual C++ Runtime and Cygwin stay, and so does `%LOCALAPPDATA%\benchy-engine` (the engine's results and the server pairing), which the uninstaller lists among the things it kept. If the machine was paired with a server, [forget the server](pair-this-machine.md#forget-the-server-before-you-retire-a-machine) before you uninstall.
