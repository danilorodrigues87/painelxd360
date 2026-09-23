const MASTER_CONQ_URL = 'master/conquistas';
const CONQ_PAGE_SIZE = 25;

var _conqAll = [];
var _conqPage = 1;

function esc(s){
	return $('<div>').text(s == null ? '' : String(s)).html();
}

function raridadeBadge(r){
	const map = {
		bronze: 'bg-secondary',
		prata: 'bg-info text-dark',
		ouro: 'bg-warning text-dark',
		lendario: 'bg-danger',
		secreto: 'bg-dark'
	};
	const cls = map[r] || 'bg-secondary';
	return '<span class="badge '+cls+'">'+esc(r)+'</span>';
}

function popularSelects(){
	const $tipo = $('#conq_meta_tipo').empty();
	(window.MASTER_CONQ_META_TIPOS || []).forEach(function(t){
		$tipo.append('<option value="'+esc(t)+'">'+esc(t)+'</option>');
	});
	const $ico = $('#conq_icone').empty();
	(window.MASTER_CONQ_ICONES || []).forEach(function(t){
		$ico.append('<option value="'+esc(t)+'">'+esc(t)+'</option>');
	});
}

function limpar(){
	$('#conq_id').val('');
	$('#conq_slug, #conq_titulo, #conq_subtitulo, #conq_descricao, #conq_como').val('');
	$('#conq_meta_valor').val('1');
	$('#conq_ordem').val('0');
	$('#conq_ativo').val('1');
	$('#conq_raridade').val('bronze');
	$('#conq_badge').val('');
	$('#conq_badge_remover').prop('checked', false);
	$('#conq_badge_preview').empty();
	$('#titulo-modal-conquista').text('Nova conquista');
	if ($('#conq_meta_tipo option').length) $('#conq_meta_tipo').prop('selectedIndex', 0);
	if ($('#conq_icone option').length) $('#conq_icone').val('Trophy');
}

function filtrarLista(){
	var q = ($('#filtro-conq-busca').val() || '').toLowerCase().trim();
	var rar = ($('#filtro-conq-raridade').val() || '').trim();
	var ativo = ($('#filtro-conq-ativo').val() || '').trim();
	return _conqAll.filter(function(c){
		if (rar && String(c.raridade || '') !== rar) return false;
		if (ativo !== '' && String(c.ativo ? 1 : 0) !== ativo) return false;
		if (!q) return true;
		var blob = [
			c.titulo, c.subtitulo, c.slug, c.meta_tipo, c.descricao, c.raridade
		].join(' ').toLowerCase();
		return blob.indexOf(q) >= 0;
	});
}

function renderLista(){
	var lista = filtrarLista();
	var total = lista.length;
	var pages = Math.max(1, Math.ceil(total / CONQ_PAGE_SIZE));
	if (_conqPage > pages) _conqPage = pages;
	if (_conqPage < 1) _conqPage = 1;
	var start = (_conqPage - 1) * CONQ_PAGE_SIZE;
	var slice = lista.slice(start, start + CONQ_PAGE_SIZE);

	const $tb = $('#lista-conquistas-master').empty();
	if (!slice.length) {
		$tb.append('<tr><td colspan="6" class="text-center text-muted py-4">Nenhuma conquista encontrada.</td></tr>');
	} else {
		slice.forEach(function(c){
			const badge = c.ativo ? '<span class="badge bg-success">Ativo</span>' : '<span class="badge bg-secondary">Inativo</span>';
			const thumb = c.badge_full_url
				? '<img src="'+esc(c.badge_full_url)+'" alt="" style="width:32px;height:32px;object-fit:contain" class="me-2">'
				: '<i class="fas fa-medal me-2 text-muted"></i>';
			const titulo = (c.subtitulo ? '<strong>'+esc(c.subtitulo)+'</strong><br>' : '')
				+ '<span>'+esc(c.titulo)+'</span>'
				+ '<br><small class="text-muted">'+esc(c.slug)+'</small>';
			$tb.append(
				'<tr>'
				+'<td>'+esc(c.ordem)+'</td>'
				+'<td><div class="d-flex align-items-center">'+thumb+'<div>'+titulo+'</div></div></td>'
				+'<td><code>'+esc(c.meta_tipo)+'</code> ≥ '+esc(c.meta_valor)+'</td>'
				+'<td>'+raridadeBadge(c.raridade)+'</td>'
				+'<td>'+badge+'</td>'
				+'<td class="text-end">'
				+'<button type="button" class="btn btn-sm btn-outline-primary me-1 btn-editar-conq" data-id="'+c.id+'"><i class="fas fa-edit"></i></button>'
				+'<button type="button" class="btn btn-sm btn-outline-danger btn-excluir-conq" data-id="'+c.id+'"><i class="fas fa-trash"></i></button>'
				+'</td></tr>'
			);
		});
	}

	var from = total ? start + 1 : 0;
	var to = Math.min(start + CONQ_PAGE_SIZE, total);
	$('#conq-pag-resumo').text(total ? (from + '–' + to + ' de ' + total) : '0 registros');
	$('#conq-pag-info').text(_conqAll.length ? (_conqAll.length + ' no total') : '');

	var $pag = $('#conq-paginacao').empty();
	if (pages <= 1) return;
	$pag.append('<li class="page-item'+( _conqPage <= 1 ? ' disabled' : '')+'"><a class="page-link conq-pag-nav" href="#" data-page="'+(_conqPage-1)+'">‹</a></li>');
	var i;
	for (i = 1; i <= pages; i++) {
		if (pages > 9 && Math.abs(i - _conqPage) > 2 && i !== 1 && i !== pages) {
			if (i === 2 || i === pages - 1) {
				$pag.append('<li class="page-item disabled"><span class="page-link">…</span></li>');
			}
			continue;
		}
		$pag.append('<li class="page-item'+(_conqPage === i ? ' active' : '')+'"><a class="page-link conq-pag-nav" href="#" data-page="'+i+'">'+i+'</a></li>');
	}
	$pag.append('<li class="page-item'+( _conqPage >= pages ? ' disabled' : '')+'"><a class="page-link conq-pag-nav" href="#" data-page="'+(_conqPage+1)+'">›</a></li>');
}

function carregar(){
	$.post(url_base + MASTER_CONQ_URL, { acao: 'listar' }, function(res){
		if (!res || !res.success) {
			Swal.fire('Erro', (res && res.message) || 'Falha ao listar.', 'error');
			return;
		}
		_conqAll = res.conquistas || [];
		_conqPage = 1;
		renderLista();
	}, 'json');
}

function abrir(id){
	$.post(url_base + MASTER_CONQ_URL, { acao: 'detalhes', id: id }, function(res){
		if (!res || !res.success) {
			Swal.fire('Erro', (res && res.message) || 'Falha.', 'error');
			return;
		}
		const c = res.conquista;
		$('#conq_id').val(c.id);
		$('#conq_slug').val(c.slug || '');
		$('#conq_titulo').val(c.titulo || '');
		$('#conq_subtitulo').val(c.subtitulo || '');
		$('#conq_descricao').val(c.descricao || '');
		$('#conq_como').val(c.como || '');
		$('#conq_meta_tipo').val(c.meta_tipo || '');
		$('#conq_meta_valor').val(c.meta_valor || 1);
		$('#conq_raridade').val(c.raridade || 'bronze');
		$('#conq_icone').val(c.icone || 'Trophy');
		$('#conq_ordem').val(c.ordem || 0);
		$('#conq_ativo').val(c.ativo ? '1' : '0');
		$('#conq_badge').val('');
		$('#conq_badge_remover').prop('checked', false);
		const $prev = $('#conq_badge_preview').empty();
		if (c.badge_full_url) {
			$prev.append('<img src="'+esc(c.badge_full_url)+'" alt="" style="max-height:64px">');
		}
		$('#titulo-modal-conquista').text('Editar conquista');
		$('#modalConquistaMaster').modal('show');
	}, 'json');
}

function salvar(){
	const fd = new FormData();
	fd.append('acao', 'salvar');
	fd.append('id', $('#conq_id').val() || '');
	fd.append('slug', $('#conq_slug').val() || '');
	fd.append('titulo', $('#conq_titulo').val() || '');
	fd.append('subtitulo', $('#conq_subtitulo').val() || '');
	fd.append('descricao', $('#conq_descricao').val() || '');
	fd.append('como', $('#conq_como').val() || '');
	fd.append('meta_tipo', $('#conq_meta_tipo').val() || '');
	fd.append('meta_valor', $('#conq_meta_valor').val() || '1');
	fd.append('raridade', $('#conq_raridade').val() || 'bronze');
	fd.append('icone', $('#conq_icone').val() || 'Trophy');
	fd.append('ordem', $('#conq_ordem').val() || '0');
	fd.append('ativo', $('#conq_ativo').val() || '0');
	if ($('#conq_badge_remover').is(':checked')) {
		fd.append('remover_badge', '1');
	}
	const f = $('#conq_badge')[0] && $('#conq_badge')[0].files[0];
	if (f) fd.append('badge', f);

	$.ajax({
		url: url_base + MASTER_CONQ_URL,
		method: 'POST',
		data: fd,
		processData: false,
		contentType: false,
		dataType: 'json'
	}).done(function(res){
		if (!res || !res.success) {
			Swal.fire('Erro', (res && res.message) || 'Falha ao salvar.', 'error');
			return;
		}
		$('#modalConquistaMaster').modal('hide');
		Swal.fire('OK', res.message || 'Salvo.', 'success');
		carregar();
	}).fail(function(){
		Swal.fire('Erro', 'Falha de comunicação.', 'error');
	});
}

$(function(){
	popularSelects();
	carregar();
	$('#btn-nova-conquista').on('click', function(){
		limpar();
		$('#modalConquistaMaster').modal('show');
	});
	$('#btn-salvar-conquista').on('click', salvar);
	$('#filtro-conq-busca').on('input', function(){ _conqPage = 1; renderLista(); });
	$('#filtro-conq-raridade, #filtro-conq-ativo').on('change', function(){ _conqPage = 1; renderLista(); });
	$(document).on('click', '.conq-pag-nav', function(e){
		e.preventDefault();
		var p = parseInt($(this).data('page'), 10);
		if (!p || $(this).parent().hasClass('disabled') || $(this).parent().hasClass('active')) return;
		_conqPage = p;
		renderLista();
	});
	$(document).on('click', '.btn-editar-conq', function(){
		abrir($(this).data('id'));
	});
	$(document).on('click', '.btn-excluir-conq', function(){
		const id = $(this).data('id');
		Swal.fire({
			title: 'Excluir conquista?',
			text: 'Alunos podem perder a referência desta medalha.',
			icon: 'warning',
			showCancelButton: true,
			confirmButtonText: 'Excluir',
			cancelButtonText: 'Cancelar'
		}).then(function(r){
			if (!r.isConfirmed) return;
			$.post(url_base + MASTER_CONQ_URL, { acao: 'excluir', id: id }, function(res){
				if (!res || !res.success) {
					Swal.fire('Erro', (res && res.message) || 'Falha.', 'error');
					return;
				}
				carregar();
			}, 'json');
		});
	});
});
