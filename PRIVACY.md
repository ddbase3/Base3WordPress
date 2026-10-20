# Base3WordPress: source-verified data processing

`Base3WordPressFileManagerOutput` authorizes FileManager calls through
`IUsermanager::can(Permission::for('system', 'admin'))`, a WordPress nonce check,
and a nonzero authenticated user identifier. It resolves the owner from the
configured administrative display, rather than from client-supplied identities.

`WordPressFileUploadService` associates upload session metadata and temporary
chunks with that identifier under `DIR_LOCAL/filemanager-upload/`.
`WordPressManagedFileStorageService` persists resources under
`DIR_LOCAL/storage/` using a hash of the configured owner. The existing
FileManager operations can remove resources; automatic lifetime and
retention/deletion guarantees are not established by this patch.

The module uses the active WordPress host capabilities and does not introduce
new external recipients or cross-border transfers. Actual deployments must
verify access to private directories (including Nginx), backups, retention,
delete workflows, legal bases, security, and data-subject rights separately.

## WordPress-native BASE3 groups and audiences (2026-10-19)

The active WordPress integration uses `IAuthorizationService` for the sole native
permission decision and `UserCriterionEvaluator` for both BASE3 group memberships
and feature eligibility. The former `WordPressUsermanager::can()` path is removed.
Feature and group requests use the authenticated WordPress identity, role slugs and
native capability APIs; the host supplies that data and no separate user table is
created.

The DB-backed `ISettingsStore` persists editable BASE3 group rules under
`base3wordpress/groups` and feature override expressions under
`base3_authorization_features/catalog`. Records may contain native user IDs,
role slugs, group descriptions, email domains, exact login/email criteria and
other plugin-defined criterion parameters. Operators can edit group rules and
feature audiences or remove unreferenced custom groups; code-owned group
memberships require code/deployment edits. Historical records may remain in
independent backups, logs or database copies. Source inspection does not establish
a retention policy, backup deletion frequency, or host access restrictions.

The existing `WordPressAudienceAdministrationService` exposes the host-owned
datasets to the unchanged `ClientStack` Groups/Features displays. No parallel
policy store, separate WordPress user table or new external recipient is added.
`base3wordpress/groups` may also store one `defaultGroup` name. When configured,
this names an editable, nonprivileged group for authenticated users with no
other effective group beyond the built-in `authenticated` group. It cannot be
used to grant `development` or `administration` automatically.

Read and write operations require both native `manage_options` and the BASE3
`development` group. The generic WordPress AJAX bridge additionally checks its
`base3_admin_display` nonce; the shared ClientStack forms independently use
BASE3 session CSRF tokens bound to the validated WordPress login. User/role
identifiers and configured criteria are submitted to the same-site host;
public `chatbotdisplay` and game outputs are not converted into admin-only
endpoints. WordPress role slugs are edited via the shared schema-backed
`wordpressrolecriterion`, without creating native roles or grants. Authorization and nonce checks generate requests to the same
WordPress host; no new external recipient is introduced by this code.

An exact developer login/email allowlist is configured in the host's
`wp-config.php`; those values can be present in configuration deployment
artifacts and backups. Revoke a fixed entry through deployment configuration
and remove UI-managed additions through the Groups editor. An email domain
alone is not an administrator grant. Privileged identities and installed
criteria should be reviewed by the operator for collection, retention, transfer,
accessibility and misuse. This describes inspected source behavior only, not a
claim of GDPR compliance or production accessibility conformance.
