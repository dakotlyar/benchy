<#
	Used only by the GUI installer (benchy.nsi): turns install.ps1's -ResultFile JSON into an INI file, which NSIS can
	read with ReadINIStr (NSIS has no JSON reader). Presentation only; no install logic belongs here.

	result-to-ini.ps1 <result.json> <result.ini>

	Writes nothing when the JSON file is missing or unreadable; the installer then reports the exit code alone.
	Also reads the JSON of `install.ps1 -CheckRunning` (it has a `running` list): that gets a [running] section with
	client, client_pid, client_tray, agent, run, console, php (1 or 0) and text.
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
	if ($Result.PSObject.Properties['running'])
	{
		$Lines = @(
			'[running]'
			"count=$(@($Result.running).Count)"
			"client=$(Format-IniFlag $Result.client)"
			"client_pid=$(Format-IniValue $Result.client_pid)"
			"client_tray=$(Format-IniFlag $Result.client_tray)"
			"agent=$(Format-IniFlag $Result.agent)"
			"run=$(Format-IniFlag $Result.run)"
			"console=$(Format-IniFlag $Result.console)"
			"php=$(Format-IniFlag $Result.php)"
			"text=$(Format-IniValue $Result.text)"
		)
		[IO.File]::WriteAllLines($IniPath, [string[]]$Lines, [Text.Encoding]::Unicode)
		exit 0
	}
	$Lines = @(
		'[result]'
		"result=$(Format-IniValue $Result.result)"
		"code=$(Format-IniValue $Result.code)"
		"message=$(Format-IniValue $Result.message)"
		"benchy_version=$(Format-IniValue $Result.benchy_version)"
		"previous_version=$(Format-IniValue $Result.previous_version)"
		"php_version=$(Format-IniValue $Result.php_version)"
		"php_path=$(Format-IniValue $Result.php_path)"
		"vcredist=$(Format-IniValue $Result.vcredist)"
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
