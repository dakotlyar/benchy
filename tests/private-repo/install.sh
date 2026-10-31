#!/bin/bash
# install-sh (0.9.2): the INSTALLED launcher, not the one in the source tree. Driven by run.sh (QC_IMAGE, QC_TREE): run.sh install
# 0.9.1's install-sh rewrote the launcher's PTS_DIR line with a sed that matched the line's exact text; once the launcher's line changed
# (quoting) the sed matched nothing, the installed /usr/bin/benchy resolved PTS_DIR to /usr/bin and exited 0 with "you must first change
# directories", so a freshly deployed server never started. Runs from the source tree could not see it. Here install-sh installs into a
# throw-away container the way deploy.sh does on a server (prefix /usr, as root), then the installed launcher runs from an unrelated directory.
# A prefix containing a space is not tested: install-sh does not support one (it leaves $DESTDIR$INSTALL_PREFIX unquoted throughout).
set -u
: "${QC_IMAGE:?run via run.sh install}" "${QC_TREE:?run via run.sh install}"
HERE=$(cd "$(dirname "$0")" && pwd)
PASS=0; FAIL=0; FAILED=()
WANT=$(sed -n "s/.*define('PTS_VERSION', *'\([^']*\)').*/\1/p" "$QC_TREE/pts-core/pts-core.php" | head -1)
[ -n "$WANT" ] || { echo "cannot read PTS_VERSION"; exit 2; }

check() {  # check "description" command...
	local d=$1 out; shift
	if out=$("$@" 2>&1); then PASS=$((PASS+1)); echo "  PASS  $d"; else FAIL=$((FAIL+1)); FAILED+=("$d"); echo "  FAIL  $d"; [ -n "$out" ] && printf '%s\n' "$out" | head -c 4000 | sed 's/^/        | /'; fi
}
# ctr <script>: runs in a throw-away container as root with a copy of the source tree as the working directory (install-sh must
# run from inside it); no network
ctr() { timeout 300 docker run --rm --network none -v "$QC_TREE":/bench:ro "$QC_IMAGE" sh -c "cp -r /bench /src && cd /src && $1" </dev/null 2>&1 | head -c 20000; return "${PIPESTATUS[0]}"; }
shows_version() {  # shows_version <install-sh args>: install, then run the installed launcher from /
	local pfx=${1:-/usr} out
	out=$(ctr "sh install-sh $1 >/dev/null && cd / && $pfx/bin/benchy version") || { echo "$out"; return 1; }
	echo "$out" | tail -3
	echo "$out" | grep -q "Benchy v$WANT" && ! echo "$out" | grep -q 'must first change directories'
}
fails_loudly() {  # the launcher without its marker line: install-sh must exit non-zero and say so
	local out rc
	out=$(ctr "sed -i '/install-sh:pts-dir/d' benchy && sh install-sh /usr >/dev/null; echo rc=\$?")
	echo "$out" | tail -3
	echo "$out" | grep -q 'ERROR: install-sh could not point' && ! echo "$out" | grep -q '^rc=0$'
}

echo "== install-sh: the installed launcher"
check "install into /usr (seinar's layout), 'cd / && /usr/bin/benchy version' prints Benchy v$WANT" shows_version /usr
check "install into /opt/benchy-x (another prefix), same check" shows_version /opt/benchy-x
check "a launcher without the PTS_DIR marker makes install-sh fail with a message" fails_loudly

echo; echo "install: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ] || { printf '  failed: %s\n' "${FAILED[@]}"; exit 1; }
