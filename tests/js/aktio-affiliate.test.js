'use strict';
// Zachowanie skryptu linku partnerskiego: parametr, zgoda (WP Consent API) i czyszczenie adresu.
const test = require('node:test');
const assert = require('node:assert');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');

const SRC = fs.readFileSync(path.join(__dirname, '../../assets/aktio-affiliate.js'), 'utf8');

function boot({ mode, search = '?ref=abc&a=1', consent, readyState } = {}) {
	const requests = [];
	const replaced = [];
	const listeners = {};
	const order = [];
	const win = {
		aktioAffiliate: { url: '/wp-json/aktio/v1/ref', param: 'ref', mode },
		fetch: (url, opts) => {
			order.push('fetch');
			requests.push({ url, body: String(opts.body) });
			return Promise.resolve({});
		},
		history: { state: null, replaceState: (s, t, u) => { order.push('replace'); replaced.push(u); } },
	};
	if (consent !== undefined) { win.wp_has_consent = () => consent.value; }
	const ctx = {
		window: win,
		location: new URL('https://shop.test/p/' + search + '#h'),
		document: { readyState, addEventListener: (n, f) => { (listeners[n] = listeners[n] || []).push(f); } },
		URL, URLSearchParams, Promise,
		history: win.history,
		fetch: win.fetch,
	};
	vm.runInNewContext(SRC, ctx);
	const tick = () => new Promise((r) => setTimeout(r, 5));
	// Jak wp_set_consent: detail to tablica z własnością kategorii.
	const fire = (category, value) => {
		const detail = []; detail[category] = value;
		(listeners.wp_listen_for_consent_change || []).forEach((f) => f({ detail }));
	};
	const ready = (late) => {
		if (late !== undefined) { win.wp_has_consent = () => late.value; }
		(listeners.DOMContentLoaded || []).forEach((f) => f({}));
	};
	return { requests, replaced, listeners, order, tick, fire, ready };
}

test('no parameter: no request, no URL change', async () => {
	const t = boot({ mode: 'self_managed', search: '?a=1' });
	await t.tick();
	assert.strictEqual(t.requests.length, 0);
	assert.strictEqual(t.replaced.length, 0);
});

test('self_managed: sends once and strips the parameter after the request', async () => {
	const t = boot({ mode: 'self_managed' });
	assert.deepStrictEqual(t.order, ['fetch']);
	await t.tick();
	assert.strictEqual(t.requests.length, 1);
	assert.strictEqual(t.requests[0].body, 'code=abc');
	assert.deepStrictEqual(t.order, ['fetch', 'replace']);
	assert.deepStrictEqual(t.replaced, ['/p/?a=1#h']);
});

test('consent_api without wp_has_consent: no request, URL unchanged', async () => {
	const t = boot({ mode: 'consent_api' });
	await t.tick();
	assert.strictEqual(t.requests.length, 0);
	assert.strictEqual(t.replaced.length, 0);
});

test('consent_api: waits for consent, then sends once and strips', async () => {
	const consent = { value: false };
	const t = boot({ mode: 'consent_api', consent });
	await t.tick();
	assert.strictEqual(t.requests.length, 0);
	assert.strictEqual(t.replaced.length, 0);
	assert.strictEqual(t.listeners.wp_listen_for_consent_change.length, 1);
	consent.value = true;
	t.fire('marketing', 'allow');
	await t.tick();
	assert.strictEqual(t.requests.length, 1);
	assert.deepStrictEqual(t.replaced, ['/p/?a=1#h']);
});

test('consent_api: already consented sends at once', async () => {
	const t = boot({ mode: 'consent_api', consent: { value: true } });
	await t.tick();
	assert.strictEqual(t.requests.length, 1);
	assert.strictEqual(t.replaced.length, 1);
});

test('consent_api: denied consent sends nothing and keeps the URL', async () => {
	const t = boot({ mode: 'consent_api', consent: { value: false } });
	t.fire('marketing', 'deny');
	t.fire('statistics', 'allow');
	await t.tick();
	assert.strictEqual(t.requests.length, 0);
	assert.strictEqual(t.replaced.length, 0);
});

test('consent_api: a second consent event does not send twice', async () => {
	const consent = { value: false };
	const t = boot({ mode: 'consent_api', consent });
	consent.value = true;
	t.fire('marketing', 'allow');
	t.fire('marketing', 'allow');
	await t.tick();
	assert.strictEqual(t.requests.length, 1);
});

test('consent_api: Consent API loaded after a non-deferred script, consent already given: sends on DOMContentLoaded', async () => {
	const t = boot({ mode: 'consent_api', readyState: 'loading' });
	assert.strictEqual(t.requests.length, 0);
	t.ready({ value: true });
	await t.tick();
	assert.strictEqual(t.requests.length, 1);
	assert.strictEqual(t.replaced.length, 1);
});

test('consent_api: Consent API loaded later, no consent yet: waits for the consent event', async () => {
	const consent = { value: false };
	const t = boot({ mode: 'consent_api', readyState: 'loading' });
	t.ready(consent);
	await t.tick();
	assert.strictEqual(t.requests.length, 0);
	consent.value = true;
	t.fire('marketing', 'allow');
	await t.tick();
	assert.strictEqual(t.requests.length, 1);
});

test('consent_api: still no Consent API at DOMContentLoaded: no request, URL unchanged', async () => {
	const t = boot({ mode: 'consent_api', readyState: 'loading' });
	t.ready();
	await t.tick();
	assert.strictEqual(t.requests.length, 0);
	assert.strictEqual(t.replaced.length, 0);
});

test('consent_api: consent event before the API function is defined still counts', async () => {
	const t = boot({ mode: 'consent_api', readyState: 'loading' });
	t.fire('marketing', 'allow');
	await t.tick();
	assert.strictEqual(t.requests.length, 1);
	t.ready({ value: true });
	await t.tick();
	assert.strictEqual(t.requests.length, 1, 'The later re-check does not send twice.');
});

test('consent_api: API absent after loading: no re-check is registered', () => {
	const t = boot({ mode: 'consent_api', readyState: 'interactive' });
	assert.strictEqual((t.listeners.DOMContentLoaded || []).length, 0);
});
