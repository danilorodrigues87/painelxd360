const MASTER_ASSINATURAS_URL = 'master/assinaturas';
const pixCache = {};
const pixQrCache = {};

function compBr(c){
	const s = String(c || '');
	const m = s.match(/^(\d{4})-(\d{2})/);
	return m ? (m[2]+'/'+m[1]) : (s || '—');
}

function esc(s){
	return $('<div>').text(s == null ? '' : String(s)).html();
}

function badgeStatus(st){
	const map = {
		aberta: 'warning',
		pago: 'success',
		vencida: 'danger',
		cancelada: 'secondary'
	};
	const cls = map[st] || 'secondary';
	return '<span class="badge bg-'+cls+'">'+esc(st)+'</span>';
}

function popularEscolas(){
	const $sel = $('#filtro_escola');
	const cur = $sel.val();
	$sel.find('option:not(:first)').remove();
	(window.MASTER_ESCOLAS_SAAS || []).forEach(function(e){
		$sel.append('<option value="'+e.id+'">'+esc(e.nome)+'</option>');
	});
	if(cur) $sel.val(cur);
}

function renderLista(faturas){
	const $tb = $('#lista-faturas-saas').empty();
	if(!faturas || !faturas.length){
		$tb.append('<tr><td colspan="6" class="text-center text-muted py-4">Nenhuma fatura.</td></tr>');
		return;
	}
	faturas.forEach(function(f){
		pixCache[f.id] = f.pix_copia_cola || '';
		pixQrCache[f.id] = f.pix_qr_src || '';
		const acoes = [];
		if(f.tem_pix){
			acoes.push('<button type="button" class="btn btn-sm btn-outline-success me-1 btn-ver-pix" data-id="'+f.id+'" data-info="'+esc(f.escola_nome+' · '+f.competencia+' · R$ '+f.valor_br)+'"><i class="fas fa-qrcode"></i></button>');
		}
		if(f.status !== 'pago'){
			acoes.push('<button type="button" class="btn btn-sm btn-outline-primary me-1 btn-reenviar-pix" data-id="'+f.id+'" title="Gerar/atualizar PIX"><i class="fas fa-sync"></i></button>');
			acoes.push('<button type="button" class="btn btn-sm btn-outline-secondary me-1 btn-reenviar-email" data-id="'+f.id+'" title="Reenviar e-mail"><i class="fas fa-envelope"></i></button>');
			acoes.push('<button type="button" class="btn btn-sm btn-outline-dark btn-marcar-paga" data-id="'+f.id+'" title="Marcar paga manual"><i class="fas fa-check"></i></button>');
		}
		$tb.append(
			'<tr>'
			+'<td><strong>'+esc(f.escola_nome)+'</strong><br><small class="text-muted">#'+f.id_admin+'</small></td>'
			+'<td>'+esc(compBr(f.competencia))+'</td>'
			+'<td>R$ '+esc(f.valor_br)+'</td>'
			+'<td>'+esc(f.vencimento_br || f.vencimento)+'</td>'
			+'<td>'+badgeStatus(f.status)+'</td>'
			+'<td class="text-end">'+acoes.join('')+'</td>'
			+'</tr>'
		);
	});
}

function renderDashboard(d){
	d = d || {};
	$('#dash-ativas').text(d.escolas_ativas != null ? d.escolas_ativas : '—');
	$('#dash-trial').text(d.escolas_trial != null ? d.escolas_trial : '—');
	$('#dash-suspensas').text(d.escolas_suspensas != null ? d.escolas_suspensas : '—');
	$('#dash-abertas').text(d.faturas_abertas != null ? d.faturas_abertas : '—');
	$('#dash-vencidas').text(d.faturas_vencidas != null ? d.faturas_vencidas : '—');
	$('#dash-receita').text(d.receita_mes_br != null ? ('R$ '+d.receita_mes_br) : '—');
}

function carregar(){
	$.post(url_base + MASTER_ASSINATURAS_URL, {
		acao: 'listar',
		competencia: $('#filtro_competencia').val() || '',
		id_admin: $('#filtro_escola').val() || '',
		status: $('#filtro_status').val() || ''
	}, function(res){
		if(!res || !res.success){
			Swal.fire('Erro', (res && res.message) || 'Falha.', 'error');
			return;
		}
		renderDashboard(res.dashboard);
		renderLista(res.faturas || []);
	}, 'json');
}

function gerarMes(){
	const comp = $('#filtro_competencia').val() || '';
	Swal.fire({
		title: 'Gerar faturas?',
		text: 'Competência '+comp+' para clientes cobráveis (com valor; fora de trial).',
		icon: 'question',
		showCancelButton: true,
		confirmButtonText: 'Gerar'
	}).then(function(r){
		if(!r.isConfirmed) return;
		$.post(url_base + MASTER_ASSINATURAS_URL, { acao: 'gerar_mes', competencia: comp }, function(res){
			if(!res || !res.success){
				Swal.fire('Erro', (res && res.message) || 'Falha.', 'error');
				return;
			}
			let extra = '';
			if(res.erros && res.erros.length){
				extra = '<br><small class="text-muted">'+esc(res.erros.slice(0,5).join(' | '))+'</small>';
			}
			Swal.fire({ title: 'OK', html: esc(res.message)+extra, icon: 'success' });
			carregar();
		}, 'json');
	});
}

function gerarEscola(){
	const id = $('#filtro_escola').val();
	if(!id){
		Swal.fire('Atenção', 'Selecione um cliente no filtro.', 'warning');
		return;
	}
	$.post(url_base + MASTER_ASSINATURAS_URL, {
		acao: 'gerar',
		id_admin: id,
		competencia: $('#filtro_competencia').val() || ''
	}, function(res){
		if(!res || !res.success){
			Swal.fire('Erro', (res && res.message) || 'Falha.', 'error');
			return;
		}
		Swal.fire('OK', res.message, 'success');
		carregar();
	}, 'json');
}

function processar(){
	$.post(url_base + MASTER_ASSINATURAS_URL, {
		acao: 'processar',
		id_admin: $('#filtro_escola').val() || ''
	}, function(res){
		if(!res || !res.success){
			Swal.fire('Erro', (res && res.message) || 'Falha.', 'error');
			return;
		}
		const r = res.resumo || {};
		Swal.fire({
			title: 'Worker',
			html: 'Geradas/atualizadas: '+(r.geradas||0)
				+'<br>E-mails: '+(r.emails||0)
				+'<br>Em trial (puladas): '+(r.trials||0)
				+'<br>Suspensas: '+(r.suspensas||0)
				+(r.mp_ok ? '' : '<br><span class="text-danger">Mercado Pago não configurado</span>'),
			icon: 'info'
		});
		carregar();
	}, 'json');
}

$(function(){
	popularEscolas();
	if(window.MASTER_WEBHOOK_SAAS){
		$('#webhook-url').text(window.MASTER_WEBHOOK_SAAS);
		$('#webhook-hint').removeClass('d-none');
	}
	carregar();
	$('#btn-filtrar').on('click', carregar);
	$('#btn-gerar-mes').on('click', gerarMes);
	$('#btn-gerar-escola').on('click', gerarEscola);
	$('#btn-processar').on('click', processar);

	$(document).on('click', '.btn-ver-pix', function(){
		const id = $(this).data('id');
		const pix = pixCache[id] || '';
		const qr = pixQrCache[id] || (pix
			? ('https://api.qrserver.com/v1/create-qr-code/?size=220x220&ecc=M&margin=8&data='+encodeURIComponent(pix))
			: '');
		$('#pix-saas-info').text($(this).data('info') || '');
		$('#pix-saas-copia').val(pix);
		if(qr){
			$('#pix-saas-qr').attr('src', qr).removeClass('d-none');
		} else {
			$('#pix-saas-qr').addClass('d-none').attr('src', '');
		}
		$('#modalPixSaas').modal('show');
	});
	$('#btn-copiar-pix').on('click', function(){
		const t = document.getElementById('pix-saas-copia');
		t.select();
		document.execCommand('copy');
		Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Copiado', showConfirmButton: false, timer: 1500 });
	});
	$(document).on('click', '.btn-reenviar-pix', function(){
		const id = $(this).data('id');
		$.post(url_base + MASTER_ASSINATURAS_URL, { acao: 'reenviar_pix', id: id }, function(res){
			if(!res || !res.success){
				Swal.fire('Erro', (res && res.message) || 'Falha.', 'error');
				return;
			}
			Swal.fire('OK', res.message, 'success');
			carregar();
		}, 'json');
	});
	$(document).on('click', '.btn-reenviar-email', function(){
		const id = $(this).data('id');
		$.post(url_base + MASTER_ASSINATURAS_URL, { acao: 'reenviar_email', id: id }, function(res){
			if(!res || !res.success){
				Swal.fire('Erro', (res && res.message) || 'Falha.', 'error');
				return;
			}
			Swal.fire('OK', res.message, 'success');
			carregar();
		}, 'json');
	});
	$(document).on('click', '.btn-marcar-paga', function(){
		const id = $(this).data('id');
		Swal.fire({ title: 'Marcar como paga?', icon: 'question', showCancelButton: true, confirmButtonText: 'Sim' })
			.then(function(r){
				if(!r.isConfirmed) return;
				$.post(url_base + MASTER_ASSINATURAS_URL, { acao: 'marcar_paga', id: id }, function(res){
					if(!res || !res.success){
						Swal.fire('Erro', (res && res.message) || 'Falha.', 'error');
						return;
					}
					carregar();
				}, 'json');
			});
	});
});
