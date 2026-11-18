#!/bin/bash
# D2 of the server discovery design (Benchy 0.10.0): join codes. Driven by run.sh d2 (QC_TREE, QC_IMAGE, HIMG, QC_SHARE, QC_CLIENT_PREFIX); it makes
# its own network and containers, no shared server. An authentication path, so the checks go from the class to the wire:
#   A  unit checks (d2_unit.php, a container with no network and a fresh data folder): the code, the vectors of the protocol README, the anchor per TLS
#      mode, making a code, enrolling with one (kind, account, once, expiry), the lockout, the cap across addresses and its pause, the Systems page
#      box, and the schema step 64 over a database at 63
#   B  a server container with the built-in HTTPS (internal CA), two accounts: the v2 enroll endpoint with a join code over real TLS, the command line,
#      the lockout and the cap over HTTP, the code decides the account, the check characters worked out the way a client does (d2_web.py api)
#   C  the Systems page: the box, shown once, the CSRF token and Origin rules, who may make one (d2_web.py page)
#   D  no code is in any log or file of the server afterwards
set -u
shopt -s lastpipe
: "${QC_TREE:?run via run.sh d2}" "${HIMG:?run via run.sh d2}" "${QC_SHARE:?}" "${QC_CLIENT_PREFIX:?}"
HERE=$(cd "$(dirname "$0")" && pwd)
PASS=0; FAIL=0; FAILED=()
check() {  # check "description" command...
	local d=$1 out; shift
	if out=$("$@" 2>&1); then PASS=$((PASS+1)); echo "  PASS  $d"; else FAIL=$((FAIL+1)); FAILED+=("$d"); echo "  FAIL  $d"; [ -n "$out" ] && printf '%s\n' "$out" | head -c 3000 | sed 's/^/        | /'; fi
}
tally() {  # echo the PASS/FAIL lines of a scenario and count them
	local line seen=0
	while IFS= read -r line; do
		case "$line" in
			"  PASS "*) PASS=$((PASS+1)); echo "$line";;
			"  FAIL "*) FAIL=$((FAIL+1)); FAILED+=("${line#  FAIL  }"); echo "$line";;
			"        |"*) echo "$line";;
			RESULT*) seen=1;;
			*) [ -n "$line" ] && echo "        ? $line";;
		esac
	done
	[ "$seen" = 1 ] || { FAIL=$((FAIL+1)); FAILED+=("a scenario printed no RESULT line (it crashed)"); echo "  FAIL  a scenario printed no RESULT line (it crashed)"; }
}
wait_for() {  # wait_for <seconds> <command...>
	local n=$1; shift
	for i in $(seq 1 "$n"); do "$@" >/dev/null 2>&1 && return 0; sleep 1; done
	return 1
}

RUN=${QC_CLIENT_PREFIX}-d2
NET=${RUN}net
SRV=${RUN}srv
VOL=${RUN}lib
cleanup() {
	docker ps -aq --filter "name=${RUN}" | xargs -r docker rm -f >/dev/null 2>&1
	docker volume rm -f "$VOL" >/dev/null 2>&1
	docker network rm "$NET" >/dev/null 2>&1
}
trap cleanup EXIT
docker network create "$NET" >/dev/null || { echo "d2 network failed"; exit 2; }

echo "== A. unit checks, in a throw-away container with a fresh data folder and no network"
docker run --rm --network none --memory 512m -e HOME=/root -e PTS_FORCE_DAEMONIZED=1 -v "$QC_TREE:/bench:ro" -v "$HERE:/h:ro" -w /bench "$HIMG" timeout 600 php /h/d2_unit.php 2>&1 | head -c 80000 | tally

echo "== setup: a server container with the built-in HTTPS (internal CA) and two accounts"
docker volume create "$VOL" >/dev/null
docker run -d --name "$SRV" --hostname d2host --network "$NET" --add-host d2srv:127.0.0.1 --memory 1500m -e PHOROMATIC_HTTP_PORT=39080 -e PTS_FORCE_DAEMONIZED=1 \
	-v "$VOL":/var/lib/benchy -v "$QC_TREE":/bench:ro -v "$HERE":/h:ro -w /bench "$HIMG" \
	sh -c "while true; do [ -f /tmp/hold ] || sh /bench/benchy start-phoromatic-server >/tmp/launcher.out 2>&1; sleep 1; done" >/dev/null || exit 2
srv() { docker exec "$SRV" sh -c "$*"; }
bs() { docker exec -e HOME=/root "$SRV" sh /bench/benchy "$@" 2>&1 </dev/null | grep -av '^MISSING\|^$\|kill: No such process\|^Benchy v\|NOTICE\|^ *$'; }
up_http() { docker exec "$SRV" curl -s -m 3 -o /dev/null -w '%{http_code}' "http://127.0.0.1:39080/server.php?phoromatic_info" | grep -q 200; }
up_https() { docker exec "$SRV" curl -sk -m 3 -o /dev/null -w '%{http_code}' "https://127.0.0.1:39443/server.php?phoromatic_info" | grep -q 200; }
wait_for 90 up_http || { echo "server did not start"; docker logs "$SRV" 2>&1 | tail -20; exit 2; }
wait_for 60 srv "test -f /var/lib/benchy/phoromatic/phoromatic.db" || exit 2
printf '%s\n' "$QC_PASS" | docker exec -i -e HOME=/root "$SRV" sh /bench/benchy server-account-create --user "$QC_USER" --email qc@example.com 2>&1 | grep -q "Account created" || { echo "account creation failed"; exit 2; }
printf '%s\n' "$QC_PASS" | docker exec -i -e HOME=/root "$SRV" sh /bench/benchy server-account-create --user qcsecond --email qc2@example.com --account-id QCBBBB --name "Second" 2>&1 | grep -q "Account created" || { echo "second account creation failed"; exit 2; }
bs server-caddy-install --archive /opt/caddy/caddy.tar.gz >/dev/null
bs server-https-set internal --names "d2srv,d2host,d2srv.lab.test,10.9.8.7" >/dev/null
srv "touch /var/lib/benchy/end-phoromatic-server"
wait_for 40 bash -c "! docker exec $SRV pgrep -f phoromatic-server-launcher >/dev/null"
sleep 2
wait_for 90 up_https || { echo "HTTPS did not come up"; docker logs "$SRV" 2>&1 | tail -5; exit 2; }
CA=/var/lib/benchy/phoromatic/tls/caddy-data/pki/authorities/local/root.crt
webrun() { docker exec -e "H_BASE=https://d2srv:39443" -e "H_CAFILE=$CA" -e "H_USER=$QC_USER" -e "H_PASS=$QC_PASS" -e D2_ACCOUNT_B=QCBBBB -e HOME=/root "$SRV" timeout 900 python3 -I /h/d2_web.py "$@" 2>&1 </dev/null; }
srv "rm -f /tmp/d2-codes"

echo "== B. the v2 enroll endpoint with a join code, over the built-in HTTPS"
webrun api | head -c 80000 | tally

echo "== C. the Systems page"
webrun page | head -c 80000 | tally

echo "== D. no code is left in a log or a file"
chk_d1() { srv "test -s /tmp/d2-codes && wc -l < /tmp/d2-codes"; }
check "D1 the run made codes (so the next check looks for something)" chk_d1
chk_d2() {
	local hits
	hits=$(docker exec "$SRV" sh -c "grep -rlaF -f /tmp/d2-codes /var/lib/benchy /var/log /tmp/launcher.out 2>/dev/null")
	[ -z "$hits" ] || { echo "a code was found in: $hits"; return 1; }
}
check "D2 neither the join codes nor the ordinary codes made by this run are in the server log, the launcher output, the database or any file under the storage folder (only their hashes are)" chk_d2

echo
echo "D2: $PASS passed, $FAIL failed"
[ "$FAIL" = 0 ] || { printf 'FAILED: %s\n' "${FAILED[@]}"; exit 1; }
