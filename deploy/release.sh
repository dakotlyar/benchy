#!/bin/bash
# Benchy release tooling. Run by Dave on his dev box, never on clients or the Phoromatic server.
#
# Usage: deploy/release.sh check <version>
#   Passes when the tag v<version> exists and PTS_VERSION in pts-core/pts-core.php at that tag is <version>.
#
# Release zips are built from the tag, so a tag that predates its version bump ships a Benchy that reports the old
# version (v0.7.0 points one commit before the 0.7.0 bump and reports 0.1.0). Run this before building or uploading
# anything for a release. Later updater phases add the bundle, signing and upload steps to this script.

set -euo pipefail

die() { echo "release.sh: $*" >&2; exit 1; }
usage() { echo "Usage: $0 check <version>   (e.g. $0 check 0.7.1)" >&2; exit 2; }

[ $# -eq 2 ] && [ "$1" = check ] || usage
VERSION=$2
[[ $VERSION =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "version must look like 0.7.1, got '$VERSION'"

REPO=$(cd "$(dirname "$0")/.." && pwd)
TAG=v$VERSION

COMMIT=$(git -C "$REPO" rev-parse -q --verify "refs/tags/$TAG^{commit}") || die "tag $TAG does not exist"
TAGGED=$(git -C "$REPO" show "$COMMIT:pts-core/pts-core.php" | sed -n "s/^pts_define('PTS_VERSION', '\([^']*\)');.*/\1/p")
[ -n "$TAGGED" ] || die "could not find PTS_VERSION in pts-core/pts-core.php at $TAG"
[ "$TAGGED" = "$VERSION" ] || die "PTS_VERSION at $TAG ($(git -C "$REPO" rev-parse --short "$COMMIT")) is $TAGGED, not $VERSION: the tag predates the version bump. Tag the commit that sets PTS_VERSION to $VERSION."

echo "OK: $TAG ($(git -C "$REPO" rev-parse --short "$COMMIT")) has PTS_VERSION $VERSION"
