#!/bin/bash
# D0 of the server discovery design (0.9.15): the OpenBenchmarking.org relay and discovered servers. Driven by run.sh d0
# (QC_TREE, QC_IMAGE, HIMG, QC_SHARE, QC_CLIENT_PREFIX); it makes its own sealed network and containers, no shared server.
#   F2  the server's relay publish defaults to off and never runs while NoPhoneHome holds: proved at the network, with a server started on a
#       config from before 2026-09-29 (no AdvertiseServiceOpenBenchmarkRelay key) and openbenchmarking.org resolving to a logging sink.
#   Q6b the client's relay check (the 2026-10-28 exception) is not sent under NoPhoneHome.
#   F3  a server that merely announced itself (avahi) gives no test profiles, index or suites, even when openbenchmarking.org is
#       unreachable; it stays usable for the hash-checked download cache. A server named in the config still answers.
# Controls: the same sink does see the publish and the check when they are allowed, so a quiet window means something.
set -u
: "${QC_TREE:?run via run.sh d0}" "${HIMG:?run via run.sh d0}" "${QC_SHARE:?}" "${QC_CLIENT_PREFIX:?}"
HERE=$(cd "$(dirname "$0")" && pwd)
PASS=0; FAIL=0; FAILED=()
check() {  # check "description" command...
	local d=$1 out; shift
	if out=$("$@" 2>&1); then PASS=$((PASS+1)); echo "  PASS  $d"; else FAIL=$((FAIL+1)); FAILED+=("$d"); echo "  FAIL  $d"; [ -n "$out" ] && printf '%s\n' "$out" | head -c 4000 | sed 's/^/        | /'; fi
}
D0=$QC_SHARE/d0; mkdir -p "$D0"; chmod 777 "$D0"
NET=${QC_CLIENT_PREFIX}-d0net
SINK=${QC_CLIENT_PREFIX}-d0sink
SINKLOG=$D0/sink.log; : > "$SINKLOG"; chmod 666 "$SINKLOG"
cleanup() {
	docker ps -aq --filter "name=${QC_CLIENT_PREFIX}-d0" | xargs -r docker rm -f >/dev/null 2>&1
	docker network rm "$NET" >/dev/null 2>&1
}
trap cleanup EXIT
docker network create --internal "$NET" >/dev/null || { echo "d0 network failed"; exit 2; }
openssl req -x509 -newkey rsa:2048 -nodes -subj /CN=openbenchmarking.org -days 2 -keyout "$D0/key.pem" -out "$D0/cert.pem" >/dev/null 2>&1 || exit 2
chmod 644 "$D0/key.pem"
docker run -d --name "$SINK" --network "$NET" -v "$HERE/np_sink.py:/np_sink.py:ro" -v "$QC_SHARE:/share" "$HIMG" \
	python3 /np_sink.py /share/d0/sink.log /share/d0/cert.pem /share/d0/key.pem >/dev/null || exit 2
SINK_IP=$(docker inspect -f "{{(index .NetworkSettings.Networks \"$NET\").IPAddress}}" "$SINK")
[ -n "$SINK_IP" ] || { echo "no sink ip"; exit 2; }
mark() { wc -l < "$SINKLOG"; }
since() { tail -n +$(( $1 + 1 )) "$SINKLOG"; }
saw_r() { since "$1" | grep -qaF "\"r\": \"$2\""; }   # saw_r <mark> <request name>
saw_probe() { since "$1" | grep -qaF '"path": "/PTS"'; }

# The server's configuration: the shipped defaults, minus the relay key (what a config written before 2026-09-29 looks like), with
# NoPhoneHome set as the scenario says, and optionally the relay key set.
DEFAULTS=$QC_TREE/pts-core/static/user-config-defaults.xml
mkcfg() {  # mkcfg <file> <NoPhoneHome TRUE|FALSE> [relay TRUE|FALSE|absent]
	local f=$1 nph=$2 relay=${3:-absent}
	sed -e '/AdvertiseServiceOpenBenchmarkRelay/d' -e "s#<NoPhoneHome>[A-Za-z]*</NoPhoneHome>#<NoPhoneHome>$nph</NoPhoneHome>#" "$DEFAULTS" > "$f"
	[ "$relay" = absent ] || sed -i "s#<AdvertiseServiceZeroConf>\(.*\)</AdvertiseServiceZeroConf>#&<AdvertiseServiceOpenBenchmarkRelay>$relay</AdvertiseServiceOpenBenchmarkRelay>#" "$f"
	chmod 644 "$f"
}
grep -q '<NoPhoneHome>' "$DEFAULTS" || { echo "no NoPhoneHome element in the defaults file"; exit 2; }

# A server process on the sealed network, as a service runs it (daemonized, reading /etc/benchy.xml), for 30 s: the startup publish and the
# first event server pass both happen in that time.
run_server() {  # run_server <name> <config file> [sink: yes|no]
	local name=${QC_CLIENT_PREFIX}-d0-$1 cfg=$2 hosts=()
	[ "${3:-yes}" = yes ] && hosts=(--add-host "openbenchmarking.org:$SINK_IP" --add-host "www.openbenchmarking.org:$SINK_IP")
	docker rm -f "$name" >/dev/null 2>&1
	docker run -d --name "$name" --network "$NET" ${hosts[@]+"${hosts[@]}"} -e PHOROMATIC_HTTP_PORT=39080 -e PTS_FORCE_DAEMONIZED=1 -e HOME=/root \
		-v "$cfg":/etc/benchy.xml -v "$QC_TREE":/bench:ro -w /bench "$HIMG" sh /bench/benchy start-phoromatic-server >/dev/null || return 1
	local i; for i in $(seq 1 40); do docker exec "$name" curl -s -m 2 -o /dev/null "http://127.0.0.1:39080/server.php?phoromatic_info" && break; sleep 1; done
	sleep 25
	docker exec "$name" cat /etc/benchy.xml > "$D0/after.$1.xml" 2>/dev/null
	docker logs "$name" > "$D0/server.$1.log" 2>&1
	docker rm -f "$name" >/dev/null 2>&1
}

echo "== d0 1. static: no read of the relay key defaults to TRUE, and both call sites use the one gate"
check "the defaults file says FALSE for AdvertiseServiceOpenBenchmarkRelay" grep -q '<AdvertiseServiceOpenBenchmarkRelay>FALSE<' "$DEFAULTS"
check "no read of AdvertiseServiceOpenBenchmarkRelay anywhere in pts-core has a TRUE default" bash -c "! grep -rn \"AdvertiseServiceOpenBenchmarkRelay'[^)]*TRUE\" '$QC_TREE/pts-core' --include=*.php"
check "the startup publish and the event server's daily publish both go through server_relay_publish_allowed()" bash -c "
	[ \$(grep -c 'server_relay_publish_allowed()' '$QC_TREE/pts-core/commands/start_phoromatic_server.php') = 1 ] &&
	[ \$(grep -c 'server_relay_publish_allowed()' '$QC_TREE/pts-core/objects/phoromatic/pts_phoromatic_event_server.php') = 1 ]"
check "every phoromatic_server_relay request in pts-core sits behind that gate" bash -c "
	for f in \$(grep -rl \"'phoromatic_server_relay'\" '$QC_TREE/pts-core' --include=*.php); do
		grep -B3 \"'phoromatic_server_relay'\" \$f | grep -q 'server_relay_publish_allowed()' || { echo \"ungated: \$f\"; exit 1; }
	done"
check "the gate reads the key with a FALSE default and checks no_phone_home()" bash -c "
	grep -A8 'function server_relay_publish_allowed' '$QC_TREE/pts-core/objects/pts_openbenchmarking.php' | grep -q \"AdvertiseServiceOpenBenchmarkRelay', 'FALSE') && !pts_client::no_phone_home()\""
check "the relay check is behind no_phone_home()" bash -c "
	grep -B8 \"'phoromatic_server_relay_check'\" '$QC_TREE/pts-core/objects/pts_openbenchmarking.php' | grep -q '!pts_client::no_phone_home()'"
check "the metadata cache request skips servers that are not marked configured" bash -c "
	grep -A12 'function phoromatic_server_ob_cache_request' '$QC_TREE/pts-core/objects/pts_openbenchmarking.php' | grep -q \"empty(\\\$archived_server\\['configured'\\])\""
check "the download cache (test install manager) is not filtered by it" bash -c "! grep -q configured '$QC_TREE/pts-core/objects/client/pts_test_install_manager.php'"
# One upstream avahi announcement, one setting: both the service file and avahi-publish paths, _http and _https, sit under AdvertiseServiceZeroConf
check "the legacy avahi announcement (_http._tcp, _https._tcp, file and avahi-publish) sits under AdvertiseServiceZeroConf alone" bash -c "
	f='$QC_TREE/pts-core/commands/start_phoromatic_server.php'
	n=\$(grep -c '_http._tcp\|_https._tcp' \$f); [ \"\$n\" -ge 4 ] || { echo \"expected the four type lines, saw \$n\"; exit 1; }
	awk '/Avahi for zeroconf/{on=1} /Zeroconf via OpenBenchmarking.org/{on=0} on' \$f | grep -c '_http._tcp\|_https._tcp' | grep -qx \"\$n\" &&
	awk '/Avahi for zeroconf/{on=1} /Zeroconf via OpenBenchmarking.org/{on=0} on' \$f | grep -q \"read_bool_config('PhoronixTestSuite/Options/Server/AdvertiseServiceZeroConf'\""

echo "== d0 2. server on sealed network, openbenchmarking.org = sink; config from before 2026-09-29 (no relay key), NoPhoneHome FALSE"
mkcfg "$D0/old.xml" FALSE absent
check "precondition: the config has no relay key" bash -c "! grep -q AdvertiseServiceOpenBenchmarkRelay '$D0/old.xml'"
M=$(mark)
run_server old "$D0/old.xml" || { echo "server container failed"; exit 2; }
check "the server was online as far as it knew (it probed openbenchmarking.org)" saw_probe "$M"
check "no phoromatic_server_relay request left the server" bash -c "! tail -n +$(( M + 1 )) '$SINKLOG' | grep -qaF '\"r\": \"phoromatic_server_relay\"'"
check "no request to openbenchmarking.org carried the server's address (local_ip / local_port)" bash -c "! tail -n +$(( M + 1 )) '$SINKLOG' | grep -qa 'local_ip\|local_port'"
check "the server came up (so the quiet window is a running server, not a crashed one)" grep -qa 'Phoromatic Web Interface Is Accessible' "$D0/server.old.log"

echo "== d0 3. NoPhoneHome TRUE and the relay key TRUE: still nothing"
mkcfg "$D0/nph.xml" TRUE TRUE
check "precondition: the key is TRUE and NoPhoneHome is TRUE" bash -c "grep -q '<AdvertiseServiceOpenBenchmarkRelay>TRUE<' '$D0/nph.xml' && grep -q '<NoPhoneHome>TRUE<' '$D0/nph.xml'"
M=$(mark)
run_server nph "$D0/nph.xml"
check "no phoromatic_server_relay request left the server" bash -c "! tail -n +$(( M + 1 )) '$SINKLOG' | grep -qaF '\"r\": \"phoromatic_server_relay\"'"
check "the server came up" grep -qa 'Phoromatic Web Interface Is Accessible' "$D0/server.nph.log"

echo "== d0 4. control: relay key TRUE and NoPhoneHome FALSE publishes (the sink would have caught it)"
mkcfg "$D0/on.xml" FALSE TRUE
M=$(mark)
run_server on "$D0/on.xml"
check "the publish reached the sink with the server's address" saw_r "$M" phoromatic_server_relay
check "...as local_ip and local_port" bash -c "tail -n +$(( M + 1 )) '$SINKLOG' | grep -aF '\"r\": \"phoromatic_server_relay\"' | grep -q 'local_ip' && tail -n +$(( M + 1 )) '$SINKLOG' | grep -aF '\"r\": \"phoromatic_server_relay\"' | grep -q 'local_port'"

echo "== d0 5. the client's relay check (Q6b)"
cdo() {  # cdo <name> <nph> <sink yes|no> <php args...>: unit scenario in a throw-away client; HOME has a config with NoPhoneHome=<nph>
	local name=${QC_CLIENT_PREFIX}-d0c-$1 nph=$2 sink=$3 hosts=() h; shift 3
	[ "$sink" = yes ] && hosts=(--add-host "openbenchmarking.org:$SINK_IP" --add-host "www.openbenchmarking.org:$SINK_IP")
	h=$D0/home.$name; rm -rf "$h"; mkdir -p "$h/.benchy"; chmod -R 777 "$h"
	mkcfg "$h/.benchy/user-config.xml" "$nph" absent; chmod 666 "$h/.benchy/user-config.xml"
	timeout 120 docker run --rm --name "$name" --network "$NET" ${hosts[@]+"${hosts[@]}"} -u 1000 -e HOME=/home/bob -e TERM=dumb ${D0_ENV[@]+"${D0_ENV[@]}"} \
		-v "$h":/home/bob -v "$QC_TREE":/bench:ro -v "$HERE":/h:ro -v "$D0":/d0 "$HIMG" "$@" </dev/null 2>&1 | head -c 20000
}
D0_ENV=()
M=$(mark)
out=$(cdo rc1 TRUE yes php /h/d0_unit.php relaycheck)
check "NoPhoneHome on: possible_phoromatic_servers() returns an empty list" grep -q '  PASS  possible_phoromatic_servers() returns nothing' <<<"$out"
check "...and no phoromatic_server_relay_check request reached the sink" bash -c "! tail -n +$(( M + 1 )) '$SINKLOG' | grep -qa 'phoromatic_server_relay_check'"
M=$(mark)
cdo rc2 FALSE yes php -r 'define("PTS_MODE","CLIENT"); define("PTS_AUTO_LOAD_OBJECTS",true); $_SERVER["argv"]=array("x"); include("/bench/pts-core/pts-core.php"); pts_core::init(); pts_openbenchmarking::possible_phoromatic_servers();' >/dev/null
check "control, NoPhoneHome off: the check is sent (the sink sees it)" saw_r "$M" phoromatic_server_relay_check

echo "== d0 6. F3: an announced, unconfigured server gives no test profiles or index, with openbenchmarking.org unreachable"
ROGUE=$D0/rogue; mkdir -p "$ROGUE/bin"
cat > "$ROGUE/router.php" <<'PHP'
<?php
// a Phoromatic-looking server that answers every metadata request with a marker; logs every request
file_put_contents('/d0/rogue.log', $_SERVER['REQUEST_URI'] . "\n", FILE_APPEND);
if(strpos($_SERVER['REQUEST_URI'], 'openbenchmarking-cache.php') !== false) { echo 'ROGUE-METADATA'; return; }
echo 'Phoromatic rogue';
PHP
cat > "$ROGUE/bin/avahi-browse" <<'SH'
#!/bin/sh
# stand-in for avahi-browse: one phoromatic-server on 127.0.0.1:$D0_PORT announced as _http._tcp
case "$*" in *_https._tcp*) exit 0;; esac
echo "=;eth0;IPv4;phoromatic-server-rogue;_http._tcp;local;rogue.local;127.0.0.1;$D0_PORT;\"Benchy Phoromatic\""
SH
chmod -R a+rX "$ROGUE"; chmod a+x "$ROGUE/bin/avahi-browse"
srv_scn() {  # srv_scn <name> <configured yes|no>: rogue web server + the scenario, in one container
	local name=$1 conf=$2 port=9911 h=$D0/home.d0c-$1
	rm -f "$D0/rogue.log"; : > "$D0/rogue.log"; chmod 666 "$D0/rogue.log"
	rm -rf "$h"; mkdir -p "$h/.benchy"; chmod -R 777 "$h"
	mkcfg "$h/.benchy/user-config.xml" TRUE absent
	[ "$conf" = yes ] && sed -i "s#<PhoromaticServers></PhoromaticServers>#<PhoromaticServers>127.0.0.1:$port</PhoromaticServers>#" "$h/.benchy/user-config.xml"
	chmod 666 "$h/.benchy/user-config.xml"
	timeout 120 docker run --rm --network "$NET" -u 1000 -e HOME=/home/bob -e TERM=dumb -e D0_PORT=$port -e D0_CONFIGURED=$conf -e "PATH=/d0/rogue/bin:/usr/local/bin:/usr/bin:/bin" \
		-v "$h":/home/bob -v "$QC_TREE":/bench:ro -v "$HERE":/h:ro -v "$D0":/d0 "$HIMG" sh -c "php -S 127.0.0.1:$port /d0/rogue/router.php >/dev/null 2>&1 & sleep 1; php /h/d0_unit.php servers" </dev/null 2>&1 | head -c 20000
}
out=$(srv_scn un no)
echo "$out" | grep -a '  PASS\|  FAIL\|        |' | head -30
check "unconfigured: the announced server is found, marked unconfigured, and none of the four metadata requests is sent to it" bash -c "grep -q 'RESULT 6 0' <<<'$(echo "$out" | grep -a RESULT)'"
check "unconfigured: the rogue never received a metadata request" bash -c "! grep -q openbenchmarking-cache '$D0/rogue.log'"
check "unconfigured: the rogue was probed (server.php), so it really was discovered" grep -q 'server.php' "$D0/rogue.log"
out=$(srv_scn cf yes)
echo "$out" | grep -a '  PASS\|  FAIL\|        |' | head -30
check "configured (named in PhoromaticServers): the same server answers all four metadata requests" bash -c "grep -q 'RESULT 6 0' <<<'$(echo "$out" | grep -a RESULT)'"
check "configured: the rogue did receive them" grep -q openbenchmarking-cache "$D0/rogue.log"

echo
echo "d0: $PASS passed, $FAIL failed"
[ "$FAIL" = 0 ] || { printf '  FAILED: %s\n' "${FAILED[@]}"; exit 1; }
