# Things worth knowing

This page collects the parts of Benchy that you do not need on day one but should know exist: what Benchy sends out over the network, what happens when two runs collide, the machine-readable output mode used by scripts and the desktop client, and what a "test bench" is when you see one in the server's web pages.

## What Benchy does and does not send out

The Phoronix Test Suite that Benchy is built on reports some anonymous information to OpenBenchmarking.org by default. Benchy does not. Since 0.7.4 a setting called `NoPhoneHome` is on by default, and while it is on Benchy:

- sends no anonymous usage reports (which tests you install and run, how long they took, install and download failures);
- asks for no "global system ID" and sends none, so nothing identifies your machine to OpenBenchmarking.org from one run to the next;
- does not load the `ob_auto_compare` module, which fetches comparison data in the background;
- never uploads a result to OpenBenchmarking.org by itself, whatever your batch-mode or "always upload" settings say. It asks every time, and you can say no.

What still goes out is what Benchy cannot work without: it downloads the list of available tests and each test's description and files from OpenBenchmarking.org. Those requests carry no system ID. A result you upload on purpose with `benchy upload-result` also goes there, since that is what the command is for.

The setting lives in `~/.benchy/user-config.xml` as `NoPhoneHome`. If a client that predates 0.7.4 was upgraded, it prints this once and then stays quiet:

    Benchy no longer reports to openbenchmarking.org (Options/OpenBenchmarking/NoPhoneHome).

You can turn it off with `benchy user-config-set NoPhoneHome=FALSE`, but there is rarely a reason to. If your machine is connected to a Phoromatic server whose administrator forbids phoning home (the default for the house server), the setting is forced on whatever your file says, and Benchy tells you so: `NoPhoneHome is FALSE in your configuration, but your Phoromatic Server forbids phoning home, so it stays on.`

To go further and stop Benchy using the network at all, set `NoInternetCommunication` to `TRUE` in `user-config.xml`. Tests then have to be installed from files you already have (`benchy make-download-cache` prepares them while you are online). The related `NoNetworkCommunication` switch also blocks contact with Phoromatic servers, so leave it alone if you send results to a Phoromatic server.

## One run at a time: the run lock

Two benchmarks running together on one machine spoil each other's numbers. Since 0.9.0, `benchmark` and `install` take a lock in your `.benchy` folder for as long as they run, so one user can have only one going at a time.

- If you start a second run from a terminal, Benchy warns but goes ahead:

        WARNING: another Benchy benchmark run is in progress on this user path (PID 4242); results of this run may be affected.

  Take the warning seriously. Stop the second run unless you do not care about its numbers.
- If a second run is started from the desktop client or by a script using JSON mode, it is refused with a "locked" error instead.
- A run killed without warning, even with `kill -9`, releases the lock on its own. You will never have to delete a lock file by hand.

The lock is per user. Two different accounts on one machine do not see each other's locks, so if you share a machine, tell the other person.

## JSON mode

Putting `--json` first on the command line makes Benchy print its progress as one JSON object per line instead of the human-readable text, and refuse to ask questions. It is how the Windows desktop client talks to Benchy, and it is the way to script Benchy from another program:

    benchy --json hello

    {"v":1,"type":"hello","seq":1,...,"engine":{"name":"benchy-pts","version":"0.9.10"},"protocol":1,"capabilities":["cancel","prompt_answers","private_repo","json_args","run_lock","no_phone_home"],...}
    {"v":1,"type":"done","seq":2,...,"status":"ok","exit_code":0}

Every run starts with a `hello` line and ends with a `done` line that carries the exit code. The `capabilities` list tells a program what this Benchy can do; `run_lock` and `no_phone_home` are the two described above. In JSON mode every question that Benchy would ask is a command-line flag instead (`--name`, `--identifier`, `--defaults` and so on), and a missing answer ends the run with an error and exit code 3 rather than waiting.

Only a few commands work in JSON mode: system information, listing tests and suites, `info`, `install`, `benchmark`, showing a result, cancelling a run, and uploading to Phoromatic. If you only use the terminal you can ignore all this. Programmers should read [`json-events.md`](../json-events.md), which is the full reference.

## Test benches

If you use test rigs managed through a Phoromatic server you may see **Benches** in the server's menu. A bench is a physical machine set up for comparing a *part*, such as a processor or a motherboard that gets swapped in and out. The server records which part is in the bench at any time (a "session"), so that results are filed against the part and not just the machine. A bench can hold several systems, so a dual-boot rig appears as one bench with a Linux side and a Windows side. A swap can involve up to four parts at once, for example a processor, a board and a cooler.

For an everyday desktop or laptop you can ignore benches. If your machine is on one:

- Swaps are declared on the server's web page. The `bench-swap` command on the machine only handles a single part.
- The commands `bench-status`, `bench-login`, `bench-swap`, `bench-end` and `bench-logout` exist for the machine itself. `benchy bench-status` needs no password and shows which bench the machine belongs to, its open session and its component. The others need a *bench token* that the administrator creates on the bench's page in the server; `benchy bench-login` saves it for you (type or paste it; it is not echoed).
- While a swap is waiting for new hardware to appear, the bench holds its tests until Benchy detects the new part.

The administrator's documentation, not this one, describes setting benches up.
