"""The protocol-v2 copy and its PIN (E4.4 C3, server design 11): static, no server needed.

  * PIN names the engine commit the copy came from, and lists every local change as a `local-field:` line;
  * each local field is in v2.schema.json, and the two capability words are named where the schema describes capabilities;
  * the fields of the engine amendment's 4.2 table that this server reads are all either local fields or already in the pinned schema, so a
    field added to one and not the other shows here (the table below is copied from benchy-engine spec/elevated-scheduled.md 4.2, rev 5);
  * the catalog fields (hello_response.catalog, job_test.version) and the two catalog error codes are in the copy;
"""
import json
import os
import re
import unittest

STATIC = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', 'pts-core', 'static', 'protocol-v2')

# 4.2 of the amendment, protocol v2 rows the server uses (message, field): C3's four and C4's end_request.deferred_kind
AMENDMENT_ROWS = {('hello_request.engine', 'not_set_up_reason'), ('catalogue_test', 'admin'), ('poll_request', 'ready_for_approval'), ('poll_response', 'hold_detail'), ('end_request', 'deferred_kind')}
CAPABILITY_WORDS = ('elevation_unattended', 'elevation_desktop')


def pin():
    with open(os.path.join(STATIC, 'PIN')) as f:
        return f.read()


def schema():
    with open(os.path.join(STATIC, 'v2.schema.json')) as f:
        return json.load(f)


def walk(node, path):
    for key in path.split('.'):
        node = node[key]
    return node


class TestPin(unittest.TestCase):
    def test_pin_names_the_engine_tag_and_its_commit(self):
        text = pin()
        self.assertRegex(text, r'^Copied from the benchy-engine repository, tag v0\.6\.0 \(commit [0-9a-f]{40}\)')
        self.assertNotIn('TODO', text)

    def test_every_local_field_in_pin_is_in_the_schema(self):
        fields = re.findall(r'^local-field: (\S+) (\S+)$', pin(), re.M)
        self.assertEqual(len(fields), 5, fields)
        doc = schema()
        for file, path in fields:
            self.assertEqual(file, 'v2.schema.json')
            self.assertTrue(walk(doc, path).get('description', '').startswith('Local change'), path)

    def test_the_local_fields_are_the_amendment_rows_and_no_others(self):
        local = {tuple(re.sub(r'^\$defs\.|\.properties', '', p).rsplit('.', 1)) for _, p in re.findall(r'^local-field: (\S+) (\S+)$', pin(), re.M)}
        self.assertEqual({('hello_request.engine' if a == 'engine' else a, b) for a, b in local}, AMENDMENT_ROWS)

    def test_the_capability_words_are_named_in_pin_and_in_the_schema(self):
        description = schema()['$defs']['engine']['properties']['capabilities']['description']
        for word in CAPABILITY_WORDS + ('catalog_v1',):
            self.assertIn(word, description)
        for word in CAPABILITY_WORDS:
            self.assertIn(word, pin())

    def test_the_catalog_fields_and_codes_the_engine_pins_are_here(self):
        defs = schema()['$defs']
        self.assertIn('catalog', defs['hello_response']['properties'])
        self.assertIn('version', defs['job_test']['properties'])
        for name in ('v2.schema.json', 'result-v1.schema.json'):
            with open(os.path.join(STATIC, name)) as f:
                text = f.read()
            self.assertIn('catalog_refused', text, name)
            self.assertIn('catalog_state_corrupt', text, name)

    def test_the_local_fields_have_the_shapes_of_the_amendment_and_are_all_optional(self):
        defs = schema()['$defs']
        admin = defs['catalogue_test']['properties']['admin']
        self.assertEqual(set(admin['properties']), {'elevated_run', 'machine_install', 'accepted_versions', 'installed'})
        self.assertEqual(defs['poll_request']['properties']['ready_for_approval']['required'], ['schedule_id'])
        self.assertEqual(set(defs['poll_response']['properties']['hold_detail']['properties']), {'kind', 'tests', 'fix'})
        self.assertNotIn('ready_for_approval', defs['poll_request']['required'])
        self.assertNotIn('hold_detail', defs['poll_response']['required'])
        self.assertNotIn('deferred_kind', defs['end_request']['required'])
        self.assertIn('deferred_kind', defs['end_request']['properties'])


if __name__ == '__main__':
    unittest.main()
