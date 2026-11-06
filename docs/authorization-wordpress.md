# BASE3 authorization in WordPress (single-site)

## Authority and composition

The project installs the current shared BASE3 interfaces without changing them.
`Base3WordPressPlugin` registers `IAuthorizationService` as
`WordPressAuthorizationService` with the stable authority `wordpress`.
`WordPressUsermanager` remains responsible for trusted `User` and `Role` values,
not an independent authorization decision. `WordPressAuthorizationService`
handles native `wordpress/<capability>`, BASE3 `system/admin` and discoverable
`feature/<permission>` requests. Unknown scopes, delegated principals, invalid
resources and other authorities fail closed.

The WordPress host's `current_user_can()` supplies native capability decisions.
For object-specific operations BASE3 passes the native ID to WordPress meta-cap
mapping for the supported resource kinds `post`, `user`, `term` and `comment`
with their matching meta-capability names. There is no mirrored WordPress RBAC
table, no independent WordPress object-tree walk and no extra authority path.
Native per-resource query filtering belongs to WordPress query APIs.

`WordPressAuthorizationAdministrationService` implements the common native-rights
administration introspection interface **read-only** for the WordPress global
role/capability catalog, referenced as `ResourceReference('wordpress','site',
'global')`. It returns real `wp_roles()` subjects and grants. WordPress does not
natively support ILIAS-style per-object role grants. A BASE3 replace operation
is rejected instead of simulating atomic capability replacement.

## Code-owned and custom groups

The canonical `Base3Framework` `IGroup` definitions own `anonymous` and
`authenticated`, using their existing authenticated/anonymous criteria. The
WordPress adapter does not redeclare those logical groups. `Base3WordPress`
provides only the WordPress-specific `administration` group, matched by the
native `manage_options` capability; only additional BASE3 membership criteria
can be edited. This guarantees one discovered owner per group name.

The `Base3WordPressLab` **project** plugin owns the `development` group. Its
fixed criteria include native WordPress `manage_options` and optional exact
project developer identities, without moving this policy into the reusable host adapter.
Group discovery uses the existing `IClassMap`. Group names must be unique.

Editable groups can add user IDs, native WordPress role slugs and any discovered
`IUserCriterion`; additions are OR-combined using one `UserCriterionEvaluator`.
Code-owned criteria remain effective and cannot be removed in the UI. Operators
may also create ordinary `Group` DTOs from Groups, with unique technical names.
There is no parallel dynamic PHP class registry. Group definitions and editable
rules are stored together in `ISettingsStore` as `base3wordpress/groups`.
Custom groups can be removed only if no effective feature audience references
or configured fallback reference them. The optional `defaultGroup` is stored in
the same `base3wordpress/groups` dataset and applies only to authenticated
users who have no other matched group beyond `authenticated`. It must name a
current editable, nonprivileged group. `development` and `administration`
cannot be selected as a fallback.

## Native administrator access and optional developer identities

Any authenticated site administrator with native WordPress `manage_options`
automatically satisfies the project-owned `development` group's fixed native
capability criterion. This gives access to **BASE3 > Werkzeuge > BASE3 >
Features** and the Groups editor without additional deployment configuration.
Neither WordPress role labels nor email domains are treated as a grant.

Additional explicit project developers may be configured in `wp-config.php`,
for example:

```php
// Optional: exact trusted WordPress logins.
define('BASE3_WORDPRESS_DEVELOPER_USERNAMES', ['your-actual-wp-login']);
```

`BASE3_WORDPRESS_DEVELOPER_EMAILS` may hold complete verified email addresses
only where the deployment controls address verification and changes. These
allowlists are empty by default. Site-admin membership and exact identities are
combined by the existing `UserCriterionEvaluator`; no separate role system is
introduced. UI changes cannot revoke a fixed native capability or exact
code-owned identity. Revocation follows WordPress role/capability changes or
updates to the optional `wp-config.php` allowlists.

The `IAuthorizationAudienceAdministrationService` still requires BOTH native
`system/admin` and `development`. Only `manage_options` permits protected
Groups/Features writes; additional developer membership does not bypass it.

## Features and user interface

`IFeature` continues to declare stable permission ownership and
`getDefaultAudience()`. The host stores explicit overrides in
`base3_authorization_features/catalog` under `rules` and uses the common
`UserCriterionEvaluator` to test them. An absent override uses the feature's
code-owned default; a saved empty expression denies the feature. Features may
select BASE3 groups, individual WordPress users, native role slugs, or arbitrary
schema-backed criteria, including email-domain matching. Advanced `any`, `all`,
`not` expressions can be edited as JSON without losing existing conditions.
Native WP permissions are not implicitly granted by feature audiences.

The canonical `ClientStack\GlobalChatbotLauncher\GlobalChatbotLauncherFeature`
declares `feature/globalchatbotlauncherfeature` with the `authenticated` default
audience. The former duplicate WordPressLab feature declaration is removed.
`GlobalChatbot` checks the shared feature before footer launcher rendering and
on the existing `base3_dispatch` AJAX hooks for `chatbot/default`; denial is
HTTP 403. Other independently embedded chatbots retain their own authority.
No new transport route or policy storage is added.

WordPress registers `IAuthorizationAudienceAdministrationService` as
`WordPressAudienceAdministrationService`. It adapts the existing
`WordPressGroupConfiguration`, `WordPressFeatureConfiguration` and
`WordPressAudienceEditor` to the unchanged shared `ClientStack` displays
`authorizationgroupsdisplay` and `authorizationfeaturesdisplay`. The original
WordPress-only display, templates and assets are retired. Native WordPress role
grants remain read-only in `AuthorizationAdministrationDisplay`.

The existing `base3_dispatch` transport remains shared by public and protected
outputs. The WordPress bridge classifies protected displays from classmap-discovered
`ISettingsTab` entries and checks native `manage_options`, project `development` membership for Groups/Features, and a
valid `base3_admin_display` WordPress AJAX nonce before dispatch. The common
displays also check `system/admin` and call the WordPress adapter for the
`development` requirement, which WordPress administrators meet by native capability. Each submitted Groups/Features form additionally
uses its own BASE3 session CSRF token. The WordPress session is bound to the
trusted current WordPress login token. Neither visibility nor nonce implies a
permission grant.

WordPress roles have **string slugs**, while the current shared native-role input
uses a numeric role ID. To avoid a WordPress-only UI or modifying ClientStack,
configure WordPress roles through the existing common `Criterion` selector
`wordpressrolecriterion` (schema property `roles`, e.g.
`{"roles":["editor"]}`). Previously stored role criteria remain readable and
editable there. Native user IDs and complex group/feature expressions continue
to use the same `UserCriterionEvaluator` and its advertised schemas. The generic
numeric `Role` input is not used for WordPress role slugs; a direct native-role
selector would require an explicitly agreed improvement to the common UI.

The shared audience UI provides labelled controls, keyboard-operable row
management, a status/error region and advanced JSON rules. Deployed WordPress
themes and screen readers still require accessibility verification; source tests
do not prove production WCAG conformance. Public chatbot/game outputs retain
their independently enforced permissions. The generic TabControlDisplay remains
restricted in AJAX to the developer classification.

## Data lifecycle and installation

The existing WordPress database-backed `ISettingsStore` remains the owner of
the same two settings datasets. **No table or versioned data migration is needed**
for the UI replacement; existing editable groups, feature overrides and the
configured developer allowlist are preserved.
Changes to group criteria are saved in one settings write per administration
operation; feature rules are saved in one settings write. These may contain
user IDs, WordPress role slugs, exact identity details, email domains and other
installed-criterion attributes. A code-owned login bootstrap remains in
`wp-config.php` rather than the settings store. Access, retention, deletion,
backups and third-party plugin data processing are deployment responsibilities,
as described in `../PRIVACY.md` and the WordPress bridge root `PRIVACY.md`.

Run source checks from the installed WordPress plugin directory:

```bash
php modules/Base3WordPress/test/Authorization/WordPressAuthorizationSmokeTest.php
php modules/Base3WordPress/test/Adapter/Base3WordPressFileManagerDispatchSmokeTest.php
php modules/Base3WordPressLab/test/GlobalChatbotSmokeTest.php
node modules/Base3WordPressLab/test/GlobalChatbotDomSmokeTest.js
```

After deploying, test native WordPress administrator access without allowlists and non-administrator denial, both BASE3 group/feature
AJAX forms, native WordPress role capabilities, public chatbot AJAX, game display
and API, native WordPress meta-cap checks, logout and different user sessions.

## Native WordPress user-directory search

`WordPressUsermanager::searchUsers(string $query, int $limit = 20, int $offset = 0): array`
implements the framework's bounded, free-text `IUsermanager` search contract.
It searches WordPress user IDs (exactly for numeric terms), logins, display names,
email addresses, first names, last names, and combined first/last names.
WordPress stores first and last names in `usermeta`, rather than the searchable
`users` table columns exposed by `WP_User_Query::search_columns`. The adapter
therefore executes one prepared query through the host's `$wpdb` connection,
using `$wpdb->esc_like()` and native table names. Duplicate usermeta records
cannot duplicate results. Results are ordered by display name and native ID,
then paginated by the database, never by filtering `getAllUsers()` in PHP.

An empty query returns no results; result pages have a maximum size of 100.
Invalid limits or negative offsets throw `InvalidArgumentException`. A SQL
failure raises `RuntimeException`, rather than silently exposing an unfiltered
result. Native `list_users` visibility is respected, including the existing
`getAllUsers()` restriction. In multisite installations, the query additionally
requires the current site's native `capabilities` usermeta key, matching
WordPress's normal site-membership filter. Actual multisite host deployments
remain subject to integration testing.

Each matching `Base3\Usermanager\User` contains the native ID, login, display
name, email, and language (user-specific `locale`, or the current site default).
Role and capability metadata are deliberately left unresolved for listing;
`getUserById()` remains the existing detailed identity lookup. Search results
must **never** be used as an authorization decision. HTTP callers, including
ClientStack's audience search endpoint, must independently enforce the active
`IAuthorizationService`, native host access requirements and CSRF protection
before returning user information to a browser. The adapter does not create
new tables, settings groups or caches, and does not log search input itself.

The PHPUnit regression test `test/Adapter/WordPressUsermanagerSearchTest.php`
checks query parameters, escaping, membership filtering and edge cases without
executing anything on file inclusion. The host integration should additionally
be tested on an actual WordPress site with users having separate first/last
names and non-default locales.

## Complete WordPress IUsermanager projection (2026-10-22)

The existing `WordPressUsermanager` now consistently exposes native identity,
role and permission metadata according to the current framework contract. The
role ID/name are the exact WordPress role slug; translated WordPress labels are
metadata only. `Role::$permissions` contains `Permission` objects for enabled
role capabilities. `getPermissions()` omits the role-slug markers from native
`allcaps`. `getAllPermissions()` includes native capability definitions with
false entries and the `system/admin` metadata entry. These are descriptions,
not an alternative authorization service.

`getAllUsers()` uses WordPress's native user query for identity fields only,
with its current-blog filter and no per-user role expansion. The detailed
lookup validates positive numeric IDs without lossy casts. WordPress's own
`create_users`, `promote_user`, `manage_options` and `editable_roles` controls
are checked before supported native account and role writes. Per-user role
changes may persist WordPress user metadata; global role capability changes
persist through WordPress's role API. Self-role revocation is disallowed by
this adapter. Neither BASE3 group memberships nor direct resource grants are
modified by these methods; native group-to-role operations remain unsupported.

`WordPressUsermanagerSmokeTest.php` tests identity and role projection, native
capability catalog, identity-only directory queries, successful and rejected
native mutations, and anonymous/unauthorized callers. The existing search test
continues to cover query escaping and pagination. Neither test claims to prove
host-specific production role-policy behavior; test with WordPress and the
installed plugins before deployment. No new BASE3 storage or migration is
introduced.

## Group discovery regression (2026-11-06)

The canonical framework groups `anonymous` and `authenticated` MUST NOT be
redefined by the WordPress host. Duplicate group names cause
`WordPressGroupConfiguration::getEntries()` to throw; the WordPress feature
service then denies decisions and `SettingsDisplay` shows no entries.
After unzipping a WordPress patch, run `sh scripts/remove-obsolete-wordpress-integration.sh`
to remove the two retired WordPress group classes and regenerate the classmap
through the existing module fingerprint on the next request. Verify with:

```sh
php modules/Base3WordPress/test/Authorization/WordPressGroupDiscoverySmokeTest.php
php modules/Base3WordPress/test/Authorization/WordPressAuthorizationSmokeTest.php
```

The discovery test scans the actual distributed module source rather than a
reduced mock classmap. No generic BASE3 group definitions or stored rules are changed.
