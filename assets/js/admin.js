/* KI-Kennzeichnung – admin UI (media library and settings page). */
(function () {
	'use strict';

	var cfg = window.kikzAdmin || {};
	var T = cfg.i18n || {};

	function fmt(str) {
		var args = Array.prototype.slice.call(arguments, 1), i = 0;
		return String(str).replace(/%(?:(\d+)\$)?d|%(?:(\d+)\$)?s/g, function (m, a, b) {
			var idx = a || b;
			return String(idx ? args[idx - 1] : args[i++]);
		});
	}

	function esc(value) {
		return String(value).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function post(action, data) {
		var body = new URLSearchParams();
		body.append('action', action);
		body.append('nonce', cfg.nonce);
		Object.keys(data || {}).forEach(function (key) {
			var val = data[key];
			if (Array.isArray(val)) { val.forEach(function (v) { body.append(key + '[]', v); }); }
			else { body.append(key, val); }
		});
		return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); });
	}

	function $(id) { return document.getElementById(id); }

	function setStats(stats) {
		if (!stats) { return; }
		[['kikz-count', 'flagged'], ['kikz-stamped', 'stamped'], ['kikz-pending', 'pending'], ['kikz-failed', 'failed']].forEach(function (pair) {
			var el = $(pair[0]);
			if (el && typeof stats[pair[1]] === 'number') { el.textContent = stats[pair[1]]; }
		});
	}

	// ------------------------------------------------------------------
	// Media list: clickable chip.
	// ------------------------------------------------------------------
	document.addEventListener('click', function (e) {
		var btn = e.target.closest ? e.target.closest('.kikz-chip') : null;
		if (!btn || btn.disabled) { return; }
		e.preventDefault();
		var next = btn.getAttribute('data-flagged') === '1' ? '0' : '1';
		function send(force) {
			btn.disabled = true;
			post('kikz_toggle', { id: btn.getAttribute('data-id'), state: next, force: force ? '1' : '' })
				.then(function (res) {
					if (res && res.success) {
						var tmp = document.createElement('div');
						tmp.innerHTML = res.data.chip;
						var fresh = tmp.firstChild;
						btn.parentNode.replaceChild(fresh, btn);
						btn = fresh;
						setStats(res.data.stats);
						if (res.data.message) { fresh.title = res.data.message; }
					} else if (res && res.data && res.data.needsConfirm) {
						btn.disabled = false;
						if (window.confirm(T.modified || res.data.message)) { send(true); }
					} else {
						window.alert((res && res.data && res.data.message) || T.error);
					}
				})
				.catch(function () { window.alert(T.error); })
				.then(function () { if (btn) { btn.disabled = false; } });
		}
		send(false);
	});

	// ------------------------------------------------------------------
	// Grid view: badge from the attachment model (no ID list, no limit).
	// ------------------------------------------------------------------
	function decorateGrid() {
		if (!window.wp || !wp.media || !wp.media.view || !wp.media.view.Attachment) { return; }
		var proto = wp.media.view.Attachment.prototype;
		if (proto.__kikzPatched) { return; }
		proto.__kikzPatched = true;
		var orig = proto.render;
		proto.render = function () {
			var out = orig.apply(this, arguments);
			try {
				if (this.model && this.model.get('kikzFlagged') && this.$el && !this.$el.find('.kikz-grid-badge').length) {
					var warn = ['failed', 'unsupported'].indexOf(this.model.get('kikzStatus')) !== -1;
					this.$el.append('<span class="kikz-grid-badge' + (warn ? ' is-warn' : '') + '">KI</span>');
				}
			} catch (err) { /* cosmetic only */ }
			return out;
		};
	}
	if (!cfg.isSettings) {
		if (document.readyState !== 'loading') { decorateGrid(); } else { document.addEventListener('DOMContentLoaded', decorateGrid); }
	}

	// ------------------------------------------------------------------
	// Queue runner (bulk actions, scans and re-stamping run in the background).
	// ------------------------------------------------------------------
	var running = false;
	function queueMessage(html) {
		var box = $('kikz-queue-msg');
		if (!box) {
			var anchor = document.querySelector('.wrap .wp-header-end') || document.querySelector('.wrap h1');
			if (!anchor) { return; }
			box = document.createElement('div');
			box.id = 'kikz-queue-msg';
			box.className = 'notice notice-info inline';
			anchor.parentNode.insertBefore(box, anchor.nextSibling);
		}
		box.innerHTML = html ? '<p>' + html + '</p>' : '';
	}

	function runQueue(initialLeft) {
		if (running) { return; }
		running = true;
		var processed = 0, busy = 0, errors = [];
		function step() {
			post('kikz_queue_run', {}).then(function (res) {
				if (!res || !res.success) { running = false; queueMessage(esc(T.error)); return; }
				var d = res.data;
				processed += d.done;
				errors = errors.concat(d.errors || []);
				setStats(d.stats);
				if (d.busy && busy++ < 20) { setTimeout(step, 3000); return; }
				if (d.left > 0 && (d.done > 0 || d.busy)) {
					queueMessage(esc(fmt(T.queueLeft, d.left)));
					setTimeout(step, 400);
					return;
				}
				running = false;
				var msg = esc(T.queueDone);
				if (errors.length) {
					msg += '<br>' + esc(T.errors) + '<ul class="kikz-issues">' + errors.slice(0, 8).map(function (m) { return '<li>' + esc(m) + '</li>'; }).join('') + '</ul>';
				}
				queueMessage(msg);
				if (!cfg.isSettings && processed > 0) { setTimeout(function () { window.location.reload(); }, 1500); }
			}).catch(function () { running = false; queueMessage(esc(T.error)); });
		}
		queueMessage(esc(fmt(T.queueLeft, initialLeft)));
		step();
	}
	if (cfg.pending > 0) {
		if (document.readyState !== 'loading') { runQueue(cfg.pending); } else { document.addEventListener('DOMContentLoaded', function () { runQueue(cfg.pending); }); }
	}

	if (!cfg.isSettings) { return; }

	// ------------------------------------------------------------------
	// Settings page.
	// ------------------------------------------------------------------
	var presets = { dark: { bg: 'rgba(0,0,0,.65)', fg: '#ffffff' }, light: { bg: 'rgba(255,255,255,.88)', fg: '#1d2327' } };
	function validColor(v) { return /^(#[0-9a-f]{3,8}|rgba?\([\d\s.,%\/]+\))$/i.test(String(v || '').trim()); }

	function bindPreview() {
		var el = function (id) { return $(id); };
		var demo = el('kikz-badge-demo'), preview = el('kikz-preview'), noticeDemo = el('kikz-notice-demo');
		if (!demo) { return; }
		function sync() {
			var short = el('kikz_badge_short'), text = el('kikz_badge_text'), size = el('kikz_badge_size'), pos = el('kikz_badge_corner');
			var theme = el('kikz_badge_theme'), bg = el('kikz_badge_bg_color'), fg = el('kikz_badge_color'), font = el('kikz_badge_font');
			var notice = el('kikz_footnote'), noticeOn = el('kikz_footnote_on'), margin = el('kikz_badge_margin');
			demo.textContent = (short && short.checked) ? 'KI' : (text && text.value ? text.value : 'KI-generiert');
			demo.style.fontSize = ({ small: '11px', medium: '12px', large: '14px' })[size ? size.value : 'medium'] || '12px';
			demo.className = 'kikz-badge-demo pos-' + (pos ? pos.value : 'bottom-right');
			var p = presets[theme && theme.value === 'light' ? 'light' : 'dark'];
			demo.style.setProperty('--kikz-demo-bg', bg && validColor(bg.value) ? bg.value : p.bg);
			demo.style.setProperty('--kikz-demo-fg', fg && validColor(fg.value) ? fg.value : p.fg);
			demo.style.fontFamily = font && font.value ? font.value : '';
			demo.style.margin = '0';
			var m = margin ? Math.max(0, Math.min(60, parseInt(margin.value, 10) || 0)) : 12;
			['top', 'bottom', 'left', 'right'].forEach(function (side) { if (demo.style[side]) { demo.style[side] = ''; } });
			demo.style.setProperty('--kikz-m', m + 'px');
			if (noticeDemo && notice) { noticeDemo.textContent = notice.value || ''; }
			if (preview && noticeOn) { preview.classList.toggle('show-notice', noticeOn.checked); }
		}
		['kikz_badge_text', 'kikz_footnote', 'kikz_badge_bg_color', 'kikz_badge_color', 'kikz_badge_font', 'kikz_badge_margin'].forEach(function (id) {
			var node = el(id); if (node) { node.addEventListener('input', sync); }
		});
		['kikz_badge_short', 'kikz_badge_size', 'kikz_badge_corner', 'kikz_badge_theme', 'kikz_footnote_on'].forEach(function (id) {
			var node = el(id); if (node) { node.addEventListener('change', sync); }
		});
		sync();
	}

	// Preview image picker.
	function bindPicker() {
		var pick = $('kikz-pick-image'), clear = $('kikz-clear-image'), input = $('kikz_preview_image');
		var img = $('kikz-preview-img'), ph = $('kikz-preview-placeholder'), box = $('kikz-preview');
		function apply(url, id) {
			if (!img) { return; }
			if (url) {
				img.src = url; img.style.display = 'block';
				if (box) { box.classList.add('has-image'); }
				if (ph) { ph.style.display = 'none'; }
				if (input) { input.value = id || ''; }
				if (pick) { pick.textContent = T.change; }
				if (clear) { clear.style.display = 'inline-block'; }
			} else {
				img.removeAttribute('src'); img.style.display = 'none';
				if (box) { box.classList.remove('has-image'); }
				if (ph) { ph.style.display = ''; }
				if (input) { input.value = '0'; }
				if (pick) { pick.textContent = T.pick; }
				if (clear) { clear.style.display = 'none'; }
			}
		}
		var frame;
		if (pick) {
			pick.addEventListener('click', function (e) {
				e.preventDefault();
				if (!window.wp || !wp.media) { return; }
				if (!frame) {
					frame = wp.media({ title: T.pick, multiple: false, library: { type: 'image' } });
					frame.on('select', function () {
						var a = frame.state().get('selection').first().toJSON();
						apply((a.sizes && a.sizes.large ? a.sizes.large.url : a.url), a.id);
					});
				}
				frame.open();
			});
		}
		if (clear) { clear.addEventListener('click', function (e) { e.preventDefault(); apply('', 0); }); }
	}

	// Metadata scan.
	function bindMetaScan() {
		var btn = $('kikz-meta-scan'), out = $('kikz-meta-result');
		if (!btn || !out) { return; }
		btn.addEventListener('click', function () {
			var checked = 0, marked = 0, cands = [];
			btn.disabled = true;
			function step(cursor) {
				post('kikz_meta_scan', { cursor: cursor }).then(function (res) {
					if (!res || !res.success) { out.textContent = T.error; btn.disabled = false; return; }
					var d = res.data;
					checked += d.checked; marked += d.marked; cands = cands.concat(d.candidates || []);
					setStats({ flagged: d.count, pending: d.pending });
					if (!d.done) { out.textContent = fmt(T.metaRunning, checked); step(d.cursor); return; }
					var html = esc(fmt(T.metaDone, marked, cands.length));
					if (cands.length) {
						html += '<ul class="kikz-list">' + cands.slice(0, 100).map(function (c) {
							return '<li><span class="kikz-file">' + esc(c.file) + '</span><span class="kikz-meta">' + esc(c.signals.join(', ')) + ' · ' + esc(c.score) + '</span>' +
								'<button type="button" class="button button-small kikz-cand" data-id="' + esc(c.id) + '">' + esc(T.mark) + '</button></li>';
						}).join('') + '</ul>';
					}
					out.innerHTML = html;
					btn.disabled = false;
					if (marked > 0) { runQueue(d.pending); }
				}).catch(function () { out.textContent = T.error; btn.disabled = false; });
			}
			out.textContent = fmt(T.metaRunning, 0);
			step(0);
		});
		out.addEventListener('click', function (e) {
			var b = e.target.closest ? e.target.closest('.kikz-cand') : null;
			if (!b) { return; }
			b.disabled = true;
			post('kikz_toggle', { id: b.getAttribute('data-id'), state: '1' }).then(function (res) {
				if (res && res.success) { b.textContent = '✓'; setStats(res.data.stats); }
				else { b.disabled = false; }
			});
		});
	}

	// File-name scan with explicit confirmation.
	function bindNameScan() {
		var scan = $('kikz-scan'), mark = $('kikz-mark-matches'), out = $('kikz-scan-result');
		if (!scan || !out) { return; }
		var openCount = 0;
		scan.addEventListener('click', function () {
			out.textContent = T.working;
			post('kikz_scan_names', {}).then(function (res) {
				if (!res || !res.success) { out.textContent = T.error; return; }
				var d = res.data;
				openCount = d.open;
				var html = '<p>' + esc(fmt(T.hits, d.count, d.open)) + ' <em>' + esc(T.nameHint) + '</em></p>';
				if (d.items.length) {
					html += '<ul class="kikz-list">' + d.items.map(function (it) {
						return '<li><label style="display:flex;gap:8px;align-items:center;width:100%;"><input type="checkbox" class="kikz-pick" value="' + esc(it.id) + '"' + (it.flagged ? ' disabled' : ' checked') + '>' +
							'<span class="kikz-file">' + esc(it.file) + '</span><span class="kikz-meta">' + esc(it.pattern) + (it.flagged ? ' ✓' : '') + '</span></label></li>';
					}).join('') + '</ul>';
				}
				if (d.open > d.items.filter(function (i) { return !i.flagged; }).length) {
					html += '<p><label><input type="checkbox" id="kikz-pick-all"> ' + esc(fmt(T.allMatches, d.open)) + '</label></p>';
				}
				out.innerHTML = html;
				if (mark) { mark.style.display = d.open > 0 ? 'inline-block' : 'none'; }
			}).catch(function () { out.textContent = T.error; });
		});
		if (mark) {
			mark.addEventListener('click', function () {
				var all = $('kikz-pick-all'), ids = [];
				out.querySelectorAll('.kikz-pick:checked').forEach(function (c) { ids.push(c.value); });
				if (!ids.length && !(all && all.checked)) { return; }
				if (!window.confirm(T.confirmMark)) { return; }
				mark.disabled = true;
				post('kikz_mark_names', all && all.checked ? { all: '1' } : { ids: ids }).then(function (res) {
					mark.disabled = false;
					if (!res || !res.success) { out.textContent = T.error; return; }
					setStats({ flagged: res.data.count, pending: res.data.pending });
					out.innerHTML = '<p>' + esc(fmt(T.markedN, res.data.marked)) + '</p>';
					mark.style.display = 'none';
					runQueue(res.data.pending);
				}).catch(function () { mark.disabled = false; out.textContent = T.error; });
			});
		}
	}

	function bindButtons() {
		var run = $('kikz-run-queue'), restamp = $('kikz-restamp'), clear = $('kikz-clear-elementor');
		if (run) { run.addEventListener('click', function () { runQueue(cfg.pending || 1); }); }
		if (restamp) {
			restamp.addEventListener('click', function () {
				if (!window.confirm(T.confirmRestamp)) { return; }
				post('kikz_restamp_all', {}).then(function (res) {
					if (res && res.success) { queueMessage(esc(fmt(T.restamped, res.data.queued))); if (res.data.queued) { runQueue(res.data.queued); } }
				});
			});
		}
		var unstamp = $('kikz-unstamp');
		if (unstamp) {
			unstamp.addEventListener('click', function () {
				if (!window.confirm(T.confirmUnstamp)) { return; }
				post('kikz_unstamp_all', {}).then(function (res) {
					if (res && res.success) { queueMessage(esc(fmt(T.restamped, res.data.queued))); if (res.data.queued) { runQueue(res.data.queued); } }
				});
			});
		}
		if (clear) {
			clear.addEventListener('click', function () {
				post('kikz_clear_elementor_cache', {}).then(function () { var o = $('kikz-audit-result'); if (o) { o.textContent = T.cleared; } });
			});
		}
		if (cfg.legacy > 0) {
			var box = document.createElement('div');
			box.className = 'kikz-hint'; box.style.margin = '8px 0 0';
			box.textContent = fmt(T.legacyHint, cfg.legacy);
			var host = $('kikz-status-card');
			if (host) { host.appendChild(box); }
		}
	}

	function bindAudit() {
		var btn = $('kikz-audit'), out = $('kikz-audit-result');
		if (!btn || !out) { return; }
		btn.addEventListener('click', function () {
			var pages = 0, issues = 0, bad = 0, lines = [];
			btn.disabled = true;
			function step(offset) {
				post('kikz_audit', { offset: offset }).then(function (res) {
					if (!res || !res.success) { out.textContent = (res && res.data && res.data.message) || T.error; btn.disabled = false; return; }
					var d = res.data;
					d.results.forEach(function (r) {
						pages++;
						if (r.error) { issues++; bad++; lines.push('<li><strong>' + esc(r.url) + '</strong> – ' + esc(r.error) + '</li>'); }
						else if (r.issues.length) {
							bad++; issues += r.issues.length;
							lines.push('<li><a href="' + esc(r.url) + '" target="_blank" rel="noopener">' + esc(r.url) + '</a><ul>' + r.issues.map(function (m) { return '<li>' + esc(m) + '</li>'; }).join('') + '</ul></li>');
						}
					});
					if (!d.done) {
						out.textContent = fmt(T.auditRunning, Math.min(d.next, d.total), d.total);
						step(d.next);
						return;
					}
					btn.disabled = false;
					out.innerHTML = issues === 0
						? '<span class="kikz-ok">' + esc(fmt(T.auditOk, pages)) + '</span>'
						: '<span class="kikz-bad">' + esc(fmt(T.auditIssues, issues, bad)) + '</span><ul class="kikz-issues">' + lines.join('') + '</ul>';
				}).catch(function () { out.textContent = T.error; btn.disabled = false; });
			}
			out.textContent = fmt(T.auditRunning, 0, '…');
			step(0);
		});
	}

	function init() { bindPreview(); bindPicker(); bindMetaScan(); bindNameScan(); bindButtons(); bindAudit(); }
	if (document.readyState !== 'loading') { init(); } else { document.addEventListener('DOMContentLoaded', init); }
})();
