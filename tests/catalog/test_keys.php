<?php
// The key table (server design 2.3) and `server-catalog-keys`

const P384_SPKI = 'MHYwEAYHKoZIzj0CAQYFK4EEACIDYgAE0quKN0+jUw3+wYp7S6h7RktjsGL2LRvbCHEh0gDoY72aJ/vwOW5d6j2lyYGqo1sgmEVdFtv96BBt45zg471fhGLzcGQzoMskL3C6iKEqoHX4ga5iBsSB2zluKbAe+i5c';
const RSA_SPKI = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAsniAccp41eNxr0eAUHR9btjXiHb0mWj3WCFg+XSEAS+sAi2G06BDek6ypNA2ugG+jdtIyAcXNkz07ogjxz7rN/W1GfhJaLDe17l2OB1hnqT+gjal5UpW5EXh+f20Fvp02pybNTkv+rAgUAZsetCAsqb5r+xHGY9QOAfcooc5WPi61an5SGcwlu6UeF5viaNRwDCGZqFFZrpU66PDkflI3P/R6DAtfS10cDXXiCT3nsRZbrtzhxfyMkYouEP6tx2qyrTynyQOLUv3cVxeaf/qlQLLOIquUDhv2/stYhvFxx5U4XfgZ8gPnIcj1j9AIH8ggMSATD47JCaOBK5smsiqDQIDAQAB';
// a public P-256 key from a CA certificate that has nothing to do with Benchy, used where a second generation is needed
const OTHER_P256_SPKI = 'MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEKZenxkF/wA2b6AEbVsbyUqW6LbIS6NIu1/rJxdiqbR9zgTs7mGs5fDOlxU6GjoAXaGJFV31EWB2zN+VnCOtm3g==';

function table_reason($keys, $denied = array(), $floor = array())
{
	return reason_of(function() use ($keys, $denied, $floor) { new phoromatic_catalog_keys($keys, $denied, $floor); });
}

$prod = file_get_contents(PTS_PATH . 'pts-core/static/catalog/keys.json');

t('the shipped table is the engine\'s two production keys, with the ids of benchy-tests legal/catalog-keys.txt', function() use ($prod)
{
	$k = phoromatic_catalog_keys::from_json($prod);
	// the text of benchy-tests/legal/catalog-keys.txt at 68aa8c5, which is also what `benchy-engine catalog keys` prints
	eq("generation 1 4df551974840a54245bb240473ffb409\ngeneration 2 86e398395c836b4b0a583d1c84e012bc\n", $k->summary_text());
});

t('the shipped table has no denied id, no floor, and holds no fixture or test key', function() use ($prod)
{
	$k = phoromatic_catalog_keys::from_json($prod);
	eq(array(), $k->denied_ids());
	eq(null, $k->floor_of('public'));
	eq(null, $k->floor_of('private'));
	ok(strpos($prod, trim(fixture_file('fixture-key.spki'))) === false, 'the fixture key is in the production table');
	ok($k->lookup('d98e6e86a8584efd075cc0fc8155f980') === null, 'the fixture key id is trusted by the production table');
});

t('key ids are computed from the SPKI, never stored', function()
{
	$k = fixture_trust();
	eq('d98e6e86a8584efd075cc0fc8155f980', $k->all_keys()[0]['id']);
	eq(substr(hash('sha256', base64_decode(trim(fixture_file('fixture-key.spki')))), 0, 32), $k->all_keys()[0]['id']);
	ok(strpos(file_get_contents(PTS_PATH . 'pts-core/static/catalog/keys.json'), '"key_id"') === false, 'the table stores an id');
});

t('summary_text sorts keys by generation, then denied ids, then floors by tier', function()
{
	$spki = trim(fixture_file('fixture-key.spki'));
	$k = new phoromatic_catalog_keys(array(array('generation' => 2, 'spki' => OTHER_P256_SPKI), array('generation' => 1, 'spki' => $spki)),
		array(str_repeat('f', 32), str_repeat('0', 32)), array('public' => array('generation' => 2, 'sequence' => 9), 'private' => array('generation' => 1, 'sequence' => 3)));
	eq("generation 1 d98e6e86a8584efd075cc0fc8155f980\ngeneration 2 " . substr(hash('sha256', base64_decode(OTHER_P256_SPKI)), 0, 32) . "\n"
		. "denied " . str_repeat('0', 32) . "\ndenied " . str_repeat('f', 32) . "\nfloor private 1 3\nfloor public 2 9\n", $k->summary_text());
});

$spki = trim(fixture_file('fixture-key.spki'));
t('a table that is damaged is refused as a whole (key_table)', function() use ($spki)
{
	eq('key_table', table_reason(array(array('generation' => 0, 'spki' => $spki))), 'generation 0');
	eq('key_table', table_reason(array(array('generation' => 1, 'spki' => $spki), array('generation' => 1, 'spki' => OTHER_P256_SPKI))), 'generation twice');
	eq('key_table', table_reason(array(array('generation' => 1, 'spki' => $spki), array('generation' => 2, 'spki' => $spki))), 'one key twice');
	eq('key_table', table_reason(array(array('generation' => 1, 'spki' => '!!notbase64!!'))), 'not base64');
	eq('key_table', table_reason(array(array('generation' => 1, 'spki' => base64_encode('not an spki')))), 'not an SPKI');
	eq('key_table', table_reason(array(array('generation' => 1, 'spki' => P384_SPKI))), 'P-384');
	eq('key_table', table_reason(array(array('generation' => 1, 'spki' => RSA_SPKI))), 'RSA');
	eq('key_table', table_reason(array(array('generation' => '1', 'spki' => $spki))), 'generation as a string');
	eq('key_table', table_reason(array(array('generation' => 1, 'spki' => $spki)), array('ABC')), 'denied id not hex');
	eq('key_table', table_reason(array(array('generation' => 1, 'spki' => $spki)), array(str_repeat('A', 32))), 'denied id upper case');
	eq('key_table', table_reason(array(array('generation' => 1, 'spki' => $spki)), array(), array('Other' => array('generation' => 1, 'sequence' => 1))), 'floor for a name that is no catalog name');
	eq('key_table', table_reason(array(array('generation' => 1, 'spki' => $spki)), array(), array('corp' => array('generation' => 1, 'sequence' => 1))), 'floor for a reserved name');
	eq('accepted', table_reason(array(array('generation' => 1, 'spki' => $spki)), array(), array('gaming' => array('generation' => 1, 'sequence' => 1))), 'a floor for a named catalog (NC3)');
	eq('key_table', table_reason(array(array('generation' => 1, 'spki' => $spki)), array(), array('public' => array('generation' => 1, 'sequence' => 0))), 'floor sequence 0');
	eq('key_table', table_reason(array(array('generation' => 1, 'spki' => $spki)), array(), array('public' => array('generation' => 1, 'sequence' => 9007199254740992))), 'floor sequence 2^53');
	eq('key_table', table_reason(array(array('generation' => 1, 'spki' => $spki)), array(), array('public' => array('generation' => 0, 'sequence' => 1))), 'floor generation 0');
	eq('accepted', table_reason(array(array('generation' => 1, 'spki' => $spki)), array(str_repeat('a', 32)), array('public' => array('generation' => 1, 'sequence' => 9007199254740991))), 'a good table');
});

t('a table file that is not the table is refused (key_table)', function()
{
	foreach(array('', 'not json', '{}', '{"schema":"other/1","keys":[],"denied_key_ids":[],"floor":{}}', '{"schema":"benchy-catalog-keys/1"}', '[]') as $text)
	{
		eq('key_table', reason_of(function() use ($text) { phoromatic_catalog_keys::from_json($text); }), $text);
	}
});

require_once PTS_PATH . 'pts-core/objects/phoromatic/phoromatic_catalog_web.php';

t('the catalog page\'s "Signed by" cell does not call generation 1 old just because the shipped table also holds the standby generation 2', function() use ($prod)
{
	$k = phoromatic_catalog_keys::from_json($prod);
	eq(2, count($k->all_keys()));
	eq('key generation 1', phoromatic_catalog_web::signed_by(1, 0));
	eq('key generation 1', phoromatic_catalog_web::signed_by(1, 1));
	eq('key generation 2', phoromatic_catalog_web::signed_by(2, 1));
	eq('key generation 1 (retired)', phoromatic_catalog_web::signed_by(1, 2));
});
