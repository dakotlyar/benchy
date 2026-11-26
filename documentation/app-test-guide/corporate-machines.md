# Corporate machines

Sooner or later someone will want to run the test on a managed work computer. Those machines have security software that a home or lab machine does not, and an application test is exactly the kind of thing it distrusts: scripts that start programs and talk to them. This page covers what went wrong when the PhotoCraft test first ran on one, and how to write a test that goes through without anyone asking IT for an exception. It does not name any product or employer, because the lessons do not depend on them.

## Antivirus that blocks a script

The first version of the PhotoCraft helper was a PowerShell script that talked to the application directly. It opened a network connection to a port on the same machine, read the replies in a loop, answered the application's login challenge with a keyed hash, and ran the commands it was given. On the work machine, the antivirus refused to run it:

    This script contains malicious content and has been blocked by your antivirus software.

Nothing in the script was harmful. But the Windows Antimalware Scan Interface, AMSI, lets security software read a PowerShell script before it runs, and the software judges what the script looks like, not what it is for. A script with a raw TCP client, a loop that reads bytes from the stream, a challenge answered with a hash and code that executes what it receives is the textbook shape of a reverse shell, the tool an attacker uses to take over a machine. You cannot argue with a scanner from inside the script.

### The way out: let the application's own tool do the talking

The lesson is to keep sockets, cryptography and read loops out of your scripts. If the test has to talk to something, find a program that already does it and is already trusted on the machine.

PhotoCraft ships one. `photocraft-cli` has an `mcp` mode with a `--bridge` option. It is a small server that speaks MCP, a standard JSON protocol, over its standard input and output, and forwards each request to the running application, doing the login itself. One of its tools, `control_call`, takes the name of any control method and its parameters, so everything the old helper did could be done through it unchanged. The test now starts the bridge as a child process and writes one line of JSON to it per request:

```powershell
    $psi.Arguments = 'mcp --bridge 127.0.0.1:' + $Port + ' --control-token-file "' + $TokenFile + '"'
```

and reads one line back. A request is one `tools/call` message for `control_call`:

```powershell
    Send-PcLine ((@{ jsonrpc = '2.0'; id = $id; method = 'tools/call'; params = @{ name = 'control_call'; arguments = @{ method = $Method; params = $Params } } }) | ConvertTo-Json -Compress -Depth 14)
```

The script has no network code, no keyed hashes and no read-until-closed loop. It starts one program, writes lines to it and reads lines from it, which is something scripts do all day. The login token is two random identifiers written to a file that both the application and the bridge read. Before the change, the author checked that the bridge covered every method the test used, and that it added no meaningful delay: 200 `jobs.list` calls took 6.4 to 6.9 seconds through the bridge and 6.5 to 9.4 seconds directly, because the cost is the application answering in its frame update, not the extra hop.

The test passed on the work machine, and the antivirus did not block it.

### Things to look for in your own application

1. A command-line client that connects to the running program (an official CLI, an MCP bridge, a COM wrapper that ships with it). Use it as a child process.
2. If there is none, ask the vendor, or write a tiny client in a compiled language and ship it as a pinned download in `files.toml`. A scanner sees an executable it can reason about, and not a script that looks like an attacker's.
3. As a last resort, ask IT for an exclusion for the test's folder. It works, and it takes time and a conversation you may not win.

### Check your scripts before you ship them

The author searched every script in the test for the patterns that scanners and reviewers look at, and found none. Run the same search over yours:

`Net.Sockets`, `TcpClient`, `TcpListener`, `GetStream`, HMAC or `Cryptography`, `IEX` and `Invoke-Expression`, `DownloadString`, `FromBase64String`, reflection or `Assembly`, `-EncodedCommand`, `Invoke-WebRequest`, `WebClient` and `Add-Type`.

`Start-Process` and killing a process you started remain, because a desktop test has to start and stop a program; they are ordinary.

Even small things count. A first version of the port-picking code asked Windows which ports were in use. That is not a flagged pattern, but it is a network query all the same, so it was removed. The test now picks a random port between 30000 and 59999 and lets the next trial pick again if something else has it.

This check cannot promise anything. The author's own Windows Defender did not object to any version of the scripts, and Defender is not the product on the work machine. Whether a particular scanner accepts a script that starts a child process and talks to it over standard input is something only that machine can tell you. Run the test there early.

## Privilege-management tools

Some managed machines run a tool that asks for approval, or blocks, whenever a program tries to run with administrator rights, or whenever an unknown executable starts. The PhotoCraft test never asks for elevation. It installs a portable zip into the test's own folder, keeps the application's settings in the run folder, and runs everything as the signed-in user. That is the best position to be in: there is nothing for the tool to approve. Build your test the same way, and prefer a portable zip to an installer whenever the vendor offers one.

If your application does need elevation, say so plainly in the test's README and description, and expect that the person running the test will need to approve a prompt or get IT to allow it. Benchy never elevates a script on its own: the checker rejects `sudo`, `runas` and `Start-Process -Verb RunAs` in a test script. <!-- unverified: no privilege-management product was available to try; this section is advice from how the PhotoCraft test was built, not an observed case, and the checker rule comes from the kit's design notes rather than a run -->

## A slow first launch

The first time a freshly unpacked program runs, security software often scans it, and that takes time. PhotoCraft's first try on the work machine got through the antivirus, but failed in the install step:

    OperationStopped: (prefs.set (engi... was not sent

The install ran the application once, to make the settings every trial starts from, and sent the first request straight away. The antivirus was still scanning the new `photocraft.exe`, the application was not listening yet, and the bridge answered "the request was not sent". The run script already had a loop that kept asking until the application answered, with a cap of 60 seconds; the install script had none. The earlier try-runs had reused an existing install, so the fresh-install path had never been run with the final helper.

The fix is a small loop that treats the "not yet" answers as "ask again" and anything else as a real error, shared by both scripts so that they cannot drift apart:

```powershell
function Wait-PcListening {
    param([int]$TimeoutSeconds = 90)
    $sw = [System.Diagnostics.Stopwatch]::StartNew(); $last = ''
    while ($sw.Elapsed.TotalSeconds -lt $TimeoutSeconds) {
        if ($script:Pc.App) { $script:Pc.App.Refresh(); if ($script:Pc.App.HasExited) { throw 'PhotoCraft exited while it was starting.' } }
        $r = Invoke-PcIfListening -What 'Wait for PhotoCraft'
        if ($r.Ready) { return }
        $last = $r.Wait
        Start-Sleep -Milliseconds 250
    }
    throw ('PhotoCraft did not start listening within ' + $TimeoutSeconds + ' s. Last answer of the bridge: ' + $last)
}
```

If it never listens, the message says how long it waited and quotes the bridge's last answer. If the application exits, it stops early.

Two general lessons come out of this. Always test the install from nothing, by deleting the test's install folder and running again, because everything after the first run is working with files that were already scanned. And anything that waits for an application to be ready needs a cap and a message, never an open loop. On the build machine only the fresh install was run again; the slow-start retry itself was not provoked there. The fixed test then passed on the work machine. <!-- unverified: the slow-start retry was not exercised on the build machine, and the build record does not say whether the retry fired on the work machine -->
