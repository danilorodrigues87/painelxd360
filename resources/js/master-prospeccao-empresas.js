(function () {
	'use strict';

	var API = 'master/prospeccao-empresas';
	var page = 1;
	var googlePageToken = null;
	var googleQuery = '';
	var importInfo = null;
	var importRunning = false;

	function esc(s) {
		return $('<div>').text(s == null ? '' : String(s)).html();
	}

	function filtros() {
		return {
			q: ($('#filt-q').val() || '').trim(),
			com_whatsapp: $('#filt-whatsapp').is(':checked') ? 1 : 0
		};
	}

	function botoesGoogle(disabled) {
		$('#btn-google-buscar, #btn-google-mais, #btn-google-todas, #btn-google-categorias, #btn-google-completa')
			.prop('disabled', !!disabled);
	}

	function initGoogleUi() {
		var ok = window.PROSP_GOOGLE_OK === 1 || window.PROSP_GOOGLE_OK === '1';
		if (!ok) {
			botoesGoogle(true);
		}
	}

	function atualizarBotoesCidade() {
		var q = ($('#google-q').val() || '').trim();
		if (q.length < 3) {
			$('#btn-google-categorias, #btn-google-completa').addClass('d-none');
			return;
		}
		$.post(url_base + API, { acao: 'import_info', q: q }, function (res) {
			if (!res || !res.success) return;
			importInfo = res;
			if (res.isCidade) {
				$('#btn-google-categorias, #btn-google-completa').removeClass('d-none');
			} else {
				$('#btn-google-categorias, #btn-google-completa').addClass('d-none');
			}
		}, 'json');
	}

	function carregarStats() {
		$.post(url_base + API, { acao: 'stats' }, function (res) {
			if (!res || !res.success || !res.stats) return;
			var s = res.stats;
			$('#stat-total').text(s.total != null ? s.total : '—');
			$('#stat-whatsapp').text(s.comWhatsapp != null ? s.comWhatsapp : '—');
			$('#stat-ultima').text(s.ultimaImportacaoBr || '—');
		}, 'json');
	}

	function carregarLista(p) {
		page = p || 1;
		var payload = $.extend({ acao: 'listar', page: page }, filtros());
		$('#tb-empresas').html('<tr><td colspan="8" class="text-muted text-center py-3">Carregando…</td></tr>');

		$.post(url_base + API, payload, function (res) {
			if (!res || !res.success) {
				var msg = (res && res.message) ? res.message : 'Erro ao carregar';
				$('#tb-empresas').html('<tr><td colspan="8" class="text-danger text-center py-3">' + esc(msg) + '</td></tr>');
				return;
			}
			var $tb = $('#tb-empresas').empty();
			if (!res.items || !res.items.length) {
				$tb.append('<tr><td colspan="8" class="text-muted text-center py-4">Nenhuma empresa salva. Importe do Google acima.</td></tr>');
			} else {
				res.items.forEach(function (r) {
					var wa = r.whatsappUrl
						? '<a href="' + esc(r.whatsappUrl) + '" target="_blank" rel="noopener" class="small">' + esc(r.telefone || r.whatsappDigits) + '</a>'
						: '<span class="text-muted">—</span>';
					var maps = r.mapsUrl
						? '<a href="' + esc(r.mapsUrl) + '" target="_blank" rel="noopener" class="small">Abrir</a>'
						: '<span class="text-muted">—</span>';
					var nota = r.nota != null && r.nota !== '' ? esc(String(r.nota)) : '—';
					$tb.append(
						'<tr>'
						+ '<td><div class="fw-medium">' + esc(r.nome) + '</div>'
						+ (r.queryOrigem ? '<div class="text-muted" style="font-size:11px">' + esc(r.queryOrigem) + '</div>' : '')
						+ '</td>'
						+ '<td class="small">' + esc(r.endereco || '—') + '</td>'
						+ '<td class="small text-nowrap">' + esc(r.telefone || '—') + '</td>'
						+ '<td>' + wa + '</td>'
						+ '<td>' + maps + '</td>'
						+ '<td>' + nota + '</td>'
						+ '<td class="small text-nowrap">' + esc(r.importadoEmBr || '—') + '</td>'
						+ '<td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger btn-excluir" data-id="' + esc(r.id) + '" title="Excluir"><i class="fas fa-trash"></i></button></td>'
						+ '</tr>'
					);
				});
			}
			var total = res.total || 0;
			var pages = res.pages || 1;
			$('#lista-info').text(total + ' registro(s) · página ' + page + ' de ' + pages);
			$('#btn-prev').prop('disabled', page <= 1);
			$('#btn-next').prop('disabled', page >= pages);
		}, 'json').fail(function () {
			$('#tb-empresas').html('<tr><td colspan="8" class="text-danger text-center py-3">Falha de rede</td></tr>');
		});
	}

	function postBuscar(payload) {
		return $.post(url_base + API, payload);
	}

	function finalizarImport(res, silent) {
		botoesGoogle(false);
		importRunning = false;
		$('#google-progress-wrap').addClass('d-none');

		if (!res || !res.success) {
			if (!silent) {
				Swal.fire('Erro', (res && res.message) || 'Falha na busca Google.', 'error');
			}
			return null;
		}

		googlePageToken = res.nextPageToken || null;
		if (googlePageToken) {
			$('#btn-google-mais').removeClass('d-none');
		} else {
			$('#btn-google-mais').addClass('d-none');
		}
		$('#google-info').removeClass('d-none').text(res.message || 'Importação concluída.');

		if (!silent) {
			var icon = (res.totalPagina || 0) > 0 ? 'success' : 'info';
			var title = (res.totalPagina || 0) > 0 ? 'Importado' : 'Concluído';
			Swal.fire(title, res.message || 'Dados salvos no banco.', icon);
		}

		carregarStats();
		carregarLista(1);
		return res;
	}

	function buscarGoogle(more, estrategia) {
		var q = ($('#google-q').val() || '').trim();
		if (!more) {
			googleQuery = q;
			googlePageToken = null;
		}
		if (!googleQuery || googleQuery.length < 3) {
			Swal.fire('Atenção', 'Informe pelo menos 3 caracteres na busca Google.', 'warning');
			return;
		}

		botoesGoogle(true);
		var payload = { acao: 'buscar', q: googleQuery, estrategia: estrategia || 'pagina' };
		if (more && googlePageToken) {
			payload.pageToken = googlePageToken;
		}

		postBuscar(payload).done(function (res) {
			finalizarImport(res, false);
		}).fail(function () {
			botoesGoogle(false);
			Swal.fire('Erro', 'Falha de rede ao buscar no Google.', 'error');
		});
	}

	function setProgress(atual, total, label) {
		var pct = total > 0 ? Math.round((atual / total) * 100) : 0;
		$('#google-progress-wrap').removeClass('d-none');
		$('#google-progress').css('width', pct + '%');
		$('#google-info').removeClass('d-none').text(label || ('Etapa ' + atual + ' de ' + total));
	}

	function importacaoCompleta() {
		var q = ($('#google-q').val() || '').trim();
		if (q.length < 3) {
			Swal.fire('Atenção', 'Informe o nome da cidade (ex.: Guapiara SP).', 'warning');
			return;
		}

		$.post(url_base + API, { acao: 'import_info', q: q }, function (info) {
			if (!info || !info.success) return;
			if (!info.isCidade) {
				Swal.fire('Atenção', 'Importação completa exige busca só com nome da cidade.', 'warning');
				return;
			}

			var req = info.reqEstimadaCompleta || 48;
			var usd = info.custoEstimadoUsd || 1.5;
			Swal.fire({
				title: 'Importação completa?',
				html: 'Cidade: <strong>' + esc(q) + '</strong><br>'
					+ 'Até <strong>' + req + '</strong> requisições Google (~US$ ' + usd + ')<br>'
					+ 'Busca geral + ' + info.totalCategorias + ' categorias (até 3 páginas cada).',
				icon: 'warning',
				showCancelButton: true,
				confirmButtonText: 'Importar tudo',
				cancelButtonText: 'Cancelar'
			}).then(function (result) {
				if (!result.isConfirmed) return;
				executarImportacaoCompleta(q, info.totalCategorias || 15);
			});
		}, 'json');
	}

	function executarImportacaoCompleta(q, totalCats) {
		importRunning = true;
		botoesGoogle(true);
		googleQuery = q;

		var etapas = [];
		etapas.push({ estrategia: 'todas_paginas', label: 'Busca geral' });
		for (var i = 0; i < totalCats; i++) {
			etapas.push({ estrategia: 'categoria_todas_paginas', categoriaIndex: i, label: 'Categoria ' + (i + 1) });
		}

		var totalNovos = 0;
		var totalAtualizados = 0;
		var idx = 0;

		function proxima() {
			if (!importRunning || idx >= etapas.length) {
				importRunning = false;
				botoesGoogle(false);
				$('#google-progress-wrap').addClass('d-none');
				Swal.fire(
					'Importação completa',
					totalNovos + ' novo(s), ' + totalAtualizados + ' atualizado(s) no total.',
					'success'
				);
				carregarStats();
				carregarLista(1);
				return;
			}

			var etapa = etapas[idx];
			setProgress(idx + 1, etapas.length, etapa.label + '…');

			var payload = {
				acao: 'buscar',
				q: q,
				estrategia: etapa.estrategia
			};
			if (etapa.categoriaIndex != null) {
				payload.categoriaIndex = etapa.categoriaIndex;
			}

			postBuscar(payload).done(function (res) {
				if (!res || !res.success) {
					importRunning = false;
					botoesGoogle(false);
					$('#google-progress-wrap').addClass('d-none');
					Swal.fire('Interrompido', (res && res.message) || 'Falha na importação.', 'error');
					carregarStats();
					carregarLista(1);
					return;
				}
				totalNovos += res.novos || 0;
				totalAtualizados += res.atualizados || 0;
				idx++;
				proxima();
			}).fail(function () {
				importRunning = false;
				botoesGoogle(false);
				$('#google-progress-wrap').addClass('d-none');
				Swal.fire('Erro', 'Falha de rede durante importação completa.', 'error');
			});
		}

		proxima();
	}

	function excluir(id) {
		Swal.fire({
			title: 'Excluir registro?',
			text: 'Remove da base local (não afeta o Google).',
			icon: 'warning',
			showCancelButton: true,
			confirmButtonText: 'Excluir',
			cancelButtonText: 'Cancelar'
		}).then(function (result) {
			if (!result.isConfirmed) return;
			$.post(url_base + API, { acao: 'excluir', id: id }, function (res) {
				if (!res || !res.success) {
					Swal.fire('Erro', (res && res.message) || 'Falha ao excluir.', 'error');
					return;
				}
				carregarStats();
				carregarLista(page);
			}, 'json');
		});
	}

	function exportarCsv() {
		var $form = $('<form method="post" action="' + url_base + API + '/export" target="_blank"></form>');
		var data = filtros();
		Object.keys(data).forEach(function (k) {
			$form.append($('<input type="hidden">').attr('name', k).val(data[k]));
		});
		$('body').append($form);
		$form.trigger('submit');
		$form.remove();
	}

	$('#btn-filtrar').on('click', function () { carregarLista(1); });
	$('#filt-whatsapp').on('change', function () { carregarLista(1); });
	$('#btn-prev').on('click', function () { if (page > 1) carregarLista(page - 1); });
	$('#btn-next').on('click', function () { carregarLista(page + 1); });
	$('#btn-google-buscar').on('click', function () { buscarGoogle(false, 'pagina'); });
	$('#btn-google-mais').on('click', function () { buscarGoogle(true, 'pagina'); });
	$('#btn-google-todas').on('click', function () { buscarGoogle(false, 'todas_paginas'); });
	$('#btn-google-categorias').on('click', function () { buscarGoogle(false, 'categorias'); });
	$('#btn-google-completa').on('click', importacaoCompleta);
	$('#btn-export-csv').on('click', exportarCsv);
	$('#google-q').on('input', atualizarBotoesCidade);
	$('#tb-empresas').on('click', '.btn-excluir', function () {
		excluir($(this).data('id'));
	});

	initGoogleUi();
	atualizarBotoesCidade();
	carregarStats();
	carregarLista(1);
})();
