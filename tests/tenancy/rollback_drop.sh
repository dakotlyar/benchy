#!/bin/bash
# Legacy password hash drop rollback (0.10.0, schema 66): this checkout held before the drop makes an account, users in each state and an agent; this checkout as it is runs
# the step (no legacy value is left anywhere, nobody is locked out); the old release (OLD, default v0.9.15) on that data refuses every sign-in with the usual page while the agent
# works, and set-root-admin-password plus the console's Set password give access back; this checkout again takes those passwords and its first open sweeps what the old release wrote.
# Driven by tenancy.sh (needs RUN, QC_NET, QC_IMAGE and QC_TREE) or run.sh rollback-drop. Own server containers on a volume pair of their own, one after the other.
set -u
: "${QC_NET:?run via tenancy.sh}"
HERE=$(cd "$(dirname "$0")" && pwd)
REPO=$(cd "$HERE/../.." && pwd)
OLD=${OLD:-v0.9.15}
RB=${RUN}-rbdrop
OLDTREE=$(mktemp -d /tmp/benchy-qc-oldtree.XXXXXX)
HELDTREE=$(mktemp -d /tmp/benchy-qc-heldtree.XXXXXX)   # this checkout without the legacy password hash drop (see hold_drop.sh)
export TN_STATE=$(mktemp /tmp/benchy-rbdrop-state.XXXXXX) TN_SRV=${RB}-srv OLD
docker volume create "${RB}-lib" >/dev/null; docker volume create "${RB}-cache" >/dev/null
trap 'docker rm -f "${RB}-srv" >/dev/null 2>&1; docker volume rm -f "${RB}-lib" "${RB}-cache" >/dev/null 2>&1; rm -rf "$OLDTREE" "$HELDTREE" "$TN_STATE"' EXIT
(cd "$REPO" && git archive "$OLD" | tar -x -C "$OLDTREE") || { echo "cannot export $OLD"; exit 2; }
bash "$HERE/hold_drop.sh" "$QC_TREE" "$HELDTREE" || exit 2
rc=0
serve() {  # serve <tree> <label>
	docker rm -f "${RB}-srv" >/dev/null 2>&1
	docker run -d --name "${RB}-srv" --network "$QC_NET" -p 127.0.0.1::8080 -e PHP_CLI_SERVER_WORKERS=8 \
		-v "${RB}-lib":/var/lib/benchy -v "${RB}-cache":/var/cache/benchy -v "$1":/bench:ro -w /bench/pts-core/phoromatic/public_html \
		"$QC_IMAGE" php -d display_errors=0 -d post_max_size=16M -d upload_max_filesize=16M -S 0.0.0.0:8080 -t /bench/pts-core/phoromatic/public_html >/dev/null || exit 2
	export TN_PORT=$(docker port "${RB}-srv" 8080/tcp | head -1 | sed 's/.*://')
	for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$TN_PORT/" && break; sleep 1; done
	echo "== $2"
}
phase() {
	local out; out=$(cd "$HERE" && python3 -W ignore rollback_drop.py "$1" 2>&1 </dev/null | head -c 20000)
	printf '%s\n' "$out"
	grep -q '^FAILURES 0$' <<<"$out" || rc=1
}
serve "$HELDTREE" "this checkout held before the drop: an account, users with a trusted hash, with the legacy hash only and with a stale one, root with the legacy hash only, an agent"; phase held
serve "$QC_TREE" "this checkout: the drop step runs"; phase new
serve "$OLDTREE" "$OLD on that data: everyone is refused, the agent works, root and Set password give access back"; phase old
serve "$QC_TREE" "this checkout again: the passwords the old release set work, its legacy hashes are swept"; phase new-again
[ $rc -eq 0 ] && echo "DROP ROLLBACK CHECK: PASS" || echo "DROP ROLLBACK CHECK: FAIL"
exit $rc
