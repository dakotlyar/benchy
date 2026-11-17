#!/bin/bash
# The server console, and the pages beside it, work on a PHP without the mbstring extension (0.9.14: creating an account in the server console was
# a fatal "Call to undefined function mb_strlen()" on a host that was set up with install-sh instead of the package, which depends on php-mbstring).
# Driven by tenancy.sh (needs RUN, QC_NET and QC_TREE) or by run.sh nombstring. Own image (Dockerfile.nombstring) and own server container; nothing runs on the host.
#   1. static: no mb_* call outside pts_strings.php that is not behind a function_exists() guard
#   2. a server on a PHP without mbstring: an account made in the console (non-ASCII name), a name taken in another case, a name too long, a deleted
#      benchmark and schedule in the activity page (no warning in the PHP log)
set -u
: "${QC_NET:?run via tenancy.sh or run.sh nombstring}"
HERE=$(cd "$(dirname "$0")" && pwd)
NM=${RUN}-nm
IMG=benchy-qc-nombstring
rc=0
trap 'docker rm -f "${NM}-srv" >/dev/null 2>&1; docker volume rm -f "${NM}-lib" >/dev/null 2>&1' EXIT
fail() { echo "  FAIL  $1"; rc=1; }
pass() { echo "  PASS  $1"; }

# every PHP file of the tree outside tests/: an mb_*() call must have function_exists() on its own line or in the three lines before it
unguarded=$(cd "$QC_TREE" && find . -name '*.php' -not -path './tests/*' -print0 | xargs -0 awk '{ l[FNR]=$0 } /(^|[^A-Za-z_>:])mb_[a-z_]+\(/ { ok=0; for(i=FNR-3;i<=FNR;i++) if(l[i] ~ /function_exists/) ok=1; if(!ok) print FILENAME ":" FNR ": " $0 } FNR==1 { delete l; l[1]=$0 }' | cut -c1-200 | head -5)
if [ -z "$unguarded" ]; then pass "no mb_* call without a function_exists() guard (pts_strings::char_length, lower_case and valid_utf8 are the way)"; else fail "unguarded mb_* calls: $unguarded"; fi

docker build -q -t "$IMG" -f "$HERE/Dockerfile.nombstring" "$HERE" >/dev/null 2>&1 || { fail "the image without mbstring could not be built"; exit 1; }
docker volume create "${NM}-lib" >/dev/null
docker run -d --name "${NM}-srv" --network "$QC_NET" -p 127.0.0.1::8080 -e PHP_CLI_SERVER_WORKERS=4 -v "${NM}-lib":/var/lib/benchy -v "$QC_TREE":/bench:ro -w /bench/pts-core/phoromatic/public_html \
	"$IMG" php -d display_errors=0 -d log_errors=1 -d error_log=/tmp/php-err.log -S 0.0.0.0:8080 -t /bench/pts-core/phoromatic/public_html >/dev/null || exit 2
export NM_SRV=${NM}-srv NM_PORT=$(docker port "${NM}-srv" 8080/tcp | head -1 | sed 's/.*://')
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$NM_PORT/" && break; sleep 1; done
docker exec "$NM_SRV" php -r 'exit(extension_loaded("mbstring") ? 1 : 0);' || { fail "the test PHP has mbstring (the check would prove nothing)"; exit 1; }
printf 'nmpass12345\n' | docker exec -i "$NM_SRV" sh /bench/benchy server-account-create --user nmadmin --email nm@example.com --account-id NMAAAA 2>&1 | grep -q 'Account created' || { fail "the first account could not be made"; exit 1; }
BUNDLE=${NM_BUNDLE:-$HOME/benchy-catalog-release/1/REL/benchy-catalog-public-1-1.zip}
if [ -f "$BUNDLE" ]; then
	docker cp "$BUNDLE" "$NM_SRV":/tmp/public.zip
	cat_out=$(docker exec "$NM_SRV" sh /bench/benchy server-catalog-import /tmp/public.zip 2>&1 | tail -3 | cut -c1-300)
	if echo "$cat_out" | grep -q 'Imported'; then pass "the real public catalog bundle imports without mbstring"; else fail "catalog import without mbstring: $cat_out"; fi
else
	echo "  SKIP  the real public catalog bundle ($BUNDLE) is not here"
fi
out=$(timeout 300 python3 -W ignore "$HERE/nombstring.py" 2>&1 | head -c 6000); st=$?
printf '%s\n' "$out"
echo "$out" | grep -q '^NOMBSTRING: PASS' || rc=1
[ $rc -eq 0 ] && echo "NOMBSTRING: PASS" || echo "NOMBSTRING: FAIL"
exit $rc
