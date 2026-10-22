# Base3WordPress

BASE3's host integration for WordPress. Its `WordPressUsermanager` implements
`Base3\Usermanager\Api\IUsermanager` using WordPress-native identities, site
roles and capability metadata. The WordPress host remains responsible for
account management and authorization decisions. The plugin registers the
adapter under `IUsermanager` without changing its constructor or DI bindings.

## Current identity and membership

`getUser()` and `getUserById()` return detailed identities with native WordPress
role slugs, role metadata, language and the BASE3 compatibility `role` field.
Native role slugs are stable `Role::$id` and `Role::$name` values. The
compatibility `admin` marker is based on the host's `manage_options` capability;
it is **not** an extra native WordPress role. `hasRole()` checks only assigned
WordPress roles, using their ID or slug, and never authorizes an operation.

`getRoles()` lists the current user's native roles; `getAllRoles()` lists the
site's registered roles. Each `Role::$permissions` entry is a BASE3 `Permission`
DTO for an enabled native capability, not a string. `getPermissions()` lists the
current user's enabled WordPress capabilities, excluding the role-name markers
stored in native `allcaps`. Administrators additionally receive descriptive
`system/admin` metadata. `getAllPermissions()` lists the administrator-visible
native capability catalog, including capability definitions with an explicit
false grant, and the BASE3 `system/admin` catalog entry. Neither method makes
a resource-specific authorization decision. Call `IAuthorizationService` for
all actual access checks.

`getGroups()` evaluates code-owned and editable BASE3 groups through the shared
`UserCriterionEvaluator`. Only valid explicit editable membership prevents the
configured nonprivileged default-group assignment. A fallback can never grant
`administration` or `development`. `getAllGroups()` exposes the same code-owned
and managed BASE3 group catalog. BASE3 groups do not modify WordPress roles.

## Native user and role management

The adapter uses WordPress core to create accounts and to verify the current
user's old password before a password change. These calls remain subject to
WordPress's `create_users` permission and password handling.

Native role assignment and revocation require a valid positive user ID, a
registered role, WordPress's `promote_user` capability for the target and the
host's `editable_roles` filter. Revoking the current actor's own role is
rejected to prevent this API from removing their own administrative access.
Role capability additions/removals require native `manage_options`, a native
WordPress permission scope and an editable existing role. They use WordPress's
persistent role-capability API. These operations do **not** edit per-resource
grants. WordPress core has no native group-to-role inheritance contract, so
`assignRoleToGroup()` and `revokeRoleFromGroup()` explicitly return `false`.
The separate common authorization-administration interface continues to
advertise read-only native grant replacement, since WordPress does not provide
atomic per-resource role-grant replacement.

## User directory and search

`getAllUsers()` requires native `list_users` and enumerates the current site's
native users with identity-only results and deterministic display-name ordering.
It requests just ID, login, display name and email through `get_users()` and
reads each user's locale. It does not expand role or capability metadata for
every directory entry. Use `getUserById()` for a detailed native identity.

```php
$users = $usermanager->searchUsers('Mueller');
$users = $usermanager->searchUsers('max.mueller', 20, 0);
$users = $usermanager->searchUsers('max@example.org', 20, 20);
$users = $usermanager->searchUsers('12345');
```

`searchUsers()` treats its query as literal, case-insensitive text, not SQL or
JSON. Login, display name, email, first name, last name, full first/last name
and exact numeric IDs are matched. The query uses one prepared, paginated
native SQL statement against the installed WordPress tables, with site
membership filtering in multisite installations. It returns identity-only
`User` objects, in display-name and native-ID order. A blank search returns
no results; invalid limits or offsets throw `InvalidArgumentException` and
native query failures throw `RuntimeException`.

`list_users` is required for both directory operations. An HTTP/API caller
must independently perform the proper `IAuthorizationService` check and protect
its endpoint before exposing personal data. Search terms and results are not
persisted or logged by the adapter itself.

For security and architecture see `docs/authorization-wordpress.md`; for
personal-data handling see `PRIVACY.md` and the bridge root `PRIVACY.md`.

## Verification

From the WordPress plugin root, with the framework modules installed:

```sh
php -l modules/Base3WordPress/src/Adapter/WordPressUsermanager.php
php -l modules/Base3WordPress/test/Adapter/WordPressUsermanagerSmokeTest.php
php -l modules/Base3WordPress/test/Adapter/WordPressUsermanagerSearchTest.php
php modules/Base3WordPress/test/Adapter/WordPressUsermanagerSmokeTest.php
```

The isolated smoke test supplies WordPress fakes and exercises identity,
metadata, directory reads, native role/capability mutations and denied paths.
The separate PHPUnit test checks prepared user-search SQL without needing an
installed WordPress site:

```sh
vendor/bin/phpunit modules/Base3WordPress/test/Adapter/WordPressUsermanagerSearchTest.php
```

These tests cannot replace an integration run in WordPress, especially for
multisite role editing, database permissions, filters and user visibility.
