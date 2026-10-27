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
; updater moved forward). There is no NSIS uninstaller: install.ps1 registers uninstall.ps1.
;
; Build defines (all required; release.sh passes them):
;   VERSION      PTS_VERSION of the tree, e.g. 0.7.1 (shown, and compared with the installed DisplayVersion)
;   PAYLOAD      the git-archive tree to install
;   PHPZIP       the pinned PHP zip, already verified against the tree's runtime-pins.json
;   OUTFILE      the installer exe to write
;   BUILD_LABEL  optional; marks a dev build (release.sh installer --ref) in the window caption and branding
;
; Command line (silent mode, for scripts):
;   Benchy-Setup-<ver>.exe [/S] [/PHOROMATIC=ip:port/accountid] [/VCREDIST=auto|never] [/LOG=<path>]
;   /S            no wizard. VC++ follows install.ps1's -NonInteractive default (Never unless already elevated).
;   /PHOROMATIC=  save this Phoromatic server; without it the current setting is kept. Also pre-fills the wizard.
;   /VCREDIST=    auto installs the VC++ runtime when needed (may show a UAC prompt), never fails with exit code 20.
;                 The wizard always uses auto unless this is given.
;   /LOG=         install.ps1's log file (default %TEMP%\Benchy-install.log); a file, at most 260 characters. Quote a
;                 path with spaces: /LOG="C:\My Logs\benchy.log" (or "/LOG=C:\My Logs\benchy.log").
;   Option names are case-insensitive. A value runs to the next space, or to the closing quote when it starts with
;   one; it may contain / (10.0.0.5:8080/ABC123, C:/logs/x.log).
;   /D= is rejected with exit code 2 (the install dir is fixed), and so are an invalid /PHOROMATIC, /VCREDIST or /LOG.
; The exit code is install.ps1's (0, 3010, 2, 3, 10, 11, 20, 21, 30, 40, 50; see install.ps1), or:
;   1   the wizard was cancelled, or Windows PowerShell did not run install.ps1 (it rejected the arguments, or a
;       group policy execution policy blocks the script); Benchy was not changed
;   2   this installer refused before running install.ps1 (the reasons above, or not 64-bit Windows)
;   50  also when install.ps1 never started: the installer could not unpack its files, or Windows PowerShell could
;       not be started; nothing was changed

!macro RequireDefine _NAME
	!ifndef ${_NAME}
		!error "benchy.nsi needs -D${_NAME}; build it with deploy/release.sh installer"
	!endif
!macroend
!insertmacro RequireDefine VERSION
!insertmacro RequireDefine PAYLOAD
!insertmacro RequireDefine PHPZIP
!insertmacro RequireDefine OUTFILE

Unicode true
ManifestDPIAware true
RequestExecutionLevel user
SetCompressor /SOLID lzma
; A file that cannot be unpacked offers Abort/Retry only: an Ignore would hand install.ps1 a partial tree, which its
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
ShowInstDetails show

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

; The same icon install.ps1 registers as the Apps & Features DisplayIcon
!define MUI_ICON "${PAYLOAD}/pts-core/static/images/benchy.ico"

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

Var InstallDialog
Var PhoromaticField
Var PhoromaticError
Var FinishOpen

Page custom InstallPageCreate InstallPageLeave
!insertmacro MUI_PAGE_INSTFILES
Page custom FinishPageCreate FinishPageLeave

!insertmacro MUI_LANGUAGE "English"

ReserveFile /plugin nsDialogs.dll
ReserveFile /plugin System.dll

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
; ^[A-Za-z0-9.-]+:[0-9]{1,5}/[A-Za-z0-9]+$ (install.ps1 checks again with the real pattern).
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

; Push <parameters>, Push <option, e.g. /LOG=>, Call GetCmdOption, Pop <value>, Pop <1 found|0>.
; Not ${GetOptions}: it takes every / as the start of the next option, so /PHOROMATIC=10.0.0.5:8080/ABC123 came back
; as 10.0.0.5:8080 and /LOG=C:/logs/x.log as C:. Here the option counts only at the start of a token (any case), and
; its value runs to the next space or tab, or, when it starts with a double quote, to the closing one. A token quoted
; as a whole ("/LOG=C:\My Logs\x.log", as PowerShell passes an argument with spaces) works too. The first one wins.
Function GetCmdOption
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
	StrLen $3 $0
	StrCpy $2 0
	StrCpy $5 " "
	StrCpy $6 0
	StrCpy $8 ""
	${Do}
		StrCpy $4 $1 1 $2
		${If} $4 == ""
			${ExitDo}
		${EndIf}
		${If} $5 == " "
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

!macro GetCmdOption _PARAMS _OPTION _FOUND _VALUE
	Push "${_PARAMS}"
	Push "${_OPTION}"
	Call GetCmdOption
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
			!insertmacro Refuse "/PHOROMATIC must be ip:port/accountid, for example 10.0.0.5:8080/ABC123 (got '$Phoromatic')."
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
		${If} $R2 > 260
		${OrIf} $R3 == "\"
		${OrIf} $R3 == "/"
			!insertmacro Refuse "/LOG must be a file path of at most 260 characters, not a folder (got '$R1')."
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
		${If} $R2 > 260
			!insertmacro Refuse "/LOG must be at most 260 characters as a full path (got '$LogPath')."
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

; --- Page 1: Install -------------------------------------------------------------------------------------------------

Function UpdateInstallButton
	${NSD_GetText} $PhoromaticField $R0
	StrCpy $R1 1
	${If} $R0 != ""
		Push $R0
		Call IsPhoromaticServer
		Pop $R1
	${EndIf}
	GetDlgItem $R2 $HWNDPARENT 1
	${If} $R1 = 1
		ShowWindow $PhoromaticError ${SW_HIDE}
		EnableWindow $R2 1
	${Else}
		ShowWindow $PhoromaticError ${SW_SHOW}
		EnableWindow $R2 0
	${EndIf}
FunctionEnd

Function OnPhoromaticChange
	Pop $R9 ; the control
	Call UpdateInstallButton
FunctionEnd

Function InstallPageCreate
	${If} $OldVersion == ""
		!insertmacro MUI_HEADER_TEXT "Install Benchy ${VERSION}" "Benchy benchmarking client"
	${ElseIf} $VersionOrder = 0
		!insertmacro MUI_HEADER_TEXT "Reinstall Benchy ${VERSION}" "Benchy ${VERSION} is already installed"
	${ElseIf} $VersionOrder = 1
		!insertmacro MUI_HEADER_TEXT "Downgrade Benchy $OldVersion to ${VERSION}" "A newer Benchy is installed"
	${Else}
		!insertmacro MUI_HEADER_TEXT "Upgrade Benchy $OldVersion to ${VERSION}" "Benchy benchmarking client"
	${EndIf}

	nsDialogs::Create 1018
	Pop $InstallDialog
	${If} $InstallDialog == error
		Abort
	${EndIf}

	ReadEnvStr $R0 USERNAME
	StrCpy $R1 "Installs for $R0 in $LOCALAPPDATA\Benchy. No administrator rights needed; Windows may ask once to install the Microsoft Visual C++ Runtime."
	${If} $OldVersion != ""
		StrCpy $R1 "$R1$\r$\n$\r$\nYour results and settings in $PROFILE\.benchy are kept."
	${EndIf}
	${If} ${IsNativeARM64}
		StrCpy $R1 "$R1$\r$\n$\r$\nThis is ARM64 Windows. Benchy installs its x64 PHP, which runs under emulation; this is untested."
	${EndIf}
	${NSD_CreateLabel} 0 0 100% 60u $R1
	Pop $R0

	${NSD_CreateLabel} 0 70u 100% 10u "Phoromatic server (optional): ip:port/accountid"
	Pop $R0
	${NSD_CreateText} 0 82u 100% 12u $Phoromatic
	Pop $PhoromaticField
	${NSD_CreateLabel} 0 98u 100% 10u "Leave blank to keep the current setting. Example: 10.0.0.5:8080/ABC123"
	Pop $R0
	${NSD_CreateLabel} 0 112u 100% 10u "Not ip:port/accountid. Fix it, or clear the field to skip Phoromatic."
	Pop $PhoromaticError
	SetCtlColors $PhoromaticError C00000 transparent
	${NSD_OnChange} $PhoromaticField OnPhoromaticChange

	GetDlgItem $R0 $HWNDPARENT 1
	SendMessage $R0 ${WM_SETTEXT} 0 "STR:&Install"
	Call UpdateInstallButton
	${NSD_SetFocus} $PhoromaticField

	nsDialogs::Show
FunctionEnd

Function InstallPageLeave
	${NSD_GetText} $PhoromaticField $Phoromatic
	${If} $Phoromatic != ""
		Push $Phoromatic
		Call IsPhoromaticServer
		Pop $R0
		${If} $R0 <> 1
			Abort
		${EndIf}
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

Section "Benchy"
	; 64-bit Windows PowerShell: this stub is 32-bit, and $SYSDIR would give the 32-bit one (wrong registry view for
	; VC++, wrong bitness for PHP). Sysnative is the 64-bit System32 as a 32-bit process sees it.
	StrCpy $PowerShell "$WINDIR\Sysnative\WindowsPowerShell\v1.0\powershell.exe"

	DetailPrint "Unpacking Benchy ${VERSION}..."
	SetDetailsPrint textonly
	InitPluginsDir
	SetOutPath "$PLUGINSDIR\tree"
	File /r "${PAYLOAD}/*"
	SetOutPath "$PLUGINSDIR\php"
	File "/oname=php.zip" "${PHPZIP}"
	SetOutPath "$PLUGINSDIR"
	File "result-to-ini.ps1"
	SetDetailsPrint both

	StrCpy $ResultMessage ""
	StrCpy $RebootRecommended 0
	StrCpy $RebootRequired 0
	StrCpy $Warnings ""
	StrCpy $NotStarted ""

	${IfNot} ${FileExists} "$PLUGINSDIR\tree\install.ps1"
	${OrIfNot} ${FileExists} "$PLUGINSDIR\php\php.zip"
		StrCpy $NotStarted "The installer could not unpack its files to $PLUGINSDIR."
	${ElseIfNot} ${FileExists} $PowerShell
		StrCpy $NotStarted "Windows PowerShell could not be started ($PowerShell)."
	${Else}
		; -LogFile last: NSIS strings stop at 1024 characters, so a long log path is what would be cut, not an option
		StrCpy $R0 '"$PowerShell" -NoProfile -NonInteractive -InputFormat None -ExecutionPolicy Bypass -File "$PLUGINSDIR\tree\install.ps1" -NonInteractive -InstallSource gui -PhpZip "$PLUGINSDIR\php\php.zip" -PhpZipMode IfOlder -ResultFile "$PLUGINSDIR\result.json"'
		${If} $VcRedist != ""
			StrCpy $R0 '$R0 -VcRedist $VcRedist'
		${EndIf}
		${If} $Phoromatic != ""
			StrCpy $R0 '$R0 -PhoromaticServer "$Phoromatic"'
		${EndIf}
		StrCpy $R0 '$R0 -LogFile "$LogPath"'
		DetailPrint "Running install.ps1..."
		nsExec::ExecToLog $R0
		Pop $ExitCode
		${If} $ExitCode == "error"
			StrCpy $NotStarted "Windows PowerShell could not be started ($PowerShell)."
		${EndIf}
	${EndIf}

	${If} $NotStarted != ""
		; install.ps1 never ran: nothing was changed, and there is no log
		StrCpy $ExitCode ${EXIT_OTHER}
		StrCpy $ResultMessage $NotStarted
	${Else}
		nsExec::Exec '"$PowerShell" -NoProfile -NonInteractive -InputFormat None -ExecutionPolicy Bypass -File "$PLUGINSDIR\result-to-ini.ps1" "$PLUGINSDIR\result.json" "$PLUGINSDIR\result.ini"'
		Pop $R0
		ReadINIStr $ResultMessage "$PLUGINSDIR\result.ini" "result" "message"
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

	${If} $ExitCode = 0
	${OrIf} $ExitCode = 3010
		StrCpy $Ok 1
	${Else}
		StrCpy $Ok 0
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
		DetailPrint "Benchy was not installed (exit code $ExitCode): $ResultMessage"
		${If} $NotStarted == ""
			DetailPrint "Log: $LogPath"
		${EndIf}
	${EndIf}
SectionEnd

; --- Page 3: finish --------------------------------------------------------------------------------------------------

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

Function FinishPageCreate
	${If} $Ok = 1
		!insertmacro MUI_HEADER_TEXT "Benchy is installed" "Benchy ${VERSION}"
		StrCpy $R1 "Benchy is installed. Open a new terminal and run benchy, or use Benchy in the Start Menu.$\r$\n$\r$\nThe first time Benchy runs it will set up Cygwin, which may ask for permission."
		StrCpy $Details ""
		${If} $RebootRecommended == 1
			StrCpy $Details "$Details$\r$\nA reboot is recommended to finish the Microsoft Visual C++ Runtime install."
		${EndIf}
		StrCpy $Details "$Details$Warnings"
	${Else}
		!insertmacro MUI_HEADER_TEXT "Benchy was not installed" "Exit code $ExitCode"
		StrCpy $R1 $ResultMessage
		; What was changed, from install.ps1's exit codes
		${If} $NotStarted != ""
			StrCpy $R2 "Nothing was changed."
		${ElseIf} $RebootRequired == 1
			StrCpy $R2 "The Microsoft Visual C++ Runtime was installed, but Windows needs a restart before Benchy can use it. Benchy itself was not installed: restart, then run this installer again."
		${ElseIf} $ExitCode = 1
			StrCpy $R2 "Windows PowerShell did not run the install: it rejected the arguments, or a policy blocks scripts. Nothing was changed."
		${ElseIf} $ExitCode >= 2
		${AndIf} $ExitCode <= 21
			StrCpy $R2 "Nothing was changed."
		${ElseIf} $ExitCode = 30
			StrCpy $R2 "Benchy itself was not copied. The message above says if the PHP folder needs a hand."
		${ElseIf} $ExitCode = 40
			StrCpy $R2 "Benchy was only partly copied. Close every Benchy window and run this installer again."
		${Else}
			StrCpy $R2 "The install did not finish. The log shows how far it got."
		${EndIf}
		StrCpy $R1 "$R1$\r$\n$\r$\n$R2"
		${If} $NotStarted != ""
			StrCpy $Details "Benchy ${VERSION} installer: exit code $ExitCode$\r$\n$ResultMessage$\r$\n$R2"
		${Else}
			; The log path first: NSIS strings stop at 1024 characters, so a long message or warnings lose their end instead
			StrCpy $Details "Benchy ${VERSION} installer: exit code $ExitCode$\r$\nLog: $LogPath$\r$\n$ResultMessage$\r$\n$R2$Warnings"
		${EndIf}
	${EndIf}

	nsDialogs::Create 1018
	Pop $R0
	${If} $R0 == error
		Abort
	${EndIf}

	${NSD_CreateLabel} 0 0 100% 50u $R1
	Pop $R0

	${If} $Ok = 1
		${If} $Details != ""
			; Drop the leading line break
			StrCpy $Details $Details "" 2
			; A read-only, word-wrapped, scrolling edit box (NSD_CreateTextMultiline scrolls sideways instead of wrapping)
			nsDialogs::CreateControl EDIT ${DEFAULT_STYLES}|${WS_TABSTOP}|${WS_VSCROLL}|${ES_MULTILINE}|${ES_READONLY}|${ES_AUTOVSCROLL} ${WS_EX_CLIENTEDGE} 0 54u 100% 50u $Details
			Pop $R0
		${EndIf}
		${NSD_CreateCheckbox} 0 112u 100% 12u "&Open Benchy now"
		Pop $FinishOpen
	${Else}
		${If} $NotStarted == ""
			${NSD_CreateLabel} 0 54u 100% 20u "Log: $LogPath"
			Pop $R0
		${EndIf}
		${NSD_CreateButton} 0 78u 80u 14u "&Copy details"
		Pop $R0
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
			; The Start Menu shortcut install.ps1 made (benchy.bat finds its PHP without the new PHP_BIN)
			ExecShell "open" "$SMPROGRAMS\Benchy.lnk"
		${EndIf}
	${EndIf}
FunctionEnd
