const MASTER_PLANOS_URL = 'master/planos';

/** @type {number|null} */
let editingPlanoId = null;

const $modalPlano = () => $('#modalPlanoMaster');

function esc(s){
	return $('<div>').text(s == null ? '' : String(s)).html();
}

function renderChecks(selecionados, todos){
	const mods = window.MASTER_MODULOS || [];
	const sel = {};
	(selecionados || []).forEach(function(s){ sel[s] = true; });
	const $box = $('#lista-modulos-plano').empty();
	mods.forEach(function(m){
		const id = 'pmod-'+m.slug;
		const checked = todos || !!sel[m.slug];
		$box.append(
			'<div class="col-md-4 col-sm-6"><div class="form-check">'
			+'<input class="form-check-input chk-mod-plano" type="checkbox" id="'+id+'" value="'+esc(m.slug)+'" '+(checked?'checked':'')+'>'
			+'<label class="form-check-label" for="'+id+'">'+esc(m.label)+'</label>'
			+'</div></div>'
		);
	});
	aplicarTodos();
}

function aplicarTodos(){
	const todos = $modalPlano().find('#plano_assinatura_todos_modulos').is(':checked');
	$('.chk-mod-plano').prop('disabled', todos);
	if(todos) $('.chk-mod-plano').prop('checked', true);
}

function coletarSlugs(){
	const slugs = [];
	$('.chk-mod-plano:checked').each(function(){ slugs.push($(this).val()); });
	return slugs;
}

function limpar(){
	editingPlanoId = null;
	const $m = $modalPlano();
	$m.find('#plano_assinatura_id').val('');
	$m.find('#plano_assinatura_nome, #plano_assinatura_descricao, #plano_assinatura_descricao_detalhada, #plano_assinatura_valor_mensal').val('');
	popularProdutos('');
	$m.find('#plano_ciclo').val('mensal');
	$('#bloco-modulos-plano').toggle(false);
	$m.find('#plano_assinatura_ordem').val('0');
	$m.find('#plano_assinatura_ativo').val('1');
	$m.find('#plano_assinatura_todos_modulos').prop('checked', false);
	$('#titulo-modal-plano').text('Novo plano');
	$('#badge-editando-plano').addClass('d-none').text('');
	renderChecks([], false);
}

function popularProdutos(sel){
	const $s = $('#plano_produto').empty();
	(window.MASTER_PRODUTOS || []).forEach(function(p){
		$s.append('<option value="'+esc(p.slug)+'" data-modo="'+esc(p.modo)+'">'+esc(p.label)+'</option>');
	});
	if(sel) $s.val(sel);
}

function produtoModular(){
	return $('#plano_produto option:selected').data('modo') === 'modular';
}

function renderLista(planos){
	const $tb = $('#lista-planos-master').empty();
	if(!planos || !planos.length){
		$tb.append('<tr><td colspan="6" class="text-center text-muted py-4">Nenhum plano ainda.</td></tr>');
		return;
	}
	planos.forEach(function(p){
		const badge = p.ativo ? '<span class="badge bg-success">Ativo</span>' : '<span class="badge bg-secondary">Inativo</span>';
		const mods = p.modular ? ((p.modulos_qtd||0)+' módulos') : 'sem módulos extras';
		const valor = (p.valor_br != null) ? ('R$ '+p.valor_br) : '—';
		const det = (p.descricao_detalhada || '').trim();
		const detBadge = det
			? ' <span class="badge bg-info text-dark" title="'+esc(det.substring(0, 200))+'">contrato</span>'
			: '';
		$tb.append(
			'<tr>'
			+'<td>'+esc(p.ordem)+'</td>'
			+'<td><strong>'+esc(p.produto_label || '')+'</strong><br>'+esc(p.nome)+detBadge+'<br><small class="text-muted">'+esc(p.descricao||'')+'</small></td>'
			+'<td>'+esc(p.ciclo || 'mensal')+'</td>'
			+'<td>'+esc(valor)+'<br><small class="text-muted">'+esc(mods)+'</small></td>'
			+'<td>'+badge+'</td>'
			+'<td class="text-end">'
			+'<button type="button" class="btn btn-sm btn-outline-primary me-1 btn-editar-plano" data-id="'+p.id+'"><i class="fas fa-edit"></i></button>'
			+'<button type="button" class="btn btn-sm btn-outline-danger btn-excluir-plano" data-id="'+p.id+'"><i class="fas fa-trash"></i></button>'
			+'</td></tr>'
		);
	});
}

function carregar(){
	$.post(url_base + MASTER_PLANOS_URL, { acao: 'listar' }, function(res){
		if(!res || !res.success){
			Swal.fire('Erro', (res && res.message) || 'Falha.', 'error');
			return;
		}
		renderLista(res.planos || []);
	}, 'json');
}

function abrir(id){
	const planoId = parseInt(id, 10);
	if (!planoId) return;

	limpar();
	editingPlanoId = planoId;

	$.post(url_base + MASTER_PLANOS_URL, { acao: 'detalhes', id: planoId }, function(res){
		if(!res || !res.success){
			Swal.fire('Erro', (res && res.message) || 'Falha.', 'error');
			return;
		}
		const p = res.plano;
		const $m = $modalPlano();
		editingPlanoId = parseInt(p.id, 10) || planoId;
		$m.find('#plano_assinatura_id').val(editingPlanoId);
		popularProdutos(p.produto_slug || '');
		$m.find('#plano_ciclo').val(p.ciclo === 'anual' ? 'anual' : 'mensal');
		$m.find('#plano_assinatura_nome').val(p.nome || '');
		$m.find('#plano_assinatura_descricao').val(p.descricao || '');
		$m.find('#plano_assinatura_descricao_detalhada').val(p.descricao_detalhada || '');
		$m.find('#plano_assinatura_valor_mensal').val(p.valor_br || '0,00');
		$m.find('#plano_assinatura_ordem').val(p.ordem || 0);
		$m.find('#plano_assinatura_ativo').val(p.ativo ? '1' : '0');
		$m.find('#plano_assinatura_todos_modulos').prop('checked', !!p.todos_modulos);
		$('#titulo-modal-plano').text('Editar plano');
		$('#badge-editando-plano').removeClass('d-none').text('#' + editingPlanoId);
		$('#bloco-modulos-plano').toggle(!!p.modular);
		renderChecks(p.modulos || [], !!p.todos_modulos);
		$m.modal('show');
	}, 'json');
}

function salvar(){
	const $m = $modalPlano();
	const idFromField = parseInt($m.find('#plano_assinatura_id').val(), 10) || 0;
	const id = editingPlanoId || idFromField;
	const isEdit = editingPlanoId !== null && editingPlanoId > 0;

	if (isEdit && id <= 0) {
		Swal.fire('Erro', 'ID do plano inválido. Feche o modal e tente editar novamente.', 'error');
		return;
	}

	const dados = {
		acao: 'salvar',
		modo: isEdit ? 'editar' : 'criar',
		id: id,
		nome: $m.find('#plano_assinatura_nome').val(),
		descricao: $m.find('#plano_assinatura_descricao').val(),
		descricao_detalhada: $m.find('#plano_assinatura_descricao_detalhada').val(),
		produto_slug: $m.find('#plano_produto').val(),
		ciclo: $m.find('#plano_ciclo').val(),
		valor_sugerido: $m.find('#plano_assinatura_valor_mensal').val(),
		valor_mensal: $m.find('#plano_assinatura_valor_mensal').val(),
		ordem: $m.find('#plano_assinatura_ordem').val(),
		ativo: $m.find('#plano_assinatura_ativo').val(),
		todos_modulos: $m.find('#plano_assinatura_todos_modulos').is(':checked') ? 1 : 0,
		modulos_json: JSON.stringify(coletarSlugs())
	};
	if(!String(dados.nome||'').trim()){
		Swal.fire('Atenção', 'Informe o nome do plano.', 'warning');
		return;
	}
	$.post(url_base + MASTER_PLANOS_URL, dados, function(res){
		if(!res || !res.success){
			Swal.fire('Erro', (res && res.message) || 'Falha.', 'error');
			return;
		}
		$m.modal('hide');
		Swal.fire('OK', res.message, 'success');
		carregar();
	}, 'json');
}

$(function(){
	popularProdutos('');
	renderChecks([], false);
	$('#plano_produto').on('change', function(){
		$('#bloco-modulos-plano').toggle(produtoModular());
	});
	carregar();
	$('#btn-novo-plano-assinatura').on('click', function(){
		limpar();
		$modalPlano().modal('show');
	});
	$('#btn-salvar-plano-assinatura').on('click', salvar);
	$modalPlano().find('#plano_assinatura_todos_modulos').on('change', aplicarTodos);
	$(document).on('click', '.btn-editar-plano', function(){ abrir($(this).data('id')); });
	$(document).on('click', '.btn-excluir-plano', function(){
		const id = $(this).data('id');
		Swal.fire({ title: 'Excluir plano?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Excluir' })
			.then(function(r){
				if(!r.isConfirmed) return;
				$.post(url_base + MASTER_PLANOS_URL, { acao: 'excluir', id: id }, function(res){
					if(!res || !res.success){
						Swal.fire('Erro', (res && res.message) || 'Falha.', 'error');
						return;
					}
					carregar();
				}, 'json');
			});
	});
	$modalPlano().on('hidden.bs.modal', limpar);
});
