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
python3 -I - "$SCRATCH" <<'E'
import sys, zipfile
d = sys.argv[1]
with zipfile.ZipFile(d + "/engine.zip", "w") as z: z.writestr("benchy-engine.exe", "engine bytes"); z.writestr("LICENSE", "x")
with zipfile.ZipFile(d + "/subdir.zip", "w") as z: z.writestr("benchy-engine-0.3.1/benchy-engine.exe", "engine bytes")
E
printf 'other bytes!' > "$SCRATCH/wrong.zip"
E_SIZE=$(stat -c %s "$SCRATCH/engine.zip"); E_SHA=$(sha256sum "$SCRATCH/engine.zip" | cut -d' ' -f1)
SETPIN="ENGINE_VERSION=9.9.9 ENGINE_FILE=benchy-engine-9.9.9-windows-x64.zip ENGINE_SIZE=$E_SIZE ENGINE_SHA=$E_SHA"
SUB_SIZE=$(stat -c %s "$SCRATCH/subdir.zip"); SUB_SHA=$(sha256sum "$SCRATCH/subdir.zip" | cut -d' ' -f1)
SETPIN_SUB="ENGINE_VERSION=9.9.9 ENGINE_FILE=benchy-engine-9.9.9-windows-x64.zip ENGINE_SIZE=$SUB_SIZE ENGINE_SHA=$SUB_SHA"

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
layout_checked() {
	local out; out=$(lib "$SETPIN_SUB; fetch_engine '$SCRATCH/subdir.zip' dev") && { echo "accepted a zip with the exe in a subfolder: $out"; return 1; }
	grep -q 'not laid out' <<<"$out" || { echo "$out"; return 1; }
	printf 'engine bytes' > "$SCRATCH/notzip.zip"
	local sz sh; sz=$(stat -c %s "$SCRATCH/notzip.zip"); sh=$(sha256sum "$SCRATCH/notzip.zip" | cut -d' ' -f1)
	out=$(lib "ENGINE_VERSION=9.9.9 ENGINE_FILE=benchy-engine-9.9.9-windows-x64.zip ENGINE_SIZE=$sz ENGINE_SHA=$sh; fetch_engine '$SCRATCH/notzip.zip' dev") && { echo "accepted a file that is not a zip"; return 1; }
	grep -q 'not laid out' <<<"$out"
}
check "an engine zip without benchy-engine.exe at its root (or not a zip) fails the build, whichever way it was found" layout_checked
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
	python3 -I -c "
import json; p = json.load(open('$SCRATCH/finalpin.json')); p['windows-x64']['php']['_todo'] = 'x'; p['other-platform'] = {'engine': {'_todo': 'y'}}; json.dump(p, open('$SCRATCH/todopin2.json', 'w'))"
	[ "$(libo "pins_with_todo '$SCRATCH/todopin.json'")" = windows-x64.client ] && [ -z "$(libo "pins_with_todo '$SCRATCH/finalpin.json'")" ] \
		&& [ "$(libo "pins_with_todo '$SCRATCH/todopin2.json'")" = "windows-x64.php other-platform.engine" ] \
		&& hasf 'still has a _todo on' "$RELEASE"
}
check "a pin block with a _todo (the client waiting for its release) is found, and a tagged build refuses it" todo_gate

todo_gate_first() {
	# the gate runs before the NSIS image is built or looked for
	awk '/pins_with_todo "\$work/{g=NR} /ensure_nsis_image ;;/{e=NR} END{exit !(g && e && g < e)}' "$RELEASE"
}
check "release.sh refuses a pin with a _todo before it builds or looks for the NSIS image" todo_gate_first

echo "== release.sh: the Linux client and engine that go on the public release"
# lin <pins file> prints "<n> <file>..." for the Linux files read_linux_pins found
lin() { lib "read_linux_pins '$1'; echo \"\${#LINUX_FILE[@]} \${LINUX_FILE[*]}\""; }
reads_linux_pins() {
	local want
	want=$(python3 -I -c "
import json; w = json.load(open('$PINS'))['windows-x64']; c = w['client']['version']; e = w['engine']['version']
print('3 BenchyClient-%s-x86_64.AppImage benchy-engine-%s-linux-x64.tar.gz benchy-engine-%s-linux-arm64.tar.gz' % (c, e, e))")
	[ "$(lin "$PINS")" = "$want" ] || { lin "$PINS"; return 1; }
}
check "the committed pins name the Linux client AppImage and both engine tarballs, at the Windows client and engine versions" reads_linux_pins
linux_edit() {  # linux_edit <python edit of the pins dict p>: writes $SCRATCH/linpin.json
	python3 -I -c "
import json; p = json.load(open('$PINS')); $1; json.dump(p, open('$SCRATCH/linpin.json', 'w'))"
}
no_linux_pins_empty() {
	linux_edit "del p['linux-x64']['client']; del p['linux-x64']['engine']; del p['linux-arm64']['engine']"
	[ "$(lin "$SCRATCH/linpin.json")" = "0 " ]
}
check "a tree that pins no Linux client or engine reads as empty (an older tag builds as before)" no_linux_pins_empty
linux_refused() {  # linux_refused <python edit> <message>
	linux_edit "$1"
	local out; out=$(lin "$SCRATCH/linpin.json") && { echo "accepted: $out"; return 1; }
	grep -q -- "$2" <<<"$out" || { echo "$out"; return 1; }
}
check "a Linux engine pin at another version than the Windows engine is refused" linux_refused "p['linux-arm64']['engine']['version'] = '0.0.1'" 'must not pair'
check "a Linux client pin at another version than the Windows client is refused" linux_refused "p['linux-x64']['client']['version'] = '0.0.1'" 'must not pair'
check "some Linux pins without the others are refused" linux_refused "del p['linux-arm64']['engine']" 'is not pinned, but the other'
check "a Linux pin with a file name other than the asset's is refused" linux_refused "p['linux-x64']['engine']['artifact']['file'] = 'engine.tar.gz'" 'unexpected linux-x64.engine file name'
check "a Linux pin with a short sha256 is refused" linux_refused "p['linux-x64']['client']['artifact']['sha256'] = 'abc'" 'must be 64'
linux_layout_checked() {
	printf 'not an elf' > "$SCRATCH/fake.AppImage"; printf '\177ELFxxxx' > "$SCRATCH/real.AppImage"
	tar -czf "$SCRATCH/noengine.tar.gz" -C "$SCRATCH" wrong.zip
	tar -czf "$SCRATCH/goodengine.tar.gz" -C "$SCRATCH" --transform 's/^engine.zip$/benchy-engine/' engine.zip
	! lib "check_linux_asset x.AppImage '$SCRATCH/fake.AppImage'" >/dev/null && ! lib "check_linux_asset x.tar.gz '$SCRATCH/noengine.tar.gz'" >/dev/null \
		&& lib "check_linux_asset x.AppImage '$SCRATCH/real.AppImage'" >/dev/null && lib "check_linux_asset x.tar.gz '$SCRATCH/goodengine.tar.gz'" >/dev/null
}
check "the AppImage must be an ELF file and an engine tarball must have benchy-engine at its root" linux_layout_checked
# The real path, run end to end in a throw-away git repo with a copy of release.sh, a fake gh that serves files from $LX/assets (and
# answers the draft check from FAKE_DRAFT), and a throw-away HOME. $LX/pins.py writes a tree's runtime-pins.json for a client and engine version.
LX=$SCRATCH/lx
mkdir -p "$LX/bin" "$LX/assets" "$LX/repo/deploy/windows-installer" "$LX/repo/pts-core/static/update"
cat > "$LX/bin/gh" <<E2
#!/bin/bash
case "\$1 \$2" in
	"release view") echo "\${FAKE_DRAFT:-false}" ;;
	"release download")
		while [ \$# -gt 0 ]; do case \$1 in -p) f=\$2; shift 2;; -D) d=\$2; shift 2;; *) shift;; esac; done
		[ -f "$LX/assets/\$f" ] && cp "$LX/assets/\$f" "\$d/\$f" ;;
	*) exit 99 ;;
esac
E2
# Stand-ins so a release-mode installer build can run without NSIS or the real PHP and VC_redist downloads
printf '#!/bin/bash\nfor a in "$@"; do case $a in -VERSION) echo v3.stub; exit 0;; -DOUTFILE=*) o=${a#-DOUTFILE=};; esac; done\necho stub > "$o"\n' > "$LX/bin/makensis"
printf '#!/bin/bash\nprintf "Signer'"'"'s certificate:\\n\\tSubject: /C=US/ST=Washington/L=Redmond/O=Microsoft Corporation/CN=Microsoft Corporation\\nSucceeded\\n"\n' > "$LX/bin/osslsigncode"
chmod +x "$LX/bin/gh" "$LX/bin/makensis" "$LX/bin/osslsigncode"
cat > "$LX/pins.py" <<'E2'
import hashlib, io, json, os, sys, tarfile, zipfile
a, out, cv, ev = sys.argv[1:5]
def put(name, data):
    open(os.path.join(a, name), "wb").write(data); return {"file": name, "size": len(data), "sha256": hashlib.sha256(data).hexdigest()}
def tgz(tag):
    b = io.BytesIO()
    with tarfile.open(fileobj=b, mode="w:gz") as t:
        for n in ("benchy-engine", "LICENSE"):
            i = tarfile.TarInfo(n); d = ("%s %s" % (n, tag)).encode(); i.size = len(d); t.addfile(i, io.BytesIO(d))
    return b.getvalue()
def zipf(names):
    b = io.BytesIO()
    with zipfile.ZipFile(b, "w") as z:
        for n in names: z.writestr(n, "x")
    return b.getvalue()
def block(v, art): return {"version": v, "artifact": art}
p = {"windows-x64": {
        "php": {"version": "1", "artifact": put("php-1.zip", zipf(["php.exe"])), "upstream_urls": ["https://x/php-1.zip"],
                "vcredist": dict(put("VC_redist.x64.exe", b"vc"), version="14.1", urls=["https://x/vc.exe"])},
        "client": block(cv, put("BenchyClient-%s-windows-x64.zip" % cv, zipf(["c"]))),
        "engine": block(ev, put("benchy-engine-%s-windows-x64.zip" % ev, zipf(["benchy-engine.exe", "LICENSE"])))},
     "linux-x64": {"client": block(cv, put("BenchyClient-%s-x86_64.AppImage" % cv, b"\x7fELF appimage " + cv.encode())),
                   "engine": block(ev, put("benchy-engine-%s-linux-x64.tar.gz" % ev, tgz("x64" + ev)))},
     "linux-arm64": {"engine": block(ev, put("benchy-engine-%s-linux-arm64.tar.gz" % ev, tgz("arm64" + ev)))}}
exec(sys.argv[5] if len(sys.argv) > 5 else "")
json.dump(p, open(out, "w"), indent=1)
E2
cp "$RELEASE" "$LX/repo/deploy/release.sh"; chmod +x "$LX/repo/deploy/release.sh"
touch "$LX/repo/deploy/windows-installer/benchy.nsi" "$LX/repo/deploy/windows-installer/microsoft-root-cas.pem"
LXP=$LX/repo/pts-core/static/update/runtime-pins.json
mkdir -p "$LX/home/.cache/benchy-release"
(cd "$LX/repo" && git init -q . && git config user.email t@t && git config user.name t && git config commit.gpgsign false \
	&& echo "pts_define('PTS_VERSION', '9.9.9');" > pts-core/pts-core.php)
# lx_tag <client ver> <engine ver> [python edit of the pins p]: a fresh set of assets, a tree pinning them, tag v9.9.9 moved there
lx_tag() {
	rm -f "$LX"/assets/* "$LX"/home/.cache/benchy-release/*
	python3 -I "$LX/pins.py" "$LX/assets" "$LXP" "$1" "$2" "${3:-}" || return 1
	cp "$LX"/assets/php-1.zip "$LX"/assets/BenchyClient-*-windows-x64.zip "$LX"/assets/benchy-engine-*-windows-x64.zip "$LX/home/.cache/benchy-release/"
	cp "$LX/assets/VC_redist.x64.exe" "$LX/home/.cache/benchy-release/vcredist-14.1-VC_redist.x64.exe"
	(cd "$LX/repo" && git add -A && git commit -qm "pins $1 $2" --allow-empty && git tag -f v9.9.9 >/dev/null)
}
# lx <release.sh args>: standard output and error, merged, in the throw-away environment; the bundle dir is $LX/out/9.9.9
lx() { rm -f "$LX/home/.cache/benchy-release/"*.AppImage "$LX/home/.cache/benchy-release/"*.tar.gz
	HOME=$LX/home PATH=$LX/bin:$PATH BENCHY_NSIS=native BENCHY_RELEASE_DIR=$LX/out timeout 60 "$LX/repo/deploy/release.sh" "$@" 2>&1; }
LXB=$LX/out/9.9.9
bundle() { (cd "$LXB" 2>/dev/null && ls | tr '\n' ' '); }
lx_files() { echo "BenchyClient-$1-x86_64.AppImage BenchyClient-$1-x86_64.AppImage.sha256 benchy-engine-$2-linux-arm64.tar.gz benchy-engine-$2-linux-arm64.tar.gz.sha256 benchy-engine-$2-linux-x64.tar.gz benchy-engine-$2-linux-x64.tar.gz.sha256 "; }
sha_files_ok() { (cd "$LXB" && sha256sum -c ./*.sha256 >/dev/null 2>&1 && ! grep -q / ./*.sha256); }  # plain sha256sum output, bare file names

linux_assets_run() {
	lx_tag 1.0.0 2.0.0 && rm -rf "$LX/out" && lx linux-assets 9.9.9 >/dev/null || return 1
	[ "$(bundle)" = "$(lx_files 1.0.0 2.0.0)" ] || { bundle; return 1; }
	sha_files_ok
}
check "release.sh linux-assets writes the AppImage and both engine tarballs, each with a plain .sha256 that verifies" linux_assets_run
linux_draft_refused() {
	lx_tag 1.0.0 2.0.0 && rm -rf "$LX/out" && FAKE_DRAFT=true lx linux-assets 9.9.9 | grep -q 'is not published' && [ -z "$(bundle)" ]
}
check "linux-assets refuses an upstream release that is still a draft" linux_draft_refused
linux_notelf_refused() {
	lx_tag 1.0.0 2.0.0 && rm -rf "$LX/out" && lx linux-assets 9.9.9 >/dev/null || return 1
	lx_tag 1.0.0 2.0.0 "
import hashlib
b = b'not an elf'; n = 'BenchyClient-1.0.0-x86_64.AppImage'; open('$LX/assets/' + n, 'wb').write(b)
p['linux-x64']['client']['artifact'].update(size=len(b), sha256=hashlib.sha256(b).hexdigest())" || return 1
	lx linux-assets 9.9.9 | grep -q 'is not an ELF file'
}
check "linux-assets refuses an AppImage that is not an ELF file" linux_notelf_refused
linux_failed_keeps_bundle() {
	lx_tag 1.0.0 2.0.0 && rm -rf "$LX/out" && lx linux-assets 9.9.9 >/dev/null || return 1
	echo keep > "$LXB/RELEASE-NOTES.md"; local before; before=$(cd "$LXB" && sha256sum -- * | sha256sum)
	lx_tag 1.0.1 2.0.1 "p['linux-arm64']['engine']['artifact']['sha256'] = '0' * 64" || return 1
	lx linux-assets 9.9.9 | grep -q 'does not match the pin' || return 1
	[ "$(cd "$LXB" && sha256sum -- * | sha256sum)" = "$before" ]
}
check "a failed linux-assets run leaves an earlier bundle exactly as it was" linux_failed_keeps_bundle
linux_repin_replaces() {
	lx_tag 1.0.0 2.0.0 && rm -rf "$LX/out" && lx linux-assets 9.9.9 >/dev/null || return 1
	echo keep > "$LXB/RELEASE-NOTES.md"; echo deb > "$LXB/benchy_9.9.9_all.deb"
	lx_tag 1.0.1 2.0.1 && lx linux-assets 9.9.9 >/dev/null || return 1
	[ "$(bundle)" = "benchy_9.9.9_all.deb $(lx_files 1.0.1 2.0.1)RELEASE-NOTES.md " ] || { bundle; return 1; }
	sha_files_ok
}
check "after a re-pin the bundle holds only the newly pinned Linux assets (other files are left alone)" linux_repin_replaces
linux_no_pins_file() {
	(cd "$LX/repo" && git rm -q -r --cached pts-core/static/update && git commit -qm nopins && git tag -f v9.9.9 >/dev/null)
	local out; out=$(lx linux-assets 9.9.9); [ "${PIPESTATUS[0]}" = 0 ] && return 1
	grep -q 'predates the pins' <<<"$out" && ! grep -q '^fatal' <<<"$out"
}
check "linux-assets on a tag with no runtime-pins.json says so instead of printing a git error" linux_no_pins_file
linux_installer_attaches() {
	lx_tag 1.0.0 2.0.0 && rm -rf "$LX/out" && lx installer 9.9.9 >/dev/null || return 1
	[ "$(bundle)" = "$(lx_files 1.0.0 2.0.0)Benchy-Setup-9.9.9.exe Benchy-Setup-9.9.9.exe.buildinfo Benchy-Setup-9.9.9.exe.sha256 " ] || { bundle; return 1; }
	sha_files_ok 2>/dev/null; (cd "$LXB" && sha256sum -c ./BenchyClient*.sha256 ./benchy-engine*.sha256 >/dev/null) || return 1
	grep -c '^linux_asset: ' "$LXB/Benchy-Setup-9.9.9.exe.buildinfo" | grep -qx 3
}
check "release.sh installer (a tagged release) puts the Linux assets in the bundle dir and lists them in the buildinfo" linux_installer_attaches
linux_installer_old_tree() {
	lx_tag 1.0.0 2.0.0 "
for k in ('linux-x64', 'linux-arm64'): del p[k]" && rm -rf "$LX/out" && lx installer 9.9.9 | grep -q 'linux assets: none' || return 1
	[ "$(bundle)" = "Benchy-Setup-9.9.9.exe Benchy-Setup-9.9.9.exe.buildinfo Benchy-Setup-9.9.9.exe.sha256 " ] && grep -qx 'linux_asset: none' "$LXB/Benchy-Setup-9.9.9.exe.buildinfo"
}
check "a tree that pins no Linux assets still builds the installer, without them" linux_installer_old_tree

echo "== benchy.nsi"
check "the engine zip is an optional payload (ENGINEZIP); install.ps1 finds it in the -PayloadDir folder" bash -c "grep -q '!ifdef ENGINEZIP' '$NSI' && grep -q -- '-PayloadDir \"\$PLUGINSDIR\"' '$NSI'"
check "the unpack-failed test covers the engine zip when one is built in" bash -c "grep -A1 '!ifdef ENGINEZIP' '$NSI' | grep -q 'OrIfNot.*engine.zip'"
nsi_command_short() {
	# the install.ps1 command line no longer has a path per file: no -PhpZip/-ClientZip/-EngineZip/-ResultFile, and it is measured
	! grep -E "StrCpy \\\$R0 .*-(PhpZip|ClientZip|EngineZip|ResultFile) " "$NSI" && grep -q 'StrLen \$R9 \$R9' "$NSI" && grep -q 'ElseIf} \$R9 >= 1023' "$NSI"
}
check "the install command takes one -PayloadDir, not a path per file, and is refused (not cut) at 1023 characters" nsi_command_short
nsi_command_length_model() {
	# the real strings: the longest first-run command, for a long profile name and a 259-character /LOG path, stays under 1023
	python3 -I - "$NSI" <<'PY'
import re, sys
t = open(sys.argv[1]).read()
base = re.search(r"StrCpy \$R0 '(\"\$PowerShell\" .*?)'\n", t).group(1)
assert "-PayloadDir" in base
def build(user, log):
    P = r"C:\Users\%s\AppData\Local\Temp\nsa1234.tmp" % user
    s = base.replace("$PowerShell", r"C:\Windows\Sysnative\WindowsPowerShell\v1.0\powershell.exe").replace("$PLUGINSDIR", P)
    s += " -VcRedist Auto"
    s += ' -PhoromaticServer "https://a-long-server-name.example.internal:39443/ABCDEF"'
    return s + ' -VcRedistHandOff "%s\\vc\\VC_redist.x64.exe" -LogFile "%s"' % (P, log)
n = len(build("u" * 41, "L" * 259))
assert n < 1023, n
old_n = n + len(" -PhpZip ") * 0
print("longest realistic first-run command:", n, "characters")
PY
}
check "...and the longest realistic one (41-character profile name, 259-character /LOG, https server) fits with room to spare" nsi_command_length_model
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
check "install.ps1 takes -EngineZip, checks it against the engine pin (exit 11), and installs it with Get-BenchyEngineSwap / Install-BenchySwappedDirs" \
	bash -c "grep -q '\[string\]\$EngineZip' '$INSTALL' && grep -q 'Get-BenchyEnginePin' '$INSTALL' && grep -q 'Stop-Benchy 11 \"The engine zip' '$INSTALL' && grep -q 'Get-BenchyEngineSwap \$EngineZip' '$INSTALL' && grep -q 'Install-BenchySwappedDirs \$InstallDir \$Swaps' '$INSTALL'"
check "the engine goes to engine\\native\\benchy-engine.exe, where the client looks (..\\engine\\native\\benchy-engine.exe)" \
	bash -c "grep -q \"'engine\\\\\\\\native\\\\\\\\benchy-engine.exe'\" '$LIB' && grep -q \"ExeRel = 'native\\\\\\\\benchy-engine.exe'\" '$LIB'"
payload_dir_derives_paths() {
	hasf '[string]$PayloadDir' "$INSTALL" && hasf "Join-Path \$PayloadDir 'php\\php.zip'" "$INSTALL" && hasf "Join-Path \$PayloadDir 'client\\client.zip'" "$INSTALL" \
		&& hasf "Join-Path \$PayloadDir 'engine.zip'" "$INSTALL" && hasf "Join-Path \$PayloadDir 'result.json'" "$INSTALL" && hasf 'if (-not $PhpZip) { $PhpZip' "$INSTALL" \
		&& awk '/^if \(\$PayloadDir\)/{p=NR} /^function Stop-EarlyInstall/{s=NR} END{exit !(p && s && p < s)}' "$INSTALL"
}
check "-PayloadDir: install.ps1 builds the zip and result paths from it (before anything can fail), and an explicit path wins" payload_dir_derives_paths
keepdirs_cover_every_swapped_dir() {
	local name missing=0 any=0
	for name in $(grep -oE "Name = '[a-z]+'; ExeRel" "$LIB" | grep -oE "'[a-z]+'" | tr -d "'"); do
		any=1
		for suffix in "" .new .prev; do
			grep -E "^\s*\\\$KeepDirs = @\(" "$INSTALL" | grep -q "'$name$suffix'" || { echo "\$KeepDirs lacks '$name$suffix'"; missing=1; }
		done
	done
	[ "$any" = 1 ] || { echo "no swapped folder found in the lib"; return 1; }
	[ "$missing" = 0 ]
}
check "\$KeepDirs holds every swapped folder (client and engine) with its .new and .prev, or /MIR would delete them" keepdirs_cover_every_swapped_dir
keepdirs_cover_per_user_data() {
	# BenchyClient (the client's settings and cache) and state (the run lock) live under the install dir and are not the release tree's
	local dirs; dirs=$(grep -E "^\s*\\\$KeepDirs = @\(" "$INSTALL")
	grep -q "'BenchyClient'" <<<"$dirs" && grep -q "'state'" <<<"$dirs" && grep -q "'update'" <<<"$dirs"
}
check "\$KeepDirs keeps BenchyClient (the client's settings and cache), state and update: a reinstall must not purge them" keepdirs_cover_per_user_data
check "...and the engine's data (%LOCALAPPDATA%\\benchy-engine) is outside the install dir, so there is nothing more to keep" \
	bash -c "grep -q 'LOCALAPPDATA%.benchy-engine' '$INSTALL'"
check "the process check looks for benchy-engine.exe running from the install dir's engine folder (engine, engine.new, engine.prev)" \
	bash -c "grep -qF \"Name = 'benchy-engine.exe'\" '$LIB' && grep -qF '\$EnginePrefix = \"\$Root\\engine\"' '$LIB' && grep -qF '\$_.ExecutablePath.StartsWith(\$EnginePrefix' '$LIB'"
check "the exit-3 refusal of install.ps1 and uninstall.ps1 takes its advice from Get-BenchyRunningAdvice, and the in-use check from Get-BenchyInUseAdvice" \
	bash -c "grep -q 'Get-BenchyRunningAdvice \$Running' '$INSTALL' && grep -q 'Get-BenchyRunningAdvice \$Running' '$UNINSTALL' && grep -q 'Get-BenchyInUseAdvice \$Held' '$INSTALL' && grep -q 'Get-BenchyInUseAdvice \$Held' '$UNINSTALL'"
check "the advice: a client means the tray icon; the engine alone (no tray) means Open Benchy, Quit and stop the agent; else close the windows" \
	bash -c "grep -qF 'Quit Benchy from its tray icon first' '$LIB' && grep -qF \"Open Benchy, then choose Quit and 'Quit and stop the agent'\" '$LIB' && grep -qF 'Close every Benchy window and run' '$LIB'"
file_in_use_before_copying() {
	python3 -I - "$INSTALL" "$UNINSTALL" <<'PY'
import sys
t = open(sys.argv[1]).read()
f = t.index("Get-BenchyFilesInUse $InstallDir")
assert t.index("Get-BenchyProcesses $InstallDir") < f < t.index("Stop-Benchy 3 (Get-BenchyInUseAdvice") < t.index("& robocopy $SourceDir"), "install.ps1"
u = open(sys.argv[2]).read()
assert u.index("Get-BenchyProcesses $InstallDir") < u.index("Get-BenchyFilesInUse $InstallDir") < u.index("Stop-Benchy 3 (Get-BenchyInUseAdvice") < u.index("Remove-BenchyTree $InstallDir"), "uninstall.ps1"
PY
}
check "install.ps1 and uninstall.ps1 check that the exes are not held open (exit 3), after the process check and before anything is changed" file_in_use_before_copying
file_in_use_opens_exclusively() {
	grep -qF '[IO.File]::Open($Path, [IO.FileMode]::Open, [IO.FileAccess]::ReadWrite, [IO.FileShare]::None)' "$LIB" \
		&& grep -qF "client.new', 'client.prev'" "$LIB" && grep -qF "engine.new', 'engine.prev'" "$LIB" \
		&& grep -qF 'native\benchy-engine.exe' "$LIB" && grep -qF '\BenchyClient.exe' "$LIB"
}
check "the in-use check opens client\\BenchyClient.exe and engine\\native\\benchy-engine.exe (and their .new/.prev) for writing with no sharing" file_in_use_opens_exclusively
swap_stages_everything_first() {
	python3 -I - "$INSTALL" <<'PY'
import sys
t = open(sys.argv[1]).read()
assert t.count("Install-BenchySwappedDirs") == 1, "one swap for the client and the engine together"
assert t.index("Get-BenchyClientSwap") < t.index("Get-BenchyEngineSwap") < t.index("Install-BenchySwappedDirs")
PY
}
check "install.ps1 swaps the client and the engine in one step (both unpacked and checked first)" swap_stages_everything_first

echo "== the swap and the in-use check, run in PowerShell (container; skipped when the image is not here)"
PWSH_IMAGE=mcr.microsoft.com/powershell:7.4-ubuntu-22.04
if command -v podman >/dev/null && podman image exists "$PWSH_IMAGE" 2>/dev/null; then
	# Only the functions under test, with the path backslashes turned into slashes so that they work on Linux
	python3 -I - "$LIB" "$SCRATCH/fn.ps1" <<'PY'
import re, sys
t = open(sys.argv[1]).read()
names = ['Stop-Benchy', 'Rename-BenchyDirRetry', 'New-BenchyStagedDir', 'Install-BenchySwappedDirs', 'Undo-BenchyStagedDirs', 'Get-BenchyClientSwap', 'Get-BenchyEngineSwap',
         'Get-BenchyRunningAdvice', 'Get-BenchyFilesInUse', 'Get-BenchyFileInUse', 'Get-BenchyInUseAdvice', 'Get-BenchyUndoText', 'Remove-BenchyTree']
out = ''
for n in names:
    m = re.search(r'^function ' + re.escape(n) + r'[\(\r\n ].*?^}\n', t, re.S | re.M)
    assert m, n
    out += m.group(0) + '\n'
open(sys.argv[2], 'w').write(out.replace('\\', '/'))
PY
	swap_run() {
		local out rc=0
		out=$(podman run --rm --network=none -v "$SCRATCH:/s:ro" -v "$HERE:/t:ro" "$PWSH_IMAGE" pwsh -NoProfile -File /t/installer_swap.ps1 -Functions /s/fn.ps1 2>&1) || rc=$?
		printf '%s\n' "$out" | grep -E '^(ok|FAIL) ' | sed 's/^ok /        ok  /; s/^FAIL /        FAIL /'
		[ "$rc" = 0 ] && ! grep -q '^FAIL ' <<<"$out" && [ "$(grep -c '^ok ' <<<"$out")" -ge 10 ]
	}
	check "installer_swap.ps1: file-in-use detection, a combined swap, a failed rename undone, a bad zip stops both" swap_run
else
	SKIP=$((SKIP+1)); echo "  SKIP  the PowerShell swap checks: $PWSH_IMAGE is not here"
fi
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

echo "== 0.9.9 installer changes (source checks; the Windows behavior is Zeb's)"
recheck_before_copy() {
	python3 -I - "$INSTALL" <<'PY'
import re, sys
t = open(sys.argv[1]).read()
copy = t.index("# --- 7. Copy Benchy into the install dir")
robo = t.index("& robocopy $SourceDir")
first = t.index("$Running = @(Get-BenchyProcesses $InstallDir)")
assert first < copy, "the first check is in step 2"
between = t[copy:robo]
assert "Get-BenchyProcesses $InstallDir" in between and "Get-BenchyFilesInUse $InstallDir" in between and "Stop-Benchy 3" in between, "the check is repeated between the step 7 heading and robocopy"
PY
}
check "install.ps1 checks for a running Benchy again right before it copies anything" recheck_before_copy
undo_result_used() {
	python3 -I - "$LIB" <<'PY'
import re, sys
t = open(sys.argv[1]).read()
body = t[t.index("function Install-BenchySwappedDirs"):t.index("function Undo-BenchyStagedDirs")]
assert len(re.findall(r"\$Left = @\(Undo-BenchyStagedDirs \$Staged\)", body)) == 3, "all three failure paths keep what Undo returns"
assert "Undo-BenchyStagedDirs $Staged\n" not in body, "no path throws the result away"
assert "Nothing was changed" not in body, "step 7b does not claim nothing was changed"
assert len(re.findall(r"Get-BenchyUndoText \$Left", body)) == 3
PY
}
check "the swap's three failure paths use the undo result, and none says 'Nothing was changed'" undo_result_used
uninstall_keeps_client_settings() {
	python3 -I - "$UNINSTALL" <<'PY'
import re, sys
t = open(sys.argv[1]).read()
assert "$KeepClientData = (-not $RemoveData) -and (Test-Path -LiteralPath $ClientData)" in t
assert t.count("$ClientData") >= 6
calls = re.findall(r"Remove-BenchyTree \$InstallDir[^\n]*", t)
assert len(calls) == 2 and "$KeepNow" in calls[0] and "$KeepLast" in calls[1], calls
assert "(Remove-BenchyTree $InstallDir $(if" not in t and "$(if ($KeepClientData) { $ClientData })" not in t, "no sub-expression that can hand a null to the keep list"
assert t.count("if ($KeepClientData) { $Keep") == 2
assert "-RemoveUserData" in t and "$RemoveData = [bool]$RemoveUserData" in t
PY
}
check "uninstall.ps1 keeps the BenchyClient settings unless the person (or -RemoveUserData) says to delete the user data" uninstall_keeps_client_settings

echo "== the agent API (source checks; the running behavior is in test_native_jobs.py)"
API=$TREE/pts-core/objects/phoromatic/phoromatic_api_v2.php
discard_before_rollback() {
	python3 -I - "$API" <<'PY'
import sys
t = open(sys.argv[1]).read()
i = t.index("if(!$ok || @$db->exec('COMMIT') === false)")
j = t.index("self::release_snapshot($job)", i)
block = t[i:j]
assert block.index("discard_files(") < block.index("exec('ROLLBACK')"), "the files are deleted after the ROLLBACK has released the lock"
PY
}
check "a failed save deletes the stored files before the ROLLBACK, while the write lock is still held" discard_before_rollback
stray_output_cut_first() {
	# log_stray_output, lifted out of the class and run: the cut comes first, then whitespace is folded, and a long text ends in ' ...'
	python3 -I - "$API" "$SCRATCH/stray.php" <<'PY'
import re, sys
t = open(sys.argv[1]).read()
m = re.search(r"\tprotected static function log_stray_output.*?\n\t}\n", t, re.S)
open(sys.argv[2], "w").write("<?php class T {\n" + m.group(0).replace("protected", "public") + "}\n"
	"ini_set('error_log', $argv[1]); ini_set('log_errors', '1');\n"
	"T::log_stray_output(\"  a \\n\\n b\\t c  \", 'x');\n"
	"T::log_stray_output(str_repeat('w ', 5000), 'long');\n"
	"T::log_stray_output(\" \\n \", 'blank');\n")
PY
	php "$SCRATCH/stray.php" "$SCRATCH/stray.log" || return 1
	[ "$(wc -l < "$SCRATCH/stray.log")" = 2 ] || { cat "$SCRATCH/stray.log" | head -c 500; return 1; }
	grep -q 'api v2: x: a b c$' "$SCRATCH/stray.log" || { head -c 300 "$SCRATCH/stray.log"; return 1; }
	local long; long=$(sed -n 2p "$SCRATCH/stray.log")
	[ "${long: -4}" = " ..." ] && [ "${#long}" -lt 2060 ] || { echo "${#long}"; return 1; }
}
check "log_stray_output cuts to 2000 characters first, then folds whitespace; blank output logs nothing" stray_output_cut_first

echo; echo "installer: $PASS passed, $FAIL failed, $SKIP skipped"
[ "$FAIL" -eq 0 ] || { printf '  failed: %s\n' "${FAILED[@]}"; exit 1; }
