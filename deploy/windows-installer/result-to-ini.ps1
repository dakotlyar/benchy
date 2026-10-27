<#
	Used only by the GUI installer (benchy.nsi): turns install.ps1's -ResultFile JSON into an INI file, which NSIS can
	read with ReadINIStr (NSIS has no JSON reader). Presentation only; no install logic belongs here.

	result-to-ini.ps1 <result.json> <result.ini>

	Writes nothing when the JSON file is missing or unreadable; the installer then reports the exit code alone.
#>

param(
	[string]$JsonPath,
	[string]$IniPath
)

$ErrorActionPreference = 'Stop'

function Format-IniValue($Value)
{
	# One line per value; ReadINIStr would stop at a line break
	return (([string]$Value) -replace '\s+', ' ').Trim()
}

function Format-IniFlag($Value)
{
	if ($Value) { return '1' }
	return '0'
}

try
{
	$Result = [IO.File]::ReadAllText($JsonPath) | ConvertFrom-Json
	$Warnings = @($Result.warnings | Where-Object { $_ })
	$Lines = @(
		'[result]'
		"result=$(Format-IniValue $Result.result)"
		"code=$(Format-IniValue $Result.code)"
		"message=$(Format-IniValue $Result.message)"
		"benchy_version=$(Format-IniValue $Result.benchy_version)"
		"previous_version=$(Format-IniValue $Result.previous_version)"
		"php_version=$(Format-IniValue $Result.php_version)"
		"reboot_recommended=$(Format-IniFlag $Result.reboot_recommended)"
		"reboot_required=$(Format-IniFlag $Result.reboot_required)"
		"warning_count=$($Warnings.Count)"
	)
	for ($i = 0; $i -lt $Warnings.Count; $i++) { $Lines += "warning$($i + 1)=$(Format-IniValue $Warnings[$i])" }
	# UTF-16 LE with a BOM, which GetPrivateProfileStringW reads as Unicode
	[IO.File]::WriteAllLines($IniPath, [string[]]$Lines, [Text.Encoding]::Unicode)
}
catch
{
	exit 1
}
exit 0
