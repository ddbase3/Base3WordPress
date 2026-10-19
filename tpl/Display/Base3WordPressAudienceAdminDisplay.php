<?php
$mode = (string)$this->_['mode'];
$entries = (array)$this->_['entries'];
$groupNames = (array)$this->_['groupNames'];
$roles = (array)$this->_['roles'];
$criteria = (array)$this->_['criteria'];
$nonce = (string)$this->_['nonce'];
$endpoint = (string)$this->_['endpoint'];
$message = (string)$this->_['message'];
$error = (string)$this->_['error'];
$featuresMode = $mode === 'features';
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<section class="base3-wp-audience-editor" data-base3-wordpress-audience="<?php echo $e($mode); ?>" data-base3-schemas="<?php echo $e(json_encode($criteria, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)); ?>">
	<h2><?php echo $featuresMode ? 'BASE3 features' : 'BASE3 groups'; ?></h2>
	<p><?php echo $featuresMode
		? 'Grant a feature to BASE3 groups, WordPress users, WordPress roles or discoverable criteria.'
		: 'Maintain code-owned and additional BASE3 groups using WordPress users, roles or discoverable criteria.'; ?></p>
	<?php if ($message !== ''): ?><p role="status" tabindex="-1" data-base3-status><?php echo $e($message); ?></p><?php endif; ?>
	<p role="alert" data-base3-error<?php echo $error === '' ? ' hidden' : ''; ?>><?php echo $e($error); ?></p>
	<form action="<?php echo $e($endpoint); ?>" method="post" data-base3-editor-form>
		<?php foreach ($entries as $entry): ?>
			<fieldset>
				<legend><?php echo $e($entry['label']); ?><?php echo $entry['source'] === 'managed' ? ' (custom)' : ''; ?></legend>
				<p><?php echo $e($entry['description']); ?></p>
				<?php if ($entry['fixed'] !== []): ?>
					<p>Code-defined membership (read-only): <code><?php echo $e(json_encode($entry['fixed'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)); ?></code></p>
				<?php endif; ?>
				<?php if ($entry['editable']): ?>
					<?php
					$prefix = 'base3_rules[' . $entry['name'] . ']';
					$editorRows = (array)$entry['rows'];
					?>
					<div data-base3-assignment-editor data-base3-prefix="<?php echo $e($prefix); ?>" data-base3-next="<?php echo count($editorRows); ?>">
						<div data-base3-list>
							<?php foreach ($editorRows as $index => $row) require __DIR__ . '/WordPressAudienceRow.php'; ?>
						</div>
						<template data-base3-template><?php $index = '__INDEX__'; $row = []; require __DIR__ . '/WordPressAudienceRow.php'; ?></template>
						<button class="button" type="button" data-base3-add>Add assignment</button>
					</div>
				<?php else: ?>
					<p>Code-defined membership cannot be edited here.</p>
				<?php endif; ?>
			</fieldset>
		<?php endforeach; ?>
		<p>Assignments are combined with OR. Advanced expressions support AND, OR and NOT. An empty feature audience denies access.</p>
		<input type="hidden" name="base3_audience_action" value="save">
		<input type="hidden" name="base3_audience_nonce" value="<?php echo $e($nonce); ?>">
		<button type="submit" class="button button-primary">Save <?php echo $featuresMode ? 'features' : 'groups'; ?></button>
	</form>
	<?php if (!$featuresMode): ?>
		<fieldset><legend>Create custom BASE3 group</legend>
			<form action="<?php echo $e($endpoint); ?>" method="post" data-base3-editor-form>
				<label>Technical group name <input type="text" name="base3_group_name" pattern="[a-z][a-z0-9_-]{1,63}" maxlength="64" required placeholder="project_group"></label>
				<label>Description <input type="text" name="base3_group_description" maxlength="250" required></label>
				<input type="hidden" name="base3_audience_action" value="create">
				<input type="hidden" name="base3_audience_nonce" value="<?php echo $e($nonce); ?>">
				<button type="submit" class="button">Create group</button>
			</form>
		</fieldset>
		<?php foreach ($entries as $entry): ?>
			<?php if ($entry['source'] !== 'managed') continue; ?>
			<form action="<?php echo $e($endpoint); ?>" method="post" data-base3-editor-form>
				<label>Remove custom group <?php echo $e($entry['name']); ?>
					<input type="hidden" name="base3_group_name" value="<?php echo $e($entry['name']); ?>">
				</label>
				<input type="hidden" name="base3_audience_action" value="remove">
				<input type="hidden" name="base3_audience_nonce" value="<?php echo $e($nonce); ?>">
				<button class="button" type="submit" data-base3-confirm-remove>Remove <?php echo $e($entry['name']); ?></button>
			</form>
		<?php endforeach; ?>
	<?php endif; ?>
</section>
