<?php
// Categories and tags on the server (Benchy 0.12.0, engine spec/categories.md): the alias table, the tag grammar, the run-length classes, and the failed-trial block of the result page.
require_once __DIR__ . '/../../pts-core/objects/phoromatic/phoromatic_native_taxonomy.php';
require_once __DIR__ . '/../../pts-core/objects/phoromatic/phoromatic_native_result.php';

t('categories: a key stays, a PTS name goes through the alias table in any case, anything else is other', function() {
	eq('cpu', phoromatic_native_taxonomy::category_key('cpu'));
	eq('cpu', phoromatic_native_taxonomy::category_key('Processor'));
	eq('cpu', phoromatic_native_taxonomy::category_key('PROCESSOR'));
	eq('storage', phoromatic_native_taxonomy::category_key('Disk'));
	eq('system', phoromatic_native_taxonomy::category_key('System'));
	eq('application', phoromatic_native_taxonomy::category_key('Application'));
	eq('other', phoromatic_native_taxonomy::category_key('Other'));
	eq('other', phoromatic_native_taxonomy::category_key(''));
	eq('other', phoromatic_native_taxonomy::category_key(null));
	eq('other', phoromatic_native_taxonomy::category_key(array('cpu')));
	eq('Other', phoromatic_native_taxonomy::category_label('other'));
	eq('Whole system', phoromatic_native_taxonomy::category_label('system'));
});
t('tags: the grammar, no repeats, six at most, plain strings only; a list that is not a list is empty', function() {
	eq(array('compression', 'multi-thread'), phoromatic_native_taxonomy::clean_tags(array('compression', 'multi-thread')));
	eq(array('a'), phoromatic_native_taxonomy::clean_tags(array('a', 'a', 'A', '-a', 'a-', 'a--b', '', 5, null, array('x'), '<b>', str_repeat('a', 33))));
	eq(array('a1', 'b-2'), phoromatic_native_taxonomy::clean_tags(array('a1', 'b-2')));
	eq(6, count(phoromatic_native_taxonomy::clean_tags(array('a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'))));
	eq(str_repeat('a', 32), phoromatic_native_taxonomy::clean_tags(array(str_repeat('a', 32)))[0]);
	eq(array(), phoromatic_native_taxonomy::clean_tags('compression'));
	eq(array(), phoromatic_native_taxonomy::clean_tags(null));
	eq('Compression', phoromatic_native_taxonomy::tag_label('compression'));
	eq('some-new-tag', phoromatic_native_taxonomy::tag_label('some-new-tag'));
});
t('run length: short to 300 s, medium to 1200 s, long above, nothing when there is no estimate', function() {
	eq('short', phoromatic_native_taxonomy::length_class(1));
	eq('short', phoromatic_native_taxonomy::length_class(300));
	eq('medium', phoromatic_native_taxonomy::length_class(301));
	eq('medium', phoromatic_native_taxonomy::length_class(1200));
	eq('long', phoromatic_native_taxonomy::length_class(1201));
	eq('', phoromatic_native_taxonomy::length_class(null));
	eq('', phoromatic_native_taxonomy::length_class(0));
	eq('', phoromatic_native_taxonomy::length_class('300'));
	eq('', phoromatic_native_taxonomy::length_class(-5));
});
t('failed trials: the block reads the stored events, keeps the end of a trial\'s output, escapes it and names the log on the machine', function() {
	$file = sys_get_temp_dir() . '/benchy-ft-' . getmypid() . '.jsonl';
	$ref = array('ref' => 'benchy/x', 'version' => '1.0.0');
	$ev = array(
		array('type' => 'test_start', 'test' => $ref, 'summary' => 'Size: big', 'index' => 1, 'total' => 1),
		array('type' => 'trial_start', 'test' => $ref, 'trial' => 1, 'of' => 2),
		array('type' => 'test_output', 'test' => $ref, 'text' => 'first line'),
		array('type' => 'test_output', 'test' => $ref, 'text' => "<script>alert(1)</script>\x07\n"),
		array('type' => 'trial_log', 'test' => $ref, 'trial' => 1, 'path' => '/home/u/.benchy/logs/r/1-x/trial-1.log'),
		array('type' => 'trial_end', 'test' => $ref, 'trial' => 1, 'of' => 2, 'value' => null, 'ok' => false),
		array('type' => 'trial_start', 'test' => $ref, 'trial' => 2, 'of' => 2),
		array('type' => 'trial_end', 'test' => $ref, 'trial' => 2, 'of' => 2, 'value' => 5, 'ok' => true),
		array('type' => 'test_result', 'test' => $ref, 'status' => 'failed', 'error' => array('message' => 'The run script failed. Full output: /home/u/.benchy/logs/r/1-x/trial-1.log')));
	file_put_contents($file, implode("\n", array_map('json_encode', $ev)) . "\n");
	$f = phoromatic_native_result::failed_trials($file);
	eq(1, count($f));
	eq('Size: big', $f[0]['summary']);
	eq(1, $f[0]['trial']);
	eq("first line\n<script>alert(1)</script>\n", $f[0]['output']);
	ok(strpos($f[0]['message'], 'The run script failed.') === 0, 'the test\'s failure message is attached');
	$html = phoromatic_native_result::failed_trials_html($file);
	ok(strpos($html, '<script>') === false && strpos($html, '&lt;script&gt;alert(1)&lt;/script&gt;') !== false, 'the output is escaped');
	ok(strpos($html, '<code>/home/u/.benchy/logs/r/1-x/trial-1.log</code>') !== false && strpos($html, 'benchy/x (Size: big), trial 1 of 2') !== false, 'the machine\'s log path and the trial are named');
	// a long output keeps its end
	$long = array(array('type' => 'trial_start', 'test' => $ref, 'trial' => 1, 'of' => 1));
	for($i = 0; $i < 3000; $i++)
	{
		$long[] = array('type' => 'test_output', 'test' => $ref, 'text' => sprintf('line %05d %s', $i, str_repeat('x', 40)));
	}
	$long[] = array('type' => 'trial_end', 'test' => $ref, 'trial' => 1, 'of' => 1, 'value' => null, 'ok' => false);
	file_put_contents($file, implode("\n", array_map('json_encode', $long)) . "\n");
	$f = phoromatic_native_result::failed_trials($file, 65536);
	ok(strlen($f[0]['output']) <= 65536 && strpos($f[0]['output'], 'line 02999') !== false && strpos($f[0]['output'], 'line 00000') === false, 'the tail is kept');
	// an agent that sent no output: said plainly; no events file: nothing
	file_put_contents($file, json_encode(array('type' => 'trial_end', 'test' => $ref, 'trial' => 1, 'of' => 1, 'value' => null, 'ok' => false)) . "\n");
	ok(strpos(phoromatic_native_result::failed_trials_html($file), 'did not receive the trial') !== false, 'no output is said');
	unlink($file);
	eq('', phoromatic_native_result::failed_trials_html($file));
});
