#!/bin/bash
# T3 rollback: a server of the release before T3 (OLD, default 0.9.13) holds an account's private repository; this checkout takes the data over (the
# view is built and the shared copy removed), the old release runs on it again, and this checkout again. Driven by tenancy.sh (needs RUN, QC_NET,
# QC_IMAGE and QC_TREE) or by run.sh tenancy-rollback-t3. Own server containers on a volume pair of their own, one after the other.
set -u
: "${QC_NET:?run via tenancy.sh}"
HERE=$(cd "$(dirname "$0")" && pwd)
REPO=$(cd "$HERE/../.." && pwd)
OLD=${OLD:-895500abd}
RB=${RUN}-rbt3
OLDTREE=$(mktemp -d /tmp/benchy-qc-oldtree.XXXXXX)
export TN_STATE=$(mktemp /tmp/benchy-rbt3-state.XXXXXX) TN_SRV=${RB}-srv OLD
docker volume create "${RB}-lib" >/dev/null; docker volume create "${RB}-cache" >/dev/null
trap 'docker rm -f "${RB}-srv" >/dev/null 2>&1; docker volume rm -f "${RB}-lib" "${RB}-cache" >/dev/null 2>&1; rm -rf "$OLDTREE" "$TN_STATE"' EXIT
(cd "$REPO" && git archive "$OLD" | tar -x -C "$OLDTREE") || { echo "cannot export $OLD"; exit 2; }
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
	local out; out=$(cd "$HERE" && python3 -W ignore rollback_t3.py "$1" 2>&1 </dev/null | head -c 20000)
	printf '%s\n' "$out"
	grep -q '^FAILURES 0$' <<<"$out" || rc=1
}
serve "$OLDTREE" "$OLD: an account's private repository, uploaded and listed"; phase old-first
serve "$QC_TREE" "this checkout takes the data over"; phase new-first
serve "$OLDTREE" "$OLD again, on the data this checkout used"; phase old-again
serve "$QC_TREE" "this checkout again"; phase new-again
[ $rc -eq 0 ] && echo "T3 ROLLBACK CHECK: PASS" || echo "T3 ROLLBACK CHECK: FAIL"
exit $rc
