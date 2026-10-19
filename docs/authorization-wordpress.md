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
them.

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

`Base3WordPressAudienceAdminDisplay` is shared by the Groups and Features tabs.
It uses the WordPress administration nonce `base3_audience_admin` for writes.
The existing `base3_dispatch` transport remains shared by public and protected
outputs. The WordPress bridge classifies administrative display names via the
unfiltered project tab catalog and checks `manage_options`, optional
`development` and a valid `base3_admin_display` nonce before instantiation.
The public chatbot output and games are not marked as admin-only; their owner
services enforce their own runtime permissions. Protected admin displays, including the general-purpose nested TabControlDisplay,
are not rendered through public post shortcodes or Gutenberg blocks.

The shared audience UI has labelled form fields, keyboard-operable add/remove
buttons, native select controls, an AJAX error/status region and advanced JSON
rules. Simple criterion schemas automatically render individual typed property
fields. Unsupported or nested schemas retain an explicit JSON configuration field;
validation still occurs in the shared criterion evaluator before persistence. The UI must be tested with keyboard and screen reader combinations in
the deployed WordPress theme; the tests shipped with the plugin do not establish
production WCAG conformance.

## Data lifecycle and installation

The existing WordPress database-backed `ISettingsStore` is the owner of the two
new settings datasets. **No new table or versioned data migration is needed**
for an installation that had no previous WordPress BASE3 group/feature rules.
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
