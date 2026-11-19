; Benchy's uninstaller (NSIS 3): %LOCALAPPDATA%\Benchy\uninstall.exe, written by benchy.nsi after a good install. Included by benchy.nsi
; (the installer and uninstaller are one compile); it uses the same parts as the installer (benchy-ui.nsh, with "un.").
;
; Pages: What goes, [Benchy is running], Removing, [Windows said no], Done (or Done with leftovers, or why it did not finish). All custom
; nsDialogs pages: the removal runs in uninstall-wizard.ps1 as a background process (so the window stays alive and the UAC prompt can be
; waited for) and a timer on the Removing page reads its progress file. There is no instfiles page; /S runs the same worker from the
; uninstall section and waits for it.
;
; What the uninstaller carries: the pinned native engine zip (ENGINEZIP, when the installer has one), uninstall.ps1, install.ps1 (only
; for `-CheckRunning`), the shared lib and stop-benchy.ps1. They are unpacked to $PLUGINSDIR when the first page is made, and the engine
; is opened for read with no write or delete sharing and kept open to the end (as PinAndCheckVcRedist does for VC++), so the engine
; that removes things is the one that matches this install, and a missing or broken installed engine does not stop the uninstall.
; It stays RequestExecutionLevel user; the one UAC prompt is the engine's own relay (`agent reset`), reached with
; AllowSetForegroundWindow(ASFW_ANY) so a prompt that only blinks on the taskbar is allowed to come forward.

!ifndef BENCHY_UNINSTALL_NSH
!define BENCHY_UNINSTALL_NSH

!define UN_EXIT_PERMISSION 5

; The pages are declared in benchy.nsi next to the installer's (before MUI_LANGUAGE); this file is included at its end, after the macros it uses.

Var UnPrepared
Var UnEngineExe ; the engine every call uses: the held copy, else the installed one, else ""
Var UnEngHandle ; the open handle that holds the engine; -1 when none
Var UnInfoEngine
Var UnInfoZip
Var UnComputer
Var UnServerHost ; "seinar", "" when this computer is not paired
Var UnServerTitle
Var UnMachine ; the inventory says something is for all users: Windows will ask once
Var UnInvOk
Var UnResultsText
Var UnTestsText
Var UnDialog
Var UnKeepResultsBox
Var UnKeepTestsBox
Var UnKeepResults ; 1 keep results and settings (the default)
Var UnKeepTests ; 1 keep installed tests (0 is the default)
Var UnProc ; the worker's process handle, 0 when none
Var UnProgressDone
Var UnStepCount
Var UnRemoving ; 1 while the worker runs
Var UnCode ; the exit code of the uninstall (design 4.4)
Var UnMessage
Var UnReason ; the engine's permission reason (uac_declined, uac_expired, uac_failed, no_desktop)
Var UnLeftCount
Var UnStill ; 1 when the helper folders for administrator tests were there
Var UnRestartBtn
Var UnDetails
Var UnInPlaceExe ; this exe when it runs from the install folder (_?=): it cannot delete itself, so it is not a leftover; "" otherwise
Var UnDone ; 1 once the last page was shown: closing the window is then not a cancel

; --- Start: the command line ---------------------------------------------------------------------------------------------------

Function un.onInit
	StrCpy $PowerShell "$WINDIR\Sysnative\WindowsPowerShell\v1.0\powershell.exe"
	StrCpy $UnPrepared 0
	StrCpy $UnEngHandle -1
	StrCpy $UnProc 0
	StrCpy $UnRemoving 0
	StrCpy $UnDone 0
	StrCpy $UnCode 50
	StrCpy $UnInPlaceExe ""
	${If} $EXEPATH == "$LOCALAPPDATA\Benchy\uninstall.exe"
		StrCpy $UnInPlaceExe $EXEPATH
	${EndIf}
	StrCpy $UnKeepResults 1
	StrCpy $UnKeepTests 0
	ReadEnvStr $UnComputer COMPUTERNAME
	ReadEnvStr $R0 USERPROFILE
	ReadEnvStr $R1 LOCALAPPDATA
	${If} $R0 == ""
	${OrIf} $R1 == ""
		!insertmacro Refuse "LOCALAPPDATA or USERPROFILE is not set, so there is no user profile to uninstall Benchy from. Run the uninstaller as the user who installed Benchy."
	${EndIf}

	${un.GetParameters} $R0
	!insertmacro unGetCmdOption $R0 "/KEEPRESULTS=" $R2 $R1
	${If} $R2 = 1
		${If} $R1 == "0"
		${OrIf} $R1 == "1"
			StrCpy $UnKeepResults $R1
		${Else}
			!insertmacro Refuse "/KEEPRESULTS must be 0 or 1 (got '$R1')."
		${EndIf}
	${EndIf}
	!insertmacro unGetCmdOption $R0 "/KEEPTESTS=" $R2 $R1
	${If} $R2 = 1
		${If} $R1 == "0"
		${OrIf} $R1 == "1"
			StrCpy $UnKeepTests $R1
		${Else}
			!insertmacro Refuse "/KEEPTESTS must be 0 or 1 (got '$R1')."
		${EndIf}
	${EndIf}
	!insertmacro unGetCmdOption $R0 "/LOG=" $R2 $R1
	${If} $R1 == ""
		StrCpy $LogPath "$TEMP\Benchy-uninstall.log"
	${Else}
		StrLen $R2 $R1
		StrCpy $R3 $R1 1 -1
		${If} $R2 >= 260
		${OrIf} $R3 == "\"
		${OrIf} $R3 == "/"
			!insertmacro Refuse "/LOG must be a file path shorter than 260 characters, not a folder (got '$R1')."
		${EndIf}
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
		${If} ${FileExists} "$LogPath\*"
			!insertmacro Refuse "/LOG must be a file, not a folder (got '$LogPath')."
		${EndIf}
	${EndIf}
FunctionEnd

; The instfiles page is only there for /S (see benchy.nsi): in the wizard it is skipped
Function un.SkipInstFiles
	${IfNot} ${Silent}
		Abort
	${EndIf}
FunctionEnd

Function un.GuiInit
	Call un.BuiInitFonts
FunctionEnd

; Cancel or close while the worker runs would leave Benchy half removed with nothing watching it
Function un.AbortCheck
	${If} $UnRemoving = 1
		MessageBox MB_OK|MB_ICONINFORMATION "Benchy is being removed. Wait for it to finish."
		Abort
	${EndIf}
FunctionEnd

; --- Unpack, ask the machine: once, before the first page is drawn ---------------------------------------------------------

; Push <command>, Call un.StartHidden, Pop <process handle, 0 when it could not be started>. A hidden process, not waited for.
Function un.StartHidden
	Exch $2
	Push $0
	Push $1
	Push $3
	Push $4
	; STARTUPINFOW (68 bytes in this 32-bit stub): STARTF_USESHOWWINDOW and SW_HIDE; PROCESS_INFORMATION (16 bytes)
	System::Call '*(i 68, p 0, p 0, p 0, i 0, i 0, i 0, i 0, i 0, i 0, i 0, i 1, &i2 0, &i2 0, p 0, p 0, p 0, p 0) p .r0'
	System::Call '*(p 0, p 0, i 0, i 0) p .r1'
	; CREATE_NO_WINDOW
	System::Call 'kernel32::CreateProcessW(p 0, w r2, p 0, p 0, i 0, i 0x08000000, p 0, p 0, p r0, p r1) i .r3'
	${If} $3 = 0
		StrCpy $2 0
	${Else}
		System::Call '*$1(p .r2, p .r4)'
		System::Call 'kernel32::CloseHandle(p r4)'
	${EndIf}
	System::Free $0
	System::Free $1
	Pop $4
	Pop $3
	Pop $1
	Pop $0
	Exch $2
FunctionEnd

; stop-benchy.ps1 -Mode Info: what runs from this install and the agent's answer. $UnInfoEngine and $UnInfoZip say which engine to ask.
; Fills $RunClient, $RunTray, $RunAgent, $RunLocal, $RunConsole, $RunJob, $NeedStop, $StopBlock and the server the agent is paired with.
Function un.RunInfo
	StrCpy $RunClient 0
	StrCpy $RunTray 0
	StrCpy $RunAgent 0
	StrCpy $RunLocal 0
	StrCpy $RunConsole 0
	StrCpy $RunJob ""
	StrCpy $NeedStop 0
	StrCpy $StopBlock ""
	Delete "$PLUGINSDIR\info.ini"
	nsExec::Exec '"$PowerShell" -NoProfile -NonInteractive -InputFormat None -ExecutionPolicy Bypass -File "$PLUGINSDIR\stop-benchy.ps1" -Mode Info -IniPath "$PLUGINSDIR\info.ini" -EarlyDir "$PLUGINSDIR\early" -Engine "$UnInfoEngine" $UnInfoZip'
	Pop $R0
	${IfNot} ${FileExists} "$PLUGINSDIR\info.ini"
		; nothing known: no page about what runs; the worker still stops Benchy, and says if it cannot
		Return
	${EndIf}
	ReadINIStr $RunClient "$PLUGINSDIR\info.ini" "running" "client"
	ReadINIStr $RunTray "$PLUGINSDIR\info.ini" "running" "client_tray"
	ReadINIStr $RunAgent "$PLUGINSDIR\info.ini" "running" "agent"
	ReadINIStr $R1 "$PLUGINSDIR\info.ini" "running" "run"
	ReadINIStr $R2 "$PLUGINSDIR\info.ini" "running" "console"
	ReadINIStr $R3 "$PLUGINSDIR\info.ini" "running" "php"
	ReadINIStr $R4 "$PLUGINSDIR\info.ini" "agent" "state"
	ReadINIStr $RunJob "$PLUGINSDIR\info.ini" "agent" "job_title"
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
	; "seinar", "KAMINO": the host of the server, not its https://
	StrCpy $UnServerHost ""
	StrCpy $UnServerTitle ""
	${If} $R5 != ""
	${AndIf} $R5 != "null"
		StrCpy $R7 $R5 8
		${If} $R7 == "https://"
			StrCpy $R5 $R5 "" 8
		${EndIf}
		StrCpy $UnServerHost $R5
		${If} $R6 != ""
		${AndIf} $R6 != "null"
			StrCpy $UnServerTitle $R6
		${EndIf}
	${EndIf}
	${If} $RunClient == 1
	${OrIf} $RunAgent == 1
		StrCpy $NeedStop 1
	${EndIf}
	${If} $RunConsole == 1
		StrCpy $StopBlock "Close the Benchy command prompt window (and anything else running Benchy's PHP), then click Next."
	${ElseIf} $RunClient == 1
	${AndIf} $RunLocal == 1
		; a client older than 0.12 cannot be asked to stop its run, and a newer one answers BUSY to quit-for-update and stays
		StrCpy $StopBlock "A test is running in Benchy. Finish or stop it, then click Next."
	${ElseIf} $RunClient <> 1
	${AndIf} $RunLocal == 1
	${AndIf} $RunAgent <> 1
		StrCpy $StopBlock "A test is running from the command line. Let it finish, then click Next."
	${EndIf}
FunctionEnd

; The engine is opened for read with only read sharing (no write, delete or rename while it is open) and kept open for the life of the
; uninstaller; Windows can still start it (the image loader only reads), and the elevated relay can still copy it. As PinAndCheckVcRedist
; does for VC++: the open must have worked, and the file the handle names (its final path, links resolved, which is also what runs) must have the
; size and sha256 built into this exe. Otherwise nothing runs and the wizard ends with exit code 50 and a message.
!ifdef ENGINEEXE
Function un.HoldAndCheckEngine
	StrCpy $0 ""
	; GENERIC_READ, FILE_SHARE_READ, OPEN_EXISTING, FILE_FLAG_SEQUENTIAL_SCAN
	System::Call 'kernel32::CreateFileW(w "$PLUGINSDIR\engine\benchy-engine.exe", i 0x80000000, i 1, p 0, i 3, i 0x08000000, p 0) i .r1 ?e'
	Pop $2
	StrCpy $UnEngHandle $1
	${If} $1 = -1
		StrCpy $0 "it could not be opened for the check (Windows error $2)"
	${Else}
		System::Call 'kernel32::GetFinalPathNameByHandleW(p r1, w .r5, i ${NSIS_MAX_STRLEN}, i 0) i .r3 ?e'
		Pop $2
		${If} $3 = 0
		${OrIf} $3 >= ${NSIS_MAX_STRLEN}
			StrCpy $0 "its final path could not be read (Windows error $2)"
		${Else}
			StrCpy $6 $5 8
			${If} $6 == "\\?\UNC\"
				StrCpy $5 $5 "" 8
				StrCpy $4 "\\$5"
			${Else}
				StrCpy $6 $5 4
				${If} $6 == "\\?\"
					StrCpy $5 $5 "" 4
				${EndIf}
				StrCpy $4 $5
			${EndIf}
			System::Call 'kernel32::GetFileSizeEx(p r1, *l .r2) i .r3'
			${If} $3 = 0
				StrCpy $0 "its size could not be read"
			${ElseIf} $2 != "${ENGINEEXE_SIZE}"
				StrCpy $0 "it has $2 bytes, this uninstaller expects ${ENGINEEXE_SIZE}"
			${Else}
				StrCpy $6 "$WINDIR\Sysnative\certutil.exe"
				${IfNot} ${FileExists} $6
					StrCpy $6 "$SYSDIR\certutil.exe"
				${EndIf}
				nsExec::ExecToStack '"$6" -hashfile "$4" SHA256'
				Pop $1
				Pop $2
				${If} $1 != 0
					StrCpy $0 "certutil could not hash it (exit code $1)"
				${Else}
					Push $2
					Call un.CertutilSha256
					Pop $3
					${If} $3 == ""
						StrCpy $0 "certutil printed no sha256"
					${ElseIf} $3 != "${ENGINEEXE_SHA256}"
						StrCpy $0 "its sha256 is $3, this uninstaller expects ${ENGINEEXE_SHA256}"
					${Else}
						StrCpy $UnEngineExe $4
						Return
					${EndIf}
				${EndIf}
			${EndIf}
		${EndIf}
	${EndIf}
	; refused: nothing was run and nothing was changed
	FileOpen $1 "$LogPath" a
	FileWrite $1 "Benchy uninstall: the engine copy was refused ($0).$\r$\n"
	FileClose $1
	MessageBox MB_OK|MB_ICONSTOP "The uninstaller's copy of the Benchy engine is not the one that was built into it ($0), so nothing was run and nothing was changed.$\r$\n$\r$\nDownload the Benchy installer again, or remove Benchy from Apps & Installed apps once it has been reinstalled." /SD IDOK
	SetErrorLevel ${EXIT_OTHER}
	Quit
FunctionEnd
!endif

; The pages' first call: files out, the machine asked once (what runs, what is installed), the engine held. Safe to call again.
Function un.Prepare
	${If} $UnPrepared = 1
		Return
	${EndIf}
	StrCpy $UnPrepared 1
	InitPluginsDir
	SetDetailsPrint none
	SetOutPath "$PLUGINSDIR\early"
	File "${PAYLOAD}/install.ps1"
	File "${PAYLOAD}/uninstall.ps1"
	SetOutPath "$PLUGINSDIR\early\pts-core\static\update"
	File "${PAYLOAD}/pts-core/static/update/benchy-windows.ps1"
	SetOutPath "$PLUGINSDIR"
	File "stop-benchy.ps1"
	File "uninstall-wizard.ps1"
!ifdef ENGINEEXE
	; the engine itself, not a zip: nothing is unpacked by anything that runs unelevated and unchecked
	SetOutPath "$PLUGINSDIR\engine"
	File "/oname=benchy-engine.exe" "${ENGINEEXE}"
!endif
	SetOutPath $TEMP
	SetDetailsPrint both
	StrCpy $UnEngineExe ""
!ifdef ENGINEEXE
	; held open and checked against the size and sha256 built into this exe BEFORE anything runs it (Info and Inventory included)
	Call un.HoldAndCheckEngine
!else
	${If} ${FileExists} "${ENGINE_EXE}"
		StrCpy $UnEngineExe "${ENGINE_EXE}"
	${EndIf}
!endif
	StrCpy $UnInfoEngine $UnEngineExe
	StrCpy $UnInfoZip ""
	Call un.RunInfo
	Call un.RunInventory
FunctionEnd

; uninstall-wizard.ps1 -Mode Inventory: inv.ini (the rows, the keep boxes' texts) and plan.json (the step rows), from what is really there
Function un.RunInventory
	Delete "$PLUGINSDIR\inv.ini"
	Delete "$PLUGINSDIR\plan.json"
	nsExec::Exec '"$PowerShell" -NoProfile -NonInteractive -InputFormat None -ExecutionPolicy Bypass -File "$PLUGINSDIR\uninstall-wizard.ps1" -Mode Inventory -WorkDir "$PLUGINSDIR" -Engine "$UnEngineExe" -Server "$UnServerHost" -Title "$UnServerTitle" -LogFile "$LogPath"'
	Pop $R0
	ReadINIStr $UnInvOk "$PLUGINSDIR\inv.ini" "inv" "ok"
	${If} $UnInvOk == 1
		ReadINIStr $UnMachine "$PLUGINSDIR\inv.ini" "inv" "machine"
		ReadINIStr $UnResultsText "$PLUGINSDIR\inv.ini" "keep" "results"
		ReadINIStr $UnTestsText "$PLUGINSDIR\inv.ini" "keep" "tests"
		ReadINIStr $UnStepCount "$PLUGINSDIR\inv.ini" "steps" "count"
	${Else}
		; Could not list it (the script did not run): a plan with no engine step, so uninstall.ps1 does it all and asks Windows itself
		StrCpy $UnInvOk 0
		StrCpy $UnMachine 0
		StrCpy $UnResultsText ""
		StrCpy $UnTestsText ""
		StrCpy $UnStepCount 2
		FileOpen $R0 "$PLUGINSDIR\plan.json" w
		FileWrite $R0 '{"rows":["Stop the server agent and close Benchy","Remove the app, engines and installed tests"],"map":{},"last":2,"unattended":false,"helpers":false,"policy":false,"machine_install":false,"reset":false}'
		FileClose $R0
		; the Removing page's labels, which the inventory would have written
		WriteINIStr "$PLUGINSDIR\inv.ini" "steps" "label1" "Stop the server agent and close Benchy"
		WriteINIStr "$PLUGINSDIR\inv.ini" "steps" "label2" "Remove the app, engines and installed tests"
	${EndIf}
	${If} $UnMachine == ""
		StrCpy $UnMachine 0
	${EndIf}
FunctionEnd

; --- The worker: uninstall-wizard.ps1 -Mode Run ------------------------------------------------------------------------------

; Starts it (no waiting): $UnProc is its handle, 0 when Windows PowerShell could not be started
Function un.StartWorker
	Delete "$PLUGINSDIR\progress.txt"
	Delete "$PLUGINSDIR\result.ini"
	StrCpy $UnProgressDone 0
	StrCpy $UnCode 50
	StrCpy $UnMessage ""
	StrCpy $UnReason ""
	StrCpy $UnLeftCount 0
	StrCpy $UnStill 0
	${IfNot} ${FileExists} $PowerShell
		StrCpy $UnProc 0
		StrCpy $UnMessage "Windows PowerShell could not be started ($PowerShell)."
		Return
	${EndIf}
	; Windows may show its permission prompt as only a blinking taskbar button when this window is not in front; let it come forward
	System::Call 'user32::AllowSetForegroundWindow(i -1)'
	Push '"$PowerShell" -NoProfile -NonInteractive -InputFormat None -ExecutionPolicy Bypass -File "$PLUGINSDIR\uninstall-wizard.ps1" -Mode Run -WorkDir "$PLUGINSDIR" -Engine "$UnEngineExe" -KeepResults $UnKeepResults -KeepTests $UnKeepTests -LogFile "$LogPath" -InPlaceExe "$UnInPlaceExe"'
	Call un.StartHidden
	Pop $UnProc
	${If} $UnProc = 0
		StrCpy $UnMessage "Windows PowerShell could not be started ($PowerShell)."
	${Else}
		StrCpy $UnRemoving 1
	${EndIf}
FunctionEnd

; The worker has ended (or never started): the exit code and what it wrote
Function un.WorkerResult
	StrCpy $R9 50
	${If} $UnProc <> 0
		System::Call 'kernel32::GetExitCodeProcess(p $UnProc, *i .R9)'
		System::Call 'kernel32::CloseHandle(p $UnProc)'
		StrCpy $UnProc 0
	${EndIf}
	StrCpy $UnRemoving 0
	${If} ${FileExists} "$PLUGINSDIR\result.ini"
		ReadINIStr $R0 "$PLUGINSDIR\result.ini" "result" "code"
		${If} $R0 != ""
			StrCpy $R9 $R0
		${EndIf}
		ReadINIStr $UnMessage "$PLUGINSDIR\result.ini" "result" "message"
		ReadINIStr $UnReason "$PLUGINSDIR\result.ini" "result" "reason"
		ReadINIStr $UnLeftCount "$PLUGINSDIR\result.ini" "left" "count"
		ReadINIStr $UnStill "$PLUGINSDIR\result.ini" "still" "machine_install"
	${Else}
		; the script died before it could say: a PowerShell that rejected the arguments gives 1, which here would read as "cancelled"
		${If} $R9 = 0
		${OrIf} $R9 = 1
			StrCpy $R9 50
		${EndIf}
		${If} $UnMessage == ""
			StrCpy $UnMessage "The uninstall stopped with exit code $R9 before it could say why. The log is $LogPath."
		${EndIf}
	${EndIf}
	${If} $UnLeftCount == ""
		StrCpy $UnLeftCount 0
	${EndIf}
	StrCpy $UnCode $R9
	; Windows said no: nothing was removed, so Benchy goes back the way it was (the worker closed it before asking)
	${If} $UnCode = ${UN_EXIT_PERMISSION}
		Call un.RestartClient
	${EndIf}
FunctionEnd

; The client goes back the way stop-benchy.ps1 found it (window or tray), as the installer does after an upgrade. This wizard runs unelevated,
; so the client does too. The tray starts the agent again if its setting says so.
Function un.RestartClient
	ReadINIStr $R0 "$PLUGINSDIR\stop.ini" "stop" "was_client"
	ReadINIStr $R1 "$PLUGINSDIR\stop.ini" "stop" "was_window"
	${If} $R0 != 1
	${OrIfNot} ${FileExists} "$LOCALAPPDATA\Benchy\client\BenchyClient.exe"
		Return
	${EndIf}
	${If} $R1 == 1
		Exec '"$LOCALAPPDATA\Benchy\client\BenchyClient.exe"'
	${Else}
		Exec '"$LOCALAPPDATA\Benchy\client\BenchyClient.exe" --tray'
	${EndIf}
FunctionEnd

; --- /S: the same worker, waited for -----------------------------------------------------------------------------------------------

Section Uninstall
	${If} ${Silent}
		Call un.Prepare
		Call un.StartWorker
		${If} $UnProc <> 0
			${Do}
				System::Call 'kernel32::WaitForSingleObject(p $UnProc, i 1000) i .R0'
			${LoopUntil} $R0 <> 258
		${EndIf}
		Call un.WorkerResult
		; the log names what went wrong (the wizard's pages are not there to)
		${If} $UnCode <> 0
		${AndIf} $UnCode <> 3010
			FileOpen $R0 "$LogPath" a
			FileSeek $R0 0 END
			FileWrite $R0 "Benchy uninstall: exit code $UnCode. $UnMessage$\r$\n"
			FileClose $R0
		${EndIf}
		SetErrorLevel $UnCode
	${EndIf}
SectionEnd

; --- Page 1: What goes ---------------------------------------------------------------------------------------------------------

Function un.WhatPageCreate
	Call un.Prepare
	!insertmacro BuiHeader "Uninstall Benchy" "Remove everything Benchy put on this computer."
	nsDialogs::Create 1018
	Pop $UnDialog
	${If} $UnDialog == error
		Abort
	${EndIf}
	!insertmacro BuiStrip3 1 "What goes" "Removing" "Done"
	${If} $UnInvOk = 1
		!insertmacro BuiText 14 10 "Benchy found these on $UnComputer. All of them are removed."
		ReadINIStr $R6 "$PLUGINSDIR\inv.ini" "rows" "count"
		StrCpy $R8 27
		StrCpy $R7 1
		${Do}
			${If} $R7 > $R6
				${ExitDo}
			${EndIf}
			ReadINIStr $R4 "$PLUGINSDIR\inv.ini" "rows" "text$R7"
			ReadINIStr $R5 "$PLUGINSDIR\inv.ini" "rows" "tag$R7"
			${If} $R5 == "all users"
				!insertmacro BuiRow $R8 none "$R4" "$R5" warn
			${Else}
				!insertmacro BuiRow $R8 none "$R4" "$R5" muted
			${EndIf}
			IntOp $R8 $R8 + 10
			IntOp $R7 $R7 + 1
		${Loop}
		IntOp $R9 $R8 - 24
		!insertmacro BuiFrame 25 $R9
		IntOp $R8 $R8 + 5
	${Else}
		!insertmacro BuiText 14 20 "Benchy could not list what is installed, so this removes Benchy's own folders and asks the engine to undo its setup. It keeps your results and settings by default."
		StrCpy $R8 40
	${EndIf}
	${If} $UnResultsText != ""
		StrCpy $R5 "Keep my results and settings ($UnResultsText)"
	${Else}
		StrCpy $R5 "Keep my results and settings"
	${EndIf}
	${NSD_CreateCheckbox} 0 $R8u 100% 10u "$R5"
	Pop $UnKeepResultsBox
	!insertmacro BuiFontOn $UnKeepResultsBox
	${If} $UnKeepResults = 1
		${NSD_Check} $UnKeepResultsBox
	${EndIf}
	IntOp $R8 $R8 + 11
	${If} $UnTestsText != ""
		StrCpy $R5 "Keep installed tests ($UnTestsText)"
	${Else}
		StrCpy $R5 "Keep installed tests"
	${EndIf}
	${NSD_CreateCheckbox} 0 $R8u 100% 10u "$R5"
	Pop $UnKeepTestsBox
	!insertmacro BuiFontOn $UnKeepTestsBox
	${If} $UnKeepTests = 1
		${NSD_Check} $UnKeepTestsBox
	${EndIf}
	IntOp $R8 $R8 + 14
	${If} $UnMachine = 1
		${If} $UnServerHost != ""
			!insertmacro BuiNote $R8 18 "Some of this is for all users, so Windows asks for permission once when you click Uninstall. $UnServerHost keeps listing $UnServerTitle until an admin removes it there."
		${Else}
			!insertmacro BuiNote $R8 18 "Some of this is for all users, so Windows asks for permission once when you click Uninstall."
		${EndIf}
	${ElseIf} $UnServerHost != ""
		!insertmacro BuiNote $R8 18 "$UnServerHost keeps listing $UnServerTitle until an admin removes it there. The installed tests download again if you ever need them."
	${EndIf}
	!insertmacro BuiShield $UnMachine
	GetDlgItem $R0 $HWNDPARENT 1
	SendMessage $R0 ${WM_SETTEXT} 0 "STR:&Uninstall"
	nsDialogs::Show
FunctionEnd

Function un.WhatPageLeave
	${NSD_GetState} $UnKeepResultsBox $UnKeepResults
	${NSD_GetState} $UnKeepTestsBox $UnKeepTests
	; Benchy may have been started or closed since the page was drawn: the next page, if one is needed, says what runs now
	StrCpy $UnInfoEngine $UnEngineExe
	StrCpy $UnInfoZip ""
	Call un.RunInfo
FunctionEnd

; --- Page 2: Benchy is running (only when something does) -------------------------------------------------------------------------

Function un.RunningPageCreate
	${If} $NeedStop <> 1
	${AndIf} $StopBlock == ""
		Abort
	${EndIf}
	!insertmacro BuiHeader "Uninstall Benchy" "Benchy has to stop before it can be removed."
	nsDialogs::Create 1018
	Pop $UnDialog
	${If} $UnDialog == error
		Abort
	${EndIf}
	!insertmacro BuiStrip3 1 "What goes" "Removing" "Done"
	${If} $StopBlock != ""
		!insertmacro BuiCallout 14 32 bad "Benchy is busy" "$StopBlock"
	${Else}
		${If} $RunJob != ""
			StrCpy $R8 "The server agent is running $\"$RunJob$\". Uninstalling stops it; the tests that finished are uploaded first."
			!insertmacro BuiCallout 14 32 warn "Benchy is running a server job" "$R8"
		${ElseIf} $RunLocal == 1
			!insertmacro BuiCallout 14 32 warn "Benchy is running a test" "Uninstalling stops it. Its partial result is kept."
		${ElseIf} $RunClient == 1
			!insertmacro BuiCallout 14 32 warn "Benchy is open" "Benchy and its tray close, then Benchy is removed."
		${Else}
			!insertmacro BuiCallout 14 32 warn "The server agent is running" "The agent is idle, so no job is interrupted. It stops, then Benchy is removed."
		${EndIf}
	${EndIf}
	StrCpy $R8 52
	${If} $RunAgent == 1
		${If} $RunJob != ""
			!insertmacro BuiRow $R8 now "Server agent, running a job" "stopped first" muted
		${Else}
			!insertmacro BuiRow $R8 now "Server agent" "stopped first" muted
		${EndIf}
		IntOp $R8 $R8 + 11
	${EndIf}
	${If} $RunClient == 1
		!insertmacro BuiRow $R8 now "Benchy window and tray" "closed" muted
		IntOp $R8 $R8 + 11
	${EndIf}
	IntOp $R8 $R8 + 4
	${If} $StopBlock == ""
		!insertmacro BuiNote $R8 18 "A local run in the window is treated the same: it's stopped and its partial result is kept."
	${EndIf}
	!insertmacro BuiShield $UnMachine
	GetDlgItem $R0 $HWNDPARENT 1
	${If} $StopBlock == ""
		; the callout says what is stopped; the button is no wider than the wizard's own
		SendMessage $R0 ${WM_SETTEXT} 0 "STR:&Uninstall"
	${Else}
		SendMessage $R0 ${WM_SETTEXT} 0 "STR:&Next >"
	${EndIf}
	nsDialogs::Show
FunctionEnd

Function un.RunningPageLeave
	StrCpy $UnInfoEngine $UnEngineExe
	StrCpy $UnInfoZip ""
	Call un.RunInfo
	${If} $StopBlock != ""
		MessageBox MB_OK|MB_ICONINFORMATION "$StopBlock"
		Abort
	${EndIf}
FunctionEnd

; --- Page 3: Removing ----------------------------------------------------------------------------------------------------------

; Reads the lines the worker added to progress.txt ("<row> start|done|fail", rows from 1); a last line with no end yet waits
Function un.ProgressPoll
	ClearErrors
	FileOpen $R0 "$PLUGINSDIR\progress.txt" r
	${If} ${Errors}
		Return
	${EndIf}
	StrCpy $R1 0
	${Do}
		FileRead $R0 $R2
		${If} ${Errors}
			${ExitDo}
		${EndIf}
		StrCpy $R3 $R2 1 -1
		${If} $R3 != "$\n"
			${ExitDo}
		${EndIf}
		IntOp $R1 $R1 + 1
		${If} $R1 > $UnProgressDone
			StrCpy $UnProgressDone $R1
			; the row (one digit: at most seven rows), then the state word
			StrCpy $R4 $R2 1
			IntOp $R4 $R4 - 1
			StrCpy $R5 $R2 4 2
			StrCpy $BuiA1 $UnDialog
			StrCpy $BuiA2 $R4
			${If} $R5 == "star"
				StrCpy $BuiA3 1
				StrCpy $BuiA4 "now"
				Call un.BuiStepSet
			${ElseIf} $R5 == "done"
				StrCpy $BuiA3 2
				StrCpy $BuiA4 "done"
				Call un.BuiStepSet
			${ElseIf} $R5 == "fail"
				StrCpy $BuiA3 3
				StrCpy $BuiA4 "left"
				Call un.BuiStepSet
			${EndIf}
		${EndIf}
	${Loop}
	FileClose $R0
FunctionEnd

; Every 400 ms: the rows, and whether the worker has ended
Function un.RemovePoll
	Call un.ProgressPoll
	StrCpy $R9 0
	${If} $UnProc <> 0
		System::Call 'kernel32::WaitForSingleObject(p $UnProc, i 0) i .R9'
	${EndIf}
	${If} $R9 = 258
		Return
	${EndIf}
	${NSD_KillTimer} un.RemovePoll
	Call un.ProgressPoll
	Call un.WorkerResult
	; forward: the page after this one decides what to show (Windows said no, or the end)
	SendMessage $HWNDPARENT 0x408 1 0
FunctionEnd

Function un.RemovingPageCreate
	!insertmacro BuiHeader "Removing Benchy" "This takes under a minute."
	nsDialogs::Create 1018
	Pop $UnDialog
	${If} $UnDialog == error
		Abort
	${EndIf}
	!insertmacro BuiStrip3 2 "What goes" "Removing" "Done"
	; the rows of the step list, in pixels: a row is 11 dialog units high, the list starts under the strip
	System::Call '*(i 0, i 0, i 0, i 11) p .r1'
	System::Call 'user32::MapDialogRect(p $UnDialog, p r1)'
	System::Call '*$1(i, i, i, i .R6)'
	System::Free $1
	System::Call '*(i 0, i 0, i 0, i 16) p .r1'
	System::Call 'user32::MapDialogRect(p $UnDialog, p r1)'
	System::Call '*$1(i, i, i, i .R4)'
	System::Free $1
	System::Call '*(i,i,i,i) p .r1'
	System::Call 'user32::GetClientRect(p $UnDialog, p r1)'
	System::Call '*$1(i, i, i .R5, i)'
	System::Free $1
	StrCpy $R7 0
	${Do}
		${If} $R7 >= $UnStepCount
			${ExitDo}
		${EndIf}
		IntOp $R8 $R7 + 1
		ReadINIStr $R3 "$PLUGINSDIR\inv.ini" "steps" "label$R8"
		StrCpy $BuiA1 $UnDialog
		StrCpy $BuiA2 0
		IntOp $R2 $R7 * $R6
		IntOp $BuiA3 $R4 + $R2
		StrCpy $BuiA4 $R5
		StrCpy $BuiA5 $R6
		StrCpy $BuiA6 $R7
		StrCpy $BuiA7 $R3
		Call un.BuiStepAdd
		IntOp $R7 $R7 + 1
	${Loop}
	IntOp $R8 $UnStepCount * 11
	IntOp $R8 $R8 + 20
	${If} $UnKeepResults = 1
		!insertmacro BuiNote $R8 18 "Your results and settings stay in %LOCALAPPDATA%\benchy-engine and %USERPROFILE%\.benchy."
	${EndIf}
	; nothing to click while it runs
	GetDlgItem $R0 $HWNDPARENT 1
	EnableWindow $R0 0
	GetDlgItem $R0 $HWNDPARENT 2
	EnableWindow $R0 0
	GetDlgItem $R0 $HWNDPARENT 3
	EnableWindow $R0 0
	!insertmacro BuiShield 0
	Call un.StartWorker
	${NSD_CreateTimer} un.RemovePoll 400
	nsDialogs::Show
FunctionEnd

Function un.RemovingPageLeave
	GetDlgItem $R0 $HWNDPARENT 1
	EnableWindow $R0 1
	GetDlgItem $R0 $HWNDPARENT 2
	EnableWindow $R0 1
	GetDlgItem $R0 $HWNDPARENT 3
	EnableWindow $R0 1
FunctionEnd

; --- Page 4: Windows said no (only after a declined prompt) -----------------------------------------------------------------------------

Function un.DeclinedPageCreate
	${If} $UnCode <> ${UN_EXIT_PERMISSION}
		Abort
	${EndIf}
	!insertmacro BuiHeader "Uninstall Benchy" "Permission is needed for the parts that are for all users."
	nsDialogs::Create 1018
	Pop $UnDialog
	${If} $UnDialog == error
		Abort
	${EndIf}
	!insertmacro BuiStrip3 1 "What goes" "Removing" "Done"
	!insertmacro BuiCallout 14 44 bad "Windows didn't give permission" "Nothing has been removed yet. Without permission, Benchy can remove only the parts in your account. The unattended agent would keep running and keep this computer's pairing."
	${If} $UnReason == "no_desktop"
		StrCpy $R8 "Windows had no desktop to show its prompt on. Run the uninstaller again from a signed-in session."
	${ElseIf} $UnReason == "uac_expired"
		StrCpy $R8 "Windows' prompt timed out before it was answered."
	${Else}
		StrCpy $R8 "Try again and approve Windows' prompt, or sign in as an administrator."
	${EndIf}
	!insertmacro BuiText 62 30 "$R8 If the prompt didn't come to the front, look for its blinking button on the taskbar."
	!insertmacro BuiShield 1
	; Try again is the way back; the wizard's own Back would start the same removal without saying why
	GetDlgItem $R0 $HWNDPARENT 3
	ShowWindow $R0 ${SW_HIDE}
	GetDlgItem $R0 $HWNDPARENT 1
	SendMessage $R0 ${WM_SETTEXT} 0 "STR:&Try again"
	nsDialogs::Show
FunctionEnd

; Try again: back to the Removing page, which starts the worker again (a post, so this click is finished first)
Function un.DeclinedPageLeave
	System::Call 'user32::PostMessage(p $HWNDPARENT, i 0x408, i -1, i 0)'
	Abort
FunctionEnd

; --- Page 5: Done, with leftovers, or not finished --------------------------------------------------------------------------------------

; Copies $UnDetails to the clipboard (as the installer's Copy details does)
Function un.CopyDetails
	Pop $R9
	StrCpy $R4 $UnDetails
	StrLen $R0 $R4
	IntOp $R0 $R0 + 1
	IntOp $R0 $R0 * 2
	System::Call 'user32::OpenClipboard(p $HWNDPARENT) i .R1'
	${If} $R1 <> 0
		System::Call 'user32::EmptyClipboard()'
		System::Call 'kernel32::GlobalAlloc(i 0x0002, p R0) p .R2'
		System::Call 'kernel32::GlobalLock(p R2) p .R3'
		System::Call 'kernel32::lstrcpyW(p R3, w R4)'
		System::Call 'kernel32::GlobalUnlock(p R2)'
		System::Call 'user32::SetClipboardData(i 13, p R2)'
		System::Call 'user32::CloseClipboard()'
	${EndIf}
FunctionEnd

; Restart now, after asking: the leftovers are removed when Windows starts
; NSIS's own Reboot only sets a flag for when the wizard quits, and it did not restart (no event 1074): the privilege is enabled and
; ExitWindowsEx called here, before anything quits, and a refusal is said out loud.
Function un.RestartNow
	Pop $R9
	MessageBox MB_YESNO|MB_ICONQUESTION|MB_DEFBUTTON2 "Restart Windows now? Save your work in other programs first." IDYES +2
	Return
	SetErrorLevel $UnCode
	; SeShutdownPrivilege on this process's token: TOKEN_PRIVILEGES { count 1, LUID (8 bytes), attributes SE_PRIVILEGE_ENABLED }
	System::Call 'kernel32::GetCurrentProcess() p .R0'
	System::Call 'advapi32::OpenProcessToken(p R0, i 0x28, *p .R1) i .R2'
	System::Call '*(i 1, l 0, i 2) p .R4'
	IntOp $R5 $R4 + 4
	System::Call 'advapi32::LookupPrivilegeValueW(p 0, w "SeShutdownPrivilege", p R5) i .R2'
	System::Call 'advapi32::AdjustTokenPrivileges(p R1, i 0, p R4, i 0, p 0, p 0) i .R2'
	System::Call 'kernel32::CloseHandle(p R1)'
	System::Free $R4
	; EWX_REBOOT | EWX_FORCEIFHUNG; planned, application, installation
	System::Call 'user32::ExitWindowsEx(i 0x12, i 0x80040002) i .R2 ?e'
	Pop $R3
	${If} $R2 = 0
		MessageBox MB_OK|MB_ICONEXCLAMATION "Windows did not restart (error $R3). Restart it yourself; the leftovers are removed when it starts."
	${EndIf}
FunctionEnd

; The kept paths and the list of what was left, as plain text for Copy
Function un.BuildDetails
	StrCpy $UnDetails "Benchy uninstaller: exit code $UnCode$\r$\nLog: $LogPath$\r$\n$UnMessage"
	StrCpy $R7 1
	${Do}
		${If} $R7 > $UnLeftCount
			${ExitDo}
		${EndIf}
		ReadINIStr $R4 "$PLUGINSDIR\result.ini" "left" "path$R7"
		ReadINIStr $R5 "$PLUGINSDIR\result.ini" "left" "reason$R7"
		StrCpy $UnDetails "$UnDetails$\r$\n$R4 ($R5)"
		IntOp $R7 $R7 + 1
	${Loop}
FunctionEnd

Function un.DonePageCreate
	StrCpy $UnDone 1
	SetErrorLevel $UnCode
	!insertmacro BuiShield 0
	Call un.BuildDetails
	${If} $UnCode = 0
		!insertmacro BuiHeader "Benchy is removed" "Thanks for benchmarking with Benchy."
	${ElseIf} $UnCode = 3010
		!insertmacro BuiHeader "Benchy is almost removed" "$UnLeftCount items are in use and go at the next restart."
	${ElseIf} $UnCode = 3
		!insertmacro BuiHeader "Benchy was not removed" "Exit code $UnCode"
	${Else}
		!insertmacro BuiHeader "Benchy was not fully removed" "Exit code $UnCode"
	${EndIf}
	nsDialogs::Create 1018
	Pop $UnDialog
	${If} $UnDialog == error
		Abort
	${EndIf}
	!insertmacro BuiStrip3 3 "What goes" "Removing" "Done"
	StrCpy $UnRestartBtn ""
	${If} $UnCode = 0
	${OrIf} $UnCode = 3010
		Call un.DoneOk
	${Else}
		Call un.DoneFailed
	${EndIf}
	; the one button left is Finish, as on the installer's last page
	GetDlgItem $R0 $HWNDPARENT 1
	SendMessage $R0 ${WM_SETTEXT} 0 "STR:&Close"
	GetDlgItem $R0 $HWNDPARENT 2
	ShowWindow $R0 ${SW_HIDE}
	GetDlgItem $R0 $HWNDPARENT 3
	ShowWindow $R0 ${SW_HIDE}
	nsDialogs::Show
FunctionEnd

; The kept paths of result.ini as lines of one label, at $R9, at most $R2 lines (the last says "and N more"); leaves $R9 below it
Function un.KeptLines
	ReadINIStr $R6 "$PLUGINSDIR\result.ini" "kept" "count"
	${If} $R6 == ""
	${OrIf} $R6 = 0
		Return
	${EndIf}
	!insertmacro BuiNote $R9 9 "Kept, as you asked"
	IntOp $R9 $R9 + 9
	StrCpy $R5 ""
	StrCpy $R3 $R6
	StrCpy $R8 $R6
	${If} $R6 > $R2
		; one line fewer than the room, for "and N more"
		IntOp $R8 $R2 - 1
		IntOp $R3 $R6 - $R8
		StrCpy $R3 $R2
	${EndIf}
	StrCpy $R7 1
	${Do}
		${If} $R7 > $R8
			${ExitDo}
		${EndIf}
		ReadINIStr $R4 "$PLUGINSDIR\result.ini" "kept" "path$R7"
		${If} $R5 == ""
			StrCpy $R5 "$R4"
		${Else}
			StrCpy $R5 "$R5$\r$\n$R4"
		${EndIf}
		IntOp $R7 $R7 + 1
	${Loop}
	${If} $R6 > $R2
		IntOp $R4 $R6 - $R8
		StrCpy $R5 "$R5$\r$\nand $R4 more"
	${EndIf}
	IntOp $R3 $R3 * 9
	!insertmacro BuiText $R9 $R3 "$R5"
	IntOp $R9 $R9 + $R3
	IntOp $R9 $R9 + 2
FunctionEnd

Function un.DoneOk
	${If} $UnCode = 0
		!insertmacro BuiText 14 20 "Benchy is removed from $UnComputer. Nothing it installed is left on this computer."
		StrCpy $R9 36
		StrCpy $R2 3
	${Else}
		!insertmacro BuiText 14 10 "Benchy is removed, except for what is in use. Everything else is gone."
		; Left behind / Why: three rows at most, the last "and N more" when there are more than three
		!insertmacro BuiLabel $R0 0 26u 150u 9u "Left behind" ${BUI_MUTED} $BuiFont
		!insertmacro BuiLabel $R0 154u 26u 110u 9u "Why" ${BUI_MUTED} $BuiFont
		StrCpy $R8 36
		StrCpy $R7 1
		StrCpy $R6 $UnLeftCount
		${If} $R6 > 3
			StrCpy $R6 2
		${EndIf}
		${Do}
			${If} $R7 > $UnLeftCount
			${OrIf} $R7 > $R6
				${ExitDo}
			${EndIf}
			ReadINIStr $R4 "$PLUGINSDIR\result.ini" "left" "path$R7"
			ReadINIStr $R5 "$PLUGINSDIR\result.ini" "left" "reason$R7"
			; SS_LEFT with SS_PATHELLIPSIS for the path, SS_ENDELLIPSIS for the reason
			nsDialogs::CreateControl STATIC ${DEFAULT_STYLES}|0x8000 0 0u $R8u 150u 9u "$R4"
			Pop $R0
			SetCtlColors $R0 ${BUI_INK} transparent
			SendMessage $R0 ${WM_SETFONT} $BuiFont 1
			nsDialogs::CreateControl STATIC ${DEFAULT_STYLES}|0x4000 0 154u $R8u 110u 9u "$R5"
			Pop $R0
			SetCtlColors $R0 ${BUI_INK} transparent
			SendMessage $R0 ${WM_SETFONT} $BuiFont 1
			IntOp $R8 $R8 + 10
			IntOp $R7 $R7 + 1
		${Loop}
		${If} $UnLeftCount > 3
			IntOp $R3 $UnLeftCount - 2
			!insertmacro BuiNote $R8 9 "and $R3 more (the log has the list)"
			IntOp $R8 $R8 + 10
		${EndIf}
		IntOp $R9 $R8 + 2
		!insertmacro BuiText $R9 9 "They're removed the next time Windows starts. Nothing else needs doing."
		IntOp $R9 $R9 + 12
		StrCpy $R2 2
	${EndIf}
	Call un.KeptLines
	${If} $UnCode = 0
		${If} $UnStill == 1
			!insertmacro BuiNote $R9 9 "Still installed"
			IntOp $R9 $R9 + 9
			!insertmacro BuiText $R9 18 "Software installed for administrator tests, for example PassMark PerformanceTest, stays. Remove it from Windows' Installed apps if you don't need it."
			IntOp $R9 $R9 + 20
		${EndIf}
		${If} $UnServerHost != ""
			!insertmacro BuiNote $R9 9 "On the server"
			IntOp $R9 $R9 + 9
			${If} $UnServerTitle != ""
				!insertmacro BuiText $R9 18 "$UnServerHost still lists $UnServerTitle. If this computer won't run benchmarks again, remove it from the server's Systems page."
			${Else}
				!insertmacro BuiText $R9 18 "$UnServerHost still lists this computer. If it won't run benchmarks again, remove it from the server's Systems page."
			${EndIf}
		${EndIf}
	${EndIf}
	${If} $UnCode = 3010
		${NSD_CreateButton} 0 118u 70u 12u "Copy the &list"
		Pop $R0
		!insertmacro BuiFontOn $R0
		${NSD_OnClick} $R0 un.CopyDetails
		${NSD_CreateButton} 194u 118u 70u 12u "&Restart now"
		Pop $UnRestartBtn
		!insertmacro BuiFontOn $UnRestartBtn
		${NSD_OnClick} $UnRestartBtn un.RestartNow
	${EndIf}
FunctionEnd

Function un.DoneFailed
	${If} $UnCode = 3
		StrCpy $R8 "Benchy is running"
		StrCpy $R7 "Nothing has been removed."
	${ElseIf} $UnCode = 40
		StrCpy $R8 "Not everything was removed"
		StrCpy $R7 "Benchy is partly removed. Run the uninstaller again to finish, or remove what the log names by hand."
	${ElseIf} $UnCode = 2
		StrCpy $R8 "The uninstaller could not start"
		StrCpy $R7 "Nothing has been removed."
	${Else}
		StrCpy $R8 "The uninstall did not finish"
		StrCpy $R7 "Some of Benchy may have been removed. Run the uninstaller again, or see the log."
	${EndIf}
	!insertmacro BuiCallout 14 52 bad "$R8" "$UnMessage"
	!insertmacro BuiText 70 20 "$R7"
	!insertmacro BuiNote 92 10 "Log"
	!insertmacro BuiText 102 20 "$LogPath"
	${NSD_CreateButton} 0 118u 70u 12u "&Copy details"
	Pop $R0
	!insertmacro BuiFontOn $R0
	${NSD_OnClick} $R0 un.CopyDetails
FunctionEnd

; Close: the exit code is the uninstall's, and the wizard ends here (the instfiles page after this one is only for /S, and ending the
; wizard by skipping past the last page left an empty window under Wine)
Function un.DonePageLeave
	SetErrorLevel $UnCode
	Quit
FunctionEnd

Function un.onGUIEnd
	${If} $UnDone = 1
		SetErrorLevel $UnCode
	${EndIf}
FunctionEnd

!endif
