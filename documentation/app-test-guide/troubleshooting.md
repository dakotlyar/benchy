# Troubleshooting

These are the problems that came up while building the PhotoCraft test, in roughly the order they happened. Each one says what you see, why it happens and what to do. Most are not specific to PhotoCraft; they are the kind of thing any application test runs into.

## Building the fixture

### The application refuses the document, or warns about memory

You see: the application will not open your generated file, and says that the estimated memory is above a limit (PhotoCraft said 2,596,462,368 bytes against 2,571,327,488).

Why: the application sizes a document from every layer's rectangle, plus a workspace as big as the biggest layer. Layers that cover the whole canvas are expensive, even if they only hold a flat color.

Fix: make layers only as large as their content, and replace full-canvas fills with shapes. Aim for the memory limit of the smallest machine you want to support, not of the machine you built on. [Making a fixture file](fixture.md) has the numbers.

### A warning that a font is not installed

You see: opening the fixture warns `font 'Deja Vu Serif' is not installed`.

Why: the font was a system font on the machine where the recipe was written, and not part of the application. A machine without it would lay out the text differently.

Fix: use only fonts compiled into the application. PhotoCraft's captions use Inter.

### The fixture is not byte for byte the same on two platforms

You see: Windows and Linux builds of the same recipe give files of the same size and different checksums.

Why: the application writes the same pixels and layers in a different order on each platform.

Fix: check the document by its size, its counts and a render of the composite, not by the checksum of the file. Keep the file's checksum as an information line only.

## Building the test

### A box sits over the canvas

You see: a "Switched to CPU rendering" box covers the window, on a machine without a usable graphics card.

Why: the renderer is set to auto, and the application tells the user when it falls back to the CPU. The box does not appear in the application's list of dialogs, and the Escape key sent through the control channel does not close it.

Fix: the test clicks the box's "Keep Using CPU" button after the application starts and before the first timed task, at a fixed offset from the middle of the window (128 points left and 84 down, the same at 1440x900 and 1280x720). The choice is saved only in the trial's own settings folder. Notice that if you set the renderer to CPU in the seed, no box appears, but the test then never uses a GPU. Choose deliberately, and record the choice in the result.

### Validate warns about a connection in the helper script

You see: three warnings in the helper, saying the line is marked `benchy-lint: allow-loopback` but does not match an allowed form.

Why: the checker accepts the marker only on an HTTP address on the local machine. An application whose control channel is plain JSON lines over TCP cannot satisfy it.

Fix: none for that design; the warnings were accepted. Better, move the conversation out of your script entirely, as [Corporate machines](corporate-machines.md) describes, and the warnings go.

### The install fails with "exit code" and no detail

You see: `The install script exited 1: photocraft-cli could not generate the PSD: exit code`, after about 23 seconds.

Why: in Windows PowerShell 5.1, `Start-Process -PassThru` gives no exit code once the process has ended unless the script has read the process object's handle. The script read an empty exit code as a failure even though the generator had worked.

Fix: one line after starting the process:

```powershell
$null = $p.Handle    # without this, Start-Process -PassThru gives no ExitCode once the process has ended
```

Anyone who waits for a child process in PowerShell 5.1 will meet this.

### The trial dies with "A positional parameter cannot be found that accepts argument"

You see: a trial runs fine through several tasks, then stops at one with that message, followed by the text of the task's own `Work` block.

Why: PowerShell variable names are not case sensitive. The script kept its work folder in `$work`, and the task function has a parameter called `-Work`. Inside the function, `$work` meant the script block, and `Join-Path` received a script block instead of a folder.

Fix: rename the variable (it became `$workDir`). The same applies to any variable that shares a name with a parameter of a function it is used in, such as `-Check` or `-Setup`. The error points at the call, not at the variable, so look for names that match. In the same edit, the `catch` block was changed to log the script stack trace, so that the failing line appears in the trial's output:

```powershell
    Write-BenchyLog $_.ScriptStackTrace    # the engine keeps stderr; the line numbers say where it stopped
```

### Every job looks missing, or a list loop runs once

You see: a content-aware fill job "was not in the list" at once, or a loop over a list of replies does one pass with the whole list as the item.

Why: a helper returned its empty list as `,@()` to make `.Count` come out as zero. That fixes counting and breaks iteration, because the comma wraps the array in another array.

Fix: return a plain `@($Value)`, and let callers write `@(Get-PcItems $x).Count`. See [Review and hardening](review-and-harden.md).

### A task passes but nothing happened, or the trial fails on a field that is not there

You see: a time that is far too fast with no error, or a trial that finishes its work and then fails reading a property.

Why: either the check was too weak to tell that nothing changed, or strict mode turned a missing field in a reply into an error.

Fix: strengthen the check ([planning page](planning.md)) and read replies through a helper that returns nothing for a missing field ([Review and hardening](review-and-harden.md)).

### "... did not answer within 90 s"

You see: a message such as `Open PSD (app.open) did not answer within 90 s`.

Why: the application drops a reply that takes more than 60 seconds, or it hung. The step in the message tells you which one was waiting.

Fix: if the step is a long job, start it with `wait:false` and poll for it; if the application hung, run the step by hand and look at what the application shows.

## Running the test

### The install fails in the first request to the application: "the request was not sent"

You see: `OperationStopped: (prefs.set (engi... was not sent`, in the install, on the first run on a machine.

Why: the application was not listening yet. On a managed machine, security software scans a program the first time it starts, and that can take many seconds.

Fix: wait for the application to answer, with a cap and a message, before the first request. See [Corporate machines](corporate-machines.md).

### "This script contains malicious content and has been blocked by your antivirus software"

You see: that sentence when the trial starts, on a managed machine.

Why: the scanner thinks the script looks like malware.

Fix: remove network code, cryptography and read loops from your scripts, and let the application's own command-line tool do the talking. See [Corporate machines](corporate-machines.md).

### "PhotoCraft did not settle within 60 seconds of starting"

You see: the trial stops at Launch with that message, plus the last answer of the bridge.

Why: something is on screen that the test did not expect, such as a dialog, or the application never came up.

Fix: open the application by hand once, in the same session, and look for a prompt. Fix the settings in the seed so that it never appears.

### "PhotoCraft drew with a different renderer in this trial than in trial 0"

You see: a trial fails with a message naming two render paths.

Why: the application changed how it drew between trials of the same run, for example because a graphics driver was reset. The times of the two trials would not be comparable.

Fix: run again. If it repeats, find out why the renderer is changing before trusting any numbers from the machine.

### A trial fails when someone touches the machine

You see: a trial fails during a task, and the output mentions input.

Why: the test fails a trial on any key or mouse input during a task, on purpose.

Fix: keep your hands off while it runs. <!-- unverified: the exact wording of the input failure message was not captured --> Do not run it over a remote session, with a locked screen or from another console session; `check.ps1` reports that as a missing desktop.

### The run says "Not steady"

You see: the badge on the run page says Not steady.

Why: at least one metric has a coefficient of variation above `target_cv_pct`.

Fix: see the list in [Validate, try-run, full run](validate-and-run.md). Tiny tasks are better reported as information ([Scoring notes](scoring.md)).

## Known limits

The PhotoCraft test is Windows only, because the window-timing helpers of the template are. It needs a real desktop session and cannot run as a service. It needs about 5 GB of free disk space and 8 GB of memory. Its baselines are provisional, from a machine with no graphics card, and must be replaced with ones from a real GPU before the test is published. The fixture is written by PhotoCraft's own tool and not by Photoshop. PhotoCraft itself is early software, and a later version may change the commands the test uses, so the download is pinned to 0.23.1. Linux support for the PhotoCraft test is planned and not part of this guide.
