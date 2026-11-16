# Sending results to your Phoromatic server

Your Phoromatic server is a web service that collects benchmark results from every machine, keeps them in one place and lets you compare them in a browser. This page shows how to connect your machine to it, get past the one-time certificate step, upload a result, and find it again on the web. It assumes you already have a saved result (see [Your first benchmark](first-benchmark.md)). If you want the server to send your machine scheduled work instead, without a terminal, see [Pair this machine](pair-this-machine.md); the two routes can be used side by side. Setting up or running the server itself is the administrator's job and is not covered here; the [administrator's guide](../admin-guide/index.md) is the place for that.

The address `server.example` and the account ID `ACCOUNT` in the examples are placeholders: use the ones your administrator gives you.

## How it fits together

Your machine and the server talk over HTTPS on port 39443. The server has its own certificate authority (CA), a private one that your machine does not know about yet. Until you tell Benchy to trust it, Benchy refuses to talk to the server, by design: it never ignores certificate errors and never quietly falls back to plain HTTP. So there are three steps, and the first is done only once per machine:

1. Trust the server's certificate.
2. Introduce your machine to the server and have it approved.
3. Upload results whenever you like.

## What you need from the administrator

Ask whoever runs the server for:

- the server address in the form `https://server.example:39443/ACCOUNT`, where `ACCOUNT` is your account's ID (a short string of letters and digits);
- the SHA-256 fingerprint of the server's CA, the long run of hexadecimal pairs printed by `benchy server-https-status` on the server, like `B0:D6:09:02:4F: ...` (64 hex digits in all);
- an account on the web interface, if you want to look at results in a browser.

Get the fingerprint from the administrator directly, not over the same network path as the server. It is what proves you are talking to the real server. Administrators approve new machines by hand today; larger deployments may use a different way of enrolling machines later.

## 1. Trust the server's certificate (once)

    benchy server-trust https://server.example:39443/ACCOUNT --fingerprint B0:D6:09:...

Benchy fetches the server's CA, shows you its fingerprint, and keeps it only if it matches the one you typed. On success you will see:

    CA offered by the server: /CN=...
      SHA-256: B0:D6:09:...
    The server's certificate verifies with this CA.
    Saved the CA to /home/you/.benchy/server-ca.pem
    No stored server address needed changing. Connect with: benchy phoromatic.connect https://server.example:39443/ACCOUNT

If instead you see `The fingerprint does not match ... Someone may be in the way. Nothing was saved.`, stop and check the fingerprint with the administrator. Do not try other values until it passes.

If you were given the CA as a file rather than a fingerprint, use that:

    benchy server-trust https://server.example:39443/ACCOUNT --ca-file /full/path/to/ca.pem

A relative name such as `ca.pem` is read from the folder you ran the command in. (Before Benchy 0.9.5 a relative name was not found, because Benchy changed into its own folder first; with an older version give the file's full path.)

Things to know:

- **Windows machines always need this step**, even if the server's certificate comes from a public authority. Benchy's PHP on Windows does not use the Windows certificate store. On Linux the system certificate store is also checked, so a server with a publicly trusted certificate needs no `server-trust` at all, and running the command with no `--fingerprint` or `--ca-file` simply tells you so.
- The CA is stored in `server-ca.pem` in your `.benchy` folder. If you already trust a different CA, Benchy asks before replacing it; add `--replace` to say yes in advance.
- A CA you trust this way is trusted for every Benchy server your machine knows about, not only your Phoromatic server. This will be narrowed to one server per CA in a later release.
- The names the certificate covers matter. If the certificate was made for `server.example` and you use its IP address in the command, the check fails; use the name the administrator gave you.

## 2. Introduce your machine

    benchy phoromatic.connect https://server.example:39443/ACCOUNT

This registers your machine with the account. The first time, the server replies that the machine is waiting for approval, and an administrator has to approve it on the server's **Systems** page. Since 0.9.12 that is always so unless the server's administrator has allowed accounts to approve new machines on their own and your account has turned that on; by default every new machine waits. Ask them to; you will see the machine listed there under the name of your computer.

`phoromatic.connect` is more than a one-time handshake. It keeps running, checking in every so often, so that the server can hand the machine scheduled tests. Leave it running only if you want your machine to take part in the house's scheduled benchmarking, which will use its processor when a schedule fires. For just uploading your own results, press Ctrl+C once the server has your machine on its Systems page; the registration stays.

If the address is missing or Benchy cannot find a server, it says: `You must pass the Phoromatic Server information as an argument to phoromatic.connect, or otherwise configure your network setup.` On a Windows machine the installer can fill the address in for you (see [Installing Benchy](install.md#installing-on-windows)).

## 3. Upload a result

Once your machine is approved, send a saved result with:

    benchy phoromatic.upload-result https://server.example:39443/ACCOUNT kitchen-laptop

    Uploading: kitchen-laptop
       Result Uploaded

You can name several results in one command. After the first time, Benchy remembers the server, so this is enough:

    benchy phoromatic.upload-result kitchen-laptop

If it prints `Upload Failed` instead, see [When something goes wrong](troubleshooting.md#upload-failed). If it prints `No Result Files Found To Upload.`, the name does not match a saved result; check it with `benchy list-saved-results`.

**Do not use `benchy upload-result`.** That older command, without the `phoromatic.` in front, sends the result to the public OpenBenchmarking.org website, not to your Phoromatic server.

If you use the desktop client, its upload step uses the same stored server address, so do steps 1 and 2 here first. The client cannot trust a server's certificate for uploads itself.

## Looking at your results on the server

Open the server in a browser at `https://server.example:39443/` and sign in with the account the administrator gave you. The first time, your browser will warn that the certificate is not trusted, because it is signed by the house's own CA. Compare the fingerprint the browser shows with the one from the administrator, accept it for this site, or ask for the CA file to import into your browser or system.

The menu across the top has the main areas:

| Menu | What you will find |
|---|---|
| Systems | Every machine on the account, when each last checked in, and a padlock beside the ones that last connected over HTTPS. |
| Results | The recent results from all machines, with graphs. Open one to see its tests, numbers and the hardware and software of the machine that produced it. |
| Testing | Test schedules, which tell connected machines what to run and when. Administrators mostly use this. |
| Main | **Settings** has your own email notifications and the options for your whole account, with a plain line under each one. If an option is greyed out and marked "Locked by server", the server's administrator has set a rule that overrides it, and the line under it says which. A Viewer sees the same page read-only. **Users** is for administrators of the account. |

If you are one of the server's administrators there is also a **Server** link in the menu. It opens the server console, where accounts are looked after (see the [administrator's guide](../admin-guide/accounts.md)), and the console has **Server settings** in its row of links, as does the bottom of the **Settings** page. That page holds the options that cover every account on the server: whether new systems wait for approval, whether clients may contact OpenBenchmarking.org, the HTTPS certificate and the native agent connections. Other users do not see the link, and the page tells them it is for server administrators. Root has the same options on **Admin Config**.

To compare, open a result and look for the **Compare Results** box beside it. It lists similar results; tick the ones you want and press **Compare Results** to see them on the same graphs. Putting results from different machines on one graph is the main reason to send them to the server.

You can also work from the terminal:

| Command | What it does |
|---|---|
| `benchy phoromatic.list-results` | Lists the most recent results on the server, with their IDs. |
| `benchy phoromatic.clone <result ID>` | Downloads one of them into your own saved results, so you can use `result-file-to-text` and the other commands on it. |

Uploaded results are private to your Phoromatic account. Other accounts on the same server cannot see them (before 0.9.12 an account could share its results with others; that is gone), and they are not published to OpenBenchmarking.org.

If you read your results in a feed reader, use the link from **Results Feed** in the **Results** menu. Links made before 0.9.12 no longer work and give an empty feed.

## Working in more than one account

Your login can belong to several accounts on the same server, at a different level in each. You might be a Group Administrator of the lab's account and a Viewer in a colleague's, for instance. An administrator of the server sets this up for you; you cannot add yourself to an account.

If you belong to only one account, nothing looks different. When you belong to two or more, the header shows a menu next to the clock with the name of the account you are in and a small arrow. Open it to see every account you belong to with your level in each, for example "Lab A - Group Administrator" and "Lab B - Viewer". Your current account is marked "(this account)". Click another account's name to move to it. You land on that account's main page, because the page you were on belonged to the account you left. An account that is suspended or that you have been turned off in is listed greyed out and cannot be clicked.

Each account is a separate space. A machine, schedule, result or private test in one is not visible from another, and the level you have there decides what you can do there. A Viewer in one account can be an administrator in another. The next time you sign in, the server opens the account you used last.

The **Users** page of an account lists its members. Each shows a "Last used" line, the last time that person was in this account, or "Never". A person who belongs to the account but whose own account is another one is marked "(member from another account)". Only administrators of the account open that page.

## When an account is no longer available to you

If an administrator of the server suspends or deletes the account you are in, or removes you from it, or turns your login off, you notice at your next click. If you belong to another working account, you are taken to that account's main page, with a line at the top, for example:

    Lab B is no longer available to you. You are now in Lab A.

Whatever you were doing in the first account did not go through: a form you had open there was not saved, so do it again from the right account if it still applies. If you have no other account to go to, you are signed out, and signing in again tells you why: "This Phoromatic account is suspended. Ask the administrator of this server." for a suspended account, or "The log-in is not possible: this user is not a member of any account." if you have been taken out of every account, or "The log-in is not possible as this account has been disabled." if your login was turned off. In each case, ask the administrator of the server. Suspending an account does not delete anything, and an administrator can put everything back.

## Older addresses without `https://`

Addresses of the form `host:port/ACCOUNT` (no `https://`) mean plain, unencrypted HTTP on the server's older port, 39080. They still work for as long as the administrator leaves that port open, but the data is not protected on the way. Prefer the `https://` form. `server-trust` also rewrites any stored plain address for that server to the HTTPS one.
