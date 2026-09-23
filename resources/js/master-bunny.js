function postBunnyMaster(data) {
	return $.ajax({
		url: url_base + 'master/bunny',
		method: 'POST',
		dataType: 'json',
		data: data
	});
}

function setBadge($el, pronto, ativo) {
	if (pronto) {
		$el.removeClass('bg-secondary bg-warning text-dark').addClass('bg-success').text('Pronto');
	} else if (ativo) {
		$el.removeClass('bg-secondary bg-success').addClass('bg-warning text-dark').text('Incompleto');
	} else {
		$el.removeClass('bg-success bg-warning text-dark').addClass('bg-secondary').text('Desativado');
	}
}

function carregarBunnyMaster() {
	postBunnyMaster({ acao: 'carregar' }).done(function (res) {
		if (!res || !res.success) {
			Swal.fire('Erro', (res && res.message) || 'Falha', 'error');
			return;
		}
		var sqlOk = !!res.coluna_ok;
		if (!sqlOk) {
			$('#alert-sql-bunny').removeClass('d-none').show();
			$('#btn-salvar-bunny, #btn-testar-stream, #btn-testar-storage').prop('disabled', true);
		} else {
			$('#alert-sql-bunny').addClass('d-none').hide();
			$('#btn-salvar-bunny, #btn-testar-stream, #btn-testar-storage').prop('disabled', false);
		}

		$('#stream_ativo').prop('checked', !!res.stream_ativo);
		$('#stream_library_id').val(res.stream_library_id || '');
		$('#stream_cdn_hostname').val(res.stream_cdn_hostname || '');
		if (res.stream_api_salva) {
			$('#stream_api_hint').text('AccessKey salva: ' + (res.stream_api_mask || '********') + '. Cole nova só para trocar.');
		}
		if (res.stream_token_salva) {
			$('#stream_token_hint').text('Token salva: ' + (res.stream_token_mask || '********') + '. Cole nova só para trocar.');
		}
		setBadge($('#badge-stream'), res.stream_pronto, res.stream_ativo);
		if (!res.stream_pronto && res.stream_motivo) {
			$('#stream_api_hint').text(res.stream_motivo);
		}

		$('#storage_ativo').prop('checked', !!res.storage_ativo);
		$('#storage_zone').val(res.storage_zone || '');
		$('#storage_endpoint').val(res.storage_endpoint || 'storage.bunnycdn.com');
		$('#storage_cdn_hostname').val(res.storage_cdn_hostname || '');
		if (res.storage_key_salva) {
			$('#storage_key_hint').text('Access Key salva: ' + (res.storage_key_mask || '********') + '. Cole nova só para trocar.');
		}
		if (res.storage_token_salva) {
			$('#storage_token_hint').text('Token CDN salva: ' + (res.storage_token_mask || '********') + '.');
		}
		setBadge($('#badge-storage'), res.storage_pronto, res.storage_ativo);
		if (!res.storage_pronto && res.storage_motivo) {
			$('#storage_key_hint').text(res.storage_motivo);
		}
	});
}

$(function () {
	carregarBunnyMaster();

	$('#btn-salvar-bunny').on('click', function () {
		postBunnyMaster({
			acao: 'salvar',
			stream_ativo: $('#stream_ativo').is(':checked') ? '1' : '0',
			stream_library_id: $('#stream_library_id').val(),
			stream_cdn_hostname: $('#stream_cdn_hostname').val(),
			stream_api_key: $('#stream_api_key').val() || '',
			stream_token_key: $('#stream_token_key').val() || '',
			storage_ativo: $('#storage_ativo').is(':checked') ? '1' : '0',
			storage_zone: $('#storage_zone').val(),
			storage_endpoint: $('#storage_endpoint').val(),
			storage_cdn_hostname: $('#storage_cdn_hostname').val(),
			storage_access_key: $('#storage_access_key').val() || '',
			storage_token_key: $('#storage_token_key').val() || ''
		}).done(function (res) {
			if (!res || !res.success) {
				Swal.fire('Erro', (res && res.message) || 'Falha', 'error');
				return;
			}
			$('#stream_api_key, #stream_token_key, #storage_access_key, #storage_token_key').val('');
			Swal.fire('OK', res.message, 'success');
			carregarBunnyMaster();
		});
	});

	$('#btn-testar-stream').on('click', function () {
		postBunnyMaster({ acao: 'testar_stream' }).done(function (res) {
			if (!res || !res.success) {
				Swal.fire('Falha', (res && res.message) || 'Não conectou', 'error');
				return;
			}
			Swal.fire('OK', (res.message || 'OK') + (res.name ? ' — ' + res.name : ''), 'success');
		});
	});

	$('#btn-testar-storage').on('click', function () {
		postBunnyMaster({ acao: 'testar_storage' }).done(function (res) {
			if (!res || !res.success) {
				Swal.fire('Falha', (res && res.message) || 'Não conectou', 'error');
				return;
			}
			Swal.fire('OK', res.message || 'OK', 'success');
		});
	});
});
