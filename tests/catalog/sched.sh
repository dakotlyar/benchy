#!/bin/bash
# E4.4 C4: schedules and the signed catalog, in a scratch server of its own (own network, own volumes, a copy of the tree whose key table holds throw-away test keys; nothing
# private is written and nothing touches this machine's Benchy). Driven by catalog.sh (QC_IMAGE, QC_TREE): sched_e44.py against the bundles of make_sched_bundles.php.
set -u
: "${QC_IMAGE:?run via run.sh catalog}" "${QC_TREE:?run via run.sh catalog}"
HERE=$(cd "$(dirname "$0")" && pwd)
RUN=${RUN:-qcsc$$}
SV=${RUN}-sc
NEWTREE=$(mktemp -d /tmp/benchy-qc-sctree.XXXXXX)
BUNDLES=$(mktemp -d /tmp/benchy-qc-scbundles.XXXXXX)
export TN_STATE=$(mktemp /tmp/benchy-sc-state.XXXXXX) TN_SRV=${SV}-srv
cleanup() { docker rm -f "${SV}-srv" >/dev/null 2>&1; docker volume rm -f "${SV}-lib" "${SV}-cache" >/dev/null 2>&1; docker network rm "${SV}-net" >/dev/null 2>&1; rm -rf "$NEWTREE" "$BUNDLES" "$TN_STATE" "$INI"; }
trap cleanup EXIT
SIMG=benchy-qc-sockets:$(docker image inspect -f '{{.Id}}' "$QC_IMAGE" | cut -c8-19)
docker image inspect "$SIMG" >/dev/null 2>&1 || printf 'FROM %s\nRUN docker-php-ext-install sockets\n' "$QC_IMAGE" | docker build -q -t "$SIMG" - >/dev/null || { echo "sockets image build failed"; exit 2; }
docker network create "${SV}-net" >/dev/null || exit 2
docker volume create "${SV}-lib" >/dev/null; docker volume create "${SV}-cache" >/dev/null
cp -a "$QC_TREE"/. "$NEWTREE"/
chmod 777 "$BUNDLES"
# A production php.ini has display_errors off; the bare image shows warnings, and the one PHP prints for a POST over post_max_size lands before the session starts
INI=$(mktemp /tmp/benchy-sc-ini.XXXXXX); printf 'display_errors=Off\n' > "$INI"; chmod 644 "$INI"
docker run --rm --network none -u 1000 -v "$QC_TREE":/bench:ro -v "$BUNDLES":/out "$QC_IMAGE" php -d memory_limit=512M /bench/tests/catalog/make_sched_bundles.php /out || exit 2
cp "$BUNDLES/keys.json" "$NEWTREE/pts-core/static/catalog/keys.json"; rm -f "$BUNDLES/keys.json"
docker run -d --name "${SV}-srv" --network "${SV}-net" --network-alias benchy-srv -p 127.0.0.1::8080 -e PHOROMATIC_HTTP_PORT=8080 -e PTS_FORCE_DAEMONIZED=1 \
	-v "${SV}-lib":/var/lib/benchy -v "${SV}-cache":/var/cache/benchy -v "$NEWTREE":/bench:ro -v "$BUNDLES":/bundles:ro -v "$INI":/usr/local/etc/php/conf.d/zz-benchy-qc.ini:ro -w /bench "$SIMG" \
	sh -c 'sh /bench/benchy start-phoromatic-server >/tmp/launcher.out 2>&1; sleep 3600' >/dev/null || exit 2
export TN_PORT=$(docker port "${SV}-srv" 8080/tcp | head -1 | sed 's/.*://')
for i in $(seq 1 60); do curl -s -o /dev/null "http://127.0.0.1:$TN_PORT/" && break; sleep 1; done
for i in $(seq 1 30); do docker exec "${SV}-srv" test -f /var/lib/benchy/phoromatic/phoromatic.db && break; sleep 1; done
echo "== catalog schedules (E4.4 C4): version pins, the issue rules, administrator tests, the level-1 rules"
out=$(cd "$HERE" && timeout 900 python3 -W ignore sched_e44.py "${1:-all}" 2>&1 </dev/null | head -c 100000)
printf '%s\n' "$out"
if grep -q '^FAILURES 0$' <<<"$out"; then echo "CATALOG SCHEDULES: PASS"; exit 0; fi
echo "CATALOG SCHEDULES: FAIL"; exit 1
