#!/bin/bash
# E4.4 C2 rollback: this checkout (its key table swapped for throw-away test keys) makes an account and imports both catalog tiers; the release before the catalog (OLD, default v0.9.14, then
# v0.9.13) serves that schema-65 database and must leave the catalog alone; this checkout again finds it byte for byte as it was and carries on. Driven by tenancy.sh (needs RUN,
# QC_NET, QC_IMAGE and QC_TREE). Own server containers on a volume pair of their own, one after the other.
set -u
: "${QC_NET:?run via tenancy.sh}"
HERE=$(cd "$(dirname "$0")" && pwd)
REPO=$(cd "$HERE/../.." && pwd)
OLDS=${OLD:-v0.9.14 v0.9.13}   # the release before this one, and the one before that (OLD=<ref> runs just one)
RB=${RUN}-rbe44
NEWTREE=$(mktemp -d /tmp/benchy-qc-e44tree.XXXXXX)
BUNDLES=$(mktemp -d /tmp/benchy-qc-e44bundles.XXXXXX)
OLDTREE=$(mktemp -d /tmp/benchy-qc-oldtree.XXXXXX)
export TN_STATE=$(mktemp /tmp/benchy-rbe44-state.XXXXXX) TN_SRV=${RB}-srv
trap 'docker rm -f "${RB}-srv" >/dev/null 2>&1; docker volume rm -f "${RB}-lib" "${RB}-cache" >/dev/null 2>&1; rm -rf "$OLDTREE" "$NEWTREE" "$BUNDLES" "$TN_STATE"' EXIT
cp -a "$QC_TREE"/. "$NEWTREE"/
chmod 777 "$BUNDLES"
docker run --rm --network none -u 1000 -v "$QC_TREE":/bench:ro -v "$BUNDLES":/out "$QC_IMAGE" php -d memory_limit=512M /bench/tests/catalog/make_rollback_bundles.php /out || exit 2
cp "$BUNDLES/keys.json" "$NEWTREE/pts-core/static/catalog/keys.json"; rm -f "$BUNDLES/keys.json"
rc=0
serve() {  # serve <tree> <label>
	docker rm -f "${RB}-srv" >/dev/null 2>&1
	docker run -d --name "${RB}-srv" --network "$QC_NET" -p 127.0.0.1::8080 -e PHP_CLI_SERVER_WORKERS=8 \
		-v "${RB}-lib":/var/lib/benchy -v "${RB}-cache":/var/cache/benchy -v "$1":/bench:ro -v "$BUNDLES":/bundles:ro -w /bench/pts-core/phoromatic/public_html \
		"$QC_IMAGE" php -d display_errors=0 -d post_max_size=16M -d upload_max_filesize=16M -S 0.0.0.0:8080 -t /bench/pts-core/phoromatic/public_html >/dev/null || exit 2
	export TN_PORT=$(docker port "${RB}-srv" 8080/tcp | head -1 | sed 's/.*://')
	for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$TN_PORT/" && break; sleep 1; done
	echo "== $2"
}
phase() {
	local out; out=$(cd "$HERE" && python3 -W ignore rollback_e44.py "$1" 2>&1 </dev/null | head -c 20000)
	printf '%s\n' "$out"
	grep -q '^FAILURES 0$' <<<"$out" || rc=1
}
for OLD in $OLDS; do
	export OLD
	docker rm -f "${RB}-srv" >/dev/null 2>&1; docker volume rm -f "${RB}-lib" "${RB}-cache" >/dev/null 2>&1; docker volume create "${RB}-lib" >/dev/null; docker volume create "${RB}-cache" >/dev/null
	rm -rf "$OLDTREE"/* "$OLDTREE"/.[!.]* 2>/dev/null
	(cd "$REPO" && git archive "$OLD" | tar -x -C "$OLDTREE") || { echo "cannot export $OLD"; exit 2; }
	serve "$NEWTREE" "this checkout: an account, both catalog tiers imported"; phase new-first
	serve "$OLDTREE" "$OLD on that data: it leaves the catalog tables and files alone"; phase old
	serve "$NEWTREE" "this checkout again: the catalog is as it was, importing carries on"; phase new-again
done
[ $rc -eq 0 ] && echo "E4.4 ROLLBACK CHECK: PASS" || echo "E4.4 ROLLBACK CHECK: FAIL"
exit $rc
