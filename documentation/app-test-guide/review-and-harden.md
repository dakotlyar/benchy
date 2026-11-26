# Review and hardening

A test that passes a try-run is not finished. A try-run shows that the test works on the machine you built it on, on a good day. It does not show what happens when the machine is slow, when the application answers late, when a field is missing from a reply, or when a check was too weak to notice that the work was not done. Have someone who did not write the test read it, and fix what they find before it spreads.

This page lists what a review of the PhotoCraft test found after its first successful runs. Each problem is a pattern you can look for in your own test.

## Timeouts that fire in the right order

The engine stops an install or a trial that runs longer than `timeout_s`, and says little about why. The first PhotoCraft test had `timeout_s = 300`. But the install alone is a minute of unpacking, up to nine minutes of generating the document on a slow machine, a check, and the settings step, so a slow machine would have been cut off with no message about what was slow.

The fix was to raise `timeout_s` to 600 and to give each long step in `install.ps1` its own, shorter limit and its own message, so that the script's limit fires before the engine's:

```powershell
# One generous limit per step, so each one can fire (with its own message) before the engine's 10 minute limit on the
# whole install (timeout_s) stops everything with nothing to say: unpack, generate (6 min), check (2 min), settings.
$generateSeconds = 360
$checkSeconds = 120
```

Do the same for anything in your test that waits on another program: put a limit on the wait, and make the message say what was being waited for.

## Control calls that cannot wait for ever

The helper that sent commands to PhotoCraft waited up to 300 seconds for a reply. But the application drops a reply that takes more than 60 seconds, so a dropped call would have waited out the whole trial. Every call now waits at most 90 seconds, counted over the whole call, and the error says which step it was:

    Open PSD (app.open) did not answer within 90 s

Anything that really takes longer is run as a job and polled, as described in the [planning page](planning.md). The poll has its own guard: if the application removes a finished job from its list between two polls, the helper would otherwise keep asking for ever. A job that is missing for three polls in a row now fails with a clear message.

## Guard every read of a reply

Benchy runs PowerShell 5.1 for Windows tests. The test scripts turn on strict mode, which makes reading a property that does not exist an error. The application leaves some fields out of some replies (`perf`, `jobs.running`, `hasMask`, `gpuFallbackNotice`), and under strict mode a missing field failed a trial that had otherwise finished. Two small helpers fix it. One reads a field by path and returns nothing if any step is missing:

```powershell
function Get-PcField {
    param($Object, [Parameter(Mandatory = $true)][string[]]$Path)
    $cur = $Object
    foreach ($key in $Path) {
        if ($null -eq $cur) { return $null }
        if (-not ($cur.PSObject.Properties.Name -contains $key)) { return $null }
        $cur = $cur.$key
    }
    $cur
}
```

The other returns a list, empty if there is none:

```powershell
function Get-PcItems {
    param($Value)
    if ($null -eq $Value) { return @() }
    return @($Value)
}
```

There is a second trap in the same area. In PowerShell 5.1, `@($null).Count` is 1, not 0, so counting an item that might be missing gives the wrong answer. The helper's own comment says callers must wrap it: `@(Get-PcItems $x).Count`. The [troubleshooting page](troubleshooting.md) has the story of the first version of this helper, which broke iteration.

## Make the checks hard to pass by accident

The first checks passed too easily. The Text task only counted new layers. The Free Transform task only asked whether the layer's bounds had changed. Both were tightened:

1. Text now looks at each of the forty layers. Each must have its own name exactly once, be a Type layer, and carry exactly one enabled stroke.
2. Free Transform now computes what the answer should be. Every panel starts as a 1200 by 900 rectangle, two were already scaled and turned when the file was made, and the expected size after a 140 percent scale and a 17 degree turn is the bounding box of the turned rectangle:

```powershell
function Get-PcRotatedSize {
    param([double]$W, [double]$H, [double]$Scale, [double]$Degrees)
    $t = [Math]::Abs($Degrees) * [Math]::PI / 180
    @(($Scale * ($W * [Math]::Cos($t) + $H * [Math]::Sin($t))), ($Scale * ($W * [Math]::Sin($t) + $H * [Math]::Cos($t))))
}
```

The size after the task must match within 3 percent and the center must stay within 15 pixels. A missing scale, a missing turn or an untouched layer each miss that by far more. Before relying on the formula, the author checked it against the real bounds of all eight panels on the build machine, and it fitted within 1 percent. Do that too: a check that is wrong in the other direction fails good trials.

## Check the picture, not the bytes

The first install failed if the fixture's SHA-256 was not exactly the pinned one. That is the wrong test: the same picture can be written in different bytes by another build of the application, and the install would fail on a machine that was fine. It now checks sizes, counts and the checksum of a render of the composite. [Making a fixture file](fixture.md) has the details.

## Say how the application drew

PhotoCraft draws on the GPU where there is one and on the CPU where there is not. The same test on the two paths measures different things, so every result now carries information lines that say which it was (`PhotoCraft render path` and `PhotoCraft graphics adapter`). The renderer never fails a trial on its own. But if it changes between the trials of one run, say the GPU is lost halfway, the trial fails with a message, because the numbers could no longer be compared. The first trial of a run writes a small reference file into the install folder, later trials compare against it, and the file is ignored after 30 minutes so that an older run on other hardware is not held against a new one.

Your application may have the same property. Whatever can differ between machines and would change the numbers, record it in the result.

## Small things

The review also found a handful of small things worth looking for in any test:

1. `check.ps1` printed an error with no text if Windows would not say how much memory the computer has. It now prints a `BENCHY-MISSING` line that says so and what to restart.
2. The token file the helper used to log in was left behind. It is now deleted in `finally`.
3. A few variables were left over from earlier drafts. Dead code in a test is a trap for the next person.
4. `PROVENANCE.toml` still had the wizard's "Replace each TODO" header, and the license was written from memory. It was rewritten from the project's own license files.
5. The `README.md` and the description said different things about the renderer. Read all the prose against the final behavior when you are done.
6. Early helper scripts that were used only while building can still be in the folder. PhotoCraft's `psd-gen` folder still has two calibration helpers, `pcc.ps1` and `launch.ps1`. They are not used by the test, and they open the same kind of connection that the helper had to stop opening (see the next page). Delete scaffolding before you hand a test on.
