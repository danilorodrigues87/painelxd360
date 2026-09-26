/**
 * Service Worker — XD360 Painel (cache estático apenas; HTML/API sempre em rede).
 * Escopo: pasta do deploy (/app/ ou raiz).
 */
'use strict';

var CACHE_VERSION = 'painel-xd360-v2';
var STATIC_PATHS = [
	'resources/css/styles.css',
	'resources/css/panel-theme.css',
	'resources/css/xd360-brand.css',
	'resources/js/url-base.js',
	'resources/js/sidebarToggle.js',
	'resources/js/panel-theme.js',
	'resources/pwa/icon-192.png',
	'resources/pwa/icon-512.png',
	'resources/assets/img/brand/favicon.png',
	'resources/assets/img/brand/logo-light.png',
	'resources/assets/img/brand/logo-dark.png',
];

function basePath() {
	var scope = self.registration && self.registration.scope
		? self.registration.scope
		: (self.location.origin + self.location.pathname.replace(/sw\.js(\?.*)?$/, ''));
	return scope.endsWith('/') ? scope : scope + '/';
}

function cacheUrls() {
	var base = basePath();
	return STATIC_PATHS.map(function (p) {
		return base + p.replace(/^\//, '');
	});
}

self.addEventListener('install', function (event) {
	event.waitUntil(
		caches.open(CACHE_VERSION).then(function (cache) {
			return cache.addAll(cacheUrls()).catch(function () {
				/* ignora falha parcial (CDN externo não entra no cache) */
			});
		}).then(function () {
			return self.skipWaiting();
		})
	);
});

self.addEventListener('activate', function (event) {
	event.waitUntil(
		caches.keys().then(function (keys) {
			return Promise.all(keys.map(function (key) {
				if (key !== CACHE_VERSION) {
					return caches.delete(key);
				}
			}));
		}).then(function () {
			return self.clients.claim();
		})
	);
});

self.addEventListener('fetch', function (event) {
	var req = event.request;
	if (req.method !== 'GET') {
		return;
	}

	var url = new URL(req.url);

	/* Navegação e API: sempre rede (sessão PHP) */
	if (req.mode === 'navigate'
		|| url.pathname.indexOf('/painel') !== -1 && req.headers.get('accept') && req.headers.get('accept').indexOf('text/html') !== -1
		|| url.pathname.indexOf('/webhook/') !== -1) {
		event.respondWith(
			fetch(req).catch(function () {
				return caches.match(basePath() + 'resources/css/styles.css');
			})
		);
		return;
	}

	/* Assets estáticos locais: cache-first */
	var isStatic = STATIC_PATHS.some(function (p) {
		return url.pathname.indexOf(p) !== -1;
	});
	if (!isStatic) {
		return;
	}

	event.respondWith(
		caches.match(req).then(function (cached) {
			if (cached) {
				return cached;
			}
			return fetch(req).then(function (res) {
				if (!res || res.status !== 200 || res.type === 'opaque') {
					return res;
				}
				var clone = res.clone();
				caches.open(CACHE_VERSION).then(function (cache) {
					cache.put(req, clone);
				});
				return res;
			});
		})
	);
});
