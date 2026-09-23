/**
 * Ações de comprovante (imprimir / enviar) reutilizáveis no relatório, carnês e extrato.
 * Requer url_base e Swal (SweetAlert2).
 */
(function () {
	function abrirRecibo(id, formato) {
		var fmt = formato === 'a4' ? 'a4' : '58mm';
		var url = url_base + 'painel/carnes/recibo/' + encodeURIComponent(id);
		if (fmt === 'a4') {
			url += '?formato=a4';
		}
		window.open(url, '_blank');
	}

	function abrirReciboLote(ids, formato) {
		if (!ids || !ids.length) return;
		var fmt = formato === 'a4' ? 'a4' : '58mm';
		var url = url_base + 'painel/carnes/recibo-lote?ids=' + ids.join(',') + '&formato=' + fmt;
		window.open(url, '_blank');
	}

	function enviarRecibo(ids, canal) {
		if (!ids || !ids.length) return;
		var lista = Array.isArray(ids) ? ids.join(',') : String(ids);
		$.ajax({
			url: url_base + 'painel/carnes/enviar-recibo-lote',
			method: 'post',
			data: { ids: lista, canal: canal },
			dataType: 'json',
			success: function (envio) {
				if (envio.erro) {
					Swal.fire({ title: 'Ops...', text: envio.erro, icon: 'error' });
				} else {
					Swal.fire({
						title: 'Enviado!',
						text: envio.mensagem || 'Comprovante enviado.',
						icon: 'success',
					});
				}
			},
			error: function () {
				Swal.fire({
					title: 'Ops...',
					text: 'Não foi possível enviar o comprovante.',
					icon: 'error',
				});
			},
		});
	}

	function menuRecibo(id) {
		Swal.fire({
			title: 'Comprovante',
			input: 'select',
			inputOptions: {
				'58mm': 'Imprimir (58mm)',
				a4: 'Imprimir (A4)',
				email: 'Enviar por e-mail',
				whatsapp: 'Enviar por WhatsApp',
			},
			inputPlaceholder: 'Escolha uma ação',
			showCancelButton: true,
			confirmButtonText: 'Executar',
			cancelButtonText: 'Cancelar',
		}).then(function (result) {
			if (!result.isConfirmed || !result.value) return;
			var acao = result.value;
			if (acao === '58mm' || acao === 'a4') {
				abrirRecibo(id, acao);
			} else if (acao === 'email' || acao === 'whatsapp') {
				enviarRecibo([id], acao);
			}
		});
	}

	window.reciboAbrir = abrirRecibo;
	window.reciboAbrirLote = abrirReciboLote;
	window.reciboEnviar = enviarRecibo;
	window.reciboMenu = menuRecibo;

	window.reciboAcoesHtml = function (id, compact) {
		if (!id || id <= 0) return '';
		if (compact) {
			return (
				'<button type="button" class="btn btn-sm btn-outline-secondary" title="Comprovante" onclick="reciboMenu(' +
				id +
				')"><i class="fas fa-receipt"></i></button>'
			);
		}
		return (
			'<div class="btn-group btn-group-sm" role="group">' +
			'<button type="button" class="btn btn-outline-secondary" title="58mm" onclick="reciboAbrir(' +
			id +
			',\'58mm\')"><i class="fas fa-print"></i></button>' +
			'<button type="button" class="btn btn-outline-secondary" title="A4" onclick="reciboAbrir(' +
			id +
			',\'a4\')">A4</button>' +
			'<button type="button" class="btn btn-outline-primary" title="E-mail" onclick="reciboEnviar([' +
			id +
			'],\'email\')"><i class="fas fa-envelope"></i></button>' +
			'<button type="button" class="btn btn-outline-success" title="WhatsApp" onclick="reciboEnviar([' +
			id +
			'],\'whatsapp\')"><i class="fab fa-whatsapp"></i></button>' +
			'</div>'
		);
	};
})();
