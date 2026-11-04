(function () {
	var c = window.aktioAffiliate, code = '', sent = false;
	if (!c || !c.param || !window.fetch) { return; }
	try { code = new URLSearchParams(location.search).get(c.param) || ''; } catch (e) {}
	if (!code) { return; }

	function strip() {
		try {
			var u = new URL(location.href);
			u.searchParams.delete(c.param);
			if (window.history && history.replaceState) { history.replaceState(history.state, '', u.pathname + u.search + u.hash); }
		} catch (e) {}
	}
	function send() {
		if (sent) { return; }
		sent = true;
		var done = function () { strip(); };
		try {
			fetch(c.url, { method: 'POST', credentials: 'same-origin', keepalive: true, headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ code: code }).toString() }).then(done, done);
		} catch (e) { sent = false; }
	}
	function consented() { return typeof window.wp_has_consent === 'function' && window.wp_has_consent('marketing'); }

	if (c.mode === 'consent_api') {
		if (typeof window.wp_has_consent !== 'function') { return; }
		if (consented()) { send(); return; }
		document.addEventListener('wp_listen_for_consent_change', function (e) {
			var t = e && e.detail && e.detail.marketing;
			if (t === 'allow' || (t === undefined && consented())) { send(); }
		});
	} else if (c.mode === 'self_managed') {
		send();
	}
})();
