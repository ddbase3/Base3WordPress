# Base3WordPress

BASE3's host adapter for WordPress. It exposes the current WordPress users,
roles, capabilities, native authority and supporting host services through
BASE3 interfaces. The WordPress host remains the authority for identities,
account changes, and native access decisions.

The module registers `Base3WordPress\Adapter\WordPressUsermanager` as the
`IUsermanager` implementation. Its constructor and DI bindings are unchanged.
The extended framework interface must be installed together with this adapter.

## User directory search

```php
$users = $usermanager->searchUsers('Müller');
$users = $usermanager->searchUsers('max.mueller', 20, 0);
$users = $usermanager->searchUsers('max@example.org', 20, 20);
$users = $usermanager->searchUsers('12345');
```

`searchUsers()` treats the first argument as plain text, **not** SQL or JSON.
It returns a bounded list of identity-only `Base3\Usermanager\User` values.
Names (including WordPress `first_name`/`last_name` metadata), logins, email
addresses and exact numeric IDs are searched case-insensitively, with native
result pagination and stable sorting. Invalid pagination is rejected and blank
input yields no users. The user-directory `list_users` capability remains
required; callers must also independently authorize each HTTP/API use.

The existing `getAllUsers()` and `getUserById()` behavior remains unchanged.
Search does not load every WordPress account or expand roles for each hit.
The implementation uses WordPress `$wpdb` and honors native table names;
multisite results are restricted to the current site's membership metadata.

For architecture and security details see `docs/authorization-wordpress.md`.
For information about processed personal data see `PRIVACY.md`.

## Verification

Run PHP syntax checks on the changed source and test file:

```sh
php -l modules/Base3WordPress/src/Adapter/WordPressUsermanager.php
php -l modules/Base3WordPress/test/Adapter/WordPressUsermanagerSearchTest.php
```

If PHPUnit and the BASE3 autoloader are available, run:

```sh
vendor/bin/phpunit modules/Base3WordPress/test/Adapter/WordPressUsermanagerSearchTest.php
```

The regression test does not define global WordPress classes/functions or
execute tests during PHP file inclusion. It checks the query composition and
parameter handling without requiring a live WordPress installation.
Native SQL execution and user visibility should also be verified in the target
WordPress installation before deployment.
