#!/bin/bash
# The catalog verifier tests (E4.4 C1): the PHP verifier against the engine's committed fixtures and the rules of catalog spec 13. Driven by
# run.sh (QC_IMAGE, QC_TREE): run.sh catalog. No server, no database, no network, no private key; a throwaway container with the committed tree
# mounted read-only.
set -u
: "${QC_IMAGE:?run via run.sh catalog}" "${QC_TREE:?run via run.sh catalog}"
timeout 600 docker run --rm --network none -u 1000 -v "$QC_TREE":/bench:ro "$QC_IMAGE" php -d memory_limit=1G /bench/tests/catalog/run_tests.php 2>&1 | head -c 200000
exit "${PIPESTATUS[0]}"
