# The Settings window, and fixing "paired twice"

Benchy's desktop client has a Settings window where the server agent, the engines, uploads and clean-up live. This page is for someone at a Windows desktop who has paired the machine with a Benchy server and wants to change how it behaves, answer a scheduled test that is waiting for approval, or repair a machine that Benchy says is paired twice. By the end you will know what each Settings page does, what the band at the top of the Benchy window means, and how to fix a double pairing. It covers desktop client 0.12.0 and engine 0.9.0, which come with Benchy 0.11.0.

If you have not paired the machine yet, start with [Pair this machine](pair-this-machine.md). The pairing form is on the Server agent page of the Settings window.

## Open Settings

<!-- unverified: the gear and the tray item opening Settings; only the server chip and --settings were seen on a running Windows client -->
There are four ways in, and each opens Settings on the page it names. The same works from a command line: `BenchyClient --settings` opens Settings on the **Server agent** page, and `--settings=PAGE` opens another page. If Benchy is already running, it opens the window there.

- Click the gear at the right of the top bar.
- Right-click the tray icon and choose **Settings...**.
- Click the server chip in the top bar (it reads "Not paired", "Server: idle" and so on). That opens the **Server agent** page.
- Click the engine chip. That opens the **Engines** page.

Settings is a window of its own, so it can sit beside the main Benchy window. Opening it again brings the same window forward. There is no OK button: a change takes effect as soon as you make it. A small amber dot beside a page name means that page wants a look.

## The five pages

| Page | What it is for |
|---|---|
| General | Whether Benchy brings its window forward for an approval, and **Only show tests for this OS**. |
| Server agent | The server agent: its state, whether it runs at the desk or unattended, starting at log on, and pairing. |
| Engines | Which engine runs tests from the window, and the signed test catalog. |
| Results and uploads | Where results are kept, what gets uploaded after a run, and what is waiting. |
| Reset and uninstall | Everything Benchy has on this computer, with buttons to reset the server agent or uninstall. |

### General

<!-- unverified: wording and layout of this page, not checked on a running client -->
**Bring the window up when a scheduled test needs me** controls what happens when an approval starts (see [The alert band](#the-alert-band)). With it ticked, which is the default, the taskbar button flashes and a hidden window comes forward behind the one you are using. Unticked, the taskbar button only flashes. The same box is on the Server agent page. **Only show tests for this OS** hides tests that cannot run on Windows from the Run tests page.

### Server agent


The top of the page lists the facts: the state, the server, this computer's name and when it was paired, the engine, and what the agent runs as, under **Runs as** ("from this app's tray" or "a Task Scheduler task at log on, as administrator"). On a machine set up to run unattended the facts also show **Set up by**, the account and the date. Under that:

- **How it runs** has two choices. **At the desk** runs the agent while you are logged in, and before each test that needs administrator rights Benchy asks you in the window and Windows asks once more. **Unattended** starts the agent at log on as administrator and runs administrator tests you accepted with nobody at the desk. Other tests still run as a standard user. **Set up unattended...**, **Update it to ...** and **Stop running unattended...** each ask Windows for permission once. [Pair this machine](pair-this-machine.md#running-without-asking-unattended) explains the unattended setup. For a bench machine, use a dedicated local account.
- **Startup** has **Start the server agent when I log in** and **Bring the window up when a scheduled test needs me**. While the machine runs unattended, the first is off, because the unattended task starts the agent.
- **Pairing** holds the pairing form itself: on a machine that is not paired it is open, with the list of servers on your network, a box for the join code or pairing string, and **Pair**. On a paired machine, **Pair with a different server...** opens the same form and **Forget this server...** asks before it forgets. While the machine runs unattended both are off, **Pair** is off too, and the line "The unattended agent holds this computer's pairing. Stop running unattended first." stands beside them. [Pair this machine](pair-this-machine.md) walks through the form.
- At the bottom, **Start server agent** or **Stop server agent**, **Cancel server job** (only while a server job is running), and **Copy details**, which puts the agent's state on the clipboard as text, handy for a bug report.

If the engine on this computer cannot run the server agent, the page says "This engine can't run the server agent."

### Engines

<!-- unverified: the page's contents, not checked on a running client -->
Benchy has two engines, the native one and the older PHP one. This page shows both, whether each is ready, and where each is, and lets you choose which one runs tests from the Benchy window. **Check both again** looks again. Switching while tests are queued asks first, because the queue belongs to the engine that built it. The page also says that the server agent always uses the native engine, and that switching here does not touch it or its pairing.

With the native engine chosen, the **Signed test catalog** section shows the catalog this computer holds and has three buttons: **Check for a newer catalog**, **Import a catalog file...** (a `benchy-catalog-*.zip`) and **Reset to the built-in catalog...**. The reset warns that it also forgets which catalog keys and versions were retired, so an old or stolen-key catalog could be accepted again until a current one arrives.

### Results and uploads

<!-- unverified: the page's contents, not checked on a running client -->
**Saved results** shows how many native results are saved and when the oldest is from, with **Open results...** and **Show the folder**. **After a run here** has one box, **Upload to `<server>` when a run finishes**, which sets what the Run dialog starts with; you can still change it for a single run. **Waiting to upload** lists results that have not been sent, each with the reason, and **Upload now** (or **Upload all now**) sends them. **Naming** sets the default identifier for a run; blank uses the computer name. Runs the server agent does for a schedule always upload, whatever is set here.

### Reset and uninstall

This page is covered in [Resetting and uninstalling](reset-and-uninstall.md).

## The alert band

When the server agent is waiting on you, a band appears under the top bar of the Benchy window. It does not matter whether the tray icon is visible. Only one band shows at a time; the most urgent one wins.

| The band says | What it means | Buttons |
|---|---|---|
| A scheduled benchmark needs administrator approval | A schedule holds a test that needs administrator rights, and the server agent is waiting for you. | **Not now**, **Review and approve...** |
| A scheduled benchmark needs you to accept a test once | A schedule is held until you accept a test version on this computer. | **Later**, **Review and accept...** |
| This computer is paired twice | See [below](#fix-a-computer-that-is-paired-twice). | **Fix in Settings...** |
| The server agent stopped on its own | The agent stopped and nobody asked it to. | **Start**, **Pair again...** |
| A scheduled benchmark was put off | You did not answer an approval in time. | **Dismiss**, **Ready to run now...** |

<!-- unverified: the approval countdown; only an acceptance hold was seen on a running Windows client -->
The approval band counts down the minutes you have left, in red under five minutes. **Review and approve...** opens the Approval dialog described in [Pair this machine](pair-this-machine.md#asking-you-at-the-desk). **Not now** declines at once, and the server holds the run for later.

When an approval or an acceptance starts, the taskbar button flashes. If the Benchy window was hidden in the tray or minimized, it comes forward behind the window you are using. Benchy never takes the keyboard focus, so typing in another program is not interrupted; click the Benchy button on the taskbar to answer. The tray balloon still appears too.

Along the bottom of the window, the agent bar shows the agent's state, the server, whether it runs at the desk or unattended, and **Details**, which opens the Server agent page. During an approval it also shows **Approve...**, and when the agent is stopped, **Start**. On the Run tests page, the new **Rights** column marks a catalog test "runs as administrator" or "standard", so you know before you queue it that Windows will ask.

## Fix a computer that is paired twice

A machine is "paired twice" when an unattended setup from earlier is still on it and your own account was then paired again afterwards. Both hold a pairing for the same system, and only one of them can take jobs, so Benchy stops the agent and shows the band "This computer is paired twice". Until you fix it, you cannot set up or stop the unattended agent either, and a command line `agent run` or `agent setup-elevated` is refused with "This computer is paired twice."

You need Benchy 0.11.0 (engine 0.9.0) for the fix. With an older engine the page says "Update Benchy to fix this here."

1. Click **Fix in Settings...** in the band, or open **Settings**, **Server agent**.
2. Under **This computer is paired twice**, read the two pairings in the list. Each shows its server, the name of the system, and when it was paired or set up. The unattended one also shows the account it runs as. The newer pairing is already selected.
3. Choose the one to keep. Usually that is the newer one, since it is the one you paired on purpose.
4. Press the button under the list. Its name follows your choice.
   - **Keep this account's pairing** removes the unattended setup: its task, its protected folder and its copy of the pairing. The agent then runs from the tray, at the desk. Windows asks for permission once.
   - **Keep the unattended agent** forgets this account's pairing. The agent then runs unattended. No prompt is needed.
5. If Windows asks, say yes. If its prompt does not come to the front, look for its blinking button on the taskbar.
6. The page lists what was removed and names anything it could not remove. When it ends, the pairing form or the mode choice is back.


Nothing changes on the server. Both pairings belong to the same system there, so the server's list stays as it is. If you decline Windows' prompt, nothing changes at all and the page says "The administrator approval was declined. Nothing changed." You can press the button again.

A **Found** list below the two pairings shows what Benchy found on the computer, so you can see what each choice would remove. If the repair leaves something behind because a file is in use, it is listed with the reason and removed at the next restart when possible.

If the repair button is missing, the engine is older than 0.9.0. At a command prompt the same fix is `benchy-engine --json agent reset --keep user` or `benchy-engine --json agent reset --keep unattended`, which mean the same as the two buttons. <!-- unverified: the command lines, not run by hand on a Windows machine -->
