(function () {
	var c = window.aktioBeacon, r = document.referrer,
		m = /[?&]utm_source=([^&#]*)/.exec(location.search);
	if (!c || !navigator.sendBeacon || (!m && (!r || r.indexOf(location.origin) === 0))) { return; }
	navigator.sendBeacon(c.url, new URLSearchParams({ r: r, u: m ? decodeURIComponent(m[1]) : '', p: location.pathname }));
})();
