; Benchy's installer and uninstaller look (NSIS 3). One set of parts, so the two wizards read as one product:
; the header (the page title and subtitle, and the "B" mark as the header bitmap), a step strip, rows (a glyph, a label, a scope tag), a
; two-column-plus table, a callout, a frame, and the footer's shield on the main button.
;
; benchy.nsi includes this after MUI2.nsh and nsDialogs.nsh. The uninstaller (benchy-uninstall.nsh, part of the same compile) uses the same
; macros and inserts BuiFunctions with "un." as well. The file has a UTF-8 byte order mark: it holds the few non-ASCII glyphs of the step list.
;
; Usage, in a page function after nsDialogs::Create (all positions are dialog units):
;   !insertmacro BuiHeader "Title" "Subtitle"      sets the MUI header text (call before nsDialogs::Create, as MUI_HEADER_TEXT is)
;   !insertmacro BuiStrip 2 "Install" "Installing" "Server" "Done"   the step strip, step 2 current
;   !insertmacro BuiFrame 14 44                    a hairline above (y 14) and below (y 58) a list of rows
;   !insertmacro BuiRow 16 ok "Label" "tag" muted  one row: ok|todo|now|bad|none, the label, a tag (muted|warn|good)
;   !insertmacro BuiTable 60 "Part" "Now" "After" "" ... see BuiTableRow
;   !insertmacro BuiCallout 90 26 warn "Title" "Body text"      warn|bad|calm
;   !insertmacro BuiNote 118 10 "Muted text"       small muted text
;   !insertmacro BuiShield 1 | 0                   the shield on the footer's main button (Windows will ask)
; The macros use $R0-$R3 and the page's own variables are not touched. Texts may use NSIS variables ($USERNAME) but not "&".
;
; The step list on the instfiles page (BuiFunctions): BuiStepAdd makes a row from the globals BuiA1..BuiA7, BuiStepSet changes its state
; from BuiA1..BuiA4. It uses SendMessage and ShowWindow only after creation, which is safe from the section's thread. The controls are
; made with CreateWindowEx and found again by control id (5000 + row * 10 + part), so no handle has to be kept.

!ifndef BENCHY_UI_NSH
!define BENCHY_UI_NSH

; text and background colours, 0xRRGGBB (the client's Theme.qml, light)
!define BUI_INK     0x15202A
!define BUI_MUTED   0x5A6875
!define BUI_ACCENT  0x0B7480
!define BUI_GOOD    0x1F7A3D
!define BUI_WARN    0x9A5B00
!define BUI_BAD     0xB3261E
!define BUI_INFO    0x2F6FD6
!define BUI_WARN_BG 0xFBF1DF
!define BUI_BAD_BG  0xF8E5E3
!define BUI_CALM_BG 0xD8EEF0

; glyphs of the step list and the rows (Segoe UI Symbol)
!define BUI_G_OK   "✓"
!define BUI_G_TODO "○"
!define BUI_G_NOW  "●"
!define BUI_G_BAD  "✗"

Var BuiFont
Var BuiFontBold
Var BuiFontSym
Var BuiA1
Var BuiA2
Var BuiA3
Var BuiA4
Var BuiA5
Var BuiA6
Var BuiA7

; --- header ----------------------------------------------------------------------------------------------------------

; The "B" mark is the header bitmap (benchy-header.bmp, 150x57), set once before the first MUI page
!macro BuiHeaderImage
	!define MUI_HEADERIMAGE
	!define MUI_HEADERIMAGE_BITMAP "benchy-header.bmp"
	!define MUI_HEADERIMAGE_RIGHT
!macroend

!macro BuiHeader _TITLE _SUB
	!insertmacro MUI_HEADER_TEXT "${_TITLE}" "${_SUB}"
!macroend

; --- parts of an nsDialogs page ----------------------------------------------------------------------------------------

; A label with a colour and a font: BuiLabel <var for the handle> <x> <y> <w> <h> <text> <colour> <font var>
!macro BuiLabel _VAR _X _Y _W _H _TEXT _COLOR _FONT
	${NSD_CreateLabel} ${_X} ${_Y} ${_W} ${_H} "${_TEXT}"
	Pop ${_VAR}
	SetCtlColors ${_VAR} ${_COLOR} transparent
	SendMessage ${_VAR} ${WM_SETFONT} ${_FONT} 1
!macroend

!macro BuiStripLabel _N _X _CUR _TEXT
	!if ${_N} == ${_CUR}
		!insertmacro BuiLabel $R0 ${_X} 0u 64u 10u "${_N}  ${_TEXT}" ${BUI_ACCENT} $BuiFontBold
	!else
		!insertmacro BuiLabel $R0 ${_X} 0u 64u 10u "${_N}  ${_TEXT}" ${BUI_MUTED} $BuiFont
	!endif
!macroend

; The four-step strip along the top of a page, with a line under it. _CUR is 1 to 4 (a number, not a variable).
!macro BuiStrip _CUR _A _B _C _D
	!insertmacro BuiStripLabel 1 0u ${_CUR} "${_A}"
	!insertmacro BuiStripLabel 2 66u ${_CUR} "${_B}"
	!insertmacro BuiStripLabel 3 132u ${_CUR} "${_C}"
	!insertmacro BuiStripLabel 4 198u ${_CUR} "${_D}"
	nsDialogs::CreateControl STATIC ${DEFAULT_STYLES}|0x10 0 0 11u 100% 1u ""
	Pop $R0
!macroend

; The same strip with three steps (the uninstaller: What goes, Removing, Done)
!macro BuiStrip3 _CUR _A _B _C
	!insertmacro BuiStripLabel 1 0u ${_CUR} "${_A}"
	!insertmacro BuiStripLabel 2 66u ${_CUR} "${_B}"
	!insertmacro BuiStripLabel 3 132u ${_CUR} "${_C}"
	nsDialogs::CreateControl STATIC ${DEFAULT_STYLES}|0x10 0 0 11u 100% 1u ""
	Pop $R0
!macroend

; A list of rows is closed top and bottom by a hairline (an etched frame hid the rows after it under Wine, so no frame). _Y and _H
; may be variables.
!macro BuiFrame _Y _H
	nsDialogs::CreateControl STATIC ${DEFAULT_STYLES}|0x10 0 0 ${_Y}u 100% 1u ""
	Pop $R0
	IntOp $R1 ${_Y} + ${_H}
	nsDialogs::CreateControl STATIC ${DEFAULT_STYLES}|0x10 0 0 $R1u 100% 1u ""
	Pop $R0
!macroend

; One row of a list. _STATE: ok, todo, now, bad or none (no glyph). _TAGKIND: muted, warn (an "all users" tag) or good.
!macro BuiRowGlyph _STATE
	!if "${_STATE}" == "ok"
		!define _BUI_G "${BUI_G_OK}"
		!define _BUI_GC ${BUI_GOOD}
	!else if "${_STATE}" == "todo"
		!define _BUI_G "${BUI_G_TODO}"
		!define _BUI_GC ${BUI_MUTED}
	!else if "${_STATE}" == "now"
		!define _BUI_G "${BUI_G_NOW}"
		!define _BUI_GC ${BUI_INFO}
	!else if "${_STATE}" == "bad"
		!define _BUI_G "${BUI_G_BAD}"
		!define _BUI_GC ${BUI_BAD}
	!else
		!define _BUI_G ""
		!define _BUI_GC ${BUI_MUTED}
	!endif
!macroend

!macro BuiRow _Y _STATE _TEXT _TAG _TAGKIND
	!insertmacro BuiRowGlyph ${_STATE}
	!insertmacro BuiLabel $R0 3u ${_Y}u 9u 10u "${_BUI_G}" ${_BUI_GC} $BuiFontSym
	!insertmacro BuiLabel $R0 14u ${_Y}u 170u 10u "${_TEXT}" ${BUI_INK} $BuiFont
	!if "${_TAGKIND}" == "warn"
		!define _BUI_TC ${BUI_WARN}
	!else if "${_TAGKIND}" == "good"
		!define _BUI_TC ${BUI_GOOD}
	!else
		!define _BUI_TC ${BUI_MUTED}
	!endif
	; right-aligned, "&" not an accelerator
	nsDialogs::CreateControl STATIC ${DEFAULT_STYLES}|0x82 0 186u ${_Y}u 78u 10u "${_TAG}"
	Pop $R0
	SetCtlColors $R0 ${_BUI_TC} transparent
	SendMessage $R0 ${WM_SETFONT} $BuiFont 1
	!undef _BUI_TC
	!undef _BUI_G
	!undef _BUI_GC
!macroend

; A table: BuiTable <y> <head 1> <head 2> <head 3> <head 4>, then BuiTableRow <y> <c1> <c2> <c3> <c4> <colour of c4: muted|warn>
!macro BuiTable _Y _C1 _C2 _C3 _C4
	!insertmacro BuiLabel $R0 3u ${_Y}u 88u 9u "${_C1}" ${BUI_MUTED} $BuiFont
	!insertmacro BuiLabel $R0 94u ${_Y}u 50u 9u "${_C2}" ${BUI_MUTED} $BuiFont
	!insertmacro BuiLabel $R0 146u ${_Y}u 50u 9u "${_C3}" ${BUI_MUTED} $BuiFont
	!insertmacro BuiLabel $R0 198u ${_Y}u 66u 9u "${_C4}" ${BUI_MUTED} $BuiFont
!macroend

!macro BuiTableRow _Y _C1 _C2 _C3 _C4 _C4KIND
	!insertmacro BuiLabel $R0 3u ${_Y}u 88u 9u "${_C1}" ${BUI_INK} $BuiFont
	!insertmacro BuiLabel $R0 94u ${_Y}u 50u 9u "${_C2}" ${BUI_INK} $BuiFont
	!insertmacro BuiLabel $R0 146u ${_Y}u 50u 9u "${_C3}" ${BUI_INK} $BuiFont
	!if "${_C4KIND}" == "warn"
		!insertmacro BuiLabel $R0 198u ${_Y}u 66u 9u "${_C4}" ${BUI_WARN} $BuiFont
	!else
		!insertmacro BuiLabel $R0 198u ${_Y}u 66u 9u "${_C4}" ${BUI_MUTED} $BuiFont
	!endif
!macroend

; A callout: a coloured block with a bold title and a line or two under it. _KIND: warn, bad or calm. _Y may be a variable ($R9),
; _H is a number (dialog units, at least 24). Uses $R0-$R2; the texts must not be in $R0-$R2.
!macro BuiCallout _Y _H _KIND _TITLE _BODY
	!if "${_KIND}" == "bad"
		!define _BUI_BG ${BUI_BAD_BG}
		!define _BUI_FG ${BUI_BAD}
	!else if "${_KIND}" == "calm"
		!define _BUI_BG ${BUI_CALM_BG}
		!define _BUI_FG ${BUI_ACCENT}
	!else
		!define _BUI_BG ${BUI_WARN_BG}
		!define _BUI_FG ${BUI_WARN}
	!endif
	!define /math _BUI_H2 ${_H} - 14
	IntOp $R1 ${_Y} + 2
	IntOp $R2 ${_Y} + 12
	${NSD_CreateLabel} 0 ${_Y}u 100% ${_H}u ""
	Pop $R0
	SetCtlColors $R0 ${BUI_INK} ${_BUI_BG}
	${NSD_CreateLabel} 5u $R1u 254u 10u "${_TITLE}"
	Pop $R0
	SetCtlColors $R0 ${_BUI_FG} ${_BUI_BG}
	SendMessage $R0 ${WM_SETFONT} $BuiFontBold 1
	${NSD_CreateLabel} 5u $R2u 254u ${_BUI_H2}u "${_BODY}"
	Pop $R0
	SetCtlColors $R0 ${BUI_MUTED} ${_BUI_BG}
	SendMessage $R0 ${WM_SETFONT} $BuiFont 1
	!undef _BUI_BG
	!undef _BUI_FG
	!undef _BUI_H2
!macroend

; Small muted text
!macro BuiNote _Y _H _TEXT
	!insertmacro BuiLabel $R0 0 ${_Y}u 100% ${_H}u "${_TEXT}" ${BUI_MUTED} $BuiFont
!macroend

; Normal text in the body font
!macro BuiText _Y _H _TEXT
	!insertmacro BuiLabel $R0 0 ${_Y}u 100% ${_H}u "${_TEXT}" ${BUI_INK} $BuiFont
!macroend

; The body font on a control (a checkbox, a radio button, a text box): BuiFontOn <handle>
!macro BuiFontOn _HWND
	SendMessage ${_HWND} ${WM_SETFONT} $BuiFont 1
!macroend

; The shield on the footer's main button (BCM_SETSHIELD): the next click asks Windows for permission
!macro BuiShield _ON
	GetDlgItem $R0 $HWNDPARENT 1
	SendMessage $R0 0x160C 0 ${_ON}
!macroend

; --- the step list of the instfiles page ----------------------------------------------------------------------------------

; Insert once in the installer ("") and once in the uninstaller ("un."). Fonts: BuiInitFonts, from .onGUIInit (un.onGUIInit).
; Colours are compared as text, so they stay in the 0xRRGGBB form of the BUI_ defines.
!macro BuiFunctions _UN
	Function ${_UN}BuiInitFonts
		CreateFont $BuiFont "Segoe UI" 9 400
		CreateFont $BuiFontBold "Segoe UI" 9 700
		CreateFont $BuiFontSym "Segoe UI Symbol" 9 400
	FunctionEnd

	; One window of the step list: parent $0, x $1, y $2, w $3, h $4, control id $5, style $6, text $7, colour $8, font $9
	Function ${_UN}BuiMakeStatic
		System::Call 'user32::CreateWindowExW(i 0, w "STATIC", w r7, i r6, i r1, i r2, i r3, i r4, p r0, p r5, p 0, p 0) p .r1'
		; SetCtlColors takes its colours at compile time, so the few colours in use are picked here
		${If} $8 == ${BUI_GOOD}
			SetCtlColors $1 ${BUI_GOOD} transparent
		${ElseIf} $8 == ${BUI_INFO}
			SetCtlColors $1 ${BUI_INFO} transparent
		${ElseIf} $8 == ${BUI_BAD}
			SetCtlColors $1 ${BUI_BAD} transparent
		${ElseIf} $8 == ${BUI_INK}
			SetCtlColors $1 ${BUI_INK} transparent
		${ElseIf} $8 == ${BUI_ACCENT}
			SetCtlColors $1 ${BUI_ACCENT} transparent
		${Else}
			SetCtlColors $1 ${BUI_MUTED} transparent
		${EndIf}
		SendMessage $1 ${WM_SETFONT} $9 1
	FunctionEnd

	; Makes row BuiA6 of the step list: parent window BuiA1, left BuiA2, top BuiA3, width BuiA4, height BuiA5 (pixels), label BuiA7.
	; A glyph for each of the four states (pending, now, done, failed; only one shows), the label, and a right-aligned tag.
	Function ${_UN}BuiStepAdd
		Push $0
		Push $1
		Push $2
		Push $3
		Push $4
		Push $5
		Push $6
		Push $7
		Push $8
		Push $9
		StrCpy $0 $BuiA1
		IntOp $5 $BuiA6 * 10
		IntOp $5 $5 + 5000
		; the four glyphs, 0 pending (shown), 1 now, 2 done, 3 failed
		StrCpy $2 $BuiA3
		StrCpy $4 $BuiA5
		StrCpy $3 $BuiA5
		StrCpy $1 $BuiA2
		StrCpy $6 0x54000080
		StrCpy $7 "${BUI_G_TODO}"
		StrCpy $8 ${BUI_MUTED}
		StrCpy $9 $BuiFontSym
		Call ${_UN}BuiMakeStatic
		; the glyph window is hidden unless it is the pending one (WS_CHILD only for the rest)
		StrCpy $1 $BuiA2
		IntOp $5 $5 + 1
		StrCpy $6 0x44000080
		StrCpy $7 "${BUI_G_NOW}"
		StrCpy $8 ${BUI_INFO}
		Call ${_UN}BuiMakeStatic
		StrCpy $1 $BuiA2
		IntOp $5 $5 + 1
		StrCpy $7 "${BUI_G_OK}"
		StrCpy $8 ${BUI_GOOD}
		Call ${_UN}BuiMakeStatic
		StrCpy $1 $BuiA2
		IntOp $5 $5 + 1
		StrCpy $7 "${BUI_G_BAD}"
		StrCpy $8 ${BUI_BAD}
		Call ${_UN}BuiMakeStatic
		; the label (id +4) and the tag (id +5), both after the glyph column
		IntOp $1 $BuiA2 + $BuiA5
		IntOp $1 $1 + 4
		IntOp $3 $BuiA4 - $BuiA5
		IntOp $3 $3 - 4
		IntOp $3 $3 * 6
		IntOp $3 $3 / 10
		IntOp $5 $5 + 1
		StrCpy $6 0x54000080
		StrCpy $7 $BuiA7
		StrCpy $8 ${BUI_INK}
		StrCpy $9 $BuiFont
		Call ${_UN}BuiMakeStatic
		; the tag: right-aligned (SS_RIGHT 2) in the remaining 40 per cent
		IntOp $1 $BuiA2 + $BuiA4
		IntOp $4 $BuiA4 * 4
		IntOp $4 $4 / 10
		IntOp $1 $1 - $4
		StrCpy $3 $4
		StrCpy $4 $BuiA5
		IntOp $5 $5 + 1
		StrCpy $6 0x54000082
		StrCpy $7 ""
		StrCpy $8 ${BUI_MUTED}
		Call ${_UN}BuiMakeStatic
		Pop $9
		Pop $8
		Pop $7
		Pop $6
		Pop $5
		Pop $4
		Pop $3
		Pop $2
		Pop $1
		Pop $0
	FunctionEnd

	; Sets row BuiA2 of the step list in window BuiA1 to state BuiA3 (0 pending, 1 now, 2 done, 3 failed) with the tag text BuiA4.
	Function ${_UN}BuiStepSet
		Push $0
		Push $1
		Push $2
		Push $3
		Push $4
		Push $5
		Push $6
		IntOp $1 $BuiA2 * 10
		IntOp $1 $1 + 5000
		StrCpy $2 0
		${Do}
			IntOp $0 $1 + $2
			GetDlgItem $3 $BuiA1 $0
			${If} $2 = $BuiA3
				ShowWindow $3 ${SW_SHOW}
			${Else}
				ShowWindow $3 ${SW_HIDE}
			${EndIf}
			IntOp $2 $2 + 1
		${LoopUntil} $2 > 3
		; the label is bold while it is the one running
		IntOp $0 $1 + 4
		GetDlgItem $3 $BuiA1 $0
		${If} $BuiA3 = 1
			SendMessage $3 ${WM_SETFONT} $BuiFontBold 1
		${Else}
			SendMessage $3 ${WM_SETFONT} $BuiFont 1
		${EndIf}
		IntOp $0 $1 + 5
		GetDlgItem $3 $BuiA1 $0
		SendMessage $3 ${WM_SETTEXT} 0 "STR:$BuiA4"
		; A transparent label leaves its old text behind unless the page under it is painted again: repaint the whole row
		; (the glyph's left, top and bottom, the tag's right)
		GetDlgItem $3 $BuiA1 $1
		System::Call '*(i,i,i,i) p .r2'
		System::Call 'user32::GetWindowRect(p r3, p r2)'
		System::Call 'user32::MapWindowPoints(p 0, p $BuiA1, p r2, i 2)'
		System::Call '*$2(i .r4, i .r5, i, i .r6)'
		GetDlgItem $3 $BuiA1 $0
		System::Call 'user32::GetWindowRect(p r3, p r2)'
		System::Call 'user32::MapWindowPoints(p 0, p $BuiA1, p r2, i 2)'
		System::Call '*$2(i, i, i .r0, i)'
		System::Call '*$2(i r4, i r5, i r0, i r6)'
		System::Call 'user32::InvalidateRect(p $BuiA1, p r2, i 1)'
		System::Free $2
		Pop $6
		Pop $5
		Pop $4
		Pop $3
		Pop $2
		Pop $1
		Pop $0
	FunctionEnd
!macroend

!endif
