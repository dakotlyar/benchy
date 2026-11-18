#!/bin/bash
# T5 rollback: this checkout makes accounts in the server console and gives a user a membership in two of them, changes a level on the Users page and turns a
# user off; the release before T5 (OLD, default 0.9.13) sees each user in the home account only, at the level that was last set, and refuses the user who was turned
# off; this checkout again finds every membership where it was. Driven by tenancy.sh (needs RUN, QC_NET, QC_IMAGE and QC_TREE). Own server containers on a volume
# pair of their own, one after the other.
set -u
: "${QC_NET:?run via tenancy.sh}"
HERE=$(cd "$(dirname "$0")" && pwd)
REPO=$(cd "$HERE/../.." && pwd)
OLD=${OLD:-v0.9.13}
RB=${RUN}-rbt5
OLDTREE=$(mktemp -d /tmp/benchy-qc-oldtree.XXXXXX)
HELDTREE=$(mktemp -d /tmp/benchy-qc-heldtree.XXXXXX)   # this checkout without the legacy password hash drop (see hold_drop.sh)
export TN_STATE=$(mktemp /tmp/benchy-rbt5-state.XXXXXX) TN_SRV=${RB}-srv OLD
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
	local out; out=$(cd "$HERE" && python3 -W ignore rollback_t5.py "$1" 2>&1 </dev/null | head -c 20000)
	printf '%s\n' "$out"
	grep -q '^FAILURES 0$' <<<"$out" || rc=1
}
serve "$HELDTREE" "this checkout: two accounts made in the server console, a user who belongs to both, a level changed, a user turned off"; phase new-first
serve "$OLDTREE" "$OLD on that data: the home account of each user, the levels as last set"; phase old
serve "$HELDTREE" "this checkout again: every membership where it was, nothing lost"; phase new-again
[ $rc -eq 0 ] && echo "T5 ROLLBACK CHECK: PASS" || echo "T5 ROLLBACK CHECK: FAIL"
exit $rc
