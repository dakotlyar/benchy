#!/bin/bash
# The server update, end to end, in a throw-away systemd container: install the 0.16.2 test debs, set up a server, then try the web page and the root
# helper with the negative cases and a real update 0.16.2 -> 0.16.99. Nothing here touches the host's systemd or any ~/.benchy: the server, its database and
# its update service live in the container, which is removed at the end.
#
#   bash tests/server-update/make_test_builds.sh OUT          (once)
#   bash tests/server-update/container_suite.sh OUT [IMAGE]    (IMAGE: a systemd-capable Ubuntu 26.04 image with php and curl; default localhost/benchy-qc-serverdeb:59fcb193ae42)
#
# Prints one PASS/FAIL line per case, at most a few lines of detail for a failure, and the totals.
set -uo pipefail
OUT=$(realpath "${1:?usage: container_suite.sh OUT [IMAGE]}")
IMAGE=${2:-localhost/benchy-qc-serverdeb:59fcb193ae42}
HERE=$(cd "$(dirname "$0")" && pwd)
C=su-suite-$$
PW=Password-Test-1
PASS=0; FAIL=0
X() { podman exec "$C" bash -c "$1" 2>&1; }
check() { # check NAME CONDITION-RESULT(0 = ok) [DETAIL]
	if [ "$2" = 0 ]; then PASS=$((PASS + 1)); echo "  PASS  $1"; else FAIL=$((FAIL + 1)); echo "  FAIL  $1"; [ -z "${3:-}" ] || echo "$3" | head -6 | sed 's/^/        | /'; fi
}
cleanup() { podman rm -f "$C" >/dev/null 2>&1; }
trap cleanup EXIT

podman run -d --name "$C" --systemd=always -v "$OUT:/tb:ro,Z" "$IMAGE" >/dev/null
for i in $(seq 1 30); do [ "$(podman exec "$C" systemctl is-system-running 2>/dev/null)" = running ] && break; sleep 1; done
podman cp "$HERE/web.sh" "$C:/root/web.sh"
# the HTTPS names are only su.test.example (not 127.0.0.1, not localhost): the helper's health check must work anyway
X "echo '127.0.0.1 su.test.example' >> /etc/hosts"
W() { X "sh /root/web.sh $*"; }
WTXT() { X "sh /root/web.sh get '$1'" | sed 's/<[^>]*>/ /g' | tr -s ' \n\t' ' '; }

echo "-- install the 0.16.2 test debs and set the server up"
X "DEBIAN_FRONTEND=noninteractive apt-get update -qq >/dev/null 2>&1; DEBIAN_FRONTEND=noninteractive apt-get install -y /tb/debs/benchy_0.16.2_all.deb /tb/debs/benchy-server_0.16.2_all.deb 2>&1 | tail -1" | tail -1
X "printf '$PW\n' > /root/pw; benchy-server-setup --names su.test.example --admin-user serveradm --admin-email a@b.example --admin-password-file /root/pw --yes 2>&1 | tail -1"
X "printf '$PW\n' | runuser -u phoromatic -- env HOME=/var/lib/phoromatic benchy server-account-create --user tenantadm --email t@b.example --name Tenant 2>&1 | tail -2"
for i in $(seq 1 30); do X "curl -sk https://su.test.example:39443/api/v2/server" | grep -q '"version":"0.16.2"' && break; sleep 2; done
check "the test server runs 0.16.2, the path unit is enabled and active" "$(X 'curl -sk https://su.test.example:39443/api/v2/server | grep -c "\"version\":\"0.16.2\""; [ "$(systemctl is-enabled benchy-server-update.path)" = enabled ] && [ "$(systemctl is-active benchy-server-update.path)" = active ]' | tail -1 | grep -q 1 && echo 0 || echo 1)"
SCHEMA=$(X "runuser -u phoromatic -- env HOME=/var/lib/phoromatic benchy server-info | sed -n 's/.*schema version \([0-9]*\).*/\1/p'")
check "a fresh database ends at the schema the code says (DB_SCHEMA)" "$([ "$SCHEMA" = "$(sed -n 's/^[[:space:]]*const DB_SCHEMA = \([0-9]*\);.*/\1/p' "$HERE/../../pts-core/objects/phoromatic/phoromatic_server_update.php")" ] && echo 0 || echo 1)" "schema is '$SCHEMA'"

H=/var/lib/phoromatic/server-update
reset_held() { rearm; X "runuser -u phoromatic -- sh -c 'rm -rf $H/held $H/request.json $H/claimed.json'; rm -f /var/lib/benchy-server-update/status.json"; }
import() { X "cd /tmp && runuser -u phoromatic -- env HOME=/var/lib/phoromatic benchy server-update-import /tb/bundles/benchy-server-update-$1.zip 2>&1 | grep -E 'held|Refused'"; }
state() { X "sed -n 's/.*\"state\":\"\([a-z_]*\)\".*/\1/p' /var/lib/benchy-server-update/status.json 2>/dev/null"; }
msg() { X "sed -n 's/.*\"message\":\"\([^\"]*\)\".*/\1/p' /var/lib/benchy-server-update/status.json 2>/dev/null" | cut -c1-200; }
version() { X "curl -sk https://su.test.example:39443/api/v2/server | sed -n 's/.*\"version\":\"\([0-9.]*\)\".*/\1/p'"; }
dbsha() { X "sha256sum /var/lib/phoromatic/storage/phoromatic.db | cut -c1-64"; }
rearm() { X "systemctl reset-failed benchy-server-update.service benchy-server-update.path 2>/dev/null; systemctl start benchy-server-update.path" >/dev/null; }   # (3 starts in a minute stop the units, on purpose)
wait_final() { for i in $(seq 1 100); do case $(state) in done|refused|rolled_back|rollback_failed) return;; esac; sleep 3; done; }
press() { # press VERSION [PASSWORD]: log in as the server admin and press Update for the offered bundle
	rearm
	W login serveradm "${2:-$PW}" >/dev/null
	local sha; sha=$(W get "?server_settings" | grep -o 'name="signed_sha256" value="[0-9a-f]*"' | head -1 | sed 's/.*value="//;s/"//')
	X "rm -f /var/lib/benchy-server-update/status.json"
	W post "?r_server_update" "action=update\&page=server_settings\&signed_sha256=$sha\&password=${2:-$PW}" >/dev/null
	echo "$sha"
}

echo "-- G2: a tenant admin"
reset_held; import 0.16.2 >/dev/null; import 0.16.99 >/dev/null
W login tenantadm $PW | tail -1
check "G2 a tenant admin sees no Server updates section (or the page at all)" "$(WTXT '?server_settings' | grep -q 'Server updates' && echo 1 || echo 0)"
SHA=$(X "ls $H/held/*.signed | head -1 | sed 's#.*/##;s#.signed##'")
codes=""
for a in "action=update\&signed_sha256=$SHA\&password=$PW" "action=check_github" "action=upload"; do codes="$codes $(W post '?r_server_update' "$a\&page=server_settings" | tail -1)"; done
codes="$codes $(W get '?r_server_update' | head -c 80 | grep -c 'do not have access')"
check "G2 crafted update, check_github and upload POSTs and the status read are refused (403 x3, message)" "$([ "$codes" = " 403 403 403 1" ] && echo 0 || echo 1)" "got:$codes"
check "G2 no request.json was written" "$(X "[ ! -e $H/request.json ] && echo 0 || echo 1")"

echo "-- G3: the wrong password"
W login serveradm $PW >/dev/null
SHA=$(W get "?server_settings" | grep -o 'name="signed_sha256" value="[0-9a-f]*"' | head -1 | sed 's/.*value="//;s/"//')
W post "?r_server_update" "action=update\&page=server_settings\&signed_sha256=$SHA\&password=not-the-password" >/dev/null
check "G3 the wrong password is refused with a message" "$(WTXT '?server_settings' | grep -q 'password was not right' && echo 0 || echo 1)"
W post "?r_server_update" "action=update\&page=server_settings\&signed_sha256=$SHA" >/dev/null; WTXT '?server_settings' >/dev/null
check "G3 no request was written, and the audit holds the refusals" "$([ "$(X "ls $H/request.json 2>/dev/null | wc -l")" = 0 ] && [ "$(W get '?server_audit' | grep -o server_update_reauth_failed | wc -l)" -ge 2 ] && echo 0 || echo 1)"

echo "-- G4: a bad signature, an unknown key and a flipped byte, through the page and by hand"
for b in 0.16.99-unknown-key 0.16.99-flipped; do
	X "rm -rf /tmp/up && mkdir /tmp/up && cp /tb/bundles/benchy-server-update-$b.zip /tmp/up/" >/dev/null
	W upload "?r_server_update" "/tmp/up/benchy-server-update-$b.zip" >/dev/null
	check "G4 upload of $b is refused" "$(WTXT '?server_settings' | grep -q 'Refused (' && echo 0 || echo 1)"
done
check "G4 the refused uploads left nothing new in held/" "$([ "$(X "ls $H/held/*.signed | wc -l")" = 2 ] && echo 0 || echo 1)"
# by hand, as the service user (a compromised web process): a signed-by-nobody bundle in held/, and a request for it
reset_held
X "cd /tmp && rm -rf hand && mkdir hand && cd hand && unzip -q /tb/bundles/benchy-server-update-0.16.99-unknown-key.zip && chmod 755 . && chmod 644 * && chown -R phoromatic: ." >/dev/null
X "cd /tmp/hand && S=\$(sha256sum server.signed | cut -c1-64) && runuser -u phoromatic -- sh -c \"mkdir -p $H/held/files && cp server.signed $H/held/\$S.signed && for f in *.deb; do cp \\\$f $H/held/files/\\\$(sha256sum \\\$f | cut -c1-64); done && printf '{\\\"schema\\\":\\\"benchy-server-update-request/1\\\",\\\"signed_sha256\\\":\\\"'\$S'\\\",\\\"requested_by\\\":\\\"hand\\\",\\\"requested_at\\\":\\\"2026-11-24T10:00:00Z\\\"}' > $H/request.json\"" >/dev/null
wait_final
check "G4 by hand: a bundle signed by an unknown key is refused by root, nothing installed" "$([ "$(state)" = refused ] && [ "$(version)" = 0.16.2 ] && msg | grep -q 'does not know' && echo 0 || echo 1)" "state=$(state) $(msg)"
# by hand: a good signature, but one deb is a symlink to a root-only file
reset_held; import 0.16.99 >/dev/null; import 0.16.2 >/dev/null
X "echo secret > /root/secret.txt; chmod 600 /root/secret.txt; F=\$(ls -S $H/held/files | head -1); runuser -u phoromatic -- sh -c \"rm $H/held/files/\$F && ln -s /root/secret.txt $H/held/files/\$F\"; echo \$F > /tmp/linked" >/dev/null
SHA=$(X "for f in $H/held/*.signed; do grep -l 0.16.99 \$f >/dev/null && echo \$f; done | head -1 | sed 's#.*/##;s#.signed##'")
X "runuser -u phoromatic -- sh -c \"printf '{\\\"schema\\\":\\\"benchy-server-update-request/1\\\",\\\"signed_sha256\\\":\\\"$SHA\\\",\\\"requested_by\\\":\\\"hand\\\",\\\"requested_at\\\":\\\"2026-11-24T10:00:00Z\\\"}' > $H/request.json\"" >/dev/null
wait_final
check "G4 by hand: a deb replaced by a symlink to a root-only file is refused, nothing installed" "$([ "$(state)" = refused ] && [ "$(version)" = 0.16.2 ] && echo 0 || echo 1)" "state=$(state) $(msg)"
# by hand: requests that are not requests
for body in '{}' '{"schema":"benchy-server-update-request/1","signed_sha256":"../../etc/shadow","requested_by":"x","requested_at":"2026-11-24T10:00:00Z"}' '{"schema":"benchy-server-update-request/1","signed_sha256":"'$(printf 'a%.0s' $(seq 64))'","requested_by":"$(touch /pwned)","requested_at":"2026-11-24T10:00:00Z"}'; do
	rearm
	X "rm -f /var/lib/benchy-server-update/status.json; runuser -u phoromatic -- sh -c \"cat > $H/request.json\" <<'EOQ'
$body
EOQ"
	wait_final
	check "G4 by hand: a malformed request is refused ($(echo "$body" | cut -c1-40)...)" "$([ "$(state)" = refused ] && msg | grep -q 'request' && [ ! -e /pwned ] && echo 0 || echo 1)" "state=$(state) $(msg)"
done
check "G4 nothing ran from a request's text (no /pwned in the container)" "$(X '[ ! -e /pwned ] && echo 0 || echo 1' | tail -1)"

echo "-- the update service ends cleanly on refusals (no start limit after 4 bad requests)"
rearm
X "systemctl reset-failed benchy-server-update.service benchy-server-update.path; systemctl start benchy-server-update.path" >/dev/null
for i in 1 2 3 4; do
	X "rm -f /var/lib/benchy-server-update/status.json; runuser -u phoromatic -- sh -c 'echo {} > $H/request.json'"
	wait_final
done
check "four bad requests in a row: the service Result is success and the path unit is still active" "$([ "$(X 'systemctl show -p Result --value benchy-server-update.service')" = success ] && [ "$(X 'systemctl is-active benchy-server-update.path')" = active ] && [ "$(state)" = refused ] && echo 0 || echo 1)" "Result=$(X 'systemctl show -p Result --value benchy-server-update.service') path=$(X 'systemctl is-active benchy-server-update.path')"

echo "-- the database copy covers the -wal file"
podman cp "$HERE/../../deploy/deb-package/benchy-server/benchy-server-update" "$C:/root/helper-under-test.php"
cat > /tmp/wal_test.php.$$ <<'EOPHP'
<?php
require '/root/helper-under-test.php';
$cp = new ReflectionMethod('BenchyServerUpdate', 'copy_db');
$dg = new ReflectionMethod('BenchyServerUpdate', 'db_digest');
$d = '/tmp/dbt';
exec('rm -rf ' . $d . ' && mkdir ' . $d . ' && chown phoromatic: ' . $d);
$u = function($c) { exec('runuser -u phoromatic -- sh -c ' . escapeshellarg($c)); };
$u("cd $d && echo main > a.db && echo wal-bytes > a.db-wal && echo shm > a.db-shm");
$r = array();
$r[] = $cp->invoke(null, "$d/a.db", "$d/b.db") === null ? 'ok' : 'copy failed';
$r[] = is_file("$d/b.db-wal") && !file_exists("$d/b.db-shm") ? 'ok' : 'wal missing or shm kept';
$r[] = $dg->invoke(null, "$d/a.db") === $dg->invoke(null, "$d/b.db") && strpos($dg->invoke(null, "$d/b.db"), 'none') === false ? 'ok' : 'digests differ';
$u("cd $d && echo changed > b.db-wal");
$r[] = $dg->invoke(null, "$d/a.db") !== $dg->invoke(null, "$d/b.db") ? 'ok' : 'a different -wal was not noticed';
$u("cd $d && rm a.db-wal");
$r[] = $cp->invoke(null, "$d/a.db", "$d/b.db") === null && !file_exists("$d/b.db-wal") && $dg->invoke(null, "$d/a.db") === $dg->invoke(null, "$d/b.db") ? 'ok' : 'stale -wal left in the copy';
$u("cd $d && echo w > a.db-wal && chmod 000 a.db-wal");
$r[] = $cp->invoke(null, "$d/a.db", "$d/c.db") !== null ? 'ok' : 'an unreadable -wal was copied without an error';
echo implode(',', $r), "\n";
EOPHP
podman cp /tmp/wal_test.php.$$ "$C:/root/wal_test.php"; rm -f /tmp/wal_test.php.$$
check "copy and compare the database with its -wal: copied, compared, stale wal removed, a failing wal copy reported" "$([ "$(X 'php -n /root/wal_test.php')" = ok,ok,ok,ok,ok,ok ] && echo 0 || echo 1)" "$(X 'php -n /root/wal_test.php')"

echo "-- move-to-deb.sh never overwrites the copy from an earlier run"
podman cp "$HERE/../../deploy/deb-package/move-to-deb.sh" "$C:/root/move-to-deb.sh"
X "echo precious > /root/phoromatic-pre-deb.db; touch /tmp/b.zip; bash /root/move-to-deb.sh /tb/debs /tmp/b.zip 2>&1 | tail -2" | grep -q "STOP: /root/phoromatic-pre-deb.db exists" && [ "$(X 'cat /root/phoromatic-pre-deb.db')" = precious ] && [ "$(X 'systemctl is-active phoromatic-server')" = active ] && R=0 || R=1
check "a rerun stops before stopping the server, and the earlier copy is untouched" "$R"
X "rm -f /root/phoromatic-pre-deb.db"

echo "-- move-to-deb.sh: the way-back bundle sits in a root-only folder"
X "mkdir -p /root/mv/debs && chmod 700 /root/mv && cp /tb/debs/*0.16.99_all.deb /root/mv/debs/ && cp /tb/bundles/benchy-server-update-0.16.99.zip /root/mv/b.zip && chmod 600 /root/mv/b.zip && rm -rf $H/held"
X "bash /root/move-to-deb.sh /root/mv/debs /root/mv/b.zip > /root/mv/out.txt 2>&1; echo exit=\$? >> /root/mv/out.txt"
check "move-to-deb.sh imports the bundle from a root-only folder and finishes (exit 0, server on 0.16.99, bundle held, no temp copy left)" "$([ "$(X 'tail -1 /root/mv/out.txt')" = exit=0 ] && [ "$(version)" = 0.16.99 ] && [ "$(X "ls $H/held/*.signed | wc -l")" = 1 ] && ! X 'ls /tmp/tmp.*/b.zip' >/dev/null 2>&1 && echo 0 || echo 1)" "exit: $(X 'tail -1 /root/mv/out.txt') version=$(version) held=$(X "ls $H/held/*.signed | wc -l") $(X 'ls /tmp/tmp.*/b.zip 2>&1' | head -2)"
X "DEBIAN_FRONTEND=noninteractive apt-get install -y --allow-downgrades /tb/debs/benchy_0.16.2_all.deb /tb/debs/benchy-server_0.16.2_all.deb >/dev/null 2>&1; rm -rf /usr/share/benchy.pre-deb /root/phoromatic-pre-deb.db /root/mv"
for i in $(seq 1 30); do [ "$(version)" = 0.16.2 ] && break; sleep 3; done

echo "-- G5: downgrade"
reset_held
check "G5 a 0.16.1 bundle is refused at import" "$(import 0.16.1 | grep -q 'Refused (downgrade)' && echo 0 || echo 1)"
# by hand: a validly signed 0.16.1 bundle in held/, requested directly
X "cd /tmp && rm -rf hand && mkdir hand && cd hand && unzip -q /tb/bundles/benchy-server-update-0.16.1.zip && chmod 755 . && chmod 644 * && chown -R phoromatic: ." >/dev/null
X "cd /tmp/hand && S=\$(sha256sum server.signed | cut -c1-64) && runuser -u phoromatic -- sh -c \"mkdir -p $H/held/files && cp server.signed $H/held/\$S.signed && for f in *.deb; do cp \\\$f $H/held/files/\\\$(sha256sum \\\$f | cut -c1-64); done && printf '{\\\"schema\\\":\\\"benchy-server-update-request/1\\\",\\\"signed_sha256\\\":\\\"'\$S'\\\",\\\"requested_by\\\":\\\"hand\\\",\\\"requested_at\\\":\\\"2026-11-24T10:00:00Z\\\"}' > $H/request.json\"" >/dev/null
wait_final
check "G5 by hand: the helper refuses a validly signed older version" "$([ "$(state)" = refused ] && [ "$(version)" = 0.16.2 ] && msg | grep -q 'never installed' && echo 0 || echo 1)" "state=$(state) $(msg)"

echo "-- G8: no way back held"
reset_held; import 0.16.99 >/dev/null
W login serveradm $PW >/dev/null
check "G8 the page says the bundle of the running version is missing and offers no button" "$(WTXT '?server_settings' | grep -q 'is not held' && ! W get '?server_settings' | grep -q 'Update server' && echo 0 || echo 1)"
SHA=$(X "ls $H/held/*.signed | head -1 | sed 's#.*/##;s#.signed##'")
W post "?r_server_update" "action=update\&page=server_settings\&signed_sha256=$SHA\&password=$PW" >/dev/null
check "G8 a crafted request without a way back is refused by the page" "$(WTXT '?server_settings' | grep -q 'not held' && [ ! -e /dev/null ] || X "[ ! -e $H/request.json ] && echo 0 || echo 1" | tail -1)"
X "rm -f /var/lib/benchy-server-update/status.json; runuser -u phoromatic -- sh -c \"printf '{\\\"schema\\\":\\\"benchy-server-update-request/1\\\",\\\"signed_sha256\\\":\\\"$SHA\\\",\\\"requested_by\\\":\\\"hand\\\",\\\"requested_at\\\":\\\"2026-11-24T10:00:00Z\\\"}' > $H/request.json\"" >/dev/null
wait_final
check "G8 by hand: the helper refuses too, with the message" "$([ "$(state)" = refused ] && msg | grep -q 'no signed bundle of the installed version' && echo 0 || echo 1)" "state=$(state) $(msg)"

for bad in 0.16.98 0.16.97; do
	echo "-- G6: a failure partway through ($bad)"
	reset_held; import 0.16.2 >/dev/null; import $bad >/dev/null
	press $bad >/dev/null
	wait_final
	BK=$(X "sed -n 's/.*\"backup\":\"\([^\"]*\)\".*/\1/p' /var/lib/benchy-server-update/status.json")
	for i in $(seq 1 20); do [ "$(version)" = 0.16.2 ] && break; sleep 3; done
	check "G6 $bad ends in rolled_back, 0.16.2 runs again" "$([ "$(state)" = rolled_back ] && [ "$(version)" = 0.16.2 ] && [ "$(X "dpkg-query -W -f='\${Version}' benchy-server")" = 0.16.2 ] && echo 0 || echo 1)" "state=$(state) $(msg) version=$(version)"
	check "G6 $bad: the database is byte for byte the copy taken after the stop" "$([ -n "$BK" ] && [ "$(X "sha256sum $BK | cut -c1-64")" = "$(dbsha)" ] && echo 0 || echo 1)" "backup=$BK"
	check "G6 $bad: the log says what failed, and the page shows rolled_back" "$(X "grep -q FAILED /var/lib/benchy-server-update/last.log" && W get '?r_server_update' | grep -q '"state":"rolled_back"' && echo 0 || echo 1)"
done

echo "-- G1: the golden path, uploaded through the page"
reset_held
BEFORE_ACCOUNTS=$(X "runuser -u phoromatic -- env HOME=/var/lib/phoromatic benchy server-info | sed -n 's/^Accounts: //p'")
W login serveradm $PW >/dev/null
for v in 0.16.2 0.16.99; do
	X "rm -rf /tmp/up && mkdir /tmp/up && cp /tb/bundles/benchy-server-update-$v.zip /tmp/up/" >/dev/null
	W upload "?r_server_update" "/tmp/up/benchy-server-update-$v.zip" >/dev/null
	check "G1 upload of $v through the page is accepted" "$(WTXT '?server_settings' | grep -q "Server update $v is checked and held" && echo 0 || echo 1)"
done
press 0.16.99 >/dev/null
wait_final
for i in $(seq 1 20); do [ "$(version)" = 0.16.99 ] && break; sleep 3; done
check "G1 ends in done and the server reports 0.16.99" "$([ "$(state)" = done ] && [ "$(version)" = 0.16.99 ] && echo 0 || echo 1)" "state=$(state) $(msg)"
AFTER=$(X "runuser -u phoromatic -- env HOME=/var/lib/phoromatic benchy server-info | sed -n 's/.*schema version \([0-9]*\).*/\1/p;s/^Accounts: /acc /p'" | tr '\n' ' ')
check "G1 the schema is the signed one, the accounts are still there ($BEFORE_ACCOUNTS)" "$([ "$AFTER" = "$SCHEMA acc $BEFORE_ACCOUNTS " ] && echo 0 || echo 1)" "after: $AFTER"
check "G1 the database copy and the audit rows are there, and the archive holds 0.16.2 and 0.16.99" "$([ "$(X "ls $H/backup/*.db | wc -l")" -ge 1 ] && W login serveradm $PW >/dev/null && [ "$(W get '?server_audit' | grep -o 'server_update_requested\|server_update_result\|server_update_imported' | sort -u | wc -l)" = 3 ] && [ "$(X 'ls /var/lib/benchy-server-update/archive | tr "\n" " "')" = "0.16.2 0.16.99 " ] && echo 0 || echo 1)" "archive: $(X 'ls /var/lib/benchy-server-update/archive' | tr '\n' ' ')"
check "G1 the page still works after the restart and says done" "$(W get '?r_server_update' | grep -q '"state":"done"' && echo 0 || echo 1)"
check "G1 a second Update is refused: nothing newer, no button" "$(W get '?server_settings' | grep -q 'Update server' && echo 1 || echo 0)"
check "the helper and units are root-owned, the folders as designed" "$([ "$(X "stat -c '%U %a' /usr/lib/benchy/benchy-server-update /var/lib/benchy-server-update $H" | tr '\n' ',')" = "root 755,root 755,phoromatic 750," ] && echo 0 || echo 1)" "$(X "stat -c '%U %a' /usr/lib/benchy/benchy-server-update /var/lib/benchy-server-update $H" | tr '\n' ',')"

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" = 0 ]
