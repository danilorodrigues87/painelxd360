// Atualiza o resumo do carrinho (card flutuante)
function atualizarWidgetCarrinho() {
	$.ajax({
		url: url_base + rotaCarrinhoResumo,
		method: "post",
		dataType: "json",
		success: function(result){

			$('#carrinho-qtd').text(result.qtd);
			$('#carrinho-total').text('R$ ' + result.total);
			$('#carrinho-itens').html(result.html_itens);

			if(result.qtd > 0){
				$('#caixa-carrinho-widget').removeClass('d-none');
			} else {
				$('#caixa-carrinho-widget').addClass('d-none');
			}
		}
	});
} 

// Adiciona um título (lançamento do caixa) ao carrinho
function addCarrinhoTitulo(id){

	$.ajax({
		url: url_base + rotaCarrinhoAddTitulo,
		method: "post",
		data: {id},
		dataType: "json",
		success: function(result){

			if(result.erro){
				Swal.fire({
					title: "Ops...",
					text: result.erro,
					icon: "error"
				});
			} else {
				Swal.fire({
					title: "Adicionado!",
					text: result.mensagem || "Título adicionado ao carrinho.",
					icon: "success",
					timer: 2200,
					showConfirmButton: false
				});
				atualizarWidgetCarrinho();
			}

		}
	});
}

// Remove item do carrinho
function removerItemCarrinho(id){
	$.ajax({
		url: url_base + rotaCarrinhoRemove,
		method: "post",
		data: {id},
		dataType: "json",
		success: function(result){
			if(result.erro){
				Swal.fire({
					title: "Ops...",
					text: result.erro,
					icon: "error"
				});
			}
			atualizarWidgetCarrinho();
		}
	});
}

// Abre/fecha os detalhes do carrinho
function toggleCarrinhoDetalhes(){
	$('#carrinho-detalhes').toggleClass('d-none');
}

// Form para adicionar item avulso (serviço/produto)
$(document).on("submit", "#form-carrinho-add-avulso", function(event){
	event.preventDefault();

	const form = $(this);

	$.ajax({
		url: url_base + rotaCarrinhoAddAvulso,
		method: "post",
		data: form.serialize(),
		dataType: "json",
		success: function(result){
			if(result.erro){
				$("#response-carrinho-avulso").html('<div class="alert alert-danger mb-1">'+result.erro+'</div>');
			} else {
				form.trigger("reset");
				$("#response-carrinho-avulso").html('<div class="alert alert-success mb-1">Item adicionado ao carrinho.</div>');
				atualizarWidgetCarrinho();
			}
		}
	});
});

function abrirModalCarrinho(){
	const el = document.getElementById('modalCarrinhoPagamento');
	if(!el){
		return;
	}
	if(typeof bootstrap !== 'undefined' && bootstrap.Modal){
		bootstrap.Modal.getOrCreateInstance(el).show();
		return;
	}
	$(el).modal('show');
}

function fecharModalCarrinho(){
	const el = document.getElementById('modalCarrinhoPagamento');
	if(!el){
		return;
	}
	if(typeof bootstrap !== 'undefined' && bootstrap.Modal){
		bootstrap.Modal.getOrCreateInstance(el).hide();
		return;
	}
	$(el).modal('hide');
}

// Abre o modal de pagamento do carrinho
function abrirPagamentoCarrinho(){
	$.ajax({
		url: url_base + rotaCarrinhoForm,
		method: "post",
		dataType: "json",
		success: function(result){
			$('#body_carrinho_pagamento').html(result);
			if (typeof atualizarTotalCarrinhoEncargos === 'function') {
				atualizarTotalCarrinhoEncargos();
			} else if (typeof calcularTrocoCarrinho === 'function') {
				calcularTrocoCarrinho();
			}
			abrirModalCarrinho();
		},
		error: function(){
			Swal.fire({
				title: "Ops...",
				text: "Não foi possível abrir o pagamento do carrinho.",
				icon: "error"
			});
		}
	});
}

// Submit do formulário de pagamento do carrinho
$(document).on("submit", "#form-carrinho", function(event){
	event.preventDefault();

	var formData = $(this).serialize();

	$.ajax({
		url: url_base + rotaCarrinhoFinalizar,
		method: "post",
		data: formData,
		dataType: "json",
		success: function(response){
			if(response.erro){
				$("#response-carrinho").html('<div class="alert alert-danger">'+response.erro+'</div>');
			} else {
				fecharModalCarrinho();
				var ids = Array.isArray(response.recibo_ids) ? response.recibo_ids.filter(function(id){ return id > 0; }) : [];
				var reciboBase = ids.length
					? (url_base + 'painel/carnes/recibo-lote?ids=' + ids.join(','))
					: '';
				var inputOptions = {};
				if (reciboBase) {
					inputOptions['58mm'] = 'Imprimir comprovante (58mm)';
					inputOptions['a4'] = 'Imprimir comprovante (A4)';
					inputOptions['email'] = 'Enviar por e-mail';
					inputOptions['whatsapp'] = 'Enviar por WhatsApp';
				}
				Swal.fire({
					title: "Pagamento registrado!",
					text: "Total recebido: R$ "+response.total,
					icon: "success",
					input: reciboBase ? 'select' : undefined,
					inputOptions: reciboBase ? inputOptions : undefined,
					inputPlaceholder: reciboBase ? 'Escolha uma ação (opcional)' : undefined,
					showCancelButton: true,
					confirmButtonText: reciboBase ? "Executar" : "OK",
					cancelButtonText: "Fechar"
				}).then(function(result){
					if (!reciboBase) return;
					if (!result.isConfirmed) {
						Swal.fire({
							toast: true,
							position: 'top-end',
							icon: 'info',
							title: 'Comprovante disponível',
							text: 'Relatório financeiro ou Carnês → Ver títulos.',
							showConfirmButton: false,
							timer: 5000,
							timerProgressBar: true,
						});
						return;
					}
					var acao = result.value;
					if (!acao) return;
					if (acao === '58mm' || acao === 'a4') {
						var url = reciboBase + (acao === 'a4' ? '&formato=a4' : '&formato=58mm');
						window.open(url, '_blank');
						return;
					}
					if (acao === 'email' || acao === 'whatsapp') {
						$.ajax({
							url: url_base + 'painel/carnes/enviar-recibo-lote',
							method: 'post',
							data: { ids: ids.join(','), canal: acao },
							dataType: 'json',
							success: function(envio){
								if (envio.erro) {
									Swal.fire({ title: 'Ops...', text: envio.erro, icon: 'error' });
								} else {
									Swal.fire({
										title: 'Enviado!',
										text: envio.mensagem || 'Comprovante enviado.',
										icon: 'success'
									});
								}
							},
							error: function(){
								Swal.fire({
									title: 'Ops...',
									text: 'Não foi possível enviar o comprovante.',
									icon: 'error'
								});
							}
						});
					}
				});
				atualizarWidgetCarrinho();
				// Recarrega a listagem atual de carnês
				if(typeof listar === 'function'){
					listar(null,1);
				}
			}
		}
	});
});

// Calcula o troco no formulário do carrinho
function calcularTrocoCarrinho(){
	let valorPagar = parseFloat($('#valor_pagar_total').val() || 0);
	let recebidoStr = $('#valor_recebido_carrinho').val() || '0';

	// Remove tudo que não for número ou vírgula/ponto
	recebidoStr = recebidoStr.replace(/[^\d.,]/g,'').replace(',','.');

	let valorRecebido = parseFloat(recebidoStr || 0);

	let troco = 0;
	if(!isNaN(valorRecebido) && valorRecebido > valorPagar){
		troco = valorRecebido - valorPagar;
	}

	$('#troco_carrinho').val(troco.toFixed(2).replace('.',','));
}

// Ao carregar a página de carnês, atualiza o widget
$(document).ready(function(){
	if(typeof rotaCarrinhoResumo !== 'undefined'){
		atualizarWidgetCarrinho();
	}
});
