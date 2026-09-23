(function () {
	'use strict';

	var API = 'painel/suporte';
	var chamadoAbertoId = 0;
	var modalNovo = null;
	var modalDetalhe = null;

	function esc(s) {
		return $('<div>').text(s == null ? '' : String(s)).html();
	}

	function badgeStatus(st) {
		var map = {
			aberto: 'primary',
			em_andamento: 'info',
			aguardando_escola: 'warning',
			resolvido: 'success',
			fechado: 'secondary'
		};
		return '<span class="badge bg-' + (map[st] || 'secondary') + '">' + esc(labelStatus(st)) + '</span>';
	}

	function labelStatus(st) {
		var list = window.SUPORTE_STATUS || [];
		for (var i = 0; i < list.length; i++) {
			if (list[i].slug === st) return list[i].label;
		}
		return st;
	}

	function popularSelects() {
		var $cat = $('#novo-categoria').empty();
		(window.SUPORTE_CATEGORIAS || []).forEach(function (c) {
			$cat.append('<option value="' + esc(c.slug) + '">' + esc(c.label) + '</option>');
		});
		var $st = $('#filtro-status');
		$st.find('option:not(:first)').remove();
		(window.SUPORTE_STATUS || []).forEach(function (s) {
			$st.append('<option value="' + esc(s.slug) + '">' + esc(s.label) + '</option>');
		});
	}

	function carregarLista() {
		$.post(url_base + API, {
			acao: 'listar',
			status: $('#filtro-status').val() || '',
			busca: $('#filtro-busca').val() || ''
		}, function (res) {
			var $tb = $('#tbody-chamados').empty();
			if (!res || !res.success) {
				$tb.append('<tr><td colspan="5" class="text-center text-danger py-4">' + esc((res && res.message) || 'Falha ao carregar.') + '</td></tr>');
				return;
			}
			if (!res.itens || !res.itens.length) {
				$tb.append('<tr><td colspan="5" class="text-center text-muted py-4">Nenhum chamado ainda.</td></tr>');
				return;
			}
			res.itens.forEach(function (c) {
				$tb.append(
					'<tr class="chamado-row" style="cursor:pointer" data-id="' + c.id + '">'
					+ '<td><strong>' + esc(c.numero) + '</strong></td>'
					+ '<td>' + esc(c.categoria_label) + '</td>'
					+ '<td>' + esc(c.assunto) + '</td>'
					+ '<td>' + badgeStatus(c.status) + '</td>'
					+ '<td><small>' + esc(c.updated_at) + '</small></td>'
					+ '</tr>'
				);
			});
		}, 'json').fail(function () {
			$('#tbody-chamados').html('<tr><td colspan="5" class="text-center text-danger py-4">Erro de rede.</td></tr>');
		});
	}

	function renderThread(chamado) {
		var html = '';
		(chamado.mensagens || []).forEach(function (m) {
			var lado = m.autor_tipo === 'master' ? 'border-primary' : 'border-secondary';
			var quem = m.autor_tipo === 'master' ? 'Suporte' : esc(m.autor_nome);
			html += '<div class="border-start border-3 ' + lado + ' ps-3 mb-3">'
				+ '<div class="d-flex justify-content-between small text-muted mb-1">'
				+ '<strong>' + quem + '</strong><span>' + esc(m.created_at) + '</span></div>'
				+ '<div style="white-space:pre-wrap">' + esc(m.mensagem) + '</div>';
			if (m.anexo && m.anexo_url) {
				html += '<div class="mt-2"><a href="' + esc(m.anexo_url) + '" target="_blank" rel="noopener">'
					+ '<i class="fas fa-image me-1"></i>' + esc(m.anexo_nome || 'Ver print') + '</a></div>';
			}
			html += '</div>';
		});
		$('#det-thread').html(html || '<p class="text-muted">Sem mensagens.</p>');
		$('#det-titulo').text(chamado.numero + ' — ' + chamado.assunto);
		$('#det-meta').text(chamado.categoria_label + ' · ' + chamado.status_label);
		if (chamado.pode_responder) {
			$('#det-responder-box').removeClass('d-none');
			$('#det-fechado-msg').addClass('d-none');
		} else {
			$('#det-responder-box').addClass('d-none');
			$('#det-fechado-msg').removeClass('d-none');
		}
	}

	function abrirDetalhe(id) {
		chamadoAbertoId = id;
		$('#det-mensagem').val('');
		$('#det-anexo').val('');
		$.post(url_base + API, { acao: 'detalhe', id: id }, function (res) {
			if (!res || !res.success) {
				Swal.fire('Erro', (res && res.message) || 'Falha.', 'error');
				return;
			}
			renderThread(res.chamado);
			modalDetalhe.show();
		}, 'json');
	}

	$(function () {
		popularSelects();
		modalNovo = new bootstrap.Modal(document.getElementById('modalNovoChamado'));
		modalDetalhe = new bootstrap.Modal(document.getElementById('modalDetalheChamado'));
		carregarLista();

		$('#btn-atualizar-lista, #filtro-status').on('click change', carregarLista);
		var tBusca = null;
		$('#filtro-busca').on('input', function () {
			clearTimeout(tBusca);
			tBusca = setTimeout(carregarLista, 350);
		});

		$('#btn-novo-chamado').on('click', function () {
			$('#novo-assunto, #novo-mensagem, #novo-anexo').val('');
			modalNovo.show();
		});

		$('#btn-enviar-chamado').on('click', function () {
			var fd = new FormData();
			fd.append('acao', 'abrir');
			fd.append('categoria', $('#novo-categoria').val() || '');
			fd.append('assunto', $('#novo-assunto').val() || '');
			fd.append('mensagem', $('#novo-mensagem').val() || '');
			var file = $('#novo-anexo')[0].files[0];
			if (file) fd.append('anexo', file);

			var $btn = $(this).prop('disabled', true);
			$.ajax({
				url: url_base + API,
				method: 'POST',
				data: fd,
				processData: false,
				contentType: false,
				dataType: 'json'
			}).done(function (res) {
				if (!res || !res.success) {
					Swal.fire('Atenção', (res && res.message) || 'Não foi possível abrir.', 'warning');
					return;
				}
				modalNovo.hide();
				Swal.fire('Pronto', res.message || 'Chamado aberto.', 'success');
				carregarLista();
			}).fail(function () {
				Swal.fire('Erro', 'Falha de rede.', 'error');
			}).always(function () {
				$btn.prop('disabled', false);
			});
		});

		$(document).on('click', '.chamado-row', function () {
			abrirDetalhe(parseInt($(this).data('id'), 10));
		});

		$('#btn-responder-chamado').on('click', function () {
			if (!chamadoAbertoId) return;
			var fd = new FormData();
			fd.append('acao', 'responder');
			fd.append('id', String(chamadoAbertoId));
			fd.append('mensagem', $('#det-mensagem').val() || '');
			var file = $('#det-anexo')[0].files[0];
			if (file) fd.append('anexo', file);

			var $btn = $(this).prop('disabled', true);
			$.ajax({
				url: url_base + API,
				method: 'POST',
				data: fd,
				processData: false,
				contentType: false,
				dataType: 'json'
			}).done(function (res) {
				if (!res || !res.success) {
					Swal.fire('Atenção', (res && res.message) || 'Falha.', 'warning');
					return;
				}
				$('#det-mensagem, #det-anexo').val('');
				renderThread(res.chamado);
				carregarLista();
			}).fail(function () {
				Swal.fire('Erro', 'Falha de rede.', 'error');
			}).always(function () {
				$btn.prop('disabled', false);
			});
		});
	});
})();
