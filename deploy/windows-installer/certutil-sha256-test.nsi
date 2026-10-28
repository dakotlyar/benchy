; Unit test for certutil-sha256.nsh (the parser benchy.nsi uses on "certutil -hashfile <file> SHA256" output).
; Not part of the installer. Build it with the same makensis as the installer, from the repo root:
;   podman run --rm --network=none -v "$PWD/deploy/windows-installer:/w:ro" -v "$PWD/out:/out" <benchy-nsis image> \
;     -V2 -WX -DOUTFILE=/out/certutil-sha256-test.exe /w/certutil-sha256-test.nsi
; Run it on Windows (or under Wine): no window; the exit code is the number of failed cases (0: all passed), and
; %TEMP%\certutil-sha256-test.log lists each case.

!ifndef OUTFILE
	!define OUTFILE "certutil-sha256-test.exe"
!endif

Unicode true
RequestExecutionLevel user
SilentInstall silent
Name "certutil-sha256 test"
OutFile "${OUTFILE}"

!include LogicLib.nsh
!include "certutil-sha256.nsh"

!define HASH "cc0ff0eb1dc3f5188ae6300faef32bf5beeba4bdd6e8e445a9184072096b713b"
!define HASH_UPPER "CC0FF0EB1DC3F5188AE6300FAEF32BF5BEEBA4BDD6E8E445A9184072096B713B"
!define HASH_SPACED "cc 0f f0 eb 1d c3 f5 18 8a e6 30 0f ae f3 2b f5 be eb a4 bd d6 e8 e4 45 a9 18 40 72 09 6b 71 3b"
!define DONE "CertUtil: -hashfile command completed successfully."

Var Failures
Var Log

!macro Case _NAME _INPUT _EXPECT
	Push "${_INPUT}"
	Call CertutilSha256
	Pop $0
	${If} $0 S== "${_EXPECT}"
		FileWrite $Log "PASS ${_NAME}$\r$\n"
	${Else}
		IntOp $Failures $Failures + 1
		FileWrite $Log "FAIL ${_NAME}: got '$0', expected '${_EXPECT}'$\r$\n"
	${EndIf}
!macroend

Section
	StrCpy $Failures 0
	FileOpen $Log "$TEMP\certutil-sha256-test.log" w

	!insertmacro Case "Windows 11 output" "SHA256 hash of C:\Users\me\AppData\Local\Temp\nsx1.tmp\vc\VC_redist.x64.exe:$\r$\n${HASH}$\r$\n${DONE}$\r$\n" "${HASH}"
	!insertmacro Case "Windows 10 output, bytes separated by spaces" "SHA256 hash of file C:\Users\me\AppData\Local\Temp\nsx1.tmp\vc\VC_redist.x64.exe:$\r$\n${HASH_SPACED}$\r$\n${DONE}$\r$\n" "${HASH}"
	!insertmacro Case "upper case, kept as printed" "SHA256 hash of C:\x.exe:$\r$\n${HASH_UPPER}$\r$\n${DONE}$\r$\n" "${HASH_UPPER}"
	!insertmacro Case "LF line ends, no final line end" "SHA256 hash of C:\x.exe:$\n${HASH}$\n${DONE}" "${HASH}"
	!insertmacro Case "localized header and status" "SHA256-Hash der Datei C:\x.exe:$\r$\n${HASH}$\r$\nCertUtil: -hashfile-Befehl wurde erfolgreich ausgef$\r$\n" "${HASH}"
	!insertmacro Case "hash line with trailing spaces and a tab" "SHA256 hash of C:\x.exe:$\r$\n$\t${HASH}  $\r$\n${DONE}$\r$\n" "${HASH}"
	!insertmacro Case "file named like a hash" "SHA256 hash of C:\t\${HASH}:$\r$\n${HASH}$\r$\n${DONE}$\r$\n" "${HASH}"
	!insertmacro Case "sharing violation" "CertUtil: -hashfile command FAILED: 0x80070020 (WIN32: 32 ERROR_SHARING_VIOLATION)$\r$\nCertUtil: The process cannot access the file because it is being used by another process.$\r$\n" ""
	!insertmacro Case "two hash lines" "${HASH}$\r$\n${HASH}$\r$\n" ""
	!insertmacro Case "63 hex digits" "SHA256 hash of C:\x.exe:$\r$\ncc0ff0eb1dc3f5188ae6300faef32bf5beeba4bdd6e8e445a9184072096b713$\r$\n${DONE}$\r$\n" ""
	!insertmacro Case "65 hex digits" "SHA256 hash of C:\x.exe:$\r$\n${HASH}0$\r$\n${DONE}$\r$\n" ""
	!insertmacro Case "64 characters, one not hex" "SHA256 hash of C:\x.exe:$\r$\ncc0ff0eb1dc3f5188ae6300faef32bf5beeba4bdd6e8e445a9184072096b713g$\r$\n${DONE}$\r$\n" ""
	!insertmacro Case "empty output" "" ""

	FileWrite $Log "failures: $Failures$\r$\n"
	FileClose $Log
	SetErrorLevel $Failures
SectionEnd
