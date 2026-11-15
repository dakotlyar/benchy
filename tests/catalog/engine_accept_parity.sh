#!/bin/bash
# One accepted-bundle parity case: a bundle with real packs, signed by a throwaway key made in memory by make_accept_bundle.php, is accepted by
# the PHP verifier and by a catalogtest engine that trusts that key's public half through BENCHY_CATALOGTEST_SPKI. Nothing is committed and no
# private key reaches a file. Needs the engine build described in engine_verdicts.sh:
#     tests/catalog/engine_accept_parity.sh /path/to/engine-ct
set -eu
HERE=$(cd "$(dirname "$0")" && pwd)
ENGINE=${1:?usage: engine_accept_parity.sh /path/to/engine-ct}
IMAGE=${QC_IMAGE:-benchy-qc-php}
W=$(mktemp -d /tmp/benchy-accept.XXXXXX)
trap 'rm -rf "$W"' EXIT
chmod 777 "$W"
docker run --rm --network none -u 1000 -v "$HERE/../..":/bench:ro -v "$W":/w "$IMAGE" php /bench/tests/catalog/make_accept_bundle.php /w/bundle.zip /w/public.spki
out=$(docker run --rm --network none -u 1000 -v "$ENGINE":/engine:ro -v "$W":/w:ro -e BENCHY_CATALOGTEST_SPKI="$(cat "$W/public.spki")" -e HOME=/tmp/h "$IMAGE" sh -c 'mkdir -p /tmp/h; /engine --json catalog.import /w/bundle.zip') || true
echo "$out" | grep '"type":"catalog_import"' | sed 's/^/engine: /' || { echo "engine did not accept:"; echo "$out" | tail -3; exit 1; }
