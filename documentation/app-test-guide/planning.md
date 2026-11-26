# Planning the test

Most of the work in an application test is decided before you open Build mode. This page walks through the decisions in the order PhotoCraft's author made them. None of them needs a line of code yet, but each one is expensive to change later, because the task list and the baselines are frozen together.

## Choose the tasks

Think of what a person does in the application on a normal day, and pick jobs that take real effort from the program. Skip jobs that finish instantly however hard you push. Each task becomes one measurement, so the list is also the list of numbers people will read.

PhotoCraft's list came from asking what a photo editor does to a big layered document:

| Task | What the program has to do |
|---|---|
| Open PSD | Decode a 6000x4000, 113-layer file and draw it once, fitted to the window |
| Luminosity Mask | Build a whole-canvas selection from the picture and turn it into a layer mask |
| Content-Aware Fill | Fill a large selected area with patch-based inpainting |
| Curves Adjustment | Add three full-canvas adjustment layers, each redrawing the whole stack |
| Smart Gaussian Blur | Turn a layer into a smart object and give it a live blur |
| Free Transform | Scale eight panels to 140% and turn them 17 degrees |
| Layer Style | Add a drop shadow, a stroke and a bevel to ten shapes |
| Text | Create forty text layers, each with an outline |
| Save PSD | Composite the image and write the layered file, about half a gigabyte |

Starting the application is also timed, as information only. The reason is in the [scoring notes](scoring.md).

### Make each task heavy enough

A task that takes a tenth of a second is mostly noise: a hiccup of a few milliseconds is a large fraction of it. The PhotoCraft author's rule was that every timed task should take at least about three seconds on the build machine, and the light ones repeat their work inside the timer to get there. That is why Free Transform does eight panels, Layer Style does ten shapes and Text makes forty layers. The repeat counts are written at the top of `run.ps1`:

```powershell
# How much work each task does. These numbers are part of the test: changing one changes what is
# measured, so they are frozen with baselines.toml.
$CafBox = @(2250, 1950, 3450, 3550)     # the lasso around the signpost: x0, y0, x1, y1
$CurvesLayers = 3
$PanelCount = 8                          # Panel 01 to 08, each scaled 140% and turned 17 degrees
$StyledFirst = 6; $StyledLast = 15       # Shape 06 to 15 get a drop shadow, a stroke and a bevel
$TextLayers = 40
```

Do not guess the counts. Run each task once by hand and write down the time, then choose counts and freeze them. PhotoCraft's first calibration showed that Text, layer styles and transforms were far too short at their first counts (0.9 seconds for ten text layers, about 0.4 seconds for each styled layer), and that the Curves layers were the heaviest thing in the test at 15 seconds for three.

## Drive the application without clicking

A test that clicks buttons breaks when the window is a different size, the screen has a different scale, or the program moves a menu in a new version. Prefer anything the application offers for being driven from outside, and look for it before you plan anything else:

1. A command or automation interface that runs the same commands the menus run (a control port, a scripting engine, COM, a command-line mode that works on the open window).
2. A documented way to ask the program what state it is in, so you can check that a task worked.
3. Flags that keep it from putting dialogs, updates or recovery prompts on screen.

PhotoCraft has all three. Started with `--control <port>` and a token file, it listens on a port on the local machine and accepts its own engine commands, one per request: `engine.execute` runs any command the menus can, `app.open` and `app.save` handle files, `jobs.list` shows running jobs, `ui.inspect` describes the window, and `prefs.set` changes preferences. The test sends the same commands a person would trigger, so nothing depends on where a button is.

The one place PhotoCraft needed a click anyway is a box that says it switched to CPU rendering, on machines without a usable graphics card. The box does not appear in the list of dialogs the app reports, and the Escape key sent through the channel does not close it. The test clicks its "Keep Using CPU" button at a fixed offset from the middle of the window, before the first timed task. That is the only pixel click in the test, and it is outside every timer; a box that stays open does not fail the trial.

If your application has no way to be driven except the keyboard and the mouse, you can still write the test, but expect to spend most of your time on the fragility. Look hard for a second way first.

## Time the work and the redraw

The clock for a task should stop when the user would see the result, which means after the window has drawn it. A command that returns instantly while the screen is still catching up would flatter the machine.

PhotoCraft handles control requests in its frame update. So the reply to even a trivial request arrives only after the window has drawn a frame. The test uses that: after each task it sends one cheap request, `jobs.list`, and the reply proves a frame was drawn after the edit. The helper is one line:

```powershell
function Sync-PcFrame {
    [void](Invoke-Pc -Method 'jobs.list' -What 'a frame sync')
}
```

Without it the redraw cost lands on the next request instead. During calibration a plain `layer.select` took five seconds right after the three Curves layers, because that was when the window finally caught up.

Every task in `run.ps1` goes through one function that gives it the same shape: a setup that is not timed, the work that is timed, and a check that is not timed.

```powershell
function Invoke-PcTask {
    param(
        [Parameter(Mandatory = $true)][string]$Key, [Parameter(Mandatory = $true)][string]$Title,
        [scriptblock]$Setup = {}, [Parameter(Mandatory = $true)][scriptblock]$Work, [Parameter(Mandatory = $true)][scriptblock]$Check
    )
    & $Setup
    Sync-PcFrame
    Measure-BenchyTask -Group 'Editing' -Task $Key -Handle $script:Handle -AppName 'PhotoCraft' -ScriptBlock {
        $sw = [System.Diagnostics.Stopwatch]::StartNew()
        & $Work
        Sync-PcFrame
        $script:Seconds[$Title] = $sw.Elapsed.TotalSeconds
    }
    & $Check
}
```

`Measure-BenchyTask` is Benchy's helper for desktop tests. It runs the block and fails the trial if any key or mouse input arrives while it runs. Setup is where you select the layer the task will work on, so the selection is not part of the time. Work is only the thing you want to measure, and the frame sync inside the block means the stopwatch stops after the redraw.

### Jobs that run longer than the app waits

PhotoCraft gives up on a reply after 60 seconds. A content-aware fill or a save on a slow machine can take longer. So long commands are started with `wait:false`, which returns a job number at once, and the test polls `jobs.list` every 25 milliseconds until the job says it is done, failed or cancelled. If your application has a similar limit, the test needs the same pattern, or a slow machine will time out on a job that was working fine.

## Prove each task happened

A job that silently does nothing is the worst thing an application test can do, because it posts a very fast time. So after each task, outside the timer, the script reads the document back and fails the trial if the edit is not there. The Layer Style check counts the effects on every shape and expects exactly three more than before:

```powershell
    } -Check {
        foreach ($s in $script:Shapes) {
            $now = @(Get-PcItems (Get-PcField (Find-PcLayer $s.name) 'effects', 'items')).Count
            Assert-Pc ($now -eq $script:Fx[$s.name] + 3) ($s.name + ' has ' + $now + ' effects after the task, expected ' + ($script:Fx[$s.name] + 3) + '.')
        }
    }
```

Good checks are specific. "Some layers exist" is weaker than "layer Bench Text 17 is a Type layer and has exactly one enabled stroke". [Review and hardening](review-and-harden.md) shows how PhotoCraft's checks got stronger after a reviewer found weak ones.

## Make the starting point identical every time

Results are only comparable if every trial begins from the same place. The PhotoCraft test does this in four ways:

1. The document is the same file every time, built from a fixed recipe. [Making a fixture file](fixture.md) explains how.
2. The application's own settings live in a folder inside the trial's run folder, copied fresh from a seed made at install, and never touch the user's own settings.
3. The seed turns off everything that could put something on the canvas: the update check, autosave and recovery on launch.
4. Everything with a choice in it is fixed: the window is 1280x720 at the top left, the content-aware fill uses `seed: 1`, and text uses a font that ships inside the application, not one the machine might lack.

## Trials, warm-up and spread

One run of a task is one sample, and one sample is not a measurement. The `[trials]` block of `benchy-test.toml` says how many times Benchy repeats the trial:

```toml
[trials]
count = 3
warmup = 1       # the first visible start of an application is a cold-cache outlier
cooldown_s = 5   # a pause between trials
target_cv_pct = 5
```

The warm-up trial runs first and is not counted: the first start of an application reads everything from disk. Three scored trials follow, with a five-second pause between them. After the trials Benchy reports each result's coefficient of variation, CV: the spread of the samples divided by their mean, in percent. A CV of 5 means a typical trial is within about five percent of the average. `target_cv_pct = 5` is the line a result has to stay under to be called steady. [Validate, try-run, full run](validate-and-run.md) shows what that looks like on screen, and what to do when a task is over the line.
