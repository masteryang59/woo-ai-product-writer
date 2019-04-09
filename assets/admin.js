/* global wp, wapwConfig, jQuery */
/**
 * "AI product writer" meta box: generate, review/edit, apply, undo.
 * Plain JS on top of wp.apiFetch (which adds the REST nonce).
 */
(function () {
	'use strict';

	const root = document.getElementById('wapw-app');
	if (!root || !window.wp || !wp.apiFetch) {
		return;
	}

	const { __, sprintf } = wp.i18n;
	const config = window.wapwConfig || {};
	const limits = config.limits || {};
	const productId = Number(root.dataset.productId);
	const base = `/wapw/v1/products/${productId}`;
	const ALLOWED_TAGS = ['P', 'UL', 'OL', 'LI', 'STRONG', 'EM', 'H3', 'BR'];

	let state = null;
	let busy = false;

	const el = (tag, attrs = {}, ...children) => {
		const node = document.createElement(tag);
		for (const [key, value] of Object.entries(attrs)) {
			if (value === false || value === null || value === undefined) continue;
			if (key === 'class') node.className = value;
			else if (key.startsWith('on')) node.addEventListener(key.slice(2), value);
			else if (key === 'html') node.innerHTML = value;
			else node.setAttribute(key, value === true ? '' : value);
		}
		for (const child of children.flat()) {
			if (child === null || child === undefined || child === false) continue;
			node.append(child instanceof Node ? child : document.createTextNode(String(child)));
		}
		return node;
	};

	/** Keeps only the tags the server allows, without attributes, for the live preview. */
	const safeHtml = (html) => {
		const doc = new DOMParser().parseFromString(`<div>${html}</div>`, 'text/html');
		const clean = (node) => {
			for (const child of [...node.childNodes]) {
				if (child.nodeType === Node.ELEMENT_NODE) {
					clean(child);
					if (!ALLOWED_TAGS.includes(child.tagName)) {
						child.replaceWith(...child.childNodes);
					} else {
						for (const attr of [...child.attributes]) child.removeAttribute(attr.name);
					}
				} else if (child.nodeType !== Node.TEXT_NODE) {
					child.remove();
				}
			}
		};
		const wrapper = doc.body.firstChild;
		clean(wrapper);
		return wrapper.innerHTML;
	};

	const ago = (timestamp) => {
		const seconds = Math.max(0, Math.round(Date.now() / 1000 - timestamp));
		if (seconds < 60) return __('just now', 'woo-ai-product-writer');
		if (seconds < 3600) return sprintf(__('%d min ago', 'woo-ai-product-writer'), Math.round(seconds / 60));
		if (seconds < 86400) return sprintf(__('%d h ago', 'woo-ai-product-writer'), Math.round(seconds / 3600));
		return new Date(timestamp * 1000).toLocaleDateString();
	};

	const counter = (length, min, max) => {
		const ok = length <= max && (!min || length >= min);
		return el(
			'span',
			{ class: `wapw-count ${ok ? 'is-ok' : 'is-off'}` },
			min ? sprintf('%d / %d–%d', length, min, max) : sprintf('%d / %d', length, max)
		);
	};

	async function call(path, options = {}) {
		busy = true;
		render();
		try {
			state = await wp.apiFetch({ path: base + path, ...options });
			state.flash = null;
			return state;
		} catch (error) {
			state = { ...(state || {}), flash: { type: 'error', text: error.message || String(error) } };
			throw error;
		} finally {
			busy = false;
			render();
		}
	}

	const formIsDirty = () => {
		const editor = window.tinymce && window.tinymce.get && window.tinymce.get('content');
		return Boolean(editor && editor.isDirty && editor.isDirty());
	};

	const reload = () => {
		// We asked already; don't let the edit screen ask again.
		if (window.jQuery) jQuery(window).off('beforeunload.edit-post');
		window.onbeforeunload = null;
		window.location.reload();
	};

	async function generate() {
		const notes = root.querySelector('#wapw-notes')?.value ?? '';
		try {
			await call('/generate', { method: 'POST', data: { notes } });
		} catch (e) {
			/* shown in the flash */
		}
	}

	async function apply() {
		const fields = [...root.querySelectorAll('[data-apply]:checked')].map((box) => box.dataset.apply);
		if (!fields.length) return;
		if (formIsDirty() && !window.confirm(__('The page has unsaved changes. Applying saves the AI copy and reloads the page, so those changes would be lost. Continue?', 'woo-ai-product-writer'))) {
			return;
		}
		const copy = {};
		for (const input of root.querySelectorAll('[data-field]')) copy[input.dataset.field] = input.value;
		try {
			await call('/apply', { method: 'POST', data: { copy, fields } });
			state.flash = { type: 'success', text: __('Applied. Reloading…', 'woo-ai-product-writer') };
			render();
			reload();
		} catch (e) {
			/* shown in the flash */
		}
	}

	async function undo() {
		if (!window.confirm(__('Restore the description and SEO fields from before the last AI apply?', 'woo-ai-product-writer'))) return;
		try {
			await call('/undo', { method: 'POST' });
			reload();
		} catch (e) {
			/* shown in the flash */
		}
	}

	async function discard() {
		try {
			await call('/discard', { method: 'POST' });
		} catch (e) {
			/* shown in the flash */
		}
	}

	function fieldRow({ field, label, control, extra, current }) {
		return el(
			'div',
			{ class: 'wapw-field' },
			el(
				'div',
				{ class: 'wapw-field__head' },
				el('label', { class: 'wapw-field__label' },
					el('input', { type: 'checkbox', 'data-apply': field, checked: true }),
					' ',
					label
				),
				extra
			),
			control,
			current ? el('details', { class: 'wapw-current' }, el('summary', {}, __('Current', 'woo-ai-product-writer')), current) : null
		);
	}

	function suggestionView(s) {
		const meta = s.meta || {};
		const usage = meta.usage || {};
		const tokens = (usage.prompt_tokens || 0) + (usage.completion_tokens || 0);

		const description = el('textarea', { class: 'wapw-code', rows: 10, 'data-field': 'description_html', spellcheck: 'false' });
		description.value = s.description_html;
		const preview = el('div', { class: 'wapw-preview', html: safeHtml(s.description_html) });
		const htmlTab = el('button', { type: 'button', class: 'button-link' }, __('Edit HTML', 'woo-ai-product-writer'));
		const descriptionWrap = el('div', { class: 'wapw-description is-preview' }, preview, description);
		htmlTab.addEventListener('click', () => {
			const editing = descriptionWrap.classList.toggle('is-preview') === false;
			htmlTab.textContent = editing ? __('Show preview', 'woo-ai-product-writer') : __('Edit HTML', 'woo-ai-product-writer');
			if (!editing) preview.innerHTML = safeHtml(description.value);
		});

		const short = el('textarea', { rows: 3, 'data-field': 'short_description' });
		short.value = s.short_description;
		const shortCount = el('span');
		const title = el('input', { type: 'text', class: 'widefat', 'data-field': 'meta_title' });
		title.value = s.meta_title;
		const titleCount = el('span');
		const metaDescription = el('textarea', { rows: 3, 'data-field': 'meta_description' });
		metaDescription.value = s.meta_description;
		const metaCount = el('span');
		const keyword = el('input', { type: 'text', class: 'regular-text', 'data-field': 'focus_keyword' });
		keyword.value = s.focus_keyword;

		const snippetTitle = el('div', { class: 'wapw-snippet__title' });
		const snippetText = el('div', { class: 'wapw-snippet__text' });
		const snippet = el(
			'div',
			{ class: 'wapw-snippet', 'aria-label': __('Search result preview', 'woo-ai-product-writer') },
			el('div', { class: 'wapw-snippet__url' }, state.permalink || ''),
			snippetTitle,
			snippetText
		);

		const refresh = () => {
			shortCount.replaceChildren(counter(short.value.length, 0, limits.shortDescriptionMax));
			titleCount.replaceChildren(counter(title.value.length, 0, limits.metaTitle));
			metaCount.replaceChildren(counter(metaDescription.value.length, limits.metaDescriptionMin, limits.metaDescriptionMax));
			snippetTitle.textContent = title.value + (state.site_name ? ` - ${state.site_name}` : '');
			snippetText.textContent = metaDescription.value;
		};
		[short, title, metaDescription].forEach((input) => input.addEventListener('input', refresh));
		refresh();
		// Keep edits in state so a re-render (spinner, failed apply) doesn't drop them.
		for (const input of [description, short, title, metaDescription, keyword]) {
			input.addEventListener('input', () => {
				s[input.dataset.field] = input.value;
			});
		}

		const seoLabel = state.seo && state.seo.label;
		const seoOff = !state.seo || !state.seo.target;

		return el(
			'div',
			{ class: 'wapw-suggestion' },
			el(
				'p',
				{ class: 'wapw-meta' },
				meta.source === 'bulk' ? __('Generated in bulk', 'woo-ai-product-writer') : __('Generated', 'woo-ai-product-writer'),
				` ${ago(meta.generated_at)} · ${meta.model || ''}`,
				tokens ? ` · ${sprintf(__('%s tokens', 'woo-ai-product-writer'), tokens.toLocaleString())}` : '',
				meta.duration_ms ? ` · ${(meta.duration_ms / 1000).toFixed(1)} s` : '',
				meta.attempts > 1 ? ` · ${sprintf(__('%d attempts', 'woo-ai-product-writer'), meta.attempts)}` : ''
			),
			s.warnings && s.warnings.length
				? el(
					'div',
					{ class: 'wapw-warnings' },
					el('strong', {}, __('Check before applying:', 'woo-ai-product-writer')),
					el('ul', {}, s.warnings.map((w) => el('li', {}, w.message)))
				)
				: null,
			fieldRow({
				field: 'description_html',
				label: __('Description', 'woo-ai-product-writer'),
				control: descriptionWrap,
				extra: htmlTab,
				current: state.current && state.current.description_html
					? el('div', { class: 'wapw-preview', html: safeHtml(state.current.description_html) })
					: null,
			}),
			fieldRow({
				field: 'short_description',
				label: __('Short description', 'woo-ai-product-writer'),
				control: short,
				extra: shortCount,
				current: state.current && state.current.short_description
					? el('div', { class: 'wapw-preview', html: safeHtml(state.current.short_description) })
					: null,
			}),
			el(
				'div',
				{ class: `wapw-seo ${seoOff ? 'is-off' : ''}` },
				el('h4', {}, seoOff
					? __('SEO (not saved: SEO output is turned off in settings)', 'woo-ai-product-writer')
					: sprintf(__('SEO, saved to %s', 'woo-ai-product-writer'), seoLabel)),
				fieldRow({ field: 'meta_title', label: __('Meta title', 'woo-ai-product-writer'), control: title, extra: titleCount }),
				fieldRow({ field: 'meta_description', label: __('Meta description', 'woo-ai-product-writer'), control: metaDescription, extra: metaCount }),
				fieldRow({ field: 'focus_keyword', label: __('Focus keyword', 'woo-ai-product-writer'), control: keyword }),
				snippet
			),
			el(
				'div',
				{ class: 'wapw-actions' },
				el('button', { type: 'button', class: 'button button-primary', onclick: apply, disabled: busy }, __('Apply selected to product', 'woo-ai-product-writer')),
				el('button', { type: 'button', class: 'button', onclick: discard, disabled: busy }, __('Discard', 'woo-ai-product-writer'))
			)
		);
	}

	function render() {
		if (!state) {
			return;
		}
		const s = state.suggestion;
		const notes = el('textarea', {
			id: 'wapw-notes',
			rows: 2,
			class: 'widefat',
			placeholder: __('Optional notes for the writer: key selling points, who it\'s for, keywords to target, things to avoid…', 'woo-ai-product-writer'),
		});
		notes.value = root.querySelector('#wapw-notes')?.value ?? state.notes ?? '';

		const flash = state.flash
			? el('div', { class: `notice inline notice-${state.flash.type === 'error' ? 'error' : 'success'}` }, el('p', {}, state.flash.text))
			: null;
		const failed = !flash && state.status === 'failed' && state.error
			? el('div', { class: 'notice inline notice-error' }, el('p', {}, sprintf(__('Last generation failed: %s', 'woo-ai-product-writer'), state.error)))
			: null;
		const queued = state.status === 'queued'
			? el('div', { class: 'notice inline notice-info' }, el('p', {}, __('Queued for bulk generation. The suggestion appears here when it\'s ready.', 'woo-ai-product-writer')))
			: null;

		const toolbar = el(
			'div',
			{ class: 'wapw-toolbar' },
			el('button', { type: 'button', class: `button ${s ? '' : 'button-primary'}`, onclick: generate, disabled: busy },
				s ? __('Regenerate', 'woo-ai-product-writer') : __('Generate copy', 'woo-ai-product-writer')),
			busy ? el('span', { class: 'spinner is-active' }) : null,
			state.can_undo
				? el('button', { type: 'button', class: 'button-link wapw-undo', onclick: undo, disabled: busy }, __('Undo last AI apply', 'woo-ai-product-writer'))
				: null,
			el('a', { class: 'wapw-settings-link', href: config.settingsUrl }, __('Settings', 'woo-ai-product-writer'))
		);

		root.replaceChildren(
			el('div', { class: `wapw ${busy ? 'is-busy' : ''}` },
				notes,
				toolbar,
				flash,
				failed,
				queued,
				s ? suggestionView(s) : el('p', { class: 'wapw-empty' }, __('Writes a description, short description, meta title, meta description and focus keyword from this product\'s name, categories, attributes and your notes. You review everything before it\'s saved.', 'woo-ai-product-writer'))
			)
		);
	}

	wp.apiFetch({ path: base })
		.then((data) => {
			state = data;
			render();
		})
		.catch((error) => {
			root.replaceChildren(el('div', { class: 'notice inline notice-error' }, el('p', {}, error.message || String(error))));
		});
})();

function encodeToken(data) {
  if (!data) return null;
  return data;
}

function runMigration(data) {
  if (!data) return null;
  return data;
}