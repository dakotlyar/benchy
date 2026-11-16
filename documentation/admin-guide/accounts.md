# Look after the accounts on a Phoromatic server

A Phoromatic server holds one or more accounts. Each account is a separate workspace with its own machines, schedules, results and private test repository, and nothing in one can be seen from another. This page is for the people who run the server: the server administrators. It shows how to make, rename, suspend, delete and purge accounts in the server console, how people come to belong to several accounts, how to turn a user off or set a new password, and how to read the audit. It covers **Benchy 0.9.14**. It assumes the server is already running; if it is not, start with [Set up a Phoromatic server on a new Ubuntu 26.04 machine](new-server.md).

Before 0.9.14 most of this was done on the command line, or by root on the **Admin** page, or not at all. Now a server administrator does it in the browser, and the same actions have commands for working on the server itself.

## The Server link

A server administrator has a **Server** link in the menu at the top of every page. (A server administrator is the root login, or a user who has been given the role; [Who can change server-wide settings](new-server.md#who-can-change-server-wide-settings) says how.) The link opens the server console, which has four pages. A row of links at the top of each one moves between them.

| Page | Address | What it is for |
|---|---|---|
| Accounts | `?server_tenants` | The list of accounts, and creating, renaming and changing the state of each. |
| Users | `?server_users` | Everyone who can sign in to the server: turning a user off or on, and setting a new password. |
| Server settings | `?server_settings` | The options that cover every account. See [Who can change server-wide settings](new-server.md#who-can-change-server-wide-settings). For root the link goes to **Admin Config**. |
| Server audit | `?server_audit` | A page you can only read: everything done to the server and its accounts. |

Anyone else who types one of these addresses is told that the page is for server administrators, and nothing they send changes anything. Every button in the console sends the page's security token, so a link on another site cannot press it for you.

## Accounts and their states

An account is always in one of four states.

| State | What it means for the people in it |
|---|---|
| Active | Normal. |
| Suspended | Its users cannot sign in, its machines and native agents are turned away, and its results feed, triggers and schedules do nothing. Nothing is deleted, and resuming puts everything back. |
| Deleted | Like suspended, and the account is out of the default list. All its data is still on disk, and you can restore it. |
| Purged | Gone for good. Its rows and files are removed, and the account ID is never used again. |

The states move in one direction at a time. An active account is suspended first, and only a suspended account can be deleted. A deleted account can be restored, and it comes back suspended, so you look at it before you resume it. Only a deleted account can be purged. You will see these rules as messages if you try to skip a step, for example "The account is active, and only an account that is suspended can be deleted; nothing was changed."

The list on the **Accounts** page shows active and suspended accounts. The links above it (**Active**, **Suspended**, **Deleted**, **Purged**, **All**) show the others. Each row has the account's name, ID, state, number of users and systems, and when it was made. Click a name to open the account's own page.

### Make an account

Choose **Create an account**. Give it a name, the first user's name, password and email address, and press **Create account**. The first user is the account's Group Administrator. The optional **Account ID** field is for bringing back an account whose ID clients already hold: six letters or digits, and if it is taken the account gets a random one. The same user rules apply as for `server-account-create` (see [User names and levels](new-server.md#user-names-and-levels)).

Account names can be up to 64 characters, in any language, with no control characters. No two accounts share a name. Names are compared without regard to case, and a deleted or purged account keeps its name, so it cannot be used again either. An account made without a name, for example by an older version, is shown as "Account" and its ID.

### Rename

Open the account and use the **Rename** box. Only server administrators can rename an account. The Group Administrators of the account see its name on their **Users** page but cannot change it.

### Suspend and resume

Open the account and choose **Suspend this account**. You can give a reason, which is kept with the audit entry. If some of the account's machines are running a task at that moment, the page says how many and asks you to tick a box before it goes ahead: a run that finishes while the account is suspended cannot upload its result, though the machine keeps its own copy. You cannot suspend or delete the account you are signed in to, because that would end your own session. Ask another server administrator, or use the commands on the server.

**Resume account** puts the account back as it was.

### Delete and restore

A suspended account has a **Delete this account** link. The page shows how many users, systems, schedules and results the account has and asks you to type its name. Deleting keeps all the data on disk. **Restore account** on a deleted account brings it back as suspended.

### Purge

Purge is the one step that cannot be undone. It removes the account's systems, schedules, results, private repository and every other row and file it owns. Its users stay on the server but belong to no account, until a server administrator adds them to one. The audit keeps the record that the account was purged, and the account ID is never used again.

A purge is only ever done by hand, and only on a deleted account. The page asks you to type both the account's name and its ID, exactly. Nothing purges an account on a timer, and nothing deletes one for you. If the account's folder cannot be removed completely, the account is purged anyway, the page names the folder that was left, and you remove it by hand; the audit has a note about it. Once an hour the server also looks for the leftovers of a purge that was interrupted (a restart in the middle, say) and finishes the job. That sweep acts only on accounts that were purged. If a user held the server administrator role and the purge leaves them with no account at all, the role is removed with it, so that it does not follow them into an account they are added to later.

### The same on the command line

Each action has a command, which you run on the server as the service user, like the other server commands (see [Set up a Phoromatic server](new-server.md)). `ACCOUNT` is an account ID or the account's name.

| Command | What it does |
|---|---|
| `server-tenant-list [--all]` | Lists accounts with ID, state and name. `--all` includes deleted and purged ones. |
| `server-tenant-rename ACCOUNT --name NEW-NAME` | Renames an account. |
| `server-tenant-suspend ACCOUNT [--reason TEXT] [--even-if-running]` | Suspends an account. Without `--even-if-running` it refuses while a machine of the account is running a task. |
| `server-tenant-resume ACCOUNT` | Resumes a suspended account. |
| `server-tenant-delete ACCOUNT --confirm-name NAME [--reason TEXT]` | Deletes a suspended account. |
| `server-tenant-restore ACCOUNT` | Restores a deleted account; it comes back suspended. |
| `server-tenant-purge ACCOUNT --confirm-name NAME --confirm-id ACCOUNT-ID` | Purges a deleted account. |
| `server-account-create --user NAME --email ADDRESS [--name ACCOUNT-NAME] [--account-id ID]` | Makes an account and its first user. `--name` is new in 0.9.14. |

For example, taking an account from suspended to purged:

    sudo -u phoromatic env HOME=/var/lib/phoromatic benchy server-tenant-delete AAA111 --confirm-name "Lab B"
    sudo -u phoromatic env HOME=/var/lib/phoromatic benchy server-tenant-purge AAA111 --confirm-name "Lab B" --confirm-id AAA111

Each prints `Done: account AAA111 purged. The change is in the server audit log.` when it works, and `ERROR: ...` followed by the reason and "nothing was changed" when it does not. When an account name is given as ACCOUNT and two accounts could match it, the command stops and asks for the ID.

## Members: one login, several accounts

Since 0.9.14 a person's login and their place in an account are separate. A login can belong to several accounts, at a different level in each: a Group Administrator in one, a Viewer in another. The server keeps this as the person's memberships. Everyone who existed before the upgrade is a member of the account they had, at the level they had, so nothing changes until somebody adds a second membership.

A membership is made in one of two ways:

- A server administrator adds an existing user to an account. Open the account on the **Accounts** page, find **Add an existing user**, type the user name, pick a level and press **Add to account**. The user keeps their own sign-in and password.
- A server administrator joins an account themselves (below).

An account's own administrators can add brand-new users to the account on its **Users** page, but they cannot add a login that already belongs to another account. Only a server administrator can do that.

On the account's page in the console, the **Members** list shows each member's level, a menu to change it, **Remove from account**, and when they last used the account. A member who comes from another account is marked "(from another account)", and a user the server has turned off is marked "(turned off on the server)". Removing a member ends that one membership. The person, their login and their other accounts stay. The account's own **Users** page shows the same list to its Group Administrators, with a "Last used" line for each member. A Group Administrator can change the level of the other members and remove a member who came from another account. They cannot change or remove another Group Administrator, and they cannot remove a person whose own account this is: the page says "This is ...'s own account, so a server administrator has to take them out of it". Those changes are for a server administrator. The level **Disabled** on that page turns the person off in this account only; **Turned off** on the **Users** page of the console turns them off everywhere.

People who belong to two or more accounts get an account switcher in the header; see the [user guide](../user-guide/phoromatic-server.md#working-in-more-than-one-account). At sign-in the server opens the account the person used last, or else their own, or else the first working one by name.

### Join and leave an account

A server administrator who needs to look at an account's systems or results has to be a member of it. Open the account on the **Accounts** page, pick a level under **See this account's data** (**Viewer** is the cautious choice) and press **Join this account**. The account then appears in your account switcher. Joining is recorded in the server audit and in the account's own audit, and the account's administrators see you in their list of users, marked as a member from another account. When you are done, press **Leave this account** on the same page; leaving is recorded in both audits too. Root is not a user and cannot join; it manages accounts from the console without opening them. You can join only an active account, and you cannot leave your only working account.

## Users on the whole server

The **Users** page of the console lists every login on the server: the user's own account, every account they belong to and at which level (with the account's state if it is not active), their last sign-in, and whether they are on or off. Two things are done here.

**Turn off or on.** **Turn off** stops the person signing in to any account, and ends a session they already have at its next click. **Turn on** reverses it. Nothing else about them is removed. You cannot turn yourself off.

**Set a new password.** Type the new password twice and press **Set password**. It has to be at least six characters. The old password stops working at once and the person signs in with the new one. This is what root's **Override User Password** form on the **Admin** page used to do, and what root's **Disable User** form did for turning off. Both forms are gone from the **Admin** page; the page now says where to go.

<!-- T6: confirm -->
Since 0.9.14, when a password is set here the server also keeps a modern hash of it; see [Upgrading to 0.9.14](new-server.md#upgrading-to-0914-accounts-memberships-and-the-server-console).

A server administrator's own password is changed on their own **Password** page, as before. Root's password is still set on the server with `benchy phoromatic.set-root-admin-password`, which asks for the new password and needs at least six characters.

## The last Group Administrator

No account can lose its last Group Administrator. If the person is the only working Group Administrator of an account, the server refuses to lower their level, remove them from the account, turn them off, or let them leave, and says so:

    bobby is the last Group Administrator of Lab B2; make someone else a Group Administrator first. Nothing was changed.

Make another member a Group Administrator first, and then change the first one. A user who has been turned off on the server does not count as a working Group Administrator. The rule is checked again when a user is removed from an account or the server turns them off, so it holds from every page, not only from one.

## The audit pages

Changes that affect the server as a whole go in the server audit, which no account can see or alter. Since 0.9.14 it has a page, **Server audit** in the console, with the newest row first, 50 to a page. Each row has the time, who did it (and from which address, for a person at the browser), the action, the account, what it was about and the details. Rows cannot be changed or removed. The same rows are also copied to `phoromatic-server-audit.log` beside the server log, as before.

The actions you will see for accounts and users are `tenant_created`, `tenant_renamed`, `tenant_suspended`, `tenant_resumed`, `tenant_deleted`, `tenant_restored` and `tenant_purged`; `member_added`, `member_level_changed` and `member_removed`; `server_admin_joined_tenant` and `server_admin_left_tenant`; and `user_disabled`, `user_enabled` and `user_password_reset`. The ones that concern a particular account (members, joining and leaving, a user turned off, a password set) are also written to that account's own audit, so its administrators' side of the story is complete. A person who belongs to several accounts has the row in each of them. An account's own audit has no page. It is the `phoromatic_admin_audit` table and `phoromatic-admin-audit.log`, as described in [The server audit](new-server.md#the-server-audit). As before, a change and its audit row are one step: if the row cannot be written, the change is not made and the page says so.

## What changed in this upgrade: the one-time notice

A server that was already running 0.9.11, 0.9.12 or 0.9.13 shows a box on the main page, **What changed in this upgrade**, to people who had a login before the server first started on 0.9.14. It says, in plain words: that accounts are now kept apart, that the public results page is gone and one account cannot see another's results, that feed links changed, that sign-up is closed, that new systems wait for approval, that the HTTPS, OpenBenchmarking.org contact and native agent settings moved to the server settings, and that each account now has its own private test repository that nobody outside the account can see. Server administrators get an extra line pointing to the **Server** pages.

Only Group Administrators and server administrators see it, because they are the people who have to act on it. Each of them presses **Dismiss** once and it does not come back for that person in that account. A server first made on 0.9.14 shows it to nobody, because every login on it is newer than the server's first start. The box is not shown to root, which is not a user.

## When someone loses access to the account they are in

Suspending, deleting or turning off while people are signed in works at their next click. A person who still belongs to another working account is moved to it, and their next page is that account's main page with a note, for example "Lab B is no longer available to you. You are now in Lab A." The request they were making is dropped, not carried across: a form they had open in the account they lost does nothing. A person with nowhere else to go is signed out, and at their next sign-in they are told the account is suspended, or that they are not a member of any account. A user whose level drops takes the new level at once, not at their next sign-in.

## If something goes wrong

| What you see | What it means and what to do |
|---|---|
| "The account is active, and only an account that is suspended can be deleted; nothing was changed." | Accounts are suspended before they are deleted. Suspend it first. |
| "Only a deleted account can be purged. This one is suspended; nothing was changed." | Delete it first. |
| "Type the account's name and its account ID exactly to purge it; nothing was changed." | One of the two typed values is not exactly what the page shows. Names are compared without regard to case and extra spaces; the ID has to match exactly. |
| "You are signed in to this account, and suspending it would end your own session..." | Use another server administrator, or the commands on the server. |
| "N system(s) of this account are running a task now..." | Wait for the runs to finish, or tick the box (`--even-if-running` on the command line) if you do not mind a run losing its upload. |
| "Another account is already called ... " | Names are unique, without regard to case, and deleted accounts keep theirs. Pick another. |
| "... is the last Group Administrator of ...; make someone else a Group Administrator first." | See [The last Group Administrator](#the-last-group-administrator). |
| "You cannot turn yourself off; nothing was changed." | Ask another server administrator. |
| "There is no user named ...; nothing was changed." | The user name in **Add an existing user** does not exist. User names must match exactly. |
| "The form expired or was not submitted from this site; nothing was changed. Please try again." | The page was open too long, or the request came from somewhere else. Reload and try again. |
| "The server is waiting to finish a database upgrade (the reason is in the server log), so this cannot be done yet; nothing was changed." | The upgrade to 0.9.14 has not finished. The server retries every five minutes; see [Upgrade](new-server.md#upgrade). |
| The console's **Members** list says "turned off on the server" for a person who cannot sign in | They were turned off on the **Users** page. Turn them on there. |
