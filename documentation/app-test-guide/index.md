# Writing a benchmark for a desktop application

This guide is for someone who wants to measure a desktop application with Benchy on Windows: a photo editor, an office suite, a CAD program, anything with a window that a person works in. You know the application. You do not need to know how Benchy works inside. By the end you will have a test folder that installs the application, does a fixed set of real jobs in it, times each one until the window has drawn the result, checks that each job really happened, and turns the times into scores that can be compared between machines.

Every page uses one real example, the PhotoCraft Photo Editing test. PhotoCraft is a free layered photo editor. The test opens a large layered PSD file and does nine typical editing jobs on it: a luminosity mask, a content-aware fill, three Curves layers, a smart Gaussian blur, eight free transforms, layer styles on ten shapes, forty text layers and a save. It was built with Benchy's test authoring kit in Build mode, so the screenshots are of the real thing, wrong turns included.

The guide was written against Benchy 0.16.2 (engine 0.14.1, client 0.17.1) and checked again against Benchy 0.16.4 (engine 0.14.2, client 0.17.2), on Windows 11. Some screenshots were taken with the earlier client; the one in the Add download step shows "Licence", which the client now spells "License". Linux is not covered; see the end of this page.

## The pages

| Page | What it covers |
|---|---|
| [What an application test is](what-is-an-app-test.md) | When to write one, and when a command-line or built-in benchmark is the better choice |
| [Planning the test](planning.md) | Choosing tasks, driving the application, timing, proving each task happened, trials and spread |
| [Making a fixture file](fixture.md) | Generating a large, repeatable document from a recipe instead of shipping it |
| [Building the test in Build mode](build-it.md) | The wizard, the files it writes, and each edit, step by step |
| [Validate, try-run, full run](validate-and-run.md) | Checking the test, running it, reading the spread, setting timeouts and baselines |
| [Review and hardening](review-and-harden.md) | What a second pair of eyes found, and the habits that fix those problems early |
| [Corporate machines](corporate-machines.md) | Antivirus that blocks scripts, privilege-management tools and slow first launches |
| [Scoring notes](scoring.md) | How Overall is computed, and what to do with noisy small tasks |
| [Troubleshooting](troubleshooting.md) | Symptom, cause and fix for every wrong turn in the PhotoCraft build |

If you have never written a Benchy test, read the short [test profile page](../test-profile-creation.md) for the classic format first; it explains what a test is in Benchy's older, Phoronix-derived form. The authoring kit used here is the newer path, and its commands (`benchy-engine test validate`, `benchy-engine test run`) are the ones this guide uses. <!-- unverified: there is no authoring-kit reference page in the repository yet; the kit's design notes are the only full description, so this guide links to none -->

## What you need

You need a Windows 11 machine with a signed-in desktop session (not a service, not a remote session, not a locked screen), Benchy 0.16.2 or newer, and the application you want to measure. The application should be one you can install without an installer that needs an administrator, ideally a portable zip, because the test will install its own copy. Plan on a scratch folder outside the Benchy install for the test itself; the PhotoCraft example lives in `C:\bench\photocraft-bench`.

A virtual machine is fine for building the test and a good place to make your mistakes. It is not a good place to measure speed. The example's first baselines came from a virtual machine with no graphics card, and they say so in the file.

## The shape of the finished test

The PhotoCraft test is a folder of ordinary files. This is the whole list, and each one gets its own section in the pages that follow.

| File | What it is for |
|---|---|
| `benchy-test.toml` | The test's name, description, trial settings and the list of results it produces |
| `files.toml` | The downloads the test needs, pinned by size and checksum |
| `install.ps1` | Unpacks the application, builds the fixture and prepares the settings every trial starts from |
| `check.ps1` | Says what is missing (disk, memory, a usable desktop) before anything runs |
| `run.ps1` | One trial: start the app, do the tasks, time them, check them, report |
| `photocraft-bridge.ps1` | A small helper that talks to the application on behalf of `run.ps1` |
| `baselines.toml` | The reference times the scores are measured against |
| `PROVENANCE.toml`, `README.md`, `.gitattributes` | Where the test came from, a description for people, line-ending rules |
| `psd-gen/` | The recipe for the fixture document, and the numbers it must match |

## Linux

The "Desktop app, timed in its window" template is Windows only in Benchy 0.16.2 (the template card says so, and the wizard greys out Linux for it). Linux support for the PhotoCraft test is planned as separate work after this guide. Nothing here promises a release for it, and the pages that follow describe only what runs on Windows today.
