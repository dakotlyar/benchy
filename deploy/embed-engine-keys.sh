#!/bin/sh
# The one step that swaps the development engine-release keys for the real ones on the server. Run it once, from the
# Benchy tree, with the two PUBLIC key files from the engine key ceremony: generation 1 (current) and generation 2
# (standby), the same two files given to the engine's scripts/embed-engine-keys.sh. It rewrites
# pts-core/static/engine/keys.json whole, sets test_keys to false, and prints `benchy server-engine-keys` for you to
# compare with benchy-tests/legal/engine-keys.txt.
#
#   deploy/embed-engine-keys.sh engine-key-1.pub.pem engine-key-2.pub.pem
#
# It reads only public keys. It never needs, and must never be given, a private key.
set -eu
k1=${1:?usage: embed-engine-keys.sh <engine-key-1.pub.pem> <engine-key-2.pub.pem>}
k2=${2:?usage: embed-engine-keys.sh <engine-key-1.pub.pem> <engine-key-2.pub.pem>}
here=$(cd "$(dirname "$0")/.." && pwd)
out="$here/pts-core/static/engine/keys.json"
for f in "$k1" "$k2"; do
	if grep -q 'PRIVATE KEY' "$f"; then
		echo "embed-engine-keys: $f holds a PRIVATE key. Stop: give this script the public file only." >&2
		exit 1
	fi
	openssl pkey -pubin -in "$f" -noout -text 2>/dev/null | grep -q 'prime256v1\|P-256' || {
		echo "embed-engine-keys: $f is not an ECDSA P-256 public key" >&2
		exit 1
	}
done
s1=$(openssl pkey -pubin -in "$k1" -outform DER | base64 -w0)
s2=$(openssl pkey -pubin -in "$k2" -outform DER | base64 -w0)
[ "$s1" != "$s2" ] || { echo "embed-engine-keys: the two keys are the same" >&2; exit 1; }
cat >"$out" <<JSON
{
  "schema": "benchy-engine-keys/1",
  "test_keys": false,
  "keys": [
    { "generation": 1, "spki": "$s1" },
    { "generation": 2, "spki": "$s2" }
  ],
  "denied_key_ids": []
}
JSON
listing=$(cd "$here" && sh ./benchy server-engine-keys) || { echo "embed-engine-keys: the server did not read the new table" >&2; exit 1; }
if printf '%s\n' "$listing" | grep -q '^TEST KEYS'; then
	echo "embed-engine-keys: the table still reports TEST KEYS" >&2
	exit 1
fi
printf '%s\n' "$listing"
echo "embed-engine-keys: wrote $out. Put the lines above in benchy-tests/legal/engine-keys.txt." >&2
