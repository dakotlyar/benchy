#!/bin/bash
# Records what the engine says about every bundle in bundles/ and writes bundles/engine-verdicts.json. Not part of run.sh: it needs a
# benchy-engine built from the commit in engine-fixtures/PIN with the catalogtest tag, which is the only build that can trust the fixture key
# (BENCHY_CATALOGTEST_SPKI):
#     (cd ~/src/benchy-engine-e42 && CGO_ENABLED=0 go build -tags catalogtest -o /some/dir/engine-ct ./cmd/benchy-engine)
#     tests/catalog/engine_verdicts.sh /some/dir/engine-ct
# The engine runs in a throwaway container with its own HOME, one fresh HOME per bundle. No private key is involved: the bundles carry the
# engine's committed fixture index, signed by a key nobody holds.
set -eu
HERE=$(cd "$(dirname "$0")" && pwd)
ENGINE=${1:?usage: engine_verdicts.sh /path/to/engine-ct}
IMAGE=${QC_IMAGE:-benchy-qc-php}
SPKI=$(tr -d '\n' < "$HERE/engine-fixtures/fixture-key.spki")
docker run --rm -u 1000 -v "$ENGINE":/engine:ro -v "$HERE/bundles":/bundles:ro -e BENCHY_CATALOGTEST_SPKI="$SPKI" "$IMAGE" sh -c '
for f in /bundles/*.zip; do
	n=$(basename "$f" .zip); h=$(mktemp -d)
	r=$(HOME=$h /engine --json catalog.import "$f" 2>&1 | grep "\"type\":\"error\"" | sed -n "s/.*\"reason\":\"\([a-z0-9_]*\)\".*/\1/p")
	[ -n "$r" ] || r=accepted
	echo "$n $r"
done' | sort | python3 -c '
import sys, json
print(json.dumps({l.split()[0]: l.split()[1] for l in sys.stdin}, indent=1, sort_keys=True))' > "$HERE/bundles/engine-verdicts.json"
cat "$HERE/bundles/engine-verdicts.json"
