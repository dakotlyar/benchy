#!/bin/bash
# The benchy-server package and benchy-server-setup (0.9.8): tests/private-repo/run.sh server-deb (also part of all, and last).
# Needs podman and the QC_TREE exported by run.sh (the committed state of the repository). Every case runs in a container of its own: ubuntu:26.04 with
# systemd as PID 1, no network. The two packages are built with the real builder (deploy/deb-package/build-package-deb.php) in a throw-away container
# first. The image holds everything the packages depend on or recommend plus the pinned Caddy tarball, so apt installs the .debs offline.
#   T0 benchy alone is inert            T1 fresh benchy-server              T2 setup, non-interactive         T3 setup without --names
#   T4 setup twice                      T5 apt reinstall                    T6 0.9.7 + hand setup, upgrade    T7 same, upgrade benchy only
#   T8 refusal shows as failed (78)     T9 remove keeps data                T10 purge deletes the data        T11 set-root-admin-password on EOF
#   T12 server-init                     T13 --http-mode off                 T14 a source-tree (install-sh) server, as the existing one
#   T15 setup on a terminal (asks for the names, the account and the password)
#   T16 first install over a server running as root   T17 storage path rules   T18 purge while the service runs
# QC_OLD_DEB (default ~/benchy-release/0.9.7/benchy_0.9.7_all.deb) is the 0.9.7 package for T6 and T7; without it those two print SKIP.
set -u
shopt -s lastpipe
: "${QC_TREE:?run via run.sh server-deb}"
HERE=$(cd "$(dirname "$0")" && pwd)
RUN=${RUN:-qcsd$$}
PM=podman
PASS=0; FAIL=0; SKIP=0; FAILED=()
OUTD=$(mktemp -d /tmp/benchy-qc-debs.XXXXXX)
chmod 0755 "$OUTD"
V=$(sed -n "s/.*define('PTS_VERSION', *'\([^']*\)').*/\1/p" "$QC_TREE/pts-core/pts-core.php" | head -1)
[ -n "$V" ] || { echo "cannot read PTS_VERSION"; exit 2; }
OLD_DEB=${QC_OLD_DEB:-$HOME/benchy-release/0.9.7/benchy_0.9.7_all.deb}
HTTPS=https://127.0.0.1:39443
PW=sdtestpw-1234

cleanup() {
	$PM ps -aq --filter "name=${RUN}-" 2>/dev/null | xargs -r $PM rm -f -t 2 >/dev/null 2>&1
	rm -rf "$OUTD"
}
trap cleanup EXIT

check() {  # check "description" command...
	local d=$1 out; shift
	if out=$("$@" 2>&1); then PASS=$((PASS+1)); echo "  PASS  $d"; else FAIL=$((FAIL+1)); FAILED+=("$d"); echo "  FAIL  $d"; [ -n "$out" ] && printf '%s\n' "$out" | head -c 3000 | sed 's/^/        | /'; fi
}
skip() { SKIP=$((SKIP+1)); echo "  SKIP  $1"; }
contains() { grep -qaF -- "$1" <<<"$2" || { echo "expected to find: $1"; echo "in: ${2:0:900}"; return 1; }; }
not_contains() { ! grep -qaF -- "$1" <<<"$2" || { echo "did not expect: $1"; echo "in: ${2:0:900}"; return 1; }; }
equals() { [ "$1" = "$2" ] || { echo "expected: $2"; echo "got:      $1"; return 1; }; }
clean() { grep -av '^MISSING\|^$\|^Benchy v\|NOTICE\|^ *$'; }
wait_for() { local n=$1; shift; while [ "$n" -gt 0 ]; do "$@" && return 0; sleep 1; n=$((n-1)); done; return 1; }

# ---- containers
sd_ready() { local st; st=$($PM exec "$1" systemctl is-system-running 2>/dev/null); [ "$st" = running ] || [ "$st" = degraded ]; }
start_ct() {  # start_ct TAG [podman options]: a systemd container, no network, the built packages at /debs, the pinned Caddy at /opt/caddy
	local n=$RUN-$1; shift
	$PM run -d --name "$n" --network none --systemd=always --tmpfs /run --tmpfs /run/lock -v "$OUTD":/debs:ro "$@" "$SDIMG" >/dev/null 2>&1 || return 1
	wait_for 60 sd_ready "$n"
}
x() {  # x TAG COMMAND: run a shell command in the container as root (stdin closed, output capped, time capped)
	local tag=$1; shift
	timeout "${XT:-300}" $PM exec "$RUN-$tag" sh -c "$*" </dev/null 2>&1 | head -c 40000
	return "${PIPESTATUS[0]}"
}
SVC_BENCHY='cd / && runuser -u phoromatic -- env HOME=/var/lib/phoromatic benchy'
sb() { local tag=$1; shift; x "$tag" "$SVC_BENCHY $*" | clean; }   # benchy as the service user
setup() { local tag=$1; shift; x "$tag" "benchy-server-setup $*"; }
active() { [ "$(x "$1" 'systemctl is-active phoromatic-server' | tail -1)" = active ]; }
listening() { x "$1" "ss -ltnH | awk '{print \$4}' | grep -qE '(^|[*:])$2\$'"; }   # something listens on port $2
listening_all() { x "$1" "ss -ltnH | awk '{print \$4}' | grep -qE '^(\\*|0\\.0\\.0\\.0|\\[::\\]):$2\$'"; }
listening_loopback_only() { x "$1" "ss -ltnH | awk '{print \$4}' | grep -qE '^127\\.0\\.0\\.1:$2\$' && ! ss -ltnH | awk '{print \$4}' | grep -qE '^(\\*|0\\.0\\.0\\.0|\\[::\\]):$2\$'"; }
is_failed() { [ "$(x "$1" 'systemctl is-active phoromatic-server' | tail -1)" = failed ]; }
https_up() { [ "$(https_status "$1")" = 200 ]; }
prop() { x "$1" "systemctl show -p $2 --value phoromatic-server" | tail -1; }
fp() { sb "$1" server-https-status | sed -n '/CA root certificate SHA-256 fingerprint/{n;p}' | tr -d ' '; }
https_status() { x "$1" "curl -sk -m 5 -o /dev/null -w '%{http_code}' $HTTPS/"; }
register_try() { x "$1" "J=\$(mktemp); curl -sk -c \$J $HTTPS/ >/dev/null; curl -sk -b \$J -X POST '$HTTPS/?register' -d register_username=$2 -d register_password=$PW -d register_password_confirm=$PW -d register_email=$2@example.com"; }
login() { x "$1" "J=\$(mktemp); curl -sk -c \$J $HTTPS/ >/dev/null; curl -sk -b \$J -X POST '$HTTPS/?login' -d username=$2 -d password=$3"; }
user_count() { x "$1" "php -r '\$d=new SQLite3(\"/var/lib/phoromatic/storage/phoromatic.db\", SQLITE3_OPEN_READONLY); echo \$d->querySingle(\"SELECT COUNT(*) FROM phoromatic_users WHERE UserName=\\\"$2\\\"\");'"; }
state_hash() {  # a hash of the files that must not change when nothing changes: settings file, server settings, the CA root and key
	x "$1" "cd /var/lib/phoromatic && sha256sum .benchy/user-config.xml storage/phoromatic-settings.pt2so \$(find storage/tls -name 'root.*' | sort) 2>/dev/null | sha256sum | cut -c1-16"
}
journal() { x "$1" "journalctl -u phoromatic-server --no-pager -o cat 2>/dev/null | tail -n ${2:-200}"; }

ok() { local tag=$1; shift; timeout "${XT:-300}" $PM exec "$RUN-$tag" sh -c "$*" </dev/null >/dev/null 2>&1; }          # succeeds when the command does
not_ok() { ! ok "$@"; }
prop_eq() { local got; got=$(prop "$1" "$2"); equals "$got" "$3"; }
all_of() { local text=$1 p; shift; for p in "$@"; do contains "$p" "$text" || return 1; done; }
none_of() { local text=$1 p; shift; for p in "$@"; do not_contains "$p" "$text" || return 1; done; }

# ---- the image and the packages
command -v $PM >/dev/null || { echo "podman is needed for server-deb"; exit 2; }
read -r CADDY_URL CADDY_SHA256 < <(python3 -c "
import json; p = json.load(open('$QC_TREE/pts-core/static/update/runtime-pins.json'))['linux-x64']['caddy']
print(p['upstream_urls'][0], p['artifact']['sha256'])")
SDIMG=benchy-qc-serverdeb:$(cat "$HERE/Dockerfile.serverdeb" <(echo "$CADDY_SHA256") | sha256sum | cut -c1-12)
if ! $PM image inspect "$SDIMG" >/dev/null 2>&1
then
	echo "== building the image $SDIMG (once; needs the network)"
	timeout 1500 $PM build -q -t "$SDIMG" -f "$HERE/Dockerfile.serverdeb" --build-arg CADDY_URL="$CADDY_URL" --build-arg CADDY_SHA256="$CADDY_SHA256" "$HERE" >/dev/null 2>"$OUTD/imagebuild.log" || { echo "image build failed"; tail -20 "$OUTD/imagebuild.log"; exit 2; }
fi

echo "== build both packages with the real builder, from the committed tree, in a throw-away container"
timeout 600 $PM run --rm --network none -v "$QC_TREE":/src:ro -v "$OUTD":/out "$SDIMG" sh -c 'cp -r /src /build && cd /build && php deploy/deb-package/build-package-deb.php --output /out' </dev/null 2>&1 | tail -4
BDEB=/debs/benchy_${V}_all.deb
SDEB=/debs/benchy-server_${V}_all.deb
[ -f "$OUTD/benchy_${V}_all.deb" ] && [ -f "$OUTD/benchy-server_${V}_all.deb" ] || { echo "the packages were not built"; exit 2; }
check "the builder makes benchy and benchy-server of the same version" bash -c "ls $OUTD | grep -c _${V}_all.deb | grep -qx 2"
control=$($PM run --rm --network none -v "$OUTD":/debs:ro "$SDIMG" sh -c "dpkg-deb -f $BDEB Recommends; dpkg-deb -f $SDEB Depends; dpkg-deb -c $SDEB | awk '{print \$1, \$6}'" </dev/null 2>&1)
check "benchy Recommends php-bz2" contains "php-bz2" "$control"
check "benchy-server Depends on the same version of benchy and on php-sqlite3" all_of "$control" "benchy (= $V)" "php-sqlite3"
check "benchy-server ships the setup script and the drop-in" all_of "$control" "/usr/sbin/benchy-server-setup" "phoromatic-server.service.d/10-benchy-server.conf"

APT="DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends"

echo "== T0. benchy alone is inert"
start_ct t0 || { echo "container did not start"; exit 2; }
x t0 "$APT $BDEB 2>&1 | tail -3" >/dev/null
check "T0 apt installs benchy alone, offline" ok t0 "dpkg -s benchy"
check "T0 no phoromatic user" not_ok t0 "getent passwd phoromatic"
check "T0 nothing enabled" not_ok t0 "systemctl is-enabled phoromatic-server"
check "T0 no listener on the Benchy ports" not_ok t0 "ss -ltn | grep -E ':39(080|082|443) '"
out=$(x t0 "cd / && benchy version 2>&1 | head -12")
check "T0 benchy version works" contains "Benchy v$V" "$out"
check "T0 ...and does not report MISSING Bzip2" not_contains "MISSING Bzip2" "$out"
$PM rm -f -t 2 "$RUN-t0" >/dev/null 2>&1

echo "== T1. fresh benchy-server"
start_ct a || { echo "container did not start"; exit 2; }
$PM exec "$RUN-a" sh -c "printf '%s\n' '$PW' > /root/pw; chmod 600 /root/pw"
out=$(x a "$APT $BDEB $SDEB 2>&1 | tail -12")
check "T1 postinst says what to run next" contains "Next: sudo benchy-server-setup" "$out"
check "T1 user and group phoromatic, home /var/lib/phoromatic, no login shell" equals "$(x a 'getent passwd phoromatic | cut -d: -f6,7; getent group phoromatic | cut -d: -f1')" $'/var/lib/phoromatic:/usr/sbin/nologin\nphoromatic'
check "T1 home and storage exist, 0750, owned by phoromatic" equals "$(x a 'stat -c "%a %U:%G" /var/lib/phoromatic /var/lib/phoromatic/storage')" $'750 phoromatic:phoromatic\n750 phoromatic:phoromatic'
check "T1 the drop-in is in effect: runs as phoromatic" prop_eq a User phoromatic
check "T1 ...with HOME, the HTTP port and PTS_FORCE_DAEMONIZED" all_of "$(prop a Environment)" "HOME=/var/lib/phoromatic" "PHOROMATIC_HTTP_PORT=39080" "PTS_FORCE_DAEMONIZED=1"
check "T1 ...and a refusal (exit 78) is not restarted" prop_eq a RestartPreventExitStatus 78
check "T1 the service is not enabled" not_ok a "systemctl is-enabled phoromatic-server"
check "T1 ...and not running" not_ok a "systemctl is-active phoromatic-server"
check "T1 nothing listens on the Benchy ports" not_ok a "ss -ltn | grep -E ':39(080|082|443) '"
check "T1 no settings or data were created" equals "$(x a 'ls -A /var/lib/phoromatic/storage; ls -d /var/lib/phoromatic/.benchy /etc/benchy.xml 2>/dev/null')" ""

echo "== T12. server-init"
start_ct i || exit 2
x i "$APT $BDEB $SDEB >/dev/null 2>&1"
out=$(x i "cd / && benchy server-init 2>&1 | grep -v '^MISSING'")
check "T12 refused as root (and says why)" contains "must not run as root" "$out"
check "T12 refused as root with a non-zero exit" not_ok i "cd / && benchy server-init"
sb i user-config-set PhoromaticStorage=/var/lib/phoromatic/storage/ >/dev/null
out=$(sb i server-init)
check "T12 as the service user it creates the database" all_of "$out" "The database was created" "schema version"
check "T12 the database belongs to phoromatic" equals "$(x i 'stat -c %U /var/lib/phoromatic/storage/phoromatic.db')" phoromatic
out=$(sb i server-init)
check "T12 a second run changes nothing..." contains "already there" "$out"
check "T12 ...and exits 0" ok i "$SVC_BENCHY server-init"
check "T12 the other server commands work after it (server-https-status)" contains "Built-in HTTPS: off" "$(sb i server-https-status)"

echo "== T11. set-root-admin-password on EOF (does not loop)"
start=$(date +%s)
timeout 12 $PM exec "$RUN-i" sh -c "$SVC_BENCHY phoromatic.set-root-admin-password </dev/null" >/dev/null 2>&1; rc=$?
took=$(( $(date +%s) - start ))
check "T11 exits 1 when standard input is closed" equals "$rc" 1
check "T11 ...within 10 seconds" test "$took" -lt 10
check "T11 one line on standard input is taken as the password" bash -c "echo rootpw-123 | timeout 30 $PM exec -i $RUN-i sh -c '$SVC_BENCHY phoromatic.set-root-admin-password >/dev/null 2>&1'"
$PM rm -f -t 2 "$RUN-i" >/dev/null 2>&1

echo "== T3. setup without --names and without a terminal"
out=$(setup a --yes </dev/null; echo "rc=$?")
check "T3 exit 2" contains "rc=2" "$out"
check "T3 the message gives the exact flags" all_of "$out" "--names" "Nothing was changed"
check "T3 nothing was created: no settings folder" not_ok a "test -e /var/lib/phoromatic/.benchy"
check "T3 ...no database" not_ok a "test -e /var/lib/phoromatic/storage/phoromatic.db"
check "T3 nothing was enabled" not_ok a "systemctl is-enabled phoromatic-server"
check "T3 ...or started" not_ok a "systemctl is-active phoromatic-server"
for bad in "--admin-user ab --admin-email d@example.com" "--admin-user dave1 --admin-email not-an-email" "--admin-user admin --admin-email d@example.com" "--admin-user dave!1 --admin-email d@example.com"; do
	out=$(setup a --yes --names srv.example.test $bad --admin-password-file /root/pw </dev/null; echo "rc=$?")
	check "T3 bad first-account answer ($bad) is exit 2 before anything changes" all_of "$out" "rc=2" "Nothing was changed"
done
x a "printf 'abc\n' > /root/shortpw"
out=$(setup a --yes --names srv.example.test --admin-user dave1 --admin-email d@example.com --admin-password-file /root/shortpw </dev/null; echo "rc=$?")
check "T3 a password under six characters is exit 2, in the script's own words" all_of "$out" "rc=2" "at least six characters"
out=$(setup a --yes --names srv.example.test --https-port 70000 --admin-user dave1 --admin-email d@example.com --admin-password-file /root/pw </dev/null; echo "rc=$?")
check "T3 an out-of-range --https-port is exit 2" all_of "$out" "rc=2" "--https-port"
check "T3 ...still nothing was created" not_ok a "test -e /var/lib/phoromatic/.benchy"
out=$(setup a --yes --names srv.example.test </dev/null; echo "rc=$?")
check "T3 a missing first account is also exit 2, and the message names the flags" all_of "$out" "rc=2" "--admin-user" "--admin-password-file"

echo "== T2. setup, non-interactive"
out=$(XT=400 setup a --names SRV.example.test,10.0.0.5,srv.example.test --admin-user dave1 --admin-email dave@example.com --admin-password-file /root/pw --caddy-archive /opt/caddy/caddy.tar.gz --yes </dev/null; echo "rc=$?")
echo "$out" | tail -32 | sed 's/^/        > /'
check "T2 setup exits 0" contains "rc=0" "$out"
check "T2 the service is active" prop_eq a ActiveState active
check "T2 ...and enabled" prop_eq a UnitFileState enabled
check "T2 39443 listens on all addresses" listening_all a 39443
check "T2 39082 (PHP) listens on the loopback address only" listening_loopback_only a 39082
check "T2 39080 (plain HTTP) is served by default, as on the reference server" listening_all a 39080
check "T2 HTTPS answers 200" equals "$(https_status a)" 200
check "T2 plain HTTP redirects the web pages to HTTPS (mode serve)" contains "https://" "$(x a "curl -s -m 5 -o /dev/null -w '%{http_code} %{redirect_url}' http://127.0.0.1:39080/")"
out=$(register_try a intruder1)
check "T2 registering through the web is refused: the closed message, no Account Created" all_of "$out" "Account creation is closed"
check "T2 ...no confirmation page" not_contains "Account Created" "$out"
check "T2 ...and no such user exists" equals "$(user_count a intruder1)" 0
check "T2 the account made by the script can log in" contains "Log-In Successful" "$(login a dave1 $PW)"
check "T2 server-info labels a hand-start port as not used by the service" contains "not used by the service" "$(sb a server-info)"
log=$(journal a 300)
check "T2 the start-up block names the settings file" contains "Configuration file: /var/lib/phoromatic/.benchy/user-config.xml" "$log"
check "T2 ...and the port with its source" contains "HTTP port: 39080 (PHOROMATIC_HTTP_PORT)" "$log"
check "T2 ...and the storage, from PhoromaticStorage" contains "Storage: /var/lib/phoromatic/storage/ (from PhoromaticStorage" "$log"
check "T2 ...and the HTTPS mode" contains "HTTPS: internal on port 39443, plain HTTP: serve" "$log"
info=$(sb a server-info)
check "T2 server-info agrees: config file, storage, the port the service used, HTTPS, registration, accounts" all_of "$info" "Configuration file: /var/lib/phoromatic/.benchy/user-config.xml" "Storage: /var/lib/phoromatic/storage/ (from PhoromaticStorage" "the service used at its last start: 39080 (PHOROMATIC_HTTP_PORT" "HTTPS: internal on port 39443, plain HTTP: serve, names srv.example.test, 10.0.0.5" "Registration: closed" "Accounts: 1"
check "T2 the settings file is 0600 and belongs to phoromatic" equals "$(x a 'stat -c "%a %U" /var/lib/phoromatic/.benchy/user-config.xml')" "600 phoromatic"
check "T2 /etc/benchy.xml was never created by the setup" not_ok a "test -e /etc/benchy.xml"
check "T2 --check passes" contains "The layout matches the reference." "$(x a 'benchy-server-setup --check; echo rc=$?')"
FP_A=$(fp a)
check "T2 the CA fingerprint is readable" test "${#FP_A}" -gt 60
HASH_A=$(state_hash a)
PID_A=$(prop a ExecMainPID)

echo "== T4. setup run twice"
out=$(XT=400 setup a --names SRV.example.test,10.0.0.5,srv.example.test --admin-user dave1 --admin-email dave@example.com --admin-password-file /root/pw --caddy-archive /opt/caddy/caddy.tar.gz --yes </dev/null; echo "rc=$?")
check "T4 the second run exits 0" contains "rc=0" "$out"
check "T4 it changed nothing: same settings, same CA files" equals "$(state_hash a)" "$HASH_A"
check "T4 same CA fingerprint" equals "$(fp a)" "$FP_A"
check "T4 the service was not restarted (same ExecMainPID)" equals "$(prop a ExecMainPID)" "$PID_A"
check "T4 still exactly one account" contains "Accounts: 1" "$(sb a server-info)"
x a "printf '#!/bin/sh\necho \"Status: active\"\n' > /usr/local/bin/ufw; chmod +x /usr/local/bin/ufw"
out=$(setup a --yes </dev/null; echo "rc=$?")
x a "rm -f /usr/local/bin/ufw"
check "T4 with ufw active, the firewall text prints the allow lines for both ports" all_of "$out" "sudo ufw allow 39443/tcp" "sudo ufw allow 39080/tcp"
check "T4 ...and says 39082 needs nothing" all_of "$out" "39082/tcp  needs nothing"
check "T4 setup with no flags at all exits 0..." contains "rc=0" "$out"
check "T4 ...and does not restart the service" equals "$(prop a ExecMainPID)" "$PID_A"

echo "== T5. apt install --reinstall"
x a "$APT --reinstall $BDEB $SDEB >/dev/null 2>&1"
check "T5 settings and CA are byte-identical after a reinstall" equals "$(state_hash a)" "$HASH_A"
check "T5 same fingerprint" equals "$(fp a)" "$FP_A"
check "T5 the service is still running, still enabled..." all_of "$(prop a ActiveState) $(prop a UnitFileState)" "active" "enabled"
check "T5 ...and was not restarted" equals "$(prop a ExecMainPID)" "$PID_A"
check "T5 the account still logs in" contains "Log-In Successful" "$(login a dave1 $PW)"

echo "== T9. remove"
x a "DEBIAN_FRONTEND=noninteractive apt-get remove -y benchy-server >/dev/null 2>&1"
check "T9 the service is stopped" not_ok a "systemctl is-active phoromatic-server"
check "T9 ...and disabled" not_ok a "systemctl is-enabled phoromatic-server"
check "T9 the user is kept" ok a "getent passwd phoromatic"
check "T9 the database, the settings and the CA are kept" ok a "test -f /var/lib/phoromatic/storage/phoromatic.db && test -f /var/lib/phoromatic/.benchy/user-config.xml && test -d /var/lib/phoromatic/storage/tls"
check "T9 the setup script is gone" not_ok a "test -e /usr/sbin/benchy-server-setup"
check "T9 the drop-in is gone" not_ok a "test -e /usr/lib/systemd/system/phoromatic-server.service.d/10-benchy-server.conf"
check "T9 benchy itself still works" contains "Benchy v$V" "$(x a 'cd / && benchy version')"
x a "$APT $SDEB >/dev/null 2>&1"
out=$(XT=400 setup a --yes </dev/null; echo "rc=$?")
check "T9 reinstall + setup with no flags exits 0..." contains "rc=0" "$out"
check "T9 ...and brings back the same server: same CA fingerprint" equals "$(fp a)" "$FP_A"
check "T9 ...HTTPS answers" equals "$(https_status a)" 200
check "T9 ...and the account logs in" contains "Log-In Successful" "$(login a dave1 $PW)"

echo "== T10. purge deletes the data (and says so)"
out=$(x a "DEBIAN_FRONTEND=noninteractive apt-get purge -y benchy-server 2>&1 | tail -8")
check "T10 purge prints a clear warning that it deletes /var/lib/phoromatic" all_of "$out" "WARNING: purging deletes /var/lib/phoromatic" "cannot be undone"
check "T10 /var/lib/phoromatic is gone" not_ok a "test -e /var/lib/phoromatic"
check "T10 the system user is kept" ok a "getent passwd phoromatic"
check "T10 the service is not running" not_ok a "systemctl is-active phoromatic-server"
x a "$APT $SDEB >/dev/null 2>&1"
out=$(XT=400 setup a --names srv.example.test --admin-user dave2 --admin-email dave@example.com --admin-password-file /root/pw --caddy-archive /opt/caddy/caddy.tar.gz --yes </dev/null; echo "rc=$?")
check "T10 reinstall + setup makes a brand-new server" contains "rc=0" "$out"
NEW_FP=$(fp a)
check "T10 ...with a new CA (a different fingerprint)" test -n "$NEW_FP" -a "$NEW_FP" != "$FP_A"
check "T10 ...the old account is gone" equals "$(user_count a dave1)" 0
check "T10 ...and the new one is there" equals "$(user_count a dave2)" 1
$PM rm -f -t 2 "$RUN-a" >/dev/null 2>&1

echo "== T13 and T8. --http-mode off; a refusal shows as failed with status 78"
start_ct b || exit 2
x b "printf '%s\n' '$PW' > /root/pw; $APT $BDEB $SDEB >/dev/null 2>&1"
out=$(XT=400 setup b --names srv.example.test --http-mode off --admin-user dave1 --admin-email dave@example.com --admin-password-file /root/pw --caddy-archive /opt/caddy/caddy.tar.gz --yes </dev/null; echo "rc=$?")
check "T13 setup --http-mode off exits 0" contains "rc=0" "$out"
check "T13 HTTPS listens on 39443..." ok b "ss -ltn | grep -q ':39443 '"
check "T13 ...and nothing listens on 39080" not_ok b "ss -ltn | grep -q ':39080 '"
check "T13 the firewall text names 39443 and says nothing listens on 39080" all_of "$out" "39443/tcp" "nothing listens there while plain HTTP is off"
x b 'rm -f /var/lib/phoromatic/storage/tls/bin/caddy-*; systemctl restart phoromatic-server' >/dev/null
wait_for 60 is_failed b
check "T8 the service is failed, not inactive" equals "$(x b 'systemctl is-active phoromatic-server' | tail -1)" failed
check "T8 ExecMainStatus is 78 (the launcher passes PHP's status on to systemd)" equals "$(prop b ExecMainStatus)" 78
check "T8 it did not loop: NRestarts is 0" equals "$(prop b NRestarts)" 0
check "T8 the journal says why" contains "Not starting: No Caddy is installed" "$(journal b 80)"
check "T8 ...and the fix it names runs as the service user (a bare benchy would create root files)" contains "Run: sudo -u phoromatic env HOME=/var/lib/phoromatic benchy server-caddy-install" "$(journal b 80)"
sleep 8
check "T8 after a wait it is still failed..." equals "$(x b 'systemctl is-active phoromatic-server' | tail -1)" failed
check "T8 ...and still has not restarted" prop_eq b NRestarts 0
out=$(x b "benchy-server-setup --check; echo rc=\$?")
check "T8 --check reports the missing Caddy as drift, and exits 1" all_of "$out" "MISSING" "Caddy" "rc=1"
check "T8 ...and points at the setup script, not at a bare benchy command" contains "sudo benchy-server-setup" "$out"
$PM rm -f -t 2 "$RUN-b" >/dev/null 2>&1

hand_setup() {  # Huyang's runbook, literally, on 0.9.7: user, settings, override, first start, Caddy, HTTPS (plain HTTP off), restart, an account
	local t=$1
	x "$t" "useradd --system --home-dir /var/lib/phoromatic --create-home --shell /usr/sbin/nologin phoromatic"
	x "$t" "runuser -u phoromatic -- env HOME=/var/lib/phoromatic benchy version >/dev/null 2>&1; runuser -u phoromatic -- env HOME=/var/lib/phoromatic sed -i 's|<PhoromaticStorage>.*</PhoromaticStorage>|<PhoromaticStorage>/var/lib/phoromatic/storage/</PhoromaticStorage>|' /var/lib/phoromatic/.benchy/user-config.xml; runuser -u phoromatic -- chmod 600 /var/lib/phoromatic/.benchy/user-config.xml"
	x "$t" "mkdir -p /etc/systemd/system/phoromatic-server.service.d; printf '[Service]\nUser=phoromatic\nGroup=phoromatic\nEnvironment=HOME=/var/lib/phoromatic\nEnvironment=PHOROMATIC_HTTP_PORT=39080\nEnvironment=PTS_FORCE_DAEMONIZED=1\n' > /etc/systemd/system/phoromatic-server.service.d/override.conf; systemctl daemon-reload; systemctl enable --now phoromatic-server >/dev/null 2>&1"
	wait_for 90 ok "$t" "test -f /var/lib/phoromatic/storage/phoromatic.db && curl -s -o /dev/null http://127.0.0.1:39080/"
	sb "$t" server-caddy-install --archive /opt/caddy/caddy.tar.gz >/dev/null
	sb "$t" server-https-set internal --names hand.example.test,10.0.0.9 --http-mode off >/dev/null
	x "$t" "systemctl restart phoromatic-server"
	wait_for 90 https_up "$t"
	register_try "$t" handuser1 >/dev/null
}

if [ ! -f "$OLD_DEB" ]
then
	echo "== T6 and T7"
	skip "T6 the 0.9.7 hand setup upgraded to 0.9.8 (no 0.9.7 package at $OLD_DEB; set QC_OLD_DEB)"
	skip "T7 the same server with only benchy upgraded"
else
	cp "$OLD_DEB" "$OUTD/old-benchy.deb"; chmod 0644 "$OUTD/old-benchy.deb"
	echo "== T6. 0.9.7 and the hand setup, then upgrade both packages"
	start_ct c || exit 2
	x c "$APT /debs/old-benchy.deb >/dev/null 2>&1"
	check "T6 the 0.9.7 package is installed" contains "0.9.7" "$(x c 'dpkg-query -W -f="\${Version}" benchy')"
	hand_setup c
	check "T6 the hand-built 0.9.7 server answers over HTTPS..." equals "$(https_status c)" 200
	check "T6 ...and the account registered on it exists" equals "$(user_count c handuser1)" 1
	FP_C=$(fp c); HASH_C=$(state_hash c); OVR=$(x c 'sha256sum /etc/systemd/system/phoromatic-server.service.d/override.conf'); PID_C=$(prop c ExecMainPID)
	out=$(x c "$APT $BDEB $SDEB 2>&1 | tail -6")
	wait_for 90 https_up c
	NEW_PID=$(prop c ExecMainPID)
	check "T6 the upgrade restarted the service (a new process)" test "$NEW_PID" != "$PID_C"
	check "T6 ...onto 0.9.8: the start-up block is in the journal" contains "Server settings in effect" "$(journal c 300)"
	check "T6 HTTPS is still on" equals "$(https_status c)" 200
	check "T6 ...with the same CA" equals "$(fp c)" "$FP_C"
	check "T6 ...and the account still logs in" contains "Log-In Successful" "$(login c handuser1 $PW)"
	check "T6 override.conf is untouched" equals "$(x c 'sha256sum /etc/systemd/system/phoromatic-server.service.d/override.conf')" "$OVR"
	check "T6 the service is still enabled" prop_eq c UnitFileState enabled
	check "T6 --check matches the reference" contains "The layout matches the reference." "$(x c 'benchy-server-setup --check; echo rc=$?')"
	PID_C=$(prop c ExecMainPID)
	out=$(setup c --yes </dev/null; echo "rc=$?")
	check "T6 setup with no flags exits 0..." contains "rc=0" "$out"
	check "T6 ...changes nothing" equals "$(state_hash c)" "$HASH_C"
	check "T6 ...and does not restart the service" equals "$(prop c ExecMainPID)" "$PID_C"
	$PM rm -f -t 2 "$RUN-c" >/dev/null 2>&1

	echo "== T7. the same hand setup, upgrading only benchy"
	start_ct d || exit 2
	x d "$APT /debs/old-benchy.deb >/dev/null 2>&1"
	hand_setup d
	FP_D=$(fp d)
	x d "$APT $BDEB >/dev/null 2>&1"
	check "T7 benchy is now 0.9.8" equals "$(x d 'dpkg-query -W -f="\${Version}" benchy')" "$V"
	check "T7 ...and benchy-server is not installed" not_ok d "dpkg -s benchy-server"
	check "T7 the running service was not touched by the package upgrade (it runs, over HTTPS)" all_of "$(prop d ActiveState) $(https_status d)" "active" "200"
	x d "systemctl restart phoromatic-server"
	wait_for 90 https_up d
	check "T7 after a restart it runs the new code (start-up block in the journal)" contains "Server settings in effect" "$(journal d 300)"
	check "T7 ...still over HTTPS, with the same CA" equals "$(fp d)" "$FP_D"
	check "T7 ...and the account is there" equals "$(user_count d handuser1)" 1
	$PM rm -f -t 2 "$RUN-d" >/dev/null 2>&1
fi

echo "== T15. setup on a terminal: it asks, and the password is typed (a pty from script(1))"
start_ct f || exit 2
x f "$APT $BDEB $SDEB >/dev/null 2>&1"
out=$(printf 'Tty.Example.test,10.1.1.1\n\nab\ntty1user\nnot-an-email\ntty1user@example.com\nabc\n%s\n%s\n' "$PW" "$PW" | XT=400 timeout 400 $PM exec -i "$RUN-f" script -qec 'benchy-server-setup --caddy-archive /opt/caddy/caddy.tar.gz' /dev/null 2>&1 | tr -d '\r' | head -c 20000)
echo "$out" | tail -12 | sed 's/^/        > /'
check "T15 it asks for the names, the user, the email and the password" all_of "$out" "Host names and addresses" "User name for the first account" "Email address for it" "Password for tty1user" "Password again"
check "T15 a blank answer says it cannot be empty; a bad name, email and password are asked again" all_of "$out" "cannot be empty" "at least four characters" "not a valid email" "at least six characters"
check "T15 and finishes: the server is ready" contains "The Phoromatic server is ready." "$out"
check "T15 HTTPS answers" equals "$(https_status f)" 200
check "T15 the typed account logs in" contains "Log-In Successful" "$(login f tty1user $PW)"
check "T15 the names typed are in the certificate settings" contains "Names:           tty.example.test, 10.1.1.1" "$(sb f server-https-status)"
$PM rm -f -t 2 "$RUN-f" >/dev/null 2>&1

echo "== T16. first install of benchy-server over a 0.9.7 server that runs as root (the stock unit)"
if [ ! -f "$OLD_DEB" ]
then
	skip "T16 first install over a root-run server (no 0.9.7 package at $OLD_DEB)"
else
	start_ct g || exit 2
	x g "$APT /debs/old-benchy.deb >/dev/null 2>&1; systemctl start phoromatic-server"
	wait_for 90 ok g "systemctl is-active phoromatic-server && ss -ltn | grep -q LISTEN"
	PID_G=$(prop g ExecMainPID)
	check "T16 the 0.9.7 stock unit runs as root" equals "$(x g 'ps -o user= -p '$PID_G | tr -d ' ')" root
	out=$(x g "$APT $BDEB $SDEB 2>&1 | tail -8")
	check "T16 the install says the server was not restarted, and what to do" all_of "$out" "running as" "NOT restarted" "benchy-server-setup"
	check "T16 the root server was left running, the same process" equals "$(prop g ExecMainPID)" "$PID_G"
	check "T16 no empty database was created for phoromatic" not_ok g "test -e /var/lib/phoromatic/storage/phoromatic.db"
	$PM rm -f -t 2 "$RUN-g" >/dev/null 2>&1
fi

echo "== T17. storage path rules, and a database in Benchy's default folder"
start_ct h || exit 2
x h "printf '%s\n' '$PW' > /root/pw; $APT $BDEB $SDEB >/dev/null 2>&1; mkdir -p /srv/nope"
out=$(setup h --yes --names srv.example.test --storage-path /srv/nope/data --admin-user dave1 --admin-email d@example.com --admin-password-file /root/pw </dev/null; echo "rc=$?")
check "T17 a --storage-path the service user cannot write to is refused, with the reason" all_of "$out" "rc=1" "cannot write to /srv/nope" "chown phoromatic"
check "T17 ...and nothing was changed" not_ok h "test -e /var/lib/phoromatic/.benchy"
sb h server-init >/dev/null
check "T17 (setup of the scene) a database now exists in Benchy's default folder" ok h "test -f /var/lib/phoromatic/.benchy/phoromatic/phoromatic.db"
out=$(setup h --yes --names srv.example.test --admin-user dave1 --admin-email d@example.com --admin-password-file /root/pw </dev/null; echo "rc=$?")
check "T17 setup refuses to start a second, empty server beside it, and says how to keep the old one" all_of "$out" "rc=1" "already a database at /var/lib/phoromatic/.benchy/phoromatic/phoromatic.db" "--storage-path /var/lib/phoromatic/.benchy/phoromatic/"
out=$(XT=400 setup h --yes --names srv.example.test --storage-path /var/lib/phoromatic/.benchy/phoromatic/ --admin-user dave1 --admin-email d@example.com --admin-password-file /root/pw --caddy-archive /opt/caddy/caddy.tar.gz </dev/null; echo "rc=$?")
check "T17 with --storage-path naming that folder, setup keeps using it" contains "rc=0" "$out"
check "T17 ...the server reports that storage" contains "Storage: /var/lib/phoromatic/.benchy/phoromatic/" "$(sb h server-info)"
$PM rm -f -t 2 "$RUN-h" >/dev/null 2>&1

echo "== T18. purge while something of the phoromatic user is still running"
start_ct j || exit 2
x j "$APT $BDEB $SDEB >/dev/null 2>&1; install -d -o phoromatic -g phoromatic /var/lib/phoromatic/storage; echo keepme > /var/lib/phoromatic/storage/marker"
$PM exec -d "$RUN-j" runuser -u phoromatic -- sleep 600
sleep 2
out=$(x j "DEBIAN_FRONTEND=noninteractive apt-get purge -y benchy-server >/tmp/purge.log 2>&1; echo rc=\$?; tail -6 /tmp/purge.log")
check "T18 purge does not delete, names the path and the rm -rf command" all_of "$out" "was NOT deleted" "sudo rm -rf /var/lib/phoromatic"
check "T18 ...and still succeeds, so dpkg is not left half-configured" contains "rc=0" "$out"
check "T18 ...the data is still there" ok j "test -f /var/lib/phoromatic/storage/marker"
$PM rm -f -t 2 "$RUN-j" >/dev/null 2>&1

echo "== T14. a server installed from the source tree with install-sh (the existing server's way) keeps working"
start_ct e || exit 2
x e "mkdir -p /root/src" >/dev/null
$PM cp "$QC_TREE/." "$RUN-e:/root/src" >/dev/null 2>&1
x e "cd /root/src && sh install-sh /usr >/dev/null 2>&1; test -x /usr/bin/benchy && echo installed"
check "T14 install-sh installs benchy and the base unit" ok e "test -x /usr/bin/benchy && test -f /usr/lib/systemd/system/phoromatic-server.service"
check "T14 ...and none of the benchy-server files" not_ok e "test -e /usr/sbin/benchy-server-setup || test -d /usr/lib/systemd/system/phoromatic-server.service.d"
x e "cd / && benchy version >/dev/null 2>&1; ls /etc/benchy.xml" >/dev/null
hand_setup e
check "T14 the server answers over HTTPS, as the existing one does" equals "$(https_status e)" 200
log=$(journal e 300)
check "T14 its journal has the start-up block" contains "Server settings in effect" "$log"
check "T14 ...and the /etc/benchy.xml notice" contains "/etc/benchy.xml exists but this server does not read it" "$log"
out=$(x e "sh /root/src/deploy/deb-package/benchy-server/benchy-server-setup --check; echo rc=\$?")
check "T14 --check from the source tree matches the reference layout" all_of "$out" "The layout matches the reference." "rc=0"
x e "rm -f /var/lib/phoromatic/storage/tls/bin/caddy-*; systemctl restart phoromatic-server" >/dev/null
wait_for 60 is_failed e
check "T14 a refusal (Caddy gone) ends failed rather than inactive, on a server without the drop-in" equals "$(x e 'systemctl is-active phoromatic-server' | tail -1)" failed
check "T14 ...with exit status 78" prop_eq e ExecMainStatus 78
$PM rm -f -t 2 "$RUN-e" >/dev/null 2>&1

echo
echo "server-deb: $PASS passed, $FAIL failed, $SKIP skipped"
if [ "$FAIL" -ne 0 ]
then
	printf 'FAILED: %s\n' "${FAILED[@]}"
	exit 1
fi
exit 0
