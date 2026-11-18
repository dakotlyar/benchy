#!/bin/bash
# Private-repo QC harness. Needs docker + python3 on the host; touches nothing outside throw-away containers/volumes.
#   tests/private-repo/run.sh            run everything
#   tests/private-repo/run.sh http       only the HTTP-level endpoint/security tests (QC_HTTP_PATTERN=<glob> narrows the files, e.g. test_server_settings_page.py)
#   tests/private-repo/run.sh v2         only the protocol-v2 conformance set in tests/protocol-v2/ (README, schema and server agree; also part of http/all)
#   tests/private-repo/run.sh rollback   only the rollback check: upgrade a database of the previous release to schema 59, use it, run the old code on it (OLD=<ref>, default v0.9.10), upgrade again
#   tests/private-repo/run.sh rehearsal  the seinar-copy rehearsal: upgrade a copy of the real database (REHEARSAL_COPY=<dir>), load the pages, roll back to the old release, again
#   tests/private-repo/run.sh upload     only the web upload (stage/review/confirm) tests (also part of http/all)
#   tests/private-repo/run.sh web        only the web page tests (also part of http/all)
#   tests/private-repo/run.sh e2e        only the CLI end-to-end scenarios
#   tests/private-repo/run.sh nophone    only the no-phone-home scenarios (sealed network, openbenchmarking.org sink)
#   tests/private-repo/run.sh benches    only the test bench tests (test_benches_*.py; also part of http/all)
#   tests/private-repo/run.sh json       only the JSON mode (benchy --json) checks in tests/json-events/
#   tests/private-repo/run.sh lu3        only the LU-S3 checks (0.10.0): phoromatic.upload-result succeeds only with an upload id, goes only to the paired server (a decoy in the client's list is read from a request log), fails fast when the server is down, sends no system logs unless --system-logs, and the server answers the same ad-hoc upload with the first upload id (stand-in server containers plus the shared server; also part of all)
#   tests/private-repo/run.sh install    only the install-sh and install_macos checks (the installed launcher runs from an unrelated directory; no server or network needed; also part of all)
#   tests/private-repo/run.sh catalog    only the signed-catalog verifier checks in tests/catalog/ (E4.4 C1: the PHP verifier against the engine's committed fixtures and the catalog spec 13 rules; C2: the server's import through its command line, in a copy of the tree whose key table holds throw-away keys; C3: serving, hello and poll, the auth matrix, and an end to end with a real catalogtest engine built from QC_ENGINE_SRC (default ~/src/benchy-engine-ce; skipped, said so, when absent) against a scratch server of its own; no real key; also part of all)
#   tests/private-repo/run.sh catalog-sched only the E4.4 C4 schedule tests of tests/catalog/: version pins and the picker, the issue rules, tests that need administrator rights (rules 0, A to D, deferred_kind), and the level-1 rules (S3), against a scratch server of its own with throw-away keys (also part of catalog)
#   tests/private-repo/run.sh catalog-release the E4.4 C6 rehearsal of the first signed catalog release on a scratch docker server (tests/catalog/first_release.sh): the packaged import at the first start, check and import of both tiers, a real engine (catalogtest build, throw-away keys) enrolling, taking the release, installing from a pack and running a pinned job. With FIRST_RELEASE_DIR=<folder of the real bundles> and FIRST_RELEASE_ENGINE=<a released engine binary> it reruns against the real first release and the production key table (also part of all)
#   tests/private-repo/run.sh installer only the Windows installer checks that Linux can do (pins, release.sh's verification of the pinned assets, benchy.nsi / install.ps1 structure; no server, no network; also part of all)
#   tests/private-repo/run.sh migration  only the schema 59 migration checks of the tenancy set (fixtures built by the code of MIG_OLD, default v0.9.10; also part of tenancy)
#   tests/private-repo/run.sh nombstring only the no-mbstring checks of the tenancy set (the server console and the activity page on a PHP without the mbstring extension; also part of tenancy)
#   tests/private-repo/run.sh mirror     only the OpenBenchmarking mirror checks of the tenancy set (official profiles from the package copy, refused when the service user could write it; also part of tenancy)
#   tests/private-repo/run.sh rollback-t3 only the private repository rollback check of the tenancy set (T3: OLD=<ref>, default 0.9.13, then this checkout, the old release again, this checkout again; also part of tenancy)
#   tests/private-repo/run.sh rollback-t4 only the T4 rollback check of the tenancy set (accounts made in the server console in each state, the release before T4, this checkout again; also part of tenancy)
#   tests/private-repo/run.sh rollback-t5 only the T5 rollback check of the tenancy set (a user who belongs to two accounts, a level changed, a user turned off, the release before T5, this checkout again; also part of tenancy)
#   tests/private-repo/run.sh rollback-t6 only the T6 rollback check of the tenancy set (passwords made by the release before T6, this checkout, the old release changing a user's password and root's, this checkout again; also part of tenancy)
#   tests/private-repo/run.sh rollback-e44 only the E4.4 C2 rollback check of the tenancy set (both catalog tiers imported with throw-away test keys, the release before the catalog, this checkout again; OLD=<ref>, default v0.9.14 and then v0.9.13; also part of tenancy)
#   tests/private-repo/run.sh d0         only the server discovery D0 checks (0.9.15): no relay publish from an old config or under NoPhoneHome, the relay check ends under NoPhoneHome, discovered servers give no test profiles (own containers, sealed network; also part of all)
#   tests/private-repo/run.sh d1         only the server discovery D1 checks (0.10.0): the _benchy._tcp announcement (avahi-daemon in a container of its own), the network name, server-announce and its audit, GET /api/v2/server and its rate limit, the Server settings section (own containers; also part of all)
#   tests/private-repo/run.sh d2         only the server discovery D2 checks (0.10.0): join codes, the authentication path: the code and its check characters, the anchor per TLS mode, the Kind column (schema 64) over a database at 63, the v2 enroll endpoint with join_code over the built-in HTTPS, the command line (enroll-code-create), the per-address lockout and the cap across addresses with its pause, the Systems page box, no code left in a log (own containers; also part of all)
#   tests/private-repo/run.sh https      only the built-in HTTPS checks (Caddy in front of PHP; own server containers, a pebble ACME test CA; also part of all)
#   tests/private-repo/run.sh https-names only the one-certificate-for-every-name checks of the internal TLS mode (0.10): the NAT case reproduced on the release before the fix (OLD=<ref>, default fffbba63b = 0.9.15), the upgrade, a client paired before it, a name change, renewal, the rollback and forward again, and the unit checks (own server container and network; also part of all)
#   tests/private-repo/run.sh tenancy    the tenancy checks in tests/tenancy/ (0.9.10): SQL scope, path and route lints, then a scratch server with two accounts that tries every cross-account read and write; own server container, no shared server (also part of all)
#   tests/private-repo/run.sh server-deb the benchy-server package and benchy-server-setup (0.9.8): builds both .debs with the real builder, then ubuntu:26.04 containers with systemd as PID 1 and no network (podman; own images; also part of all, and last). QC_OLD_DEB (default ~/benchy-release/0.9.7/benchy_0.9.7_all.deb) is the 0.9.7 package for the upgrade cases
#   tests/private-repo/run.sh http,nophone,json   several selectors, one after another on the same server (phase order is fixed: http, e2e, nophone, json, install, https, server-deb)
# The repo is exported with `git archive HEAD` (committed state, correct file modes) and mounted read-only.
set -u
HERE=$(cd "$(dirname "$0")" && pwd)
REPO=$(cd "$HERE/../.." && pwd)
WHICH=${1:-all}
# A comma list runs several selectors in the order given on the one server (e.g. "http,json"): the way to bisect a leak between phases
want() { case ",$WHICH," in *",$1,"*) return 0;; esac; return 1; }
RUN=qcpr$$
export QC_IMAGE=${QC_IMAGE:-benchy-qc-php}
export QC_SRV=${RUN}-srv QC_NET=${RUN}-net QC_CLIENT_PREFIX=${RUN}-c
export QC_TREE=$(mktemp -d /tmp/benchy-qc-tree.XXXXXX)
export QC_SHARE=$(mktemp -d /tmp/benchy-qc-share.XXXXXX)
export QC_USER='qcuser1' QC_PASS='qcpass123'

cleanup() {
	docker rm -f "$QC_SRV" >/dev/null 2>&1
	docker ps -aq --filter "name=${RUN}-" | xargs -r docker rm -f >/dev/null 2>&1
	docker volume ls -q --filter "name=${RUN}-" | xargs -r docker volume rm -f >/dev/null 2>&1
	docker network rm "$QC_NET" >/dev/null 2>&1
	rm -rf "$QC_TREE" "$QC_SHARE"
}
trap cleanup EXIT

if [ "$WHICH" = installer ]; then
	# No server and no containers of its own: static checks of the installer files
	(cd "$REPO" && git archive HEAD | tar -x -C "$QC_TREE") || exit 2
	bash "$HERE/installer.sh"; exit $?
fi
if [ "$WHICH" = server-deb ]; then
	# Podman containers of its own (systemd as PID 1); the shared server and the docker images are not needed
	(cd "$REPO" && git archive HEAD | tar -x -C "$QC_TREE") || exit 2
	RUN=$RUN bash "$HERE/server_deb.sh"; exit $?
fi
docker image inspect "$QC_IMAGE" >/dev/null 2>&1 || docker build -q -t "$QC_IMAGE" "$HERE" >/dev/null || { echo "image build failed"; exit 2; }
# The HTTPS selector's image: the private-repo image plus sockets and the pinned Caddy tarball (checked against runtime-pins.json at build time)
if [ "$WHICH" = all ] || want https || want d0 || want d1 || want d2 || want https-names; then
	read -r CADDY_URL CADDY_SHA256 < <(python3 -c "
import json; p = json.load(open('$REPO/pts-core/static/update/runtime-pins.json'))['linux-x64']['caddy']
print(p['upstream_urls'][0], p['artifact']['sha256'])")
	export HIMG=benchy-qc-https:$(cat "$HERE/Dockerfile.https" <(echo "$CADDY_SHA256") | sha256sum | cut -c1-12)
	docker image inspect "$HIMG" >/dev/null 2>&1 || docker build -q -t "$HIMG" -f "$HERE/Dockerfile.https" --build-arg BASE="$QC_IMAGE" --build-arg CADDY_URL="$CADDY_URL" --build-arg CADDY_SHA256="$CADDY_SHA256" "$HERE" >/dev/null || { echo "https image build failed"; exit 2; }
fi
(cd "$REPO" && git archive HEAD | tar -x -C "$QC_TREE") || exit 2
if [ "$WHICH" = install ]; then
	# install-sh checks use throw-away containers only (no shared server, no network)
	bash "$HERE/install.sh"; exit $?
fi
if [ "$WHICH" = catalog-sched ]; then
	# Only the E4.4 C4 schedule tests (its own scratch server and throw-away keys)
	bash "$HERE/../catalog/sched.sh"; exit $?
fi
if [ "$WHICH" = catalog-release ]; then
	# The first-release rehearsal: a scratch server of its own (throw-away keys, or the real release with FIRST_RELEASE_DIR)
	bash "$HERE/../catalog/first_release.sh"; exit $?
fi
if [ "$WHICH" = catalog ]; then
	# The verifier tests need no server: one throw-away container with the committed tree
	bash "$HERE/../catalog/catalog.sh"; exit $?
fi
if [ "$WHICH" = d2 ]; then
	# Server discovery D2 (0.10.0): join codes, in a server container of its own with the built-in HTTPS and two accounts; no shared server
	bash "$HERE/d2.sh"; exit $?
fi
if [ "$WHICH" = d1 ]; then
	# Server discovery D1 (0.10.0): avahi in a server container of its own, a second host to browse from; no shared server
	bash "$HERE/d1.sh"; exit $?
fi
if [ "$WHICH" = d0 ]; then
	# Server discovery D0 (0.9.15): the relay publish and discovered servers, in containers of its own on a sealed network; no shared server
	bash "$HERE/d0.sh"; exit $?
fi
if [ "$WHICH" = https-names ]; then
	# One certificate for every name (0.10): a server container of its own on a network of its own, two release trees swapped under one data volume
	bash "$HERE/https_names.sh"; exit $?
fi
docker network create "$QC_NET" >/dev/null || exit 2
if [ "$WHICH" = rehearsal ]; then
	# The seinar-copy rehearsal (a copy of the real database, in containers of its own)
	RUN=$RUN HERE=$HERE bash "$HERE/rehearsal.sh"; exit $?
fi
if [ "$WHICH" = rollback ]; then
	# The rollback check starts its own server containers (0.9.3 and this checkout, one after the other) on a data volume of its own
	RUN=$RUN HERE=$HERE bash "$HERE/rollback.sh"; exit $?
fi
if [ "$WHICH" = tenancy ]; then
	# Its own server container with two accounts; the shared server (and its single account) is not needed
	RUN=$RUN bash "$HERE/../tenancy/tenancy.sh"; exit $?
fi
if [ "$WHICH" = nombstring ]; then
	# Only the no-mbstring checks of the tenancy set (the server console and the activity page on a PHP without the mbstring extension)
	RUN=$RUN bash "$HERE/../tenancy/nombstring.sh"; exit $?
fi
if [ "$WHICH" = mirror ]; then
	# Only the mirror checks of the tenancy set (package copy of the official profiles, as a non-root user)
	RUN=$RUN bash "$HERE/../tenancy/mirror.sh"; exit $?
fi
if [ "$WHICH" = rollback-t3 ]; then
	# Only the T3 rollback check (the release before T3 on an account's private repository, this checkout, the old release again; OLD=<ref>, default 0.9.13)
	RUN=$RUN bash "$HERE/../tenancy/rollback_t3.sh"; exit $?
fi
if [ "$WHICH" = rollback-t5 ]; then
	# Only the T5 rollback check (memberships and levels, the release before T5 on that data, this checkout again; OLD=<ref>, default v0.9.13)
	RUN=$RUN bash "$HERE/../tenancy/rollback_t5.sh"; exit $?
fi
if [ "$WHICH" = rollback-t6 ]; then
	# Only the T6 rollback check (password hashes: the release before T6, this checkout, the old release changing two passwords, this checkout again; OLD=<ref>, default v0.9.13)
	RUN=$RUN bash "$HERE/../tenancy/rollback_t6.sh"; exit $?
fi
if [ "$WHICH" = rollback-e44 ]; then
	# Only the E4.4 C2 rollback check (the signed catalog imported, the release before it on that data, this checkout again; OLD=<ref>, default both v0.9.14 and v0.9.13)
	RUN=$RUN bash "$HERE/../tenancy/rollback_e44.sh"; exit $?
fi
if [ "$WHICH" = rollback-t4 ]; then
	# Only the T4 rollback check (accounts made in the server console, in each state, served by the release before T4 and then by this checkout again; OLD=<ref>, default 0.9.13)
	RUN=$RUN bash "$HERE/../tenancy/rollback_t4.sh"; exit $?
fi
if [ "$WHICH" = migration ]; then
	# Only the schema 59 migration checks of the tenancy set (they are also its last part)
	RUN=$RUN bash "$HERE/../tenancy/migration.sh"; exit $?
fi
if [ "$WHICH" = https ]; then
	# The HTTPS checks start their own servers; the shared one (and its account) is not needed
	bash "$HERE/https.sh"; exit $?
fi
docker volume create "${RUN}-lib" >/dev/null; docker volume create "${RUN}-cache" >/dev/null
# Runs as root like the production service, so the server uses /var/lib/benchy + /var/cache/benchy (the "daemonized" layout).
docker run -d --name "$QC_SRV" --network "$QC_NET" -p 127.0.0.1::8080 -p 127.0.0.1::8081 -e PHP_CLI_SERVER_WORKERS=8 \
	-v "${RUN}-lib":/var/lib/benchy -v "${RUN}-cache":/var/cache/benchy -v "$QC_TREE":/bench:ro -w /bench/pts-core/phoromatic/public_html \
	"$QC_IMAGE" php -d post_max_size=16M -d upload_max_filesize=16M -S 0.0.0.0:8080 -t /bench/pts-core/phoromatic/public_html >/dev/null || exit 2
export QC_PORT=$(docker port "$QC_SRV" 8080/tcp | head -1 | sed 's/.*://')
# A twin of the server with display_errors off, like a production php.ini: PHP prints its "POST Content-Length exceeds the limit"
# warning before any script runs, and with display_errors on that output stops sessions from starting, so the oversize-upload
# message of the web upload page can only be seen on a server configured like this one.
docker exec -d -w /bench/pts-core/phoromatic/public_html -e PHP_CLI_SERVER_WORKERS=4 "$QC_SRV" php -d display_errors=0 -d post_max_size=16M -d upload_max_filesize=16M -S 0.0.0.0:8081 -t /bench/pts-core/phoromatic/public_html
export QC_PORT2=$(docker port "$QC_SRV" 8081/tcp | head -1 | sed 's/.*://')
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$QC_PORT2/" && break; sleep 1; done
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$QC_PORT/" && break; sleep 1; done

# There is no registration form (0.9.12): the account is made the way an administrator makes one, on the server (the same checks as the form had).
printf '%s\n' "$QC_PASS" | docker exec -i "$QC_SRV" sh /bench/benchy server-account-create --user "$QC_USER" --email qc@example.com 2>&1 | grep -q "Account created" || { echo "account creation failed"; exit 2; }
export QC_AID=$(docker exec "$QC_SRV" php -r '$d=new SQLite3("/var/lib/benchy/phoromatic/phoromatic.db"); echo $d->querySingle("SELECT AccountID FROM phoromatic_accounts ORDER BY CreatedOn ASC LIMIT 1");')
[ -n "$QC_AID" ] || { echo "no account id"; exit 2; }

# The upload/delete/rebuild endpoints need a repository token. Create one the way an administrator would, with the server-side command.
export QC_REPO_TOKEN=$(docker exec "$QC_SRV" sh /bench/benchy private-repo-token-create qc-harness --tenant "$QC_AID" 2>&1 | grep -o 'bpr_[A-Za-z0-9_-]\{43\}' | head -1)
[ -n "$QC_REPO_TOKEN" ] || { echo "token creation failed"; exit 2; }

rc=0
if [ "$WHICH" = all ] || want http; then
	(cd "$HERE" && python3 -m unittest discover -s "$HERE" -p "${QC_HTTP_PATTERN:-test_*.py}" -v) || rc=1
fi
if [ "$WHICH" = all ] || want http || want v2; then
	# The protocol-v2 conformance set (README / schema / server agree); it reuses the helpers in this directory
	(cd "$HERE/../protocol-v2" && python3 -m unittest discover -s "$HERE/../protocol-v2" -p "test_*.py" -v) || rc=1
fi
if want upload; then
	(cd "$HERE" && python3 -m unittest discover -s "$HERE" -p "test_private_repo_upload_web.py" -v) || rc=1
fi
if want benches; then
	(cd "$HERE" && python3 -m unittest discover -s "$HERE" -p "${QC_PATTERN:-test_benches_*.py}" -v) || rc=1
fi
if want web; then
	(cd "$HERE" && python3 -m unittest discover -s "$HERE" -p "test_private_repo_web.py" -v) || rc=1
fi
if [ "$WHICH" = all ] || want e2e; then
	bash "$HERE/e2e.sh" || rc=1
fi
{ [ "$WHICH" = all ] || want nophone; } && { bash "$HERE/nophone.sh" || rc=1; }
{ [ "$WHICH" = all ] || want json; } && { bash "$HERE/../json-events/json.sh" || rc=1; }
{ [ "$WHICH" = all ] || want lu3; } && { bash "$HERE/lu3.sh" || rc=1; }
{ [ "$WHICH" = all ] || want install; } && { bash "$HERE/install.sh" || rc=1; }
{ [ "$WHICH" = all ] || want installer; } && { bash "$HERE/installer.sh" || rc=1; }
{ [ "$WHICH" = all ] || want catalog; } && { bash "$HERE/../catalog/catalog.sh" || rc=1; }
{ [ "$WHICH" = all ] || want catalog-release; } && { bash "$HERE/../catalog/first_release.sh" || rc=1; }
# After the shared-server phases: it starts server containers of its own
{ [ "$WHICH" = all ] || want https; } && { bash "$HERE/https.sh" || rc=1; }
{ [ "$WHICH" = all ] || want https-names; } && { bash "$HERE/https_names.sh" || rc=1; }
{ [ "$WHICH" = all ] || want d0; } && { bash "$HERE/d0.sh" || rc=1; }
{ [ "$WHICH" = all ] || want d1; } && { bash "$HERE/d1.sh" || rc=1; }
{ [ "$WHICH" = all ] || want d2; } && { bash "$HERE/d2.sh" || rc=1; }
# Its own server container too, with two accounts
{ [ "$WHICH" = all ] || want tenancy; } && { RUN=$RUN bash "$HERE/../tenancy/tenancy.sh" || rc=1; }
# Last: podman containers with systemd as PID 1, nothing shared with the phases above
{ [ "$WHICH" = all ] || want server-deb; } && { RUN=$RUN bash "$HERE/server_deb.sh" || rc=1; }
exit $rc
