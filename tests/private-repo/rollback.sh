#!/bin/bash
# Rollback check for the newest schema step (58 -> 59, tenancy T1): upgrade a database made by the old release, use it, run the old code on it, upgrade again.
# Driven by run.sh (tests/private-repo/run.sh rollback); needs RUN, QC_NET, QC_IMAGE, QC_TREE (this checkout) and HERE from there.
# It starts its own server containers on a data volume of its own, one after the other: old, this checkout, old, this checkout.
# OLD is the git ref of the release to roll back to (default v0.9.3).
set -u
: "${QC_NET:?run via run.sh rollback}"
HERE=$(cd "$(dirname "$0")" && pwd)
REPO=$(cd "$HERE/../.." && pwd)
OLD=${OLD:-v0.9.10}
RB=${RUN}-rb
OLDTREE=$(mktemp -d /tmp/benchy-qc-oldtree.XXXXXX)
export RB_STATE=$(mktemp -u /tmp/benchy-qc-rbstate.XXXXXX)
docker volume create "${RB}-lib" >/dev/null; docker volume create "${RB}-cache" >/dev/null
trap 'docker rm -f "${RB}-srv" >/dev/null 2>&1; docker volume rm -f "${RB}-lib" "${RB}-cache" >/dev/null 2>&1; rm -rf "$OLDTREE" "$RB_STATE"' EXIT
(cd "$REPO" && git archive "$OLD" | tar -x -C "$OLDTREE") || { echo "cannot export $OLD"; exit 2; }
rc=0

serve() {  # serve <tree> <label>: a server container on the shared data volume, as root like the production service
	docker rm -f "${RB}-srv" >/dev/null 2>&1
	docker run -d --name "${RB}-srv" --network "$QC_NET" -p 127.0.0.1::8080 -e PHP_CLI_SERVER_WORKERS=8 \
		-v "${RB}-lib":/var/lib/benchy -v "${RB}-cache":/var/cache/benchy -v "$1":/bench:ro -w /bench/pts-core/phoromatic/public_html \
		"$QC_IMAGE" php -d post_max_size=16M -d upload_max_filesize=16M -S 0.0.0.0:8080 -t /bench/pts-core/phoromatic/public_html >/dev/null || exit 2
	export QC_SRV=${RB}-srv QC_PORT=$(docker port "${RB}-srv" 8080/tcp | head -1 | sed 's/.*://')
	for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$QC_PORT/" && break; sleep 1; done
	echo "== $2"
}
phase() {  # phase <name>: run one phase of rollback_check.py, show what it printed and fail unless it ended with FAILURES 0
	local out; out=$(cd "$HERE" && python3 -W ignore rollback_check.py "$1" 2>&1 </dev/null | head -c 20000)
	printf '%s\n' "$out"
	grep -q '^FAILURES 0$' <<<"$out" || rc=1
}

export QC_USER='qcuser1' QC_PASS='qcpass123'
serve "$OLDTREE" "$OLD, fresh"
J=$(mktemp)
curl -s -c "$J" "http://127.0.0.1:$QC_PORT/" >/dev/null
# registration is closed on a server nobody opened it on (0.9.10 and later; a release before it has no such command, and its registration is open)
docker exec "$QC_SRV" sh /bench/benchy server-registration open >/dev/null 2>&1 </dev/null
curl -s -b "$J" -X POST "http://127.0.0.1:$QC_PORT/?register" -d register_username=$QC_USER -d register_password=$QC_PASS \
	-d register_password_confirm=$QC_PASS -d register_email=qc@example.com | grep -q "Account Created" || { echo "account creation failed"; exit 2; }
rm -f "$J"
export QC_AID=$(docker exec "$QC_SRV" php -r '$d=new SQLite3("/var/lib/benchy/phoromatic/phoromatic.db"); echo $d->querySingle("SELECT AccountID FROM phoromatic_accounts ORDER BY CreatedOn ASC LIMIT 1");')
export QC_REPO_TOKEN=x   # only imported by the shared helpers; this check never uses the repository endpoints
phase old-pages
serve "$QC_TREE" "this checkout upgrades the $OLD database"; phase use-new
serve "$OLDTREE" "$OLD again, on the upgraded database"; phase old-after
serve "$QC_TREE" "this checkout again"; phase new-again
[ $rc -eq 0 ] && echo "ROLLBACK CHECK: PASS" || echo "ROLLBACK CHECK: FAIL"
exit $rc
