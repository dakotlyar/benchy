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
# start-phoromatic-server needs PHP sockets, which the plain scratch image doesn't have: a small image of its own on top of it, built once
SIMG=benchy-qc-sockets:$(docker image inspect -f '{{.Id}}' "$QC_IMAGE" | cut -c8-19)
docker image inspect "$SIMG" >/dev/null 2>&1 || printf 'FROM %s\nRUN docker-php-ext-install sockets\n' "$QC_IMAGE" | docker build -q -t "$SIMG" - >/dev/null || { echo "sockets image build failed"; exit 2; }
docker network create "${SV}-net" >/dev/null || exit 2
docker volume create "${SV}-lib" >/dev/null; docker volume create "${SV}-cache" >/dev/null
cp -a "$QC_TREE"/. "$NEWTREE"/
chmod 777 "$BUNDLES"
docker run --rm --network none -u 1000 -v "$QC_TREE":/bench:ro -v "$BUNDLES":/out "$QC_IMAGE" php -d memory_limit=512M /bench/tests/catalog/make_rollback_bundles.php /out || exit 2
cp "$BUNDLES/keys.json" "$NEWTREE/pts-core/static/catalog/keys.json"; rm -f "$BUNDLES/keys.json"
rc=0
start_server() {  # start_server <tree> <bundles dir>: the real launcher, as a service runs it (plain PHP built-in server with the router script)
	docker rm -f "${SV}-srv" >/dev/null 2>&1
	docker run -d --name "${SV}-srv" --network "${SV}-net" --network-alias benchy-srv -p 127.0.0.1::8080 -e PHOROMATIC_HTTP_PORT=8080 -e PTS_FORCE_DAEMONIZED=1 \
		-v "${SV}-lib":/var/lib/benchy -v "${SV}-cache":/var/cache/benchy -v "$1":/bench:ro -v "$2":/bundles:ro -w /bench "$SIMG" \
		sh -c 'sh /bench/benchy start-phoromatic-server >/tmp/launcher.out 2>&1; sleep 3600' >/dev/null || exit 2
	export TN_PORT=$(docker port "${SV}-srv" 8080/tcp | head -1 | sed 's/.*://')
	for i in $(seq 1 60); do curl -s -o /dev/null "http://127.0.0.1:$TN_PORT/" && break; sleep 1; done
	for i in $(seq 1 30); do docker exec "${SV}-srv" test -f /var/lib/benchy/phoromatic/phoromatic.db && break; sleep 1; done
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

# ---- 2. the end to end with a real engine
echo "== catalog serving (E4.4 C3), 2. a real engine (catalogtest build) enrolls, fetches the catalog, installs from a pack, and follows a second release"
ENGSRC=${QC_ENGINE_SRC:-$HOME/src/benchy-engine-ce}
pass() { echo "  PASS  $1"; }
fail() { echo "  FAIL  $1"; [ -n "${2:-}" ] && printf '%s\n' "$2" | head -c 1500 | sed 's/^/        | /'; rc=1; }
if [ ! -f "$ENGSRC/cmd/benchy-engine/main.go" ] || ! command -v go >/dev/null 2>&1; then
	echo "  SKIPPED: no benchy-engine checkout at $ENGSRC (set QC_ENGINE_SRC) or no Go: the end-to-end part did not run"
	[ $rc -eq 0 ] && echo "CATALOG SERVING: PASS (end to end skipped)" || echo "CATALOG SERVING: FAIL"
	exit $rc
fi
E2E=$(mktemp -d /tmp/benchy-qc-e2e.XXXXXX)
mkdir "$E2E/tree" "$E2E/bundles"; chmod 755 "$E2E" "$E2E/bundles"
# a copy of the engine tree in a temp directory: the checkout itself is never touched, and the helper that signs with a throw-away key goes into the copy
(cd "$ENGSRC" && tar --exclude=.git -cf - .) | tar -xf - -C "$E2E/tree" || exit 2
mkdir "$E2E/tree/cmd/c3-mkbundle"; cp "$HERE/e2e/mkbundle.go" "$E2E/tree/cmd/c3-mkbundle/main.go"
(cd "$E2E/tree" && CGO_ENABLED=0 go build -tags catalogtest -o "$E2E/engine-ct" ./cmd/benchy-engine && CGO_ENABLED=0 go build -o "$E2E/mkbundle" ./cmd/c3-mkbundle) || { fail "the engine did not build"; exit 1; }
out=$(docker run --rm --network none -v "$E2E":/e2e "$QC_IMAGE" /e2e/mkbundle /e2e/tree/testdata/authoring/good /e2e/bundles 2>&1)
[ "$out" = ok ] || { fail "mkbundle" "$out"; exit 1; }
SPKI=$(cat "$E2E/bundles/spki.txt")
# a fresh server whose key table holds only that key
docker rm -f "${SV}-srv" >/dev/null 2>&1; docker volume rm -f "${SV}-lib" "${SV}-cache" >/dev/null 2>&1; docker volume create "${SV}-lib" >/dev/null; docker volume create "${SV}-cache" >/dev/null
printf '{"schema":"benchy-catalog-keys/1","keys":[{"generation":1,"spki":"%s"}],"denied_key_ids":[],"floor":{}}' "$SPKI" > "$NEWTREE/pts-core/static/catalog/keys.json"
start_server "$NEWTREE" "$E2E/bundles"
sv() { docker exec -i "${SV}-srv" "$@" 2>&1 </dev/null | head -c 4000; }
printf '%s\n' qcpass12345 | docker exec -i "${SV}-srv" sh /bench/benchy server-account-create --user e2eadmin --email e@example.com --account-id E2EAAA 2>&1 | grep -q "Account created" && pass "an account" || fail "account creation"
sv php /bench/tests/private-repo/agent_admin.php allow-http on >/dev/null
CODE=$(sv php /bench/tests/private-repo/agent_admin.php code E2EAAA e2e - native | sed -n 's/.*"code":"\([^"]*\)".*/\1/p')
out=$(sv sh /bench/benchy server-catalog-import /bundles/one.zip); case "$out" in *"Imported the free catalog: generation 1, sequence 1"*) pass "the engine-built, throw-away-signed bundle imports (real pack and index)";; *) fail "import of one.zip" "$out";; esac
docker run -d --name "${SV}-eng" --network "${SV}-net" -e HOME=/tmp/h -e SSL_CERT_FILE=/tmp/c.pem -e BENCHY_CATALOGTEST_SPKI="$SPKI" -v "$E2E":/e2e:ro -v "$HERE/e2e":/tools:ro "$QC_IMAGE" sleep 900 >/dev/null || exit 2
eng() { docker exec "${SV}-eng" "$@" 2>&1 </dev/null | head -c 6000; }
# the engine speaks https only: a TLS front, inside the engine's own container, with a certificate it is told to trust
eng sh -c 'mkdir -p /tmp/h; openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -nodes -keyout /tmp/k.pem -out /tmp/c.pem -subj /CN=localhost -addext subjectAltName=DNS:localhost,IP:127.0.0.1 -days 2 2>/dev/null' >/dev/null
docker exec -d "${SV}-eng" sh -c 'python3 /tools/tlsproxy.py /tmp/c.pem /tmp/k.pem 8443 benchy-srv:8080 2>/tmp/proxy.log'
sleep 2
out=$(eng /e2e/engine-ct --json agent enroll https://localhost:8443 --code "$CODE"); case "$out" in *'"type":"agent_enrolled"'*) pass "the engine enrolls";; *) fail "enroll" "$out";; esac
docker exec -d "${SV}-eng" sh -c '/e2e/engine-ct --json agent run > /tmp/agent.log 2>&1'
seq_of() { eng /e2e/engine-ct --json catalog status | grep '"type":"catalog_status"' | grep -o '"sequence":[0-9]*' | head -1; }
for i in $(seq 1 20); do [ "$(seq_of)" = '"sequence":1' ] && break; sleep 2; done
[ "$(seq_of)" = '"sequence":1' ] && pass "hello announced the tier; the engine fetched index.signed through the route and accepted it (public, sequence 1)" || fail "the engine did not accept the first release" "$(eng /e2e/engine-ct --json catalog status; tail -3 /tmp/agent.log)"
eng grep -c 'GET /api/v2/catalog/public/index.signed' /tmp/proxy.log | grep -q '^1$' && pass "one index fetch, no repeats" || fail "index fetches" "$(eng cat /tmp/proxy.log)"
caps=$(sv php -r '$d=new SQLite3("/var/lib/benchy/phoromatic/phoromatic.db");echo $d->querySingle("SELECT AgentCapabilities FROM phoromatic_systems WHERE AccountID=\"E2EAAA\"");')
case "$caps" in *catalog_v1*) pass "the server kept the engine's capabilities ($caps)";; *) fail "capabilities" "$caps";; esac
out=$(eng /e2e/engine-ct --json install benchy/hello-bench)
case "$out" in *'"type":"download_start"'*|*'"type":"download_end"'*) pass "install: the pack came through the server's pack route and was verified and unpacked (the test then wants its own download, which this network refuses)";; *) fail "install" "$out";; esac
eng grep -c 'GET /api/v2/catalog/packs/' /tmp/proxy.log | grep -q '^1$' && pass "the pack was fetched once from /api/v2/catalog/packs/" || fail "pack fetch" "$(eng cat /tmp/proxy.log)"
sleep 2
out=$(sv sh /bench/benchy server-catalog-import /bundles/two.zip); case "$out" in *"sequence 2"*) pass "the second release is imported";; *) fail "import of two.zip" "$out";; esac
for i in $(seq 1 40); do [ "$(seq_of)" = '"sequence":2' ] && break; sleep 5; done
[ "$(seq_of)" = '"sequence":2' ] && pass "the agent's next poll said hello again; it refreshed to sequence 2 with no one touching it (rehello, design 5.3)" || fail "the agent did not follow the second release" "$(tail -3 /tmp/agent.log; eng cat /tmp/proxy.log)"
[ $rc -eq 0 ] && echo "CATALOG SERVING: PASS" || echo "CATALOG SERVING: FAIL"
exit $rc
