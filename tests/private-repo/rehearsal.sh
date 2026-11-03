#!/bin/bash
# Seinar-copy rehearsal for the 0.9.4 upgrade: take a copy of the real server's database, upgrade it with this checkout, load the pages,
# roll back to 0.9.3 and load them again, then upgrade once more.
#   tests/private-repo/run.sh rehearsal            (the copy is ~/benchy-release/0.9.4/seinar-db-copy/)
#   REHEARSAL_COPY=/other/dir tests/private-repo/run.sh rehearsal
# The copy itself is never opened by a server: it is read with cp into a working directory in /tmp, the working directory is copied into
# a throw-away Docker volume, and a rehearsal administrator is added to that volume's database (so the pages can be loaded). The copy's
# checksums are taken before and after and must match. Everything (volume, containers, working directory) is removed at the end.
# A copy that is a single dated .db file (no result files) leaves the result pages with nothing to read; the list pages and everything else still load.
# Needs the variables run.sh sets (RUN, QC_NET, QC_IMAGE, QC_TREE) and HERE. OLD is the git ref of the release to roll back to.
set -u
: "${QC_NET:?run via run.sh rehearsal}"
HERE=$(cd "$(dirname "$0")" && pwd)
REPO=$(cd "$HERE/../.." && pwd)
OLD=${OLD:-v0.9.3}
SRC=${REHEARSAL_COPY:-$HOME/benchy-release/0.9.4/seinar-db-copy}
DB=$(find "$SRC" -maxdepth 3 \( -name 'phoromatic*.db' \) -type f 2>/dev/null | sort | head -1)
[ -n "$DB" ] || { echo "WAITING: no phoromatic.db under $SRC yet"; exit 3; }
RH=${RUN}-rh
WORK=$(mktemp -d /tmp/benchy-qc-rehearsal.XXXXXX)
OLDTREE=$(mktemp -d /tmp/benchy-qc-oldtree.XXXXXX)
export RH_STATE=$WORK/state.json
cleanup() {
	docker rm -f "${RH}-srv" >/dev/null 2>&1
	docker volume rm -f "${RH}-lib" "${RH}-cache" >/dev/null 2>&1
	rm -rf "$WORK" "$OLDTREE"
}
trap cleanup EXIT
sums() { (cd "$SRC" && find . -type f -print0 | sort -z | xargs -0 sha256sum); }
BEFORE=$(sums)
(cd "$REPO" && git archive "$OLD" | tar -x -C "$OLDTREE") || { echo "cannot export $OLD"; exit 2; }

# the working copy of the copy: a file named phoromatic.db is taken with the rest of its directory (accounts, settings); a dated copy such as
# phoromatic-20261103.db is taken alone, as phoromatic.db
mkdir "$WORK/data"
if [ "$(basename "$DB")" = phoromatic.db ]; then cp -a "$(dirname "$DB")/." "$WORK/data/" || exit 2; else cp -a "$DB" "$WORK/data/phoromatic.db" || exit 2; fi
docker volume create "${RH}-lib" >/dev/null; docker volume create "${RH}-cache" >/dev/null
docker run --rm -v "${RH}-lib":/var/lib/benchy -v "$WORK/data":/in:ro "$QC_IMAGE" sh -c 'mkdir -p /var/lib/benchy/phoromatic && cp -a /in/. /var/lib/benchy/phoromatic/' || exit 2
echo "copy: $DB ($(du -sh "$DB" | cut -f1)); working copy loaded into volume ${RH}-lib"
# add the rehearsal administrator to the first account of the working database (sha256 of the account salt and the password, as the log-in checks)
docker run --rm -v "${RH}-lib":/var/lib/benchy "$QC_IMAGE" php -r '
$d = new SQLite3("/var/lib/benchy/phoromatic/phoromatic.db"); $d->busyTimeout(5000);
echo "user_version before: ", $d->querySingle("PRAGMA user_version"), "\n";
$a = $d->querySingle("SELECT AccountID FROM phoromatic_accounts ORDER BY CreatedOn ASC LIMIT 1");
$salt = $d->querySingle("SELECT Salt FROM phoromatic_accounts WHERE AccountID = \"$a\"");
$cols = array(); $r = $d->query("PRAGMA table_info(phoromatic_users)"); while($x = $r->fetchArray(SQLITE3_ASSOC)) $cols[] = $x["name"];
$vals = array("UserID" => "rehearsal-user", "AccountID" => $a, "UserName" => "rehearsal", "Email" => "r@example.com", "Password" => hash("sha256", $salt . "rehearsal-pass"), "CreatedOn" => "2026-11-03 00:00:00", "LastIP" => "127.0.0.1", "AdminLevel" => 1);
$use = array_intersect_key($vals, array_flip($cols));
$d->exec("DELETE FROM phoromatic_users WHERE UserName = \"rehearsal\"");
$d->exec("INSERT INTO phoromatic_users (" . implode(",", array_keys($use)) . ") VALUES (" . implode(",", array_map(function($v) { return "\"" . SQLite3::escapeString($v) . "\""; }, $use)) . ")");
echo "account $a\n"; file_put_contents("/var/lib/benchy/aid", $a);' || exit 2
export QC_AID=$(docker run --rm -v "${RH}-lib":/var/lib/benchy "$QC_IMAGE" cat /var/lib/benchy/aid)
export QC_USER=rehearsal QC_PASS=rehearsal-pass QC_REPO_TOKEN=x
rc=0

serve() {  # serve <tree> <label>
	docker rm -f "${RH}-srv" >/dev/null 2>&1
	docker run -d --name "${RH}-srv" --network "$QC_NET" -p 127.0.0.1::8080 -e PHP_CLI_SERVER_WORKERS=4 \
		-v "${RH}-lib":/var/lib/benchy -v "${RH}-cache":/var/cache/benchy -v "$1":/bench:ro -w /bench/pts-core/phoromatic/public_html \
		"$QC_IMAGE" php -d post_max_size=16M -d upload_max_filesize=16M -S 0.0.0.0:8080 -t /bench/pts-core/phoromatic/public_html >/dev/null || exit 2
	export QC_SRV=${RH}-srv QC_PORT=$(docker port "${RH}-srv" 8080/tcp | head -1 | sed 's/.*://')
	for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$QC_PORT/" && break; sleep 1; done
	echo "== $2"
}
phase() {
	local out; out=$(cd "$HERE" && python3 -W ignore rehearsal_check.py "$@" 2>&1 </dev/null | head -c 30000)
	printf '%s\n' "$out"
	grep -q '^FAILURES 0$' <<<"$out" || rc=1
}

serve "$OLDTREE" "baseline: the copy as 0.9.3 serves it"
phase version 57; phase counts before; phase pages "0.9.3 baseline"
serve "$QC_TREE" "upgrade: this checkout on the copy"
phase pages "0.9.4 after the first request"; phase version 58; phase same before
serve "$OLDTREE" "rollback: 0.9.3 on the upgraded database"
phase version 58; phase pages "0.9.3 after rollback"; phase same before
serve "$QC_TREE" "upgrade again"
phase pages "0.9.4 again"; phase version 58; phase same before

AFTER=$(sums)
if [ "$BEFORE" = "$AFTER" ]; then echo "  PASS  the copy in $SRC is byte for byte as it was"; else echo "  FAIL  the copy was changed"; rc=1; fi
[ $rc -eq 0 ] && echo "REHEARSAL: PASS" || echo "REHEARSAL: FAIL"
exit $rc
