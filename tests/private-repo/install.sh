#!/bin/bash
# install-sh / install_macos (0.9.2, extended in 0.9.3): the INSTALLED launcher, not the one in the source tree. Driven by run.sh (QC_IMAGE, QC_TREE): run.sh install
# 0.9.1's install-sh rewrote the launcher's PTS_DIR line with a sed that matched the line's exact text; once the launcher's line changed
# (quoting) the sed matched nothing, the installed /usr/bin/benchy resolved PTS_DIR to /usr/bin and exited 0 with "you must first change
# directories", so a freshly deployed server never started. Runs from the source tree could not see it. Here install-sh installs into a
# throw-away container the way deploy.sh does on a server (prefix /usr, as root), then the installed launcher runs from an unrelated directory.
# 0.9.3 adds: the launcher is checked in a temp file and moved into place (a failed install leaves the old launcher alone), exactly one
# marker line is required, the error paths exit non-zero, install-sh prints no stray "-e", and install_macos gets the same marker rewrite.
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
# ctr_bob <script>: the same as the unprivileged user (a prefix that user cannot write to), the tree copied somewhere it can write
ctr_bob() { timeout 300 docker run --rm --network none -u 1000 -v "$QC_TREE":/bench:ro "$QC_IMAGE" sh -c "cp -r /bench /tmp/src && cd /tmp/src && $1" </dev/null 2>&1 | head -c 20000; return "${PIPESTATUS[0]}"; }
shows_version() {  # shows_version <prefix> [script]: install, then run the installed launcher from /
	local pfx=${1:-/usr} script=${2:-install-sh} out
	out=$(ctr "sh $script $1 >/dev/null && cd / && $pfx/bin/benchy version") || { echo "$out"; return 1; }
	echo "$out" | tail -3
	echo "$out" | grep -q "Benchy v$WANT" && ! echo "$out" | grep -q 'must first change directories'
}
# After a good install, break the source launcher with <sed script>, install again: the installer must exit non-zero with <message>,
# and the launcher already installed must be byte-for-byte the same and still work.
breaks_and_keeps() {  # breaks_and_keeps <script> <message regex> <shell snippet that breaks ./benchy>
	local script=$1 msg=$2 out
	out=$(ctr "sh $script /usr >/dev/null && cp /usr/bin/benchy /before && $3 && sh $script /usr >/dev/null 2>/err; echo rc=\$?; cat /err; cmp /usr/bin/benchy /before && echo kept; ls -A /usr/bin | grep -c '^\.benchy\.' ; cd / && /usr/bin/benchy version")
	echo "$out" | tail -8
	! echo "$out" | grep -q '^rc=0$' && echo "$out" | grep -Eq "$msg" && echo "$out" | grep -q '^kept$' && echo "$out" | grep -q "Benchy v$WANT"
}
fails_loudly() {  # the launcher without its marker line: install-sh must exit non-zero and say so (nothing installed yet)
	local out
	out=$(ctr "sed -i '/install-sh:pts-dir/d' benchy && sh install-sh /usr >/dev/null 2>/err; echo rc=\$?; cat /err; ls /usr/bin/benchy 2>&1")
	echo "$out" | tail -4
	echo "$out" | grep -q 'found 0' && ! echo "$out" | grep -q '^rc=0$' && echo "$out" | grep -q 'No such file'
}
exits_nonzero() {  # exits_nonzero <message regex> <shell snippet that must fail>
	local out
	out=$(ctr "$2; echo rc=\$?")
	echo "$out" | tail -4
	echo "$out" | grep -Eq "$1" && ! echo "$out" | grep -q '^rc=0$'
}
no_stray_dash_e() {
	local out
	out=$(ctr "sh install-sh /usr")
	echo "$out" | tail -5
	echo "$out" | grep -q 'Benchy Installation Completed' && ! echo "$out" | grep -q '^-e'
}
unwritable_prefix() {
	local out
	out=$(ctr_bob 'sh install-sh /usr; echo rc=$?')
	echo "$out" | tail -3
	echo "$out" | grep -q 'is not writable' && ! echo "$out" | grep -q '^rc=0$'
}
leaves_no_temp_file() {
	local out
	out=$(ctr "sh install-sh /usr >/dev/null && ls -A /usr/bin | grep -c '^\.benchy\.'; ls -l /usr/bin/benchy")
	echo "$out"
	echo "$out" | head -1 | grep -qx 0 && echo "$out" | grep -q -- '-rwxr-xr-x.*/usr/bin/benchy'
}

# The completion and the systemd units go to the system for /usr, inside the prefix for /usr/local, and nowhere for another prefix (0.9.5: they
# used to land in <prefix>/../etc and <prefix>/../usr, outside the prefix)
puts_system_files_where() {  # puts_system_files_where <prefix> <script> [installer]: <script> runs after the install and exits 0 when the files are where they should be
	local out
	out=$(ctr "sh ${3:-install-sh} $1 >/tmp/install.out && $2; echo rc=\$?; cat /tmp/install.out | grep '^Note:'")
	echo "$out" | tail -4
	echo "$out" | grep -q '^rc=0$'
}
# 0.9.5: the launcher changes into the install directory, so a relative file name was read from there. It exports the directory it was run from
# and the commands that take a file read relative names against it.
reads_relative_files_from_the_callers_directory() {
	# as the unprivileged user: a root process without a network waits a minute for one, a normal user's does not
	local out rel missing
	out=$(ctr_bob "export HOME=/tmp/qchome && mkdir -p /tmp/qchome && sh install-sh /tmp/pfx >/dev/null && mkdir /tmp/work && cd /tmp/work && openssl req -x509 -newkey rsa:2048 -nodes -keyout k.pem -out ca.pem -subj /CN=qc-ca -days 2 2>/dev/null \\
		&& echo '--- relative:' && /tmp/pfx/bin/benchy server-trust https://127.0.0.1:1/AID --ca-file ca.pem 2>&1 | grep -a 'ERROR\\|holds' ; echo '--- missing:' && /tmp/pfx/bin/benchy server-trust https://127.0.0.1:1/AID --ca-file nothere.pem 2>&1 | grep -a 'ERROR\\|holds'; echo '--- end'")
	echo "$out" | head -20
	rel=$(echo "$out" | sed -n '/^--- relative:/,/^--- missing:/p')
	missing=$(echo "$out" | sed -n '/^--- missing:/,/^--- end/p')
	# the certificate in the caller's directory is found (whatever else goes wrong without a server), the missing file is named with its full path
	echo "$out" | grep -q '^--- end' && ! echo "$rel" | grep -q 'holds no PEM certificate' && echo "$missing" | grep -q '/tmp/work/nothere.pem holds no PEM certificate'
}
echo "== install-sh: where the completion and the systemd units go"
check "prefix /usr: completion in /etc/bash_completion.d, units in /usr/lib/systemd/system, no note" puts_system_files_where /usr \
	"[ -f /etc/bash_completion.d/benchy ] && ls /usr/lib/systemd/system/*.service >/dev/null && ! grep -q '^Note:' /tmp/install.out"
check "prefix /usr/local: completion and units stay inside it, nothing lands in /usr/etc or /usr/usr" puts_system_files_where /usr/local \
	"[ -f /usr/local/share/bash-completion/completions/benchy ] && ls /usr/local/lib/systemd/system/*.service >/dev/null && [ ! -e /usr/etc ] && [ ! -e /usr/usr ] && [ ! -e /etc/bash_completion.d/benchy ]"
check "a prefix ending in /usr (an rpm build root): the /usr layout inside its own tree, units still say /usr/bin/benchy" puts_system_files_where /tmp/br/usr \
	"[ -f /tmp/br/etc/bash_completion.d/benchy ] && ls /tmp/br/usr/lib/systemd/system/*.service >/dev/null && [ ! -e /etc/bash_completion.d/benchy ] && [ ! -e /tmp/br/usr/etc ] && grep -q '^ExecStart=/usr/bin/benchy ' /tmp/br/usr/lib/systemd/system/*.service && ! grep -q '^Note:' /tmp/install.out"
check "prefix /usr/local: the installed units start /usr/local/bin/benchy, with their arguments" puts_system_files_where /usr/local \
	"! grep -h '^ExecStart=' /usr/local/lib/systemd/system/*.service | grep -v '^ExecStart=/usr/local/bin/benchy ' && [ \$(grep -h '^ExecStart=/usr/local/bin/benchy ' /usr/local/lib/systemd/system/*.service | wc -l) = \$(ls /usr/local/lib/systemd/system/*.service | wc -l) ] && grep -q 'ExecStart=/usr/local/bin/benchy phoromatic.connect' /usr/local/lib/systemd/system/*.service"
check "another prefix: neither is installed, the installer says so, and nothing is written beside the prefix" puts_system_files_where /opt/p/prefix \
	"[ ! -e /opt/p/etc ] && [ ! -e /opt/p/usr ] && [ ! -e /opt/p/prefix/etc ] && grep -q '^Note: the prefix /opt/p/prefix is not /usr or /usr/local' /tmp/install.out && [ ! -e /etc/bash_completion.d/benchy ]"
check "install_macos with another prefix writes nothing beside it either" puts_system_files_where /opt/p/prefix \
	"[ ! -e /opt/p/etc ] && [ ! -e /opt/p/usr ] && [ -x /opt/p/prefix/bin/benchy ]" install_macos
echo "== install-sh: the installed launcher"
check "install into /usr (seinar's layout), 'cd / && /usr/bin/benchy version' prints Benchy v$WANT" shows_version /usr
check "install into /opt/benchy-x (another prefix), same check" shows_version /opt/benchy-x
check "a launcher without the PTS_DIR marker makes install-sh fail (non-zero, 'found 0') before anything is installed" fails_loudly
check "two marker lines: install-sh fails ('found 2'), the installed launcher stays as it was and still works" \
	breaks_and_keeps install-sh 'found 2' "echo '# install-sh:pts-dir' >> benchy"
check "a marker on a line the launcher does not use: the rewrite is checked by resolving PTS_DIR, install-sh fails, the installed launcher stays" \
	breaks_and_keeps install-sh 'resolves PTS_DIR to' "sed -i '/install-sh:pts-dir/s/ # install-sh:pts-dir.*//; s|^\texport PTS_DIR=\"/usr/share/benchy/\"\$|& # install-sh:pts-dir|' benchy"
check "install-sh run from the wrong directory exits non-zero" exits_nonzero 'must first change directories' "cd / && sh /src/install-sh"
check "a prefix the user cannot write to: install-sh exits non-zero" unwritable_prefix
check "install-sh prints no stray '-e' in its closing message" no_stray_dash_e
check "a good install leaves no temp file next to the launcher" leaves_no_temp_file


# 0.9.5: root's configuration is /etc/benchy.xml (it was /etc/phoronix-test-suite.xml); an old file is copied across once and left alone
uses_the_benchy_config_file() {
	local out
	out=$(ctr "sh benchy user-config-set CacheDirectory=/tmp/qc-cache 2>&1 | grep 'written'; ls /etc/benchy.xml; ls /etc/phoronix-test-suite.xml 2>&1")
	echo "$out" | head -8
	echo "$out" | grep -q 'written: /etc/benchy.xml' && echo "$out" | grep -q 'phoronix-test-suite.xml.: No such file'
}
copies_an_old_config_file_once() {
	local out
	out=$(ctr "sh benchy user-config-set CacheDirectory=/tmp/qc-old-cache >/dev/null 2>&1; mv /etc/benchy.xml /etc/phoronix-test-suite.xml && cp /etc/phoronix-test-suite.xml /old.xml \\
		&& sh benchy user-config-set ResultsDirectory=/tmp/qc-results 2>&1 | grep 'written\\|now lives'; echo '---'; grep -c qc-old-cache /etc/benchy.xml; grep -c qc-results /etc/benchy.xml; cmp /old.xml /etc/phoronix-test-suite.xml && echo old-untouched \\
		&& sh benchy user-config-set ResultsDirectory=/tmp/qc-results2 2>&1 | grep -c 'now lives'")
	echo "$out" | head -12
	# the notice once, the new file written with the old settings kept, the old file as it was, and no second notice
	echo "$out" | grep -q 'written: /etc/benchy.xml' && [ "$(echo "$out" | grep -c 'now lives')" = 1 ] && echo "$out" | sed -n '/^---/,$p' | tr '\n' ' ' | grep -q -- '--- 1 1 old-untouched 0'
}

# 0.9.5 review: the copy keeps the old file's mode, owner and group (it can hold passwords), and leaves no temporary file behind
copy_keeps_mode_owner_group() {
	local out
	out=$(ctr "sh benchy user-config-set CacheDirectory=/tmp/qc-old-cache >/dev/null 2>&1; mv /etc/benchy.xml /etc/phoronix-test-suite.xml && chown 1234:4321 /etc/phoronix-test-suite.xml && chmod 0640 /etc/phoronix-test-suite.xml \\
		&& sh benchy user-config-set ResultsDirectory=/tmp/qc-results >/dev/null 2>&1; stat -c 'new %a %u %g' /etc/benchy.xml; stat -c 'old %a %u %g' /etc/phoronix-test-suite.xml; ls /etc | grep -c 'benchy.xml.tmp'")
	echo "$out" | head -6
	echo "$out" | grep -qx 'new 640 1234 4321' && echo "$out" | grep -qx 'old 640 1234 4321' && [ "$(echo "$out" | tail -1)" = 0 ]
}

echo "== install-sh: relative file arguments and the root configuration file"
check "root's configuration is /etc/benchy.xml, and /etc/phoronix-test-suite.xml is not created" uses_the_benchy_config_file
check "an old /etc/phoronix-test-suite.xml is copied to /etc/benchy.xml once, with a notice, and left as it was" copies_an_old_config_file_once
check "the copied /etc/benchy.xml keeps the old file's mode, owner and group, and no temporary file is left" copy_keeps_mode_owner_group
check "a relative --ca-file is read from the directory the installed launcher was run from, not from the install directory" reads_relative_files_from_the_callers_directory

echo "== install_macos: the same marker rewrite"
check "install_macos into /usr, 'cd / && /usr/bin/benchy version' prints Benchy v$WANT" shows_version /usr install_macos
check "install_macos with two marker lines fails and keeps the installed launcher" \
	breaks_and_keeps install_macos 'found 2' "echo '# install-sh:pts-dir' >> benchy"
check "install_macos with no marker line fails (non-zero, 'found 0')" exits_nonzero 'found 0' "sed -i '/install-sh:pts-dir/d' benchy && sh install_macos /usr >/dev/null"

echo; echo "install: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ] || { printf '  failed: %s\n' "${FAILED[@]}"; exit 1; }
