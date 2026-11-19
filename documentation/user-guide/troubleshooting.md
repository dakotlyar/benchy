# When something goes wrong

This page lists messages people actually meet, what they mean and what to do. It ends with a list of things Benchy 0.9.15 is known not to do well, so you can tell a limitation from a mistake.

## Starting Benchy

### PHP must be installed for Benchy

The `benchy` launcher cannot find PHP. Install the command-line PHP package (`php-cli` or `php`) with the command the message prints, then try again. See [Installing Benchy](install.md#installing-on-linux).

### To run Benchy locally you must first change directories to benchy/

This is what a copy of Benchy installed by the 0.9.1 `install-sh` printed instead of working. It is fixed. If you see it from an installed `benchy`, run `./install-sh` from the current Benchy folder to replace the broken launcher, then check with `cd / && benchy version`, which should print `Benchy v0.9.14`. If you see the message when you start `./benchy` yourself, you are not in the Benchy folder.

### ERROR: ... is not writable (from install-sh)

`install-sh` puts files under `/usr` by default, which needs root. Run it with `sudo`, or skip the install and [run Benchy from its folder](install.md#option-1-run-it-from-its-folder).

### MISSING ... OPTIONAL (a table of PHP extensions at start-up)

Benchy lists PHP extensions it did not find. `REQUIRED` ones must be installed before it works. The `OPTIONAL` ones only switch off features: no GD means plainer graphs, no PCNTL means some tests and threaded features are unavailable, and no OpenSSL means no HTTPS, so no connection to your Phoromatic server. If you plan to use one, install OpenSSL support first.

On Ubuntu and Debian with the Benchy package, expect to see `Bzip2` listed as missing every time, whichever way you installed. It is harmless: Benchy uses the `bzip2` program for archives, and the extension only affects how much a few files compress. After a `--no-install-recommends` install the table also lists `GD` and `SQLite3`; see [below](#benchy-runs-but-has-no-sqlite3-or-gd-after-a-lean-install).

## The Debian package

### dpkg: dependency problems - leaving unconfigured

You installed the package with `sudo dpkg -i benchy_0.10.1_all.deb`. `dpkg` unpacks a file but does not fetch anything, so on a machine without PHP it stops with lines like these and leaves Benchy half installed:

    dpkg: error processing package benchy (--install):
     dependency problems - leaving unconfigured

    benchy depends on php-cli; however:
     Package php-cli is not installed.

Ask `apt` to finish the job, which fetches what is missing:

    sudo apt -f install

From then on, install Benchy with `sudo apt install ./benchy_0.10.1_all.deb`, as in [Installing Benchy](install.md#on-ubuntu-or-debian-the-deb-package). On a fresh Ubuntu 26.04 container `apt -f install` finished the install and `benchy version` then printed `Benchy v0.9.14`.

### The checksum does not match

`sha256sum -c benchy_0.10.1_all.deb.sha256` printed `FAILED` instead of `OK` (and `WARNING: 1 computed checksum did NOT match`). The download is damaged or is not the file the checksum belongs to. Download both files again from the same release, and keep them in the same folder. Do not install a package that fails the check.

### Error: Unable to locate package benchy_0.10.1_all.deb

You left off the `./`, so `apt` looked for a package of that name in the archive. Write `sudo apt install ./benchy_0.10.1_all.deb`, or give the full path to the file.

### Benchy runs but has no SQLite3 or GD after a lean install

You installed with `--no-install-recommends`. At the start of a command such as `benchy install pts/sysbench`, Benchy lists what it did not find:

    MISSING GD        OPTIONAL -  The GD library is recommended for improved graph rendering.
    MISSING SQLite3   OPTIONAL -  SQLite3 is required when running a Phoromatic server.

Tests install and run normally without them. Install the one you want:

    sudo apt install php-gd         # PNG and PDF graph export
    sudo apt install php-sqlite3    # needed only to run the Phoromatic server

If you start the Phoromatic server without SQLite3, it stops with `PHP SQLite3 support must first be enabled before accessing the Phoromatic server`. Exporting a result to PDF without GD says `PHP GD support and TTF support are required for the PDF output if wanting to display the benchmark result graphs.` (These two messages come from Benchy's source; they were not triggered on the test container.)

### benchy system-info shows less than it should

On a lean install the graphics line shows the kernel's name for the display (for example `amdgpudrmfb`) instead of the card, and the chipset, audio and network lines are missing. Benchy gets those from `lspci` and `ip`. Install them with `sudo apt install pciutils iproute2`.

### OpenGL or Vulkan details are missing on a headless machine

Since 0.9.7, when Benchy installs a test's system packages on a machine with no display and no graphics hardware it can see, it skips `mesa-utils` (which provides `glxinfo`) and `vulkan-tools`. This is deliberate: on a server they would add about 120 packages of desktop libraries. A test's own requirements are still installed. What you can miss is the OpenGL and Vulkan information that Benchy reads from `glxinfo` and `vulkaninfo`. If you have a graphics card or an emulated one that Benchy does not see, and want that information in your results, install the tools by hand:

    sudo apt install mesa-utils vulkan-tools

A desktop you reach over SSH does not need this, because its graphics hardware is visible and it gets the tools automatically. A Docker container counts as headless unless you pass a graphics device in, for example with `--device /dev/dri`. (Benchy prints no message about skipping them, and this page's wording about results with and without them comes from the source and the release notes, not from a graphics test run on a headless machine.)

## Running tests

### There are dependencies still missing from the system

Benchy tried to install a test's system packages and could not. It offers four choices: ignore and carry on (the test will probably fail to build), skip the tests that need the missing packages, try again, or quit. Read the lines above the choices to see which packages failed, install them yourself with your package manager, then choose "try again". Package installs need root, so Benchy asks for the root or `sudo` password when it runs them.

### An answer is required but standard input is closed; exiting

Benchy wanted to ask a question and there was nobody to answer, as happens in a `cron` job, a CI job, or a command with its input redirected from `/dev/null`. Either run it in a terminal, or answer the questions in advance. [Running without questions](finding-and-running-tests.md#running-without-questions) shows how.

### Phoronix Test Suite Argument Missing / CORRECT SYNTAX

You left off something the command needs, often the name of a result or a test. The line under `CORRECT SYNTAX:` shows what it expects. The program name it prints (`phoronix-test-suite`) is the old one; type `benchy` instead.

### WARNING: another Benchy ... run is in progress on this user path

A second benchmark or install is already running. See [the run lock](good-to-know.md#one-run-at-a-time-the-run-lock). Stop one of them if the numbers matter.

### [NOTICE] Unable to obtain ... from OpenBenchmarking.org

Benchy could not download the description of one test. The command usually carries on without it. If it keeps happening for a test you want, check your connection, then run `benchy openbenchmarking-refresh`.

### The result file name is refused

Result names cannot be the name of a test or suite, and they have a length limit. Choose something else, such as your machine's name.

## Trusting and reaching your Phoromatic server

### The certificate of ... does not verify against the CA file or the system store

Benchy cannot check the server's certificate against anything it trusts, or the server did not answer. Either you have not run `benchy server-trust` yet, or the address is wrong, or the server is down. Run `server-trust` with the fingerprint from the administrator ([step 1 here](phoromatic-server.md#1-trust-the-servers-certificate-once)). Benchy never skips the check; there is no setting for that.

### ERROR: ... holds no PEM certificate

Either the file really is not a certificate in PEM format (it should begin `-----BEGIN CERTIFICATE-----`), or Benchy could not find it. Check the name and the folder you ran the command from. (With a Benchy older than 0.9.5, a relative name like `ca.pem` pointed into Benchy's own folder; give the full path there.)

### The fingerprint does not match the one you gave ... Someone may be in the way

The CA that answered is not the one you were told about. Do not carry on. Check the fingerprint you were given, and ask the administrator to run `benchy server-https-status` again. If both agree and it still fails, something on your network is intercepting the connection.

### With that CA the certificate of ... does not verify (is ... one of the names the certificate covers?)

The CA is right but the certificate does not name the host you typed, for example you used an IP address and the certificate lists only `server.example`. Use the exact name the administrator gave you.

### Upload Failed

`benchy phoromatic.upload-result` got no acceptance from the server. Things to check, in this order:

1. Is the machine approved? A newly connected machine appears on the server's Systems page as awaiting approval until an administrator accepts it. Since 0.10.0 Benchy says so ("Upload Failed: this system is waiting for approval by an administrator on the server, so nothing was stored."); older versions printed "Result Uploaded" in that case although nothing was kept.
2. Did you run `server-trust`, and does the address in your command start with `https://`?
3. Is the account ID in the address right? It is the last part: `https://server.example:39443/ACCOUNT`.
4. Can you reach the server at all? Try opening `https://server.example:39443/` in a browser from the same machine.

### Too many requests naming an unknown account

A machine that connects with an account ID the server does not know gets this reply, and so does everything else at the same address for the next 15 minutes, even requests with the right ID. The server counts requests per address: 20 for an account that does not exist within 15 minutes shuts that address out of the older connection method until the window ends. Check the account ID in the address on every machine behind yours (`https://server.example:39443/ACCOUNT`; see point 3 of the checklist under Upload Failed), correct any that are wrong, and wait. If several machines share one address, a single mistyped one is enough to lock the rest out. The administrator can tell you which account ID is the real one.

### Too many systems are already waiting for approval in this account

The account already has 200 machines waiting on the Systems page. Ask an administrator of the account to approve or delete some; your machine can register once there is room.

### My results feed link stopped working

Since 0.9.12 the links that feed readers use have a longer form, with a key the server makes. Links made before the upgrade give an empty feed. Open the server's **Results** menu, choose **Results Feed**, and subscribe with the new link. The feed holds your own account's results only.

### ... is no longer available to you. You are now in ...

You were working in a Phoromatic account that an administrator of the server then suspended, deleted or took you out of. You are not signed out because you belong to another account, and the server moved you to it. What you were doing in the first account was not saved. If you need the first account back, ask the administrator of the server. See [When an account is no longer available to you](phoromatic-server.md#when-an-account-is-no-longer-available-to-you).

### Account Suspended, or "The log-in is not possible ..."

You gave the right password, but you cannot sign in. "This Phoromatic account is suspended. Ask the administrator of this server." means every account you belong to is suspended. "The log-in is not possible: this user is not a member of any account." means you have been taken out of all of them. "The log-in is not possible as this account has been disabled." means your login was turned off. Only an administrator of the server can change any of these; ask them. The server says this only after the right password. A wrong password still gets "The user-name or password did not match our records.", so the messages tell a stranger nothing.

### Phoromatic is already running

You started `phoromatic.connect` twice. It only runs once per user. Use the first one, or close it first.

## Pairing and the tray

These are the messages on the Settings, Server agent page and in the tray menu, from [Pair this machine](pair-this-machine.md). Most of them end with a button that fits: **Edit** takes you back to the box with your pairing string still in it, **Retry** tries again, **Close** leaves.

### That doesn't look like a pairing string. Copy it again from the server's Systems page.

The text in the box is not a pairing string. It should start `benchy-enroll:https://`. Copy the whole line, with no spaces or line breaks at either end. The Details line under the message may say "that is not an enrollment code ([hidden] followed by 43 characters)": that is what you get when the code inside the string is damaged, most often because the string was pasted into the box twice. Click **Edit**, select everything in the box (Ctrl+A), delete it and paste once.

![The pairing form after a damaged pairing string.](images/win-pairing-error.png)
<!-- unverified: screenshot shows the 0.11 Server dialog; retake on Kamino --> Under the box, Benchy says "This doesn't look like a pairing string; the server's answer will say." while you type something it cannot read.

### The server doesn't recognize this code. Check you copied the whole pairing string.

The code part of the string is wrong or cut short. Copy it again. If it still fails, ask for a new one.

### This code has expired (codes last 24 hours). / This code has already been used.

An enrollment code works once and lasts 24 hours; a join code works once and lasts an hour. Ask the administrator for a new one from the server's Systems page. Nothing was saved on your machine.

### Type the join code first. / A join code has ten characters, like K7Q-29X-A1FH.

The box is empty, or what you typed is not a join code. A join code has exactly ten characters, made of the digits and the letters on the server's screen (capitals and small letters are the same, dashes are optional). Check the code against the server's Systems page and type it again.

### This code wasn't made by the server at ... Check the code, or pick another server. / None of the servers found made this code. Type the server's address.

The ten characters of a join code include four that tie it to one server. Benchy worked out what they should be for the server you picked, or for each server it found, and none matched, so it sent nothing. The usual causes are a typo (try again slowly), a code from a different server than the one you picked, or a code from a server Benchy could not find. In the last case press **Type an address instead**, type the server's address and **Check**, then type the code again. A code that is wrong the same way on the right server is not a fault of your network; ask for a new one.

### The server at ... doesn't take join codes. Use a pairing string from its Systems page.

That server is older than 0.10.0, or has its built-in HTTPS off, and cannot make or check join codes. Ask the administrator for a pairing string.

### ... answered, but it isn't a Benchy server. / Nothing answered at ...

You typed an address that answered with something other than a Benchy server, or did not answer. Check the name and the port (39443 unless the administrator changed it) and that this computer can reach the server.

### This server's certificate doesn't cover any address this computer can reach. Ask the admin to add ... to the HTTPS names

You typed an IP address that the server's certificate does not list, typically a NAT or port-forward address. The message names the address and the command the administrator needs, `sudo benchy-server-setup --names ADDRESS,` followed by the names the server already has. Nothing is wrong with your computer.

### No Benchy servers found. A server on another part of your network (another VLAN) can only be found if your admin adds it to DNS.

**Find servers** looks on your own network segment (mDNS) and, when your computer has a DNS search domain, for a record your administrator may have added. Neither found anything. Check that you are on the same network as the server, or press **Type an address instead** and type it. The dialog may also say "This computer has no DNS search domain, so only this network segment was searched." or "Many servers answered; only the first ones are listed." Both are information.

### Join codes are switched off for about N more minute(s) because too many wrong ones were tried on this server.

The server counts wrong codes from every computer together. After a hundred in ten minutes it stops accepting join codes for ten minutes, and a person who typed the code correctly just has to try again afterward. A pairing string keeps working in the meantime. Ten wrong codes from one address in fifteen minutes lock that address out for a while, as with any code.

### ...'s certificate isn't trusted by this computer. If the server uses its own certificate authority, paste the CA fingerprint shown in the server's Enroll box.

This is the normal first step for a server with its own certificate authority when the string has no fingerprint in it, not a fault. The smaller line under it, "The engine said: ... did not verify (verify_failed)", is Benchy telling you why it asked.

Your machine does not know the server's certificate authority yet, and the pairing string did not carry a fingerprint. Paste the SHA-256 fingerprint the administrator gave you into the box that appears and click **Pair with this fingerprint**. If you see "A CA fingerprint is 64 hexadecimal digits (SHA-256)", what you pasted is not the right length; copy it again.

### The server's certificate authority doesn't match the pairing string. Nothing was saved.

The fingerprint in the pairing string is not the one the server showed. Either the string is from another server, or something between you and the server is intercepting the connection. Do not try again with the same string. Check with the administrator, and get a new string if they confirm the server's fingerprint has not changed. There is no override for this one.

### The server's certificate isn't signed by the certificate authority in the pairing string. Nothing was saved.

The same kind of problem, seen from the other side: ask the administrator.

### The server's certificate is for a different name than ... Pair using the name in the certificate

The address in your string is a name or an IP address that the server's certificate does not cover. The server's Enroll box shows a red warning about this when the string is made. Ask for a new string made while the administrator is browsing the server by one of the names the certificate covers, then click **Edit** and paste it.

### The server's certificate has expired. The server's admin needs to renew it.

Nothing to do on your side.

### Can't reach ... Check the address, and that this computer can reach the server.

The machine could not make a connection. Check the address in the string, that you are on the right network (a VPN, for example), and that the server is up, then click **Retry**.

### The server refused: ...

The server answered and said no. The rest of the message is the server's reason, and the Details line may add an HTTP status. Ask the administrator, quoting both.

### This computer is already paired with ... Pairing again replaces it

You are pairing a machine that already has a server. Read the message: replacing stops this machine taking work from the old server and puts unsent results aside. Click **Replace** only if that is what you want. If the message says the machine "trusts a different certificate authority for it than the pairing string names", pairing will change which authority it trusts for that server.

### The server accepted this computer, but the credentials couldn't be saved here, and the pairing code is now used up.

The server took the code but this machine could not write the pairing to disk, usually because of permissions or a full disk. The message's Details line says why. Fix that, ask for a new code and pair again; the old code cannot be used a second time.

### The server agent keeps starting. Stop it from its tray menu or the Settings, Server agent page, then try again.

Benchy has to stop the agent to pair, and the agent kept coming back. Stop it with **Stop server agent** in the tray menu and pair again.

### Benchy is already pairing or forgetting a server. Try again in a moment.

A second pairing or forget was started while the first was still working. Wait, then **Retry**.

### Can't read the server agent's state

Shown as a grey icon with a question mark. Benchy could not get an answer from the engine. Open Settings, Server agent and click **Copy details** for the technical state to send to whoever looks after Benchy. Benchy keeps trying.

### The server agent stopped on its own. Start it again, or pair again if the server revoked this computer.

The Server agent page shows this, and the tray icon gets a red dot. The agent exits by itself when the administrator revokes the system on the server. Click **Start** once; if it stops again at once, the token has been revoked and you must pair again with a new code. The state line says "Stopped: ..." with the reason.

### Can't reach the server; trying again in 30 s

The agent could not reach the server and waits before trying again. Results of finished jobs are kept on the machine and sent when the server is back. Nothing to do unless it goes on for a long time.

### Held by the server / Paused while tests run on this computer

Not faults. "Paused while tests run on this computer" is the agent standing aside because you are running your own tests. "Held by the server" means the server asked the agent to wait, and the server gives the reason.

### A schedule waits for "Update the engine on this system to 0.7.0 or later"

The server holds a schedule that contains a test needing administrator rights (the catalog marks these "Installs ... for all users" or "Runs as administrator") on a machine whose engine cannot run it, and says so on the machine's page. It is not a fault and nothing was lost: the schedule runs as soon as the reason is gone. The engine that comes with Benchy 0.10.1 is 0.8.0 and can run these tests, so the fix is to update Benchy on that machine (the Windows installer carries the new engine). Until then the administrator can move the test into a schedule of its own so the other tests keep running. The administrator's side is in [The signed test catalog](../admin-guide/catalog.md).

### A schedule waits: "this system can't run tests that need administrator rights yet" / "someone at this machine has to accept ... once"

Both are the machine's own state, not faults. The first means the machine is neither set up to run administrator tests without asking nor started from the tray where it can ask you; start the server agent from the Benchy tray, or have an administrator of the machine do the unattended setup (see [Scheduled tests that need administrator rights](pair-this-machine.md#scheduled-tests-that-need-administrator-rights)). The second means the test has to be accepted once at the machine: use **Review and accept…** in the tray, or `benchy-engine machine-accept TEST@VERSION`. The schedule runs as soon as that is done.

### The administrator approval was declined. Nothing was changed.

You pressed **No** on Windows' own prompt while accepting a test or approving a run. Nothing was installed or run. Try again when you are ready; for a scheduled run, the Approval dialog offers it again.

### Busy with server job ...

You pressed **Run** while a server job was using the machine. Benchy offers to cancel the server job; the tests that had finished are uploaded. Otherwise wait for it, and see [the run lock](good-to-know.md#one-run-at-a-time-the-run-lock).

### Benchy was started in a way that may stop the server agent when Benchy closes. Start Benchy from the Start menu to avoid this.

A Windows-only warning. The agent is meant to keep running when Benchy closes. If Benchy was itself started from inside something that keeps its programs together and does not let them leave (a terminal, a code editor or a scheduled task), the agent cannot leave either, and may stop when that program closes. Quit Benchy and start it from the Start menu.

### The agent never gets a job

If the Server agent page said "The server still has this system on the PHP engine. An admin needs to switch it to native before jobs arrive.", that is the cause: ask the administrator to switch the system. Otherwise the schedule has to include the system and contain tests the native engine can run.

### The tray icon is not there (Linux)

Some desktops, GNOME among them, show no tray without an extension. Benchy then keeps its window as its only home: closing it quits (after asking about the agent), and notices that would have been tray balloons appear as a banner at the top of the window. The Server dialog says "Your desktop has no tray. On Linux the systemd user unit (benchy-agent.service, see the engine's README) is the better way to keep the agent running." Follow that advice.

## Windows

### The installer says Benchy is running

Since 0.11.0 the installer closes a running Benchy itself, so this appears only when it could not. In the installer window it shows as "Benchy was not installed, Exit code 3", or as a "Benchy is busy" note on the first page that keeps **Next** off.

What to do depends on what the page names:

- "A test is running in Benchy. Finish or stop it, then click Next." The client is older than 0.12, and a test is running in its window. Wait for the test, or stop it, and click **Next**. The first upgrade from 0.10.1 hits this whenever a test is running, because the old client cannot be asked to quit.
- A `benchy.bat` console or a PHP process is named. Close that window, and **Next** comes back.
- A client that did not quit, or a message that a file "is in use" and that Benchy "may be running as administrator": Benchy was started with administrator rights, which the installer cannot see. Quit it from its tray icon, or close it, and run the installer again. A silent install ends the same way, with exit code 3.
- The tray icon is there and the page does not name a cause: right-click it, choose **Quit** (pick **Quit and stop the agent** if asked), and run the installer again.

The installer never touches the unattended agent while it runs, since that one runs from Program Files. <!-- unverified: each message above on Kamino-B, particularly the old-client path -->

### The unattended agent is still `<version>`, update it in Settings

The upgrade finished, but the unattended agent's own copy of the engine was not updated, because you unticked **Update the unattended agent too**, or declined Windows' prompt, or a job was running. Nothing is broken: the old agent keeps running. Open Settings, **Server agent**, and press **Update it to ...**. <!-- unverified: the exact line on the installer's last page -->

### This computer is paired twice

An unattended setup from earlier is still on the machine and your account was then paired again. The agent is stopped, and `agent run` and `agent setup-elevated` refuse with "This computer is paired twice. Open Benchy, Settings, Server agent, and keep one of them, or run `benchy-engine agent reset --keep user` or `--keep unattended`." Follow [Fix a computer that is paired twice](settings.md#fix-a-computer-that-is-paired-twice). If the page only says "Update Benchy to fix this here.", the engine is older than 0.9.0; update Benchy.

### The administrator approval was declined. Nothing changed. (Settings)

You said no to, or did not answer, Windows' prompt during a reset or the paired-twice fix. The other variants are "The administrator approval timed out. Nothing changed.", "Windows couldn't show the administrator approval. Nothing changed.", and "There is no desktop to show the administrator approval on. Nothing changed." (a session with nobody logged on). In each case nothing was removed, not even the parts in your own account. Press the button again and approve the prompt; if it does not come to the front, look for its blinking button on the taskbar.

### Some of it couldn't be removed. / The server agent didn't stop in time, or a test is running.

The first is a reset that left something behind. Settings lists each item with the reason, and anything queued for the next restart is marked "removed at the next restart"; restart Windows, then open Reset and uninstall to check. The second means the agent is still finishing an upload or a test; try again in a moment. <!-- unverified: both messages on Kamino-B -->

### The Engine chip says the engine didn't answer within 30 seconds (client 0.10.2)

The desktop client waits up to 30 seconds for Benchy to say hello when it starts. If nothing comes, the **Engine** chip fails with "The engine didn't answer within 30 seconds." and a **Try again** button appears. Press it; the check starts over. Once Benchy has said hello the client waits as long as it takes, so a Benchy installing Cygwin for the first time is not cut off. If it keeps failing, run `benchy --json hello` in a terminal and see whether it answers.

### Cygwin isn't set up yet, so tests can't run (client 0.10.2)

On a Windows machine without Cygwin the client's **Run tests** page says so, browsing works, and Run is blocked with "Set up Cygwin first." Press **Set up Cygwin** and approve the Windows prompt; if no prompt is visible, click the blinking shield on the taskbar. If it ends with "The setup didn't finish", the reason follows: you declined the Windows prompt, or the download of Cygwin's setup program was cut short. Press **Set up Cygwin** again. Benchy no longer reuses a half-downloaded Cygwin setup file, so a retry starts clean. If the message instead names a file and a package that are missing from `C:\cygwin64\bin`, Cygwin is installed but incomplete: run Cygwin's setup program again (Benchy keeps `cygwin-setup-x86_64.exe` in your Downloads folder), search for that package and choose **Reinstall**. Closing the client while it is setting Cygwin up asks you to confirm, because quitting may leave a half installation; Benchy sets it up again next time.

### The desktop client said "Missing: run_lock"

That was a display fault in Benchy 0.9.0 to 0.9.2 and is fixed in 0.9.3. Upgrade the installed Benchy.

### The Benchy engines dialog says a feature is missing

When the client lists "Missing: ..." for the native engine, for example "phoromatic.upload-result (no upload)", it means that engine cannot do that job. That is expected: uploads and some other functions use the PHP engine. The client says "Benchy native ... is ready" when the engine itself is fine.

### Stopping a test on Windows does not stop it

Cancelling on Windows assumes Cygwin is installed in `C:\cygwin64`. If you let Benchy set Cygwin up itself, that is where it is. If you installed Cygwin elsewhere, a cancel may leave the test's processes running; end them in Task Manager.

### Going back to Benchy 0.9.5

Do not run the 0.9.5 installer over 0.9.15 (or 0.9.14, 0.9.13, 0.9.12, 0.9.11, 0.9.10, 0.9.9, 0.9.8, 0.9.7 or 0.9.6). It removes the native engine but leaves the "start when I log in" entry, so the older client opens a window at every login, and it resets the desktop app's settings. Uninstall 0.9.13 first, then install 0.9.5. Forget the server first if the machine is paired.

## Uninstalling Benchy

### Windows didn't give permission

The uninstaller page says "Nothing has been removed yet", and its button is now **Try again**. The uninstall needs Windows' permission once, for the parts that are for all users, and without it the unattended agent would keep running and keep this computer's pairing. Click **Try again** and say yes. If the page says Windows had no desktop to show its prompt, run the uninstaller again from a signed-in session. If the prompt timed out, run it again and answer sooner. A silent `uninstall.exe /S` with no desktop ends with exit code 5 and changes nothing.

### Benchy is almost removed

Some items were in use. The page lists each with the reason; they are removed the next time Windows starts, and the exit code is 3010. Restart Windows before you install Benchy again.

### Not everything was removed

Exit code 40. Some items could not be removed and could not be queued. The page says "Benchy is partly removed. Run the uninstaller again to finish, or remove what the log names by hand." The log is `%TEMP%\Benchy-uninstall.log`. <!-- unverified: the page and the log path -->

### My script thinks uninstall.exe /S worked, but Benchy is still there

A silent `uninstall.exe /S` copies itself to `%TEMP%` and returns 0 at once. Run it as `uninstall.exe /S _?=%LOCALAPPDATA%\Benchy` to wait and get the real exit code. See [Uninstall with the command line](reset-and-uninstall.md#uninstall-with-the-command-line).

## Known limitations in 0.10.1

- Windows needs `benchy server-trust` for every HTTPS server, even ones with a certificate from a public authority.
- A CA you trust with `server-trust` is trusted for every Benchy server your machine knows, not just the one it came from.
- Before 0.9.5, file names given on the Benchy command line had to be full paths, not relative ones.
- `result show` in JSON mode reports only the latest run of a result that holds several runs.
- Only about a hundred of the tests have Windows versions.
- On a first Windows launch of 0.9.14 or earlier, the desktop client's **Engine** chip waits while the PHP side installs Cygwin. From 0.9.15 the install waits for you to ask for it (with client 0.10.2, the **Set up Cygwin** button).
- Scheduled tests that need administrator rights run on Windows only, and only on a machine whose engine is 0.8.0 or later (the Windows installer of 0.10.1 carries it). Someone at the machine approves each run, or an administrator sets the machine up to run them unattended. A suite that holds such a test cannot be built yet; put the test in a schedule on its own.
- Sending your own native results from the desktop client needs a server of 0.10.0 or later; to an older one, results stay on the machine and show "Waiting for the server to take results from this computer". A machine that runs unattended cannot send its own results at all yet; the upload-only credential for that is planned for a later release.
- Server discovery finds servers on the same network segment, and on other VLANs only through a DNS record the administrator adds. Everything it finds is a suggestion; you still need a join code or pairing string.
- Resetting or uninstalling Benchy 0.11.0 removes the machine's pairing from the machine, but not from the server, so the token stays valid there until an administrator removes or revokes the system. Before 0.11.0, uninstalling did not remove the pairing at all. Forget the server (Settings, Server agent, **Forget this server...**) or have it revoked on the server before you retire a machine.
- Resetting and uninstalling leave products that Benchy installed for administrator tests (for example PassMark PerformanceTest for all users); remove them from Windows' Installed apps.
- A silent `uninstall.exe /S` returns at once unless it is called with `_?=`.
- You cannot withdraw a single accepted administrator test; a failed upload from the Benchy window is not retried by itself, and waits under Settings, Results and uploads.
- Quitting with "Quit, keep the agent running" leaves a background agent with no icon. The installer will not run until you stop it.
- If the installed copy of Benchy is missing and you tick "Start at login" from another copy, the login entry points at that copy until the installed one starts again.
- A machine that is paired a second time with a code that is not tied to an existing system becomes a new system on the server, not the old one again.
- The result viewer on the server still shows the upstream logo.
- The desktop client has a login entry on Windows only. On Linux use the systemd user unit.
- The desktop client runs one test at a time, and cannot trust a server's certificate for uploads by itself (pairing is separate: it pins the server's certificate authority as part of the pairing).
- `install-sh` does not support an install folder whose path contains a space, `&` or `|`. If one of its copy steps fails (for example, the disk is full) it can still report success.
- On macOS, the `install_macos` script does not point the launcher at the install, so run Benchy from its folder there.
- There is no package for Fedora, Red Hat or other RPM-based systems yet, and Debian 12 has not been tried with the Debian package. On those systems, install PHP and run Benchy from its folder.
- The Debian package has been checked on a server with no display. Graphics tests on a headless machine, and the PNG and PDF graph export, were not part of that check.
- The server's own pages and a few messages still say `phoronix-test-suite` where they mean `benchy`.
