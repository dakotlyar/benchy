#!/bin/bash
# Tenancy checks (0.9.10), driven by run.sh (tests/private-repo/run.sh tenancy). Needs RUN, QC_NET, QC_IMAGE and QC_TREE (the committed checkout) from there.
#   1. static: the SQL scope lint, the path lint, the route inventory, the gate lint (T1) and the self-test that shows each of them fails on what it should
#   2. a scratch server of its own with ONE account: the server-administrator rule on a single-account server
#   3. the same server with a SECOND account full of canary values: cross-account reads and writes, settings, v1 clients, log-in, tenant state, roles
#   4. migration.sh: the schema 59 step on 58 databases built by the 0.9.10 code (one account, two, half done, eight racing requests, no backup)
# Nothing runs on the host: the server is a throw-away container on a volume of its own.
set -u
: "${QC_NET:?run via run.sh tenancy}"
HERE=$(cd "$(dirname "$0")" && pwd)
TN=${RUN}-tn
export TN_SRV=${TN}-srv TN_TREE=$QC_TREE
export TN_STATE=$(mktemp /tmp/benchy-tn-state.XXXXXX)
docker volume create "${TN}-lib" >/dev/null; docker volume create "${TN}-cache" >/dev/null
trap 'docker rm -f "$TN_SRV" "${TN}-reg" >/dev/null 2>&1; docker volume rm -f "${TN}-reglib" >/dev/null 2>&1; docker volume rm -f "${TN}-lib" "${TN}-cache" >/dev/null 2>&1; rm -f "$TN_STATE"' EXIT
rc=0
capped() {  # capped <label> <command...>: run it, show at most 60 KB of its output, remember a failure
	local label=$1; shift
	local out; out=$(timeout 900 "$@" 2>&1 </dev/null | head -c 60000; exit "${PIPESTATUS[0]}"); local st=$?
	printf '%s\n' "$out"
	[ $st -eq 0 ] || { echo "FAILED: $label (exit $st)"; rc=1; }
}

echo "== tenancy 1. static lints"
for l in lint_sql lint_paths lint_routes lint_gates; do capped "$l" python3 -W ignore "$HERE/$l.py" --root "$QC_TREE"; done
capped "lint self-test" python3 -W ignore -m unittest discover -s "$HERE" -p 'lint_selftest.py'

docker run -d --name "$TN_SRV" --network "$QC_NET" -p 127.0.0.1::8080 -e PHP_CLI_SERVER_WORKERS=8 -e PTS_PHOROMATIC_LOG_LOCATION=/tmp/tn-server.log \
	-v "${TN}-lib":/var/lib/benchy -v "${TN}-cache":/var/cache/benchy -v "$QC_TREE":/bench:ro -w /bench/pts-core/phoromatic/public_html \
	"$QC_IMAGE" php -d display_errors=0 -d post_max_size=16M -d upload_max_filesize=16M -S 0.0.0.0:8080 -t /bench/pts-core/phoromatic/public_html >/dev/null || exit 2
export TN_PORT=$(docker port "$TN_SRV" 8080/tcp | head -1 | sed 's/.*://')
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$TN_PORT/" && break; sleep 1; done

echo "== tenancy 1b. registration is closed unless opened (a fresh server, an upgraded one, server-registration open): a server of its own"
export TN_REG_SRV=${TN}-reg
docker volume create "${TN}-reglib" >/dev/null
docker run -d --name "$TN_REG_SRV" --network "$QC_NET" -p 127.0.0.1::8080 -e PHP_CLI_SERVER_WORKERS=4 \
	-v "${TN}-reglib":/var/lib/benchy -v "$QC_TREE":/bench:ro -w /bench/pts-core/phoromatic/public_html \
	"$QC_IMAGE" php -d display_errors=0 -S 0.0.0.0:8080 -t /bench/pts-core/phoromatic/public_html >/dev/null || exit 2
export TN_REG_PORT=$(docker port "$TN_REG_SRV" 8080/tcp | head -1 | sed 's/.*://')
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$TN_REG_PORT/" && break; sleep 1; done
capped "registration tests" python3 -W ignore -m unittest discover -s "$HERE" -p 'phase_0_*.py' -v
docker rm -f "$TN_REG_SRV" >/dev/null 2>&1; docker volume rm -f "${TN}-reglib" >/dev/null 2>&1
# the checks below register accounts through the web page
docker exec "$TN_SRV" sh /bench/benchy server-registration open >/dev/null 2>&1 </dev/null

echo "== tenancy 2. one account (the single-account server keeps today's behavior)"
cd "$HERE" || exit 2
capped "fixture a" python3 -W ignore fixture.py a
capped "single-account tests" python3 -W ignore -m unittest discover -s "$HERE" -p 'phase_a_*.py' -v

echo "== tenancy 3. two accounts"
capped "fixture b" python3 -W ignore fixture.py b
capped "two-account tests" python3 -W ignore -m unittest discover -s "$HERE" -p 'test_*.py' -v
echo "== tenancy 4. the schema 59 migration (fixtures built by the 0.9.10 code)"
RUN=$RUN bash "$HERE/migration.sh" || { echo "FAILED: migration"; rc=1; }
echo "== tenancy 5. the mirror serves official profiles from the package copy only (own server, non-root user)"
RUN=$RUN bash "$HERE/mirror.sh" || { echo "FAILED: mirror"; rc=1; }
[ $rc -eq 0 ] && echo "TENANCY: PASS" || echo "TENANCY: FAIL"
exit $rc
