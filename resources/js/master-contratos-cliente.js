function preencherOpcoesContrato(){
	const produtos = window.MASTER_MODULOS || [];
	const $prod = $('#ct_produto').empty();
	if(!produtos.length){
		$prod.append('<option value="">Nenhum produto no catálogo</option>');
	}
	produtos.forEach(function(p){
		$prod.append('<option value="'+p.slug+'">'+p.label+'</option>');
	});
	window.CT_PLANOS = window.MASTER_PLANOS || [];
	filtrarPlanosContrato();
}

function carregarContratosCliente(id){
	preencherOpcoesContrato();
	if(!id){
		$('#lista-contratos-cliente').html('<p class="small text-muted mb-2">Salve o cliente e, em seguida, grave o contrato aqui.</p>');
		return;
	}
	$.post(url_base + MASTER_ESCOLAS_URL, { acao: 'contratos', id: id }, function(res){
		if(res && res.planos && res.planos.length) window.CT_PLANOS = res.planos;
		if(res && res.produtos && res.produtos.length){
			const $prod = $('#ct_produto').empty();
			res.produtos.forEach(function(p){
				$prod.append('<option value="'+p.slug+'">'+p.label+'</option>');
			});
		}
		filtrarPlanosContrato();
		const $box = $('#lista-contratos-cliente').empty();
		const lista = (res && res.contratos) || [];
		if(!lista.length){
			$box.html('<p class="small text-muted mb-2">Nenhum contrato gravado.</p>');
			return;
		}
		lista.forEach(function(c){
			const btn = c.status === 'vigente'
				? ' <button type="button" class="btn btn-link btn-sm p-0 btn-renovar-ct" data-id="'+c.id+'">Renovar</button>'
				: '';
			$box.append('<div class="border rounded p-2 mb-1 small"><strong>'+c.produto_label+'</strong> — '+c.plano_nome
				+' · R$ '+c.valor_br+' · '+c.qtd_parcelas+'x · '+c.duracao_meses+' meses · '+c.status+btn+'</div>');
		});
	}, 'json').fail(function(){
		$('#lista-contratos-cliente').html('<p class="small text-danger mb-2">Não foi possível carregar os contratos. Os planos do catálogo continuam na lista abaixo.</p>');
	});
}

function filtrarPlanosContrato(){
	const prod = $('#ct_produto').val();
	const $pl = $('#ct_plano').empty();
	let n = 0;
	(window.CT_PLANOS || []).forEach(function(p){
		if(!p.produto_slug || p.produto_slug !== prod) return;
		const valor = (p.valor_sugerido != null ? p.valor_sugerido : p.valor_mensal) || 0;
		$pl.append('<option value="'+p.id+'" data-valor="'+valor+'" data-ciclo="'+(p.ciclo||'mensal')+'">'+p.nome+'</option>');
		n++;
	});
	if(!n){
		$pl.append('<option value="">Nenhum plano deste produto</option>');
	} else {
		$('#ct_plano').trigger('change');
	}
}

$(function(){
	preencherOpcoesContrato();
	$('#ct_produto').on('change', filtrarPlanosContrato);
	$('#ct_plano').on('change', function(){
		const $o = $(this).find('option:selected');
		const valor = parseFloat($o.data('valor') || 0);
		if(valor > 0) $('#ct_valor').val(valor.toFixed(2));
		if($o.data('ciclo') === 'anual'){
			$('#ct_duracao').val(12);
			$('#ct_parcelas').val(1);
		} else if($o.val()){
			$('#ct_duracao').val(1);
			$('#ct_parcelas').val(1);
		}
	});
	$('#btn-salvar-contrato').on('click', function(){
		const id = $('#escola_id').val();
		if(!id){
			Swal.fire('Atenção', 'Salve o cliente antes de gravar o contrato.', 'warning');
			return;
		}
		if(!$('#ct_plano').val()){
			Swal.fire('Atenção', 'Este produto ainda não tem plano. Cadastre em Planos.', 'warning');
			return;
		}
		$.post(url_base + MASTER_ESCOLAS_URL, {
			acao: 'salvar_contrato',
			id_admin: id,
			produto_slug: $('#ct_produto').val(),
			plan_id: $('#ct_plano').val(),
			valor_parcela: $('#ct_valor').val(),
			duracao_meses: $('#ct_duracao').val(),
			qtd_parcelas: $('#ct_parcelas').val(),
			inicio: $('#ct_inicio').val(),
			ciclo: $('#ct_plano option:selected').data('ciclo') || 'mensal'
		}, function(res){
			if(!res || !res.success){
				Swal.fire('Erro', (res && res.message) || 'Falha.', 'error');
				return;
			}
			Swal.fire('OK', res.message, 'success');
			carregarContratosCliente(id);
		}, 'json');
	});
	$(document).on('click', '.btn-renovar-ct', function(){
		const contratoId = $(this).data('id');
		const clienteId = $('#escola_id').val();
		$.post(url_base + MASTER_ESCOLAS_URL, { acao: 'preparar_renovacao', contrato_id: contratoId }, function(prep){
			if(!prep || !prep.success){
				Swal.fire('Erro', (prep && prep.message) || 'Falha.', 'error');
				return;
			}
			Swal.fire({
				title: 'Renovar contrato',
				html: '<p class="small">Valor deste contrato: <strong>R$ '+Number(prep.valor_atual).toFixed(2)+'</strong><br>Valor atual do catálogo: <strong>R$ '+Number(prep.valor_catalogo||0).toFixed(2)+'</strong></p>'
					+'<label class="form-label">Valor da nova parcela</label><input id="sw-valor" class="form-control" value="'+prep.valor_atual+'">'
					+'<label class="form-label mt-2">Duração (meses)</label><input id="sw-dur" class="form-control" value="'+(prep.contrato.duracao_meses||1)+'">'
					+'<label class="form-label mt-2">Parcelas</label><input id="sw-parc" class="form-control" value="'+(prep.contrato.qtd_parcelas||1)+'">',
				showCancelButton: true,
				confirmButtonText: 'Renovar',
				preConfirm: function(){
					return {
						valor_parcela: document.getElementById('sw-valor').value,
						duracao_meses: document.getElementById('sw-dur').value,
						qtd_parcelas: document.getElementById('sw-parc').value
					};
				}
			}).then(function(r){
				if(!r.isConfirmed) return;
				$.post(url_base + MASTER_ESCOLAS_URL, {
					acao: 'renovar_contrato',
					contrato_id: contratoId,
					valor_parcela: r.value.valor_parcela,
					duracao_meses: r.value.duracao_meses,
					qtd_parcelas: r.value.qtd_parcelas,
					ciclo: prep.contrato.ciclo
				}, function(res){
					if(!res || !res.success){
						Swal.fire('Erro', (res && res.message) || 'Falha.', 'error');
						return;
					}
					Swal.fire('OK', res.message, 'success');
					carregarContratosCliente(clienteId);
				}, 'json');
			});
		}, 'json');
	});
});
