#!/bin/bash
# The OpenBenchmarking mirror's official profiles come from the installed package's own copy and from nowhere else (tenancy T1). Driven by tenancy.sh
# (needs RUN, QC_NET, QC_IMAGE and QC_TREE) or by run.sh mirror. The server runs as www-data (uid 33), as the package's service user does, on a copy of
# the tree that belongs to root, so "the service user cannot write it" is real here; a root service, a service that owns the tree, an ACL grant, a
# writable ancestor and the real install-sh layout have their own cases. Nothing runs on the host.
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

root "chmod -R a-w $T/$PROFILE; chown -R 33:33 $T/$PROFILE"
check "a profile owned by the service user is refused even with every write bit removed (it could chmod it)" sh -c "! curl -s -m 60 'http://127.0.0.1:$PORT/openbenchmarking-cache.php?test=$PROFILE&repo=pts' | grep -q ."
root "chown -R 0:0 $T/$PROFILE; chmod -R u+w $T/$PROFILE; chmod -R go-w $T/$PROFILE"
check "and is served once it is root's again" served pts $PROFILE
N1=$(refused_logged); get pts no-such-profile-9.9.9 >/dev/null; get pts "made-up-$RANDOM" >/dev/null; get system '../x' >/dev/null
[ "$(refused_logged)" = "$N1" ] && pass "made-up names add nothing to the log" || fail "made-up names add nothing to the log"
[ "$(docker logs "${MR}-srv" 2>&1 | grep -c 'is not in the package')" = 0 ] && pass "and no line says they are missing" || fail "and no line says they are missing"

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

root "chmod o+w /bench/ob-cache"
check "a writable ob-cache (an ancestor of the profile) is refused" sh -c "! curl -s -m 60 'http://127.0.0.1:$PORT/openbenchmarking-cache.php?test=$PROFILE&repo=pts' | grep -q ."
root "chmod o-w /bench/ob-cache; chmod o+w /bench"
check "a writable package folder (the root of the tree) is refused" sh -c "! curl -s -m 60 'http://127.0.0.1:$PORT/openbenchmarking-cache.php?test=$PROFILE&repo=pts' | grep -q ."
root "chmod o-w /bench"
check "and served again when they are put right" served pts $PROFILE
if docker run --rm -v "${MR}-tree":/t debian:trixie-slim setfacl -m u:33:rwx /t/ob-cache/test-profiles/pts/$PROFILE 2>/dev/null; then
	check "an ACL that lets the service user write the profile folder is refused" sh -c "! curl -s -m 60 'http://127.0.0.1:$PORT/openbenchmarking-cache.php?test=$PROFILE&repo=pts' | grep -q ."
	docker run --rm -v "${MR}-tree":/t debian:trixie-slim setfacl -b /t/ob-cache/test-profiles/pts/$PROFILE
	check "and served again with the ACL removed" served pts $PROFILE
	docker run --rm -v "${MR}-tree":/t debian:trixie-slim setfacl -m u:33:rwx /t/ob-cache
	check "an ACL on an ancestor is refused too" sh -c "! curl -s -m 60 'http://127.0.0.1:$PORT/openbenchmarking-cache.php?test=$PROFILE&repo=pts' | grep -q ."
	docker run --rm -v "${MR}-tree":/t debian:trixie-slim setfacl -b /t/ob-cache
	check "and served again with it removed" served pts $PROFILE
else
	echo "  SKIP  ACL cases: setfacl or file system ACLs are not available here"
fi

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

echo "== mirror 4b. the same tree under a root service is refused"
docker run -d --name "${MR}-rootsrv" --network "$QC_NET" -p 127.0.0.1::8080 -e PTS_USER_PATH_OVERRIDE=/data -v "${MR}-data":/data -v "${MR}-tree":/bench -w /bench/pts-core/phoromatic/public_html \
	"$QC_IMAGE" php -d display_errors=0 -d log_errors=1 -S 0.0.0.0:8080 -t /bench/pts-core/phoromatic/public_html >/dev/null
RPORT=$(docker port "${MR}-rootsrv" 8080/tcp | head -1 | sed 's/.*://')
for i in $(seq 1 30); do (exec 3<>/dev/tcp/127.0.0.1/$RPORT) 2>/dev/null && break; sleep 0.5; done
check "a server that runs as root gets nothing from the package copy (root could change it)" sh -c "! curl -s -m 60 'http://127.0.0.1:$RPORT/openbenchmarking-cache.php?test=$PROFILE&repo=pts' | grep -q ."
check "and the reason is in its log" sh -c "docker logs ${MR}-rootsrv 2>&1 | grep -q 'package profile was not served'"
docker rm -f "${MR}-rootsrv" >/dev/null 2>&1

echo "== mirror 4c. the layout install-sh makes, served by a non-root user"
docker volume create "${MR}-inst" >/dev/null
docker run --rm -v "$QC_TREE":/s:ro -v "${MR}-inst":/d "$QC_IMAGE" sh -c 'cp -r /s /w && cd /w && DESTDIR=/d ./install-sh /usr >/dev/null 2>&1' || exit 2
docker run -d --name "${MR}-instsrv" --network "$QC_NET" --user 33:33 -p 127.0.0.1::8080 -e PTS_USER_PATH_OVERRIDE=/data -v "${MR}-data":/data -v "${MR}-inst":/inst:ro -w /inst/usr/share/benchy/pts-core/phoromatic/public_html \
	"$QC_IMAGE" php -d display_errors=0 -d log_errors=1 -S 0.0.0.0:8080 -t /inst/usr/share/benchy/pts-core/phoromatic/public_html >/dev/null
IPORT=$(docker port "${MR}-instsrv" 8080/tcp | head -1 | sed 's/.*://')
for i in $(seq 1 30); do (exec 3<>/dev/tcp/127.0.0.1/$IPORT) 2>/dev/null && break; sleep 0.5; done
check "an official profile is served from /usr/share/benchy as installed by install-sh" sh -c "curl -s -m 60 'http://127.0.0.1:$IPORT/openbenchmarking-cache.php?test=$PROFILE&repo=pts' | base64 -d | python3 -c 'import sys,zipfile,io; z=zipfile.ZipFile(io.BytesIO(sys.stdin.buffer.read())); sys.exit(0 if \"test-definition.xml\" in z.namelist() else 1)'"
check "every official profile of that install builds (1700+)" sh -c "docker exec ${MR}-instsrv php -r 'define(\"PTS_MODE\",\"CLIENT\");define(\"PTS_AUTO_LOAD_OBJECTS\",true);include(\"/inst/usr/share/benchy/pts-core/pts-core.php\");pts_core::init(); \$n=0; foreach(phoromatic_package_profiles::OFFICIAL as \$r) foreach(scandir(PTS_INTERNAL_OB_CACHE.\"test-profiles/\".\$r) as \$p) { if(\$p[0]==\".\") continue; if(phoromatic_package_profiles::zip(\$r,\$p)===false) exit(1); \$n++; } exit(\$n > 1700 ? 0 : 2);' 2>/dev/null"
check "the same names, slashes and dots stripped, give the plain profile" sh -c "[ \"\$(curl -s -m 60 'http://127.0.0.1:$IPORT/openbenchmarking-cache.php?test=$PROFILE%2f..%2f..&repo=pts' | md5sum)\" = \"\$(curl -s -m 60 'http://127.0.0.1:$IPORT/openbenchmarking-cache.php?test=$PROFILE&repo=pts' | md5sum)\" ]"
docker rm -f "${MR}-instsrv" >/dev/null 2>&1

echo "== mirror 5. the writable copy is never read"
root "mkdir -p /data/test-profiles/pts/$PROFILE && echo TN_TAMPERED > /data/test-profiles/pts/$PROFILE/test-definition.xml && chown -R 33:33 /data/test-profiles"
check "a changed working copy does not change what is served" sh -c "! curl -s -m 60 'http://127.0.0.1:$PORT/openbenchmarking-cache.php?test=$PROFILE&repo=pts' | base64 -d 2>/dev/null | grep -aq TN_TAMPERED"
check "and the zip is still served" served pts $PROFILE
[ $rc -eq 0 ] && echo "MIRROR: PASS" || echo "MIRROR: FAIL"
exit $rc
