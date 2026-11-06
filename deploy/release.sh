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
#   a client gets it with `gh release download v<client version> -R dakotlyar/benchy-client` (a private repo: the gh
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
Usage: $0 check <version>                                  (e.g. $0 check 0.7.1)
       $0 installer <version> [--out <dir>] Windows GUI installer from tag v<version>
       $0 installer --ref <commit-ish> <version> [--out <dir>] [--client-zip <zip>] [--engine-zip <zip>] [--wrong-vcredist-hash]
                                                            DEV/TEST build from any commit, not for releases
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

# A pinned GitHub release asset: the zip given on the command line when there is one, else the cache, else the release
# asset. Prints its path.
# fetch_release_asset <label> <flag> <repo> <version> <file> <size> <sha256> <given path or ""> <release|dev>
# In release mode the GitHub release must be published (not a draft), whether the zip comes from the cache or not.
fetch_release_asset()
{
	local label=$1 flag=$2 repo=$3 version=$4 file=$5 size=$6 sha=$7 given=$8 mode=$9 cache=$HOME/.cache/benchy-release path
	if [ -n "$given" ]; then
		[ -f "$given" ] || die "$flag $given: no such file"
		[ "$(file_digest "$given")" = "$size $sha" ] \
			|| die "$flag $given does not match the pinned $label $version (got $(file_digest "$given"), pin $size $sha)"
		echo "$label zip: $given (matches the pin)" >&2
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
		echo "$label zip: $path (cached, matches the pin)" >&2
		echo "$path"
		return
	fi
	command -v gh >/dev/null || die "the $label zip isn't cached and gh isn't installed; pass $flag <path>"
	rm -f "$path"
	echo "$label zip: gh release download v$version -R $repo -p $file" >&2
	gh release download "v$version" -R "$repo" -p "$file" -D "$cache" >&2 \
		|| die "could not download $file from the $label's v$version release; pass $flag <path>"
	[ "$(file_digest "$path")" = "$size $sha" ] \
		|| die "the $label release asset does not match the pin (got $(file_digest "$path"), pin $size $sha)"
	echo "$path"
}

fetch_client() { fetch_release_asset client --client-zip dakotlyar/benchy-client "$CLIENT_VERSION" "$CLIENT_FILE" "$CLIENT_SIZE" "$CLIENT_SHA" "$1" "$2"; }
fetch_engine() { fetch_release_asset engine --engine-zip dakotlyar/benchy-engine "$ENGINE_VERSION" "$ENGINE_FILE" "$ENGINE_SIZE" "$ENGINE_SHA" "$1" "$2"; }

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
	if [ -n "$engine_cached" ]; then
		cp "$engine_cached" "$engine_zip"
		touch -d "@$(git -C "$REPO" log -1 --format=%ct "$commit")" "$engine_zip"
		engine_mount=(-v "$engine_zip:/in/engine.zip:ro")
	fi
	case $how in
		native)
			makensis_version=$(makensis -VERSION)
			makensis_from="native $(command -v makensis)"
			# -WX: any warning fails the build
			[ -n "$client_cached" ] && defines+=(-DCLIENTZIP="$client_zip")
			[ -n "$engine_cached" ] && defines+=(-DENGINEZIP="$engine_zip")
			makensis -V2 -WX "${defines[@]}" -DPAYLOAD="$work/tree" -DPHPZIP="$zip" -DOUTFILE="$work/out/$name" "$nsi" >&2 \
				|| die "makensis failed"
			;;
		container)
			makensis_version=$(podman run --rm --network=none "$NSIS_IMAGE" -VERSION)
			makensis_from="container $NSIS_IMAGE ($(podman image inspect "$NSIS_IMAGE" --format '{{.Id}}'))"
			[ -n "$client_cached" ] && defines+=(-DCLIENTZIP=/in/client.zip)
			[ -n "$engine_cached" ] && defines+=(-DENGINEZIP=/in/engine.zip)
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
	{
		echo "file: $name"
		echo "mode: $mode"
		echo "version: $version"
		echo "commit: $commit"
		echo "php_zip: $(basename "$cached") $(file_digest "$zip")"
		echo "vcredist: $VC_VERSION $VC_FILE $(file_digest "$vc_cached") (signature verified)"
		if [ -n "$client_cached" ]; then echo "client_zip: $CLIENT_VERSION $CLIENT_FILE $(file_digest "$client_zip")"; else echo "client_zip: none"; fi
		if [ -n "$engine_cached" ]; then echo "engine_zip: $ENGINE_VERSION $ENGINE_FILE $(file_digest "$engine_zip")"; else echo "engine_zip: none"; fi
		[ -n "$wrong_vc_hash" ] && echo "vcredist_baked_sha256: $vc_baked_sha (WRONG on purpose: --wrong-vcredist-hash)"
		echo "makensis: $makensis_version"
		echo "makensis_from: $makensis_from"
		echo "sha256: $(cut -d' ' -f1 "$out/$name.sha256")"
		echo "built: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
	} > "$out/$name.buildinfo"
	echo "Built $out/$name ($(stat -c %s "$out/$name") bytes, makensis $makensis_version)"
	cat "$out/$name.sha256"
}

[ $# -ge 1 ] || usage
case $1 in
	check)
		[ $# -eq 2 ] || usage
		check_version_format "$2"
		check_tag "$2"
		;;
	installer)
		shift
		build_installer "$@"
		;;
	*) usage ;;
esac
