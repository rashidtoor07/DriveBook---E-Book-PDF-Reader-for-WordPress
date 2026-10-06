/*!
 * VWL E-Book Reader – Gutenberg block (plain JS, no build step).
 * Attributes and their defaults are registered in PHP and shared with the editor.
 */
(function (wp) {
	'use strict';

	if (!wp || !wp.blocks || !wp.element) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var be = wp.blockEditor || wp.editor;
	var InspectorControls = be.InspectorControls;
	var useBlockProps = be.useBlockProps;
	var C = wp.components;
	var useSelect = wp.data && wp.data.useSelect;

	function extractId(input) {
		var v = String(input || '').trim();
		if (!v) {
			return '';
		}
		if (/^[A-Za-z0-9_-]{20,128}$/.test(v)) {
			return v;
		}
		var host = (v.match(/^https?:\/\/([^/?#]+)/i) || [])[1] || '';
		if (['drive.google.com', 'docs.google.com', 'drive.usercontent.google.com'].indexOf(host.toLowerCase()) === -1) {
			return '';
		}
		var m = v.match(/\/(?:file\/)?d\/([A-Za-z0-9_-]{20,128})/);
		if (m) {
			return m[1];
		}
		m = v.match(/[?&]id=([A-Za-z0-9_-]{20,128})/);
		return m ? m[1] : '';
	}

	var icon = el('svg', { viewBox: '0 0 24 24', width: 24, height: 24, fill: 'none', stroke: 'currentColor', strokeWidth: 1.6, strokeLinecap: 'round', strokeLinejoin: 'round' },
		el('path', { d: 'M12 6.5C10 5 7 4.5 3.5 5v13c3.5-.5 6.5 0 8.5 1.5 2-1.5 5-2 8.5-1.5V5C17 4.5 14 5 12 6.5z' }),
		el('path', { d: 'M12 6.5v13' })
	);

	function Edit(props) {
		var a = props.attributes;
		var set = props.setAttributes;
		var blockProps = useBlockProps ? useBlockProps({ className: 'vwl-ebook-block-preview' }) : { className: 'vwl-ebook-block-preview' };

		var books = useSelect ? useSelect(function (select) {
			var core = select('core');
			return core && core.getEntityRecords ? core.getEntityRecords('postType', 'vwl_ebook', { per_page: 100, status: 'publish', _fields: 'id,title' }) : null;
		}, []) : null;

		var fileId = extractId(a.url);
		var selected = null;
		if (books && a.ebookId) {
			books.forEach(function (b) {
				if (b.id === a.ebookId) {
					selected = b;
				}
			});
		}
		var hasSource = !!(fileId || a.ebookId);

		var bookOptions = [{ label: __('— Use a Google Drive link instead —', 'vwl-ebook-reader'), value: 0 }];
		(books || []).forEach(function (b) {
			bookOptions.push({ label: (b.title && b.title.rendered) || ('#' + b.id), value: b.id });
		});

		var toggle = function (key, label, help) {
			return el(C.ToggleControl, {
				label: label,
				help: help,
				checked: !!a[key],
				onChange: function (v) {
					var o = {};
					o[key] = v;
					set(o);
				}
			});
		};

		var inspector = el(InspectorControls, null,
			el(C.PanelBody, { title: __('Book', 'vwl-ebook-reader'), initialOpen: true },
				el(C.TextControl, {
					label: __('PDF URL', 'vwl-ebook-reader'),
					help: __('Google Drive share link or file ID. The file must be shared as "Anyone with the link".', 'vwl-ebook-reader'),
					value: a.url,
					onChange: function (v) { set({ url: v }); }
				}),
				books && books.length ? el(C.SelectControl, {
					label: __('Or choose from the E-Books library', 'vwl-ebook-reader'),
					value: a.ebookId,
					options: bookOptions,
					onChange: function (v) { set({ ebookId: parseInt(v, 10) || 0 }); }
				}) : null,
				el(C.TextControl, {
					label: __('Book Title', 'vwl-ebook-reader'),
					value: a.title,
					onChange: function (v) { set({ title: v }); }
				}),
				el(C.RangeControl, {
					label: __('Height (px)', 'vwl-ebook-reader'),
					value: a.height,
					min: 320,
					max: 1400,
					step: 10,
					onChange: function (v) { set({ height: v || 760 }); }
				}),
				el(C.SelectControl, {
					label: __('Theme', 'vwl-ebook-reader'),
					value: a.theme,
					options: [
						{ label: __('Light', 'vwl-ebook-reader'), value: 'light' },
						{ label: __('Dark', 'vwl-ebook-reader'), value: 'dark' },
						{ label: __('Match the visitor’s device', 'vwl-ebook-reader'), value: 'auto' }
					],
					onChange: function (v) { set({ theme: v }); }
				})
			),
			el(C.PanelBody, { title: __('Reader features', 'vwl-ebook-reader'), initialOpen: false },
				toggle('toolbar', __('Show Toolbar', 'vwl-ebook-reader')),
				toggle('search', __('Show Search', 'vwl-ebook-reader')),
				toggle('download', __('Show Download', 'vwl-ebook-reader'), __('Hides the button only; it is not copy protection.', 'vwl-ebook-reader')),
				toggle('print', __('Show Print', 'vwl-ebook-reader')),
				toggle('fullscreen', __('Show Fullscreen', 'vwl-ebook-reader')),
				toggle('toc', __('Show Table of Contents', 'vwl-ebook-reader'), __('Appears only if the PDF has bookmarks.', 'vwl-ebook-reader')),
				toggle('cover', __('Show Book Cover Screen', 'vwl-ebook-reader'))
			),
			el(C.PanelBody, { title: __('Layout', 'vwl-ebook-reader'), initialOpen: false },
				toggle('remember', __('Remember Reading Position', 'vwl-ebook-reader')),
				toggle('twoPage', __('Desktop Two-Page Mode', 'vwl-ebook-reader')),
				toggle('mobileSingle', __('Mobile Single-Page Mode', 'vwl-ebook-reader'), __('When off, small tablets in landscape may also show two pages.', 'vwl-ebook-reader'))
			)
		);

		var body;
		if (!hasSource) {
			body = el(C.Placeholder, {
				icon: icon,
				label: __('Google Drive E-Book Reader', 'vwl-ebook-reader'),
				instructions: __('Paste the share link of a PDF in Google Drive. In Drive, set General access to "Anyone with the link".', 'vwl-ebook-reader')
			},
				el('div', { className: 'vwl-ebook-block-preview__form' },
					el(C.TextControl, {
						placeholder: 'https://drive.google.com/file/d/FILE_ID/view',
						value: a.url,
						onChange: function (v) { set({ url: v }); },
						__nextHasNoMarginBottom: true
					}),
					a.url && !fileId ? el('p', { className: 'vwl-ebook-block-preview__bad' }, __('No Google Drive file ID found in this link.', 'vwl-ebook-reader')) : null,
					books && books.length ? el(C.SelectControl, {
						value: a.ebookId,
						options: bookOptions.map(function (o, i) {
							return i === 0 ? { label: __('…or choose a book from the library', 'vwl-ebook-reader'), value: 0 } : o;
						}),
						onChange: function (v) { set({ ebookId: parseInt(v, 10) || 0 }); },
						__nextHasNoMarginBottom: true
					}) : null
				)
			);
		} else {
			var title = a.title || (selected && selected.title && selected.title.rendered) || __('E-book', 'vwl-ebook-reader');
			var sourceLine = fileId
				? sprintf(/* translators: %s: Google Drive file ID */ __('Google Drive file: %s', 'vwl-ebook-reader'), fileId)
				: sprintf(/* translators: %d: e-book post ID */ __('Library book #%d', 'vwl-ebook-reader'), a.ebookId);
			var features = [];
			if (a.twoPage) { features.push(__('Two-page spread', 'vwl-ebook-reader')); }
			if (a.search) { features.push(__('Search', 'vwl-ebook-reader')); }
			if (a.toc) { features.push(__('Contents', 'vwl-ebook-reader')); }
			if (a.fullscreen) { features.push(__('Fullscreen', 'vwl-ebook-reader')); }
			if (a.download) { features.push(__('Download', 'vwl-ebook-reader')); }
			if (a.print) { features.push(__('Print', 'vwl-ebook-reader')); }
			if (a.cover) { features.push(__('Cover screen', 'vwl-ebook-reader')); }

			body = el('div', { className: 'vwl-ebook-block-preview__card is-' + (a.theme === 'dark' ? 'dark' : 'light'), style: { height: Math.min(a.height || 760, 520) + 'px' } },
				el('div', { className: 'vwl-ebook-block-preview__bar' },
					el('span', { className: 'vwl-ebook-block-preview__title' }, title)
				),
				el('div', { className: 'vwl-ebook-block-preview__stage' },
					el('div', { className: 'vwl-ebook-block-preview__page' }),
					a.twoPage ? el('div', { className: 'vwl-ebook-block-preview__page' }) : null
				),
				el('div', { className: 'vwl-ebook-block-preview__meta' },
					el('strong', null, sourceLine),
					el('span', null, features.join(', ')),
					el('span', null, __('The interactive reader appears on the published page and in Preview.', 'vwl-ebook-reader'))
				)
			);
		}

		return el(Fragment, null, inspector, el('div', blockProps, body));
	}

	wp.blocks.registerBlockType('vwl/ebook-reader', {
		apiVersion: 3,
		title: __('Google Drive E-Book Reader', 'vwl-ebook-reader'),
		icon: icon,
		category: 'media',
		edit: Edit,
		save: function () {
			return null;
		}
	});
})(window.wp);
