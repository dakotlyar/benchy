# Finding and running tests

Benchy's catalogue holds several hundred tests, and a number of ready-made suites that group them. This page shows how to find the ones you care about, how to run several at once, and how to run without being asked questions, which is how you would script a regular run.

## Browsing the catalogue

| To see | Run |
|---|---|
| Every test Benchy can run on this machine | `benchy list-available-tests` |
| The ready-made suites | `benchy list-available-suites` |
| Tests that other users run most on hardware like yours | `benchy list-recommended-tests` |
| Tests you have installed | `benchy list-installed-tests` |
| What you have run, and how long each took | `benchy list-test-usage` |

`list-available-tests` prints one line per test: the name you type to run it (`pts/aircrack-ng`), its title and what it exercises (Processor, Graphics, System, Disk and so on). The `pts/` part is the repository the test comes from; for the tests in this guide it is always `pts/`.

To find a test by name or topic, search:

    benchy search zstd

    TEST PROFILES
    pts/compress-zstd-1.6.0     Zstd Compression  Processor
    system/compress-zstd-1.5.0  Zstd Compression  Processor

If you see `[NOTICE] Unable to obtain ... from OpenBenchmarking.org` lines while searching, Benchy could not fetch a few test descriptions; the results that matched are still listed.

Suites are named the same way, and run the same way. For instance `pts/compilation` runs the "Timed Code Compilation" tests together. Suites can take hours, so look inside first with `benchy info pts/compilation` and ask for an estimate with `benchy estimate-run-time pts/compilation`.

To find out what one test does, how big it is and what it needs, use `benchy info`, as in [the first benchmark](first-benchmark.md#1-look-at-the-test-first). `benchy list-unsupported-tests` shows the tests that exist but cannot run on your kind of machine, such as Linux-only tests on Windows.

## The signed catalog in the desktop client

From desktop client 0.11.0 (in 0.10.1), when the client uses the native engine it shows the signed catalog that your server (or a file you import) provides. The tests are in a tree on the left of the **Run tests** page, under **Free tests** (anyone may run them), **Non-free tests** (products with their own license terms, which you bring) and **Local tests** (ones on this computer that are not in the catalog), and under **Suites**. The table has a **Version** column. **Search tests** filters it, and **Only tests for this OS** hides the ones that cannot run here.

Select a test and the **Options** panel has a **Version** menu. The newest version is first and marked "Newest"; a version the publisher has withdrawn is shown as "(withdrawn)" with its reason and cannot be queued. Under the menu the panel says what the test needs of you, in plain lines such as "Installs `<product>` for all users of this computer.", "Its tests run as administrator.", "Not installed yet: Benchy installs it during the first run.", "This computer's policy blocks it." and "You accept it when you press Run." (or "Accepted on this computer."). A test that needs administrator rights is accepted once, with Windows' own prompt; see [Scheduled tests that need administrator rights](pair-this-machine.md#scheduled-tests-that-need-administrator-rights).

A status block above the table says what catalog the computer holds. **Refresh** fetches the newest one from the server you are paired with, **Import a catalog file…** takes a `benchy-catalog-*.zip` file instead (for a computer with no server, or no network), and **Details** shows more. When the catalog changed while the page was open, a line says "The catalog changed." with a **Reload** button. On a computer that is not paired, **Refresh** says "This computer isn't paired with a server, so it can't fetch the catalog. Pair it (Server…) or import a catalog file." If the client is using the PHP engine, it shows "Signed catalog tests run on Benchy native. Switch engine to see them." with a **Switch engine** button.

Suites you see here are suites the engine knows on this computer. The suites an administrator builds on the server from catalog tests, and puts in a schedule, are not listed here; the server sends them to the machine as part of a scheduled job.

## Running tests

The command you will use most is:

    benchy benchmark <test or suite> [<test or suite> ...]

It installs whatever is missing and runs everything you named. Give it several names and the results all go into one saved result:

    benchy benchmark pts/sysbench pts/compress-zstd

Related commands, if you want the steps apart:

| Command | What it does |
|---|---|
| `benchy install <test>` | Install only. Good for preparing a machine ahead of time. |
| `benchy run <test>` | Run a test that is already installed. |
| `benchy force-install <test>` | Reinstall it, for example after you installed a compiler. |
| `benchy remove-installed-test <test>` | Delete the test's installed files to free space. |
| `benchy finish-run <result>` | Pick up a run that was interrupted and do the tests that are still missing. |

You can leave out the `pts/` and the version: `benchy benchmark sysbench` works.

If you do not know where to start, `benchy shell` and `benchy interactive` are guided menus that walk you through the same steps. They are meant for people new to Benchy.

## Stopping a run

Press Ctrl+C in the terminal. If the result was being saved, `benchy finish-run <result name>` is the command for picking it up again and running the tests that are still missing. In the desktop client, use its Stop button.

## Running without questions

Interactive prompts are the right way to start, but not for a machine that runs every night. There are two ways to avoid them.

### Defaults for everything: `default-benchmark`

    benchy default-benchmark pts/sysbench

This runs the test with each option set to its default and does not ask you to choose. Benchy still asks whether to save the result and what to call it, unless you answer those in the environment (below).

### Answering in the environment

Benchy reads some settings from environment variables, which is the easiest way to script it. The ones that cover the questions in [the first benchmark](first-benchmark.md) are:

| Variable | Answers |
|---|---|
| `TEST_RESULTS_NAME` | "Enter a name for the result file" |
| `TEST_RESULTS_IDENTIFIER` | "Enter a unique name to describe this test run" |
| `TEST_RESULTS_DESCRIPTION` | "New Description" |
| `FORCE_TIMES_TO_RUN` | How many times to run each test (overrides the test's own number) |

For example, to add a quick extra run to an existing result:

    TEST_RESULTS_NAME=kitchen-laptop TEST_RESULTS_IDENTIFIER=bios-update \
      TEST_RESULTS_DESCRIPTION="After the BIOS update" \
      benchy benchmark kitchen-laptop

Passing a saved result's name (`kitchen-laptop`) instead of a test name re-runs that result's tests with the same options, and adds the new run to it. That is the simplest way to measure "before" and "after", and it is how [comparison](results.md#compare-two-runs) is done. Using the same result name with a new run label also works. Each run in a result needs its own label, so if you reuse one Benchy asks you for another (and if the hardware or software has changed since the earlier run with that label, it says so).

`benchy variables` prints every supported environment variable with its current value, and [`benchy.md`](../benchy.md) describes each in its "Environment Variables" section.

### Batch mode

Batch mode suppresses most of the questions and uses answers you gave once:

    benchy batch-setup
    benchy batch-benchmark pts/sysbench

`batch-setup` asks things like whether to save results, whether to prompt for a name, and whether to run every option combination of a test. When it asks whether to auto-upload results to OpenBenchmarking.org, say no. (With Benchy's default settings that upload is blocked anyway; see [what Benchy sends out](good-to-know.md#what-benchy-does-and-does-not-send-out).)

### Trying things without keeping them

`benchy dry-run <test>` runs a test in batch mode and saves nothing. It is meant for checking that a test works on your machine.

## One run at a time

Benchy only wants one `benchmark` or `install` going at a time per user, because two runs on one machine spoil each other's numbers. If you start a second from a terminal while one is running, it prints a warning and goes on. See [the run lock](good-to-know.md#one-run-at-a-time-the-run-lock).
