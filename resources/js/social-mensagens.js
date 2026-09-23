const META_MSG_URL = 'painel/social/mensagens';

let metaConversaId = null;
let metaFiltro = 'todas';
let metaPoll = null;
let metaUltimaMsgId = null;
let metaCarregandoMsg = false;
let metaBuscaTimer = null;
let metaConversaStatus = '';

function metaPost(data, cb, silentFail){
	$.post(url_base + META_MSG_URL, data, cb, 'json').fail(function(){
		if(!silentFail){
			Swal.fire('Erro', 'Falha na requisição.', 'error');
		}
	});
}

function esc(s){
	return $('<div>').text(s == null ? '' : String(s)).html();
}

function badgeCanal(canal){
	if(canal === 'instagram'){
		return '<span class="badge bg-info text-dark ms-1">Instagram</span>';
	}
	return '<span class="badge bg-secondary ms-1">Messenger</span>';
}

function formatData(s){
	if(!s) return '';
	return String(s).replace('T', ' ').substring(0, 16);
}

function htmlCorpoMensagem(m){
	const tipo = m.tipo || 'text';
	if(tipo === 'attachment' && m.anexo_json){
		try {
			const arr = JSON.parse(m.anexo_json);
			const t = (arr[0] && arr[0].type) ? arr[0].type : 'anexo';
			return '<em class="small">['+esc(t)+']</em>'+(m.corpo ? '<div class="mt-1">'+esc(m.corpo)+'</div>' : '');
		} catch(e){
			return esc(m.corpo || '[anexo]');
		}
	}
	if(!(m.corpo || '').trim()){
		return '<em class="small text-muted">[mensagem sem texto]</em>';
	}
	return esc(m.corpo || '');
}

function renderMensagens(mensagens, forcarScroll){
	const $m = $('#meta-mensagens');
	const el = $m[0];
	const pertoDoFim = !el || (el.scrollHeight - el.scrollTop - el.clientHeight) < 80;
	const ultima = mensagens.length ? mensagens[mensagens.length - 1] : null;
	const novoUltimoId = ultima ? String(ultima.id) : null;

	if(!forcarScroll && novoUltimoId && novoUltimoId === metaUltimaMsgId){
		return false;
	}

	metaUltimaMsgId = novoUltimoId;
	$m.empty();
	if(!mensagens || !mensagens.length){
		$m.html('<p class="text-muted small mb-0">Nenhuma mensagem nesta conversa.</p>');
		return true;
	}

	(mensagens || []).forEach(function(m){
		const mine = m.direction === 'out';
		const align = mine ? 'text-end' : 'text-start';
		const bg = mine ? 'bg-primary text-white' : 'bg-white';
		const status = m.status_envio ? ' · '+esc(m.status_envio) : '';
		$m.append(
			'<div class="mb-2 '+align+'">'
			+'<span class="d-inline-block rounded px-3 py-2 shadow-sm '+bg+'" style="max-width:85%;">'
			+htmlCorpoMensagem(m)
			+'</span>'
			+'<div class="small text-muted">'+esc(formatData(m.created_at))+(mine ? status : '')+'</div>'
			+'</div>'
		);
	});
	if(forcarScroll || pertoDoFim){
		$m.scrollTop($m[0].scrollHeight);
	}
	return true;
}

function setChatEnabled(on){
	$('#meta-texto, #btn-meta-enviar').prop('disabled', !on);
}

function atualizarBotoesConversa(){
	const arquivada = metaConversaStatus === 'arquivada';
	$('#btn-meta-arquivar').toggleClass('d-none', !metaConversaId || arquivada);
	$('#btn-meta-reabrir').toggleClass('d-none', !metaConversaId || !arquivada);
	setChatEnabled(!!metaConversaId && !arquivada);
}

function mostrarAlertaConexao(meta){
	if(!meta || !meta.tabelas_ok){
		$('#alert-meta-sql').removeClass('d-none').html(
			'Execute o SQL <code>database/meta_messaging.sql</code> no phpMyAdmin para habilitar o inbox Meta.'
		);
	} else {
		$('#alert-meta-sql').addClass('d-none');
	}

	if(meta && meta.tabelas_ok && !meta.conectado){
		$('#alert-meta-conexao').removeClass('d-none').html(
			'Meta não conectada ou incompleta. <a href="'+url_base+'painel/config/social">Conectar Facebook/Instagram</a> '
			+'e assine os webhooks antes de receber mensagens.'
		);
	} else if(meta && meta.conectado){
		let txt = 'Conectado';
		if(meta.page_name) txt += ' · Page: '+esc(meta.page_name);
		if(meta.ig_username) txt += ' · @'+esc(meta.ig_username);
		if(meta.conectado_em) txt += ' · desde '+esc(formatData(meta.conectado_em));
		$('#alert-meta-conexao').removeClass('d-none alert-info').addClass('alert-success').html(txt);
	} else {
		$('#alert-meta-conexao').addClass('d-none');
	}
}

function carregarConversas(){
	metaPost({
		acao: 'listar',
		filtro: metaFiltro,
		busca: $('#meta-busca').val()
	}, function(res){
		if(!res || !res.success){
			$('#alert-meta-sql').removeClass('d-none').text((res && res.message) ? res.message : 'Não foi possível listar.');
			return;
		}

		mostrarAlertaConexao(res.meta || {});

		const ind = res.indicadores || {};
		$('#meta-ind-total').text((ind.total || 0)+' abertas');
		$('#meta-ind-nao-lidas').text((ind.nao_lidas || 0)+' não lidas');
		$('#meta-ind-messenger').text((ind.messenger || 0)+' Messenger');
		$('#meta-ind-instagram').text((ind.instagram || 0)+' Instagram');

		const lista = res.conversas || [];
		const $box = $('#meta-lista-conversas').empty();
		if(!lista.length){
			$box.append('<div class="p-3 text-muted small">Nenhuma conversa neste filtro.</div>');
			return;
		}

		lista.forEach(function(c){
			const ativa = metaConversaId && parseInt(c.id, 10) === parseInt(metaConversaId, 10);
			const nome = c.nome_contato || ('Contato #'+String(c.participant_id || '').slice(-6));
			const preview = c.ultima_mensagem || '';
			const naoLidas = parseInt(c.nao_lidas, 10) || 0;
			const badge = naoLidas > 0 ? '<span class="badge bg-danger rounded-pill">'+naoLidas+'</span>' : '';
			$box.append(
				'<button type="button" class="list-group-item list-group-item-action '+(ativa ? 'active' : '')+' meta-item-conversa"'
				+' data-id="'+esc(c.id)+'">'
				+'<div class="d-flex justify-content-between align-items-start">'
				+'<strong class="small">'+esc(nome)+'</strong>'
				+badge
				+'</div>'
				+'<div class="small '+(ativa ? '' : 'text-muted')+'">'
				+badgeCanal(c.canal)
				+' <span class="ms-1">'+esc(formatData(c.ultima_mensagem_em))+'</span>'
				+'</div>'
				+'<div class="small text-truncate '+(ativa ? '' : 'text-muted')+'">'+esc(preview)+'</div>'
				+'</button>'
			);
		});
	});
}

function abrirConversa(id, silent){
	id = parseInt(id, 10);
	if(!id) return;
	metaConversaId = id;
	metaCarregandoMsg = true;

	metaPost({ acao: 'mensagens', conversa_id: id }, function(res){
		metaCarregandoMsg = false;
		if(!res || !res.success){
			if(!silent) Swal.fire('Erro', (res && res.message) ? res.message : 'Falha ao abrir.', 'error');
			return;
		}

		const c = res.conversa || {};
		metaConversaStatus = c.status || 'aberta';
		const nome = c.nome_contato || ('Contato #'+String(c.participant_id || '').slice(-6));
		$('#meta-chat-titulo').text(nome);
		$('#meta-chat-sub').html(
			(c.canal_label || '')+' · ID '+esc(c.participant_id || '')
			+(c.status === 'arquivada' ? ' · <em>Arquivada</em>' : '')
		);

		renderMensagens(res.mensagens || [], true);
		atualizarBotoesConversa();
		carregarConversas();
	}, silent);
}

function enviarMensagem(){
	const texto = ($('#meta-texto').val() || '').trim();
	if(!texto || !metaConversaId) return;

	$('#btn-meta-enviar').prop('disabled', true);
	$('#meta-envio-status').text('Enviando…');

	metaPost({
		acao: 'enviar',
		conversa_id: metaConversaId,
		texto: texto
	}, function(res){
		$('#btn-meta-enviar').prop('disabled', false);
		if(!res || !res.success){
			$('#meta-envio-status').text('');
			Swal.fire('Erro', (res && res.message) ? res.message : 'Falha ao enviar.', 'error');
			return;
		}
		$('#meta-texto').val('');
		$('#meta-envio-status').text('Enviado.');
		abrirConversa(metaConversaId, true);
		setTimeout(function(){ $('#meta-envio-status').text(''); }, 2500);
	});
}

function arquivarConversa(){
	if(!metaConversaId) return;
	Swal.fire({
		title: 'Arquivar conversa?',
		icon: 'question',
		showCancelButton: true,
		confirmButtonText: 'Arquivar'
	}).then(function(r){
		if(!r.isConfirmed) return;
		metaPost({ acao: 'arquivar', conversa_id: metaConversaId }, function(res){
			if(!res || !res.success){
				Swal.fire('Erro', (res && res.message) ? res.message : 'Falha.', 'error');
				return;
			}
			metaConversaStatus = 'arquivada';
			atualizarBotoesConversa();
			carregarConversas();
		});
	});
}

function reabrirConversa(){
	if(!metaConversaId) return;
	metaPost({ acao: 'reabrir', conversa_id: metaConversaId }, function(res){
		if(!res || !res.success){
			Swal.fire('Erro', (res && res.message) ? res.message : 'Falha.', 'error');
			return;
		}
		metaConversaStatus = 'aberta';
		atualizarBotoesConversa();
		carregarConversas();
		abrirConversa(metaConversaId, true);
	});
}

function iniciarPoll(){
	if(metaPoll) clearInterval(metaPoll);
	metaPoll = setInterval(function(){
		carregarConversas();
		if(metaConversaId && !metaCarregandoMsg){
			metaPost({ acao: 'mensagens', conversa_id: metaConversaId }, function(res){
				if(res && res.success){
					renderMensagens(res.mensagens || [], false);
				}
			}, true);
		}
	}, 12000);
}

$(function(){
	carregarConversas();
	iniciarPoll();

	$('#btn-meta-refresh').on('click', carregarConversas);

	$('#meta-filtros').on('click', 'button[data-filtro]', function(){
		metaFiltro = $(this).data('filtro');
		$('#meta-filtros button').removeClass('active');
		$(this).addClass('active');
		carregarConversas();
	});

	$('#meta-busca').on('input', function(){
		clearTimeout(metaBuscaTimer);
		metaBuscaTimer = setTimeout(carregarConversas, 350);
	});

	$('#meta-lista-conversas').on('click', '.meta-item-conversa', function(){
		abrirConversa($(this).data('id'));
	});

	$('#btn-meta-enviar').on('click', enviarMensagem);
	$('#meta-texto').on('keydown', function(e){
		if(e.key === 'Enter' && !e.shiftKey){
			e.preventDefault();
			enviarMensagem();
		}
	});

	$('#btn-meta-arquivar').on('click', arquivarConversa);
	$('#btn-meta-reabrir').on('click', reabrirConversa);

	// Deep-link: /painel/social/mensagens?conversa=ID
	var params = new URLSearchParams(window.location.search || '');
	var convParam = parseInt(params.get('conversa') || '0', 10);
	if (convParam > 0) {
		setTimeout(function () { abrirConversa(convParam); }, 600);
	}
});
