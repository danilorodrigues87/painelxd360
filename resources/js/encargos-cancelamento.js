function escHtml(s) {
	return String(s || '')
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;');
}

function moedaBrJs(n) {
	var v = parseFloat(n);
	if (isNaN(v)) return '0,00';
	return v.toFixed(2).replace('.', ',');
}

function acertoFinanceiroConfig(modo) {
	if (modo === 'regularizar') {
		return {
			modo: 'regularizar',
			simUrl:
				typeof regularizarSimular !== 'undefined' && regularizarSimular
					? regularizarSimular
					: 'painel/matriculas/regularizar/simular',
			confirmUrl:
				typeof regularizar !== 'undefined' && regularizar
					? regularizar
					: 'painel/matriculas/regularizar',
			title: 'Regularizar financeiro?',
			confirmText: 'Confirmar regularização',
			successTitle: 'Regularizado!',
			erroSim: 'Não foi possível simular a regularização.',
			erroConfirm: 'Falha ao regularizar o financeiro.',
			intro:
				'<p class="mb-2 text-info">O contrato permanece <strong>encerrado</strong>. Escolha o que permanece em aberto para cobrança.</p>',
		};
	}
	return {
		modo: 'cancelar',
		simUrl:
			typeof cancelarSimular !== 'undefined' && cancelarSimular
				? cancelarSimular
				: 'painel/matriculas/cancelar/simular',
		confirmUrl:
			typeof cancelar !== 'undefined' && cancelar ? cancelar : 'painel/matriculas/cancelar',
		title: 'Cancelar contrato?',
		confirmText: 'Confirmar cancelamento',
		successTitle: 'Cancelado!',
		erroSim: 'Não foi possível simular o cancelamento.',
		erroConfirm: 'Falha ao cancelar o contrato.',
		intro: '',
	};
}

function idsParcelasMarcadas($scope) {
	var $root = $scope && $scope.length ? $scope : $(document);
	var ids = [];
	$root.find('.chk-parc-cobrar:checked').each(function () {
		var id = parseInt($(this).val(), 10);
		if (id > 0) ids.push(id);
	});
	return ids;
}

function parcelasCobrarPayload($scope) {
	var ids = idsParcelasMarcadas($scope);
	return ids.length ? ids.join(',') : '';
}

function recalcularSimulacao(id, callback, $scope, modo) {
	var cfg = acertoFinanceiroConfig(modo);
	$('#box-totais-cancel').css('opacity', '0.55');
	$.ajax({
		url: url_base + cfg.simUrl,
		method: 'post',
		data: { id: id, parcelas_cobrar: parcelasCobrarPayload($scope) },
		dataType: 'json',
		success: function (sim) {
			$('#box-totais-cancel').css('opacity', '1');
			if (sim && sim.ok && typeof callback === 'function') callback(sim);
		},
		error: function () {
			$('#box-totais-cancel').css('opacity', '1');
		},
	});
}

function aplicarPoliticaDesistencia(id, $popup, modo) {
	var cfg = acertoFinanceiroConfig(modo);
	var max = parseInt($popup.data('max-parcelas') || '3', 10) || 3;
	$('#box-totais-cancel').css('opacity', '0.55');
	$.ajax({
		url: url_base + cfg.simUrl,
		method: 'post',
		data: { id: id, preset: 'politica_desistencia' },
		dataType: 'json',
		success: function (sim) {
			$('#box-totais-cancel').css('opacity', '1');
			if (!sim || !sim.ok) {
				Swal.showValidationMessage((sim && sim.message) || 'Não foi possível aplicar a política.');
				return;
			}
			$popup.find('#box-totais-cancel').replaceWith(renderTotaisCancelamento(sim));
			$popup.find('#box-multa-cancel').replaceWith(renderBlocoMulta(sim));
			$popup.find('.chk-parc-cobrar').each(function () {
				var pid = parseInt($(this).val(), 10);
				var marcar = (sim.parcelas_cobrar || []).indexOf(pid) >= 0;
				$(this).prop('checked', marcar);
			});
			Swal.fire({
				toast: true,
				position: 'top-end',
				icon: 'info',
				title: 'Política aplicada: até ' + max + ' vencida(s) para cobrança',
				showConfirmButton: false,
				timer: 2200,
			});
		},
		error: function () {
			$('#box-totais-cancel').css('opacity', '1');
		},
	});
}

function renderBlocoMulta(sim) {
	var multa = parseFloat(sim.multa_rescisoria) || 0;
	if (multa <= 0) {
		return '<div id="box-multa-cancel" class="d-none"></div>';
	}
	var avisoMultaExistente =
		sim.tem_multa_rescisoria_aberta && sim.modo === 'regularizar'
			? ' <span class="text-warning">(já existe multa rescisória em aberto — não será duplicada)</span>'
			: '';
	return (
		'<p id="box-multa-cancel" class="mb-2 border-start border-3 border-warning ps-2">' +
		'<strong>Multa rescisória:</strong> R$ ' +
		moedaBrJs(sim.multa_rescisoria) +
		' <span class="text-muted">(' +
		moedaBrJs((sim.params && sim.params.multa_cancelamento_pct) || 10) +
		'% sobre parcelas canceladas (baixa administrativa)</span> — ' +
		'será gerado <u>título em aberto</u> para quitação no carnê ou extrato do aluno.' +
		avisoMultaExistente +
		'</p>'
	);
}

function renderTotaisCancelamento(sim) {
	var html =
		'<div class="border rounded p-2 mb-2 bg-light small" id="box-totais-cancel">' +
		'<div>Parcelas a cobrar: <strong>' +
		(sim.qtd_cobrar || 0) +
		'</strong> · Baixa admin: <strong>' +
		(sim.qtd_baixar || 0) +
		'</strong></div>' +
		'<div>Total a cobrar (face): <strong>R$ ' +
		moedaBrJs(sim.total_cobrar_face) +
		'</strong> · com encargos: <strong>R$ ' +
		moedaBrJs(sim.total_cobrar_com_encargos) +
		'</strong></div>' +
		'<div>Multa rescisória (' +
		moedaBrJs((sim.params && sim.params.multa_cancelamento_pct) || 10) +
		'% sobre parcelas canceladas): <strong>R$ ' +
		moedaBrJs(sim.multa_rescisoria) +
		'</strong></div>' +
		'<div class="fw-semibold mt-1">Dívida estimada (cobrança + multa): R$ ' +
		moedaBrJs(sim.total_geral_com_encargos) +
		'</div>' +
		'</div>';
	return html;
}

function renderListaParcelas(sim) {
	var parcelas = sim.parcelas || [].concat(sim.vencidas || [], sim.futuras || []);
	if (!parcelas.length) {
		return '<p class="text-muted mb-2">Nenhuma parcela em aberto nesta matrícula.</p>';
	}
	var maxPol = parseInt(sim.max_parcelas_desistencia || '3', 10) || 3;
	var html =
		'<div class="d-flex flex-wrap gap-2 mb-2">' +
		'<button type="button" class="btn btn-sm btn-outline-primary btn-politica-desistencia">' +
		'Aplicar política (máx. ' +
		maxPol +
		' vencidas)</button>' +
		'</div>' +
		'<p class="fw-semibold mb-1">Selecione as parcelas que <u>permanecem em aberto</u> para cobrança. As demais recebem baixa administrativa (R$ 0).</p>' +
		'<div class="table-responsive mb-2" style="max-height:220px;overflow:auto">' +
		'<table class="table table-sm table-bordered mb-0"><thead class="table-light">' +
		'<tr><th style="width:32px"></th><th>Venc.</th><th>Descrição</th><th>Face</th><th>Com enc.</th></tr></thead><tbody>';
	parcelas.forEach(function (p) {
		var checked = p.cobrar !== undefined ? p.cobrar : !!p.cobrar_default;
		var badge =
			p.grupo === 'vencida'
				? '<span class="badge bg-danger ms-1">Vencida</span>'
				: '<span class="badge bg-secondary ms-1">Futura</span>';
		html +=
			'<tr><td class="text-center">' +
			'<input type="checkbox" class="form-check-input chk-parc-cobrar" value="' +
			p.id +
			'"' +
			(checked ? ' checked' : '') +
			'></td>' +
			'<td class="text-nowrap">' +
			escHtml(p.vencimento_br) +
			badge +
			'</td>' +
			'<td class="small">' +
			escHtml(p.descricao) +
			'</td>' +
			'<td>R$ ' +
			moedaBrJs(p.valor_face) +
			'</td>' +
			'<td>R$ ' +
			moedaBrJs(p.total_com_encargos) +
			'</td></tr>';
	});
	html += '</tbody></table></div>';
	return html;
}

function iniciarAcertoFinanceiro(id, modo) {
	var cfg = acertoFinanceiroConfig(modo);
	$.ajax({
		url: url_base + cfg.simUrl,
		method: 'post',
		data: { id: id },
		dataType: 'json',
		success: function (sim) {
			if (!sim || !sim.ok) {
				Swal.fire('Erro', (sim && sim.message) || cfg.erroSim, 'error');
				return;
			}
			sim.modo = cfg.modo;
			abrirModalAcertoFinanceiro(id, sim, modo);
		},
		error: function () {
			Swal.fire('Erro', cfg.erroSim, 'error');
		},
	});
}

function cancelar_contrato(id) {
	iniciarAcertoFinanceiro(id, 'cancelar');
}

function regularizar_financeiro(id) {
	iniciarAcertoFinanceiro(id, 'regularizar');
}

function abrirModalAcertoFinanceiro(id, sim, modo) {
	var cfg = acertoFinanceiroConfig(modo);
	var params = sim.params || {};
	var html = '<div class="text-start small" id="wrap-cancel-modal">';
	html += cfg.intro;
	html +=
		'<p class="mb-2">Carência: <strong>' +
		(params.carencia_dias || 7) +
		' dias</strong> · Multa atraso: <strong>' +
		moedaBrJs(params.multa_atraso_pct || 2) +
		'%</strong> · Juros: <strong>' +
		moedaBrJs(params.juros_mora_pct_mes || 1) +
		'% a.m.</strong></p>';
	html += renderTotaisCancelamento(sim);
	html += renderListaParcelas(sim);
	html += renderBlocoMulta(sim);
	html +=
		'<p class="text-muted mb-0 small">Encargos de atraso continuam sendo calculados até a data do pagamento de cada título. A multa rescisória recalcula ao marcar/desmarcar parcelas (vencidas ou futuras).</p>';
	html += '</div>';

	Swal.fire({
		title: cfg.title,
		html: html,
		icon: 'warning',
		width: 720,
		showCancelButton: true,
		confirmButtonColor: '#3085d6',
		cancelButtonColor: '#d33',
		confirmButtonText: cfg.confirmText,
		cancelButtonText: 'Voltar',
		didOpen: function () {
			var $popup = $(Swal.getHtmlContainer());
			$popup.data('max-parcelas', sim.max_parcelas_desistencia || 3);
			$popup
				.off('change.cancelParc', '.chk-parc-cobrar')
				.on('change.cancelParc', '.chk-parc-cobrar', function () {
					recalcularSimulacao(
						id,
						function (novoSim) {
							novoSim.modo = cfg.modo;
							$popup.find('#box-totais-cancel').replaceWith(renderTotaisCancelamento(novoSim));
							$popup.find('#box-multa-cancel').replaceWith(renderBlocoMulta(novoSim));
						},
						$popup,
						modo
					);
				});
			$popup
				.off('click.politicaDesist', '.btn-politica-desistencia')
				.on('click.politicaDesist', '.btn-politica-desistencia', function () {
					aplicarPoliticaDesistencia(id, $popup, modo);
				});
		},
		willClose: function () {
			var $popup = Swal.getHtmlContainer();
			if ($popup) {
				$($popup).off('change.cancelParc', '.chk-parc-cobrar');
				$($popup).off('click.politicaDesist', '.btn-politica-desistencia');
			}
		},
		preConfirm: function () {
			var $popup = $(Swal.getHtmlContainer());
			return {
				parcelas_cobrar: idsParcelasMarcadas($popup),
			};
		},
	}).then(function (result) {
		if (!result.isConfirmed) return;

		var flags = result.value || {};
		$.ajax({
			url: url_base + cfg.confirmUrl,
			method: 'post',
			data: {
				id: id,
				parcelas_cobrar: (flags.parcelas_cobrar || []).join(','),
			},
			dataType: 'json',
			success: function (res) {
				var ok = res && res.ok;
				Swal.fire({
					title: ok ? cfg.successTitle : 'Atenção',
					text: (res && res.message) || (ok ? 'OK' : cfg.erroConfirm),
					icon: ok ? 'success' : 'error',
				});
				if (typeof listar === 'function') listar(null, 1);
				if (typeof carregarInadimplentes === 'function') carregarInadimplentes();
			},
			error: function () {
				Swal.fire({ title: 'Erro', text: cfg.erroConfirm, icon: 'error' });
			},
		});
	});
}

/** Compatibilidade com código legado */
function abrirModalCancelamento(id, sim) {
	abrirModalAcertoFinanceiro(id, sim, 'cancelar');
}
