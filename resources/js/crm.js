const STATUS_COLUNAS = ['novo','em_atendimento','matriculado','perdido'];

let crmArrastando = false;
let crmPaginaAtual = 1;
let crmFiltroTimer = null;
let crmFunilAtivo  = null;
let crmFunisCache  = [];

const CRM_FUNIL_STORAGE = 'crm_funil_ativo';
let crmFunisRequest = null;

function montarCardLead(lead){
	const classeEsquecido = lead.esquecido ? ' lead-esquecido' : '';
	const badgeEsquecido  = lead.esquecido ? '<span class="badge badge-esquecido ms-1">Sem contato +48h</span>' : '';
	const valorHtml       = lead.valor_estimado_br ? '<div class="lead-valor">R$ '+lead.valor_estimado_br+'</div>' : '';

	return `
	<div class="kanban-card${classeEsquecido}" data-id="${lead.id}" data-nome="${lead.nome.toLowerCase()}" data-curso="${(lead.curso_interesse || '').toLowerCase()}">
		<div class="lead-nome">${lead.nome}${badgeEsquecido}</div>
		<div class="lead-meta">
			${lead.curso_interesse ? lead.curso_interesse + '<br>' : ''}
			<small>${lead.data_cadastro}</small>
		</div>
	</div>`;
}

function atualizarContadores(contagens){
	STATUS_COLUNAS.forEach(function(status){
		const qtd = (contagens && contagens[status] !== undefined)
			? contagens[status]
			: 0;
		$('#count-'+status).text(qtd);
	});
}

function atualizarTotais(totais){
	STATUS_COLUNAS.forEach(function(status){
		const valor = totais[status] || '0,00';
		$('#total-'+status).text('R$ '+valor);
	});
}

function popularSelectFunis($select, funis, valorSelecionado, placeholder){
	const valorAtual = valorSelecionado || $select.val();
	$select.empty();

	if(placeholder){
		$select.append('<option value="">'+placeholder+'</option>');
	}

	(funis || []).forEach(function(funil){
		$select.append('<option value="'+funil.id+'">'+funil.nome+'</option>');
	});

	if(valorAtual){
		$select.val(String(valorAtual));
	}
}

function atualizarTodosSelectsFunis(funis, funilAtivo){
	crmFunisCache = funis || [];
	let funilId = funilAtivo || crmFunilAtivo;

	if(funilId && !crmFunisCache.some(function(f){ return String(f.id) === String(funilId); })){
		funilId = crmFunisCache[0] ? String(crmFunisCache[0].id) : null;
		sessionStorage.removeItem(CRM_FUNIL_STORAGE);
	}

	popularSelectFunis($('#filtro-funil'), crmFunisCache, funilId, null);
	popularSelectFunis($('#novo-lead-funil'), crmFunisCache, funilId, 'Selecione...');
	popularSelectFunis($('#importar-funil'), crmFunisCache, funilId, 'Selecione...');
	popularSelectFunis($('#editar-funil'), crmFunisCache, $('#editar-funil').val(), 'Selecione...');

	if(funilId){
		crmFunilAtivo = String(funilId);
		sessionStorage.setItem(CRM_FUNIL_STORAGE, crmFunilAtivo);
	}
}

function aplicarErroFunilFiltro(){
	if(crmFunisCache.length > 0){
		return;
	}
	$('#filtro-funil').html('<option value="">Erro ao carregar</option>');
}

function renderizarListaFunis(funis){
	if(!funis || funis.length === 0){
		$('#lista-funis').html('<p class="text-muted small mb-0">Nenhum funil cadastrado.</p>');
		return;
	}

	let html = '<div class="list-group list-group-flush">';

	funis.forEach(function(funil){
		const qtd = funil.qtd_leads || 0;
		const podeExcluir = qtd === 0;
		const btnExcluir = podeExcluir
			? '<button type="button" class="btn btn-outline-danger btn-sm btn-excluir-funil" data-id="'+funil.id+'" data-nome="'+funil.nome+'"><i class="fas fa-trash"></i></button>'
			: '<span class="badge bg-light text-muted">'+qtd+' lead(s)</span>';

		html += `
		<div class="list-group-item d-flex justify-content-between align-items-center px-0">
			<span>${funil.nome}</span>
			${btnExcluir}
		</div>`;
	});

	html += '</div>';
	$('#lista-funis').html(html);
}

function carregarFunis(callback, tentativa){
	tentativa = tentativa || 1;

	if(crmFunisRequest){
		crmFunisRequest.abort();
	}

	const usarRotaDedicada = typeof listarFunis !== 'undefined';
	const url = url_base + (usarRotaDedicada ? listarFunis : listagemCrm);
	const dados = usarRotaDedicada ? {} : { acao: 'listar_funis' };

	crmFunisRequest = $.ajax({
		url: url,
		method: 'post',
		data: dados,
		dataType: 'json',
		success: function(result){
			crmFunisRequest = null;

			if(result.erro){
				console.error('Erro funis:', result.erro);
				$('#response-funis').html('<div class="alert alert-danger py-1">'+result.erro+'</div>');
				aplicarErroFunilFiltro();
				if(typeof callback === 'function') callback([]);
				return;
			}

			const funis = result.funis || [];
			const salvo = sessionStorage.getItem(CRM_FUNIL_STORAGE);
			const funilAtivo = salvo || obterFunilAtivo() || (funis[0] ? String(funis[0].id) : null);

			atualizarTodosSelectsFunis(funis, funilAtivo);
			renderizarListaFunis(funis);

			if(typeof callback === 'function'){
				callback(funis);
			}
		},
		error: function(xhr, status){
			crmFunisRequest = null;

			if(status === 'abort'){
				return;
			}

			console.error('Erro ao carregar funis (tentativa '+tentativa+'):', url, xhr.status, xhr.responseText);

			if(tentativa < 3){
				setTimeout(function(){
					carregarFunis(callback, tentativa + 1);
				}, 600 * tentativa);
				return;
			}

			aplicarErroFunilFiltro();
			if(typeof callback === 'function'){
				callback([]);
			}
		}
	});
}

function obterFunilAtivo(){
	return $('#filtro-funil').val() || crmFunilAtivo || '';
}

function preencherFunilModais(){
	const funilAtivo = obterFunilAtivo();
	$('#novo-lead-funil').val(funilAtivo);
	$('#importar-funil').val(funilAtivo);
}

function popularFiltroCursos(cursos){
	const $select = $('#filtro-curso');
	const valorAtual = $select.val();

	$select.find('option:not(:first)').remove();

	(cursos || []).forEach(function(curso){
		$select.append('<option value="'+curso.toLowerCase()+'">'+curso+'</option>');
	});

	if(valorAtual){
		$select.val(valorAtual);
	}
}

function renderizarKanban(colunas, totais, contagensGlobais){
	STATUS_COLUNAS.forEach(function(status){
		const $coluna = $('#coluna-'+status);
		$coluna.empty();

		(colunas[status] || []).forEach(function(lead){
			$coluna.append(montarCardLead(lead));
		});
	});

	atualizarContadores(contagensGlobais || {});
	if(totais){
		atualizarTotais(totais);
	}
}

function irPagina(page){
	carregarLeads(page || 1);
}

function listar(filtro=null, page=1){
	carregarLeads(page);
}

function carregarLeads(page){
	if(page === undefined || page === null){
		page = crmPaginaAtual;
	}

	crmPaginaAtual = page;

	$.ajax({
		url: url_base + listagemCrm,
		method: 'post',
		data: {
			page: page,
			filtro_funil: obterFunilAtivo(),
			filtro_nome: ($('#filtro-busca-lead').val() || '').trim(),
			filtro_curso: ($('#filtro-curso').val() || '').trim()
		},
		dataType: 'json',
		success: function(result){
			if(Array.isArray(result.funis)){
				atualizarTodosSelectsFunis(result.funis, result.funil_ativo);
			}

			if(result.colunas){
				popularFiltroCursos(result.cursos || []);
				renderizarKanban(result.colunas, result.totais || {}, result.contagens_globais || {});
			}

			if(result.pagination !== undefined){
				$('#pagination').html(result.pagination);
			}
		},
		error: function(xhr){
			console.error('Erro ao carregar leads:', xhr.status, xhr.responseText);
			aplicarErroFunilFiltro();
			Swal.fire({
				title: 'Erro ao carregar leads',
				text: 'Não foi possível carregar o kanban. Recarregue a página.',
				icon: 'error'
			});
		}
	});
}

function atualizarStatusLead(id, status, motivoPerda, callback){
	const dados = { id, status };

	if(motivoPerda){
		dados.motivo_perda = motivoPerda;
	}

	$.ajax({
		url: url_base + statusLead,
		method: 'post',
		data: dados,
		dataType: 'json',
		success: function(result){
			if(result.erro){
				Swal.fire({ title: 'Ops...', text: result.erro, icon: 'error' });
				carregarLeads();
			} else {
				carregarLeads();
				if(typeof callback === 'function'){
					callback();
				}
			}
		},
		error: function(){
			Swal.fire({ title: 'Erro', text: 'Não foi possível atualizar o status.', icon: 'error' });
			carregarLeads();
		}
	});
}

function abrirModalMotivoPerda(id){
	$('#perda-lead-id').val(id);
	$('#perda-motivo').val('');
	$('#response-motivo-perda').html('');
	$('.btn-motivo').removeClass('active');
	$('#btn-confirmar-perda').prop('disabled', true);
	$('#modalMotivoPerda').modal('show');
}

function preencherModalDetalhes(result){
	const lead = result.lead;

	$('#detalhe-lead-nome').text(lead.nome);
	$('#detalhe-lead-id').val(lead.id);
	$('#editar-lead-id').val(lead.id);
	$('#detalhe-status-badge').text(lead.status_label);
	$('#detalhe-data-resumo').text('Cadastrado em '+lead.data_cadastro);

	$('#editar-nome').val(lead.nome);
	$('#editar-whatsapp').val(lead.whatsapp);
	$('#editar-email').val(lead.email);
	$('#editar-idade').val(lead.idade);
	$('#editar-responsavel').val(lead.responsavel_nome);
	$('#editar-curso').val(lead.curso_interesse);
	$('#editar-valor').val(lead.valor_estimado);
	$('#editar-origem').val(lead.origem);
	$('#editar-bairro').val(lead.bairro);
	$('#editar-cidade').val(lead.cidade);
	$('#editar-motivo-perda').val(lead.motivo_perda !== '-' ? lead.motivo_perda : '');

	if(result.funis){
		popularSelectFunis($('#editar-funil'), result.funis, lead.funil_id, 'Selecione...');
	} else if(lead.funil_id){
		$('#editar-funil').val(String(lead.funil_id));
	}

	$('#detalhe-timeline').html(result.timeline);
	$('#detalhe-observacao').val('');
	$('#response-detalhe-lead').html('');

	$('#btn-whatsapp-lead').off('click').on('click', function(){
		iniciarAtendimentoWa(lead.whatsapp, lead.nome);
	});

	const $btnConv = $('#btn-converter-aluno');
	const $btnMat = $('#btn-ir-matricula');
	$btnConv.addClass('d-none').off('click');
	$btnMat.addClass('d-none').off('click');

	if (lead.pode_matricular !== false && lead.status !== 'matriculado') {
		if (lead.tem_aluno && lead.id_usuario > 0) {
			$btnMat.removeClass('d-none').on('click', function(){
				window.location.href = url_base + 'painel/matriculas?aluno=' + lead.id_usuario + '&lead=' + lead.id;
			});
		} else {
			$btnConv.removeClass('d-none').on('click', function(){
				converterLeadEmAluno(lead.id);
			});
		}
	}
}

function converterLeadEmAluno(id){
	Swal.fire({
		title: 'Converter em aluno?',
		text: 'Será criado ou vinculado um cadastro comercial (Cliente) a partir deste lead.',
		icon: 'question',
		showCancelButton: true,
		confirmButtonText: 'Sim, converter',
		cancelButtonText: 'Cancelar'
	}).then(function(confirmacao){
		if(!confirmacao.isConfirmed) return;

		$.ajax({
			url: url_base + converterAlunoLead,
			method: 'post',
			data: { id },
			dataType: 'json',
			success: function(result){
				if(result.erro){
					Swal.fire({ title: 'Ops...', text: result.erro, icon: 'error' });
					return;
				}
				Swal.fire({
					title: 'Pronto!',
					text: result.message || 'Aluno disponível para matrícula.',
					icon: 'success',
					showCancelButton: true,
					confirmButtonText: 'Ir para matrícula',
					cancelButtonText: 'Fechar'
				}).then(function(r){
					if(r.isConfirmed && result.url_matricula){
						window.location.href = result.url_matricula;
					} else {
						abrirDetalhesLead(id);
					}
				});
			},
			error: function(){
				Swal.fire({ title: 'Erro', text: 'Falha ao converter o lead.', icon: 'error' });
			}
		});
	});
}

function abrirDetalhesLead(id){
	$.ajax({
		url: url_base + detalhesLead,
		method: 'post',
		data: { id },
		dataType: 'json',
		success: function(result){
			if(result.erro){
				Swal.fire({ title: 'Ops...', text: result.erro, icon: 'error' });
				return;
			}
			preencherModalDetalhes(result);
			$('#modalDetalhesLead').modal('show');
		},
		error: function(xhr){
			console.error('detalhes lead:', xhr.status, xhr.responseText);
			Swal.fire({ title: 'Erro', text: 'Não foi possível abrir os detalhes do lead.', icon: 'error' });
		}
	});
}

function inicializarSortable(){
	STATUS_COLUNAS.forEach(function(status){
		const el = document.getElementById('coluna-'+status);
		if(!el) return;

		new Sortable(el, {
			group: 'crm-kanban',
			animation: 150,
			ghostClass: 'kanban-ghost',
			delay: 120,
			delayOnTouchOnly: true,
			onStart: function(){
				crmArrastando = true;
			},
			onEnd: function(){
				setTimeout(function(){ crmArrastando = false; }, 150);
			},
			onAdd: function(evt){
				const id         = evt.item.getAttribute('data-id');
				const novoStatus = evt.to.getAttribute('data-status');
				const colunaOrigem = evt.from;

				if(novoStatus === 'perdido'){
					colunaOrigem.appendChild(evt.item);
					abrirModalMotivoPerda(id);
					return;
				}

				atualizarStatusLead(id, novoStatus);
			}
		});
	});
}

$(document).on('click', '.kanban-card', function(){
	if(crmArrastando) return;
	abrirDetalhesLead($(this).data('id'));
});

$(document).on('input', '#filtro-busca-lead', function(){
	clearTimeout(crmFiltroTimer);
	crmFiltroTimer = setTimeout(function(){
		carregarLeads(1);
	}, 400);
});

$(document).on('change', '#filtro-funil', function(){
	crmFunilAtivo = $(this).val();
	sessionStorage.setItem(CRM_FUNIL_STORAGE, crmFunilAtivo);
	carregarLeads(1);
});

$(document).on('change', '#filtro-curso', function(){
	carregarLeads(1);
});

$(document).on('click', '#btn-limpar-filtros', function(){
	$('#filtro-busca-lead').val('').removeAttr('readonly');
	$('#filtro-curso').val('');
	carregarLeads(1);
});

$('#modalNovoLead').on('show.bs.modal', function(){
	preencherFunilModais();
});

$('#modalImportarLeads').on('show.bs.modal', function(){
	preencherFunilModais();
});

$('#modalGerenciarFunis').on('show.bs.modal', function(){
	carregarFunis();
});

$(document).on('submit', '#form-novo-funil', function(event){
	event.preventDefault();

	const nome = ($('#input-novo-funil').val() || '').trim();

	$.ajax({
		url: url_base + listagemCrm,
		method: 'post',
		data: { acao: 'salvar_funil', nome },
		dataType: 'json',
		success: function(result){
			if(result.erro){
				$('#response-funis').html('<div class="alert alert-danger py-1">'+result.erro+'</div>');
				return;
			}

			$('#response-funis').html('<div class="alert alert-success py-1">Funil criado com sucesso.</div>');
			$('#form-novo-funil')[0].reset();

			if(result.funis){
				const funilAtivo = result.funil ? String(result.funil.id) : obterFunilAtivo();
				atualizarTodosSelectsFunis(result.funis, funilAtivo);
				renderizarListaFunis(result.funis);
			}

			setTimeout(function(){
				$('#response-funis').html('');
			}, 2000);
		},
		error: function(xhr){
			console.error('Erro ao salvar funil:', xhr.status, xhr.responseText);
			$('#response-funis').html('<div class="alert alert-danger py-1">Erro ao salvar o funil. Verifique se a rota está disponível no servidor.</div>');
		}
	});
});

$(document).on('click', '.btn-excluir-funil', function(){
	const id   = $(this).data('id');
	const nome = $(this).data('nome');

	Swal.fire({
		title: 'Excluir funil?',
		text: 'Deseja excluir o funil "'+nome+'"?',
		icon: 'warning',
		showCancelButton: true,
		confirmButtonText: 'Sim, excluir',
		cancelButtonText: 'Cancelar'
	}).then(function(confirmacao){
		if(!confirmacao.isConfirmed) return;

		$.ajax({
			url: url_base + listagemCrm,
			method: 'post',
			data: { acao: 'excluir_funil', id },
			dataType: 'json',
			success: function(result){
				if(result.erro){
					Swal.fire({ title: 'Ops...', text: result.erro, icon: 'error' });
					return;
				}

				if(String(id) === String(obterFunilAtivo())){
					sessionStorage.removeItem(CRM_FUNIL_STORAGE);
					crmFunilAtivo = null;
				}

				if(result.funis){
					const novoAtivo = result.funis[0] ? String(result.funis[0].id) : null;
					atualizarTodosSelectsFunis(result.funis, novoAtivo);
					renderizarListaFunis(result.funis);
				}

				carregarLeads(1);
				Swal.fire({ title: 'Funil excluído!', icon: 'success', timer: 1200, showConfirmButton: false });
			}
		});
	});
});

$(document).on('click', '.btn-motivo', function(){
	const motivo = $(this).data('motivo');
	$('.btn-motivo').removeClass('active');
	$(this).addClass('active');
	$('#perda-motivo').val(motivo);
	$('#btn-confirmar-perda').prop('disabled', false);
});

$(document).on('submit', '#form-motivo-perda', function(event){
	event.preventDefault();

	const id     = $('#perda-lead-id').val();
	const motivo = $('#perda-motivo').val();

	if(!motivo){
		$('#response-motivo-perda').html('<div class="alert alert-danger py-1">Selecione um motivo.</div>');
		return;
	}

	atualizarStatusLead(id, 'perdido', motivo, function(){
		$('#modalMotivoPerda').modal('hide');
	});
});

$('#modalMotivoPerda').on('hidden.bs.modal', function(){
	if($('#perda-motivo').val() === ''){
		carregarLeads();
	}
});

$(document).on('submit', '#form-novo-lead', function(event){
	event.preventDefault();

	$.ajax({
		url: url_base + salvarLead,
		method: 'post',
		data: $(this).serialize(),
		dataType: 'json',
		success: function(result){
			if(result.erro){
				$('#response-lead').html('<div class="alert alert-danger">'+result.erro+'</div>');
			} else {
				$('#modalNovoLead').modal('hide');
				$('#form-novo-lead')[0].reset();
				$('#response-lead').html('');
				Swal.fire({ title: 'Lead cadastrado!', icon: 'success', timer: 1200, showConfirmButton: false });
				carregarLeads();
			}
		}
	});
});

$(document).on('submit', '#form-comentario-lead', function(event){
	event.preventDefault();

	$.ajax({
		url: url_base + comentarioLead,
		method: 'post',
		data: $(this).serialize(),
		dataType: 'json',
		success: function(result){
			if(result.erro){
				$('#response-detalhe-lead').html('<div class="alert alert-danger">'+result.erro+'</div>');
			} else {
				$('#detalhe-observacao').val('');
				$('#detalhe-timeline').html(result.timeline);
				$('#response-detalhe-lead').html('<div class="alert alert-success">Comentário salvo com sucesso.</div>');
				carregarLeads();
				setTimeout(function(){
					$('#response-detalhe-lead').html('');
				}, 2000);
			}
		}
	});
});

$(document).on('submit', '#form-editar-lead', function(event){
	event.preventDefault();

	$.ajax({
		url: url_base + atualizarLead,
		method: 'post',
		data: $(this).serialize(),
		dataType: 'json',
		success: function(result){
			if(result.erro){
				$('#response-detalhe-lead').html('<div class="alert alert-danger">'+result.erro+'</div>');
			} else {
				preencherModalDetalhes(result);
				$('#response-detalhe-lead').html('<div class="alert alert-success">Dados atualizados com sucesso.</div>');
				carregarLeads();
				setTimeout(function(){
					$('#response-detalhe-lead').html('');
				}, 2000);
			}
		}
	});
});

$(document).on('submit', '#form-importar-leads', function(event){
	event.preventDefault();

	const $form = $(this);
	const $btn  = $('#btn-importar-leads');
	const formData = new FormData(this);

	$('#response-importar').html('');
	$btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Importando...');

	$.ajax({
		url: url_base + importarLeads,
		method: 'post',
		data: formData,
		processData: false,
		contentType: false,
		dataType: 'json',
		success: function(result){
			if(result.erro){
				let html = '<div class="alert alert-danger">'+result.erro+'</div>';
				if(result.detalhes && result.detalhes.length){
					html += '<ul class="small text-danger mb-0">';
					result.detalhes.forEach(function(item){
						html += '<li>'+item+'</li>';
					});
					html += '</ul>';
				}
				$('#response-importar').html(html);
				$btn.prop('disabled', false).html('<i class="fas fa-upload"></i> Importar');
				return;
			}

			$('#modalImportarLeads').modal('hide');
			$form[0].reset();

			let texto = result.importados + ' lead(s) importado(s) com sucesso.';
			if(result.ignorados > 0){
				texto += ' '+result.ignorados+' linha(s) ignorada(s).';
			}

			Swal.fire({
				title: 'Importação concluída!',
				text: texto,
				icon: 'success'
			}).then(function(){
				carregarLeads();
			});
		},
		error: function(){
			$('#response-importar').html('<div class="alert alert-danger">Erro ao enviar a planilha. Tente novamente.</div>');
			$btn.prop('disabled', false).html('<i class="fas fa-upload"></i> Importar');
		}
	});
});

$('#modalImportarLeads').on('hidden.bs.modal', function(){
	$('#form-importar-leads')[0].reset();
	$('#response-importar').html('');
	$('#btn-importar-leads').prop('disabled', false).html('<i class="fas fa-upload"></i> Importar');
});

function limparFiltroBuscaLead(){
	const el = document.getElementById('filtro-busca-lead');
	if(!el) return;

	const valor = (el.value || '').trim();

	if(valor.indexOf('@') !== -1){
		el.value = '';
		el.setAttribute('value', '');
	}
}

function resetarFiltroBuscaLead(){
	const el = document.getElementById('filtro-busca-lead');
	if(!el) return;

	el.value = '';
	el.setAttribute('value', '');
	el.removeAttribute('readonly');
}

function iniciarAntiAutofillFiltro(){
	resetarFiltroBuscaLead();

	[50, 150, 400, 800, 1200].forEach(function(ms){
		setTimeout(limparFiltroBuscaLead, ms);
	});

	$(document).on('focus', '#filtro-busca-lead', function(){
		$(this).removeAttr('readonly');
		limparFiltroBuscaLead();
	});
}

$(document).ready(function(){
	if(typeof listagemCrm !== 'undefined'){
		iniciarAntiAutofillFiltro();
		$('#filtro-curso').val('');
		carregarLeads(1);
		inicializarSortable();
	}
});

$(window).on('load', function(){
	if(typeof listagemCrm !== 'undefined'){
		resetarFiltroBuscaLead();
		setTimeout(limparFiltroBuscaLead, 100);
	}
});
