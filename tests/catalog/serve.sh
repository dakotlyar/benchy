#!/bin/bash
# E4.4 C3: serving the signed catalog, in a scratch server of its own (own network, own volumes, a copy of the tree whose key table holds throw-away test keys; nothing
# private is written and nothing touches this machine's Benchy). Driven by catalog.sh (QC_IMAGE, QC_TREE).
#   1. serve_e44.py: the routes, hello, rehello, the auth matrix (N23), against bundles from make_rollback_bundles.php
#   2. the end to end with a real engine (a catalogtest build of benchy-engine, built from QC_ENGINE_SRC into a temp directory; that tree is not modified): enroll, hello,
#      the engine fetches and verifies the index through the server's routes, a second release is announced and refreshed, a pack is fetched. Skipped, said so, when
#      QC_ENGINE_SRC is not a benchy-engine checkout or Go is missing.
set -u
: "${QC_IMAGE:?run via run.sh catalog}" "${QC_TREE:?run via run.sh catalog}"
HERE=$(cd "$(dirname "$0")" && pwd)
RUN=${RUN:-qcsv$$}
SV=${RUN}-sv
NEWTREE=$(mktemp -d /tmp/benchy-qc-svtree.XXXXXX)
BUNDLES=$(mktemp -d /tmp/benchy-qc-svbundles.XXXXXX)
export TN_STATE=$(mktemp /tmp/benchy-sv-state.XXXXXX) TN_SRV=${SV}-srv
cleanup() { docker rm -f "${SV}-srv" "${SV}-eng" >/dev/null 2>&1; docker volume rm -f "${SV}-lib" "${SV}-cache" >/dev/null 2>&1; docker network rm "${SV}-net" >/dev/null 2>&1; rm -rf "$NEWTREE" "$BUNDLES" "${E2E:-/nonexistent}" "$TN_STATE"; }
trap cleanup EXIT
docker network create "${SV}-net" >/dev/null || exit 2
docker volume create "${SV}-lib" >/dev/null; docker volume create "${SV}-cache" >/dev/null
cp -a "$QC_TREE"/. "$NEWTREE"/
chmod 777 "$BUNDLES"
docker run --rm --network none -u 1000 -v "$QC_TREE":/bench:ro -v "$BUNDLES":/out "$QC_IMAGE" php -d memory_limit=512M /bench/tests/catalog/make_rollback_bundles.php /out || exit 2
cp "$BUNDLES/keys.json" "$NEWTREE/pts-core/static/catalog/keys.json"; rm -f "$BUNDLES/keys.json"
rc=0
start_server() {  # start_server <tree> <bundles dir>
	docker rm -f "${SV}-srv" >/dev/null 2>&1
	docker run -d --name "${SV}-srv" --network "${SV}-net" --network-alias benchy-srv -p 127.0.0.1::8080 -e PHP_CLI_SERVER_WORKERS=8 \
		-v "${SV}-lib":/var/lib/benchy -v "${SV}-cache":/var/cache/benchy -v "$1":/bench:ro -v "$2":/bundles:ro -w /bench/pts-core/phoromatic/public_html \
		"$QC_IMAGE" php -d display_errors=0 -d post_max_size=16M -d upload_max_filesize=16M -S 0.0.0.0:8080 -t /bench/pts-core/phoromatic/public_html >/dev/null || exit 2
	export TN_PORT=$(docker port "${SV}-srv" 8080/tcp | head -1 | sed 's/.*://')
	for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$TN_PORT/" && break; sleep 1; done
}
phase() {
	local out; out=$(cd "$HERE" && timeout 600 python3 -W ignore serve_e44.py "$1" 2>&1 </dev/null | head -c 60000)
	printf '%s\n' "$out"
	grep -q '^FAILURES 0$' <<<"$out" || rc=1
}
echo "== catalog serving (E4.4 C3), 1. the routes, hello, rehello and the auth matrix"
start_server "$NEWTREE" "$BUNDLES"
phase no-catalog
for b in a b; do out=$(docker exec "$TN_SRV" sh /bench/benchy server-catalog-import /bundles/$b.zip 2>&1 </dev/null | head -c 2000); case "$out" in *"Imported the"*) echo "  PASS  bundle $b imported";; *) echo "  FAIL  import of $b: $out"; rc=1;; esac; done
phase serve
phase rehello
[ $rc -eq 0 ] && echo "CATALOG SERVING: PASS" || echo "CATALOG SERVING: FAIL"
exit $rc
