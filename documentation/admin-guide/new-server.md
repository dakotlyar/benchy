# Set up a Phoromatic server on a new Ubuntu 26.04 machine

This page takes a freshly installed, headless Ubuntu 26.04 server to a working Phoromatic server that is reached over HTTPS, with its first account created and the first test machine paired to it. It is written for the person who installs and looks after the server, not for someone at a desk who only runs benchmarks; that reader wants the [user guide](../user-guide/index.md). You need to be comfortable in a terminal and with `sudo`, but you do not need to know Benchy. It covers **Benchy 0.9.9**, installed from the two Debian packages `benchy_0.9.9_all.deb` and `benchy-server_0.9.9_all.deb`.

Since 0.9.8 one command, `benchy-server-setup`, does the work that used to be a page of hand-made settings: it creates the database, fetches Caddy (the web server that provides HTTPS), turns HTTPS on, closes account registration, creates your account, and starts the server. By the end you will have the server running as its own unprivileged user, started at boot by systemd, listening on one HTTPS port with a certificate from Benchy's built-in certificate authority (CA). The page also covers backing the server up, upgrading, removing it, moving its data to a new machine, and the messages you are most likely to meet.

In this page the server is `server.example.com` with the address `203.0.113.10`, and the administrator is `yourname`. Replace them with your own. Whenever a Benchy command has to change the server's data, it is run as `sudo -u phoromatic env HOME=/var/lib/phoromatic benchy ...`, which means "as the server's own user, with that user's home folder". Benchy refuses to touch the server's data as anyone else, and the home folder decides which settings file and data it finds.

## What you need

- A server running Ubuntu 26.04 and a user on it who can use `sudo`.
- A name or an address that your test machines will use to reach the server. The certificate is made for exactly the names and addresses you give the setup, so give every one that a machine might use, such as the host name and the IP address. The name must resolve from the test machines.
- Outbound HTTPS from the server to GitHub, for the Caddy download. Without it you can fetch the archive elsewhere (see [A server that cannot reach GitHub](#a-server-that-cannot-reach-github)).
- Inbound access from your test machines to one TCP port, 39443.

## What the installer sets up

The two packages and the setup command always build the same layout, so this is what you will find on every server:

| Item | Where or what |
|---|---|
| Service user | `phoromatic`, a system account with no login shell, home `/var/lib/phoromatic` |
| Service | `phoromatic-server`, run by systemd as that user, started at boot once you have run the setup |
| Server settings | `/var/lib/phoromatic/.benchy/user-config.xml`, readable only by `phoromatic` |
| Data | `/var/lib/phoromatic/storage/`: the database, every account's results and uploads, and the `tls` folder with the CA |
| HTTPS | Port 39443, certificate from the built-in CA, through Caddy |
| Plain HTTP | Port 39080, for older clients; `serve` by default (see [Plain HTTP](#plain-http)) |
| Web server behind Caddy | Port 39082, on the loopback address only |
| Registration | Closed from the start. The first account is made on the command line. |

## 1. Get both packages onto the server

Each package comes with a file of the same name plus `.sha256` that holds its SHA-256 checksum, so you can tell that the copy you install is the copy that was published. Check both.

### While the Benchy repository is private

Download the files on a computer where you are signed in to GitHub with access to the repository. With the GitHub command-line tool (`gh`) that is:

    gh release download v0.9.9 -R dakotlyar/benchy -p '*.deb*'

That gives you four files: the two packages and their two checksum files. Check them there, copy them to the server, and check them again on the other side. The second check is the one that matters, because it proves nothing changed on the way:

    sha256sum -c benchy_0.9.9_all.deb.sha256 benchy-server_0.9.9_all.deb.sha256
    scp benchy_0.9.9_all.deb* benchy-server_0.9.9_all.deb* you@server.example.com:

and then, on the server:

    sha256sum -c benchy_0.9.9_all.deb.sha256 benchy-server_0.9.9_all.deb.sha256

Each check should print:

    benchy_0.9.9_all.deb: OK
    benchy-server_0.9.9_all.deb: OK

If either prints `FAILED`, delete all four files and copy them again. Do not install a package that fails the check.

### Once the repository is public

Download straight onto the server:

    curl -fLO https://github.com/dakotlyar/benchy/releases/download/v0.9.9/benchy_0.9.9_all.deb
    curl -fLO https://github.com/dakotlyar/benchy/releases/download/v0.9.9/benchy_0.9.9_all.deb.sha256
    curl -fLO https://github.com/dakotlyar/benchy/releases/download/v0.9.9/benchy-server_0.9.9_all.deb
    curl -fLO https://github.com/dakotlyar/benchy/releases/download/v0.9.9/benchy-server_0.9.9_all.deb.sha256
    sha256sum -c benchy_0.9.9_all.deb.sha256 benchy-server_0.9.9_all.deb.sha256

## 2. Open the firewall

Do this before anything is running, so that the server is never reachable on a port you did not choose. Allow SSH first, so that turning the firewall on does not lock you out, then the server's one port:

    sudo ufw allow 22/tcp
    sudo ufw allow 39443/tcp
    sudo ufw enable
    sudo ufw status

`ufw enable` asks you to confirm; answer `y`. `ufw status` should list `22/tcp` and `39443/tcp` as `ALLOW`.

Leave 39080 closed unless you have older Benchy clients that need plain HTTP. A new server serves plain HTTP on that port unless you tell the setup otherwise, but with the firewall in place nobody outside can reach it. The other port Benchy uses, 39082, is on the loopback address and cannot be reached from outside. If your server sits behind a cloud provider's firewall or a router, open 39443/tcp there as well. The setup never changes a firewall; it only tells you at the end which ports it uses.

## 3. Install the packages

Run this from the folder that holds the files, with the `./` in front of each name so that `apt` treats them as files and not as package names:

    sudo apt update
    sudo apt install ./benchy_0.9.9_all.deb ./benchy-server_0.9.9_all.deb

Use a plain `apt install`. The server keeps everything in an SQLite database, and the PHP extension for it, `php-sqlite3`, is a dependency of `benchy-server`, so `apt` brings it along. The `benchy` package also recommends `php-gd` (graphs as PNG and PDF) and the `lspci` and `ip` tools; none of these is needed to run the server, and you can leave them out with `--no-install-recommends`.

Installing `benchy-server` creates the `phoromatic` user and its folders and puts the service settings in place. It starts nothing and enables nothing. Its last line tells you what to do next:

    benchy-server: installed. Next: sudo benchy-server-setup --names HOST,IP --admin-user NAME --admin-email ADDRESS

Check that Benchy is there. Run this as yourself, not as root and not with `sudo`:

    cd / && benchy version

Expected, after a few lines about building its test catalogue the first time:

    Benchy v0.9.9

**Never run `benchy` as root on a server.** The first command run as root creates `/etc/benchy.xml`, `/var/lib/benchy` and `/var/cache/benchy`. A server running as `phoromatic` reads none of them, so settings put there do nothing (see [If something goes wrong](#if-something-goes-wrong)). The setup command is the one thing you run with `sudo`, and it runs every Benchy step as `phoromatic` itself.

## 4. Run the setup

The setup needs three things from you: the names and addresses machines will use, and the details of your own account. You can give them as flags, or leave them out and let the command ask on a terminal.

### With flags

    sudo benchy-server-setup --names server.example.com,203.0.113.10 \
        --admin-user yourname --admin-email you@example.com

`--names` is a comma-separated list with no spaces. With no `--admin-password-file` it asks for your password on the terminal (twice, and it does not show what you type). To give the password in a file instead, which is what you want in a script, put it on the first line of a file that only you can read:

    (umask 077; read -rsp 'Password: ' pw && printf '%s\n' "$pw" > ~/benchy-pw)

and add `--admin-password-file ~/benchy-pw` to the command. The password never appears on the command line. Delete the file when the setup has finished.

The user name must be four or more characters, made of letters, digits, dot, dash or underscore (`admin`, `administrator` and `rootadmin` are reserved). The password must be at least six characters, and the email address must be a valid one. The setup checks all three before it changes anything.

### Or let it ask

Run `sudo benchy-server-setup` on a terminal with no flags. It suggests the machine's host name and address for the names; press Enter to take the suggestion. It then asks for a user name, an email address and a password, and asks again if an answer is not acceptable:

    Host names and addresses that machines will use to reach this server, separated by commas [server.example.com,203.0.113.10]:
    User name for the first account: ab
      the user name must be at least four characters long
    User name for the first account: yourname
    Email address for it: you@example.com
    Password for yourname:
    Password again:

Run without a terminal, for example from a script or with `--yes`, it never asks. It stops with the flags it needs and changes nothing; the exit status is 2.

### What it does

The setup works through eight steps. Each is skipped if it is already done, and it stops at the first one that fails, before the service is enabled.

| Step | What happens |
|---|---|
| 1 | Checks the machine and your answers. Nothing changes yet. |
| 2 | Writes the server's settings file. |
| 3 | Creates the database. |
| 4 | Downloads the pinned Caddy and checks it. |
| 5 | Turns on HTTPS for your names. |
| 6 | Closes account registration, then creates your account. |
| 7 | Enables the service, starts it, and waits until HTTPS answers. |
| 8 | Prints a summary. |

It takes a minute or two on a connection that can fetch Caddy, which is an 18 MB download. The summary looks like this (the fingerprint here is a sample):

    The Phoromatic server is ready.
      Address:             https://server.example.com:39443/
      Names in the certificate: server.example.com, 203.0.113.10
      CA fingerprint (give it to the people who pair machines, by a route other than the network they use to reach the server):
        5E:32:96:F9:D1:80:CA:DA:F5:EA:65:01:71:B7:14:C2:39:75:EB:76:8A:4F:4F:B3:67:9B:06:95:45:1A:BE:33
      Settings file:       /var/lib/phoromatic/.benchy/user-config.xml
      Storage:             /var/lib/phoromatic/storage/
      Ports:               39443 HTTPS (all addresses); 39082 PHP (this machine only); 39080 plain HTTP (serve)

    Firewall (this script changes none):
      39443/tcp  HTTPS: the web interface and paired machines.
      ...

The **CA fingerprint** is the value to keep. It identifies the server's certificate authority, and a test machine uses it to be sure it is talking to your server and not to someone in between. Note it down and give it to whoever pairs machines, by a route other than the network path the machine will use to reach the server. It belongs to the CA, which is made once and kept in the server's storage, so restarts and upgrades do not change it. The certificate itself is renewed automatically. You can read the same value again at any time:

    sudo -u phoromatic env HOME=/var/lib/phoromatic benchy server-https-status

### Plain HTTP

Older Benchy clients that are not paired talk to the server over plain HTTP. `--http-mode` decides what happens to that port, 39080:

| Mode | What it does |
|---|---|
| `serve` | The default for a new server. Keeps the old plain-HTTP address answering for older clients, and redirects the web pages to HTTPS. |
| `redirect` | Redirects the web pages to HTTPS and answers anything else with an error that names the fix. |
| `off` | No plain-HTTP port at all. |

Machines paired as in step 6 talk to the server over HTTPS only. We recommend `--http-mode off` whenever you have no older clients: add it to the setup command, and nothing ever listens on 39080. The default stays `serve` so that an older client keeps working without anyone having to think about it, but a plain-HTTP port you do not use is one more thing to look after. You can change the mode later by running the setup again with a different `--http-mode`.

### Other options

| Option | What it does |
|---|---|
| `--https-port N` | A different HTTPS port, from 1024 to 65535. The default is 39443. |
| `--registration open` | Leaves account registration open. Normally you leave it closed (see [Accounts and registration](#accounts-and-registration)). |
| `--storage-path DIR` | Keeps the data somewhere other than `/var/lib/phoromatic/storage/`. The folder's parent must exist and be writable by `phoromatic` (for example `sudo chown phoromatic: /srv/phoromatic`, then `--storage-path /srv/phoromatic/data`), or Benchy quietly ignores the path, so the setup refuses it. Existing data is not moved. |
| `--caddy PATH` | Uses a Caddy you already have (version 2.8 or newer) instead of downloading one. |
| `--caddy-archive FILE` | Uses a copy of the pinned Caddy archive instead of downloading it. |
| `--yes` | Never asks a question. A missing answer is an error. |
| `--check` | Changes nothing; compares the server with the standard layout. See [Check that it works](#7-check-that-it-works). |

### A server that cannot reach GitHub

If the Caddy download fails, the setup stops at step 4 and says so, naming the archive:

    ERROR: Caddy could not be installed. If this machine cannot reach GitHub, download caddy_2.11.7_linux_amd64.tar.gz on another computer, copy it here and run this script again with --caddy-archive FILE (or use --caddy PATH for a Caddy 2.8 or newer you already have).

Do that, and run the same command again with `--caddy-archive /path/to/caddy_2.11.7_linux_amd64.tar.gz` added. Steps 2 and 3 are already done and are skipped. The archive comes from the Caddy releases on GitHub (`https://github.com/caddyserver/caddy/releases/download/v2.11.7/`); Benchy checks its size and checksum before it uses it.

## 5. Log in

Open `https://server.example.com:39443/` in a browser. Use one of the names you gave the setup, not a different one: the page builds the pairing strings in step 6 from the address in your browser's address bar, and a string for an address the certificate does not cover will not work.

Your browser will warn that the certificate is not trusted, because the built-in CA is not one it knows. That is expected. Open the certificate details, compare the SHA-256 fingerprint of the issuing (root) certificate with the one from the summary, and if they match, continue. To stop the warning on your own computer, copy the CA root certificate from the server and add it to your browser or system trust store. It is `/var/lib/phoromatic/storage/tls/caddy-data/pki/authorities/local/root.crt`, and only root and `phoromatic` can read it.

The welcome page has no form for creating an account: it says that account creation is closed. Log in with the user name and password you gave the setup.

### Accounts and registration

On a new server the setup closes registration before it creates your account, so nobody can sign up on a fresh server before you do. Your account is the first one; the machines you pair belong to it. Each account is a separate space, with its own machines, schedules and results.

These commands (run as the service user) change that later:

    sudo -u phoromatic env HOME=/var/lib/phoromatic benchy server-registration
    sudo -u phoromatic env HOME=/var/lib/phoromatic benchy server-registration open
    sudo -u phoromatic env HOME=/var/lib/phoromatic benchy server-registration closed --message 'Ask the administrator.'

The first shows the current state, the others open or close the form. With the form closed, the welcome page shows the message instead (the same setting as **Account Creation String** on the server's **Admin Config** page). To make another account yourself:

    sudo -u phoromatic env HOME=/var/lib/phoromatic benchy server-account-create --user colleague --email colleague@example.com

On a terminal it asks for the password. In a script, give the password as one line on standard input; do not feed it from an empty or closed pipe. The same rules apply as in the setup, and it either creates the account or leaves nothing behind.

## 6. Pair the first machine

Pairing introduces a test machine to the server once. The server makes a one-time pairing string; the machine uses it and from then on takes scheduled work on its own. This step is the server's half; the machine's half is in the [user guide](../user-guide/pair-this-machine.md).

1. Log in with your own account and open **Systems**.
2. Find **Enroll A Native System**. Type a label for the code, such as `first-machine`, and press **Enroll a new native system**.
3. The page shows the **Enrollment code** box, once. It lists the **Server**, the **Code**, the **CA fingerprint (SHA-256)** and a **Pairing string**, a line that starts `benchy-enroll:https://`, like this:

       benchy-enroll:https://server.example.com:39443?code=ben_...&ca=5e3296f9d180cadaf5ea...

   The code in it works once and expires after 24 hours. Press **Copy** and keep the string private until the machine has used it. If you lose it, make another; unused codes are listed under **Open codes** on the same page, where you can revoke them. The label only names the code in that list. Once the machine has paired, it appears under its own computer name.
4. Send the string to the person at the machine, and the fingerprint separately. The pairing string already contains the fingerprint (the `ca=` part, in lower case without colons), so the person only needs yours to compare against what their screen shows.

On the machine, the person pairs with the Benchy desktop client or with the engine on the command line. As an example, from a terminal on a Linux machine that has `benchy-engine` (the string goes in on standard input, so that it stays out of the process list):

    echo '["benchy-enroll:https://server.example.com:39443?code=...&ca=..."]' | benchy-engine --json agent enroll --json-args -

The last two lines of its output tell you it worked:

    {"v":1,"type":"agent_enrolled","seq":3,...,"server":"https://server.example.com:39443","account":{"id":"6BCW4X","name":"6BCW4X"},"system":{"id":"IHEJQ","title":"bench1"},"policy":"native","moved_aside":0}
    {"v":1,"type":"done","seq":4,...,"status":"ok","exit_code":0}

There is no separate approval step: the enrollment code is the approval. Back on the **Systems** page, the machine appears under **Active Systems**, and shows `Idle (native agent)` once its agent is running (`benchy-engine --json agent run`, or the desktop client's tray, which starts it by itself). Until then it shows `Enrolled, waiting for the agent`.

## 7. Check that it works

Five checks, from the server's side.

1. The standard layout is in place. This only reads, and it needs `sudo`:

       sudo benchy-server-setup --check

   It prints a line for each item, marked `ok`, `DIFFERENT` or `MISSING`, then a short list of settings it reports without calling them wrong (plain HTTP, registration), and ends `The layout matches the reference.` with exit status 0. A difference ends with status 1.

2. The server describes itself. Run this as the service user:

       sudo -u phoromatic env HOME=/var/lib/phoromatic benchy server-info

   Expected, on a server that has one account:

       Configuration file: /var/lib/phoromatic/.benchy/user-config.xml
       Storage: /var/lib/phoromatic/storage/ (from PhoromaticStorage in the configuration file)
       HTTP port the service used at its last start: 39080 (PHOROMATIC_HTTP_PORT; from /var/lib/phoromatic/.benchy/phoromatic.log)
       ...
       Database: /var/lib/phoromatic/storage/phoromatic.db, schema version 58
       HTTPS: internal on port 39443, plain HTTP: serve, names server.example.com, 203.0.113.10
       Accounts: 1
       Registration: closed (Account creation is closed on this server. Ask its administrator for an account.)

   The line about "a server started by hand from this shell" is for people who run Benchy in the foreground; it does not describe the service. The same settings, with where each one came from, are written to the service log at every start (see [If something goes wrong](#if-something-goes-wrong)).

3. A page loads over HTTPS, from another computer. Without the CA trusted, `curl` refuses the certificate, which is the correct behavior; `-k` skips the check for this one test only:

       curl -sk -o /dev/null -w '%{http_code}\n' https://server.example.com:39443/

   It prints `200`.

4. The service survives a restart:

       sudo systemctl restart phoromatic-server && sleep 12 && systemctl is-active phoromatic-server

   It prints `active`.

5. A result arrives. The machine's agent must be running, so its state on the **Systems** page is `Idle (native agent)`. Open **Create A Schedule**, give it a title and a description, tick your machine under the systems it runs on, tick every day under **Active On**, and press **Create Schedule**. On the new schedule's page, scroll to **Native Tests**, find **STREAM** (`benchy/stream`, a memory test of about a minute that needs a compiler on the machine; `build-essential` provides one) and press **Add**. The section only offers tests once a paired machine's agent has connected, because the list comes from the machine. Then press **Run Test Schedule Now**; the machine picks the job up at its next check-in, within a minute or so. When the run finishes the machine goes back to `Idle (native agent)` and **Results** lists a result named after the schedule, for example `smoke - 2026-11-07`.

   Expect two runs if the schedule's time of day has already passed today. A daily schedule whose run time is behind you runs once for today on its own, and **Run Test Schedule Now** adds a separate run on top of it. You then see two jobs and two results, and both are real. If you want just one result, set the run time for later today and leave out the button, and wait for the scheduled run. If it does not, see [If something goes wrong](#if-something-goes-wrong).

The server keeps its own log at `/var/lib/phoromatic/.benchy/phoromatic.log` and Caddy's log at `/var/lib/phoromatic/storage/tls/caddy.log`. Both sit in folders that only root and the `phoromatic` user can read.

## Day two

### Run the setup again

Running `sudo benchy-server-setup` on a finished server is safe. Every step checks whether it is already done, and a server that is running and needs nothing is not restarted. With no flags it only checks and fills in whatever is missing, which is also how you repair a server: a missing Caddy, a stopped service, a lost settings file.

To change something, run it with the flag. Changing `--names` replaces the whole list, so always give every name:

    sudo benchy-server-setup --names server.example.com,203.0.113.10,newname.example.com

The CA stays the same, so the fingerprint does not change. A machine paired by a name you removed has to be paired again.

The output tells you only what changed. If you change the names, it says how the list changes; if you change `--http-mode` or `--https-port`, it says that instead. HTTPS changes are applied live by Caddy within a few seconds, with no restart of the service, and the output says so. The setup then waits until the new settings answer before it finishes.

### Upgrade

Installing a newer pair of packages replaces the program files and the unit; your settings and data are untouched. Upgrading restarts a server that is running, so the web pages and the launcher stay on the same version; a stopped server stays stopped, and reinstalling the same version touches nothing.

1. Back up (below).
2. Get the new packages onto the server and check them, as in step 1.
3. Install both together:

       sudo apt install ./benchy_NEW_all.deb ./benchy-server_NEW_all.deb

4. Run `cd / && benchy version` and the checks in step 7. Read the release notes first: they say whether the database changes, because after a release that changes the database, going back means restoring a backup. Release 0.9.8 has no database change.

### Remove, and purge

`sudo apt remove benchy-server` stops and disables the service and keeps everything: the data, the settings and the CA. The `benchy-server-setup` command goes away with the package. To bring the server back, install the package again and run `sudo benchy-server-setup`; with no flags it finds the existing server, enables it and starts it again.

`sudo apt purge benchy-server` **deletes `/var/lib/phoromatic`**: the database, every result, the settings and the CA. Machines paired with the server have to be paired again with a new one. A warning says so as it runs, and it cannot be undone, so back up first. `apt` stops the service before it deletes, but if some other process of the `phoromatic` user is still running, such as a server started by hand, the purge refuses to delete anything, and prints the command to do it yourself:

    benchy-server: /var/lib/phoromatic was NOT deleted because the phoromatic-server service, or a process of the user phoromatic, is still running. Stop it, then delete the server's data yourself if you want it gone:  sudo rm -rf /var/lib/phoromatic

A storage folder you placed outside `/var/lib/phoromatic` is never deleted, and the `phoromatic` user is always kept.

### Back up

Everything the server needs is in `/var/lib/phoromatic/storage`: the database (`phoromatic.db`), every account's results and uploaded files, and the `tls` folder with the built-in CA and its private key. Losing the `tls` folder means a new CA with a new fingerprint, and every paired machine has to be paired again. The settings file is rebuilt by the setup, and the rest of `/var/lib/phoromatic/.benchy` is a cache and the logs, so neither needs to be in the backup.

Copy the storage folder with the service stopped, so that the database is not written to while you copy it:

    sudo systemctl stop phoromatic-server
    sudo mkdir -p /root/backups
    sudo tar -C / -czf /root/backups/phoromatic-$(date +%Y%m%d-%H%M).tgz var/lib/phoromatic/storage
    sudo systemctl start phoromatic-server
    sudo ls -l /root/backups

The archive holds the CA's private key and password hashes, so keep it where only you can read it, and copy it off the machine. `/root` is already private. It is about 18 MB on a new server, most of it Caddy.

### Restore, or move to another machine

To put a backup, or the storage folder of another server, onto a machine, install the two packages there, but do not run the setup yet. Then:

1. Unpack the data into `/var/lib/phoromatic/storage/` and give it to the service user. The numbers behind the `phoromatic` user differ from machine to machine, so always set the owner:

       sudo tar -C / -xzf /root/backups/phoromatic-20261107-0425.tgz var/lib/phoromatic/storage
       sudo chown -R phoromatic: /var/lib/phoromatic/storage

   Unpack only the `storage` folder, as above. If you unpack a path that includes `/var/lib/phoromatic/.benchy`, `tar` makes that folder owned by root and the server cannot write its log or its lock file there. In that case the service will not start, and its log says so in plain words: `The Phoromatic Server could not start: ` followed by the path, who owns it, and the `chown` command that fixes it. Running `sudo benchy-server-setup` repairs the owner of the service's home folder (`/var/lib/phoromatic`, if a restore left that one owned by root too) and of everything inside `.benchy` and the storage folder, and it tells you which files it changed. It only looks at folders inside the service's home. If you gave `--storage-path` a folder somewhere else, the setup says that its owner is not looked at or changed, and you set it yourself with `chown`. `sudo benchy-server-setup --check` reports a wrong owner as `DIFFERENT` without changing anything.
2. Run the setup with the names, and nothing else:

       sudo benchy-server-setup --names server.example.com,203.0.113.10

The setup finds the accounts in the database and the CA in the `tls` folder. It says how many accounts it found, asks for no new account, keeps registration as it was, and starts the server with the same CA fingerprint. Machines that were paired to the old server reconnect once the new one answers at the same name. If the new machine has a different name or address, put it in `--names`, and point the name at the new machine.

A new, empty `--storage-path` is a new server, not a restore: it needs `--admin-user`, `--admin-email` and a password, like a first run. If a database already exists in Benchy's fallback folder, `/var/lib/phoromatic/.benchy/phoromatic/`, the setup refuses to start a second server beside it unless you name that folder with `--storage-path` or move the old one away.

### A server you set up by hand with 0.9.7

You can install `benchy-server` over a server made by hand from the 0.9.7 version of this page, which used the same layout. Run the setup once afterwards; with no flags it only checks and fills in what is missing. A server that was enabled with the stock unit that 0.9.7 shipped runs as root, and is left running as it is when `benchy-server` is installed, because restarting it under the new settings would bring up an empty server. The install prints this warning:

    benchy-server: phoromatic-server is running as root (or is restarting by itself), not as phoromatic, so it was NOT restarted. It will come up as phoromatic with an EMPTY database the next time it restarts or the machine reboots. Stop and disable it now (sudo systemctl disable --now phoromatic-server), then run: sudo benchy-server-setup

Do what it says. If that server kept its data somewhere other than `/var/lib/phoromatic/storage/`, copy it there first (see [Restore, or move to another machine](#restore-or-move-to-another-machine)). The hand-setup instructions themselves are no longer in this guide; the 0.9.7 version of this page is in the project's history if you need them.

## If something goes wrong

Start with the service log, which is where the server writes every message below:

    sudo journalctl -u phoromatic-server -n 50 --no-pager

Every start writes a short block of settings, so you can see which file and port the server actually used:

    Server settings in effect:
      Configuration file: /var/lib/phoromatic/.benchy/user-config.xml
      Storage: /var/lib/phoromatic/storage/ (from PhoromaticStorage in the configuration file)
      HTTP port: 39080 (PHOROMATIC_HTTP_PORT)
      HTTPS: internal on port 39443, plain HTTP: serve

`benchy server-info` shows the same thing at any time.

| What you see | What it means and what to do |
|---|---|
| `systemctl status` says `failed`, and the log ends `Not starting: ...` with `status=78/CONFIG` | The server refused to start, and tells you why in the line before. systemd shows it as failed and does not retry in a loop. For `No Caddy is installed for the built-in HTTPS`, run `sudo benchy-server-setup` (it fetches Caddy again; use `--caddy-archive` if the server cannot reach GitHub), then check the service. The server deliberately never falls back to plain HTTP. |
| Other `Not starting: ...` lines, such as `The HTTPS port 39443 is already in use.` or a Caddy that does not match the checksum recorded when it was installed | The message names the problem. A busy port means another program has it: stop that program or choose another port with `--https-port`. A Caddy that fails its checksum or is too old is fixed by running the setup again. |
| The setup stops with `--names is required` or `this server has no account yet`, and exit status 2 | It was run without a terminal and is missing an answer. It changed nothing. Run it again with the flags it names. |
| The setup stops with `ERROR: nothing listens on port 39443 (HTTPS) within 30 seconds`, followed by the end of the log | The service started but did not come up. Read the lines printed under it; the usual causes are the two rows above, or a folder the `phoromatic` user cannot write to (see the next row). |
| `The Phoromatic Server could not start: ...` in the log, or `mkdir(): Permission denied`, or the service fails right after a restore | The message says which of four things is wrong, and it is never "already running" when it names a path. **There is no free space** in the folder: free some up. **The folder belongs to someone else** (`It belongs to root.`): usually a backup unpacked as root. Run `sudo benchy-server-setup`, which fixes the owner and restarts the service; `sudo benchy-server-setup --check` shows which path is wrong first. By hand, `sudo chown -R phoromatic: /var/lib/phoromatic/.benchy`, then `sudo systemctl reset-failed phoromatic-server` and start the service again. **A folder cannot be reached** (the message names the one that cannot be entered, often `/var/lib/phoromatic` itself): the same repair, run `sudo benchy-server-setup`. **It belongs to the right user** but still cannot be written: check for a file system mounted read-only or full, and for an immutable flag or access list on the folder. |
| The setup stops with `... is a symbolic link` naming the settings file (`/var/lib/phoromatic/.benchy/user-config.xml`) or the storage folder, or `--check` shows it as `DIFFERENT` | The setup will not change the owner or mode of whatever a link points to. Find out who made the link, replace it with the real file or folder, and run the setup again. |
| A script starts the server and expects it to end when its input closes, but it keeps running (or the reverse) | A server started by systemd, with no terminal on its input, is treated as a service and keeps running. A script that wants the old behavior, where the server ends when its input does, sets `PTS_FORCE_DAEMONIZED=0`. Any other value makes the server behave as a service. |
| `Note: /etc/benchy.xml exists but this server does not read it` in the log, in `server-info` or in `--check`; or: I changed `/etc/benchy.xml` and nothing happened | A server that runs as `phoromatic` does not read `/etc/benchy.xml`. Its settings file is `/var/lib/phoromatic/.benchy/user-config.xml`. The file exists because Benchy was once run as root on this machine. Leave it alone or delete it (a new one is created readable by root only, because it can hold passwords), and change settings with `sudo -u phoromatic env HOME=/var/lib/phoromatic benchy user-config-set NAME=VALUE`, then restart the service. |
| `phoromatic-server is running as root (or is restarting by itself), not as phoromatic, so it was NOT restarted` when you install `benchy-server` | A server from before 0.9.8 is running as root. See [A server you set up by hand with 0.9.7](#a-server-you-set-up-by-hand-with-097). |
| `PHP SQLite3 support must first be enabled before accessing the Phoromatic server` | `php-sqlite3` is missing. `sudo apt install php-sqlite3`, then run the setup. |
| `ERROR: The Phoromatic database ... belongs to phoromatic, but this command is running as a different user.` | You ran a server command as yourself or as root. Run it as `sudo -u phoromatic env HOME=/var/lib/phoromatic benchy ...`. The server's own error messages give the command in that form, so paste it as it is. |
| `Port NNNN chosen as random port for this instance` in the log | The service has no port, so the `benchy-server` drop-in is not in effect. Run `sudo systemctl daemon-reload`, then `sudo benchy-server-setup --check` to see what differs. |
| The storage folder is not where you expect, or the data seems to have disappeared | `PhoromaticStorage` in the settings file decides where the database lives. Check the `Storage:` line in `server-info`. Data under the old location is not moved for you. |
| `The Phoromatic Server is already running.` | A copy is already running, or died without cleaning up. `sudo systemctl stop phoromatic-server`, wait a few seconds, and start it again. |
| `[NOTICE] mkdir(): File exists in pts_file_io:28`, repeated, in the first minute after a start | Cosmetic. Benchy makes a folder that already exists. Nothing to do. |
| A browser, or `curl`, refuses the certificate | Expected until the CA is trusted: see step 5. For `curl`, use `--cacert` with the CA root file, or `-k` for a one-off test. |
| A paired machine says the certificate is for a different name | The name in its pairing string is not one of the `--names`. Run the setup again with every name, including the new one, and make a new pairing string while browsing by the right name. |
| The machine's page says `Enrolled, waiting for the agent` for a long time | The machine paired, but its agent is not running or cannot reach the server on port 39443. Check on the machine. |

For messages on the machine's side (an expired code, a fingerprint that does not match) see [When something goes wrong](../user-guide/troubleshooting.md#pairing-and-the-tray).

## Limits to know about

- The Phoromatic server runs on Linux. This page covers Ubuntu 26.04, which the installer was built and checked on; the packages also install on Debian 13 and Ubuntu 24.04, but those were only checked as far as `benchy version`.
- A server installed from a source tree with `install-sh` does not need `benchy-server` and keeps working as it is, but it does not get the standard layout. Running `sudo sh deploy/deb-package/benchy-server/benchy-server-setup --check` in the source tree compares it with the layout.
- Certificates from the built-in CA are for names and addresses you list. If the server's address changes, run the setup again with the new one and re-pair the machines that used the old one. A certificate from your own authority or from Let's Encrypt is possible too; see [Built-in HTTPS for the Phoromatic server](../https.md).
- One PHP web server serves everything, with eight workers by default. That is plenty for a handful of machines; for more, see "PHP workers" in [`https.md`](../https.md).
- Email from the server is not set up. It needs a working `sendmail` program on the machine, either named by its full path or found on the server's `PATH`, in PHP's `sendmail_path` setting; without one the server quietly skips sending mail, so its email features do nothing.
- There is no RPM of the server package yet.
- When a native test fails to install on a paired machine, for example because the machine has no compiler, the server shows only "No test produced a result."
