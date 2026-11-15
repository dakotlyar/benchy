<?php
// Catalog spec 13, "recovery and rotation" and "stored indexes", at the rules below the signature (evaluate_index) and at the retired check.

const MAXSEQ = 9007199254740991;

function rec_reason($raw, $state, $gen) { return reason_of(function() use ($raw, $state, $gen) { ev($raw, $state, $gen); }); }

t('a generation-2 recovery index is accepted after a generation-1 index at sequence 2^53-1, and starts at 1', function()
{
	$state = state_of(array('public' => tier_state(1, MAXSEQ, array('benchy/a@1.0.0' => sha('b')))));
	eq('rollback', rec_reason(index_json(array('sequence' => 1)), $state, 1), 'a thief\'s key stays refused');
	$d = ev(index_json(array('sequence' => 1, 'min_generation' => 2)), $state, 2);
	ok($d['recovery'], 'it is a recovery');
	eq(array(2, 1), array($d['next']['tiers']['public']['generation'], $d['next']['tiers']['public']['sequence']));
	eq(2, $d['next']['retired_below'], 'it retires generation 1');
});

t('a recovery index is accepted after a poisoned ledger, and the poisoned row is replaced', function()
{
	$state = state_of(array('public' => tier_state(1, 4, array('benchy/a@1.0.0' => sha('6'), 'benchy/thief@9.9.9' => sha('7')), array('benchy/s@1.0.0' => sha('8')))));
	eq('ledger_conflict', rec_reason(index_json(array('sequence' => 5)), $state, 1), 'without recovery the poisoned row refuses the real tree');
	$d = ev(index_json(array('sequence' => 1, 'min_generation' => 2, 'tests' => array(test_entry('benchy/a', '1.0.0', sha('b'))))), $state, 2);
	ok($d['recovery'], 'recovery');
	eq(array('benchy/a@1.0.0' => sha('b')), $d['next']['tiers']['public']['tests'], 'the poisoned row is replaced and the thief\'s entry is gone');
	eq(array(), $d['next']['tiers']['public']['suites'], 'and so are the suites');
});

t('a recovery index clears a thief\'s revocation; a routine index would have to keep it', function()
{
	$state = state_of(array('public' => tier_state(1, 4, array(), array(), array('benchy/a@1.0.0'))));
	eq('revoked_listed', rec_reason(index_json(array('sequence' => 5, 'tests' => array(test_entry('benchy/a')), 'revoked' => array(revoked_entry('benchy/a', '1.0.0')))), $state, 1), 'listed and revoked together');
	eq('revocation_dropped', rec_reason(index_json(array('sequence' => 5, 'tests' => array(test_entry('benchy/b')))), $state, 1), 'dropping it is refused');
	$d = ev(index_json(array('sequence' => 1, 'min_generation' => 2, 'tests' => array(test_entry('benchy/a')))), $state, 2);
	ok(isset($d['next']['tiers']['public']['tests']['benchy/a@1.0.0']), 'the revoked version may be listed again by the recovery index');
	eq(array(), $d['next']['tiers']['public']['revoked'], 'the thief\'s revocation is gone');
	$d = ev(index_json(array('sequence' => 1, 'min_generation' => 2, 'revoked' => array(revoked_entry('benchy/bad', '1.0.0')))), $state, 2);
	eq(array('benchy/bad@1.0.0'), $d['next']['tiers']['public']['revoked'], 'a recovery index replaces the revocations with its own');
});

t('a recovery-shaped index on a tier already at that generation continues its sequence and goes through the ledger', function()
{
	$state = state_of(array('public' => tier_state(2, 5, array('benchy/a@1.0.0' => sha('b')), array(), array('benchy/old@0.1.0'))), 2);
	$raw = function($seq, $tree, $revoked) { return index_json(array('sequence' => $seq, 'min_generation' => 2, 'tests' => array(test_entry('benchy/a', '1.0.0', $tree)), 'revoked' => $revoked)); };
	eq('rollback', rec_reason($raw(1, sha('b'), array(revoked_entry('benchy/old', '0.1.0'))), $state, 2), 'it does not start again at 1');
	eq('ledger_conflict', rec_reason($raw(6, sha('c'), array(revoked_entry('benchy/old', '0.1.0'))), $state, 2), 'the ledger applies');
	eq('revocation_dropped', rec_reason($raw(6, sha('b'), array()), $state, 2), 'so do the revocations');
	$d = ev($raw(6, sha('b'), array(revoked_entry('benchy/old', '0.1.0'))), $state, 2);
	ok(!$d['recovery'], 'not treated as a recovery');
	eq(6, $d['next']['tiers']['public']['sequence']);
});

t('a routine rotation keeps revocations and the ledger, and a second-phase index is not a recovery', function()
{
	$p1 = state_of(array('public' => tier_state(1, 7, array('benchy/a@1.0.0' => sha('b')), array(), array('benchy/old@0.1.0')), 'private' => tier_state(1, 3, array('benchy/p@1.0.0' => sha('d')))));
	// first phase: the public tier is re-signed by generation 2 with min_generation null
	$d = ev(index_json(array('sequence' => 1, 'revoked' => array(revoked_entry('benchy/old', '0.1.0')))), $p1, 2);
	ok(!$d['recovery'], 'the first phase is not a recovery');
	eq(array(2, 1), array($d['next']['tiers']['public']['generation'], $d['next']['tiers']['public']['sequence']));
	eq(array('benchy/old@0.1.0'), $d['next']['tiers']['public']['revoked'], 'the revocation is kept');
	eq(0, $d['next']['retired_below'], 'nothing is retired yet');
	eq($p1['tiers']['private'], $d['next']['tiers']['private'], 'the other tier is untouched by a planned generation bump of one');
	// second phase, on the same generation: min_generation is set, and the ledger still applies
	$p2 = $d['next'];
	eq('ledger_conflict', rec_reason(index_json(array('sequence' => 2, 'min_generation' => 2, 'tests' => array(test_entry('benchy/a', '1.0.0', sha('c'))), 'revoked' => array(revoked_entry('benchy/old', '0.1.0')))), $p2, 2));
	$d2 = ev(index_json(array('sequence' => 2, 'min_generation' => 2, 'revoked' => array(revoked_entry('benchy/old', '0.1.0')))), $p2, 2);
	ok(!$d2['recovery'], 'a second-phase index is not a recovery');
	eq(2, $d2['next']['retired_below'], 'it retires generation 1');
	eq(array('benchy/a@1.0.0' => sha('b')), $d2['next']['tiers']['public']['tests'], 'and carries the ledger');
	// a machine that missed the first phase treats it as one, which is harmless
	$d3 = ev(index_json(array('sequence' => 2, 'min_generation' => 2, 'revoked' => array(revoked_entry('benchy/old', '0.1.0')))), $p1, 2);
	ok($d3['recovery'], 'a machine still on generation 1 sees a recovery-shaped index');
});

t('after the second phase a retired key signing the other tier is refused, including a tier the machine never held', function()
{
	$good = fixture_file('index-good.signed'); // signed by a generation-1 key, for the public tier
	$held = state_of(array('private' => tier_state(2, 4)), 2);
	eq('retired_key', reason_of(function() use ($good, $held) { phoromatic_catalog_verify::evaluate(fixture_trust(), $held, '', $good); }), 'a tier that moved on');
	$never = state_of(array(), 2);
	eq('retired_key', reason_of(function() use ($good, $never) { phoromatic_catalog_verify::evaluate(fixture_trust(), $never, '', $good); }), 'a machine that never held the tier');
	eq('accepted', reason_of(function() use ($good) { phoromatic_catalog_verify::evaluate(fixture_trust(), state_of(array(), 1), '', $good); }), 'but not before the second phase');
});

t('retired_below only rises: a signed min_generation raises it, a lower or null one leaves it', function()
{
	$state = state_of(array('public' => tier_state(3, 1)), 2);
	eq(2, ev(index_json(array('sequence' => 2)), $state, 3)['next']['retired_below'], 'null leaves it');
	eq(2, ev(index_json(array('sequence' => 2, 'min_generation' => 1)), $state, 3)['next']['retired_below'], 'a lower one leaves it');
	eq(3, ev(index_json(array('sequence' => 2, 'min_generation' => 3)), $state, 3)['next']['retired_below'], 'a higher one raises it');
});

t('stored indexes: the same file is still accepted when its key is gone from the table or the floor has been raised; a denied key refuses it', function()
{
	$good = fixture_file('index-good.signed');
	$state = state_of(array('public' => tier_state(1, 1, array(), array(), array(), hash('sha256', $good))));
	$gone = new phoromatic_catalog_keys(array(array('generation' => 2, 'spki' => OTHER_P256_SPKI)), array(), array('public' => array('generation' => 2, 'sequence' => 1)));
	ok(phoromatic_catalog_verify::evaluate($gone, $state, '', $good)['unchanged'], 'key dropped and floor raised');
	eq('denied_key', reason_of(function() use ($good, $state) { phoromatic_catalog_verify::evaluate(fixture_trust(array(), array('d98e6e86a8584efd075cc0fc8155f980')), $state, '', $good); }));
});

t('a state that holds only the other tier does not make this tier\'s first import a rollback', function()
{
	$state = state_of(array('private' => tier_state(1, 50, array('benchy/p@1.0.0' => sha('d')))));
	$d = ev(index_json(array('sequence' => 1)), $state, 1);
	ok(isset($d['next']['tiers']['public']) && isset($d['next']['tiers']['private']), 'both tiers are in the next state');
});
