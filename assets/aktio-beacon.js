(function () {
	var c = window.aktioBeacon, r = document.referrer, u = '', h = '', m = /[?&]utm_source=([^&#]*)/.exec(location.search);
	function ai(x) { return c.hosts.indexOf(x.toLowerCase().replace(/^www\./, '')) > -1; }
	try { u = m ? decodeURIComponent(m[1]) : ''; h = r ? new URL(r).hostname : ''; } catch (e) {}
	if (!c || !navigator.sendBeacon || !(ai(h) || ai(u))) { return; }
	navigator.sendBeacon(c.url, new URLSearchParams({ r: r, u: u, p: location.pathname }));
})();
