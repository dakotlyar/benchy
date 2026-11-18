#!/bin/bash
# One certificate for every name in the internal TLS mode (0.10; the NAT bug found 2026-11-17). Driven by run.sh: tests/private-repo/run.sh https-names
# (needs the QC_* environment and the benchy-qc-https image, HIMG, from run.sh).
#
# The field case: a server behind NAT (a Proxmox VM behind its host) with --names VMIP,HOSTIP. A client dials HOSTIP, the host forwards it
# to the VM, and the VM sees the connection arrive on VMIP. Caddy used to hold one certificate per name and, for a client that sends no
# SNI (anything that dials an IP), picked the certificate of the address the connection arrived on: VMIP's, never valid for HOSTIP.
# Here a small TCP forwarder in a container of its own plays the host (address GW): it passes each connection on to the server (VMIP), so the
# server sees it arrive on VMIP while the client dialed GW. The names are VMIP, GW and a host name. A client on the network dials GW.
#
# Phases (one server container, one data volume, trees swapped under it):
#   A. the release before the fix (OLD, default 0.9.15, fffbba63b): the bug is reproduced, a client is paired with the root fingerprint
#   B. this checkout on the same data: every name works with no SNI, the root and the paired client are unchanged, a name change, key hygiene
#   C. renewal: the launcher's check renews the leaf, the root does not change, the paired client keeps working
#   D. rollback to the old release (per-name behavior again, still a working server for the paired client), then forward again
set -u
shopt -s lastpipe
: "${QC_NET:?run via run.sh https-names}"
: "${HIMG:?run via run.sh https-names}"
HERE=$(cd "$(dirname "$0")" && pwd)
REPO=$(cd "$HERE/../.." && pwd)
RUN=${QC_SRV%-srv}
NSRV=${RUN}-nsrv
NVOL=${RUN}-nlib
NNET=${RUN}-natnet
OLD=${OLD:-fffbba63b}
PASS=0; FAIL=0; FAILED=()
OLD_TREE=$(mktemp -d /tmp/benchy-qc-oldtree.XXXXXX)
trap 'docker rm -f "$NSRV" >/dev/null 2>&1; docker ps -aq --filter "name=${RUN}-n" | xargs -r docker rm -f >/dev/null 2>&1; docker volume rm -f "$NVOL" >/dev/null 2>&1; docker network rm "$NNET" >/dev/null 2>&1; rm -rf "$OLD_TREE"' EXIT

check() {  # check "description" command...
	local d=$1 _o; shift
	if _o=$("$@" 2>&1); then PASS=$((PASS+1)); echo "  PASS  $d"; else FAIL=$((FAIL+1)); FAILED+=("$d"); echo "  FAIL  $d"; [ -n "$_o" ] && printf '%s\n' "$_o" | head -c 2500 | sed 's/^/        | /'; fi
}
contains() { grep -qaF -- "$1" <<<"$2" || { echo "expected to find: $1"; echo "in: ${2:0:700}"; return 1; }; }
clean() { grep -av '^MISSING\|^$\|kill: No such process\|^Benchy v\|NOTICE\|^ *$'; }
wait_for() { local n=$1; shift; for i in $(seq 1 "$n"); do "$@" >/dev/null 2>&1 && return 0; sleep 1; done; return 1; }
srv() { docker exec "$NSRV" sh -c "$*"; }
bs() { docker exec -e HOME=/root "$NSRV" sh /bench/benchy "$@" 2>&1 </dev/null | clean; }
TLS=/var/lib/benchy/phoromatic/tls
up_http() { docker exec "$NSRV" curl -s -m 3 -o /dev/null -w '%{http_code}' "http://127.0.0.1:39080/server.php?phoromatic_info" | grep -q 200; }
up_https() { docker exec "$NSRV" curl -sk -m 3 -o /dev/null -w '%{http_code}' "https://127.0.0.1:39443/server.php?phoromatic_info" | grep -q 200; }
# client <command...>: a throw-away container on the NAT network (its own address), uid 1000, with a HOME volume for the paired client
CVOL=${RUN}-nclient
client() { docker run --rm -i --network "$NNET" -u 1000 -e HOME=/home/bob -v "$CVOL:/home/bob" -v "$QC_TREE:/bench:ro" -v "$QC_SHARE:/share" -v "$HERE:/h:ro" "$HIMG" "$@" 2>&1 </dev/null; }

echo "== setup: the old release ($OLD), a NAT-like network and one server container"
(cd "$REPO" && git archive "$OLD" | tar -x -C "$OLD_TREE") || { echo "cannot export $OLD"; exit 2; }
docker network create "$NNET" >/dev/null || exit 2
docker volume create "$NVOL" >/dev/null; docker volume create "$CVOL" >/dev/null
docker run --rm -v "$CVOL:/home/bob" "$HIMG" chown 1000:1000 /home/bob
SUBNET=$(docker network inspect "$NNET" -f '{{(index .IPAM.Config 0).Subnet}}')
VMIP=$(python3 -c "import ipaddress,sys; n=ipaddress.ip_network('$SUBNET'); print(n.network_address+60)")
GW=$(python3 -c "import ipaddress,sys; n=ipaddress.ip_network('$SUBNET'); print(n.network_address+70)")
HOSTNAME_N=natsrv.lan
echo "     (server $VMIP, the forwarding "host" $GW, names $VMIP,$GW,$HOSTNAME_N)"
start_server() {  # start_server <tree> [env...]: the service as it runs under systemd, supervised by a loop; the same volume every time
	local tree=$1; shift
	docker rm -f "$NSRV" >/dev/null 2>&1
	docker run -d --name "$NSRV" --network "$NNET" --ip "$VMIP" --network-alias natsrv.lan -e PHOROMATIC_HTTP_PORT=39080 -e PTS_FORCE_DAEMONIZED=1 "$@" \
		-v "$NVOL":/var/lib/benchy -v "$tree":/bench:ro -v "$QC_SHARE":/share -v "$HERE":/h:ro -w /bench "$HIMG" \
		sh -c 'while true; do [ -f /tmp/hold ] || sh /bench/benchy start-phoromatic-server >/tmp/launcher.out 2>&1; sleep 1; done' >/dev/null || return 1
}
applied() { ! docker exec "$NSRV" test -f $TLS/reload-caddy; }
settle() { wait_for 40 applied; sleep 3; wait_for 40 up_https; }
# openssl against the server from a client on the NAT network. verify <address> [extra s_client args]: no SNI, the built-in root, the address checked
SERVER_ROOT=/share/natroot.crt
verify() { local a=$1; shift; client sh -c "openssl s_client -connect $a:39443 -noservername -verify_ip $a -CAfile $SERVER_ROOT -verify_return_error $* </dev/null 2>&1"; }
sans() { client sh -c "openssl s_client -connect $1:39443 -noservername </dev/null 2>/dev/null | openssl x509 -noout -ext subjectAltName"; }
leaf_fp() { client sh -c "openssl s_client -connect $1:39443 ${2:--noservername} </dev/null 2>/dev/null | openssl x509 -noout -fingerprint -sha256" | sed 's/.*=//'; }
leaf_serial() { client sh -c "openssl s_client -connect $1:39443 -noservername </dev/null 2>/dev/null | openssl x509 -noout -serial"; }
root_fp() { srv "openssl x509 -in $TLS/caddy-data/pki/authorities/local/root.crt -noout -fingerprint -sha256" | sed 's/.*=//; s/://g' | tr A-F a-f; }
chmod 777 "$QC_SHARE"

docker run -d --name "${RUN}-nhost" --network "$NNET" --ip "$GW" -v "$HERE:/h:ro" "$HIMG" python3 -I /h/tcp_relay.py 39443 "$VMIP" 39443 >/dev/null || exit 2
start_server "$OLD_TREE" || exit 2
wait_for 60 up_http || { echo "server did not start"; docker logs "$NSRV" 2>&1 | tail; exit 2; }
wait_for 60 srv "test -f /var/lib/benchy/phoromatic/phoromatic.db"
printf '%s\n' qcpass123 | docker exec -i -e HOME=/root "$NSRV" sh /bench/benchy server-account-create --user qcuser1 --email qc@example.com 2>&1 | grep -q "Account created" || { echo "account creation failed"; exit 2; }
AID=$(docker exec "$NSRV" php -r '$d=new SQLite3("/var/lib/benchy/phoromatic/phoromatic.db"); $d->busyTimeout(5000); echo $d->querySingle("SELECT AccountID FROM phoromatic_accounts ORDER BY CreatedOn ASC LIMIT 1");')
[ -n "$AID" ] || { echo "no account"; exit 2; }
bs server-caddy-install --archive /opt/caddy/caddy.tar.gz | grep -q "Installed Caddy" || { echo "caddy install failed"; exit 2; }
bs server-https-set internal --names "$VMIP,$GW,$HOSTNAME_N" >/dev/null
srv "touch /var/lib/benchy/end-phoromatic-server"; sleep 6
wait_for 90 up_https || { echo "HTTPS did not come up (old release)"; srv "tail -20 /tmp/launcher.out; tail -5 $TLS/caddy.log"; exit 2; }
srv "cp $TLS/caddy-data/pki/authorities/local/root.crt $SERVER_ROOT; chmod a+r $SERVER_ROOT"
ROOT0=$(root_fp)

echo "== A. the old release: the bug is there (this is the regression the fix has to remove)"
check "A1. a client that dials the server's own address with no SNI gets a certificate that verifies" contains "Verify return code: 0 (ok)" "$(verify "$VMIP")"
out=$(verify "$GW")
check "A2. REPRODUCED: a client that dials the host/NAT address with no SNI is refused (the certificate it gets is the arrival address's)" eval 'grep -qi "verify error\|Verify return code" <<<"$out" && ! grep -q "Verify return code: 0" <<<"$out"'
check "A3. ...because that certificate carries one name only" eval 'o=$(sans "$VMIP"); ! grep -q "$GW" <<<"$o"'
out=$(client sh -c "curl -s -m 10 -o /dev/null -w '%{http_code}' --cacert $SERVER_ROOT https://$GW:39443/server.php?phoromatic_info")
check "A4. ...so curl against the host/NAT address fails certificate verification" test "$out" != 200
out=$(client sh /bench/benchy server-trust "https://$VMIP:39443/$AID" --fingerprint "$(echo "$ROOT0" | sed 's/../&:/g; s/:$//')" | clean)
check "A5. a client is paired with the root fingerprint (server-trust --fingerprint)" contains "Saved the CA" "$out"
CAPEM=/home/bob/.benchy/server-ca.pem
paired_get() { client sh -c "curl -s -m 10 -o /dev/null -w '%{http_code}' --cacert $CAPEM https://$1:39443/server.php?phoromatic_info"; }
check "A6. the paired client reaches the server on the address it was paired with" test "$(paired_get "$VMIP")" = 200

echo "== B. upgrade to this checkout on the same data"
start_server "$QC_TREE" || exit 2
wait_for 90 up_https || { echo "HTTPS did not come up after the upgrade"; srv "tail -20 /tmp/launcher.out; tail -5 $TLS/caddy.log"; exit 2; }
sleep 2
check "B1. the root certificate (and its fingerprint) is the one the old release made" test "$(root_fp)" = "$ROOT0"
check "B2. a client that dials the server's own address with no SNI verifies" contains "Verify return code: 0 (ok)" "$(verify "$VMIP")"
check "B3. THE FIX: a client that dials the host/NAT address with no SNI verifies" contains "Verify return code: 0 (ok)" "$(verify "$GW")"
out=$(sans "$GW")
check "B4. the one certificate carries every name (both addresses and the host name)" eval 'grep -q "IP Address:$VMIP" <<<"$out" && grep -q "IP Address:$GW" <<<"$out" && grep -q "DNS:$HOSTNAME_N" <<<"$out"'
check "B5. both addresses are served the same certificate" test "$(leaf_fp "$VMIP")" = "$(leaf_fp "$GW")"
check "B6. the host name works with SNI, and is the same certificate" test "$(leaf_fp "$VMIP" "-servername $HOSTNAME_N")" = "$(leaf_fp "$GW")"
check "B7. the client paired under the old release still trusts the server, on both addresses (no action needed)" eval '[ "$(paired_get $VMIP)" = 200 ] && [ "$(paired_get $GW)" = 200 ]'
check "B8. the plain-HTTP side and PHP behind Caddy still answer" up_http
check "B9. a client sending an unknown SNI still gets the certificate (default_sni) and the root verifies it" contains "Verify return code: 0 (ok)" "$(client sh -c "openssl s_client -connect $GW:39443 -servername other.example -CAfile $SERVER_ROOT -verify_hostname $HOSTNAME_N </dev/null 2>&1")"
check "B10. the leaf key and certificate are 0600, the tls directory 0700, all with one owner" eval '[ "$(docker exec $NSRV stat -c "%a %U" $TLS/internal-leaf.key)" = "600 root" ] && [ "$(docker exec $NSRV stat -c %a $TLS/internal-leaf.pem)" = 600 ] && [ "$(docker exec $NSRV stat -c %a $TLS)" = 700 ]'
check "B11. no private key (leaf or root) is in the logs, the launcher output, the audit log or the settings" eval '! docker exec $NSRV sh -c "grep -l \"PRIVATE KEY\" /var/log/phoromatic.log /tmp/launcher.out $TLS/caddy.log $TLS/caddy.err $TLS/caddy.json $TLS/runtime.json $TLS/*.log $TLS/*.problem /var/lib/benchy/phoromatic/phoromatic.db 2>/dev/null"'
out=$(bs server-https-status)
check "B12. server-https-status probes with no server name and says so" contains "to a client that sends no server name" "$out"
check "B13. ...shows what each name gets: both addresses and the host name are covered" eval '[ $(grep -c "ok, the certificate covers it" <<<"$out") = 3 ] && ! grep -q WRONG <<<"$out"'
check "B14. ...and the root fingerprint is unchanged" eval 'tr -d ": " <<<"$out" | tr A-F a-f | grep -q $ROOT0'
echo "-- a name change applies live and keeps the root"
bs server-https-set internal --names "$VMIP,$GW,$HOSTNAME_N,10.9.9.9" | grep -q "Applying now" && echo "     applying"
settle
o=$(sans "$GW")
check "B15. the new address is on the one certificate, the old ones still are" eval 'grep -q "IP Address:10.9.9.9" <<<"$o" && grep -q "IP Address:$GW" <<<"$o" && grep -q "IP Address:$VMIP" <<<"$o"'
check "B16. ...and the root is still the same" test "$(root_fp)" = "$ROOT0"
check "B17. ...and a client dialing the NAT address verifies" contains "Verify return code: 0 (ok)" "$(verify "$GW")"
bs server-https-set internal --names "$VMIP,$GW,$HOSTNAME_N" >/dev/null; settle

echo "== C. renewal (the check every few hours, shortened here: renew whenever fewer than 31 days are left, check every 10 s)"
start_server "$QC_TREE" -e BENCHY_TLS_LEAF_RENEW_DAYS=31 -e BENCHY_TLS_LEAF_CHECK_SECONDS=10 || exit 2
wait_for 90 up_https || { echo "HTTPS did not come up for the renewal run"; srv "tail -20 /tmp/launcher.out"; exit 2; }
sleep 3
S0=$(leaf_serial "$GW")
PID0=$(docker exec "$NSRV" sh -c "pgrep -o -f 'auto_prepend_file'")
renewed() { [ -n "$(leaf_serial "$GW")" ] && [ "$(leaf_serial "$GW")" != "$S0" ]; }
check "C1. the launcher's check renews the certificate with no admin (the served serial changes)" wait_for 90 renewed
settle
check "C2. ...the renewed certificate still carries every name" eval 'o=$(sans "$GW"); grep -q "IP Address:$GW" <<<"$o" && grep -q "IP Address:$VMIP" <<<"$o"'
check "C3. ...the root is unchanged and the paired client still trusts the server" eval '[ "$(root_fp)" = $ROOT0 ] && [ "$(paired_get $GW)" = 200 ]'
check "C4. ...PHP was not restarted by the renewal (only Caddy)" test "$(docker exec "$NSRV" sh -c "pgrep -o -f 'auto_prepend_file'")" = "$PID0"
check "C5. ...and the renewal is noted in the log without any key" eval 'docker exec $NSRV test -s $TLS/internal-leaf.log && ! docker exec $NSRV grep -q PRIVATE $TLS/internal-leaf.log'
echo "-- a restart (the reboot case) keeps one working certificate"
start_server "$QC_TREE" || exit 2
wait_for 90 up_https || { echo "HTTPS did not come up after a restart"; exit 2; }
sleep 2
S1=$(leaf_serial "$GW")
check "C6. after a restart without the test knobs the certificate verifies on every name" eval '[ -n "$S1" ] && verify $GW | grep -q "Verify return code: 0" && verify $VMIP | grep -q "Verify return code: 0"'
out=$(bs server-https-renew-leaf)
sleep 4
check "C7. the renewal check with nothing due changes nothing (no restart churn)" test "$(leaf_serial "$GW")" = "$S1"

echo "== D. rollback to the old release, then forward again"
start_server "$OLD_TREE" || exit 2
wait_for 90 up_https || { echo "HTTPS did not come up after the rollback"; srv "tail -20 /tmp/launcher.out; tail -8 $TLS/caddy.log"; exit 2; }
sleep 2
check "D1. the old release starts on the new data and serves HTTPS" up_https
check "D2. the root is the same, so the paired client still trusts it on the address it was paired with" eval '[ "$(root_fp)" = $ROOT0 ] && [ "$(paired_get $VMIP)" = 200 ]'
check "D3. (the old per-name behavior is back: the NAT address fails again, as in A2)" eval '! verify $GW | grep -q "Verify return code: 0"'
start_server "$QC_TREE" || exit 2
wait_for 90 up_https || { echo "HTTPS did not come up going forward again"; srv "tail -20 /tmp/launcher.out; tail -8 $TLS/caddy.log"; exit 2; }
sleep 2
check "D4. forward again: the NAT address verifies, the root is the same, the paired client works on both" eval 'verify $GW | grep -q "Verify return code: 0" && [ "$(root_fp)" = $ROOT0 ] && [ "$(paired_get $GW)" = 200 ] && [ "$(paired_get $VMIP)" = 200 ]'

echo "== E. the unit checks (a throw-away container; no server)"
docker run --rm -v "$QC_TREE:/bench:ro" -v "$HERE:/h:ro" -v "$QC_SHARE:/share" "$HIMG" php /h/https_names_unit.php 2>&1 | while IFS= read -r line; do
	case "$line" in
		"  PASS "*) PASS=$((PASS+1)); echo "$line";;
		"  FAIL "*) FAIL=$((FAIL+1)); FAILED+=("${line#  FAIL  }"); echo "$line";;
		"        |"*) echo "$line";;
		*) [ -n "$line" ] && echo "        ? $line";;
	esac
done
echo
echo "HTTPS-NAMES: $PASS passed, $FAIL failed"
[ "$FAIL" = 0 ] || { printf '  failed: %s\n' "${FAILED[@]}"; exit 1; }
exit 0
