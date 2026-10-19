(() => {
	'use strict';

	function parseJson(value) {
		try {
			const data = JSON.parse(value || '{}');
			return data && typeof data === 'object' && !Array.isArray(data) ? data : {};
		} catch (_) {
			return {};
		}
	}

	function simpleProperties(schema) {
		if (!schema || schema.type !== 'object' || !schema.properties || typeof schema.properties !== 'object') return null;
		const properties = schema.properties;
		for (const property of Object.values(properties)) {
			if (!property || typeof property !== 'object' || Array.isArray(property.type)) return null;
			if (['string', 'integer', 'number', 'boolean'].includes(property.type)) continue;
			if (property.type === 'array' && ['string', 'integer', 'number'].includes(property.items?.type)) continue;
			return null;
		}
		return properties;
	}

	function makeProperty(name, definition, current, required) {
		const label = document.createElement('label');
		label.textContent = name + (required ? ' *' : '');
		let control;
		if (Array.isArray(definition.enum)) {
			control = document.createElement('select');
			for (const value of definition.enum) {
				const option = document.createElement('option');
				option.value = String(value);
				option.textContent = String(value);
				control.append(option);
			}
		} else {
			control = document.createElement('input');
			control.type = definition.type === 'boolean' ? 'checkbox'
				: ['number','integer'].includes(definition.type) ? 'number' : 'text';
			if (definition.type === 'array') control.placeholder = 'Comma-separated values';
			if (control.type === 'number') control.step = definition.type === 'integer' ? '1' : 'any';
		}
		control.dataset.base3SchemaProperty = name;
		control.dataset.base3SchemaType = definition.type;
		if (definition.description) control.title = String(definition.description);
		if (required) control.required = true;
		if (control.type === 'checkbox') control.checked = current === true;
		else if (Array.isArray(current)) control.value = current.join(', ');
		else if (current !== undefined && current !== null) control.value = String(current);
		label.append(control);
		return label;
	}

	function syncProperties(row, schema) {
		const values = {};
		for (const control of row.querySelectorAll('[data-base3-schema-property]')) {
			const name = control.dataset.base3SchemaProperty;
			const kind = control.dataset.base3SchemaType;
			if (kind === 'boolean') values[name] = control.checked;
			else if (control.value.trim() !== '') {
				if (kind === 'array') {
					const itemType = schema.properties[name].items.type;
					values[name] = control.value.split(',').map((value) => value.trim()).filter(Boolean)
						.map((value) => ['integer', 'number'].includes(itemType) ? Number(value) : value);
				} else if (kind === 'integer' || kind === 'number') values[name] = Number(control.value);
				else values[name] = control.value.trim();
			}
		}
		row.querySelector('[data-base3-config]').value = JSON.stringify(values);
	}

	function configureRow(root, row, reset = false) {
		const kind = row.querySelector('[data-base3-kind]').value;
		const schemaCatalog = JSON.parse(root.dataset.base3Schemas || '{}');
		const schemas = row.querySelector('[data-base3-criterion]');
		const schema = kind === 'criterion' ? schemaCatalog[schemas.value] : null;
		const config = row.querySelector('[data-base3-config]');
		if (reset) config.value = '{}';
		for (const type of ['user', 'role', 'group', 'criterion']) {
			const wrap = row.querySelector('[data-base3-' + type + '-wrap]');
			if (!wrap) continue;
			wrap.hidden = type !== kind;
			for (const input of wrap.querySelectorAll('input,select')) {
				input.disabled = type !== kind;
				input.required = type === kind;
			}
		}
		const configWrap = row.querySelector('[data-base3-config-wrap]');
		configWrap.hidden = !['criterion', 'expression'].includes(kind);
		config.disabled = configWrap.hidden;
		const hint = row.querySelector('[data-base3-schema]');
		const oldProperties = row.querySelector('[data-base3-property-fields]');
		if (oldProperties) oldProperties.remove();
		const properties = kind === 'criterion' ? simpleProperties(schema) : null;
		const configLabel = configWrap;
		if (properties === null) {
			configLabel.hidden = configWrap.hidden;
			hint.textContent = schema ? (schema.description || 'Configure this criterion using its JSON schema.') : '';
			return;
		}
		const values = parseJson(config.value);
		const fieldset = document.createElement('div');
		fieldset.className = 'base3-wp-audience-properties';
		fieldset.dataset.base3PropertyFields = '';
		const mandatory = Array.isArray(schema.required) ? schema.required : [];
		for (const [name, definition] of Object.entries(properties)) {
			fieldset.append(makeProperty(name, definition, values[name], mandatory.includes(name)));
		}
		configWrap.parentNode.insertBefore(fieldset, configWrap);
		configLabel.hidden = true;
		hint.textContent = 'Fields provided by the discovered criterion schema.';
		// Preserve an existing JSON config until the operator edits a property.
		fieldset.addEventListener('input', () => syncProperties(row, schema));
		fieldset.addEventListener('change', () => syncProperties(row, schema));
	}

	function initialize(root) {
		if (root.dataset.base3Bound === '1') return;
		root.dataset.base3Bound = '1';
		for (const row of root.querySelectorAll('[data-base3-row]')) configureRow(root, row);
	}

	document.addEventListener('click', (event) => {
		const remove = event.target.closest('[data-base3-remove]');
		if (remove) { remove.closest('[data-base3-row]').remove(); return; }
		const add = event.target.closest('[data-base3-add]');
		if (!add) return;
		const editor = add.closest('[data-base3-assignment-editor]');
		const fragment = editor.querySelector('template').content.cloneNode(true);
		const index = editor.dataset.base3Next;
		editor.dataset.base3Next = String(Number(index) + 1);
		for (const input of fragment.querySelectorAll('[name]')) input.name = input.name.replace('__INDEX__', index);
		const row = fragment.querySelector('[data-base3-row]');
		editor.querySelector('[data-base3-list]').append(fragment);
		configureRow(add.closest('[data-base3-wordpress-audience]'), row, true);
		row.querySelector('[data-base3-kind]').focus();
	});

	document.addEventListener('change', (event) => {
		if (!event.target.matches('[data-base3-kind],[data-base3-criterion]')) return;
		const row = event.target.closest('[data-base3-row]');
		if (row) configureRow(row.closest('[data-base3-wordpress-audience]'), row, true);
	});

	document.addEventListener('submit', async (event) => {
		const form = event.target.closest('[data-base3-editor-form]');
		if (!form) return;
		event.preventDefault();
		if (event.submitter?.matches('[data-base3-confirm-remove]') && !confirm('Remove this custom BASE3 group?')) return;
		const root = form.closest('[data-base3-wordpress-audience]');
		const error = root.querySelector('[data-base3-error]');
		const button = event.submitter;
		if (button) button.disabled = true;
		error.hidden = true;
		try {
			const response = await fetch(form.action, {
				method:'POST', body:new FormData(form), credentials:'same-origin',
				headers:{'X-Requested-With':'XMLHttpRequest', 'Accept':'text/html'}
			});
			const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
			const replacement = doc.querySelector('[data-base3-wordpress-audience]');
			if (!response.ok || !replacement) {
				const rejection = replacement?.querySelector('[data-base3-error]')?.textContent?.trim();
				throw new Error(rejection || 'Audience update rejected (HTTP ' + response.status + ').');
			}
			root.replaceWith(replacement);
			initialize(replacement);
			replacement.querySelector('[data-base3-status]')?.focus();
		} catch (failure) {
			error.textContent = failure instanceof Error ? failure.message : 'Audience update failed.';
			error.hidden = false;
			if (button) button.disabled = false;
		}
	});

	function scan() { for (const root of document.querySelectorAll('[data-base3-wordpress-audience]')) initialize(root); }
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scan);
	else scan();
})();
