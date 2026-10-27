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
#   version, commit, PHP zip) into the release bundle dir: --out, else $BENCHY_RELEASE_DIR/<version>, else
#   ~/benchy-release/<version>.
#
# Usage: deploy/release.sh installer --ref <commit-ish> <version> [--out <dir>]
#   DEV/TEST BUILDS ONLY, NEVER FOR A RELEASE. The same build from any commit (committed changes only; the working
#   tree is never used), with no tag check. <version> must equal PTS_VERSION at that commit. The exe is named
#   Benchy-Setup-<version>-dev-<sha>.exe and says "dev build <sha>" in its window caption; the default output dir is
#   ~/benchy-release/dev.
#
# makensis: the native one when it is on PATH, otherwise the pinned container image localhost/benchy-nsis:<NSIS_VERSION>
# (rootless podman; built from deploy/windows-installer/Containerfile on first use). BENCHY_NSIS=native|container
# forces one; the container gives a known makensis version.

set -euo pipefail

NSIS_VERSION=3.11-1
NSIS_IMAGE=localhost/benchy-nsis:$NSIS_VERSION

die() { echo "release.sh: $*" >&2; exit 1; }
usage()
{
	cat >&2 <<EOF
Usage: $0 check <version>                                  (e.g. $0 check 0.7.1)
       $0 installer <version> [--out <dir>]                 Windows GUI installer from tag v<version>
       $0 installer --ref <commit-ish> <version> [--out <dir>]   DEV/TEST build from any commit, not for releases
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

# Gets the pinned PHP zip into the cache (reused only when size and sha256 match) and prints its path
fetch_php_zip()
{
	local pins=$1 cache=$HOME/.cache/benchy-release
	local file size sha urls url zip
	{ read -r file; read -r size; read -r sha; read -r urls; } < <(python3 -I -c '
import json, sys
php = json.load(open(sys.argv[1]))["windows-x64"]["php"]
print(php["artifact"]["file"]); print(php["artifact"]["size"]); print(php["artifact"]["sha256"])
print(" ".join(php["upstream_urls"]))' "$pins") || die "could not read the PHP pin from $pins"
	[[ $file =~ ^[A-Za-z0-9._-]+\.zip$ ]] || die "unexpected PHP zip name in the pin: '$file'"
	mkdir -p "$cache"
	zip=$cache/$file
	if [ -f "$zip" ] && [ "$(file_digest "$zip")" = "$size $sha" ]; then
		echo "PHP zip: $zip (cached, matches the pin)" >&2
		echo "$zip"
		return
	fi
	[ -f "$zip" ] && echo "PHP zip: cached $zip does not match the pin, downloading it again" >&2
	for url in $urls; do
		echo "PHP zip: downloading $url" >&2
		if curl -fsSL --proto '=https' --max-filesize $((size + 1)) -o "$zip.part" "$url"; then
			if [ "$(file_digest "$zip.part")" = "$size $sha" ]; then
				mv "$zip.part" "$zip"
				echo "PHP zip: $zip (downloaded, matches the pin)" >&2
				echo "$zip"
				return
			fi
			echo "PHP zip: $url does not match the pin (got $(file_digest "$zip.part"), pin $size $sha)" >&2
		fi
		rm -f "$zip.part"
	done
	die "no upstream URL gave a PHP zip matching the pin ($file, $size bytes, sha256 $sha)"
}

ensure_nsis_image()
{
	podman image exists "$NSIS_IMAGE" && return
	echo "makensis: building $NSIS_IMAGE (once)" >&2
	podman build -q -t "$NSIS_IMAGE" --build-arg "NSIS_VERSION=$NSIS_VERSION" \
		-f "$REPO/deploy/windows-installer/Containerfile" "$REPO/deploy/windows-installer" >&2 \
		|| die "could not build $NSIS_IMAGE"
}

build_installer()
{
	local ref= version out=
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
			*) usage ;;
		esac
	done
	check_version_format "$version"

	local commit short name label= mode
	if [ -n "$ref" ]; then
		commit=$(git -C "$REPO" rev-parse -q --verify "$ref^{commit}") || die "$ref is not a commit"
		short=$(git -C "$REPO" rev-parse --short "$commit")
		[ "$(version_at "$commit")" = "$version" ] || die "PTS_VERSION at $ref ($short) is '$(version_at "$commit")', not $version"
		name=Benchy-Setup-$version-dev-$short.exe
		label="dev build $short"
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

	local cached zip=$work/in/php.zip
	cached=$(fetch_php_zip "$work/tree/pts-core/static/update/runtime-pins.json")
	# NSIS stores each file's mtime, so a copy dated like the tree's files (the commit time, as git archive does):
	# otherwise the exe would change whenever the cache is downloaded again
	mkdir -p "$work/in"
	cp "$cached" "$zip"
	touch -d "@$(git -C "$REPO" log -1 --format=%ct "$commit")" "$zip"

	local how=${BENCHY_NSIS:-auto} makensis_version makensis_from
	local -a defines=(-DVERSION="$version")
	[ -n "$label" ] && defines+=(-DBUILD_LABEL="$label")
	if [ "$how" = auto ]; then
		if command -v makensis >/dev/null; then how=native; else how=container; fi
	fi
	case $how in
		native)
			command -v makensis >/dev/null || die "BENCHY_NSIS=native, but makensis is not on PATH"
			makensis_version=$(makensis -VERSION)
			makensis_from="native $(command -v makensis)"
			# -WX: any warning fails the build
			makensis -V2 -WX "${defines[@]}" -DPAYLOAD="$work/tree" -DPHPZIP="$zip" -DOUTFILE="$work/out/$name" "$nsi" >&2 \
				|| die "makensis failed"
			;;
		container)
			ensure_nsis_image
			makensis_version=$(podman run --rm --network=none "$NSIS_IMAGE" -VERSION)
			makensis_from="container $NSIS_IMAGE ($(podman image inspect "$NSIS_IMAGE" --format '{{.Id}}'))"
			podman run --rm --network=none \
				-v "$work/tree:/work/tree:ro" -v "$zip:/in/php.zip:ro" -v "$work/out:/work/out" \
				"$NSIS_IMAGE" -V2 -WX "${defines[@]}" -DPAYLOAD=/work/tree -DPHPZIP=/in/php.zip -DOUTFILE="/work/out/$name" \
				/work/tree/deploy/windows-installer/benchy.nsi >&2 \
				|| die "makensis failed"
			;;
		*) die "BENCHY_NSIS must be native or container, not '$how'" ;;
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
