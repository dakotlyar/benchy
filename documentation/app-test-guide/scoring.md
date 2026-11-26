# Scoring notes

A test produces two kinds of numbers. The seconds each task took are what a person wants to read. The scores turn those seconds into points, so that machines can be ranked and a result can go on a chart. This page explains how the points are worked out, and the choices PhotoCraft made about which tasks count.

## How a score is made

`baselines.toml` holds the time a reference machine took for each scored task. A task's score is its baseline divided by the time measured, times 1000. A machine as fast as the reference scores 1000 on that task, a machine twice as fast scores 2000, and one twice as slow scores 500.

A group scores the harmonic mean of its tasks, and Overall scores the harmonic mean of the groups. The harmonic mean is the average that a slow result pulls down hardest. One task that takes much longer than it should cannot be hidden behind eight fast ones.

PhotoCraft has a single group, `Editing`, so its Editing score and its Overall score are the same number, as the screenshot on the [validate page](validate-and-run.md) shows (956 and 956). The wizard adds Overall itself, and a test with several groups, such as separate tasks for opening, editing and saving, would show a score for each group and an Overall across them.

Both scores are computed inside the trial by one call at the end of `run.ps1`, `Write-BenchyScores`, from the tasks that `Measure-BenchyTask` timed and the baselines in the file. Each task also prints a line like `task.Editing.OpenPsd` in the information lines with the measured time and the score, which is a quick way to see which task is dragging Overall down.

## There are no per-task weights

Overall in this kit is a plain harmonic mean. Every scored task counts the same. There is no setting to make one task count half as much or twice as much; `baselines.toml` accepts only a group, a key and a baseline for each task.

That matters when a task is much shorter than the others. PhotoCraft's Launch task, starting the application until it is ready, takes about 0.3 seconds. The Curves task takes about 15. In a plain harmonic mean each of them is one task among ten, so they count the same. A start-up that wobbles by a few hundredths of a second, which is a large fraction of 0.3 seconds, then moves Overall as much as a 15 second job that changes by a large fraction of its time. The author first asked for Launch to count at half weight. The kit cannot do that, and faking it in the script would have made the numbers differ from the ones the kit reports for the same results. So the choice was between counting Launch fully and not counting it at all.

## Report a noisy small task as information

PhotoCraft chose not to count Launch. It is still measured, and still reported, but as an information line, `Launch (s)`, the way a game test reports a frame-time figure it does not score. To do the same for any small task:

1. Do not declare a `[[metrics]]` entry for it in `benchy-test.toml`.
2. Do not list it in `baselines.toml`. The kit rejects a score report that has a task with no baseline, so the task also must not be timed by `Measure-BenchyTask`, which would print a task line.
3. Time it with a plain stopwatch in `run.ps1`, and write the number with `Write-BenchyInfo`.

The comment in `run.ps1` records the reasoning for the next person:

```powershell
    # 1. Launch: from starting the program until the window is up, the control port answers, and nothing is
    # waiting on the canvas (no dialog, no job). Launch is reported as information only: it is no BENCHY-TASK, has no
    # baseline and is not in Overall (the kit has no per-task weights, and one start-up of 0.3 s should not count as
    # much as a 15 s job). The input guard of Measure-BenchyTask is not used; nothing is open to click yet.
```

and the report line at the end is:

```powershell
    Write-BenchyInfo -Key 'Launch (s)' -Value ([string][Math]::Round($script:Seconds['Launch'], 3))
```

The result is that PhotoCraft's nine scored tasks all take more than about three seconds, and Launch is one line of information in every result. The side effect is that Overall is a little higher than it was when Launch was counted, because the start-up scored about 980 or less while the others sat nearer 960 to 1000. Pick the scored set before you freeze the baselines. Moving a task in or out later is a new major version of the test.

This is also what to do with any task that your spread check flags as noisy only because it is small. Do not widen `target_cv_pct` to hide it.

## Keep the seconds too

PhotoCraft declares a seconds metric for every scored task, such as `Open PSD (s)`, beside the scores. The seconds are what people compare most readily ("this machine opens the file in 5 seconds") and what to chart if you want to see which task a machine is good or bad at. Keep both. Scores summarize; seconds explain.

## Read scores with the context beside them

A score is only comparable to another score of the same test, on the same version, drawn the same way. Two things in PhotoCraft's results are there to keep readers honest:

1. The information lines say which renderer drew the canvas, so a score from a machine with a GPU is not read against one from a machine without.
2. The baselines carry their provenance in the `reference` line, and say plainly when they are provisional.

For scale: a single trial on a desktop with a graphics card scored 3664.5 against the provisional baselines, and one on a managed work machine scored 1127.9. The baselines came from a virtual machine with no graphics card, so scores far above 1000 are expected until baselines from a real GPU machine replace them. <!-- unverified: both numbers are one-trial try-runs taken from the build log, not repeated for this guide -->
