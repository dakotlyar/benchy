#!/bin/bash
# 0.16.3: the request body limits of the generated Caddy config, proved against the pinned real Caddy (the version of runtime-pins.json, 2.11.7).
# Caddy ANDs the keys of one query matcher, so the bundle pages need one route each. This starts Caddy in front of a stub upstream and POSTs 60 MB:
# /?server_catalog and /?admin_config must pass Caddy (200 from the stub), /?login and / must get 413. Needs podman or docker and the benchy-qc-https image
# (its /opt/caddy/caddy.tar.gz is the pinned tarball); nothing is installed or written outside the container.
#   tests/web-bundles/caddy_body_limit.sh [image]
set -u
HERE=$(cd "$(dirname "$0")" && pwd)
TREE=$(cd "$HERE/../.." && pwd)
IMG=${1:-localhost/benchy-qc-https:7ca072a15b18}
CT=$(command -v podman || command -v docker)
"$CT" run --rm -v "$TREE:/bench:ro,Z" -v "$HERE:/h:ro,Z" "$IMG" bash /h/caddy_body_limit_inner.sh
