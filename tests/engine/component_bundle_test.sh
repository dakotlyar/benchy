#!/bin/bash
# deploy/release.sh component-bundle, the upstream checks and the prepare step, offline: the "upstream" is a folder of files answered by BENCHY_COMPONENT_FETCH_DIR.
# Nothing is signed (the private key is Dave's, and never here). Needs the engine repo's benchy-catalog:
#   BENCHY_CATALOG=/path/to/benchy-catalog bash tests/engine/component_bundle_test.sh
set -u
: "${BENCHY_CATALOG:?set BENCHY_CATALOG}"
HERE=$(cd "$(dirname "$0")" && pwd)
REL=$HERE/../../deploy/release.sh
W=$(mktemp -d "${TMPDIR:-/tmp}/benchy-compbundle.XXXXXX")
trap 'rm -rf "$W"' EXIT
export BENCHY_RELEASE_DIR=$W/rel BENCHY_CATALOG BENCHY_COMPONENT_FETCH_DIR=$W/up
mkdir -p "$W/up" "$W/in"
fail=0
check() { if [ "$2" = ok ]; then echo "  PASS  $1"; else echo "  FAIL  $1: $2"; fail=1; fi; }
expect_ok() { local n=$1; shift; if out=$("$@" 2>&1); then check "$n" ok; else check "$n" "exit $? $(tail -3 <<<"$out" | tr '\n' ' ')"; fi; }
expect_fail() { local n=$1 want=$2; shift 2; if out=$("$@" 2>&1); then check "$n" "was accepted"; elif grep -q -- "$want" <<<"$out"; then check "$n" ok; else check "$n" "refused for another reason: $(tail -2 <<<"$out" | tr '\n' ' ')"; fi; }

# PHP: releases.json says what the zip's sha256 is
printf 'php zip bytes' > "$W/in/php-8.5.13-nts-Win32-vs17-x64.zip"
psha=$(sha256sum "$W/in/php-8.5.13-nts-Win32-vs17-x64.zip" | cut -d' ' -f1)
cat > "$W/up/releases.json" <<JSON
{"8.5": {"version": "8.5.13", "nts-vs17-x64": {"zip": {"path": "php-8.5.13-nts-Win32-vs17-x64.zip", "size": "1KB", "sha256": "$psha"}}},
 "8.4": {"version": "8.4.20", "nts-vs17-x64": {"zip": {"path": "php-8.4.20-nts-Win32-vs17-x64.zip", "size": "1KB", "sha256": "$psha"}}}}
JSON
cp "$W/in/php-8.5.13-nts-Win32-vs17-x64.zip" "$W/up/"
expect_ok "php: the zip is windows.php.net's, a request to sign is written" bash "$REL" component-bundle php --version 8.5.13 --out "$W/o1" --requires vcredist=14.44 --notes "Security fix."
[ -s "$W/o1/request/to-sign.bin" ] && grep -q '"php"' "$W/o1/request/to-sign.bin" && grep -q '"vcredist": "14.44"' "$W/o1/request/to-sign.bin" && grep -q "$psha" "$W/o1/spec.json" && check "php: the bytes to sign name the release, its requirement and the upstream hash" ok || check "php: the bytes to sign" "missing"
printf 'tampered' > "$W/in/php-8.5.13-nts-Win32-vs17-x64.zip"
expect_fail "php: a zip that is not windows.php.net's is refused" "is not windows.php.net" bash "$REL" component-bundle php --version 8.5.13 --file "$W/in/php-8.5.13-nts-Win32-vs17-x64.zip" --out "$W/o2"
expect_fail "php: a version releases.json does not list is refused" "does not offer PHP" bash "$REL" component-bundle php --version 8.5.12 --out "$W/o3"
expect_fail "php: nothing is overwritten" "exists already" bash "$REL" component-bundle php --version 8.5.13 --out "$W/o1"
expect_fail "a bad version is refused" "must look like" bash "$REL" component-bundle php --version 8 --out "$W/o4"
expect_fail "an unknown component is refused" "unknown component" bash "$REL" component-bundle perl --version 1.2.3 --out "$W/o5"

# client: the release's SHA256SUMS
printf 'client zip' > "$W/in/benchy-client-0.15.0-windows-x64.zip"
csha=$(sha256sum "$W/in/benchy-client-0.15.0-windows-x64.zip" | cut -d' ' -f1)
echo "$csha  benchy-client-0.15.0-windows-x64.zip" > "$W/up/SHA256SUMS"
expect_ok "client: matches its release SHA256SUMS" bash "$REL" component-bundle client --version 0.15.0 --file "$W/in/benchy-client-0.15.0-windows-x64.zip" --out "$W/o6" --requires engine=0.12.0
echo "$(printf 'x%.0s' $(seq 64))  benchy-client-0.15.0-windows-x64.zip" > "$W/up/SHA256SUMS"
expect_fail "client: a zip the SHA256SUMS does not vouch for is refused" "SHA256SUMS" bash "$REL" component-bundle client --version 0.15.0 --file "$W/in/benchy-client-0.15.0-windows-x64.zip" --out "$W/o7"
echo "$csha  other.zip" > "$W/up/SHA256SUMS"
expect_fail "client: a zip the SHA256SUMS does not list is refused" "does not list" bash "$REL" component-bundle client --version 0.15.0 --file "$W/in/benchy-client-0.15.0-windows-x64.zip" --out "$W/o8"

# benchy tree: its CA bundle is the pin; curl.se's current one is compared
pin=$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["windows-x64"]["cacert"]["artifact"]["sha256"])' "$HERE/../../pts-core/static/update/runtime-pins.json")
mkdir -p "$W/tree/pts-core/static/update"
cp "$HERE/../../pts-core/static/update/cacert.pem" "$W/tree/pts-core/static/update/cacert.pem"
(cd "$W/tree" && zip -qr "$W/in/benchy-tree.zip" pts-core)
echo "$pin  cacert.pem" > "$W/up/cacert.pem.sha256"
expect_ok "benchy: the tree's CA bundle is the pin and curl.se's current one" bash "$REL" component-bundle benchy --version 0.13.1 --file "$W/in/benchy-tree.zip" --out "$W/o9"
echo "$(printf 'a%.0s' $(seq 64))  cacert.pem" > "$W/up/cacert.pem.sha256"
out=$(bash "$REL" component-bundle benchy --version 0.13.2 --file "$W/in/benchy-tree.zip" --out "$W/o10" 2>&1) && grep -q "WARNING: the tree's CA bundle" <<<"$out" && check "benchy: an older pinned bundle is a warning" ok || check "benchy: an older pinned bundle is a warning" "$out"
echo "$pin  cacert.pem" > "$W/up/cacert.pem.sha256"
printf 'changed\n' >> "$W/tree/pts-core/static/update/cacert.pem"
rm -f "$W/in/benchy-tree.zip"; (cd "$W/tree" && zip -qr "$W/in/benchy-tree.zip" pts-core)
expect_fail "benchy: a changed CA bundle is refused" "is not the pinned CA bundle" bash "$REL" component-bundle benchy --version 0.13.3 --file "$W/in/benchy-tree.zip" --out "$W/o11"

[ $fail = 0 ] && echo "COMPONENT BUNDLE: PASS" || { echo "COMPONENT BUNDLE: FAIL"; exit 1; }
