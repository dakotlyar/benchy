# Benchy user guide

Benchy is a benchmarking tool for your own computers. You tell it which test you want, it downloads and builds the test, runs it a few times, and gives you a result you can look at, compare with other runs, and send to a central server. This guide is for someone who sits at a Linux or Windows desktop or laptop and wants to do that, and who is comfortable in a terminal but has never used Benchy, or the Phoronix Test Suite it is built on. By the end you will have installed Benchy, run a benchmark, read and compared results, sent a result to the house server and, if you like, paired the machine so it can take scheduled work from the server on its own.

This guide covers **Benchy 0.9.9**, which on Windows comes with **desktop client 0.10.1** and **engine 0.3.1**, and it also applies to 0.9.4 through 0.9.8 for everything except the Windows installer and tray and the Debian package. It does not cover running the server itself, which is a job for an administrator (see the [administrator's guide](../admin-guide/index.md)), or writing your own tests.

## What Benchy is

Benchy is a fork of the [Phoronix Test Suite](https://www.phoronix-test-suite.com/), renamed. If you already know the Phoronix Test Suite, almost everything carries over: the commands, the tests and the result files are the same. The command is called `benchy` on Linux and `benchy` or `benchy.bat` on Windows. Your settings, downloaded tests and saved results live in a folder called `.benchy` in your home folder.

A few words you will meet:

| Word | What it means |
|---|---|
| Test (or test profile) | One benchmark, such as `pts/sysbench`. Benchy knows several hundred of them. |
| Suite | A named collection of tests that run together, such as `pts/compilation`. |
| Result | What a run produces. A saved result has a name, and can hold several runs side by side so you can compare them. |
| Phoromatic | The result server. Machines send results to it and you read them on its web pages. This guide calls it "your Phoromatic server". |
| Pairing | Introducing a machine to the server once, with a code the administrator makes, so the machine can take scheduled work. See [Pair this machine](pair-this-machine.md). |
| Server agent | A small background program that, once a machine is paired, asks the server for work and runs it. |
| Bench | A Phoromatic term for a physical test rig whose parts get swapped. Most people never need one. See [Things worth knowing](good-to-know.md). |

## The chapters

1. [Installing Benchy](install.md): Linux (the Debian package first), Windows, and how to check it worked.
2. [Your first benchmark](first-benchmark.md): one test from start to finish, with what you will see on screen.
3. [Finding and running tests](finding-and-running-tests.md): searching the catalogue, running several tests, running without questions.
4. [Reading and comparing results](results.md): looking at a result, comparing runs, exporting.
5. [Sending results to your Phoromatic server](phoromatic-server.md): connecting a machine, trusting the server's certificate, uploading, and looking at your results on the web.
6. [Pair this machine and let it take scheduled work](pair-this-machine.md): the tray icon, pairing, start at login, quitting, and forgetting the server before you retire a machine.
7. [Things worth knowing](good-to-know.md): what Benchy does and does not send out, the run lock, JSON mode, and test benches.
8. [When something goes wrong](troubleshooting.md): messages people hit and what to do about them.

## Where to look next

This guide links to the reference pages in this folder rather than repeating them.

| Page | What it is for |
|---|---|
| [`benchy.md`](../benchy.md) | The full command and setting reference, inherited from the Phoronix Test Suite. It predates Benchy and is out of date in places (for example, the settings file and data paths when running as root); where it disagrees with this guide, this guide describes 0.9.8. |
| [`https.md`](../https.md) | How the server's HTTPS works. The "Clients" section is the part that matters to you. |
| [`json-events.md`](../json-events.md) | The machine-readable output mode that the desktop client and scripts use. |
| [`test-profile-creation.md`](../test-profile-creation.md) | Writing your own tests. |

Every command also describes itself: `benchy help` lists them all, and `benchy commands` prints the same list in a plainer form.
