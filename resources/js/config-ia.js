function postIa(data) {
	return $.ajax({
		url: url_base + 'painel/config/ia',
		method: 'POST',
		dataType: 'json',
		data: data
	});
}

function preencherTelegramNativo(tn) {
	if (!tn) {
		return;
	}
	$('#tg-webhook-url').text(tn.webhook_url || '—');
	if (tn.historico_ok === false) {
		$('#alert-tg-historico-sql').removeClass('d-none');
	} else {
		$('#alert-tg-historico-sql').addClass('d-none');
	}
	var hint = '';
	if (tn.https_ok) {
		hint = tn.webhook_url_ativa
			? 'Webhook ativo no Telegram.'
			: 'Produção HTTPS: clique em Ativar webhook após salvar token + Chat ID.';
	} else {
		hint = 'URL local sem HTTPS. Use o worker: php worker/telegram_agent.php (ou túnel HTTPS).';
	}
	if (tn.gate_ok === false && tn.gate_message) {
		hint += ' Pendente: ' + tn.gate_message;
	}
	$('#tg-webhook-hint').text(hint);
	$('#st-nativo').text(tn.pronto || tn.gate_ok ? 'pronto' : 'incompleto');
}

function carregarIa() {
	postIa({ acao: 'carregar' }).done(function (res) {
		if (!res || !res.success) {
			Swal.fire('Erro', (res && res.message) || 'Falha', 'error');
			return;
		}
		if (!res.coluna_ok) {
			$('#alert-sql-ia').removeClass('d-none');
			$('#btn-salvar-ia').prop('disabled', true);
		} else {
			$('#alert-sql-ia').addClass('d-none');
			$('#btn-salvar-ia').prop('disabled', false);
		}

		$('#ai_provider').val(res.ai_provider || '');
		$('#ai_model').val(res.ai_model || '');
		if (res.key_salva) {
			$('#ai_key_hint').text('Chave salva: ' + (res.key_mask || '********') + '. Cole uma nova só para trocar.');
		} else {
			$('#ai_key_hint').text('Deixe em branco para manter a chave já salva.');
		}

		var $bc = $('#badge-credencial');
		if (res.credencial_pronta) {
			$bc.removeClass('bg-secondary bg-warning').addClass('bg-success').text('Pronta');
		} else if (res.key_salva) {
			$bc.removeClass('bg-secondary bg-success').addClass('bg-warning text-dark').text('Sem provedor');
		} else {
			$bc.removeClass('bg-success bg-warning').addClass('bg-secondary').text('Sem chave');
		}

		$('#ai_ativo').prop('checked', !!res.ai_ativo);

		var a = res.assistente || {};
		$('#assistente_ativo').prop('checked', !!a.llm_ativo);
		$('#telegram_ia_ativo').prop('checked', a.telegram_ia_ativo !== false);
		if (a.telegram_ia_coluna_ok === false) {
			$('#telegram_ia_ativo').prop('disabled', true);
			$('#alert-tg-historico-sql').removeClass('d-none');
		} else {
			$('#telegram_ia_ativo').prop('disabled', false);
		}
		$('#telegram_bot_username').val(a.telegram_bot_username || '');
		$('#telegram_chat_id').val(a.telegram_chat_id || '');
		$('#telegram_notas').val(a.telegram_notas || '');
		if (a.telegram_token_salvo) {
			$('#tg_token_hint').text('Token salvo: ' + (a.telegram_token_mask || '********') + '. Cole um novo só para trocar.');
		} else {
			$('#tg_token_hint').text('Deixe em branco para manter o token já salvo. Crie o bot no @BotFather.');
		}
		$('#st-tg').text(a.telegram_pronto ? 'token ok' : (a.telegram_token_salvo ? 'token ok' : 'pendente'));

		preencherTelegramNativo(res.telegram_nativo);

		var $ba = $('#badge-assistente');
		var nativoOk = res.telegram_nativo && (res.telegram_nativo.pronto || res.telegram_nativo.gate_ok);
		if (nativoOk && a.llm_ativo && res.credencial_pronta) {
			$ba.removeClass('bg-secondary bg-warning').addClass('bg-success').text('OK');
		} else if (a.llm_ativo) {
			$ba.removeClass('bg-secondary bg-success').addClass('bg-warning text-dark').text('Parcial');
		} else {
			$ba.removeClass('bg-success bg-warning').addClass('bg-secondary').text('Off');
		}

		if (res.whatsapp_variar_ok === false) {
			$('#alert-variar-sql').removeClass('d-none');
			$('#whatsapp_variar_texto').prop('disabled', true);
		} else {
			$('#alert-variar-sql').addClass('d-none');
			$('#whatsapp_variar_texto').prop('disabled', false);
		}
		$('#whatsapp_variar_texto').prop('checked', !!res.whatsapp_variar_texto);
	});
}

$(function () {
	carregarIa();
	$('#btn-salvar-ia').on('click', function () {
		postIa({
			acao: 'salvar',
			ai_provider: $('#ai_provider').val(),
			ai_model: $('#ai_model').val(),
			ai_api_key: $('#ai_api_key').val() || '',
			ai_ativo: $('#ai_ativo').is(':checked') ? '1' : '0',
			assistente_ativo: $('#assistente_ativo').is(':checked') ? '1' : '0',
			telegram_ia_ativo: $('#telegram_ia_ativo').is(':checked') ? '1' : '0',
			telegram_bot_token: $('#telegram_bot_token').val() || '',
			telegram_bot_username: $('#telegram_bot_username').val() || '',
			telegram_chat_id: $('#telegram_chat_id').val() || '',
			telegram_notas: $('#telegram_notas').val() || '',
			whatsapp_variar_texto: $('#whatsapp_variar_texto').is(':checked') ? '1' : '0'
		}).done(function (res) {
			if (!res || !res.success) {
				Swal.fire('Erro', (res && res.message) || 'Falha', 'error');
				return;
			}
			$('#ai_api_key').val('');
			$('#telegram_bot_token').val('');
			Swal.fire('OK', res.message, 'success');
			carregarIa();
		});
	});

	$('#btn-tg-webhook-on').on('click', function () {
		postIa({ acao: 'telegram_webhook_ativar' }).done(function (res) {
			if (!res || !res.success) {
				Swal.fire('Webhook', (res && res.message) || 'Falha', 'warning');
				return;
			}
			Swal.fire('OK', res.message, 'success');
			carregarIa();
		});
	});
	$('#btn-tg-webhook-off').on('click', function () {
		postIa({ acao: 'telegram_webhook_desativar' }).done(function (res) {
			if (!res || !res.success) {
				Swal.fire('Erro', (res && res.message) || 'Falha', 'error');
				return;
			}
			Swal.fire('OK', res.message, 'success');
			carregarIa();
		});
	});
	$('#btn-tg-testar').on('click', function () {
		postIa({ acao: 'telegram_testar' }).done(function (res) {
			if (!res || !res.success) {
				Swal.fire('Erro', (res && res.message) || 'Falha', 'error');
				return;
			}
			Swal.fire('OK', res.message, 'success');
		});
	});
});
