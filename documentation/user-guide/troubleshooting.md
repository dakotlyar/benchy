# When something goes wrong

This page lists messages people actually meet, what they mean and what to do. It ends with a list of things Benchy 0.9.3 is known not to do well, so you can tell a limitation from a mistake.

## Starting Benchy

### PHP must be installed for Benchy

The `benchy` launcher cannot find PHP. Install the command-line PHP package (`php-cli` or `php`) with the command the message prints, then try again. See [Installing Benchy](install.md#installing-on-linux).

### To run Benchy locally you must first change directories to benchy/

This is what a copy of Benchy installed by the 0.9.1 `install-sh` printed instead of working. It is fixed. If you see it from an installed `benchy`, run `./install-sh` from the 0.9.3 folder to replace the broken launcher, then check with `cd / && benchy version`, which should print `Benchy v0.9.3`. If you see the message when you start `./benchy` yourself, you are not in the Benchy folder.

### ERROR: ... is not writable (from install-sh)

`install-sh` puts files under `/usr` by default, which needs root. Run it with `sudo`, or skip the install and [run Benchy from its folder](install.md#option-1-run-it-from-its-folder).

### MISSING ... OPTIONAL (a table of PHP extensions at start-up)

Benchy lists PHP extensions it did not find. `REQUIRED` ones must be installed before it works. The `OPTIONAL` ones only switch off features: no GD means plainer graphs, no PCNTL means some tests and threaded features are unavailable, and no OpenSSL means no HTTPS, so no connection to your Phoromatic server. If you plan to use one, install OpenSSL support first.

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

Either the file really is not a certificate in PEM format (it should begin `-----BEGIN CERTIFICATE-----`), or Benchy could not find it. If you gave a relative name like `ca.pem`, give the full path: Benchy changes into its own folder when it starts, so a relative name points to the wrong place.

### The fingerprint does not match the one you gave ... Someone may be in the way

The CA that answered is not the one you were told about. Do not carry on. Check the fingerprint you were given, and ask the administrator to run `benchy server-https-status` again. If both agree and it still fails, something on your network is intercepting the connection.

### With that CA the certificate of ... does not verify (is ... one of the names the certificate covers?)

The CA is right but the certificate does not name the host you typed, for example you used an IP address and the certificate lists only `server.example`. Use the exact name the administrator gave you.

### Upload Failed

`benchy phoromatic.upload-result` got no acceptance from the server. Things to check, in this order:

1. Is the machine approved? A newly connected machine appears on the server's Systems page as awaiting approval until an administrator accepts it.
2. Did you run `server-trust`, and does the address in your command start with `https://`?
3. Is the account ID in the address right? It is the last part: `https://server.example:39443/ACCOUNT`.
4. Can you reach the server at all? Try opening `https://server.example:39443/` in a browser from the same machine.

### Phoromatic is already running

You started `phoromatic.connect` twice. It only runs once per user. Use the first one, or close it first.

## Windows

### The installer says Benchy is running

Close every Benchy window, including the desktop client and any command prompt that is running a test, then run the installer again.

### The desktop client said "Missing: run_lock"

That was a display fault in Benchy 0.9.0 to 0.9.2 and is fixed in 0.9.3. Upgrade the installed Benchy.

### Stopping a test on Windows does not stop it

Cancelling on Windows assumes Cygwin is installed in `C:\cygwin64`. If you let Benchy set Cygwin up itself, that is where it is. If you installed Cygwin elsewhere, a cancel may leave the test's processes running; end them in Task Manager.

## Known limitations in 0.9.3

- Windows needs `benchy server-trust` for every HTTPS server, even ones with a certificate from a public authority.
- A CA you trust with `server-trust` is trusted for every Benchy server your machine knows, not just the one it came from.
- File names given on the Benchy command line must be full paths, not relative ones.
- `result show` in JSON mode reports only the latest run of a result that holds several runs.
- Only about a hundred of the tests have Windows versions.
- The desktop client runs one test at a time, and cannot trust a server's certificate by itself.
- `install-sh` does not support an install folder whose path contains a space, `&` or `|`. If one of its copy steps fails (for example, the disk is full) it can still report success.
- On macOS, the `install_macos` script does not point the launcher at the install, so run Benchy from its folder there.
- The server's own pages and a few messages still say `phoronix-test-suite` where they mean `benchy`.
