/**
 * Anti-autofill global do painel.
 * Navegadores/gerenciadores de senha costumam encher o 1º campo de busca com e-mail de login.
 * Aplique automaticamente em type=search e campos com id/name/placeholder de busca,
 * ou marque com data-anti-autofill / class="anti-autofill".
 * Opt-out: data-allow-autofill="1"
 */
(function (global, document) {
	var ATTR_READY = 'data-anti-autofill-ready';

	function looksLikeEmail(v) {
		return typeof v === 'string' && v.indexOf('@') !== -1;
	}

	function isSearchLike(el) {
		if (!el || el.tagName !== 'INPUT') return false;
		var type = (el.getAttribute('type') || 'text').toLowerCase();
		if (type === 'password' || type === 'email' || type === 'hidden' || type === 'checkbox' || type === 'radio' || type === 'file' || type === 'submit' || type === 'button') {
			return false;
		}
		if (el.getAttribute('data-allow-autofill') === '1') return false;
		if (el.hasAttribute('data-anti-autofill') || (el.classList && el.classList.contains('anti-autofill'))) {
			return true;
		}
		if (type === 'search') return true;

		var id = String(el.id || '').toLowerCase();
		var name = String(el.name || '').toLowerCase();
		var ph = String(el.getAttribute('placeholder') || '').toLowerCase();
		var blob = id + ' ' + name + ' ' + ph;
		return /(busca|buscar|search|filtro|filter)\b/.test(blob)
			|| blob.indexOf('busca') !== -1
			|| blob.indexOf('buscar') !== -1
			|| blob.indexOf('search') !== -1
			|| blob.indexOf('filtro') !== -1;
	}

	function clearIfAutofilled(el) {
		if (!isSearchLike(el)) return;
		if (looksLikeEmail(el.value)) {
			el.value = '';
		}
	}

	function protect(el) {
		if (!el || el.getAttribute(ATTR_READY) === '1') return;
		if (!isSearchLike(el)) return;
		el.setAttribute(ATTR_READY, '1');

		el.setAttribute('autocomplete', 'off');
		el.setAttribute('autocorrect', 'off');
		el.setAttribute('autocapitalize', 'off');
		el.setAttribute('spellcheck', 'false');
		el.setAttribute('data-lpignore', 'true');
		el.setAttribute('data-1p-ignore', 'true');
		el.setAttribute('data-bwignore', 'true');
		el.setAttribute('data-form-type', 'other');

		// Chrome ignora autocomplete=off; readonly até o foco costuma bloquear o preenchimento.
		if (!el.hasAttribute('readonly')) {
			el.setAttribute('readonly', 'readonly');
		}
		el.addEventListener('focus', function () {
			el.removeAttribute('readonly');
		});
		el.addEventListener('blur', function () {
			if (!String(el.value || '').trim()) {
				el.setAttribute('readonly', 'readonly');
			}
		});

		clearIfAutofilled(el);
		setTimeout(function () { clearIfAutofilled(el); }, 100);
		setTimeout(function () { clearIfAutofilled(el); }, 600);
		setTimeout(function () { clearIfAutofilled(el); }, 1500);
	}

	function scan(root) {
		var scope = root && root.querySelectorAll ? root : document;
		var list = scope.querySelectorAll('input');
		for (var i = 0; i < list.length; i++) {
			protect(list[i]);
		}
	}

	function boot() {
		// Sem honeypot de e-mail/senha: no mobile o navegador força esses inputs
		// a aparecerem (topo ou rodapé), mesmo com CSS de ocultação.
		scan(document);
		if (typeof MutationObserver !== 'undefined') {
			var obs = new MutationObserver(function (mutations) {
				for (var i = 0; i < mutations.length; i++) {
					var nodes = mutations[i].addedNodes;
					for (var j = 0; j < nodes.length; j++) {
						var n = nodes[j];
						if (n.nodeType !== 1) continue;
						if (n.tagName === 'INPUT') {
							protect(n);
						} else if (n.querySelectorAll) {
							scan(n);
						}
					}
				}
			});
			obs.observe(document.documentElement, { childList: true, subtree: true });
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}

	global.PainelAntiAutofill = { scan: scan, protect: protect };
})(window, document);
