(function () {
	'use strict';

	var API = 'master/conect-relatorios';
	var candPage = 1;
	var charts = {};

	function esc(s) {
		return $('<div>').text(s == null ? '' : String(s)).html();
	}

	function fmtNum(n) {
		if (n == null || n === '') return '—';
		return Number(n).toLocaleString('pt-BR');
	}

	function filtrosComuns() {
		return {
			de: $('#rel-de').val() || '',
			ate: $('#rel-ate').val() || '',
			id_admin: $('#filt-escola').val() || '',
			uf: ($('#filt-uf').val() || '').trim().toUpperCase(),
			tipo: $('#filt-tipo').val() || '',
			status: $('#filt-status').val() || '',
			q: ($('#filt-q').val() || '').trim()
		};
	}

	function destroyChart(key) {
		if (charts[key]) {
			charts[key].destroy();
			charts[key] = null;
		}
	}

	function renderSerie(serie) {
		destroyChart('serie');
		if (typeof Chart === 'undefined') return;
		var labels = (serie || []).map(function (d) { return d.diaBr || d.dia; });
		var ctx = document.getElementById('chart-serie');
		if (!ctx) return;
		charts.serie = new Chart(ctx.getContext('2d'), {
			type: 'line',
			data: {
				labels: labels,
				datasets: [
					{ label: 'Pageviews', data: (serie || []).map(function (d) { return d.visitas; }), borderColor: '#0d6efd', backgroundColor: 'rgba(13,110,253,0.1)', fill: true, lineTension: 0.2 },
					{ label: 'Cadastros', data: (serie || []).map(function (d) { return d.cadastros; }), borderColor: '#198754', backgroundColor: 'transparent', fill: false, lineTension: 0.2 },
					{ label: 'Shares', data: (serie || []).map(function (d) { return d.shares; }), borderColor: '#ffc107', backgroundColor: 'transparent', fill: false, lineTension: 0.2 }
				]
			},
			options: { responsive: true, legend: { display: true, position: 'bottom' }, scales: { yAxes: [{ ticks: { beginAtZero: true, precision: 0 } }] } }
		});
	}

	function renderEscolas(items) {
		destroyChart('escolas');
		if (typeof Chart === 'undefined') return;
		var ctx = document.getElementById('chart-escolas');
		if (!ctx) return;
		var labels = (items || []).map(function (d) {
			var n = d.escola || '—';
			return n.length > 28 ? n.slice(0, 26) + '…' : n;
		});
		charts.escolas = new Chart(ctx.getContext('2d'), {
			type: 'horizontalBar',
			data: {
				labels: labels,
				datasets: [{ label: 'Candidatos', data: (items || []).map(function (d) { return d.qtd; }), backgroundColor: '#0d6efd' }]
			},
			options: { responsive: true, legend: { display: false }, scales: { xAxes: [{ ticks: { beginAtZero: true, precision: 0 } }] } }
		});
	}

	function renderShares(items) {
		destroyChart('shares');
		if (typeof Chart === 'undefined') return;
		var ctx = document.getElementById('chart-shares');
		if (!ctx) return;
		var colors = ['#25D366', '#1877F2', '#0A66C2', '#000000', '#6c757d'];
		charts.shares = new Chart(ctx.getContext('2d'), {
			type: 'doughnut',
			data: {
				labels: (items || []).map(function (d) { return d.label || d.plataforma; }),
				datasets: [{ data: (items || []).map(function (d) { return d.qtd; }), backgroundColor: colors }]
			},
			options: { responsive: true, legend: { position: 'bottom' } }
		});
	}

	function renderTopPaginas(items) {
		var $tb = $('#tb-top-paginas').empty();
		if (!items || !items.length) {
			$tb.append('<tr><td colspan="2" class="text-muted text-center py-3">Sem dados no período.</td></tr>');
			return;
		}
		items.forEach(function (r) {
			$tb.append(
				'<tr><td class="small font-monospace">' + esc(r.path) + '</td>'
				+ '<td class="text-end">' + fmtNum(r.visitas) + '</td></tr>'
			);
		});
	}

	function renderFunil(k) {
		var visitas = Number(k.visitas_cadastro || 0);
		var cadastros = Number(k.candidatos_novos || 0);
		var taxa = visitas > 0 ? ((cadastros / visitas) * 100).toFixed(1) + '%' : '—';
		$('#funil-visitas').text(fmtNum(visitas));
		$('#funil-cadastros').text(fmtNum(cadastros));
		$('#funil-taxa').text(taxa);
	}

	function carregarResumo() {
		$.post(url_base + API, $.extend({ acao: 'resumo' }, filtrosComuns()), function (res) {
			if (!res || !res.success) {
				Swal.fire('Atenção', (res && res.message) || 'Falha ao carregar resumo', 'warning');
				return;
			}
			var k = res.kpis || {};
			var p = k.periodo || {};
			$('#rel-periodo').text('Período: ' + (p.de_br || '') + ' a ' + (p.ate_br || ''));
			if (p.de) $('#rel-de').val(p.de);
			if (p.ate) $('#rel-ate').val(p.ate);

			$('#kpi-cand-novos').text(fmtNum(k.candidatos_novos));
			$('#kpi-cand-total').text(fmtNum(k.total_candidatos));
			$('#kpi-emp-novas').text(fmtNum(k.empresas_novas));
			$('#kpi-emp-total').text(fmtNum(k.total_empresas));
			$('#kpi-views-vagas').text(fmtNum(k.views_vagas_total));

			if (k.analytics_ok) {
				$('#rel-analytics-alert').addClass('d-none');
				$('#kpi-visitantes').text(fmtNum(k.visitantes_unicos));
				$('#kpi-visitas').text(fmtNum(k.visitas) + ' pageviews');
				$('#kpi-shares').text(fmtNum(k.compartilhamentos));
				$('#kpi-novos-visitantes').text(fmtNum(k.novos_visitantes));
				renderSerie(k.serie_diaria || []);
				renderShares(k.shares_plataforma || []);
				renderTopPaginas(k.top_paginas || []);
			} else {
				$('#rel-analytics-alert').removeClass('d-none');
				$('#kpi-visitantes').text('—');
				$('#kpi-visitas').text('— pageviews');
				$('#kpi-shares').text('—');
				$('#kpi-novos-visitantes').text('—');
				renderSerie([]);
				renderShares([]);
				renderTopPaginas([]);
			}

			renderEscolas(k.candidatos_por_escola || []);
			renderFunil(k);
		}, 'json').fail(function () {
			Swal.fire('Erro', 'Falha de rede ao carregar resumo.', 'error');
		});
	}

	function whatsCell(r) {
		var w = r.whatsapp || '';
		var digits = r.whatsappDigits || String(w).replace(/\D+/g, '');
		if (!digits) {
			return '<span class="text-muted">—</span>';
		}
		var label = w || digits;
		return '<a href="https://wa.me/55' + esc(digits) + '" target="_blank" rel="noopener" class="small text-nowrap">' + esc(label) + '</a>';
	}

	function carregarCandidatos(page) {
		candPage = page || 1;
		var payload = $.extend({ acao: 'candidatos', page: candPage }, filtrosComuns());
		$('#tb-candidatos').html('<tr><td colspan="8" class="text-muted text-center py-3">Carregando…</td></tr>');

		$.post(url_base + API, payload, function (res) {
			if (!res || !res.success) {
				var msg = (res && res.message) ? res.message : 'Erro ao carregar';
				$('#tb-candidatos').html('<tr><td colspan="8" class="text-danger text-center py-3">' + esc(msg) + '</td></tr>');
				return;
			}
			var $tb = $('#tb-candidatos').empty();
			if (!res.items || !res.items.length) {
				$tb.append('<tr><td colspan="8" class="text-muted text-center py-4">Nenhum candidato encontrado.</td></tr>');
			} else {
				res.items.forEach(function (r) {
					var selo = r.temSelo
						? '<span class="badge bg-success">Sim</span>'
						: '<span class="badge bg-secondary">Não</span>';
					$tb.append(
						'<tr>'
						+ '<td>' + esc(r.nome) + '</td>'
						+ '<td>' + whatsCell(r) + '</td>'
						+ '<td class="small">' + esc(r.tipoLabel) + '</td>'
						+ '<td class="small">' + esc(r.escolaNome) + '</td>'
						+ '<td class="small">' + esc(r.cidadeNome) + '</td>'
						+ '<td>' + esc(r.uf) + '</td>'
						+ '<td>' + selo + '</td>'
						+ '<td class="small text-nowrap">' + esc(r.createdAtBr) + '</td>'
						+ '</tr>'
					);
				});
			}
			var total = res.total || 0;
			var pages = res.pages || 1;
			$('#rel-cand-info').text(total + ' registro(s) · página ' + candPage + ' de ' + pages);
			$('#btn-cand-prev').prop('disabled', candPage <= 1);
			$('#btn-cand-next').prop('disabled', candPage >= pages);
		}, 'json').fail(function (xhr) {
			var msg = 'Falha de rede';
			try {
				var err = xhr.responseJSON;
				if (err && err.message) msg = err.message;
			} catch (e) {}
			$('#tb-candidatos').html('<tr><td colspan="8" class="text-danger text-center py-3">' + esc(msg) + '</td></tr>');
		});
	}

	function recarregar() {
		carregarResumo();
		carregarCandidatos(1);
	}

	function exportarCsv() {
		var $form = $('<form method="post" action="' + url_base + API + '/export" target="_blank"></form>');
		var data = filtrosComuns();
		Object.keys(data).forEach(function (k) {
			$form.append($('<input type="hidden">').attr('name', k).val(data[k]));
		});
		$('body').append($form);
		$form.trigger('submit');
		$form.remove();
	}

	$('#btn-rel-filtrar').on('click', recarregar);
	$('#filt-escola, #filt-tipo, #filt-status').on('change', function () { carregarCandidatos(1); });
	$('#filt-uf, #filt-q').on('change blur', function () { carregarCandidatos(1); });
	$('#btn-cand-prev').on('click', function () { if (candPage > 1) carregarCandidatos(candPage - 1); });
	$('#btn-cand-next').on('click', function () { carregarCandidatos(candPage + 1); });
	$('#btn-export-csv').on('click', exportarCsv);

	recarregar();
})();
