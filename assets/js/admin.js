/*!
 * VWL Flip Book – admin screens.
 */
(function ($) {
	'use strict';

	var A = window.vwlEbookAdmin || { i18n: {} };
	var T = A.i18n || {};

	function extractId(input) {
		var v = String(input || '').trim();
		if (!v) { return ''; }
		if (/^[A-Za-z0-9_-]{20,128}$/.test(v)) { return v; }
		var host = (v.match(/^https?:\/\/([^/?#]+)/i) || [])[1] || '';
		if (['drive.google.com', 'docs.google.com', 'drive.usercontent.google.com'].indexOf(host.toLowerCase()) === -1) { return ''; }
		var m = v.match(/\/(?:file\/)?d\/([A-Za-z0-9_-]{20,128})/);
		if (m) { return m[1]; }
		m = v.match(/[?&]id=([A-Za-z0-9_-]{20,128})/);
		return m ? m[1] : '';
	}

	$(function () {
		// Tabs.
		var $wrap = $('.vwl-ebook-admin');
		if ($wrap.length) {
			$wrap.addClass('vwl-ebook-js');
			var show = function (slug) {
				var $tab = $wrap.find('[data-vwl-tab="' + slug + '"]');
				if (!$tab.length) { slug = $wrap.find('[data-vwl-tab]').first().data('vwl-tab'); $tab = $wrap.find('[data-vwl-tab="' + slug + '"]'); }
				$wrap.find('[data-vwl-tab]').removeClass('nav-tab-active').removeAttr('aria-current');
				$tab.addClass('nav-tab-active').attr('aria-current', 'page');
				$wrap.find('[data-vwl-panel]').removeClass('is-active');
				$wrap.find('[data-vwl-panel="' + slug + '"]').addClass('is-active');
				$wrap.find('.vwl-ebook-submit').toggleClass('is-hidden', slug === 'help' || slug === 'tools');
			};
			$wrap.on('click', '[data-vwl-tab]', function (e) {
				e.preventDefault();
				var slug = $(this).data('vwl-tab');
				show(slug);
				if (window.history && window.history.replaceState) { window.history.replaceState(null, '', '#vwl-tab-' + slug); }
			});
			show((window.location.hash || '').replace('#vwl-tab-', '') || 'general');
		}

		// Colour pickers.
		if ($.fn.wpColorPicker) { $('.vwl-ebook-color').wpColorPicker(); }

		// Live Drive link validation.
		$(document).on('input change', '[data-vwl-drive-input]', function () {
			var $status = $(this).closest('td, .vwl-ebook-panel').find('[data-vwl-drive-status]').first();
			var val = $(this).val();
			var id = extractId(val);
			if (!val) { $status.text(T.idEmpty || ''); return; }
			$status.empty().append($('<span/>').addClass(id ? 'vwl-ebook-ok' : 'vwl-ebook-bad').text(id ? (T.idFound || '%s').replace('%s', id) : T.idNone));
		});

		// Copy shortcode.
		$(document).on('click', '[data-vwl-copy]', function () {
			var $b = $(this);
			var text = $b.attr('data-vwl-copy');
			var done = function () { var old = $b.text(); $b.text(T.copied || 'Copied'); setTimeout(function () { $b.text(old); }, 1500); };
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(text).then(done, function () {});
			} else {
				var $t = $('<textarea/>').val(text).appendTo('body').select();
				try { document.execCommand('copy'); done(); } catch (e) {}
				$t.remove();
			}
		});

		// Link tester.
		$('#vwl-ebook-test-btn').on('click', function () {
			var $btn = $(this);
			var $out = $('#vwl-ebook-test-result');
			var url = $('#vwl-ebook-test-url').val();
			$btn.prop('disabled', true);
			$out.html($('<p/>').text(T.testing));
			$.post(A.ajaxUrl, { action: 'vwl_ebook_test_link', nonce: A.nonce, url: url })
				.done(function (res) {
					var ok = res && res.success;
					var data = (res && res.data) || {};
					var $n = $('<div/>').addClass('notice inline ' + (ok ? 'notice-success' : 'notice-error'));
					$n.append($('<p/>').text(data.message || T.failed));
					if (ok && data.shortcode) { $n.append($('<p/>').append($('<code/>').text(data.shortcode))); }
					if (!ok && data.code) { $n.append($('<p/>').append($('<small/>').text('Code: ' + data.code + (data.detail ? ' (' + data.detail + ')' : '')))); }
					$out.empty().append($n);
				})
				.fail(function () { $out.empty().append($('<div class="notice inline notice-error"/>').append($('<p/>').text(T.failed))); })
				.always(function () { $btn.prop('disabled', false); });
		});
	});
})(jQuery);
