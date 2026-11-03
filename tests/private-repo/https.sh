#!/bin/bash
# Built-in HTTPS (Benchy-managed Caddy in front of PHP), MVP checks of the test plan in ideas/developing/benchy-https-caddy.md section 11.
# Driven by run.sh: tests/private-repo/run.sh https (needs the QC_* environment, HERE, RUN and the benchy-qc-https image from run.sh).
# It starts its OWN server container, the way a service runs: the real `benchy start-phoromatic-server` launcher (supervised by a loop
# that restarts it, like systemd Restart=), with a data volume of its own. Clients are throw-away HOME volumes at uid 1000.
# Groups: A off mode and the plain-HTTP web form; B internal CA (server, REMOTE_ADDR, web, http_mode, client trust, supervision);
# C uploaded certificates (CLI and web, live apply, rollback); D start refusals; E ACME against a pebble test CA; F off again.
set -u
: "${QC_SRV:?run via run.sh https}"
: "${HIMG:?run via run.sh https}"
HERE=$(cd "$(dirname "$0")" && pwd)
RUN=${QC_SRV%-srv}
HSRV=${RUN}-hsrv
HVOL=${RUN}-hlib
PASS=0; FAIL=0; FAILED=()
FX=$QC_SHARE/fx
HTOKEN=

check() {  # check "description" command...
	local d=$1 out; shift
	if out=$("$@" 2>&1); then PASS=$((PASS+1)); echo "  PASS  $d"; else FAIL=$((FAIL+1)); FAILED+=("$d"); echo "  FAIL  $d"; [ -n "$out" ] && printf '%s\n' "$out" | head -c 3000 | sed 's/^/        | /'; fi
}
contains() { grep -qaF -- "$1" <<<"$2" || { echo "expected to find: $1"; echo "in: ${2:0:900}"; return 1; }; }
not_contains() { ! grep -qaF -- "$1" <<<"$2"; }
clean() { grep -av '^MISSING\|^$\|kill: No such process\|^Benchy v\|NOTICE\|^ *$'; }
vol() { echo "${QC_CLIENT_PREFIX}-h$1"; }
srv() { docker exec "$HSRV" sh -c "$*"; }
bs() { docker exec -e HOME=/root "$HSRV" sh /bench/benchy "$@" 2>&1 </dev/null | clean; }   # benchy on the server host
dbq() { docker exec "$HSRV" php -r '$d=new SQLite3("/var/lib/benchy/phoromatic/phoromatic.db"); $d->busyTimeout(5000); echo $d->querySingle($argv[1]);' "$1"; }
TLS=/var/lib/benchy/phoromatic/tls
# cdocker <client> <command...>: run in the client's own HOME volume as uid 1000 (CENV: extra -e options)
CENV=()
cdocker() {
	local c=$1; shift
	docker volume create "$(vol "$c")" >/dev/null
	docker run --rm -i --network "$QC_NET" -u 1000 -e HOME=/home/bob -e TERM=dumb -e "BENCHY_PRIVATE_REPO_TOKEN=$HTOKEN" ${CENV[@]+"${CENV[@]}"} \
		-v "$(vol "$c"):/home/bob" -v "$QC_TREE:/bench:ro" -v "$QC_SHARE:/share" -v "$HERE:/h:ro" "$HIMG" "$@"
}
bp() { local c=$1; shift; cdocker "$c" sh /bench/benchy "$@" 2>&1 </dev/null | clean; }
csh() { local c=$1; shift; cdocker "$c" sh -c "$*" 2>&1 </dev/null; }
web() {  # web <scenario> [env...]: the https_web.py scenario from a throw-away client (own IP) against $H_BASE
	local s=$1; shift
	docker run --rm --network "$QC_NET" -e "H_BASE=$H_BASE" -e "H_HOST=$HSRV" -e "H_USER=$QC_USER" -e "H_PASS=$QC_PASS" ${H_CA:+-e "H_CAFILE=$H_CA"} "$@" -v "$QC_SHARE:/share" -v "$HERE:/h:ro" "$HIMG" python3 -I /h/https_web.py "$s" 2>&1
}
tally() {  # echo the PASS/FAIL lines of a scenario and count them
	local line
	while IFS= read -r line; do
		case "$line" in
			"  PASS "*) PASS=$((PASS+1)); echo "$line";;
			"  FAIL "*) FAIL=$((FAIL+1)); FAILED+=("${line#  FAIL  }"); echo "$line";;
			"        |"*) echo "$line";;
			RESULT*|FP*) ;;
			*) [ -n "$line" ] && echo "        ? $line";;
		esac
	done
}
wait_for() {  # wait_for <seconds> <command...>
	local n=$1; shift
	for i in $(seq 1 "$n"); do "$@" >/dev/null 2>&1 && return 0; sleep 1; done
	return 1
}
up_http() { docker exec "$HSRV" curl -s -m 3 -o /dev/null -w '%{http_code}' "http://127.0.0.1:39080/server.php?phoromatic_info" | grep -q 200; }
up_https() { docker exec "$HSRV" curl -sk -m 3 -o /dev/null -w '%{http_code}' "https://127.0.0.1:39443/server.php?phoromatic_info" | grep -q 200; }
restart_srv() {  # what `systemctl restart phoromatic-server` does: the launcher ends, the supervisor loop starts it again
	srv "touch /var/lib/benchy/end-phoromatic-server"
	wait_for 30 bash -c "! docker exec $HSRV pgrep -f phoromatic-server-launcher >/dev/null"
	sleep 2
}
live_fp() {  # live_fp [name]: SHA-256 of the certificate the server presents on 39443 (handshake from a client container)
	docker run --rm --network "$QC_NET" "$HIMG" sh -c "openssl s_client -connect $HSRV:39443 -servername ${1:-$HSRV} </dev/null 2>/dev/null | openssl x509 -noout -fingerprint -sha256 2>/dev/null" | sed 's/.*=//; s/://g' | tr 'A-F' 'a-f'
}
fp_of() { docker run --rm -v "$QC_SHARE:/share" "$HIMG" sh -c "openssl x509 -in $1 -noout -fingerprint -sha256" | sed 's/.*=//; s/://g' | tr 'A-F' 'a-f'; }
php_pid() { docker exec "$HSRV" sh -c "pgrep -o -f 'auto_prepend_file'" 2>/dev/null; }
caddy_pid() { docker exec "$HSRV" sh -c "pgrep -o -f 'caddy-.* run'" 2>/dev/null; }
status_field() { bs server-https-status | grep -a "$1" | head -1; }
applied() { ! docker exec "$HSRV" test -f $TLS/reload-caddy; }
settle() { wait_for 30 applied; sleep 3; wait_for 30 up_https; }   # the launcher took the flag, restarted Caddy alone, HTTPS answers again
fp_is() { [ "$(live_fp "$1")" = "$2" ]; }   # fp_is <name> <sha256>: the live certificate has that fingerprint
export HSRV TLS QC_NET QC_SHARE HIMG
export -f live_fp fp_of php_pid caddy_pid up_http up_https

mkdir -p "$FX"; chmod 777 "$QC_SHARE" "$FX"

echo "== fixtures: a test CA and leaf certificates made with openssl"
docker run --rm -v "$QC_SHARE:/share" "$HIMG" sh -c "
set -e; cd /share/fx
openssl req -x509 -newkey rsa:2048 -nodes -keyout ca.key -out ca.crt -days 3650 -subj '/CN=Benchy QC Test CA' -addext 'basicConstraints=critical,CA:TRUE' -addext 'keyUsage=critical,keyCertSign,cRLSign' 2>/dev/null
cat > ca.cnf <<CNF
[ca]
default_ca = CA_default
[CA_default]
dir = .
database = index.txt
new_certs_dir = .
serial = serial
default_md = sha256
policy = pol
unique_subject = no
copy_extensions = copy
[pol]
commonName = supplied
CNF
: > index.txt; echo 01 > serial
printf 'subjectAltName=DNS:$HSRV,DNS:hsrv,IP:127.0.0.1\nbasicConstraints=CA:FALSE\n' > leaf.ext
mk() {  # mk <name> [ca options...]: key, csr, certificate signed by the test CA
  n=\$1; shift
  openssl req -newkey rsa:2048 -nodes -keyout \$n.key -out \$n.csr -subj /CN=$HSRV 2>/dev/null
  openssl ca -batch -config ca.cnf -cert ca.crt -keyfile ca.key -in \$n.csr -out \$n.pem -extfile leaf.ext -days 90 \"\$@\" 2>/dev/null
  openssl x509 -in \$n.pem -out \$n.crt
}
mk leaf
mk leaf2
mk expired -startdate 20200101000000Z -enddate 20200102000000Z
mk future -startdate 20990101000000Z -enddate 20990201000000Z
cat leaf.crt ca.crt > leaf-chain.crt
cat leaf2.crt ca.crt > leaf2-chain.crt
openssl genrsa -out other.key 2048 2>/dev/null
openssl pkcs12 -export -in leaf2.crt -certfile ca.crt -inkey leaf2.key -out leaf2.pfx -passout pass:pfxpass 2>/dev/null
echo pfxpass > pfxpass.txt
chmod -R a+rX /share/fx; chmod a+r /share/fx/*.key
" && echo "  fixtures ready" || { echo "fixtures failed"; exit 2; }
LEAF_FP=$(fp_of /share/fx/leaf.crt); LEAF2_FP=$(fp_of /share/fx/leaf2.crt)

echo "== setup: a supervised server container, as a service runs, with its own data volume"
docker volume create "$HVOL" >/dev/null
docker run -d --name "$HSRV" --network "$QC_NET" --network-alias hsrv -e PHOROMATIC_HTTP_PORT=39080 -e PTS_FORCE_DAEMONIZED=1 \
	-v "$HVOL":/var/lib/benchy -v "$QC_TREE":/bench:ro -v "$QC_SHARE":/share -v "$HERE":/h:ro -w /bench "$HIMG" \
	sh -c 'while true; do [ -f /tmp/hold ] || sh /bench/benchy start-phoromatic-server >/tmp/launcher.out 2>&1; sleep 1; done' >/dev/null || exit 2
wait_for 60 up_http || { echo "server did not start"; docker logs "$HSRV" 2>&1 | tail -20; exit 2; }
J=$(mktemp)
docker exec "$HSRV" sh -c "curl -s -c /tmp/j http://127.0.0.1:39080/ >/dev/null; curl -s -b /tmp/j -X POST 'http://127.0.0.1:39080/?register' -d register_username=$QC_USER -d register_password=$QC_PASS -d register_password_confirm=$QC_PASS -d register_email=qc@example.com" | grep -q "Account Created" || { echo "account creation failed"; exit 2; }
rm -f "$J"
AID=$(dbq "SELECT AccountID FROM phoromatic_accounts ORDER BY CreatedOn ASC LIMIT 1")
HTOKEN=$(bs private-repo-token-create qc-https | grep -o 'bpr_[A-Za-z0-9_-]\{43\}' | head -1)
[ -n "$AID" ] && [ -n "$HTOKEN" ] || { echo "no account or token"; exit 2; }
approve_all() { srv "php -r '\$d=new SQLite3(\"/var/lib/benchy/phoromatic/phoromatic.db\"); \$d->busyTimeout(5000); \$d->exec(\"UPDATE phoromatic_systems SET State=1\");'"; }
export H_USER=$QC_USER H_PASS=$QC_PASS

echo "== A. HTTPS off: 0.8 behaviour, the schema, and the web form over plain HTTP"
check "off mode: no Caddy process runs" bash -c "! docker exec $HSRV pgrep -f 'caddy-' >/dev/null"
check "off mode: the plain server answers on the HTTP port" up_http
check "off mode: the schema is at user_version 58 (LastScheme in 56, session components in 57, native agent tables in 58)" test "$(dbq 'PRAGMA user_version')" = 58
check "off mode: phoromatic_systems has LastScheme" test "$(dbq "SELECT COUNT(*) FROM pragma_table_info('phoromatic_systems') WHERE name='LastScheme'")" = 1
out=$(bs server-https-status); check "server-https-status says off" contains "Built-in HTTPS: off" "$out"
check "...and shows the workers rule of thumb (8 workers)" contains "php_workers = 4 + the peak number of clients downloading" "$out"
check "the pinned Caddy has a size and sha256 in runtime-pins.json (linux-x64 and linux-arm64)" python3 -c "
import json; p=json.load(open('$QC_TREE/pts-core/static/update/runtime-pins.json'))
for k in ('linux-x64','linux-arm64'):
    a=p[k]['caddy']['artifact']; assert len(a['sha256'])==64 and a['size']>1000000 and p[k]['caddy']['version'].count('.')==2"

H_BASE=http://$HSRV:39080 H_CA= web plain_off | tally
check "the refused upload left no audit row" test "$(dbq "SELECT COUNT(*) FROM phoromatic_admin_audit WHERE Action='https_cert_upload'")" = 0
echo "-- 9d: from 127.0.0.1 inside the server container the same form is allowed over plain HTTP"
docker run --rm --network "container:$HSRV" -e H_BASE=http://127.0.0.1:39080 -e "H_FP=$LEAF_FP" -v "$QC_SHARE:/share" -v "$HERE:/h:ro" "$HIMG" python3 -I /h/https_web.py local_off 2>&1 | tally
check "a staged certificate is not left behind after cancel" test "$(srv "ls $TLS/staged 2>/dev/null | wc -l")" = 0

echo "-- Caddy install and the bootstrap button"
out=$(bs server-caddy-install --archive /opt/caddy/caddy.tar.gz); check "server-caddy-install --archive installs the pinned Caddy" contains "Installed Caddy 2.11.7" "$out"
CADDY=$(srv "ls $TLS/bin/caddy-*")
echo "-- unit checks of the HTTPS classes (inside the server container)"
docker exec -e HOME=/root "$HSRV" php /h/https_unit.php 2>&1 | tally
check "the installed binary is 0755 and the tls directory 0700" bash -c "[ \"\$(docker exec $HSRV stat -c %a $TLS)\" = 700 ] && [ \"\$(docker exec $HSRV stat -c %a $CADDY)\" = 755 ]"
out=$(bs server-caddy-install --use /bin/true); check "server-caddy-install --use refuses a program that is not Caddy" contains "does not run as Caddy" "$out"
H_BASE=http://$HSRV:39080 H_CA= web bootstrap | tally
out=$(bs server-https-status); check "after the bootstrap button the mode is internal" contains "Built-in HTTPS: internal" "$out"
check "...with the host the request used among the names" contains "$HSRV" "$out"
check "the bootstrap is in the audit log (https_mode_change, source web)" test "$(dbq "SELECT COUNT(*) FROM phoromatic_admin_audit WHERE Action='https_mode_change' AND Detail LIKE '%\"source\":\"web\"%'")" = 1
# detected names carry the request host; make sure 127.0.0.1 is covered too for the checks inside the server container
bs server-https-set internal --names "$HSRV,hsrv,127.0.0.1" >/dev/null

echo "== B. internal CA: server"
restart_srv
wait_for 60 up_https || { echo "HTTPS did not come up"; srv "tail -20 /tmp/launcher.out; tail -5 $TLS/caddy.log" ; exit 2; }
check "1. off -> internal: :39443 answers" up_https
check "...and the plain port still serves the client endpoints (http_mode serve)" up_http
srv "cp $TLS/caddy-data/pki/authorities/local/root.crt /share/fx/internal-root.crt; chmod a+r /share/fx/internal-root.crt"
out=$(docker run --rm --network "$QC_NET" -v "$QC_SHARE:/share" "$HIMG" sh -c "openssl s_client -connect $HSRV:39443 -servername $HSRV -CAfile /share/fx/internal-root.crt -verify_return_error </dev/null 2>&1")
check "2. openssl s_client: the chain verifies against the built-in root" contains "Verify return code: 0 (ok)" "$out"
check "...a second name gets its own certificate" contains "DNS:hsrv" "$(docker run --rm --network "$QC_NET" "$HIMG" sh -c "openssl s_client -connect $HSRV:39443 -servername hsrv </dev/null 2>/dev/null | openssl x509 -noout -ext subjectAltName")"
check "...a client that connects by IP (no SNI) is served that IP's certificate and it verifies" contains "Verify return code: 0 (ok)" "$(docker exec "$HSRV" sh -c "openssl s_client -connect 127.0.0.1:39443 -verify_ip 127.0.0.1 -CAfile /share/fx/internal-root.crt </dev/null 2>&1")"
rc=$(docker run --rm --network "$QC_NET" "$HIMG" sh -c "curl -s -m 5 -o /dev/null http://$HSRV:39082/; echo \$?")
check "the PHP port is not reachable from another container (loopback only)" test "$rc" != 0
check "a request to the PHP port from inside the server container without the secret gets 403" test "$(docker exec "$HSRV" curl -s -m 5 -o /dev/null -w '%{http_code}' http://127.0.0.1:39082/server.php?phoromatic_info)" = 403
check "...and one that forges the X-Benchy headers without the secret too" test "$(docker exec "$HSRV" curl -s -m 5 -o /dev/null -w '%{http_code}' -H 'X-Benchy-Proxy: guess' -H 'X-Benchy-Client-Ip: 1.2.3.4' http://127.0.0.1:39082/server.php?phoromatic_info)" = 403
check "a client-sent X-Benchy-Client-Ip does not reach PHP through Caddy (it is overwritten)" bash -c "docker run --rm --network $QC_NET -v $QC_SHARE:/share $HIMG sh -c \"curl -s --cacert /share/fx/internal-root.crt -H 'X-Benchy-Client-Ip: 6.6.6.6' https://$HSRV:39443/server.php?phoromatic_info >/dev/null\"; ! docker exec $HSRV sh -c 'grep -rl 6.6.6.6 /var/lib/benchy/phoromatic/*.db /var/log/phoromatic.log 2>/dev/null'"
echo "-- H1: underscore spellings of the proxy headers (X_Benchy_*) must not reach PHP as the real ones"
spoof() { docker run --rm --network "$QC_NET" -v "$QC_SHARE:/share" "$HIMG" sh -c "curl -s -m 10 --cacert /share/fx/internal-root.crt -o /dev/null -w '%{http_code}' https://$HSRV:39443/phoromatic.php -d aid=$AID -d pts_core=10860 $*"; }
spoof "-d r=private_repo_index -d msi=spoof-a" >/dev/null; approve_all
code=$(spoof "-H 'X_Benchy_Client_Ip: 127.0.0.1' -d r=private_repo_rebuild -d msi=spoof-a -d rt=bpr_spoofbad")
SPOOF_SRC=$(dbq "SELECT SourceIP FROM phoromatic_private_repo_audit WHERE Action='cli_auth_failed' AND Detail LIKE '%private_repo_rebuild%' ORDER BY AuditID DESC LIMIT 1")
echo "     (X_Benchy_Client_Ip through Caddy: status $code, audit SourceIP '${SPOOF_SRC:-none}')"
check "H1. X_Benchy_Client_Ip: 127.0.0.1 through Caddy is refused (403) or at least ignored: the audit source is the real peer, never 127.0.0.1" bash -c "[ '$code' = 403 ] || { [ -n '$SPOOF_SRC' ] && [ '$SPOOF_SRC' != 127.0.0.1 ]; }"
# Layer 1 on its own: what Caddy's order of headers would hand PHP if the underscore spelling got through. Straight to the PHP port WITH the
# secret (as Caddy would send it) plus an underscore spelling; the prepend itself must refuse it. This check fails on the code before the fix.
PSEC=$(srv "python3 -c 'import json; r=json.load(open(\"$TLS/runtime.json\")); print(r[\"secret\"], r[\"php_port\"])'")
direct() { docker exec "$HSRV" curl -s -m 5 -o /dev/null -w '%{http_code}' -H "X-Benchy-Proxy: ${PSEC% *}" -H 'X-Benchy-Client-Ip: 172.19.0.9' -H 'X-Benchy-Proto: https' -H 'X-Benchy-Port: 39443' "$@" "http://127.0.0.1:${PSEC#* }/server.php?phoromatic_info"; }
check "H1. (PHP port, right secret) the real headers alone are accepted (200)" test "$(direct)" = 200
check "H1. (PHP port, right secret) plus X_Benchy_Client_Ip: 127.0.0.1 is refused (403)" test "$(direct -H 'X_Benchy_Client_Ip: 127.0.0.1')" = 403
check "H1. (PHP port, right secret) plus X_Benchy_Proto / X_Benchy_Port is refused (403)" test "$(direct -H 'X_Benchy_Proto: https' -H 'X_Benchy_Port: 39443')" = 403
check "H1b. (PHP port, right secret) dotted X.Benchy.Client.Ip / X.benchy.proto and the mixed X-Benchy.Client_Ip are refused (403)" bash -c "[ \"\$(docker exec $HSRV curl -s -m 5 -o /dev/null -w '%{http_code}' -H 'X-Benchy-Proxy: ${PSEC% *}' -H 'X-Benchy-Client-Ip: 172.19.0.9' -H 'X-Benchy-Proto: https' -H 'X-Benchy-Port: 39443' -H 'X.Benchy.Client.Ip: 127.0.0.1' http://127.0.0.1:${PSEC#* }/server.php?phoromatic_info)\" = 403 ] && [ \"\$(docker exec $HSRV curl -s -m 5 -o /dev/null -w '%{http_code}' -H 'X-Benchy-Proxy: ${PSEC% *}' -H 'X-Benchy-Client-Ip: 172.19.0.9' -H 'X-Benchy-Proto: https' -H 'X-Benchy-Port: 39443' -H 'X.benchy.proto: https' http://127.0.0.1:${PSEC#* }/server.php?phoromatic_info)\" = 403 ] && [ \"\$(docker exec $HSRV curl -s -m 5 -o /dev/null -w '%{http_code}' -H 'X-Benchy-Proxy: ${PSEC% *}' -H 'X-Benchy-Client-Ip: 172.19.0.9' -H 'X-Benchy-Proto: https' -H 'X-Benchy-Port: 39443' -H 'X-Benchy.Client_Ip: 127.0.0.1' http://127.0.0.1:${PSEC#* }/server.php?phoromatic_info)\" = 403 ]"
code=$(spoof "-H 'X.Benchy.Client.Ip: 127.0.0.1' -H 'X-Benchy.Client_Ip: 127.0.0.1' -d r=private_repo_rebuild -d msi=spoof-a -d rt=bpr_spoofbad2")
SPOOF_SRC=$(dbq "SELECT SourceIP FROM phoromatic_private_repo_audit WHERE Action='cli_auth_failed' AND Detail LIKE '%private_repo_rebuild%' ORDER BY AuditID DESC LIMIT 1")
check "H1b. dotted and mixed spellings through Caddy: refused, or the audit source is the real peer, never 127.0.0.1" bash -c "[ '$code' = 403 ] || { [ -n '$SPOOF_SRC' ] && [ '$SPOOF_SRC' != 127.0.0.1 ]; }"
check "H1. (PHP port, right secret) a header X-Benchy-Extra is refused (403)" test "$(direct -H 'X-Benchy-Extra: 1')" = 403
code=$(docker run --rm --network "$QC_NET" -v "$QC_SHARE:/share" "$HIMG" curl -s -m 10 --cacert /share/fx/internal-root.crt -o /dev/null -w '%{http_code}' -H 'x-benchy-extra: 1' https://$HSRV:39443/server.php?phoromatic_info)
check "H1. any other X-Benchy-* header from a client is refused too (403)" test "$code" = 403
check "H1. ordinary requests still work (200)" test "$(docker run --rm --network "$QC_NET" -v "$QC_SHARE:/share" "$HIMG" curl -s -m 10 --cacert /share/fx/internal-root.crt -o /dev/null -w '%{http_code}' https://$HSRV:39443/server.php?phoromatic_info)" = 200
check "native agent API: /api/v2/agent/poll through Caddy reaches PHP over HTTPS (401 for a missing token, no plain-HTTP setting needed)" bash -c "out=\$(docker run --rm --network $QC_NET -v $QC_SHARE:/share $HIMG curl -s -m 10 --cacert /share/fx/internal-root.crt -w ' %{http_code}' -X POST -H 'Content-Type: application/json' -d '{}' https://$HSRV:39443/api/v2/agent/poll); case \"\$out\" in *unauthenticated*' 401') exit 0;; esac; echo \"\$out\"; exit 1"
check "native agent API: over plain HTTP it never gets as far as a token (426 from PHP, or Caddy's own redirect or refusal)" bash -c "code=\$(docker exec $HSRV curl -s -m 5 -o /dev/null -w '%{http_code}' -X POST -d '{}' http://127.0.0.1:39080/api/v2/agent/poll); case \$code in 426|308|403) exit 0;; esac; echo \$code; exit 1"
check "the proxy secret is nowhere in the Phoromatic log or the Caddy log" bash -c "S=\$(docker exec $HSRV sh -c \"cat $TLS/runtime.json\" | python3 -c 'import json,sys; print(json.load(sys.stdin)[\"secret\"])'); [ -n \"\$S\" ] && ! docker exec $HSRV sh -c \"grep -l \$S /var/log/phoromatic.log $TLS/caddy.log /var/log/*.log 2>/dev/null\""
check "caddy.json and runtime.json are 0600" bash -c "[ \"\$(docker exec $HSRV stat -c %a $TLS/caddy.json)\" = 600 ] && [ \"\$(docker exec $HSRV stat -c %a $TLS/runtime.json)\" = 600 ]"
check "the browser can fetch the built-in root over HTTPS and plain HTTP" bash -c "[ \"\$(docker run --rm --network $QC_NET $HIMG curl -sk https://$HSRV:39443/server.php?benchy_ca | openssl x509 -noout -fingerprint -sha256 | sed 's/.*=//; s/://g' | tr A-F a-f)\" = \"\$(fp_of /share/fx/internal-root.crt)\" ]"
out=$(bs server-https-status)
ROOT_FP=$(grep -A1 'root certificate SHA-256 fingerprint' <<<"$out" | tail -1 | tr -d ' ')
check "server-https-status shows the root fingerprint, the live certificate and the Caddy version" bash -c "[ \"\$(echo $ROOT_FP | tr -d : | tr A-F a-f)\" = \"$(fp_of /share/fx/internal-root.crt)\" ]"
check "...the live certificate (names and issuer)" contains "Benchy $HSRV CA" "$out"
H_BASE=https://$HSRV:39443 H_CA=/share/fx/internal-root.crt web web | tally

echo "== B. internal CA: client trust (10, 12, 13)"
check "no ignore switch exists: nothing in the client sets verification off for a Benchy server" bash -c "! grep -n 'verify_peer.*false\|VERIFYPEER.*false' $QC_TREE/pts-core/objects/client/pts_server_tls.php; ! grep -rn 'phoromatic_server_supports_https\|try_https' $QC_TREE/pts-core"
out=$(bp c1 server-trust https://$HSRV:39443/$AID)
check "10. server-trust with neither flag against an untrusted CA refuses and says how to fix it" bash -c "grep -q 'does not verify against the CA file or the system store' <<<\"\$1\" && grep -q -- '--fingerprint' <<<\"\$1\"" _ "$out"
check "...and saved nothing" bash -c "! docker run --rm -v $(vol c1):/home/bob $HIMG test -e /home/bob/.benchy/server-ca.pem"
out=$(bp c1 server-trust https://$HSRV:39443/$AID --fingerprint 00:11:22:33:44:55:66:77:88:99:AA:BB:CC:DD:EE:FF:00:11:22:33:44:55:66:77:88:99:AA:BB:CC:DD:EE:FF)
check "10. server-trust with a wrong fingerprint refuses" contains "does not match the one you gave" "$out"
check "...and saves nothing" bash -c "! docker run --rm -v $(vol c1):/home/bob $HIMG test -e /home/bob/.benchy/server-ca.pem"
# strings that should be rewritten: a legacy plain string for the same host and account, and a private-repo one
csh c1 "mkdir -p ~/.benchy/modules-data/phoromatic; echo '$HSRV:39080/$AID' > ~/.benchy/modules-data/phoromatic/last-phoromatic-server; echo '$HSRV:39080/$AID' > ~/.benchy/private-repo-server" >/dev/null
out=$(bp c1 server-trust https://$HSRV:39443/$AID --fingerprint "$ROOT_FP")
check "10. server-trust with the right fingerprint saves the CA" contains "Saved the CA" "$out"
check "...and rewrites both stored server strings to https://" bash -c "[ \"\$1\" = 'https://$HSRV:39443/$AID https://$HSRV:39443/$AID' ]" _ "$(csh c1 'echo $(cat ~/.benchy/modules-data/phoromatic/last-phoromatic-server) $(cat ~/.benchy/private-repo-server)')"
check "...with the CA file private (0600)" bash -c "[ \"\$1\" = 600 ]" _ "$(csh c1 'stat -c %a ~/.benchy/server-ca.pem')"
out=$(bp c1 server-trust https://$HSRV:39443/$AID --fingerprint "$ROOT_FP"); check "running it again is fine and changes nothing" contains "No stored server address needed changing" "$out"
CENV=(-e "BENCHY_SERVER_CAFILE=/share/fx/internal-root.crt")
out=$(bp c2 server-trust https://$HSRV:39443/$AID --ca-file /share/fx/leaf.crt)
CENV=()
check "10. server-trust --ca-file refuses a file that is not a CA certificate" contains "holds no CA certificate" "$out"
out=$(bp c2 server-trust https://$HSRV:39443/$AID --ca-file /share/fx/internal-root.crt)
check "10. server-trust --ca-file with the built-in root saves it and prints the fingerprint" bash -c "grep -q 'Saved the CA' <<<\"\$1\" && grep -qi '$ROOT_FP' <<<\"\$1\"" _ "$out"
out=$(bp c2 server-trust https://$HSRV:39443/$AID --ca-file /share/fx/internal-root.crt --fingerprint 00:11:22:33:44:55:66:77:88:99:AA:BB:CC:DD:EE:FF:00:11:22:33:44:55:66:77:88:99:AA:BB:CC:DD:EE:FF)
check "10. --ca-file with a --fingerprint that does not match refuses" contains "None of the certificates in the file has the SHA-256 fingerprint" "$out"
out=$(bp c2 server-trust http://$HSRV:39080/$AID --fingerprint "$ROOT_FP"); check "server-trust needs an https:// address" contains "must start with https://" "$out"

echo "== B. internal CA: REMOTE_ADDR, LastScheme, audit and lockout behind Caddy (3)"
out=$(csh c1 "hostname -i; timeout 45 sh /bench/benchy phoromatic.connect https://$HSRV:39443/$AID </dev/null >/tmp/connect.out 2>&1; cat /tmp/connect.out | grep -av '^MISSING\|NOTICE\|^ *$' | head -8")
C1_IP=$(head -1 <<<"$out" | awk '{print $1}')
echo "     (client c1 is $C1_IP)"; sed 's/^/     c1| /' <<<"$out" | head -12
echo "     systems: $(srv "php -r '\$d=new SQLite3(\"/var/lib/benchy/phoromatic/phoromatic.db\"); \$r=\$d->query(\"SELECT Title,LastIP,LastScheme,State FROM phoromatic_systems\"); while(\$x=\$r->fetchArray(SQLITE3_ASSOC)) echo json_encode(\$x),\" \";'")"
check "11. phoromatic.connect over HTTPS reaches the server and registers the system" test "$(dbq "SELECT COUNT(*) FROM phoromatic_systems WHERE LastScheme='https'")" -ge 1
check "3. the check-in recorded the client container's IP as LastIP (not 127.0.0.1)" test "$(dbq "SELECT LastIP FROM phoromatic_systems WHERE LastScheme='https' ORDER BY LastCommunication DESC LIMIT 1")" = "$C1_IP"
check "3. LastScheme is https" test "$(dbq "SELECT LastScheme FROM phoromatic_systems ORDER BY LastCommunication DESC LIMIT 1")" = https
approve_all

echo "== B. internal CA: HTTP modes with live apply (5)"
PHP0=$(php_pid)
code() { docker run --rm --network "$QC_NET" "$HIMG" sh -c "curl -s -m 5 -o /dev/null -w '%{http_code}' $*"; }
check "5. serve: /phoromatic.php is proxied over plain HTTP (a ping answers)" contains '"phoromatic"' "$(docker run --rm --network "$QC_NET" "$HIMG" curl -s -m 5 http://$HSRV:39080/phoromatic.php -d r=ping -d aid=$AID -d msi=x -d pts_core=10860)"
check "5. serve: GET / is a 308 to the HTTPS port" bash -c "[ \"\$(docker run --rm --network $QC_NET $HIMG curl -s -m 5 -o /dev/null -w '%{http_code} %{redirect_url}' http://$HSRV:39080/)\" = '308 https://$HSRV:39443/' ]"
out=$(bs server-https-set --http-mode redirect); check "server-https-set --http-mode redirect applies to the running service" contains "Applying now" "$out"
settle
wait_for 20 bash -c "[ \"\$(docker run --rm --network $QC_NET $HIMG curl -s -m 5 -o /dev/null -w '%{http_code}' -X POST http://$HSRV:39080/phoromatic.php -d r=ping)\" = 403 ]"
check "5. redirect: GET / is still a 308" test "$(code http://$HSRV:39080/)" = 308
body=$(docker run --rm --network "$QC_NET" "$HIMG" curl -s -m 5 -X POST http://$HSRV:39080/phoromatic.php -d r=ping)
check "5. redirect: a POST to /phoromatic.php is 403 with the JSON error naming the fix" bash -c "grep -q 'This server requires HTTPS' <<<\"\$1\" && grep -q 'server-trust https://' <<<\"\$1\"" _ "$body"
check "...PHP kept running through the live apply (same PID)" test "$(php_pid)" = "$PHP0"
bs server-https-set --http-mode off >/dev/null
settle
wait_for 20 bash -c "! docker run --rm --network $QC_NET $HIMG curl -s -m 3 -o /dev/null http://$HSRV:39080/"
check "5. off: nothing listens on the plain port" bash -c "! docker run --rm --network $QC_NET $HIMG curl -s -m 3 -o /dev/null http://$HSRV:39080/"
check "...HTTPS still answers" up_https
bs server-https-set --http-mode serve >/dev/null
settle
wait_for 20 up_http
check "serve again: the plain endpoints are back" up_http
out=$(bs server-https-set internal --names "bad name!"); check "server-https-set refuses a bad host name" contains "is not a host name or an IP address" "$out"
out=$(bs server-https-set acme --names example.test --email a@b.co); check "server-https-set acme refuses without ports 443/80" contains "ACME validates on the standard ports" "$out"

echo "== B. internal CA: legacy strings against serve mode (14), HTTPS client calls (11), no fallback (12)"
bp c3 private-repo-setup "$HSRV:39080/$AID" >/dev/null; approve_all
check "14. a legacy ip:port/AID string keeps working against serve mode (private-repo-refresh over plain HTTP)" contains "index is up to date" "$(bp c3 private-repo-refresh)"
out=$(csh c3 "timeout 30 sh /bench/benchy phoromatic.connect $HSRV:39080/$AID </dev/null 2>&1 | head -3 >/dev/null; true")
check "14. ...and the check-in over plain HTTP records LastScheme http" test "$(dbq "SELECT COUNT(*) FROM phoromatic_systems WHERE LastScheme='http'")" -ge 1
# a published private test: author c4 (over https)
csh c4 "mkdir -p ~/.benchy/test-profiles/local/hello-bench" >/dev/null
cat > "$QC_SHARE/hello-def.xml" <<'XML'
<?xml version="1.0"?>
<PhoronixTestSuite>
  <TestInformation><Title>Hello Bench</Title><AppVersion>1.0</AppVersion><Description>Trivial shell test.</Description>
    <ResultScale>Score</ResultScale><Proportion>HIB</Proportion><TimesToRun>1</TimesToRun></TestInformation>
  <TestProfile><Version>1.0.0</Version><SupportedPlatforms>Linux</SupportedPlatforms><SoftwareType>Benchmark</SoftwareType>
    <TestType>System</TestType><License>Free</License><Status>Verified</Status><SupportedArchitectures>x86_64, aarch64</SupportedArchitectures>
    <EnvironmentSize>1</EnvironmentSize><ProjectURL>https://example.invalid/</ProjectURL><Maintainer>QC</Maintainer></TestProfile>
  <TestSettings><Default><Arguments></Arguments></Default></TestSettings>
</PhoronixTestSuite>
XML
cat > "$QC_SHARE/hello-res.xml" <<'XML'
<?xml version="1.0"?>
<PhoronixTestSuite>
  <ResultsParser><OutputTemplate>Score: #_RESULT_#</OutputTemplate><ResultScale>Score</ResultScale></ResultsParser>
</PhoronixTestSuite>
XML
printf '#!/bin/sh\necho "#!/bin/sh\necho '"'"'Score: 41'"'"' > \\$LOG_FILE" > hello-bench\nchmod +x hello-bench\n' > "$QC_SHARE/hello-install.sh"
chmod a+r "$QC_SHARE"/hello-*
csh c4 "cd ~/.benchy/test-profiles/local/hello-bench && cp /share/hello-def.xml test-definition.xml && cp /share/hello-res.xml results-definition.xml && cp /share/hello-install.sh install.sh" >/dev/null
bp c4 server-trust https://$HSRV:39443/$AID --fingerprint "$ROOT_FP" >/dev/null
bp c4 private-repo-setup https://$HSRV:39443/$AID >/dev/null; approve_all
out=$(cdocker c4 sh /bench/benchy upload-private-test-profile local/hello-bench <<<"first" 2>&1 | clean)
sed 's/^/     c4| /' <<<"$out" | head -8
check "11. upload-private-test-profile over HTTPS (with the repository token)" contains "Uploaded private/hello-bench-1.0.0" "$out"
bp c5 server-trust https://$HSRV:39443/$AID --fingerprint "$ROOT_FP" >/dev/null
bp c5 private-repo-setup https://$HSRV:39443/$AID >/dev/null; approve_all
check "11. private-repo-refresh over HTTPS lists the test" contains "hello-bench" "$(bp c5 private-repo-refresh; bp c5 private-repo-list)"
jr() {  # jr <name> <args...>: benchy --json on client c5, stdout to a file
	local n=$1; shift
	cdocker c5 sh /bench/benchy --json "$@" </dev/null >"$QC_SHARE/$n.out" 2>"$QC_SHARE/$n.err"; echo $? > "$QC_SHARE/$n.rc"
}
jr h-bench benchmark private/hello-bench --defaults --name hpr1
check "11. a test installed from the server over HTTPS runs (benchmark private/hello-bench, JSON)" bash -c "grep -q '\"status\":\"ok\"' $QC_SHARE/h-bench.out && [ \"\$(cat $QC_SHARE/h-bench.rc)\" = 0 ]"
UP0=$(dbq "SELECT COUNT(*) FROM phoromatic_results")
jr h-up phoromatic.upload-result https://$HSRV:39443/$AID hpr1
check "11. benchy --json phoromatic.upload-result over HTTPS: upload event ok, exit 0" bash -c "grep -q '\"type\":\"upload\"' $QC_SHARE/h-up.out && grep -q '\"status\":\"ok\"' $QC_SHARE/h-up.out && [ \"\$(cat $QC_SHARE/h-up.rc)\" = 0 ]"
check "...the server stored the result" test "$(dbq "SELECT COUNT(*) FROM phoromatic_results")" -gt "$UP0"
# a test whose download is a file of the server itself (robots.txt), through the download path (curl or stream)
MD5=$(md5sum "$QC_TREE/pts-core/phoromatic/public_html/robots.txt" | cut -d' ' -f1); SHA=$(sha256sum "$QC_TREE/pts-core/phoromatic/public_html/robots.txt" | cut -d' ' -f1)
csh c5 "mkdir -p ~/.benchy/test-profiles/local/dl-test && cd ~/.benchy/test-profiles/local/dl-test && cp /share/hello-def.xml test-definition.xml && cp /share/hello-res.xml results-definition.xml && sed -i 's#<Title>Hello Bench#<Title>Download Test#' test-definition.xml && cp /share/hello-install.sh install.sh && cat > downloads.xml <<XML
<?xml version=\"1.0\"?>
<PhoronixTestSuite><Downloads><Package><URL>https://$HSRV:39443/robots.txt</URL><MD5>$MD5</MD5><SHA256>$SHA</SHA256><FileName>robots.txt</FileName><FileSize>26</FileSize></Package></Downloads></PhoronixTestSuite>
XML" >/dev/null
out=$(bp c5 install local/dl-test)
check "11. a download from the server over HTTPS (verified) works through the install path" bash -c "! grep -qi 'failed\|could not\|checksum' <<<\"\$1\" && [ \"\$(docker run --rm -v $(vol c5):/home/bob $HIMG sh -c 'cat /home/bob/.benchy/installed-tests/local/dl-test*/robots.txt 2>/dev/null | head -1')\" = 'User-agent: *' ]" _ "$out"
# 12. no fallback: remove the CA; every call fails with the TLS message and sends no plain-HTTP request
LOG0=$(srv "wc -l < $TLS/caddy.log")
csh c5 "rm -f ~/.benchy/server-ca.pem" >/dev/null
out=$(bp c5 private-repo-refresh)
check "12. without the CA: private-repo-refresh fails with the TLS message and the fix" bash -c "grep -q 'Could not verify the certificate' <<<\"\$1\" && grep -q 'server-trust' <<<\"\$1\"" _ "$out"
out=$(csh c5 "timeout 40 sh /bench/benchy phoromatic.connect https://$HSRV:39443/$AID </dev/null 2>&1 | grep -av '^MISSING\|NOTICE\|^ *$' | head -20")
check "12. without the CA: phoromatic.connect reports the TLS failure" contains "Could not verify the certificate" "$out"
jr h-nofb phoromatic.upload-result https://$HSRV:39443/$AID hpr1
echo "     h-nofb.out: $(head -c 600 "$QC_SHARE/h-nofb.out" | tr '\n' ' ') | err: $(head -c 300 "$QC_SHARE/h-nofb.err" | tr '\n' ' ')"
check "12. without the CA: --json upload fails with network error detail.tls verify_failed, host and port" bash -c "grep -q '\"tls\":\"verify_failed\"' $QC_SHARE/h-nofb.out && grep -q '\"host\":\"$HSRV\"' $QC_SHARE/h-nofb.out && grep -q '\"port\":39443' $QC_SHARE/h-nofb.out && [ \"\$(cat $QC_SHARE/h-nofb.rc)\" != 0 ]"
out=$(csh c5 "rm -f ~/.benchy/install-tmp; cd /tmp && timeout 60 sh /bench/benchy install local/dl-test 2>&1 | grep -ac 'robots.txt'" ); true
check "12. Caddy's access log shows no request from that client on the plain port ($HSRV:39080) after the CA was removed" bash -c "[ \"\$(docker exec $HSRV sh -c \"tail -n +$((LOG0+1)) $TLS/caddy.log | grep -c '\\\"host\\\":\\\"$HSRV:39080\\\"'\")\" = 0 ] && [ \"\$(docker exec $HSRV sh -c \"tail -n +$((LOG0+1)) $TLS/caddy.log | grep -c 'tls handshake error\|\\\"host\\\":\\\"$HSRV:39443\\\"'\")\" -ge 0 ]"
# 13. order of trust attempts: a wrong CA file, with the system store trusting the server
CENV=(-e "BENCHY_SERVER_CAFILE=/share/fx/ca.crt" -e "SSL_CERT_FILE=/share/fx/internal-root.crt")
out=$(bp c6 private-repo-setup https://$HSRV:39443/$AID)
CENV=()
check "13. a wrong CA file plus a system-trusted server succeeds on attempt 2" contains "private" "$out"
CENV=(-e "BENCHY_SERVER_CAFILE=/share/fx/nonexistent.pem")
out=$(bp c6 private-repo-refresh)
CENV=()
check "13. a CA file that does not parse is an error at use, not skipped" contains "cannot be read" "$out"

echo "== B. internal CA: per-client-IP audit and lockout behind Caddy (3), last because a lockout lasts 5 minutes per address"
# Per-client-IP lockout behind Caddy: client A (a container that stays up) fails ten times, then even a valid token from A is locked;
# client B (another IP) with a valid token is not.
LCK=${RUN}-lockA
docker run -d --name "$LCK" --network "$QC_NET" -v "$QC_SHARE:/share" "$HIMG" sleep 300 >/dev/null
lck() { docker exec "$LCK" sh -c "curl -s --cacert /share/fx/internal-root.crt https://$HSRV:39443/phoromatic.php -d aid=$AID -d pts_core=10860 $*"; }
lck "-d r=private_repo_index -d msi=lock-a" >/dev/null; approve_all
for i in $(seq 1 12); do lck "-d r=private_repo_rebuild -d msi=lock-a -d rt=bpr_bad$i" >/dev/null; done
A_IP=$(docker exec "$LCK" hostname -i | awk '{print $1}')
check "3. failed token attempts are audited with the client's IP as source_ip" test "$(dbq "SELECT COUNT(*) FROM phoromatic_private_repo_audit WHERE SourceIP='$A_IP' AND Action='cli_auth_failed'")" -ge 10
check "3. no audit row names 127.0.0.1 as a source" test "$(dbq "SELECT COUNT(*) FROM phoromatic_private_repo_audit WHERE SourceIP='127.0.0.1'")" = 0
check "3. after ten failures the lockout counts that client IP: even its valid token is refused" contains "Too many failed" "$(lck "-d r=private_repo_rebuild -d msi=lock-a -d rt=$HTOKEN")"
out=$(docker run --rm --network "$QC_NET" -v "$QC_SHARE:/share" "$HIMG" sh -c "curl -s --cacert /share/fx/internal-root.crt https://$HSRV:39443/phoromatic.php -d aid=$AID -d pts_core=10860 -d r=private_repo_index -d msi=lock-b >/dev/null")
approve_all
out=$(docker run --rm --network "$QC_NET" -v "$QC_SHARE:/share" "$HIMG" sh -c "curl -s --cacert /share/fx/internal-root.crt https://$HSRV:39443/phoromatic.php -d aid=$AID -d pts_core=10860 -d r=private_repo_rebuild -d msi=lock-b -d rt=$HTOKEN")
check "3. a client with another IP and a valid token is not locked out by A's failures" not_contains "Too many failed" "$out"
docker rm -f "$LCK" >/dev/null 2>&1

echo "== B. internal CA: supervision (8)"
CP=$(caddy_pid); check "Caddy runs" test -n "$CP"
srv "kill -9 $CP"
check "8. kill Caddy: it is back within 15 s" wait_for 15 bash -c "[ -n \"\$(docker exec $HSRV pgrep -o -f 'caddy-.* run')\" ] && docker exec $HSRV curl -sk -m 3 -o /dev/null https://127.0.0.1:39443/server.php"
check "...and HTTPS answers again" wait_for 15 up_https
PP=$(php_pid); srv "kill -9 \$(pgrep -f 'auto_prepend_file' | head -50 | tr '\n' ' ')"
check "8. kill PHP (all workers): it is back within 15 s" wait_for 15 bash -c "[ -n \"\$(docker exec $HSRV pgrep -o -f auto_prepend_file)\" ]"
check "...and the service answers over HTTPS" wait_for 20 up_https
check "the supervision is in the Phoromatic log" contains "died; restarting" "$(srv 'cat /var/log/phoromatic.log 2>/dev/null')"

echo "== C. uploaded certificates: CLI (6)"
out=$(bs server-https-set file --cert /share/fx/expired.crt --key /share/fx/expired.key); check "6. an expired certificate is refused by server-https-set" contains "expired on" "$out"
out=$(bs server-https-set file --cert /share/fx/future.crt --key /share/fx/future.key); check "6. a not-yet-valid certificate is refused" contains "not valid until" "$out"
out=$(bs server-https-set file --cert /share/fx/leaf-chain.crt --key /share/fx/other.key); check "6. a key that does not match is refused" contains "does not belong to the certificate" "$out"
check "...nothing changed (still internal)" contains "Built-in HTTPS: internal" "$(bs server-https-status)"
srv "echo {} > $TLS/live-cert.cache"   # a cached handshake that the change must drop
out=$(bs server-https-set file --cert /share/fx/leaf.crt --key /share/fx/leaf.key)
check "6. a leaf without its chain is accepted with a warning about intermediates" bash -c "grep -q 'Saved: HTTPS mode file' <<<\"\$1\" && grep -q 'no intermediate' <<<\"\$1\"" _ "$out"
check "6. a mode/certificate change clears the cached live-certificate (no stale expiry banner)" bash -c "! docker exec $HSRV test -f $TLS/live-cert.cache"
PHP0=$(php_pid)
srv "echo {} > $TLS/live-cert.cache"   # a stale handshake planted while the apply is in flight: only the launcher's rm after its Caddy restart can drop it
settle
check "6. the launcher drops the cached live-certificate after it restarts Caddy" wait_for 15 bash -c "! docker exec $HSRV test -f $TLS/live-cert.cache || docker exec $HSRV grep -q $LEAF_FP $TLS/live-cert.cache"
wait_for 25 fp_is $HSRV $LEAF_FP
check "6. the CLI change applied live: the handshake shows the uploaded certificate" test "$(live_fp $HSRV)" = "$LEAF_FP"
check "...PHP's PID did not change" test "$(php_pid)" = "$PHP0"
# the race: a page rendered while an apply is pending sees the old Caddy and must not cache that handshake.
# The launcher is paused (SIGSTOP) for this one call so its 1 s poll cannot consume the flag mid-check and restart Caddy.
LPID=$(docker exec "$HSRV" pgrep -o -f phoromatic-server-launcher)
check "the launcher process is found" test -n "$LPID"
srv "kill -STOP $LPID"
out=$(srv "php -r '
define(\"PTS_MODE\",\"CLIENT\"); define(\"PTS_AUTO_LOAD_OBJECTS\", true); define(\"PHOROMATIC_SERVER\", true);
\$_SERVER[\"argv\"]=array(\"x\"); include \"/bench/pts-core/pts-core.php\"; pts_core::init();
\$t = phoromatic_tls::tls_path(); @unlink(\$t.\"live-cert.cache\"); touch(\$t.\"reload-caddy\");
phoromatic_tls::cached_live_certificate(); echo is_file(\$t.\"live-cert.cache\") ? \"cached\" : \"not-cached\"; @unlink(\$t.\"reload-caddy\");' 2>/dev/null")
srv "kill -CONT $LPID"
check "6. a page rendered while reload-caddy is pending does not cache the handshake" test "$out" = not-cached
out=$(bs server-https-status)
check "6. server-https-status shows the uploaded certificate (issuer, names)" bash -c "grep -q 'Benchy QC Test CA' <<<\"\$1\" && grep -q 'DNS:$HSRV' <<<\"\$1\"" _ "$out"
check "...the key is stored 0600" test "$(docker exec "$HSRV" stat -c %a $TLS/key.pem)" = 600
check "the CLI change is in the audit log with the fingerprint and no key" bash -c "[ \"\$(docker exec $HSRV php -r '\$d=new SQLite3(\"/var/lib/benchy/phoromatic/phoromatic.db\"); echo \$d->querySingle(\"SELECT COUNT(*) FROM phoromatic_admin_audit WHERE Action=\\\"https_cert_upload\\\" AND Actor LIKE \\\"local:%\\\" AND Detail LIKE \\\"%$LEAF_FP%\\\"\");')\" = 1 ] && ! docker exec $HSRV sh -c \"grep -c 'PRIVATE KEY' /var/log/phoromatic-admin-audit.log /var/log/*audit* 2>/dev/null | grep -qv ':0'\""
out=$(bp c7 server-trust https://$HSRV:39443/$AID --ca-file /share/fx/ca.crt)
check "10. the same client trusts the uploaded certificate through the test CA file" contains "Saved the CA" "$out"
check "...and a verified request works (private-repo-setup)" contains "private" "$(bp c7 private-repo-setup https://$HSRV:39443/$AID)"

echo "== C. uploaded certificates: web form over HTTPS (9b, 9c)"
H_BASE=https://$HSRV:39443 H_CA=/share/fx/ca.crt
AUD0=$(dbq "SELECT COUNT(*) FROM phoromatic_admin_audit WHERE Action='https_cert_upload'")
PHP0=$(php_pid)
out=$(web upload_pem); echo "$out" | tally
PEM_FP=$(grep '^FP ' <<<"$out" | cut -d' ' -f2)
settle
check "9b. the PEM upload's fingerprint is the fixture's" test "$PEM_FP" = "$LEAF_FP"
# the form is in use now; switch back to an uploaded cert that differs so the handshake change is visible: leaf (CLI) -> leaf (web) is the same file, so go via leaf2 with the PFX
check "9b. the web upload of the PEM pair is in the audit log" test "$(dbq "SELECT COUNT(*) FROM phoromatic_admin_audit WHERE Action='https_cert_upload' AND Detail LIKE '%\"source\":\"web\"%'")" = 1
out=$(web upload_pfx); echo "$out" | tally
PFX_FP=$(grep '^FP ' <<<"$out" | cut -d' ' -f2)
check "9b. the PFX upload's fingerprint is the second fixture's" test "$PFX_FP" = "$LEAF2_FP"
check "9b. the handshake shows the new certificate within 15 s" wait_for 15 fp_is $HSRV $LEAF2_FP
settle
check "9b. PHP's PID did not change" test "$(php_pid)" = "$PHP0"
check "9b. the audit rows carry the fingerprint and no key material or password" bash -c "[ \"\$(docker exec $HSRV php -r '\$d=new SQLite3(\"/var/lib/benchy/phoromatic/phoromatic.db\"); echo \$d->querySingle(\"SELECT COUNT(*) FROM phoromatic_admin_audit WHERE Action=\\\"https_cert_upload\\\" AND Detail LIKE \\\"%$LEAF2_FP%\\\" AND Detail NOT LIKE \\\"%PRIVATE%\\\" AND Detail NOT LIKE \\\"%pfxpass%\\\"\");')\" = 1 ]"
check "9b. no certificate material is left in the staging directory or /tmp" bash -c "[ \"\$(docker exec $HSRV sh -c 'ls $TLS/staged 2>/dev/null | wc -l; ls /tmp/php* 2>/dev/null | wc -l' | tr '\n' ' ')\" = '0 0 ' ]"
check "9b. the uploaded files on the server are 0600" test "$(docker exec "$HSRV" stat -c %a $TLS/cert.pem $TLS/key.pem | tr '\n' ' ')" = "600 600 "
AUD1=$(dbq "SELECT COUNT(*) FROM phoromatic_admin_audit WHERE Action='https_cert_upload'")
web refusals | tally
check "9c. none of the refused attempts wrote an upload audit row" test "$(dbq "SELECT COUNT(*) FROM phoromatic_admin_audit WHERE Action='https_cert_upload'")" = "$AUD1"
check "9c. the certificate being served is still the PFX one" test "$(live_fp $HSRV)" = "$LEAF2_FP"
echo "-- 9c. a configuration Caddy cannot start with: the old one is restored and the failure shown"
srv "cp $TLS/caddy.json $TLS/caddy.json.prev; python3 - <<'PY'
import json
c=json.load(open('$TLS/caddy.json'))
c['apps']['http']['servers']['benchy_https']['listen']=['10.255.255.1:39443']
json.dump(c,open('$TLS/caddy.json','w'))
PY
touch $TLS/reload-caddy"
check "9c. the launcher sees the flag, finds the new configuration will not start and leaves the 'not applied' marker (within 30 s)" wait_for 30 docker exec "$HSRV" test -f $TLS/apply-failed
check "9c. ...and the previous configuration is back: HTTPS answers again" wait_for 30 up_https
check "9c. ...and the settings page shows that the new certificate was not applied" contains "was not applied" "$(docker run --rm --network "$QC_NET" -e H_BASE=$H_BASE -e H_CAFILE=/share/fx/ca.crt -e H_USER -e H_PASS -v "$QC_SHARE:/share" -v "$HERE:/h:ro" "$HIMG" python3 -I -c "
import sys; sys.path.insert(0,'/h'); sys.argv=['x','web']
import https_web as w
print(w.Browser().login().get('/?settings')[1])")"

echo "== D. start refusals: no silent fallback to plain HTTP (9)"
srv "touch /tmp/hold; touch /var/lib/benchy/end-phoromatic-server"
wait_for 30 bash -c "! docker exec $HSRV pgrep -f phoromatic-server-launcher >/dev/null"
sleep 2
refuses() {  # refuses <description> <expected message>: a start attempt that must not start anything
	local out; out=$(docker exec -e PHOROMATIC_HTTP_PORT="${3:-39080}" -e PTS_FORCE_DAEMONIZED=1 -w /bench "$HSRV" timeout 60 sh /bench/benchy start-phoromatic-server 2>&1 </dev/null | clean | head -c 2000)
	check "9. start refuses: $1" contains "$2" "$out"
	check "...and nothing listens (no plain HTTP fallback)" bash -c "! docker exec $HSRV sh -c 'curl -s -m 2 -o /dev/null http://127.0.0.1:${3:-39080}/ || curl -sk -m 2 -o /dev/null https://127.0.0.1:39443/' && ! docker exec $HSRV pgrep -f 'caddy-.* run' >/dev/null"
}
srv "rm -f $TLS/apply-failed"
srv "cp $CADDY /tmp/caddy.good; printf 'x' >> $CADDY"
refuses "a Caddy binary that does not match its recorded sha256" "does not match the sha256 recorded"
srv "cp /tmp/caddy.good $CADDY; chmod 755 $CADDY"
srv "python3 -c \"
import json
c=json.load(open('$TLS/caddy.json')); c['apps']['http']['servers']['benchy_https']['routes'][0]['handle'][0]['handler']='no_such_handler'
json.dump(c,open('/tmp/bad.json','w'))\""
# a config that cannot validate: ACME with a CA roots file that does not exist (ports 443 and 80, as ACME needs)
PYS="from phoromatic_tls import *"
srv "php -r '
define(\"PTS_MODE\",\"CLIENT\"); define(\"PTS_AUTO_LOAD_OBJECTS\", true); define(\"PHOROMATIC_SERVER\", true);
\$_SERVER[\"argv\"]=array(\"x\"); include \"/bench/pts-core/pts-core.php\"; pts_core::init();
\$s = phoromatic_tls::settings(); \$s[\"mode\"]=\"acme\"; \$s[\"https_port\"]=443; \$s[\"names\"]=array(\"acme-check.test\"); \$s[\"acme\"]=array(\"directory\"=>\"https://pebble:14000/dir\",\"email\"=>\"a@b.co\",\"ca_roots\"=>\"/nonexistent/roots.pem\");
phoromatic_tls::save_settings(\$s);' >/dev/null 2>&1"
refuses "a configuration that caddy validate rejects" "caddy validate failed" 80
# restore the internal settings, then a busy port
bs server-https-set internal --names "$HSRV,hsrv,127.0.0.1" --https-port 39443 >/dev/null
srv "nohup python3 -c \"
import socket,time
s=socket.socket(); s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1); s.bind(('0.0.0.0',39443)); s.listen(1); time.sleep(120)\" >/dev/null 2>&1 &"
sleep 1
out=$(docker exec -e PHOROMATIC_HTTP_PORT=39080 -e PTS_FORCE_DAEMONIZED=1 -w /bench "$HSRV" timeout 60 sh /bench/benchy start-phoromatic-server 2>&1 </dev/null | clean | head -c 2000)
check "9. start refuses: the HTTPS port is busy" contains "port 39443 is already in use" "$out"
check "...and the plain port was not opened" bash -c "! docker exec $HSRV curl -s -m 2 -o /dev/null http://127.0.0.1:39080/"
srv "pkill -f 'bind((.0.0.0.0.,39443))' ; true"
srv "rm -f /tmp/hold /var/lib/benchy/end-phoromatic-server"
check "the supervised service starts again once the cause is gone" wait_for 60 up_https

echo "== E. ACME against a local pebble test CA (7)"
PEB=${RUN}-pebble; ACM=${RUN}-acme; AVOL=${RUN}-alib
if docker image inspect ghcr.io/letsencrypt/pebble@sha256:ddf230642b1a584f519f32e347de1b05a6e4c1f6c35c1863b33effeab5f78199 >/dev/null 2>&1 || docker pull -q ghcr.io/letsencrypt/pebble@sha256:ddf230642b1a584f519f32e347de1b05a6e4c1f6c35c1863b33effeab5f78199 >/dev/null 2>&1; then
	cat > "$QC_SHARE/pebble-config.json" <<'JSON'
{"pebble":{"listenAddress":"0.0.0.0:14000","managementListenAddress":"0.0.0.0:15000","certificate":"test/certs/localhost/cert.pem","privateKey":"test/certs/localhost/key.pem","httpPort":80,"tlsPort":443,"ocspResponderURL":"","externalAccountBindingRequired":false}}
JSON
	chmod a+r "$QC_SHARE/pebble-config.json"
	docker run -d --name "$PEB" --network "$QC_NET" --network-alias pebble -e PEBBLE_VA_NOSLEEP=1 -e PEBBLE_VA_ALWAYS_VALID=0 -v "$QC_SHARE/pebble-config.json:/cfg.json:ro" ghcr.io/letsencrypt/pebble@sha256:ddf230642b1a584f519f32e347de1b05a6e4c1f6c35c1863b33effeab5f78199 -config /cfg.json >/dev/null
	docker cp "$PEB:/test/certs/pebble.minica.pem" "$QC_SHARE/fx/pebble-minica.pem" >/dev/null 2>&1; chmod a+r "$QC_SHARE/fx/pebble-minica.pem"
	docker volume create "$AVOL" >/dev/null
	docker run -d --name "$ACM" --network "$QC_NET" --network-alias acme-check.test -e PHOROMATIC_HTTP_PORT=80 -e PTS_FORCE_DAEMONIZED=1 \
		-v "$AVOL":/var/lib/benchy -v "$QC_TREE":/bench:ro -v "$QC_SHARE":/share -w /bench "$HIMG" \
		sh -c 'while true; do [ -f /tmp/hold ] || sh /bench/benchy start-phoromatic-server >/tmp/launcher.out 2>&1; sleep 1; done' >/dev/null
	wait_for 60 docker exec "$ACM" curl -s -m 3 -o /dev/null http://127.0.0.1:80/server.php
	docker exec "$ACM" sh -c "curl -s -c /tmp/j http://127.0.0.1:80/ >/dev/null; curl -s -b /tmp/j -X POST 'http://127.0.0.1:80/?register' -d register_username=$QC_USER -d register_password=$QC_PASS -d register_password_confirm=$QC_PASS -d register_email=qc@example.com" >/dev/null
	abs() { docker exec -e HOME=/root "$ACM" sh /bench/benchy "$@" 2>&1 </dev/null | clean; }
	abs server-caddy-install --archive /opt/caddy/caddy.tar.gz >/dev/null
	out=$(abs server-https-set acme --names acme-check.test --email qc@example.com --https-port 443 --directory https://pebble:14000/dir --ca-roots /share/fx/pebble-minica.pem --http-mode off)
	check "7. server-https-set acme saves the settings (ports 443/80)" contains "HTTPS mode acme" "$out"
	docker exec "$ACM" touch /var/lib/benchy/end-phoromatic-server
	acme_issuer() { docker run --rm --network "$QC_NET" "$HIMG" sh -c "openssl s_client -connect acme-check.test:443 -servername acme-check.test </dev/null 2>/dev/null | openssl x509 -noout -issuer 2>/dev/null"; }
	acme_is_pebble() { acme_issuer | grep -qi pebble; }
	check "7. Caddy gets a certificate from pebble (HTTP-01/TLS-ALPN-01 on the standard ports) and serves it" wait_for 90 acme_is_pebble
	out=$(docker exec "$ACM" curl -sk -m 5 https://127.0.0.1:443/server.php?phoromatic_info)
	check "7. the service answers over HTTPS with the ACME certificate" contains '"pts"' "$out"
	acme_log=$(docker exec "$ACM" sh -c "grep -c 'certificate obtained successfully' /var/lib/benchy/phoromatic/tls/caddy.log")
	check "7. caddy.log records the certificate being obtained" test "${acme_log:-0}" -ge 1
else
	echo "  SKIP  7. ACME: the pebble image is not available"
fi

echo "== F. HTTPS off again (rollback)"
out=$(bs server-https-off); check "server-https-off: mode off, restart to apply" contains "HTTPS is off" "$out"
restart_srv
check "after the restart the plain server answers again" wait_for 60 up_http
check "...with no Caddy and nothing on the HTTPS port" bash -c "! docker exec $HSRV pgrep -f 'caddy-.* run' >/dev/null && ! docker exec $HSRV curl -sk -m 2 -o /dev/null https://127.0.0.1:39443/"
out=$(bp c7 private-repo-refresh)
check "a client that was switched to https:// does not fall back by itself (re-pointing is manual)" bash -c "! grep -q 'index is up to date' <<<\"\$1\"" _ "$out"
bp c7 private-repo-setup "$HSRV:39080/$AID" >/dev/null
approve_all
check "...re-pointed to host:port/AID it works over plain HTTP again" contains "index is up to date" "$(bp c7 private-repo-refresh)"

echo
echo "https: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ] || { printf '  failed: %s\n' "${FAILED[@]}"; exit 1; }
exit 0
