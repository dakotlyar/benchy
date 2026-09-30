#!/bin/sh
# Deploy the latest dakotlyar/benchy master to this host's running Phoromatic
# Server: pull, sync the installed copy under /usr/share/benchy (the service
# serves web assets from there, not from this clone directly), restart the
# service, and confirm it actually came back up before exiting.
#
# Usage: /opt/benchy/deploy.sh
# Run as root (same as the manual routine this replaces).

set -e

REPO_DIR="$(cd "$(dirname "$0")" && pwd)"
SERVICE="phoromatic-server.service"

echo "== git pull ($REPO_DIR) =="
cd "$REPO_DIR"
BEFORE="$(git rev-parse HEAD)"
git pull origin master
AFTER="$(git rev-parse HEAD)"

if [ "$BEFORE" = "$AFTER" ]; then
	echo "Already up to date at $AFTER — continuing anyway to sync/restart."
else
	echo "Updated $BEFORE -> $AFTER"
fi

echo
echo "== install-sh (sync /usr/share/benchy) =="
./install-sh

echo
echo "== restart $SERVICE =="
systemctl restart "$SERVICE"

echo
echo "== verify =="
# Give it a moment to settle before judging it, same as the manual routine.
for i in 1 2 3 4 5; do
	STATE="$(systemctl show "$SERVICE" -p ActiveState --value)"
	if [ "$STATE" = "active" ]; then
		break
	fi
	sleep 1
done

SUBSTATE="$(systemctl show "$SERVICE" -p SubState --value)"
RESTARTS="$(systemctl show "$SERVICE" -p NRestarts --value)"

echo "ActiveState=$STATE SubState=$SUBSTATE NRestarts=$RESTARTS"

if [ "$STATE" != "active" ] || [ "$SUBSTATE" != "running" ]; then
	echo "FAILED: $SERVICE is not active/running after restart." >&2
	systemctl status "$SERVICE" --no-pager -l >&2
	exit 1
fi

if [ "$RESTARTS" != "0" ]; then
	echo "WARNING: NRestarts=$RESTARTS (expected 0 right after a clean restart) — check for a crash loop." >&2
fi

echo "OK: $SERVICE is active (running) at $(date -Iseconds)."
