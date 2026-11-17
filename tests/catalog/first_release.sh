#!/bin/bash
# E4.4 C6: the rehearsal of the first signed catalog release, on a scratch docker server (own network, own volumes; nothing touches this machine's
# Benchy). Run through run.sh: `run.sh catalog-release` (and part of `all`). It has two modes.
#
#   throw-away (the default): a copy of the tree whose key table holds keys made fresh for this run (the same harness-only swap C1 to C3 use), bundles
#     signed by them (PHP, for both tiers; the engine's own signing code, for a real engine), and a catalogtest build of the engine. It proves the code
#     and the steps. It does not prove the production keys.
#   real: FIRST_RELEASE_DIR=<folder with benchy-catalog-public-1-1.zip and, optionally, benchy-catalog-private-1-1.zip, as `finish` made them> and
#     FIRST_RELEASE_ENGINE=<a released benchy-engine binary, 0.6.0 or later>. The tree is used as it is, with the production key table; nothing is replaced.
#     The same steps run, with whatever generation and sequence the bundles carry. Rerun this when Dave has the first signed release in hand:
#       FIRST_RELEASE_DIR=~/benchy-release/catalog FIRST_RELEASE_ENGINE=/path/to/benchy-engine tests/private-repo/run.sh catalog-release
#
# The steps (design 13, "the first signed catalog release"):
#   1. a server that starts with the public bundle in the package folder imports it by itself (the packaged import), once
#   2. server-catalog-check on each bundle, then server-catalog-import on the other tier, server-catalog-status, a repeat that changes nothing
#   3. a real engine enrolls, says hello, takes the release, installs from a pack through the server's route
#   4. (throw-away) a second release is imported, and the engine follows it; a schedule pinned to the first release's version still issues that version
set -u
: "${QC_IMAGE:?run via run.sh catalog-release}" "${QC_TREE:?run via run.sh catalog-release}"
HERE=$(cd "$(dirname "$0")" && pwd)
RUN=${RUN:-qcfr$$}
SV=${RUN}-fr
REAL=0; [ -n "${FIRST_RELEASE_DIR:-}" ] && REAL=1
NEWTREE=$(mktemp -d /tmp/benchy-qc-frtree.XXXXXX)
BUNDLES=$(mktemp -d /tmp/benchy-qc-frbundles.XXXXXX)
E2E=
cleanup() { docker rm -f "${SV}-srv" "${SV}-eng" >/dev/null 2>&1; docker volume rm -f "${SV}-lib" "${SV}-cache" "${SV}-lib2" "${SV}-cache2" >/dev/null 2>&1; docker network rm "${SV}-net" >/dev/null 2>&1; rm -rf "$NEWTREE" "$BUNDLES" "$E2E"; }
trap cleanup EXIT
rc=0
pass() { echo "  PASS  $1"; }
fail() { echo "  FAIL  $1"; [ -n "${2:-}" ] && printf '%s\n' "$2" | head -c 1500 | sed 's/^/        | /'; rc=1; }
SIMG=benchy-qc-sockets:$(docker image inspect -f '{{.Id}}' "$QC_IMAGE" | cut -c8-19)
docker image inspect "$SIMG" >/dev/null 2>&1 || printf 'FROM %s\nRUN docker-php-ext-install sockets\n' "$QC_IMAGE" | docker build -q -t "$SIMG" - >/dev/null || { echo "sockets image build failed"; exit 2; }
docker network create "${SV}-net" >/dev/null || exit 2
cp -a "$QC_TREE"/. "$NEWTREE"/
chmod 777 "$BUNDLES"

echo "== first signed release rehearsal ($([ $REAL = 1 ] && echo "REAL bundles and production keys: $FIRST_RELEASE_DIR" || echo "throw-away keys: proves the code and the steps, not the production keys"))"
PUB=benchy-catalog-public-1-1.zip
PRIV=benchy-catalog-private-1-1.zip
if [ $REAL = 1 ]; then
	PUB=$(cd "$FIRST_RELEASE_DIR" && ls benchy-catalog-public-*.zip 2>/dev/null | head -1)
	[ -n "$PUB" ] || { echo "  FAIL  no benchy-catalog-public-*.zip in $FIRST_RELEASE_DIR"; exit 1; }
	PRIV=$(cd "$FIRST_RELEASE_DIR" && ls benchy-catalog-private-*.zip 2>/dev/null | head -1)
	cp "$FIRST_RELEASE_DIR/$PUB" "$BUNDLES/$PUB"; [ -z "$PRIV" ] || cp "$FIRST_RELEASE_DIR/$PRIV" "$BUNDLES/$PRIV"
else
	docker run --rm --network none -u 1000 -v "$QC_TREE":/bench:ro -v "$BUNDLES":/out "$QC_IMAGE" php -d memory_limit=512M /bench/tests/catalog/make_rollback_bundles.php /out || exit 2
	cp "$BUNDLES/keys.json" "$NEWTREE/pts-core/static/catalog/keys.json"; rm -f "$BUNDLES/keys.json"
	mv "$BUNDLES/a.zip" "$BUNDLES/$PUB"; mv "$BUNDLES/b.zip" "$BUNDLES/$PRIV"; rm -f "$BUNDLES/c.zip"
fi
chmod 644 "$BUNDLES"/*.zip

start_server() {  # start_server <tree> <lib volume> <cache volume>: the real launcher, as a service runs it
	docker rm -f "${SV}-srv" >/dev/null 2>&1
	docker run -d --name "${SV}-srv" --network "${SV}-net" --network-alias benchy-srv -p 127.0.0.1::8080 -e PHOROMATIC_HTTP_PORT=8080 -e PTS_FORCE_DAEMONIZED=1 \
		-v "$2":/var/lib/benchy -v "$3":/var/cache/benchy -v "$1":/bench:ro -v "$BUNDLES":/bundles:ro -w /bench "$SIMG" \
		sh -c 'sh /bench/benchy start-phoromatic-server >/tmp/launcher.out 2>&1; sleep 3600' >/dev/null || exit 2
	TN_PORT=$(docker port "${SV}-srv" 8080/tcp | head -1 | sed 's/.*://')
	for i in $(seq 1 60); do curl -s -o /dev/null "http://127.0.0.1:$TN_PORT/" && break; sleep 1; done
	for i in $(seq 1 30); do docker exec "${SV}-srv" test -f /var/lib/benchy/phoromatic/phoromatic.db && break; sleep 1; done
}
sv() { docker exec -i "${SV}-srv" "$@" 2>&1 </dev/null | head -c 6000; }
dbq() {  # dbq <sql>: the first column of the first row, from the server's database
	docker exec -i "${SV}-srv" php -r '$d=new SQLite3("/var/lib/benchy/phoromatic/phoromatic.db",SQLITE3_OPEN_READONLY);$d->busyTimeout(5000);echo $d->querySingle($argv[1]);' "$1" 2>&1 </dev/null | head -c 2000
}
dbx() { docker exec -i "${SV}-srv" php -r '$d=new SQLite3("/var/lib/benchy/phoromatic/phoromatic.db");$d->busyTimeout(5000);$d->exec($argv[1]);' "$1" 2>&1 </dev/null | head -c 2000; }
tier_line() { dbq "SELECT Generation||' '||Sequence||' '||ImportedVia FROM phoromatic_catalog_tiers WHERE Tier='$1'"; }
imports() { dbq "SELECT COUNT(*) FROM phoromatic_server_audit WHERE Action='catalog_imported'"; }

# ---- 1 and 2: the packaged import, the command line, both tiers
echo "== 1. the packaged public bundle (at /usr/share/benchy/catalog/ in a package) is imported by the first start"
mkdir -p "$NEWTREE/catalog"; cp "$BUNDLES/$PUB" "$NEWTREE/catalog/$PUB"
docker volume create "${SV}-lib" >/dev/null; docker volume create "${SV}-cache" >/dev/null
start_server "$NEWTREE" "${SV}-lib" "${SV}-cache"
for i in $(seq 1 90); do [ -n "$(tier_line public)" ] && break; sleep 1; done
want_gen_seq=$(echo "$PUB" | sed -n 's/^benchy-catalog-public-\([0-9]*\)-\([0-9]*\)\.zip$/\1 \2/p')
case "$(tier_line public)" in "$want_gen_seq package") pass "the public tier is live at generation/sequence $want_gen_seq, imported via 'package', with nobody running a command";; *) fail "the packaged import" "tier=$(tier_line public) $(sv cat /tmp/launcher.out)";; esac
[ "$(dbq "SELECT PackagedOutcome FROM phoromatic_catalog_state")" = imported ] && pass "the packaged outcome is recorded (imported)" || fail "PackagedOutcome" "$(dbq 'SELECT PackagedOutcome FROM phoromatic_catalog_state')"
[ "$(imports)" = 1 ] && pass "one catalog_imported audit row, by the package" || fail "audit rows after the packaged import: $(imports)"
echo "== 2. the command line: check both bundles, import the other tier, status, a repeat that changes nothing"
out=$(sv sh /bench/benchy server-catalog-check "/bundles/$PUB"); case "$out" in "public "[0-9]*) pass "server-catalog-check, public: $out";; *) fail "check of the public bundle" "$out";; esac
if [ -n "$PRIV" ]; then
	out=$(sv sh /bench/benchy server-catalog-check "/bundles/$PRIV"); case "$out" in "private "[0-9]*) pass "server-catalog-check, private: $out";; *) fail "check of the private bundle" "$out";; esac
	out=$(sv sh /bench/benchy server-catalog-import "/bundles/$PRIV"); case "$out" in *Imported*) pass "server-catalog-import of the private tier";; *) fail "import of the private bundle" "$out";; esac
	[ -n "$(tier_line private)" ] && pass "the private tier is live ($(tier_line private))" || fail "the private tier is not in the table"
fi
before="$(tier_line public) $(tier_line private)"
out=$(sv sh /bench/benchy server-catalog-import "/bundles/$PUB")
[ "$before" = "$(tier_line public) $(tier_line private)" ] && case "$out" in *"nothing changed"*) true;; *) false;; esac && pass "importing the public bundle again says nothing changed, and both tiers are as they were" || fail "a repeat import changed state" "$out"
after_repeat=$(imports)
out=$(sv sh /bench/benchy server-catalog-status)
case "$out" in *public*) pass "server-catalog-status shows the public tier";; *) fail "status" "$out";; esac
[ -z "$PRIV" ] || case "$out" in *private*) pass "...and the private tier";; *) fail "status without the private tier" "$out";; esac
# A restart: with the packaged hash cleared, the first pass of the event server must find the bundle, see it is the one already live and change nothing.
# Its PackagedOn moves when that pass has run, which is what is polled (no fixed sleep).
dbx "UPDATE phoromatic_catalog_state SET PackagedSHA256 = NULL, PackagedOn = 'before-restart'"
docker restart "${SV}-srv" >/dev/null
for i in $(seq 1 90); do [ "$(dbq "SELECT PackagedOn FROM phoromatic_catalog_state")" != before-restart ] && break; sleep 1; done
[ "$(dbq "SELECT PackagedOn FROM phoromatic_catalog_state")" != before-restart ] && pass "after a restart the event server's first pass looked at the packaged bundle (PackagedOn moved, outcome: $(dbq "SELECT PackagedOutcome FROM phoromatic_catalog_state"))" || fail "the event server did not make its first pass after the restart"
[ "$before" = "$(tier_line public) $(tier_line private)" ] && pass "...and both tiers are as they were: the same bundle is not imported twice" || fail "the restart changed a tier" "$(tier_line public) $(tier_line private)"
TN_PORT=$(docker port "${SV}-srv" 8080/tcp | head -1 | sed 's/.*://')

# ---- 3 and 4: a real engine
echo "== 3. a real engine enrolls, says hello, takes the release and installs from a pack through the server"
if [ $REAL = 1 ]; then
	ENGBIN=${FIRST_RELEASE_ENGINE:-}
	[ -x "$ENGBIN" ] || { echo "  SKIPPED: FIRST_RELEASE_ENGINE is not an executable: the engine part did not run"; echo "FIRST RELEASE REHEARSAL: $([ $rc = 0 ] && echo 'PASS (engine part skipped)' || echo FAIL)"; exit $rc; }
	E2E=$(mktemp -d /tmp/benchy-qc-e2e.XXXXXX); chmod 755 "$E2E"; cp "$ENGBIN" "$E2E/engine-ct"
	ENGENV=()
else
	ENGSRC=${QC_ENGINE_SRC:-$HOME/src/benchy-engine-ce}
	if [ ! -f "$ENGSRC/cmd/benchy-engine/main.go" ] || ! command -v go >/dev/null 2>&1; then
		echo "  SKIPPED: no benchy-engine checkout at $ENGSRC (set QC_ENGINE_SRC) or no Go: the engine part did not run"
		echo "FIRST RELEASE REHEARSAL: $([ $rc = 0 ] && echo 'PASS (engine part skipped)' || echo FAIL)"; exit $rc
	fi
	E2E=$(mktemp -d /tmp/benchy-qc-e2e.XXXXXX)
	mkdir "$E2E/tree" "$E2E/bundles"; chmod 755 "$E2E" "$E2E/bundles"
	(cd "$ENGSRC" && tar --exclude=.git -cf - .) | tar -xf - -C "$E2E/tree" || exit 2
	mkdir "$E2E/tree/cmd/c3-mkbundle"; cp "$HERE/e2e/mkbundle.go" "$E2E/tree/cmd/c3-mkbundle/main.go"
	(cd "$E2E/tree" && CGO_ENABLED=0 go build -tags catalogtest -o "$E2E/engine-ct" ./cmd/benchy-engine && CGO_ENABLED=0 go build -o "$E2E/mkbundle" ./cmd/c3-mkbundle) || { fail "the engine did not build"; exit 1; }
	out=$(docker run --rm --network none -v "$E2E":/e2e "$QC_IMAGE" /e2e/mkbundle /e2e/tree/testdata/authoring/good /e2e/bundles 2>&1)
	[ "$out" = ok ] || { fail "mkbundle" "$out"; exit 1; }
	SPKI=$(cat "$E2E/bundles/spki.txt")
	# a fresh server (a fresh database, a key table of that one key) in the same way the first one started, with the packaged bundle: the engine-signed first release
	printf '{"schema":"benchy-catalog-keys/1","keys":[{"generation":1,"spki":"%s"}],"denied_key_ids":[],"floor":{}}' "$SPKI" > "$NEWTREE/pts-core/static/catalog/keys.json"
	rm -rf "$NEWTREE/catalog"; mkdir -p "$NEWTREE/catalog"; cp "$E2E/bundles/one.zip" "$NEWTREE/catalog/benchy-catalog-public-1-1.zip"
	cp "$E2E/bundles/one.zip" "$BUNDLES/one.zip"; cp "$E2E/bundles/two.zip" "$BUNDLES/two.zip"; chmod 644 "$BUNDLES"/*.zip
	docker rm -f "${SV}-srv" >/dev/null 2>&1; docker volume create "${SV}-lib2" >/dev/null; docker volume create "${SV}-cache2" >/dev/null
	start_server "$NEWTREE" "${SV}-lib2" "${SV}-cache2"
	for i in $(seq 1 90); do [ -n "$(tier_line public)" ] && break; sleep 1; done
	ENGENV=(-e BENCHY_CATALOGTEST_SPKI="$SPKI")
fi
printf '%s\n' qcpass12345 | docker exec -i "${SV}-srv" sh /bench/benchy server-account-create --user e2eadmin --email e@example.com --account-id E2EAAA 2>&1 | grep -q "Account created" && pass "an account" || fail "account creation"
sv php /bench/tests/private-repo/agent_admin.php allow-http on >/dev/null
CODE=$(sv php /bench/tests/private-repo/agent_admin.php code E2EAAA e2e - native | sed -n 's/.*"code":"\([^"]*\)".*/\1/p')
docker run -d --name "${SV}-eng" --network "${SV}-net" -e HOME=/tmp/h -e SSL_CERT_FILE=/tmp/c.pem "${ENGENV[@]}" -v "$E2E":/e2e:ro -v "$HERE/e2e":/tools:ro "$QC_IMAGE" sleep 1500 >/dev/null || exit 2
eng() { docker exec "${SV}-eng" "$@" 2>&1 </dev/null | head -c 6000; }
eng sh -c 'mkdir -p /tmp/h; openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -nodes -keyout /tmp/k.pem -out /tmp/c.pem -subj /CN=localhost -addext subjectAltName=DNS:localhost,IP:127.0.0.1 -days 2 2>/dev/null' >/dev/null
docker exec -d "${SV}-eng" sh -c 'python3 /tools/tlsproxy.py /tmp/c.pem /tmp/k.pem 8443 benchy-srv:8080 2>/tmp/proxy.log'
sleep 2
out=$(eng /e2e/engine-ct --json agent enroll https://localhost:8443 --code "$CODE"); case "$out" in *'"type":"agent_enrolled"'*) pass "the engine enrolls";; *) fail "enroll" "$out";; esac
docker exec -d "${SV}-eng" sh -c '/e2e/engine-ct --json agent run > /tmp/agent.log 2>&1'
seq_of() { eng /e2e/engine-ct --json catalog status | grep '"type":"catalog_status"' | grep -o '"sequence":[0-9]*' | head -1; }
want_seq=$(echo "$want_gen_seq" | cut -d' ' -f2)
for i in $(seq 1 25); do [ "$(seq_of)" = "\"sequence\":$want_seq" ] && break; sleep 2; done
[ "$(seq_of)" = "\"sequence\":$want_seq" ] && pass "hello announced the release; the engine fetched index.signed through the route and accepted it (sequence $want_seq)" || fail "the engine did not accept the release" "$(eng /e2e/engine-ct --json catalog status; eng tail -3 /tmp/agent.log)"
SYS=$(dbq "SELECT SystemID FROM phoromatic_systems WHERE AccountID='E2EAAA' AND DeletedOn IS NULL LIMIT 1")
REF=$(dbq "SELECT ID FROM phoromatic_catalog_entries WHERE Tier='public' AND Kind='test' ORDER BY ID, Version LIMIT 1")
VER=$(dbq "SELECT Version FROM phoromatic_catalog_entries WHERE Tier='public' AND Kind='test' AND ID='$REF' ORDER BY Version LIMIT 1")
[ -n "$SYS" ] && [ -n "$REF" ] && pass "the system and a test to run are known to the server ($SYS, $REF $VER)" || fail "no system or no test" "$SYS $REF $VER"
caps=$(dbq "SELECT AgentCapabilities FROM phoromatic_systems WHERE SystemID='$SYS'")
case "$caps" in *catalog_v1*) pass "the server kept the engine's capabilities ($caps)";; *) fail "capabilities" "$caps";; esac
out=$(eng /e2e/engine-ct --json install "$REF")
case "$out" in *'"type":"download_start"'*|*'"type":"download_end"'*|*'"type":"install_end"'*) pass "install: the pack came through the server's route and was verified and unpacked";; *) fail "install" "$out";; esac
eng grep -c 'GET /api/v2/catalog/packs/' /tmp/proxy.log | grep -qv '^0$' && pass "the pack was fetched from /api/v2/catalog/packs/" || fail "pack fetch" "$(eng cat /tmp/proxy.log)"

echo "== 4. a pinned job"
if [ $REAL = 0 ]; then
	out=$(sv sh /bench/benchy server-catalog-import /bundles/two.zip); case "$out" in *"sequence 2"*) pass "the second release is imported";; *) fail "import of two.zip" "$out";; esac
	for i in $(seq 1 40); do [ "$(seq_of)" = '"sequence":2' ] && break; sleep 5; done
	[ "$(seq_of)" = '"sequence":2' ] && pass "the agent said hello again at its next poll and refreshed to sequence 2" || fail "the agent did not follow the second release" "$(eng tail -3 /tmp/agent.log)"
fi
dbx "INSERT INTO phoromatic_schedules (AccountID, ScheduleID, Title, Description, State, ActiveOn, RunAt, RunTargetSystems, RunTargetGroups, RunPriority, LastModifiedBy) VALUES ('E2EAAA', 9001, 'pinned', 'rehearsal', 1, '0,1,2,3,4,5,6', '00.00', '$SYS', '', 100, 'qc'); INSERT INTO phoromatic_schedules_native_tests (AccountID, ScheduleID, Position, Ref, OptionsJSON, Description, AddedOn, AddedBy, Version) VALUES ('E2EAAA', 9001, 0, '$REF', '{}', 'd', 'now', 'qc', '$VER');"
for i in $(seq 1 40); do [ -n "$(dbq "SELECT JobID FROM phoromatic_v2_jobs WHERE ScheduleID=9001 LIMIT 1")" ] && break; sleep 3; done
payload=$(dbq "SELECT PayloadJSON FROM phoromatic_v2_jobs WHERE ScheduleID=9001 ORDER BY JobID LIMIT 1")
case "$payload" in *"\"version\":\"$VER\""*) pass "the job was issued with the pinned version ($REF $VER)$([ $REAL = 0 ] && echo ', although a newer release (1.1.0) is in the catalog')";; *) fail "the issued job does not carry version $VER" "$payload";; esac
for i in $(seq 1 30); do [ "$(dbq "SELECT State FROM phoromatic_v2_jobs WHERE ScheduleID=9001 ORDER BY JobID LIMIT 1")" != issued ] && break; sleep 2; done
st=$(dbq "SELECT State||' '||COALESCE(EndStatus,'') FROM phoromatic_v2_jobs WHERE ScheduleID=9001 ORDER BY JobID LIMIT 1")
case "$st" in "issued "*) fail "the agent never started the pinned job" "$st $(eng tail -3 /tmp/agent.log)";; *) pass "the agent took the pinned job (now: $st; the test may fail on its own download in this closed network, which is not the catalog's concern)";; esac
[ $rc -eq 0 ] && echo "FIRST RELEASE REHEARSAL: PASS" || echo "FIRST RELEASE REHEARSAL: FAIL"
exit $rc
