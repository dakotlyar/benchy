# The signed test catalog

Since 0.9.15 a Phoromatic server can hold a signed list of tests, the test catalog, and hand it to the machines that run native agents. The catalog is made and signed by the publisher of the tests, not by your server, and a machine checks the signature itself before it trusts anything, so the server is only the post office. This page is for the people who run the server. It shows how the catalog gets onto the server, how to see what the server holds, how a schedule can pin a test to one version, what the labels on a test mean, and what happens to tests that need administrator rights. It covers **Benchy 0.9.15**.

If you only want to upgrade a server, read [Upgrading to 0.9.15](new-server.md#upgrading-to-0915-the-signed-test-catalog-and-the-no-phone-home-fixes) first. Nothing in this page has to be done for an upgrade to work.

## What is in the catalog

The catalog comes in two halves, called tiers. The free tier holds tests whose license lets anyone download and run them. The non-free tier holds tests for products with their own license terms. A bundle (one `.zip` file) holds one tier. Each carries a release number made of two parts, a generation and a sequence. The first catalog is generation 1, sequence 1, and every later release is higher. A server refuses a bundle that is older than the one it already holds, so a catalog can only move forward.

| Tier | Bundle file | Tests |
|---|---|---|
| Free | `benchy-catalog-public-1-1.zip` | blender, build-php, compress-lz4, compress-pbzip2, compress-zstd, cpuminer-opt, john-the-ripper, lzbench, openssl, redis, stockfish and x265. All are Linux tests. |
| Non-free | `benchy-catalog-private-1-1.zip` | cinebench, intel-mlc, ms-office-benchmark, novabench and passmark. All are Windows tests. |

The free bundle comes with the 0.9.15 packages and is also a file on the 0.9.15 release page. The non-free bundle is not part of any package or public release. It is on the private `benchy-tests` release, and you can only get it if you have access to that.

Only machines whose engine is 0.6.0 or later use the catalog (the engine that comes with 0.9.15 is 0.6.0). Older ones ignore it and keep running the tests they already have. A machine that is not approved yet, or whose account is suspended, is not given the catalog.

## Getting the catalog onto the server

### A server installed from the packages

The `benchy-server` package carries the free bundle in `/usr/share/benchy/catalog/`. The first time the server starts after the upgrade it imports that file by itself, once. You do not have to do anything. To see that it happened:

    sudo -u phoromatic env HOME=/var/lib/phoromatic benchy server-catalog-status

The last line says what became of the bundle that came with this version, for example `The bundle that came with this version: imported (2026-11-17 13:32:10)`. If it says it was refused, the reason is on the line and on the server's catalog page, and the catalog you had is untouched.

The non-free bundle is never imported for you. If you want it, import it yourself as described next.

### A server run from a source tree

A server that is updated with `deploy.sh` has no `/usr/share/benchy/catalog/` folder, so nothing is imported at start. Download the free bundle from the 0.9.15 release page, check its checksum, and import it once with the command below. This is also how you add the non-free bundle on any server.

### Import from the command line

Run the commands on the server, as the user the server runs as. On a package server that is `phoromatic`:

    sudo -u phoromatic env HOME=/var/lib/phoromatic benchy server-catalog-import benchy-catalog-public-1-1.zip

The file has to be one the `phoromatic` user can read (a copy in `/tmp` will do). A good import prints one line:

    Imported the free catalog: generation 1, sequence 1 (the first). 12 tests added, 0 withdrawn. Agents pick it up when they next check in.

Every check passes before anything is stored, and the result is written to the server audit. The command takes bundles up to 128 MiB. For the rules about who may run server commands, and what the server says when you run one as the wrong user, see [When root runs the server commands](new-server.md#when-root-runs-the-server-commands) and [Running a command as the wrong user](new-server.md#running-a-command-as-the-wrong-user).

If you import a bundle the server already holds, nothing changes and the command says so:

    The free catalog is already imported (generation 1, sequence 1); nothing changed.

That is safe to do. It is also the repair: if a stored file of the catalog has gone missing or been damaged, importing the same bundle again puts it back, and the message then adds that the missing files were put back.

To look at a bundle first without importing it, use the check command. It tells you what is in the file, and writes nothing and needs no server database:

    benchy server-catalog-check benchy-catalog-public-1-1.zip

It prints one line, the tier, the generation, the sequence, and the number of tests, suites and withdrawn tests: `public 1 1 12 0 0`. If the bundle is refused it prints the reason and exits with status 1.

### Import in the browser

A server administrator can also import on the **Test catalog** page: the **Server** link in the menu, then **Test catalog** (the address is `?server_catalog`; root finds the same section on **Admin Config**). Choose the `.zip` file next to **Import a catalog bundle** and press **Import**. The page limit is 24 MiB, which is plenty for a catalog (the free bundle is about 70 KB). A larger file is turned back with the message that it is bigger than the page accepts and the command to use on the server instead.

The page shows, for each tier, whether it is served, the release, the date it was issued, how many tests, suites and withdrawn tests it holds, then the tests themselves with their versions and platforms, and the last 20 catalog events. A person who is not a server administrator cannot open it, and a forged form changes nothing.

## What the server says when it refuses a bundle

A refusal changes nothing, and is written to the server audit as `catalog_import_refused`. The command prints it as `Refused (reason): message` followed by `Nothing was changed.`; the page prints the same sentence in red. The words you are most likely to meet:

| Reason | What it means | What to do |
|---|---|---|
| `bad_signature`, `unknown_key` | The bundle is not signed by a key this version of Benchy trusts, or it was changed after signing. | Download it again from the release page. If it keeps failing, it is not an official bundle. |
| `denied_key`, `retired_key` | The key that signed it is known to be compromised, or has been retired. | Get a newer bundle. |
| `rollback`, `floor` | The bundle is older than the one already on the server, or older than this version of Benchy will accept. | You already have a newer catalog. Nothing to do. |
| `same_pair_differs` | Two different bundles claim the same release number. The page also shows a red notice. | Tell the publisher. Do not import either again. |
| `license_tier` | A bundle meant for the free tier holds a test that is not free. | Tell the publisher. |
| `zip_structure`, `zip_mismatch`, `bundle_unreadable` | The file is damaged, cut short, or cannot be read by the server. | Download it again and check the checksum. |
| `limit` | The file is bigger than 128 MiB, or an index holds more than the server accepts. | Not a catalog bundle. |
| `busy` | Another import is running, or the database is busy. | Wait a moment and try again. |
| `precondition` | PHP on the server lacks `openssl` or `zlib`, or the database has not been upgraded for the catalog yet. | Install the missing PHP part, or start the server once on 0.9.15 so it finishes its upgrade, and try again. |

## Seeing what the server holds

`server-catalog-status` changes nothing and shows each tier:

    Free catalog (public): served
      release: generation 1, sequence 1; signed by key 4df551974840a54245bb240473ffb409; issued 2026-11-17T12:00:00Z
      holds: 12 tests, 0 suites, 0 withdrawn
      imported 2026-11-17 13:28:28 by local:root (cli)
    Retired key generations: none

A tier that is stored but not given out says why in place of `served`, for example `NOT SERVED: the signing key is no longer trusted` or `NOT SERVED: the stored file is missing or damaged (import the same bundle again to repair it)`. The same words appear as red notices on the **Test catalog** page and on **Server settings**. Two more notices are worth knowing: one tells you that a catalog was issued more than a year ago and asks you to look for a newer release, and one says that some test packs are missing from storage, which importing the same bundle again fixes.

On the page, a tier signed by anything but the newest key generation the server knows is described as "an older key". Today the server knows a generation 2 key as well, so the page says "an older key (generation 1)" about the first catalog, which is the newest one there is. That is only the page's wording; it is not a warning.

## Pinning a schedule to a version

By default a machine runs the newest version of a test that it has. A schedule can instead pin a test to one version, so that every machine in the schedule runs exactly that one and results stay comparable. Pins are for native tests on machines that use the catalog, and they are on the schedule page, under **Native Tests**.

When you add a test, the **Version** menu offers what the catalog holds. If every machine the schedule runs on has the same version now, that version is the default and the page says "Pinned to 1.1.0, the version every system has now." If the machines differ, the default is not to pin, and the page lists who has which version and says to pick one to make them match. You can always choose **Not pinned**.

A test that is already in the schedule shows either **Pinned to** and its version, with a menu and a **Change version** button, or **Not pinned** with a **Pin to** button that pins it to the version the machines have now. Choosing **Not pinned** in the menu takes the pin off again. Withdrawn versions are not offered; if the version you pinned is withdrawn later, the schedule page says so in red, with the reason the publisher gave, and the schedule is held until you pick another.

The schedule is held on a machine, and runs nothing there, in these cases:

- The machine's engine is older than 0.6.0 and cannot run a chosen version. The schedule page and the machine's page say `Schedule "NAME" pins test versions, and this system's engine (0.5.0) can't run a chosen version. Update it to engine 0.6.0 or later.`
- The pinned version was withdrawn: `benchy/pin 1.2.0 was withdrawn: REASON. Pick another version.`
- The pinned version needs a newer engine than the machine's: `benchy/pin 1.3.0 needs engine 0.9.0 or later, and this system runs 0.7.0. Update it, or pick an older version.`

A held schedule behaves like a switched-off one: its newest trigger waits, older ones do not pile up, and it runs as soon as the reason is gone. Pinning and changing a pin are written to the account's audit (see below). A result records the version that ran.

## The labels on a test

Next to every native test on the schedule page, and in the add list, the server says what kind of test it is:

| Label | Meaning |
|---|---|
| Free test, Non-free test | Which tier the test comes from. A non-free test is for a product with its own license. This has nothing to do with an account's private test repository. |
| Installs *product* for all users | Running the test first installs the vendor's product for every user of the machine, which takes administrator rights once. |
| Runs as administrator | The test itself needs administrator rights each time it runs. |
| Not in this server's catalog | The machine reports a test the server holds no signed entry for. |

Of the tests in the first catalog, novabench and passmark install their product for all users, and passmark also runs as administrator.

## Tests that need administrator rights

A test that installs for all users, or runs as administrator, cannot be run by an ordinary agent. In 0.9.15 the server never fails such a job. It holds the schedule on every machine that cannot run it yet, says why, and sends it the moment the machine can. The held schedule is listed on the machine's page under what will not run there.

The engine that comes with 0.9.15 is 0.6.0. It cannot run these tests, and no released engine can yet. So on every machine today a schedule with novabench or passmark is held with

    Schedule "NAME" has a test that needs administrator rights. Update the engine on this system to 0.7.0 or later. It can then run tests that need administrator rights.

The rest of the schedule does not run on that machine either: a schedule that mixes ordinary tests with an administrator test is held whole on a machine that cannot run the administrator test, because a partial result would quietly differ from what the schedule is meant to measure. Put such a test in a schedule of its own if you want the others to run now. This is a change from 0.9.14, where such a job started and failed.

Engine 0.7.0 is not released. When a machine runs it, the server tells apart what is still missing, and each message has the fix in it:

| Held because | The schedule page says |
|---|---|
| The machine is not set up for administrator tests | `Schedule "NAME" is waiting: this system can't run tests that need administrator rights yet.` and the one-time setup for that machine. |
| Nobody at the machine has accepted the test | `Schedule "NAME" is waiting: someone at this machine has to accept TEST VERSION once.` and how: the tray offers it, or `benchy-engine machine-accept`. |
| The last run was put off by the agent | `Schedule "NAME" is waiting:` followed by why: nobody approved it at the machine, the machine is not set up, or nobody has accepted it. It is offered again when the cause changes, and in any case after at most six hours. |

### Who can schedule them

Only a Group Administrator (level 1) of the account can add one of these tests to a schedule or change its version. On a schedule that holds one, only a Group Administrator can change the systems and groups it runs on, its triggers and times, switch it on or off, run it now or delete it. Putting a machine into a group that such a schedule runs on counts as changing the schedule. Taking an administrator test out of a schedule is open to level 2, because it only narrows what runs. An Administrator or Power User sees the buttons greyed out, with the words "An account administrator has to change this, because the schedule runs tests that need administrator rights." A forged form changes nothing. Each change is written to the account's audit.

## The audit

The server audit (the **Server audit** page) gets two new entries: `catalog_imported` for every successful import, repeat imports and repairs included, and `catalog_import_refused` for every refusal. Each says whether it came from the command line, the web page or the package, and the release numbers. Accounts do not see these.

An account's own audit records the schedule side. `native_test_add` now carries the version when a test is pinned. `native_test_version_set` records a pin being set, changed or removed, with the old and the new version. `admin_schedule_changed` records every change to a schedule that holds an administrator test: which field changed, from what and to what.

## Going back to 0.9.14 or 0.9.13

The upgrade adds tables, moves the database to schema 63, and the first import adds a `catalog/` folder inside the storage folder (see [the upgrade notes](new-server.md#upgrading-to-0915-the-signed-test-catalog-and-the-no-phone-home-fixes)). Going back is code only. Older releases do not know the tables and leave them alone, and when you upgrade again they are as you left them.

While you are on the older release the catalog is not offered. Machines keep what they already have and run what they already installed, but a test they have not installed yet cannot be fetched from the server and that job fails.

Version pins are ignored. The older server sends no version, so each machine runs its newest. The result records the version that ran, but a schedule you pinned will not behave as pinned until you upgrade again. If results must stay comparable, do not roll back without taking pins off first.

A held test that needs administrator rights is no longer held either: 0.9.14 and 0.9.13 do not know about holds, so such a job is sent and the agent refuses it with a message.

## Limits

- Only machines with an engine of 0.6.0 or later use the catalog or pins.
- No test that needs administrator rights runs from a schedule yet; see above.
- A server cannot add its own tests to the signed catalog. A private test repository is separate and unchanged.
- Suites in the catalog are listed, but there are none in the first release.
