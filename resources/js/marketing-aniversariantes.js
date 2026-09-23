const ANIV_URL = 'painel/marketing/aniversariantes';
let anivPeriodo = 'mes';
let anivBuscaTimer = null;

function anivPost(data, cb){
	$.post(url_base + ANIV_URL, data, cb, 'json').fail(function(){
		Swal.fire('Erro', 'Falha na requisição.', 'error');
	});
}

function escAniv(s){
	return $('<div>').text(s == null ? '' : String(s)).html();
}

function idsSelecionadosAniv(){
	const ids = [];
	$('#aniv-corpo input.aniv-check:checked').each(function(){
		const id = parseInt($(this).val(), 10);
		if(id > 0) ids.push(id);
	});
	return ids;
}

function badgeSituacaoAniv(a){
	const parts = [];
	if(a.matricula_ativa){
		parts.push('<span class="badge bg-success">Ativo</span>');
	} else {
		parts.push('<span class="badge bg-secondary">Inativo</span>');
	}
	if(a.inadimplente){
		parts.push('<span class="badge bg-danger">Inadimplente</span>');
	}
	return parts.join(' ');
}

function atualizarUiPeriodoAniv(){
	const ehMes = anivPeriodo === 'mes';
	$('#wrap-aniv-mes').toggleClass('d-none', !ehMes);
	$('#aniv-filtros-periodo button').removeClass('active');
	$('#aniv-filtros-periodo button[data-periodo="'+anivPeriodo+'"]').addClass('active');
}

function carregarAniversariantes(){
	const mes = parseInt($('#aniv-mes').val(), 10) || new Date().getMonth() + 1;
	anivPost({
		acao: 'listar',
		periodo: anivPeriodo,
		mes: mes,
		situacao: $('#aniv-situacao').val() || 'todos',
		busca: ($('#aniv-busca').val() || '').trim()
	}, function(res){
		if(!res || !res.success){
			$('#aniv-corpo').html('<tr><td colspan="8" class="text-danger p-3">'+
				escAniv((res && res.message) || 'Falha ao carregar.')+'</td></tr>');
			return;
		}
		$('#aniv-total').text((res.total || 0)+' aniversariante(s)');
		const $body = $('#aniv-corpo').empty();
		const lista = res.lista || [];
		if(!lista.length){
			$body.append('<tr><td colspan="8" class="text-muted p-3">Nenhum aniversariante neste filtro.</td></tr>');
			$('#aniv-check-all').prop('checked', false);
			return;
		}
		lista.forEach(function(a){
			const env = a.enviado_ano
				? '<span class="badge bg-info text-dark">Sim</span>'
				: '<span class="text-muted">Não</span>';
			$body.append(
				'<tr>'
				+'<td><input type="checkbox" class="form-check-input aniv-check" value="'+a.id+'"></td>'
				+'<td>'+escAniv(a.nome)+'</td>'
				+'<td>'+escAniv(a.nascimento_fmt)+'</td>'
				+'<td>'+(a.idade != null ? escAniv(a.idade) : '—')+'</td>'
				+'<td class="small">'+escAniv(a.email || '—')+'</td>'
				+'<td class="small">'+escAniv(a.whatsapp || '—')+'</td>'
				+'<td>'+badgeSituacaoAniv(a)+'</td>'
				+'<td>'+env+'</td>'
				+'</tr>'
			);
		});
		$('#aniv-check-all').prop('checked', false);
	});
}

function criarCampanhaAniv(){
	let segmento = 'aniversariantes_mes';
	if(anivPeriodo === 'hoje'){
		segmento = 'aniversariantes_dia';
	}
	const params = new URLSearchParams({
		segmento: segmento,
		titulo: 'Aniversariantes'
	});
	const situacao = $('#aniv-situacao').val() || 'todos';
	if(situacao && situacao !== 'todos'){
		params.set('aniv_situacao', situacao);
	}
	window.location.href = url_base + 'painel/campanhas?' + params.toString();
}

$(function(){
	const mesAtual = new Date().getMonth() + 1;
	$('#aniv-mes').val(String(mesAtual));
	atualizarUiPeriodoAniv();
	carregarAniversariantes();

	$('#aniv-filtros-periodo').on('click', 'button[data-periodo]', function(){
		anivPeriodo = $(this).data('periodo') || 'mes';
		atualizarUiPeriodoAniv();
		carregarAniversariantes();
	});
	$('#aniv-mes, #aniv-situacao').on('change', carregarAniversariantes);
	$('#aniv-busca').on('input', function(){
		clearTimeout(anivBuscaTimer);
		anivBuscaTimer = setTimeout(carregarAniversariantes, 350);
	});
	$('#btn-aniv-atualizar').on('click', carregarAniversariantes);

	$('#aniv-check-all').on('change', function(){
		const ck = $(this).prop('checked');
		$('#aniv-corpo .aniv-check').prop('checked', ck);
	});

	$('#btn-aniv-campanha').on('click', criarCampanhaAniv);
});
