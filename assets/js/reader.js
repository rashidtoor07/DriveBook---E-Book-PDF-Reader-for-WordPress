/*!
 * VWL Flip Book – front-end reader.
 * Requires PDF.js (window.pdfjsLib), bundled with the plugin.
 */
(function (window, document) {
	'use strict';

	var G = window.vwlEbookGlobals || {};
	var I = G.i18n || {};
	var ERR = G.errors || {};

	var CSS_UNITS = 96 / 72;
	var ZOOM_STEPS = [0.25, 0.33, 0.5, 0.67, 0.75, 0.8, 0.9, 1, 1.1, 1.25, 1.5, 1.75, 2, 2.5, 3, 4, 5];
	var MIN_ZOOM = 0.25;
	var MAX_ZOOM = 5;
	var NARROW_UI = 720; // container width below which the compact toolbar is used.
	var SINGLE_PAGE = 768; // container width below which only one page is shown.
	var MAX_CANVAS_PX = 12000000; // per canvas (iOS limit is ~16.7M).
	var BIG_FILE = 8 * 1024 * 1024; // above this, load the PDF in ranges.

	var workerReady = false;
	var scrollbarWidth = null;

	/* ------------------------------------------------------------------ */
	/* Helpers                                                            */
	/* ------------------------------------------------------------------ */

	function fmt(str) {
		var args = Array.prototype.slice.call(arguments, 1);
		var i = 0;
		return String(str || '')
			.replace(/%(\d+)\$s/g, function (m, n) {
				var v = args[parseInt(n, 10) - 1];
				return v === undefined ? '' : String(v);
			})
			.replace(/%s/g, function () {
				var v = args[i++];
				return v === undefined ? '' : String(v);
			})
			.replace(/%%/g, '%');
	}

	function store(key, value) {
		try {
			if (value === undefined) {
				return window.localStorage.getItem(key);
			}
			if (value === null) {
				window.localStorage.removeItem(key);
			} else {
				window.localStorage.setItem(key, value);
			}
		} catch (e) {
			/* Storage disabled (private mode, policies). Reader still works. */
		}
		return null;
	}

	function clamp(v, min, max) {
		return Math.max(min, Math.min(max, v));
	}

	function escapeRe(s) {
		return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
	}

	function idle(fn) {
		if (window.requestIdleCallback) {
			window.requestIdleCallback(fn, { timeout: 800 });
		} else {
			window.setTimeout(fn, 120);
		}
	}

	function nextFrame() {
		return new Promise(function (resolve) {
			(window.requestAnimationFrame || window.setTimeout)(function () {
				resolve();
			});
		});
	}

	function getScrollbarWidth() {
		if (scrollbarWidth === null) {
			var d = document.createElement('div');
			d.style.cssText = 'position:absolute;top:-9999px;width:100px;height:100px;overflow:scroll;';
			document.body.appendChild(d);
			scrollbarWidth = d.offsetWidth - d.clientWidth;
			document.body.removeChild(d);
		}
		return scrollbarWidth;
	}

	function prefersReducedMotion() {
		return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
	}

	function animateEl(el, frames, opts) {
		if (!el.animate) {
			return Promise.resolve();
		}
		var a = el.animate(frames, opts);
		if (a.finished) {
			return a.finished.catch(function () {});
		}
		return new Promise(function (resolve) {
			a.onfinish = resolve;
			a.oncancel = resolve;
		});
	}

	function ReaderError(code) {
		this.vwlCode = code;
	}

	/* ------------------------------------------------------------------ */
	/* Reader                                                             */
	/* ------------------------------------------------------------------ */

	function Reader(root) {
		var self = this;
		this.root = root;
		try {
			this.cfg = JSON.parse(root.getAttribute('data-vwl-ebook') || '{}');
		} catch (e) {
			this.cfg = {};
		}
		this.f = this.cfg.features || {};
		this.completion = this.cfg.completion || {};
		this.refs = {};
		Array.prototype.forEach.call(root.querySelectorAll('[data-ref]'), function (n) {
			self.refs[n.getAttribute('data-ref')] = n;
		});

		this.pdf = null;
		this.task = null;
		this.numPages = 0;
		this.page = 1;
		this.spread = null;
		this.sheets = {};
		this.pages = {};
		this.sizes = {};
		this.cache = new Map();
		this.pending = new Map();
		this.pinned = {};
		this.zoomMode = null;
		this.userZoom = false;
		this.zoomValue = 1;
		this.scale = 1;
		this.double = false;
		this.narrow = false;
		this.busy = false;
		this.queued = null;
		this.loaded = false;
		this.loading = false;
		this.started = !this.refs.cover;
		this.completed = false;
		this.search = { open: false, query: '', matches: [], index: -1, texts: null, extracting: null, token: 0, hasText: true };
		this.storeKey = 'vwl_ebook_pos_' + (this.cfg.fileId || 'none');
		this.animationsOn = !!this.cfg.animations && !prefersReducedMotion() && typeof root.animate === 'function';

		root.vwlEbook = this;

		this.initTheme();
		this.bind();
		this.measure();
		this.applyDefaultZoom();
		this.startWhenVisible();
	}

	var R = Reader.prototype;

	/* ---------- Setup ---------- */

	R.initTheme = function () {
		var cfg = this.cfg;
		var saved = this.f.darkmode ? store('vwl_ebook_theme') : null;
		var theme = saved === 'dark' || saved === 'light' ? saved : cfg.theme;
		if (theme === 'auto') {
			theme = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
		}
		this.setTheme(theme, false);

		var self = this;
		if (cfg.theme === 'auto' && !saved && window.matchMedia) {
			var mq = window.matchMedia('(prefers-color-scheme: dark)');
			var handler = function (e) {
				if (!store('vwl_ebook_theme')) {
					self.setTheme(e.matches ? 'dark' : 'light', false);
				}
			};
			if (mq.addEventListener) {
				mq.addEventListener('change', handler);
			} else if (mq.addListener) {
				mq.addListener(handler);
			}
		}
	};

	R.setTheme = function (theme, persist) {
		this.theme = theme === 'dark' ? 'dark' : 'light';
		this.root.setAttribute('data-theme', this.theme);
		Array.prototype.forEach.call(this.root.querySelectorAll('[data-action="theme"]'), function (b) {
			if (b.classList.contains('vwl-ebook__btn')) {
				b.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
			}
		});
		if (persist) {
			store('vwl_ebook_theme', this.theme);
		}
	};

	R.bind = function () {
		var self = this;
		var root = this.root;
		var refs = this.refs;

		root.addEventListener('click', function (e) {
			var target = e.target.closest ? e.target.closest('[data-action]') : null;
			if (target && root.contains(target)) {
				var action = target.getAttribute('data-action');
				if (refs.menu && refs.menu.contains(target)) {
					self.closeMenu();
				}
				self.action(action);
				return;
			}
			var tocItem = e.target.closest ? e.target.closest('.vwl-ebook__toc-item') : null;
			if (tocItem) {
				self.openTocItem(tocItem);
			}
		});

		root.addEventListener('keydown', function (e) {
			self.onKey(e);
		});

		document.addEventListener('click', function (e) {
			if (refs.menu && !refs.menu.hidden && !refs.menu.contains(e.target) && !(refs.moreBtn && refs.moreBtn.contains(e.target))) {
				self.closeMenu();
			}
		});

		if (refs.pageInput) {
			refs.pageInput.addEventListener('keydown', function (e) {
				if (e.key === 'Enter') {
					e.preventDefault();
					self.jumpFromInput();
				}
			});
			refs.pageInput.addEventListener('change', function () {
				self.jumpFromInput();
			});
			refs.pageInput.addEventListener('focus', function () {
				refs.pageInput.select();
			});
		}

		if (refs.scrubber) {
			refs.scrubber.addEventListener('input', function () {
				if (refs.statusLabel) {
					refs.statusLabel.textContent = fmt(I.pageOf, refs.scrubber.value, self.numPages);
				}
			});
			refs.scrubber.addEventListener('change', function () {
				self.goTo(parseInt(refs.scrubber.value, 10) || 1, { user: true });
			});
		}

		if (refs.searchInput) {
			var timer = null;
			refs.searchInput.addEventListener('input', function () {
				window.clearTimeout(timer);
				timer = window.setTimeout(function () {
					self.runSearch(refs.searchInput.value);
				}, 300);
			});
			refs.searchInput.addEventListener('keydown', function (e) {
				if (e.key === 'Enter') {
					e.preventDefault();
					window.clearTimeout(timer);
					if (refs.searchInput.value.trim() !== self.search.query) {
						self.runSearch(refs.searchInput.value);
					} else {
						self.stepMatch(e.shiftKey ? -1 : 1);
					}
				}
			});
		}

		var stage = refs.stage;
		if (stage) {
			stage.addEventListener('pointerdown', function (e) {
				if (e.target === stage || (refs.viewport && refs.viewport.contains(e.target))) {
					try {
						stage.focus({ preventScroll: true });
					} catch (err) {
						stage.focus();
					}
				}
			});
			stage.addEventListener('wheel', function (e) {
				self.onWheel(e);
			}, { passive: false });
		}

		var vp = refs.viewport;
		if (vp) {
			vp.addEventListener('touchstart', function (e) {
				self.onTouchStart(e);
			}, { passive: false });
			vp.addEventListener('touchmove', function (e) {
				self.onTouchMove(e);
			}, { passive: false });
			vp.addEventListener('touchend', function (e) {
				self.onTouchEnd(e);
			}, { passive: false });
			vp.addEventListener('touchcancel', function () {
				self.cancelPinch();
			});
		}

		var resizeTimer = null;
		var onResize = function () {
			window.clearTimeout(resizeTimer);
			resizeTimer = window.setTimeout(function () {
				self.onResize();
			}, 140);
		};
		if (window.ResizeObserver) {
			this.ro = new window.ResizeObserver(onResize);
			this.ro.observe(root);
		} else {
			window.addEventListener('resize', onResize);
		}

		var fsHandler = function () {
			self.onFullscreenChange();
		};
		document.addEventListener('fullscreenchange', fsHandler);
		document.addEventListener('webkitfullscreenchange', fsHandler);

		window.addEventListener('pagehide', function () {
			self.savePosition(true);
		});
	};

	R.startWhenVisible = function () {
		var self = this;
		if (!this.cfg.lazy || !window.IntersectionObserver) {
			this.load();
			return;
		}
		var io = new window.IntersectionObserver(function (entries) {
			for (var i = 0; i < entries.length; i++) {
				if (entries[i].isIntersecting) {
					io.disconnect();
					self.load();
					return;
				}
			}
		}, { rootMargin: '300px 0px' });
		io.observe(this.root);
	};

	/* ---------- Actions ---------- */

	R.action = function (action) {
		switch (action) {
			case 'prev': this.prev(); break;
			case 'next': this.next(); break;
			case 'zoom-in': this.zoomStep(1); break;
			case 'zoom-out': this.zoomStep(-1); break;
			case 'zoom-reset': this.zoomReset(); break;
			case 'fit-width': this.setZoomMode('fit-width'); break;
			case 'fit-page': this.setZoomMode('fit-page'); break;
			case 'search': this.search.open ? this.closeSearch() : this.openSearch(); break;
			case 'search-prev': this.stepMatch(-1); break;
			case 'search-next': this.stepMatch(1); break;
			case 'search-close': this.closeSearch(); break;
			case 'theme': this.setTheme(this.theme === 'dark' ? 'light' : 'dark', true); break;
			case 'fullscreen': this.toggleFullscreen(); break;
			case 'print': this.print(); break;
			case 'more': this.toggleMenu(); break;
			case 'toc': this.toggleToc(); break;
			case 'toc-close': this.closeToc(); break;
			case 'retry': this.retry(); break;
			case 'fallback': this.showFallback(); break;
			case 'start': this.startReading(); break;
			case 'resume-yes': this.hideOverlay('resume'); this.goTo(this.resumePage || 1, { instant: true }); this.focusStage(); break;
			case 'resume-no': this.hideOverlay('resume'); this.goTo(1, { instant: true }); this.focusStage(); break;
			case 'read-again': this.hideOverlay('complete'); this.goTo(1, { instant: true }); this.focusStage(); break;
			case 'complete-close': this.hideOverlay('complete'); this.focusStage(); break;
			default: return;
		}
	};

	R.focusStage = function () {
		if (!this.refs.stage) {
			return;
		}
		try {
			this.refs.stage.focus({ preventScroll: true });
		} catch (e) {
			this.refs.stage.focus();
		}
	};

	R.onKey = function (e) {
		var t = e.target;
		var tag = t && t.tagName ? t.tagName.toLowerCase() : '';
		var inField = tag === 'input' || tag === 'textarea' || tag === 'select' || (t && t.isContentEditable);
		var mod = e.ctrlKey || e.metaKey;

		if (mod && !e.altKey && (e.key === 'f' || e.key === 'F') && this.f.search && this.loaded) {
			e.preventDefault();
			this.openSearch();
			return;
		}

		if (e.key === 'Escape' || e.key === 'Esc') {
			if (this.refs.menu && !this.refs.menu.hidden) {
				this.closeMenu();
				if (this.refs.moreBtn) {
					this.refs.moreBtn.focus();
				}
			} else if (this.root.classList.contains('vwl-ebook--toc-open')) {
				this.closeToc();
			} else if (this.search.open) {
				this.closeSearch();
			} else if (this.refs.complete && !this.refs.complete.hidden) {
				this.hideOverlay('complete');
			} else if (this.refs.resume && !this.refs.resume.hidden) {
				this.action('resume-no');
			} else if (this.pseudoFs) {
				this.exitPseudoFullscreen();
			} else {
				return;
			}
			e.preventDefault();
			return;
		}

		if (inField || mod || e.altKey || !this.loaded) {
			return;
		}

		switch (e.key) {
			case 'ArrowLeft':
			case 'PageUp':
				this.prev();
				break;
			case 'ArrowRight':
			case 'PageDown':
				this.next();
				break;
			case 'Home':
				this.goTo(1, { user: true });
				break;
			case 'End':
				this.goTo(this.numPages, { user: true });
				break;
			case '+':
			case '=':
				this.zoomStep(1);
				break;
			case '-':
			case '_':
				this.zoomStep(-1);
				break;
			case 'f':
			case 'F':
				if (!this.f.fullscreen) {
					return;
				}
				this.toggleFullscreen();
				break;
			default:
				return;
		}
		e.preventDefault();
	};

	/* ---------- Loading ---------- */

	R.showLoader = function (text, sub) {
		var r = this.refs;
		if (!r.loader) {
			return;
		}
		r.loader.hidden = false;
		r.loader.classList.remove('vwl-ebook--fading');
		if (r.loaderText) {
			r.loaderText.textContent = text || I.opening || '';
		}
		if (r.loaderSub) {
			r.loaderSub.textContent = sub || '';
		}
	};

	R.setLoaderSub = function (text) {
		if (this.refs.loaderSub) {
			this.refs.loaderSub.textContent = text || '';
		}
	};

	R.hideLoader = function () {
		var loader = this.refs.loader;
		if (!loader || loader.hidden) {
			return;
		}
		window.clearTimeout(this.slowTimer);
		loader.classList.add('vwl-ebook--fading');
		window.setTimeout(function () {
			loader.hidden = true;
		}, 320);
	};

	R.load = function () {
		var self = this;
		if (this.loading || this.loaded) {
			return Promise.resolve();
		}
		this.loading = true;
		this.passwordHit = false;
		this.showLoader(I.opening, I.preparing);
		window.clearTimeout(this.slowTimer);
		this.slowTimer = window.setTimeout(function () {
			self.setLoaderSub(I.stillLoading);
		}, 8000);

		return Promise.resolve()
			.then(function () {
				if (!window.Promise || !window.fetch || !document.createElement('canvas').getContext) {
					throw new ReaderError('browser');
				}
				if (!self.cfg.fileId || !self.cfg.src) {
					throw new ReaderError('invalid_url');
				}
				var lib = window.pdfjsLib;
				if (!lib || !lib.getDocument) {
					throw new ReaderError('pdfjs');
				}
				if (!workerReady) {
					lib.GlobalWorkerOptions.workerSrc = G.workerSrc;
					workerReady = true;
				}
				return self.prepare();
			})
			.then(function (info) {
				var big = info && info.size > BIG_FILE;
				var task = window.pdfjsLib.getDocument({
					url: self.cfg.src,
					rangeChunkSize: 262144,
					disableRange: !big,
					disableStream: !!big,
					disableAutoFetch: !!big,
					isEvalSupported: false,
					enableXfa: false,
					cMapUrl: G.cMapUrl,
					cMapPacked: true,
					standardFontDataUrl: G.standardFontDataUrl
				});
				self.task = task;
				task.onPassword = function () {
					self.passwordHit = true;
					task.destroy();
				};
				task.onProgress = function (p) {
					if (p && p.total && !big) {
						self.setLoaderSub(fmt(I.loadingPct, Math.min(100, Math.round((p.loaded / p.total) * 100))));
					}
				};
				return task.promise;
			})
			.then(function (pdf) {
				self.pdf = pdf;
				self.numPages = pdf.numPages;
				return self.afterOpen();
			})
			.catch(function (err) {
				self.loading = false;
				self.fail(self.codeFor(err));
			});
	};

	R.prepare = function () {
		return window
			.fetch(this.cfg.prepareUrl, { credentials: 'same-origin', cache: 'no-store' })
			.catch(function () {
				throw new ReaderError('network');
			})
			.then(function (res) {
				return res.json().catch(function () {
					throw new ReaderError('generic');
				});
			})
			.then(function (json) {
				if (json && json.success && json.data) {
					return json.data;
				}
				throw new ReaderError(json && json.data && json.data.code ? json.data.code : 'generic');
			});
	};

	R.codeFor = function (err) {
		if (this.passwordHit) {
			return 'password';
		}
		if (!err) {
			return 'generic';
		}
		if (err.vwlCode) {
			return err.vwlCode;
		}
		switch (err.name) {
			case 'PasswordException':
				return 'password';
			case 'InvalidPDFException':
				return 'corrupt';
			case 'MissingPDFException':
				return 'not_found';
			case 'UnexpectedResponseException':
				return err.status === 403 ? 'private' : 'network';
			case 'TypeError':
				return 'network';
			default:
				return 'generic';
		}
	};

	R.fail = function (code) {
		var r = this.refs;
		window.clearTimeout(this.slowTimer);
		if (r.loader) {
			r.loader.hidden = true;
		}
		if (r.cover) {
			r.cover.hidden = true;
		}
		if (!r.error) {
			return;
		}
		if (r.errorText) {
			r.errorText.textContent = ERR[code] || ERR.generic || '';
		}
		if (r.errorHint) {
			r.errorHint.hidden = !this.cfg.canEdit;
			r.errorHint.textContent = this.cfg.canEdit ? fmt(I.adminHint, code) : '';
		}
		if (r.fallbackBtn) {
			var noFallback = ['invalid_url', 'private', 'not_found', 'not_pdf', 'password'];
			r.fallbackBtn.hidden = !this.cfg.fallbackUrl || noFallback.indexOf(code) !== -1;
		}
		r.error.hidden = false;
	};

	R.retry = function () {
		if (this.refs.error) {
			this.refs.error.hidden = true;
		}
		if (this.task) {
			try {
				this.task.destroy();
			} catch (e) {}
		}
		this.task = null;
		this.pdf = null;
		this.loaded = false;
		this.loading = false;
		this.load();
	};

	R.showFallback = function () {
		var box = this.refs.fallback;
		if (!box || !this.cfg.fallbackUrl) {
			return;
		}
		box.innerHTML = '';
		var frame = document.createElement('iframe');
		frame.src = this.cfg.fallbackUrl;
		frame.title = this.cfg.title || '';
		frame.setAttribute('allow', 'fullscreen');
		frame.setAttribute('allowfullscreen', '');
		frame.setAttribute('loading', 'lazy');
		frame.setAttribute('referrerpolicy', 'no-referrer');
		box.appendChild(frame);
		box.hidden = false;
		if (this.refs.error) {
			this.refs.error.hidden = true;
		}
	};

	R.afterOpen = function () {
		var self = this;
		var r = this.refs;
		var n = this.numPages;

		if (r.pageTotal) {
			r.pageTotal.textContent = n;
		}
		if (r.scrubber) {
			r.scrubber.max = n;
			r.scrubber.hidden = n < 2;
		}

		this.setupToc();

		return this.getSize(1)
			.then(function () {
				self.loaded = true;
				self.loading = false;
				return self.display(1, { instant: true });
			})
			.then(function () {
				self.hideLoader();
				self.emit('opened');
				self.coverThumb();
				self.resumePage = self.readSaved();
				if (self.started) {
					self.maybeAskResume();
				}
			});
	};

	R.startReading = function () {
		var cover = this.refs.cover;
		this.started = true;
		if (cover) {
			cover.classList.add('vwl-ebook--fading');
			window.setTimeout(function () {
				cover.hidden = true;
			}, 320);
		}
		if (this.loaded) {
			this.maybeAskResume();
		}
		this.focusStage();
	};

	R.coverThumb = function () {
		var art = this.refs.coverArt;
		if (!art || this.cfg.coverImage || this.started || !this.pdf) {
			return;
		}
		this.pdf.getPage(1).then(function (page) {
			var vp1 = page.getViewport({ scale: 1 });
			var h = art.clientHeight || 320;
			var s = (h / vp1.height) * Math.min(window.devicePixelRatio || 1, 2);
			var vp = page.getViewport({ scale: s });
			var c = document.createElement('canvas');
			c.width = Math.floor(vp.width);
			c.height = Math.floor(vp.height);
			var ctx = c.getContext('2d');
			ctx.fillStyle = '#fff';
			ctx.fillRect(0, 0, c.width, c.height);
			return page.render({ canvasContext: ctx, viewport: vp }).promise.then(function () {
				art.innerHTML = '';
				art.style.aspectRatio = vp1.width + ' / ' + vp1.height;
				art.appendChild(c);
			});
		}).catch(function () {});
	};

	R.readSaved = function () {
		if (!this.cfg.remember) {
			return 0;
		}
		try {
			var d = JSON.parse(store(this.storeKey) || 'null');
			if (d && d.page > 1 && d.page <= this.numPages && (!d.total || d.total === this.numPages)) {
				return d.page;
			}
		} catch (e) {}
		return 0;
	};

	R.maybeAskResume = function () {
		var r = this.refs;
		if (!this.resumePage || this.resumeAsked || !r.resume) {
			return;
		}
		this.resumeAsked = true;
		if (this.spread && this.spread.indexOf(this.resumePage) !== -1) {
			return;
		}
		if (r.resumeText) {
			r.resumeText.textContent = fmt(I.resumeTitle, this.resumePage);
		}
		r.resume.hidden = false;
		var btn = r.resume.querySelector('[data-action="resume-yes"]');
		if (btn) {
			btn.focus();
		}
	};

	R.savePosition = function (now) {
		if (!this.cfg.remember || !this.loaded) {
			return;
		}
		var self = this;
		var write = function () {
			var atEnd = self.spread && self.spread[self.spread.length - 1] >= self.numPages;
			if (atEnd || self.page <= 1) {
				store(self.storeKey, null);
				return;
			}
			store(self.storeKey, JSON.stringify({ page: self.page, total: self.numPages, t: Date.now() }));
		};
		window.clearTimeout(this.saveTimer);
		if (now) {
			write();
		} else {
			this.saveTimer = window.setTimeout(write, 400);
		}
	};

	R.hideOverlay = function (name) {
		if (this.refs[name]) {
			this.refs[name].hidden = true;
		}
	};

	/* ---------- Layout & mode ---------- */

	R.measure = function () {
		var w = this.root.clientWidth;
		if (!w) {
			return false;
		}
		var narrow = w < NARROW_UI;
		var dbl = !!this.cfg.twoPage && (w >= SINGLE_PAGE || (!this.cfg.mobileSingle && w >= 560));
		var changed = narrow !== this.narrow || dbl !== this.double;
		this.narrow = narrow;
		this.double = dbl;
		this.root.classList.toggle('vwl-ebook--narrow', narrow);
		this.root.classList.toggle('vwl-ebook--double', dbl);
		return changed;
	};

	R.defaultZoomMode = function () {
		return this.narrow && !this.double ? 'fit-width' : 'fit-page';
	};

	R.applyDefaultZoom = function () {
		if (!this.userZoom) {
			this.zoomMode = this.defaultZoomMode();
		}
	};

	R.onResize = function () {
		var changed = this.measure();
		if (changed) {
			this.applyDefaultZoom();
		}
		if (!this.loaded) {
			return;
		}
		if (this.zoomMode === 'custom' && !changed && !this.pendingDisplay) {
			return;
		}
		this.display(this.pendingDisplay || this.page, { instant: true });
	};

	R.available = function () {
		var vp = this.refs.viewport;
		var area = vp ? vp.firstElementChild : null;
		if (!vp || !area) {
			return { w: 0, h: 0 };
		}
		var cs = window.getComputedStyle(area);
		var w = vp.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
		var h = vp.clientHeight - parseFloat(cs.paddingTop) - parseFloat(cs.paddingBottom);
		return { w: Math.max(0, w), h: Math.max(0, h) };
	};

	R.spreadFor = function (n) {
		n = clamp(n, 1, this.numPages || 1);
		if (!this.double || this.numPages < 3) {
			return [n];
		}
		// Book convention: page 1 (cover) alone, then 2–3, 4–5, …
		if (n === 1) {
			return [1];
		}
		var left = n % 2 === 0 ? n : n - 1;
		var right = left + 1;
		return right <= this.numPages ? [left, right] : [left];
	};

	R.getPage = function (n) {
		var self = this;
		if (this.pages[n]) {
			return Promise.resolve(this.pages[n]);
		}
		return this.pdf.getPage(n).then(function (p) {
			self.pages[n] = p;
			return p;
		});
	};

	R.getSize = function (n) {
		var self = this;
		if (this.sizes[n]) {
			return Promise.resolve(this.sizes[n]);
		}
		return this.getPage(n).then(function (p) {
			var vp = p.getViewport({ scale: 1 });
			self.sizes[n] = { w: vp.width, h: vp.height };
			return self.sizes[n];
		});
	};

	R.scaleFor = function (spread) {
		var self = this;
		return Promise.all(spread.map(function (p) {
			return self.getSize(p);
		})).then(function (sizes) {
			var totalW = 0;
			var maxH = 0;
			sizes.forEach(function (s) {
				totalW += s.w;
				maxH = Math.max(maxH, s.h);
			});
			var avail = self.available();
			if (avail.w < 40 || avail.h < 40) {
				return 0;
			}
			var fitW = avail.w / totalW;
			var fitP = Math.min(fitW, avail.h / maxH);
			var s;
			if (self.zoomMode === 'custom') {
				s = self.zoomValue * CSS_UNITS;
			} else if (self.zoomMode === 'fit-width') {
				s = fitW;
				if (maxH * s > avail.h) {
					s = Math.max(0.05, (avail.w - getScrollbarWidth()) / totalW);
				}
			} else {
				s = fitP;
			}
			s = clamp(s, 0.05, MAX_ZOOM * CSS_UNITS);
			return Math.round(s * 10000) / 10000;
		});
	};

	R.fitScaleFor = function (spread, mode) {
		var saved = this.zoomMode;
		this.zoomMode = mode;
		var p = this.scaleFor(spread);
		this.zoomMode = saved;
		return p;
	};

	/* ---------- Rendering ---------- */

	R.cacheKey = function (n, scale) {
		return n + '@' + scale.toFixed(4);
	};

	R.getCanvas = function (n, scale) {
		var self = this;
		var key = this.cacheKey(n, scale);
		if (this.cache.has(key)) {
			var c = this.cache.get(key);
			this.cache.delete(key);
			this.cache.set(key, c);
			return Promise.resolve(c);
		}
		if (this.pending.has(key)) {
			return this.pending.get(key);
		}
		var promise = this.getPage(n).then(function (page) {
			var vp = page.getViewport({ scale: scale });
			var out = Math.max(1, Math.min(window.devicePixelRatio || 1, self.cfg.maxScale || 2));
			if (vp.width * vp.height * out * out > MAX_CANVAS_PX) {
				out = Math.sqrt(MAX_CANVAS_PX / (vp.width * vp.height));
			}
			var canvas = document.createElement('canvas');
			canvas.width = Math.max(1, Math.floor(vp.width * out));
			canvas.height = Math.max(1, Math.floor(vp.height * out));
			canvas.vwlW = vp.width;
			canvas.vwlH = vp.height;
			canvas.vwlPage = n;
			canvas.vwlKey = key;
			canvas.setAttribute('role', 'img');
			canvas.setAttribute('aria-label', fmt(I.pageLabel, n));
			var ctx = canvas.getContext('2d', { alpha: false });
			ctx.fillStyle = '#ffffff';
			ctx.fillRect(0, 0, canvas.width, canvas.height);
			return page.render({
				canvasContext: ctx,
				viewport: vp,
				transform: out !== 1 ? [out, 0, 0, out, 0, 0] : null
			}).promise.then(function () {
				self.pending.delete(key);
				self.cache.set(key, canvas);
				self.evict();
				return canvas;
			});
		}).catch(function (err) {
			self.pending.delete(key);
			throw err;
		});
		this.pending.set(key, promise);
		return promise;
	};

	R.evict = function (scaleOnly) {
		var budget = this.narrow ? 14e6 : 40e6;
		var maxEntries = this.cfg.preload ? 12 : 6;
		var total = 0;
		var self = this;
		var entries = [];
		this.cache.forEach(function (c, key) {
			total += c.width * c.height;
			entries.push([key, c]);
		});
		var currentSuffix = '@' + this.scale.toFixed(4);
		for (var i = 0; i < entries.length; i++) {
			var key = entries[i][0];
			var c = entries[i][1];
			var stale = scaleOnly && key.slice(-currentSuffix.length) !== currentSuffix;
			var over = total > budget || this.cache.size > maxEntries;
			if (!stale && !over) {
				if (!scaleOnly) {
					break;
				}
				continue;
			}
			if (c.isConnected || self.pinned[key]) {
				continue;
			}
			total -= c.width * c.height;
			this.cache.delete(key);
			c.width = 0;
			c.height = 0;
		}
	};

	R.makeSheet = function (n, canvas, side) {
		var sheet = document.createElement('div');
		sheet.className = 'vwl-ebook__sheet' + (side ? ' vwl-ebook__sheet--' + side : '');
		sheet.style.width = canvas.vwlW + 'px';
		sheet.style.height = canvas.vwlH + 'px';
		sheet.setAttribute('data-page', n);
		sheet.appendChild(canvas);
		var hl = document.createElement('div');
		hl.className = 'vwl-ebook__hl-layer';
		sheet.appendChild(hl);
		return sheet;
	};

	R.layoutSpread = function (spread, canvases) {
		var book = this.refs.book;
		var self = this;
		book.textContent = '';
		this.sheets = {};
		var two = spread.length === 2;
		spread.forEach(function (n, i) {
			var side = two ? (i === 0 ? 'left' : 'right') : '';
			var sheet = self.makeSheet(n, canvases[i], side);
			self.sheets[n] = sheet;
			book.appendChild(sheet);
		});
	};

	R.display = function (n, opts) {
		var self = this;
		opts = opts || {};
		if (!this.loaded) {
			return Promise.resolve();
		}
		if (this.busy) {
			this.queued = { type: 'goto', n: n, opts: opts };
			return Promise.resolve();
		}
		this.busy = true;
		var newSpread = this.spreadFor(n);
		var oldSpread = this.spread;
		var gen = (this.gen = (this.gen || 0) + 1);
		var scale;

		return this.scaleFor(newSpread)
			.then(function (s) {
				if (!s) {
					// Reader is hidden (tab, accordion). Wait for a resize.
					self.pendingDisplay = n;
					throw { vwlSkip: true };
				}
				self.pendingDisplay = 0;
				scale = s;
				newSpread.forEach(function (p) {
					self.pinned[self.cacheKey(p, s)] = true;
				});
				return Promise.all(newSpread.map(function (p) {
					return self.getCanvas(p, s);
				}));
			})
			.then(function (canvases) {
				if (gen !== self.gen) {
					return;
				}
				var dir = oldSpread ? (newSpread[0] > oldSpread[0] ? 1 : newSpread[0] < oldSpread[0] ? -1 : 0) : 0;
				var animate = self.animationsOn && !opts.instant && dir !== 0 && oldSpread && self.root.offsetParent !== null;
				self.scale = scale;
				if (animate) {
					return self.turn(oldSpread, newSpread, canvases, dir);
				}
				self.layoutSpread(newSpread, canvases);
			})
			.then(function () {
				self.spread = newSpread;
				self.page = newSpread[0];
				self.pinned = {};
				self.afterDisplay(opts);
			})
			.catch(function (err) {
				self.pinned = {};
				if (err && err.vwlSkip) {
					return;
				}
				if (err && err.name === 'RenderingCancelledException') {
					return;
				}
				if (window.console && window.console.warn) {
					window.console.warn('[VWL Flip Book]', err);
				}
			})
			.then(function () {
				self.busy = false;
				var q = self.queued;
				self.queued = null;
				if (q) {
					if (q.type === 'step') {
						self.runSteps(q.count);
					} else {
						self.display(q.n, q.opts);
					}
				}
			});
	};

	/* Page-turn animations (transform/opacity only, GPU friendly). */
	R.turn = function (oldSpread, newSpread, newCanvases, dir) {
		var self = this;
		var book = this.refs.book;
		var oldCanvases = oldSpread.map(function (p) {
			var sheet = self.sheets[p];
			return sheet ? sheet.querySelector('canvas') : null;
		});
		if (oldCanvases.some(function (c) {
			return !c;
		})) {
			this.layoutSpread(newSpread, newCanvases);
			return Promise.resolve();
		}
		var ms = this.cfg.animationMs || 650;
		var ease = 'cubic-bezier(0.42, 0.06, 0.3, 1)';

		var sameGeometry = oldSpread.length === 2 && newSpread.length === 2 &&
			Math.abs(oldCanvases[0].vwlW - newCanvases[0].vwlW) < 1 && Math.abs(oldCanvases[1].vwlW - newCanvases[1].vwlW) < 1 &&
			Math.abs(oldCanvases[0].vwlH - newCanvases[0].vwlH) < 1 && Math.abs(oldCanvases[1].vwlH - newCanvases[1].vwlH) < 1;

		var makeLeaf = function (front, back, fromLeft) {
			var leaf = document.createElement('div');
			leaf.className = 'vwl-ebook__leaf' + (fromLeft ? ' vwl-ebook__leaf--from-left' : '');
			var w = front.vwlW;
			var h = front.vwlH;
			leaf.style.width = w + 'px';
			leaf.style.height = h + 'px';
			var faces = [];
			[[front, 'front'], [back, 'back']].forEach(function (pair) {
				if (!pair[0]) {
					return;
				}
				var face = document.createElement('div');
				face.className = 'vwl-ebook__face vwl-ebook__face--' + pair[1];
				face.appendChild(pair[0]);
				var shade = document.createElement('div');
				shade.className = 'vwl-ebook__shade';
				face.appendChild(shade);
				leaf.appendChild(face);
				faces.push(shade);
			});
			leaf.vwlShades = faces;
			return leaf;
		};

		var shadeAnims = function (leaf) {
			var timing = { duration: ms, easing: 'linear', fill: 'forwards' };
			var s = leaf.vwlShades;
			if (s[0]) {
				animateEl(s[0], [{ opacity: 0 }, { opacity: 1, offset: 0.5 }, { opacity: 1 }], timing);
			}
			if (s[1]) {
				animateEl(s[1], [{ opacity: 1 }, { opacity: 1, offset: 0.5 }, { opacity: 0 }], timing);
			}
		};

		if (sameGeometry) {
			var leaf;
			var anim;
			if (dir > 0) {
				// Static: old left + new right underneath; leaf: old right (front) / new left (back).
				this.layoutSpread([oldSpread[0], newSpread[1]], [oldCanvases[0], newCanvases[1]]);
				var right = book.lastElementChild;
				leaf = makeLeaf(oldCanvases[1], newCanvases[0], false);
				leaf.style.left = right.offsetLeft + 'px';
				leaf.style.top = right.offsetTop + 'px';
				leaf.style.transformOrigin = '0 50%';
				book.appendChild(leaf);
				shadeAnims(leaf);
				anim = animateEl(leaf, [{ transform: 'rotateY(0deg)' }, { transform: 'rotateY(-180deg)' }], { duration: ms, easing: ease, fill: 'forwards' });
			} else {
				this.layoutSpread([newSpread[0], oldSpread[1]], [newCanvases[0], oldCanvases[1]]);
				var left = book.firstElementChild;
				leaf = makeLeaf(oldCanvases[0], newCanvases[1], true);
				leaf.style.left = left.offsetLeft + 'px';
				leaf.style.top = left.offsetTop + 'px';
				leaf.style.transformOrigin = '100% 50%';
				book.appendChild(leaf);
				shadeAnims(leaf);
				anim = animateEl(leaf, [{ transform: 'rotateY(0deg)' }, { transform: 'rotateY(180deg)' }], { duration: ms, easing: ease, fill: 'forwards' });
			}
			return anim.then(function () {
				self.layoutSpread(newSpread, newCanvases);
			});
		}

		if (!this.double && oldSpread.length === 1 && newSpread.length === 1) {
			var single;
			var fast = Math.round(ms * 0.75);
			if (dir > 0) {
				this.layoutSpread(newSpread, newCanvases);
				var target = book.firstElementChild;
				single = makeLeaf(oldCanvases[0], null, false);
				single.classList.add('vwl-ebook__leaf--single');
				single.style.left = target.offsetLeft + 'px';
				single.style.top = target.offsetTop + 'px';
				single.style.transformOrigin = '0 50%';
				book.appendChild(single);
				animateEl(single.vwlShades[0], [{ opacity: 0 }, { opacity: 1 }], { duration: fast, fill: 'forwards' });
				return animateEl(single, [
					{ transform: 'rotateY(0deg)', opacity: 1 },
					{ transform: 'rotateY(-70deg)', opacity: 1, offset: 0.75 },
					{ transform: 'rotateY(-90deg)', opacity: 0 }
				], { duration: fast, easing: 'ease-in', fill: 'forwards' }).then(function () {
					self.layoutSpread(newSpread, newCanvases);
				});
			}
			this.layoutSpread(oldSpread, oldCanvases);
			var base = book.firstElementChild;
			single = makeLeaf(newCanvases[0], null, false);
			single.classList.add('vwl-ebook__leaf--single');
			single.style.left = base.offsetLeft + 'px';
			single.style.top = base.offsetTop + 'px';
			single.style.transformOrigin = '0 50%';
			book.appendChild(single);
			animateEl(single.vwlShades[0], [{ opacity: 1 }, { opacity: 0 }], { duration: fast, fill: 'forwards' });
			return animateEl(single, [
				{ transform: 'rotateY(-90deg)', opacity: 0 },
				{ transform: 'rotateY(-70deg)', opacity: 1, offset: 0.25 },
				{ transform: 'rotateY(0deg)', opacity: 1 }
			], { duration: fast, easing: 'ease-out', fill: 'forwards' }).then(function () {
				self.layoutSpread(newSpread, newCanvases);
			});
		}

		// Different shapes (cover → spread, last odd page…): a short slide + fade.
		this.layoutSpread(newSpread, newCanvases);
		return animateEl(book, [
			{ opacity: 0.25, transform: 'translateX(' + (dir * 24) + 'px)' },
			{ opacity: 1, transform: 'translateX(0)' }
		], { duration: Math.round(ms * 0.5), easing: 'ease-out' });
	};

	R.afterDisplay = function (opts) {
		var r = this.refs;
		var n = this.numPages;
		var first = this.spread[0];
		var last = this.spread[this.spread.length - 1];
		var pct = n ? Math.round((last / n) * 100) : 0;

		if (r.pageInput && document.activeElement !== r.pageInput) {
			r.pageInput.value = first;
		}
		if (r.scrubber) {
			r.scrubber.value = first;
		}
		if (r.statusLabel) {
			if (this.narrow) {
				r.statusLabel.textContent = fmt(I.pageShort, first === last ? first : first + '–' + last, n);
			} else if (first !== last) {
				r.statusLabel.textContent = fmt(I.pagesOf, first, last, n);
			} else {
				r.statusLabel.textContent = fmt(I.pageOf, first, n);
			}
		}
		if (r.statusPct) {
			r.statusPct.textContent = fmt(I.readingPct, pct);
		}
		if (r.progressFill) {
			r.progressFill.style.width = pct + '%';
		}
		if (r.live) {
			r.live.textContent = first !== last ? fmt(I.pagesOf, first, last, n) : fmt(I.pageOf, first, n);
		}

		var atStart = first <= 1;
		var atEnd = last >= n;
		Array.prototype.forEach.call(this.root.querySelectorAll('[data-action="prev"]'), function (b) {
			b.disabled = atStart;
		});
		Array.prototype.forEach.call(this.root.querySelectorAll('[data-action="next"]'), function (b) {
			b.disabled = atEnd;
		});

		this.updateZoomUi();
		this.renderHighlights();
		this.evict(true);
		this.savePosition(false);
		this.queuePageEvent();
		this.preload();

		if (atEnd && opts && opts.user && n > 1) {
			this.reachedEnd();
		}
	};

	R.preload = function () {
		if (!this.cfg.preload || !this.spread) {
			return;
		}
		var self = this;
		var first = this.spread[0];
		var last = this.spread[this.spread.length - 1];
		idle(function () {
			var targets = [];
			if (last < self.numPages) {
				targets.push(self.spreadFor(last + 1));
			}
			if (first > 1) {
				targets.push(self.spreadFor(first - 1));
			}
			targets.reduce(function (chain, spread) {
				return chain.then(function () {
					return self.scaleFor(spread).then(function (s) {
						if (!s) {
							return null;
						}
						return Promise.all(spread.map(function (p) {
							return self.getCanvas(p, s);
						}));
					});
				}).catch(function () {});
			}, Promise.resolve());
		});
	};

	/* ---------- Navigation ---------- */

	R.next = function () {
		if (!this.loaded || !this.spread) {
			return;
		}
		if (this.busy) {
			this.queueStep(1);
			return;
		}
		var last = this.spread[this.spread.length - 1];
		if (last >= this.numPages) {
			this.reachedEnd();
			return;
		}
		this.display(last + 1, { user: true });
	};

	R.prev = function () {
		if (!this.loaded || !this.spread) {
			return;
		}
		if (this.busy) {
			this.queueStep(-1);
			return;
		}
		var first = this.spread[0];
		if (first <= 1) {
			return;
		}
		this.display(this.spreadFor(first - 1)[0], { user: true });
	};

	R.queueStep = function (dir) {
		var q = this.queued;
		if (q && q.type === 'step') {
			q.count += dir;
		} else {
			this.queued = { type: 'step', count: dir };
		}
	};

	/* Several quick clicks during an animation: jump straight to the final spread. */
	R.runSteps = function (count) {
		if (!count || !this.spread) {
			return;
		}
		if (count === 1) {
			this.next();
			return;
		}
		if (count === -1) {
			this.prev();
			return;
		}
		var spread = this.spread;
		var steps = Math.abs(count);
		for (var i = 0; i < steps; i++) {
			if (count > 0) {
				if (spread[spread.length - 1] >= this.numPages) {
					break;
				}
				spread = this.spreadFor(spread[spread.length - 1] + 1);
			} else {
				if (spread[0] <= 1) {
					break;
				}
				spread = this.spreadFor(spread[0] - 1);
			}
		}
		this.display(spread[0], { user: true });
	};

	R.goTo = function (n, opts) {
		if (!this.loaded) {
			return Promise.resolve();
		}
		n = clamp(parseInt(n, 10) || 1, 1, this.numPages);
		return this.display(n, opts || { user: true });
	};

	R.jumpFromInput = function () {
		var input = this.refs.pageInput;
		var v = parseInt(input.value, 10);
		if (!v || !this.loaded) {
			input.value = this.page;
			return;
		}
		this.goTo(v, { user: true });
		input.blur();
		this.focusStage();
	};

	R.reachedEnd = function () {
		if (!this.completed) {
			this.completed = true;
			this.emit('completed');
			this.savePosition(true);
		}
		var r = this.refs;
		if (!this.completion.enabled || !r.complete) {
			return;
		}
		if (r.completeText) {
			r.completeText.textContent = this.completion.message || I.completeTitle;
		}
		if (r.completeLink) {
			var url = this.completion.buttonUrl;
			var label = this.completion.buttonLabel;
			if (url && label && /^https?:\/\//i.test(url)) {
				r.completeLink.href = url;
				r.completeLink.textContent = label;
				r.completeLink.hidden = false;
			}
		}
		r.complete.hidden = false;
		var btn = r.complete.querySelector('[data-action="read-again"]');
		if (btn) {
			btn.focus();
		}
	};

	/* ---------- Zoom ---------- */

	R.updateZoomUi = function () {
		var r = this.refs;
		var pct = Math.round((this.scale / CSS_UNITS) * 100);
		if (r.zoomValue) {
			r.zoomValue.textContent = pct + '%';
			r.zoomValue.setAttribute('aria-label', fmt(I.zoomLabel, pct));
		}
		var mode = this.zoomMode;
		Array.prototype.forEach.call(this.root.querySelectorAll('.vwl-ebook__btn[data-action="fit-width"], .vwl-ebook__btn[data-action="fit-page"]'), function (b) {
			b.setAttribute('aria-pressed', b.getAttribute('data-action') === mode ? 'true' : 'false');
		});
	};

	R.setZoomMode = function (mode) {
		this.zoomMode = mode;
		this.userZoom = true;
		this.rerender();
	};

	R.zoomReset = function () {
		this.userZoom = false;
		this.zoomMode = this.defaultZoomMode();
		this.rerender();
	};

	R.zoomStep = function (dir) {
		if (!this.loaded) {
			return;
		}
		var cur = this.scale / CSS_UNITS;
		var next = cur;
		var i;
		if (dir > 0) {
			for (i = 0; i < ZOOM_STEPS.length; i++) {
				if (ZOOM_STEPS[i] > cur * 1.02) {
					next = ZOOM_STEPS[i];
					break;
				}
			}
		} else {
			for (i = ZOOM_STEPS.length - 1; i >= 0; i--) {
				if (ZOOM_STEPS[i] < cur * 0.98) {
					next = ZOOM_STEPS[i];
					break;
				}
			}
		}
		this.applyZoom(next);
	};

	R.applyZoom = function (value, anchor) {
		this.zoomValue = clamp(value, MIN_ZOOM, MAX_ZOOM);
		this.zoomMode = 'custom';
		this.userZoom = true;
		this.rerender(anchor);
	};

	/* Re-render at a new scale, keeping the anchor point (or the centre) in place. */
	R.rerender = function (anchor) {
		var self = this;
		if (!this.loaded) {
			return;
		}
		var vp = this.refs.viewport;
		var book = this.refs.book;
		var ax = anchor ? anchor.x : vp.clientWidth / 2;
		var ay = anchor ? anchor.y : vp.clientHeight / 2;
		var bx = vp.scrollLeft + ax - book.offsetLeft;
		var by = vp.scrollTop + ay - book.offsetTop;
		var old = this.scale;
		this.display(this.page, { instant: true }).then(function () {
			var k = self.scale / old;
			vp.scrollLeft = book.offsetLeft + bx * k - ax;
			vp.scrollTop = book.offsetTop + by * k - ay;
		});
	};

	R.pointInViewport = function (clientX, clientY) {
		var rect = this.refs.viewport.getBoundingClientRect();
		return { x: clientX - rect.left, y: clientY - rect.top };
	};

	R.previewScale = function (factor, anchor) {
		var vp = this.refs.viewport;
		var book = this.refs.book;
		var ox = vp.scrollLeft + anchor.x - book.offsetLeft;
		var oy = vp.scrollTop + anchor.y - book.offsetTop;
		book.style.transformOrigin = ox + 'px ' + oy + 'px';
		book.style.transform = 'scale(' + factor + ')';
	};

	R.clearPreview = function () {
		this.refs.book.style.transform = '';
		this.refs.book.style.transformOrigin = '';
	};

	R.onWheel = function (e) {
		if (!(e.ctrlKey || e.metaKey) || !this.loaded) {
			return;
		}
		e.preventDefault();
		var self = this;
		var delta = e.deltaY * (e.deltaMode === 1 ? 16 : e.deltaMode === 2 ? 400 : 1);
		if (!this.wheel) {
			this.wheel = { start: this.scale / CSS_UNITS, factor: 1, anchor: this.pointInViewport(e.clientX, e.clientY) };
		}
		var w = this.wheel;
		w.factor = clamp(w.factor * Math.exp(-delta * 0.0022), MIN_ZOOM / w.start, MAX_ZOOM / w.start);
		this.previewScale(w.factor, w.anchor);
		window.clearTimeout(this.wheelTimer);
		this.wheelTimer = window.setTimeout(function () {
			var done = self.wheel;
			self.wheel = null;
			self.clearPreview();
			self.applyZoom(done.start * done.factor, done.anchor);
		}, 160);
	};

	/* ---------- Touch: swipe, pinch, double tap ---------- */

	R.onTouchStart = function (e) {
		if (!this.loaded) {
			return;
		}
		var vp = this.refs.viewport;
		if (e.touches.length === 2) {
			var a = e.touches[0];
			var b = e.touches[1];
			var mid = this.pointInViewport((a.clientX + b.clientX) / 2, (a.clientY + b.clientY) / 2);
			this.pinch = {
				d0: Math.hypot(a.clientX - b.clientX, a.clientY - b.clientY) || 1,
				start: this.scale / CSS_UNITS,
				anchor: mid,
				ratio: 1
			};
			this.swipe = null;
			e.preventDefault();
		} else if (e.touches.length === 1 && !this.pinch) {
			var t = e.touches[0];
			this.swipe = {
				x: t.clientX,
				y: t.clientY,
				time: Date.now(),
				atLeft: vp.scrollLeft <= 1,
				atRight: vp.scrollLeft + vp.clientWidth >= vp.scrollWidth - 1
			};
		}
	};

	R.onTouchMove = function (e) {
		if (!this.pinch || e.touches.length !== 2) {
			return;
		}
		e.preventDefault();
		var a = e.touches[0];
		var b = e.touches[1];
		var d = Math.hypot(a.clientX - b.clientX, a.clientY - b.clientY);
		var p = this.pinch;
		p.ratio = clamp(d / p.d0, MIN_ZOOM / p.start, MAX_ZOOM / p.start);
		this.previewScale(p.ratio, p.anchor);
	};

	R.cancelPinch = function () {
		if (this.pinch) {
			this.pinch = null;
			this.clearPreview();
		}
		this.swipe = null;
	};

	R.onTouchEnd = function (e) {
		if (this.pinch) {
			if (e.touches.length < 2) {
				var p = this.pinch;
				this.pinch = null;
				this.clearPreview();
				this.lastPinch = Date.now();
				if (Math.abs(p.ratio - 1) > 0.03) {
					this.applyZoom(p.start * p.ratio, p.anchor);
				}
			}
			this.swipe = null;
			return;
		}
		var s = this.swipe;
		this.swipe = null;
		if (!s || !e.changedTouches.length || Date.now() - (this.lastPinch || 0) < 350) {
			return;
		}
		var t = e.changedTouches[0];
		var dx = t.clientX - s.x;
		var dy = t.clientY - s.y;
		var dt = Date.now() - s.time;

		if (Math.abs(dx) > 45 && Math.abs(dx) > Math.abs(dy) * 1.4 && dt < 800) {
			var vp = this.refs.viewport;
			var scrollable = vp.scrollWidth > vp.clientWidth + 2;
			if (!scrollable || (dx < 0 && s.atRight) || (dx > 0 && s.atLeft)) {
				if (dx < 0) {
					this.next();
				} else {
					this.prev();
				}
			}
			return;
		}

		if (Math.abs(dx) < 12 && Math.abs(dy) < 12 && dt < 300) {
			var now = Date.now();
			var last = this.lastTap;
			if (last && now - last.time < 320 && Math.hypot(t.clientX - last.x, t.clientY - last.y) < 36) {
				e.preventDefault();
				this.lastTap = null;
				this.doubleTapZoom(this.pointInViewport(t.clientX, t.clientY));
			} else {
				this.lastTap = { time: now, x: t.clientX, y: t.clientY };
			}
		}
	};

	R.doubleTapZoom = function (anchor) {
		if (this.zoomMode === 'custom') {
			this.zoomReset();
			return;
		}
		this.applyZoom((this.scale / CSS_UNITS) * 2, anchor);
	};

	/* ---------- Fullscreen ---------- */

	R.isNativeFs = function () {
		var el = document.fullscreenElement || document.webkitFullscreenElement;
		return el === this.root;
	};

	R.toggleFullscreen = function () {
		var self = this;
		if (this.pseudoFs) {
			this.exitPseudoFullscreen();
			return;
		}
		if (this.isNativeFs()) {
			var exit = document.exitFullscreen || document.webkitExitFullscreen;
			if (exit) {
				exit.call(document);
			}
			return;
		}
		var req = this.root.requestFullscreen || this.root.webkitRequestFullscreen;
		if (!req) {
			this.enterPseudoFullscreen();
			return;
		}
		try {
			var p = req.call(this.root);
			if (p && p.catch) {
				p.catch(function () {
					self.enterPseudoFullscreen();
				});
			}
		} catch (e) {
			this.enterPseudoFullscreen();
		}
	};

	R.onFullscreenChange = function () {
		var on = this.isNativeFs();
		this.root.classList.toggle('vwl-ebook--fs', on || !!this.pseudoFs);
		this.updateFsButtons();
		this.focusStage();
	};

	R.updateFsButtons = function () {
		var on = this.root.classList.contains('vwl-ebook--fs');
		Array.prototype.forEach.call(this.root.querySelectorAll('[data-action="fullscreen"]'), function (b) {
			b.setAttribute('aria-pressed', on ? 'true' : 'false');
		});
	};

	R.enterPseudoFullscreen = function () {
		var root = this.root;
		if (this.pseudoFs) {
			return;
		}
		// Move the reader to <body> so theme containers with transforms/overflow cannot clip it.
		this.placeholder = document.createComment('vwl-ebook-placeholder');
		root.parentNode.insertBefore(this.placeholder, root);
		document.body.appendChild(root);
		this.pseudoFs = true;
		root.classList.add('vwl-ebook--fs', 'vwl-ebook--pseudo-fs');
		document.documentElement.classList.add('vwl-ebook-lock');
		this.updateFsButtons();
		this.focusStage();
	};

	R.exitPseudoFullscreen = function () {
		var root = this.root;
		if (!this.pseudoFs) {
			return;
		}
		this.pseudoFs = false;
		if (this.placeholder && this.placeholder.parentNode) {
			this.placeholder.parentNode.insertBefore(root, this.placeholder);
			this.placeholder.parentNode.removeChild(this.placeholder);
		}
		this.placeholder = null;
		root.classList.remove('vwl-ebook--fs', 'vwl-ebook--pseudo-fs');
		document.documentElement.classList.remove('vwl-ebook-lock');
		this.updateFsButtons();
		this.focusStage();
	};

	/* ---------- Menu & TOC ---------- */

	R.toggleMenu = function () {
		var m = this.refs.menu;
		if (!m) {
			return;
		}
		if (m.hidden) {
			m.hidden = false;
			if (this.refs.moreBtn) {
				this.refs.moreBtn.setAttribute('aria-expanded', 'true');
			}
			var first = m.querySelector('.vwl-ebook__menu-item:not([hidden])');
			if (first) {
				first.focus();
			}
		} else {
			this.closeMenu();
		}
	};

	R.closeMenu = function () {
		if (this.refs.menu) {
			this.refs.menu.hidden = true;
		}
		if (this.refs.moreBtn) {
			this.refs.moreBtn.setAttribute('aria-expanded', 'false');
		}
	};

	R.setupToc = function () {
		var self = this;
		var r = this.refs;
		if (!this.f.toc || !r.tocList || !this.pdf) {
			return;
		}
		this.pdf.getOutline().then(function (outline) {
			if (!outline || !outline.length) {
				return;
			}
			var count = 0;
			var frag = document.createDocumentFragment();
			var add = function (items, level) {
				items.forEach(function (item) {
					if (count > 2000) {
						return;
					}
					count++;
					var b = document.createElement('button');
					b.type = 'button';
					b.className = 'vwl-ebook__toc-item';
					b.setAttribute('data-level', Math.min(level, 3));
					b.textContent = (item.title || '').trim() || '—';
					b.vwlDest = item.dest;
					b.vwlUrl = item.url || '';
					frag.appendChild(b);
					if (item.items && item.items.length) {
						add(item.items, level + 1);
					}
				});
			};
			add(outline, 0);
			r.tocList.textContent = '';
			r.tocList.appendChild(frag);
			if (r.tocBtn) {
				r.tocBtn.hidden = false;
			}
			if (r.tocMenuItem) {
				r.tocMenuItem.hidden = false;
			}
			self.hasToc = true;
		}).catch(function () {});
	};

	R.toggleToc = function () {
		if (this.root.classList.contains('vwl-ebook--toc-open')) {
			this.closeToc();
		} else {
			this.openToc();
		}
	};

	R.openToc = function () {
		var r = this.refs;
		if (!r.toc || !this.hasToc) {
			return;
		}
		window.clearTimeout(this.tocTimer);
		r.toc.hidden = false;
		if (r.scrim) {
			r.scrim.hidden = false;
		}
		void r.toc.offsetWidth; // reflow so the slide-in transition runs
		this.root.classList.add('vwl-ebook--toc-open');
		if (r.tocBtn) {
			r.tocBtn.setAttribute('aria-expanded', 'true');
		}
		var first = r.tocList.querySelector('.vwl-ebook__toc-item');
		if (first) {
			first.focus();
		}
	};

	R.closeToc = function () {
		var r = this.refs;
		if (!r.toc) {
			return;
		}
		this.root.classList.remove('vwl-ebook--toc-open');
		if (r.tocBtn) {
			r.tocBtn.setAttribute('aria-expanded', 'false');
		}
		this.tocTimer = window.setTimeout(function () {
			r.toc.hidden = true;
			if (r.scrim) {
				r.scrim.hidden = true;
			}
		}, 260);
		this.focusStage();
	};

	R.openTocItem = function (btn) {
		var self = this;
		if (btn.vwlUrl) {
			if (/^https?:\/\//i.test(btn.vwlUrl)) {
				window.open(btn.vwlUrl, '_blank', 'noopener');
			}
			return;
		}
		this.resolveDest(btn.vwlDest).then(function (page) {
			if (page) {
				self.closeToc();
				self.goTo(page, { user: true });
			}
		}).catch(function () {});
	};

	R.resolveDest = function (dest) {
		var pdf = this.pdf;
		var p = typeof dest === 'string' ? pdf.getDestination(dest) : Promise.resolve(dest);
		return p.then(function (explicit) {
			if (!Array.isArray(explicit) || !explicit.length) {
				return 0;
			}
			var ref = explicit[0];
			if (ref && typeof ref === 'object') {
				return pdf.getPageIndex(ref).then(function (i) {
					return i + 1;
				});
			}
			if (typeof ref === 'number') {
				return ref + 1;
			}
			return 0;
		});
	};

	/* ---------- Search ---------- */

	R.openSearch = function () {
		var r = this.refs;
		if (!r.searchBar) {
			return;
		}
		r.searchBar.hidden = false;
		this.search.open = true;
		Array.prototype.forEach.call(this.root.querySelectorAll('.vwl-ebook__btn[data-action="search"]'), function (b) {
			b.setAttribute('aria-expanded', 'true');
		});
		r.searchInput.focus();
		r.searchInput.select();
		if (this.loaded) {
			this.ensureText().catch(function () {});
		}
		this.onResize();
	};

	R.closeSearch = function () {
		var r = this.refs;
		if (!r.searchBar) {
			return;
		}
		r.searchBar.hidden = true;
		this.search.open = false;
		this.search.matches = [];
		this.search.index = -1;
		this.search.query = '';
		this.search.token++;
		if (r.searchStatus) {
			r.searchStatus.textContent = '';
		}
		Array.prototype.forEach.call(this.root.querySelectorAll('.vwl-ebook__btn[data-action="search"]'), function (b) {
			b.setAttribute('aria-expanded', 'false');
		});
		this.renderHighlights();
		this.focusStage();
		this.onResize();
	};

	R.setSearchStatus = function (text) {
		if (this.refs.searchStatus) {
			this.refs.searchStatus.textContent = text || '';
		}
	};

	R.ensureText = function () {
		var self = this;
		var s = this.search;
		if (s.texts) {
			return Promise.resolve(s.texts);
		}
		if (s.extracting) {
			return s.extracting;
		}
		var n = this.numPages;
		var texts = new Array(n + 1);
		var p = 1;
		var step = function () {
			if (p > n) {
				s.texts = texts;
				s.hasText = texts.some(function (t) {
					return t && /\S/.test(t.str);
				});
				return texts;
			}
			var current = p;
			return self.getPage(current).then(function (page) {
				return page.getTextContent();
			}).then(function (tc) {
				var str = '';
				var items = [];
				tc.items.forEach(function (it) {
					if (typeof it.str !== 'string') {
						return;
					}
					var start = str.length;
					str += it.str;
					items.push({ s: start, e: str.length, tx: it.transform, w: it.width, h: it.height });
					if (it.hasEOL) {
						str += ' ';
					}
				});
				texts[current] = { str: str, items: items };
			}).catch(function () {
				texts[current] = { str: '', items: [] };
			}).then(function () {
				p++;
				if (s.query && p % 4 === 0) {
					self.setSearchStatus(fmt(I.searching, Math.round((p / n) * 100)));
				}
				return p % 8 === 0 ? nextFrame().then(step) : step();
			});
		};
		s.extracting = step();
		return s.extracting;
	};

	R.runSearch = function (raw) {
		var self = this;
		var s = this.search;
		var q = String(raw || '').trim();
		var token = ++s.token;
		s.query = q;
		s.matches = [];
		s.index = -1;
		if (!q || !this.loaded) {
			this.setSearchStatus('');
			this.renderHighlights();
			return;
		}
		this.setSearchStatus(fmt(I.searching, 0));
		this.ensureText().then(function (texts) {
			if (token !== s.token) {
				return;
			}
			if (!s.hasText) {
				self.setSearchStatus(I.searchNoText);
				return;
			}
			var re = new RegExp(escapeRe(q).replace(/\s+/g, '\\s+'), 'gi');
			var matches = [];
			for (var p = 1; p <= self.numPages && matches.length < 1000; p++) {
				var str = texts[p] ? texts[p].str : '';
				re.lastIndex = 0;
				var m;
				while ((m = re.exec(str)) && matches.length < 1000) {
					matches.push({ p: p, s: m.index, e: m.index + m[0].length });
					if (!m[0].length) {
						re.lastIndex++;
					}
				}
			}
			s.matches = matches;
			s.capped = matches.length >= 1000;
			self.emit('search', { query: q, results: matches.length });
			if (!matches.length) {
				self.setSearchStatus(I.noMatches);
				self.renderHighlights();
				return;
			}
			var idx = 0;
			for (var i = 0; i < matches.length; i++) {
				if (matches[i].p >= self.page) {
					idx = i;
					break;
				}
			}
			self.gotoMatch(idx);
		}).catch(function () {
			self.setSearchStatus(I.searchNoText);
		});
	};

	R.stepMatch = function (dir) {
		var s = this.search;
		if (!s.matches.length) {
			if (this.refs.searchInput && this.refs.searchInput.value.trim()) {
				this.runSearch(this.refs.searchInput.value);
			}
			return;
		}
		var i = (s.index + dir + s.matches.length) % s.matches.length;
		this.gotoMatch(i);
	};

	R.gotoMatch = function (i) {
		var s = this.search;
		s.index = i;
		var m = s.matches[i];
		this.setSearchStatus(fmt(s.capped ? I.matchOfMany : I.matchOf, i + 1, s.matches.length));
		if (this.spread && this.spread.indexOf(m.p) !== -1) {
			this.renderHighlights();
		} else {
			this.goTo(m.p, { user: true, instant: true });
		}
	};

	R.matchRects = function (pageNum, match) {
		var text = this.search.texts && this.search.texts[pageNum];
		var page = this.pages[pageNum];
		if (!text || !page) {
			return [];
		}
		var vp = page.getViewport({ scale: this.scale });
		var rects = [];
		text.items.forEach(function (it) {
			var a = Math.max(match.s, it.s);
			var b = Math.min(match.e, it.e);
			if (a >= b || !it.tx) {
				return;
			}
			var len = it.e - it.s || 1;
			var tx = it.tx;
			var x0 = tx[4] + (it.w * (a - it.s)) / len;
			var x1 = tx[4] + (it.w * (b - it.s)) / len;
			var fh = Math.hypot(tx[2], tx[3]) || it.h || 10;
			var r = vp.convertToViewportRectangle([x0, tx[5] - fh * 0.22, x1, tx[5] + fh * 0.88]);
			rects.push({
				left: Math.min(r[0], r[2]),
				top: Math.min(r[1], r[3]),
				width: Math.abs(r[2] - r[0]),
				height: Math.abs(r[3] - r[1])
			});
		});
		return rects;
	};

	R.renderHighlights = function () {
		var self = this;
		var s = this.search;
		var currentEl = null;
		Object.keys(this.sheets).forEach(function (key) {
			var sheet = self.sheets[key];
			var layer = sheet.querySelector('.vwl-ebook__hl-layer');
			if (!layer) {
				return;
			}
			layer.textContent = '';
			if (!s.open || !s.matches.length) {
				return;
			}
			var pageNum = parseInt(key, 10);
			s.matches.forEach(function (m, idx) {
				if (m.p !== pageNum) {
					return;
				}
				self.matchRects(pageNum, m).forEach(function (rc) {
					var d = document.createElement('span');
					d.className = 'vwl-ebook__hl' + (idx === s.index ? ' vwl-ebook__hl--current' : '');
					d.style.left = rc.left + 'px';
					d.style.top = rc.top + 'px';
					d.style.width = rc.width + 'px';
					d.style.height = rc.height + 'px';
					layer.appendChild(d);
					if (idx === s.index && !currentEl) {
						currentEl = d;
					}
				});
			});
		});
		if (currentEl) {
			var vp = this.refs.viewport;
			var r = currentEl.getBoundingClientRect();
			var v = vp.getBoundingClientRect();
			if (r.top < v.top || r.bottom > v.bottom) {
				vp.scrollTop += r.top - v.top - v.height / 3;
			}
			if (r.left < v.left || r.right > v.right) {
				vp.scrollLeft += r.left - v.left - v.width / 3;
			}
		}
	};

	/* ---------- Print ---------- */

	R.toast = function (text, sticky) {
		var t = this.refs.toast;
		if (!t) {
			return;
		}
		window.clearTimeout(this.toastTimer);
		if (!text) {
			t.hidden = true;
			return;
		}
		t.textContent = text;
		t.hidden = false;
		if (!sticky) {
			this.toastTimer = window.setTimeout(function () {
				t.hidden = true;
			}, 2600);
		}
	};

	R.print = function () {
		var self = this;
		if (!this.f.print || !this.loaded || this.printing) {
			return;
		}
		this.printing = true;
		var n = this.numPages;
		var box = document.createElement('div');
		box.className = 'vwl-ebook-print';
		var urls = [];
		var cleanup = function () {
			window.removeEventListener('afterprint', cleanup);
			window.clearTimeout(self.printTimer);
			document.body.classList.remove('vwl-ebook-printing');
			if (box.parentNode) {
				box.parentNode.removeChild(box);
			}
			urls.forEach(function (u) {
				try {
					URL.revokeObjectURL(u);
				} catch (e) {}
			});
			self.printing = false;
		};

		var renderOne = function (p) {
			self.toast(fmt(I.printPreparing, p, n), true);
			return self.getPage(p).then(function (page) {
				var base = page.getViewport({ scale: 1 });
				var s = Math.min(2, 2200 / Math.max(base.width, base.height));
				var vp = page.getViewport({ scale: s });
				var c = document.createElement('canvas');
				c.width = Math.floor(vp.width);
				c.height = Math.floor(vp.height);
				var ctx = c.getContext('2d', { alpha: false });
				ctx.fillStyle = '#fff';
				ctx.fillRect(0, 0, c.width, c.height);
				return page.render({ canvasContext: ctx, viewport: vp }).promise.then(function () {
					return new Promise(function (resolve) {
						var img = new Image();
						img.alt = '';
						var finish = function (src) {
							img.onload = function () {
								resolve();
							};
							img.onerror = function () {
								resolve();
							};
							img.src = src;
							box.appendChild(img);
							c.width = 0;
							c.height = 0;
						};
						if (c.toBlob && window.URL && URL.createObjectURL) {
							c.toBlob(function (blob) {
								if (!blob) {
									finish(c.toDataURL('image/jpeg', 0.9));
									return;
								}
								var u = URL.createObjectURL(blob);
								urls.push(u);
								finish(u);
							}, 'image/jpeg', 0.9);
						} else {
							finish(c.toDataURL('image/jpeg', 0.9));
						}
					});
				});
			});
		};

		var chain = Promise.resolve();
		for (var p = 1; p <= n; p++) {
			chain = chain.then(renderOne.bind(null, p));
		}
		chain.then(function () {
			self.toast('');
			document.body.appendChild(box);
			document.body.classList.add('vwl-ebook-printing');
			window.addEventListener('afterprint', cleanup);
			self.printTimer = window.setTimeout(cleanup, 600000);
			window.setTimeout(function () {
				window.print();
			}, 60);
		}).catch(function () {
			self.toast(ERR.generic);
			cleanup();
		});
	};

	/* ---------- Events / analytics ---------- */

	R.emit = function (name, extra) {
		var detail = {
			ebookId: this.cfg.ebookId || 0,
			fileId: this.cfg.fileId || '',
			page: this.page,
			totalPages: this.numPages
		};
		if (extra) {
			for (var k in extra) {
				if (Object.prototype.hasOwnProperty.call(extra, k)) {
					detail[k] = extra[k];
				}
			}
		}
		try {
			this.root.dispatchEvent(new window.CustomEvent('vwl-ebook:' + name, { bubbles: true, detail: detail }));
		} catch (e) {}

		if (!this.cfg.analytics || !navigator.sendBeacon || !window.FormData) {
			return;
		}
		var map = { opened: 'opened', page: 'page_viewed', completed: 'completed', search: 'search' };
		if (!map[name]) {
			return;
		}
		var fd = new window.FormData();
		fd.append('action', 'vwl_ebook_event');
		fd.append('event', map[name]);
		fd.append('id', this.cfg.fileId);
		fd.append('t', this.cfg.token);
		fd.append('ebook', String(detail.ebookId));
		fd.append('page', String(detail.page));
		fd.append('total', String(detail.totalPages));
		fd.append('source', window.location.href.split('#')[0]);
		if (extra && extra.query) {
			fd.append('query', String(extra.query).slice(0, 120));
		}
		try {
			navigator.sendBeacon(G.ajaxUrl, fd);
		} catch (e) {}
	};

	R.queuePageEvent = function () {
		var self = this;
		window.clearTimeout(this.pageEventTimer);
		this.pageEventTimer = window.setTimeout(function () {
			if (self.lastEventPage !== self.page) {
				self.lastEventPage = self.page;
				self.emit('page');
			}
		}, 1500);
	};

	/* ------------------------------------------------------------------ */
	/* Boot                                                               */
	/* ------------------------------------------------------------------ */

	function initAll(scope) {
		var ctx = scope && scope.querySelectorAll ? scope : document;
		Array.prototype.forEach.call(ctx.querySelectorAll('.vwl-ebook[data-vwl-ebook]'), function (node) {
			if (node.vwlEbookInit) {
				return;
			}
			node.vwlEbookInit = true;
			try {
				new Reader(node);
			} catch (e) {
				if (window.console && window.console.error) {
					window.console.error('[VWL Flip Book]', e);
				}
			}
		});
	}

	window.VWLEbookReader = { init: initAll, Reader: Reader };

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			initAll();
		});
	} else {
		initAll();
	}

	// Elementor renders widgets dynamically in its editor and in popups.
	if (window.jQuery) {
		window.jQuery(window).on('elementor/frontend/init', function () {
			var ef = window.elementorFrontend;
			if (ef && ef.hooks) {
				ef.hooks.addAction('frontend/element_ready/global', function ($scope) {
					initAll($scope && $scope[0] ? $scope[0] : document);
				});
			}
		});
	}
})(window, document);
