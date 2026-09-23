/**
 * Mantém a fila de campanhas andando enquanto houver sessão aberta no painel.
 * Necessário quando o cron do worker/campanhas.php ainda não está configurado.
 */
(function () {
	if (typeof url_base === 'undefined' || typeof $ === 'undefined') {
		return;
	}

	var INTERVALO_MS = 45000;
	var emAndamento = false;
	var timer = null;

	function tick() {
		if (emAndamento || document.hidden) {
			return;
		}
		emAndamento = true;
		$.post(url_base + 'painel/campanhas', {
			acao: 'processar',
			limite: 1,
			silencioso: 1
		}, function () {
			emAndamento = false;
		}, 'json').fail(function () {
			emAndamento = false;
		});
	}

	function iniciar() {
		if (timer) {
			return;
		}
		timer = setInterval(tick, INTERVALO_MS);
		// primeira tentativa após 15s (evita sobrecarregar o carregamento da página)
		setTimeout(tick, 15000);
	}

	$(iniciar);
})();
