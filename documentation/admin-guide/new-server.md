# Set up a Phoromatic server on a new Ubuntu 26.04 machine

This page takes a freshly installed, headless Ubuntu 26.04 server to a working Phoromatic server that is reached over HTTPS, with its first account created and the first test machine paired to it. It is written for the person who installs and looks after the server, not for someone at a desk who only runs benchmarks; that reader wants the [user guide](../user-guide/index.md). You need to be comfortable in a terminal and with `sudo`, but you do not need to know Benchy. It covers **Benchy 0.10.0**, installed from the two Debian packages `benchy_0.10.0_all.deb` and `benchy-server_0.10.0_all.deb`.

Since 0.9.8 one command, `benchy-server-setup`, does the work that used to be a page of hand-made settings: it creates the database, fetches Caddy (the web server that provides HTTPS), turns HTTPS on, makes sure the welcome page offers no sign-up, creates your account, and starts the server. By the end you will have the server running as its own unprivileged user, started at boot by systemd, listening on one HTTPS port with a certificate from Benchy's built-in certificate authority (CA). The page also covers backing the server up, upgrading, removing it, moving its data to a new machine, and the messages you are most likely to meet.

In this page the server is `server.example.com` with the address `203.0.113.10`, and the administrator is `yourname`. Replace them with your own. Whenever a Benchy command has to change the server's data, it is run as `sudo -u phoromatic env HOME=/var/lib/phoromatic benchy ...`, which means "as the server's own user, with that user's home folder". Benchy refuses to touch the server's data as anyone else, and the home folder decides which settings file and data it finds.

## What you need

- A server running Ubuntu 26.04 and a user on it who can use `sudo`.
- Every name and address that your test machines will use to reach the server. The certificate is made for exactly the names and addresses you give the setup, so list all of them: the host name, the server's own IP address, and any address a machine reaches it by from outside, such as the address on the host of a virtual machine that forwards traffic to the server (NAT or a port forward). The server's own address and that outside address are different, and a machine that dials either one has to find it in the certificate. Since 0.10 one certificate carries every name on the list, so each of them works, whichever address the connection arrived on. The names must resolve from the test machines.
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
| Sign-up | There is no registration form. Accounts are made in the server console (the **Server** link) or on the command line, starting with yours. |

## 1. Get both packages onto the server

Each package comes with a file of the same name plus `.sha256` that holds its SHA-256 checksum, so you can tell that the copy you install is the copy that was published. Check both.

Download them straight onto the server, from the Benchy releases on GitHub:

    curl -fLO https://github.com/dakotlyar/benchy/releases/download/v0.10.0/benchy_0.10.0_all.deb
    curl -fLO https://github.com/dakotlyar/benchy/releases/download/v0.10.0/benchy_0.10.0_all.deb.sha256
    curl -fLO https://github.com/dakotlyar/benchy/releases/download/v0.10.0/benchy-server_0.10.0_all.deb
    curl -fLO https://github.com/dakotlyar/benchy/releases/download/v0.10.0/benchy-server_0.10.0_all.deb.sha256
    sha256sum -c benchy_0.10.0_all.deb.sha256 benchy-server_0.10.0_all.deb.sha256

Each check should print:

    benchy_0.10.0_all.deb: OK
    benchy-server_0.10.0_all.deb: OK

If either prints `FAILED`, delete all four files and download them again. Do not install a package that fails the check.

If the server cannot reach GitHub, or you would rather fetch on another computer, use the GitHub command-line tool (`gh`) there:

    gh release download v0.10.0 -R dakotlyar/benchy -p '*.deb*'

That gives you the same four files. Check them there, copy them across with `scp benchy_0.10.0_all.deb* benchy-server_0.10.0_all.deb* you@server.example.com:`, and run `sha256sum -c` again on the server. The second check is the one that matters, because it proves nothing changed on the way.

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
    sudo apt install ./benchy_0.10.0_all.deb ./benchy-server_0.10.0_all.deb

Use a plain `apt install`. The server keeps everything in an SQLite database, and the PHP extension for it, `php-sqlite3`, is a dependency of `benchy-server`, so `apt` brings it along. The `benchy` package also recommends `php-gd` (graphs as PNG and PDF) and the `lspci` and `ip` tools; none of these is needed to run the server, and you can leave them out with `--no-install-recommends`.

Installing `benchy-server` creates the `phoromatic` user and its folders and puts the service settings in place. It starts nothing and enables nothing. Its last line tells you what to do next:

    benchy-server: installed. Next: sudo benchy-server-setup --names HOST,IP --admin-user NAME --admin-email ADDRESS

Check that Benchy is there. Run this as yourself, not as root and not with `sudo`:

    cd / && benchy version

Expected, after a few lines about building its test catalogue the first time:

    Benchy v0.10.0

**Never run `benchy` as root on a server.** The first command run as root creates `/etc/benchy.xml`, `/var/lib/benchy` and `/var/cache/benchy`. A server running as `phoromatic` reads none of them, so settings put there do nothing (see [If something goes wrong](#if-something-goes-wrong)). The setup command is the one thing you run with `sudo`, and it runs every Benchy step as `phoromatic` itself. If you do run a server command as root anyway, it reads `PhoromaticStorage` from `/etc/benchy.xml`, and it refuses (exit status 78, nothing changed) when the storage folder belongs to another user or can be written by its group or by others. The message names the owner; run the command as that user (`sudo -u OWNER benchy ...`), or make the folder root-owned and `chmod go-w`. The details are under [A storage folder that cannot be used](#a-storage-folder-that-cannot-be-used).

## 4. Run the setup

The setup needs three things from you: the names and addresses machines will use, and the details of your own account. You can give them as flags, or leave them out and let the command ask on a terminal.

### With flags

    sudo benchy-server-setup --names server.example.com,203.0.113.10 \
        --admin-user yourname --admin-email you@example.com

`--names` is a comma-separated list with no spaces. With no `--admin-password-file` it asks for your password on the terminal (twice, and it does not show what you type). To give the password in a file instead, which is what you want in a script, put it on the first line of a file that only you can read:

    (umask 077; read -rsp 'Password: ' pw && printf '%s\n' "$pw" > ~/benchy-pw)

and add `--admin-password-file ~/benchy-pw` to the command. The password never appears on the command line. Delete the file when the setup has finished.

The user name must be four or more characters, made of letters, digits, dot, dash or underscore (`admin`, `administrator` and `rootadmin` are reserved). The password must be at least six characters, and the email address must be a valid one. The setup checks all three before it changes anything.

### Names, addresses and NAT

The certificate is made for exactly the names and addresses in `--names`, so the list has to hold every one that a machine might use to reach the server: its host name, its own IP address, and, if a router or a virtual machine host forwards traffic to it (NAT or a port forward), the address that machines use on the other side. Those two addresses are different: a machine that dials the forwarded address connects to the host, which passes the connection on, and it expects to find the address it dialed in the certificate. For a server in a virtual machine at `192.168.15.10`, reached from other networks as `10.10.1.185` through its host, the list is:

    sudo benchy-server-setup --names server.example.com,192.168.15.10,10.10.1.185 \
        --admin-user yourname --admin-email you@example.com

Since 0.10.0 the server makes one certificate that carries all of them, so each name works, and a machine that connects by IP address and sends no server name gets the right certificate whichever address the connection arrived on. Before 0.10.0 Caddy made one certificate per name and gave a machine that dialed an address the certificate for the address the connection arrived on, so the forwarded address failed with "certificate is valid for 192.168.15.10, not 10.10.1.185". The fix for such a server is the upgrade, a restart of the service, and a `--names` list that includes the forwarded address; the CA and its fingerprint do not change, and machines that are already paired keep working. A machine paired by an address that you later leave out of `--names` has to be paired again. After a change, `sudo -u phoromatic env HOME=/var/lib/phoromatic benchy server-https-status` shows, for each name, either "ok, the certificate covers it" or "WRONG: it gets a certificate for ...". A forwarded address often reads "not checked: this machine cannot connect to it", because the server cannot dial its own forwarded address from the inside; test it from a machine on the other side instead:

    curl --cacert root.crt https://10.10.1.185:39443/api/v2/server

(`root.crt` is the CA certificate, `/var/lib/phoromatic/storage/tls/caddy-data/pki/authorities/local/root.crt`, copied from the server.) `--names` takes host names and IP addresses, written in lower case or not (Benchy lowers them), separated by commas with no spaces. Anything else, such as a name with an underscore or a semicolon, is refused with `"..." is not a host name or an IP address.` before anything changes. The one-certificate change is for the built-in CA (`internal` mode). With your own certificate (`file` mode) the certificate has to list the names itself, and with `acme` the names are the ones the certificate authority is asked for.

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
| 6 | Sets the message the welcome page shows in place of a sign-up form, then creates your account. |
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

The **CA fingerprint** is the value to keep. It identifies the server's certificate authority, and a test machine uses it to be sure it is talking to your server and not to someone in between. Note it down and give it to whoever pairs machines, by a route other than the network path the machine will use to reach the server. It belongs to the CA, which is made once and kept in the server's storage, so restarts and upgrades do not change it. The certificate itself is renewed automatically by the service (systemd), about ten days before it ends (a server you start by hand does not renew while it runs; it checks only when it starts), and Caddy restarts for a few seconds when that happens. You can read the same value again at any time, together with what each name on your list is given when a machine connects to it:

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
| `--registration closed` | Accepted, and it is the only value. `--registration open` is refused with the message that open registration was removed in 0.9.12 (see [Accounts and registration](#accounts-and-registration)). |
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

The welcome page has no form for creating an account: it says that account creation is closed. Log in with the user name and password you gave the setup. (On a server that has no account yet, the welcome page tells you the command that makes the first one, `benchy server-account-create --user NAME --email ADDRESS`; see below.)

### Accounts and registration

Since 0.9.12 nobody can create an account from the welcome page, because there is no registration form any more. Before 0.9.10 the form was open by default, and in 0.9.10 and 0.9.11 an administrator could still open it. Now the only ways to get an account are the setup (which makes the first one), the `server-account-create` command and **Create an account** in the server console, which server administrators and root reach from the **Server** link (see [Look after the accounts](accounts.md)). Root's old **Create New Account Group** form on the **Admin** page is gone. A server that had registration open is closed when you upgrade it (see [Upgrading to 0.9.12](#upgrading-to-0912-the-settings-that-only-close)). Before 0.9.10, the default was open, so on a server that is still on an earlier release, check that registration is closed before you do anything else.

Your account is the first one; the machines you pair belong to it. Each account is a separate space, with its own machines, schedules and results. To make another account:

    sudo -u phoromatic env HOME=/var/lib/phoromatic benchy server-account-create --user colleague --email colleague@example.com

On a terminal it asks for the password twice. In a script, give the password as one line on standard input; do not feed it from an empty or closed pipe. The same rules apply as in the setup, and it either creates the account or leaves nothing behind. Add `--name "Lab A"` to give the account a name; without it the account is shown as "Account" and its ID until a server administrator renames it. It prints the user name and the new account's ID. If you are re-creating an account whose ID clients already hold, add `--account-id` with six letters or digits, and the new account gets that ID instead of a random one. If the ID is already in use, the account gets a random one.

The welcome page reads "You must log in to your Phoromatic account to use this service. Accounts are made by the server's administrator." and shows a message where the form used to be. By default it says "Account creation is closed on this server. Ask its administrator for an account." You can change the text on the root login's **Admin Config** page (**Welcome-page message**, called **Account Creation String** before 0.9.13) or from the command line:

    sudo -u phoromatic env HOME=/var/lib/phoromatic benchy server-registration
    sudo -u phoromatic env HOME=/var/lib/phoromatic benchy server-registration closed --message 'Ask the administrator.'

The first shows the message in force. The second sets it. Saving an empty **Welcome-page message** on the **Admin Config** page stores the default closed message, not an open form. `server-registration open` and `benchy-server-setup --registration open` do not work any more. The first prints:

    ERROR: Open registration was removed in 0.9.12: nobody can create an account from the welcome page any more, so there is nothing to open. Make an account on this server with: benchy server-account-create --user NAME --email ADDRESS

What to tell people who used to sign themselves up: ask you. You make the account with `server-account-create` and give them the user name, the password and the account ID the command prints. No email is sent.

### Who can change server-wide settings

Some settings are shared by every account on the server. Since 0.9.13 they have a page of their own, **Server settings**, and the account's **Settings** page no longer carries them. Only a server administrator can change them. That is the root login, or a user who has been given the **server administrator role**. Since 0.9.11 the role is something you grant on purpose. It no longer depends on how many accounts the server has, and a user's level inside their account (**Group Administrator** and the rest) does not carry it: a Power User or even a Viewer can hold it.

A server administrator reaches the page from the **Server** link in the menu, which opens the server console and has **Server settings** in its row of links, or from the **Open Server settings** link at the bottom of **Settings**. The address is `?server_settings`. Anyone else who opens the address is told the page is for server administrators, and nothing they post changes anything. The page has these sections, in this order:

| Section | What it controls |
|---|---|
| New systems, all accounts | **Every new system waits for approval** (the default) or **Each account decides**. See [Approving new systems](#approving-new-systems). |
| Contact with OpenBenchmarking.org | **Clients never contact OpenBenchmarking.org** (the default) or **Each client decides**. It covers usage reports, OpenBenchmarking system IDs and automatic comparisons and uploads. Clients pick up a change at their next check-in. |
| HTTPS | The certificate and the upload form for your own. See [Built-in HTTPS for the Phoromatic server](../https.md). The warning that the certificate is about to expire is shown to server administrators. |
| Native agent connections | Whether native agents may use plain HTTP (test rigs only), and the download mirrors offered to agents. |
| Shared download cache | A link to the **Download cache** page, where files are added. |
| Set on the server only | The commands for the things that have no web form: accounts, the welcome message, server administrators and where the audit log is. |

In 0.9.13 the old **Settings** page still accepted a save of these options from a page that was left open, and said where they had moved. 0.9.14 removed that: a form left open from an older version and saved on **Settings** no longer changes any server-wide option. Use **Server settings**.

Who has the role to begin with:

- On a fresh server, the first account's level 1 user gets it, which is you after the setup.
- When a server is upgraded to 0.9.11 and has exactly one account, that account's level 1 users get the role, so the server works as before. They keep it if a second account is added later.
- When a server is upgraded and has more than one account, nobody has the role until root grants it. Root can always do what a server administrator can.

Manage the role on the server, as the service user, like the other server commands:

    sudo -u phoromatic env HOME=/var/lib/phoromatic benchy server-admin-list
    sudo -u phoromatic env HOME=/var/lib/phoromatic benchy server-admin-grant USERNAME
    sudo -u phoromatic env HOME=/var/lib/phoromatic benchy server-admin-revoke USERNAME

`server-admin-list` shows each server administrator, the account they belong to, and when and by whom the role was granted. Lowering or removing a user's level on the **Users** page does not take the role away: the page says the user is still a server administrator and shows the `server-admin-revoke` command to use. While a database upgrade is waiting to finish (see [Upgrade](#upgrade)), these commands refuse and say so.

Root reaches the same sections on the **Admin Config** page (`?admin_config`), because root can open only pages whose names contain `admin` or `result`. Below them come the settings only root has (the **Welcome-page message**, the **Main-page message** shown to every account after log-in, whether test lists show only tests whose files are in the download cache, the reply-to address for server emails, and **Rebuild the result index**), and then a read-only block, **Managed by benchy-server-setup**. That block shows the storage folder, the download cache folder, the real HTTPS and plain-HTTP ports and the proxy, each with the command that changes it; see [Change the setup](#change-the-setup). Before 0.9.13 root could edit the ports, the proxy and the storage folder on this page. Those forms are gone: the ports form could save values the server could not use, the proxy form contacted phoronix-test-suite.com to check the address, and the storage form copied data while the server was running. A page left open from an older version that posts one of them changes nothing and names the command to use instead.

Root can upload to the download cache on the **Caches** page (`?caches`) and still cannot open the **Settings** page. Phone-home and the plain-HTTP mode can also be changed on the command line, with `benchy phoromatic-client-phone-home` (run as the service user, like the other server commands) and the `--http-mode` option of the setup. The upload form on the **Caches** page appears only once a download cache exists. A fresh server has no `pts-download-cache.json`, so nobody sees the form, root included, until a cache has been created (for example with `benchy make-download-cache`). Uploading needs the page's security token, so a form saved from somewhere else is refused.

The public results page no longer exists: there is no longer a link to it on the welcome page, no **Public Viewer** link on results, and no **Allow public visitors** setting in **Account Settings**. Results can only be seen after logging in.

### The server audit

Changes that affect the whole server are recorded in an audit that no account can see or alter: the `phoromatic_server_audit` table in the server's database, which only accepts new rows, and a copy of each entry in a log file, `phoromatic-server-audit.log`, next to the server log (on a package server, `/var/lib/phoromatic/.benchy/phoromatic-server-audit.log`). The entries have names such as `server_setting_changed`, `server_role_granted`, `server_role_revoked` and `migration`. Since 0.9.14 the server audit has a read-only page, **Server audit** in the server console (`?server_audit`); see [The audit pages](accounts.md#the-audit-pages). The log file is still written.

Each account also has its own audit, `phoromatic_admin_audit` in the database with a copy in `phoromatic-admin-audit.log` beside the server one. Since 0.9.13 it records the changes that decide who can do what in an account, so that you can answer "who did that?" afterward:

| Entry | Written when |
|---|---|
| `member_level_changed` | A Group Administrator changes a user's level on the **Users** page. It holds the old and new level. |
| `user_created` | Someone adds a user on the **Users** page. It holds the level. |
| `account_setting_set` | The account's **Approve new systems automatically** box is turned on or off. |
| `account_env_set` | The account's **Test options for every run** change. It lists the names of the options that changed, never their values. |
| `client_script_set` | The client update script is saved. It holds a fingerprint (SHA-256) of the old and new script and the new size in bytes, never the script itself. |

Since 0.9.14 it also records people being added to, removed from or moved between levels in an account by a server administrator, server administrators joining and leaving an account, and a user being turned off, turned on or given a new password (`member_added`, `member_removed`, `server_admin_joined_tenant`, `server_admin_left_tenant`, `user_disabled`, `user_enabled` and `user_password_reset`). The same rows go in the server audit.

These changes are all or nothing: the audit row is written first, in the same transaction as the change. If the row cannot be written, for example because the disk is full, the change is not made and the page says so in red. Only the server audit has a web page (see [The audit pages](accounts.md#the-audit-pages)); an account's own audit is read from the table or the log file.

### Suspended and deleted accounts

Since 0.9.14 a server administrator can suspend, resume, delete, restore and purge accounts, in the server console or with the `server-tenant-` commands. A suspended account's users cannot sign in and its machines are turned away; a deleted one is the same with the data kept on disk. [Look after the accounts](accounts.md) has the whole story, including the order the states go in and how purge works.

### Approving new systems

A machine that connects to an account for the first time waits on the **Systems** page until an administrator of that account approves it. Each account has a checkbox on its **Settings** page, **Approve new systems automatically**, that skips the wait. Since 0.9.12 that box works only if the server allows it. The server-wide policy is "every new system waits for approval" by default: while it is in force, every new system waits whatever its account's own box says, and the box is shown switched off and marked **Locked by server**, with the line "Off. This server requires approval for every new system (server setting). Systems enrolled with a code are approved by the code." Native agents enrolled with a code that an administrator made are approved either way, because the code is the approval.

A server administrator changes the policy on the **Server settings** page (**New systems, all accounts**, with the choices **Every new system waits for approval** and **Each account decides**), root on the **Admin Config** page, or on the server (`forbidden` is the first choice and `allowed` the second):

    sudo -u phoromatic env HOME=/var/lib/phoromatic benchy phoromatic-tenant-auto-approve
    sudo -u phoromatic env HOME=/var/lib/phoromatic benchy phoromatic-tenant-auto-approve allowed
    sudo -u phoromatic env HOME=/var/lib/phoromatic benchy phoromatic-tenant-auto-approve forbidden

With no argument it shows the policy. Setting it to `forbidden` also switches off every account's own box, so the boxes are off again if you later allow it. The change applies to the next system that registers; machines that are already approved are not touched. Every change goes in the server audit.

Each account also holds at most 200 systems that are waiting for approval. When an account is full, a new machine trying to register gets "Too many systems are already waiting for approval in this account. An administrator has to approve or delete some before another can register." Machines the account already knows are not affected. Approve or delete some of the waiting ones. The limit has no setting.

### Several accounts, and the --tenant option

When a command on the server acts on one account, it has to know which. With one active account it uses that one, as before. With several it will not guess: it lists the accounts and stops. Since 0.9.12 these commands take `--tenant ACCOUNT`, an account ID or the account's name:

    private-repo-token-create, private-repo-token-list, private-repo-token-revoke
    bench-token-create, bench-token-list, bench-token-revoke

For example:

    sudo -u phoromatic env HOME=/var/lib/phoromatic benchy private-repo-token-create laptop --tenant AAA111

Without `--tenant` on a server with two accounts you get:

    ERROR: This server has 2 active accounts, so say which one with --tenant ACCOUNT (an account ID or the account's name):
      AAA111  
      D79R6R  

The commands that list and revoke tokens, `private-repo-token-list` and `-revoke`, `bench-token-list` and `-revoke`, also work with `--tenant` on a suspended account, so a token that has leaked can be revoked while the account is suspended. Making a new token on a suspended account is still refused.

Three commands change a server-wide setting: `phoromatic-client-phone-home`, `phoromatic-v2-allow-http` and `phoromatic-tenant-auto-approve`. They also accept `--tenant`, but only to say whose audit log should also carry the entry. They do not refuse without it: with one active account that account is used, and with several the change is recorded in the server audit only. If you want the entry in an account's own log, name it.

### Results stay inside the account

Results are visible only to the users of the account that owns them. Before 0.9.12 an account could let other accounts see its results, and an administrator could force sharing on for all of them. That is gone: the view of results shared by other groups and the sharing checkbox no longer exist, and the upgrade switches off every sharing setting that was on (see [Upgrading to 0.9.12](#upgrading-to-0912-the-settings-that-only-close)).

The results feed (RSS) link changes. A feed link now has the form `/rss.php?user=USERID&t=ACCOUNTID&v=KEY`, where the key is 64 hexadecimal characters made from a secret that only the server holds. The links people subscribed to before the upgrade stop working, and the feed reader gets an empty answer. Each person gets a new link from the **Results Feed** entry in the **Results** menu, or the feed icon at the top of the page. A wrong key, a missing key, a key from another account, and a suspended or removed account all get the same empty answer, so a stranger learns nothing from trying. The page's own feed autodiscovery link (the one a browser or reader finds in the page header) uses the same form, and is left out if the server cannot make the key. The feed shows only the account's own latest 30 results. To invalidate every feed link at once, remove `feed_secret` from the server's settings file (`phoromatic-settings.pt2so` in the storage folder); the server makes a new secret the next time one is needed. The upgrade to 0.9.12 makes the secret, so on an upgraded server the first feed request does not have to.

### Systems, claims and triggers

Claiming an address for an account (**Add Via SSH Or IP/MAC Claim** on the **Systems** page) is open to users of level 1 and 2 only. A claim for an IP or MAC address that already belongs to another account, as a claim or as a live system, is refused: "That address is already associated with another account on this server, so it cannot be claimed here." The refusal does not say whose address it is. Addresses are compared without regard to case or spelling: a MAC typed in capitals or with dashes matches the same MAC with colons, and an IPv6 address typed in capitals or in full matches its short form. The server stores a new IPv6 claim in lower case and compressed. A claim held by a suspended account still blocks another account from claiming the same address. Account IDs made only of digits (for example `123456`) are kept as text everywhere, including in the discovery JSON that version 1 clients read, so they are never turned into numbers.

A benchmark trigger sent to the server's event address (`event.php`) works only for an enabled user of level 1, 2 or 3 whose account is active and who gives the public key of one of that account's own schedules. Every way of failing gets the same answer, so a wrong user name and a wrong key look alike.

### Limits for older clients

Machines that connect with the older protocol (a machine ID and an account ID, no token) have two limits. First, an address that names an account that does not exist 20 times within 15 minutes is shut out of that protocol for the rest of the window. While it is shut out it gets "Too many requests naming an unknown account from this address; try again in about 15 minute(s)." for every request, including requests for an account that does exist. Second, the 200-system cap on waiting systems above. Neither has a setting.

Think about the first one if many machines sit behind one address, which is what happens on a home network with address translation (NAT) or behind a reverse proxy. The server sees one address, so a few machines that have a wrong account ID in their configuration, retrying, can lock the others out for up to 15 minutes. If machines suddenly all say "Too many requests naming an unknown account", look for one with a mistyped or deleted account ID, fix it, and wait.

### A storage folder that cannot be used

Since 0.9.12, a `PhoromaticStorage` setting that names a folder the service user cannot write, cannot make, or that is a file, stops the server from starting. Before, Benchy quietly used a folder under the user data directory instead, and the server came up looking empty. Now the commands print an error and exit with status 78, and any web request gets a plain 503 answer, "The Phoromatic Server is not available: its storage cannot be used. The details are in the server log." The web page deliberately gives no path or setting name; the full message goes to the server log. The commands print the full message, which names the setting and the folder:

    ERROR: PhoromaticStorage is set to /srv/phoromatic/data/ but the folder does not exist and /srv cannot be written by phoromatic, so it cannot be made. The Phoromatic Server was not started and nothing was changed. Fix the owner or the mount of that folder (benchy-server-setup does this on a packaged server), or correct PhoromaticStorage in /var/lib/phoromatic/.benchy/user-config.xml. The server does not fall back to another folder when this setting is set.

The package's systemd unit treats status 78 as a configuration error and does not restart in a loop (see [If something goes wrong](#if-something-goes-wrong)). `benchy server-info` still runs and prints a `PROBLEM:` line under `Storage:`. Every other server command that needs the storage, including the token commands, `phoromatic-client-phone-home` and `phoromatic-v2-allow-http`, prints the same message and exits with status 78 instead of saying that no database was found. There is no fallback folder for a setting you chose. Two cases are different, because nobody chose them. An empty `PhoromaticStorage` means the folder under the user data directory. A `PhoromaticStorage` left at the value the configuration file ships with, `~/.benchy/phoromatic/`, means whichever of two folders already holds `phoromatic.db`: `~/.benchy/phoromatic/` or `phoromatic/` under the user data directory. If neither has a database yet, the rule depends on who runs the server. A server run by root or as a service (the packaged `phoromatic` user, for one) uses the folder under the user data directory, as every earlier release did (`/var/lib/benchy/phoromatic/` on a packaged install); it never newly picks `/root/.benchy/phoromatic/`. Anyone else uses the `~` folder if `~/.benchy` exists and can be written, otherwise the folder under the user data directory. Whichever folder is picked has to be usable: if it cannot be written or made, the server does not start, the commands exit with status 78, and the message tells you to set `PhoromaticStorage` (`benchy user-config-set PhoromaticStorage=<folder>`).

If both folders hold a database and the setting is still the shipped default, the server cannot tell which is the real one, so it does not start. The commands exit with status 78 and name both folders:

    ERROR: Both /home/you/.benchy/phoromatic/ and /srv/benchy/phoromatic/ hold a Phoromatic database, and PhoromaticStorage still has the value the configuration file ships with, so the server cannot tell which one is the real one. The Phoromatic Server was not started and nothing was changed. Set PhoromaticStorage to the folder you want to keep (benchy user-config-set PhoromaticStorage=<folder>, or edit the settings file) and start it again.

Set `PhoromaticStorage` to the one you want to keep and start the server again; nothing is moved or deleted for you.

The `Storage:` line of `benchy server-info` and `benchy server-init` says which rule picked the folder. It is one of: "from PhoromaticStorage in the configuration file"; "the folder under the user data directory, because PhoromaticStorage is empty"; "the shipped default ~/.benchy/phoromatic/, because PhoromaticStorage still has that value and that folder holds the database"; the same with "the folder under the user data directory ... and this folder holds the database"; "... and no database exists yet in either place"; "... no database exists yet and this user cannot write the default folder or its parent"; for a service or root, "the folder under the user data directory, because PhoromaticStorage still has the shipped default, no database exists yet and a server run by root or as a service always keeps its data there"; or, when the two folders are the same, "the shipped default ~/.benchy/phoromatic/, which is also the folder under the user data directory". With both folders occupied, `server-info` prints a `PROBLEM:` line instead and still exits 0.

#### When root runs the server commands

The rules above are the same for every user, but root gets a stricter one. When `start-phoromatic-server`, the event server, `server-init` or the token, server-administrator, policy and HTTPS commands run as root, the storage folder has to belong to root and must not be writable by its group or by others. If it is not, they refuse with status 78, say who owns the folder and change nothing. Root does not switch to that owner and reads `PhoromaticStorage` from `/etc/benchy.xml`, not from the service user's file. To go on, run the command as the folder's owner (`sudo -u phoromatic env HOME=/var/lib/phoromatic benchy ...` on a packaged server), or make the folder root-owned and remove write access for group and others with `chmod go-w`. A folder that does not exist yet is not refused, and `server-info` shows the same message as a `PROBLEM:` line and exits 0.

If you do give a storage folder to root, run `chown -R root` on it so that everything inside belongs to root too, and check that nothing inside it is a symbolic link. Do not keep a storage folder that root runs from inside a folder that other users can write. The check looks at the folder itself; it does not make root safe against links someone has planted, so keep the folder out of their reach.

One case gets a different answer. If root is pointed at a folder that `phoromatic` owns and the database belongs to `phoromatic` as well, the token, server-administrator and policy commands print "Run: sudo -u phoromatic ..." and exit with status 1, as in the next section. `start-phoromatic-server` and `server-init` refuse with status 78.

#### The settings file

The server's own settings file, `phoromatic-settings.pt2so` in the storage folder, is saved under a lock: Benchy writes a new file with owner-only (0600) permissions and renames it into place. Two commands changing a setting at the same time take turns, so neither loses its change, and a crash cannot leave half a file. A client's settings files are saved as in 0.9.11.

A settings file that exists but cannot be read is an error, not an empty file. Every page and command fails until you fix it. The likely cause is a file left as `root:root` with mode 0600 by an earlier run as root. Check with `ls -l /var/lib/phoromatic/storage/phoromatic-settings.pt2so`, then give it back with `sudo chown phoromatic /var/lib/phoromatic/storage/phoromatic-settings.pt2so`, or restore it from a backup.

#### Running a command as the wrong user

Running a command as the wrong user is a separate case. When the database file belongs to another user, the commands that work on it (`phoromatic-client-phone-home`, `phoromatic-v2-allow-http`, `phoromatic-tenant-auto-approve`, `private-repo-token-*`, `bench-token-*`, `server-admin-*` and the HTTPS commands) do not stop with status 78. They print the command to run instead, in the form `sudo -u OWNER env HOME=/home/owner benchy private-repo-token-list`, change nothing and exit with status 1. `server-info` shows the same problem and still exits 0. Only this case exits 1; a usage error such as a missing argument keeps the status it had.

### Deleting results, schedule tests and triggers

Deleting results on the **Results** page, and **Remove Test** and **Remove Trigger** on a schedule's page, are buttons that send the page's security token. An old bookmark or a plain link to a delete or remove address no longer does anything: the page says "Nothing was deleted" or "Nothing was removed" and leaves the data as it was. Select the results and press the **Delete** button, or press the **Remove** button on the schedule's page.

Since 0.9.11 the same goes for **Log out**, **Activate Schedule** and the **Repeat**, **Remove** and **End** controls on a benchmark ticket: they are buttons now, and an old link or bookmark does nothing. Saving a suite and creating a schedule need the page's token too. The session ID changes when you log in, so a session ID that was known before the log-in is no use afterward.

### Test profiles

The test profile creator is gone. In 0.9.11 and earlier, an account could create, edit and delete test profiles in the `local/` store, and every account on the server shared that store. Since 0.9.12 the **Allow Test Profile Creation** setting no longer exists on the **Admin Config** page, and `?create_test` answers "Test profiles are no longer created on the server" and points to the account's own private repository. A server that had the setting on is switched off at the upgrade. Tests an account needs of its own go in its private repository (its private repository page in the web interface).

### The test mirror and the standard profiles

The server's built-in test mirror gives machines the test profiles they ask for. Since 0.9.11 the official profiles (`pts/`, `system/` and `git/`) that have no cached archive are built from the installed package's own copy, `/usr/share/benchy/ob-cache/test-profiles`, never from `.benchy/test-profiles`. The mirror serves one only if the service runs as a user that is not root and nothing in that tree, up through its parent folders, can be written by that user. The package installs it that way, owned by root. If you changed the ownership, or run the server as root, the mirror serves no official profiles from the package, and `phoromatic.log` says "a package profile was not served" with the reason. A server running as root therefore gets no official profiles from the mirror. The public mirror never serves `private/`.

One limit: a profile whose archive is already cached on the server is served from that cache, and the cache folder belongs to the service user, so a compromised service user can still put a changed archive there.

### Names

Reserved Windows names (`con`, `prn`, `aux`, `nul`, `com1` to `com9` and `lpt1` to `lpt9`, with or without an extension) and names that end in a dot are refused in suite and test names, because Windows treats two such names as one.

### Failed log-ins

The web log-in counts failures per address, for users and for root together. Ten failures within five minutes lock that address, and during that time even the right password is refused. The lock ends five minutes after the oldest of those ten failures, so it lasts at most five minutes and less if the failures were spread out. The page says "Too Many Failed Log-Ins" and how many minutes to wait. Attempts made while locked are refused and not counted, so they do not extend it. A wrong user name, a wrong password and a wrong root password all show the same message, "The user-name or password did not match our records." so it does not reveal which was wrong.

Behind a reverse proxy, every visitor arrives from the proxy's address, so they share one count. A few bad log-ins from anyone can lock everybody out, root included, for up to five minutes. Wait it out. The server has no setting for the limits.

### User names and levels

What each level can do in its account, as the **Users** page now describes it:

| Level | Name | What it can do |
|---|---|---|
| 1 | Group Administrator | Everything in the account, including changing other users' levels. Usually the account's first user. Can add Administrators, Power Users and Viewers. |
| 2 | Administrator | Like a Group Administrator, except changing levels and adding other Administrators. Can add Power Users and Viewers. |
| 3 | Power User | Runs the account day to day: systems, schedules and the account's settings. Can add Viewers. Cannot delete or adopt systems, manage the private repository, bench tokens or native enrollment codes, and cannot set the client update script. |
| 10 | Viewer | Sees everything in the account. Changes only their own notifications and password. |

Only Group Administrators and Administrators can set the **client update script**, the shell script every client of the account runs. Be aware that a Power User can still run commands on the account's test machines: schedule scripts (the "set context" scripts on a schedule), system variables and the test options for every run all end up running on the machines. A Power User is trusted with the account's machines, not only with its results, so give the level to people you would give a shell on them.

A user's level is 1 (Group Administrator), 2 (Administrator), 3 (Power User) or 10 (Viewer), and nothing else. The **Users** page only offers to create levels the person making the user is allowed to create: a level 1 user can create levels 2, 3 and 10, a level 2 user 3 and 10, and a level 3 user 10. A user whose stored level is anything else cannot log in; fix it in the database or recreate the user.

The rule for a user name depends on where it is made. The setup and `server-account-create` want four or more characters, made of letters, digits, dot, dash or underscore. The **Users** page of the web interface wants four or more characters with no spaces. Either way, these user names are refused, because they are names of columns in the user table: `username`, `userid`, `accountid`, `email`, `password`, `adminlevel`, `createdon`, `lastip` and `lastlogin` (as well as `admin`, `administrator` and `rootadmin`, which were reserved already). A private repository cannot be named `corp` or `benchy`, as well as `pts`, `system`, `git`, `local` and `windows`.

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

### A join code instead of a pairing string

A pairing string is long. When a person is standing at the machine, or you are reading the code over the phone, make a join code instead: ten characters such as `K7Q-29X-A1FH`. On the **Systems** page put a label in the **Enroll A Native System** box and press **Make a join code**; the page shows the code once. It works once and lasts an hour. Machines can use it from 0.10.1 (engine 0.8.0, client 0.11.0); until then pair them with a pairing string. [Join codes](#join-codes) below explains how they are tied to your server, how to make one from the command line, and the limits on wrong guesses.

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
       Database: /var/lib/phoromatic/storage/phoromatic.db, schema version 67
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

To change something, run it with the flag. Changing `--names` replaces the whole list, so always give every name and address, including a NAT or port-forward address on the host:

    sudo benchy-server-setup --names server.example.com,203.0.113.10,newname.example.com

The CA stays the same, so the fingerprint does not change. A machine paired by a name you removed has to be paired again.

The output tells you only what changed. If you change the names, it says how the list changes; if you change `--http-mode` or `--https-port`, it says that instead. HTTPS changes are applied live by Caddy within a few seconds, with no restart of the service, and the output says so. The setup then waits until the new settings answer before it finishes.

### Change the setup

Where the server keeps its data, where it keeps its download cache, which ports it listens on and which proxy it uses are set on the server itself. Since 0.9.13 the web pages no longer have forms for them: root's **Admin Config** page ends with a read-only block, **Managed by benchy-server-setup**, that shows what is in force now and the command that changes each one. This section is those commands in one place. Run them on the server. The commands that start `sudo -u phoromatic env HOME=/var/lib/phoromatic` are run as the service user, so the files they write belong to it.

Check what is in force at any time:

    sudo -u phoromatic env HOME=/var/lib/phoromatic benchy server-info

The `Storage:` line names the data folder, and the `HTTP port` and `HTTPS:` lines the ports.

**Move the data to another folder.** Setup points the server at a new folder; it does not move anything for you, and the old folder stays where it is. Copy the data yourself first, with the service stopped, so that nothing changes while you copy:

    sudo systemctl stop phoromatic-server
    sudo mkdir -p /var/lib/phoromatic/data2
    sudo cp -a /var/lib/phoromatic/storage/. /var/lib/phoromatic/data2/
    sudo chown -R phoromatic:phoromatic /var/lib/phoromatic/data2
    sudo benchy-server-setup --names server.example.com,203.0.113.10 --storage-path /var/lib/phoromatic/data2/

Give the same `--names` as before (setup asks for them every time you run it with a flag) and an absolute path. The service user has to be able to write in the folder that holds the new one, or setup refuses with "Nothing was changed" and says which folder; a path under `/var/lib/phoromatic` always works. Setup starts the service again and `server-info` should now show the new folder on its `Storage:` line. Keep the old folder until the server has run for a while; deleting it is up to you.

**Move the download cache.** Make the folder, let the service user write to it, then set it and restart the service:

    sudo -u phoromatic env HOME=/var/lib/phoromatic benchy user-config-set CacheDirectory=/var/lib/phoromatic/cache
    sudo systemctl restart phoromatic-server

**Use a proxy.** Set the address and the port, or empty values to go back to none, then restart the service:

    sudo -u phoromatic env HOME=/var/lib/phoromatic benchy user-config-set ProxyAddress=proxy.example.com ProxyPort=3128
    sudo systemctl restart phoromatic-server

**Change the HTTPS port.** Setup does it, between 1024 and 65535 and not one the server already uses. Caddy applies the change within a few seconds without a restart. Open the new port in the firewall and tell the people who pair machines:

    sudo benchy-server-setup --names server.example.com,203.0.113.10 --https-port 40443

**Change the plain-HTTP port.** This is the port older clients use. It is not a setup flag. The package sets it (39080) in `/usr/lib/systemd/system/phoromatic-server.service.d/10-benchy-server.conf`, which every upgrade replaces, so change it in a file of your own that sorts after it:

    sudo mkdir -p /etc/systemd/system/phoromatic-server.service.d
    printf '[Service]\nEnvironment=PHOROMATIC_HTTP_PORT=39090\n' | sudo tee /etc/systemd/system/phoromatic-server.service.d/20-http-port.conf
    sudo systemctl daemon-reload
    sudo systemctl restart phoromatic-server

Then run `benchy server-info` again: its `HTTP port the service used at its last start` line should show the new number. On the **Admin Config** page the same value is shown as set by `PHOROMATIC_HTTP_PORT` in the systemd drop-in.

### Let machines find the server

A machine that wants to pair has to know where the server is. Since 0.10.0 Benchy helps in three ways: the server says "I'm here" on its own network segment, you can add one DNS record so that machines on other VLANs find it too, and a Windows machine can be given the server's address when Benchy is installed (that part is in the 0.10.1 installer). The machine's side of finding a server, in engine 0.8.0 and client 0.11.0, also comes in 0.10.1. Finding a server only saves typing. It does not let anybody in: pairing still takes a code that you hand over, and a machine checks the server's identity before it sends the code.

A machine looks in this order, and lists what it finds with the best source first:

| Source | What it is |
|---|---|
| The administrator's choice | On Windows, the server named in the registry for the whole computer (see below). |
| A typed address | What a person typed. |
| The installer's suggestion | The server a person picked, or `/SERVER=` gave, when Benchy was installed. |
| A DNS record | The `_benchy._tcp` service record on this page, found through the computer's search domain. |
| A plain name | A host called `benchy` in that search domain, on the default port 39443. An ordinary DNS name for the server is enough for this. |
| The local network | The server's own announcement, on the same network segment. |

Each candidate is asked one question, "who are you?", over HTTPS (see the next section but one). Everything that comes back is a hint that anything on the network could have forged, so the machine never trusts it. What it trusts is the code: see [Join codes](#join-codes).

#### What the server announces

While the built-in HTTPS is on, the server announces itself on the local network as a `_benchy._tcp` service. The announcement holds three things: that a Benchy server is here, its network name, and its HTTPS port. It never names an account, and it never mentions the plain-HTTP port. Anyone on the segment can read it, which is why it holds nothing else. If you don't want even that on the network, turn announcing off and rely on the DNS record or a typed address.

**Announcing is on by default.** A server that you upgrade to 0.10.0 starts announcing at its first start after the upgrade, unless you had already turned the older announcement off (see below). If you want a quiet network, run `server-announce off` as soon as the upgrade is done.

The network name is what machines show for the server. It defaults to the host name. You can change it to anything up to 63 bytes (an accented letter takes two) that has no control characters in it.

A server administrator changes the setting on the **Server settings** page, in the **Finding this server on the network** section, or on the server:

    sudo -u phoromatic env HOME=/var/lib/phoromatic benchy server-announce status
    sudo -u phoromatic env HOME=/var/lib/phoromatic benchy server-announce off
    sudo -u phoromatic env HOME=/var/lib/phoromatic benchy server-announce on
    sudo -u phoromatic env HOME=/var/lib/phoromatic benchy server-announce name "Lab rack 4"
    sudo -u phoromatic env HOME=/var/lib/phoromatic benchy server-announce name default

A running server follows a change within a few seconds, with no restart. Every change goes into the server audit as `discovery_announce_change`. An older server's `AdvertiseServiceZeroConf` setting still counts until you choose here: a server whose administrator had turned it off stays off.

The page and `server-announce status` also say what is happening right now:

| What it says | What it means |
|---|---|
| Announced as *name* on port 39443. | It is working. |
| Not announced: announcing is turned off. | You turned it off. |
| Not announced: the built-in HTTPS is off. Only the HTTPS port is announced, never plain HTTP. | There is nothing to announce without HTTPS. |
| Not announced: the server is not running here. It announces when it starts. | The service is stopped (or you ran the command on another machine). |
| Not announced: avahi-daemon isn't running on this host (in a container, it has to run inside the container, and the container needs host networking or macvlan). | The announcement goes through avahi. Start it, or install it as described below. |
| Not announced: avahi-publish isn't installed on this host (on Debian and Ubuntu it is in the avahi-utils package). | Install `avahi-utils`. |
| Not announced yet. The server tries again every few seconds; if this stays, look in the server log. | A short wait after a start or a change. If it lasts, the server log says why. |

The `benchy-server` package recommends `avahi-daemon` and `avahi-utils`, so a normal `apt install` brings them in. A server run from a source tree needs them installed by hand. If you installed with `--no-install-recommends`, add them:

    sudo apt install avahi-daemon avahi-utils

The server restarts the announcement by itself if avahi-daemon restarts or the helper stops, trying at most once every ten seconds, and it logs the change when it stops or starts, not every try.

The server also still announces itself the older way, as `phoromatic-server-<host>` under `_http._tcp` and `_https._tcp`, for the older PHP client's download cache. That announcement follows the same setting, live. Plain HTTP is announced only while it is served (`--http-mode serve`). Because those two types are the ones web browsers look for, Finder and file managers list that entry as a web site. If you don't want that, turn announcing off.

If the server runs in a container, mDNS doesn't leave a bridge network. Give the container host networking or a macvlan network, and run avahi inside it, or mount the host's D-Bus socket and use the host's avahi. The page's "Not announced" line is how you'll know it isn't getting through.

#### Machines on other VLANs: one DNS record

mDNS stops at the router, so a machine on another VLAN won't see the announcement. The one-time fix is a DNS record in the resolver those machines use. It is a service record that points `_benchy._tcp.<your search domain>` at the server's name and HTTPS port. The Server settings page and `server-announce status` print it with your server's own name and port filled in, so you can copy it:

    local-data: "_benchy._tcp.example.com. 3600 IN SRV 0 0 39443 server.example.com."

On pfSense and OPNsense, put that line in the resolver's custom options (**Services > DNS Resolver > General Settings > Custom options**, the Unbound box), then save and apply. If you run another DNS server, make the same record there: service `_benchy`, protocol `_tcp`, priority 0, weight 0, port 39443, target the server's name.

The line takes its domain from the first dotted host name you gave `--names`. If none of your names has a dot in it (an IP address and a bare host name), the line has `YOUR-SEARCH-DOMAIN` and `SERVER-NAME` in it for you to fill in. The domain has to be a search domain that your machines really use, because that is where they look. A machine with no search domain cannot use the record; it says so ("This computer has no DNS search domain, so only this network segment was searched.").

The record changes nothing about who can pair. If you'd rather not run a mDNS reflector between VLANs, and you shouldn't, since it passes every printer and speaker announcement across as well, this record is the way across. A machine can also be given the server's address by hand.

#### The "who are you" question

`GET /api/v2/server` answers over HTTPS with no password, so that a machine can show a found server before it is paired. It says the server's network name, its version, the protocol versions it speaks, how its certificate is made (`internal`, `file` or `acme`) and the names on it, the SHA-256 fingerprint of its own certificate authority where it has one, and whether it takes join codes. It never says anything about accounts, systems or results. More than 60 requests a minute from one address are answered with an error (429). Plain HTTP is refused (426). You can look at it yourself from any machine that can reach the server:

    curl -sk https://server.example.com:39443/api/v2/server

The same view from a Windows or Linux machine that has `benchy-engine`, which also tells you which of the ways above found the server:

    benchy-engine --json discover
    benchy-engine --json discover --address server.example.com:39443

Each line `server_found` has the `source`, the `address`, whether `join_codes` is true and a `probe` result: `ok`, `unreachable`, `not_benchy`, or `tls_names` (the server answered but its certificate has none of the names or addresses the machine can reach it by; add that name or address to `--names`). Nothing is stored and nothing is paired.

#### Join codes

A join code is ten characters such as `K7Q-29X-A1FH`, from an alphabet without look-alikes. On the **Systems** page, put a label in the **Enroll A Native System** box and press **Make a join code**. The page shows the code once, with a Copy button. It works once and lasts an hour; a pairing string lasts a day. The same thing from the server's command line:

    sudo -u phoromatic env HOME=/var/lib/phoromatic benchy enroll-code-create rig-02 --join

The label is optional with `--join`. `--tenant` picks the account when there are several, as with the other server commands, and `--system` ties a code to an existing system. Without `--join` the command makes an ordinary code, and prints the code and, when the built-in HTTPS is on, its pairing string. Join codes need the built-in HTTPS; with it off, the page offers only the pairing string and the command refuses with "Join codes need the server's built-in HTTPS, which is off."

A join code is tied to your server. Its last four characters are a check worked out from something the machine can learn about the server without trusting it: the fingerprint of the server's own certificate authority (the usual case), or, when you supplied your own certificate or use ACME, the first name in the server's HTTPS names. Put the name machines really use first in `--names`. The machine works that check out for the server it is about to talk to before it sends anything. A look-alike server on the network therefore never receives the code, and a typo is caught on the machine ("This code wasn't made by the server at ...").

Wrong guesses are limited twice. One address is locked out after ten wrong codes in fifteen minutes, as with any enrollment code. If the whole server sees a hundred wrong join codes inside ten minutes, join codes are switched off for ten minutes: the Systems page says "Join codes are switched off for about N more minute(s) because too many wrong ones were tried on this server. A pairing string still works; so does a join code after the wait.", the server audit records it, and pairing strings keep working. A person who typed the code correctly during that wait just tries again afterward. A server that uses its own certificate file or ACME, and whose first HTTPS name is a wildcard such as `*.example.com`, cannot make join codes at all, because there is no single name to tie them to (the "who are you" answer still says it takes them). Put a plain name first.

#### Windows machines: the installer and a named server

The Windows installer from 0.10.1 (0.10.0 has none) asks "Connect to a Benchy server (optional)" after it installs. It searches the network in the background, lists what it finds, and remembers the choice as a suggestion for the Server dialog. Nothing is paired and no code is asked for. For unattended installs, `Benchy-Setup-0.10.1.exe /S /SERVER=https://server.example.com:39443` remembers that address; `/SERVER=auto` searches and remembers the server only when it finds exactly one; `/SERVER=none` remembers "don't connect". An address that is invalid or starts `http://` stops the install with exit code 2 before anything changes. See [Installing Benchy](../user-guide/install.md#installing-on-windows).

To name the server for every user of a computer, write two values under `HKEY_LOCAL_MACHINE\SOFTWARE\Policies\Benchy`: `Server` (text, `https://host:port`) and `DisableDiscovery` (number, 1 stops Benchy searching the network, so only that server and an address a person types are used). `deploy/windows-installer/benchy-server-policy.example.reg` in the Benchy source is a commented example that you can import or push with Group Policy Preferences. The named server is listed first and marked as set by the administrator. It is still only a suggestion.

#### Firewall rules between a client VLAN and the server VLAN

Rules on a pfSense or OPNsense router go on the interface the traffic comes in on, so these go on each client VLAN. They are stateful; replies need no rule.

| From | To | Port | What it is for | Needed? |
|---|---|---|---|---|
| The client VLAN | The server | TCP 39443 (your HTTPS port) | Pairing, the agent, and the "who are you" question | Yes. Without it a machine on that VLAN can't pair at all. |
| The client VLAN | The DNS resolver it uses | UDP and TCP 53 | The record above | Usually already allowed |
| The client VLAN | The server | TCP 39080 (plain HTTP) | Older PHP clients only | Only if you have them |

Nothing is needed from the server VLAN to the client VLAN: machines poll the server, and it never connects back to them. If you did choose a mDNS reflector, it also needs UDP 5353 to 224.0.0.251 (and `ff02::fb`) on each interface it joins.

On the server's own firewall, allow TCP 39443 from each client VLAN. For the announcement itself, allow UDP 5353 from the server's own segment, which avahi uses:

    sudo ufw allow from 192.0.2.0/24 to any port 39443 proto tcp
    sudo ufw allow in on eth0 to any port 5353 proto udp

Use your own subnet and interface. Nothing needs to be opened on the client side.

### Upgrade

Installing a newer pair of packages replaces the program files and the unit; your settings and data are untouched. Upgrading restarts a server that is running, so the web pages and the launcher stay on the same version; a stopped server stays stopped, and reinstalling the same version touches nothing.

1. Back up (below).
2. Get the new packages onto the server and check them, as in step 1.
3. Install both together:

       sudo apt install ./benchy_NEW_all.deb ./benchy-server_NEW_all.deb

4. Run `cd / && benchy version` and the checks in step 7. Read the release notes first: they say whether the database changes, because after a release that changes the database, going back means restoring a backup. Release 0.9.8 has no database change. Releases 0.9.11 and 0.9.12 change the database, as the next two sections explain. Release 0.9.13 has none (see [Upgrading to 0.9.13](#upgrading-to-0913-the-settings-pages)). Release 0.9.14 changes the database once more (see [Upgrading to 0.9.14](#upgrading-to-0914-accounts-memberships-and-the-server-console)), and so does 0.9.15, by one step (see [Upgrading to 0.9.15](#upgrading-to-0915-the-signed-test-catalog-and-the-no-phone-home-fixes)). Release 0.10.0 takes three steps and changes how passwords are stored, which makes going back harder than before: read [Upgrading to 0.10.0](#upgrading-to-0100-what-changes) and [Going back from 0.10.0](#going-back-from-0100) before you start. Release 0.9.10 had none, but it changes two things you will notice: registration is closed on every server (see [Accounts and registration](#accounts-and-registration)), and the server-wide settings listed under [Who can change server-wide settings](#who-can-change-server-wide-settings) move to the root login or the only account's level 1 user. If your day-to-day administrator is a level 2 user, or the server has more than one account, log in as root for those. Going back to 0.9.9 has one consequence to know about: on a server that never had registration set, 0.9.9 treats it as open again, so anyone who can reach the server can create an account. Run `server-registration closed` yourself before you go back, or right after, so that it stays closed.

Before 0.9.10, a logged-in user of level 1, 2 or 3, in any account, could change or add files in the server's shared copies of the standard test profiles, even with the test profile creator turned off. For a profile version with no cached archive, the server's built-in test mirror could then hand a changed copy to clients. After upgrading, check the `test-profiles` folder under the server's Benchy data, in `pts/`, `system/`, `windows/` and `git/`, for profiles that do not match OpenBenchmarking.org: changed `install.sh` files, versions you do not recognize, folders you did not expect. On a package server that is `/var/lib/phoromatic/.benchy/test-profiles/`. On a server run from a source tree or by hand, it is the `test-profiles` folder inside the `.benchy` folder of the user that runs the server; `benchy server-info` prints the settings file's location on its `Configuration file` line, and the `test-profiles` folder is in the same `.benchy` folder. Remove anything that should not be there. If you are unsure about a profile, compare it with the one on OpenBenchmarking.org.

#### Upgrading to 0.9.11: the database changes

0.9.11 moves the database to schema version 59. The change runs once, the first time the new server starts and uses the database, and a lock keeps two processes from running it at the same time. It adds the server administrator roles, the server audit and the room for suspended and deleted accounts. An account with an empty group name is given one: the oldest such account becomes "Default" and any other becomes "Account " followed by its ID. Accounts made later are not renamed.

Before it changes anything it makes a copy of the database in `storage/backups/`, named `phoromatic-pre-59-<timestamp>.db`. The file is readable only by the service user, in a folder with the same limit, and the copy is checked before it is used. At most the newest earlier copy is kept beside a fresh one. Because it is inside `storage/`, the usual backup of that folder (see [Back up](#back-up)) includes it. You do not have to do anything with it unless the upgrade goes wrong.

If the copy cannot be made (a full or read-only disk, say), the upgrade waits and nothing is changed. The server keeps serving at the old schema. Registration and the server-administrator commands say that a database upgrade is pending. The server tries again every five minutes, and the file `<storage>/migrate.retry` is the marker for that wait. The reason is in `phoromatic.log`. Fix it, and the next try does the upgrade; you do not need to restart anything.

#### Upgrading to 0.9.12: the settings that only close

0.9.12 moves the database to schema version 60. It runs the same way as the step before it: once, at the first start, behind a lock, after a copy of the database in `storage/backups/`, named after the first step it takes. A server already at schema 59 (any 0.9.11 server) gets `phoromatic-pre-60-<timestamp>.db`. A server coming straight from 0.9.10, at schema 58, takes both steps in one go and makes one copy for the whole upgrade, `phoromatic-pre-59-<timestamp>.db`, which holds the database as it was before either step. A new server made by 0.9.12 has nothing to upgrade and makes no copy. If the copy cannot be made, or a step stops on an internal error, the upgrade waits and retries every five minutes, exactly as described above.

What the step does is close things. It never opens anything. It switches off:

- results shared with other accounts, forced result sharing and public results, in every account;
- **Auto-approve new systems** in every account;
- adding new users to an existing account on sign-up, and the test profile creator, in the server's settings;
- open registration: a server whose registration text was empty or had never been set now shows the closed message;
- the server-wide auto-approve policy, which always ends as "forbidden";
- and it makes the secret that feed links are keyed with.

The policy ends "forbidden" on every upgrade, even if you had set it to "allowed" before; allow it again afterwards if you want it. The accounts' own boxes are switched off either way, and stay off when you allow the policy again, so turn them on in the accounts that want them.

Anything that is open in the server's settings file when the step runs is closed, even after a failed attempt and a rollback, and a value that has been closed stays closed. The before-values in the audit entry come from the first attempt's record; the record of a failed attempt is kept with no age limit, and when the step is tried again, anything still open is closed. Everything it changed is written down before it is changed (if the server stops in the middle, the values from before are kept in `<storage>/migrate-60.before` until the step has finished, so the audit entry still shows the real old values; the file is removed once the database is at 60), in one entry of the server audit (the `migration` entry for schema 60, in `phoromatic-server-audit.log` and the `phoromatic_server_audit` table). It names the backup file, lists for each of the three account settings the accounts that had it on, and shows the server settings as they were. If someone asks why their sharing stopped, that entry is the answer.

Some things change for people, not only for the server. Feed links stop working and need to be made again. Test profiles can no longer be created on the web. Systems wait for approval even in accounts that used to approve automatically. Tell your users before you upgrade.

#### Upgrading to 0.9.13: the settings pages

0.9.13 does not change the database: it stays at schema version 60, there is no new upgrade step, and the first start makes no backup copy. You can check with `PRAGMA user_version` before and after; it prints 60 both times. What changes is what people see and what is recorded:

- The account **Settings** page covers one account only, in groups, with a plain line under each option. The server-wide options moved to **Server settings** (see [Who can change server-wide settings](#who-can-change-server-wide-settings)), and root's **Admin Config** page lost its forms for ports, proxy and storage (see [Change the setup](#change-the-setup)). Tell your server administrators where the options went.
- The **Users** page describes each level as it works, and the account audit gains rows for level changes, new users, the auto-approve box, test options and the client update script.
- A Power User can run commands on the account's test machines, as the table under [User names and levels](#user-names-and-levels) says. Nothing changed in that; the page now says it.
- Fixes: a reflected cross-site scripting hole on **Settings**, the schedule page and the benchmark page; values with `&` in them no longer being cut short when saved (clients still receive an `&` written as `&amp;`, so avoid `&` in test option values for now); multiple-choice test options being cleared on save; editing a schedule deleting its uploaded scripts (there is now a checkbox, **Remove the script now set**, to remove one on purpose); a test option containing `;` (now refused with a message); and clients now apply only the test options the server's pages offer, logging and skipping any other name.

A save of the moved server options from a page left open still went through in 0.9.13 and said where the options are now. 0.9.14 removed that.

#### Upgrading to 0.9.14: accounts, memberships and the server console

0.9.14 moves the database to schema version 62, in two steps. It runs the way the earlier steps did: once, at the first start, behind a lock, after a checked copy of the database in `storage/backups/`. A server coming from 0.9.13 or 0.9.12 is at schema 60 and takes both steps in one go with one copy, named after the first step, `phoromatic-pre-61-<timestamp>.db`. A server already at schema 61 (a 0.9.14 pre-release) gets `phoromatic-pre-62-<timestamp>.db`. If the copy cannot be made, the upgrade waits and retries every five minutes, exactly as described under 0.9.11.

Step 61 adds memberships. Every user becomes a member of the account they had, at the level they had, and two columns on the user record (whether the server has turned them off, and the account they used last). Step 62 adds two more columns for the password hash described below; it changes no password. Nothing changes for anyone's sign-in. A server administrator role that belongs to nobody who still has an account is removed.

Other things happen at the first start of 0.9.14 that are not database steps:

- The shared copy of the first account's private test repository is removed, once. Before 0.9.14 that copy sat in the server's shared cache where every account could read it. Each account now has its own private repository folder, `accounts/ID/private-repo/view/` in the storage folder, built from its own uploaded tests the first time anyone in that account needs it. Nothing the account uploaded is lost: the uploads are kept as they were, and the view is made from them.
- A marker is written to the server audit (`notice_marker`). It decides who sees the one-time notice described in [What changed in this upgrade](accounts.md#what-changed-in-this-upgrade-the-one-time-notice).
- The server starts the hourly sweep that finishes interrupted purges.

What people see: a **Server** link for server administrators, with the console described in [Look after the accounts](accounts.md); an account switcher for anyone who belongs to more than one account; a **Last used** line for each member on the **Users** page; and the one-time notice for Group Administrators and server administrators. Root's **Admin** page lost the **Create New Account Group**, **Override User Password** and **Disable User** forms, which are on the console now. Mail the server sends for an account (new systems, warnings, hung systems, result uploads) goes to the members of the account who asked for it, wherever their own account is.

A security fix is part of this release. A request with a padded file path on two of the pages that add a test, and the same trick in an uploaded result file, could read another account's private test definitions. Both are closed. Nothing needs doing on the server after the upgrade.

Results that include a test the signed catalog refused no longer cause the whole result upload to be rejected; the tests that ran are kept and the refused one carries an error. See [the JSON events page](../json-events.md) for the new error codes, `catalog_refused` and `catalog_state_corrupt`.

Passwords: at a user's next sign-in the server stores their password again in PHP's modern hash (bcrypt), made from the password they just typed. Root gets the same, kept in the settings file. The old hash is kept next to it and is not changed, so going back to 0.9.13 still works. Everything that sets a password writes both: a user's own password page, a new user, the console's **Set password**, and `phoromatic.set-root-admin-password`. Nobody is asked to change a password. 0.10.0 drops the old hash; see [Upgrading to 0.10.0](#upgrading-to-0100-passwords).

#### Upgrading to 0.9.15: the signed test catalog and the no-phone-home fixes

0.9.15 moves the database to schema version 63, one step, in the same way as the earlier ones: once, at the first start, behind a lock, after a checked copy of the database in `storage/backups/`, named `phoromatic-pre-63-<timestamp>.db`. A server coming from 0.9.13 or earlier takes steps 61 and 62 as well in the same go, and its one copy is named after the first of them. If the copy cannot be made, the upgrade waits and retries every five minutes, as described under 0.9.11. A server made new by 0.9.15 has nothing to upgrade and makes no copy.

Step 63 adds the tables for the signed test catalog (see [The signed test catalog](catalog.md)). The first import adds a `catalog/` folder to the storage folder for the stored bundles. Step 63 changes nothing that already exists. On a server installed from the packages, the first start after the step imports the free catalog bundle that the `benchy-server` package carries, once, and records it in the server audit as `catalog_imported`. A server run from a source tree has no such bundle and imports it by hand; the upgrade notes for that are in the release's runbook, and the command is `benchy server-catalog-import`. After the upgrade, `benchy server-catalog-status` should show the free catalog as `served` and, on a package server, `The bundle that came with this version: imported`.

What people see: a **Test catalog** page in the server console, pins and labels on native tests in schedules, and schedules with a test that needs administrator rights held with a reason instead of failing. All of it is in [The signed test catalog](catalog.md). Machines whose engine is older than 0.6.0 are untouched: they ignore the catalog.

`server-catalog-import` with no file now prints its usage and exits with an error (status 2), so a script notices.

Two smaller changes for servers run from a source tree. Creating an account no longer needs PHP's `mbstring` extension; before, a server without it answered the account page with an error (a 500). `install-sh` now also warns, while it installs, when a PHP extension Benchy needs is not loaded (`dom`, `zip`, `curl`, `mbstring`, `sqlite3` or `ctype`), and names the package to install, for example `apt install php-mbstring`. Install it and restart the Phoromatic server. Package installs bring these in by themselves.

The rest of 0.9.15 closes two small leaks, and one of them concerns every server that was set up before 29 September 2026.

A Phoromatic server can tell openbenchmarking.org its local address and port, so that a machine with the same public address can find it. The setting for that is `AdvertiseServiceOpenBenchmarkRelay`. The shipped default has been `FALSE` since 29 September 2026, but a settings file written before that, or one that never had the line, was still read as `TRUE`, and the server published its address at start and once a day after. From 0.9.15 a missing line means `FALSE`. The server also never publishes while `NoPhoneHome` is on, even if the line says `TRUE`. `NoPhoneHome` is on by default, so in practice the only servers that still publish are ones where someone turned both on on purpose. If you want the old behavior back, set both in the server's settings file, which on a package server is `/var/lib/phoromatic/.benchy/user-config.xml` (the service does not read `/etc/benchy.xml`):

    sudo -u phoromatic env HOME=/var/lib/phoromatic benchy user-config-set AdvertiseServiceOpenBenchmarkRelay=TRUE NoPhoneHome=FALSE
    sudo systemctl restart phoromatic-server

The server needs the restart to pick either change up. Turning `NoPhoneHome` off also lets Benchy send the anonymous reports it otherwise holds back; [Things worth knowing](../user-guide/good-to-know.md) lists them.

An address already published earlier is out of Benchy's hands; there is no way for the server to withdraw it.

The other change is for the older PHP client, the one that finds servers with `avahi-browse`. When openbenchmarking.org could not be reached, it used to fetch the list of tests, test descriptions and suites from any server it had found on the network, including one nobody had set up. It now takes those only from a server it was told about: one named on the `PhoromaticServers` line in its own settings, or the one it enrolled with. A client that found its server by itself counts that server as its own from its second run on, after it has saved which server it connected to. A server that has only announced itself on the network is still used for the download cache, but a file from it is accepted only when the test profile gives a checksum and the file matches it; a file without a checksum, or a check turned off with `NO_FILE_HASH_CHECKS`, is accepted only from a server the client was told about. If one address is announced on more than one port, the client keeps the one it was told about. The client's own question to openbenchmarking.org about servers that share its public address is no longer sent while `NoPhoneHome` is on.

The announcement the server makes on the local network under the names `_http._tcp` and `_https._tcp`, which is how the older client finds it, is switched by one setting, `AdvertiseServiceZeroConf` (on by default). Set it to `FALSE` if you do not want the server to announce itself.

#### Upgrading to 0.10.0: what changes

0.10.0 moves the database from schema 63 to 67, four steps, in the same way as the earlier ones: once, at the first start, behind a lock, after one checked copy of the database in `storage/backups/`. The copy is named after the first step that runs, so a server coming from 0.9.15 gets `phoromatic-pre-64-<timestamp>.db`; a server coming from an older release takes its earlier steps in the same go and the copy is named after the first of them. If the copy cannot be made, the upgrade waits and retries every five minutes, as described under 0.9.11. A server made new by 0.10.0 has nothing to upgrade and makes no copy. On a source-tree server the first web request after the restart does the work, and the first request can take a little longer than usual.

| Step | What it does |
|---|---|
| 64 | Adds a kind to enrollment codes, so that a code can be a join code. Nothing existing changes. |
| 65 | Adds the tables for suites of catalog tests. Nothing existing changes. |
| 66 | Adds what results that people run themselves need: a table that keeps each uploaded run once, who ran it and what the machine reported about its tests, and the account setting that turns such results on or off (on by default). Nothing existing changes. |
| 67 | Drops the old password hash (next section). This one cannot be undone by older releases. |

What you will see after the upgrade:

- **One certificate for every name.** With the built-in CA (HTTPS mode `internal`, the usual case) the server now makes a single certificate that lists every name and address in `--names`, instead of letting Caddy make one per name. A machine that reaches the server through NAT or a port forward, by an address that is not the server's own, used to fail with "certificate is valid for A, not B"; it works now. The CA and its fingerprint are the ones the server had, so paired machines need nothing. The first start makes the certificate; Caddy restarts for a few seconds when it is made and again when it is renewed (it lasts 30 days and is renewed with 10 left, by a check the service runs every few hours). `server-https-status` says what a client that sends no server name gets and what each name on the list gets. Give the setup every name and address a machine might use (see [Run the setup again](#run-the-setup-again)). If the server cannot make the one certificate, it falls back to one per name and says so in `server-https-status`.
- **The server announces itself on the local network**, by default. See [Let machines find the server](#let-machines-find-the-server); run `server-announce off` if you do not want that.
- **Join codes** on the Systems page and `enroll-code-create --join`, and the "who are you" answer at `/api/v2/server`.
- **Suites of catalog tests** on the **Build Suite** page and on schedules (see [The signed test catalog](catalog.md#suites-of-catalog-tests)).
- **Administrator tests can run.** A schedule that was held with "Update the engine on this system to 0.7.0 or later" is released on every machine whose engine is 0.8.0 or later, subject to the machine being set up or someone at it approving. The 0.10.0 server is ready for this, but no released engine is 0.8.0 yet: it comes with the Windows installer in 0.10.1, so until then those schedules stay held.
- The **Server settings** page shows a red line about old database copies, see below.

0.9.15 already stopped the server from publishing its address to openbenchmarking.org; nothing changes in 0.10.0. The PHP client's upload (`benchy phoromatic.upload-result`) is stricter: it only counts a result as uploaded when the server stored it (the old "Result Uploaded" for a machine that was still waiting for approval was wrong), it sends system logs only when asked, and it talks only to the server it was told about.

#### Upgrading to 0.10.0: passwords

Until now the server kept two hashes of every password: the modern one, and the old fast one beside it, so that going back a version still worked. 0.10.0 removes the old one. At its first start, as the last step of the database upgrade (after the checked copy of the database, like every step), every account loses its old hash and keeps only the modern one. Root's password, which lives in the settings file, is converted the same way.

Nobody has to do anything. People who have signed in since 0.9.14 already have a modern hash, and the server keeps it. People who have not (an account nobody has used for a while, or root) have only the old hash. For them the server wraps it: it makes a modern hash from the old one and throws the old one away. The next time the person signs in with their usual password the server checks it through the wrap and replaces it with a plain modern hash. Nobody is locked out and nobody gets a new password.

Check it from the database, read-only (run as the service user; on a package server `sudo -u phoromatic php -r ...` works the same):

    sudo -u phoromatic php -r '$d=new SQLite3("/var/lib/phoromatic/storage/phoromatic.db",SQLITE3_OPEN_READONLY); echo $d->querySingle("PRAGMA user_version"),"\n",$d->querySingle("SELECT COUNT(*) FROM phoromatic_users WHERE Password != \"!\""),"\n";'

It prints `67` and then `0`. `Password` holds `!` and nothing else, and `PasswordLegacyFp` is empty. The column stays only because older releases name it when someone signs in. Then sign in as yourself and as `rootadmin` in a browser.

**The database copies still hold the old hashes.** The copies the server made before earlier upgrades, `phoromatic-pre-59-*.db` up to `phoromatic-pre-64-*.db` from this upgrade in `storage/backups/`, were taken before the old hash was dropped, so they still contain it. The server does not delete them, because they are your way back if an upgrade goes wrong. While any are there, the **Server settings** page shows a red line, for example:

> 3 older database copies in `storage/backups/` still hold the old password hashes. Delete them once you are sure you will not go back to 0.9.15 or older.

(With one copy it says "1 older database copy ... still holds ... Delete it".) The count is every copy made at or before schema step 67. Look at them with `sudo ls -la /var/lib/phoromatic/storage/backups/`, and when you have run on 0.10.0 long enough to be sure, delete the ones you no longer need, for example `sudo rm /var/lib/phoromatic/storage/backups/phoromatic-pre-59-*.db`, one by one or all at once; the line goes away when none are left. Your own backups of the server (copies of the storage folder, a Proxmox backup of the machine) hold the hashes too, and deleting those is up to you.

If a rolled-back server writes old hashes again (see the next section), the server clears them at the first start after you upgrade, with no step to run and no backup.

#### Going back from 0.10.0

Going back from 0.10.0 to 0.9.15 or older is code only, as the other steps are, with one exception: **no password works afterwards.** 0.9.15 and older can only check the old hash, and 0.10.0 removed it. After the rollback:

- Every sign-in fails with the usual "The user-name or password did not match our records", for every person and for `rootadmin`. A few tries from one address bring up "Too Many Failed Log-Ins" for some minutes; that is the usual lock-out, not a second problem. Wait it out.
- Machines are not affected. Agents and clients sign in with tokens, so schedules keep running and results keep uploading.
- Nothing is lost and nothing crashes.

To get back in, on the server, as the user the service runs as:

1. Run `sudo -u phoromatic env HOME=/var/lib/phoromatic benchy phoromatic.set-root-admin-password` and set root's password. 0.9.15 writes both hashes.
2. Sign in as `rootadmin`, open the server console's **Users** page and use **Set password** for each person. Tell them their new password.
3. When you upgrade to 0.10.0 again, the passwords you set are the ones that work for those people, and the server removes the old hashes the older release wrote at its first start. People you did not set a password for cannot sign in while you are back, but they have the password they had before once you upgrade again.

When the benchy-server package is replaced by an older version, the 0.10.0 package prints a warning with these steps before it goes. That only works for the package; on a server run from a source tree, this section is the warning.

Restoring the copy this upgrade made (named after the first step it took: `phoromatic-pre-64-*.db` when you came from 0.9.15) instead has every old hash in it, but loses everything written since the upgrade (results, schedules, the audit). It is for a rollback within hours that is going back for another reason. Root's password is in the server's settings file (`phoromatic-settings.pt2so`, in the storage folder), which the copy does not include, so step 1 is needed either way, unless you also put back a copy of that file taken before the upgrade.

The rest of 0.10.0 goes back without trouble:

- **Suites of catalog tests.** A 0.9.15 server does not know native suites. The suite rows stay in the database untouched (step 65 only added tables), but 0.9.15 reads a suite on a schedule as an ordinary test and sends it to the machine as `native:N`, which the machine does not have, so the job fails with `not_found`. Nothing crashes and nothing is lost: the schedule page loads, and the suites and their schedule rows come back when you upgrade again. If scheduled runs matter while you are back, take the suites off the schedules first (**Remove suite** on the schedule page).
- **The certificate.** Caddy goes back to a certificate for each name. The CA and fingerprint are the same. A NAT address fails again with "certificate is valid for A, not B".
- **Announcing, join codes and `/api/v2/server`.** 0.9.15 knows none of them. It does not announce `_benchy._tcp`, and the machines' "Find servers" finds nothing from it. A join code made on 0.10.0 does not work on it; use a pairing string. (Machines can use join codes only from 0.10.1, when engine 0.8.0 and client 0.11.0 arrive, so until then pair with pairing strings anyway.)
- **Enrollment codes** made on 0.10.0 stay in the database and are ignored by older releases except that a join code is not a valid ordinary code.

#### Going back from 0.9.15

Going back from 0.9.15 to 0.9.14 or 0.9.13 is code only. They run on the schema 63 database and ignore what they do not know: the catalog tables and the `catalog/` folder stay as they are, and when you upgrade again they are found as you left them, and importing the bundle again changes nothing. After the rollback:

- Machines are not offered the catalog. What they already installed keeps running, but a test they have not installed yet cannot be fetched from the server, and that job fails.
- Version pins on schedules are ignored. The older server sends no version, so each machine runs its own newest version, and the result records the version that ran. A pinned schedule can therefore measure something different until you upgrade again.
- A schedule with a test that needs administrator rights is no longer held. The older server sends the job and the agent refuses it with a message.
- The **Test catalog** page and the labels are gone with the code. Audit rows 0.9.15 wrote stay and are ignored.

The `pre-63` copy is for the case where something breaks. You do not need it for a rollback.

#### Going back from 0.9.14

Going back from 0.9.14 to 0.9.13 is code only. 0.9.13 runs on the schema 62 database and ignores what it does not know. After the rollback:

- Accounts made, suspended or deleted in the console are served by 0.9.13 as plain accounts: an active one works, a suspended one is refused at sign-in, and a deleted one is an unknown account. Root's **Admin** page lists the accounts again.
- A person who belongs to several accounts sees only their own account. Their other memberships are kept in the database. Levels set while 0.9.14 was running are what 0.9.13 uses, and a user turned off on the server stays refused. The administrators of their other accounts do not see them on their **Users** page.
- The private repository goes back to the shared copy. 0.9.13 builds it again, for the oldest account only, so other accounts see no private tests until you upgrade again. Nothing is deleted. When you upgrade again each account's own view is rebuilt, but the shared copy that 0.9.13 made is left on disk: its test profiles are ignored, though the names of the oldest account's private suites can show in other accounts' suite lists. Delete the `private` folders under the server's test-profiles, test-suites and scratch folders by hand after the second upgrade.
- The server console, the account switcher and the one-time notice are gone with the code. The notice does not come back for people who dismissed it.
- Audit rows that 0.9.14 wrote stay in the database and are ignored.
- Passwords: because the old hash was kept, everyone can still sign in on 0.9.13, root included. A password changed on 0.9.13 after the rollback is the one that works when you upgrade again; the server notices the old hash moved and ignores the modern one made earlier.

The `pre-61` copy (or `pre-62`, on a server that was already at 61) is for the case where something breaks. You do not need it for a rollback.

#### Going back from 0.9.13 or 0.9.12

Going back from 0.9.13 to 0.9.12 is code only: both run on the same database. Rolling back to 0.9.11 or 0.9.10 works on a database at schema 60, as the earlier steps did. The settings stay closed: the older releases honor the same values, so nothing sharing or auto-approving is turned back on by the rollback. Feed links made by 0.9.12 do not work in the older releases, and the older releases' feed links do not work in 0.9.12, so people need new ones after either move. Going back to 0.9.9 still reopens registration on a server that never set it; run `server-registration closed` first.

#### Going back from 0.9.11 to 0.9.10

Rolling back to 0.9.10 works on a database that has been upgraded to 59. Install the older packages and start the server; 0.9.10 does not need the new data and ignores it. What changes in the meantime: a suspended account works again on the web and for version 1 clients (paired native agents of a suspended account stay refused), and server roles are ignored, so only root and the old single-account rule apply. Accounts created while you are rolled back have no server role once you upgrade again; grant it then if they need it. The `pre-59` copy is for the case where something breaks; you do not need to restore it for a rollback. Going back to 0.9.10 keeps registration as it was set; going back to 0.9.9 reopens it on a server that never set it (see above).

#### A server run from a source tree

A server installed from a source tree upgrades with `deploy.sh`: it pulls the new code, runs `install-sh` to copy it to `/usr/share/benchy`, and restarts the service. The database upgrade above happens at that first start, with the same backup. If systemd warns that the unit file changed on disk after `install-sh` has rewritten it, run `sudo systemctl daemon-reload` and restart the service again.

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

The setup finds the accounts in the database and the CA in the `tls` folder. It says how many accounts it found, asks for no new account, and starts the server with the same CA fingerprint. Machines that were paired to the old server reconnect once the new one answers at the same name. If the new machine has a different name or address, put it in `--names`, and point the name at the new machine.

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
| The storage folder is not where you expect, or the data seems to have disappeared | `PhoromaticStorage` in the settings file decides where the database lives. Check the `Storage:` line in `server-info`. Data under the old location is not moved for you. Since 0.9.12 a `PhoromaticStorage` that cannot be used no longer sends the server to another folder; see the next row. |
| `ERROR: PhoromaticStorage is set to ... but ...`, exit status 78, or a plain 503 page saying the details are in the server log | The folder named by `PhoromaticStorage` cannot be written by the service user, cannot be made, or is a file. The message says which, and nothing was changed. On the web the page gives no details; the full message is in the server log. Fix the owner or the mount (`sudo benchy-server-setup` repairs it on a package server), or correct the setting. systemd does not restart the service in a loop on status 78, so start it yourself once it is fixed. |
| Every page and command fails, and the message says "The settings file ... is there but cannot be read" | The settings file exists but the service user cannot read it, usually because an earlier run as root left it `root:root` with mode 0600. Check with `ls -l` on the storage folder, then `sudo chown phoromatic <storage>/phoromatic-settings.pt2so`, or restore it from a backup. |
| `ERROR: ...` naming the folder's owner, exit status 78, from a command you ran as root | Root refuses a storage folder it does not own or that its group or others can write. Run the command as the owner (`sudo -u phoromatic env HOME=/var/lib/phoromatic benchy ...`), or make the folder root-owned and `chmod go-w` it. |
| `ERROR: Both ... and ... hold a Phoromatic database`, exit status 78 | `PhoromaticStorage` still has the shipped default and both `~/.benchy/phoromatic/` and the folder under the user data directory hold a database. Decide which one is real and set `PhoromaticStorage=<folder>` to it (`benchy user-config-set PhoromaticStorage=<folder>`), then start the server. Nothing is moved for you. |
| The **Server settings** page says it is for server administrators | Your user does not have the server administrator role (your level in the account does not matter). Ask an existing server administrator or root to run `server-admin-grant YOUR_NAME`. |
| After saving **Users**, **Settings** or a schedule, a red message says a change "was not made" and that it "could not be recorded in the audit log" | The change and its audit row go together, so nothing was changed. The reason is in the message, most often a full disk or a locked database; fix it and try again. |
| **Test options for every run were not changed: a value cannot contain a semicolon** | A value had a `;` in it. Remove it; the options are stored as a list separated by semicolons. |
| An option on **Settings** is greyed out and marked **Locked by server** | A server administrator's choice on **Server settings** overrides it. The line under the option says which. Your own saved choice is kept for if the server rule changes. |
| `--storage-path: the server runs as phoromatic, who cannot write to ...` | The folder that would hold the new storage folder is not writable by the service user. Choose a path under `/var/lib/phoromatic`, or make the parent writable by `phoromatic`. Nothing was changed. |
| `--names is required` when you run `benchy-server-setup` with a flag | Give the same `--names` list as before, together with the flag you want. |
| `Open registration was removed in 0.9.12` | You ran `server-registration open` or `benchy-server-setup --registration open`. Neither exists any more. Make accounts with `server-account-create`. |
| `This server has N active accounts, so say which one with --tenant ACCOUNT` | A token command was run on a server with several accounts. Add `--tenant` with the account ID or name from the list it printed. |
| A machine or `curl` gets `Too many requests naming an unknown account from this address` | Some machine at that address (or behind the same NAT or proxy) has been asking for an account ID that does not exist. See [Limits for older clients](#limits-for-older-clients). |
| A new machine gets `Too many systems are already waiting for approval in this account` | The account has 200 waiting systems. Approve or delete some on the **Systems** page. |
| A results feed shows nothing | The link is from before 0.9.12, or its key is wrong. Make a new link from the **Results** menu. |
| A message on the console such as "... is the last Group Administrator of ...", "only an account that is suspended can be deleted" or "Type the account's name and its account ID exactly" | See the table at the end of [Look after the accounts](accounts.md#if-something-goes-wrong). |
| `The Phoromatic Server is already running.` | A copy is already running, or died without cleaning up. `sudo systemctl stop phoromatic-server`, wait a few seconds, and start it again. |
| `[NOTICE] mkdir(): File exists in pts_file_io:28`, repeated, in the first minute after a start | Cosmetic. Benchy makes a folder that already exists. Nothing to do. |
| A browser, or `curl`, refuses the certificate | Expected until the CA is trusted: see step 5. For `curl`, use `--cacert` with the CA root file, or `-k` for a one-off test. |
| Every log-in fails with `Too Many Failed Log-Ins`, even with the right password | The address has had ten failures within five minutes and is locked for up to five more. Wait, and see [Failed log-ins](#failed-log-ins), especially if a reverse proxy makes everyone share one address. |
| A paired machine says the certificate is for a different name | The name or address in its pairing string is not one of the `--names`. Run the setup again with every name and address, including the new one (and any NAT or port-forward address on the host), and make a new pairing string while browsing by the right name. `server-https-status` shows what each name on the list is given. |
| Before 0.10, only one of two addresses worked ("certificate is valid for 192.168.15.10, not 10.10.1.185") | The server was reached by an address other than its own, through NAT. Upgrade to 0.10 and restart the service; the certificate then carries every name, the CA and its fingerprint stay the same, and machines that are already paired keep working. |
| The machine's page says `Enrolled, waiting for the agent` for a long time | The machine paired, but its agent is not running or cannot reach the server on port 39443. Check on the machine. |

For messages on the machine's side (an expired code, a fingerprint that does not match) see [When something goes wrong](../user-guide/troubleshooting.md#pairing-and-the-tray).

## Limits to know about

- The Phoromatic server runs on Linux. This page covers Ubuntu 26.04, which the installer was built and checked on; the packages also install on Debian 13 and Ubuntu 24.04, but those were only checked as far as `benchy version`.
- A server installed from a source tree with `install-sh` does not need `benchy-server` and keeps working as it is, but it does not get the standard layout. Running `sudo sh deploy/deb-package/benchy-server/benchy-server-setup --check` in the source tree compares it with the layout.
- Certificates from the built-in CA are for names and addresses you list. If the server's address changes, run the setup again with the new one and re-pair the machines that used the old one. A certificate from your own authority or from Let's Encrypt is possible too; see [Built-in HTTPS for the Phoromatic server](../https.md).
- One PHP web server serves everything, with eight workers by default. That is plenty for a handful of machines; for more, see "PHP workers" in [`https.md`](../https.md).
- Email from the server is not set up. It needs a working `sendmail` program on the machine, either named by its full path or found on the server's `PATH`, in PHP's `sendmail_path` setting; without one the server skips sending mail, so its email features do nothing. Since 0.9.13 the **Your notifications** part of the **Settings** page says so: "This server can't send email yet, so these have no effect until its administrator sets up mail."
- There is no RPM of the server package yet.
- On a first Windows launch of 0.9.14 and earlier, the desktop client's **Engine** chip waits while the PHP side installs Cygwin, so it can look stuck for a while before the first test. 0.9.15 no longer installs Cygwin on its own for a read-only command; see the [user guide](../user-guide/install.md#installing-on-windows).
- A test that needs administrator rights (one with `[elevated_run]` or `[machine_install]`) runs from a schedule only on Windows, on a machine whose engine is 0.8.0 or later (from 0.10.1), and only after the test is accepted at the machine once and each run is either approved by a person there or the machine has been set up to run unattended. Anywhere else the server holds the schedule and says why. Suites cannot hold such a test yet.
- The server takes a person's own results from 0.10.0, but no released client or engine sends them yet: the native desktop client's upload comes with client 0.11.0 and engine 0.8.0 in 0.10.1, and a machine that runs unattended cannot send its own results at all. The PHP client's upload works now. Scheduled results are not affected.
- Join codes need the built-in HTTPS, and with your own certificate or ACME they need a plain (not wildcard) first name in `--names`. Discovery finds a server on the same network segment, and on other VLANs only through the DNS record described under [Machines on other VLANs](#machines-on-other-vlans-one-dns-record).
- The server audit has a web page (**Server audit**, 50 rows to a page, read-only), but an account's own audit has none; read `phoromatic-admin-audit.log` or the `phoromatic_admin_audit` table. Neither page nor log can be searched or filtered yet.
- Purge is by hand only, and it does not take a copy first. If you may want the account again, back up the storage folder (see [Back up](#back-up)) before you purge.
- A server administrator sees an account's members, counts and state without joining it, but needs to join as a member to see its systems and results. Root cannot join an account.
- Memberships come from the server only: there is no single sign-on, and no way for a user to ask to join an account.
- When a native test fails to install on a paired machine, for example because the machine has no compiler, the server shows only "No test produced a result."
