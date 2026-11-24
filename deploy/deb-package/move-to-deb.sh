#!/bin/bash
# Moves a Benchy server that was set up by hand (a git clone and deploy.sh, run as the phoromatic user: seinar) onto the benchy and benchy-server
# packages, once, so that later versions can be installed from the web page. Run it ON the server, as root, with the two debs and the server update bundle of
# the same version (the bundle is the way back for the first web update):
#
#   sudo bash move-to-deb.sh DEBDIR BUNDLE.zip [OTHER-BUNDLE.zip ...]
#
# DEBDIR holds benchy_X_all.deb and benchy-server_X_all.deb. Every further bundle (components, engine) is imported after the move with the command that matches
# its name. It stops at the first problem and says what is in place. The database copy it makes is /root/phoromatic-pre-deb.db.
# Going back: dpkg -r benchy-server benchy; rm -rf /usr/share/benchy; mv /usr/share/benchy.pre-deb /usr/share/benchy; cd /opt/benchy && ./install-sh;
# cp -p /root/phoromatic-pre-deb.db <the database path it printed>; systemctl enable --now phoromatic-server
set -euo pipefail
[ "$(id -u)" = 0 ] || { echo "run this as root"; exit 1; }
[ $# -ge 2 ] || { sed -n '2,9p' "$0"; exit 2; }
DEBDIR=$(realpath -- "$1") || { echo "no such folder: $1"; exit 1; }; BUNDLE=$2; shift 2
B() { runuser -u phoromatic -- env HOME=/var/lib/phoromatic benchy "$@"; }
say() { printf '\n=== %s\n' "$*"; }

VER=$(ls "$DEBDIR"/benchy_*_all.deb 2>/dev/null | sed -n 's/.*benchy_\(.*\)_all.deb/\1/p' | head -1)
[ -n "$VER" ] && [ -f "$DEBDIR/benchy_${VER}_all.deb" ] && [ -f "$DEBDIR/benchy-server_${VER}_all.deb" ] || { echo "DEBDIR needs benchy_X_all.deb and benchy-server_X_all.deb of one version"; exit 1; }
[ -f "$BUNDLE" ] || { echo "no such bundle: $BUNDLE"; exit 1; }
# a rerun must never overwrite the only copy from before the move, or move the tree aside twice
[ ! -e /root/phoromatic-pre-deb.db ] || { echo "STOP: /root/phoromatic-pre-deb.db exists already (a copy from an earlier run). Look at it, move it away, then run again."; exit 1; }
[ ! -e /usr/share/benchy.pre-deb ] || { echo "STOP: /usr/share/benchy.pre-deb exists already (an earlier run). Look at it first."; exit 1; }

say "1. the layout, checked with the new package's own checker (the package is not installed yet)"
T=$(mktemp -d); trap 'rm -rf "$T"' EXIT
dpkg-deb -x "$DEBDIR/benchy-server_${VER}_all.deb" "$T" || { echo "cannot unpack the benchy-server deb"; exit 1; }
# rows the package itself puts right (its drop-in and unit settings) may differ before the move; anything else must be sorted out first
if OUT=$(sh "$T/usr/sbin/benchy-server-setup" --check 2>&1); then echo "$OUT" | tail -5; else
	echo "$OUT" | tail -25
	BAD=$(echo "$OUT" | grep -E '^ +(DIFFERENT|MISSING) ' | grep -v -E 'unit (User|Environment|enabled)=?|service ' || true)
	[ -z "$BAD" ] || { echo; echo "STOP: these rows differ for a reason the package does not fix:"; echo "$BAD"; exit 1; }
fi

say "2. stop the server and copy the database"
DB=$(B server-info 2>/dev/null | sed -n 's/^Database: \(\/.*\), schema version.*/\1/p') || true
[ -n "$DB" ] || { echo "could not find the database (benchy server-info)"; exit 1; }
systemctl stop phoromatic-server
cp -p "$DB" /root/phoromatic-pre-deb.db
for x in -wal -shm; do [ ! -e "$DB$x" ] || cp -p "$DB$x" "/root/phoromatic-pre-deb.db$x"; done
echo "database $DB copied to /root/phoromatic-pre-deb.db"

say "3. put the old tree aside and install the packages"
mv /usr/share/benchy /usr/share/benchy.pre-deb
DEBIAN_FRONTEND=noninteractive apt-get install -y "$DEBDIR/benchy_${VER}_all.deb" "$DEBDIR/benchy-server_${VER}_all.deb"

say "4. start the server and check it"
systemctl start phoromatic-server
for i in $(seq 1 60); do
	curl -sk "https://127.0.0.1:39443/api/v2/server" | grep -q "\"version\":\"$VER\"" && break
	curl -s "http://127.0.0.1:39080/api/v2/server" | grep -q "\"version\":\"$VER\"" && break
	sleep 2
	[ "$i" != 60 ] || { echo "the server does not report $VER; see journalctl -u phoromatic-server. The database copy is /root/phoromatic-pre-deb.db"; exit 1; }
done
echo "the server runs $VER"
benchy-server-setup --check 2>&1 | tail -8 || true
systemctl is-enabled benchy-server-update.path || { echo "benchy-server-update.path is not enabled after the install; see systemctl status benchy-server-update.path"; exit 1; }

say "5. import the bundles (the server update bundle is the way back for the first web update)"
B server-update-import "$BUNDLE"
for f in "$@"; do
	case $(basename "$f") in
		benchy-components-bundle-*) B server-component-import "$f" ;;
		benchy-engine-bundle-*) B server-engine-import "$f" ;;
		benchy-catalog-*) B server-catalog-import "$f" ;;
		*) echo "not imported (unknown name): $f" ;;
	esac
done
say "done: the server is on the packages, and the Server settings page can update it from now on"
