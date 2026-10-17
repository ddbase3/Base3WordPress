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
