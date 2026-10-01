#!/bin/bash
# Private-repo QC harness. Needs docker + python3 on the host; touches nothing outside throw-away containers/volumes.
#   tests/private-repo/run.sh            run everything
#   tests/private-repo/run.sh http       only the HTTP-level endpoint/security tests
#   tests/private-repo/run.sh web        only the web page tests (also part of http/all)
#   tests/private-repo/run.sh e2e        only the CLI end-to-end scenarios
# The repo is exported with `git archive HEAD` (committed state, correct file modes) and mounted read-only.
set -u
HERE=$(cd "$(dirname "$0")" && pwd)
REPO=$(cd "$HERE/../.." && pwd)
WHICH=${1:-all}
RUN=qcpr$$
export QC_IMAGE=${QC_IMAGE:-benchy-qc-php}
export QC_SRV=${RUN}-srv QC_NET=${RUN}-net QC_CLIENT_PREFIX=${RUN}-c
export QC_TREE=$(mktemp -d /tmp/benchy-qc-tree.XXXXXX)
export QC_SHARE=$(mktemp -d /tmp/benchy-qc-share.XXXXXX)
export QC_USER='qcuser1' QC_PASS='qcpass123'

cleanup() {
	docker rm -f "$QC_SRV" >/dev/null 2>&1
	docker ps -aq --filter "name=${RUN}-" | xargs -r docker rm -f >/dev/null 2>&1
	docker volume ls -q --filter "name=${RUN}-" | xargs -r docker volume rm -f >/dev/null 2>&1
	docker network rm "$QC_NET" >/dev/null 2>&1
	rm -rf "$QC_TREE" "$QC_SHARE"
}
trap cleanup EXIT

docker image inspect "$QC_IMAGE" >/dev/null 2>&1 || docker build -q -t "$QC_IMAGE" "$HERE" >/dev/null || { echo "image build failed"; exit 2; }
(cd "$REPO" && git archive HEAD | tar -x -C "$QC_TREE") || exit 2
docker network create "$QC_NET" >/dev/null || exit 2
docker volume create "${RUN}-lib" >/dev/null; docker volume create "${RUN}-cache" >/dev/null
# Runs as root like the production service, so the server uses /var/lib/benchy + /var/cache/benchy (the "daemonized" layout).
docker run -d --name "$QC_SRV" --network "$QC_NET" -p 127.0.0.1::8080 -e PHP_CLI_SERVER_WORKERS=8 \
	-v "${RUN}-lib":/var/lib/benchy -v "${RUN}-cache":/var/cache/benchy -v "$QC_TREE":/bench:ro -w /bench/pts-core/phoromatic/public_html \
	"$QC_IMAGE" php -d post_max_size=16M -d upload_max_filesize=16M -S 0.0.0.0:8080 -t /bench/pts-core/phoromatic/public_html >/dev/null || exit 2
export QC_PORT=$(docker port "$QC_SRV" 8080/tcp | head -1 | sed 's/.*://')
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$QC_PORT/" && break; sleep 1; done

# Create the account through the web UI, exactly as a user would.
J=$(mktemp)
curl -s -c "$J" "http://127.0.0.1:$QC_PORT/" >/dev/null
curl -s -b "$J" -X POST "http://127.0.0.1:$QC_PORT/?register" -d register_username=$QC_USER -d register_password=$QC_PASS \
	-d register_password_confirm=$QC_PASS -d register_email=qc@example.com | grep -q "Account Created" || { echo "account creation failed"; exit 2; }
rm -f "$J"
export QC_AID=$(docker exec "$QC_SRV" php -r '$d=new SQLite3("/var/lib/benchy/phoromatic/phoromatic.db"); echo $d->querySingle("SELECT AccountID FROM phoromatic_accounts ORDER BY CreatedOn ASC LIMIT 1");')
[ -n "$QC_AID" ] || { echo "no account id"; exit 2; }

# The upload/delete/rebuild endpoints need a repository token. Create one the way an administrator would, with the server-side command.
export QC_REPO_TOKEN=$(docker exec "$QC_SRV" sh /bench/benchy private-repo-token-create qc-harness 2>&1 | grep -o 'bpr_[A-Za-z0-9_-]\{43\}' | head -1)
[ -n "$QC_REPO_TOKEN" ] || { echo "token creation failed"; exit 2; }

rc=0
if [ "$WHICH" = all ] || [ "$WHICH" = http ]; then
	(cd "$HERE" && python3 -m unittest discover -s "$HERE" -p "test_*.py" -v) || rc=1
fi
if [ "$WHICH" = web ]; then
	(cd "$HERE" && python3 -m unittest discover -s "$HERE" -p "test_private_repo_web.py" -v) || rc=1
fi
if [ "$WHICH" = all ] || [ "$WHICH" = e2e ]; then
	bash "$HERE/e2e.sh" || rc=1
fi
exit $rc
