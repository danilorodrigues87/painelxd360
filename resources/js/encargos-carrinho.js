function parseMoedaCarrinho(v) {
	var n = parseFloat(v);
	return isNaN(n) ? 0 : n;
}

function valorLinhaCarrinhoEncargos($linha) {
	var $cb = $linha.find('.cobrar-encargos-item');
	if (!$cb.length) {
		return parseMoedaCarrinho($linha.find('.carrinho-item-valor').data('valor'));
	}
	var comEnc = parseMoedaCarrinho($linha.find('.carrinho-item-valor').data('comEnc'));
	var semEnc = parseMoedaCarrinho($linha.find('.carrinho-item-valor').data('semEnc'));
	return $cb.is(':checked') ? comEnc : semEnc;
}

function formatMoedaCarrinho(n) {
	return n.toFixed(2).replace('.', ',');
}

function atualizarTotalCarrinhoEncargos() {
	var total = 0;
	$('#form-carrinho .carrinho-linha-item').each(function () {
		var v = valorLinhaCarrinhoEncargos($(this));
		total += v;
		var $label = $(this).find('.carrinho-item-valor');
		if ($label.length) {
			$label.text('R$ ' + formatMoedaCarrinho(v));
		}
	});

	$('#valor_pagar_total').val(total.toFixed(2));
	var $totalLabel = $('#carrinho-total-pagar');
	if ($totalLabel.length) {
		$totalLabel.text('R$ ' + formatMoedaCarrinho(total));
	}

	if (typeof calcularTrocoCarrinho === 'function') {
		calcularTrocoCarrinho();
	}
}

$(document).on('change', '.cobrar-encargos-item, #cobrar_encargos_todos', function () {
	if ($(this).attr('id') === 'cobrar_encargos_todos') {
		var checked = $(this).is(':checked');
		$('.cobrar-encargos-item').prop('checked', checked);
	}
	atualizarTotalCarrinhoEncargos();
});

$(document).on('shown.bs.modal', '#modalCarrinhoPagamento', function () {
	atualizarTotalCarrinhoEncargos();
});
