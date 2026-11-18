#!/bin/bash
# Tenancy checks (0.9.10), driven by run.sh (tests/private-repo/run.sh tenancy). Needs RUN, QC_NET, QC_IMAGE and QC_TREE (the committed checkout) from there.
#   1. static: the SQL scope lint, the path lint, the route inventory, the gate lint (T1) and the self-test that shows each of them fails on what it should
#   2. a scratch server of its own with ONE account: the server-administrator rule on a single-account server
#   3. the same server with a SECOND account full of canary values: cross-account reads and writes, settings, v1 clients, log-in, tenant state, roles
#   4. migration.sh: the schema 59 and 60 steps on 58 databases built by the 0.9.10 code (one account, two, half done, eight racing requests, no backup)
#   5. mirror.sh (official profiles from the package copy), 6. storage.sh (a configured storage folder that cannot be written is a start-up error)
#   7. rollback_t3.sh (the private repository across the release before T3, this checkout, the old release again)
#   8. rollback_t4.sh (accounts made in the server console, in each state, served by the release before T4 as plain accounts, and then by this checkout again)
#   9. rollback_t5.sh (memberships beyond the home account, a user turned off, levels changed here: the release before T5 sees the home account and the right levels, then this checkout again)
#  10. rollback_t6.sh (passwords: the release before T6 makes them, this checkout adds the modern hash, the old release changes two of them, this checkout takes the new ones)
#  11. rollback_e44.sh (the signed catalog, E4.4 C2: both tiers imported with throw-away test keys, the release before it serves that database and leaves the catalog alone, this checkout again)
#  12. rollback_drop.sh (the legacy password hash drop, schema 66: the step on users in each state and root, the release before it refusing every sign-in while an agent works, the recovery, this checkout again)
#  13. nombstring.sh (a PHP without mbstring: the server console and the activity page)
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
	local out; out=$(timeout 2400 "$@" 2>&1 </dev/null | head -c 400000; exit "${PIPESTATUS[0]}"); local st=$?
	printf '%s\n' "$out"
	[ $st -eq 0 ] || { echo "FAILED: $label (exit $st)"; rc=1; }
}

# TN_ONLY=<test file pattern, e.g. 'test_t2_*.py'> runs just the fixtures and those two-account tests (for working on one file); never in the real gate
quick() { [ -n "${TN_ONLY:-}" ]; }
echo "== tenancy 1. static lints"
quick || { for l in lint_sql lint_paths lint_routes lint_gates lint_passwords; do capped "$l" python3 -W ignore "$HERE/$l.py" --root "$QC_TREE"; done
capped "lint self-test" python3 -W ignore -m unittest discover -s "$HERE" -p 'lint_selftest.py'; }

docker run -d --name "$TN_SRV" --network "$QC_NET" --cap-add SYS_ADMIN -p 127.0.0.1::8080 -e PHP_CLI_SERVER_WORKERS=8 -e PTS_PHOROMATIC_LOG_LOCATION=/tmp/tn-server.log \
	-v "${TN}-lib":/var/lib/benchy -v "${TN}-cache":/var/cache/benchy -v "$QC_TREE":/bench:ro -w /bench/pts-core/phoromatic/public_html \
	"$QC_IMAGE" php -d display_errors=0 -d post_max_size=16M -d upload_max_filesize=16M -d "sendmail_path=sh /bench/tests/tenancy/tn_sendmail.sh" -S 0.0.0.0:8080 -t /bench/pts-core/phoromatic/public_html >/dev/null || exit 2
export TN_PORT=$(docker port "$TN_SRV" 8080/tcp | head -1 | sed 's/.*://')
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$TN_PORT/" && break; sleep 1; done

echo "== tenancy 1b. there is no registration form (a fresh server, an upgraded one, an old explicit open, server-registration open refused): a server of its own"
export TN_REG_SRV=${TN}-reg
docker volume create "${TN}-reglib" >/dev/null
docker run -d --name "$TN_REG_SRV" --network "$QC_NET" -p 127.0.0.1::8080 -e PHP_CLI_SERVER_WORKERS=4 \
	-v "${TN}-reglib":/var/lib/benchy -v "$QC_TREE":/bench:ro -w /bench/pts-core/phoromatic/public_html \
	"$QC_IMAGE" php -d display_errors=0 -S 0.0.0.0:8080 -t /bench/pts-core/phoromatic/public_html >/dev/null || exit 2
export TN_REG_PORT=$(docker port "$TN_REG_SRV" 8080/tcp | head -1 | sed 's/.*://')
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$TN_REG_PORT/" && break; sleep 1; done
quick || capped "registration tests" python3 -W ignore -m unittest discover -s "$HERE" -p 'phase_0_*.py' -v
docker rm -f "$TN_REG_SRV" >/dev/null 2>&1; docker volume rm -f "${TN}-reglib" >/dev/null 2>&1

echo "== tenancy 2. one account (the single-account server keeps today's behavior)"
cd "$HERE" || exit 2
capped "fixture a" python3 -W ignore fixture.py a
capped "single-account tests" python3 -W ignore -m unittest discover -s "$HERE" -p 'phase_a_*.py' -v

echo "== tenancy 3. two accounts"
capped "fixture b" python3 -W ignore fixture.py b
capped "two-account tests" python3 -W ignore -m unittest discover -s "$HERE" -p "${TN_ONLY:-test_*.py}" -v
quick && { [ $rc -eq 0 ] && echo "TENANCY (quick): PASS" || echo "TENANCY (quick): FAIL"; exit $rc; }
echo "== tenancy 4. the schema 59 migration (fixtures built by the 0.9.10 code)"
RUN=$RUN bash "$HERE/migration.sh" || { echo "FAILED: migration"; rc=1; }
echo "== tenancy 5. the mirror serves official profiles from the package copy only (own server, non-root user)"
RUN=$RUN bash "$HERE/mirror.sh" || { echo "FAILED: mirror"; rc=1; }
echo "== tenancy 6. a PhoromaticStorage that cannot be written is a start-up error, not a fall-back"
RUN=$RUN bash "$HERE/storage.sh" || { echo "FAILED: storage"; rc=1; }
echo "== tenancy 7. rollback of T3: the release before it, this checkout, the old release again, this checkout again, on one account's private repository"
RUN=$RUN bash "$HERE/rollback_t3.sh" || { echo "FAILED: T3 rollback"; rc=1; }
echo "== tenancy 8. rollback of T4: console-made accounts in each state, the release before it, this checkout again"
RUN=$RUN bash "$HERE/rollback_t4.sh" || { echo "FAILED: T4 rollback"; rc=1; }
echo "== tenancy 9. rollback of T5: a user of two accounts, a level changed, a user turned off, the release before it, this checkout again"
RUN=$RUN bash "$HERE/rollback_t5.sh" || { echo "FAILED: T5 rollback"; rc=1; }
echo "== tenancy 10. rollback of T6: passwords made by the release before it, this checkout, the old release changing two, this checkout again"
RUN=$RUN bash "$HERE/rollback_t6.sh" || { echo "FAILED: T6 rollback"; rc=1; }
echo "== tenancy 11. rollback of the signed catalog (E4.4 C2): both tiers imported, the release before it leaves the catalog alone, this checkout again"
RUN=$RUN bash "$HERE/rollback_e44.sh" || { echo "FAILED: E4.4 rollback"; rc=1; }
echo "== tenancy 12. rollback of the legacy password hash drop: the step, the old release refusing everyone, the way back, this checkout again"
RUN=$RUN bash "$HERE/rollback_drop.sh" || { echo "FAILED: drop rollback"; rc=1; }
echo "== tenancy 13. a PHP without mbstring (an install-sh host): the server console and the activity page"
RUN=$RUN bash "$HERE/nombstring.sh" || { echo "FAILED: no-mbstring"; rc=1; }
[ $rc -eq 0 ] && echo "TENANCY: PASS" || echo "TENANCY: FAIL"
exit $rc
