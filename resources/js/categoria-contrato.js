let catContratoPreviewTimer = null;
let catContratoCampoAtivo = 'contrato_clausula_1';
let catContratoModelos = null;

function catContratoUrl() {
	const id = $('#id_categoria_contrato').val();
	return 'painel/categoria/cursos/contrato/' + id;
}

function getCamposContratoCat() {
	return {
		contrato_clausula_1: $('#contrato_clausula_1').val(),
		contrato_clausula_2: $('#contrato_clausula_2').val(),
		contrato_clausula_3: $('#contrato_clausula_3').val(),
		contrato_clausula_extra: $('#contrato_clausula_extra').val(),
		contrato_pagamento_parcelado: $('#contrato_pagamento_parcelado').val(),
		contrato_pagamento_vista: $('#contrato_pagamento_vista').val(),
		contrato_pagamento_bolsista: $('#contrato_pagamento_bolsista').val(),
		contrato_obs_pontualidade: $('#contrato_obs_pontualidade').val(),
		pagamento: $('#preview_pagamento_cat').val() || 'parcelado',
		menor: $('#preview_menor_cat').is(':checked') ? 1 : 0,
	};
}

function atualizarPreviewCatContrato() {
	const payload = getCamposContratoCat();
	payload.acao = 'preview';
	$.post(url_base + catContratoUrl(), payload, function(res) {
		if (!res || !res.success) return;
		const frame = document.getElementById('iframe-preview-cat');
		if (frame) frame.srcdoc = res.preview || '';
	}, 'json');
}

function agendarPreviewCatContrato() {
	clearTimeout(catContratoPreviewTimer);
	catContratoPreviewTimer = setTimeout(atualizarPreviewCatContrato, 450);
}

function inserirTokenCat(chave) {
	const el = document.getElementById(catContratoCampoAtivo);
	if (!el) return;
	const token = '{{' + chave + '}}';
	const start = el.selectionStart;
	const end = el.selectionEnd;
	const val = el.value;
	el.value = val.slice(0, start) + token + val.slice(end);
	el.focus();
	el.selectionStart = el.selectionEnd = start + token.length;
	agendarPreviewCatContrato();
}

function renderChipsTokensCat(tokens) {
	const $wrap = $('#chips-tokens-cat').empty();
	(tokens || []).forEach(function(t) {
		const $btn = $('<button type="button" class="btn btn-sm btn-outline-primary"></button>');
		$btn.text('{{' + t.chave + '}}');
		$btn.on('click', function() { inserirTokenCat(t.chave); });
		$wrap.append($btn);
	});
}

function preencherCamposCat(clausulas) {
	const c = clausulas || {};
	$('#contrato_clausula_1').val(c.contrato_clausula_1 || '');
	$('#contrato_clausula_2').val(c.contrato_clausula_2 || '');
	$('#contrato_clausula_3').val(c.contrato_clausula_3 || '');
	$('#contrato_clausula_extra').val(c.contrato_clausula_extra || '');
	$('#contrato_pagamento_parcelado').val(c.contrato_pagamento_parcelado || '');
	$('#contrato_pagamento_vista').val(c.contrato_pagamento_vista || '');
	$('#contrato_pagamento_bolsista').val(c.contrato_pagamento_bolsista || '');
	$('#contrato_obs_pontualidade').val(c.contrato_obs_pontualidade || '');
}

function carregarContratoCategoria() {
	$.post(url_base + catContratoUrl(), { acao: 'carregar' }, function(res) {
		if (!res || !res.success) {
			Swal.fire('Erro', (res && res.message) ? res.message : 'Falha ao carregar.', 'error');
			return;
		}
		if (!res.coluna_ok) {
			$('#alert-sql-cat-contrato').removeClass('d-none').html(
				'Execute os SQL <code>database/categorias_contrato.sql</code> no phpMyAdmin.'
			);
			$('#btn-salvar-cat-contrato, #btn-modelo-sugerido-cat').prop('disabled', true);
		} else {
			$('#alert-sql-cat-contrato').addClass('d-none');
			$('#btn-salvar-cat-contrato, #btn-modelo-sugerido-cat').prop('disabled', false);
		}

		preencherCamposCat(res.clausulas);
		renderChipsTokensCat(res.tokens || []);

		$('#badge-contrato-cat')
			.removeClass('bg-secondary bg-success bg-warning')
			.addClass(res.contrato_completo ? 'bg-success' : 'bg-warning text-dark')
			.text(res.contrato_completo ? 'Completo' : 'Incompleto');

		if (!res.contrato_completo) {
			$('#alert-incompleto-cat').removeClass('d-none').text(
				'Preencha as cláusulas 1ª, 2ª e 3ª para que o contrato desta categoria seja impresso corretamente.'
			);
		} else {
			$('#alert-incompleto-cat').addClass('d-none');
		}

		atualizarPreviewCatContrato();
	}, 'json');
}

function salvarContratoCategoria() {
	$('#btn-salvar-cat-contrato').prop('disabled', true);
	const payload = getCamposContratoCat();
	payload.acao = 'salvar';
	$.post(url_base + catContratoUrl(), payload, function(res) {
		$('#btn-salvar-cat-contrato').prop('disabled', false);
		if (!res || !res.success) {
			Swal.fire('Erro', (res && res.message) ? res.message : 'Falha ao salvar.', 'error');
			return;
		}
		Swal.fire('Salvo', res.message, 'success');
		carregarContratoCategoria();
	}, 'json').fail(function() {
		$('#btn-salvar-cat-contrato').prop('disabled', false);
		Swal.fire('Erro', 'Falha ao salvar.', 'error');
	});
}

function aplicarModeloSugerido() {
	const aplicar = function(modelos) {
		if (!modelos) return;
		Swal.fire({
			title: 'Usar modelo sugerido?',
			text: 'Substitui cláusulas 1–3 e textos de pagamento vazios. Campos já preenchidos serão mantidos.',
			icon: 'question',
			showCancelButton: true,
			confirmButtonText: 'Aplicar',
		}).then(function(r) {
			if (!r.isConfirmed) return;
			if (!$('#contrato_clausula_1').val().trim()) $('#contrato_clausula_1').val(modelos.clausula_1 || '');
			if (!$('#contrato_clausula_2').val().trim()) $('#contrato_clausula_2').val(modelos.clausula_2 || '');
			if (!$('#contrato_clausula_3').val().trim()) $('#contrato_clausula_3').val(modelos.clausula_3 || '');
			if (!$('#contrato_pagamento_parcelado').val().trim()) $('#contrato_pagamento_parcelado').val(modelos.pagamento_parcelado || '');
			if (!$('#contrato_pagamento_vista').val().trim()) $('#contrato_pagamento_vista').val(modelos.pagamento_vista || '');
			if (!$('#contrato_pagamento_bolsista').val().trim()) $('#contrato_pagamento_bolsista').val(modelos.pagamento_bolsista || '');
			if (!$('#contrato_obs_pontualidade').val().trim()) $('#contrato_obs_pontualidade').val(modelos.obs_pontualidade || '');
			agendarPreviewCatContrato();
		});
	};

	if (catContratoModelos) {
		aplicar(catContratoModelos);
		return;
	}
	$.post(url_base + catContratoUrl(), { acao: 'modelos' }, function(res) {
		if (res && res.success) {
			catContratoModelos = res.modelos;
			aplicar(catContratoModelos);
		}
	}, 'json');
}

$(function() {
	carregarContratoCategoria();
	$('#btn-salvar-cat-contrato').on('click', salvarContratoCategoria);
	$('#btn-modelo-sugerido-cat').on('click', aplicarModeloSugerido);
	$('#btn-print-preview-cat').on('click', function() {
		const frame = document.getElementById('iframe-preview-cat');
		if (!frame || !frame.contentWindow) return;
		frame.contentWindow.focus();
		frame.contentWindow.print();
	});
	$('.contrato-campo-clausula').on('focus', function() {
		catContratoCampoAtivo = this.id;
	}).on('input', agendarPreviewCatContrato);
	$('#preview_pagamento_cat, #preview_menor_cat').on('change', atualizarPreviewCatContrato);
});
