#!/bin/bash
# The Windows installer, as far as Linux can check it (0.9.6, B3): the runtime pins, release.sh's verification of the pinned release assets,
# and the structure of benchy.nsi, install.ps1, uninstall.ps1 and the shared PowerShell lib. Nothing here runs PowerShell or the installer;
# the real Windows behavior (a swap, a process check, the Run key, an unelevated launch) is Zeb's K-T1 on Kamino.
# Driven by run.sh (run.sh installer, and part of all): no docker, no network. The NSIS compile checks use the pinned makensis image when
# it is already built (podman), and are skipped, loudly, when it is not.
set -u
HERE=$(cd "$(dirname "$0")" && pwd)
REPO=$(cd "$HERE/../.." && pwd)
# What was committed, like the other phases: run.sh puts it in QC_TREE
TREE=${QC_TREE:-$REPO}
PASS=0; FAIL=0; SKIP=0; FAILED=()
PINS=$TREE/pts-core/static/update/runtime-pins.json
NSI=$TREE/deploy/windows-installer/benchy.nsi
INSTALL=$TREE/install.ps1
UNINSTALL=$TREE/uninstall.ps1
LIB=$TREE/pts-core/static/update/benchy-windows.ps1
RELEASE=$TREE/deploy/release.sh
SCRATCH=$(mktemp -d /tmp/benchy-qc-installer.XXXXXX)
trap 'rm -rf "$SCRATCH"' EXIT

check() {  # check "description" command...
	local d=$1 out; shift
	if out=$("$@" 2>&1); then PASS=$((PASS+1)); echo "  PASS  $d"; else FAIL=$((FAIL+1)); FAILED+=("$d"); echo "  FAIL  $d"; [ -n "$out" ] && printf '%s\n' "$out" | head -c 3000 | sed 's/^/        | /'; fi
}
has() { grep -qE -- "$1" "$2"; }               # has <extended regex> <file>
lacks() { ! grep -qE -- "$1" "$2"; }
hasf() { grep -qF -- "$1" "$2"; }              # hasf <fixed string> <file>

echo "== runtime-pins.json: the engine pin"
engine_pin_ok() {
	python3 -I - "$PINS" <<'E'
import json, re, sys
p = json.load(open(sys.argv[1]))["windows-x64"]
e = p["engine"]
a = e["artifact"]
assert re.fullmatch(r"[0-9]+\.[0-9]+\.[0-9]+", e["version"]), e["version"]
assert a["file"] == "benchy-engine-%s-windows-x64.zip" % e["version"], a["file"]
assert isinstance(a["size"], int) and a["size"] > 0
assert re.fullmatch(r"[0-9a-f]{64}", a["sha256"]), a["sha256"]
assert e["upstream_urls"] == ["https://github.com/dakotlyar/benchy-engine/releases/download/v%s/%s" % (e["version"], a["file"])], e["upstream_urls"]
assert "client" in p and "php" in p, "the other pins are still there"
E
}
check "windows-x64.engine: version, file name, size, sha256 and the release URL agree" engine_pin_ok
# The same as the client's pin has: a version that is a release tag, a name release.sh accepts
client_pin_ok() {
	python3 -I - "$PINS" <<'E'
import json, re, sys
c = json.load(open(sys.argv[1]))["windows-x64"]["client"]
a = c["artifact"]
assert a["file"] == "BenchyClient-%s-windows-x64.zip" % c["version"], a["file"]
assert re.fullmatch(r"[0-9a-f]{64}", a["sha256"]) and a["size"] > 0
E
}
check "windows-x64.client: still a well-formed pin" client_pin_ok

echo "== release.sh: the pinned engine (and client) release assets"
# release.sh runs its dispatch at the bottom, so its functions are loaded from a copy cut off before that
sed '/^\[ \$# -ge 1 \] || usage$/,$d' "$RELEASE" > "$SCRATCH/release-lib.sh"
# lib <bash snippet>: runs it with the functions loaded, a throw-away HOME (the cache), and a PATH with only the fake gh in front
mkdir -p "$SCRATCH/bin" "$SCRATCH/home"
cat > "$SCRATCH/bin/gh" <<'E'
#!/bin/bash
# A fake gh: "release view ... --json isDraft -q .isDraft" answers FAKE_GH_DRAFT; "release download v<ver> -R repo -p file -D dir" copies FAKE_GH_ASSET there
echo "gh $*" >> "${FAKE_GH_LOG:-/dev/null}"
case "$1 $2" in
	"release view") echo "${FAKE_GH_DRAFT:-false}" ;;
	"release download")
		while [ $# -gt 0 ]; do case $1 in -p) f=$2; shift 2;; -D) d=$2; shift 2;; *) shift;; esac; done
		cp "$FAKE_GH_ASSET" "$d/$f" ;;
	*) exit 1 ;;
esac
E
chmod +x "$SCRATCH/bin/gh"
lib() { HOME=$SCRATCH/home PATH=$SCRATCH/bin:$PATH bash -c "set -euo pipefail; source '$SCRATCH/release-lib.sh'; $1" 2>&1; }
# libo: the same, standard output only (what a function prints for its caller; the progress lines go to standard error)
libo() { HOME=$SCRATCH/home PATH=$SCRATCH/bin:$PATH bash -c "set -euo pipefail; source '$SCRATCH/release-lib.sh'; $1" 2>/dev/null; }
printf 'engine bytes' > "$SCRATCH/engine.zip"
printf 'other bytes!' > "$SCRATCH/wrong.zip"
E_SIZE=$(stat -c %s "$SCRATCH/engine.zip"); E_SHA=$(sha256sum "$SCRATCH/engine.zip" | cut -d' ' -f1)
SETPIN="ENGINE_VERSION=9.9.9 ENGINE_FILE=benchy-engine-9.9.9-windows-x64.zip ENGINE_SIZE=$E_SIZE ENGINE_SHA=$E_SHA"

reads_engine_pin() {
	local out
	out=$(lib "read_engine_pin '$PINS'; echo \"\$ENGINE_VERSION \$ENGINE_FILE \$ENGINE_SIZE \$ENGINE_SHA\"") || { echo "$out"; return 1; }
	[ "$out" = "$(python3 -I -c "
import json; e = json.load(open('$PINS'))['windows-x64']['engine']; a = e['artifact']
print(e['version'], a['file'], a['size'], a['sha256'])")" ] || { echo "$out"; return 1; }
}
check "read_engine_pin reads the committed pin" reads_engine_pin
reads_client_pin_still() { lib "read_client_pin '$PINS'; [ -n \"\$CLIENT_VERSION\" ] && [ \"\$CLIENT_FILE\" = \"BenchyClient-\$CLIENT_VERSION-windows-x64.zip\" ]"; }
check "read_client_pin still reads the client pin (the shared reader)" reads_client_pin_still
no_engine_pin_is_empty() {
	python3 -I -c "
import json; p = json.load(open('$PINS')); del p['windows-x64']['engine']; json.dump(p, open('$SCRATCH/nopin.json', 'w'))"
	[ "$(lib "read_engine_pin '$SCRATCH/nopin.json'; echo \"[\$ENGINE_VERSION\$ENGINE_FILE]\"")" = "[]" ]
}
check "a tree that pins no engine reads as empty (an older tree builds without one)" no_engine_pin_is_empty
bad_pin_refused() {  # bad_pin_refused <python edit of the engine block> <message>
	python3 -I -c "
import json; p = json.load(open('$PINS')); e = p['windows-x64']['engine']; $1; json.dump(p, open('$SCRATCH/badpin.json', 'w'))"
	local out; out=$(lib "read_engine_pin '$SCRATCH/badpin.json'") && { echo "accepted: $out"; return 1; }
	grep -q "$2" <<<"$out" || { echo "$out"; return 1; }
}
check "an engine pin with a short sha256 is refused" bad_pin_refused "e['artifact']['sha256'] = 'abc'" 'sha256 in the pin must be 64'
check "an engine pin with a zip name that is not benchy-engine-<ver>-windows-x64.zip is refused" bad_pin_refused "e['artifact']['file'] = '../evil.zip'" 'unexpected engine zip name'
check "an engine pin with a non-numeric size is refused" bad_pin_refused "e['artifact']['size'] = '1; rm -rf /'" 'unexpected engine zip size'

given_matching_ok() { [ "$(libo "$SETPIN; fetch_engine '$SCRATCH/engine.zip' dev")" = "$SCRATCH/engine.zip" ]; }
check "--engine-zip with a file that matches the pin is used as it is" given_matching_ok
given_wrong_refused() {
	local out; out=$(lib "$SETPIN; fetch_engine '$SCRATCH/wrong.zip' dev") && { echo "accepted"; return 1; }
	grep -q -- '--engine-zip .*does not match the pinned engine 9.9.9' <<<"$out" || { echo "$out"; return 1; }
}
check "--engine-zip with a file that does not match the pin fails the build" given_wrong_refused
given_missing_refused() { local out; out=$(lib "$SETPIN; fetch_engine '$SCRATCH/none.zip' dev") && return 1; grep -q 'no such file' <<<"$out"; }
check "--engine-zip with no such file fails" given_missing_refused
cached_ok() {
	mkdir -p "$SCRATCH/home/.cache/benchy-release"; cp "$SCRATCH/engine.zip" "$SCRATCH/home/.cache/benchy-release/benchy-engine-9.9.9-windows-x64.zip"
	[ "$(libo "$SETPIN; fetch_engine '' dev")" = "$SCRATCH/home/.cache/benchy-release/benchy-engine-9.9.9-windows-x64.zip" ]
}
check "a cached engine zip that matches the pin is reused (no download)" cached_ok
draft_refused() {
	local out; out=$(FAKE_GH_DRAFT=true lib "$SETPIN; fetch_engine '' release") && { echo "accepted a draft"; return 1; }
	grep -q "engine's v9.9.9 release is not published" <<<"$out" || { echo "$out"; return 1; }
}
check "a release build refuses an engine release that is still a draft, even when the zip is cached" draft_refused
published_ok() { [ "$(FAKE_GH_DRAFT=false libo "$SETPIN; fetch_engine '' release")" = "$SCRATCH/home/.cache/benchy-release/benchy-engine-9.9.9-windows-x64.zip" ]; }
check "a release build takes a published engine release" published_ok
download_checked() {
	rm -f "$SCRATCH/home/.cache/benchy-release/benchy-engine-9.9.9-windows-x64.zip"
	local out
	out=$(FAKE_GH_ASSET="$SCRATCH/wrong.zip" lib "$SETPIN; fetch_engine '' dev") && { echo "accepted a wrong download"; return 1; }
	grep -q 'release asset does not match the pin' <<<"$out" || { echo "$out"; return 1; }
	rm -f "$SCRATCH/home/.cache/benchy-release/benchy-engine-9.9.9-windows-x64.zip"
	[ "$(FAKE_GH_ASSET="$SCRATCH/engine.zip" FAKE_GH_LOG="$SCRATCH/gh.log" libo "$SETPIN; fetch_engine '' dev")" = "$SCRATCH/home/.cache/benchy-release/benchy-engine-9.9.9-windows-x64.zip" ] \
		&& grep -q 'release download v9.9.9 -R dakotlyar/benchy-engine -p benchy-engine-9.9.9-windows-x64.zip' "$SCRATCH/gh.log"
}
check "a downloaded engine asset is verified against the pin, from dakotlyar/benchy-engine at the pinned version" download_checked
tagged_refuses_engine_zip() {
	local out; out=$("$RELEASE" installer 0.9.6 --engine-zip "$SCRATCH/engine.zip" 2>&1) && return 1
	grep -q -- '--engine-zip is for dev builds only' <<<"$out"
}
check "release.sh installer <version> (a tagged release) refuses --engine-zip" tagged_refuses_engine_zip
has_engine_wiring() {
	hasf 'engine_zip_arg' "$RELEASE" && hasf 'read_engine_pin' "$RELEASE" && hasf -DENGINEZIP= "$RELEASE" && hasf 'engine_zip: ' "$RELEASE" \
		&& hasf 'tree pins no engine' "$RELEASE"
}
check "release.sh builds the engine in (the define, the buildinfo line, a tree with no pin builds without)" has_engine_wiring

todo_gate() {
	python3 -I -c "
import json; p = json.load(open('$PINS')); p['windows-x64']['client']['_todo'] = 'x'; json.dump(p, open('$SCRATCH/todopin.json', 'w'))
del p['windows-x64']['client']['_todo']; json.dump(p, open('$SCRATCH/finalpin.json', 'w'))"
	[ "$(libo "pins_with_todo '$SCRATCH/todopin.json'")" = client ] && [ -z "$(libo "pins_with_todo '$SCRATCH/finalpin.json'")" ] \
		&& hasf 'still has a _todo on' "$RELEASE"
}
check "a pin block with a _todo (the client waiting for its release) is found, and a tagged build refuses it" todo_gate

echo "== benchy.nsi"
check "the engine zip is an optional payload (ENGINEZIP) handed to install.ps1 as -EngineZip" bash -c "grep -q '!ifdef ENGINEZIP' '$NSI' && grep -q -- '-EngineZip \"\$PLUGINSDIR\\\\engine.zip\"' '$NSI'"
check "the unpack-failed test covers the engine zip when one is built in" bash -c "grep -A1 '!ifdef ENGINEZIP' '$NSI' | grep -q 'OrIfNot.*engine.zip'"
check "'Open Benchy now' no longer uses ExecShell on the shortcut (that child inherits an elevated installer's token)" lacks 'ExecShell +"open" +"\$SMPROGRAMS' "$NSI"
check "...it starts the shortcut through explorer.exe, which runs it with the user's normal token" has 'Exec .*explorer\.exe.*Benchy\.lnk' "$NSI"
check "the installer itself still asks for no elevation (RequestExecutionLevel user)" has '^RequestExecutionLevel user' "$NSI"

nsis_image=localhost/benchy-nsis:3.11-1-$(sha256sum "$TREE/deploy/windows-installer/Containerfile" | cut -c1-12)
if command -v podman >/dev/null && podman image exists "$nsis_image" 2>/dev/null; then
	mkdir -p "$SCRATCH/payload" "$SCRATCH/out"
	mkdir -p "$SCRATCH/payload/pts-core/static/images"; cp "$TREE/pts-core/static/images/benchy.ico" "$SCRATCH/payload/pts-core/static/images/"
	echo '# not run' > "$SCRATCH/payload/install.ps1"
	printf 'php' > "$SCRATCH/php.zip"; printf 'client' > "$SCRATCH/client.zip"
	nsis() {  # nsis <extra -D args...>: compile with -WX (any warning fails), no network
		podman run --rm --network=none -v "$TREE:/work/tree:ro" -v "$SCRATCH:/s" "$nsis_image" -V2 -WX \
			-DVERSION=9.9.9 -DPAYLOAD=/s/payload -DPHPZIP=/s/php.zip -DVCREDIST_SHA256=$(printf '0%.0s' $(seq 64)) -DVCREDIST_SIZE=1 -DOUTFILE=/s/out/t.exe "$@" /work/tree/deploy/windows-installer/benchy.nsi
	}
	check "benchy.nsi compiles with -WX: no client, no engine" bash -c "$(declare -f nsis); nsis_image='$nsis_image' TREE='$TREE' SCRATCH='$SCRATCH'; nsis"
	check "benchy.nsi compiles with -WX: the client and the engine built in" bash -c "$(declare -f nsis); nsis_image='$nsis_image' TREE='$TREE' SCRATCH='$SCRATCH'; nsis -DCLIENTZIP=/s/client.zip -DENGINEZIP=/s/engine.zip"
	check "benchy.nsi compiles with -WX: the engine alone (no client)" bash -c "$(declare -f nsis); nsis_image='$nsis_image' TREE='$TREE' SCRATCH='$SCRATCH'; nsis -DENGINEZIP=/s/engine.zip"
	check "the compiled installer exists" test -s "$SCRATCH/out/t.exe"
else
	SKIP=$((SKIP+1)); echo "  SKIP  the NSIS compile checks: $nsis_image is not built here (deploy/release.sh builds it on first use)"
fi

echo "== install.ps1, uninstall.ps1 and the shared lib"
check "install.ps1 takes -EngineZip, checks it against the engine pin (exit 11), and installs it with Install-BenchyEngine" \
	bash -c "grep -q '\[string\]\$EngineZip' '$INSTALL' && grep -q 'Get-BenchyEnginePin' '$INSTALL' && grep -q 'Stop-Benchy 11 \"The engine zip' '$INSTALL' && grep -q 'Install-BenchyEngine \$InstallDir \$EngineZip' '$INSTALL'"
check "the engine goes to engine\\native\\benchy-engine.exe, where the client looks (..\\engine\\native\\benchy-engine.exe)" \
	bash -c "grep -q \"'engine\\\\\\\\native\\\\\\\\benchy-engine.exe'\" '$LIB' && grep -q \"Install-BenchySwappedDir \\\$InstallDir \\\$Zip 'engine' 'native\\\\\\\\benchy-engine.exe' 'engine' 'native'\" '$LIB'"
keepdirs_cover_every_swapped_dir() {
	local name missing=0
	for name in $(grep -oE "Install-BenchySwappedDir \\\$InstallDir \\\$Zip '[a-z]+'" "$LIB" | grep -oE "'[a-z]+'" | tr -d "'"); do
		for suffix in "" .new .prev; do
			grep -E "^\s*\\\$KeepDirs = @\(" "$INSTALL" | grep -q "'$name$suffix'" || { echo "\$KeepDirs lacks '$name$suffix'"; missing=1; }
		done
	done
	[ "$missing" = 0 ]
}
check "\$KeepDirs holds every swapped folder (client and engine) with its .new and .prev, or /MIR would delete them" keepdirs_cover_every_swapped_dir
check "...and the engine's data (%LOCALAPPDATA%\\benchy-engine) is outside the install dir, so there is nothing more to keep" \
	bash -c "grep -q 'LOCALAPPDATA%.benchy-engine' '$INSTALL'"
check "the process check looks for benchy-engine.exe running from the install dir's engine folder (engine, engine.new, engine.prev)" \
	bash -c "grep -qF \"Name = 'benchy-engine.exe'\" '$LIB' && grep -qF '\$EnginePrefix = \"\$Root\\engine\"' '$LIB' && grep -qF '\$_.ExecutablePath.StartsWith(\$EnginePrefix' '$LIB'"
check "the exit-3 refusal of install.ps1 and uninstall.ps1 says to quit Benchy from its tray icon first" \
	bash -c "grep -q 'Quit Benchy from its tray icon first' '$LIB' && grep -q 'Get-BenchyRunningAdvice \$Running' '$INSTALL' && grep -q 'Get-BenchyRunningAdvice \$Running' '$UNINSTALL'"
check "...and only for the client or the engine (a php.exe or benchy.bat alone still says close every window)" \
	bash -c "grep -qF -e \"-in @('BenchyClient.exe', 'benchy-engine.exe')\" '$LIB' && grep -qF 'Close every Benchy window and run' '$LIB'"
install_checks_before_copying() {
	python3 -I - "$INSTALL" <<'PY'
import sys
t = open(sys.argv[1]).read()
p = t.index("Get-BenchyProcesses $InstallDir")
s = t.index("Stop-Benchy 3 ")
r = t.index("& robocopy $SourceDir")
assert p < s < r, (p, s, r)
PY
}
check "install.ps1 still exits 3 for a running Benchy, before anything is copied" install_checks_before_copying
check "uninstall.ps1 deletes the Run value Benchy (Remove-BenchyAutostart), before the entry is removed" \
	bash -c "grep -q 'Remove-BenchyAutostart' '$UNINSTALL' && awk '/Remove-BenchyAutostart/{a=NR} /Remove-BenchyUninstallEntry/{e=NR} END{exit !(a && e && a < e)}' '$UNINSTALL'"
check "Remove-BenchyAutostart deletes HKCU ...\\CurrentVersion\\Run\\Benchy and the StartupApproved switch for it, and nothing else" \
	bash -c "grep -q \"'Software.Microsoft.Windows.CurrentVersion.Run'\" '$LIB' && grep -q 'StartupApproved' '$LIB' && grep -q \"DeleteValue('Benchy', \\\$false)\" '$LIB' && ! grep -E 'DeleteSubKey' '$LIB' | grep -qi 'Run'"
check "uninstall.ps1 leaves the engine's data alone and says so" bash -c "grep -q 'LOCALAPPDATA .benchy-engine' '$UNINSTALL' && ! grep -E 'Remove-Item|Remove-BenchyTree' '$UNINSTALL' | grep -q 'benchy-engine'"
check "the install result file reports engine_version" has 'engine_version = \$null' "$INSTALL"

echo; echo "installer: $PASS passed, $FAIL failed, $SKIP skipped"
[ "$FAIL" -eq 0 ] || { printf '  failed: %s\n' "${FAILED[@]}"; exit 1; }
