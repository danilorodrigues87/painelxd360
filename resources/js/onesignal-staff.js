(function () {
	'use strict';

	function meta(name) {
		var el = document.querySelector('meta[name="' + name + '"]');
		return el ? String(el.getAttribute('content') || '').trim() : '';
	}

	function swPathRelativo() {
		var p = meta('onesignal-sw-path') || 'push/onesignal/OneSignalSDKWorker.js';
		return p.replace(/^\/+/, '');
	}

	function swUpdaterRelativo() {
		var p = meta('onesignal-sw-updater-path') || 'push/onesignal/OneSignalSDKUpdaterWorker.js';
		return p.replace(/^\/+/, '');
	}

	function baseUrl() {
		if (typeof url_base !== 'undefined' && url_base) {
			return String(url_base).replace(/\/?$/, '');
		}
		var origin = window.location.origin || '';
		var path = window.location.pathname || '/';
		var match = path.match(/^(.*)\/(?:painel|master)(?:\/.*)?$/);
		if (match) {
			var basePath = match[1] || '';
			return origin + basePath;
		}
		return origin;
	}

	function postNotif(data, cb) {
		$.ajax({
			url: baseUrl() + '/painel/notificacoes',
			method: 'POST',
			data: data,
			dataType: 'json'
		}).done(function (res) {
			if (typeof cb === 'function') cb(res || {});
		}).fail(function () {
			if (typeof cb === 'function') cb({ success: false });
		});
	}

	function registrarSubscription(subId) {
		if (!subId) return;
		postNotif({ acao: 'registrar_push', subscription_id: subId });
	}

	function syncUsuario(OneSignal, cfg) {
		if (!cfg || !cfg.habilitado) {
			return Promise.resolve();
		}

		var extId = cfg.external_id || ('u' + cfg.id_usuario);
		var tags = cfg.tags || {};

		return OneSignal.login(extId).then(function () {
			return OneSignal.User.addTags(tags);
		}).then(function () {
			var sub = OneSignal.User.PushSubscription;
			if (sub && sub.id) {
				registrarSubscription(String(sub.id));
			}
			if (typeof sub.addEventListener === 'function') {
				sub.addEventListener('change', function (ev) {
					var id = ev && ev.current && ev.current.id ? ev.current.id : (sub.id || '');
					registrarSubscription(String(id));
				});
			}
		}).catch(function (err) {
			console.warn('[OneSignal] syncUsuario', err);
		});
	}

	function pedirPermissao(OneSignal) {
		try {
			if (typeof Notification !== 'undefined') {
				if (Notification.permission === 'granted' || Notification.permission === 'denied') {
					return;
				}
			}
			var key = 'painel-cti-push-asked';
			if (localStorage.getItem(key) === '1') return;
			localStorage.setItem(key, '1');
			if (OneSignal.Notifications && typeof OneSignal.Notifications.requestPermission === 'function') {
				OneSignal.Notifications.requestPermission();
			}
		} catch (e) { /* ignore */ }
	}

	function iniciar() {
		var appId = meta('onesignal-app-id');
		if (!appId) return;

		var swPath = swPathRelativo();
		var swUpdater = swUpdaterRelativo();
		var swScope = meta('onesignal-sw-scope') || '/push/onesignal/';

		window.OneSignalDeferred = window.OneSignalDeferred || [];
		OneSignalDeferred.push(function (OneSignal) {
			var initOpts = {
				appId: appId,
				notifyButton: { enable: false },
				serviceWorkerPath: swPath,
				serviceWorkerUpdaterPath: swUpdater,
				serviceWorkerParam: { scope: swScope },
			};
			if (/^localhost$|^127\.0\.0\.1$/i.test(window.location.hostname)) {
				initOpts.allowLocalhostAsSecureOrigin = true;
			}

			return OneSignal.init(initOpts).then(function () {
				return new Promise(function (resolve) {
					postNotif({ acao: 'push_config' }, function (cfg) {
						resolve(cfg || {});
					});
				});
			}).then(function (cfg) {
				if (!cfg.success || !cfg.onesignal) return;
				return syncUsuario(OneSignal, cfg).then(function () {
					if (cfg.habilitado) {
						pedirPermissao(OneSignal);
					}
				});
			}).catch(function (err) {
				console.warn('[OneSignal] init', err);
			});
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', iniciar);
	} else {
		iniciar();
	}
})();
