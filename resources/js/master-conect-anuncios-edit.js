$(function(){
	function esc(s){
		return $('<div>').text(s == null ? '' : String(s)).html();
	}

	function atualizarDimensaoSlot(){
		const $opt = $('#anuncio_slot option:selected');
		const sug = $opt.data('sugestao') || '728×90 px';
		const hint = $opt.data('hint') || '';
		$('#slot_dim_sugestao').text(sug);
		$('#slot_dim_hint').text(hint);
	}

	function popularEstados(selected){
		const $sel = $('#anuncio_estado');
		$sel.find('option:not(:first)').remove();
		(window.MASTER_ESTADOS || []).forEach(function(e){
			const label = e.sigla ? (e.sigla + ' — ' + e.nome) : e.nome;
			$sel.append('<option value="'+e.id+'">'+esc(label)+'</option>');
		});
		if(selected) $sel.val(String(selected));
	}

	function carregarCidades(estadoId, cidadeSelected){
		const $cid = $('#anuncio_cidade');
		if(!estadoId){
			$cid.empty().append('<option value="">Selecione o estado</option>').prop('disabled', true);
			return;
		}
		$cid.prop('disabled', false).empty().append('<option value="">Todo o estado</option>');
		$.post(url_base + 'master/conect-anuncios/cidades', { estado: estadoId }, function(res){
			((res && res.cidades) || []).forEach(function(c){
				$cid.append('<option value="'+c.id+'">'+esc(c.nome)+'</option>');
			});
			if(cidadeSelected) $cid.val(String(cidadeSelected));
		}, 'json').fail(function(){
			$cid.empty().append('<option value="">Falha ao carregar cidades</option>');
		});
	}

	popularEstados(window.ANUNCIO_ESTADO_ID || '');
	if(window.ANUNCIO_ESTADO_ID){
		carregarCidades(window.ANUNCIO_ESTADO_ID, window.ANUNCIO_CIDADE_ID || '');
	}
	atualizarDimensaoSlot();

	$('#anuncio_slot').on('change', atualizarDimensaoSlot);
	$('#anuncio_estado').on('change', function(){
		carregarCidades($(this).val(), '');
	});

	$('#form-anuncio').on('submit', function(e){
		e.preventDefault();
		const fd = new FormData(this);
		$.ajax({
			url: url_base + 'master/conect-anuncios/salvar',
			method: 'POST',
			data: fd,
			processData: false,
			contentType: false,
			dataType: 'json'
		}).done(function(res){
			if(res && res.success){
				Swal.fire('OK', res.message, 'success').then(function(){
					if(res.redirect) window.location.href = res.redirect;
				});
			} else {
				Swal.fire('Erro', (res && res.message) ? res.message : 'Falha ao salvar.', 'error');
			}
		}).fail(function(xhr){
			let msg = 'Falha na comunicação.';
			if(xhr.responseJSON && xhr.responseJSON.message) msg = xhr.responseJSON.message;
			Swal.fire('Erro', msg, 'error');
		});
	});
});
