const ASSINATURA_ESCOLA_URL = 'painel/assinatura';
let faturaAbertaId = null;
let faturaPagina = 1;

function compBr(c){
	const s = String(c || '');
	const m = s.match(/^(\d{4})-(\d{2})/);
	return m ? (m[2]+'/'+m[1]) : (s || '—');
}

function esc(s){
	return $('<div>').text(s == null ? '' : String(s)).html();
}

function badgeStatus(st){
	const map = { aberta: 'warning', pago: 'success', vencida: 'danger', cancelada: 'secondary' };
	return '<span class="badge bg-'+(map[st]||'secondary')+'">'+esc(st)+'</span>';
}

function renderPaginacaoAjax($el, pag, onPage){
	pag = pag || {};
	const pages = parseInt(pag.pages, 10) || 1;
	const page = parseInt(pag.page, 10) || 1;
	const total = parseInt(pag.total, 10) || 0;
	if(pages <= 1){
		$el.empty();
		return;
	}
	let html = '<ul class="pagination pagination-sm mb-0 justify-content-end">';
	html += '<li class="page-item'+(page <= 1 ? ' disabled' : '')+'"><a class="page-link" href="#" data-p="'+(page-1)+'">«</a></li>';
	for(let i = 1; i <= pages; i++){
		if(pages > 9 && Math.abs(i - page) > 3 && i !== 1 && i !== pages){
			if(i === 2 || i === pages - 1) html += '<li class="page-item disabled"><span class="page-link">…</span></li>';
			continue;
		}
		html += '<li class="page-item'+(i === page ? ' active' : '')+'"><a class="page-link" href="#" data-p="'+i+'">'+i+'</a></li>';
	}
	html += '<li class="page-item'+(page >= pages ? ' disabled' : '')+'"><a class="page-link" href="#" data-p="'+(page+1)+'">»</a></li>';
	html += '</ul>';
	if(total) html = '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2"><small class="text-muted">'+total+' fatura(s)</small>'+html+'</div>';
	$el.html(html);
	$el.find('a.page-link[data-p]').on('click', function(e){
		e.preventDefault();
		const p = parseInt($(this).data('p'), 10);
		if(!p || p < 1 || p > pages || p === page) return;
		onPage(p);
	});
}

function setAberta(f){
	faturaAbertaId = f ? f.id : null;
	if(!f){
		$('#box-sem-aberta').removeClass('d-none');
		$('#box-com-aberta').addClass('d-none');
		$('#badge-fatura-status').attr('class', 'badge bg-secondary').text('—');
		return;
	}
	$('#box-sem-aberta').addClass('d-none');
	$('#box-com-aberta').removeClass('d-none');
	$('#badge-fatura-status').attr('class', 'badge bg-'+(f.status === 'vencida' ? 'danger' : 'warning')).text(f.status);
	$('#fat-competencia').text(compBr(f.competencia));
	$('#fat-valor').text('R$ '+(f.valor_br || '0,00'));
	$('#fat-vencimento').text(f.vencimento_br || f.vencimento || '—');
	const pix = f.pix_copia_cola || '';
	$('#fat-pix').val(pix);
	const qrSrc = f.pix_qr_src || (pix
		? ('https://api.qrserver.com/v1/create-qr-code/?size=220x220&ecc=M&margin=8&data='+encodeURIComponent(pix))
		: '');
	if(qrSrc){
		$('#fat-qr-img').attr('src', qrSrc).removeClass('d-none');
		$('#fat-qr-placeholder').addClass('d-none');
	} else {
		$('#fat-qr-img').addClass('d-none').attr('src', '');
		$('#fat-qr-placeholder').removeClass('d-none');
	}
	$('#btn-copiar-pix-escola').prop('disabled', !pix);
	$('#btn-atualizar-pix').prop('disabled', false);
	$('#btn-gerar-boleto').prop('disabled', false);
	$('#btn-verificar-pag').prop('disabled', !f.mp_payment_id && !pix);
	if(f.boleto_url){
		$('#box-boleto').removeClass('d-none');
		$('#boleto-url').attr('href', f.boleto_url);
		$('#boleto-linha').text(f.boleto_linha || '—');
	} else {
		$('#box-boleto').addClass('d-none');
	}
	montarCartao(f);
}

function renderHistorico(faturas){
	const $tb = $('#lista-faturas-escola').empty();
	if(!faturas || !faturas.length){
		$tb.append('<tr><td colspan="5" class="text-center text-muted py-4">Nenhuma fatura ainda.</td></tr>');
		return;
	}
	faturas.forEach(function(f){
		$tb.append(
			'<tr>'
			+'<td>'+esc(compBr(f.competencia))+'</td>'
			+'<td>R$ '+esc(f.valor_br)+'</td>'
			+'<td>'+esc(f.vencimento_br || f.vencimento)+'</td>'
			+'<td>'+badgeStatus(f.status)+'</td>'
			+'<td>'+esc(f.pago_em_br || f.pago_em || '—')+'</td>'
			+'</tr>'
		);
	});
}

function carregar(page){
	if(page) faturaPagina = page;
	$.post(url_base + ASSINATURA_ESCOLA_URL, { acao: 'carregar', page: faturaPagina }, function(res){
		if(!res || !res.success){
			Swal.fire('Erro', (res && res.message) || 'Falha ao carregar.', 'error');
			return;
		}
		if(!res.tabela_ok){
			$('#alert-assinatura').removeClass('d-none').text(res.message || 'Assinatura indisponível.');
			$('#lista-faturas-escola').html('<tr><td colspan="5" class="text-center text-muted py-4">—</td></tr>');
			$('#paginacao-faturas').empty();
			setAberta(null);
			return;
		}

		const r = res.resumo || {};
		const contratos = r.contratos || [];
		if(contratos.length){
			const vig = contratos.filter(function(c){ return c.status === 'vigente'; });
			$('#ass-plano-nome').text(vig.map(function(c){ return c.plano_nome; }).join(' · ') || r.plano_nome || '—');
			const soma = vig.reduce(function(a,c){ return a + (parseFloat(c.valor_parcela)||0); }, 0);
			$('#ass-valor-mensal').text(soma ? ('R$ '+soma.toFixed(2).replace('.', ',')+' / parcela') : '—');
		} else {
		$('#ass-plano-nome').text(r.plano_nome || 'Personalizado / sem plano');
		$('#ass-valor-mensal').text(r.valor_mensal_br ? ('R$ '+r.valor_mensal_br) : '—');
		}
		window.MP_PUBLIC_KEY = r.mp_public_key || '';
		$('#ass-dia-venc').text(r.dia_vencimento || '—');

		if(r.em_trial){
			$('#alert-assinatura').removeClass('d-none alert-warning').addClass('alert-info')
				.text('Você está em período de trial'+(r.trial_ate_br || r.trial_ate ? (' até '+(r.trial_ate_br || r.trial_ate)) : '')+'. Cobrança começa após o trial.');
		} else if(r.bloqueada || r.assinatura_status === 'suspensa' || !r.escola_ativa){
			$('#alert-assinatura').removeClass('d-none alert-info').addClass('alert-warning')
				.text('Assinatura suspensa. Regularize o pagamento abaixo para liberar o painel.');
		} else {
			$('#alert-assinatura').addClass('d-none');
		}

		const pend = res.contrato_pendencias || [];
		if (pend.length) {
			$('#alert-contrato-pendencias').removeClass('d-none').html(
				'<strong>Contrato de licença incompleto:</strong> ' + esc(pend.join(', '))
				+ '. O Diretor deve completar o <a href="' + url_base + 'painel/perfil">Perfil</a> (CPF).'
			);
		} else {
			$('#alert-contrato-pendencias').addClass('d-none');
		}

		const soLeitura = !!r.so_leitura;
		$('#btn-atualizar-pix, #btn-verificar-pag, #btn-copiar-pix-escola').toggleClass('d-none', soLeitura);
		if(soLeitura){
			$('#alert-somente-diretor').removeClass('d-none');
		} else {
			$('#alert-somente-diretor').addClass('d-none');
		}

		setAberta(res.aberta || null);
		renderHistorico(res.faturas || []);
		if(res.pagination){
			faturaPagina = res.pagination.page || faturaPagina;
			renderPaginacaoAjax($('#paginacao-faturas'), res.pagination, carregar);
		} else {
			$('#paginacao-faturas').empty();
		}
	}, 'json').fail(function(){
		Swal.fire('Erro', 'Falha de comunicação.', 'error');
	});
}

let cardForm = null;
function montarCartao(f){
	const key = window.MP_PUBLIC_KEY || '';
	if(!key || typeof MercadoPago === 'undefined' || !f){
		$('#form-checkout').addClass('d-none');
		$('#cartao-sem-chave').removeClass('d-none');
		return;
	}
	$('#cartao-sem-chave').addClass('d-none');
	$('#form-checkout').removeClass('d-none');
	if(cardForm) return;
	const mp = new MercadoPago(key, { locale: 'pt-BR' });
	const valorCartao = Number(f.valor || 0);
	if (!(valorCartao > 0)) {
		Swal.fire('Cartão', 'Esta fatura não tem valor para cobrar no cartão.', 'warning');
		return;
	}
	cardForm = mp.cardForm({
		amount: valorCartao.toFixed(2),
		iframe: true,
		form: {
			id: 'form-checkout',
			cardNumber: { id: 'form-checkout__cardNumber', placeholder: 'Número do cartão' },
			expirationDate: { id: 'form-checkout__expirationDate', placeholder: 'MM/AA' },
			securityCode: { id: 'form-checkout__securityCode', placeholder: 'CVV' },
			cardholderName: { id: 'form-checkout__cardholderName', placeholder: 'Nome impresso' },
			issuer: { id: 'form-checkout__issuer', placeholder: 'Banco' },
			installments: { id: 'form-checkout__installments', placeholder: 'Parcelas' },
			identificationType: { id: 'form-checkout__identificationType', placeholder: 'Documento' },
			identificationNumber: { id: 'form-checkout__identificationNumber', placeholder: 'Número' },
			cardholderEmail: { id: 'form-checkout__cardholderEmail', placeholder: 'E-mail' }
		},
		callbacks: {
			onFormMounted: function(error){
				if(error){
					Swal.fire('Cartão', 'Não foi possível abrir o formulário do cartão. Recarregue a página.', 'error');
				}
			},
			onSubmit: function(event){
				event.preventDefault();
				let data;
				try {
					data = cardForm.getCardFormData();
				} catch (e) {
					data = null;
				}
				if(!data || !data.token){
					Swal.fire('Cartão', 'Confira número, validade, CVV, nome, CPF e e-mail. O Mercado Pago não gerou a cobrança.', 'warning');
					return;
				}
				const $btn = $('#btn-pagar-cartao').prop('disabled', true);
				$.post(url_base + ASSINATURA_ESCOLA_URL, {
					acao: 'pagar_cartao',
					id: faturaAbertaId,
					token: data.token,
					payment_method_id: data.paymentMethodId,
					issuer_id: data.issuerId,
					email: data.cardholderEmail,
					doc_type: data.identificationType,
					doc_number: data.identificationNumber
				}, function(res){
					$btn.prop('disabled', false);
					Swal.fire(res && res.success ? 'Pagamento' : 'Atenção', (res && res.message) || 'Falha.', res && res.success ? 'success' : 'warning');
					carregar();
				}, 'json').fail(function(){
					$btn.prop('disabled', false);
					Swal.fire('Erro', 'Falha de comunicação ao cobrar o cartão.', 'error');
				});
			}
		}
	});
}

$(function(){
	carregar();

	$('#btn-copiar-pix-escola').on('click', function(){
		const t = document.getElementById('fat-pix');
		if(!t.value) return;
		t.select();
		document.execCommand('copy');
		if(navigator.clipboard && navigator.clipboard.writeText){
			navigator.clipboard.writeText(t.value);
		}
		Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'PIX copiado', showConfirmButton: false, timer: 1500 });
	});

	$('#btn-atualizar-pix').on('click', function(){
		if(!faturaAbertaId) return;
		const $btn = $(this).prop('disabled', true);
		$.post(url_base + ASSINATURA_ESCOLA_URL, { acao: 'atualizar_pix', id: faturaAbertaId }, function(res){
			$btn.prop('disabled', false);
			if(!res || !res.success){
				Swal.fire('Erro', (res && res.message) || 'Falha.', 'error');
				return;
			}
			Swal.fire('OK', res.message, 'success');
			carregar();
		}, 'json').fail(function(){
			$btn.prop('disabled', false);
			Swal.fire('Erro', 'Falha de comunicação.', 'error');
		});
	});

	$('#btn-gerar-boleto').on('click', function(){
		if(!faturaAbertaId) return;
		const $btn = $(this).prop('disabled', true);
		$.post(url_base + ASSINATURA_ESCOLA_URL, { acao: 'pagar_boleto', id: faturaAbertaId }, function(res){
			$btn.prop('disabled', false);
			if(!res || !res.success){
				Swal.fire('Erro', (res && res.message) || 'Falha.', 'error');
				return;
			}
			Swal.fire('Boleto', res.message, 'success');
			carregar();
		}, 'json').fail(function(){
			$btn.prop('disabled', false);
			Swal.fire('Erro', 'Falha de comunicação.', 'error');
		});
	});

	$('#btn-verificar-pag').on('click', function(){
		if(!faturaAbertaId) return;
		const $btn = $(this).prop('disabled', true);
		$.post(url_base + ASSINATURA_ESCOLA_URL, { acao: 'verificar', id: faturaAbertaId }, function(res){
			$btn.prop('disabled', false);
			if(!res || !res.success){
				Swal.fire('Erro', (res && res.message) || 'Falha.', 'error');
				return;
			}
			Swal.fire(res.fatura && res.fatura.status === 'pago' ? 'Pago' : 'Status', res.message, res.fatura && res.fatura.status === 'pago' ? 'success' : 'info');
			carregar();
		}, 'json').fail(function(){
			$btn.prop('disabled', false);
			Swal.fire('Erro', 'Falha de comunicação.', 'error');
		});
	});
});
