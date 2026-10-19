<?php
/** @var array $row */
/** @var string $prefix */
/** @var string|int $index */
/** @var bool $featuresMode */
/** @var list<string> $groupNames */
/** @var array<string,string> $roles */
/** @var array<string,array> $criteria */
$kind = (string)($row['kind'] ?? ($featuresMode ? 'group' : 'user'));
$value = (string)($row['value'] ?? '');
$config = (string)($row['config'] ?? '{}');
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$field = $prefix . '[' . $index . ']';
?>
<div class="base3-wp-audience-row" data-base3-row>
	<label>Assignment type
		<select name="<?php echo $e($field); ?>[kind]" data-base3-kind>
			<option value="user"<?php echo $kind === 'user' ? ' selected' : ''; ?>>WordPress user</option>
			<option value="role"<?php echo $kind === 'role' ? ' selected' : ''; ?>>WordPress role</option>
			<?php if ($featuresMode): ?><option value="group"<?php echo $kind === 'group' ? ' selected' : ''; ?>>BASE3 group</option><?php endif; ?>
			<option value="criterion"<?php echo $kind === 'criterion' ? ' selected' : ''; ?>>Criterion</option>
			<option value="expression"<?php echo $kind === 'expression' ? ' selected' : ''; ?>>Advanced expression</option>
		</select>
	</label>
	<label data-base3-user-wrap>WordPress user ID
		<input type="number" min="1" step="1" name="<?php echo $e($field); ?>[value]" value="<?php echo $e($value); ?>" data-base3-user>
	</label>
	<label data-base3-role-wrap>WordPress role
		<select name="<?php echo $e($field); ?>[value]" data-base3-role>
			<?php foreach ($roles as $slug => $label): ?><option value="<?php echo $e($slug); ?>"<?php echo $value === $slug ? ' selected' : ''; ?>><?php echo $e($label); ?> (<?php echo $e($slug); ?>)</option><?php endforeach; ?>
		</select>
	</label>
	<?php if ($featuresMode): ?>
	<label data-base3-group-wrap>BASE3 group
		<select name="<?php echo $e($field); ?>[value]" data-base3-group>
			<?php foreach ($groupNames as $group): ?><option value="<?php echo $e($group); ?>"<?php echo $value === $group ? ' selected' : ''; ?>><?php echo $e($group); ?></option><?php endforeach; ?>
		</select>
	</label>
	<?php endif; ?>
	<label data-base3-criterion-wrap>Discoverable criterion
		<select name="<?php echo $e($field); ?>[value]" data-base3-criterion>
			<?php foreach ($criteria as $criterionName => $_schema): ?><option value="<?php echo $e($criterionName); ?>"<?php echo $value === $criterionName ? ' selected' : ''; ?>><?php echo $e($criterionName); ?></option><?php endforeach; ?>
		</select>
	</label>
	<label data-base3-config-wrap>Configuration (JSON)
		<textarea name="<?php echo $e($field); ?>[config]" rows="2" spellcheck="false" data-base3-config><?php echo $e($config); ?></textarea>
	</label>
	<button class="button" type="button" data-base3-remove aria-label="Remove this assignment">Remove</button>
	<p class="base3-wp-audience-schema" data-base3-schema aria-live="polite"></p>
</div>
