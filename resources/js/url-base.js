/**
 * Detecta automaticamente a URL base do painel a partir do navegador.
 * Funciona em localhost (subpasta) e em produção (raiz do domínio).
 */
(function (global) {
	function detectUrlBase() {
		var origin = global.location.origin;
		var path = global.location.pathname || '/';

		// Rotas do app: /painel/... ou /master/...
		// Ex: /pjt/painel-cti/painel  →  base /pjt/painel-cti/
		// Ex: /pjt/painel-cti/master/escolas → base /pjt/painel-cti/
		// Ex: /painel/crm             →  base /
		var match = path.match(/^(.*)\/(?:painel|master)(?:\/.*)?$/);

		if (match) {
			var basePath = match[1] || '';
			return origin + basePath + '/';
		}

		if (path === '/painel' || path.indexOf('/painel/') === 0
			|| path === '/master' || path.indexOf('/master/') === 0) {
			return origin + '/';
		}

		return origin + '/';
	}

	global.url_base = detectUrlBase();
})(window);
