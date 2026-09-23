const CRM_AUTO_URL = 'painel/crm/automacao';

const STATUS_ORDER = ['novo', 'em_atendimento', 'matriculado'];

function escHtml(s){
	return $('<div>').text(s == null ? '' : String(s)).html();
}

function cardStatusHtml(slug, data){
	const enviarId = 'enviar_' + slug;
	const msgId = 'msg_' + slug;
	const previewId = 'preview_' + slug;
	const badgeClass = data.usando_padrao ? 'bg-secondary' : 'bg-success';
	const badgeText = data.usando_padrao ? 'Padrão CTI' : 'Customizado';

	return `
	<div class="card shadow-sm mb-4" data-status="${escHtml(slug)}">
		<div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
			<span>${escHtml(data.label)} <span class="badge ${badgeClass} badge-status">${badgeText}</span></span>
			<button type="button" class="btn btn-sm btn-outline-info btn-preview-status" data-status="${escHtml(slug)}">Pré-visualizar</button>
		</div>
		<div class="card-body">
			<div class="form-check form-switch mb-3">
				<input class="form-check-input enviar-status" type="checkbox" id="${enviarId}" data-status="${escHtml(slug)}" ${parseInt(data.enviar, 10) === 1 ? 'checked' : ''}>
				<label class="form-check-label" for="${enviarId}">Enviar mensagem ao mover lead para "${escHtml(data.label)}"</label>
			</div>
			<label class="form-label" for="${msgId}">Texto da mensagem</label>
			<textarea class="form-control msg-status" id="${msgId}" data-status="${escHtml(slug)}" rows="4" spellcheck="true">${escHtml(data.mensagem || '')}</textarea>
			<div class="form-text">Deixe igual ao padrão CTI ou use "Restaurar padrão CTI" para voltar aos textos originais.</div>
			<div class="mt-3 p-2 bg-light border rounded small d-none preview-box" id="${previewId}"></div>
		</div>
	</div>`;
}

function preencherTela(res){
	if(!res.colunas_ok){
		$('#alert-sql-crm-auto').removeClass('d-none').html(
			'Execute o SQL <code>database/crm_automacao_wa.sql</code> no phpMyAdmin para salvar templates por escola. Enquanto isso, continuam valendo os textos padrão CTI.'
		);
		$('#btn-salvar-crm-auto, #btn-restaurar-crm-auto, #crm_automacao_ativo').prop('disabled', true);
	} else {
		$('#alert-sql-crm-auto').addClass('d-none');
		$('#btn-salvar-crm-auto, #btn-restaurar-crm-auto, #crm_automacao_ativo').prop('disabled', false);
	}

	$('#crm_automacao_ativo').prop('checked', parseInt(res.automacao_ativo, 10) === 1);

	const $cards = $('#crm-auto-status-cards').empty();
	const statuses = res.statuses || {};
	STATUS_ORDER.forEach(function(slug){
		if(!statuses[slug]) return;
		$cards.append(cardStatusHtml(slug, statuses[slug]));
	});

	const $ul = $('#lista-vars-crm-auto').empty();
	(res.variaveis || []).forEach(function(v){
		$ul.append('<li><code>{{'+escHtml(v.chave)+'}}</code> — '+escHtml(v.descricao)+'</li>');
	});
}

function coletarDados(){
	const payload = {
		acao: 'salvar',
		automacao_ativo: $('#crm_automacao_ativo').is(':checked') ? 1 : 0
	};
	STATUS_ORDER.forEach(function(slug){
		payload['enviar_' + slug] = $('#enviar_' + slug).is(':checked') ? 1 : 0;
		payload['msg_' + slug] = $('#msg_' + slug).val();
	});
	return payload;
}

function carregarAutomacao(){
	$.post(url_base + CRM_AUTO_URL, { acao: 'carregar' }, function(res){
		if(!res || !res.success){
			Swal.fire('Erro', (res && res.message) ? res.message : 'Falha ao carregar.', 'error');
			return;
		}
		preencherTela(res);
	}, 'json').fail(function(){
		Swal.fire('Erro', 'Falha ao carregar automação CRM.', 'error');
	});
}

function salvarAutomacao(){
	$('#btn-salvar-crm-auto').prop('disabled', true);
	$.post(url_base + CRM_AUTO_URL, coletarDados(), function(res){
		$('#btn-salvar-crm-auto').prop('disabled', false);
		if(!res || !res.success){
			Swal.fire('Erro', (res && res.message) ? res.message : 'Falha ao salvar.', 'error');
			return;
		}
		Swal.fire('Salvo', res.message, 'success');
		carregarAutomacao();
	}, 'json').fail(function(){
		$('#btn-salvar-crm-auto').prop('disabled', false);
		Swal.fire('Erro', 'Falha ao salvar.', 'error');
	});
}

function restaurarAutomacao(){
	Swal.fire({
		title: 'Restaurar padrão CTI?',
		text: 'Volta aos textos originais e reativa o envio nos três status.',
		icon: 'question',
		showCancelButton: true,
		confirmButtonText: 'Restaurar'
	}).then(function(r){
		if(!r.isConfirmed) return;
		$.post(url_base + CRM_AUTO_URL, { acao: 'restaurar' }, function(res){
			if(!res || !res.success){
				Swal.fire('Erro', (res && res.message) ? res.message : 'Falha.', 'error');
				return;
			}
			Swal.fire('OK', res.message, 'success');
			carregarAutomacao();
		}, 'json');
	});
}

function previewStatus(slug){
	const $box = $('#preview_' + slug);
	$box.removeClass('d-none').text('Gerando…');
	$.post(url_base + CRM_AUTO_URL, {
		acao: 'preview',
		status: slug,
		mensagem: $('#msg_' + slug).val(),
		nome_exemplo: $('#preview_nome').val(),
		curso_exemplo: $('#preview_curso').val()
	}, function(res){
		if(!res || !res.success){
			$box.text('Erro na pré-visualização.');
			return;
		}
		$box.text(res.preview || '');
	}, 'json').fail(function(){
		$box.text('Falha na pré-visualização.');
	});
}

$(function(){
	carregarAutomacao();
	$('#btn-salvar-crm-auto').on('click', salvarAutomacao);
	$('#btn-restaurar-crm-auto').on('click', restaurarAutomacao);
	$('#crm-auto-status-cards').on('click', '.btn-preview-status', function(){
		previewStatus($(this).data('status'));
	});
});
