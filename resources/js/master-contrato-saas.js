const MASTER_CONTRATO_SAAS_URL = 'master/contrato-saas';

let saasPreviewTimer = null;

function escHtml(s) {
	return $('<div>').text(s == null ? '' : String(s)).html();
}

function getPreviewOptsSaas() {
	return {
		id_escola: parseInt($('#preview_escola').val(), 10) || 0,
		id_plano: parseInt($('#preview_plano').val(), 10) || 0,
	};
}

function mostrarAlertasContrato(res) {
	const $xd360 = $('#alert-dados-xd360-contrato');
	const $pend = $('#alert-pendencias-contrato');
	if (res && res.dados_xd360_ok === false && (res.dados_xd360_faltando || []).length) {
		$xd360.removeClass('d-none').html(
			'<strong>Dados jurídicos XD360 incompletos:</strong> ' + escHtml((res.dados_xd360_faltando || []).join(', '))
			+ '. <a href="' + url_base + 'master/dados-xd360">Completar cadastro</a>.'
		);
	} else {
		$xd360.addClass('d-none');
	}
}

function mostrarPendenciasPreview(pendencias) {
	const $pend = $('#alert-pendencias-contrato');
	if (pendencias && pendencias.length) {
		$pend.removeClass('d-none').html(
			'<strong>Prévia — pendências do cliente selecionado:</strong> ' + escHtml(pendencias.join(', ')) + '.'
		);
	} else {
		$pend.addClass('d-none');
	}
}

function atualizarPreviewSaas() {
	const html = $('#modelo_saas_html').val();
	const opts = getPreviewOptsSaas();
	$.post(url_base + MASTER_CONTRATO_SAAS_URL, {
		acao: 'preview',
		html: html,
		id_escola: opts.id_escola,
		id_plano: opts.id_plano,
	}, function(res) {
		if (!res || !res.success) return;
		const frame = document.getElementById('iframe-preview-saas');
		if (frame) frame.srcdoc = res.preview || '';
		mostrarPendenciasPreview(res.pendencias || []);
	}, 'json');
}

function agendarPreviewSaas() {
	clearTimeout(saasPreviewTimer);
	saasPreviewTimer = setTimeout(atualizarPreviewSaas, 450);
}

function inserirVariavelSaas(chave) {
	const el = document.getElementById('modelo_saas_html');
	if (!el) return;
	const token = '{{' + chave + '}}';
	const start = el.selectionStart;
	const end = el.selectionEnd;
	const val = el.value;
	el.value = val.slice(0, start) + token + val.slice(end);
	el.focus();
	el.selectionStart = el.selectionEnd = start + token.length;
	agendarPreviewSaas();
}

function renderChipsSaas(vars) {
	const $wrap = $('#chips-vars-saas').empty();
	(vars || []).forEach(function(v) {
		const $btn = $('<button type="button" class="btn btn-sm btn-outline-primary"></button>');
		$btn.text('{{' + v.chave + '}}');
		$btn.on('click', function() { inserirVariavelSaas(v.chave); });
		$wrap.append($btn);
	});
}

function popularSelectEscolas(escolas) {
	const $sel = $('#preview_escola');
	$sel.find('option:not(:first)').remove();
	(escolas || []).forEach(function(e) {
		$sel.append('<option value="' + e.id + '">' + escHtml(e.nome) + '</option>');
	});
}

function popularSelectPlanos(planos) {
	const $sel = $('#preview_plano');
	$sel.find('option:not(:first)').remove();
	(planos || []).forEach(function(p) {
		$sel.append('<option value="' + p.id + '">' + escHtml(p.nome) + '</option>');
	});
}

function carregarContratoSaas() {
	$.post(url_base + MASTER_CONTRATO_SAAS_URL, { acao: 'carregar' }, function(res) {
		if (!res || !res.success) {
			Swal.fire('Erro', (res && res.message) ? res.message : 'Falha ao carregar.', 'error');
			return;
		}

		popularSelectEscolas(res.escolas || []);
		popularSelectPlanos(res.planos || []);

		$('#modelo_saas_html').val(res.html || '');
		$('#badge-padrao-saas')
			.removeClass('bg-secondary bg-success')
			.addClass(res.usando_padrao ? 'bg-secondary' : 'bg-success')
			.text(res.usando_padrao ? 'Usando padrão XD360' : 'Modelo customizado');

		const $ul = $('#lista-vars-saas').empty();
		(res.variaveis || []).forEach(function(v) {
			$ul.append('<li><code>{{' + escHtml(v.chave) + '}}</code> — ' + escHtml(v.descricao) + '</li>');
		});
		renderChipsSaas(res.variaveis || []);
		mostrarAlertasContrato(res);
		atualizarPreviewSaas();
	}, 'json').fail(function() {
		Swal.fire('Erro', 'Falha ao carregar contrato SaaS.', 'error');
	});
}

function salvarContratoSaas() {
	$('#btn-salvar-saas').prop('disabled', true);
	$.post(url_base + MASTER_CONTRATO_SAAS_URL, {
		acao: 'salvar',
		html: $('#modelo_saas_html').val(),
	}, function(res) {
		$('#btn-salvar-saas').prop('disabled', false);
		if (!res || !res.success) {
			Swal.fire('Erro', (res && res.message) ? res.message : 'Falha ao salvar.', 'error');
			return;
		}
		Swal.fire('Salvo', res.message, 'success');
		carregarContratoSaas();
	}, 'json').fail(function() {
		$('#btn-salvar-saas').prop('disabled', false);
		Swal.fire('Erro', 'Falha ao salvar.', 'error');
	});
}

function restaurarContratoSaas() {
	Swal.fire({
		title: 'Restaurar padrão XD360?',
		text: 'Volta ao texto do arquivo modelo_padrao.html. Seu HTML customizado será descartado.',
		icon: 'question',
		showCancelButton: true,
		confirmButtonText: 'Restaurar',
	}).then(function(r) {
		if (!r.isConfirmed) return;
		$.post(url_base + MASTER_CONTRATO_SAAS_URL, { acao: 'restaurar' }, function(res) {
			if (!res || !res.success) {
				Swal.fire('Erro', (res && res.message) ? res.message : 'Falha.', 'error');
				return;
			}
			Swal.fire('OK', res.message, 'success');
			carregarContratoSaas();
		}, 'json');
	});
}

function imprimirPreviewSaas() {
	const frame = document.getElementById('iframe-preview-saas');
	if (!frame || !frame.contentWindow) return;
	frame.contentWindow.focus();
	frame.contentWindow.print();
}

$(function() {
	carregarContratoSaas();
	$('#btn-salvar-saas').on('click', salvarContratoSaas);
	$('#btn-restaurar-saas').on('click', restaurarContratoSaas);
	$('#btn-print-preview-saas').on('click', imprimirPreviewSaas);
	$('#modelo_saas_html').on('input', agendarPreviewSaas);
	$('#preview_escola, #preview_plano').on('change', atualizarPreviewSaas);
});
