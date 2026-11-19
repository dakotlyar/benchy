# Resetting and uninstalling

Over time a Windows machine that has taken part in Benchy's scheduled testing collects more than a folder of programs: a pairing with your server, maybe an unattended setup with its own scheduled task and protected folders, helper programs for administrator tests, and policy files. This page is for the person at that machine who wants Benchy put back to a clean state, either to start again (reset) or to remove it altogether (uninstall). It explains what each one removes, what you can keep, and what to do when something is left behind. It covers Benchy 0.11.0 with desktop client 0.12.0 and engine 0.9.0, on Windows. Linux machines uninstall as described in [Installing Benchy](install.md#removing-benchy).

## Reset or uninstall?

| | Reset the server agent | Uninstall Benchy |
|---|---|---|
| The app and the engines | Kept | Removed |
| Your results and settings | Kept | Kept by default, your choice |
| Installed tests | Kept | Removed by default, your choice |
| The pairing with the server | Removed | Removed |
| The server address the installer remembered | Removed | Removed |
| The unattended agent, its task and protected folders | Removed | Removed |
| Elevated-run and machine-install helpers | Removed | Removed |
| Policy lines written by setup, and the tests you accepted | Removed | Removed |
| The server policy key, and any `deny` rule you wrote yourself | Kept | Removed |
| Start menu entry and log on entry | Kept | Removed |

Reset is the one to use when you want to pair the machine again, or when something about the pairing is wrong. After it, the pairing form is back and nothing needs reinstalling. Uninstall is for retiring the machine's copy of Benchy.

Before either one, read [Forget the server before you retire a machine](pair-this-machine.md#forget-the-server-before-you-retire-a-machine). Neither removes the machine's entry on the server. The server keeps listing the system until an administrator removes it on the Systems page.

## See what Benchy has on this computer

<!-- unverified: the table, its rows and its Reset and Uninstall columns, on a running client -->
1. Open **Settings** (the gear in the top bar) and choose **Reset and uninstall**.
2. Read the table under **What Benchy has on this computer**. Each row is something Benchy put here, with where it is and what it holds. The **Reset** and **Uninstall** columns say whether that button keeps or removes it ("kept", "removed", "your choice" for results and installed tests). A computer with nothing set up shows a short table.

The table is built from what is really on the machine. A row is missing when that thing is not there. If it says "Update Benchy to use this", the engine is older than 0.9.0 and the page can only show a short fixed table.

## Reset the server agent

<!-- unverified: steps 1 to 5 on a machine with an unattended setup (the Windows test VM), including the wording of the confirmation and the progress lines -->
1. On **Reset and uninstall**, press **Reset the server agent...**.
2. The box titled **Reset the server agent?** lists what it will remove ("This removes:"), and says "The app, your results and your settings stay, so you can pair again straight away." If a server job is running, it adds "This cancels server job `<title>`; the tests that finished are uploaded." Press **Reset**.
3. If some of what is listed is for all users of the computer, Windows asks for permission once. Say yes. If the prompt does not come to the front, look for its blinking button on the taskbar. If you say no, nothing at all is changed.
4. The page shows each step as it happens: "removed", "nothing to remove", "will be removed at the next restart", or "couldn't be removed" with the reason.
5. When it ends, the page says "Done." and the Server agent page offers the pairing form again. Anything in use is listed, and is removed at the next restart when Windows allows it.

A reset leaves the agent stopped and not wanted, so it does not start again by itself. The pairing form is on the Server agent page; use it to start again. <!-- unverified: that the agent stays stopped after a reset; the client sets agent/wanted off in the source -->

## Uninstall Benchy

You can start the uninstaller from **Settings**, **Reset and uninstall**, **Uninstall Benchy...** (Benchy closes and the uninstaller opens), from **Apps & Features** in Windows, or by running `%LOCALAPPDATA%\Benchy\uninstall.exe`. A machine that was installed with `install.bat` has an `uninstall.ps1` instead; see [below](#uninstall-with-the-command-line).

<!-- unverified: every page of the wizard, on the Windows test VM: page titles, button names, the default ticks, the sizes and the lists. Read from deploy/windows-installer/benchy-uninstall.nsh -->
1. The first page is titled **Uninstall Benchy**, "Remove everything Benchy put on this computer." It says "Benchy found these on `<computer>`. All of them are removed." and lists them. Parts that are for all users carry an "all users" tag.
2. Choose what to keep with the two boxes under the list.
   - **Keep my results and settings** is ticked by default. It covers both engines' data: your results and settings in `%USERPROFILE%\.benchy` and `%LOCALAPPDATA%\benchy-engine`, and the desktop client's own settings. The number and size of the results are shown. Untick it to delete them.
   - **Keep installed tests** is unticked by default. These are the downloaded and built tests, which can be downloaded again. Tick it to keep them.
3. Click **Uninstall**. If some of the parts are for all users, the button has a shield and a note says "Windows asks for permission once when you click Uninstall."
4. If Benchy is running, a page says what will be stopped. The window and tray close. A running server job is cancelled, and the tests that finished are uploaded first. A test running in the Benchy window is stopped and its partial result is kept. Click **Next**. If something cannot be stopped, for example a Benchy running from a `benchy.bat` console window, the page names it and asks you to close it.
5. Windows asks for permission once. Say yes. If you say no, the wizard says "Windows didn't give permission", that nothing has been removed yet, and offers **Try again**. Or choose **Cancel** to stop.
6. The **Removing** page shows the steps: closing Benchy, undoing the unattended setup and its task, the protected and work folders, the all users folders, the policy and keys, and then the app, the engines and installed tests. It takes under a minute.
7. The last page says **Benchy is removed**, "Benchy is removed from `<computer>`. Nothing it installed is left on this computer." and lists what it kept, in "Kept, as you asked". Click **Close**.

If a file was in use, the last page says "Benchy is almost removed", names how many items are in use, and lists each with the reason in a table. They are removed the next time Windows starts, and nothing else needs doing. **Copy the list** puts them on the clipboard, and **Restart now** asks first, then restarts Windows. Restart before you install Benchy again.

The last page also says what Benchy leaves. Software it installed for administrator tests, for example PassMark PerformanceTest for all users, stays: remove it from Windows' Installed apps if you do not need it. And it tells you the server still lists this computer, with the name of the system, until an administrator removes it on the server's Systems page.

Shared components that other software may use stay too: the Visual C++ Runtime and Cygwin.

### If the uninstall does not finish

The last page names a problem and its exit code. "Benchy is running" (code 3) means something could not be stopped, and nothing was removed. "Not everything was removed" (code 40) means some leftovers are neither removed nor queued; run the uninstaller again to finish, or delete what the log names by hand. The log is `%TEMP%\Benchy-uninstall.log`, and **Copy details** copies the message. [When something goes wrong](troubleshooting.md#uninstalling-benchy) has more.

### Uninstall with the command line

A silent uninstall takes no questions:

```
%LOCALAPPDATA%\Benchy\uninstall.exe /S [/KEEPRESULTS=0|1] [/KEEPTESTS=0|1] [/LOG=path]
```

`/KEEPRESULTS=1` (the default) keeps results and settings, `0` removes them. `/KEEPTESTS=0` (the default) removes installed tests, `1` keeps them. `/LOG=` names a log file, which must be a file and not a folder; the default is `%TEMP%\Benchy-uninstall.log`.

This command returns at once, with exit code 0, before anything has been removed. Like every uninstaller made with NSIS, `uninstall.exe` copies itself to `%TEMP%` and runs from there, so a script or a management tool does not see how it ended. To wait for the end and get the real exit code, add `_?=` and the install folder, as the last argument:

```
%LOCALAPPDATA%\Benchy\uninstall.exe /S _?=%LOCALAPPDATA%\Benchy
```

This form leaves `uninstall.exe` itself in `%LOCALAPPDATA%\Benchy`, so delete it afterward. <!-- unverified: the _?= form's wait and exit code, and that the exe is left behind, on the Windows test VM -->

A silent uninstall still asks Windows for permission once, when some of the parts are for all users. If there is no desktop to show the prompt (a service, or a remote session with nobody logged on), it stops with exit code 5 and changes nothing.

| Code | Meaning |
|---|---|
| 0 | Everything removed. |
| 1 | Cancelled, nothing changed. |
| 2 | No user profile, or bad arguments. |
| 3 | Benchy is running and could not be stopped. |
| 5 | Windows did not give permission, so nothing changed. |
| 3010 | Removed, except leftovers queued for the next restart. |
| 40 | Not everything was removed, and some leftovers are not queued. The log names them. |
| 50 | Anything else, such as the uninstaller being unable to unpack its files. |

For a machine installed with `install.bat`, `uninstall.ps1` does the same job: with the engine at 0.9.0 or later it runs the engine's `agent reset --all --uninstall` first, which asks Windows once. Its switches `-KeepResults`, `-KeepSettings` and `-KeepInstalledTests` choose what to keep (naming any of them keeps exactly those), `-RemoveUserData` removes all three, and `-Inventory -ResultFile <path>` writes the list of what it would remove and changes nothing. <!-- unverified: uninstall.ps1 on a command-line install on the Windows test VM -->

## What if the engine is old or missing?

The uninstaller carries its own checked copy of the 0.9.0 engine, so a broken install can still be removed with `uninstall.exe`. With `uninstall.ps1` and an engine older than 0.9.0, the machine-wide parts and the engine's own data are left, and it says so. Use the uninstaller from a current Benchy, or install Benchy 0.11.0 and uninstall again.
