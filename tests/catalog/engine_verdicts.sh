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
docker run --rm --network none -u 1000 -v "$ENGINE":/engine:ro -v "$HERE/bundles":/bundles:ro -e BENCHY_CATALOGTEST_SPKI="$SPKI" "$IMAGE" sh -c '
for f in /bundles/*.zip; do
	n=$(basename "$f" .zip); h=$(mktemp -d)
	o=$(HOME=$h /engine --json catalog.import "$f" 2>&1)
	r=$(printf "%s\n" "$o" | grep "\"type\":\"error\"" | sed -n "s/.*\"reason\":\"\([a-z0-9_]*\)\".*/\1/p")
	if [ -z "$r" ]; then
		# no refusal: only a real accept (a catalog_import event) counts; a crash or empty output is an error, never "accepted"
		if printf "%s\n" "$o" | grep -q "\"type\":\"catalog_import\""; then r=accepted; else r=ENGINE_ERROR; fi
	fi
	echo "$n $r"
done' | sort > "$HERE/bundles/.verdicts.tmp"
if grep -q ENGINE_ERROR "$HERE/bundles/.verdicts.tmp"; then grep ENGINE_ERROR "$HERE/bundles/.verdicts.tmp"; rm -f "$HERE/bundles/.verdicts.tmp"; echo "the engine crashed or printed nothing for the bundles above" >&2; exit 1; fi
python3 -c '
import sys, json
print(json.dumps({l.split()[0]: l.split()[1] for l in sys.stdin}, indent=1, sort_keys=True))' < "$HERE/bundles/.verdicts.tmp" > "$HERE/bundles/engine-verdicts.json"
rm -f "$HERE/bundles/.verdicts.tmp"
cat "$HERE/bundles/engine-verdicts.json"
