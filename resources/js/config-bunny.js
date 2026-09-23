function postBunny(data) {
	return $.ajax({
		url: url_base + 'painel/config/bunny',
		method: 'POST',
		dataType: 'json',
		data: data
	});
}

function carregarBunny() {
	postBunny({ acao: 'carregar' }).done(function (res) {
		if (!res || !res.success) {
			Swal.fire('Erro', (res && res.message) || 'Falha', 'error');
			return;
		}
		if (!res.coluna_ok) {
			$('#alert-sql-bunny').removeClass('d-none');
			$('#btn-salvar-bunny, #btn-testar-bunny').prop('disabled', true);
		} else {
			$('#alert-sql-bunny').addClass('d-none');
			$('#btn-salvar-bunny, #btn-testar-bunny').prop('disabled', false);
		}
		$('#bunny_ativo').prop('checked', !!res.bunny_ativo);
		$('#bunny_library_id').val(res.bunny_library_id || '');
		$('#bunny_cdn_hostname').val(res.bunny_cdn_hostname || '');
		if (res.api_key_salva) {
			$('#bunny_api_hint').text('AccessKey salva: ' + (res.api_key_mask || '********') + '. Cole uma nova só para trocar.');
		}
		if (res.token_key_salva) {
			$('#bunny_token_hint').text('Token Key salva: ' + (res.token_key_mask || '********') + '. Cole uma nova só para trocar.');
		}
		var $b = $('#badge-bunny-status');
		if (res.bunny_pronto) {
			$b.removeClass('bg-secondary bg-warning').addClass('bg-success').text('Pronto');
		} else if (res.bunny_ativo) {
			$b.removeClass('bg-secondary bg-success').addClass('bg-warning text-dark').text('Incompleto');
		} else {
			$b.removeClass('bg-success bg-warning').addClass('bg-secondary').text('Desativado');
		}
	});
}

$(function () {
	carregarBunny();
	$('#btn-salvar-bunny').on('click', function () {
		postBunny({
			acao: 'salvar',
			bunny_ativo: $('#bunny_ativo').is(':checked') ? '1' : '0',
			bunny_library_id: $('#bunny_library_id').val(),
			bunny_cdn_hostname: $('#bunny_cdn_hostname').val(),
			bunny_api_key: $('#bunny_api_key').val() || '',
			bunny_token_key: $('#bunny_token_key').val() || ''
		}).done(function (res) {
			if (!res || !res.success) {
				Swal.fire('Erro', (res && res.message) || 'Falha', 'error');
				return;
			}
			$('#bunny_api_key').val('');
			$('#bunny_token_key').val('');
			Swal.fire('OK', res.message, 'success');
			carregarBunny();
		});
	});
	$('#btn-testar-bunny').on('click', function () {
		postBunny({ acao: 'testar' }).done(function (res) {
			if (!res || !res.success) {
				Swal.fire('Falha', (res && res.message) || 'Não conectou', 'error');
				return;
			}
			Swal.fire('OK', (res.message || 'Conexão OK') + (res.name ? ' — ' + res.name : ''), 'success');
		});
	});
});
