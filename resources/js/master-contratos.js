function esc(s){
	return $('<div>').text(s == null ? '' : String(s)).html();
}

function badgeStatus(st){
	const map = { vigente: 'success', encerrado: 'secondary', cancelado: 'danger' };
	return '<span class="badge bg-'+(map[st] || 'secondary')+'">'+esc(st)+'</span>';
}

function carregarContratos(){
	if(window.CONTRATOS_TABELA !== '1'){
		$('#lista-contratos').html('<tr><td colspan="7" class="text-center text-muted py-4">Tabela de contratos ainda não existe.</td></tr>');
		return;
	}
	$.post(url_base + 'master/contratos', {
		acao: 'listar',
		q: $('#filtro-q').val() || '',
		status: $('#filtro-status').val() || '',
		produto: $('#filtro-produto').val() || '',
		aceite: $('#filtro-aceite').val() || ''
	}, function(res){
		const $tb = $('#lista-contratos').empty();
		const lista = (res && res.contratos) || [];
		if(!lista.length){
			$tb.append('<tr><td colspan="7" class="text-center text-muted py-4">Nenhum contrato com esses filtros.</td></tr>');
			return;
		}
		lista.forEach(function(c){
			let acao = '<button type="button" class="btn btn-sm btn-outline-primary me-1 btn-ver-contrato" data-id="'+c.id+'">Ver</button>';
			if (c.status === 'vigente') {
				acao += '<button type="button" class="btn btn-sm btn-outline-danger btn-cancelar-contrato" data-id="'+c.id+'">Cancelar</button>';
			}
			$tb.append(
				'<tr>'
				+'<td>'+esc(c.cliente_nome || ('#'+c.id_admin))+'</td>'
				+'<td><strong>'+esc(c.produto_label)+'</strong><br><span class="text-muted small">'+esc(c.plano_nome)+'</span></td>'
				+'<td>R$ '+esc(c.valor_br)+'<br><span class="text-muted small">'+esc(c.qtd_parcelas)+'x · '+esc(c.duracao_meses)+' meses</span></td>'
				+'<td>'+esc(c.inicio_br || c.inicio)+'<br><span class="text-muted small">até '+esc(c.fim_br || c.fim || '—')+'</span></td>'
				+'<td>'+badgeStatus(c.status)+'</td>'
				+'<td>'+(c.aceito ? ('Aceito<br><span class="text-muted small">'+esc(c.aceito_em_br || c.aceito_em)+'</span>') : (c.status==='vigente' ? 'Pendente' : '—'))+'</td>'
				+'<td class="text-end">'+acao+'</td>'
				+'</tr>'
			);
		});
	}, 'json').fail(function(){
		$('#lista-contratos').html('<tr><td colspan="7" class="text-center text-danger py-4">Falha ao carregar.</td></tr>');
	});
}

$(function(){
	const $prod = $('#filtro-produto');
	(window.CONTRATOS_PRODUTOS || []).forEach(function(p){
		$prod.append('<option value="'+esc(p.slug)+'">'+esc(p.label)+'</option>');
	});
	$('#btn-filtrar-contratos').on('click', carregarContratos);
	$('#filtro-q').on('keydown', function(e){
		if(e.key === 'Enter') carregarContratos();
	});
	$('#filtro-status, #filtro-produto, #filtro-aceite').on('change', carregarContratos);
	$(document).on('click', '.btn-ver-contrato', function(){
		const id = $(this).data('id');
		$.post(url_base + 'master/contratos', { acao: 'visualizar', id: id }, function(res){
			if(!res || !res.success){
				Swal.fire('Erro', (res && res.message) || 'Não foi possível abrir o contrato.', 'error');
				return;
			}
			$('#corpo-ver-contrato').html(res.html || '<p>Contrato sem texto.</p>');
			$('#modalVerContrato').modal('show');
		}, 'json');
	});
	$(document).on('click', '.btn-cancelar-contrato', function(){
		const id = $(this).data('id');
		Swal.fire({
			title: 'Cancelar contrato?',
			text: 'O acesso do produto deixa de valer e as parcelas em aberto são canceladas. O preço gravado permanece no histórico.',
			icon: 'warning',
			showCancelButton: true,
			confirmButtonText: 'Cancelar contrato',
			cancelButtonText: 'Voltar'
		}).then(function(r){
			if(!r.isConfirmed) return;
			$.post(url_base + 'master/contratos', { acao: 'cancelar', id: id }, function(res){
				if(!res || !res.success){
					Swal.fire('Erro', (res && res.message) || 'Falha.', 'error');
					return;
				}
				Swal.fire('OK', res.message, 'success');
				carregarContratos();
			}, 'json');
		});
	});
	carregarContratos();
});
