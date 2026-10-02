"""The Systems page's delete: POST + CSRF token + same-origin + AdminLevel 1/2 only; the old GET URL must delete nothing.

Run through tests/private-repo/run.sh (part of http/all).
"""
import re
import unittest
import uuid

from test_private_repo_http import AID, sql
from test_private_repo_tokens import sql_exec
from test_private_repo_web import Browser, WebBase, USER, PASS, make_user


def exists(sid, account=None):
    """A live (not deleted) system record; a deleted system stays as a tombstone row, see test_systems_tombstone.py."""
    return bool(sql("SELECT 1 AS x FROM phoromatic_systems WHERE AccountID='%s' AND SystemID='%s' AND DeletedOn IS NULL" % (account or AID, sid)))


def make_system(state=-1, account=None):
    """A Disabled system by default: only a disabled system can be deleted."""
    account = account or AID
    sid = 'QCSYS' + uuid.uuid4().hex[:8]
    assert sql_exec("INSERT INTO phoromatic_systems (AccountID, SystemID, Title, Description, CurrentTask, CreatedOn, LastCommunication, State) "
                    "VALUES ('%s', '%s', 'qc system', 'd', 'Awaiting Task', '2026-01-01 00:00:00', '2026-01-01 00:00:00', %d)" % (account, sid, state)) == 'ok'
    return sid


class TestSystemDelete(WebBase):
    def test_old_get_url_deletes_nothing(self):
        sid = make_system()
        for path in ('/?systems/%s&really_delete_system' % sid, '/?systems/%s/&really_delete_system=1' % sid, '/?systems/%s/delete' % sid):
            self.admin.get(path)
            self.assertTrue(exists(sid), 'GET %s deleted the system' % path)

    def test_post_without_or_with_wrong_token_is_refused(self):
        sid = make_system()
        url = '/?systems/%s' % sid
        self.admin.post(url, {'really_delete_system': '1'})
        self.admin.post(url, {'really_delete_system': '1', 'token_submit': 'deadbeef'})
        self.assertTrue(exists(sid))

    def test_post_with_wrong_origin_is_refused(self):
        sid = make_system()
        url = '/?systems/%s' % sid
        tok = self.admin.token('/?systems/%s/delete' % sid)
        for hdr in ({'Origin': 'http://evil.example'}, {'Referer': 'http://evil.example/x'}):
            self.admin.post(url, {'really_delete_system': '1', 'token_submit': tok}, headers=hdr)
            self.assertTrue(exists(sid), 'cross-origin POST deleted the system: %s' % hdr)

    def test_lower_levels_cannot_delete_and_do_not_see_the_link(self):
        sid = make_system()
        b = self.as_user(3)
        tok = b.token('/?systems/%s' % sid)
        b.post('/?systems/%s' % sid, {'really_delete_system': '1', 'token_submit': tok})
        self.assertTrue(exists(sid), 'a level 3 user deleted a system')
        self.assertNotIn('/delete"', b.get('/?systems/%s' % sid)[2])

    def test_confirm_page_then_valid_post_deletes(self):
        sid = make_system()
        status, _, body = self.admin.get('/?systems/%s' % sid)
        self.assertIn('?systems/%s/delete' % sid, body)
        status, _, body = self.admin.get('/?systems/%s/delete' % sid)
        self.assertIn('Yes, delete this system', body)
        tok = re.search(r'name="token_submit" value="([0-9a-f]+)"', body).group(1)
        self.assertTrue(exists(sid))
        self.admin.post('/?systems/%s' % sid, {'really_delete_system': '1', 'token_submit': tok})
        self.assertFalse(exists(sid))

    def test_same_origin_header_is_accepted_and_other_systems_untouched(self):
        sid, other = make_system(), make_system()
        tok = self.admin.token('/?systems/%s/delete' % sid)
        host = re.sub(r'^https?://', '', self.admin.base)
        self.admin.post('/?systems/%s' % sid, {'really_delete_system': '1', 'token_submit': tok}, headers={'Origin': 'http://' + host})
        self.assertFalse(exists(sid))
        self.assertTrue(exists(other))

    def test_disable_flow_still_works(self):
        sid = make_system(state=1)
        self.admin.post('/?systems/%s' % sid, {'system_title': 'renamed', 'system_description': 'x', 'system_state': '-1', 'block_power_offs': '0',
                                               'token_submit': self.admin.token('/?systems/%s/edit' % sid)})
        row = sql("SELECT Title, State FROM phoromatic_systems WHERE SystemID='%s'" % sid)[0]
        self.assertEqual((row['Title'], int(row['State'])), ('renamed', -1))

    def test_demoted_user_with_a_stale_session_cannot_delete(self):
        sid = make_system()
        name = 'sysdem' + uuid.uuid4().hex[:6]
        make_user(self.admin, name, 2)
        b = Browser().login(name, 'qcpass123')
        tok = b.token('/?systems/%s/delete' % sid)
        self.assertIn('Yes, delete this system', b.get('/?systems/%s/delete' % sid)[2])
        self.assertEqual(sql_exec("UPDATE phoromatic_users SET AdminLevel=3 WHERE UserName='%s'" % name), 'ok')
        body = b.get('/?systems/%s/delete' % sid)[2]
        self.assertNotIn('Yes, delete this system', body)
        self.assertNotIn('/delete"', b.get('/?systems/%s' % sid)[2])
        b.post('/?systems/%s' % sid, {'really_delete_system': '1', 'token_submit': tok})
        self.assertTrue(exists(sid), 'a demoted user deleted a system through a stale session')

    def test_nonexistent_and_other_account_ids_give_a_clear_message(self):
        other = make_system(account='OTHERACCT')
        tok = self.admin.token('/?systems/%s/delete' % other)
        for sid in ('QCNOSUCH123', other):
            body = self.admin.post('/?systems/%s' % sid, {'really_delete_system': '1', 'token_submit': tok})[2]
            self.assertIn('System not found or already deleted', body)
        self.assertTrue(exists(other, 'OTHERACCT'), "another account's system was deleted")

    def test_double_submit_reports_already_deleted(self):
        sid = make_system()
        tok = self.admin.token('/?systems/%s/delete' % sid)
        self.assertIn('System deleted', self.admin.post('/?systems/%s' % sid, {'really_delete_system': '1', 'token_submit': tok})[2])
        self.assertIn('System not found or already deleted', self.admin.post('/?systems/%s' % sid, {'really_delete_system': '1', 'token_submit': tok})[2])

    def test_confirm_page_escapes_the_title(self):
        sid = make_system()
        sql_exec("UPDATE phoromatic_systems SET Title='<b id=xss>t</b>' WHERE SystemID='%s'" % sid)
        body = self.admin.get('/?systems/%s/delete' % sid)[2]
        self.assertIn('Delete &lt;b id=xss&gt;', body)


if __name__ == '__main__':
    unittest.main()
