(function () {
	'use strict';

	var DISMISS_KEY = 'painel-cti-pwa-dismiss';
	var INSTALLED_KEY = 'painel-cti-pwa-installed';
	var deferredPrompt = null;

	function baseUrl() {
		if (typeof url_base !== 'undefined' && url_base) {
			return String(url_base).replace(/\/?$/, '');
		}
		return window.location.origin;
	}

	function isStandalone() {
		return window.matchMedia('(display-mode: standalone)').matches
			|| window.navigator.standalone === true;
	}

	function registerSw() {
		if (!('serviceWorker' in navigator)) {
			return;
		}
		var swUrl = baseUrl() + '/sw.js';
		var scope = baseUrl() + '/';
		window.addEventListener('load', function () {
			navigator.serviceWorker.register(swUrl, { scope: scope }).catch(function (err) {
				console.warn('[PWA] SW register failed', err);
			});
		});
	}

	function showBanner() {
		if (isStandalone() || localStorage.getItem(DISMISS_KEY) === '1' || localStorage.getItem(INSTALLED_KEY) === '1') {
			return;
		}
		var $b = $('#pwa-install-banner');
		if (!$b.length) {
			return;
		}
		$b.removeClass('d-none');
	}

	function hideBanner(persist) {
		$('#pwa-install-banner').addClass('d-none');
		if (persist) {
			try { localStorage.setItem(DISMISS_KEY, '1'); } catch (e) {}
		}
	}

	function bindInstallUi() {
		$(document).on('click', '#pwa-install-btn', function () {
			if (!deferredPrompt) {
				/* iOS / Safari: instrução manual */
				Swal.fire({
					title: 'Instalar no celular',
					html: '<p class="small mb-2">No <strong>iPhone</strong>: toque em Compartilhar → <em>Adicionar à Tela de Início</em>.</p>'
						+ '<p class="small mb-0">No <strong>Android/Chrome</strong>: menu ⋮ → <em>Instalar aplicativo</em> ou use o botão quando aparecer.</p>',
					icon: 'info',
					confirmButtonText: 'Entendi'
				});
				return;
			}
			deferredPrompt.prompt();
			deferredPrompt.userChoice.then(function (choice) {
				if (choice && choice.outcome === 'accepted') {
					try { localStorage.setItem(INSTALLED_KEY, '1'); } catch (e) {}
					hideBanner(true);
				}
				deferredPrompt = null;
			});
		});

		$('#pwa-install-close').on('click', function () {
			hideBanner(true);
		});

		$('#pwa-install-menu').on('click', function (e) {
			e.preventDefault();
			$('#pwa-install-btn').trigger('click');
		});
	}

	window.addEventListener('beforeinstallprompt', function (e) {
		e.preventDefault();
		deferredPrompt = e;
		showBanner();
	});

	window.addEventListener('appinstalled', function () {
		try { localStorage.setItem(INSTALLED_KEY, '1'); } catch (err) {}
		hideBanner(true);
		deferredPrompt = null;
	});

	$(function () {
		registerSw();
		bindInstallUi();
		if (!isStandalone() && deferredPrompt) {
			showBanner();
		}
	});
})();
