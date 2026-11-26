#!/bin/bash
# Benchy release tooling. Run by Dave on his dev box, never on clients or the Phoromatic server.
#
# Usage: deploy/release.sh check <version>
#   Passes when the tag v<version> exists and PTS_VERSION in pts-core/pts-core.php at that tag is <version>.
#
# Release zips are built from the tag, so a tag that predates its version bump ships a Benchy that reports the old
# version (v0.7.0 points one commit before the 0.7.0 bump and reports 0.1.0). Run this before building or uploading
# anything for a release. Later updater phases add the bundle, signing and upload steps to this script.
#
# Usage: deploy/release.sh installer <version> [--out <dir>]
#   Builds the Windows GUI installer Benchy-Setup-<version>.exe from the tag v<version>: runs check, git-archives the
#   tag, takes the PHP zip pinned in that tree's runtime-pins.json (from ~/.cache/benchy-release/ when its size and
#   sha256 match, otherwise downloaded from the pin's upstream_urls; a mismatch fails the build), and runs makensis on
#   the tag's own deploy/windows-installer/benchy.nsi. Writes the exe, <exe>.sha256 and <exe>.buildinfo (makensis
#   version, commit, PHP zip, VC_redist) into the release bundle dir: --out, else $BENCHY_RELEASE_DIR/<version>, else
#   ~/benchy-release/<version>.
#   The VC_redist.x64.exe pinned in the same runtime-pins.json (vcredist) is not bundled, but its sha256 and size are
#   built into the exe (the wizard checks the downloaded file against them before it runs it elevated). So the build
#   gets it the same way (cache, else the pin's urls), checks size and sha256, and checks its Authenticode signature
#   with osslsigncode verify against Microsoft's roots in deploy/windows-installer/microsoft-root-cas.pem (offline, no
#   CRL fetch: the pinned hash is the real check, the signature is defence in depth); the signer must be Microsoft
#   Corporation. Any mismatch fails the build.
#
# Usage: deploy/release.sh installer --ref <commit-ish> <version> [--out <dir>]
#   DEV/TEST BUILDS ONLY, NEVER FOR A RELEASE. The same build from any commit (committed changes only; the working
#   tree is never used), with no tag check. <version> must equal PTS_VERSION at that commit. The exe is named
#   Benchy-Setup-<version>-dev-<sha>.exe and says "dev build <sha>" in its window caption; the default output dir is
#   ~/benchy-release/dev.
#   --client-zip <path> (only with --ref; refused for a tagged release): the desktop client zip to build in (Benchy-side
#   step B2 of the Qt client design), instead of the GitHub release asset. Either way it must match the client pin in the tree's runtime-pins.json
#   (windows-x64.client: version, artifact file/size/sha256), or the build fails. Without --client-zip, a tree that pins
#   a client gets it with `gh release download v<client version> -R dakotlyar/benchy-client` (the client repo is private: the gh
#   login on the dev box), cached in ~/.cache/benchy-release when it matches. A tree that pins no client builds an
#   installer without one ("Benchy" then opens the command prompt, as before 0.9).
#   RELEASE ORDER for a version that ships the client (a tagged build can only use a PUBLISHED client release; the
#   client's release.yml creates a DRAFT, and `gh release download` is not relied on to see drafts):
#     1. tag the client and publish its GitHub release (take it out of draft);
#     2. re-pin Benchy: runtime-pins.json windows-x64.client from that published asset (version, file, size, sha256);
#     3. tag Benchy, then run `release.sh installer <version>` (no --client-zip: it downloads and verifies the asset).
#   --engine-zip <path> (only with --ref; refused for a tagged release): the same for the native engine
#   (benchy-engine-<ver>-windows-x64.zip, the windows-x64.engine pin), which the installer puts in engine\\native\\ and
#   which carries the tray's server agent. Without it, a tree that pins an engine gets it with `gh release download
#   v<engine version> -R dakotlyar/benchy-engine`, cached like the client; a tree that pins none builds one without.
#   RELEASE ORDER, engine part: the engine's GitHub release must be PUBLISHED (not a draft) before Benchy is tagged;
#   a tagged build checks that, then downloads and verifies the asset against the pin.
#   --wrong-vcredist-hash (only with --ref): builds in an all-zero VC_redist sha256 instead of the pin's, so the wizard
#   rejects every VC_redist (exit code 22, no UAC prompt). For testing that path; the exe is named
#   Benchy-Setup-<version>-dev-<sha>-WRONG-VC-HASH.exe and says so in its caption. The pin is still verified.
#
# Usage: deploy/release.sh linux-assets <version> [--out <dir>]
#   The Linux client and engine that go on the public Benchy release, next to the installer (the client and engine repos
#   stay private, so the Benchy release is the only place outside users can download them). They are the three files
#   BenchyClient-<ver>-x86_64.AppImage, benchy-engine-<ver>-linux-x64.tar.gz and benchy-engine-<ver>-linux-arm64.tar.gz,
#   each with a .sha256 (plain sha256sum output, bare file name) beside it, written into the same release bundle dir as
#   the installer. `installer <version>` (a tagged release) does this itself; this command redoes just this part.
#   The tag's runtime-pins.json pins them: linux-x64.client, linux-x64.engine and linux-arm64.engine (version, artifact
#   file/size/sha256, as for the Windows pins). Each must be the same client or engine version as the windows-x64 pin it
#   ships beside, so a release never pairs a Linux binary with a Windows one of another version; otherwise it fails.
#   Each is fetched like the Windows client and engine (cache, else `gh release download` from the published upstream
#   release, which must not be a draft) and checked against its pin; the AppImage must be an ELF file and each tarball
#   must have benchy-engine at its root. A tag that pins none of them (older than 0.9.9) builds as before, without them.
#   release.sh does not create the GitHub release or upload anything: Dave attaches the files in the bundle dir by hand.
#   Every release (server-only ones included) attaches all three bundles to v<version>: benchy-engine-bundle-<sequence>.zip, benchy-components-bundle-<sequence>.zip and benchy-catalog-public-<generation>-<sequence>.zip. Check GitHub reads them from the release the server runs.
#
# Usage: deploy/release.sh check <version> [--catalog-bundle <path>]
#   Besides the tag and version check above, a tag that carries the server's signed-catalog key table
#   (pts-core/static/catalog/keys.json; older tags don't, and skip this) must pass the catalog release gate (E4.4, server
#   design section 10), and a failing check stops the release:
#     1. keys: `benchy server-catalog-keys`, run from a git archive of the tag, is compared with legal/catalog-keys.txt of
#        benchy-tests (main), fetched with `gh api`; any difference, or no gh, or no file, fails;
#     2. no test key: the key table holds only keys of that list, and no file outside tests/ and spec/ holds the id or the key
#        of a test key (the engine's fixture key, a key under tests/catalog/) or a catalog-test build;
#     3. bundle pin: when runtime-pins.json has a catalog.public pin (file, size, sha256, generation, sequence, urls), the
#        bundle is fetched (cache, then the urls; --catalog-bundle <path> the first time, before it is a release asset),
#        matched to the pin, and checked by `benchy server-catalog-check` from the archived tree. That is the fifth guard:
#        a public Benchy release can only carry a bundle whose signed tier is public, at the pinned generation and sequence;
#     4. protocol-v2 PIN: its first line must name an engine tag that exists and carries the commit it says, and none of its
#        local-field lines may be something that tag already has (the re-pin was forgotten).
#   The gate needs php, python3 and a logged-in gh. The installer step runs it too (a tagged release).
#
# Usage: deploy/release.sh catalog-bundle <version> [--catalog-bundle <path>] [--out <dir>]
#   Runs check, then puts the pinned public bundle (benchy-catalog-public-<generation>-<sequence>.zip) and a plain .sha256 in
#   the release bundle dir, for attaching to the public release. The deb and rpm builders take the same file with
#   --catalog-bundle and ship it in the package. A tag whose pins have no catalog pin builds as before.
#
#
# Usage: deploy/release.sh component-bundle <component> --version <v> [options]
#   Step one of a runtime-component release (PHP, the Windows PHP tree, the desktop client, the Cygwin set, VC++): it checks the
#   file against what its upstream publishes, writes the update's spec, and has `benchy-catalog component prepare` write the exact
#   bytes to sign (to-sign.bin), then tells Dave how to sign them with openssl on the machine that holds the engine key. Step two,
#   `release.sh component-bundle finish`, checks his signature against the engine key table and writes components.signed, the partial
#   bundle benchy-components-bundle-<sequence>.zip (the index and only the files this release adds) and the ledger to carry forward.
#   The upstream checks, each over HTTPS or against a published checksum file, and none of them is skipped:
#     php      windows.php.net's releases.json (the sha256 of the NTS x64 vs17 zip of that version) must equal the file's; --file may
#              give the zip, otherwise it is downloaded from the same site and checked
#     client   the client's GitHub release SHA256SUMS (gh release download v<version> -R dakotlyar/benchy-client) must list the
#              windows-x64 zip with the file's sha256
#     benchy   the Windows tree zip (--file) must be byte-equal to `git archive --format=zip v<version>` (the tag exists and says
#              PTS_VERSION <version>); its pts-core/static/update/cacert.pem must equal the tag's runtime-pins.json windows-x64.cacert
#              pin, and is compared with curl.se/ca/cacert.pem.sha256 (an older pinned bundle is a warning, a changed file an error)
#     vcredist the file (--file) must carry Microsoft Corporation's Authenticode signature (osslsigncode, offline) and match the pin
#     cygwin   refused until R3 builds the set and its checks
#   --version is the component's version (8.5.13, 0.15.0); --rebuild N marks a rebuild of the same version; --track names the series
#   (php 8.5, the others 0; vcredist has none); --requires comp=ver may be repeated; --generation is the engine key generation that
#   signs (default 1); --previous is the last release's components-ledger.json (none for the first release); --out defaults to
#   ~/benchy-release/components-<component>-<version>. BENCHY_CATALOG names the benchy-catalog binary (default: on PATH).
#   --test (tests only) lets BENCHY_COMPONENT_FETCH_DIR stand in for the upstream and prints a TEST banner; without --test that
#   variable is refused. Nothing here makes, reads or holds the private key.
#
# Usage: deploy/release.sh server-update-bundle prepare --version <v> [--debs <dir>] [--out <dir>] [--generation <n>] [--notes <text>]
#        deploy/release.sh server-update-bundle finish --request <dir> --sig <file> [--out <dir>]
#   The signed server update the web page installs (benchy-server-release/1, 0.16.2). Step one checks that the tag v<v> exists and says <v>, that <dir>
#   (default: the release bundle dir) holds benchy_<v>_all.deb and benchy-server_<v>_all.deb, reads the database schema the tag's code migrates to
#   (phoromatic_server_update::DB_SCHEMA), writes the index and the exact bytes to sign (request/to-sign.bin, the same bytes), and says how to sign them
#   with openssl on the machine that holds the engine key, in the same session as the component bundle. Step two checks Dave's signature against the
#   engine key table of the tag (a wrong or missing key stops it), writes server.signed (signature line + index) and benchy-server-update-<v>.zip
#   (server.signed and the two debs) with its .sha256, to attach to the public release. It takes no new key: it is the engine key (generation 1 by
#   default). Nothing here makes, reads or holds the private key.
#
# makensis and osslsigncode: the native ones when makensis is on PATH, otherwise the pinned container image
# localhost/benchy-nsis:<NSIS_VERSION>-<first 12 hex of the Containerfile's sha256> (rootless podman; built from
# deploy/windows-installer/Containerfile on first use, and again whenever the Containerfile changes, since that changes
# the tag). BENCHY_NSIS=native|container forces one; the container gives a known makensis version.

set -euo pipefail

NSIS_VERSION=3.11-1

die() { echo "release.sh: $*" >&2; exit 1; }
usage()
{
	cat >&2 <<EOF
Usage: $0 check <version> [--catalog-bundle <path>]        (e.g. $0 check 0.7.1)
       $0 installer <version> [--out <dir>] Windows GUI installer from tag v<version>
       $0 installer --ref <commit-ish> <version> [--out <dir>] [--client-zip <zip>] [--engine-zip <zip>] [--wrong-vcredist-hash]
                                                            DEV/TEST build from any commit, not for releases
       $0 linux-assets <version> [--out <dir>]              the Linux client AppImage and engine tarballs for the release
       $0 catalog-bundle <version> [--catalog-bundle <path>] [--out <dir>]   the pinned public catalog bundle for the release
       $0 check <version> [--catalog-bundle <path>]         (with the signed-catalog gate, for a tag that has the key table)
       $0 component-bundle <php|client|benchy|cygwin|vcredist> --version <v> [--track <t>] [--file <path>] [--rebuild <n>] [--requires comp=ver]... [--notes <text>] [--out <dir>] [--previous <ledger>] [--generation <n>]
       $0 component-bundle finish --request <dir> --sig <file> [--out <dir>] [--previous <ledger>]
                                                            a signed runtime-component bundle (PHP, client, tree, Cygwin, VC++) for the paired servers
       $0 server-update-bundle prepare --version <v> [--debs <dir>] [--out <dir>] [--generation <n>] [--notes <text>]
       $0 server-update-bundle finish --request <dir> --sig <file> [--out <dir>]
                                                            the signed server update (the two debs) a server administrator installs from the web page
EOF
	exit 2
}
check_version_format() { [[ $1 =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "version must look like 0.7.1, got '$1'"; }

REPO=$(cd "$(dirname "$0")/.." && pwd)

# PTS_VERSION in pts-core/pts-core.php at a commit
version_at() { git -C "$REPO" show "$1:pts-core/pts-core.php" | sed -n "s/^pts_define('PTS_VERSION', '\([^']*\)');.*/\1/p"; }

check_tag()
{
	local version=$1 tag=v$1 commit tagged
	commit=$(git -C "$REPO" rev-parse -q --verify "refs/tags/$tag^{commit}") || die "tag $tag does not exist"
	tagged=$(version_at "$commit")
	[ -n "$tagged" ] || die "could not find PTS_VERSION in pts-core/pts-core.php at $tag"
	[ "$tagged" = "$version" ] || die "PTS_VERSION at $tag ($(git -C "$REPO" rev-parse --short "$commit")) is $tagged, not $version: the tag predates the version bump. Tag the commit that sets PTS_VERSION to $version."
	echo "OK: $tag ($(git -C "$REPO" rev-parse --short "$commit")) has PTS_VERSION $version"
}

# Prints "<size> <sha256>" of a file
file_digest() { echo "$(stat -c %s "$1") $(sha256sum "$1" | cut -d' ' -f1)"; }

# Gets a pinned file into the cache (reused only when size and sha256 match) and prints its path:
# fetch_pinned <what> <cache file name> <size> <sha256> <url>...
fetch_pinned()
{
	local what=$1 name=$2 size=$3 sha=$4 cache=$HOME/.cache/benchy-release url file
	shift 4
	mkdir -p "$cache"
	file=$cache/$name
	if [ -f "$file" ] && [ "$(file_digest "$file")" = "$size $sha" ]; then
		echo "$what: $file (cached, matches the pin)" >&2
		echo "$file"
		return
	fi
	[ -f "$file" ] && echo "$what: cached $file does not match the pin, downloading it again" >&2
	for url in "$@"; do
		echo "$what: downloading $url" >&2
		if curl -fsSL --proto '=https' --max-filesize $((size + 1)) -o "$file.part" "$url"; then
			if [ "$(file_digest "$file.part")" = "$size $sha" ]; then
				mv "$file.part" "$file"
				echo "$what: $file (downloaded, matches the pin)" >&2
				echo "$file"
				return
			fi
			echo "$what: $url does not match the pin (got $(file_digest "$file.part"), pin $size $sha)" >&2
		fi
		rm -f "$file.part"
	done
	die "no URL gave a $what matching the pin ($name, $size bytes, sha256 $sha)"
}

# Reads the pins from a runtime-pins.json. Sets PHP_FILE PHP_SIZE PHP_SHA PHP_URLS (array) and VC_VERSION VC_FILE
# VC_SIZE VC_SHA VC_URLS (array).
read_pins()
{
	local pins=$1 php_urls vc_urls values rc=0
	# Exit code 3: the tree has no vcredist pin (it predates it)
	values=$(python3 -I -c '
import json, sys
php = json.load(open(sys.argv[1]))["windows-x64"]["php"]
if "vcredist" not in php:
    sys.exit(3)
vc = php["vcredist"]
print(php["artifact"]["file"]); print(php["artifact"]["size"]); print(php["artifact"]["sha256"])
print(" ".join(php["upstream_urls"]))
print(vc["version"]); print(vc["file"]); print(vc["size"]); print(vc["sha256"]); print(" ".join(vc["urls"]))' "$pins" 2>&1) || rc=$?
	[ "$rc" -ne 3 ] || die "this commit predates the VC_redist pin (runtime-pins.json has no vcredist); build it with that tag's own deploy/release.sh"
	[ "$rc" -eq 0 ] || die "could not read the PHP and VC_redist pins from $pins: $values"
	{
		read -r PHP_FILE; read -r PHP_SIZE; read -r PHP_SHA; read -r php_urls
		read -r VC_VERSION; read -r VC_FILE; read -r VC_SIZE; read -r VC_SHA; read -r vc_urls
	} <<<"$values"
	[[ $PHP_FILE =~ ^[A-Za-z0-9._-]+\.zip$ ]] || die "unexpected PHP zip name in the pin: '$PHP_FILE'"
	[[ $VC_FILE =~ ^[A-Za-z0-9._-]+\.exe$ ]] || die "unexpected VC_redist name in the pin: '$VC_FILE'"
	[[ $VC_VERSION =~ ^[0-9.]+$ ]] || die "unexpected VC_redist version in the pin: '$VC_VERSION'"
	# The size and hash go into benchy.nsi as defines, so they must be exactly a number and 64 lower-case hex digits
	[[ $VC_SIZE =~ ^[1-9][0-9]*$ ]] || die "unexpected VC_redist size in the pin: '$VC_SIZE'"
	[[ $VC_SHA =~ ^[0-9a-f]{64}$ ]] || die "the VC_redist sha256 in the pin must be 64 lower-case hex digits: '$VC_SHA'"
	# No word splitting or globbing of the URL lists: read -a splits on whitespace only
	read -r -a PHP_URLS <<<"$php_urls"
	read -r -a VC_URLS <<<"$vc_urls"
	[ ${#VC_URLS[@]} -gt 0 ] || die "the VC_redist pin has no urls"
}

# Reads an optional windows-x64 release-asset pin ("client" or "engine"). Prints version, file, size, sha256, one per
# line; returns 3 when the tree pins none.
read_asset_pin()
{
	python3 -I -c '
import json, sys
c = json.load(open(sys.argv[1]))["windows-x64"].get(sys.argv[2])
if c is None:
    sys.exit(3)
print(c["version"]); print(c["artifact"]["file"]); print(c["artifact"]["size"]); print(c["artifact"]["sha256"])' "$1" "$2" 2>&1
}

# Validates one pin's fields. check_asset_pin <label> <file-name regex> <version> <file> <size> <sha>
check_asset_pin()
{
	local label=$1 re=$2
	[[ $3 =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "unexpected $label version in the pin: '$3'"
	[[ $4 =~ $re ]] || die "unexpected $label zip name in the pin: '$4'"
	[[ $5 =~ ^[1-9][0-9]*$ ]] || die "unexpected $label zip size in the pin: '$5'"
	[[ $6 =~ ^[0-9a-f]{64}$ ]] || die "the $label sha256 in the pin must be 64 lower-case hex digits: '$6'"
}

# Reads the optional desktop client pin. Sets CLIENT_VERSION CLIENT_FILE CLIENT_SIZE CLIENT_SHA, all empty when the tree
# pins no client.
read_client_pin()
{
	local pins=$1 values rc=0
	values=$(read_asset_pin "$pins" client) || rc=$?
	CLIENT_VERSION= CLIENT_FILE= CLIENT_SIZE= CLIENT_SHA=
	[ "$rc" -ne 3 ] || return 0
	[ "$rc" -eq 0 ] || die "could not read the client pin from $pins: $values"
	{ read -r CLIENT_VERSION; read -r CLIENT_FILE; read -r CLIENT_SIZE; read -r CLIENT_SHA; } <<<"$values"
	check_asset_pin client '^BenchyClient-[A-Za-z0-9._-]+-windows-x64\.zip$' "$CLIENT_VERSION" "$CLIENT_FILE" "$CLIENT_SIZE" "$CLIENT_SHA"
}

# Reads the optional native engine pin. Sets ENGINE_VERSION ENGINE_FILE ENGINE_SIZE ENGINE_SHA, all empty when the tree
# pins no engine.
read_engine_pin()
{
	local pins=$1 values rc=0
	values=$(read_asset_pin "$pins" engine) || rc=$?
	ENGINE_VERSION= ENGINE_FILE= ENGINE_SIZE= ENGINE_SHA=
	[ "$rc" -ne 3 ] || return 0
	[ "$rc" -eq 0 ] || die "could not read the engine pin from $pins: $values"
	{ read -r ENGINE_VERSION; read -r ENGINE_FILE; read -r ENGINE_SIZE; read -r ENGINE_SHA; } <<<"$values"
	check_asset_pin engine '^benchy-engine-[A-Za-z0-9._-]+-windows-x64\.zip$' "$ENGINE_VERSION" "$ENGINE_FILE" "$ENGINE_SIZE" "$ENGINE_SHA"
}

# A pin block that carries a "_todo" key is not final (say, a client pin waiting for the client's release): a tagged release
# refuses to build from it. Every block of every platform is looked at. Prints the names of such blocks (platform.block).
pins_with_todo()
{
	python3 -I -c '
import json, sys
pins = json.load(open(sys.argv[1]))
print(" ".join("%s.%s" % (plat, k) for plat, blocks in pins.items() if isinstance(blocks, dict)
               for k, v in blocks.items() if isinstance(v, dict) and "_todo" in v))' "$1"
}

# A pinned GitHub release asset: the zip given on the command line when there is one, else the cache, else the release
# asset. Prints its path.
# fetch_release_asset <label> <flag> <repo> <version> <file> <size> <sha256> <given path or ""> <release|dev> [<noun, default zip>]
# An empty <flag> is for assets that cannot be given on the command line (the Linux ones).
# In release mode the GitHub release must be published (not a draft), whether the zip comes from the cache or not.
fetch_release_asset()
{
	local label=$1 flag=$2 repo=$3 version=$4 file=$5 size=$6 sha=$7 given=$8 mode=$9 noun=${10:-zip} cache=$HOME/.cache/benchy-release path
	local hint=${flag:+; pass $flag <path>}
	if [ -n "$given" ]; then
		[ -f "$given" ] || die "$flag $given: no such file"
		[ "$(file_digest "$given")" = "$size $sha" ] \
			|| die "$flag $given does not match the pinned $label $version (got $(file_digest "$given"), pin $size $sha)"
		echo "$label $noun: $given (matches the pin)" >&2
		echo "$given"
		return
	fi
	if [ "$mode" = release ]; then
		command -v gh >/dev/null || die "gh is needed to check that the $label's v$version release is published"
		[ "$(gh release view "v$version" -R "$repo" --json isDraft -q .isDraft 2>/dev/null)" = false ] \
			|| die "the $label's v$version release is not published (a draft, or not found): publish it first (release order)"
	fi
	mkdir -p "$cache"
	path=$cache/$file
	if [ -f "$path" ] && [ "$(file_digest "$path")" = "$size $sha" ]; then
		echo "$label $noun: $path (cached, matches the pin)" >&2
		echo "$path"
		return
	fi
	command -v gh >/dev/null || die "the $label $noun isn't cached and gh isn't installed$hint"
	rm -f "$path"
	echo "$label $noun: gh release download v$version -R $repo -p $file" >&2
	gh release download "v$version" -R "$repo" -p "$file" -D "$cache" >&2 \
		|| die "could not download $file from the $label's v$version release$hint"
	[ "$(file_digest "$path")" = "$size $sha" ] \
		|| die "the $label release asset does not match the pin (got $(file_digest "$path"), pin $size $sha)"
	echo "$path"
}

fetch_client() { fetch_release_asset client --client-zip dakotlyar/benchy-client "$CLIENT_VERSION" "$CLIENT_FILE" "$CLIENT_SIZE" "$CLIENT_SHA" "$1" "$2"; }
# The engine zip must have benchy-engine.exe at its root: install.ps1 looks for it there and refuses the zip otherwise, which the
# user would only find out at install time. The pin fixes the bytes, so this catches a wrong pin rather than a tampered file.
fetch_engine()
{
	local path
	path=$(fetch_release_asset engine --engine-zip dakotlyar/benchy-engine "$ENGINE_VERSION" "$ENGINE_FILE" "$ENGINE_SIZE" "$ENGINE_SHA" "$1" "$2") || return 1
	python3 -I -c '
import sys, zipfile
try:
    names = zipfile.ZipFile(sys.argv[1]).namelist()
except Exception as e:
    sys.exit("not a readable zip: %s" % e)
sys.exit(0 if "benchy-engine.exe" in names else "no benchy-engine.exe at the root of the zip")' "$path" \
		|| die "the engine zip $path is not laid out as install.ps1 expects (benchy-engine.exe and LICENSE at its root)"
	scan_engine_asset "$path" "$2"
	echo "$path"
}

# Reads the optional Linux release-asset pins: linux-x64 client, linux-x64 engine and linux-arm64 engine. Fills the parallel
# arrays LINUX_LABEL LINUX_REPO LINUX_VERSION LINUX_FILE LINUX_SIZE LINUX_SHA, all empty when the tree pins none (an older
# tree). A tree that pins some but not all, a pin that is not the version of the windows-x64 client or engine it ships
# beside, or one with an unexpected file name, size or sha256 is refused.
read_linux_pins()
{
	local pins=$1 values rc=0 label repo version file size sha
	values=$(python3 -I -c '
import json, re, sys
p = json.load(open(sys.argv[1]))
want = [("Linux client AppImage", "linux-x64", "client", "dakotlyar/benchy-client", "BenchyClient-%s-x86_64.AppImage"),
        ("Linux x64 engine", "linux-x64", "engine", "dakotlyar/benchy-engine", "benchy-engine-%s-linux-x64.tar.gz"),
        ("Linux arm64 engine", "linux-arm64", "engine", "dakotlyar/benchy-engine", "benchy-engine-%s-linux-arm64.tar.gz")]
pins = [p.get(plat, {}).get(kind) for _, plat, kind, _, _ in want]
rows = []
if all(x is None for x in pins):
    sys.exit(3)
for (label, plat, kind, repo, name), c in zip(want, pins):
    where = "%s.%s" % (plat, kind)
    if c is None:
        sys.exit("%s is not pinned, but the other Linux release assets are" % where)
    win = p["windows-x64"].get(kind)
    if win is None or win["version"] != c["version"]:
        sys.exit("%s pins %s, but windows-x64.%s pins %s: a release must not pair Linux and Windows files of different versions"
                 % (where, c["version"], kind, win and win["version"]))
    a = c["artifact"]
    if not re.fullmatch(r"[0-9]+\.[0-9]+\.[0-9]+", c["version"]): sys.exit("unexpected %s version in the pin: %r" % (where, c["version"]))
    if a["file"] != name % c["version"]: sys.exit("unexpected %s file name in the pin: %r (want %s)" % (where, a["file"], name % c["version"]))
    if not (isinstance(a["size"], int) and a["size"] > 0): sys.exit("unexpected %s size in the pin: %r" % (where, a["size"]))
    if not re.fullmatch(r"[0-9a-f]{64}", str(a["sha256"])): sys.exit("the %s sha256 in the pin must be 64 lower-case hex digits: %r" % (where, a["sha256"]))
    rows.append("\t".join([label, repo, c["version"], a["file"], str(a["size"]), a["sha256"]]))
print("\n".join(rows))' "$pins" 2>&1) || rc=$?
	LINUX_LABEL=() LINUX_REPO=() LINUX_VERSION=() LINUX_FILE=() LINUX_SIZE=() LINUX_SHA=()
	[ "$rc" -ne 3 ] || return 0
	[ "$rc" -eq 0 ] || die "could not read the Linux release-asset pins from $pins: $values"
	while IFS=$'\t' read -r label repo version file size sha; do
		LINUX_LABEL+=("$label") LINUX_REPO+=("$repo") LINUX_VERSION+=("$version") LINUX_FILE+=("$file") LINUX_SIZE+=("$size") LINUX_SHA+=("$sha")
	done <<<"$values"
}

# Checks the layout of a pinned Linux file: the AppImage is an ELF file, an engine tarball has benchy-engine at its root. The
# pin fixes the bytes, so this catches a wrong pin rather than a tampered file.
# check_linux_asset <file name> <path> <release|dev>
check_linux_asset()
{
	local file=$1 path=$2 mode=${3:-release} names
	case $file in
		*.AppImage)
			[ "$(head -c 4 "$path" | od -An -tx1 | tr -d ' \n')" = 7f454c46 ] || die "$file ($path) is not an ELF file"
			;;
		*.tar.gz)
			names=$(tar -tzf "$path") || die "$file ($path) is not a readable tarball"
			grep -qx 'benchy-engine' <<<"$names" || die "$file ($path) has no benchy-engine at the root of the tarball"
			scan_engine_asset "$path" "$mode"
			;;
	esac
}

# Puts the Linux assets fetched into <src> in the bundle dir <out>. First removes any earlier Linux asset there (the names carry the
# client and engine versions, the bundle dir is keyed by the Benchy version, so a re-pin would leave the old files to be attached
# by hand): only files with the three asset name patterns and their .sha256 go. Nothing is touched before the fetch has passed.
# install_linux_assets <src> <out>
install_linux_assets()
{
	local src=$1 out=$2 f
	mkdir -p "$out"
	for f in "$out"/BenchyClient-*-x86_64.AppImage "$out"/benchy-engine-*-linux-x64.tar.gz "$out"/benchy-engine-*-linux-arm64.tar.gz; do
		rm -f "$f" "$f.sha256"
	done
	for f in "$src"/*; do cp "$f" "$out/"; done
}

# Fetches the pinned Linux assets of a tree into <dest>, each with a <file>.sha256 beside it. The tree's pins file is the
# argument; does nothing (LINUX_FILE stays empty) when the tree pins none.
# fetch_linux_assets <runtime-pins.json> <release|dev> <dest>
fetch_linux_assets()
{
	local pins=$1 mode=$2 dest=$3 i path
	read_linux_pins "$pins"
	[ ${#LINUX_FILE[@]} -eq 0 ] || mkdir -p "$dest"
	for i in "${!LINUX_FILE[@]}"; do
		path=$(fetch_release_asset "${LINUX_LABEL[i]}" "" "${LINUX_REPO[i]}" "${LINUX_VERSION[i]}" "${LINUX_FILE[i]}" "${LINUX_SIZE[i]}" "${LINUX_SHA[i]}" "" "$mode" file)
		check_linux_asset "${LINUX_FILE[i]}" "$path" "$mode"
		cp "$path" "$dest/${LINUX_FILE[i]}"
		(cd "$dest" && sha256sum "${LINUX_FILE[i]}" > "${LINUX_FILE[i]}.sha256")
	done
}

# Checks the Authenticode signature of VC_redist with osslsigncode, offline, against the Microsoft roots in
# deploy/windows-installer/microsoft-root-cas.pem (Root Certificate Authority 2011 for the code signing chain, 2010
# for the timestamp; neither is in the Mozilla CA bundle). A timestamped signature is verified at its timestamp, so it
# stays valid after the signing certificate expires. -ignore-crl: no network in the container, and the pinned sha256
# already fixes the file. The signer of every signature must be Microsoft Corporation.
# verify_vcredist_signature <how: native|container> <file>
verify_vcredist_signature()
{
	local how=$1 file=$2 roots=$REPO/deploy/windows-installer/microsoft-root-cas.pem output signers
	[ -f "$roots" ] || die "no $roots"
	case $how in
		native)
			command -v osslsigncode >/dev/null || die "BENCHY_NSIS=native needs osslsigncode on PATH to check the VC_redist signature"
			output=$(osslsigncode verify -ignore-crl -CAfile "$roots" -TSA-CAfile "$roots" -in "$file" 2>&1) \
				|| { echo "$output" >&2; die "osslsigncode verify failed for VC_redist $file"; }
			;;
		container)
			output=$(podman run --rm --network=none --entrypoint osslsigncode \
				-v "$roots:/in/roots.pem:ro" -v "$file:/in/vc.exe:ro" "$NSIS_IMAGE" \
				verify -ignore-crl -CAfile /in/roots.pem -TSA-CAfile /in/roots.pem -in /in/vc.exe 2>&1) \
				|| { echo "$output" >&2; die "osslsigncode verify failed for VC_redist $file"; }
			;;
	esac
	# Every Subject in each "Signer's certificate:" section (indented lines, up to the next unindented heading) is a
	# signer; each must be Microsoft Corporation
	signers=$(awk '/^Signer.s certificate:/ { in_signer = 1; next } /^[^ \t]/ { in_signer = 0 } in_signer && /Subject:/ { sub(/^[ \t]*Subject: */, ""); print }' <<<"$output")
	[ -n "$signers" ] || { echo "$output" >&2; die "osslsigncode printed no signer for VC_redist $file"; }
	if grep -qvx '/C=US/ST=Washington/L=Redmond/O=Microsoft Corporation/CN=Microsoft Corporation' <<<"$signers"; then
		die "VC_redist $file is not signed by Microsoft Corporation: $signers"
	fi
	grep -q '^Succeeded$' <<<"$output" || { echo "$output" >&2; die "osslsigncode did not report success for VC_redist $file"; }
	echo "VC_redist: signature verified (osslsigncode, timestamped), signer Microsoft Corporation" >&2
}

# Sets NSIS_IMAGE, tagged by the NSIS version and the Containerfile's hash, so an image built from an older
# Containerfile is never reused; builds it when it does not exist yet
ensure_nsis_image()
{
	local containerfile=$REPO/deploy/windows-installer/Containerfile
	[ -f "$containerfile" ] || die "no $containerfile"
	NSIS_IMAGE=localhost/benchy-nsis:$NSIS_VERSION-$(sha256sum "$containerfile" | cut -c1-12)
	podman image exists "$NSIS_IMAGE" && return
	echo "makensis: building $NSIS_IMAGE (once)" >&2
	podman build -q -t "$NSIS_IMAGE" --build-arg "NSIS_VERSION=$NSIS_VERSION" \
		-f "$containerfile" "$REPO/deploy/windows-installer" >&2 \
		|| die "could not build $NSIS_IMAGE"
}

build_installer()
{
	local ref= version out= wrong_vc_hash= client_zip_arg= engine_zip_arg=
	if [ "${1:-}" = --ref ]; then
		[ $# -ge 3 ] || usage
		ref=$2
		shift 2
	fi
	[ $# -ge 1 ] || usage
	version=$1
	shift
	while [ $# -gt 0 ]; do
		case $1 in
			--out) [ $# -ge 2 ] || usage; out=$2; shift 2 ;;
			--wrong-vcredist-hash) wrong_vc_hash=1; shift ;;
			--client-zip) [ $# -ge 2 ] || usage; client_zip_arg=$(realpath "$2"); shift 2 ;;
			--engine-zip) [ $# -ge 2 ] || usage; engine_zip_arg=$(realpath "$2"); shift 2 ;;
			*) usage ;;
		esac
	done
	check_version_format "$version"
	[ -z "$wrong_vc_hash" ] || [ -n "$ref" ] || die "--wrong-vcredist-hash is for dev builds only (with --ref), never for a release"
	[ -z "$client_zip_arg" ] || [ -n "$ref" ] || die "--client-zip is for dev builds only (with --ref). A tagged release takes the client zip from the client's published GitHub release (release order: publish the client, re-pin Benchy from that asset, then tag Benchy)"

	[ -z "$engine_zip_arg" ] || [ -n "$ref" ] || die "--engine-zip is for dev builds only (with --ref). A tagged release takes the engine zip from the engine's published GitHub release (release order: publish the engine, re-pin Benchy from that asset, then tag Benchy)"

	local commit short name label= mode
	if [ -n "$ref" ]; then
		commit=$(git -C "$REPO" rev-parse -q --verify "$ref^{commit}") || die "$ref is not a commit"
		short=$(git -C "$REPO" rev-parse --short "$commit")
		[ "$(version_at "$commit")" = "$version" ] || die "PTS_VERSION at $ref ($short) is '$(version_at "$commit")', not $version"
		name=Benchy-Setup-$version-dev-$short.exe
		label="dev build $short"
		if [ -n "$wrong_vc_hash" ]; then
			name=Benchy-Setup-$version-dev-$short-WRONG-VC-HASH.exe
			label="$label, WRONG VC_redist hash"
		fi
		mode=dev
		out=${out:-${BENCHY_RELEASE_DIR:-$HOME/benchy-release}/dev}
		echo "*** DEV/TEST BUILD from $short: not a release, do not distribute ***" >&2
	else
		check_tag "$version"
		catalog_gate "$version" "" ""
		commit=$(git -C "$REPO" rev-parse "refs/tags/v$version^{commit}")
		name=Benchy-Setup-$version.exe
		mode=release
		out=${out:-${BENCHY_RELEASE_DIR:-$HOME/benchy-release}/$version}
	fi

	local work
	work=$(mktemp -d "${TMPDIR:-/tmp}/benchy-installer.XXXXXX")
	# shellcheck disable=SC2064
	trap "rm -rf '$work'" EXIT
	mkdir -p "$work/tree" "$work/out"
	# The same tree as the release zip (git archive of the commit), so the installer payload cannot drift from it
	git -C "$REPO" archive --format=tar "$commit" | tar -x -C "$work/tree"
	local nsi=$work/tree/deploy/windows-installer/benchy.nsi
	[ -f "$nsi" ] || die "$commit has no deploy/windows-installer/benchy.nsi (the installer script is taken from the tree being built)"

	# Before the NSIS image is built or looked for: a pin that is not final stops a release at once
	if [ "$mode" = release ]; then
		local todo
		todo=$(pins_with_todo "$work/tree/pts-core/static/update/runtime-pins.json")
		[ -z "$todo" ] || die "runtime-pins.json still has a _todo on: $todo. Re-pin it from the published release first (release order); a dev build (--ref) is not stopped by this"
	fi

	# The pinned Mozilla CA bundle (windows-x64.cacert) ships in the tree: the tree's copy and the pinned upstream file must both match the pin
	local cacert_values cacert_date= cacert_file cacert_size cacert_sha cacert_url cacert_cached
	cacert_values=$(python3 -I -c '
import json, sys
c = json.load(open(sys.argv[1]))["windows-x64"].get("cacert")
if c:
    print(c["date"]); print(c["artifact"]["file"]); print(c["artifact"]["size"]); print(c["artifact"]["sha256"]); print(c["upstream_urls"][0])' "$work/tree/pts-core/static/update/runtime-pins.json") \
		|| die "could not read the cacert pin from runtime-pins.json"
	if [ -n "$cacert_values" ]; then
		{ read -r cacert_date; read -r cacert_file; read -r cacert_size; read -r cacert_sha; read -r cacert_url; } <<<"$cacert_values"
		[[ $cacert_file =~ ^[A-Za-z0-9._-]+\.pem$ ]] && [[ $cacert_size =~ ^[1-9][0-9]*$ ]] && [[ $cacert_sha =~ ^[0-9a-f]{64}$ ]] || die "unexpected cacert pin in runtime-pins.json"
		cacert_cached=$(fetch_pinned "CA bundle $cacert_date" "$cacert_file" "$cacert_size" "$cacert_sha" "$cacert_url")
		[ "$(file_digest "$work/tree/pts-core/static/update/cacert.pem")" = "$cacert_size $cacert_sha" ] \
			|| die "pts-core/static/update/cacert.pem does not match the cacert pin ($cacert_file, $cacert_size bytes, sha256 $cacert_sha)"
	fi

	local how=${BENCHY_NSIS:-auto} makensis_version makensis_from
	if [ "$how" = auto ]; then
		if command -v makensis >/dev/null; then how=native; else how=container; fi
	fi
	case $how in
		native) command -v makensis >/dev/null || die "BENCHY_NSIS=native, but makensis is not on PATH" ;;
		container) ensure_nsis_image ;;
		*) die "BENCHY_NSIS must be native or container, not '$how'" ;;
	esac

	local cached zip=$work/in/php.zip vc_cached vc_baked_sha
	read_pins "$work/tree/pts-core/static/update/runtime-pins.json"
	cached=$(fetch_pinned "PHP zip" "$PHP_FILE" "$PHP_SIZE" "$PHP_SHA" "${PHP_URLS[@]}")
	vc_cached=$(fetch_pinned "VC_redist" "vcredist-$VC_VERSION-$VC_FILE" "$VC_SIZE" "$VC_SHA" "${VC_URLS[@]}")
	verify_vcredist_signature "$how" "$vc_cached"
	local client_cached= client_zip=$work/in/client.zip
	read_client_pin "$work/tree/pts-core/static/update/runtime-pins.json"
	if [ -n "$CLIENT_VERSION" ]; then
		client_cached=$(fetch_client "$client_zip_arg" "$mode")
	else
		[ -z "$client_zip_arg" ] || die "--client-zip given, but this tree pins no client (runtime-pins.json has no windows-x64.client)"
		echo "client zip: none (this tree pins no client)" >&2
	fi
	local engine_cached= engine_zip=$work/in/engine.zip
	read_engine_pin "$work/tree/pts-core/static/update/runtime-pins.json"
	if [ -n "$ENGINE_VERSION" ]; then
		engine_cached=$(fetch_engine "$engine_zip_arg" "$mode")
	else
		[ -z "$engine_zip_arg" ] || die "--engine-zip given, but this tree pins no engine (runtime-pins.json has no windows-x64.engine)"
		echo "engine zip: none (this tree pins no engine)" >&2
	fi
	# The Linux client and engine for the public release: fetched and checked now so a missing or wrong one stops the release
	# before makensis runs. Only a tagged release has them (a dev build is not published).
	if [ "$mode" = release ]; then
		fetch_linux_assets "$work/tree/pts-core/static/update/runtime-pins.json" release "$work/linux"
		[ ${#LINUX_FILE[@]} -gt 0 ] || echo "linux assets: none (this tree pins none)" >&2
	else
		LINUX_FILE=()
	fi
	vc_baked_sha=$VC_SHA
	if [ -n "$wrong_vc_hash" ]; then
		vc_baked_sha=0000000000000000000000000000000000000000000000000000000000000000
		echo "*** --wrong-vcredist-hash: building in sha256 $vc_baked_sha; this exe rejects every VC_redist ***" >&2
	fi
	# NSIS stores each file's mtime, so a copy dated like the tree's files (the commit time, as git archive does):
	# otherwise the exe would change whenever the cache is downloaded again
	mkdir -p "$work/in"
	cp "$cached" "$zip"
	touch -d "@$(git -C "$REPO" log -1 --format=%ct "$commit")" "$zip"

	local -a defines=(-DVERSION="$version" -DVCREDIST_SHA256="$vc_baked_sha" -DVCREDIST_SIZE="$VC_SIZE")
	[ -n "$label" ] && defines+=(-DBUILD_LABEL="$label")
	# The client: a third payload, dated like the PHP zip (see above)
	local -a client_mount=()
	if [ -n "$client_cached" ]; then
		cp "$client_cached" "$client_zip"
		touch -d "@$(git -C "$REPO" log -1 --format=%ct "$commit")" "$client_zip"
		client_mount=(-v "$client_zip:/in/client.zip:ro")
	fi
	# The engine: a fourth payload, dated the same way
	local -a engine_mount=()
	local engine_exe=$work/in/benchy-engine.exe engine_exe_sha= engine_exe_size=
	if [ -n "$engine_cached" ]; then
		cp "$engine_cached" "$engine_zip"
		touch -d "@$(git -C "$REPO" log -1 --format=%ct "$commit")" "$engine_zip"
		# The uninstaller carries the engine exe itself (the zip is already verified against the pin), and checks the copy it holds against
		# this size and sha256 before it runs anything: so nothing is unpacked at uninstall time by a process that runs unelevated and unchecked
		read -r engine_exe_sha engine_exe_size < <(python3 -I - "$engine_zip" "$engine_exe" <<'PY'
import hashlib, sys, zipfile
z = zipfile.ZipFile(sys.argv[1])
names = [i for i in z.infolist() if i.filename == 'benchy-engine.exe']
assert len(names) == 1, 'the engine zip must have exactly one benchy-engine.exe at its root'
data = z.read(names[0])
open(sys.argv[2], 'wb').write(data)
print(hashlib.sha256(data).hexdigest(), len(data))
PY
) || die "could not take benchy-engine.exe out of the engine zip"
		[ -n "$engine_exe_sha" ] || die "could not hash benchy-engine.exe"
		touch -d "@$(git -C "$REPO" log -1 --format=%ct "$commit")" "$engine_exe"
		engine_mount=(-v "$engine_zip:/in/engine.zip:ro" -v "$engine_exe:/in/benchy-engine.exe:ro")
	fi
	case $how in
		native)
			makensis_version=$(makensis -VERSION)
			makensis_from="native $(command -v makensis)"
			# -WX: any warning fails the build
			[ -n "$client_cached" ] && defines+=(-DCLIENTZIP="$client_zip" -DCLIENT_VERSION="$CLIENT_VERSION")
			[ -n "$engine_cached" ] && defines+=(-DENGINEZIP="$engine_zip" -DENGINE_VERSION="$ENGINE_VERSION" -DENGINEEXE="$engine_exe" -DENGINEEXE_SHA256="$engine_exe_sha" -DENGINEEXE_SIZE="$engine_exe_size")
			makensis -V2 -WX "${defines[@]}" -DPAYLOAD="$work/tree" -DPHPZIP="$zip" -DOUTFILE="$work/out/$name" "$nsi" >&2 \
				|| die "makensis failed"
			;;
		container)
			makensis_version=$(podman run --rm --network=none "$NSIS_IMAGE" -VERSION)
			makensis_from="container $NSIS_IMAGE ($(podman image inspect "$NSIS_IMAGE" --format '{{.Id}}'))"
			[ -n "$client_cached" ] && defines+=(-DCLIENTZIP=/in/client.zip -DCLIENT_VERSION="$CLIENT_VERSION")
			[ -n "$engine_cached" ] && defines+=(-DENGINEZIP=/in/engine.zip -DENGINE_VERSION="$ENGINE_VERSION" -DENGINEEXE=/in/benchy-engine.exe -DENGINEEXE_SHA256="$engine_exe_sha" -DENGINEEXE_SIZE="$engine_exe_size")
			podman run --rm --network=none \
				-v "$work/tree:/work/tree:ro" -v "$zip:/in/php.zip:ro" "${client_mount[@]}" "${engine_mount[@]}" -v "$work/out:/work/out" \
				"$NSIS_IMAGE" -V2 -WX "${defines[@]}" -DPAYLOAD=/work/tree -DPHPZIP=/in/php.zip -DOUTFILE="/work/out/$name" \
				/work/tree/deploy/windows-installer/benchy.nsi >&2 \
				|| die "makensis failed"
			;;
	esac
	[ -s "$work/out/$name" ] || die "makensis wrote no $name"

	mkdir -p "$out"
	cp "$work/out/$name" "$out/$name"
	(cd "$out" && sha256sum "$name" > "$name.sha256")
	local i
	[ ${#LINUX_FILE[@]} -eq 0 ] || install_linux_assets "$work/linux" "$out"
	{
		echo "file: $name"
		echo "mode: $mode"
		echo "version: $version"
		echo "commit: $commit"
		echo "php_zip: $(basename "$cached") $(file_digest "$zip")"
		[ -z "$cacert_date" ] || echo "cacert: $cacert_date $cacert_file $cacert_size $cacert_sha"
		echo "vcredist: $VC_VERSION $VC_FILE $(file_digest "$vc_cached") (signature verified)"
		if [ -n "$client_cached" ]; then echo "client_zip: $CLIENT_VERSION $CLIENT_FILE $(file_digest "$client_zip")"; else echo "client_zip: none"; fi
		if [ -n "$engine_cached" ]; then echo "engine_zip: $ENGINE_VERSION $ENGINE_FILE $(file_digest "$engine_zip")"; else echo "engine_zip: none"; fi
		for i in "${!LINUX_FILE[@]}"; do echo "linux_asset: ${LINUX_VERSION[i]} ${LINUX_FILE[i]} ${LINUX_SIZE[i]} ${LINUX_SHA[i]}"; done
		[ ${#LINUX_FILE[@]} -gt 0 ] || echo "linux_asset: none"
		[ -n "$wrong_vc_hash" ] && echo "vcredist_baked_sha256: $vc_baked_sha (WRONG on purpose: --wrong-vcredist-hash)"
		echo "makensis: $makensis_version"
		echo "makensis_from: $makensis_from"
		echo "sha256: $(cut -d' ' -f1 "$out/$name.sha256")"
		echo "built: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
	} > "$out/$name.buildinfo"
	echo "Built $out/$name ($(stat -c %s "$out/$name") bytes, makensis $makensis_version)"
	cat "$out/$name.sha256"
	for i in "${!LINUX_FILE[@]}"; do echo "Also ${LINUX_FILE[i]} ($(stat -c %s "$out/${LINUX_FILE[i]}") bytes)"; cat "$out/${LINUX_FILE[i]}.sha256"; done
}

# Just the Linux assets of a tagged release, into the release bundle dir (see the header)
build_linux_assets()
{
	local version out= commit work pins todo
	[ $# -ge 1 ] || usage
	version=$1
	shift
	while [ $# -gt 0 ]; do
		case $1 in
			--out) [ $# -ge 2 ] || usage; out=$2; shift 2 ;;
			*) usage ;;
		esac
	done
	check_version_format "$version"
	check_tag "$version"
	commit=$(git -C "$REPO" rev-parse "refs/tags/v$version^{commit}")
	out=${out:-${BENCHY_RELEASE_DIR:-$HOME/benchy-release}/$version}
	work=$(mktemp -d "${TMPDIR:-/tmp}/benchy-linux-assets.XXXXXX")
	# shellcheck disable=SC2064
	trap "rm -rf '$work'" EXIT
	pins=$work/runtime-pins.json
	git -C "$REPO" show "$commit:pts-core/static/update/runtime-pins.json" > "$pins" 2>/dev/null \
		|| die "v$version has no pts-core/static/update/runtime-pins.json: it predates the pins, nothing to attach"
	todo=$(pins_with_todo "$pins")
	[ -z "$todo" ] || die "runtime-pins.json still has a _todo on: $todo. Re-pin it from the published release first (release order)"
	fetch_linux_assets "$pins" release "$work/linux"
	[ ${#LINUX_FILE[@]} -gt 0 ] || die "v$version pins no Linux release assets (runtime-pins.json has no linux-x64.client, linux-x64.engine or linux-arm64.engine): it predates them, nothing to attach"
	# Into the bundle dir only when all of them passed
	local i
	install_linux_assets "$work/linux" "$out"
	for i in "${!LINUX_FILE[@]}"; do
		echo "$out/${LINUX_FILE[i]} ($(stat -c %s "$out/${LINUX_FILE[i]}") bytes)"
		cat "$out/${LINUX_FILE[i]}.sha256"
	done
}

# ---- E4.4 catalog release gate (server design section 10) ----

# Reads the optional catalog.public pin of a runtime-pins.json. Prints file, size, sha256, generation, sequence and the urls, one per
# line (the urls on one line, space separated); returns 3 when the tree pins no catalog. Anything else under "catalog" than "public"
# is refused: a private bundle is never packaged or released.
read_catalog_pin()
{
	python3 -I -c '
import json, re, sys
p = json.load(open(sys.argv[1]))
c = p.get("catalog")
if c is None:
    sys.exit(3)
if not isinstance(c, dict) or sorted(c) != ["public"] or not isinstance(c["public"], dict):
    sys.exit("runtime-pins.json: catalog may hold only a public pin (a private bundle is never released)")
c = c["public"]
if sorted(c) != ["file", "generation", "sequence", "sha256", "size", "urls"]:
    sys.exit("runtime-pins.json: catalog.public must have exactly file, size, sha256, generation, sequence and urls, got %s" % sorted(c))
m = re.fullmatch(r"benchy-catalog-public-([1-9][0-9]*)-([1-9][0-9]*)\.zip", str(c["file"]))
if not m:
    sys.exit("runtime-pins.json: catalog.public.file must be benchy-catalog-public-<generation>-<sequence>.zip, got %r" % c["file"])
for k in ("size", "generation", "sequence"):
    if not (isinstance(c[k], int) and not isinstance(c[k], bool) and c[k] > 0):
        sys.exit("runtime-pins.json: catalog.public.%s must be a positive number, got %r" % (k, c[k]))
if c["size"] > 128 * 1024 * 1024:
    sys.exit("runtime-pins.json: catalog.public.size is over the 128 MiB import limit")
if (c["generation"], c["sequence"]) != (int(m.group(1)), int(m.group(2))):
    sys.exit("runtime-pins.json: catalog.public.file names %s-%s but the pin says generation %s, sequence %s" % (m.group(1), m.group(2), c["generation"], c["sequence"]))
if not re.fullmatch(r"[0-9a-f]{64}", str(c["sha256"])):
    sys.exit("runtime-pins.json: catalog.public.sha256 must be 64 lower-case hex digits")
if not (isinstance(c["urls"], list) and c["urls"] and all(isinstance(u, str) and u.startswith("https://") and " " not in u for u in c["urls"])):
    sys.exit("runtime-pins.json: catalog.public.urls must be a list of https URLs")
print(c["file"]); print(c["size"]); print(c["sha256"]); print(c["generation"]); print(c["sequence"]); print(" ".join(c["urls"]))' "$1" 2>&1
}

# The test-key and test-build scan of the gate (check 2). catalog_scan <tree> <expected key list file>
catalog_scan()
{
	python3 -I -c '
import base64, hashlib, json, os, re, sys
tree, expected = sys.argv[1:3]
ids = lambda spki: hashlib.sha256(base64.b64decode(spki, validate=True)).hexdigest()[:32]
allowed = set(re.findall(r"^generation [0-9]+ ([0-9a-f]{32})", open(expected).read(), re.M))
if not allowed:
    sys.exit("the key list of benchy-tests has no generation lines")
table = json.load(open(os.path.join(tree, "pts-core/static/catalog/keys.json")))
for k in table["keys"]:
    if ids(k["spki"]) not in allowed:
        sys.exit("the key table holds generation %s (key id %s), which is not in benchy-tests legal/catalog-keys.txt: a test key?" % (k["generation"], ids(k["spki"])))
# keys that must never ship: the engine fixture key and any key under tests/catalog
bad = {"d98e6e86a8584efd" "075cc0fc8155f980": "the engine fixture key"}
spkis = {}
for root, dirs, files in os.walk(os.path.join(tree, "tests", "catalog")):
    for f in files:
        if f.endswith(".spki"):
            s = open(os.path.join(root, f)).read().strip()
            bad[ids(s)] = "the test key " + os.path.join(root, f)[len(tree) + 1:]
            spkis[s] = bad[ids(s)]
marker = b"BENCHY_CATALOG" b"TEST_SPKI"
word = "catalog" "test"
for root, dirs, files in os.walk(tree):
    rel = os.path.relpath(root, tree)
    if rel == ".":
        dirs[:] = [d for d in dirs if d not in ("tests", "spec")]
    for f in files:
        path = os.path.join(root, f)
        shown = os.path.relpath(path, tree)
        if word in f.lower():
            sys.exit("%s: a test-build artifact (its name has %s)" % (shown, word))
        data = open(path, "rb").read()
        if marker in data:
            sys.exit("%s: holds the test-build key variable, so it is or contains a test build" % shown)
        for i, what in bad.items():
            if i.encode() in data:
                sys.exit("%s: holds the id of %s" % (shown, what))
        for s, what in spkis.items():
            if s.encode() in data:
                sys.exit("%s: holds the key of %s" % (shown, what))' "$1" "$2" 2>&1
}

# The protocol-v2 PIN check of the gate (check 4). catalog_pin_check <tree> <work dir>
catalog_pin_check()
{
	local tree=$1 work=$2 pin=$1/pts-core/static/protocol-v2/PIN tag commit want file first
	[ -f "$pin" ] || die "$pin: the tag has no protocol-v2 PIN"
	first=$(python3 -I -c '
import re, sys
first = open(sys.argv[1]).readline()
m = re.match(r"Copied from the benchy-engine repository, tag (v[0-9]+\.[0-9]+\.[0-9]+) \(commit ([0-9a-f]{40})\)", first)
if not m:
    sys.exit("the first line of pts-core/static/protocol-v2/PIN must name an engine tag and its commit (\"Copied from the benchy-engine repository, tag vX.Y.Z (commit <40 hex>)\"), got: " + first.strip()[:120])
print(m.group(1), m.group(2))' "$pin" 2>&1) || die "protocol-v2 PIN: $first"
	read -r tag commit <<<"$first"
	want=$(gh api "repos/dakotlyar/benchy-engine/commits/$tag" -q .sha 2>/dev/null) || die "protocol-v2 PIN: gh could not find the engine tag $tag in dakotlyar/benchy-engine (the gate fails closed)"
	[ "$want" = "$commit" ] || die "protocol-v2 PIN names engine $tag at commit $commit, but the tag is at $want"
	# A local-field line is a field the copy has that the tag's own schema doesn't. A tag that already has it means the re-pin was forgotten.
	# One parse serves both steps, and a local-field line that does not parse (a trailing note or space, a CR) fails the gate rather than being skipped.
	python3 -I -c '
import re, sys
out = []
for n, line in enumerate(open(sys.argv[1], newline="").read().split("\n"), 1):
    if line.startswith("local-field:"):
        m = re.fullmatch(r"local-field: ([A-Za-z0-9._-]+) ([^\s.][^\s]*)", line)
        if not m:
            sys.exit("PIN line %d: a local-field line must be exactly \"local-field: <file> <dotted.path>\", got %r" % (n, line[:120]))
        out.append(m.group(1) + " " + m.group(2))
print("\n".join(out))' "$pin" > "$work/fields.txt" 2>"$work/fields.err" || die "protocol-v2 PIN: $(cat "$work/fields.err")"
	for file in $(cut -d' ' -f1 "$work/fields.txt" | sort -u); do
		gh api "repos/dakotlyar/benchy-engine/contents/spec/protocol-v2/$file?ref=$tag" -H 'Accept: application/vnd.github.raw' > "$work/engine-$file" 2>/dev/null \
			|| die "protocol-v2 PIN: gh could not read spec/protocol-v2/$file at $tag"
	done
	python3 -I -c '
import json, os, sys
fields, work, tag = sys.argv[1:4]
for line in open(fields).read().split("\n"):
    if not line:
        continue
    f, path = line.split(" ", 1)
    node = json.load(open(os.path.join(work, "engine-" + f)))
    for part in path.split("."):
        if isinstance(node, dict) and part in node:
            node = node[part]
        else:
            break
    else:
        sys.exit("protocol-v2 PIN lists the local change %s %s, but engine %s already has it: the re-pin to that tag was forgotten" % (f, path, tag))' "$work/fields.txt" "$work" "$tag" 2>&1 || die "protocol-v2 PIN check failed"
	echo "OK: protocol-v2 PIN names engine $tag ($commit); no local change is already in that tag"
}

# The catalog release gate for the tag of <version> (see the header). <given bundle path> may be empty; <out dir> may be empty: when it
# is not, the checked bundle is copied there with a .sha256 once every check has passed. Tags without a server key table skip it.
# The engine-key gate (engine self-update): the tag's server engine key table must not be the development one. Fails closed: anything but
# "test_keys": false is a refusal. Tags without the table (before 0.13.0) skip it. engine_keys_gate <version>
engine_keys_gate()
{
	local tag=v$1 commit text
	commit=$(git -C "$REPO" rev-parse "refs/tags/$tag^{commit}")
	git -C "$REPO" cat-file -e "$commit:pts-core/static/engine/keys.json" 2>/dev/null || { echo "engine key gate: skipped ($tag has no server engine key table)"; return 0; }
	text=$(git -C "$REPO" show "$commit:pts-core/static/engine/keys.json")
	printf '%s' "$text" | tr -d '\n' | grep -Eq '"test_keys"[[:space:]]*:[[:space:]]*false[[:space:]]*[,}]' \
		|| die "$tag ships the development engine key table (pts-core/static/engine/keys.json does not say test_keys false): run deploy/embed-engine-keys.sh with the real public keys"
	echo "OK: the engine key table of $tag is not the development one"
}

# catalog_gate <version> <given bundle or ""> <out dir or "">
catalog_gate()
{
	local version=$1 given=$2 out=$3 tag=v$1 commit
	engine_keys_gate "$version"
	commit=$(git -C "$REPO" rev-parse "refs/tags/$tag^{commit}")
	if ! git -C "$REPO" cat-file -e "$commit:pts-core/static/catalog/keys.json" 2>/dev/null; then
		[ -z "$given$out" ] || die "$tag has no server catalog key table (it predates E4.4): there is no catalog bundle to build"
		echo "catalog gate: skipped ($tag has no server catalog key table: it predates E4.4)"
		return 0
	fi
	command -v php >/dev/null || die "the catalog gate needs php on PATH (it runs benchy server-catalog-keys from the tag)"
	command -v gh >/dev/null || die "the catalog gate needs gh (the key list comes from benchy-tests): it fails closed without it"
	(
		set -euo pipefail
		work=$(mktemp -d "${TMPDIR:-/tmp}/benchy-catalog-gate.XXXXXX")
		trap 'rm -rf "$work"' EXIT
		mkdir -p "$work/tree" "$work/home"
		git -C "$REPO" archive --format=tar "$commit" | tar -x -C "$work/tree"
		# 1. keys
		gh api repos/dakotlyar/benchy-tests/contents/legal/catalog-keys.txt -H 'Accept: application/vnd.github.raw' > "$work/expected.txt" 2>"$work/gh.err" \
			|| die "could not fetch legal/catalog-keys.txt from dakotlyar/benchy-tests: $(head -c 300 "$work/gh.err") (the gate fails closed)"
		[ -s "$work/expected.txt" ] || die "legal/catalog-keys.txt of benchy-tests is empty"
		(cd "$work/tree" && env -u PTS_USER_PATH_OVERRIDE -u PHP_BIN HOME="$work/home" sh ./benchy server-catalog-keys) > "$work/actual.txt" 2>"$work/keys.err" \
			|| die "benchy server-catalog-keys failed at $tag: $(head -c 400 "$work/keys.err")"
		diff -u "$work/expected.txt" "$work/actual.txt" > "$work/keys.diff" \
			|| { cat "$work/keys.diff" >&2; die "the server's key table at $tag differs from benchy-tests legal/catalog-keys.txt (expected is the - side)"; }
		echo "OK: the server key table matches benchy-tests legal/catalog-keys.txt ($(wc -l < "$work/expected.txt") lines)"
		# 2. no test key, no test build
		catalog_scan "$work/tree" "$work/expected.txt" || die "test key check failed"
		echo "OK: no test key or test build is in $tag outside tests/ and spec/"
		# 4. the protocol-v2 PIN
		catalog_pin_check "$work/tree" "$work"
		# 3. the bundle pin and the fifth guard
		local rc=0 values
		values=$(read_catalog_pin "$work/tree/pts-core/static/update/runtime-pins.json") || rc=$?
		if [ "$rc" -eq 3 ]; then
			[ -z "$given$out" ] && echo "catalog gate: no catalog bundle is pinned in $tag (it builds as before)" \
				|| echo "$tag pins no catalog bundle (runtime-pins.json has no catalog.public): there is nothing to build"
			exit 0
		fi
		[ "$rc" -eq 0 ] || die "$values"
		local file size sha gen seq urls bundle line
		{ read -r file; read -r size; read -r sha; read -r gen; read -r seq; read -r urls; } <<<"$values"
		local -a url_list
		read -r -a url_list <<<"$urls"
		if [ -n "$given" ]; then
			[ -f "$given" ] || die "--catalog-bundle $given: no such file"
			[ "$(file_digest "$given")" = "$size $sha" ] || die "--catalog-bundle $given does not match the pin (got $(file_digest "$given"), pin $size $sha)"
			bundle=$given
			mkdir -p "$HOME/.cache/benchy-release"
			cp "$given" "$HOME/.cache/benchy-release/$file"
		else
			bundle=$(fetch_pinned "catalog bundle" "$file" "$size" "$sha" "${url_list[@]}") \
				|| { echo "(before the bundle is a release asset anywhere, pass --catalog-bundle <path>)" >&2; exit 1; }
		fi
		line=$(cd "$work/tree" && env -u PTS_USER_PATH_OVERRIDE -u PHP_BIN HOME="$work/home" sh ./benchy server-catalog-check "$bundle" 2>&1) \
			|| die "the pinned catalog bundle is refused by the server's own check at $tag: $line"
		line=$(tail -1 <<<"$line")
		set -- $line
		[ "${1:-}" = public ] || die "the pinned catalog bundle is the ${1:-unknown} tier, not public: a public release can only carry a public bundle (fifth guard)"
		[ "${2:-}" = "$gen" ] && [ "${3:-}" = "$seq" ] || die "the pinned catalog bundle is signed at generation ${2:-?}, sequence ${3:-?}, but the pin says generation $gen, sequence $seq"
		echo "OK: $file is public, generation $gen, sequence $seq, and the server's own check accepts it ($line)"
		if [ -n "$out" ]; then
			mkdir -p "$out"
			rm -f "$out"/benchy-catalog-public-*.zip "$out"/benchy-catalog-public-*.zip.sha256
			cp "$bundle" "$out/$file"
			(cd "$out" && sha256sum "$file" > "$file.sha256")
			echo "$out/$file ($size bytes)"
			cat "$out/$file.sha256"
		fi
	)
}

# Neither the engine zip nor a Linux engine tarball may be a test build: one that reads a test key from the environment, or whose Go build
# info shows the test build tag. A release refuses it; a dev/test build only warns, loudly, so a hardware pass with a test-key engine still works.
# scan_engine_asset <path> <release|dev>
scan_engine_asset()
{
	local msg
	msg=$(python3 -I -c '
import re, sys, tarfile, zipfile
marker = b"BENCHY_CATALOG" b"TEST_SPKI"
tagre = re.compile(rb"-tags=[^\n\x00]*catalog" b"test")
path = sys.argv[1]
if path.endswith(".zip"):
    z = zipfile.ZipFile(path); blobs = ((n, z.read(n)) for n in z.namelist())
else:
    t = tarfile.open(path); blobs = ((m.name, t.extractfile(m).read()) for m in t.getmembers() if m.isfile())
for name, data in blobs:
    if marker in data or tagre.search(data):
        sys.exit("%s in %s is a test build (it reads a test key from the environment, or was built with the test tag)" % (name, path))' "$1" 2>&1) || {
		if [ "${2:-release}" = release ]; then die "refusing a test-build engine: $msg"; fi
		echo "*** WARNING: $msg ***" >&2
		echo "*** This dev/test build carries a TEST-KEY engine. It must never be released or distributed. ***" >&2
	}
}

# abspath <path>: the real path, or a stop when there is none
abspath() { local p; p=$(realpath -- "$1" 2>/dev/null) && [ -n "$p" ] || die "no such file: $1"; echo "$p"; }

build_catalog_bundle()
{
	local version out= given=
	[ $# -ge 1 ] || usage
	version=$1
	shift
	while [ $# -gt 0 ]; do
		case $1 in
			--out) [ $# -ge 2 ] || usage; out=$2; shift 2 ;;
			--catalog-bundle) [ $# -ge 2 ] || usage; given=$(abspath "$2"); shift 2 ;;
			*) usage ;;
		esac
	done
	check_version_format "$version"
	check_tag "$version"
	out=${out:-${BENCHY_RELEASE_DIR:-$HOME/benchy-release}/$version}
	catalog_gate "$version" "$given" "$out"
}

# ---- runtime-component bundles (component updates, R1) ----

# fetch_text <url>: the body over HTTPS (at most 4 MiB, no redirect to another scheme). BENCHY_COMPONENT_FETCH_DIR (tests only) answers from files named like the url's last part.
fetch_text()
{
	local url=$1
	if [ -n "${BENCHY_COMPONENT_FETCH_DIR:-}" ]; then
		[ "${COMPONENT_TEST:-0}" = 1 ] || die "BENCHY_COMPONENT_FETCH_DIR is set, which replaces the upstream with local files; that is for tests only (pass --test), unset it for a real release"
		cat -- "$BENCHY_COMPONENT_FETCH_DIR/$(basename "$url")" 2>/dev/null || die "(test fetch) no $(basename "$url")"
		return
	fi
	curl -fsS --proto =https --proto-redir =https --max-filesize 4194304 --max-time 60 "$url" || die "could not fetch $url"
}
# fetch_file <url> <dest>: a download over HTTPS to a file, 512 MiB at most
fetch_file()
{
	local url=$1 dest=$2
	if [ -n "${BENCHY_COMPONENT_FETCH_DIR:-}" ]; then
		[ "${COMPONENT_TEST:-0}" = 1 ] || die "BENCHY_COMPONENT_FETCH_DIR is set, which replaces the upstream with local files; that is for tests only (pass --test), unset it for a real release"
		cp -- "$BENCHY_COMPONENT_FETCH_DIR/$(basename "$url")" "$dest" 2>/dev/null || die "(test fetch) no $(basename "$url")"
		return
	fi
	curl -fsS --proto =https --proto-redir =https --max-filesize 536870912 --max-time 900 -o "$dest" "$url" || die "could not download $url"
}
sha_of() { sha256sum "$1" | cut -d' ' -f1; }

component_bundle()
{
	local comp=$1 version= track= file= rebuild=0 notes= out= prev= gen=1 pkgsha= req=() upstream_url= upstream_sha= name= spec cat
	shift
	while [ $# -gt 0 ]; do
		case $1 in
			--version) [ $# -ge 2 ] || usage; version=$2; shift 2 ;;
			--track) [ $# -ge 2 ] || usage; track=$2; shift 2 ;;
			--file) [ $# -ge 2 ] || usage; file=$(abspath "$2"); shift 2 ;;
			--rebuild) [ $# -ge 2 ] || usage; rebuild=$2; shift 2 ;;
			--requires) [ $# -ge 2 ] || usage; req+=("$2"); shift 2 ;;
			--notes) [ $# -ge 2 ] || usage; notes=$2; shift 2 ;;
			--out) [ $# -ge 2 ] || usage; out=$2; shift 2 ;;
			--previous) [ $# -ge 2 ] || usage; prev=$(abspath "$2"); shift 2 ;;
			--generation) [ $# -ge 2 ] || usage; gen=$2; shift 2 ;;
			--packages-sha256) [ $# -ge 2 ] || usage; pkgsha=$2; shift 2 ;;
			--test) COMPONENT_TEST=1; shift ;;
			*) usage ;;
		esac
	done
	if [ "${COMPONENT_TEST:-0}" = 1 ]; then
		echo "################################################################" >&2
		echo "## TEST MODE: upstream checks read local files from BENCHY_COMPONENT_FETCH_DIR." >&2
		echo "## Nothing made in this mode is a release. Never sign it." >&2
		echo "################################################################" >&2
	elif [ -n "${BENCHY_COMPONENT_FETCH_DIR:-}" ]; then
		die "BENCHY_COMPONENT_FETCH_DIR is set; unset it for a real release (or pass --test)"
	fi
	[ "$comp" != cygwin ] || die "cygwin is not released through the channel yet (R3 builds the set and its checks)"
	case $comp in php|client|benchy|cygwin|vcredist) ;; *) die "unknown component '$comp' (php, client, benchy, cygwin or vcredist)" ;; esac
	[[ $version =~ ^[0-9]+(\.[0-9]+){1,3}$ ]] || die "--version must look like 8.5.13 or 0.15.0, got '$version'"
	[[ $rebuild =~ ^[0-9]{1,4}$ ]] || die "--rebuild must be a number"
	[[ $gen =~ ^[0-9]+$ ]] || die "--generation must be a number"
	cat=${BENCHY_CATALOG:-benchy-catalog}
	command -v "$cat" >/dev/null || die "benchy-catalog is needed (BENCHY_CATALOG=<path>)"
	command -v python3 >/dev/null || die "python3 is needed"
	out=${out:-${BENCHY_RELEASE_DIR:-$HOME/benchy-release}/components-$comp-$version}
	[ -e "$out" ] && die "$out exists already; give --out or remove it"
	mkdir -p "$out/files"
	case $comp in
		php)
			track=${track:-${version%.*}}
			local json zip_path want
			json=$(fetch_text https://windows.php.net/downloads/releases/releases.json)
			read -r zip_path want < <(python3 -c '
import json, sys
v = sys.argv[1]
d = json.loads(sys.stdin.read())
series = ".".join(v.split(".")[:2])
e = d.get(series)
if not isinstance(e, dict) or e.get("version") != v:
    sys.exit("releases.json lists %s for %s, not %s: an older PHP is not offered from it" % (e.get("version") if isinstance(e, dict) else None, series, v))
z = (e.get("nts-vs17-x64") or {}).get("zip") or {}
p, h = z.get("path", ""), z.get("sha256", "")
import re
if not re.fullmatch(r"php-[0-9.]+-nts-Win32-vs17-x64\.zip", p) or not re.fullmatch(r"[0-9a-f]{64}", h):
    sys.exit("releases.json has no usable NTS x64 vs17 zip for " + v)
print(p, h)' "$version" <<<"$json") || die "windows.php.net's releases.json does not offer PHP $version"
			upstream_url=https://windows.php.net/downloads/releases/$zip_path
			upstream_sha=$want
			if [ -z "$file" ]; then
				file=$out/files/$zip_path
				fetch_file "$upstream_url" "$file"
			fi
			[ "$(sha_of "$file")" = "$want" ] || die "$file is not windows.php.net's $zip_path (sha256 $(sha_of "$file"), releases.json says $want)"
			[ "$(basename "$file")" = "$zip_path" ] || die "the file is named $(basename "$file"); windows.php.net's is $zip_path"
			echo "OK: PHP $version matches windows.php.net's releases.json ($want)"
			;;
		client)
			track=${track:-0}
			[ -n "$file" ] || die "client: give --file <the release's windows-x64 zip>"
			local sums
			if [ -n "${BENCHY_COMPONENT_FETCH_DIR:-}" ]; then
				sums=$(fetch_text SHA256SUMS)
			else
				command -v gh >/dev/null || die "gh is needed to read the client release's SHA256SUMS"
				sums=$(gh release download "v$version" -R dakotlyar/benchy-client -p SHA256SUMS -O - 2>/dev/null) || die "could not read SHA256SUMS of the client's v$version release (is it published?)"
			fi
			name=$(basename "$file")
			want=$(awk -v n="$name" '{ f=$2; sub(/^\*/, "", f); if (f == n) print $1 }' <<<"$sums")
			[[ $want =~ ^[0-9a-f]{64}$ ]] || die "the client release's SHA256SUMS does not list $name"
			[ "$(sha_of "$file")" = "$want" ] || die "$file does not match the client release's SHA256SUMS ($(sha_of "$file") vs $want)"
			upstream_url=https://github.com/dakotlyar/benchy-client/releases/tag/v$version
			upstream_sha=$want
			echo "OK: the client $version matches its release SHA256SUMS ($want)"
			;;
		benchy)
			track=${track:-0}
			[ -n "$file" ] || die "benchy: give --file <the Windows tree zip>"
			local pin have cur tag=v$version tagsha treeversion pinsjson
			# The tree must be the release's own: the tag v<version> exists with PTS_VERSION <version> (check_tag), the zip is
			# byte-equal to `git archive` of that tag, and the zip itself says the same version. The CA bundle is then checked
			# against the pin of that tag, and against curl.se.
			check_tag "$version" >&2
			tagsha=$(git -C "$REPO" archive --format=zip "$tag" | sha256sum | cut -d' ' -f1)
			[ "$(sha_of "$file")" = "$tagsha" ] || die "$file is not the release's own tree: git archive of $tag has sha256 $tagsha, the file $(sha_of "$file"). Build it with git archive --format=zip $tag"
			treeversion=$(unzip -p "$file" pts-core/pts-core.php 2>/dev/null | sed -n "s/^pts_define('PTS_VERSION', '\([^']*\)');.*/\1/p")
			[ "$treeversion" = "$version" ] || die "the tree zip says PTS_VERSION '$treeversion', not $version"
			pinsjson=$(git -C "$REPO" show "$tag:pts-core/static/update/runtime-pins.json") || die "$tag has no runtime-pins.json"
			pin=$(python3 -c 'import json,sys; print(json.loads(sys.stdin.read())["windows-x64"]["cacert"]["artifact"]["sha256"])' <<<"$pinsjson") || die "runtime-pins.json of $tag has no windows-x64.cacert"
			have=$(unzip -p "$file" pts-core/static/update/cacert.pem 2>/dev/null | sha256sum | cut -d' ' -f1)
			[ "$have" = "$pin" ] || die "the tree's cacert.pem ($have) is not the pinned CA bundle ($pin): a changed bundle goes through runtime-pins.json first"
			cur=$(fetch_text https://curl.se/ca/cacert.pem.sha256 | awk '{print $1}' | head -1)
			[[ $cur =~ ^[0-9a-f]{64}$ ]] || die "curl.se's cacert.pem.sha256 is not a sha256"
			if [ "$cur" = "$pin" ]; then
				echo "OK: the tree's CA bundle is curl.se's current one ($cur)"
			else
				echo "WARNING: the tree's CA bundle ($pin) is not curl.se's current one ($cur); re-pin it before this release if you want the newer roots" >&2
			fi
			upstream_url=https://curl.se/ca/cacert.pem
			upstream_sha=$pin
			;;
		vcredist)
			track=
			[ -n "$file" ] || die "vcredist: give --file <VC_redist.x64.exe>"
			local vpin
			vpin=$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["windows-x64"]["php"]["vcredist"]["sha256"])' "$REPO/pts-core/static/update/runtime-pins.json") || die "no vcredist pin"
			if [ "$(sha_of "$file")" != "$vpin" ]; then
				echo "NOTE: this VC_redist is not the one runtime-pins.json pins ($vpin); its signature is the check" >&2
			fi
			if command -v osslsigncode >/dev/null; then
				verify_vcredist_signature native "$file"
			else
				die "vcredist needs osslsigncode on PATH to check the Microsoft signature"
			fi
			upstream_url=https://aka.ms/vs/17/release/vc_redist.x64.exe
			upstream_sha=$(sha_of "$file")
			;;
		cygwin)
			track=${track:-3}
			[ -n "$file" ] || die "cygwin: give --file <the set's root zip>"
			[[ $pkgsha =~ ^[0-9a-f]{64}$ ]] || die "cygwin: give --packages-sha256 (the sha256 of the set's cygwin-set.json)"
			upstream_url=
			upstream_sha=
			;;
	esac
	spec=$out/spec.json
	python3 - "$spec" "$comp" "$track" "$version" "$rebuild" "$file" "$upstream_url" "$upstream_sha" "$notes" "$pkgsha" "${req[@]+"${req[@]}"}" <<'PY' || die "could not write the spec"
import json, sys
spec, comp, track, version, rebuild, file, url, sha, notes, pkgsha = sys.argv[1:11]
req = {}
for r in sys.argv[11:]:
    k, _, v = r.partition("=")
    if not k or not v:
        sys.exit("--requires wants component=version, got " + r)
    req[k] = v
d = {"component": comp, "version": version, "file": file}
if track:
    d["track"] = track
if int(rebuild):
    d["rebuild"] = int(rebuild)
if url and sha:
    d["upstream"] = {"url": url, "sha256": sha}
if req:
    d["requires"] = req
if notes:
    d["notes"] = notes
if pkgsha:
    d["packages_sha256"] = pkgsha
if comp == "benchy":
    d["applier"] = 1
if comp == "vcredist":
    d["authenticode"] = "Microsoft Corporation"
json.dump(d, open(spec, "w"), indent=1)
PY
	local pa=()
	[ -n "$prev" ] && pa=(--previous "$prev")
	"$cat" component prepare --generation "$gen" --out "$out/request" --update "$spec" "${pa[@]+"${pa[@]}"}"
}

component_bundle_finish()
{
	local req= sig= out= prev= cat
	while [ $# -gt 0 ]; do
		case $1 in
			--request) [ $# -ge 2 ] || usage; req=$(abspath "$2"); shift 2 ;;
			--sig) [ $# -ge 2 ] || usage; sig=$(abspath "$2"); shift 2 ;;
			--out) [ $# -ge 2 ] || usage; out=$2; shift 2 ;;
			--previous) [ $# -ge 2 ] || usage; prev=$(abspath "$2"); shift 2 ;;
			*) usage ;;
		esac
	done
	[ -n "$req" ] && [ -n "$sig" ] || usage
	cat=${BENCHY_CATALOG:-benchy-catalog}
	command -v "$cat" >/dev/null || die "benchy-catalog is needed (BENCHY_CATALOG=<path>)"
	out=${out:-$(dirname "$req")/signed}
	local pa=()
	[ -n "$prev" ] && pa=(--previous "$prev")
	"$cat" component finish --request "$req" --sig "$sig" --out "$out" "${pa[@]+"${pa[@]}"}"
	echo "Attach $out/benchy-components-bundle-*.zip to the public Benchy release v<version> (every release carries the engine, components and public catalog bundles, server-only releases included), and commit $out/components-ledger.json as the next release's --previous."
}

server_update_bundle()
{
	local version= debs= out= gen=1 notes= schema
	while [ $# -gt 0 ]; do
		case $1 in
			--version) [ $# -ge 2 ] || usage; version=$2; shift 2 ;;
			--debs) [ $# -ge 2 ] || usage; debs=$(realpath -- "$2" 2>/dev/null) || die "no such folder: $2"; shift 2 ;;
			--out) [ $# -ge 2 ] || usage; out=$2; shift 2 ;;
			--generation) [ $# -ge 2 ] || usage; gen=$2; shift 2 ;;
			--notes) [ $# -ge 2 ] || usage; notes=$2; shift 2 ;;
			*) usage ;;
		esac
	done
	[ -n "$version" ] || usage
	check_version_format "$version"
	[[ $gen =~ ^[0-9]+$ ]] || die "--generation must be a number"
	[ "$(printf %s "$notes" | wc -c)" -le 200 ] || die "--notes is at most 200 bytes"
	check_tag "$version" >&2
	debs=${debs:-${BENCHY_RELEASE_DIR:-$HOME/benchy-release}/$version}
	out=${out:-${BENCHY_RELEASE_DIR:-$HOME/benchy-release}/server-update-$version}
	[ ! -e "$out" ] || die "$out exists already; remove it or pass another --out"
	local f
	for f in benchy_${version}_all.deb benchy-server_${version}_all.deb; do
		[ -f "$debs/$f" ] || die "$debs/$f is missing (build the debs first: deploy/deb-package/build-package-deb.php)"
		if command -v dpkg-deb >/dev/null; then
			[ "$(dpkg-deb -f "$debs/$f" Version)" = "$version" ] || die "$f says version $(dpkg-deb -f "$debs/$f" Version), not $version"
		else
			echo "WARNING: dpkg-deb is not here, so $f's version is not checked" >&2
		fi
	done
	schema=$(git -C "$REPO" show "v$version:pts-core/objects/phoromatic/phoromatic_server_update.php" 2>/dev/null | sed -n 's/^[[:space:]]*const DB_SCHEMA = \([0-9]\{1,6\}\);.*/\1/p')
	[[ $schema =~ ^[0-9]+$ ]] || die "v$version has no phoromatic_server_update::DB_SCHEMA"
	mkdir -p "$out/request"
	python3 - "$out/request" "$version" "$schema" "$gen" "$notes" "$debs" <<'PY' || die "could not write the index"
import hashlib, json, os, sys, datetime
req, version, schema, gen, notes, debs = sys.argv[1:7]
pk = []
for name in ("benchy", "benchy-server"):
    fn = "%s_%s_all.deb" % (name, version)
    b = open(os.path.join(debs, fn), "rb").read()
    pk.append({"name": name, "version": version, "file": fn, "size": len(b), "sha256": hashlib.sha256(b).hexdigest()})
ix = {"schema": "benchy-server-release/1", "version": version,
      "issued": datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
      "min_generation": None, "platform": "linux-deb", "db_schema": int(schema), "packages": pk, "notes": notes}
data = (json.dumps(ix, indent=1) + "\n").encode()
open(os.path.join(req, "to-sign.bin"), "wb").write(data)
json.dump({"version": version, "generation": int(gen), "debs": debs}, open(os.path.join(req, "request.json"), "w"))
PY
	cat <<MSG
The server update $version is ready to sign: $out/request/to-sign.bin
(database schema $schema, generation $gen key). On the machine that holds the engine key, in the same session as the component bundle:
    openssl dgst -sha256 -sign <engine-key.pem> -out sig.der $out/request/to-sign.bin
then, back here:
    $0 server-update-bundle finish --request $out/request --sig sig.der
MSG
}

server_update_bundle_finish()
{
	local req= sig= out=
	while [ $# -gt 0 ]; do
		case $1 in
			--request) [ $# -ge 2 ] || usage; req=$(abspath "$2"); shift 2 ;;
			--sig) [ $# -ge 2 ] || usage; sig=$(abspath "$2"); shift 2 ;;
			--out) [ $# -ge 2 ] || usage; out=$2; shift 2 ;;
			*) usage ;;
		esac
	done
	[ -n "$req" ] && [ -n "$sig" ] || usage
	[ -f "$req/to-sign.bin" ] && [ -f "$req/request.json" ] || die "$req is not a request folder made by server-update-bundle prepare"
	out=${out:-$(dirname "$req")/signed}
	[ ! -e "$out" ] || die "$out exists already"
	local version
	version=$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["version"])' "$req/request.json") || die "damaged request.json"
	check_version_format "$version"
	mkdir -p "$out"
	python3 - "$req" "$sig" "$out" "$REPO" "$version" <<'PY' || { rm -rf "$out"; die "the signature was not accepted (nothing was written)"; }
import base64, hashlib, json, os, subprocess, sys, tempfile, zipfile
req, sigfile, out, repo, version = sys.argv[1:6]
r = json.load(open(os.path.join(req, "request.json")))
gen = r["generation"]
data = open(os.path.join(req, "to-sign.bin"), "rb").read()
sig = open(sigfile, "rb").read()
if not sig or len(sig) > 200 or sig[:1] != b"\x30":
    sys.exit("the signature is not an openssl DER file (openssl dgst -sha256 -sign ... -out sig.der)")
tag = subprocess.run(["git", "-C", repo, "show", "v%s:pts-core/static/engine/keys.json" % version], capture_output=True, check=True).stdout
table = json.loads(tag)
if table.get("test_keys") is not False and os.environ.get("BENCHY_SERVER_UPDATE_TEST") != "1":
    sys.exit("the tag's key table holds test keys")
spki = None
for k in table["keys"]:
    if k["generation"] == gen:
        spki = base64.b64decode(k["spki"])
if spki is None:
    sys.exit("the tag's engine key table has no generation %d" % gen)
key_id = hashlib.sha256(spki).hexdigest()[:32]
if key_id in table.get("denied_key_ids", []):
    sys.exit("that key is denied")
with tempfile.TemporaryDirectory() as t:
    b64 = base64.b64encode(spki).decode()
    pub = os.path.join(t, "pub.pem")
    open(pub, "w").write("-----BEGIN PUBLIC KEY-----\n" + "\n".join(b64[i:i + 64] for i in range(0, len(b64), 64)) + "\n-----END PUBLIC KEY-----\n")
    sf = os.path.join(t, "sig")
    open(sf, "wb").write(sig)
    df = os.path.join(t, "data")
    open(df, "wb").write(data)
    v = subprocess.run(["openssl", "dgst", "-sha256", "-verify", pub, "-signature", sf, df], capture_output=True)
    if v.returncode != 0:
        sys.exit("the signature does not verify against generation %d of the engine key table (%s)" % (gen, v.stdout.decode().strip() or v.stderr.decode().strip()))
line = json.dumps({"schema": "benchy-catalog-sig/1", "key_id": key_id, "sig": base64.b64encode(sig).decode()}, separators=(",", ":"))
signed = line.encode() + b"\n" + data
open(os.path.join(out, "server.signed"), "wb").write(signed)
ix = json.loads(data)
zn = os.path.join(out, "benchy-server-update-%s.zip" % version)
with zipfile.ZipFile(zn, "w", zipfile.ZIP_DEFLATED) as z:
    z.writestr(zipfile.ZipInfo("server.signed", (2026, 1, 1, 0, 0, 0)), signed, zipfile.ZIP_DEFLATED)
    for p in ix["packages"]:
        b = open(os.path.join(r["debs"], p["file"]), "rb").read()
        if hashlib.sha256(b).hexdigest() != p["sha256"] or len(b) != p["size"]:
            sys.exit("%s changed since prepare" % p["file"])
        zi = zipfile.ZipInfo(p["file"], (2026, 1, 1, 0, 0, 0))
        zi.external_attr = 0o644 << 16
        z.writestr(zi, b, zipfile.ZIP_STORED)
open(zn + ".sha256", "w").write("%s  %s\n" % (hashlib.sha256(open(zn, "rb").read()).hexdigest(), os.path.basename(zn)))
PY
	echo "Wrote $out/benchy-server-update-$version.zip (and .sha256). Attach it to the public Benchy release v$version, and import it on seinar: benchy server-update-import."
}

[ $# -ge 1 ] || usage
case $1 in
	check)
		[ $# -ge 2 ] || usage
		check_version_format "$2"
		check_tag "$2"
		if [ $# -eq 2 ]; then
			catalog_gate "$2" "" ""
		else
			[ $# -eq 4 ] && [ "$3" = --catalog-bundle ] || usage
			g=$(abspath "$4") || exit 1
			catalog_gate "$2" "$g" ""
		fi
		;;
	catalog-bundle)
		shift
		build_catalog_bundle "$@"
		;;
	installer)
		shift
		build_installer "$@"
		;;
	linux-assets)
		shift
		build_linux_assets "$@"
		;;
	component-bundle)
		shift
		[ $# -ge 1 ] || usage
		if [ "$1" = finish ]; then
			shift
			component_bundle_finish "$@"
		else
			component_bundle "$@"
		fi
		;;
	server-update-bundle)
		shift
		[ $# -ge 1 ] || usage
		if [ "$1" = finish ]; then
			shift
			server_update_bundle_finish "$@"
		elif [ "$1" = prepare ]; then
			shift
			server_update_bundle "$@"
		else
			usage
		fi
		;;
	*) usage ;;
esac
