; Benchy GUI installer for Windows (NSIS 3, built by deploy/release.sh installer)
;
; This program is free software; you can redistribute it and/or modify
; it under the terms of the GNU General Public License as published by
; the Free Software Foundation; either version 3 of the License, or
; (at your option) any later version.
;
; A thin wrapper around the tree's own install.ps1, so the GUI and install.bat install the same way. This script
; knows nothing about the install layout, the PHP pin, VC++, PATH, the shortcut or the registry: it unpacks the tree
; and the pinned PHP zip to a temp dir, runs install.ps1 -NonInteractive in 64-bit Windows PowerShell, reads its
; result file and shows pages. The one difference from install.bat: the bundled PHP goes in with -PhpZipMode IfOlder,
; so it replaces a managed PHP only when that one is older than the pin (a reinstall never downgrades a PHP the
; updater moved forward). A good install also writes the uninstaller, %LOCALAPPDATA%\Benchy\uninstall.exe (the same compile; see below), and
; registers it in Apps & Features in place of uninstall.ps1, which install.bat installs keep.
; The pages are custom nsDialogs pages in the look of benchy-ui.nsh (shared with the uninstaller): Install or Upgrade (a
; before and after table), Installing (a step list, the log behind Show details), Server, and Done or Didn't install. A running Benchy is
; closed and started again, not refused: stop-benchy.ps1 asks the client to quit (or, for a client older than 0.12, ends it when nothing
; runs in it), cancels an agent job through the engine, and the client goes back the way it was after the install. The unattended
; agent is updated after the install with the engine's own `agent setup-elevated --update` (one prompt) when the box on the Upgrade page
; says so; /S never does that.
; One step runs here instead of in install.ps1: launching the VC++ runtime installer install.ps1 downloaded and checked
; (-VcRedistHandOff), elevated and owned by the wizard window so the UAC prompt comes to the front; its exit code goes
; back to install.ps1 (-VcRedistExitCode), which decides what it means. Before that, this script holds the file open
; (no write, delete or rename sharing) and checks it again against the size and sha256 built into this exe, because
; install.ps1, its result file and the saved file all sit in the user-writable $PLUGINSDIR; on a mismatch it does not
; run it, and install.ps1 -VcRedistRejected ends with exit code 22.
;
; Build defines (all required unless marked; release.sh passes them):
;   VERSION      PTS_VERSION of the tree, e.g. 0.7.1 (shown, and compared with the installed DisplayVersion)
;   PAYLOAD      the git-archive tree to install
;   PHPZIP       the pinned PHP zip, already verified against the tree's runtime-pins.json
;   VCREDIST_SHA256  sha256 (64 hex digits) of the pinned VC_redist.x64.exe (runtime-pins.json, vcredist), which
;   VCREDIST_SIZE    and its size in bytes: release.sh downloads it and checks both and its signer before building
;   OUTFILE      the installer exe to write
;   BUILD_LABEL  optional; marks a dev build (release.sh installer --ref) in the window caption and branding
;   CLIENTZIP    optional; the desktop client zip (BenchyClient-<ver>-windows-x64.zip), already verified against the
;                tree's runtime-pins.json (windows-x64.client). It is unpacked next to the others and handed to
;                install.ps1 as -ClientZip, which checks it again and knows the layout; this script does not.
;   CLIENT_VERSION, ENGINE_VERSION  optional; the pinned client and native engine versions, shown on the Install and Upgrade pages
;                (release.sh takes them from the tree's runtime-pins.json, with CLIENTZIP and ENGINEZIP)
;   ENGINEEXE, ENGINEEXE_SHA256, ENGINEEXE_SIZE  required with ENGINEZIP: the benchy-engine.exe inside that zip (release.sh takes it out of the already
;                verified zip), its sha256 and its size. The uninstaller carries this exe itself (not the zip), and checks the copy it holds
;                open against the sha256 and size built in here before it runs anything.
;   ENGINEZIP    optional; the native engine zip (benchy-engine-<ver>-windows-x64.zip), already verified against the
;                tree's runtime-pins.json (windows-x64.engine). Handled like CLIENTZIP: handed to install.ps1 as
;                -EngineZip, which checks it again and puts it in engine\native\.
;
; Command line (silent mode, for scripts):
;   Benchy-Setup-<ver>.exe [/S] [/PHOROMATIC=[https://]ip:port/accountid] [/SERVER=[https://]host[:port]|auto|none]
;                          [/VCREDIST=auto|never] [/LOG=<path>]
;   /S            no wizard. VC++ follows install.ps1's -NonInteractive default (Never unless already elevated).
;   /PHOROMATIC=  save this Phoromatic (v1) server; without it the current setting is kept.
;   /SERVER=      the Benchy server this computer should offer to join (server discovery, benchy-server-discovery.md 7). It
;                 saves a hint (HKCU\Software\Benchy ServerHint, ServerHintSource) that the engine's `discover` lists and
;                 the client preselects; it never pairs, and no join code is asked for or stored. An address
;                 ([https://]host[:port], never plain http) is saved as given; none saves "Don't connect"; auto runs the
;                 engine's discover after the install and saves the policy server, or the one server found when exactly
;                 one was (with none or several it saves nothing and says so in the log: never a failure). Without
;                 /SERVER nothing is searched and the current hint stays, so a silent install makes no surprise
;                 network traffic. The wizard asks on its own page after the install and uses /SERVER as the preset.
;                 A policy server (HKLM\SOFTWARE\Policies\Benchy\Server, see benchy-server-policy.example.reg) needs
;                 no /SERVER.
;   /VCREDIST=    auto installs the VC++ runtime when needed (may show a UAC prompt), never fails with exit code 20.
;                 The wizard always uses auto unless this is given.
;   /LOG=         install.ps1's log file (default %TEMP%\Benchy-install.log); a file, not a folder, under 260 characters
;                 as a full path. Quote a path with spaces: /LOG="C:\My Logs\benchy.log" (or "/LOG=C:\My Logs\benchy.log").
;   Option names are case-insensitive. A value runs to the next space, or to the closing quote when it starts with
;   one; it may contain / (10.0.0.5:8080/ABC123, C:/logs/x.log). Text inside quotes is never taken as an option.
;   /D= is rejected with exit code 2 (the install dir is fixed), and so are an invalid /PHOROMATIC, /SERVER, /VCREDIST or /LOG.
; The exit code is install.ps1's (0, 3010, 2, 3, 10, 11, 20, 21, 22, 30, 40, 50; see install.ps1), or 3 when a running Benchy
; (or the agent) could not be closed first (stop-benchy.ps1; /S closes it without asking, the wizard says so on its first page), or:
;   1   the wizard was cancelled, or Windows PowerShell did not run install.ps1 (it rejected the arguments, or a
;       group policy execution policy blocks the script); Benchy was not changed
;   2   this installer refused before running install.ps1 (the reasons above, or not 64-bit Windows)
;   50  also when install.ps1 never started: the installer could not unpack its files (also when Cancel is chosen
;       at a file that cannot be written), or Windows PowerShell could not be started; nothing was changed
;
; The uninstaller (benchy-uninstall.nsh), %LOCALAPPDATA%\Benchy\uninstall.exe, is a wizard in the same look: What goes, [Benchy is
; running], Removing, [Windows said no], Done. It carries its own copy of the pinned native engine (extracted to its temp folder and held
; open, so a broken or missing install can still be removed, and the engine that removes things is the one that matches this install),
; and of uninstall.ps1, install.ps1 (for its -CheckRunning) and stop-benchy.ps1. It stays RequestExecutionLevel user: the one Windows
; prompt comes from the engine's own relay (`agent reset`). Command line (silent mode):
;   uninstall.exe /S [/KEEPRESULTS=0|1] [/KEEPTESTS=0|1] [/LOG=<path>]
;   /KEEPRESULTS=  1 (the default) keeps results and settings (%USERPROFILE%\.benchy, %LOCALAPPDATA%\benchy-engine and the client's
;                  settings folder), 0 removes them. /KEEPTESTS=  1 keeps installed tests, 0 (the default) removes them (they download again).
;   /LOG=          the log file (default %TEMP%\Benchy-uninstall.log); a file, not a folder, under 260 characters.
;   Like every NSIS uninstaller, uninstall.exe starts a copy of itself in %TEMP% and returns at once; for a result to wait on, run
;   `uninstall.exe /S _?=%LOCALAPPDATA%\Benchy`. The exe itself is then left in that folder: it cannot delete itself while it runs, so it is not
; counted as a leftover (the exit code is 0 when that is all that is left) and the caller deletes it.
; Uninstaller exit codes (the copy's; SetErrorLevel):
;   0     everything removed
;   1     cancelled, nothing changed
;   2     no user profile, or bad arguments
;   3     Benchy is running and could not be stopped (an older client with a run going, a benchy.bat console, a client that did not quit)
;   5     Windows did not give permission (the prompt was declined or expired, or /S with no desktop): nothing was changed
;   3010  removed, except leftovers queued for the next restart (the log names them)
;   40    not everything removed, and some leftovers are not queued (the log names them)
;   50    anything else (the uninstaller could not unpack its files, PowerShell could not be started, the engine failed)

!macro RequireDefine _NAME
	!ifndef ${_NAME}
		!error "benchy.nsi needs -D${_NAME}; build it with deploy/release.sh installer"
	!endif
!macroend
!insertmacro RequireDefine VERSION
!insertmacro RequireDefine PAYLOAD
!insertmacro RequireDefine PHPZIP
!insertmacro RequireDefine OUTFILE
!insertmacro RequireDefine VCREDIST_SHA256
!insertmacro RequireDefine VCREDIST_SIZE
!ifdef ENGINEZIP
	!insertmacro RequireDefine ENGINEEXE
	!insertmacro RequireDefine ENGINEEXE_SHA256
	!insertmacro RequireDefine ENGINEEXE_SIZE
!endif

Unicode true
ManifestDPIAware true
RequestExecutionLevel user
SetCompressor /SOLID lzma
; A file that cannot be unpacked offers Retry/Cancel only: an Ignore would hand install.ps1 a partial tree, which its
; robocopy /MIR then mirrors over the install
AllowSkipFiles off

!ifdef BUILD_LABEL
	!define CAPTION_SUFFIX " (${BUILD_LABEL})"
!else
	!define CAPTION_SUFFIX ""
!endif

Name "Benchy ${VERSION}"
Caption "Benchy ${VERSION} Setup${CAPTION_SUFFIX}"
BrandingText "Benchy ${VERSION}${CAPTION_SUFFIX}"
OutFile "${OUTFILE}"
ShowInstDetails hide

VIProductVersion "${VERSION}.0"
VIAddVersionKey /LANG=1033 "ProductName" "Benchy"
VIAddVersionKey /LANG=1033 "ProductVersion" "${VERSION}"
VIAddVersionKey /LANG=1033 "FileVersion" "${VERSION}"
VIAddVersionKey /LANG=1033 "FileDescription" "Benchy ${VERSION} Setup"
VIAddVersionKey /LANG=1033 "CompanyName" "Dave Kotlyar"
VIAddVersionKey /LANG=1033 "LegalCopyright" "GNU GPL v3 or later"

!include MUI2.nsh
!include nsDialogs.nsh
!include LogicLib.nsh
!include FileFunc.nsh
!include WordFunc.nsh
!include x64.nsh
!include WinMessages.nsh
!include "certutil-sha256.nsh"
!include "benchy-ui.nsh"
!ifdef ENGINEEXE
	!insertmacro CertutilSha256Function "un."
!endif

; The same icon install.ps1 registers as the Apps & Features DisplayIcon
!define MUI_ICON "${PAYLOAD}/pts-core/static/images/benchy.ico"
!define MUI_UNICON "${PAYLOAD}/pts-core/static/images/benchy.ico"
; MUI inserts un.onGUIInit (the header and buttons it reads) only when an MUI uninstaller page was used; this one has custom pages only
!define MUI_UNINSTALLER
!define MUI_CUSTOMFUNCTION_UNGUIINIT un.GuiInit
!insertmacro BuiHeaderImage
!define MUI_CUSTOMFUNCTION_GUIINIT GuiInit

!define UNINSTALL_KEY "Software\Microsoft\Windows\CurrentVersion\Uninstall\Benchy"
!define EXIT_REFUSED 2
!define EXIT_OTHER 50

Var PowerShell
Var LogPath
Var Phoromatic
Var VcRedist
Var OldVersion
Var VersionOrder ; VersionCompare of the installed and this version: 0 same, 1 installed is newer, 2 this is newer
Var ExitCode
Var Ok
Var ResultMessage
Var RebootRecommended
Var RebootRequired
Var Warnings
Var Details
Var NotStarted ; why install.ps1 never ran; "" when it ran
Var HasLog ; 1 when install.ps1 ran far enough to write its log (not when it never started, nor on exit code 1)
Var InstFilesHeader ; the progress page header once the install is over: complete, or not installed
Var InstFilesSubHeader
Var VcHandle ; the open handle that pins VC_redist.x64.exe from its check until it has run; -1 when none
Var VcFinalPath ; VC_redist.x64.exe as that handle resolves it (links resolved); what is hashed and started
Var VcDirFresh ; 1 when this installer made $PLUGINSDIR\vc itself, before install.ps1 ran

Var ServerMode ; /SERVER: "" not given, "none", "auto" or "address"
Var ServerAddress ; /SERVER address, or what the server page saves (https://host[:port])
Var PhpPath ; the PHP install.ps1 used (result.json php_path), for saving a v1 string
Var InstallDialog
Var FinishOpen

Var NotStartedCode ; the exit code for a NotStarted reason other than 50 (3: Benchy could not be closed)
Var RunCmd ; the command line RunInstallPs runs
Var StepsOn ; 1 once the instfiles page has its step list (never in /S)
Var RowCount
Var RowTop
Var RowStop
Var RowCheck
Var RowVc
Var RowCopy
Var RowEntries
Var RowEngines
Var RowAgent
Var NowRow ; the row install.ps1 last started and has not ended, -1 none
Var LastDone
Var FailRow
Var ProgressDone ; lines of progress.txt already read
Var InfoDone
Var NeedStop ; 1 when a client or an agent runs from this install and the install will close it
Var RunClient
Var RunTray
Var RunAgent
Var RunLocal ; a run is going (the agent is busy, or an engine run is going)
Var RunConsole ; a benchy.bat console or a PHP of this install
Var RunJob ; the agent's job title, "" when none
Var StopBlock ; "" or what only the person can end (the Next click stops there)
Var StopMessage ; "" or why stop-benchy.ps1 could not close Benchy
Var WasClient ; the client before the stop: 0 none, 1 window, 2 tray
Var RestartDone
Var VcNeeded
Var ProtVer ; the unattended agent's engine version, "" when there is none
Var ProtOlder
Var PairText ; "seinar as KAMINO", "" when not paired
Var OldClientVer
Var OldEngineVer
Var InstallY
Var UpdateBox
Var UpdateAgent ; 1 when the box "Update the unattended agent too" is ticked
Var AutostartBox
Var AutostartMode ; 1 when "Start Benchy in the tray when I log in" is ticked
Var AgentResult ; "", updated, or not
Var EnginesOk
Var VcResult
Var ServerSaved
Var FailTitle
Var FailBody
Var FailState

Var ServerDialog
Var ServerStatus
Var ServerNone
Var ServerField
Var ServerTicks
Var ServerCount
Var ServerR1
Var ServerR2
Var ServerR3
Var ServerR4
Var ServerR5

!define ENGINE_EXE "$LOCALAPPDATA\Benchy\engine\native\benchy-engine.exe"
!define SERVER_MAX_SHOWN 5

Page custom InstallPageCreate InstallPageLeave
; MUI sets these as the header when the section ends; without them a failed install said "Installation Complete"
!define MUI_INSTFILESPAGE_FINISHHEADER_TEXT "$InstFilesHeader"
!define MUI_INSTFILESPAGE_FINISHHEADER_SUBTEXT "$InstFilesSubHeader"
!define MUI_PAGE_HEADER_TEXT "Installing Benchy ${VERSION}"
!define MUI_PAGE_HEADER_SUBTEXT "A slow first copy is Windows Defender scanning the files."
!define MUI_PAGE_CUSTOMFUNCTION_SHOW InstFilesShow
!define MUI_PAGE_CUSTOMFUNCTION_LEAVE InstFilesLeave
!insertmacro MUI_PAGE_INSTFILES
Page custom ServerPageCreate ServerPageLeave
Page custom FinishPageCreate FinishPageLeave
; The uninstaller's pages (benchy-uninstall.nsh, included at the end). The Cancel button and close box are refused while it removes things
UninstPage custom un.WhatPageCreate un.WhatPageLeave
UninstPage custom un.RunningPageCreate un.RunningPageLeave
UninstPage custom un.RemovingPageCreate un.RemovingPageLeave
UninstPage custom un.DeclinedPageCreate un.DeclinedPageLeave
UninstPage custom un.DonePageCreate un.DonePageLeave
; NSIS runs the uninstall section only through an instfiles page, and /S through none of the custom ones: this page is skipped in the
; wizard (the removal is the Removing page's worker) and is what lets /S run the section
!define MUI_PAGE_CUSTOMFUNCTION_PRE un.SkipInstFiles
!insertmacro MUI_UNPAGE_INSTFILES
!define MUI_CUSTOMFUNCTION_UNABORT un.AbortCheck

!insertmacro MUI_LANGUAGE "English"

ReserveFile /plugin nsDialogs.dll
ReserveFile /plugin System.dll

!insertmacro BuiFunctions ""
!insertmacro BuiFunctions "un."

; --- Helpers ---------------------------------------------------------------------------------------------------------

; Push <char>, Push <set>, Call CharInSet, Pop <1|0>. Case-sensitive (StrCmpS).
Function CharInSet
	Exch $0 ; set
	Exch
	Exch $1 ; char
	Push $2
	Push $3
	StrCpy $2 0
	${Do}
		StrCpy $3 $0 1 $2
		${If} $3 == ""
			StrCpy $0 0
			${ExitDo}
		${EndIf}
		${If} $3 S== $1
			StrCpy $0 1
			${ExitDo}
		${EndIf}
		IntOp $2 $2 + 1
	${Loop}
	Pop $3
	Pop $2
	Pop $1
	Exch $0
FunctionEnd

!define ALNUM "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789"
!define DIGITS "0123456789"

; Push <value>, Call IsPhoromaticServer, Pop <1|0>. The same check as install.ps1's
; ^(https://)?[A-Za-z0-9.-]+:[0-9]{1,5}/[A-Za-z0-9]+$ (install.ps1 checks again with the real pattern).
Function IsPhoromaticServer
	Exch $0 ; value
	Push $1 ; position
	Push $2 ; character
	Push $3 ; part: 0 host, 1 port, 2 account id
	Push $4 ; length of the current part
	Push $5 ; result
	Push $6
	StrCpy $1 0
	StrCpy $3 0
	StrCpy $4 0
	StrCpy $5 1
	; An optional https:// in front (a server with the built-in HTTPS)
	StrCpy $6 $0 8
	${If} $6 == "https://"
		StrCpy $1 8
	${EndIf}
	${Do}
		StrCpy $2 $0 1 $1
		${If} $2 == ""
			${ExitDo}
		${EndIf}
		IntOp $1 $1 + 1
		${If} $3 = 0
			Push $2
			Push "${ALNUM}.-"
			Call CharInSet
			Pop $6
			${If} $6 = 1
				IntOp $4 $4 + 1
			${ElseIf} $2 == ":"
			${AndIf} $4 > 0
				StrCpy $3 1
				StrCpy $4 0
			${Else}
				StrCpy $5 0
				${ExitDo}
			${EndIf}
		${ElseIf} $3 = 1
			Push $2
			Push "${DIGITS}"
			Call CharInSet
			Pop $6
			${If} $6 = 1
			${AndIf} $4 < 5
				IntOp $4 $4 + 1
			${ElseIf} $2 == "/"
			${AndIf} $4 > 0
				StrCpy $3 2
				StrCpy $4 0
			${Else}
				StrCpy $5 0
				${ExitDo}
			${EndIf}
		${Else}
			Push $2
			Push "${ALNUM}"
			Call CharInSet
			Pop $6
			${If} $6 = 1
				IntOp $4 $4 + 1
			${Else}
				StrCpy $5 0
				${ExitDo}
			${EndIf}
		${EndIf}
	${Loop}
	${If} $3 <> 2
	${OrIf} $4 = 0
		StrCpy $5 0
	${EndIf}
	StrCpy $0 $5
	Pop $6
	Pop $5
	Pop $4
	Pop $3
	Pop $2
	Pop $1
	Exch $0
FunctionEnd

; Push <value>, Call IsServerAddress, Pop <1|0>. [https://]host[:port]: a host name or IPv4 address (letters, digits, dots and
; hyphens), then an optional port of 1 to 5 digits (1 to 65535). Never http://, and nothing after the port (no path, no
; account id: that is the v1 string above).
Function IsServerAddress
	Exch $0 ; value
	Push $1 ; position
	Push $2 ; character
	Push $3 ; 0 host, 1 port
	Push $4 ; length of the current part
	Push $5 ; result
	Push $6
	StrCpy $1 0
	StrCpy $3 0
	StrCpy $4 0
	StrCpy $5 1
	StrCpy $6 $0 8
	${If} $6 == "https://"
		StrCpy $1 8
	${EndIf}
	${Do}
		StrCpy $2 $0 1 $1
		${If} $2 == ""
			${ExitDo}
		${EndIf}
		IntOp $1 $1 + 1
		${If} $3 = 0
			Push $2
			Push "${ALNUM}.-"
			Call CharInSet
			Pop $6
			${If} $6 = 1
				IntOp $4 $4 + 1
			${ElseIf} $2 == ":"
			${AndIf} $4 > 0
				StrCpy $3 1
				StrCpy $4 0
				StrCpy $0 $0 "" $1 ; the port, for the range check below
				StrCpy $1 0
				${ExitDo}
			${Else}
				StrCpy $5 0
				${ExitDo}
			${EndIf}
		${EndIf}
	${Loop}
	${If} $3 = 0
		; no port: needs a host
		${If} $4 = 0
			StrCpy $5 0
		${EndIf}
	${ElseIf} $5 = 1
		; $0 is now the port text
		StrLen $4 $0
		${If} $4 < 1
		${OrIf} $4 > 5
			StrCpy $5 0
		${Else}
			StrCpy $1 0
			${Do}
				StrCpy $2 $0 1 $1
				${If} $2 == ""
					${ExitDo}
				${EndIf}
				Push $2
				Push "${DIGITS}"
				Call CharInSet
				Pop $6
				${If} $6 <> 1
					StrCpy $5 0
					${ExitDo}
				${EndIf}
				IntOp $1 $1 + 1
			${Loop}
			${If} $5 = 1
				IntOp $6 $0 + 0
				${If} $6 < 1
				${OrIf} $6 > 65535
					StrCpy $5 0
				${EndIf}
			${EndIf}
		${EndIf}
	${EndIf}
	StrCpy $0 $5
	Pop $6
	Pop $5
	Pop $4
	Pop $3
	Pop $2
	Pop $1
	Exch $0
FunctionEnd

; Push <value that passed IsServerAddress>, Call NormalizeServerAddress, Pop <https://host[:port]>
Function NormalizeServerAddress
	Exch $0
	Push $1
	StrCpy $1 $0 8
	${If} $1 != "https://"
		StrCpy $0 "https://$0"
	${EndIf}
	Pop $1
	Exch $0
FunctionEnd

; Push <value>, Call WriteServerHint: HKCU\Software\Benchy ServerHint (the address) and ServerHintSource installer, where the
; engine's discover reads the installer's hint. "none" is the person's answer to the page: no hint, and the source says so.
Function WriteServerHint
	Exch $0
	${If} $0 == "none"
		DeleteRegValue HKCU "Software\Benchy" "ServerHint"
		WriteRegStr HKCU "Software\Benchy" "ServerHintSource" "none"
	${Else}
		WriteRegStr HKCU "Software\Benchy" "ServerHint" "$0"
		WriteRegStr HKCU "Software\Benchy" "ServerHintSource" "installer"
	${EndIf}
	Pop $0
FunctionEnd

; Push <text>, Call AppendInstallLog: a line at the end of the install log (install.ps1 has finished with it by now)
Function AppendInstallLog
	Exch $0
	Push $1
	ClearErrors
	FileOpen $1 "$LogPath" a
	${IfNot} ${Errors}
		FileWrite $1 "$0$\r$\n"
		FileClose $1
	${EndIf}
	Pop $1
	Pop $0
FunctionEnd

; Push <parameters>, Push <option, e.g. /LOG=>, Call GetCmdOption, Pop <value>, Pop <1 found|0>.
; Not ${GetOptions}: it takes every / as the start of the next option, so /PHOROMATIC=10.0.0.5:8080/ABC123 came back
; as 10.0.0.5:8080 and /LOG=C:/logs/x.log as C:. Here the option counts only at the start of a token (any case), and
; its value runs to the next space or tab, or, when it starts with a double quote, to the closing one. A token quoted
; as a whole ("/LOG=C:\My Logs\x.log", as PowerShell passes an argument with spaces) works too. The first one wins.
; Text inside quotes is never an option: /LOG="D:\x /VCREDIST=maybe\b.log" has no /VCREDIST.
!macro GetCmdOptionFunction _UN
	Function ${_UN}GetCmdOption
		Exch $0 ; option
		Exch
		Exch $1 ; parameters
		Push $2 ; position
		Push $3 ; option length
		Push $4 ; character or substring
		Push $5 ; previous character
		Push $6 ; found
		Push $7 ; end of the value: '"' or a space
		Push $8 ; value
		Push $9 ; 1 inside double quotes
		StrLen $3 $0
		StrCpy $2 0
		StrCpy $5 " "
		StrCpy $6 0
		StrCpy $8 ""
		StrCpy $9 0
		${Do}
			StrCpy $4 $1 1 $2
			${If} $4 == ""
				${ExitDo}
			${EndIf}
			${If} $9 = 1
				; inside quotes: not the start of a token
			${ElseIf} $5 == " "
			${OrIf} $5 == "$\t"
				${If} $4 == '"'
					IntOp $7 $2 + 1
					StrCpy $4 $1 $3 $7
					${If} $4 == $0
						; "/OPTION=value"
						IntOp $2 $7 + $3
						StrCpy $7 '"'
						StrCpy $6 1
						${ExitDo}
					${EndIf}
				${Else}
					StrCpy $4 $1 $3 $2
					${If} $4 == $0
						IntOp $2 $2 + $3
						StrCpy $4 $1 1 $2
						${If} $4 == '"'
							; /OPTION="value"
							IntOp $2 $2 + 1
							StrCpy $7 '"'
						${Else}
							StrCpy $7 " "
						${EndIf}
						StrCpy $6 1
						${ExitDo}
					${EndIf}
				${EndIf}
			${EndIf}
			StrCpy $5 $1 1 $2
			${If} $5 == '"'
				IntOp $9 1 - $9
			${EndIf}
			IntOp $2 $2 + 1
		${Loop}
		${If} $6 = 1
			${Do}
				StrCpy $4 $1 1 $2
				${If} $4 == ""
					${ExitDo}
				${ElseIf} $7 == '"'
					${If} $4 == '"'
						${ExitDo}
					${EndIf}
				${ElseIf} $4 == " "
				${OrIf} $4 == "$\t"
					${ExitDo}
				${EndIf}
				StrCpy $8 "$8$4"
				IntOp $2 $2 + 1
			${Loop}
		${EndIf}
		StrCpy $0 $8
		StrCpy $1 $6
		Pop $9
		Pop $8
		Pop $7
		Pop $6
		Pop $5
		Pop $4
		Pop $3
		Pop $2
		Exch $1
		Exch
		Exch $0
	FunctionEnd
!macroend
!insertmacro GetCmdOptionFunction ""

!macro GetCmdOption _PARAMS _OPTION _FOUND _VALUE
	Push "${_PARAMS}"
	Push "${_OPTION}"
	Call GetCmdOption
	Pop ${_VALUE}
	Pop ${_FOUND}
!macroend

!insertmacro GetCmdOptionFunction "un."
!macro unGetCmdOption _PARAMS _OPTION _FOUND _VALUE
	Push "${_PARAMS}"
	Push "${_OPTION}"
	Call un.GetCmdOption
	Pop ${_VALUE}
	Pop ${_FOUND}
!macroend

; Refuse before anything runs: message (skipped by /S), exit code 2
!macro Refuse _MESSAGE
	MessageBox MB_OK|MB_ICONSTOP "${_MESSAGE}" /SD IDOK
	SetErrorLevel ${EXIT_REFUSED}
	Quit
!macroend

; --- Start: platform and command line ----------------------------------------------------------------------------

Function .onInit
	${IfNot} ${RunningX64}
		!insertmacro Refuse "Benchy needs 64-bit Windows (its PHP is x64 only)."
	${EndIf}
	SetRegView 64
	; 64-bit Windows PowerShell (see the section): the pages use it before the section runs
	StrCpy $PowerShell "$WINDIR\Sysnative\WindowsPowerShell\v1.0\powershell.exe"

	; NSIS takes /D= off the command line and puts it in $INSTDIR; this script sets no InstallDir, so $INSTDIR is
	; empty unless /D= was given
	${If} $INSTDIR != ""
		!insertmacro Refuse "/D is not supported: Benchy always installs to $LOCALAPPDATA\Benchy."
	${EndIf}

	${GetParameters} $R0

	!insertmacro GetCmdOption $R0 "/PHOROMATIC=" $R1 $Phoromatic
	${If} $Phoromatic != ""
		Push $Phoromatic
		Call IsPhoromaticServer
		Pop $R1
		${If} $R1 <> 1
			!insertmacro Refuse "/PHOROMATIC must be [https://]ip:port/accountid, for example 10.0.0.5:8080/ABC123 or https://seinar:39443/ABC123 (got '$Phoromatic')."
		${EndIf}
	${EndIf}

	StrCpy $ServerMode ""
	StrCpy $ServerAddress ""
	!insertmacro GetCmdOption $R0 "/SERVER=" $R2 $R1
	${If} $R2 = 1
		${If} $R1 == "auto"
			StrCpy $ServerMode "auto"
		${ElseIf} $R1 == "none"
			StrCpy $ServerMode "none"
		${Else}
			Push $R1
			Call IsServerAddress
			Pop $R3
			${If} $R3 <> 1
				!insertmacro Refuse "/SERVER must be auto, none, or [https://]host[:port], for example https://seinar:39443 (got '$R1')."
			${EndIf}
			Push $R1
			Call NormalizeServerAddress
			Pop $ServerAddress
			StrCpy $ServerMode "address"
		${EndIf}
	${EndIf}

	!insertmacro GetCmdOption $R0 "/VCREDIST=" $R2 $R1
	${If} $R2 = 0
		StrCpy $VcRedist ""
	${ElseIf} $R1 == "auto"
		StrCpy $VcRedist "Auto"
	${ElseIf} $R1 == "never"
		StrCpy $VcRedist "Never"
	${Else}
		!insertmacro Refuse "/VCREDIST must be auto or never (got '$R1')."
	${EndIf}
	; The wizard is interactive, so a UAC prompt for the VC++ runtime is fine. /S leaves it to install.ps1, whose
	; -NonInteractive default is Never unless the process is already elevated.
	${IfNot} ${Silent}
	${AndIf} $VcRedist == ""
		StrCpy $VcRedist "Auto"
	${EndIf}

	!insertmacro GetCmdOption $R0 "/LOG=" $R2 $R1
	${If} $R1 == ""
		StrCpy $LogPath "$TEMP\Benchy-install.log"
	${Else}
		StrLen $R2 $R1
		StrCpy $R3 $R1 1 -1
		${If} $R2 >= 260
		${OrIf} $R3 == "\"
		${OrIf} $R3 == "/"
			!insertmacro Refuse "/LOG must be a file path shorter than 260 characters, not a folder (got '$R1')."
		${EndIf}
		; Relative to where the installer was started, not to the temp dir install.ps1 runs from (deleted afterwards).
		; A return of NSIS_MAX_STRLEN or more is the buffer size it needed, with nothing written.
		System::Call 'kernel32::GetFullPathNameW(w R1, i ${NSIS_MAX_STRLEN}, w .R2, p 0) i .R3'
		${If} $R3 > 0
		${AndIf} $R3 < ${NSIS_MAX_STRLEN}
			StrCpy $LogPath $R2
		${Else}
			StrCpy $LogPath $R1
		${EndIf}
		StrLen $R2 $LogPath
		${If} $R2 >= 260
			!insertmacro Refuse "/LOG must be shorter than 260 characters as a full path (got '$LogPath')."
		${EndIf}
		; An existing folder (C:\Temp, ., C:)
		${If} ${FileExists} "$LogPath\*"
			!insertmacro Refuse "/LOG must be a file, not a folder (got '$LogPath')."
		${EndIf}
	${EndIf}

	ClearErrors
	ReadRegStr $OldVersion HKCU "${UNINSTALL_KEY}" "DisplayVersion"
	${If} ${Errors}
		StrCpy $OldVersion ""
	${EndIf}
	${If} $OldVersion != ""
		${VersionCompare} $OldVersion "${VERSION}" $VersionOrder
	${EndIf}
FunctionEnd

; --- What runs and what is installed: gathered once, for the first pages ---------------------------------------------------

; Only the files the pages need before the section unpacks the whole tree: install.ps1 and the shared lib (for -CheckRunning), the
; pin (is the Visual C++ Runtime needed), stop-benchy.ps1, and the engine zip (one copy in the installer: the section uses this one)
Function ExtractEarly
	${If} ${FileExists} "$PLUGINSDIR\early\install.ps1"
		Return
	${EndIf}
	InitPluginsDir
	SetDetailsPrint none
	SetOutPath "$PLUGINSDIR\early"
	File "${PAYLOAD}/install.ps1"
	SetOutPath "$PLUGINSDIR\early\pts-core\static\update"
	File "${PAYLOAD}/pts-core/static/update/benchy-windows.ps1"
	File "${PAYLOAD}/pts-core/static/update/runtime-pins.json"
	SetOutPath "$PLUGINSDIR"
	File "stop-benchy.ps1"
!ifdef ENGINEZIP
	File "/oname=engine.zip" "${ENGINEZIP}"
!endif
	SetOutPath $TEMP
	SetDetailsPrint both
FunctionEnd

; Push <version like 0.12.0.0>, Call TrimVersion, Pop <0.12.0>: Windows file versions have four parts, Benchy's have three
Function TrimVersion
	Exch $0
	Push $1
	StrCpy $1 $0 "" -2
	${If} $1 == ".0"
		StrCpy $1 $0 -2
		; only when that leaves three parts
		${WordFind} $1 "." "#" $R9
		${If} $R9 >= 3
			StrCpy $0 $1
		${EndIf}
	${EndIf}
	Pop $1
	Exch $0
FunctionEnd

; Push <path of an exe>, Call ExeVersion, Pop <its version, or "">
Function ExeVersion
	Exch $0
	Push $1
	StrCpy $1 ""
	${If} ${FileExists} $0
		ClearErrors
		${GetFileVersion} $0 $1
		${If} ${Errors}
			StrCpy $1 ""
		${EndIf}
	${EndIf}
	${If} $1 != ""
		Push $1
		Call TrimVersion
		Pop $1
	${EndIf}
	StrCpy $0 $1
	Pop $1
	Exch $0
FunctionEnd

; The wizard asks the machine, with stop-benchy.ps1 -Mode Info: what is running from this install, the agent's state, whether the
; Visual C++ Runtime is needed. $InfoDone says it ran; Force=1 asks again (the Next click: what ran can have changed).
Function GatherInfo
	Call ExtractEarly
	; what is installed now
	Push "$LOCALAPPDATA\Benchy\client\BenchyClient.exe"
	Call ExeVersion
	Pop $OldClientVer
	Push "${ENGINE_EXE}"
	Call ExeVersion
	Pop $OldEngineVer
	StrCpy $RunClient 0
	StrCpy $RunTray 0
	StrCpy $RunAgent 0
	StrCpy $RunLocal 0
	StrCpy $RunConsole 0
	StrCpy $RunJob ""
	StrCpy $ProtVer ""
	StrCpy $PairText ""
	StrCpy $VcNeeded 0
	Delete "$PLUGINSDIR\info.ini"
	nsExec::Exec '"$PowerShell" -NoProfile -NonInteractive -InputFormat None -ExecutionPolicy Bypass -File "$PLUGINSDIR\stop-benchy.ps1" -Mode Info -IniPath "$PLUGINSDIR\info.ini" -EarlyDir "$PLUGINSDIR\early" -Engine "${ENGINE_EXE}" -NewEngineZip "$PLUGINSDIR\engine.zip" -NewEngineDir "$PLUGINSDIR\newengine"'
	Pop $R0
	StrCpy $InfoDone 1
	${IfNot} ${FileExists} "$PLUGINSDIR\info.ini"
		; nothing known: no callout, and install.ps1 still refuses a Benchy that runs (exit code 3)
		StrCpy $NeedStop 0
		StrCpy $StopBlock ""
		Return
	${EndIf}
	ReadINIStr $R0 "$PLUGINSDIR\info.ini" "running" "client"
	StrCpy $RunClient $R0
	ReadINIStr $RunTray "$PLUGINSDIR\info.ini" "running" "client_tray"
	ReadINIStr $RunAgent "$PLUGINSDIR\info.ini" "running" "agent"
	ReadINIStr $R1 "$PLUGINSDIR\info.ini" "running" "run"
	ReadINIStr $R2 "$PLUGINSDIR\info.ini" "running" "console"
	ReadINIStr $R3 "$PLUGINSDIR\info.ini" "running" "php"
	ReadINIStr $R4 "$PLUGINSDIR\info.ini" "agent" "state"
	ReadINIStr $RunJob "$PLUGINSDIR\info.ini" "agent" "job_title"
	ReadINIStr $VcNeeded "$PLUGINSDIR\info.ini" "vc" "needed"
	ReadINIStr $ProtVer "$PLUGINSDIR\info.ini" "agent" "protected_version"
	StrCpy $ProtOlder 0
!ifdef ENGINE_VERSION
	${If} $ProtVer != ""
		${VersionCompare} $ProtVer "${ENGINE_VERSION}" $R0
		${If} $R0 = 2
			StrCpy $ProtOlder 1
		${EndIf}
	${EndIf}
!endif
	ReadINIStr $R5 "$PLUGINSDIR\info.ini" "agent" "server"
	ReadINIStr $R6 "$PLUGINSDIR\info.ini" "agent" "system_title"
	${If} $R1 == 1
	${OrIf} $R4 == "busy"
		StrCpy $RunLocal 1
	${EndIf}
	${If} $R2 == 1
	${OrIf} $R3 == 1
		StrCpy $RunConsole 1
	${EndIf}
	; "pairing with seinar as KAMINO": the host of the server, not its https://
	${If} $R5 != ""
	${AndIf} $R5 != "null"
		StrCpy $R7 $R5 8
		${If} $R7 == "https://"
			StrCpy $R5 $R5 "" 8
		${EndIf}
		StrCpy $PairText "$R5"
		${If} $R6 != ""
		${AndIf} $R6 != "null"
			StrCpy $PairText "$R5 as $R6"
		${EndIf}
	${EndIf}
	; what the install will close
	${If} $RunClient == 1
	${OrIf} $RunAgent == 1
		StrCpy $NeedStop 1
	${Else}
		StrCpy $NeedStop 0
	${EndIf}
	; what stops Next: something only the person can end
	StrCpy $StopBlock ""
	${If} $RunConsole == 1
		StrCpy $StopBlock "Close the Benchy command prompt window (and anything else running Benchy's PHP), then click Next."
	${ElseIf} $RunClient == 1
	${AndIf} $RunLocal == 1
		; a client older than 0.12 cannot be asked to stop its run, and a newer one answers BUSY to quit-for-update and stays: either way
		; the person finishes or stops it
		StrCpy $StopBlock "A test is running in Benchy. Finish or stop it, then click Next."
	${ElseIf} $RunClient <> 1
	${AndIf} $RunLocal == 1
	${AndIf} $RunAgent <> 1
		StrCpy $StopBlock "A test is running from the command line. Let it finish, then click Next."
	${EndIf}
FunctionEnd

; The agent was running with a job, or only a local run: the callout's text
Function RunningText
	${If} $RunClient == 1
		StrCpy $R8 "Benchy and its tray close for the upgrade, then start again."
	${Else}
		StrCpy $R8 "The server agent stops for the upgrade."
	${EndIf}
	${If} $RunJob != ""
		StrCpy $R8 "$R8 Job $\"$RunJob$\" is stopped; finished tests upload first."
	${ElseIf} $RunLocal == 1
		StrCpy $R8 "$R8 The test in Benchy is stopped; its partial result is kept."
	${ElseIf} $RunAgent == 1
		StrCpy $R8 "$R8 The agent is idle, so no job is interrupted."
	${EndIf}
FunctionEnd

; --- Page 1: Install / Upgrade ---------------------------------------------------------------------------------------

Function InstallPageCreate
	${If} $OldVersion == ""
		!insertmacro BuiHeader "Install Benchy ${VERSION}" "Benchmark this computer, and run scheduled tests."
	${ElseIf} $VersionOrder = 0
		!insertmacro BuiHeader "Reinstall Benchy ${VERSION}" "Benchy ${VERSION} is already installed."
	${ElseIf} $VersionOrder = 1
		!insertmacro BuiHeader "Downgrade Benchy $OldVersion to ${VERSION}" "A newer Benchy is installed."
	${Else}
		!insertmacro BuiHeader "Upgrade Benchy $OldVersion to ${VERSION}" "Benchmark this computer, and run scheduled tests."
	${EndIf}

	Call GatherInfo

	nsDialogs::Create 1018
	Pop $InstallDialog
	${If} $InstallDialog == error
		Abort
	${EndIf}

	; $R7: the Bui macros below use $R0 (it once held a window handle here, and the page read "installs for 196672")
	ReadEnvStr $R7 USERNAME
	${If} $OldVersion == ""
		!insertmacro BuiStrip 1 "Install" "Installing" "Server" "Done"
		!insertmacro BuiText 14 20 "Benchy installs for $R7 on this computer. You don't need administrator rights."
		!insertmacro BuiFrame 36 48
		!ifdef CLIENT_VERSION
			!insertmacro BuiRow 38 ok "Benchy app and tray" "client ${CLIENT_VERSION}" muted
		!else
			!insertmacro BuiRow 38 ok "Benchy app and command line" "${VERSION}" muted
		!endif
		!ifdef ENGINE_VERSION
			!insertmacro BuiRow 49 ok "Native engine and the PHP engine" "${ENGINE_VERSION} / ${VERSION}" muted
		!else
			!insertmacro BuiRow 49 ok "The PHP engine" "${VERSION}" muted
		!endif
		!insertmacro BuiRow 60 ok "Start menu entry and tray log on entry" "your account" muted
		${If} $VcNeeded == 1
			!insertmacro BuiRow 71 ok "Visual C++ Runtime, if it's missing" "all users" warn
		${Else}
			!insertmacro BuiRow 71 ok "Visual C++ Runtime" "already there" muted
		${EndIf}
		StrCpy $R9 88
	${Else}
		!insertmacro BuiStrip 1 "Upgrade" "Installing" "Server" "Done"
		!insertmacro BuiTable 13 "Part" "Now" "After" ""
		; the unattended agent's row only when one is set up here
		!ifdef CLIENT_VERSION
			!insertmacro BuiTableRow 23 "Benchy app" "$OldClientVer" "${CLIENT_VERSION}" "" muted
		!endif
		!ifdef ENGINE_VERSION
			${If} $OldEngineVer == "${ENGINE_VERSION}"
				!insertmacro BuiTableRow 33 "Native engine" "$OldEngineVer" "${ENGINE_VERSION}" "unchanged" muted
			${Else}
				!insertmacro BuiTableRow 33 "Native engine" "$OldEngineVer" "${ENGINE_VERSION}" "" muted
			${EndIf}
		!endif
		${If} $OldVersion == "${VERSION}"
			!insertmacro BuiTableRow 43 "PHP engine" "$OldVersion" "${VERSION}" "unchanged" muted
		${Else}
			!insertmacro BuiTableRow 43 "PHP engine" "$OldVersion" "${VERSION}" "" muted
		${EndIf}
		StrCpy $R9 53
		${If} $ProtVer != ""
			!ifdef ENGINE_VERSION
				${If} $ProtOlder = 1
					!insertmacro BuiTableRow 53 "Unattended agent" "$ProtVer" "${ENGINE_VERSION}" "asks Windows once" warn
				${Else}
					!insertmacro BuiTableRow 53 "Unattended agent" "$ProtVer" "$ProtVer" "unchanged" muted
				${EndIf}
				StrCpy $R9 63
			!endif
		${EndIf}
		!insertmacro BuiRow $R9 ok "Results, settings and installed tests" "kept" muted
		IntOp $R9 $R9 + 10
		${If} $PairText != ""
			!insertmacro BuiRow $R9 ok "Pairing with $PairText" "kept" muted
			IntOp $R9 $R9 + 10
		${EndIf}
		IntOp $R9 $R9 + 3
	${EndIf}
	StrCpy $InstallY $R9
	Call InstallPageRest
FunctionEnd

; The rest of page 1, from $InstallY down: the check boxes, the callout about what runs, the note
Function InstallPageRest
	StrCpy $R9 $InstallY
	StrCpy $AutostartBox ""
	StrCpy $UpdateBox ""
	${If} $OldVersion == ""
!ifdef CLIENTZIP
		${NSD_CreateCheckbox} 0 $R9u 100% 10u "Start Benchy in the tray when I log in"
		Pop $AutostartBox
		!insertmacro BuiFontOn $AutostartBox
		${NSD_Check} $AutostartBox
		IntOp $R9 $R9 + 13
!endif
		${If} ${IsNativeARM64}
			!insertmacro BuiNote $R9 28 "Installs to %LOCALAPPDATA%\Benchy. This is ARM64 Windows: Benchy installs its x64 PHP, which runs under emulation; this is untested."
		${Else}
			!insertmacro BuiNote $R9 28 "Installs to %LOCALAPPDATA%\Benchy. Windows asks only if the Visual C++ Runtime has to be installed. Unattended administrator tests are set up later, in Settings."
		${EndIf}
	${Else}
		${If} $ProtOlder = 1
			${NSD_CreateCheckbox} 0 $R9u 100% 10u "Update the unattended agent too"
			Pop $UpdateBox
			!insertmacro BuiFontOn $UpdateBox
			${NSD_Check} $UpdateBox
			IntOp $R9 $R9 + 11
		${EndIf}
		${If} $StopBlock != ""
			!insertmacro BuiCallout $R9 32 bad "Benchy is busy" "$StopBlock"
		${ElseIf} $NeedStop = 1
			Call RunningText
			!insertmacro BuiCallout $R9 32 warn "Benchy is running" "$R8"
		${EndIf}
	${EndIf}

	${If} $VcNeeded == 1
		!insertmacro BuiShield 1
	${Else}
		!insertmacro BuiShield 0
	${EndIf}
	GetDlgItem $R0 $HWNDPARENT 1
	${If} $OldVersion == ""
		SendMessage $R0 ${WM_SETTEXT} 0 "STR:&Install"
	${ElseIf} $VersionOrder = 0
		SendMessage $R0 ${WM_SETTEXT} 0 "STR:&Reinstall"
	${ElseIf} $VersionOrder = 1
		SendMessage $R0 ${WM_SETTEXT} 0 "STR:&Downgrade"
	${Else}
		SendMessage $R0 ${WM_SETTEXT} 0 "STR:&Upgrade"
	${EndIf}

	nsDialogs::Show
FunctionEnd

Function InstallPageLeave
	${If} $AutostartBox != ""
		${NSD_GetState} $AutostartBox $AutostartMode
	${EndIf}
	${If} $UpdateBox != ""
		${NSD_GetState} $UpdateBox $UpdateAgent
	${EndIf}
	; Ask again: Benchy may have been started or closed since the page was drawn
	Call GatherInfo
	${If} $StopBlock != ""
		MessageBox MB_OK|MB_ICONINFORMATION "$StopBlock"
		Abort
	${EndIf}
	; Only the wizard asks: /S never shows this page, so scripted downgrades go ahead (deployers mean what they push)
	${If} $OldVersion != ""
	${AndIf} $VersionOrder = 1
		MessageBox MB_YESNO|MB_ICONQUESTION|MB_DEFBUTTON2 "This will replace Benchy $OldVersion with the older ${VERSION}.$\r$\n$\r$\nContinue?" IDYES downgrade_confirmed
		Abort
		downgrade_confirmed:
	${EndIf}
FunctionEnd

; --- Page 2: progress (the install itself) ----------------------------------------------------------------------

; Push <path of VC_redist.x64.exe>, Call PinAndCheckVcRedist, Pop <1 when it may run, 0 when it must not>.
; Opens the file for reading with read sharing only (5 tries, 200 ms apart: a virus scanner may hold a fresh file for a
; moment) and leaves the handle in $VcHandle (-1 when it could not be opened), for the caller to close once the
; installer has exited: while it is open no process can write, replace, rename or delete the file, and Windows can
; still start it (the image loader only reads).
; The handle pins the file, not the path: a junction in the path could be re-pointed after the check. So, after the
; handle is taken: the vc folder must be the one this installer made, and neither it nor $PLUGINSDIR may be a junction
; or symbolic link; and the file is hashed and started by $VcFinalPath, the handle's own final path (every link
; resolved), whose folders are all real folders that cannot be renamed or deleted while the file in them is open.
; Then checks the size (from the handle) and sha256 against VCREDIST_SIZE and VCREDIST_SHA256, built into this exe by
; release.sh, so nothing in $PLUGINSDIR decides. The hash comes from certutil in the system folder, its output
; through a pipe.
Function PinAndCheckVcRedist
	Exch $0 ; path
	Push $1
	Push $2
	Push $3
	Push $4
	Push $5
	Push $6
	StrCpy $4 0
	StrCpy $VcFinalPath ""
	StrCpy $5 1
	${Do}
		; GENERIC_READ, FILE_SHARE_READ, OPEN_EXISTING, FILE_FLAG_SEQUENTIAL_SCAN
		System::Call 'kernel32::CreateFileW(w r0, i 0x80000000, i 1, p 0, i 3, i 0x08000000, p 0) i .r1 ?e'
		Pop $2 ; GetLastError
		${If} $1 <> -1
		${OrIf} $5 >= 5
			${ExitDo}
		${EndIf}
		IntOp $5 $5 + 1
		Sleep 200
	${Loop}
	StrCpy $VcHandle $1
	; INVALID_FILE_ATTRIBUTES (-1) has the reparse point bit set too, so a folder that cannot be read is refused
	System::Call 'kernel32::GetFileAttributesW(w "$PLUGINSDIR\vc") i .r5'
	System::Call 'kernel32::GetFileAttributesW(w "$PLUGINSDIR") i .r6'
	IntOp $5 $5 | $6
	IntOp $5 $5 & 0x400 ; FILE_ATTRIBUTE_REPARSE_POINT
	${If} $1 = -1
		DetailPrint "  Rejected: it could not be opened for the check (Windows error $2)."
	${ElseIf} $VcDirFresh <> 1
		DetailPrint "  Rejected: its folder $PLUGINSDIR\vc was already there before this installer made it."
	${ElseIf} $5 <> 0
		DetailPrint "  Rejected: $PLUGINSDIR\vc or $PLUGINSDIR is a junction or symbolic link."
	${Else}
		; VOLUME_NAME_DOS, FILE_NAME_NORMALIZED: \\?\C:\...; drop the \\?\ for certutil and ShellExecuteEx
		System::Call 'kernel32::GetFinalPathNameByHandleW(p r1, w .r5, i ${NSIS_MAX_STRLEN}, i 0) i .r3 ?e'
		Pop $2
		${If} $3 = 0
		${OrIf} $3 >= ${NSIS_MAX_STRLEN}
			DetailPrint "  Rejected: its final path could not be read (Windows error $2)."
		${Else}
			StrCpy $6 $5 8
			${If} $6 == "\\?\UNC\"
				StrCpy $5 $5 "" 8
				StrCpy $VcFinalPath "\\$5"
			${Else}
				StrCpy $6 $5 4
				${If} $6 == "\\?\"
					StrCpy $5 $5 "" 4
				${EndIf}
				StrCpy $VcFinalPath $5
			${EndIf}
			DetailPrint "  $VcFinalPath"
			System::Call 'kernel32::GetFileSizeEx(p r1, *l .r2) i .r3'
			${If} $3 = 0
				DetailPrint "  Rejected: its size could not be read."
			${ElseIf} $2 != "${VCREDIST_SIZE}"
				DetailPrint "  Rejected: it has $2 bytes, this installer expects ${VCREDIST_SIZE}."
			${Else}
				; The 64-bit certutil, as for PowerShell; $SYSDIR (SysWOW64 for this 32-bit stub) when there is none
				StrCpy $6 "$WINDIR\Sysnative\certutil.exe"
				${IfNot} ${FileExists} $6
					StrCpy $6 "$SYSDIR\certutil.exe"
				${EndIf}
				nsExec::ExecToStack '"$6" -hashfile "$VcFinalPath" SHA256'
				Pop $1 ; exit code
				Pop $2 ; output
				${If} $1 != 0
					DetailPrint "  Rejected: certutil could not hash it (exit code $1)."
				${Else}
					Push $2
					Call CertutilSha256
					Pop $3
					${If} $3 == ""
						DetailPrint "  Rejected: no sha256 in certutil's output."
					${ElseIf} $3 != "${VCREDIST_SHA256}"
						DetailPrint "  Rejected: sha256 $3, this installer expects ${VCREDIST_SHA256}."
					${Else}
						DetailPrint "  ${VCREDIST_SIZE} bytes, sha256 $3: as expected."
						StrCpy $4 1
					${EndIf}
				${EndIf}
			${EndIf}
		${EndIf}
	${EndIf}
	StrCpy $0 $4
	Pop $6
	Pop $5
	Pop $4
	Pop $3
	Pop $2
	Pop $1
	Exch $0
FunctionEnd

; --- Page 2: the step list (the instfiles page, skinned) ---------------------------------------------------------------------

; Row numbers of the step list ($Row* = -1 when a row is not there): [stop] check vcredist copy entries engines [agent]
; Sets row <_ROW> to <_STATE> (0 pending, 1 now, 2 done, 3 failed) with the tag <_TAG>. Safe from the section's thread (SendMessage
; and ShowWindow only), and nothing in /S, where there is no page.
!macro StepRow _ROW _STATE _TAG
	${If} $StepsOn = 1
	${AndIf} ${_ROW} >= 0
		StrCpy $BuiA1 $mui.InstFilesPage
		StrCpy $BuiA2 ${_ROW}
		StrCpy $BuiA3 ${_STATE}
		StrCpy $BuiA4 "${_TAG}"
		Call BuiStepSet
	${EndIf}
!macroend

; One step of the list: BuiStepAdd on the next free row; <_ROWVAR> gets its row number. Pixel geometry: left $R3, top $R4, width $R5, row height $R6.
!macro StepAdd _ROWVAR _LABEL
	StrCpy ${_ROWVAR} $RowCount
	StrCpy $BuiA1 $mui.InstFilesPage
	StrCpy $BuiA2 $R3
	IntOp $BuiA3 $R4 + $RowTop
	StrCpy $BuiA4 $R5
	StrCpy $BuiA5 $R6
	StrCpy $BuiA6 $RowCount
	StrCpy $BuiA7 "${_LABEL}"
	Call BuiStepAdd
	IntOp $RowTop $RowTop + $R6
	IntOp $RowCount $RowCount + 1
!macroend

; Moves a control of the instfiles page down by $R2 pixels; $R1 = 1 also makes it that much shorter. Push <control id>.
Function InstFilesShift
	Exch $0
	Push $1
	Push $2
	Push $3
	Push $4
	Push $5
	Push $6
	GetDlgItem $1 $mui.InstFilesPage $0
	${If} $1 <> 0
		; its rectangle in the page's own coordinates: left, top, right, bottom
		System::Call '*(i,i,i,i) p .r2'
		System::Call 'user32::GetWindowRect(p r1, p r2)'
		System::Call 'user32::MapWindowPoints(p 0, p $mui.InstFilesPage, p r2, i 2)'
		System::Call '*$2(i .r3, i .r4, i .r5, i .r6)'
		IntOp $5 $5 - $3
		IntOp $6 $6 - $4
		IntOp $4 $4 + $R2
		${If} $R1 = 1
			IntOp $6 $6 - $R2
		${EndIf}
		; SWP_NOZORDER | SWP_NOACTIVATE
		System::Call 'user32::SetWindowPos(p r1, p 0, i r3, i r4, i r5, i r6, i 0x14)'
		System::Free $2
	${EndIf}
	Pop $6
	Pop $5
	Pop $4
	Pop $3
	Pop $2
	Pop $1
	Pop $0
FunctionEnd

; The instfiles page keeps NSIS's list and its Show details button; the step list is drawn over the hidden list, under the strip. The
; section changes the rows as install.ps1 reports its steps (-ProgressFile), and the stock list shows over them when asked for.
Function InstFilesShow
	StrCpy $StepsOn 0
	StrCpy $RowCount 0
	StrCpy $RowStop -1
	StrCpy $RowAgent -1
	${If} $mui.InstFilesPage.Log = 0
		Return
	${EndIf}
	; the strip's height (12u) and a row's (11u), in pixels
	System::Call '*(i 0, i 0, i 0, i 12) p .r1'
	System::Call 'user32::MapDialogRect(p $mui.InstFilesPage, p r1)'
	System::Call '*$1(i, i, i, i .R2)'
	System::Free $1
	; the strip's labels are that high, and its line sits under them
	IntOp $R7 $R2 + 2
	System::Call '*(i 0, i 0, i 0, i 11) p .r1'
	System::Call 'user32::MapDialogRect(p $mui.InstFilesPage, p r1)'
	System::Call '*$1(i, i, i, i .R6)'
	System::Free $1
	System::Call '*(i,i,i,i) p .r1'
	System::Call 'user32::GetClientRect(p $mui.InstFilesPage, p r1)'
	System::Call '*$1(i, i, i .R5, i)'
	System::Free $1
	; everything down by the strip, the stock list shorter by as much
	StrCpy $R2 $R7
	Push 1004
	StrCpy $R1 0
	Call InstFilesShift
	Push 1006
	Call InstFilesShift
	Push 1027
	Call InstFilesShift
	Push 1016
	StrCpy $R1 1
	Call InstFilesShift
	; the strip: four steps and a line
	StrCpy $R2 $R7
	StrCpy $0 $mui.InstFilesPage
	StrCpy $2 0
	StrCpy $4 $R7
	IntOp $3 $R5 / 4
	StrCpy $1 0
	StrCpy $5 4900
	StrCpy $6 0x54000080
	StrCpy $7 "1  Install"
	StrCpy $8 ${BUI_MUTED}
	StrCpy $9 $BuiFont
	Call BuiMakeStatic
	StrCpy $1 $3
	IntOp $5 $5 + 1
	StrCpy $7 "2  Installing"
	StrCpy $8 ${BUI_ACCENT}
	StrCpy $9 $BuiFontBold
	Call BuiMakeStatic
	IntOp $1 $3 * 2
	IntOp $5 $5 + 1
	StrCpy $7 "3  Server"
	StrCpy $8 ${BUI_MUTED}
	StrCpy $9 $BuiFont
	Call BuiMakeStatic
	IntOp $1 $3 * 3
	IntOp $5 $5 + 1
	StrCpy $7 "4  Done"
	Call BuiMakeStatic
	IntOp $5 $5 + 1
	StrCpy $4 1
	IntOp $2 $R7 - 2
	StrCpy $1 0
	StrCpy $3 $R5
	StrCpy $6 0x50000010
	StrCpy $7 ""
	Call BuiMakeStatic
	; the rows start below the Show details button, which sits at the top of the stock list's area (it goes when the list is shown)
	System::Call '*(i,i,i,i) p .r1'
	System::Call 'user32::GetWindowRect(p $mui.InstFilesPage.Log, p r1)'
	System::Call 'user32::MapWindowPoints(p 0, p $mui.InstFilesPage, p r1, i 2)'
	System::Call '*$1(i .R3, i .R4, i, i)'
	GetDlgItem $0 $mui.InstFilesPage 1027
	StrCpy $RowTop 0
	${If} $0 <> 0
		System::Call 'user32::GetWindowRect(p r0, p r1)'
		System::Call 'user32::MapWindowPoints(p 0, p $mui.InstFilesPage, p r1, i 2)'
		System::Call '*$1(i, i, i, i .r2)'
		IntOp $RowTop $2 + 4
		IntOp $RowTop $RowTop - $R4
		${If} $RowTop < 0
			StrCpy $RowTop 0
		${EndIf}
	${EndIf}
	System::Free $1
	IntOp $R5 $R5 - $R3
	${If} $NeedStop = 1
		!insertmacro StepAdd $RowStop "Close Benchy"
	${EndIf}
	!insertmacro StepAdd $RowCheck "Check the files against their checksums"
	!insertmacro StepAdd $RowVc "Visual C++ Runtime"
	!insertmacro StepAdd $RowCopy "Copy Benchy and the engines"
	!insertmacro StepAdd $RowEntries "Add the Start menu and log on entries"
	!insertmacro StepAdd $RowEngines "Check that both engines start"
	${If} $UpdateAgent = 1
		!insertmacro StepAdd $RowAgent "Update the unattended agent"
	${EndIf}
	; the stock list goes back on top of the rows, for when Show details is clicked
	System::Call 'user32::SetWindowPos(p $mui.InstFilesPage.Log, p 0, i 0, i 0, i 0, i 0, i 0x13)'
	; the bar has no end in sight while a step runs: marquee
	GetDlgItem $R0 $mui.InstFilesPage 1004
	System::Call 'user32::GetWindowLongW(p R0, i -16) i .r1'
	IntOp $1 $1 | 8
	System::Call 'user32::SetWindowLongW(p R0, i -16, i r1)'
	SendMessage $R0 0x40A 1 30
	StrCpy $StepsOn 1
FunctionEnd

; --- progress.txt: install.ps1's "step <id> start|done|fail" lines, read while it runs ----------------------------------------

; Push <line>, Call ProgressLine. Row numbers from the Row* variables.
Function ProgressLine
	Exch $0
	Push $1
	Push $2
	Push $3
	StrCpy $1 $0 5
	${If} $1 == "step "
		${WordFind} $0 " " "+2" $1
		${WordFind} $0 " " "+3" $2
		StrCpy $2 $2 4
		StrCpy $3 -1
		${If} $1 == "check"
			StrCpy $3 $RowCheck
		${ElseIf} $1 == "vcredist"
			StrCpy $3 $RowVc
		${ElseIf} $1 == "copy"
			StrCpy $3 $RowCopy
		${ElseIf} $1 == "entries"
			StrCpy $3 $RowEntries
		${ElseIf} $1 == "engines"
			StrCpy $3 $RowEngines
		${EndIf}
		${If} $1 == "engines"
		${AndIf} $2 == "fail"
			StrCpy $EnginesOk 0
		${EndIf}
		${If} $3 >= 0
			${If} $2 == "star"
				StrCpy $NowRow $3
				!insertmacro StepRow $3 1 "now"
			${ElseIf} $2 == "done"
				StrCpy $NowRow -1
				StrCpy $LastDone $3
				!insertmacro StepRow $3 2 "done"
			${ElseIf} $2 == "fail"
				StrCpy $NowRow -1
				StrCpy $FailRow $3
				!insertmacro StepRow $3 3 "failed"
			${EndIf}
		${EndIf}
	${EndIf}
	Pop $3
	Pop $2
	Pop $1
	Pop $0
FunctionEnd

; Reads the lines added to progress.txt since the last time; a last line with no end yet waits for the next
Function ProgressPoll
	Push $0
	Push $1
	Push $2
	Push $3
	ClearErrors
	FileOpen $0 "$PLUGINSDIR\progress.txt" r
	${IfNot} ${Errors}
		StrCpy $1 0
		${Do}
			FileRead $0 $2
			${If} ${Errors}
				${ExitDo}
			${EndIf}
			StrCpy $3 $2 1 -1
			${If} $3 != "$\n"
				${ExitDo}
			${EndIf}
			IntOp $1 $1 + 1
			${If} $1 > $ProgressDone
				StrCpy $ProgressDone $1
				Push $2
				Call ProgressLine
			${EndIf}
		${Loop}
		FileClose $0
	${EndIf}
	Pop $3
	Pop $2
	Pop $1
	Pop $0
FunctionEnd

; Runs $RunCmd hidden and waits for it, reading progress.txt every 400 ms (the section's own thread; the window stays alive). The exit
; code goes to $ExitCode, "error" when the process could not be started. NOT nsExec: a loop of ours is what keeps the step list moving.
Function RunInstallPs
	Push $0
	Push $1
	Push $2
	Push $3
	Push $4
	Push $5
	Push $6
	StrCpy $2 $RunCmd
	; STARTUPINFOW (68 bytes in this 32-bit stub): STARTF_USESHOWWINDOW and SW_HIDE; PROCESS_INFORMATION (16 bytes)
	System::Call '*(i 68, p 0, p 0, p 0, i 0, i 0, i 0, i 0, i 0, i 0, i 0, i 1, &i2 0, &i2 0, p 0, p 0, p 0, p 0) p .r0'
	System::Call '*(p 0, p 0, i 0, i 0) p .r1'
	; CREATE_NO_WINDOW
	System::Call 'kernel32::CreateProcessW(p 0, w r2, p 0, p 0, i 0, i 0x08000000, p 0, p 0, p r0, p r1) i .r3'
	${If} $3 = 0
		StrCpy $ExitCode "error"
	${Else}
		System::Call '*$1(p .r4, p .r5)'
		${Do}
			System::Call 'kernel32::WaitForSingleObject(p r4, i 400) i .r6'
			${If} $StepsOn = 1
				Call ProgressPoll
			${EndIf}
			${If} $6 <> 258
				${ExitDo}
			${EndIf}
		${Loop}
		${If} $StepsOn = 1
			Call ProgressPoll
		${EndIf}
		System::Call 'kernel32::GetExitCodeProcess(p r4, *i .r6)'
		System::Call 'kernel32::CloseHandle(p r4)'
		System::Call 'kernel32::CloseHandle(p r5)'
		StrCpy $ExitCode $6
	${EndIf}
	System::Free $0
	System::Free $1
	Pop $6
	Pop $5
	Pop $4
	Pop $3
	Pop $2
	Pop $1
	Pop $0
FunctionEnd

; The install log (install.ps1's transcript) into the details list, for "Show details"
Function LogToDetails
	Push $0
	Push $1
	Push $2
	ClearErrors
	FileOpen $0 "$LogPath" r
	${IfNot} ${Errors}
		${Do}
			FileRead $0 $1
			${If} ${Errors}
				${ExitDo}
			${EndIf}
			; no line end: NSIS prints it as it is
			StrCpy $2 $1 1 -1
			${If} $2 == "$\n"
				StrCpy $1 $1 -1
			${EndIf}
			StrCpy $2 $1 1 -1
			${If} $2 == "$\r"
				StrCpy $1 $1 -1
			${EndIf}
			DetailPrint "  $1"
		${Loop}
		FileClose $0
	${EndIf}
	Pop $2
	Pop $1
	Pop $0
FunctionEnd

; --- Closing Benchy before the copy, and starting it again after ------------------------------------------------------------

; stop-benchy.ps1 -Mode Stop: the client is asked to quit (an older one is ended once nothing is running in it), an agent with no client
; is cancelled through the engine. Result in $StopMessage ("" when it went well) and $WasClient (0 none, 1 window, 2 tray).
Function StopRunning
	StrCpy $StopMessage ""
	StrCpy $WasClient 0
	Delete "$PLUGINSDIR\stop.ini"
	nsExec::Exec '"$PowerShell" -NoProfile -NonInteractive -InputFormat None -ExecutionPolicy Bypass -File "$PLUGINSDIR\stop-benchy.ps1" -Mode Stop -IniPath "$PLUGINSDIR\stop.ini" -EarlyDir "$PLUGINSDIR\early" -Engine "${ENGINE_EXE}"'
	Pop $R0
	ReadINIStr $R1 "$PLUGINSDIR\stop.ini" "stop" "result"
	${If} $R1 == ""
		StrCpy $StopMessage "Benchy could not be checked: the check did not run."
		Return
	${EndIf}
	ReadINIStr $R2 "$PLUGINSDIR\stop.ini" "stop" "was_client"
	ReadINIStr $R3 "$PLUGINSDIR\stop.ini" "stop" "was_window"
	${If} $R2 == 1
		${If} $R3 == 1
			StrCpy $WasClient 1
		${Else}
			StrCpy $WasClient 2
		${EndIf}
	${EndIf}
	${If} $R1 == "busy"
	${OrIf} $R1 == "console"
	${OrIf} $R1 == "failed"
		ReadINIStr $StopMessage "$PLUGINSDIR\stop.ini" "stop" "message"
		${If} $StopMessage == ""
			StrCpy $StopMessage "Benchy is running and could not be closed."
		${EndIf}
	${EndIf}
FunctionEnd

; The client goes back the way it was (window or tray), unelevated: through the desktop shell when this installer is elevated, so the
; client and the agent it starts do not run as administrator. The tray's flag cannot go through the shell, so an elevated installer
; opens the window (the Start menu entry) instead.
Function RestartBenchy
	${If} $RestartDone = 1
	${OrIf} $Ok <> 1
	${OrIf} $WasClient = 0
		Return
	${EndIf}
	StrCpy $RestartDone 1
	${IfNot} ${FileExists} "$LOCALAPPDATA\Benchy\client\BenchyClient.exe"
		Return
	${EndIf}
	System::Call 'shell32::IsUserAnAdmin() i .r0'
	${If} $0 <> 0
		Exec '"$WINDIR\explorer.exe" "$SMPROGRAMS\Benchy.lnk"'
	${ElseIf} $WasClient = 2
		Exec '"$LOCALAPPDATA\Benchy\client\BenchyClient.exe" --tray'
	${Else}
		Exec '"$LOCALAPPDATA\Benchy\client\BenchyClient.exe"'
	${EndIf}
FunctionEnd

; Closing the wizard in any way after a good install (also at the server page) still puts Benchy back
Function .onGUIEnd
	Call RestartBenchy
FunctionEnd

; MUI2 owns .onGUIInit; this is its hook for it
Function GuiInit
	Call BuiInitFonts
FunctionEnd

; After a good install: the uninstaller next to the app, and Apps & Features pointed at it (install.ps1 registered uninstall.ps1, and a reinstall
; over an older Benchy puts that back each time, so this runs every time). When it cannot be written the entry stays as install.ps1 left it.
Function WriteBenchyUninstaller
	ClearErrors
	WriteUninstaller "$LOCALAPPDATA\Benchy\uninstall.exe"
	${IfNot} ${Errors}
		WriteRegStr HKCU "${UNINSTALL_KEY}" "UninstallString" '"$LOCALAPPDATA\Benchy\uninstall.exe"'
		WriteRegStr HKCU "${UNINSTALL_KEY}" "QuietUninstallString" '"$LOCALAPPDATA\Benchy\uninstall.exe" /S'
	${Else}
		DetailPrint "The uninstaller could not be written; Apps & Features keeps uninstall.ps1."
	${EndIf}
FunctionEnd

; After a good install: the log on entry the Install page asked for, and the unattended agent's update when its box was ticked
Function PostInstall
	${If} $AutostartMode == 1
	${AndIf} ${FileExists} "$LOCALAPPDATA\Benchy\client\BenchyClient.exe"
		; the same value the client's own start-at-login setting writes (and un-disables a switch Task Manager had turned off)
		WriteRegStr HKCU "Software\Microsoft\Windows\CurrentVersion\Run" "Benchy" '"$LOCALAPPDATA\Benchy\client\BenchyClient.exe" --tray'
		DeleteRegValue HKCU "Software\Microsoft\Windows\CurrentVersion\Explorer\StartupApproved\Run" "Benchy"
	${EndIf}
	${If} $UpdateAgent = 1
	${AndIf} ${FileExists} "${ENGINE_EXE}"
		; The engine raises the one prompt itself (setup-elevated starts an elevated copy of itself); this window lets that prompt
		; come to the front. Declined or failed: the install still succeeded, and the Done page says the agent is where it was.
		!insertmacro StepRow $RowAgent 1 "asks Windows"
		DetailPrint "Updating the unattended agent (Windows asks once)..."
		System::Call 'user32::AllowSetForegroundWindow(i -1)'
		nsExec::ExecToLog '"${ENGINE_EXE}" --json agent setup-elevated --update'
		Pop $R0
		${If} $R0 == 0
			StrCpy $AgentResult "updated"
			!insertmacro StepRow $RowAgent 2 "updated"
		${Else}
			StrCpy $AgentResult "not"
			!insertmacro StepRow $RowAgent 3 "not updated"
		${EndIf}
	${EndIf}
FunctionEnd

Section "Benchy"
	; 64-bit Windows PowerShell: this stub is 32-bit, and $SYSDIR would give the 32-bit one (wrong registry view for
	; VC++, wrong bitness for PHP). Sysnative is the 64-bit System32 as a 32-bit process sees it.
	StrCpy $PowerShell "$WINDIR\Sysnative\WindowsPowerShell\v1.0\powershell.exe"

	StrCpy $InstFilesHeader "$(MUI_TEXT_FINISH_TITLE)"
	StrCpy $InstFilesSubHeader "$(MUI_TEXT_FINISH_SUBTITLE)"
	StrCpy $ProgressDone 0
	StrCpy $NowRow -1
	StrCpy $LastDone -1
	StrCpy $FailRow -1
	StrCpy $EnginesOk 1
	StrCpy $AgentResult ""
	StrCpy $NotStarted ""
	StrCpy $NotStartedCode ""

	; Close a running Benchy (and its agent) first. The wizard's page found out what runs; /S has no page and always looks.
	!insertmacro StepRow $RowCheck 1 "unpacking"
	InitPluginsDir
	Call ExtractEarly
	Delete "$PLUGINSDIR\progress.txt"
	${If} $NeedStop = 1
	${OrIf} ${Silent}
		DetailPrint "Looking for a running Benchy..."
		!insertmacro StepRow $RowStop 1 "now"
		Call StopRunning
		${If} $StopMessage == ""
			!insertmacro StepRow $RowStop 2 "done"
		${Else}
			!insertmacro StepRow $RowStop 3 "failed"
			DetailPrint "$StopMessage"
			StrCpy $NotStarted $StopMessage
			StrCpy $NotStartedCode 3
		${EndIf}
	${EndIf}

	DetailPrint "Unpacking Benchy ${VERSION}..."
	SetDetailsPrint textonly
	SetOutPath "$PLUGINSDIR\tree"
	File /r "${PAYLOAD}/*"
	SetOutPath "$PLUGINSDIR\php"
	File "/oname=php.zip" "${PHPZIP}"
!ifdef CLIENTZIP
	SetOutPath "$PLUGINSDIR\client"
	File "/oname=client.zip" "${CLIENTZIP}"
!endif
	; engine.zip and stop-benchy.ps1 were put in $PLUGINSDIR by ExtractEarly
	SetOutPath "$PLUGINSDIR"
	File "result-to-ini.ps1"
	File "server-page.ps1"
	SetDetailsPrint both
	; SetDetailsPrint textonly did not keep the ~13,000 "Output folder:"/"Extract:" lines out of the details list on
	; Windows 11, so empty the list itself (the instfiles page's list view; none in /S)
	${If} $mui.InstFilesPage.Log <> 0
		SendMessage $mui.InstFilesPage.Log ${LVM_DELETEALLITEMS} 0 0
	${EndIf}
	DetailPrint "Unpacked Benchy ${VERSION}."
	; Not the temp dir as the working directory of what runs next: it holds NSIS's own System.dll, which the C# compiler
	; behind PowerShell's Add-Type picked up instead of .NET's
	SetOutPath $TEMP

	StrCpy $ResultMessage ""
	StrCpy $RebootRecommended 0
	StrCpy $RebootRequired 0
	StrCpy $Warnings ""

	; install.ps1 gets the folder everything was unpacked to and builds the paths of the zips and the result file from it
	; (-PayloadDir), because NSIS strings stop at 1023 characters: with a path for each file, a user name of 35 or more
	; characters pushed the end of the command (-LogFile, -VcRedistHandOff) over, and it was cut off without a word. The
	; command is also measured below, and refused when it would not fit.
	StrCpy $R0 '"$PowerShell" -NoProfile -NonInteractive -InputFormat None -ExecutionPolicy Bypass -File "$PLUGINSDIR\tree\install.ps1" -NonInteractive -InstallSource gui -PayloadDir "$PLUGINSDIR" -PhpZipMode IfOlder'
	${If} $VcRedist != ""
		StrCpy $R0 '$R0 -VcRedist $VcRedist'
	${EndIf}
	${If} $Phoromatic != ""
		StrCpy $R0 '$R0 -PhoromaticServer "$Phoromatic"'
	${EndIf}
	StrCpy $R5 "$PLUGINSDIR\vc\VC_redist.x64.exe"
	; The longest command line it will be given: the first run, with the hand-off and the log (the second run, with the
	; exit code of the runtime installer instead, is shorter). Everything past 1023 characters would be lost.
	StrCpy $R9 '$R0 -VcRedistHandOff "$R5" -LogFile "$LogPath"'
	StrLen $R9 $R9

	${If} $NotStarted != ""
		; Benchy could not be closed: nothing was changed, and install.ps1 was not run
	${ElseIfNot} ${FileExists} "$PLUGINSDIR\tree\install.ps1"
	${OrIfNot} ${FileExists} "$PLUGINSDIR\php\php.zip"
!ifdef CLIENTZIP
	${OrIfNot} ${FileExists} "$PLUGINSDIR\client\client.zip"
!endif
!ifdef ENGINEZIP
	${OrIfNot} ${FileExists} "$PLUGINSDIR\engine.zip"
!endif
		StrCpy $NotStarted "The installer could not unpack its files to $PLUGINSDIR."
	${ElseIfNot} ${FileExists} $PowerShell
		StrCpy $NotStarted "Windows PowerShell could not be started ($PowerShell)."
	${ElseIf} $R9 >= 1023
		StrCpy $NotStarted "The command that runs the install is too long for this installer (the temporary folder $PLUGINSDIR or the /LOG path is very long). Use a shorter /LOG path, or run the installer from an account with a shorter profile folder name."
	${Else}
		; When the VC++ runtime has to be installed, install.ps1 downloads and checks VC_redist.x64.exe, saves it here (a
		; folder holding nothing else, since the Burn bundle starts from there) and stops with exit code 4; this process
		; then holds it open, checks it again, runs it elevated, owned by the wizard window, so the UAC prompt comes to
		; the front (elevated from install.ps1's hidden console it only flashed in the taskbar), and runs install.ps1
		; again with its exit code, which install.ps1 maps as if it had run it
		; $R5 (the saved VC_redist.x64.exe) is set above. Made here, fresh, before install.ps1 runs: CreateDirectory fails on anything already there (a junction made
		; while the tree was unpacking), and then the saved installer is never run
		System::Call 'kernel32::CreateDirectoryW(w "$PLUGINSDIR\vc", p 0) i .R1'
		${If} $R1 <> 0
			StrCpy $VcDirFresh 1
		${Else}
			StrCpy $VcDirFresh 0
		${EndIf}
		DetailPrint "Running install.ps1..."
		; the step list moves with install.ps1's progress file, so this is not nsExec (RunInstallPs: a hidden process and a loop that
		; reads the file every 400 ms); install.ps1's own output is in its log, which goes into the details list afterwards
		StrCpy $RunCmd '$R0 -VcRedistHandOff "$R5" -LogFile "$LogPath"'
		Call RunInstallPs
		${If} $ExitCode = 4
			!insertmacro StepRow $RowVc 1 "asks Windows"
			DetailPrint "Checking the Visual C++ Runtime installer again..."
			Push $R5
			Call PinAndCheckVcRedist
			Pop $R4
			${If} $R4 = 1
				DetailPrint "Running the Visual C++ Runtime installer (Windows may ask for permission)..."
				; SHELLEXECUTEINFOW: SEE_MASK_NOCLOSEPROCESS|SEE_MASK_NOASYNC|SEE_MASK_FLAG_NO_UI, hwnd = the wizard (0 in /S)
				System::Call '*(&l4, i 0x540, p $HWNDPARENT, w "runas", w "$VcFinalPath", w "/install /quiet /norestart", p 0, i ${SW_SHOWNORMAL}, p 0, p 0, p 0, p 0, i 0, p 0, p 0) p .R6'
				System::Call 'shell32::ShellExecuteExW(p R6) i .R7 ?e'
				Pop $R8 ; GetLastError: 1223 when the UAC prompt is declined
				${If} $R7 <> 0
					System::Call '*$R6(i, i, p, p, p, p, p, i, p, p, p, p, i, p, p .R9)'
					${If} $R9 <> 0
						System::Call 'kernel32::WaitForSingleObject(p R9, i -1)'
						System::Call 'kernel32::GetExitCodeProcess(p R9, *i .R7)'
						System::Call 'kernel32::CloseHandle(p R9)'
					${Else}
						StrCpy $R7 -1
					${EndIf}
				${Else}
					StrCpy $R7 $R8
				${EndIf}
				System::Free $R6
				; Unsigned: a negative number after -VcRedistExitCode could be taken as a parameter name
				IntFmt $R7 "%u" $R7
				StrCpy $R4 "-VcRedistExitCode $R7"
			${Else}
				DetailPrint "The Visual C++ Runtime installer changed after it was checked; it was not run."
				StrCpy $R4 "-VcRedistRejected"
			${EndIf}
			; Only now, after the installer has exited (or was never started), let go of the file
			${If} $VcHandle <> -1
				System::Call 'kernel32::CloseHandle(p $VcHandle)'
			${EndIf}
			; The file and the folder only, never recursively: a junction in place of the folder is removed as a link
			SetDetailsPrint none
			Delete "$PLUGINSDIR\vc\VC_redist.x64.exe"
			RMDir "$PLUGINSDIR\vc"
			SetDetailsPrint both
			; The second run writes a new result; a stale one from the first run must not be read if it does not
			Delete "$PLUGINSDIR\result.json"
			StrCpy $RunCmd '$R0 $R4 -LogFile "$LogPath"'
			Call RunInstallPs
		${EndIf}
		${If} $ExitCode == "error"
			StrCpy $NotStarted "Windows PowerShell could not be started ($PowerShell)."
		${EndIf}
	${EndIf}

	StrCpy $R4 ""
	${If} $NotStarted != ""
		; install.ps1 never ran: nothing was changed, and there is no log
		StrCpy $ExitCode ${EXIT_OTHER}
		${If} $NotStartedCode != ""
			StrCpy $ExitCode $NotStartedCode
		${EndIf}
		StrCpy $ResultMessage $NotStarted
	${Else}
		nsExec::Exec '"$PowerShell" -NoProfile -NonInteractive -InputFormat None -ExecutionPolicy Bypass -File "$PLUGINSDIR\result-to-ini.ps1" "$PLUGINSDIR\result.json" "$PLUGINSDIR\result.ini"'
		Pop $R0
		ReadINIStr $ResultMessage "$PLUGINSDIR\result.ini" "result" "message"
		; install.ps1 printed its own "Benchy was not installed" and "Log:" lines when it got as far as a result
		ReadINIStr $R4 "$PLUGINSDIR\result.ini" "result" "result"
		ReadINIStr $PhpPath "$PLUGINSDIR\result.ini" "result" "php_path"
		ReadINIStr $VcResult "$PLUGINSDIR\result.ini" "result" "vcredist"
		ReadINIStr $RebootRecommended "$PLUGINSDIR\result.ini" "result" "reboot_recommended"
		ReadINIStr $RebootRequired "$PLUGINSDIR\result.ini" "result" "reboot_required"
		ReadINIStr $R1 "$PLUGINSDIR\result.ini" "result" "warning_count"
		${If} $R1 == ""
			StrCpy $R1 0
		${EndIf}
		${For} $R2 1 $R1
			ReadINIStr $R3 "$PLUGINSDIR\result.ini" "result" "warning$R2"
			StrCpy $Warnings "$Warnings$\r$\nWarning: $R3"
		${Next}
	${EndIf}
	${If} $ResultMessage == ""
		StrCpy $ResultMessage "install.ps1 stopped with exit code $ExitCode before it could say why."
	${EndIf}
	SetErrorLevel $ExitCode
	; Exit code 1 is Windows PowerShell refusing to run install.ps1 (arguments, or a policy): no log either
	${If} $NotStarted != ""
	${OrIf} $ExitCode = 1
		StrCpy $HasLog 0
	${Else}
		StrCpy $HasLog 1
	${EndIf}

	${If} $ExitCode = 0
	${OrIf} $ExitCode = 3010
		StrCpy $Ok 1
	${Else}
		StrCpy $Ok 0
	${EndIf}

	; The step list: what install.ps1 did not report, and where it stopped. $R4 is read below: not touched here.
	${If} $Ok = 1
		!insertmacro StepRow $RowCheck 2 "done"
		${If} $VcResult == "installed"
			!insertmacro StepRow $RowVc 2 "installed"
		${Else}
			!insertmacro StepRow $RowVc 2 "already there"
		${EndIf}
		!insertmacro StepRow $RowCopy 2 "done"
		!insertmacro StepRow $RowEntries 2 "done"
		${If} $EnginesOk = 1
			!insertmacro StepRow $RowEngines 2 "both start"
		${EndIf}
	${Else}
		; a step that was running, or the one after the last that finished, or the first
		${If} $FailRow < 0
			${If} $NowRow >= 0
				StrCpy $FailRow $NowRow
			${ElseIf} $LastDone >= 0
				IntOp $FailRow $LastDone + 1
			${Else}
				StrCpy $FailRow $RowCheck
			${EndIf}
			!insertmacro StepRow $FailRow 3 "failed"
		${EndIf}
	${EndIf}
	${If} $HasLog = 1
		Call LogToDetails
	${EndIf}

	; Remove the unpacked tree and PHP zip now (NSIS removes the rest of the temp dir when it exits)
	SetOutPath $TEMP
	SetDetailsPrint none
	RMDir /r "$PLUGINSDIR\tree"
	RMDir /r "$PLUGINSDIR\php"
	SetDetailsPrint both

	${If} $Ok = 1
		; Straight on to the finish page
		SetAutoClose true
	${Else}
		; Stay here, so the output above can be read before Next
		StrCpy $InstFilesHeader "Benchy was not installed"
		StrCpy $InstFilesSubHeader "Exit code $ExitCode. Next shows what to do."
		${If} $R4 == ""
			; install.ps1 did not say it (it never ran, or stopped before writing its result)
			DetailPrint "Benchy was not installed (exit code $ExitCode): $ResultMessage"
			${If} $HasLog = 1
				DetailPrint "Log: $LogPath"
			${EndIf}
		${EndIf}
	${EndIf}

	${If} $Ok = 1
		Call WriteBenchyUninstaller
		Call PostInstall
	${EndIf}

	; /S has no server page: what /SERVER= asked for is done here, after a good install only
	${If} $Ok = 1
	${AndIf} ${Silent}
		Call ServerSilent
		; and no wizard to close, so the client goes back now
		Call RestartBenchy
	${EndIf}
SectionEnd

; --- The server: /SERVER= in a silent install --------------------------------------------------------------------------

; Never a failure: nothing here changes the exit code
Function ServerSilent
	${If} $ServerMode == ""
		; no /SERVER: no search, and the hint that is there stays
		Return
	${ElseIf} $ServerMode == "none"
		Push "none"
		Call WriteServerHint
		Push "server: /SERVER=none saved (no server offered)"
		Call AppendInstallLog
	${ElseIf} $ServerMode == "address"
		Push $ServerAddress
		Call WriteServerHint
		Push "server: $ServerAddress saved as the server hint"
		Call AppendInstallLog
	${Else}
		; auto: the engine's discover, after the install
		${IfNot} ${FileExists} "${ENGINE_EXE}"
			Push "discovery: this install has no engine, nothing searched or saved (use /SERVER=<address>)"
			Call AppendInstallLog
			Return
		${EndIf}
		Delete "$PLUGINSDIR\discover.ini"
		nsExec::Exec '"$PowerShell" -NoProfile -NonInteractive -InputFormat None -ExecutionPolicy Bypass -File "$PLUGINSDIR\server-page.ps1" -Mode Discover -Engine "${ENGINE_EXE}" -IniPath "$PLUGINSDIR\discover.ini"'
		Pop $R0
		ReadINIStr $R1 "$PLUGINSDIR\discover.ini" "discover" "count"
		${If} $R1 == ""
			Push "discovery: the search did not run, none saved (use /SERVER=<address>)"
			Call AppendInstallLog
			Return
		${EndIf}
		; The policy server is listed first; with no policy server exactly one found server is saved
		ReadINIStr $R2 "$PLUGINSDIR\discover.ini" "server1" "source"
		StrCpy $R4 0
		${If} $R1 = 1
			StrCpy $R4 1
		${ElseIf} $R1 > 1
		${AndIf} $R2 == "policy"
			StrCpy $R4 1
		${EndIf}
		${If} $R4 = 1
			ReadINIStr $R3 "$PLUGINSDIR\discover.ini" "server1" "address"
			Push $R3
			Call WriteServerHint
			Push "discovery: $R3 saved as the server hint"
			Call AppendInstallLog
		${ElseIf} $R1 = 0
			Push "discovery: no servers found, none saved (use /SERVER=)"
			Call AppendInstallLog
		${Else}
			Push "discovery: $R1 servers found, none saved (use /SERVER=)"
			Call AppendInstallLog
		${EndIf}
	${EndIf}
FunctionEnd

; The progress page's status line says "Completed" when the section ends; on a failure, say what happened instead
Function InstFilesLeave
	${If} $Ok <> 1
	${AndIf} $mui.InstFilesPage.Text <> 0
		SendMessage $mui.InstFilesPage.Text ${WM_SETTEXT} 0 "STR:Benchy was not installed (exit code $ExitCode)."
	${EndIf}
FunctionEnd

; A file that could not be unpacked and was cancelled at the Retry/Cancel prompt (also in /S) ends the install here,
; without the finish page; NSIS would exit with 2, which means a refusal, so make it 50 like the other never-started
; cases
Function .onInstFailed
	SetErrorLevel ${EXIT_OTHER}
FunctionEnd

; --- Page 3: connect to a server (optional), after the install ---------------------------------------------------------

; Push <source>, Call ServerSourceLabel, Pop <words for the list>
Function ServerSourceLabel
	Exch $0
	${If} $0 == "policy"
		StrCpy $0 "set by your administrator"
	${ElseIf} $0 == "hint"
		StrCpy $0 "chosen before"
	${ElseIf} $0 == "dns"
		StrCpy $0 "found in DNS"
	${ElseIf} $0 == "name"
		StrCpy $0 "found by its usual name"
	${Else}
		StrCpy $0 "found on this network"
	${EndIf}
	Exch $0
FunctionEnd

; One radio button for found server <_N> (when there are that many), its handle in <_VAR>. Nothing found is trusted
; by this page: the list is only what the engine heard, and what is picked is saved as a hint, never paired.
!macro ServerRadio _N _VAR
	${If} $ServerCount >= ${_N}
		ReadINIStr $R0 "$PLUGINSDIR\discover.ini" "server${_N}" "name"
		ReadINIStr $R1 "$PLUGINSDIR\discover.ini" "server${_N}" "address"
		ReadINIStr $R2 "$PLUGINSDIR\discover.ini" "server${_N}" "source"
		Push $R2
		Call ServerSourceLabel
		Pop $R2
		${If} $R0 == ""
		${OrIf} $R0 == "null"
			StrCpy $R0 $R1
		${EndIf}
		IntOp $R3 ${_N} - 1
		IntOp $R3 $R3 * 11
		IntOp $R3 $R3 + 25
		${NSD_CreateAdditionalRadioButton} 4u $R3u 98% 11u "$R0 ($R1), $R2"
		Pop ${_VAR}
		!insertmacro BuiFontOn ${_VAR}
	${EndIf}
!macroend

; The found servers are put on the page when discover.ini appears (the search runs hidden, in the background, and the page
; never waits for it: Next and the address field work from the start)
Function ServerFill
	ReadINIStr $R9 "$PLUGINSDIR\discover.ini" "discover" "count"
	${If} $R9 == ""
		StrCpy $R9 0
	${EndIf}
	ReadINIStr $R8 "$PLUGINSDIR\discover.ini" "discover" "status"
	StrCpy $ServerCount $R9
	${If} $ServerCount > ${SERVER_MAX_SHOWN}
		StrCpy $ServerCount ${SERVER_MAX_SHOWN}
	${EndIf}
	${If} $R9 = 0
		${NSD_SetText} $ServerStatus "No Benchy servers were found on this network. Type an address below, or connect later in Benchy."
	${ElseIf} $R9 = 1
		${NSD_SetText} $ServerStatus "Found a Benchy server on this network:"
	${ElseIf} $R9 > ${SERVER_MAX_SHOWN}
		${NSD_SetText} $ServerStatus "Found $R9 Benchy servers on this network (the first ${SERVER_MAX_SHOWN} are shown; type an address for another):"
	${Else}
		${NSD_SetText} $ServerStatus "Found $R9 Benchy servers on this network:"
	${EndIf}
	!insertmacro ServerRadio 1 $ServerR1
	!insertmacro ServerRadio 2 $ServerR2
	!insertmacro ServerRadio 3 $ServerR3
	!insertmacro ServerRadio 4 $ServerR4
	!insertmacro ServerRadio 5 $ServerR5
	; Preselected: the policy server, else the only server found, else "Don't connect"
	ReadINIStr $R0 "$PLUGINSDIR\discover.ini" "server1" "source"
	${If} $ServerCount >= 1
		${If} $R9 = 1
		${OrIf} $R0 == "policy"
			${NSD_Check} $ServerR1
			${NSD_Uncheck} $ServerNone
		${EndIf}
	${EndIf}
FunctionEnd

Function ServerPoll
	IntOp $ServerTicks $ServerTicks + 1
	${If} ${FileExists} "$PLUGINSDIR\discover.ini"
		${NSD_KillTimer} ServerPoll
		Call ServerFill
	${ElseIf} $ServerTicks >= 30
		; the search is limited to 4 seconds and its 2 seconds of grace, so this is a search that could not run
		${NSD_KillTimer} ServerPoll
		${NSD_SetText} $ServerStatus "The search didn't finish. You can still type an address below."
	${EndIf}
FunctionEnd

Function ServerPageCreate
	; Nothing to ask when the install failed, or this install has no engine (the engine does the searching)
	${If} $Ok <> 1
		Abort
	${EndIf}
	${IfNot} ${FileExists} "${ENGINE_EXE}"
		; no page, but an address or none given with /SERVER= is still saved (auto says in the log that it could not search)
		Call ServerSilent
		Abort
	${EndIf}
	!insertmacro BuiHeader "Connect to a Benchy server (optional)" "Benchy can look for your Benchy server on this network."

	nsDialogs::Create 1018
	Pop $ServerDialog
	${If} $ServerDialog == error
		Abort
	${EndIf}
	StrCpy $ServerCount 0
	StrCpy $ServerTicks 0
	Delete "$PLUGINSDIR\discover.ini"

	!insertmacro BuiStrip 3 "Install" "Installing" "Server" "Done"
	!insertmacro BuiShield 0
	${NSD_CreateLabel} 0 14u 100% 10u "Looking for Benchy servers on this network..."
	Pop $ServerStatus
	!insertmacro BuiFontOn $ServerStatus
	${NSD_CreateFirstRadioButton} 4u 82u 98% 11u "Don't connect to a server. You can do it later in Benchy."
	Pop $ServerNone
	!insertmacro BuiFontOn $ServerNone
	${NSD_Check} $ServerNone
	!insertmacro BuiLabel $R0 0 95u 100% 9u "Another server (host or https://host:port):" ${BUI_INK} $BuiFont
	${NSD_CreateText} 0 105u 100% 12u $ServerAddress
	Pop $ServerField
	!insertmacro BuiFontOn $ServerField
	!insertmacro BuiNote 119 18 "Only remembered here. Benchy asks for the join code from the server's Systems page when you connect."

	; The search starts at once and the page does not wait for it. /SERVER=<address> is already in the address box and
	; /SERVER=none is the radio button that is on; whatever the search finds is added to the list
	${NSD_CreateTimer} ServerPoll 300
	nsExec::Exec '"$PowerShell" -NoProfile -NonInteractive -InputFormat None -ExecutionPolicy Bypass -File "$PLUGINSDIR\server-page.ps1" -Mode DiscoverBackground -Engine "${ENGINE_EXE}" -IniPath "$PLUGINSDIR\discover.ini"'
	Pop $R0

	GetDlgItem $R0 $HWNDPARENT 1
	SendMessage $R0 ${WM_SETTEXT} 0 "STR:&Next"
	nsDialogs::Show
FunctionEnd

; What Next saves. A typed address wins over the radio buttons: a v1 string (ip:port/accountid) goes to Benchy's own
; Phoromatic setter, anything else typed is a server address for the hint. Otherwise the radio button that is on.
Function ServerPageLeave
	${NSD_GetText} $ServerField $R0
	${If} $R0 != ""
		Push $R0
		Call IsPhoromaticServer
		Pop $R1
		${If} $R1 = 1
			; An old (v1) Phoromatic server: the same setter install.ps1 uses, from the tree that was just installed
			nsExec::Exec '"$PowerShell" -NoProfile -NonInteractive -InputFormat None -ExecutionPolicy Bypass -File "$PLUGINSDIR\server-page.ps1" -Mode SetPhoromatic -InstallDir "$LOCALAPPDATA\Benchy" -PhpExe "$PhpPath" -Server "$R0"'
			Pop $R2
			${If} $R2 <> 0
				MessageBox MB_OK|MB_ICONEXCLAMATION "Benchy could not save that Phoromatic server. You can set it later in Benchy."
			${EndIf}
		${Else}
			Push $R0
			Call IsServerAddress
			Pop $R1
			${If} $R1 <> 1
				MessageBox MB_OK|MB_ICONEXCLAMATION "That is not a server address. Type a host name or address, like seinar.example.com or https://10.0.0.5:39443, or clear the box."
				Abort
			${EndIf}
			Push $R0
			Call NormalizeServerAddress
			Pop $R0
			Push $R0
			Call WriteServerHint
			StrCpy $ServerSaved $R0
		${EndIf}
		Return
	${EndIf}

	${NSD_GetState} $ServerNone $R1
	${If} $R1 = ${BST_CHECKED}
		Push "none"
		Call WriteServerHint
		Return
	${EndIf}
	; One of the found servers
	StrCpy $R2 1
	${Do}
		${If} $R2 > $ServerCount
			${ExitDo}
		${EndIf}
		${If} $R2 = 1
			StrCpy $R3 $ServerR1
		${ElseIf} $R2 = 2
			StrCpy $R3 $ServerR2
		${ElseIf} $R2 = 3
			StrCpy $R3 $ServerR3
		${ElseIf} $R2 = 4
			StrCpy $R3 $ServerR4
		${Else}
			StrCpy $R3 $ServerR5
		${EndIf}
		${NSD_GetState} $R3 $R4
		${If} $R4 = ${BST_CHECKED}
			ReadINIStr $R5 "$PLUGINSDIR\discover.ini" "server$R2" "address"
			StrCpy $ServerSaved $R5
			Push $R5
			Call WriteServerHint
			${ExitDo}
		${EndIf}
		IntOp $R2 $R2 + 1
	${Loop}
FunctionEnd

; --- Page 4: Done, or Didn't install ------------------------------------------------------------------------------------

Function CopyDetails
	Pop $R9 ; the button
	; CF_UNICODETEXT (13) in global memory owned by the clipboard once set
	StrCpy $R4 $Details
	StrLen $R0 $R4
	IntOp $R0 $R0 + 1
	IntOp $R0 $R0 * 2
	System::Call 'user32::OpenClipboard(p $HWNDPARENT) i .R1'
	${If} $R1 <> 0
		System::Call 'user32::EmptyClipboard()'
		System::Call 'kernel32::GlobalAlloc(i 0x0002, p R0) p .R2' ; GMEM_MOVEABLE
		System::Call 'kernel32::GlobalLock(p R2) p .R3'
		System::Call 'kernel32::lstrcpyW(p R3, w R4)'
		System::Call 'kernel32::GlobalUnlock(p R2)'
		System::Call 'user32::SetClipboardData(i 13, p R2)'
		System::Call 'user32::CloseClipboard()'
	${EndIf}
FunctionEnd

; What went wrong, in a few words, from install.ps1's exit codes: $FailTitle
Function FailTitleFor
	${If} $NotStarted != ""
	${AndIf} $ExitCode <> 3
		StrCpy $FailTitle "The installer could not start"
	${ElseIf} $RebootRequired == 1
		StrCpy $FailTitle "Windows needs a restart first"
	${ElseIf} $ExitCode = 1
		StrCpy $FailTitle "Windows PowerShell did not run the install"
	${ElseIf} $ExitCode = 2
		StrCpy $FailTitle "The install could not start"
	${ElseIf} $ExitCode = 3
		StrCpy $FailTitle "Benchy is running"
	${ElseIf} $ExitCode = 10
		StrCpy $FailTitle "PHP could not be downloaded"
	${ElseIf} $ExitCode = 11
		StrCpy $FailTitle "A file did not match its checksum"
	${ElseIf} $ExitCode = 20
		StrCpy $FailTitle "The Visual C++ Runtime is missing"
	${ElseIf} $ExitCode = 21
		StrCpy $FailTitle "The Visual C++ Runtime was not installed"
	${ElseIf} $ExitCode = 22
		StrCpy $FailTitle "The Visual C++ Runtime installer changed"
	${ElseIf} $ExitCode = 30
		StrCpy $FailTitle "PHP does not start"
	${ElseIf} $ExitCode = 40
		StrCpy $FailTitle "Benchy was only partly copied"
	${Else}
		StrCpy $FailTitle "The install did not finish"
	${EndIf}
FunctionEnd

Function FinishPageCreate
	!insertmacro BuiShield 0
	${If} $Ok = 1
		!insertmacro BuiHeader "Benchy is installed" "Benchy ${VERSION}"
		StrCpy $Details ""
		${If} $RebootRecommended == 1
			StrCpy $Details "$Details$\r$\nA reboot is recommended to finish the Microsoft Visual C++ Runtime install."
		${EndIf}
		StrCpy $Details "$Details$Warnings"
	${Else}
		!insertmacro BuiHeader "Benchy was not installed" "Exit code $ExitCode"
		Call FailTitleFor
		StrCpy $FailBody $ResultMessage
		; What was changed, from install.ps1's exit codes
		${If} $NotStarted != ""
			StrCpy $FailState "Nothing was changed."
		${ElseIf} $RebootRequired == 1
			StrCpy $FailState "The Microsoft Visual C++ Runtime was installed, but Windows needs a restart before Benchy can use it. Benchy itself was not installed: restart, then run this installer again."
		${ElseIf} $ExitCode = 1
			StrCpy $FailState "Windows PowerShell did not run the install: it rejected the arguments, or a policy blocks scripts. Nothing was changed."
		${ElseIf} $ExitCode = 3
			; Benchy is running: raised at the start, or again just before the copy (after the PHP step); install.ps1's message says which and what was done
			StrCpy $FailState ""
		${ElseIf} $ExitCode >= 2
		${AndIf} $ExitCode <= 22
			StrCpy $FailState "Nothing was changed."
		${ElseIf} $ExitCode = 30
			StrCpy $FailState "Benchy itself was not copied. The message above says if the PHP folder needs a hand."
		${ElseIf} $ExitCode = 40
			StrCpy $FailState "Benchy was only partly copied. Close every Benchy window and run this installer again."
		${Else}
			StrCpy $FailState "The install did not finish. The log shows how far it got."
		${EndIf}
		${If} $HasLog = 0
			StrCpy $Details "Benchy ${VERSION} installer: exit code $ExitCode$\r$\n$ResultMessage$\r$\n$FailState$Warnings"
		${Else}
			; The log path first: NSIS strings stop at 1024 characters, so a long message or warnings lose their end instead
			StrCpy $Details "Benchy ${VERSION} installer: exit code $ExitCode$\r$\nLog: $LogPath$\r$\n$ResultMessage$\r$\n$FailState$Warnings"
		${EndIf}
	${EndIf}

	nsDialogs::Create 1018
	Pop $R0
	${If} $R0 == error
		Abort
	${EndIf}

	${If} $Ok = 1
		!insertmacro BuiStrip 4 "Install" "Installing" "Server" "Done"
		${If} $AutostartMode == 1
			!insertmacro BuiText 14 20 "Benchy is installed. Open it from the Start menu, or from the tray icon after you log in."
		${Else}
			!insertmacro BuiText 14 20 "Benchy is installed. Open it from the Start menu."
		${EndIf}
		; what is there now
		StrCpy $R9 38
		StrCpy $R8 0
		!ifdef CLIENT_VERSION
			!insertmacro BuiRow $R9 ok "Benchy app, client ${CLIENT_VERSION}" "installed" muted
		!else
			!insertmacro BuiRow $R9 ok "Benchy ${VERSION}" "installed" muted
		!endif
		IntOp $R9 $R9 + 11
		IntOp $R8 $R8 + 1
		${If} $EnginesOk = 1
			!ifdef ENGINE_VERSION
				!insertmacro BuiRow $R9 ok "Native engine ${ENGINE_VERSION} and PHP engine ${VERSION}" "both start" good
			!else
				!insertmacro BuiRow $R9 ok "PHP engine ${VERSION}" "starts" good
			!endif
		${Else}
			!insertmacro BuiRow $R9 bad "The engines" "check the log" muted
		${EndIf}
		IntOp $R9 $R9 + 11
		IntOp $R8 $R8 + 1
		${If} $ServerSaved != ""
			!insertmacro BuiRow $R9 now "$ServerSaved" "join code next" muted
			IntOp $R9 $R9 + 11
			IntOp $R8 $R8 + 1
		${EndIf}
		${If} $AgentResult == "updated"
			!insertmacro BuiRow $R9 ok "Unattended agent" "updated" good
			IntOp $R9 $R9 + 11
			IntOp $R8 $R8 + 1
		${ElseIf} $AgentResult == "not"
			!insertmacro BuiRow $R9 bad "Unattended agent" "still $ProtVer" muted
			IntOp $R9 $R9 + 11
			IntOp $R8 $R8 + 1
		${EndIf}
		; the frame, from the first row's top to the last row's end (made last: it is only a line)
		IntOp $R7 $R8 * 11
		IntOp $R7 $R7 + 4
		!insertmacro BuiFrame 36 $R7
		IntOp $R9 $R9 + 6
		${If} $AgentResult == "not"
			!insertmacro BuiNote $R9 10 "The unattended agent is still $ProtVer. Update it in Settings, Server agent."
			IntOp $R9 $R9 + 11
		${EndIf}
		!insertmacro BuiNote $R9 18 "The first time a PHP engine test runs, Benchy sets up Cygwin. Windows may ask for permission then."
		IntOp $R9 $R9 + 20
		${If} $Details != ""
			; Drop the leading line break
			StrCpy $Details $Details "" 2
			; A read-only, word-wrapped, scrolling edit box (NSD_CreateTextMultiline scrolls sideways instead of wrapping)
			nsDialogs::CreateControl EDIT ${DEFAULT_STYLES}|${WS_TABSTOP}|${WS_VSCROLL}|${ES_MULTILINE}|${ES_READONLY}|${ES_AUTOVSCROLL} ${WS_EX_CLIENTEDGE} 0 $R9u 100% 20u $Details
			Pop $R0
			!insertmacro BuiFontOn $R0
		${EndIf}
		${NSD_CreateCheckbox} 0 118u 100% 11u "&Open Benchy now"
		Pop $FinishOpen
		!insertmacro BuiFontOn $FinishOpen
		; a client that was running has been put back (or is, when this page closes): nothing to open
		${If} $WasClient = 0
			${NSD_Check} $FinishOpen
		${EndIf}
	${Else}
		!insertmacro BuiStrip 4 "Install" "Installing" "Server" "Done"
		!insertmacro BuiCallout 14 52 bad "$FailTitle" "$FailBody"
		StrCpy $R9 70
		${If} $FailState != ""
			!insertmacro BuiText $R9 20 "$FailState"
			IntOp $R9 $R9 + 22
		${EndIf}
		${If} $HasLog = 1
			!insertmacro BuiNote $R9 10 "Log"
			IntOp $R9 $R9 + 10
			!insertmacro BuiText $R9 20 "$LogPath"
			IntOp $R9 $R9 + 22
		${EndIf}
		${NSD_CreateButton} 0 $R9u 70u 14u "&Copy details"
		Pop $R0
		!insertmacro BuiFontOn $R0
		${NSD_OnClick} $R0 CopyDetails
		StrCpy $FinishOpen ""
	${EndIf}

	GetDlgItem $R0 $HWNDPARENT 1
	SendMessage $R0 ${WM_SETTEXT} 0 "STR:&Finish"
	GetDlgItem $R0 $HWNDPARENT 2
	ShowWindow $R0 ${SW_HIDE}
	GetDlgItem $R0 $HWNDPARENT 3
	ShowWindow $R0 ${SW_HIDE}

	nsDialogs::Show
FunctionEnd

Function FinishPageLeave
	${If} $FinishOpen != ""
		${NSD_GetState} $FinishOpen $R0
		${If} $R0 = ${BST_CHECKED}
			; The Start Menu shortcut install.ps1 made: the desktop client when the installer carries one, else the command
			; prompt (benchy.bat finds its PHP without the new PHP_BIN). Through explorer.exe, NOT ExecShell: a child of this
			; installer inherits its token, and when the user ran the installer as administrator the client, and the
			; server agent it starts, would run elevated for as long as they live. explorer.exe hands the shortcut to the
			; desktop shell, which starts it with the user's normal (unelevated) token.
			Exec '"$WINDIR\explorer.exe" "$SMPROGRAMS\Benchy.lnk"'
		${EndIf}
	${EndIf}
FunctionEnd

; --- The uninstaller ---------------------------------------------------------------------------------------------------------------

!include "benchy-uninstall.nsh"
