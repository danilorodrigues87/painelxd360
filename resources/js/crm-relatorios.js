(function () {
	'use strict';

	var API = 'painel/crm/relatorios';

	function esc(s) {
		return $('<div>').text(s == null ? '' : String(s)).html();
	}

	function moeda(v) {
		return 'R$ ' + Number(v || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
	}

	function carregar() {
		$.post(url_base + API, {
			acao: 'resumo',
			de: $('#rel-de').val() || '',
			ate: $('#rel-ate').val() || ''
		}, function (res) {
			if (!res || !res.success) {
				Swal.fire('Atenção', (res && res.message) || 'Falha ao carregar', 'warning');
				return;
			}
			var p = res.periodo || {};
			$('#rel-periodo').text('Período: ' + (p.de_br || '') + ' a ' + (p.ate_br || ''));
			if (p.de) $('#rel-de').val(p.de);
			if (p.ate) $('#rel-ate').val(p.ate);

			var k = res.kpis || {};
			$('#kpi-total').text(k.total != null ? k.total : '—');
			$('#kpi-mat').text(k.matriculados != null ? k.matriculados : '—');
			$('#kpi-conv').text(k.conversao_pct != null ? (k.conversao_pct + '% de conversão') : '');
			$('#kpi-perd').text(k.perdidos != null ? k.perdidos : '—');
			$('#kpi-perda').text(k.perda_pct != null ? (k.perda_pct + '% de perda') : '');
			$('#kpi-valor').text(k.valor_estimado_br != null ? ('R$ ' + k.valor_estimado_br) : '—');

			var $st = $('#tb-status').empty();
			(res.por_status || []).forEach(function (r) {
				$st.append('<tr><td>' + esc(r.label) + '</td><td class="text-end">' + esc(r.qtd) + '</td><td class="text-end">' + esc(moeda(r.valor)) + '</td></tr>');
			});
			if (!(res.por_status || []).length) {
				$st.append('<tr><td colspan="3" class="text-muted text-center">Sem dados</td></tr>');
			}

			var $m = $('#tb-motivos').empty();
			(res.motivos_perda || []).forEach(function (r) {
				$m.append('<tr><td>' + esc(r.motivo) + '</td><td class="text-end">' + esc(r.qtd) + '</td></tr>');
			});
			if (!(res.motivos_perda || []).length) {
				$m.append('<tr><td colspan="2" class="text-muted text-center">Nenhuma perda no período</td></tr>');
			}

			var $f = $('#tb-funis').empty();
			(res.por_funil || []).forEach(function (r) {
				var ps = r.por_status || {};
				$f.append(
					'<tr>'
					+ '<td>' + esc(r.nome) + '</td>'
					+ '<td class="text-end">' + esc(r.total) + '</td>'
					+ '<td class="text-end">' + esc(ps.novo || 0) + '</td>'
					+ '<td class="text-end">' + esc(ps.em_atendimento || 0) + '</td>'
					+ '<td class="text-end">' + esc(ps.matriculado || 0) + '</td>'
					+ '<td class="text-end">' + esc(ps.perdido || 0) + '</td>'
					+ '<td class="text-end">' + esc(r.conversao_pct) + '%</td>'
					+ '</tr>'
				);
			});
			if (!(res.por_funil || []).length) {
				$f.append('<tr><td colspan="7" class="text-muted text-center">Sem dados</td></tr>');
			}

			var $o = $('#tb-origens').empty();
			(res.origens || []).forEach(function (r) {
				$o.append('<tr><td>' + esc(r.origem) + '</td><td class="text-end">' + esc(r.qtd) + '</td></tr>');
			});
			if (!(res.origens || []).length) {
				$o.append('<tr><td colspan="2" class="text-muted text-center">Sem dados</td></tr>');
			}

			$('#rel-tarefas-nota').text(res.tarefas_nota || '');
		}, 'json');
	}

	$(function () {
		var hoje = new Date();
		var y = hoje.getFullYear();
		var m = String(hoje.getMonth() + 1).padStart(2, '0');
		var d = String(hoje.getDate()).padStart(2, '0');
		$('#rel-de').val(y + '-' + m + '-01');
		$('#rel-ate').val(y + '-' + m + '-' + d);
		$('#btn-rel-filtrar').on('click', carregar);
		carregar();
	});
})();
