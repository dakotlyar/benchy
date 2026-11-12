#!/bin/bash
# A PhoromaticStorage that is set and cannot be written is a start-up error (T2, Dave's decision of 2026-11-10), not a quiet fall-back to another folder.
# Driven by tenancy.sh (needs RUN, QC_NET, QC_IMAGE and QC_TREE from run.sh). Throw-away containers as an ordinary user (uid 33) on a volume of their own.
#   - a storage folder that can be made or written: works (the control), and an empty setting still uses the folder under the user data directory
#   - a folder it cannot write, one it cannot make, one that is a file: server-init and start-phoromatic-server stop with status 78 and a message that names
#     the setting and the folder; server-info still reads and says what is wrong; a web request gets 503 with a plain message (the detail is in the server log); no folder is made anywhere else
set -u
HERE=$(cd "$(dirname "$0")" && pwd)
: "${QC_NET:?run via tenancy.sh}"
ST=${RUN}-st
VOL=${ST}-data
rc=0
trap 'docker rm -f "${ST}-web" >/dev/null 2>&1; docker volume rm -f "$VOL" >/dev/null 2>&1' EXIT
docker volume create "$VOL" >/dev/null
docker run --rm -v "$VOL":/data "$QC_IMAGE" sh -c 'chown 33:33 /data; mkdir -p /data/ro && chown root:root /data/ro && chmod 755 /data/ro; echo "not a folder" > /data/afile; chown root:root /data/afile'
as_user() {  # as_user <shell command>: as uid 33 with the user data directory /data (where its settings file is)
	docker run --rm --user 33:33 -e PTS_USER_PATH_OVERRIDE=/data -v "$VOL":/data -v "$QC_TREE":/bench:ro -w /bench "$QC_IMAGE" sh -c "$1" </dev/null 2>&1 | head -c 20000
}
set_storage() { as_user "sh /bench/benchy user-config-set PhoromaticStorage=$1" >/dev/null; }
fails=0
check() {  # check <description> <command...>: the command succeeds
	local what=$1; shift
	if "$@"; then echo "  PASS  $what"; else echo "  FAIL  $what"; fails=$((fails + 1)); fi
}
has() { grep -q -- "$1" <<<"$2"; }
lacks() { ! grep -q -- "$1" <<<"$2"; }
status_of() {  # status_of <storage> <command>: the exit status of benchy <command> with that storage setting
	set_storage "$1"
	as_user "sh /bench/benchy $2 >/dev/null 2>&1; echo \$?" | tail -1
}

echo "-- a storage folder that can be made works (the control)"
set_storage /data/okstore
out=$(as_user 'sh /bench/benchy server-init; echo "rc=$?"')
check "server-init makes the database in the folder the setting names" has "Database: /data/okstore/phoromatic.db" "$out"
check "...and exits 0" has "rc=0" "$out"
check "...with nothing under the fallback folder" test -z "$(as_user 'ls /data/phoromatic 2>/dev/null')"

echo "-- a folder that cannot be written, cannot be made, or is a file"
for case in "/data/ro|exists but cannot be written|cannot be written by" "/data/ro/new/deep|does not exist|cannot be made" "/data/afile|is a file|not a folder"; do
	IFS='|' read -r path label expect <<<"$case"
	set_storage "$path"
	out=$(as_user 'sh /bench/benchy server-init; echo "rc=$?"')
	check "$label: server-init stops with 78" has "rc=78" "$out"
	check "...and says which setting and which folder" has "PhoromaticStorage is set to $path" "$out"
	check "...and why ($expect)" has "$expect" "$out"
	check "...and that the server does not fall back to another folder" has "does not fall back to another folder" "$out"
	check "$label: start-phoromatic-server stops with 78" test "$(as_user 'sh /bench/benchy start-phoromatic-server >/dev/null 2>&1; echo $?' | tail -1)" = 78
	out=$(as_user 'sh /bench/benchy server-info; echo "rc=$?"')
	check "$label: server-info still reads, says there is a problem, and exits 0" has "PROBLEM: PhoromaticStorage is set to $path" "$out"
	check "...rc=0" has "rc=0" "$out"
	out=$(as_user 'sh /bench/benchy phoromatic-client-phone-home 2>&1; echo "rc=$?"')
	check "$label: a command that needs the database (phoromatic-client-phone-home) names the storage problem and exits 78, not 'no database was found'" has "PhoromaticStorage is set to $path" "$out"
	check "...rc=78" has "rc=78" "$out"
	check "...and does not say a database was not found" lacks "No Phoromatic database was found" "$out"
	check "$label: nothing was made under the fallback folder" test -z "$(as_user 'ls -d /data/phoromatic 2>/dev/null')"
done

echo "-- a web request on a server whose storage cannot be written"
set_storage /data/ro
docker run -d --name "${ST}-web" --network "$QC_NET" --user 33:33 -e PTS_USER_PATH_OVERRIDE=/data -v "$VOL":/data -v "$QC_TREE":/bench:ro -w /bench/pts-core/phoromatic/public_html \
	"$QC_IMAGE" php -d display_errors=0 -S 0.0.0.0:8080 -t /bench/pts-core/phoromatic/public_html >/dev/null
for i in $(seq 1 20); do docker exec "${ST}-web" curl -s -o /dev/null http://127.0.0.1:8080/ 2>/dev/null && break; sleep 0.5; done
code=$(docker exec "${ST}-web" curl -s -o /tmp/body -w '%{http_code}' http://127.0.0.1:8080/)
check "the welcome page answers 503" test "$code" = 503
body=$(docker exec "${ST}-web" cat /tmp/body)
check "...with a plain message and no path, setting name or user in it" lacks "/data" "$body"
check "...that points at the server log" has "server log" "$body"
check "...and the server log has the detail" has "PhoromaticStorage is set to /data/ro" "$(docker logs "${ST}-web" 2>&1)"
check "...and no fallback folder was made" test -z "$(as_user 'ls -d /data/phoromatic 2>/dev/null')"
docker rm -f "${ST}-web" >/dev/null 2>&1

echo "-- the value the configuration file ships with is not a folder anyone chose"
set_storage "~/.benchy/phoromatic/"
out=$(as_user 'sh /bench/benchy server-info; echo "rc=$?"')
check "the shipped default with no database anywhere and no writable ~/.benchy: the folder under the user data directory is used" has "Storage: /data/phoromatic/ " "$out"
check "...with no problem" lacks "PROBLEM" "$out"

echo "-- an empty setting still uses the folder under the user data directory"
set_storage ""
out=$(as_user 'sh /bench/benchy server-info')
check "server-info names the fallback and why" has "the folder under the user data directory, because PhoromaticStorage is empty" "$out"
check "...with no problem" lacks "PROBLEM" "$out"

echo "-- the shipped default (~/.benchy/phoromatic/) is not a chosen folder: the folder that holds the database wins, two are an error, none uses the old rule"
# (the home folder comes from the password file, not from HOME: root's is /root and www-data's /var/www, so each case prepares them as root and then drops to the user)
docker run --rm -v "$VOL":/data "$QC_IMAGE" sh -c 'mkdir -p /data/ov-tilde /data/ov-both/phoromatic /data/ov-data/phoromatic /data/ov-none; touch /data/ov-both/phoromatic/phoromatic.db /data/ov-data/phoromatic/phoromatic.db; chown -R 33:33 /data/ov-tilde /data/ov-both /data/ov-data /data/ov-none'
default_case() {  # default_case <uid> <override dir or -> <preparation as root> <command>: PhoromaticStorage at the value the file ships with, in a container of its own
	local env=(-e "CASE_CMD=sh /bench/benchy user-config-set 'PhoromaticStorage=~/.benchy/phoromatic/' >/dev/null 2>&1; $4"); [ "$2" != - ] && env+=(-e "PTS_USER_PATH_OVERRIDE=$2")
	local run='sh -c "$CASE_CMD"'; [ "$1" != 0 ] && run='setpriv --reuid=33 --regid=33 --clear-groups sh -c "$CASE_CMD"'
	docker run --rm --user 0 "${env[@]}" -v "$VOL":/data -v "$QC_TREE":/bench:ro -w /bench "$QC_IMAGE" sh -c "$3
exec $run" </dev/null 2>&1 | head -c 20000
}
WWW_DB='mkdir -p /var/www/.benchy/phoromatic && touch /var/www/.benchy/phoromatic/phoromatic.db && chown -R 33:33 /var/www/.benchy'
out=$(default_case 0 - 'mkdir -p /root/.benchy/phoromatic && touch /root/.benchy/phoromatic/phoromatic.db' 'sh /bench/benchy server-info')
check "run by root (the data is in root's ~/.benchy/phoromatic/, the user data directory is /var/lib/benchy/): the folder with the database is used" has "Storage: /root/.benchy/phoromatic/ " "$out"
check "...and server-info says why" has "that folder holds the database" "$out"
out=$(default_case 33 /data/ov-data ':' 'sh /bench/benchy server-info')
check "with PTS_USER_PATH_OVERRIDE and the data in the override folder (none in ~): the override folder's phoromatic/ is used" has "Storage: /data/ov-data/phoromatic/ " "$out"
check "...and server-info says why" has "the folder under the user data directory, because PhoromaticStorage still has the shipped default and this folder holds the database" "$out"
out=$(default_case 33 /data/ov-tilde "$WWW_DB" 'sh /bench/benchy server-info')
check "data in ~ only: the ~ folder is used (not an empty database under the override folder)" has "Storage: /var/www/.benchy/phoromatic/ " "$out"
check "...and server-info says the default folder holds the database" has "that folder holds the database" "$out"
check "...and nothing was made under the override folder" test -z "$(as_user 'ls -d /data/ov-tilde/phoromatic 2>/dev/null')"
out=$(default_case 33 /data/ov-both "$WWW_DB" 'sh /bench/benchy server-init; echo "rc=$?"')
check "both hold a database: server-init stops with 78" has "rc=78" "$out"
check "...naming both folders" has "/var/www/.benchy/phoromatic/ and /data/ov-both/phoromatic/" "$out"
check "...and telling the administrator to set PhoromaticStorage" has "Set PhoromaticStorage" "$out"
check "...without the advice for an unwritable folder" lacks "Fix the owner" "$out"
out=$(default_case 33 /data/ov-both "$WWW_DB" 'sh /bench/benchy start-phoromatic-server >/dev/null 2>&1; echo "rc=$?"')
check "...and start-phoromatic-server stops with 78" has "rc=78" "$out"
out=$(default_case 33 /data/ov-both "$WWW_DB" 'sh /bench/benchy server-info; echo "rc=$?"')
check "...and server-info says PROBLEM and exits 0" has "PROBLEM: Both /var/www/.benchy/phoromatic/" "$out"
check "...rc=0" has "rc=0" "$out"
out=$(default_case 33 /data/ov-none 'mkdir -p /var/www/.benchy && chown 33:33 /var/www/.benchy' 'sh /bench/benchy server-info')
check "neither holds one, ~/.benchy can be written: the old rule picks the ~ folder" has "Storage: /var/www/.benchy/phoromatic/ " "$out"
check "...and server-info says no database exists yet" has "no database exists yet" "$out"
out=$(default_case 33 /data/ov-none ':' 'sh /bench/benchy server-info')
check "neither holds one, ~/.benchy cannot be written: the folder under the user data directory" has "Storage: /data/ov-none/phoromatic/ " "$out"
out=$(default_case 33 /data/ov-none ':' 'sh /bench/benchy user-config-set PhoromaticStorage=/data/ro/x >/dev/null 2>&1; sh /bench/benchy server-init; echo "rc=$?"')
check "a folder that is set explicitly keeps its rule: unwritable is a start-up error" has "rc=78" "$out"

echo "-- a storage folder that belongs to another user keeps the 'Run: sudo -u OWNER' advice (not the chown advice), and server-info still ends with 0"
docker run --rm -v "$VOL":/data "$QC_IMAGE" sh -c 'mkdir -p /data/other && touch /data/other/phoromatic.db && chown -R 0:0 /data/other && chmod 755 /data/other'
set_storage /data/other
out=$(as_user 'sh /bench/benchy bench-token-list; echo "rc=$?"')
check "a token command: tells the user to run it as the owner" has "Run: sudo -u root" "$out"
check "...and does not give the 78 storage advice" lacks "Fix the owner or the mount" "$out"
out=$(as_user 'sh /bench/benchy server-info; echo "rc=$?"')
check "server-info prints the problem, the owner advice, and exits 0" has "PROBLEM: PhoromaticStorage is set to /data/other" "$out"
check "...with sudo -u root advice" has "sudo -u root" "$out"
check "...rc=0" has "rc=0" "$out"

echo "-- the settings file writer: 0600 from the start, the owner kept, stale temporaries, no store path"
out=$(docker run --rm --user 0:0 -v "$QC_TREE":/bench:ro -v "$HERE/storage_object_check.php":/check.php:ro "$QC_IMAGE" sh -c 'mkdir -p /tmp/so && php /check.php /tmp/so' </dev/null 2>&1 | head -c 20000)
printf '%s\n' "$out"
check "pts_storage_object checks" has "STORAGE OBJECT: PASS" "$out"
[ $fails -eq 0 ] && echo "STORAGE: PASS" || { echo "STORAGE: FAIL ($fails)"; rc=1; }
exit $rc
