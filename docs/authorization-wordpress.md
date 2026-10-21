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

Three code-discovered `IGroup` definitions in `Base3WordPress` supply:

* `anonymous`, matched by `Base3AnonymousCriterion`, locked;
* `authenticated`, matched by `Base3AuthenticatedCriterion`, locked;
* `administration`, matched by WordPress's own `manage_options` capability;
  only additional BASE3 membership criteria can be edited.

The `Base3WordPressLab` **project** plugin owns the `development` group, avoiding
hardcoding a deployment administrator in reusable WordPress host integration.
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

## Initial developer: prevent lockout

Before installing the restricted BASE3 configuration UI, add a trusted WordPress
login to the site's `wp-config.php`, for example:

```php
// Define this before WordPress loads plugins.
define('BASE3_WORDPRESS_DEVELOPER_USERNAMES', ['your-actual-wp-login']);
```

This is an exact, case-sensitive login list and does not depend on user IDs.
Alternatively `BASE3_WORDPRESS_DEVELOPER_EMAILS` can hold exact full addresses,
but only on deployments with properly verified and restricted address changes.
Both are empty by default and do not grant anybody development membership.
Never bootstrap all accounts of a domain using an email-domain criterion.

The site owner must retain WordPress's native `manage_options` capability as
well: **developer membership alone does not authorize native administrative
writes**. More `development` members may be added from Groups through WordPress
role slugs, user IDs or configured criteria. UI changes cannot remove the
code-defined bootstrap accounts; revocation requires updating `wp-config.php`.
Do not expose the installed `wp-config.php` contents or credential-bearing
backups to unauthorised persons.

All WordPress administrators without `development` see only a reduced BASE3
admin catalog. Native `manage_options` plus `development` is required for
Groups/Features write endpoints, not merely for their navigation tabs.

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

The project module `Base3WordPressLab` also declares
`GlobalChatbotLauncherFeature`, with the permission
`feature/globalchatbotlauncherfeature` and a public-by-default audience of
`anonymous` or `authenticated`. Its existing `GlobalChatbot` component checks
this permission before WordPress footer launcher rendering and at priority 0
on the existing `base3_dispatch` AJAX hooks when the decoded
`chatbotdisplay` target is `chatbot/default`. The guard reuses the shared
`AjaxBridge::decodeDisplayData()` method, which is exposed to its host project
so the display-data format has a single decoder. Denial terminates the AJAX
request with HTTP 403 before dispatch. Other BASE3 displays, WordPress blocks
and shortcodes, and generic chatbot conversation APIs retain their own
existing checks, not this launcher feature. No new transport route is added.

WordPress registers `IAuthorizationAudienceAdministrationService` as
`WordPressAudienceAdministrationService`. It adapts the existing
`WordPressGroupConfiguration`, `WordPressFeatureConfiguration` and
`WordPressAudienceEditor` to the unchanged shared `ClientStack` displays
`authorizationgroupsdisplay` and `authorizationfeaturesdisplay`. The original
WordPress-only display, templates and assets are retired. Native WordPress role
grants remain read-only in `AuthorizationAdministrationDisplay`.

The existing `base3_dispatch` transport remains shared by public and protected
outputs. The WordPress bridge classifies admin displays by the project-owned
catalog and checks `manage_options`, `development` for Groups/Features, and a
valid `base3_admin_display` WordPress AJAX nonce before dispatch. The common
displays also check `system/admin` and call the WordPress adapter for the
`development` requirement. Each submitted Groups/Features form additionally
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

After deploying, test full and reduced admin navigation, both BASE3 group/feature
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
