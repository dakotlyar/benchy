#!/bin/bash
# D1 of the server discovery design (Benchy 0.10.0): the server side. Driven by run.sh d1 (QC_TREE, QC_IMAGE, HIMG, QC_SHARE, QC_CLIENT_PREFIX); it
# makes its own network and containers, no shared server. The server container runs avahi-daemon (and D-Bus) beside the real launcher, the way
# a host with avahi does, so the announcement is seen as a browser would see it (avahi-browse, here and from a second container).
#   A  unit checks of the class (d1_unit.php): name rules, the setting and its audit, the DNS record, the reply body, the rate limit
#   B  HTTPS off: nothing is announced under _benchy._tcp; the older announcement still is
#   C  HTTPS on: _benchy._tcp with the name, the HTTPS port and the two TXT strings, nothing else; seen from another container; the older
#      announcement under the same setting, _http._tcp only while plain HTTP is served; a non-root user can publish
#   D  GET /api/v2/server: fields, schema, HTTPS only, GET only, no database, the rate limit
#   E  supervision: avahi-publish killed, avahi-daemon restarted
#   F  server-announce: off, on, name, refusals, audit rows; the older announcement follows at the next restart
#   G  the Server settings page: the form, the state line, the DNS record, CSRF and Origin, who may change it
#   H  HTTPS off again: the announcement goes away
set -u
shopt -s lastpipe
: "${QC_TREE:?run via run.sh d1}" "${HIMG:?run via run.sh d1}" "${QC_SHARE:?}" "${QC_CLIENT_PREFIX:?}"
HERE=$(cd "$(dirname "$0")" && pwd)
PASS=0; FAIL=0; FAILED=()
check() {  # check "description" command...
	local d=$1 out; shift
	if out=$("$@" 2>&1); then PASS=$((PASS+1)); echo "  PASS  $d"; else FAIL=$((FAIL+1)); FAILED+=("$d"); echo "  FAIL  $d"; [ -n "$out" ] && printf '%s\n' "$out" | head -c 3000 | sed 's/^/        | /'; fi
}
contains() { grep -qaF -- "$1" <<<"$2" || { echo "expected to find: $1"; echo "in: ${2:0:900}"; return 1; }; }
not_contains() { ! grep -qaF -- "$1" <<<"$2" || { echo "did not expect: $1"; echo "in: ${2:0:900}"; return 1; }; }
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

RUN=${QC_CLIENT_PREFIX}-d1
NET=${RUN}net
SRV=${RUN}srv
OTHER=${RUN}other
VOL=${RUN}lib
SH=d1host
cleanup() {
	docker ps -aq --filter "name=${RUN}" | xargs -r docker rm -f >/dev/null 2>&1
	docker volume rm -f "$VOL" >/dev/null 2>&1
	docker network rm "$NET" >/dev/null 2>&1
}
trap cleanup EXIT
D1IMG=benchy-qc-d1:$(cat "$HERE/Dockerfile.d1" <(echo "$HIMG") | sha256sum | cut -c1-12)
docker image inspect "$D1IMG" >/dev/null 2>&1 || timeout 900 docker build -q -t "$D1IMG" -f "$HERE/Dockerfile.d1" --build-arg BASE="$HIMG" "$HERE" >/dev/null || { echo "the D1 image build failed (it needs the network once)"; exit 2; }
docker network create "$NET" >/dev/null || { echo "d1 network failed"; exit 2; }

echo "== A. unit checks of the class, in a throw-away container with a fresh data folder"
docker run --rm --network none --memory 512m -e HOME=/root -e PTS_FORCE_DAEMONIZED=1 -v "$QC_TREE:/bench:ro" -v "$HERE:/h:ro" -w /bench "$D1IMG" timeout 300 sh -c 'sh /bench/benchy user-config-set AdvertiseServiceZeroConf=FALSE >/dev/null 2>&1; php /h/d1_unit.php' 2>&1 | head -c 60000 | tally

echo "== A2. the PHP client's avahi-browse use: one scan per command, and never longer than 5 s per call"
stubtest() {  # stubtest <stub body>: calls counted by a stub avahi-browse on PATH while one trivial command runs
	docker run --rm --network none --memory 512m -e HOME=/root -e PTS_FORCE_DAEMONIZED=1 -v "$QC_TREE:/bench:ro" -w /bench "$D1IMG" bash -c "
		printf '#!/bin/sh\necho x >> /tmp/avahi-calls\n$1\n' > /usr/local/bin/avahi-browse; chmod +x /usr/local/bin/avahi-browse; rm -f /tmp/avahi-calls
		s=\$(date +%s); timeout 300 sh /bench/benchy server-announce status >/dev/null 2>&1; e=\$(date +%s)
		echo \"calls=\$(wc -l < /tmp/avahi-calls) secs=\$((e-s))\""
}
a2_quick=$(stubtest 'exit 0' | tail -1)
chk_a2a() { echo "$a2_quick"; grep -qE 'calls=[12] ' <<<"$a2_quick"; }
check "A2a one command makes one scan: at most two avahi-browse calls (_https and _http), not one per profile lookup" chk_a2a
a2_hung=$(stubtest 'sleep 60' | tail -1)
chk_a2b() {
	local base hung
	base=$(sed "s/.*secs=//" <<<"$a2_quick"); hung=$(sed 's/.*secs=//' <<<"$a2_hung")
	echo "quick stub: $a2_quick; hanging stub: $a2_hung"
	[ $((hung - base)) -lt 25 ]
}
check "A2b a hung avahi-browse is cut off after 5 s per call: the command takes under 25 s longer than with a quick one (60 s each if not)" chk_a2b

echo "== setup: a server container with avahi-daemon beside the supervised launcher, HTTPS off to begin with"
docker volume create "$VOL" >/dev/null
avahi_up='mkdir -p /run/dbus; rm -f /run/dbus/pid /run/avahi-daemon/pid; dbus-daemon --system --fork; avahi-daemon -D --no-rlimits'
docker run -d --name "$SRV" --hostname "$SH" --network "$NET" --network-alias d1srv --memory 1500m -e PHOROMATIC_HTTP_PORT=39080 -e PTS_FORCE_DAEMONIZED=1 \
	-v "$VOL":/var/lib/benchy -v "$QC_TREE":/bench:ro -v "$QC_SHARE":/share -v "$HERE":/h:ro -w /bench "$D1IMG" \
	sh -c "$avahi_up; while true; do [ -f /tmp/hold ] || sh /bench/benchy start-phoromatic-server >/tmp/launcher.out 2>&1; sleep 1; done" >/dev/null || exit 2
srv() { docker exec "$SRV" sh -c "$*"; }
bs() { docker exec -e HOME=/root "$SRV" sh /bench/benchy "$@" 2>&1 </dev/null | grep -av '^MISSING\|^$\|kill: No such process\|^Benchy v\|NOTICE\|^ *$'; }
dbq() { docker exec "$SRV" php -r '$d=new SQLite3("/var/lib/benchy/phoromatic/phoromatic.db"); $d->busyTimeout(5000); echo $d->querySingle($argv[1]);' "$1"; }
up_http() { docker exec "$SRV" curl -s -m 3 -o /dev/null -w '%{http_code}' "http://127.0.0.1:39080/server.php?phoromatic_info" | grep -q 200; }
up_https() { docker exec "$SRV" curl -sk -m 3 -o /dev/null -w '%{http_code}' "https://127.0.0.1:39443/server.php?phoromatic_info" | grep -q 200; }
wait_for 90 up_http || { echo "server did not start"; docker logs "$SRV" 2>&1 | tail -20; exit 2; }
wait_for 60 srv "test -f /var/lib/benchy/phoromatic/phoromatic.db" || exit 2
printf '%s\n' "$QC_PASS" | docker exec -i -e HOME=/root "$SRV" sh /bench/benchy server-account-create --user "$QC_USER" --email qc@example.com 2>&1 | grep -q "Account created" || { echo "account creation failed"; exit 2; }
browse() { docker exec "${2:-$SRV}" timeout 10 /usr/local/lib/avahi-browse-real -rtp "${1:-_benchy._tcp}" 2>/dev/null | grep '^='; }   # the resolved lines of a browse
browse_name() { browse "${2:-_benchy._tcp}" | awk -F';' -v n="$1" '$4 == n' ; }   # lines for one instance name
legacy() {  # legacy <type> [port]: the older announcement (phoromatic-server-<host>, with or without a -https suffix) of that type is listed
	browse "$1" | awk -F';' -v n="phoromatic-server-$SH" -v p="${2:-}" 'index($4, n) == 1 && (p == "" || $9 == p)' | grep -q .
}
gone() { [ -z "$(browse_name "$1" "${2:-_benchy._tcp}")" ]; }
there() { [ -n "$(browse_name "$1" "${2:-_benchy._tcp}")" ]; }
restart_srv() {  # what `systemctl restart phoromatic-server` does: the launcher ends, the supervisor loop starts it again
	srv "touch /var/lib/benchy/end-phoromatic-server"
	wait_for 40 bash -c "! docker exec $SRV pgrep -f phoromatic-server-launcher >/dev/null"
	sleep 2
}

echo "== B. HTTPS off: nothing under _benchy._tcp, the older announcement as before"
wait_for 30 legacy _http._tcp
check "B1 the older announcement (_http._tcp, phoromatic-server-<host>) is there" legacy _http._tcp 39080
check "B2 nothing is announced as _benchy._tcp while the built-in HTTPS is off" bash -c "[ -z \"\$(docker exec $SRV timeout 10 /usr/local/lib/avahi-browse-real -rtp _benchy._tcp 2>/dev/null | grep '^=')\" ]"
out=$(bs server-announce status)
check "B3 server-announce status says why: the built-in HTTPS is off" contains "built-in HTTPS is off" "$out"
check "B4 ...and there is no DNS record to print yet" not_contains "_benchy._tcp." "$out"

echo "== C. HTTPS on (internal CA): the announcement"
bs server-caddy-install --archive /opt/caddy/caddy.tar.gz >/dev/null
bs server-https-set internal --names "d1srv,$SH,d1srv.lab.test,10.9.8.7" >/dev/null
restart_srv
wait_for 90 up_https || { echo "HTTPS did not come up"; docker logs "$SRV" 2>&1 | tail -5; exit 2; }
wait_for 40 there "$SH"
line=$(browse_name "$SH" | head -1)
check "C1 the server announces itself as _benchy._tcp under its host name" test -n "$line"
check "C2 ...on the HTTPS port, and that is the only port announced for it" bash -c "[ \"\$(printf '%s\n' '$(browse_name "$SH" | awk -F';' '{print $9}' | sort -u | tr '\n' ' ')' | tr -d ' ')\" = 39443 ]"
check "C3 ...with txtvers=1 and proto=2 and nothing else in the TXT record" bash -c "[ \"\$(printf '%s\n' '$line' | awk -F';' '{print \$10}')\" = '\"proto=2\" \"txtvers=1\"' ]"
check "C4 the older _https._tcp announcement is there too (same setting), on the HTTPS port" legacy _https._tcp 39443
check "C5 ...and _http._tcp while plain HTTP is served" legacy _http._tcp 39080
check "C6 the launcher says it announces (server log)" bash -c "docker exec $SRV cat /var/log/phoromatic.log | grep -aq 'Announcing on the local network as $SH (port 39443)'"
# another container on the same network, with its own avahi-daemon, browses it: it is on the wire, not only in this host's cache
docker run -d --name "$OTHER" --network "$NET" --memory 300m "$D1IMG" sh -c "$avahi_up; sleep 600" >/dev/null
sleep 4
seen_other() { docker exec "$OTHER" timeout 10 /usr/local/lib/avahi-browse-real -rtp _benchy._tcp 2>/dev/null | grep '^=' | awk -F';' -v n="$SH" '$4 == n && $9 == 39443' | grep -q .; }
check "C7 a second host on the network browses _benchy._tcp and finds it" wait_for 30 seen_other
check "C8 a generic mDNS browser of web sites does not list it under _http._tcp or _https._tcp by the network name" bash -c "! docker exec $OTHER timeout 10 /usr/local/lib/avahi-browse-real -rtp _https._tcp 2>/dev/null | grep '^=' | awk -F';' '\$4 == \"$SH\"' | grep -q ."
check "C9 a non-root user can publish _benchy._tcp through avahi-daemon (what the phoromatic service user does)" bash -c "docker exec $SRV sh -c \"su -s /bin/sh bob -c 'avahi-publish -s -- nonroot-probe _benchy._tcp 39999 txtvers=1 proto=2 >/dev/null 2>&1 & sleep 3; timeout 10 /usr/local/lib/avahi-browse-real -rtp _benchy._tcp | grep -c nonroot-probe'\" | grep -q '^[1-9]'"
docker exec "$SRV" pkill -f nonroot-probe >/dev/null 2>&1
check "C10 the state is announced: server-announce status says the name and the port" contains "Announced as $SH on port 39443." "$(bs server-announce status)"
out=$(bs server-announce status)
check "C11 ...and prints the DNS record, filled in from the first dotted name and the HTTPS port" contains 'local-data: "_benchy._tcp.lab.test. 3600 IN SRV 0 0 39443 d1srv.lab.test."' "$out"
bs server-https-set --http-mode redirect >/dev/null
restart_srv; wait_for 90 up_https; wait_for 40 there "$SH"
chk_c12() { wait_for 20 legacy _https._tcp 39443 && ! legacy _http._tcp; }
check "C12 plain HTTP redirecting: _http._tcp is no longer announced, _https._tcp still is (the older announcement, one rule)" chk_c12
bs server-https-set --http-mode serve >/dev/null

echo "== D. GET /api/v2/server"
bs server-https-set internal --names "d1srv,$SH,d1srv.lab.test,10.9.8.7" >/dev/null
wait_for 20 up_https
cli() { docker run --rm --network "$NET" -v "$QC_SHARE:/share" "$D1IMG" "$@"; }
cli curl -sk -m 10 -o /share/ca.crt "https://d1srv:39443/server.php?benchy_ca"
chmod 644 "$QC_SHARE/ca.crt" 2>/dev/null
BODY=$(cli curl -sk -m 10 -D /share/hdr.txt "https://d1srv:39443/api/v2/server")
HDR=$(cat "$QC_SHARE/hdr.txt")
CA_FP=$(docker exec "$SRV" sh -c "openssl x509 -in /var/lib/benchy/phoromatic/tls/caddy-data/pki/authorities/local/root.crt -noout -fingerprint -sha256" | sed 's/.*=//; s/://g' | tr 'A-F' 'a-f')
check "D1 200, JSON, never cached" bash -c "head -1 $QC_SHARE/hdr.txt | grep -q ' 200' && grep -qi '^content-type: application/json' $QC_SHARE/hdr.txt && grep -qi '^cache-control: no-store' $QC_SHARE/hdr.txt"
check "D2 it verifies with the CA it reports (the cert is Benchy's own CA's, reached by the name d1srv)" bash -c "docker run --rm --network $NET -v $QC_SHARE:/share $D1IMG curl -s -m 10 --cacert /share/ca.crt https://d1srv:39443/api/v2/server | grep -q '\"product\":\"benchy\"'"
check "D3 the fields: product, name, version, protocol [2], tls.mode internal, the configured names, the CA fingerprint, join_codes" bash -c "python3 - <<'EOF'
import json, sys
b = json.loads('''$BODY''')
ok = (b['product'] == 'benchy' and b['name'] == '$SH' and b['protocol'] == [2] and b['tls']['mode'] == 'internal' and b['tls']['names'] == ['d1srv', '$SH', 'd1srv.lab.test', '10.9.8.7']
      and b['tls']['ca_sha256'] == '$CA_FP' and b['join_codes'] is False and b['api'] == 2 and b['server'] == b['version'])
sys.exit(0 if ok else 1)
EOF"
check "D4 nothing else is in the reply: exactly these keys (no account, no count, no stack, no ports but the HTTPS one)" bash -c "python3 - <<'EOF'
import json, sys
b = json.loads('''$BODY''')
print(sorted(b), sorted(b['tls']))
sys.exit(0 if sorted(b) == ['api', 'join_codes', 'name', 'product', 'protocol', 'server', 'tls', 'version'] and sorted(b['tls']) == ['ca_sha256', 'mode', 'names'] else 1)
EOF"
check "D5 the reply is valid against the server_info definition of the pinned schema (the server's own validator)" bash -c "out=\$(docker exec $SRV php /bench/tests/private-repo/agent_admin.php validate 'v2.schema.json#/\$defs/server_info' \"\$(printf '%s' '$BODY' | base64 -w0)\"); echo \"\$out\" | grep -q '\"errors\": *\[\]\\|\"errors\":\[\]'"
code=$(cli curl -s -m 10 -o /dev/null -w '%{http_code}' -X POST "http://d1srv:39080/api/v2/server" -d '{}' )
check "D6 over plain HTTP it is never answered (the web port serves client endpoints only; anything else is sent to HTTPS)" bash -c "[ '$code' != 200 ]"
chk_d7() {  # the PHP port itself, as Caddy would call it for a plain-HTTP request (secret from runtime.json), asks for /api/v2/server
	local rt body; rt=$(srv "cat /var/lib/benchy/phoromatic/tls/runtime.json")
	body=$(docker exec "$SRV" php -r '$r=json_decode($argv[1],true); $c=curl_init("http://127.0.0.1:".$r["php_port"]."/api/v2/server"); curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>1,CURLOPT_HTTPHEADER=>["X-Benchy-Proxy: ".$r["secret"],"X-Benchy-Client-Ip: 127.0.0.1","X-Benchy-Proto: http"]]); $b=curl_exec($c); echo curl_getinfo($c,CURLINFO_HTTP_CODE)." ".$b;' "$rt")
	echo "$body"; [[ "$body" == 426\ * ]] && grep -q '"code":"https_required"' <<<"$body"
}
check "D7 over plain HTTP the route is 426 https_required (asked at the PHP port the way Caddy would for an http request)" chk_d7
check "D8 only GET: a POST is 400 bad_request with Allow: GET" bash -c "docker run --rm --network $NET $D1IMG curl -sk -m 10 -i -X POST https://d1srv:39443/api/v2/server -d '{}' | tr -d '\r' | grep -qi '^allow: GET' "
check "D9 no database is needed: the answer comes while the database file is unreadable (settings only)" bash -c "docker exec $SRV sh -c 'mv /var/lib/benchy/phoromatic/phoromatic.db /var/lib/benchy/phoromatic/phoromatic.db.hold; curl -sk -m 10 -o /dev/null -w \"%{http_code}\" https://127.0.0.1:39443/api/v2/server; mv /var/lib/benchy/phoromatic/phoromatic.db.hold /var/lib/benchy/phoromatic/phoromatic.db' | grep -q 200"
check "D10 the old server.php?phoromatic_info is unchanged and still gives the server stack (the PHP clients and the engine's Pin use it)" contains "http_server" "$(cli curl -sk -m 10 'https://d1srv:39443/server.php?phoromatic_info')"
# the flood: 70 requests on one connection from one address; the first 60 of the minute are answered, the rest are 429 with Retry-After
codes=$(docker run --rm --network "$NET" "$D1IMG" sh -c 'curl -sk -m 30 -w "%{http_code}\n" $(for i in $(seq 1 70); do printf -- "-o /dev/null https://d1srv:39443/api/v2/server "; done)' | sort | uniq -c | tr -s ' ' | tr '\n' ';')
chk_d11() { echo "$codes"; echo "$codes" | tr ';' '\n' | awk '$1 ~ /^[0-9]+$/ {s+=$1; if($2==429 && $1>=10) a=1; if($2==200 && $1>=1 && $1<=60) b=1} END {exit !(s==70 && a && b)}'; }
check "D11 more than 60 requests a minute from one address: at most 60 are answered and the rest are 429 (all 70 accounted for)" chk_d11
check "D12 a 429 is a v2 error reply (rate_limited) with Retry-After" bash -c "docker run --rm --network $NET $D1IMG sh -c 'for i in \$(seq 1 62); do curl -sk -m 5 -o /dev/null https://d1srv:39443/api/v2/server; done; curl -sk -m 5 -i https://d1srv:39443/api/v2/server' | tr -d '\r' | grep -qi 'retry-after: 60' "
check "D13 the counters are small files in the server's own folder, not database rows" bash -c "[ \"\$(docker exec $SRV sh -c 'ls /var/lib/benchy/phoromatic/tls/info-rate | wc -l')\" -ge 1 ] && [ \"\$(docker exec $SRV php -r '\$d=new SQLite3(\"/var/lib/benchy/phoromatic/phoromatic.db\"); echo \$d->querySingle(\"SELECT COUNT(*) FROM phoromatic_v2_failures\");')\" = 0 ]"

sleep 62   # the flood above is out of the one-minute window before the next requests
echo "== E. supervision"
srv "pkill -f '_benchy._tcp'"; sleep 1
check "E1 avahi-publish killed: it is announced again (restarted by the launcher)" wait_for 40 there "$SH"
srv "avahi-daemon -k; sleep 1; rm -f /run/avahi-daemon/pid; avahi-daemon -D --no-rlimits"
check "E2 avahi-daemon restarted: it is announced again" wait_for 60 there "$SH"
check "E3 ...and the launcher logged the changes of state (no flood: a handful of lines)" bash -c "n=\$(docker exec $SRV cat /var/log/phoromatic.log | grep -ac 'Not announcing: avahi-publish is not running'); [ \$n -ge 1 ] && [ \$n -le 6 ]"

echo "== F. server-announce"
ann_out=$(bs server-announce off)
check "F1 off is accepted and says the running server follows" contains "Announcing is off." "$ann_out"
check "F2 ...and the announcement is gone within seconds" wait_for 20 gone "$SH"
legacy_gone() { ! legacy _https._tcp && ! legacy _http._tcp; }
chk_f3() { wait_for 25 legacy_gone; }
check "F3 ...and the older _http/_https announcement goes with it, live (state and reality agree: nothing is announced)" chk_f3
check "F3b ...and the services file the launcher wrote is removed" docker exec "$SRV" sh -c '! test -e /etc/avahi/services/phoromatic-server.service' 
check "F4 the state says it is turned off" contains "announcing is turned off" "$(bs server-announce status)"
out=$(bs server-announce on)
check "F5 on brings it back" wait_for 30 there "$SH"
check "F5b ...and the older announcement returns with it, live" wait_for 30 legacy _https._tcp 39443
out=$(bs server-announce name Rack-4)
check "F6 a new name replaces the old one on the network" bash -c "for i in \$(seq 1 25); do docker exec $SRV timeout 10 /usr/local/lib/avahi-browse-real -rtp _benchy._tcp 2>/dev/null | grep '^=' | awk -F';' '\$4 == \"Rack-4\"' | grep -q . && ! docker exec $SRV timeout 10 /usr/local/lib/avahi-browse-real -rtp _benchy._tcp 2>/dev/null | grep '^=' | awk -F';' '\$4 == \"$SH\"' | grep -q . && exit 0; sleep 1; done; exit 1"
check "F7 ...and in the who-are-you reply" contains '"name":"Rack-4"' "$(cli curl -sk -m 10 https://d1srv:39443/api/v2/server)"
out=$(bs server-announce name "$(printf 'x%.0s' $(seq 1 64))")
check "F8 a name over 63 bytes is refused, nothing changes" bash -c "echo '$out' | grep -q 'limit is 63 bytes' && docker exec $SRV curl -sk https://127.0.0.1:39443/api/v2/server | grep -q Rack-4"
out=$(docker exec -e HOME=/root "$SRV" sh /bench/benchy server-announce name "$(printf 'bad\001name')" 2>&1 </dev/null)
check "F9 a name with a control character is refused" contains "control characters" "$out"
out=$(bs server-announce frobnicate)
check "F10 an unknown word shows the usage" contains "Usage: benchy server-announce" "$out"
bs server-announce name default >/dev/null
check "F11 'name default' goes back to the host name" wait_for 30 there "$SH"
chk_f12() { [ "$(dbq "SELECT COUNT(*) FROM phoromatic_admin_audit WHERE Action='discovery_announce_change' AND Detail LIKE '%\"source\":\"cli\"%' AND Detail LIKE '%\"before\"%' AND Detail LIKE '%\"after\"%'")" = 4 ]; }
check "F12 each change is one audit row (off, on, name, name default), with its source and before/after" chk_f12
chk_f13() { [ "$(dbq "SELECT COUNT(*) FROM phoromatic_server_audit WHERE Action='server_setting_changed' AND Detail LIKE '%discovery_announce_change%'")" = 4 ]; }
check "F13 ...and the server audit has them too" chk_f13
chk_f14() { [ "$(dbq "SELECT COUNT(*) FROM phoromatic_admin_audit WHERE Action='discovery_announce_change'")" = 4 ]; }
check "F14 the refused changes (F8, F9) wrote no audit row: still the same four" chk_f14
bs server-announce off >/dev/null
restart_srv; wait_for 90 up_https
sleep 5
chk_f15() { ! legacy _https._tcp && ! legacy _http._tcp && [ -z "$(browse _benchy._tcp)" ]; }
check "F15 off, then a restart: the older announcement is off too (one setting), and nothing is announced" chk_f15
bs server-announce on >/dev/null
check "F16 on again, no restart needed for _benchy._tcp" wait_for 30 there "$SH"
port_is() { browse_name "$SH" | awk -F';' -v p="$1" '$9 == p' | grep -q .; }
bs server-https-set internal --names "d1srv,$SH,d1srv.lab.test,10.9.8.7" --https-port 40443 >/dev/null
chk_f17() { wait_for 40 port_is 40443 && return 0; srv "ls -l --time-style=full-iso /var/lib/benchy/phoromatic/tls/caddy.json /var/lib/benchy/phoromatic/tls/announce; cat /var/lib/benchy/phoromatic/tls/announce"; return 1; }
check "F17 a live change of the HTTPS port (no restart) moves the announcement to the new port" chk_f17
bs server-https-set internal --names "d1srv,$SH,d1srv.lab.test,10.9.8.7" --https-port 39443 >/dev/null
check "F18 ...and back again" wait_for 40 port_is 39443
wait_for 40 up_https

echo "== G. the Server settings page"
export H_USER=$QC_USER H_PASS=$QC_PASS
H_CA=/share/ca.crt
web() { docker run --rm --network "$NET" -e "H_BASE=https://d1srv:39443" -e "H_HOST=d1srv" -e "H_USER=$QC_USER" -e "H_PASS=$QC_PASS" -e "H_CAFILE=$H_CA" -v "$QC_SHARE:/share" -v "$HERE:/h:ro" "$D1IMG" python3 -I /h/d1_web.py "$@" 2>&1; }
web page | tally
chk_g_audit() { [ "$(dbq "SELECT COUNT(*) FROM phoromatic_admin_audit WHERE Action='discovery_announce_change' AND Detail LIKE '%\"source\":\"web\"%'")" = 3 ]; }
check "G+ the three web changes that changed something (off; on with a name; back to the host name) are audited with source web, the refused posts are not" chk_g_audit

echo "== H. HTTPS off again"
bs server-https-off >/dev/null
restart_srv; wait_for 60 up_http; sleep 6
check "H1 with the built-in HTTPS off nothing is announced as _benchy._tcp" bash -c "! docker exec $SRV timeout 10 /usr/local/lib/avahi-browse-real -rtp _benchy._tcp 2>/dev/null | grep -q '^='"
check "H2 ...the state says so" contains "built-in HTTPS is off" "$(bs server-announce status)"
chk_h3() { legacy _http._tcp 39080 && ! legacy _https._tcp; }
check "H3 ...and the older announcement is back to _http._tcp only" chk_h3

echo
echo "D1: $PASS passed, $FAIL failed"
[ "$FAIL" = 0 ] || { printf 'FAILED: %s\n' "${FAILED[@]}"; exit 1; }
