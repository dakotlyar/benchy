"""CSRF housekeeping on the system page (benches step A10): the System Variables form and "Remove Inactive Systems" need the session's
token, and clearing a system's warnings is a POST with the token (a GET link no longer changes anything).

Run through tests/private-repo/run.sh (part of http/all).
"""
import unittest

from bench_helpers import new_system, system_row, token
from test_private_repo_http import AID, sql
from test_private_repo_tokens import sql_exec
from test_private_repo_web import WebBase


class TestSystemPageCsrf(WebBase):
    def test_system_variables_need_the_token(self):
        msi, sid = new_system()
        fields = {'system_var_names[]': 'QCVAR', 'system_var_values[]': 'one'}
        self.admin.post('/?systems/%s' % sid, fields)
        self.assertIsNone(system_row(sid)['SystemVariables'])
        self.admin.post('/?systems/%s' % sid, dict(fields, token_submit='0' * 40))
        self.assertIsNone(system_row(sid)['SystemVariables'])
        self.assertIn('name="update_system_variables"', self.admin.get('/?systems/%s' % sid)[2])
        self.admin.post('/?systems/%s' % sid, dict(fields, token_submit=token(self.admin)))
        self.assertEqual(system_row(sid)['SystemVariables'], 'QCVAR=one;')

    def test_clear_warnings_is_a_post_with_the_token(self):
        msi, sid = new_system()
        sql_exec("INSERT INTO phoromatic_system_client_errors (AccountID, SystemID, UploadTime, ErrorMessage, TestIdentifier) VALUES ('%s', '%s', datetime('now'), 'boom', 'qc')" % (AID, sid))
        count = lambda: sql("SELECT COUNT(*) AS n FROM phoromatic_system_client_errors WHERE SystemID='%s'" % sid)[0]['n']
        page = self.admin.get('/?systems/%s/&clear_system_warnings' % sid)[2]
        self.assertEqual(count(), 1, 'a GET cleared the warnings')
        self.assertIn('name="clear_system_warnings"', page)
        self.admin.post('/?systems/%s' % sid, {'clear_system_warnings': '1'})
        self.assertEqual(count(), 1)
        self.admin.post('/?systems/%s' % sid, {'clear_system_warnings': '1', 'token_submit': token(self.admin)})
        self.assertEqual(count(), 0)

    def test_remove_inactive_form_carries_the_token(self):
        # The handler now also requires the token. Its effect cannot be observed here: the UPDATE compares julianday() arithmetic with a
        # value bound as TEXT, which SQLite never finds greater, so "Remove Inactive Systems" has never disabled anything (pre-existing,
        # reported, deliberately not changed in this step).
        self.admin.post('/?systems', {'new_group': 'qccsrfgrp', 'token_submit': token(self.admin)})  # the form shows once a group exists
        page = self.admin.get('/?systems')[2]
        form = page[page.index('name="remove_inactive"'):]
        form = form[:form.index('</form>')]
        self.assertIn('name="token_submit"', form)


if __name__ == '__main__':
    unittest.main()
