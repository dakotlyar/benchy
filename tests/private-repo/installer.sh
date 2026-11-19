#!/bin/bash
# The Windows installer, as far as Linux can check it (0.9.6, B3): the runtime pins, release.sh's verification of the pinned release assets,
# and the structure of benchy.nsi, install.ps1, uninstall.ps1 and the shared PowerShell lib. Nothing here runs PowerShell or the installer;
# the real Windows behavior (a swap, a process check, the Run key, an unelevated launch) is Zeb's K-T1 on the Windows test VM.
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
mkdir -p "$LX/bin" "$LX/assets" "$LX/gate" "$LX/repo/deploy/windows-installer" "$LX/repo/pts-core/static/update"
cat > "$LX/bin/gh" <<E2
#!/bin/bash
case "\$1 \$2" in
	"release view") echo "\${FAKE_DRAFT:-false}" ;;
	api*)
		case "\$2" in
			repos/dakotlyar/benchy-tests/contents/legal/catalog-keys.txt) cat "$LX/gate/keys.txt" || exit 1 ;;
			repos/dakotlyar/benchy-engine/commits/*) cat "$LX/gate/engine-sha" || exit 1 ;;
			repos/dakotlyar/benchy-engine/contents/spec/protocol-v2/*) f=\${2#*protocol-v2/}; f=\${f%%\?*}; cat "$LX/gate/engine-\$f" || exit 1 ;;
			*) exit 99 ;;
		esac ;;
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

echo "== release.sh: the signed-catalog release gate (E4.4 C6)"
# A tree with a server key table, a stand-in benchy that answers server-catalog-keys and server-catalog-check from files, a protocol-v2 PIN and a catalog pin.
# gate_tag [python edit of the pins p] [shell snippet run in the tree before the commit]: the base tree is rebuilt every time, then tagged v9.9.9.
GK=$(openssl ecparam -name prime256v1 -genkey -noout 2>/dev/null | openssl pkey -pubout -outform DER 2>/dev/null | base64 -w0)
GK_ID=$(printf '%s' "$GK" | base64 -d | sha256sum | cut -c1-32)
FX=$(openssl ecparam -name prime256v1 -genkey -noout 2>/dev/null | openssl pkey -pubout -outform DER 2>/dev/null | base64 -w0)
FX_ID=$(printf '%s' "$FX" | base64 -d | sha256sum | cut -c1-32)
GB=$LX/gate-bundle.zip; printf 'a signed bundle, as far as release.sh can tell' > "$GB"
GB_SHA=$(sha256sum "$GB" | cut -d' ' -f1); GB_SIZE=$(stat -c %s "$GB")
ENGINE_SHA=$(printf 'ab%.0s' $(seq 20))
gate_tag() {
	local R=$LX/repo
	rm -rf "$R/pts-core/static/catalog" "$R/pts-core/static/protocol-v2" "$R/tests" "$R/documentation" "$R/benchy" "$R/pts-core/oops.txt" "$R"/stub-* "$LX"/gate/*
	mkdir -p "$R/pts-core/static/catalog" "$R/pts-core/static/protocol-v2" "$R/tests/catalog/engine-fixtures" "$R/documentation"
	printf '{"schema":"benchy-catalog-keys/1","keys":[{"generation":1,"spki":"%s"}],"denied_key_ids":[],"floor":{}}' "$GK" > "$R/pts-core/static/catalog/keys.json"
	printf 'generation 1 %s\n' "$GK_ID" > "$LX/gate/keys.txt"
	printf '%s\n' "$FX" > "$R/tests/catalog/engine-fixtures/fixture-key.spki"
	printf 'keys of the tests: %s and the engine fixture d98e6e86a8584efd075cc0fc8155f980\n' "$FX_ID" > "$R/tests/catalog/notes.txt"
	printf 'Copied from the benchy-engine repository, tag v0.6.0 (commit %s): spec/protocol-v2/\nlocal-field: v2.schema.json $defs.engine.properties.later_field\n' "$ENGINE_SHA" > "$R/pts-core/static/protocol-v2/PIN"
	printf '%s\n' "$ENGINE_SHA" > "$LX/gate/engine-sha"
	echo '{"$defs":{"engine":{"properties":{"older_field":{}}}}}' > "$LX/gate/engine-v2.schema.json"
	cat > "$R/benchy" <<'E3'
#!/bin/sh
d=$(dirname "$0")
case "$1" in
	server-catalog-keys) cat "$d/stub-keys.txt" ;;
	server-catalog-check) [ -f "$d/stub-check-refused" ] && { echo "Refused (bad_signature): the signature does not verify" >&2; exit 1; }; cat "$d/stub-check.txt" ;;
esac
E3
	chmod +x "$R/benchy"
	cp "$LX/gate/keys.txt" "$R/stub-keys.txt"
	echo "public 1 1 5 0 0" > "$R/stub-check.txt"
	if [ -n "${2:-}" ]; then (cd "$R" && eval "$2"); fi
	lx_tag 1.0.0 2.0.0 "p['catalog'] = {'public': {'file': 'benchy-catalog-public-1-1.zip', 'size': $GB_SIZE, 'sha256': '$GB_SHA', 'generation': 1, 'sequence': 1, 'urls': ['https://x/benchy-catalog-public-1-1.zip']}}
${1:-}"
}
gate() { lx check 9.9.9 --catalog-bundle "$GB"; }
gate_refuses() { local out rc=0; out=$(gate) || rc=$?; [ "$rc" != 0 ] || { echo "passed, but should refuse: $out" | tail -5; return 1; }; grep -qF -- "$1" <<<"$out" || { printf '%s\n' "$out" | tail -8; return 1; }; }
gate_passes() { local out; out=$(gate) || { printf '%s\n' "$out" | tail -8; return 1; }; grep -q 'is public, generation 1, sequence 1' <<<"$out" && grep -q 'protocol-v2 PIN names engine v0.6.0' <<<"$out"; }
gate_run_good() { gate_tag && gate_passes; }
check "the gate passes a tree whose keys match, with no test key, a public pinned bundle and a PIN that names its tag" gate_run_good
gate_old_tree() { (cd "$LX/repo" && git rm -q -r --cached pts-core/static/catalog && git commit -qm old && git tag -f v9.9.9 >/dev/null) && lx check 9.9.9 | grep -q 'catalog gate: skipped'; }
check "a tag with no server key table (older than E4.4) skips the gate" gate_old_tree
gate_keys_differ() { gate_tag "" 'printf "generation 1 %s\ngeneration 2 %s\n" '"$GK_ID"' 00000000000000000000000000000000 > "$LX/gate/keys.txt"' && gate_refuses 'differs from benchy-tests legal/catalog-keys.txt'; }
check "refused: the server's key list differs from benchy-tests' (a key missing or a generation added)" gate_keys_differ
gate_gh_down() { gate_tag && rm -f "$LX/gate/keys.txt" && gate_refuses 'could not fetch legal/catalog-keys.txt'; }
check "refused, failing closed: benchy-tests' key list can't be fetched" gate_gh_down
gate_test_key_in_table() { gate_tag "" 'printf "{\"schema\":\"benchy-catalog-keys/1\",\"keys\":[{\"generation\":1,\"spki\":\"%s\"}],\"denied_key_ids\":[],\"floor\":{}}" "'"$FX"'" > pts-core/static/catalog/keys.json; printf "generation 1 %s\n" '"$FX_ID"' > stub-keys.txt; cp stub-keys.txt "$LX/gate/keys.txt"' && gate_refuses 'the test key tests/catalog/engine-fixtures/fixture-key.spki' || return 1
	# ...the table and the list agreeing on a key is not enough when the table holds one the list lacks
	gate_tag "" 'printf "{\"schema\":\"benchy-catalog-keys/1\",\"keys\":[{\"generation\":1,\"spki\":\"%s\"},{\"generation\":2,\"spki\":\"%s\"}],\"denied_key_ids\":[],\"floor\":{}}" "'"$GK"'" "'"$FX"'" > pts-core/static/catalog/keys.json' && gate_refuses 'is not in benchy-tests legal/catalog-keys.txt'; }
check "refused: the key table holds a key the benchy-tests list doesn't (a test key)" gate_test_key_in_table
gate_fixture_id_outside() { gate_tag "" 'echo "the fixture key d98e6e86a8584efd075cc0fc8155f980" > documentation/notes.txt' && gate_refuses 'the engine fixture key'; }
check "refused: the engine's fixture key id in a file outside tests/" gate_fixture_id_outside
gate_tests_key_outside() { gate_tag "" 'echo "key '"$FX_ID"'" > pts-core/oops.txt' && gate_refuses 'the test key tests/catalog/engine-fixtures/fixture-key.spki'; }
check "refused: the id of a key under tests/catalog in a file outside tests/ (it is fine inside tests/)" gate_tests_key_outside
gate_testbuild_name() { gate_tag "" 'touch documentation/benchy-engine-'"catalog""test"'' && gate_refuses 'a test-build artifact'; }
check "refused: a file named like a test build" gate_testbuild_name
gate_testbuild_marker() { gate_tag "" 'printf "x BENCHY_CATALOG""TEST_SPKI x" > documentation/engine.bin' && gate_refuses 'holds the test-build key variable'; }
check "refused: a file that holds the test-build key variable" gate_testbuild_marker
gate_pin_notag() { gate_tag "" 'printf "Copied from the benchy-engine repository, ce-catalog-fields commit e1b7b12\n" > pts-core/static/protocol-v2/PIN' && gate_refuses 'must name an engine tag'; }
check "refused: the protocol-v2 PIN's first line doesn't name a tag" gate_pin_notag
gate_pin_wrongcommit() { gate_tag "" 'echo cd'"$(printf 'cd%.0s' $(seq 19))"' > "$LX/gate/engine-sha"' && gate_refuses 'but the tag is at'; }
check "refused: the PIN's commit is not where its tag is" gate_pin_wrongcommit
gate_pin_forgotten() { gate_tag "" 'echo "{\"\$defs\":{\"engine\":{\"properties\":{\"later_field\":{}}}}}" > "$LX/gate/engine-v2.schema.json"' && gate_refuses 'already has it: the re-pin to that tag was forgotten'; }
check "refused: the PIN lists a local field the engine tag it names already has" gate_pin_forgotten
gate_pin_badline() {
	gate_tag "" 'printf "local-field: v2.schema.json \$defs.engine.properties.older_field (note)\n" >> pts-core/static/protocol-v2/PIN' && gate_refuses 'a local-field line must be exactly' || return 1
	gate_tag "" 'printf "local-field: v2.schema.json \$defs.engine.properties.older_field \n" >> pts-core/static/protocol-v2/PIN' && gate_refuses 'a local-field line must be exactly' || return 1
	gate_tag "" 'printf "local-field: v2.schema.json \$defs.engine.properties.older_field\r\n" >> pts-core/static/protocol-v2/PIN' && gate_refuses 'a local-field line must be exactly'
}
check "refused: a local-field line with a trailing note, a trailing space or a CR (it is never skipped)" gate_pin_badline
gate_fifth_private() { gate_tag "" 'echo "private 1 1 5 0 0" > stub-check.txt' && gate_refuses 'is the private tier, not public'; }
check "the fifth guard: a pinned bundle whose signed tier is private is refused" gate_fifth_private
gate_pair() { gate_tag "" 'echo "public 1 2 5 0 0" > stub-check.txt' && gate_refuses 'but the pin says generation 1, sequence 1'; }
check "refused: the bundle is signed at another generation or sequence than the pin says" gate_pair
gate_server_refuses() { gate_tag "" 'touch stub-check-refused' && gate_refuses 'is refused by the server'"'"'s own check'; }
check "refused: the server's own check refuses the pinned bundle (a bad signature)" gate_server_refuses
gate_sha() { gate_tag && printf 'another file' > "$LX/other.zip" && local out rc=0; out=$(lx check 9.9.9 --catalog-bundle "$LX/other.zip") || rc=$?; [ "$rc" != 0 ] && grep -q 'does not match the pin' <<<"$out"; }
check "refused: --catalog-bundle with a file that is not the pinned one" gate_sha
gate_pin_private() { gate_tag "p['catalog']['private'] = dict(p['catalog']['public'])" && gate_refuses 'may hold only a public pin'; }
check "refused: a catalog pin for a private bundle" gate_pin_private
gate_pin_bad_name() { gate_tag "p['catalog']['public']['file'] = 'benchy-catalog-private-1-1.zip'" && gate_refuses 'must be benchy-catalog-public-'; }
check "refused: a catalog pin whose file name is not a public bundle's" gate_pin_bad_name
gate_missing_bundle_arg() { gate_tag && local out rc=0; out=$(lx check 9.9.9 --catalog-bundle "$LX/no-such-file.zip") || rc=$?; [ "$rc" != 0 ] && grep -q 'no such file' <<<"$out"; }
check "check --catalog-bundle with a path that doesn't exist is refused (not silently treated as no file)" gate_missing_bundle_arg
gate_cached() { gate_tag && cp "$GB" "$LX/home/.cache/benchy-release/benchy-catalog-public-1-1.zip" && lx check 9.9.9 | grep -q 'is public, generation 1, sequence 1'; }
check "the pinned bundle is found in the cache once it has been given (a later step needs no --catalog-bundle)" gate_cached
gate_no_pin() { local out rc=0; gate_tag "del p['catalog']" && lx check 9.9.9 | grep -q 'no catalog bundle is pinned' || return 1
	out=$(lx catalog-bundle 9.9.9 --out "$LX/out/cb") || rc=$?; [ "$rc" = 0 ] && grep -q 'pins no catalog bundle' <<<"$out" && [ ! -e "$LX/out/cb" ]; }
check "a tag with no catalog pin passes the gate; catalog-bundle says there is nothing to build, exits 0 and writes nothing" gate_no_pin
gate_bundle_step() { gate_tag && rm -rf "$LX/out/cb" && mkdir -p "$LX/out/cb" && : > "$LX/out/cb/benchy-catalog-public-1-0.zip" && echo keep > "$LX/out/cb/RELEASE-NOTES.md" \
	&& lx catalog-bundle 9.9.9 --catalog-bundle "$GB" --out "$LX/out/cb" >/dev/null && [ "$(cd "$LX/out/cb" && LC_ALL=C ls | tr '\n' ' ')" = "RELEASE-NOTES.md benchy-catalog-public-1-1.zip benchy-catalog-public-1-1.zip.sha256 " ] \
	&& (cd "$LX/out/cb" && sha256sum -c benchy-catalog-public-1-1.zip.sha256 >/dev/null) && cmp -s "$GB" "$LX/out/cb/benchy-catalog-public-1-1.zip"; }
check "catalog-bundle puts the checked bundle and a verifying .sha256 in the release dir, replacing an older bundle there and leaving other files" gate_bundle_step
gate_bundle_refused_keeps() { gate_tag "" 'echo "private 1 1 5 0 0" > stub-check.txt' && rm -rf "$LX/out/cb" && mkdir -p "$LX/out/cb" && ! lx catalog-bundle 9.9.9 --catalog-bundle "$GB" --out "$LX/out/cb" >/dev/null && [ -z "$(ls "$LX/out/cb")" ]; }
check "a refused catalog-bundle writes nothing to the release dir" gate_bundle_refused_keeps
gate_installer_runs_it() { gate_tag "" 'echo "private 1 1 5 0 0" > stub-check.txt' && cp "$GB" "$LX/home/.cache/benchy-release/benchy-catalog-public-1-1.zip" && rm -rf "$LX/out" && lx installer 9.9.9 | grep -q 'is the private tier' && [ ! -d "$LXB" ]; }
check "the installer build (a tagged release) runs the gate first and stops before building anything" gate_installer_runs_it
gate_clean() { (cd "$LX/repo" && rm -rf pts-core/static/catalog pts-core/static/protocol-v2 tests benchy stub-*.txt documentation); }
gate_engine_tree() {  # gate_engine_tree <python bytes expression for benchy-engine.exe>
	gate_clean; lx_tag 1.0.0 2.0.0 "
import zipfile, hashlib
z = zipfile.ZipFile('$LX/assets/benchy-engine-2.0.0-windows-x64.zip', 'w'); z.writestr('benchy-engine.exe', $1); z.writestr('LICENSE', 'x'); z.close()
b = open('$LX/assets/benchy-engine-2.0.0-windows-x64.zip', 'rb').read(); p['windows-x64']['engine']['artifact'].update(size=len(b), sha256=hashlib.sha256(b).hexdigest())" || return 1
	cp "$LX/assets/benchy-engine-2.0.0-windows-x64.zip" "$LX/home/.cache/benchy-release/"; rm -rf "$LX/out"
}
gate_testbuild_engine() { gate_engine_tree "b'MZ BENCHY_CATALOG' b'TEST_SPKI'" && lx installer 9.9.9 | grep -q 'refusing a test-build engine' && [ ! -d "$LXB" ]; }
check "the installer refuses a pinned engine zip that is a test build (a release)" gate_testbuild_engine
gate_testbuild_tag() { gate_engine_tree "b'MZ build\\t-tags=netgo,catalog' b'test'" && lx installer 9.9.9 | grep -q 'built with the test tag' && [ ! -d "$LXB" ]; }
check "...and one whose Go build info shows the test build tag" gate_testbuild_tag
gate_testbuild_dev() { gate_engine_tree "b'MZ BENCHY_CATALOG' b'TEST_SPKI'" && local out; out=$(lx installer --ref v9.9.9 9.9.9 --engine-zip "$LX/assets/benchy-engine-2.0.0-windows-x64.zip" --out "$LX/out/dev") \
	&& grep -q 'WARNING: .*is a test build' <<<"$out" && grep -q 'TEST-KEY engine' <<<"$out" && grep -q '^Built ' <<<"$out" && [ -s "$LX/out/dev/Benchy-Setup-9.9.9-dev-"*.exe ]; }
check "a dev/test installer build (--ref) with a test-key engine warns loudly and still builds" gate_testbuild_dev
gate_testbuild_linux() {
	gate_clean; lx_tag 1.0.0 2.0.0 "
import hashlib, io, tarfile
b = io.BytesIO()
with tarfile.open(fileobj=b, mode='w:gz') as t:
    for n, d in (('benchy-engine', b'ELF BENCHY_CATALOG' b'TEST_SPKI'), ('LICENSE', b'x')):
        i = tarfile.TarInfo(n); i.size = len(d); t.addfile(i, io.BytesIO(d))
raw = b.getvalue(); open('$LX/assets/benchy-engine-2.0.0-linux-x64.tar.gz', 'wb').write(raw)
p['linux-x64']['engine']['artifact'].update(size=len(raw), sha256=hashlib.sha256(raw).hexdigest())" || return 1
	rm -rf "$LX/out"; lx linux-assets 9.9.9 | grep -q 'refusing a test-build engine' && [ ! -d "$LXB" ]
}
check "linux-assets refuses a pinned engine tarball that is a test build" gate_testbuild_linux

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
	# the uninstaller carries uninstall.ps1 (the real one: it is small and the compile only embeds it)
	cp "$TREE/uninstall.ps1" "$SCRATCH/payload/uninstall.ps1"
	# the early files the wizard's pages unpack before the section (install.ps1 -CheckRunning needs the lib; the pin says if VC++ is needed)
	mkdir -p "$SCRATCH/payload/pts-core/static/update"
	cp "$TREE/pts-core/static/update/benchy-windows.ps1" "$TREE/pts-core/static/update/runtime-pins.json" "$SCRATCH/payload/pts-core/static/update/"
	printf 'php' > "$SCRATCH/php.zip"; printf 'client' > "$SCRATCH/client.zip"; printf 'MZ' > "$SCRATCH/engine.exe"
	nsis() {  # nsis <extra -D args...>: compile with -WX (any warning fails), no network
		podman run --rm --network=none -v "$TREE:/work/tree:ro" -v "$SCRATCH:/s" "$nsis_image" -V2 -WX \
			-DVERSION=9.9.9 -DPAYLOAD=/s/payload -DPHPZIP=/s/php.zip -DVCREDIST_SHA256=$(printf '0%.0s' $(seq 64)) -DVCREDIST_SIZE=1 -DOUTFILE=/s/out/t.exe "$@" /work/tree/deploy/windows-installer/benchy.nsi
	}
	check "benchy.nsi compiles with -WX: no client, no engine" bash -c "$(declare -f nsis); nsis_image='$nsis_image' TREE='$TREE' SCRATCH='$SCRATCH'; nsis"
	check "benchy.nsi compiles with -WX: the client and the engine built in" bash -c "$(declare -f nsis); nsis_image='$nsis_image' TREE='$TREE' SCRATCH='$SCRATCH'; nsis -DCLIENTZIP=/s/client.zip -DENGINEZIP=/s/engine.zip -DENGINEEXE=/s/engine.exe -DENGINEEXE_SHA256=$(printf \"0%.0s\" $(seq 64)) -DENGINEEXE_SIZE=2"
	check "benchy.nsi compiles with -WX: the client and engine versions shown on the pages (release.sh passes them)" bash -c "$(declare -f nsis); nsis_image='$nsis_image' TREE='$TREE' SCRATCH='$SCRATCH'; nsis -DCLIENTZIP=/s/client.zip -DENGINEZIP=/s/engine.zip -DENGINEEXE=/s/engine.exe -DENGINEEXE_SHA256=$(printf \"0%.0s\" $(seq 64)) -DENGINEEXE_SIZE=2 -DCLIENT_VERSION=0.12.0 -DENGINE_VERSION=0.9.0"
	check "benchy.nsi compiles with -WX: the engine alone (no client)" bash -c "$(declare -f nsis); nsis_image='$nsis_image' TREE='$TREE' SCRATCH='$SCRATCH'; nsis -DENGINEZIP=/s/engine.zip -DENGINEEXE=/s/engine.exe -DENGINEEXE_SHA256=$(printf \"0%.0s\" $(seq 64)) -DENGINEEXE_SIZE=2"
	check "the compiled installer exists" test -s "$SCRATCH/out/t.exe"
else
	SKIP=$((SKIP+1)); echo "  SKIP  the NSIS compile checks: $nsis_image is not built here (deploy/release.sh builds it on first use)"
fi

echo "== 0.11.0 (N1-N3): the installer's look, the step list, stop and restart"
UI=$TREE/deploy/windows-installer/benchy-ui.nsh
STOP=$TREE/deploy/windows-installer/stop-benchy.ps1
INST=$TREE/install.ps1
check "benchy-ui.nsh is the shared look: the macros the uninstaller will reuse, and BuiFunctions made for an 'un.' prefix" bash -c "grep -q 'macro BuiStrip' '$UI' && grep -q 'macro BuiRow ' '$UI' && grep -q 'macro BuiCallout' '$UI' && grep -q 'macro BuiShield' '$UI' && grep -q 'macro BuiFunctions _UN' '$UI' && grep -q 'Function \${_UN}BuiStepSet' '$UI' && grep -q '^!include \"benchy-ui.nsh\"' '$NSI' && grep -q 'insertmacro BuiFunctions \"\"' '$NSI'"
check "benchy-ui.nsh has a UTF-8 byte order mark (its glyphs are not ASCII) and the header bitmap exists" bash -c "[ \"\$(head -c3 '$UI' | xxd -p)\" = efbbbf ] && [ -s '$TREE/deploy/windows-installer/benchy-header.bmp' ]"
check "the step list is moved by install.ps1's progress file, read by a loop in the section's own thread (not a timer callback)" bash -c "grep -q 'Function RunInstallPs' '$NSI' && grep -q 'WaitForSingleObject(p r4, i 400)' '$NSI' && grep -q 'progress.txt' '$NSI' && ! grep -q 'NSD_CreateTimer InstFiles\|SetTimer' '$NSI'"
progress_steps() {
	local id
	grep -qF '[string]$ProgressFile' "$INST" || { echo "no -ProgressFile parameter"; return 1; }
	for id in check vcredist copy entries engines; do
		grep -qF "Write-InstallStep '$id' 'start'" "$INST" || { echo "no start line for $id"; return 1; }
		grep -qF "Write-InstallStep '$id' 'done'" "$INST" || grep -qF "Write-InstallStep '$id' \$(" "$INST" || { echo "no done line for $id"; return 1; }
	done
	grep -qF 'Result.code -ne 4 -and $script:CurrentStep' "$INST" || { echo "failure does not mark the running step (or marks the VC++ hand-off)"; return 1; }
	grep -qF "ProgressFile = Join-Path \$PayloadDir 'progress.txt'" "$INST" || { echo "no default from -PayloadDir"; return 1; }
}
check "install.ps1 -ProgressFile: step <id> start|done|fail for check, vcredist, copy, entries and engines; a failure marks the running step, but not the VC++ hand-off" progress_steps
checkrunning_readonly() {
	python3 -I - "$INST" <<'PY'
import sys
t = open(sys.argv[1]).read()
i = t.index('if ($CheckRunning)')
j = t.index('\texit 0\n}', i)
assert j < t.index('try\n{\n\tif (-not $env:LOCALAPPDATA -or -not $env:TEMP)'), 'the CheckRunning block must come before the install proper'
b = t[i:j]
for bad in ('Start-Transcript', 'Set-Benchy', 'robocopy', 'Remove-Item', 'New-Item', 'Stop-Process', 'Install-'):
    assert bad not in b, bad
assert 'Get-BenchyProcesses' in b
PY
}
check "install.ps1 -CheckRunning is read-only: it returns before the log, the preflight and every write except -ResultFile" checkrunning_readonly
check "stop-benchy.ps1 -Mode Info and -Mode Stop: quit-for-update on the client's pipe, an older client only ended when nothing runs in it (busy otherwise), the agent cancelled through the engine" bash -c "grep -q 'BENCHY-CLIENT-QUIT-FOR-UPDATE 1' '$STOP' && grep -q \"'Info', 'Stop'\" '$STOP' && grep -q \"state -eq 'busy'\" '$STOP' && grep -q 'cancel \$(\$State.agent_run)' '$STOP' && grep -q 'Wait-NoneOf' '$STOP'"
check "the installer closes Benchy instead of refusing: the Next click re-asks and stops on a console or an old client's run, the section runs StopRunning (always in /S), exit code 3 is kept for 'could not be closed'" bash -c "grep -q 'Function StopRunning' '$NSI' && grep -q 'StrCpy \$NotStartedCode 3' '$NSI' && grep -q 'Call StopRunning' '$NSI' && grep -q 'MessageBox MB_OK|MB_ICONINFORMATION \"\$StopBlock\"' '$NSI'"
check "the client goes back the way it was after a good install, unelevated (explorer.exe when this installer is elevated), also when the wizard is closed at the server page" bash -c "grep -q 'Function RestartBenchy' '$NSI' && grep -q 'IsUserAnAdmin' '$NSI' && grep -q 'Function .onGUIEnd' '$NSI' && grep -q 'BenchyClient.exe\" --tray' '$NSI'"
check "the unattended agent is updated by the engine's own setup-elevated --update (one prompt, ASFW_ANY hand-off) only when its box is ticked; a decline leaves the install a success" bash -c "grep -q 'agent setup-elevated --update' '$NSI' && grep -q 'AllowSetForegroundWindow(i -1)' '$NSI' && grep -q 'AgentResult \"not\"' '$NSI' && grep -q 'UpdateAgent = 1' '$NSI'"
check "stop-benchy.ps1 stays Windows PowerShell 5.1 syntax (no ??, ?. or ternary)" bash -c "! grep -nE '\?\?|\?\.|\? +\\$' '$STOP' && ! grep -nE ' -and\\b.*\?\?' '$STOP'"

echo "== 0.11.0 (N4): the uninstaller (source checks, the orchestrator and the BUSY answer in PowerShell; the Windows behavior is the Windows test VM pass)"
PWSH_IMAGE=mcr.microsoft.com/powershell:7.4-ubuntu-22.04
NUN=$TREE/deploy/windows-installer/benchy-uninstall.nsh
WIZ=$TREE/deploy/windows-installer/uninstall-wizard.ps1
check "the installer writes the uninstaller as %LOCALAPPDATA%\\Benchy\\uninstall.exe (the name the client looks for) and registers it, with /S for the quiet string, only when it could be written" bash -c "grep -q 'WriteUninstaller \"\$LOCALAPPDATA.Benchy.uninstall.exe\"' '$NSI' && grep -q 'UninstallString\" .\"\$LOCALAPPDATA.Benchy.uninstall.exe\".' '$NSI' && grep -q 'QuietUninstallString\" .\"\$LOCALAPPDATA.Benchy.uninstall.exe\" /S.' '$NSI' && grep -q 'Call WriteBenchyUninstaller' '$NSI' && awk '/^Function WriteBenchyUninstaller/,/^FunctionEnd/' '$NSI' | grep -q 'IfNot} \${Errors}'"
check "a reinstall deletes an older uninstall.exe (the GUI installer writes its own after install.ps1), and Apps & Features gets uninstall.exe only for a GUI registration, through Get-BenchyUninstallCommands" bash -c "grep -q \"Remove-Item -LiteralPath (Join-Path \\\$InstallDir 'uninstall.exe')\" '$INSTALL' && grep -q 'function Get-BenchyUninstallCommands' '$LIB' && grep -q 'Source -eq .gui. -and (Test-Path' '$LIB'"
check "the uninstaller stays RequestExecutionLevel user (the one prompt is the engine's relay), and the nsh does not ask for elevation" bash -c "grep -q '^RequestExecutionLevel user' '$NSI' && ! grep -qi 'runas\|RequestExecutionLevel admin' '$NUN'"
check "the uninstaller embeds the engine exe itself (ENGINEEXE, with its size and sha256 built in) with uninstall.ps1, install.ps1, the lib and stop-benchy.ps1, and release.sh passes the three after taking the exe out of the verified zip" bash -c "awk '/^Function un.Prepare/,/^FunctionEnd/' '$NUN' | grep -q 'oname=benchy-engine.exe.*ENGINEEXE' && ! awk '/^Function un.Prepare/,/^FunctionEnd/' '$NUN' | grep -q 'engine.zip' && awk '/^Function un.Prepare/,/^FunctionEnd/' '$NUN' | grep -q 'PAYLOAD}/uninstall.ps1' && awk '/^Function un.Prepare/,/^FunctionEnd/' '$NUN' | grep -q 'stop-benchy.ps1' && grep -q 'RequireDefine ENGINEEXE_SHA256' '$NSI' && grep -q 'DENGINEEXE_SHA256=\"\$engine_exe_sha\"' '$RELEASE' && grep -q 'DENGINEEXE_SIZE=\"\$engine_exe_size\"' '$RELEASE'"
engine_hold_check() {
	python3 -I - "$NUN" <<'PY'
import sys
t = open(sys.argv[1], encoding='utf-8').read()
h = t[t.index("Function un.HoldAndCheckEngine"):]
h = h[:h.index("\nFunctionEnd")]
# read-only, read sharing only, kept open: GENERIC_READ, FILE_SHARE_READ
assert 'CreateFileW(w "$PLUGINSDIR\\engine\\benchy-engine.exe", i 0x80000000, i 1,' in h
# the open is checked, then the final path, the size and the sha256 against the built-in values, and a mismatch refuses with exit 50
assert h.index("= -1") < h.index("GetFinalPathNameByHandleW") < h.index("GetFileSizeEx") < h.index("ENGINEEXE_SIZE") < h.index("-hashfile") < h.index("ENGINEEXE_SHA256")
assert "StrCpy $UnEngineExe $4" in h and "SetErrorLevel ${EXIT_OTHER}" in h and "Quit" in h
# nothing runs the engine before the check: Prepare holds and checks it before Info and Inventory
p = t[t.index("Function un.Prepare"):]
p = p[:p.index("\nFunctionEnd")]
assert p.index("Call un.HoldAndCheckEngine") < p.index("Call un.RunInfo") < p.index("Call un.RunInventory")
assert "CloseHandle(p $UnEngHandle" not in t
PY
}
check "the held engine copy is checked before anything runs it: the open, the final path, size and sha256 (a mismatch ends the wizard with exit 50), then Info and Inventory, and the handle stays open" engine_hold_check
check "Restart now enables SeShutdownPrivilege and calls ExitWindowsEx(EWX_REBOOT) itself (NSIS's Reboot did not restart), says so when Windows refuses, and does not quit first" bash -c "awk '/^Function un.RestartNow/,/^FunctionEnd/' '$NUN' | grep -q 'SeShutdownPrivilege' && awk '/^Function un.RestartNow/,/^FunctionEnd/' '$NUN' | grep -q 'AdjustTokenPrivileges' && awk '/^Function un.RestartNow/,/^FunctionEnd/' '$NUN' | grep -q 'ExitWindowsEx(i 0x12' && ! awk '/^Function un.RestartNow/,/^FunctionEnd/' '$NUN' | grep -qE '^[[:space:]]*(Reboot|Quit)\$'"
check "a declined prompt puts the client back as it was (window or tray, from stop.ini), in the wizard and in /S; the install page reads the account name into a register the Bui macros leave alone" bash -c "awk '/^Function un.WorkerResult/,/^FunctionEnd/' '$NUN' | grep -q 'Call un.RestartClient' && awk '/^Function un.RestartClient/,/^FunctionEnd/' '$NUN' | grep -q 'was_window' && grep -q 'ReadEnvStr \$R7 USERNAME' '$NSI' && grep -q 'installs for \$R7' '$NSI'"
check "run in place (_?=): the wizard is told its own exe, and the header says the exe is not counted and the caller deletes it" bash -c "grep -q 'InPlaceExe \"\$UnInPlaceExe\"' '$NUN' && grep -q 'caller deletes it' '$NSI'"
check "when the inventory fails the Removing rows still have their labels (the fallback writes them)" bash -c "awk '/^Function un.RunInventory/,/^FunctionEnd/' '$NUN' | grep -q 'WriteINIStr .*steps. .label2.'"
check "the pages run in order: What goes, Benchy is running, Removing, Windows said no, Done; the instfiles page is last and skipped in the wizard (it only lets /S run the section)" bash -c "a=\$(grep -n '^UninstPage custom un.WhatPageCreate' '$NSI' | cut -d: -f1); b=\$(grep -n '^UninstPage custom un.RunningPageCreate' '$NSI' | cut -d: -f1); c=\$(grep -n '^UninstPage custom un.RemovingPageCreate' '$NSI' | cut -d: -f1); d=\$(grep -n '^UninstPage custom un.DeclinedPageCreate' '$NSI' | cut -d: -f1); e=\$(grep -n '^UninstPage custom un.DonePageCreate' '$NSI' | cut -d: -f1); f=\$(grep -n 'MUI_UNPAGE_INSTFILES' '$NSI' | cut -d: -f1); [ \"\$a\" -lt \"\$b\" ] && [ \"\$b\" -lt \"\$c\" ] && [ \"\$c\" -lt \"\$d\" ] && [ \"\$d\" -lt \"\$e\" ] && [ \"\$e\" -lt \"\$f\" ] && awk '/^Function un.SkipInstFiles/,/^FunctionEnd/' '$NUN' | grep -q 'IfNot} \${Silent}'"
check "Windows said no: shown only after exit 5, Try again goes back to Removing (a posted page-back), no 'only my parts' choice, and the Back button is hidden" bash -c "awk '/^Function un.DeclinedPageCreate/,/^FunctionEnd/' '$NUN' | grep -q 'UnCode <> \${UN_EXIT_PERMISSION}' && awk '/^Function un.DeclinedPageLeave/,/^FunctionEnd/' '$NUN' | grep -q 'PostMessage(p \$HWNDPARENT, i 0x408, i -1' && ! grep -qi 'only my\|my parts\|only the parts in your account\" *$' '$NUN' && awk '/^Function un.DeclinedPageCreate/,/^FunctionEnd/' '$NUN' | grep -q 'ShowWindow \$R0 \${SW_HIDE}'"
check "the Windows prompt is allowed to come forward (AllowSetForegroundWindow ASFW_ANY) before the worker starts, and Cancel is refused while it removes" bash -c "awk '/^Function un.StartWorker/,/^FunctionEnd/' '$NUN' | grep -q 'AllowSetForegroundWindow(i -1)' && awk '/^Function un.AbortCheck/,/^FunctionEnd/' '$NUN' | grep -q 'UnRemoving = 1' && grep -q 'MUI_CUSTOMFUNCTION_UNABORT un.AbortCheck' '$NSI'"
check "silent: /KEEPRESULTS=0|1, /KEEPTESTS=0|1 and /LOG= are checked (exit 2 on a bad one), defaults keep results and remove tests, and the section runs the worker only with /S" bash -c "grep -q 'unGetCmdOption \$R0 \"/KEEPRESULTS=\"' '$NUN' && grep -q 'unGetCmdOption \$R0 \"/KEEPTESTS=\"' '$NUN' && grep -q 'unGetCmdOption \$R0 \"/LOG=\"' '$NUN' && grep -q 'StrCpy \$UnKeepResults 1' '$NUN' && grep -q 'StrCpy \$UnKeepTests 0' '$NUN' && awk '/^Section Uninstall/,/^SectionEnd/' '$NUN' | grep -q 'If} \${Silent}' && awk '/^Section Uninstall/,/^SectionEnd/' '$NUN' | grep -q 'SetErrorLevel \$UnCode'"
check "the .nsi header documents the uninstaller's exit codes 0, 1, 2, 3, 5, 3010, 40, 50 and its silent options" bash -c "for c in '0 ' '1 ' '2 ' '3 ' '5 ' '3010' '40 ' '50 '; do sed -n '/^; Uninstaller exit codes/,/^;   50 /p' '$NSI' | grep -q \"^;   \$c\" || exit 1; done; grep -q 'uninstall.exe /S \[/KEEPRESULTS=0|1\] \[/KEEPTESTS=0|1\] \[/LOG=<path>\]' '$NSI'"
order_in_wizard() {
	python3 -I - "$WIZ" <<'PY'
import sys
t = open(sys.argv[1]).read()
i = t.index("function Invoke-Run")
r = t[i:]
a = r.index("Mode', 'Stop'")            # 1 stop
b = r.index("Invoke-EngineReset $Plan")   # 2 the one prompt
c = r.index("Invoke-Script (Join-Path $EarlyDir 'uninstall.ps1') $PsArgs")  # 3
assert a < b < c, (a, b, c)
assert "$Code = 5" in r[b:c] and "return" in r[b:c], "a declined prompt must stop before uninstall.ps1"
assert "'-Quiet', '-AtReboot'" in r and "if ($ResetRan) { $PsArgs += '-FromGui' }" in r
assert "'--keep-data'" in t and "'--at-reboot'" in t and "'--uninstall'" in t
PY
}
check "uninstall-wizard.ps1 runs stop, then the engine's reset (the one prompt; a decline returns exit 5 before anything else), then uninstall.ps1 -Quiet -AtReboot (-FromGui only when the reset ran)" order_in_wizard
check "uninstall-wizard.ps1 and stop-benchy.ps1 stay Windows PowerShell 5.1 syntax (no ??, ?. or ternary)" bash -c "! grep -nE '\?\?|\?\.|\? +\\$' '$WIZ' '$STOP'"
if command -v podman >/dev/null && podman image exists "$PWSH_IMAGE" 2>/dev/null; then
	parse_run() {
		local out
		out=$(podman run --rm --network=none -v "$TREE/deploy/windows-installer:/d:ro" "$PWSH_IMAGE" pwsh -NoProfile -Command '
			foreach ($f in "/d/uninstall-wizard.ps1", "/d/stop-benchy.ps1") { $e = $null; $t = $null; [void][Management.Automation.Language.Parser]::ParseFile($f, [ref]$t, [ref]$e); if ($e.Count) { $e | ForEach-Object { "$f $($_.Message) line $($_.Extent.StartLineNumber)" }; exit 1 } }' 2>&1) || { echo "$out"; return 1; }
	}
	check "uninstall-wizard.ps1 and stop-benchy.ps1 parse" parse_run
	wizard_run() {
		local out rc=0 d; d=$(mktemp -d "$SCRATCH/uw.XXXXXX")
		out=$(podman run --rm --network=none -v "$d:/s" -v "$HERE:/t:ro" -v "$TREE/deploy/windows-installer:/d:ro" "$PWSH_IMAGE" bash /t/uninstall_wizard_test.sh /d/uninstall-wizard.ps1 /s 2>&1) || rc=$?
		printf '%s\n' "$out" | grep -E '^(ok|FAIL) ' | sed 's/^ok /        ok  /; s/^FAIL /        FAIL /'
		[ "$rc" = 0 ] && ! grep -q '^FAIL ' <<<"$out" && [ "$(grep -c '^ok ' <<<"$out")" -ge 25 ]
	}
	check "uninstall-wizard.ps1 Run and Inventory, with stand-in engine and scripts: order, keep switches, exit 0/3/5/3010/40/50, progress rows, result.ini, rows built from what is there" wizard_run
	busy_run() {
		local out rc=0 d; d=$(mktemp -d "$SCRATCH/sb.XXXXXX")
		out=$(timeout 280 podman run --rm --network=none -v "$d:/s" -v "$HERE:/t:ro" -v "$TREE/deploy/windows-installer:/d:ro" "$PWSH_IMAGE" bash /t/stop_benchy_test.sh /d/stop-benchy.ps1 /s 2>&1) || rc=$?
		printf '%s\n' "$out" | grep -E '^(ok|FAIL) ' | sed 's/^ok /        ok  /; s/^FAIL /        FAIL /'
		[ "$rc" = 0 ] && ! grep -q '^FAIL ' <<<"$out" && [ "$(grep -c "^ok " <<<"$out")" -ge 8 ]
	}
	check "stop-benchy.ps1 Stop: a client 0.12's BUSY answer ends nothing (result busy), OK quits by itself, an unknown answer is not a reason to kill, only no answer takes the older-client path" busy_run
	cr_run() {
		local out rc=0 d; d=$(mktemp -d "$SCRATCH/cr.XXXXXX")
		out=$(podman run --rm --network=none -v "$d:/s" -v "$HERE:/t:ro" -v "$TREE:/r:ro" "$PWSH_IMAGE" bash /t/checkrunning_test.sh /r/install.ps1 /s 2>&1) || rc=$?
		printf '%s\n' "$out" | grep -E '^(ok|FAIL) ' | sed 's/^ok /        ok  /; s/^FAIL /        FAIL /'
		[ "$rc" = 0 ] && ! grep -q '^FAIL ' <<<"$out" && [ "$(grep -c '^ok ' <<<"$out")" -ge 5 ]
	}
	check "install.ps1 -CheckRunning: a server job (the agent's child) with the client open is not a local run, status polls are ignored, a run nobody's agent started is" cr_run
	python3 -I - "$LIB" "$SCRATCH/fn2.ps1" <<'PY'
import re, sys
t = open(sys.argv[1]).read()
m = re.search(r'^function Get-BenchyUninstallCommands[\(\r\n ].*?^}\n', t, re.S | re.M)
assert m
open(sys.argv[2], 'w').write(m.group(0).replace('\\', '/'))
PY
	reg_run() {
		local out
		out=$(podman run --rm --network=none -v "$SCRATCH:/s:ro" "$PWSH_IMAGE" pwsh -NoProfile -Command '
			. /s/fn2.ps1; $d = "/tmp/bi"; New-Item -ItemType Directory -Force $d | Out-Null; $r = @()
			function Show($s) { $c = Get-BenchyUninstallCommands $d $s; ($c.Command -replace "powershell.exe .*", "ps1") + "|" + ($c.Quiet -replace "powershell.exe .*", "ps1") }
			$r += "none-gui:" + (Show "gui"); Set-Content "$d/uninstall.exe" "x"
			$r += "exe-gui:" + (Show "gui"); $r += "exe-bat:" + (Show "bat"); $r += "exe-updater:" + (Show ""); $r -join "`n"' 2>&1)
		printf '%s\n' "$out" | sed 's/^/        /'
		grep -qx 'none-gui:ps1|ps1' <<<"$out" && grep -qx 'exe-gui:"/tmp/bi/uninstall.exe"|"/tmp/bi/uninstall.exe" /S' <<<"$out" && grep -qx 'exe-bat:ps1|ps1' <<<"$out" && grep -qx 'exe-updater:ps1|ps1' <<<"$out"
	}
	check "registration: uninstall.exe only for a GUI install that has one; an install.bat install over a GUI install and an updater re-registration (no source) get uninstall.ps1" reg_run
else
	SKIP=$((SKIP+1)); echo "  SKIP  the uninstaller's PowerShell checks: $PWSH_IMAGE is not here"
fi

echo "== D5 (0.10.0): the server page, /SERVER= and the hint (source checks and the NSIS compile; the Windows behavior is the Windows test VM pass)"
SRVPS=$TREE/deploy/windows-installer/server-page.ps1
REGEX=$TREE/deploy/windows-installer/benchy-server-policy.example.reg
check "benchy.nsi: /SERVER= is read, and auto and none are the only words besides an address" bash -c "grep -q 'GetCmdOption \$R0 \"/SERVER=\"' '$NSI' && grep -q 'R1 == \"auto\"' '$NSI' && grep -q 'R1 == \"none\"' '$NSI'"
check "...an address is checked by IsServerAddress and refused with exit code 2 (Refuse), http:// never passes" bash -c "grep -q 'Call IsServerAddress' '$NSI' && grep -q 'Refuse \"/SERVER must be auto, none, or' '$NSI' && grep -q 'StrCpy \$6 \$0 8' '$NSI' && ! grep -q '\"http://\"' '$NSI'"
check "the pages run in order: install, progress, server, finish" bash -c "a=\$(grep -n '^Page custom InstallPageCreate' '$NSI' | cut -d: -f1); b=\$(grep -n 'MUI_PAGE_INSTFILES\$' '$NSI' | tail -1 | cut -d: -f1); c=\$(grep -n '^Page custom ServerPageCreate' '$NSI' | cut -d: -f1); d=\$(grep -n '^Page custom FinishPageCreate' '$NSI' | cut -d: -f1); [ \"\$a\" -lt \"\$b\" ] && [ \"\$b\" -lt \"\$c\" ] && [ \"\$c\" -lt \"\$d\" ]"
check "...and /SERVER= is still applied when the page is skipped for want of an engine" bash -c "awk '/^Function ServerPageCreate/,/^FunctionEnd/' '$NSI' | head -12 | grep -q 'Call ServerSilent'"
check "the server page is skipped (Abort) when the install failed or the engine is not there" bash -c "awk '/^Function ServerPageCreate/,/^FunctionEnd/' '$NSI' | head -12 | grep -c 'Abort' | grep -qx 2 && awk '/^Function ServerPageCreate/,/^FunctionEnd/' '$NSI' | grep -q 'Ok <> 1'"
check "the v1 field is off page 1 (no PhoromaticField), and /PHOROMATIC= still reaches install.ps1" bash -c "! grep -q 'PhoromaticField' '$NSI' && grep -q -- '-PhoromaticServer \"\$Phoromatic\"' '$NSI'"
check "the page writes only the hint: HKCU Software\\Benchy ServerHint and ServerHintSource (installer, or none for Don't connect)" bash -c "grep -q 'WriteRegStr HKCU \"Software.Benchy\" \"ServerHint\" \"\$0\"' '$NSI' && grep -q 'WriteRegStr HKCU \"Software.Benchy\" \"ServerHintSource\" \"installer\"' '$NSI' && grep -q 'WriteRegStr HKCU \"Software.Benchy\" \"ServerHintSource\" \"none\"' '$NSI' && grep -q 'DeleteRegValue HKCU \"Software.Benchy\" \"ServerHint\"' '$NSI'"
check "nothing here pairs: no enroll and no join code in the installer or its helper" bash -c "! grep -qi 'enroll' '$NSI' '$SRVPS' && ! grep -qi 'join-code\\|join_code' '$NSI' '$SRVPS'"
check "silent: without /SERVER nothing is searched (ServerSilent returns first)" bash -c "awk '/^Function ServerSilent/,/^FunctionEnd/' '$NSI' | sed -n 2,4p | grep -q 'ServerMode == \"\"' && awk '/^Function ServerSilent/,/^FunctionEnd/' '$NSI' | sed -n 2,5p | grep -q Return"
check "silent auto: the one-server and several-servers outcomes, and it never changes the exit code" bash -c "grep -q 'discovery: \$R1 servers found, none saved (use /SERVER=)' '$NSI' && grep -q 'discovery: no servers found, none saved' '$NSI' && ! awk '/^Function ServerSilent/,/^FunctionEnd/' '$NSI' | grep -q 'SetErrorLevel\\|StrCpy \$ExitCode'"
check "silent auto saves a policy server first, else exactly one found server" bash -c "awk '/^Function ServerSilent/,/^FunctionEnd/' '$NSI' | grep -q '\"policy\"' && awk '/^Function ServerSilent/,/^FunctionEnd/' '$NSI' | grep -q 'R1 = 1'"
check "the search runs hidden through the helper, 4 s limit, 6 s hard stop, only servers whose probe said ok" bash -c "grep -q \"'--json', 'discover', '--timeout', '4s'\" '$SRVPS' && grep -q 'WaitForExit(6000)' '$SRVPS' && grep -q \"probe -eq 'ok'\" '$SRVPS' && grep -q -- '-WindowStyle Hidden' '$SRVPS'"
check "the helper never contacts anything itself and writes no registry (it runs the engine and writes an INI)" bash -c "! grep -q 'Invoke-WebRequest\\|Invoke-RestMethod\\|HttpClient\\|Set-ItemProperty\\|New-ItemProperty' '$SRVPS'"
check "a typed v1 string goes through Benchy's own Phoromatic setter (Set-BenchyPhoromaticServer), the same pattern as install.ps1" bash -c "grep -q 'Set-BenchyPhoromaticServer' '$SRVPS' && grep -q 'Mode SetPhoromatic' '$NSI' && grep -q 'php_path' '$TREE/deploy/windows-installer/result-to-ini.ps1' && grep -q 'ReadINIStr \$PhpPath' '$NSI'"
check "the helper is unpacked with the installer, next to result-to-ini.ps1" hasf 'File "server-page.ps1"' "$NSI"
policy_reg_ok() {
	python3 -I - "$REGEX" <<'E'
import re, sys
t = open(sys.argv[1], encoding="utf-8").read()
lines = [l for l in t.splitlines() if l.strip() and not l.startswith(";")]
assert lines[0] == "Windows Registry Editor Version 5.00", lines[0]
assert lines[1] == r"[HKEY_LOCAL_MACHINE\SOFTWARE\Policies\Benchy]", lines[1]
vals = dict(re.fullmatch(r'"([A-Za-z]+)"=(.+)', l).groups() for l in lines[2:])
assert set(vals) == {"Server", "DisableDiscovery"}, vals
assert re.fullmatch(r'"https://[A-Za-z0-9.-]+:[0-9]+"', vals["Server"]), vals["Server"]
assert re.fullmatch(r"dword:0000000[01]", vals["DisableDiscovery"]), vals["DisableDiscovery"]
E
}
check "the .reg policy example: HKLM\\SOFTWARE\\Policies\\Benchy with Server (text, https) and DisableDiscovery (dword), nothing else" policy_reg_ok
check "the header documents /SERVER= and the policy key" bash -c "grep -q '^;   /SERVER=' '$NSI' && grep -q 'benchy-server-policy.example.reg' '$NSI'"

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
assert "$KeepClientData = $KeepS -and (Test-Path -LiteralPath $ClientData)" in t
assert t.count("$ClientData") >= 6
calls = re.findall(r"Remove-BenchyTree \$InstallDir[^\n]*", t)
assert len(calls) == 2 and "$KeepNow" in calls[0] and "$KeepLast" in calls[1], calls
assert "(Remove-BenchyTree $InstallDir $(if" not in t and "$(if ($KeepClientData) { $ClientData })" not in t, "no sub-expression that can hand a null to the keep list"
assert t.count("if ($KeepClientData) { $Keep") == 2
assert "-RemoveUserData" in t and "if ($RemoveUserData) { $KeepR = $false; $KeepS = $false; $KeepT = $false }" in t
PY
}
check "uninstall.ps1 keeps the BenchyClient settings unless the person (or -RemoveUserData) says to delete the user data" uninstall_keeps_client_settings
uninstall_n5_shape() {
	python3 - "$UNINSTALL" <<'PY'
import sys
t = open(sys.argv[1]).read()
# the engine's reset runs before anything of ours is removed, gated on hello's agent.reset, with the documented flags
i = t.index("'agent', 'reset', '--all', '--uninstall'")
assert "agent.reset" in t and "'--at-reboot'" in t and "'--keep-data'" in t
assert i < t.index("Remove-BenchyTree $InstallDir $KeepNow") < t.index("Remove-BenchyUninstallEntry")
# permission refusal is exit 5, queued leftovers exit 3010
assert "Stop-Benchy 5 " in t and "$Code = 3010" in t
# -Inventory is read-only and returns before the running check and any removal
assert t.index("if ($Inventory)") < t.index("$Running = @(Get-BenchyProcesses")
# the Run and StartupApproved values still go through Remove-BenchyAutostart
assert "Remove-BenchyAutostart" in t
PY
}
check "uninstall.ps1 (N5): engine reset first, exit 5 and 3010, read-only -Inventory" uninstall_n5_shape

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

echo "== Cygwin at startup (B-CY): what Linux can check of the Windows-only path"
MS=$TREE/pts-core/external-test-dependencies/dependency-handlers/microsoft_dependency_handler.php
MAIN=$TREE/pts-core/phoronix-test-suite.php
EXD=$TREE/pts-core/objects/client/pts_external_dependencies.php
JO=$TREE/pts-core/objects/pts_json_output.php
NET=$TREE/pts-core/objects/pts_network.php
check "the Cygwin files are checked in one place (bash, unzip, which), used by the install, the setup event and hello" bash -c "
	grep -qF \"CYGWIN_FILES = array('bash.exe' => 'bash', 'unzip.exe' => 'unzip', 'which.exe' => 'which')\" '$EXD' && [ \$(grep -c 'bash\\.exe' '$MS' '$MAIN' '$EXD' '$JO' | grep -v ':0' | wc -l) = 1 ] &&
	grep -qF 'cygwin_ready()' '$MS' && grep -qF 'cygwin_ready()' '$MAIN' && grep -qF 'cygwin_ready()' '$JO'"
check "only benchmark, install and setup may install at startup in JSON mode" bash -c "grep -qF \"INSTALLING_COMMANDS = array('benchmark', 'install', 'setup')\" '$JO' && grep -qF 'startup_handler(!\$json_mode || pts_json_output::installs_at_startup())' '$MAIN'"
check "the mdstool download is gone (a copy put there by hand is still read by system-info)" bash -c "! grep -q 'phoronix-test-suite.com/benchmark-files/mdstool' '$MS' && grep -q 'mdstool-cli.exe' '$TREE/pts-core/objects/phodevi/components/phodevi_system.php'"
check "both curl download paths have a low-speed limit (1 byte/s for 60 s)" bash -c "[ \$(grep -c 'CURLOPT_LOW_SPEED_LIMIT, 1)' '$NET') = 2 ] && [ \$(grep -c 'CURLOPT_LOW_SPEED_TIME, 60)' '$NET') = 2 ]"
cygwin_download_safe() {
	hasf ".part'" "$MS" && hasf "=== 'MZ'" "$MS" && hasf 'rename(' "$MS" || { echo "handler: no .part/MZ/rename"; return 1; }
	hasf "'system-info', 'setup'" "$JO" || { echo "setup is not in skip_startup_refresh"; return 1; }
	# the plain curl branch keeps curl_exec's result and deletes the file when it fails
	python3 -I - "$NET" <<'PY'
import sys
t = open(sys.argv[1]).read()
i = t.index("// XXX: with curl_multi_init")
j = t.index("public static function stream_download")
b = t[i:j]
assert "$ok = curl_exec($cr);" in b and "@unlink($download_to);" in b and "return false;" in b, "plain curl branch ignores failure"
PY
}
check "the Cygwin setup download goes to a .part file, is checked (MZ, size) and renamed; a failed plain curl download deletes its file; setup skips the repo refresh" cygwin_download_safe
check "the PHP files parse" bash -c "for f in '$MS' '$MAIN' '$EXD' '$JO' '$NET'; do php -l \$f >/dev/null || exit 1; done"

echo; echo "installer: $PASS passed, $FAIL failed, $SKIP skipped"
[ "$FAIL" -eq 0 ] || { printf '  failed: %s\n' "${FAILED[@]}"; exit 1; }
