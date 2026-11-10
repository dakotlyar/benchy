#!/bin/bash
# Schema 59 (tenancy T1) migration checks, driven by tenancy.sh (needs RUN, QC_NET, QC_IMAGE and QC_TREE from run.sh). Server containers of their own on data
# volumes of their own; no shared server.
#   fixtures   the 0.9.10 code (git ref MIG_OLD, default v0.9.10, schema 58) builds a one-account and a two-account database
#   one        the one-account database upgraded, then the upgrade run twice and a version put back to 58 (N32)
#   two        the two-account database upgraded: no role, names, nobody reaches the server forms (N33)
#   half       a database whose upgrade stopped halfway (N32)
#   race       eight simultaneous first requests, three times over (N35)
#   nobackup   a backup that cannot be made stops the step and says why; the next request after the fault is gone does it
# Nothing runs on the host.
set -u
: "${QC_NET:?run via tenancy.sh}"
HERE=$(cd "$(dirname "$0")" && pwd)
REPO=$(cd "$HERE/../.." && pwd)
MIG_OLD=${MIG_OLD:-v0.9.10}
MG=${RUN}-mg
OLDTREE=$(mktemp -d /tmp/benchy-mg-oldtree.XXXXXX)
export TN_STATE=$(mktemp -u /tmp/benchy-mg-state.XXXXXX)
vols="${MG}-one ${MG}-two"
trap 'docker rm -f "${MG}-srv" >/dev/null 2>&1; for v in $(docker volume ls -q --filter "name=${MG}-"); do docker volume rm -f $v >/dev/null 2>&1; done; rm -rf "$OLDTREE" "$TN_STATE"' EXIT
(cd "$REPO" && git archive "$MIG_OLD" | tar -x -C "$OLDTREE") || { echo "cannot export $MIG_OLD"; exit 2; }
rc=0

serve() {  # serve <tree> <volume> [workers]: a server container on the volume, as root like the production service; waits for the port without sending a request
	docker rm -f "${MG}-srv" >/dev/null 2>&1
	docker run -d --name "${MG}-srv" --network "$QC_NET" -p 127.0.0.1::8080 -e PHP_CLI_SERVER_WORKERS=${3:-8} -v "$2":/var/lib/benchy -v "$1":/bench:ro -w /bench/pts-core/phoromatic/public_html \
		"$QC_IMAGE" php -d display_errors=0 -d log_errors=1 -d post_max_size=16M -S 0.0.0.0:8080 -t /bench/pts-core/phoromatic/public_html >/dev/null || exit 2
	export TN_SRV=${MG}-srv TN_PORT=$(docker port "${MG}-srv" 8080/tcp | head -1 | sed 's/.*://')
	for i in $(seq 1 30); do (exec 3<>/dev/tcp/127.0.0.1/$TN_PORT) 2>/dev/null && break; sleep 0.5; done
}
phase() {  # phase <name>: run one phase of migration_check.py and fail unless it ended with FAILURES 0
	local out; out=$(cd "$HERE" && timeout 600 python3 -W ignore migration_check.py "$1" 2>&1 </dev/null | head -c 30000)
	printf '%s\n' "$out"
	grep -q '^FAILURES 0$' <<<"$out" || rc=1
}
copy() {  # copy <from volume> <to volume>: the database as it was, with its files and modes
	docker volume create "$2" >/dev/null
	docker run --rm -v "$1":/from:ro -v "$2":/to "$QC_IMAGE" sh -c 'cp -a /from/. /to/' || exit 2
}

echo "== migration 0. the $MIG_OLD code builds the schema 58 fixtures"
for v in $vols; do docker volume create "$v" >/dev/null; done
serve "$OLDTREE" "${MG}-one"; curl -s -o /dev/null "http://127.0.0.1:$TN_PORT/"
docker exec "$TN_SRV" sh /bench/benchy server-registration open >/dev/null 2>&1 </dev/null
phase fixture-one
serve "$OLDTREE" "${MG}-two"; curl -s -o /dev/null "http://127.0.0.1:$TN_PORT/"
docker exec "$TN_SRV" sh /bench/benchy server-registration open >/dev/null 2>&1 </dev/null
phase fixture-two
docker rm -f "${MG}-srv" >/dev/null 2>&1

echo "== migration 1. one account at 58 (run twice, then a version put back)"
copy "${MG}-one" "${MG}-w1"; serve "$QC_TREE" "${MG}-w1"; phase one
echo "== migration 2. two accounts at 58"
copy "${MG}-two" "${MG}-w2"; serve "$QC_TREE" "${MG}-w2"; phase two
echo "== migration 3. a step that stopped halfway"
copy "${MG}-one" "${MG}-w3"; docker rm -f "${MG}-srv" >/dev/null 2>&1
serve "$OLDTREE" "${MG}-w3"; phase half-prepare
serve "$QC_TREE" "${MG}-w3"; phase half
echo "== migration 4. eight simultaneous first requests, three times"
for round in 1 2 3; do
	docker volume rm -f "${MG}-w4" >/dev/null 2>&1; copy "${MG}-one" "${MG}-w4"; serve "$QC_TREE" "${MG}-w4" 8; phase race
done
echo "== migration 5. a backup that cannot be made"
copy "${MG}-one" "${MG}-w5"; serve "$QC_TREE" "${MG}-w5"; phase nobackup
[ $rc -eq 0 ] && echo "MIGRATION: PASS" || echo "MIGRATION: FAIL"
exit $rc
