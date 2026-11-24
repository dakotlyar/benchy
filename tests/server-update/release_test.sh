#!/bin/bash
# deploy/release.sh server-update-bundle, offline: prepare writes the bytes to sign, finish accepts only a signature that verifies against the tag's engine key
# table and writes a bundle the server's own reader takes. Throw-away keys and fake debs; nothing is signed with a real key. Run:
#   bash tests/server-update/release_test.sh        (needs git, openssl, python3, php with openssl and zlib)
set -u
HERE=$(cd "$(dirname "$0")" && pwd)
SRC=$(cd "$HERE/../.." && pwd)
W=$(mktemp -d "${TMPDIR:-/tmp}/benchy-suprel.XXXXXX")
trap 'rm -rf "$W"' EXIT
fail=0
check() { if [ "$2" = ok ]; then echo "  PASS  $1"; else echo "  FAIL  $1: $2"; fail=1; fi; }
G="git -c user.name=t -c user.email=t@t -C $W/repo"
mkdir -p "$W/repo/deploy" "$W/repo/pts-core/static/engine" "$W/repo/pts-core/objects/phoromatic" "$W/debs" "$W/key"
cp "$SRC/deploy/release.sh" "$W/repo/deploy/"
cp "$SRC/pts-core/objects/phoromatic/phoromatic_server_update.php" "$W/repo/pts-core/objects/phoromatic/"
printf "<?php\npts_define('PTS_VERSION', '0.16.77');\n" > "$W/repo/pts-core/pts-core.php"
for k in good other; do openssl ecparam -genkey -name prime256v1 -noout -out "$W/key/$k.pem" 2>/dev/null; openssl ec -in "$W/key/$k.pem" -pubout -outform DER -out "$W/key/$k.der" 2>/dev/null; done
python3 - "$W" <<'PY'
import base64, json, sys
w = sys.argv[1]
spki = base64.b64encode(open(w + "/key/good.der", "rb").read()).decode()
json.dump({"schema": "benchy-engine-keys/1", "test_keys": False, "keys": [{"generation": 1, "spki": spki}], "denied_key_ids": []}, open(w + "/repo/pts-core/static/engine/keys.json", "w"))
PY
git -C "$W/repo" init -q && $G add -A && $G commit -q -m one && $G tag v0.16.77
printf 'benchy deb bytes' > "$W/debs/benchy_0.16.77_all.deb"; printf 'server deb bytes' > "$W/debs/benchy-server_0.16.77_all.deb"
REL="bash $W/repo/deploy/release.sh server-update-bundle"

out=$($REL prepare --version 0.16.77 --debs "$W/debs" --out "$W/o1" --notes "test" 2>&1) && check "prepare writes the bytes to sign" ok || check "prepare" "$out"
[ -s "$W/o1/request/to-sign.bin" ] && python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); assert d["schema"]=="benchy-server-release/1" and d["db_schema"]>=60 and d["platform"]=="linux-deb" and [p["name"] for p in d["packages"]]==["benchy","benchy-server"]' "$W/o1/request/to-sign.bin" && check "the index has the schema, the platform, db_schema and the two packages" ok || check "the index" "bad"
out=$($REL prepare --version 0.16.77 --debs "$W/debs" --out "$W/o1" 2>&1) && check "prepare does not overwrite" "was accepted" || { grep -q "exists already" <<<"$out" && check "prepare does not overwrite" ok || check "prepare does not overwrite" "$out"; }
out=$($REL prepare --version 0.16.78 --debs "$W/debs" --out "$W/o2" 2>&1) && check "a version without a tag is refused" "was accepted" || { grep -q "does not exist" <<<"$out" && check "a version without a tag is refused" ok || check "no tag" "$out"; }
rm -f "$W/debs/benchy-server_0.16.77_all.deb"
out=$($REL prepare --version 0.16.77 --debs "$W/debs" --out "$W/o3" 2>&1) && check "a missing deb is refused" "was accepted" || { grep -q "is missing" <<<"$out" && check "a missing deb is refused" ok || check "missing deb" "$out"; }
printf 'server deb bytes' > "$W/debs/benchy-server_0.16.77_all.deb"

openssl dgst -sha256 -sign "$W/key/other.pem" -out "$W/bad.der" "$W/o1/request/to-sign.bin"
out=$($REL finish --request "$W/o1/request" --sig "$W/bad.der" --out "$W/f1" 2>&1) && check "a signature by another key is refused" "was accepted" || { [ ! -e "$W/f1" ] && check "a signature by another key is refused, nothing written" ok || check "other key" "left $W/f1"; }
printf 'not a signature' > "$W/junk.der"
out=$($REL finish --request "$W/o1/request" --sig "$W/junk.der" --out "$W/f2" 2>&1) && check "a file that is no signature is refused" "was accepted" || check "a file that is no signature is refused" ok
openssl dgst -sha256 -sign "$W/key/good.pem" -out "$W/good.der" "$W/o1/request/to-sign.bin"
out=$($REL finish --request "$W/o1/request" --sig "$W/good.der" --out "$W/f3" 2>&1) && check "the right signature gives the bundle" ok || check "finish" "$out"
[ -s "$W/f3/benchy-server-update-0.16.77.zip" ] && (cd "$W/f3" && sha256sum -c benchy-server-update-0.16.77.zip.sha256 >/dev/null) && check "the zip and its .sha256 are there" ok || check "zip" "missing"
unzip -l "$W/f3/benchy-server-update-0.16.77.zip" 2>/dev/null | grep -q "server.signed" && check "the zip holds server.signed" ok || check "zip content" "no server.signed"
# the server's own reader accepts it (the throw-away key as the engine key table)
php -d memory_limit=512M -r '
define("PTS_PATH", $argv[1] . "/tree/");
mkdir(PTS_PATH . "pts-core/static/engine", 0777, true);
copy($argv[1] . "/repo/pts-core/static/engine/keys.json", PTS_PATH . "pts-core/static/engine/keys.json");
$src = $argv[2] . "/pts-core/objects/phoromatic/";
foreach(array("phoromatic_catalog_refusal", "phoromatic_catalog_keys", "phoromatic_catalog_json", "phoromatic_catalog_zip", "phoromatic_catalog_verify", "phoromatic_server_update") as $c) { require_once $src . $c . ".php"; }
class phoromatic_server { public static function current_time() { return "x"; } }
class phoromatic_server_audit { public static function record() {} }
phoromatic_server_update::$handoff_override = $argv[1] . "/handoff/";
phoromatic_server_update::$running_override = "0.16.77";
try { $r = phoromatic_server_update::import($argv[1] . "/f3/benchy-server-update-0.16.77.zip", "cli", "cli", "t"); echo "accepted " . $r["version"] . "\n"; } catch(phoromatic_catalog_refusal $e) { echo "refused: " . $e->getMessage() . "\n"; }
' "$W" "$SRC" 2>&1 | grep -q "accepted 0.16.77" && check "the server's reader accepts the bundle" ok || check "the server's reader accepts the bundle" "refused"
[ $fail = 0 ] && echo "all passed" || echo "FAILED"
exit $fail
