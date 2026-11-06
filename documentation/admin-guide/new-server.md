# Set up a Phoromatic server on a new Ubuntu 26.04 machine

This page takes a freshly installed, headless Ubuntu 26.04 server to a working Phoromatic server that is reached over HTTPS, with the first test machine paired to it. It is written for the person who installs and looks after the server, not for someone at a desk who only runs benchmarks; that reader wants the [user guide](../user-guide/index.md). You need to be comfortable in a terminal and with `sudo`, but you do not need to know Benchy. It covers **Benchy 0.9.7** installed from the Debian package, `benchy_0.9.7_all.deb`.

By the end you will have the Phoromatic server running as its own unprivileged user, started at boot by systemd, listening on one HTTPS port with a certificate from Benchy's built-in certificate authority (CA), behind a firewall that allows only SSH and that port. You will have a first account, a way to hand out pairing strings, and one machine sending results. The page also covers backing the server up, upgrading it and the messages you are most likely to meet.

In this page the server is `server.example.com` with the address `203.0.113.10`. Replace both with your own. Every command that touches the server's data is run as `sudo -u phoromatic env HOME=/var/lib/phoromatic benchy ...`, which means "as the server's own user, with that user's home directory"; Benchy refuses to change the server's settings as any other user, and the home directory decides which settings file and data it finds.

## What you need

- A server running Ubuntu 26.04, and a user on it who can use `sudo`.
- A name or an address that your test machines will use to reach the server. The name must resolve from the test machines, and you will give the name and the address to Benchy in step 5 because the certificate is made for exactly those. Changing them later is possible, but every machine paired by the old name needs to be told.
- Outbound HTTPS from the server: to `github.com` (Benchy downloads its web server, Caddy, from there) and to `openbenchmarking.org` (Benchy refreshes its test catalogue when the service starts; the server still works if this is blocked).
- Inbound access from your test machines to one TCP port, 39443 by default.
- The Benchy package, as described next.

## 1. Get the package onto the server

The package is called `benchy_0.9.7_all.deb`. A file with the same name plus `.sha256` sits next to it on the release page and holds the package's SHA-256 checksum, so you can tell that the copy you installed is the copy that was published.

### While the Benchy repository is private

Download the package on a computer where you are signed in to GitHub with access to the repository. With the GitHub command-line tool (`gh`) that is:

    gh release download v0.9.7 -R dakotlyar/benchy -p 'benchy_0.9.7_all.deb*'

Check it there, copy both files to the server, and check it again on the other side. The second check is the one that matters, because it proves that nothing changed on the way:

    sha256sum -c benchy_0.9.7_all.deb.sha256
    scp benchy_0.9.7_all.deb benchy_0.9.7_all.deb.sha256 you@server.example.com:

and then, on the server:

    sha256sum -c benchy_0.9.7_all.deb.sha256

Each check should print:

    benchy_0.9.7_all.deb: OK

If it prints `FAILED`, delete both files and copy them again. Do not install a package that fails the check.

### Once the repository is public

Download straight onto the server:

    curl -fLO https://github.com/dakotlyar/benchy/releases/download/v0.9.7/benchy_0.9.7_all.deb
    curl -fLO https://github.com/dakotlyar/benchy/releases/download/v0.9.7/benchy_0.9.7_all.deb.sha256
    sha256sum -c benchy_0.9.7_all.deb.sha256

## 2. Install the package

Run this from the folder that holds the file, with the `./` in front so that apt treats it as a file and not as a package name:

    sudo apt update
    sudo apt install ./benchy_0.9.7_all.deb

Use a plain `apt install` on a server. The Phoromatic server stores everything in an SQLite database, and the PHP extension for that, `php-sqlite3`, is a recommended package, not a required one. Installing with `--no-install-recommends` leaves it out and the server then refuses to start (see [If something goes wrong](#if-something-goes-wrong)). If you do want the lean install, add the extension yourself:

    sudo apt install --no-install-recommends ./benchy_0.9.7_all.deb php-sqlite3

A plain install also brings `php-gd`, which draws PNG and PDF graphs, and the `lspci` and `ip` tools for the hardware lines in `benchy system-info`. They are not needed to run the server.

Check that Benchy is there:

    cd / && benchy version

Expected, after a few lines about building its test catalogue the first time:

    Benchy v0.9.7

The package copies three systemd unit files into `/usr/lib/systemd/system` but does not enable or start any of them, and it creates no user and no folders. Nothing runs until you start it, and the next step sets up the pieces the package leaves to you.

## 3. Open the firewall

Do this before anything is running, so that the server is never reachable on a port you did not choose. Allow SSH first, so that turning the firewall on does not lock you out, then the server's one port:

    sudo ufw allow 22/tcp
    sudo ufw allow 39443/tcp
    sudo ufw enable
    sudo ufw status

`ufw enable` asks you to confirm; answer `y`. `ufw status` should list `22/tcp` and `39443/tcp` as `ALLOW`. Do not open 39080, the port for plain HTTP: for a short while in the next step the server listens on it, and with the firewall in place nobody can reach it; once you turn plain HTTP off, nothing listens there at all. The other ports Benchy uses (the PHP web server on 39082 and a random internal one) are on the loopback address and are not reachable from outside. If your server sits behind a cloud provider's firewall or a router, open 39443/tcp there as well.

## 4. Set up the service

The unit that ships with the package, `phoromatic-server.service`, is a minimal one. It starts `benchy start-phoromatic-server` as **root**, and it does not choose a port. Started as it is, the server would run as root, keep its data in `/var/lib/benchy`, listen on a random port that changes at every restart and speak plain HTTP. None of that is what you want, so you will give it a user of its own, a settings file that points at the storage folder, and a small override to the unit that sets its home directory and port.

### Create the service user

    sudo useradd --system --home-dir /var/lib/phoromatic --create-home --shell /usr/sbin/nologin phoromatic
    id phoromatic

Expected (the numbers will differ):

    uid=994(phoromatic) gid=994(phoromatic) groups=994(phoromatic)

The user's home folder, `/var/lib/phoromatic`, is where Benchy keeps its working files for the server. The database and everything the server stores for your accounts go into a `storage` folder inside it, which Benchy creates itself on first start.

### Point the server at its storage folder

A server that does not run as root keeps its settings in its own user's folder: `$HOME/.benchy/user-config.xml`, which for this user is `/var/lib/phoromatic/.benchy/user-config.xml`. Benchy writes that file the first time any `benchy` command runs as the user, so run one:

    sudo -u phoromatic env HOME=/var/lib/phoromatic benchy version | tail -n 2
    sudo ls -l /var/lib/phoromatic/.benchy/user-config.xml

Expected:

    Benchy v0.9.7

    -rw------- 1 phoromatic phoromatic 3753 Nov  6 21:41 /var/lib/phoromatic/.benchy/user-config.xml

By default the file puts the server's storage under `~/.benchy/phoromatic/`. Move it to `/var/lib/phoromatic/storage/`, and keep the file private, because it can hold passwords:

    sudo -u phoromatic env HOME=/var/lib/phoromatic sed -i 's|<PhoromaticStorage>.*</PhoromaticStorage>|<PhoromaticStorage>/var/lib/phoromatic/storage/</PhoromaticStorage>|' /var/lib/phoromatic/.benchy/user-config.xml
    sudo -u phoromatic chmod 600 /var/lib/phoromatic/.benchy/user-config.xml
    sudo grep PhoromaticStorage /var/lib/phoromatic/.benchy/user-config.xml

Expected:

      <PhoromaticStorage>/var/lib/phoromatic/storage/</PhoromaticStorage>

**Do not use `/etc/benchy.xml` for this.** That is the system-wide settings file, and only root, or a service that is allowed to write to it, reads it. A server running as `phoromatic` ignores it, so a change made there has no effect on the service, however it looks. Leave it alone (it may not exist at all; the first `benchy` command run as root creates it). The port is not set in either file: the next section sets it in the unit.

### Tell systemd to run it as that user

Put your changes in an override file, so a later package upgrade can replace the shipped unit without undoing them:

    sudo mkdir -p /etc/systemd/system/phoromatic-server.service.d
    sudo tee /etc/systemd/system/phoromatic-server.service.d/override.conf >/dev/null <<'EOF'
    [Service]
    User=phoromatic
    Group=phoromatic
    Environment=HOME=/var/lib/phoromatic
    Environment=PHOROMATIC_HTTP_PORT=39080
    Environment=PTS_FORCE_DAEMONIZED=1
    EOF
    sudo systemctl daemon-reload

`HOME` makes the service use the settings file and folders you just prepared. `PHOROMATIC_HTTP_PORT` is the port for plain HTTP; it takes priority over any port in a settings file, and without it Benchy picks a random port at every start. In step 5 you will turn plain HTTP off, so nothing will listen on 39080; choose another number if it is taken, but Benchy still needs one. `PTS_FORCE_DAEMONIZED=1` is not optional for a service that does not run as root. Without it Benchy decides that a person is at a keyboard, prints "Press [ENTER] to kill server..." and shuts the server down a couple of seconds after it started, with a clean exit status, so systemd reports nothing wrong and does not restart it.

Now start the service, once, in its plain-HTTP form. The first start creates the server's database, and the HTTPS commands in the next step refuse to run until it exists ("No Phoromatic database was found").

    sudo systemctl enable --now phoromatic-server
    sleep 10
    systemctl is-active phoromatic-server

Expected:

    active

The service is now enabled, which means it starts at every boot. It is listening on port 39080 in plain HTTP, which your firewall keeps closed; do not create an account yet. The first lines of the service log say which settings file and port it actually used, which is the first thing to check if anything behaves oddly:

    sudo journalctl -u phoromatic-server --no-pager | grep -E 'Directory Path|Configuration File|Accessible At'

Expected:

    Benchy User-Data Directory Path: /var/lib/phoromatic/.benchy/
    Benchy Configuration File: /var/lib/phoromatic/.benchy/user-config.xml
    The Phoromatic Web Interface Is Accessible At: http://localhost:39080

## 5. Turn on HTTPS

Benchy serves HTTPS through Caddy, a web server it starts in front of its own. HTTPS is **off** until you turn it on. These commands change the server's settings, so run them as the service user.

Fetch Caddy. Ubuntu 26.04's own `caddy` package is too old and starts a web server of its own; use the one Benchy is pinned to:

    sudo -u phoromatic env HOME=/var/lib/phoromatic benchy server-caddy-install

Expected (the last lines):

    Installed Caddy 2.11.7 at /var/lib/phoromatic/storage/tls/bin/caddy-2.11.7

    Next: benchy server-https-set internal --names HOST,IP   (then restart the service)

If the server cannot reach GitHub, download the Caddy archive named in the error message on another computer, copy it over and run `sudo -u phoromatic env HOME=/var/lib/phoromatic benchy server-caddy-install --archive /path/to/the-archive`.

Now choose the certificate source and the names. `internal` means Benchy's built-in CA signs the server's certificate. The names are every name and address your machines will use to reach the server, separated by commas, with no spaces. Use your own:

    sudo -u phoromatic env HOME=/var/lib/phoromatic benchy server-https-set internal --names server.example.com,203.0.113.10 --http-mode off

Expected:

    Saved: HTTPS mode internal, names server.example.com, 203.0.113.10, HTTPS port 39443, plain HTTP: off.
    Restart the Benchy server service to apply this change (systemctl restart phoromatic-server).

The `--http-mode` option decides what happens to plain HTTP:

| Mode | What it does |
|---|---|
| `serve` | The default. Keeps the old plain-HTTP address answering for older clients, and redirects the web pages to HTTPS. |
| `redirect` | Redirects the web pages to HTTPS and answers anything else with an error that names the fix. |
| `off` | No plain-HTTP port at all. |

A new server has no older clients, and the machines paired in step 7 talk to the server only over HTTPS, so `off` is the right choice and means port 39080 is never opened. The HTTPS port is 39443 unless you add `--https-port`. A certificate from your own authority, or one from Let's Encrypt, is possible too; see [Built-in HTTPS for the Phoromatic server](../https.md).

Restart the server so that it comes up with HTTPS:

    sudo systemctl restart phoromatic-server
    sleep 12
    systemctl status phoromatic-server --no-pager | head -6

Expected:

    ● phoromatic-server.service - Benchy Phoromatic Server
         Loaded: loaded (/usr/lib/systemd/system/phoromatic-server.service; enabled; preset: enabled)
        Drop-In: /etc/systemd/system/phoromatic-server.service.d
                 └─override.conf
         Active: active (running) since ...

Check the ports, and read the certificate and the CA fingerprint:

    ss -ltn | grep :39
    sudo -u phoromatic env HOME=/var/lib/phoromatic benchy server-https-status

`ss` should show Caddy on every address on 39443 and the web server on the loopback address only:

    LISTEN 0      4096       127.0.0.1:39082      0.0.0.0:*
    LISTEN 0      4096               *:39443            *:*

`server-https-status` prints the settings, then the certificate being presented, then the line you need:

    Built-in HTTPS: internal
    Names:           server.example.com, 203.0.113.10
    HTTPS port:      39443
    Plain HTTP:      off
    ...
    Service running: yes

    Certificate presented on port 39443:
      Names:     DNS:server.example.com
      Issuer:    CN=Benchy server.example.com CA - ECC Intermediate
      ...

    Built-in CA root certificate SHA-256 fingerprint (give it to clients):
      DB:0C:AA:F7:02:1F:5D:2C:8E:F5:9C:53:22:AC:6F:B0:92:0D:1E:91:1D:1B:52:11:87:C5:2F:E7:F3:48:E9:A9

That 64-digit value is the fingerprint of the built-in CA. A test machine uses it to be sure it is talking to your server and not to someone in between. Note it down and give it to whoever pairs machines, by a route that is not the network path the machine will use to reach the server. The fingerprint is stable: it belongs to the CA, which is made once and kept in the server's storage, so restarts and upgrades do not change it. The certificate itself is renewed automatically.

Test from another computer. Without the CA trusted, `curl` refuses the certificate, which is the correct behavior; `-k` skips the check for this one test only:

    curl -sk -o /dev/null -w '%{http_code}\n' https://server.example.com:39443/

Expected:

    200

## 6. Create the first account

Open `https://server.example.com:39443/` in a browser. Use the name you gave in step 5, not a different one: the page builds the pairing strings in step 7 from the address in your browser's address bar, and a string for an address the certificate does not cover will not work.

Your browser will warn that the certificate is not trusted, because the built-in CA is not one it knows. That is expected. Open the certificate details, compare the SHA-256 fingerprint of the issuing (root) certificate with the one from `server-https-status`, and if they match, continue. To stop the warning on your own computer, copy the CA root certificate from the server (`/var/lib/phoromatic/storage/tls/caddy-data/pki/authorities/local/root.crt`, readable only by root) and add it to your browser or system trust store.

The welcome page has a **Create A New Account** link, which opens the registration form. Fill in a user name (four or more characters, letters, digits, dot, dash or underscore; `admin` and `administrator` are reserved), a password of at least six characters, and an email address. This first user is the administrator of its account, and the machines you pair will belong to that account.

The page then says:

    Account Created
    Your account has been created. You may now log-in to begin utilizing Benchy's Phoromatic.

Above the confirmation you may see a line starting `mail(): Sendmail exited with non-zero exit code 127`. The server tried to send a welcome email and the machine has no mail program. It is harmless; email from the server is simply not set up.

Then log in with the account you just made.

### Stop strangers creating accounts

By default anyone who can reach the welcome page can create an account of their own, and on a server that is open to the internet you almost certainly do not want that. Each account is separate from yours, but it is still a stranger using your machine's disk and CPU. Switch it off once you have your own account:

1. On the server, set a password for the server's built-in administrator, called `rootadmin`. Type a password of at least six characters when it asks, at the keyboard; do not feed it from `/dev/null` or a closed pipe, as it keeps asking until it gets one:

       sudo -u phoromatic env HOME=/var/lib/phoromatic benchy phoromatic.set-root-admin-password

2. In the browser, log out, then log in with the user name `rootadmin` and that password. You land on the server administration pages.
3. Open **Admin Config**. Under **Account Creation**, type a short message in **Account Creation String**, such as "Account creation is turned off. Ask the administrator.", and press **Update Account Handling**.

The welcome page now shows that message instead of the form. To allow account creation again later, empty the field.

## 7. Pair the first machine

Pairing introduces a test machine to the server once. The server makes a one-time pairing string; the machine uses it and from then on takes scheduled work on its own. This step is the server's half; the machine's half is in the [user guide](../user-guide/pair-this-machine.md).

1. Log in with your own account and open **Systems**.
2. Find **Enroll A Native System**. Type a label for the machine, such as `first-machine`, and press **Enroll a new native system**.
3. The page shows an **Enrollment code** box, once. Beside **Server** is the address, beside **CA fingerprint** the value from step 5, and beside **Pairing string** a line that starts `benchy-enroll:https://`, like this:

       benchy-enroll:https://server.example.com:39443?code=ben_...&ca=db0caaf7021f5d2c8e...

   The code in it works once and expires after 24 hours. Press **Copy** and keep the string private until the machine has used it. If you lose it, make another; the old code can be revoked from the list of open codes on the same page.
4. Send the string to the person at the machine, and the fingerprint separately. The pairing string already contains the fingerprint (the `ca=` part, written in lower case without colons), so the person only needs the fingerprint from you to compare it against what their screen shows.

On the machine, the person pairs with the Benchy desktop client or with the engine on the command line. As an example, from a terminal on a Linux machine that has `benchy-engine` (the string goes in on standard input, so that it stays out of the process list):

    echo '["benchy-enroll:https://server.example.com:39443?code=...&ca=..."]' | benchy-engine --json agent enroll --json-args -

The last two lines of its output tell you it worked:

    {"v":1,"type":"agent_enrolled","seq":3,...,"server":"https://server.example.com:39443","account":{"id":"VMVDU3","name":"VMVDU3"},"system":{"id":"FH52B","title":"bench1"},"policy":"native",...}
    {"v":1,"type":"done","seq":4,...,"status":"ok","exit_code":0}

There is no second approval step: the enrollment code is the approval. Back on the **Systems** page, the machine appears under **Active Systems**:

    bench1 Enrolled, waiting for the agent

and changes to `Idle (native agent)` once the machine's agent is running (`benchy-engine --json agent run`, or the desktop client's tray, which starts it by itself).

## 8. Check that it works

Four checks, from the server's side.

1. The service is up, and survives a restart:

       systemctl is-active phoromatic-server
       sudo systemctl restart phoromatic-server && sleep 12 && systemctl is-active phoromatic-server

   Both print `active`.

2. A page loads over HTTPS (this is the same check you used in step 5, and it works from the server itself too):

       curl -sk -o /dev/null -w '%{http_code}\n' https://server.example.com:39443/

   It prints `200`. If the name does not resolve on the server itself, use `https://127.0.0.1:39443/`.

3. The server's own log is quiet:

       sudo journalctl -u phoromatic-server -n 20 --no-pager

   Near the end you should see `Launching with PHP built-in web server on 127.0.0.1:39082 behind Caddy (HTTPS internal on port 39443, plain HTTP: off).` and `The Phoromatic Web Interface Is Accessible At: https://localhost:39443`.

4. A result arrives. The machine's agent must be running, so its state on the **Systems** page is `Idle (native agent)`. Open **Create A Schedule**, give it a title, tick your machine under the systems it runs on, tick every day under **Active On**, and press **Create Schedule**. On the new schedule's page, scroll to **Native Tests**, open **STREAM** (`benchy/stream`, a memory test of about ninety seconds that needs a compiler on the machine; `build-essential` provides one) and press **Add**. The section only offers tests once a paired machine's agent has connected, because the list comes from the machine. Then press **Run Test Schedule Now**; the machine picks the job up at its next check-in, within a minute or so. When the run finishes the machine goes back to `Idle (native agent)` and **Results** lists a result named after the schedule, for example `smoke - yourname - Manual Test Run - 21:09 6 Nov 2026`. If it does not, see [If something goes wrong](#if-something-goes-wrong).

The server keeps its own log at `/var/lib/phoromatic/.benchy/phoromatic.log`, with PHP's startup lines, and Caddy's log at `/var/lib/phoromatic/storage/tls/caddy.log`. Both sit in folders that only root and the `phoromatic` user can read.

## Back up the server

All the server's data is in `/var/lib/phoromatic/storage`: the database (`phoromatic.db`), the settings file next to it, every account's results and uploaded files, and the `tls` folder with the built-in CA and its private key. Losing the `tls` folder means a new CA with a new fingerprint, and every paired machine will have to be paired again. Together with the service user's settings file, `/var/lib/phoromatic/.benchy/user-config.xml`, and the systemd override, that is the whole server.

Copy it with the service stopped, so that the database is not written to while you copy it:

    sudo systemctl stop phoromatic-server
    sudo mkdir -p /root/backups
    sudo tar -C / -czf /root/backups/phoromatic-$(date +%Y%m%d-%H%M).tgz var/lib/phoromatic/storage var/lib/phoromatic/.benchy/user-config.xml etc/systemd/system/phoromatic-server.service.d
    sudo systemctl start phoromatic-server
    sudo ls -l /root/backups

The archive holds the CA's private key and password hashes, so keep it where only you can read it, and copy it off the machine. `/root` is already private. To restore it, stop the service, unpack over the top and start again; ownership is restored with the files:

    sudo systemctl stop phoromatic-server
    sudo tar -C / -xzf /root/backups/phoromatic-20261106-2101.tgz
    sudo systemctl start phoromatic-server

The rest of `/var/lib/phoromatic/.benchy` is only a cache of the test catalogue and the log, so it is not in the backup.

## Upgrade to a later package

1. Back up, as above, and leave the service stopped.
2. Get the new package onto the server and check it, as in step 1 (for example `benchy_0.9.8_all.deb`).
3. Install it over the old one and start the service. The package replaces the program files in `/usr/share/benchy` and the unit in `/usr/lib/systemd/system`; your settings, data and override are untouched. A package install never restarts the service, so the old code keeps running until you do it:

       sudo apt install ./benchy_0.9.8_all.deb
       sudo systemctl daemon-reload
       sudo systemctl start phoromatic-server

4. Check `cd / && benchy version`, then repeat the checks in step 8. Read the release notes first: they say whether the database changes, because after a release that changes the database, going back means restoring the backup.

To go back, stop the service, install the old package (`sudo apt install --allow-downgrades ./benchy_0.9.7_all.deb`), restore the backup and start the service.

## If something goes wrong

Start with the service log, which is where the server writes every message below:

    sudo journalctl -u phoromatic-server -n 50 --no-pager

| What you see | What it means and what to do |
|---|---|
| `systemctl status` says `inactive (dead)` a few seconds after you started it, and the log ends `Press [ENTER] to kill server...` | The unit has no `PTS_FORCE_DAEMONIZED=1`. Add it to the override file in step 4, then `sudo systemctl daemon-reload` and start the service again. |
| `Not starting: No Caddy is installed for the built-in HTTPS. Run: benchy server-caddy-install` followed by `(The server does not fall back to plain HTTP. Fix it, or run benchy server-https-off.)` | HTTPS is on but there is no Caddy, or it has gone from the storage folder. Run `sudo -u phoromatic env HOME=/var/lib/phoromatic benchy server-caddy-install`, then start the service. The server deliberately never falls back to plain HTTP. The service shows `inactive (dead)` and does not restart itself after a message like this. |
| Other `Not starting: ...` lines, such as `The HTTPS port 39443 is already in use.` or `The Caddy binary ... does not match the sha256 recorded when it was installed` | The message names the problem. A busy port means another program has it: stop that program or choose another port with `server-https-set --https-port`. A Caddy that fails its checksum or is too old is fixed by running `server-caddy-install` again. |
| `PHP SQLite3 support must first be enabled before accessing the Phoromatic server` | `php-sqlite3` is missing, usually after a `--no-install-recommends` install. `sudo apt install php-sqlite3`, then start the service. |
| `ERROR: The Phoromatic database /var/lib/phoromatic/storage/phoromatic.db belongs to phoromatic, but this command is running as a different user. Run: sudo -u phoromatic benchy server-https-status` | You ran a server command as yourself or as root, or as `phoromatic` without `HOME=/var/lib/phoromatic`. Run it as `sudo -u phoromatic env HOME=/var/lib/phoromatic benchy ...`. |
| `Port NNNN chosen as random port for this instance` in the log | The service has no port. Check that the override file has `Environment=PHOROMATIC_HTTP_PORT=39080`, run `sudo systemctl daemon-reload`, and restart. |
| I changed `/etc/benchy.xml` and nothing happened | A server that runs as `phoromatic` does not read `/etc/benchy.xml`. Its settings file is `/var/lib/phoromatic/.benchy/user-config.xml`; edit that, then restart. To see which file and port the service really used, run `sudo journalctl -u phoromatic-server --no-pager | grep -E 'Directory Path\|Configuration File\|Accessible At'` and read the lines from the latest start. The port also shows in `ss -ltn`. |
| The storage folder is not where you expect, or the data seems to have disappeared after a change | `PhoromaticStorage` in the settings file decides where the database lives. Check it, and check the `Configuration File:` line in the log. Data under the old location is not moved for you. |
| `The Phoromatic Server is already running.` | A copy is already running, or died without cleaning up. `sudo systemctl stop phoromatic-server`, wait a few seconds, and start it again. |
| `[NOTICE] mkdir(): File exists in pts_file_io:28`, repeated, in the first minute after the first start | Cosmetic. Benchy makes a folder that already exists. Nothing to do. |
| A browser, or `curl`, refuses the certificate | Expected until the CA is trusted: see step 6. For `curl`, use `--cacert` with the CA root file, or `-k` for a one-off test. |
| A paired machine says the certificate is for a different name | The name in its pairing string is not one of `--names`. Add it with `sudo -u phoromatic env HOME=/var/lib/phoromatic benchy server-https-set internal --names ...` (list every name), restart the service, and make a new pairing string while browsing by the right name. |
| The machine's page says `Enrolled, waiting for the agent` for a long time | The machine paired, but its agent is not running or cannot reach the server on port 39443. Check on the machine. |

For messages on the machine's side (an expired code, a fingerprint that does not match) see [When something goes wrong](../user-guide/troubleshooting.md#pairing-and-the-tray).

## Limits to know about

- The Phoromatic server runs on Linux. This page covers Ubuntu 26.04; the package also installs on Debian 13 and Ubuntu 24.04, but those were only checked as far as `benchy version`.
- Certificates from the built-in CA are for names and addresses you list. If the server's address changes, run `server-https-set` again with the new one and re-pair the machines that used the old one.
- One PHP web server serves everything, with eight workers by default. That is plenty for a handful of machines; for more, see "PHP workers" in [`https.md`](../https.md).
- Email from the server is not set up, so the server's own email features do nothing.
