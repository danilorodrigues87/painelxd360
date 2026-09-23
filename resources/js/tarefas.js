let tarefasArrastando = false;
let tarefasSortables  = [];
let tarefasDadosCache = { listas: [] };
let salvarDescricaoTimer = null;

function montarBadgeChecklist(cartao){
	if(!cartao.checklist_total || cartao.checklist_total === 0){
		return '';
	}
	return `<span class="trello-card-badge"><i class="fas fa-check"></i> ${cartao.checklist_concluidos}/${cartao.checklist_total}</span>`;
}

function montarCardTarefa(cartao){
	const resumo = cartao.descricao_resumo
		? `<div class="trello-card-meta">${cartao.descricao_resumo}</div>`
		: '';

	return `
	<div class="trello-card" data-id="${cartao.id}">
		<div class="trello-card-titulo">${cartao.titulo}</div>
		${resumo}
		${montarBadgeChecklist(cartao)}
	</div>`;
}

function montarColuna(lista){
	const cartoesHtml = (lista.cartoes || []).map(montarCardTarefa).join('');

	return `
	<div class="trello-coluna" data-lista-id="${lista.id}">
		<div class="trello-coluna-header">
			<input type="text" class="trello-coluna-titulo" value="${lista.titulo}" data-lista-id="${lista.id}">
			<button type="button" class="btn btn-link btn-sm text-danger p-0 ms-1 btn-excluir-lista" data-lista-id="${lista.id}" title="Excluir lista">
				<i class="fas fa-times"></i>
			</button>
		</div>
		<div class="trello-list-cards" data-lista-id="${lista.id}">
			${cartoesHtml}
		</div>
		<button type="button" class="trello-btn-add-card btn-add-cartao" data-lista-id="${lista.id}">
			<i class="fas fa-plus"></i> Adicionar um cartão
		</button>
	</div>`;
}

function renderizarQuadro(listas){
	const $board = $('#trello-board');
	$board.empty();

	(listas || []).forEach(function(lista){
		$board.append(montarColuna(lista));
	});

	inicializarSortableTarefas();
}

function coletarPosicoes(){
	const posicoes = [];

	document.querySelectorAll('.trello-list-cards').forEach(function(listEl){
		const listaId = listEl.getAttribute('data-lista-id');
		listEl.querySelectorAll('.trello-card').forEach(function(card, index){
			posicoes.push({
				id: card.getAttribute('data-id'),
				lista_id: listaId,
				posicao: index
			});
		});
	});

	return posicoes;
}

function salvarPosicoes(){
	const posicoes = coletarPosicoes();

	$.ajax({
		url: url_base + posicaoCartao,
		method: 'post',
		data: { posicoes },
		dataType: 'json'
	});
}

function inicializarSortableTarefas(){
	tarefasSortables.forEach(function(instance){
		if(instance && typeof instance.destroy === 'function'){
			instance.destroy();
		}
	});
	tarefasSortables = [];

	document.querySelectorAll('.trello-list-cards').forEach(function(el){
		const sortable = new Sortable(el, {
			group: 'trello-tarefas',
			animation: 150,
			ghostClass: 'trello-ghost',
			delay: 120,
			delayOnTouchOnly: true,
			onStart: function(){
				tarefasArrastando = true;
			},
			onEnd: function(){
				setTimeout(function(){ tarefasArrastando = false; }, 150);
				salvarPosicoes();
			}
		});
		tarefasSortables.push(sortable);
	});
}

function carregarTarefas(){
	$.ajax({
		url: url_base + listagemTarefas,
		method: 'post',
		dataType: 'json',
		success: function(result){
			if(result.listas){
				tarefasDadosCache.listas = result.listas;
				renderizarQuadro(result.listas);
			}
		}
	});
}

function atualizarProgressoChecklist(percentual){
	const pct = percentual || 0;
	$('#checklist-progresso-texto').text(pct + '%');
	$('#checklist-progresso-barra').css('width', pct + '%');
}

function montarItensChecklist(itens){
	if(!itens || itens.length === 0){
		return '';
	}

	return itens.map(function(item){
		const checked   = item.concluido ? 'checked' : '';
		const classe    = item.concluido ? 'concluido' : '';
		return `
		<div class="checklist-item ${classe}" data-item-id="${item.id}">
			<input type="checkbox" class="form-check-input checklist-checkbox" data-item-id="${item.id}" ${checked}>
			<label for="">${item.item_texto}</label>
			<button type="button" class="btn-excluir-item" data-item-id="${item.id}" title="Remover item">
				<i class="fas fa-times"></i>
			</button>
		</div>`;
	}).join('');
}

function preencherModalDetalhes(result){
	const cartao = result.cartao;

	$('#detalhe-cartao-id').val(cartao.id);
	$('#detalhe-cartao-titulo').val(cartao.titulo);
	$('#detalhe-cartao-descricao').val(cartao.descricao);
	$('#detalhe-cartao-data').text('Criado em ' + cartao.data_cadastro);
	$('#checklist-cartao-id').val(cartao.id);
	$('#comentario-cartao-id').val(cartao.id);
	$('#checklist-itens').html(montarItensChecklist(result.checklist));
	$('#detalhe-comentarios').html(result.comentarios);
	$('#comentario-texto').val('');
	$('#response-detalhe-cartao').html('');
	atualizarProgressoChecklist(result.progresso);
}

function abrirDetalhesCartao(id){
	$.ajax({
		url: url_base + detalhesCartao,
		method: 'post',
		data: { id },
		dataType: 'json',
		success: function(result){
			if(result.erro){
				Swal.fire({ title: 'Ops...', text: result.erro, icon: 'error' });
				return;
			}
			preencherModalDetalhes(result);
			$('#modalDetalhesCartao').modal('show');
		}
	});
}

function salvarTituloCartao(){
	const id     = $('#detalhe-cartao-id').val();
	const titulo = $('#detalhe-cartao-titulo').val().trim();

	if(!id || titulo === '') return;

	$.ajax({
		url: url_base + atualizarCartao,
		method: 'post',
		data: { id, titulo },
		dataType: 'json',
		success: function(){
			carregarTarefas();
		}
	});
}

function salvarDescricaoCartao(){
	const id        = $('#detalhe-cartao-id').val();
	const descricao = $('#detalhe-cartao-descricao').val();

	if(!id) return;

	$.ajax({
		url: url_base + atualizarCartao,
		method: 'post',
		data: { id, descricao },
		dataType: 'json'
	});
}

$(document).on('click', '#btn-nova-lista', function(){
	$('#form-nova-lista')[0].reset();
	$('#response-nova-lista').html('');
	$('#modalNovaLista').modal('show');
});

$(document).on('submit', '#form-nova-lista', function(event){
	event.preventDefault();

	$.ajax({
		url: url_base + salvarLista,
		method: 'post',
		data: $(this).serialize(),
		dataType: 'json',
		success: function(result){
			if(result.erro){
				$('#response-nova-lista').html('<div class="alert alert-danger py-1">'+result.erro+'</div>');
			} else {
				$('#modalNovaLista').modal('hide');
				carregarTarefas();
			}
		}
	});
});

$(document).on('click', '.btn-add-cartao', function(){
	const listaId = $(this).data('lista-id');
	$('#novo-cartao-lista-id').val(listaId);
	$('#form-novo-cartao')[0].reset();
	$('#novo-cartao-lista-id').val(listaId);
	$('#response-novo-cartao').html('');
	$('#modalNovoCartao').modal('show');
});

$(document).on('submit', '#form-novo-cartao', function(event){
	event.preventDefault();

	$.ajax({
		url: url_base + salvarCartao,
		method: 'post',
		data: $(this).serialize(),
		dataType: 'json',
		success: function(result){
			if(result.erro){
				$('#response-novo-cartao').html('<div class="alert alert-danger py-1">'+result.erro+'</div>');
			} else {
				$('#modalNovoCartao').modal('hide');
				carregarTarefas();
			}
		}
	});
});

$(document).on('blur', '.trello-coluna-titulo', function(){
	const id     = $(this).data('lista-id');
	const titulo = $(this).val().trim();
	const valorOriginal = $(this).data('valor-original') || $(this).val();

	if(titulo === '' || titulo === valorOriginal){
		$(this).val(valorOriginal);
		return;
	}

	$.ajax({
		url: url_base + atualizarLista,
		method: 'post',
		data: { id, titulo },
		dataType: 'json',
		success: function(result){
			if(result.erro){
				Swal.fire({ title: 'Ops...', text: result.erro, icon: 'error' });
				carregarTarefas();
			}
		}
	});
});

$(document).on('focus', '.trello-coluna-titulo', function(){
	$(this).data('valor-original', $(this).val());
});

$(document).on('click', '.btn-excluir-lista', function(event){
	event.stopPropagation();
	const id = $(this).data('lista-id');

	Swal.fire({
		title: 'Excluir lista?',
		text: 'Todos os cartões desta coluna serão removidos.',
		icon: 'warning',
		showCancelButton: true,
		confirmButtonText: 'Sim, excluir',
		cancelButtonText: 'Cancelar'
	}).then(function(confirmacao){
		if(!confirmacao.isConfirmed) return;

		$.ajax({
			url: url_base + excluirLista,
			method: 'post',
			data: { id },
			dataType: 'json',
			success: function(result){
				if(result.erro){
					Swal.fire({ title: 'Ops...', text: result.erro, icon: 'error' });
				} else {
					carregarTarefas();
				}
			}
		});
	});
});

$(document).on('click', '.trello-card', function(){
	if(tarefasArrastando) return;
	abrirDetalhesCartao($(this).data('id'));
});

$(document).on('blur', '#detalhe-cartao-titulo', function(){
	salvarTituloCartao();
});

$(document).on('blur', '#detalhe-cartao-descricao', function(){
	salvarDescricaoCartao();
});

$(document).on('submit', '#form-checklist-item', function(event){
	event.preventDefault();

	const cartaoId = $('#checklist-cartao-id').val();
	const itemTexto = $('#checklist-novo-item').val().trim();

	if(itemTexto === '') return;

	$.ajax({
		url: url_base + salvarChecklist,
		method: 'post',
		data: { cartao_id: cartaoId, item_texto: itemTexto },
		dataType: 'json',
		success: function(result){
			if(result.erro){
				$('#response-detalhe-cartao').html('<div class="alert alert-danger py-1">'+result.erro+'</div>');
			} else {
				$('#checklist-novo-item').val('');
				const htmlAtual = $('#checklist-itens').html();
				const novoItem = `
				<div class="checklist-item" data-item-id="${result.item.id}">
					<input type="checkbox" class="form-check-input checklist-checkbox" data-item-id="${result.item.id}">
					<label for="">${result.item.item_texto}</label>
					<button type="button" class="btn-excluir-item" data-item-id="${result.item.id}" title="Remover item">
						<i class="fas fa-times"></i>
					</button>
				</div>`;
				$('#checklist-itens').html(htmlAtual + novoItem);
				atualizarProgressoChecklist(result.progresso);
				carregarTarefas();
			}
		}
	});
});

$(document).on('change', '.checklist-checkbox', function(){
	const id        = $(this).data('item-id');
	const concluido = $(this).is(':checked') ? 1 : 0;
	const $item     = $(this).closest('.checklist-item');

	$.ajax({
		url: url_base + toggleChecklist,
		method: 'post',
		data: { id, concluido },
		dataType: 'json',
		success: function(result){
			if(result.erro){
				Swal.fire({ title: 'Ops...', text: result.erro, icon: 'error' });
			} else {
				$item.toggleClass('concluido', concluido === 1);
				atualizarProgressoChecklist(result.progresso);
				carregarTarefas();
			}
		}
	});
});

$(document).on('click', '.btn-excluir-item', function(event){
	event.preventDefault();
	const id = $(this).data('item-id');

	$.ajax({
		url: url_base + excluirChecklist,
		method: 'post',
		data: { id },
		dataType: 'json',
		success: function(result){
			if(result.erro){
				Swal.fire({ title: 'Ops...', text: result.erro, icon: 'error' });
			} else {
				$('.checklist-item[data-item-id="'+id+'"]').remove();
				atualizarProgressoChecklist(result.progresso);
				carregarTarefas();
			}
		}
	});
});

$(document).on('submit', '#form-comentario-tarefa', function(event){
	event.preventDefault();

	$.ajax({
		url: url_base + comentarioTarefa,
		method: 'post',
		data: $(this).serialize(),
		dataType: 'json',
		success: function(result){
			if(result.erro){
				$('#response-detalhe-cartao').html('<div class="alert alert-danger py-1">'+result.erro+'</div>');
			} else {
				$('#detalhe-comentarios').html(result.comentarios);
				$('#comentario-texto').val('');
				carregarTarefas();
			}
		}
	});
});

$(document).on('click', '#btn-excluir-cartao', function(){
	const id = $('#detalhe-cartao-id').val();

	Swal.fire({
		title: 'Excluir cartão?',
		text: 'Esta ação não pode ser desfeita.',
		icon: 'warning',
		showCancelButton: true,
		confirmButtonText: 'Sim, excluir',
		cancelButtonText: 'Cancelar'
	}).then(function(confirmacao){
		if(!confirmacao.isConfirmed) return;

		$.ajax({
			url: url_base + excluirCartao,
			method: 'post',
			data: { id },
			dataType: 'json',
			success: function(result){
				if(result.erro){
					Swal.fire({ title: 'Ops...', text: result.erro, icon: 'error' });
				} else {
					$('#modalDetalhesCartao').modal('hide');
					carregarTarefas();
				}
			}
		});
	});
});

$(document).ready(function(){
	if(typeof listagemTarefas !== 'undefined'){
		carregarTarefas();
	}
});
