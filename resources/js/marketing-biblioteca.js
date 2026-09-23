(function () {
	'use strict';

	var API = url_base + 'painel/marketing/biblioteca';
	var UPLOAD = url_base + 'painel/marketing/biblioteca/upload';
	var S = window.SocialBibShared;
	var bibFiltroFormato = 'feed';
	var bibFiltroTipo = 'image';

	function postApi(data, cb) {
		$.post(API, data, cb, 'json').fail(function () {
			Swal.fire('Erro', 'Falha na requisição.', 'error');
		});
	}

	function syncUploadFormatoUi() {
		var ehImagem = bibFiltroTipo === 'image' || bibFiltroTipo === '';
		$('#wrap-bib-upload-formato').toggleClass('d-none', !ehImagem || bibFiltroFormato === '');
		if (bibFiltroFormato === 'feed' || bibFiltroFormato === 'story') {
			$('#bib-upload-formato').val(bibFiltroFormato);
		}
	}

	function loadBiblioteca() {
		var payload = { acao: 'listar' };
		if (bibFiltroTipo) payload.tipo = bibFiltroTipo;
		if (bibFiltroFormato) payload.formato = bibFiltroFormato;
		postApi(payload, function (r) {
			if (!r || r.sql_ok === false) {
				$('#bib-grid').html('<div class="col-12 text-warning">' + S.esc((r && r.message) || 'SQL pendente') + '</div>');
				return;
			}
			if (r.formato_col_ok === false) {
				$('#bib-alerta-formato').removeClass('d-none');
			} else {
				$('#bib-alerta-formato').addClass('d-none');
			}
			if (r.stats) {
				$('#bib-stats').text(r.stats.total_itens + ' arquivo(s) · ' + (r.stats.total_bytes_fmt || S.formatBytes(r.stats.total_bytes)));
			}
			S.renderGrid('#bib-grid', (r && r.itens) || [], { pickMode: false });
		});
	}

	function uploadOne(file, formato) {
		var fd = new FormData();
		fd.append('arquivo', file);
		if (formato) fd.append('formato', formato);
		return $.ajax({
			url: UPLOAD,
			method: 'POST',
			data: fd,
			processData: false,
			contentType: false,
			dataType: 'json'
		});
	}

	function uploadFiles(files) {
		var list = Array.prototype.slice.call(files || []);
		var chain = $.Deferred().resolve(0).promise();
		var ok = 0;
		list.forEach(function (file) {
			chain = chain.then(function () {
				var ehVideo = S.guessTipo(file.type || file.name) === 'video';
				var fmt = ehVideo ? '' : ($('#bib-upload-formato').val() || bibFiltroFormato || 'feed');
				return uploadOne(file, fmt).then(function (r) {
					if (r && r.success) ok++;
					return ok;
				}, function () { return ok; });
			});
		});
		return chain;
	}

	function abrirEditarBib($btn) {
		var id = parseInt($btn.data('id'), 10);
		var titulo = String($btn.data('titulo') || '');
		var tipo = String($btn.data('tipo') || 'image');
		var formato = String($btn.data('formato') || 'feed');
		var html = '<div class="text-start">'
			+ '<label class="form-label small mb-1" for="swal-bib-titulo">Título</label>'
			+ '<input id="swal-bib-titulo" class="form-control mb-2" value="' + S.esc(titulo) + '">';
		if (tipo === 'image') {
			html += '<label class="form-label small mb-1" for="swal-bib-formato">Categoria</label>'
				+ '<select id="swal-bib-formato" class="form-select">'
				+ '<option value="feed"' + (formato === 'feed' ? ' selected' : '') + '>Quadrado (feed)</option>'
				+ '<option value="story"' + (formato === 'story' ? ' selected' : '') + '>Story (vertical)</option>'
				+ '</select>';
		}
		html += '</div>';

		Swal.fire({
			title: 'Editar mídia',
			html: html,
			showCancelButton: true,
			confirmButtonText: 'Salvar',
			focusConfirm: false,
			preConfirm: function () {
				return {
					titulo: ($('#swal-bib-titulo').val() || '').trim(),
					formato: $('#swal-bib-formato').val() || formato
				};
			}
		}).then(function (r) {
			if (!r.isConfirmed) return;
			var payload = { acao: 'salvar', id: id, titulo: r.value.titulo };
			if (tipo === 'image') payload.formato = r.value.formato;
			postApi(payload, function (res) {
				if (!res || !res.success) {
					Swal.fire('Erro', (res && res.message) || 'Falha.', 'error');
					return;
				}
				loadBiblioteca();
			});
		});
	}

	$(function () {
		syncUploadFormatoUi();
		loadBiblioteca();

		$('#bib-filtro-formato .nav-link').on('click', function () {
			bibFiltroFormato = String($(this).data('formato') || '');
			bibFiltroTipo = String($(this).data('tipo') || '');
			$('#bib-filtro-formato .nav-link').removeClass('active');
			$(this).addClass('active');
			syncUploadFormatoUi();
			loadBiblioteca();
		});

		$('#bib-upload').on('change', function () {
			var files = this.files;
			if (!files || !files.length) return;
			$('#bib-upload').prop('disabled', true);
			uploadFiles(files).always(function (ok) {
				$('#bib-upload').prop('disabled', false).val('');
				loadBiblioteca();
				if (!ok) Swal.fire('Upload', 'Nenhum arquivo enviado com sucesso.', 'warning');
			});
		});

		$('#btn-bib-refresh').on('click', loadBiblioteca);

		$(document).on('click', '.bib-ver, .bib-thumb', function (e) {
			e.preventDefault();
			e.stopPropagation();
			S.abrirPreview($(this).data('url'), $(this).data('tipo'), $(this).data('titulo'));
		});

		$(document).on('click', '.bib-edit', function () {
			abrirEditarBib($(this));
		});

		$(document).on('click', '.bib-del', function () {
			var id = parseInt($(this).data('id'), 10);
			Swal.fire({
				title: 'Excluir mídia?',
				text: 'O arquivo será removido do servidor se não estiver em uso.',
				icon: 'warning',
				showCancelButton: true,
				confirmButtonColor: '#dc3545',
				confirmButtonText: 'Excluir'
			}).then(function (r) {
				if (!r.isConfirmed) return;
				postApi({ acao: 'excluir', id: id }, function (res) {
					if (!res || !res.success) {
						var extra = '';
						if (res && res.usos && res.usos.length) {
							extra = '<ul class="text-start small mt-2">' + res.usos.map(function (u) {
								return '<li>' + S.esc(u.detalhe || u.ref) + '</li>';
							}).join('') + '</ul>';
						}
						Swal.fire({
							title: 'Não foi possível',
							html: S.esc((res && res.message) || 'Falha.') + extra,
							icon: 'error'
						});
						return;
					}
					Swal.fire('OK', res.message || 'Removida.', 'success');
					loadBiblioteca();
				});
			});
		});

		$('#modalBibPreview').on('hidden.bs.modal', function () {
			$('#bib-preview-body').empty();
		});
	});
})();
