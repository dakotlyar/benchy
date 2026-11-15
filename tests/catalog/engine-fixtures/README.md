# Signed index fixtures

One public key (fixture-key.spki, generation 1, base64 SPKI DER) and index.signed files made with
its private half. The private half was generated in memory by TestRegenerateFixtures and
discarded: it is not stored anywhere, and these files can't be re-signed. To make a new set, run

    go test ./internal/catindex -run TestRegenerateFixtures -regen-fixtures

which writes a new key's public half and new files here (and the old files then fail against the
new key; commit them together).

expected.json says what a verifier must do with each file at first contact, with an empty state and
no floor. Both the engine (TestCommittedFixtures) and the server's PHP verifier (E4.4) check these
same bytes. This key is a test key: no engine embeds it outside tests built with -tags catalogtest.
