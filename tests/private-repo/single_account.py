"""Server-wide settings on the Settings page (phone-home policy, agent API settings, mirrors, HTTPS certificate) and the download cache upload are for
server administrators: the root login, or the level-1 user of a server that has exactly ONE account. The shared harness server gets more accounts as
other tests register them, so the tests of those settings hide the other accounts for their duration (the rows come back afterwards). The same rule
with two accounts is tested in tests/tenancy/."""
from test_private_repo_http import AID
from test_private_repo_tokens import sql_exec


def hide_other_accounts():
    sql_exec("DROP TABLE IF EXISTS qc_accounts_hidden; CREATE TABLE qc_accounts_hidden AS SELECT * FROM phoromatic_accounts WHERE AccountID <> '%s'; "
             "DELETE FROM phoromatic_accounts WHERE AccountID <> '%s'" % (AID, AID))


def restore_other_accounts():
    sql_exec("INSERT OR IGNORE INTO phoromatic_accounts SELECT * FROM qc_accounts_hidden; DROP TABLE IF EXISTS qc_accounts_hidden")
