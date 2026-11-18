#!/bin/bash
# hold_drop.sh <tree> <dest>: a copy of <tree> in which the legacy password hash drop (the last schema step of 0.10.0) never runs, so the database stays at the
# schema before it and every password is still written as both hashes. The older-release rollback checks (T3 to T6, E4.4, run.sh rollback) sign in on the old release after
# this checkout has had the data, which is the one thing the drop makes impossible by design; they test their own step, and rollback_drop.sh tests the drop itself.
set -e
cp -a "$1"/. "$2"/
f="$2/pts-core/objects/phoromatic/phoromatic_server.php"
grep -q 'self::migrate_password_drop(\$start_version === 0);' "$f" || { echo "hold_drop: the drop step call was not found in $f" >&2; exit 1; }
sed -i 's/self::migrate_password_drop(\$start_version === 0);/\/\/ (held by tests\/tenancy\/hold_drop.sh)/' "$f"
