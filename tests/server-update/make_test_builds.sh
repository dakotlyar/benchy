#!/bin/bash
# Builds the test debs and signed test bundles the server update is tried with (the web page and the root helper), from THIS checkout, with a throw-away
# key made here. The private key stays in OUT/key/ and is for these tests only: the builds trust only the test key (keys.json is replaced in a copy of the
# tree, never in the checkout), so none of this can be installed on a server that has the real key table. Needs podman (the debs are built in
# localhost/benchy-relcheck:2, as in the release runbook), openssl, python3 and git.
#
#   bash tests/server-update/make_test_builds.sh OUT [KEYDIR]
# KEYDIR: the key folder of an earlier run (OUT/key), to build with the SAME test key (so builds from two runs can update each other).
#
# OUT/bundles/ then holds:
#   0.16.2 (the base the test server runs), 0.16.99 (the target), 0.16.98 (its server exits 78 on start), 0.16.97 (its postinst fails),
#   0.16.1 (older than the base: a downgrade), 0.16.99-unknown-key (signed by a key not in the table), 0.16.99-flipped (one byte changed in a deb)
set -euo pipefail
OUT=$(realpath -m "${1:?usage: make_test_builds.sh OUT [KEYDIR]}")
KEYDIR=${2:-}
HERE=$(cd "$(dirname "$0")" && pwd)
SRC=$(cd "$HERE/../.." && pwd)
IMAGE=${BENCHY_RELCHECK_IMAGE:-localhost/benchy-relcheck:2}
PIN=$(grep -o 'benchy-catalog-public-[0-9-]*\.zip' "$SRC/pts-core/static/update/runtime-pins.json" | head -1)
CATALOG=${BENCHY_CATALOG_BUNDLE:-$(ls "$HOME"/benchy-release/*/"$PIN" 2>/dev/null | tail -1)}
[ -f "$CATALOG" ] || { echo "the pinned catalog bundle (benchy-catalog-public-N-N.zip) was not found; set BENCHY_CATALOG_BUNDLE=<path>" >&2; exit 1; }
[ ! -e "$OUT" ] || { echo "$OUT exists already" >&2; exit 1; }
mkdir -p "$OUT/key" "$OUT/debs" "$OUT/bundles"

# the throw-away keys: the test key (generation 1) and one the builds do not trust
if [ -n "$KEYDIR" ]; then
	[ -f "$KEYDIR/test.pem" ] && [ -f "$KEYDIR/keys.json" ] || { echo "$KEYDIR is not a key folder of make_test_builds.sh" >&2; exit 1; }
	cp "$KEYDIR"/* "$OUT/key/"
else
for k in test rogue; do
		openssl ecparam -genkey -name prime256v1 -noout -out "$OUT/key/$k.pem" 2>/dev/null 2>&1
		openssl ec -in "$OUT/key/$k.pem" -pubout -outform DER -out "$OUT/key/$k.spki.der" 2>/dev/null 2>&1
	done
fi
chmod 600 "$OUT"/key/*.pem
[ -n "$KEYDIR" ] || python3 - "$OUT/key" <<'PY'
import base64, json, sys
d = sys.argv[1]
spki = base64.b64encode(open(d + "/test.spki.der", "rb").read()).decode()
json.dump({"schema": "benchy-engine-keys/1", "test_keys": True, "keys": [{"generation": 1, "spki": spki}], "denied_key_ids": []}, open(d + "/keys.json", "w"), indent=1)
spki = base64.b64encode(open(d + "/rogue.spki.der", "rb").read()).decode()
json.dump({"schema": "benchy-engine-keys/1", "test_keys": True, "keys": [{"generation": 1, "spki": spki}], "denied_key_ids": []}, open(d + "/keys-rogue.json", "w"), indent=1)
PY

# the debs, in the release container, from a copy of the tree
cat > "$OUT/build-in-container.sh" <<'EOS'
set -euo pipefail
build() {
	v=$1; mod=$2
	W=/work/$v; mkdir -p "$W"
	(cd /src && tar --exclude=.git -cf - .) | tar -xf - -C "$W"
	cd "$W"
	sed -i "s/^\(pts_define('PTS_VERSION', '\)[^']*\(');\)/\1$v\2/" pts-core/pts-core.php
	grep -q "'PTS_VERSION', '$v'" pts-core/pts-core.php
	cp /out/key/keys.json pts-core/static/engine/keys.json
	case $mod in
		exit78) n=$(grep -n 'public static function run($r)' pts-core/commands/start_phoromatic_server.php | head -1 | cut -d: -f1)
			awk -v n="$n" '{print} NR==n+1 {print "\t\tself::refuse();"}' pts-core/commands/start_phoromatic_server.php > /tmp/sps.php && cat /tmp/sps.php > pts-core/commands/start_phoromatic_server.php
			sed -n "$((n)),$((n+3))p" pts-core/commands/start_phoromatic_server.php | grep -q 'self::refuse();' ;;
		badpostinst) sed -i '0,/^set -e$/s//set -e\nexit 1/' deploy/deb-package/benchy-server/postinst
			grep -q '^exit 1$' deploy/deb-package/benchy-server/postinst ;;
	esac
	git init -q && git add -A && git -c user.name=t -c user.email=t@t commit -q -m "test build $v" && git tag "v$v"
	mkdir -p /out/debs/tmp-$v
	php deploy/deb-package/build-package-deb.php --output /out/debs/tmp-$v --catalog-bundle /catalog/$(basename "$CATALOG_FILE") >/dev/null 2>&1
	mv /out/debs/tmp-$v/*.deb /out/debs/ && rmdir /out/debs/tmp-$v
}
build 0.16.2 none; build 0.16.99 none; build 0.16.98 exit78; build 0.16.97 badpostinst; build 0.16.1 none
EOS
podman run --rm -e "CATALOG_FILE=$CATALOG" -v "$SRC:/src:ro,Z" -v "$OUT:/out:Z" -v "$(dirname "$CATALOG"):/catalog:ro,Z" "$IMAGE" bash /out/build-in-container.sh 2>&1 | tail -5
rm -f "$OUT/build-in-container.sh"
ls "$OUT/debs" | wc -l

# the signed bundles: release.sh itself, in a throw-away git repo that has the test key table
make_repo() { # make_repo DIR KEYS VERSION
	rm -rf "$1"; mkdir -p "$1/deploy" "$1/pts-core/static/engine" "$1/pts-core/objects/phoromatic"
	cp "$SRC/deploy/release.sh" "$1/deploy/release.sh"
	cp "$2" "$1/pts-core/static/engine/keys.json"
	cp "$SRC/pts-core/objects/phoromatic/phoromatic_server_update.php" "$1/pts-core/objects/phoromatic/"
	printf "<?php\npts_define('PTS_VERSION', '%s');\n" "$3" > "$1/pts-core/pts-core.php"
	git -C "$1" init -q && git -C "$1" -c user.name=t -c user.email=t@t add -A && git -C "$1" -c user.name=t -c user.email=t@t commit -q -m t && git -C "$1" tag "v$3"
}
sign_bundle() { # sign_bundle VERSION KEYS PEM NAME
	local v=$1 repo=$OUT/repo-$4
	make_repo "$repo" "$2" "$v"
	BENCHY_RELEASE_DIR=$OUT bash "$repo/deploy/release.sh" server-update-bundle prepare --version "$v" --debs "$OUT/debs" --out "$OUT/work-$4" --notes "test build $v" >/dev/null 2>&1
	openssl dgst -sha256 -sign "$3" -out "$OUT/work-$4/sig.der" "$OUT/work-$4/request/to-sign.bin"
	BENCHY_SERVER_UPDATE_TEST=1 bash "$repo/deploy/release.sh" server-update-bundle finish --request "$OUT/work-$4/request" --sig "$OUT/work-$4/sig.der" --out "$OUT/work-$4/signed" >/dev/null 2>&1
	cp "$OUT/work-$4/signed/benchy-server-update-$v.zip" "$OUT/bundles/benchy-server-update-$4.zip"
}
for v in 0.16.2 0.16.99 0.16.98 0.16.97 0.16.1; do
	sign_bundle "$v" "$OUT/key/keys.json" "$OUT/key/test.pem" "$v"
done
sign_bundle 0.16.99 "$OUT/key/keys-rogue.json" "$OUT/key/rogue.pem" 0.16.99-unknown-key
python3 - "$OUT/bundles" <<'PY'
import sys, zipfile
d = sys.argv[1]
src = zipfile.ZipFile(d + "/benchy-server-update-0.16.99.zip")
with zipfile.ZipFile(d + "/benchy-server-update-0.16.99-flipped.zip", "w", zipfile.ZIP_STORED) as z:
    for i in src.infolist():
        b = src.read(i.filename)
        if i.filename.startswith("benchy-server_"):
            b = b[:100] + bytes([b[100] ^ 1]) + b[101:]
        z.writestr(i, b)
PY
rm -rf "$OUT"/repo-* "$OUT"/work-*
ls -1 "$OUT/bundles"
