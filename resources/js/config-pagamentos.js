const CONFIG_PAGAMENTOS_URL = 'painel/config/pagamentos';

function postPagamentos(data){
	return $.ajax({
		url: url_base + CONFIG_PAGAMENTOS_URL,
		method: 'POST',
		dataType: 'json',
		data: data
	});
}

function atualizarBadge(pixPronto, ativo){
	const $b = $('#badge-mp-status');
	if(pixPronto){
		$b.removeClass('bg-secondary bg-warning').addClass('bg-success').text('PIX pronto');
	} else if(ativo){
		$b.removeClass('bg-secondary bg-success').addClass('bg-warning').text('Ativo sem token');
	} else {
		$b.removeClass('bg-success bg-warning').addClass('bg-secondary').text('Desativado');
	}
}

function carregarPagamentos(){
	postPagamentos({ acao: 'carregar' }).done(function(res){
		if(!res || !res.success){
			Swal.fire('Erro', (res && res.message) || 'Falha ao carregar.', 'error');
			return;
		}
		if(!res.coluna_ok){
			$('#alert-sql-mp').removeClass('d-none');
			$('#btn-salvar-mp, #btn-testar-mp, #btn-regen-token').prop('disabled', true);
		} else {
			$('#alert-sql-mp').addClass('d-none');
			$('#btn-salvar-mp, #btn-testar-mp, #btn-regen-token').prop('disabled', false);
		}
		$('#mp_ativo').prop('checked', !!res.mp_ativo);
		$('#mp_webhook_url').val(res.webhook_url || '');
		if(res.token_salvo){
			$('#mp_token_hint').text('Token salvo: ' + (res.token_mask || '********') + '. Cole um novo só se for trocar.');
		}
		atualizarBadge(!!res.pix_pronto, !!res.mp_ativo);
	}).fail(function(){
		Swal.fire('Erro', 'Falha ao carregar pagamentos.', 'error');
	});
}

function salvarPagamentos(){
	$('#btn-salvar-mp').prop('disabled', true);
	postPagamentos({
		acao: 'salvar',
		mp_ativo: $('#mp_ativo').is(':checked') ? '1' : '0',
		mp_access_token: $('#mp_access_token').val() || '',
		mp_webhook_secret: $('#mp_webhook_secret').val() || ''
	}).done(function(res){
		$('#btn-salvar-mp').prop('disabled', false);
		if(!res || !res.success){
			Swal.fire('Erro', (res && res.message) || 'Não foi possível salvar.', 'error');
			return;
		}
		$('#mp_access_token, #mp_webhook_secret').val('');
		if(res.webhook_url) $('#mp_webhook_url').val(res.webhook_url);
		atualizarBadge(!!res.pix_pronto, $('#mp_ativo').is(':checked'));
		Swal.fire('OK', res.message, 'success');
		carregarPagamentos();
	}).fail(function(){
		$('#btn-salvar-mp').prop('disabled', false);
		Swal.fire('Erro', 'Falha ao salvar.', 'error');
	});
}

function testarPagamentos(){
	$('#btn-testar-mp').prop('disabled', true);
	postPagamentos({
		acao: 'testar',
		mp_access_token: $('#mp_access_token').val() || ''
	}).done(function(res){
		$('#btn-testar-mp').prop('disabled', false);
		Swal.fire(res && res.success ? 'OK' : 'Erro', (res && res.message) || 'Falha.', res && res.success ? 'success' : 'error');
	}).fail(function(){
		$('#btn-testar-mp').prop('disabled', false);
		Swal.fire('Erro', 'Falha no teste.', 'error');
	});
}

$(function(){
	carregarPagamentos();
	$('#btn-salvar-mp').on('click', salvarPagamentos);
	$('#btn-testar-mp').on('click', testarPagamentos);
	$('#btn-copiar-webhook').on('click', function(){
		const v = $('#mp_webhook_url').val();
		if(!v) return;
		if(navigator.clipboard && navigator.clipboard.writeText){
			navigator.clipboard.writeText(v).then(function(){
				Swal.fire({toast:true, position:'top-end', timer:1500, showConfirmButton:false, icon:'success', title:'URL copiada'});
			});
		} else {
			$('#mp_webhook_url').select();
			document.execCommand('copy');
		}
	});
	$('#btn-regen-token').on('click', function(){
		Swal.fire({
			title: 'Gerar novo token?',
			text: 'A URL antiga no Mercado Pago deixará de funcionar.',
			icon: 'warning',
			showCancelButton: true,
			confirmButtonText: 'Sim, gerar'
		}).then(function(r){
			if(!r.isConfirmed) return;
			postPagamentos({ acao: 'regenerar_token' }).done(function(res){
				if(!res || !res.success){
					Swal.fire('Erro', (res && res.message) || 'Falha.', 'error');
					return;
				}
				$('#mp_webhook_url').val(res.webhook_url || '');
				Swal.fire('OK', res.message, 'success');
			});
		});
	});
});
