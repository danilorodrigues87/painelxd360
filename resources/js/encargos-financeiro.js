function moedaEncargos(n) {
	var v = parseFloat(n);
	if (isNaN(v)) return '0,00';
	return v.toFixed(2).replace('.', ',');
}

function atualizarTotalEncargosPagamento() {
	var $cb = $('#cobrar_encargos');
	if (!$cb.length) return;

	var face = parseFloat($('#enc_valor_face').val()) || 0;
	var multa = parseFloat($('#enc_valor_multa').val()) || 0;
	var juros = parseFloat($('#enc_valor_juros').val()) || 0;
	var comEnc = parseFloat($('#enc_total_com_encargos').val()) || face + multa + juros;
	var total = $cb.is(':checked') ? comEnc : face;

	$('#valor_pagar').val(total.toFixed(2));
	if ($('#bx-valor').length) {
		$('#bx-valor').val(total.toFixed(2));
	}
	if ($('#enc_total_label').length) {
		$('#enc_total_label').text('R$ ' + moedaEncargos(total));
	}
	if (typeof calcularTroco === 'function') {
		calcularTroco();
	}
}

$(document).on('change', '#cobrar_encargos', atualizarTotalEncargosPagamento);

$(document).on('shown.bs.modal', '#formModal, #modalDarBaixa', function () {
	atualizarTotalEncargosPagamento();
});
