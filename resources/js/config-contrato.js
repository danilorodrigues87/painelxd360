const CONFIG_CONTRATO_URL = 'painel/config/contrato';

let contratoPreviewTimer = null;



function escHtml(s) {

	return $('<div>').text(s == null ? '' : String(s)).html();

}



function getPreviewOpts() {

	return {

		id_categoria: parseInt($('#preview_categoria').val(), 10) || 0,

		pagamento: $('#preview_pagamento').val() || 'parcelado',

		menor: $('#preview_menor').is(':checked') ? 1 : 0,

	};

}



function atualizarAlertaCategoria(res) {

	const $alert = $('#alert-categoria-incompleta');

	if (!res || !res.categoria_incompleta) {

		$alert.addClass('d-none');

		return;

	}

	const id = res.categoria_id || getPreviewOpts().id_categoria;

	$alert.removeClass('d-none').html(

		'A categoria selecionada ainda não tem cláusulas 1ª–3ª configuradas. '

		+ '<a href="' + url_base + 'painel/categoria/cursos/contrato/' + id + '">Editar contrato da categoria</a>.'

	);

}



function atualizarPreviewContrato() {

	const html = $('#modelo_contrato_html').val();

	const opts = getPreviewOpts();

	$.post(url_base + CONFIG_CONTRATO_URL, {

		acao: 'preview',

		html: html,

		id_categoria: opts.id_categoria,

		pagamento: opts.pagamento,

		menor: opts.menor,

	}, function(res) {

		if (!res || !res.success) {

			return;

		}

		const frame = document.getElementById('iframe-preview-contrato');

		if (frame) frame.srcdoc = res.preview || '';

		atualizarAlertaCategoria(res);

	}, 'json');

}



function agendarPreviewContrato() {

	clearTimeout(contratoPreviewTimer);

	contratoPreviewTimer = setTimeout(atualizarPreviewContrato, 450);

}



function inserirVariavelContrato(chave) {

	const el = document.getElementById('modelo_contrato_html');

	if (!el) return;

	const token = '{{' + chave + '}}';

	const start = el.selectionStart;

	const end = el.selectionEnd;

	const val = el.value;

	el.value = val.slice(0, start) + token + val.slice(end);

	el.focus();

	el.selectionStart = el.selectionEnd = start + token.length;

	agendarPreviewContrato();

}



function renderChipsVariaveis(vars) {

	const $wrap = $('#chips-vars-contrato').empty();

	(vars || []).forEach(function(v) {

		const $btn = $('<button type="button" class="btn btn-sm btn-outline-primary"></button>');

		$btn.text('{{' + v.chave + '}}');

		$btn.on('click', function() { inserirVariavelContrato(v.chave); });

		$wrap.append($btn);

	});

}



function popularSelectCategorias(categorias) {

	const $sel = $('#preview_categoria').empty();

	const list = categorias || [];

	if (!list.length) {

		$sel.append('<option value="">Nenhuma categoria cadastrada</option>');

		return;

	}

	list.forEach(function(c) {

		const label = c.nome + (c.contrato_completo ? '' : ' (incompleto)');

		$sel.append('<option value="' + c.id + '">' + escHtml(label) + '</option>');

	});

}



function carregarModeloContrato() {

	$.post(url_base + CONFIG_CONTRATO_URL, { acao: 'carregar' }, function(res) {

		if (!res || !res.success) {

			Swal.fire('Erro', (res && res.message) ? res.message : 'Falha ao carregar.', 'error');

			return;

		}



		if (!res.coluna_ok) {

			$('#alert-sql-contrato').removeClass('d-none').html(

				'Execute o SQL <code>database/escolas_modelo_contrato.sql</code> no phpMyAdmin para salvar modelos por escola. Enquanto isso, o contrato impresso continua no padrão CTI.'

			);

			$('#btn-salvar-contrato, #btn-restaurar-contrato').prop('disabled', true);

		} else {

			$('#alert-sql-contrato').addClass('d-none');

			$('#btn-salvar-contrato, #btn-restaurar-contrato').prop('disabled', false);

		}



		if (res.contrato_categoria_coluna_ok === false) {

			$('#alert-sql-contrato').removeClass('d-none').append(

				'<br>Para cláusulas por categoria, execute também <code>database/categorias_contrato.sql</code>.'

			);

		}



		popularSelectCategorias(res.categorias || []);



		$('#modelo_contrato_html').val(res.html || '');

		$('#badge-padrao-contrato')

			.removeClass('bg-secondary bg-success bg-warning')

			.addClass(res.usando_padrao ? 'bg-secondary' : 'bg-success')

			.text(res.usando_padrao ? 'Usando padrão CTI' : 'Modelo customizado');



		const $ul = $('#lista-vars-contrato').empty();

		(res.variaveis || []).forEach(function(v) {

			$ul.append('<li><code>{{' + escHtml(v.chave) + '}}</code> — ' + escHtml(v.descricao) + '</li>');

		});

		renderChipsVariaveis(res.variaveis || []);



		if (res.frase_coluna_ok === false) {

			$('#certificado_frase_conclusao, #btn-salvar-frase-cert').prop('disabled', true);

		} else {

			$('#certificado_frase_conclusao, #btn-salvar-frase-cert').prop('disabled', false);

			$('#certificado_frase_conclusao').val((res.certificado && res.certificado.frase_conclusao) || '');

		}



		atualizarPreviewContrato();

	}, 'json').fail(function() {

		Swal.fire('Erro', 'Falha ao carregar modelo de contrato.', 'error');

	});

}



function salvarModeloContrato() {

	$('#btn-salvar-contrato').prop('disabled', true);

	$.post(url_base + CONFIG_CONTRATO_URL, {

		acao: 'salvar',

		html: $('#modelo_contrato_html').val(),

	}, function(res) {

		$('#btn-salvar-contrato').prop('disabled', false);

		if (!res || !res.success) {

			Swal.fire('Erro', (res && res.message) ? res.message : 'Falha ao salvar.', 'error');

			return;

		}

		Swal.fire('Salvo', res.message, 'success');

		carregarModeloContrato();

	}, 'json').fail(function() {

		$('#btn-salvar-contrato').prop('disabled', false);

		Swal.fire('Erro', 'Falha ao salvar.', 'error');

	});

}



function restaurarModeloContrato() {

	Swal.fire({

		title: 'Restaurar padrão CTI?',

		text: 'Volta ao texto atual da escola 1 (Capão Bonito). Seu HTML customizado será descartado.',

		icon: 'question',

		showCancelButton: true,

		confirmButtonText: 'Restaurar',

	}).then(function(r) {

		if (!r.isConfirmed) return;

		$.post(url_base + CONFIG_CONTRATO_URL, { acao: 'restaurar' }, function(res) {

			if (!res || !res.success) {

				Swal.fire('Erro', (res && res.message) ? res.message : 'Falha.', 'error');

				return;

			}

			Swal.fire('OK', res.message, 'success');

			carregarModeloContrato();

		}, 'json');

	});

}



function salvarFraseCertificado() {

	$('#btn-salvar-frase-cert').prop('disabled', true);

	$.post(url_base + CONFIG_CONTRATO_URL, {

		acao: 'salvar_certificado',

		frase_conclusao: $('#certificado_frase_conclusao').val(),

	}, function(res) {

		$('#btn-salvar-frase-cert').prop('disabled', false);

		if (!res || !res.success) {

			Swal.fire('Erro', (res && res.message) ? res.message : 'Falha ao salvar.', 'error');

			return;

		}

		Swal.fire('Salvo', res.message, 'success');

		carregarModeloContrato();

	}, 'json').fail(function() {

		$('#btn-salvar-frase-cert').prop('disabled', false);

		Swal.fire('Erro', 'Falha ao salvar.', 'error');

	});

}



function imprimirPreviewContrato() {

	const frame = document.getElementById('iframe-preview-contrato');

	if (!frame || !frame.contentWindow) return;

	frame.contentWindow.focus();

	frame.contentWindow.print();

}



$(function() {

	carregarModeloContrato();

	$('#btn-salvar-contrato').on('click', salvarModeloContrato);

	$('#btn-restaurar-contrato').on('click', restaurarModeloContrato);

	$('#btn-salvar-frase-cert').on('click', salvarFraseCertificado);

	$('#btn-print-preview-contrato').on('click', imprimirPreviewContrato);

	$('#modelo_contrato_html').on('input', agendarPreviewContrato);

	$('#preview_categoria, #preview_pagamento, #preview_menor').on('change', atualizarPreviewContrato);

});

