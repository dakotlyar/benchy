# Your first benchmark

This page takes you from a fresh install to one saved result, with every question Benchy asks along the way. We use `pts/sysbench`, a well-known test of processor and memory speed. It needs a compiler on the machine, builds in under a minute, and runs for a few minutes. The output below was captured on Benchy 0.9.3 on Linux (the command line has changed very little since, and not in this run); yours will differ in the numbers and in the details of your hardware.

## 1. Look at the test first

Before installing anything, you can ask Benchy about a test:

    benchy info pts/sysbench

You get the test's full name, its version (`pts/sysbench-1.1.0`), what it measures, how big the download is, an estimate of how long it takes, and the system packages it needs:

    Sysbench 1.0.20

    Run Identifier:          pts/sysbench-1.1.0
    Test Type:               System
    Supported Platforms:     Linux
    Estimated Run-Time:      90 Seconds
    Estimated Install Time:  25 Seconds
    Download Size:           1.44 MB
    Environment Size:        30 MB
    ...
    Test Installed: No

    Software Dependencies:
    - C/C++ Compiler Toolchain
    - Libtool
    - Linux AIO

The estimates are rough. On the machine used for this page the run took about twice as long as Benchy predicted. Treat them as a ballpark.

## 2. Run it

    benchy benchmark pts/sysbench

`benchmark` does everything in one go: it installs the test if needed, then runs it. (The `install` and `run` commands do the two halves separately.) The sections below are what you will see, in order.

### Installing what the test needs

If the test needs system packages you do not have, Benchy lists them and installs them with your distribution's package manager:

    The following dependencies are needed and will be installed:

    - build-essential
    - autoconf
    - libtool
    - libaio-dev
    ...

You will be asked for the root password (or your `sudo` password) at this point, because installing packages needs it. If Benchy cannot install them, it says which are still missing and offers four choices: ignore and carry on, skip the tests that need them, try again, or quit. Install the packages yourself in another window and choose "try again". See [When something goes wrong](troubleshooting.md#there-are-dependencies-still-missing-from-the-system).

Then it downloads and builds the test:

    pts/sysbench-1.1.0:
        Test Installation 1 of 1
        1 File Needed [1.44 MB]
        Downloading: sysbench-1.0.20.tar.gz                         [1.44MB]
        Approximate Install Size: 30 MB
        Estimated Test Install Time: 25 Seconds
        Installing Test @ 13:20:59

Tests are installed once and stay installed, in `~/.benchy/installed-tests/`. The next run skips this step.

### Choosing the options

Most tests have options. Sysbench asks which part you want:

    Sysbench 1.0.20:
        pts/sysbench-1.1.0
        System Test Configuration
            1: CPU
            2: RAM / Memory
            3: Test All Options
            ** Multiple items can be selected, delimit by a comma. **
            Test:

Type a number and press Enter. `1` is the processor test. Typing `1,2` runs both.

Benchy then prints a *System Information* table, the same one `benchy system-info` gives you. Benchy saves it with the result so that you can tell later what machine and software produced it.

### Saving the result

    Would you like to save these test results (Y/n):

Press Enter for yes. Then it asks three things:

| Question | What to type |
|---|---|
| Enter a name for the result file | A name for this whole result, such as `kitchen-laptop`. It becomes a folder under `~/.benchy/test-results/`. It cannot be the name of a test, and it can be at most 126 characters. |
| Enter a unique name to describe this test run / configuration | A label for *this particular run*, such as `stock-settings` or `after-bios-update`. When you later add a second run to the same result, this label is what tells the two apart in the graphs. |
| New Description | Optional free text. Press Enter to keep the one Benchy wrote for you. |

Pick the labels with the comparison in mind. A result called `kitchen-laptop` that holds runs called `stock` and `performance-governor` reads better than two results with unrelated names.

### The run

Benchy may print a performance tip first (for instance, that your processor's frequency governor is not set to *performance*), and waits five seconds. Press Ctrl+C in that window if you want to stop and change it. Then the test runs, usually three times:

    Sysbench 1.0.20:
        pts/sysbench-1.1.0 [Test: CPU]
        Test 1 of 1
        Estimated Trial Run Count:    3
        Estimated Time To Completion: 2 Minutes [13:23 UTC]
            Started Run 1 @ 13:21:45
            Started Run 2 @ 13:23:19
            Started Run 3 @ 13:24:53

        Test: CPU:
            71642.13
            70898.21
            70759.73

        Average: 71100.02 Events Per Second
        Deviation: 0.67%

The three numbers are the three runs. The *average* is the result. The *deviation* says how much the runs disagreed with each other: under a few percent is healthy. If a test is noisy, Benchy may add extra runs on its own to settle it. Leave the machine alone while it runs; moving windows or starting other work spoils the numbers.

### The end

    Do you want to view the text results of the testing (Y/n):
    Would you like to upload the results to OpenBenchmarking.org (y/n):

The first question shows the result as a small text chart. The second is about the *public* OpenBenchmarking.org website, which is not your Phoromatic server. **Answer `n`** unless you really mean to publish the result there for anyone to see. When you run tests by hand like this, Benchy asks every time.

Your result is now saved. To see that:

    benchy list-saved-results

    1 Saved Results

    kitchen-laptop kitchen-laptop
        - stock-settings

The next chapter, [Finding and running tests](finding-and-running-tests.md), shows how to pick tests, and [Reading and comparing results](results.md) shows how to add a second run to this result and compare.

## On Windows

The steps are the same. Run the commands in "Benchy Command Prompt", or in any terminal opened after the install. The first time a test needs a Unix tool, Benchy sets up Cygwin. Only about a hundred tests exist for Windows; if `benchy info` for a test says `Supported Platforms: Linux`, that test will not run on Windows.
