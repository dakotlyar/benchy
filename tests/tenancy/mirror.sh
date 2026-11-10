#!/bin/bash
# The OpenBenchmarking mirror's official profiles come from the installed package's own copy and from nowhere else (tenancy T1). Driven by tenancy.sh
# (needs RUN, QC_NET, QC_IMAGE and QC_TREE) or by run.sh mirror. The server runs as www-data (uid 33), as the package's service user does, on a copy of
# the tree that belongs to root, so "the service user cannot write it" is real here. Nothing runs on the host.
set -u
: "${QC_NET:?run via tenancy.sh or run.sh mirror}"
MR=${RUN}-mr
PROFILE=sysbench-1.1.0
OTHER=stream-1.3.4
rc=0
trap 'docker rm -f "${MR}-srv" >/dev/null 2>&1; for v in $(docker volume ls -q --filter "name=${MR}-"); do docker volume rm -f $v >/dev/null 2>&1; done' EXIT
fail() { echo "  FAIL  $1"; rc=1; }
pass() { echo "  PASS  $1"; }
check() { local what=$1; shift; if "$@" >/dev/null 2>&1; then pass "$what"; else fail "$what"; fi; }

docker volume create "${MR}-tree" >/dev/null; docker volume create "${MR}-data" >/dev/null
# a copy of the tree owned by root (cp without -p), and a storage folder for the service user
docker run --rm -v "${MR}-tree":/t -v "$QC_TREE":/s:ro "$QC_IMAGE" sh -c 'cp -r /s/. /t/ && chown -R 0:0 /t && chmod -R go-w /t' || exit 2
docker run --rm -v "${MR}-data":/d "$QC_IMAGE" chown 33:33 /d
docker run -d --name "${MR}-srv" --network "$QC_NET" --user 33:33 -p 127.0.0.1::8080 -e PTS_USER_PATH_OVERRIDE=/data -v "${MR}-data":/data -v "${MR}-tree":/bench -w /bench/pts-core/phoromatic/public_html \
	"$QC_IMAGE" php -d display_errors=0 -d log_errors=1 -S 0.0.0.0:8080 -t /bench/pts-core/phoromatic/public_html >/dev/null || exit 2
PORT=$(docker port "${MR}-srv" 8080/tcp | head -1 | sed 's/.*://')
for i in $(seq 1 30); do (exec 3<>/dev/tcp/127.0.0.1/$PORT) 2>/dev/null && break; sleep 0.5; done
root() { docker exec -u 0 "${MR}-srv" sh -c "$1"; }
get() { curl -s -m 60 "http://127.0.0.1:$PORT/openbenchmarking-cache.php?test=$2&repo=$1"; }
served() { [ -n "$(get "$1" "$2")" ]; }
refused_logged() { docker logs "${MR}-srv" 2>&1 | grep -c 'package profile was not served'; }
T=/bench/ob-cache/test-profiles/pts

echo "== mirror 1. a tree only root can write is served"
check "the profile is served to a service user that cannot write the tree" served pts $PROFILE
A=$(get pts $PROFILE | md5sum); B=$(get pts $PROFILE | md5sum)
check "and gives the same bytes again" [ "$A" = "$B" ]
check "and the zip holds the package's files" sh -c "get() { curl -s -m 60 'http://127.0.0.1:$PORT/openbenchmarking-cache.php?test=$PROFILE&repo=pts'; }; get | base64 -d | python3 -c 'import sys,zipfile,io; z=zipfile.ZipFile(io.BytesIO(sys.stdin.buffer.read())); sys.exit(0 if \"test-definition.xml\" in z.namelist() else 1)'"
N0=$(refused_logged)

echo "== mirror 2. the service user's own tree is refused"
root "chown -R 33:33 $T/$PROFILE"
check "a profile folder owned by the service user is not served" sh -c "! curl -s -m 60 'http://127.0.0.1:$PORT/openbenchmarking-cache.php?test=$PROFILE&repo=pts' | grep -q ."
check "another profile of the same tree is still served" served pts $OTHER
[ "$(refused_logged)" -gt "$N0" ] && pass "the refusal is in the log" || fail "the refusal is in the log"
root "chown -R 0:0 $T/$PROFILE"
check "and served again once it is root's" served pts $PROFILE

echo "== mirror 3. a folder others can write is refused"
root "chmod o+w $T/$PROFILE"
check "a profile folder writable by others is not served" sh -c "! curl -s -m 60 'http://127.0.0.1:$PORT/openbenchmarking-cache.php?test=$PROFILE&repo=pts' | grep -q ."
root "chmod o-w $T/$PROFILE; chmod o+w $T/$PROFILE/test-definition.xml"
check "a file in it writable by others is not served" sh -c "! curl -s -m 60 'http://127.0.0.1:$PORT/openbenchmarking-cache.php?test=$PROFILE&repo=pts' | grep -q ."
root "chmod o-w $T/$PROFILE/test-definition.xml; chmod o+w $T"
check "the repository folder writable by others refuses all of its profiles" sh -c "! curl -s -m 60 'http://127.0.0.1:$PORT/openbenchmarking-cache.php?test=$OTHER&repo=pts' | grep -q ."
root "chmod o-w $T; chmod g+w /bench/ob-cache/test-profiles; chgrp 33 /bench/ob-cache/test-profiles"
check "the test-profiles folder writable by the service user's group is refused" sh -c "! curl -s -m 60 'http://127.0.0.1:$PORT/openbenchmarking-cache.php?test=$OTHER&repo=pts' | grep -q ."
root "chgrp 0 /bench/ob-cache/test-profiles; chmod g-w /bench/ob-cache/test-profiles"
check "everything is served again after it is put right" served pts $PROFILE

echo "== mirror 4. links are refused"
root "mv $T/$PROFILE/install.sh $T/$PROFILE/install.sh.real; ln -s /etc/passwd $T/$PROFILE/install.sh"
check "a link inside a profile is not served" sh -c "! curl -s -m 60 'http://127.0.0.1:$PORT/openbenchmarking-cache.php?test=$PROFILE&repo=pts' | grep -q ."
root "rm $T/$PROFILE/install.sh; mv $T/$PROFILE/install.sh.real $T/$PROFILE/install.sh"
root "mv $T/$PROFILE $T/$PROFILE.real; ln -s $OTHER $T/$PROFILE"
check "a profile folder that is a link to another is not served" sh -c "! curl -s -m 60 'http://127.0.0.1:$PORT/openbenchmarking-cache.php?test=$PROFILE&repo=pts' | grep -q ."
root "rm $T/$PROFILE; mv $T/$PROFILE.real $T/$PROFILE"
root "mv /bench/ob-cache/test-profiles /bench/ob-cache/test-profiles.real; mkdir /tmp/elsewhere; cp -r /bench/ob-cache/test-profiles.real/pts /tmp/elsewhere/; ln -s /tmp/elsewhere /bench/ob-cache/test-profiles"
check "(control: the folder outside the package is readable by the service user)" docker exec -u 33 "${MR}-srv" test -r /tmp/elsewhere/pts/$OTHER/test-definition.xml
check "a test-profiles folder that leads outside the package is not served" sh -c "! curl -s -m 60 'http://127.0.0.1:$PORT/openbenchmarking-cache.php?test=$OTHER&repo=pts' | grep -q ."
root "rm /bench/ob-cache/test-profiles; mv /bench/ob-cache/test-profiles.real /bench/ob-cache/test-profiles"
check "and everything works again afterwards" served pts $PROFILE

root "mv $T $T.real; ln -s pts.real $T"
check "a repository folder that is a link is not served" sh -c "! curl -s -m 60 'http://127.0.0.1:$PORT/openbenchmarking-cache.php?test=$OTHER&repo=pts' | grep -q ."
root "rm $T; mv $T.real $T"
check "and is served again when it is a plain folder" served pts $OTHER

echo "== mirror 5. the writable copy is never read"
root "mkdir -p /data/test-profiles/pts/$PROFILE && echo TN_TAMPERED > /data/test-profiles/pts/$PROFILE/test-definition.xml && chown -R 33:33 /data/test-profiles"
check "a changed working copy does not change what is served" sh -c "! curl -s -m 60 'http://127.0.0.1:$PORT/openbenchmarking-cache.php?test=$PROFILE&repo=pts' | base64 -d 2>/dev/null | grep -aq TN_TAMPERED"
check "and the zip is still served" served pts $PROFILE
[ $rc -eq 0 ] && echo "MIRROR: PASS" || echo "MIRROR: FAIL"
exit $rc
