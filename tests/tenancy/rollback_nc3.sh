#!/bin/bash
# NC3 migration and rollback (named catalogs, schema step 68): the release before it (OLD, default v0.14.0, schema 67) makes two accounts, an enrolled agent and imports the public and
# private catalogs (throw-away test keys); this checkout migrates that database (every catalog row and pair kept, `private` granted to both accounts, a new tenant gets public only);
# OLD serves the migrated database; this checkout again imports a named catalog and the grants work. Driven by tenancy.sh (needs RUN, QC_NET, QC_IMAGE and QC_TREE) or run.sh rollback-nc3.
# Own server containers on a volume pair of their own, one after the other.
set -u
: "${QC_NET:?run via tenancy.sh}"
HERE=$(cd "$(dirname "$0")" && pwd)
REPO=$(cd "$HERE/../.." && pwd)
OLD=${OLD:-v0.14.0}
RB=${RUN}-rbnc3
NEWTREE=$(mktemp -d /tmp/benchy-qc-nc3tree.XXXXXX)
BUNDLES=$(mktemp -d /tmp/benchy-qc-nc3bundles.XXXXXX)
OLDTREE=$(mktemp -d /tmp/benchy-qc-nc3old.XXXXXX)
export TN_STATE=$(mktemp /tmp/benchy-rbnc3-state.XXXXXX) TN_SRV=${RB}-srv OLD
trap 'docker rm -f "${RB}-srv" >/dev/null 2>&1; docker volume rm -f "${RB}-lib" "${RB}-cache" >/dev/null 2>&1; rm -rf "$OLDTREE" "$NEWTREE" "$BUNDLES" "$TN_STATE"' EXIT
cp -a "$QC_TREE"/. "$NEWTREE"/
chmod 777 "$BUNDLES"
docker run --rm --network none -u 1000 -v "$QC_TREE":/bench:ro -v "$BUNDLES":/out "$QC_IMAGE" php -d memory_limit=512M /bench/tests/catalog/make_named_bundles.php /out || exit 2
(cd "$REPO" && git archive "$OLD" | tar -x -C "$OLDTREE") || { echo "cannot export $OLD"; exit 2; }
cp "$BUNDLES/keys.json" "$NEWTREE/pts-core/static/catalog/keys.json"; cp "$BUNDLES/keys.json" "$OLDTREE/pts-core/static/catalog/keys.json"; rm -f "$BUNDLES/keys.json"
docker volume create "${RB}-lib" >/dev/null; docker volume create "${RB}-cache" >/dev/null
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
	local out; out=$(cd "$HERE" && python3 -W ignore rollback_nc3.py "$1" 2>&1 </dev/null | head -c 30000)
	printf '%s\n' "$out"
	grep -q '^FAILURES 0$' <<<"$out" || rc=1
}
serve "$OLDTREE" "$OLD: two accounts, an agent, the public and private catalogs"; phase old-first
serve "$NEWTREE" "this checkout migrates that database"; phase new-first
serve "$OLDTREE" "$OLD on the migrated database"; phase old-again
serve "$NEWTREE" "this checkout again: a named catalog, the grants"; phase new-again
serve "$OLDTREE" "$OLD after a named catalog was imported: what rolling back really does"; phase old-last
[ $rc -eq 0 ] && echo "NC3 MIGRATION CHECK: PASS" || echo "NC3 MIGRATION CHECK: FAIL"
exit $rc
