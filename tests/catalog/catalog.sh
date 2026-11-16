#!/bin/bash
# The catalog verifier tests (E4.4 C1): the PHP verifier against the engine's committed fixtures and the rules of catalog spec 13. Driven by
# run.sh (QC_IMAGE, QC_TREE): run.sh catalog. No server, no database, no network, no private key; a throwaway container with the committed tree
# mounted read-only.
set -u
: "${QC_IMAGE:?run via run.sh catalog}" "${QC_TREE:?run via run.sh catalog}"
timeout 600 docker run --rm --network none -u 1000 -v "$QC_TREE":/bench:ro "$QC_IMAGE" php -d memory_limit=1G /bench/tests/catalog/run_tests.php 2>&1 | head -c 200000
rc=${PIPESTATUS[0]}
# The command line, offline, with a fresh HOME: server-catalog-keys prints the key lines and nothing else (no banner, no network check, no
# ~/.benchy), byte for byte what `benchy-engine catalog keys` prints and benchy-tests/legal/catalog-keys.txt holds
out=$(timeout 300 docker run --rm --network none -u 1000 -e HOME=/tmp/h -v "$QC_TREE":/bench:ro "$QC_IMAGE" sh -c 'mkdir -p /tmp/h; sh /bench/benchy server-catalog-keys 2>/tmp/err > /tmp/out; echo "rc=$?"; echo "stderr=$(wc -c < /tmp/err)"; echo "home=$(ls -A /tmp/h | wc -l)"; echo ---; cat /tmp/out' 2>&1)
want=$(printf 'rc=0\nstderr=0\nhome=0\n---\ngeneration 1 4df551974840a54245bb240473ffb409\ngeneration 2 86e398395c836b4b0a583d1c84e012bc')
if [ "$out" = "$want" ]; then echo "  PASS  benchy server-catalog-keys, offline, fresh HOME: the two key lines only, nothing in HOME"; else echo "  FAIL  benchy server-catalog-keys output"; printf '%s\n' "$out" | head -20 | sed 's/^/        | /'; rc=1; fi
# The biggest bundle a bundle can be, under the memory the import raises itself to
big=$(timeout 600 docker run --rm --network none -u 1000 -v "$QC_TREE":/bench:ro "$QC_IMAGE" php -d memory_limit=512M /bench/tests/catalog/big_bundle.php 2>&1 | tail -3)
case "$big" in *"ok, peak"*) echo "  PASS  a 126 MiB bundle through the whole verifier under memory_limit=512M ($(echo "$big" | tail -1))";; *) echo "  FAIL  the 126 MiB bundle"; printf '%s\n' "$big" | sed 's/^/        | /'; rc=1;; esac
# The server side (E4.4 C2): storage, import, repair, collection, the packaged bundle, through the command line against a database of each scenario's own,
# in a copy of the tree whose key table holds keys made fresh for this run (nothing private is written anywhere). Own HOME in every scenario.
out=$(timeout 1500 docker run --rm --network none -u 1000 -v "$QC_TREE":/bench:ro "$QC_IMAGE" php -d memory_limit=1G /bench/tests/catalog/import_run.php 2>&1 | head -c 200000)
printf '%s\n' "$out"
case "$out" in *" 0 failed"*) ;; *) echo "FAILED: the import tests"; rc=1;; esac
# Serving (E4.4 C3): both GET routes, hello and poll, the auth matrix, and a real catalogtest engine against a scratch server started by the real launcher; own network and volumes
out=$(timeout 1500 bash "$(dirname "$0")/serve.sh" 2>&1 </dev/null | head -c 200000)
printf '%s\n' "$out"
case "$out" in *"CATALOG SERVING: PASS"*) ;; *) echo "FAILED: the serving tests"; rc=1;; esac
exit $rc
