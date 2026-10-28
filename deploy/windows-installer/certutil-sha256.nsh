; Parser for the output of "certutil -hashfile <file> SHA256", included by benchy.nsi and by
; certutil-sha256-test.nsi (its unit test). Self-contained: needs only LogicLib.
;
; certutil prints a header line naming the file (localized), the hash, and a status line (localized):
;   Windows 11:          SHA256 hash of C:\...\VC_redist.x64.exe:
;                        cc0ff0eb1dc3f5188ae6300faef32bf5beeba4bdd6e8e445a9184072096b713b
;                        CertUtil: -hashfile command completed successfully.
;   older Windows 10:    the same, with the hash as bytes separated by spaces: cc 0f f0 eb ...
; So the hash is the one line that, with spaces and tabs removed, is exactly 64 hex digits. Language-independent.
;
; Push <certutil output>, Call CertutilSha256, Pop <the 64 hex digits as printed (any case), or "" when the output
; has no such line or more than one>

Function CertutilSha256
	Exch $0 ; output
	Push $1 ; position
	Push $2 ; character
	Push $3 ; current line, without spaces and tabs
	Push $4 ; the hash line found
	Push $5 ; number of hash lines found
	Push $6 ; scratch: length, hex check position
	Push $7 ; scratch: 1 while the line is all hex
	Push $8 ; scratch: hex digit index
	Push $9 ; scratch: character
	StrCpy $1 0
	StrCpy $3 ""
	StrCpy $4 ""
	StrCpy $5 0
	${Do}
		StrCpy $2 $0 1 $1
		${If} $2 == ""
		${OrIf} $2 == "$\r"
		${OrIf} $2 == "$\n"
			; The end of a line: is it 64 hex digits?
			StrLen $6 $3
			${If} $6 = 64
				StrCpy $7 1
				StrCpy $6 0
				${Do}
					StrCpy $9 $3 1 $6
					${If} $9 == ""
						${ExitDo}
					${EndIf}
					StrCpy $8 0
					${Do}
						StrCpy $2 "0123456789abcdefABCDEF" 1 $8
						${If} $2 == ""
							StrCpy $7 0
							${ExitDo}
						${EndIf}
						${If} $2 S== $9
							${ExitDo}
						${EndIf}
						IntOp $8 $8 + 1
					${Loop}
					${If} $7 = 0
						${ExitDo}
					${EndIf}
					IntOp $6 $6 + 1
				${Loop}
				${If} $7 = 1
					IntOp $5 $5 + 1
					StrCpy $4 $3
				${EndIf}
			${EndIf}
			StrCpy $3 ""
			; $2 was reused by the hex check: the end of the output is the end of $0
			StrCpy $2 $0 1 $1
			${If} $2 == ""
				${ExitDo}
			${EndIf}
		${ElseIf} $2 != " "
		${AndIf} $2 != "$\t"
			StrCpy $3 "$3$2"
		${EndIf}
		IntOp $1 $1 + 1
	${Loop}
	${If} $5 <> 1
		StrCpy $4 ""
	${EndIf}
	StrCpy $0 $4
	Pop $9
	Pop $8
	Pop $7
	Pop $6
	Pop $5
	Pop $4
	Pop $3
	Pop $2
	Pop $1
	Exch $0
FunctionEnd
