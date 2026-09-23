function postDoc(data) {
	return $.ajax({
		url: url_base + 'master/documentacao',
		method: 'POST',
		dataType: 'json',
		data: data
	});
}

function showModal(id) {
	var el = document.getElementById(id);
	if (window.bootstrap && bootstrap.Modal) bootstrap.Modal.getOrCreateInstance(el).show();
	else $(el).modal('show');
}

function hideModal(id) {
	var el = document.getElementById(id);
	if (window.bootstrap && bootstrap.Modal) bootstrap.Modal.getOrCreateInstance(el).hide();
	else $(el).modal('hide');
}

$(function () {
	$('#btn-nova-cat').on('click', function () {
		$('#cat_id').val('');
		$('#cat_titulo').val('');
		$('#cat_slug').val('');
		$('#cat_ordem').val('0');
		$('#cat_ativo').prop('checked', true);
		$('#btn-del-cat').addClass('d-none');
		showModal('modalCat');
	});

	$(document).on('click', '.btn-edit-cat', function () {
		var b = $(this);
		$('#cat_id').val(b.data('id'));
		$('#cat_titulo').val(b.data('titulo'));
		$('#cat_slug').val(b.data('slug'));
		$('#cat_ordem').val(b.data('ordem'));
		$('#cat_ativo').prop('checked', Number(b.data('ativo')) === 1);
		$('#btn-del-cat').removeClass('d-none');
		showModal('modalCat');
	});

	$('#btn-save-cat').on('click', function () {
		postDoc({
			acao: 'salvar_categoria',
			id: $('#cat_id').val(),
			titulo: $('#cat_titulo').val(),
			slug: $('#cat_slug').val(),
			ordem: $('#cat_ordem').val(),
			ativo: $('#cat_ativo').is(':checked') ? 1 : 0
		}).done(function (res) {
			if (!res || !res.success) return Swal.fire('Erro', (res && res.message) || 'Falha', 'error');
			location.reload();
		});
	});

	$('#btn-del-cat').on('click', function () {
		var id = $('#cat_id').val();
		Swal.fire({ title: 'Excluir categoria e artigos?', showCancelButton: true, confirmButtonText: 'Excluir' }).then(function (r) {
			if (!r.isConfirmed) return;
			postDoc({ acao: 'excluir_categoria', id: id }).done(function () { location.reload(); });
		});
	});

	$(document).on('click', '.btn-new-art', function () {
		$('#art_id').val('');
		$('#art_cat').val($(this).data('cat'));
		$('#art_titulo').val('');
		$('#art_slug').val('');
		$('#art_resumo').val('');
		$('#art_corpo').val('');
		$('#art_video_url').val('');
		$('#art_video_titulo').val('');
		$('#art_ordem').val('0');
		$('#art_publicado').prop('checked', false);
		showModal('modalArt');
	});

	$(document).on('click', '.btn-edit-art', function () {
		var id = $(this).data('id');
		postDoc({ acao: 'get_artigo', id: id }).done(function (res) {
			if (!res || !res.success) return Swal.fire('Erro', (res && res.message) || 'Falha', 'error');
			var a = res.artigo;
			$('#art_id').val(a.id);
			$('#art_cat').val(a.id_categoria);
			$('#art_titulo').val(a.titulo);
			$('#art_slug').val(a.slug);
			$('#art_resumo').val(a.resumo);
			$('#art_corpo').val(a.corpo);
			$('#art_video_url').val(a.video_url);
			$('#art_video_titulo').val(a.video_titulo);
			$('#art_ordem').val(a.ordem);
			$('#art_publicado').prop('checked', Number(a.publicado) === 1);
			showModal('modalArt');
		});
	});

	$(document).on('click', '.btn-del-art', function () {
		var id = $(this).data('id');
		Swal.fire({ title: 'Excluir artigo?', showCancelButton: true, confirmButtonText: 'Excluir' }).then(function (r) {
			if (!r.isConfirmed) return;
			postDoc({ acao: 'excluir_artigo', id: id }).done(function () { location.reload(); });
		});
	});

	$('#btn-save-art').on('click', function () {
		postDoc({
			acao: 'salvar_artigo',
			id: $('#art_id').val(),
			id_categoria: $('#art_cat').val(),
			titulo: $('#art_titulo').val(),
			slug: $('#art_slug').val(),
			resumo: $('#art_resumo').val(),
			corpo: $('#art_corpo').val(),
			video_url: $('#art_video_url').val(),
			video_titulo: $('#art_video_titulo').val(),
			ordem: $('#art_ordem').val(),
			publicado: $('#art_publicado').is(':checked') ? 1 : 0
		}).done(function (res) {
			if (!res || !res.success) return Swal.fire('Erro', (res && res.message) || 'Falha', 'error');
			location.reload();
		});
	});

	$('#btn-seed-tutoriais').on('click', function () {
		Swal.fire({
			title: 'Carregar tutoriais padrão?',
			html: 'Cria/atualiza categorias e artigos escritos de todos os módulos.<br><small>URLs de vídeo já preenchidas <strong>não</strong> serão apagadas.</small>',
			icon: 'question',
			showCancelButton: true,
			confirmButtonText: 'Carregar'
		}).then(function (r) {
			if (!r.isConfirmed) return;
			postDoc({ acao: 'seed_tutoriais' }).done(function (res) {
				if (!res || !res.success) return Swal.fire('Erro', (res && res.message) || 'Falha', 'error');
				Swal.fire('Pronto', res.message || 'Tutoriais aplicados.', 'success').then(function () {
					location.reload();
				});
			});
		});
	});
});
