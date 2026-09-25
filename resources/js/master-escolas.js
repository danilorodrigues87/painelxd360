const MASTER_ESCOLAS_URL = 'master/clientes';
const XD360_BASE_DOMAIN = window.XD360_BASE_DOMAIN || 'xd360.com.br';
const LOGO_PADRAO = (typeof url_base !== 'undefined' ? url_base : '/') + 'resources/assets/img/brand/xd360-icon.svg';
const MODELO_CERT_PADRAO = window.MASTER_MODELO_CERT_PADRAO
	|| ((typeof url_base !== 'undefined' ? url_base : '/') + 'uploads/img/certificado/modelo_cert.png');
let masterEscolasCache = [];

function esc(s){
	return $('<div>').text(s == null ? '' : String(s)).html();
}

function slugifyNome(nome){
	return String(nome || '').toLowerCase()
		.normalize('NFD').replace(/[\u0300-\u036f]/g, '')
		.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 80);
}

function atualizarPreviewSlug(){
	const slug = ($('#escola_slug').val() || '').trim().toLowerCase();
	$('#escola_slug_suffix').text('.' + XD360_BASE_DOMAIN);
	if(!slug){
		$('#escola_url_preview').text('URL do painel: —');
		return;
	}
	$('#escola_url_preview').html('URL do painel: <code>https://'+esc(slug)+'.'+esc(XD360_BASE_DOMAIN)+'</code>');
}

function temPlanId(){
	return String(window.MASTER_TEM_PLAN_ID) === '1';
}

function temModeloCert(){
	return String(window.MASTER_TEM_MODELO_CERT) === '1';
}

function popularSelectPlanos(selected){
	const $sel = $('#escola_plan_id').empty();
	$sel.append('<option value="">Personalizado (módulos manuais)</option>');
	(window.MASTER_PLANOS || []).forEach(function(p){
		$sel.append('<option value="'+p.id+'">'+esc(p.nome)+'</option>');
	});
	if(selected) $sel.val(String(selected));
}

function renderModulosChecks(selecionados, todos){
	const mods = window.MASTER_MODULOS || [];
	const sel = {};
	(selecionados || []).forEach(function(s){ sel[s] = true; });
	const $box = $('#lista-modulos-master').empty();
	mods.forEach(function(m){
		const id = 'mod-'+m.slug;
		const checked = todos || !!sel[m.slug];
		$box.append(
			'<div class="col-md-4 col-sm-6">'
			+'<div class="form-check">'
			+'<input class="form-check-input chk-mod-master" type="checkbox" id="'+id+'" value="'+esc(m.slug)+'" '+(checked?'checked':'')+'>'
			+'<label class="form-check-label" for="'+id+'">'+esc(m.label)+'</label>'
			+'</div></div>'
		);
	});
	aplicarUiPlanoModulos();
}

function aplicarUiPlanoModulos(){
	const planId = $('#escola_plan_id').val();
	const comPlano = !!planId;
	$('#todos_modulos, .chk-mod-master').prop('disabled', comPlano);
	if(comPlano){
		const plano = (window.MASTER_PLANOS || []).find(function(p){ return String(p.id) === String(planId); });
		if(plano){
			if(plano.todos_modulos){
				$('#todos_modulos').prop('checked', true);
				$('.chk-mod-master').prop('checked', true);
			} else {
				$('#todos_modulos').prop('checked', false);
				const set = {};
				(plano.modulos || []).forEach(function(s){ set[s] = true; });
				$('.chk-mod-master').each(function(){
					$(this).prop('checked', !!set[$(this).val()]);
				});
			}
		}
		$('#hint-modulos-plano').text('Produtos definidos pelo plano selecionado.');
	} else {
		const todos = $('#todos_modulos').is(':checked');
		$('.chk-mod-master').prop('disabled', todos);
		if(todos) $('.chk-mod-master').prop('checked', true);
		$('#hint-modulos-plano').text('Sem plano: escolha os produtos manualmente.');
	}
}

function coletarSlugs(){
	const slugs = [];
	$('.chk-mod-master:checked').each(function(){ slugs.push($(this).val()); });
	return slugs;
}

function popularSelectEstados(selected){
	const $sel = $('#escola_estado').empty();
	$sel.append('<option value="">Selecione</option>');
	(window.MASTER_ESTADOS || []).forEach(function(e){
		const label = e.sigla ? (e.sigla + ' — ' + e.nome) : e.nome;
		$sel.append('<option value="'+e.id+'">'+esc(label)+'</option>');
	});
	if(selected) $sel.val(String(selected));
}

function carregarCidades(estadoId, cidadeSelected, done){
	const $cid = $('#escola_cidade').empty();
	if(!estadoId){
		$cid.append('<option value="">Selecione o estado</option>');
		if(typeof done === 'function') done();
		return;
	}
	$cid.append('<option value="">Carregando...</option>');
	$.post(url_base + MASTER_ESCOLAS_URL, { acao: 'cidades', estado: estadoId }, function(res){
		$cid.empty().append('<option value="">Selecione</option>');
		((res && res.cidades) || []).forEach(function(c){
			$cid.append('<option value="'+c.id+'">'+esc(c.nome)+'</option>');
		});
		if(cidadeSelected) $cid.val(String(cidadeSelected));
		if(typeof done === 'function') done();
	}, 'json').fail(function(){
		$cid.empty().append('<option value="">Falha ao carregar cidades</option>');
		if(typeof done === 'function') done();
	});
}

function aplicarTipoCliente(){
	const pf = $('#escola_tipo').val() === 'pf';
	$('#lbl-escola-nome').text(pf ? 'Nome completo *' : 'Nome da empresa *');
	$('#lbl-escola-doc').text(pf ? 'CPF' : 'CNPJ');
	$('#titulo-responsavel').text(pf ? 'Quem assina' : 'Quem assina o contrato');
	$('#ajuda-responsavel').text(pf
		? 'Pessoa física assina com o próprio nome e CPF. O e-mail abaixo é o login do painel.'
		: 'No MEI e na empresa, o contrato é da pessoa jurídica e a assinatura é de uma pessoa física (nome e CPF).');
	if (pf) {
		if (!$('#diretor_nome').val()) $('#diretor_nome').val($('#escola_nome').val() || '');
		if (!$('#diretor_cpf').val()) $('#diretor_cpf').val($('#escola_cpf_cnpj').val() || '');
	}
}

function limparForm(){
	$('#escola_id').val('');
	$('#escola_nome, #escola_email, #escola_telefone, #escola_cpf_cnpj, #escola_site, #escola_slug, #escola_dominio_custom').val('');
	$('#escola_dominio_verificado, #escola_quer_site').prop('checked', false);
	$('#bloco-dominio-site').addClass('d-none');
	atualizarPreviewSlug();
	$('#diretor_nome, #diretor_email, #diretor_cpf').val('');
	$('#escola_tipo').val('pj');
	aplicarTipoCliente();
	$('#escola_ativo').val('1');
	$('#escola_dia_venc').val('10');
	$('#escola_valor_custom').val('');
	$('#escola_assinatura_status').val('trial');
	$('#escola_trial_ate').val('');
	$('#escola_sem_trial').prop('checked', false);
	$('#todos_modulos').prop('checked', true);
	popularSelectPlanos('');
	$('#bloco-diretor-nova').show();
	$('#titulo-modal-escola').text('Novo cliente');
	renderModulosChecks([], true);
	if(typeof carregarContratosCliente === 'function') carregarContratosCliente('');
}

function mostrarSenhaDiretor(titulo, diretor){
	Swal.fire({
		icon: 'success',
		title: titulo,
		html: '<p class="small mb-1"><strong>Administrador:</strong> '+esc(diretor.nome)+'</p>'
			+'<p class="small mb-1"><strong>E-mail:</strong> '+esc(diretor.email)+'</p>'
			+'<p class="small mb-0"><strong>Senha temporária:</strong> <code>'+esc(diretor.senha)+'</code></p>'
			+'<p class="text-danger small mt-2 mb-0">Anote agora — não será exibida novamente.</p>',
		width: 520
	});
}

function renderLista(escolas){
	const filtro = ($('#filtro-escola').val() || '').toLowerCase().trim();
	const $tb = $('#lista-escolas-master').empty();
	const lista = (escolas || []).filter(function(e){
		if(!filtro) return true;
		return String(e.nome || '').toLowerCase().indexOf(filtro) !== -1
			|| String(e.email || '').toLowerCase().indexOf(filtro) !== -1;
	});

	if(!lista.length){
		$tb.append('<tr><td colspan="7" class="text-center text-muted py-4">Nenhum cliente encontrado.</td></tr>');
		return;
	}

	lista.forEach(function(e){
		const badge = e.ativo
			? '<span class="badge bg-success">Ativa</span>'
			: '<span class="badge bg-secondary">Inativa</span>';
		const plano = e.plano_nome ? esc(e.plano_nome) : '<span class="text-muted">Personalizado</span>';
		const sub = e.slug
			? '<code class="small">'+esc(e.slug)+'</code>'
			: '<span class="text-muted">—</span>';
		$tb.append(
			'<tr>'
			+'<td>'+esc(e.id)+'</td>'
			+'<td><strong>'+esc(e.nome)+'</strong></td>'
			+'<td class="small">'+sub+'</td>'
			+'<td class="small">'+plano+'</td>'
			+'<td class="small">'+esc(e.email || '—')+'<br>'+esc(e.telefone || '')+'</td>'
			+'<td>'+badge+'</td>'
			+'<td class="text-end text-nowrap">'
			+'<button type="button" class="btn btn-sm btn-outline-success me-1 btn-impersonar" data-id="'+e.id+'" title="Entrar como cliente"><i class="fas fa-sign-in-alt"></i></button>'
			+'<button type="button" class="btn btn-sm btn-outline-warning me-1 btn-reset-diretor" data-id="'+e.id+'" title="Reset senha admin"><i class="fas fa-key"></i></button>'
			+'<button type="button" class="btn btn-sm btn-outline-primary me-1 btn-editar-escola" data-id="'+e.id+'"><i class="fas fa-edit"></i></button>'
			+'<button type="button" class="btn btn-sm btn-outline-'+(e.ativo?'secondary':'success')+' btn-toggle-escola" data-id="'+e.id+'">'
			+(e.ativo?'Off':'On')
			+'</button>'
			+'</td>'
			+'</tr>'
		);
	});
}

function carregarEscolas(){
	$.post(url_base + MASTER_ESCOLAS_URL, { acao: 'listar' }, function(res){
		if(!res || !res.success){
			Swal.fire('Erro', (res && res.message) || 'Falha ao listar.', 'error');
			return;
		}
		masterEscolasCache = res.escolas || [];
		renderLista(masterEscolasCache);
	}, 'json').fail(function(){
		Swal.fire('Erro', 'Falha ao carregar clientes.', 'error');
	});
}

function abrirEdicao(id){
	$.post(url_base + MASTER_ESCOLAS_URL, { acao: 'detalhes', id: id }, function(res){
		if(!res || !res.success){
			Swal.fire('Erro', (res && res.message) || 'Falha ao carregar.', 'error');
			return;
		}
		const e = res.escola;
		$('#escola_id').val(e.id);
		$('#escola_nome').val(e.nome || '');
		$('#escola_slug').val(e.slug || '');
		$('#escola_dominio_custom').val(e.dominio_custom || '');
		$('#escola_dominio_verificado').prop('checked', !!e.dominio_verificado);
		$('#escola_quer_site').prop('checked', !!(e.dominio_custom));
		$('#bloco-dominio-site').toggleClass('d-none', !e.dominio_custom);
		atualizarPreviewSlug();
		$('#escola_email').val(e.email || '');
		$('#escola_telefone').val(e.telefone || '');
		$('#escola_cpf_cnpj').val(e.cpf_cnpj || '');
		const doc = String(e.cpf_cnpj || '').replace(/\D/g, '');
		$('#escola_tipo').val(doc.length === 11 ? 'pf' : 'pj');
		aplicarTipoCliente();
		const dir = (e.diretores && e.diretores[0]) || {};
		$('#diretor_nome').val(dir.nome || '');
		$('#diretor_email').val(dir.email || '');
		$('#diretor_cpf').val(dir.cpf || '');
		$('#escola_site').val(e.site || '');
		$('#escola_ativo').val(e.ativo ? '1' : '0');
		$('#escola_dia_venc').val(e.dia_vencimento_assinatura || 10);
		$('#escola_valor_custom').val(e.valor_mensal_custom != null ? e.valor_mensal_custom : '');
		$('#escola_assinatura_status').val(e.assinatura_status || 'ativa');
		$('#escola_trial_ate').val(e.trial_ate || '');
		$('#escola_sem_trial').prop('checked', false);
		$('#todos_modulos').prop('checked', !!e.todos_modulos);
		popularSelectPlanos(e.plan_id || '');
		$('#bloco-diretor-nova').show();
		$('#titulo-modal-escola').text('Editar cliente #'+e.id);
		renderModulosChecks(e.modulos || [], !!e.todos_modulos);
		$('#modalEscolaMaster').modal('show');
		if (typeof carregarContratosCliente === 'function') carregarContratosCliente(e.id);
	}, 'json');
}

function salvarEscola(){
	const id = $('#escola_id').val();
	const nome = ($('#escola_nome').val() || '').trim();
	const diretorNome = ($('#diretor_nome').val() || '').trim();
	const diretorEmail = ($('#diretor_email').val() || '').trim();

	if(!nome){
		Swal.fire('Atenção', $('#escola_tipo').val() === 'pf' ? 'Informe o nome completo.' : 'Informe o nome da empresa.', 'warning');
		return;
	}
	if(!id && (!diretorNome || !diretorEmail)){
		Swal.fire('Atenção', 'Informe nome e e-mail do administrador.', 'warning');
		return;
	}

	const fd = new FormData();
	fd.append('acao', 'salvar');
	fd.append('id', id || '');
	fd.append('nome', nome);
	fd.append('slug', ($('#escola_slug').val() || '').trim());
	const querSite = $('#escola_quer_site').is(':checked');
	fd.append('dominio_custom', querSite ? ($('#escola_dominio_custom').val() || '').trim() : '');
	fd.append('comercial_por_contrato', '1');
	if(querSite && $('#escola_dominio_verificado').is(':checked')){
		fd.append('dominio_verificado', '1');
	}
	fd.append('email', $('#escola_email').val() || '');
	fd.append('telefone', $('#escola_telefone').val() || '');
	fd.append('cpf_cnpj', $('#escola_cpf_cnpj').val() || '');
	fd.append('site', $('#escola_site').val() || '');
	fd.append('ativo', $('#escola_ativo').val());
	fd.append('dia_vencimento_assinatura', $('#escola_dia_venc').val() || '10');
	fd.append('assinatura_status', $('#escola_assinatura_status').val() || 'ativa');
	fd.append('trial_ate', $('#escola_trial_ate').val() || '');
	if($('#escola_sem_trial').is(':checked')){
		fd.append('sem_trial', '1');
	}
	fd.append('plan_id', $('#escola_plan_id').val() || '');
	fd.append('todos_modulos', $('#todos_modulos').is(':checked') ? '1' : '0');
	fd.append('modulos_json', JSON.stringify(coletarSlugs()));
	fd.append('diretor_nome', diretorNome);
	fd.append('diretor_cpf', $('#diretor_cpf').val() || '');
	fd.append('diretor_email', diretorEmail);
	$('#btn-salvar-escola').prop('disabled', true);
	$.ajax({
		url: url_base + MASTER_ESCOLAS_URL,
		method: 'POST',
		data: fd,
		processData: false,
		contentType: false,
		dataType: 'json'
	}).done(function(res){
		$('#btn-salvar-escola').prop('disabled', false);
		if(!res || !res.success){
			Swal.fire('Erro', (res && res.message) || 'Não foi possível salvar.', 'error');
			return;
		}
		carregarEscolas();
		if(res.escola && res.escola.id){
			$('#escola_id').val(res.escola.id);
			$('#bloco-diretor-nova').hide();
			$('#titulo-modal-escola').text('Cliente #'+res.escola.id);
			if(typeof carregarContratosCliente === 'function') carregarContratosCliente(res.escola.id);
		}
		if(res.diretor && res.diretor.senha){
			mostrarSenhaDiretor('Cliente criado. Agora grave o contrato abaixo.', res.diretor);
			return;
		}
		Swal.fire('OK', res.message, 'success');
	}).fail(function(xhr){
		$('#btn-salvar-escola').prop('disabled', false);
		let msg = 'Falha ao salvar.';
		const raw = (xhr && xhr.responseText) ? String(xhr.responseText) : '';
		if(raw.indexOf('ERROR:') === 0){
			msg = raw.replace(/^ERROR:\s*/, '').slice(0, 280);
		} else if(raw){
			try {
				const parsed = JSON.parse(raw);
				if(parsed && parsed.message) msg = parsed.message;
			} catch (e) { /* ignore */ }
		}
		Swal.fire('Erro', msg, 'error');
	});
}

$(function(){
	if(!temPlanId()){
		$('#alert-plan-id').removeClass('d-none');
	}
	if(!temModeloCert()){
		$('#alert-modelo-cert').removeClass('d-none');
		$('#escola_modelo_certificado').prop('disabled', true);
	}
	$('#preview-modelo-cert').attr('src', MODELO_CERT_PADRAO);
	popularSelectPlanos('');
	popularSelectEstados('');
	carregarCidades('', '');
	renderModulosChecks([], true);
	carregarEscolas();
	$('#escola_tipo').on('change', aplicarTipoCliente);

	$('#escola_nome').on('blur', function(){
		if(!($('#escola_slug').val() || '').trim()){
			$('#escola_slug').val(slugifyNome($(this).val()));
			atualizarPreviewSlug();
		}
	});
	$('#escola_slug').on('input', function(){
		$(this).val(String($(this).val() || '').toLowerCase().replace(/[^a-z0-9-]/g, ''));
		atualizarPreviewSlug();
	});
	atualizarPreviewSlug();
	$('#escola_quer_site').on('change', function(){
		$('#bloco-dominio-site').toggleClass('d-none', !this.checked);
		if(!this.checked){
			$('#escola_dominio_custom').val('');
			$('#escola_dominio_verificado').prop('checked', false);
		}
	});

	$('#btn-nova-escola').on('click', function(){
		limparForm();
		$('#modalEscolaMaster').modal('show');
	});
	$('#btn-salvar-escola').on('click', salvarEscola);
	$('#escola_estado').on('change', function(){
		carregarCidades($(this).val() || '', '');
	});
	$('#todos_modulos').on('change', aplicarUiPlanoModulos);
	$('#escola_plan_id').on('change', aplicarUiPlanoModulos);
	$('#filtro-escola').on('input', function(){ renderLista(masterEscolasCache); });

	$(document).on('click', '.btn-editar-escola', function(){
		abrirEdicao($(this).data('id'));
	});
	$(document).on('click', '.btn-toggle-escola', function(){
		$.post(url_base + MASTER_ESCOLAS_URL, { acao: 'toggle_ativo', id: $(this).data('id') }, function(res){
			if(!res || !res.success){
				Swal.fire('Erro', (res && res.message) || 'Falha.', 'error');
				return;
			}
			carregarEscolas();
		}, 'json');
	});
	$(document).on('click', '.btn-reset-diretor', function(){
		const id = $(this).data('id');
		Swal.fire({
			title: 'Resetar senha do administrador?',
			text: 'Uma senha temporária será gerada.',
			icon: 'question',
			showCancelButton: true,
			confirmButtonText: 'Sim, resetar'
		}).then(function(r){
			if(!r.isConfirmed) return;
			$.post(url_base + MASTER_ESCOLAS_URL, { acao: 'reset_diretor', id: id }, function(res){
				if(!res || !res.success){
					Swal.fire('Erro', (res && res.message) || 'Falha.', 'error');
					return;
				}
				mostrarSenhaDiretor('Senha redefinida', res.diretor);
			}, 'json');
		});
	});
	$(document).on('click', '.btn-impersonar', function(){
		const id = $(this).data('id');
		Swal.fire({
			title: 'Entrar neste cliente?',
			text: 'Você acessará o painel como administrador (modo suporte).',
			icon: 'question',
			showCancelButton: true,
			confirmButtonText: 'Entrar'
		}).then(function(r){
			if(!r.isConfirmed) return;
			$.post(url_base + MASTER_ESCOLAS_URL, { acao: 'impersonar', id: id }, function(res){
				if(!res || !res.success){
					Swal.fire('Erro', (res && res.message) || 'Falha.', 'error');
					return;
				}
				window.location.href = res.redirect || (url_base + 'painel');
			}, 'json');
		});
	});

	$('#modalEscolaMaster').on('hidden.bs.modal', limparForm);
});
