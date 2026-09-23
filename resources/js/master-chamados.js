(function () {
	'use strict';

	var API = 'master/chamados';
	var chamadoId = 0;
	var modalDetalhe = null;

	function esc(s) {
		return $('<div>').text(s == null ? '' : String(s)).html();
	}

	function badgeStatus(st, label) {
		var map = {
			aberto: 'primary',
			em_andamento: 'info',
			aguardando_escola: 'warning',
			resolvido: 'success',
			fechado: 'secondary'
		};
		return '<span class="badge bg-' + (map[st] || 'secondary') + '">' + esc(label || st) + '</span>';
	}

	function popularFiltros() {
		var $esc = $('#filtro_escola');
		$esc.find('option:not(:first)').remove();
		(window.MASTER_ESCOLAS_CHAMADOS || []).forEach(function (e) {
			$esc.append('<option value="' + e.id + '">' + esc(e.nome) + '</option>');
		});
		var $st = $('#filtro_status');
		var $detSt = $('#det-status');
		$st.find('option:not(:first)').remove();
		$detSt.empty();
		(window.MASTER_CHAMADOS_STATUS || []).forEach(function (s) {
			$st.append('<option value="' + esc(s.slug) + '">' + esc(s.label) + '</option>');
			$detSt.append('<option value="' + esc(s.slug) + '">' + esc(s.label) + '</option>');
		});
		var $cat = $('#filtro_categoria');
		$cat.find('option:not(:first)').remove();
		(window.MASTER_CHAMADOS_CATEGORIAS || []).forEach(function (c) {
			$cat.append('<option value="' + esc(c.slug) + '">' + esc(c.label) + '</option>');
		});
	}

	function carregarLista() {
		$.post(url_base + API, {
			acao: 'listar',
			id_admin: $('#filtro_escola').val() || '',
			status: $('#filtro_status').val() || '',
			categoria: $('#filtro_categoria').val() || '',
			busca: $('#filtro_busca').val() || ''
		}, function (res) {
			var $tb = $('#tbody-chamados').empty();
			if (res && res.abertos != null) {
				$('#badge-abertos').text(res.abertos);
			}
			if (!res || !res.success) {
				$tb.append('<tr><td colspan="6" class="text-center text-danger py-4">' + esc((res && res.message) || 'Falha.') + '</td></tr>');
				return;
			}
			if (!res.itens || !res.itens.length) {
				$tb.append('<tr><td colspan="6" class="text-center text-muted py-4">Nenhum chamado.</td></tr>');
				return;
			}
			res.itens.forEach(function (c) {
				$tb.append(
					'<tr class="chamado-row" style="cursor:pointer" data-id="' + c.id + '">'
					+ '<td><strong>' + esc(c.numero) + '</strong></td>'
					+ '<td>' + esc(c.escola_nome) + '</td>'
					+ '<td>' + esc(c.categoria_label) + '</td>'
					+ '<td>' + esc(c.assunto) + '</td>'
					+ '<td>' + badgeStatus(c.status, c.status_label) + '</td>'
					+ '<td><small>' + esc(c.updated_at) + '</small></td>'
					+ '</tr>'
				);
			});
		}, 'json');
	}

	function renderThread(c) {
		var html = '';
		(c.mensagens || []).forEach(function (m) {
			var lado = m.autor_tipo === 'master' ? 'border-primary' : 'border-secondary';
			html += '<div class="border-start border-3 ' + lado + ' ps-3 mb-3">'
				+ '<div class="d-flex justify-content-between small text-muted mb-1">'
				+ '<strong>' + esc(m.autor_nome) + '</strong><span>' + esc(m.created_at) + '</span></div>'
				+ '<div style="white-space:pre-wrap">' + esc(m.mensagem) + '</div>';
			if (m.anexo && m.anexo_url) {
				html += '<div class="mt-2"><a href="' + esc(m.anexo_url) + '" target="_blank" rel="noopener">'
					+ '<i class="fas fa-image me-1"></i>' + esc(m.anexo_nome || 'Ver print') + '</a></div>';
			}
			html += '</div>';
		});
		$('#det-thread').html(html || '<p class="text-muted">Sem mensagens.</p>');
		$('#det-titulo').text(c.numero + ' — ' + c.assunto);
		$('#det-meta').text(c.escola_nome + ' · aberto por ' + c.aberto_por + ' · ' + c.categoria_label);
		$('#det-status').val(c.status);
		$('#det-responder-box').toggleClass('d-none', !c.pode_responder);
	}

	function abrirDetalhe(id) {
		chamadoId = id;
		$('#det-mensagem, #det-anexo').val('');
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
		popularFiltros();
		modalDetalhe = new bootstrap.Modal(document.getElementById('modalDetalheChamado'));
		carregarLista();

		$('#btn-atualizar, #filtro_escola, #filtro_status, #filtro_categoria').on('click change', carregarLista);
		var t = null;
		$('#filtro_busca').on('input', function () {
			clearTimeout(t);
			t = setTimeout(carregarLista, 350);
		});

		$(document).on('click', '.chamado-row', function () {
			abrirDetalhe(parseInt($(this).data('id'), 10));
		});

		$('#btn-salvar-status').on('click', function () {
			if (!chamadoId) return;
			$.post(url_base + API, {
				acao: 'status',
				id: chamadoId,
				status: $('#det-status').val()
			}, function (res) {
				if (!res || !res.success) {
					Swal.fire('Atenção', (res && res.message) || 'Falha.', 'warning');
					return;
				}
				renderThread(res.chamado);
				if (res.abertos != null) $('#badge-abertos').text(res.abertos);
				carregarLista();
				Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Status atualizado', showConfirmButton: false, timer: 1800 });
			}, 'json');
		});

		$('#btn-responder').on('click', function () {
			if (!chamadoId) return;
			var fd = new FormData();
			fd.append('acao', 'responder');
			fd.append('id', String(chamadoId));
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
