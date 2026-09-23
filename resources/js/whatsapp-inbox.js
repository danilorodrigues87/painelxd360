const WA_URL = 'painel/whatsapp';
let waConversaId = null;
let waIsDiretor = false;
let waPoll = null;
let waUltimaMsgId = null;
let waUltimaMsgHash = null;
let waCarregandoMsg = false;
let waMsgReqSeq = 0;
let waMediaRecorder = null;
let waAudioChunks = [];
let waGravando = false;
let waAudioStream = null;
let waFiltro = 'todas';
let waBuscaTimer = null;

function sincronizarFiltroWaUi(){
	$('#wa-filtros button').removeClass('active');
	const $btn = $('#wa-filtros button[data-filtro="'+waFiltro+'"]');
	if($btn.length){
		$btn.addClass('active');
	}
	$('.wa-ind-filtro').removeClass('wa-ind-ativo');
	if(['nao_lidas','fila','abertas'].indexOf(waFiltro) >= 0){
		$('.wa-ind-filtro[data-filtro="'+waFiltro+'"]').addClass('wa-ind-ativo');
	}
}

function aplicarFiltroWa(filtro){
	waFiltro = filtro || 'todas';
	sincronizarFiltroWaUi();
	carregarConversas();
}

function limparChatWaAberto(){
	waConversaId = null;
	waUltimaMsgId = null;
	waUltimaMsgHash = null;
	$('#wa-chat-titulo').text('Selecione uma conversa');
	$('#wa-chat-sub').text('');
	$('#wa-mensagens').empty();
	$('#btn-wa-assumir, #btn-wa-transferir, #btn-wa-fechar').addClass('d-none');
	$('#wa-texto, #btn-wa-enviar, #wa-input-img, #wa-input-doc, #btn-wa-audio, #btn-wa-audio-file').prop('disabled', true);
	$('.wa-emoji').prop('disabled', true);
}

function executarFecharTodasWa(){
	const $btn = $('#btn-wa-fechar-todas');
	$btn.prop('disabled', true);
	waPost({ acao: 'fechar_todas' }, function(res){
		$btn.prop('disabled', false);
		if(!res || !res.success){
			if(typeof Swal !== 'undefined'){
				Swal.fire('Erro', (res && res.message) || 'Falha ao encerrar.', 'error');
			} else {
				alert((res && res.message) || 'Falha ao encerrar.');
			}
			return;
		}
		if(typeof Swal !== 'undefined'){
			Swal.fire('Concluído', (res.message || ''), (res.fechadas || 0) > 0 ? 'success' : 'info');
		} else {
			alert(res.message || 'Concluído.');
		}
		limparChatWaAberto();
		carregarConversas();
	});
}

function confirmarFecharTodasWa(){
	const escopo = waIsDiretor
		? 'todas as conversas em andamento da escola'
		: 'suas conversas e as da fila do seu setor';
	if(typeof Swal !== 'undefined' && Swal.fire){
		Swal.fire({
			title: 'Finalizar todas em andamento?',
			html: 'Serão encerradas <strong>'+escopo+'</strong>.<br><br>Esta ação não pode ser desfeita.',
			icon: 'warning',
			showCancelButton: true,
			confirmButtonText: 'Sim, finalizar todas',
			confirmButtonColor: '#dc3545',
			cancelButtonText: 'Cancelar'
		}).then(function(r){
			if(r.isConfirmed){
				executarFecharTodasWa();
			}
		});
		return;
	}
	if(window.confirm('Finalizar todas em andamento?\n\nSerão encerradas '+escopo+'.\n\nEsta ação não pode ser desfeita.')){
		executarFecharTodasWa();
	}
}

function waPost(data, cb, silentFail){
	$.post(url_base + WA_URL, data, cb, 'json').fail(function(){
		if(!silentFail){
			Swal.fire('Erro', 'Falha na requisição WhatsApp.', 'error');
		}
	});
}

function esc(s){
	return $('<div>').text(s == null ? '' : String(s)).html();
}

/** Busca da lista — ignora autofill de e-mail do navegador. */
function valorBuscaWa(){
	const $el = $('#wa-busca');
	let v = String($el.val() || '').trim();
	if(/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)){
		$el.val('');
		return '';
	}
	return v;
}

function htmlCorpoMensagem(m){
	const tipo = m.tipo || 'text';
	const media = m.media_url_full || '';
	let html = '';

	if((tipo === 'image' || tipo === 'sticker') && media){
		html += '<a href="'+esc(media)+'" target="_blank" rel="noopener">'
			+'<img src="'+esc(media)+'" alt="imagem" class="img-fluid rounded" style="max-width:240px;max-height:240px;"></a>';
		if(m.corpo) html += '<div class="mt-1">'+esc(m.corpo)+'</div>';
		return html;
	}
	if(tipo === 'audio' && media){
		html += '<audio controls preload="metadata" style="max-width:260px;"><source src="'+esc(media)+'"></audio>';
		return html;
	}
	if(tipo === 'video' && media){
		html += '<video controls preload="metadata" style="max-width:260px;" class="rounded"><source src="'+esc(media)+'"></video>';
		if(m.corpo) html += '<div class="mt-1">'+esc(m.corpo)+'</div>';
		return html;
	}
	if(tipo === 'document' && media){
		const nome = m.corpo || 'Documento';
		html += '<a class="text-decoration-none" href="'+esc(media)+'" target="_blank" rel="noopener">'
			+'<i class="fas fa-file-alt"></i> '+esc(nome)+'</a>';
		return html;
	}
	if(tipo === 'reaction'){
		const emoji = (m.corpo || '').trim();
		if(emoji){
			return '<span class="small text-muted">Reagiu com </span><span style="font-size:1.35rem;line-height:1;">'+esc(emoji)+'</span>';
		}
		return '<em class="small text-muted">Removeu a reação</em>';
	}
	if(tipo !== 'text' && !m.corpo){
		return '<em class="small">['+esc(tipo)+']</em>';
	}
	if(!(m.corpo || '').trim()){
		return '<em class="small text-muted">[mensagem sem texto]</em>';
	}
	return esc(m.corpo || '');
}

function renderMensagens(mensagens, forcarScroll){
	const $m = $('#wa-mensagens');
	const el = $m[0];
	const pertoDoFim = !el || (el.scrollHeight - el.scrollTop - el.clientHeight) < 80;
	const ultima = mensagens.length ? mensagens[mensagens.length - 1] : null;
	const novoUltimoId = ultima ? String(ultima.id) : null;
	const novoHash = novoUltimoId + ':' + (mensagens ? mensagens.length : 0);

	if(!forcarScroll && novoHash === waUltimaMsgHash){
		return false;
	}

	waUltimaMsgId = novoUltimoId;
	waUltimaMsgHash = novoHash;
	$m.empty();
	(mensagens || []).forEach(function(m){
		const mine = m.direction === 'out';
		const align = mine ? 'text-end' : 'text-start';
		const bg = mine ? 'bg-success text-white' : 'bg-white';
		$m.append(
			'<div class="mb-2 '+align+'"><span class="d-inline-block rounded px-3 py-2 shadow-sm '+bg+'" style="max-width:85%;">'
			+htmlCorpoMensagem(m)
			+'</span><div class="small text-muted">'+esc(m.created_at || '')+'</div></div>'
		);
	});
	if(forcarScroll || pertoDoFim){
		$m.scrollTop($m[0].scrollHeight);
	}
	return true;
}

function setChatEnabled(on){
	$('#wa-texto, #btn-wa-enviar, #wa-input-img, #wa-input-doc, #btn-wa-audio, #btn-wa-audio-file, .wa-emoji').prop('disabled', !on);
}

function carregarConversas(){
	waPost({
		acao: 'listar',
		filtro: waFiltro,
		busca: valorBuscaWa()
	}, function(res){
		if(!res || !res.success){
			$('#alert-wa-sql').removeClass('d-none').text((res && res.message) ? res.message : 'Não foi possível listar.');
			return;
		}
		const meta = res.meta || {};
		waIsDiretor = !!meta.is_diretor;
		if(!meta.chatbot_ok || !meta.setores_ok){
			$('#alert-wa-sql').removeClass('d-none').html(
				'Execute o SQL de WhatsApp (setores/chatbot) no phpMyAdmin — veja ARCHITECTURE.md.'
			);
		} else {
			$('#alert-wa-sql').addClass('d-none');
		}
		if(!waIsDiretor){
			$('#tab-config-li').addClass('d-none');
		}

		const ind = res.indicadores || {};
		$('#wa-ind-nao-lidas').text((ind.nao_lidas || 0)+' não lidas');
		$('#wa-ind-fila').text((ind.fila || 0)+' na fila');
		$('#wa-ind-abertas').text((ind.abertas || 0)+' abertas');
		const setoresTxt = (ind.por_setor || []).slice(0, 4).map(function(s){
			return esc(s.setor)+': '+s.qtd;
		}).join(' · ');
		$('#wa-ind-setores').text(setoresTxt ? ('Por setor — '+setoresTxt) : '');

		const lista = res.conversas || [];
		const $box = $('#wa-lista-conversas').empty();
		if(!lista.length){
			$box.append('<div class="p-3 text-muted small">Nenhuma conversa neste filtro.</div>');
			return;
		}
		lista.forEach(function(c){
			const ativo = waConversaId === parseInt(c.id, 10) ? ' active' : '';
			const nome = c.nome_contato || c.telefone;
			const setor = c.setor_nome ? ' · '+c.setor_nome : '';
			const atend = c.atendente_nome ? ' · '+c.atendente_nome : '';
			const st = c.chatbot_estado || c.status || '';
			const unread = parseInt(c.nao_lida, 10) === 1 ? ' <span class="badge bg-danger">nova</span>' : '';
			$box.append(
				'<a href="#" class="list-group-item list-group-item-action'+ativo+'" data-id="'+c.id+'">'
				+'<div class="d-flex justify-content-between"><strong class="text-truncate">'+esc(nome)+'</strong>'
				+'<span class="badge bg-secondary">'+esc(st)+'</span></div>'
				+'<div class="small text-muted">'+esc(c.telefone)+esc(setor)+esc(atend)+unread+'</div>'
				+'</a>'
			);
		});
	}, true);
}

function abrirConversa(id, opcoes){
	opcoes = opcoes || {};
	const silencioso = !!opcoes.silencioso;
	const idNum = parseInt(id, 10);
	if(!idNum) return;

	if(!silencioso){
		waConversaId = idNum;
		waUltimaMsgId = null;
		waUltimaMsgHash = null;
	} else if(waConversaId !== idNum){
		return;
	}

	const reqSeq = ++waMsgReqSeq;
	waCarregandoMsg = true;

	$.ajax({
		url: url_base + WA_URL,
		method: 'POST',
		data: { acao: 'mensagens', conversa_id: idNum },
		dataType: 'json'
	}).always(function(){
		if(reqSeq === waMsgReqSeq){
			waCarregandoMsg = false;
		}
	}).done(function(res){
		if(reqSeq !== waMsgReqSeq || waConversaId !== idNum){
			return;
		}
		if(!res || !res.success){
			if(!silencioso){
				Swal.fire('Erro', (res && res.message) || 'Falha ao carregar mensagens.', 'error');
			}
			return;
		}

		const c = res.conversa || {};
		$('#wa-chat-titulo').text(c.nome_contato || c.telefone || 'Conversa');
		$('#wa-chat-sub').text((c.telefone || '')+' · '+(c.chatbot_estado || c.status || ''));
		$('#btn-wa-assumir, #btn-wa-transferir, #btn-wa-fechar').removeClass('d-none');
		setChatEnabled(true);

		const msgs = res.mensagens || [];
		const pertoDoFim = (function(){
			const el = $('#wa-mensagens')[0];
			return !el || (el.scrollHeight - el.scrollTop - el.clientHeight) < 80;
		})();
		renderMensagens(msgs, !silencioso || pertoDoFim);
		if(!silencioso){
			carregarConversas();
		}
	}).fail(function(){
		if(reqSeq !== waMsgReqSeq || silencioso){
			return;
		}
		Swal.fire('Erro', 'Falha na requisição ao carregar mensagens.', 'error');
	});
}

function atualizarInbox(){
	carregarConversas();
	if(waConversaId){
		abrirConversa(waConversaId, { silencioso: true });
	}
}

function enviarMsg(){
	if(!waConversaId) return;
	const texto = $('#wa-texto').val();
	if(!String(texto).trim()) return;
	$('#btn-wa-enviar').prop('disabled', true);
	waPost({ acao: 'enviar', conversa_id: waConversaId, texto: texto }, function(res){
		$('#btn-wa-enviar').prop('disabled', false);
		if(!res || !res.success){
			Swal.fire('Erro', (res && res.message) || 'Não enviou.', 'error');
			return;
		}
		$('#wa-texto').val('');
		abrirConversa(waConversaId);
	});
}

function enviarMidia(file, tipo, caption){
	if(!waConversaId || !file) return;
	const fd = new FormData();
	fd.append('acao', 'enviar_midia');
	fd.append('conversa_id', waConversaId);
	fd.append('tipo_midia', tipo);
	fd.append('arquivo', file);
	if(caption) fd.append('caption', caption);

	$('#wa-audio-status').text('Enviando...');
	$.ajax({
		url: url_base + WA_URL,
		method: 'POST',
		data: fd,
		processData: false,
		contentType: false,
		dataType: 'json'
	}).done(function(res){
		$('#wa-audio-status').text('');
		if(!res || !res.success){
			Swal.fire({
				title: 'Erro',
				html: '<div style="text-align:left;font-size:13px;word-break:break-word;">'
					+ esc((res && res.message) || 'Falha no envio.')
					+ '</div>',
				icon: 'error',
				width: 640
			});
			return;
		}
		abrirConversa(waConversaId);
	}).fail(function(xhr){
		$('#wa-audio-status').text('');
		let extra = '';
		if(xhr && xhr.responseText){
			extra = '<div style="text-align:left;font-size:12px;margin-top:8px;word-break:break-word;">'
				+ esc(String(xhr.responseText).substring(0, 500)) + '</div>';
		}
		Swal.fire({
			title: 'Erro',
			html: 'Falha ao enviar mídia.'+extra,
			icon: 'error',
			width: 640
		});
	});
}

function inserirEmoji(emoji){
	const $t = $('#wa-texto');
	if($t.prop('disabled')) return;
	const el = $t[0];
	const start = el.selectionStart || $t.val().length;
	const end = el.selectionEnd || start;
	const val = $t.val() || '';
	$t.val(val.substring(0, start) + emoji + val.substring(end));
	el.focus();
	const pos = start + emoji.length;
	el.setSelectionRange(pos, pos);
}

function mimeGravacaoPreferido(){
	if(typeof MediaRecorder === 'undefined') return '';
	const candidatos = [
		'audio/ogg;codecs=opus',
		'audio/webm;codecs=opus',
		'audio/webm',
		'audio/ogg'
	];
	for(let i = 0; i < candidatos.length; i++){
		if(MediaRecorder.isTypeSupported(candidatos[i])) return candidatos[i];
	}
	return '';
}

function extensaoDoMimeAudio(mime){
	const m = String(mime || '').toLowerCase();
	if(m.indexOf('ogg') >= 0) return 'ogg';
	if(m.indexOf('webm') >= 0) return 'webm';
	if(m.indexOf('mp4') >= 0 || m.indexOf('m4a') >= 0) return 'm4a';
	if(m.indexOf('mpeg') >= 0 || m.indexOf('mp3') >= 0) return 'mp3';
	return 'webm';
}

function limparStreamAudio(){
	if(waAudioStream){
		waAudioStream.getTracks().forEach(function(t){ t.stop(); });
	}
	waAudioStream = null;
	waMediaRecorder = null;
	waAudioChunks = [];
	waGravando = false;
	$('#btn-wa-audio').removeClass('btn-danger').addClass('btn-outline-secondary');
}

async function toggleGravacaoAudio(){
	if(!waConversaId) return;

	if(waGravando && waMediaRecorder && waMediaRecorder.state !== 'inactive'){
		$('#wa-audio-status').text('Enviando nota de voz...');
		waMediaRecorder.stop();
		return;
	}

	if(!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || typeof MediaRecorder === 'undefined'){
		escolherArquivoAudio();
		return;
	}

	try {
		waAudioStream = await navigator.mediaDevices.getUserMedia({
			audio: {
				echoCancellation: true,
				noiseSuppression: true,
				channelCount: 1
			}
		});

		const mime = mimeGravacaoPreferido();
		waAudioChunks = [];
		waMediaRecorder = mime
			? new MediaRecorder(waAudioStream, { mimeType: mime })
			: new MediaRecorder(waAudioStream);

		waMediaRecorder.ondataavailable = function(ev){
			if(ev.data && ev.data.size > 0) waAudioChunks.push(ev.data);
		};

		waMediaRecorder.onstop = function(){
			const usedMime = (waMediaRecorder && waMediaRecorder.mimeType) || mime || 'audio/webm';
			const ext = extensaoDoMimeAudio(usedMime);
			const blob = new Blob(waAudioChunks, { type: usedMime });
			limparStreamAudio();
			if(!blob.size){
				$('#wa-audio-status').text('');
				Swal.fire('Áudio', 'Nada foi gravado. Tente novamente.', 'warning');
				return;
			}
			const file = new File([blob], 'audio-'+Date.now()+'.'+ext, { type: usedMime });
			enviarMidia(file, 'audio');
		};

		waMediaRecorder.start(250);
		waGravando = true;
		$('#btn-wa-audio').removeClass('btn-outline-secondary').addClass('btn-danger');
		$('#wa-audio-status').text('Gravando... clique de novo para enviar.');
	} catch (e) {
		limparStreamAudio();
		Swal.fire({
			title: 'Áudio',
			text: 'Não foi possível acessar o microfone. Deseja enviar um arquivo de áudio?',
			icon: 'warning',
			showCancelButton: true,
			confirmButtonText: 'Escolher arquivo'
		}).then(function(r){
			if(r.isConfirmed) escolherArquivoAudio();
		});
	}
}

function escolherArquivoAudio(){
	const input = document.createElement('input');
	input.type = 'file';
	input.accept = 'audio/ogg,audio/webm,audio/mpeg,audio/mp3,audio/wav,audio/mp4,audio/aac,.ogg,.opus,.webm,.mp3,.wav,.m4a';
	input.onchange = function(){
		if(input.files && input.files[0]) enviarMidia(input.files[0], 'audio');
	};
	input.click();
}

function carregarSetoresConfig(){
	waPost({ acao: 'setores_listar' }, function(res){
		if(!res || !res.success) return;
		const $t = $('#tbl-setores').empty();
		const $sel = $('#at-setor').empty();
		(res.setores || []).forEach(function(s){
			$t.append(
				'<tr><td>'+esc(s.nome)+'</td><td>'+esc(s.ordem)+'</td><td>'+(parseInt(s.ativo,10)===1?'sim':'não')+'</td>'
				+'<td><button type="button" class="btn btn-link btn-sm btn-editar-setor" data-id="'+s.id+'">Editar</button></td></tr>'
			);
			if(parseInt(s.ativo,10)===1){
				$sel.append('<option value="'+s.id+'">'+esc(s.nome)+'</option>');
			}
		});
	});
	waPost({ acao: 'atendentes_listar' }, function(res){
		if(!res || !res.success) return;
		const $t = $('#tbl-atendentes').empty();
		(res.atendentes || []).forEach(function(a){
			$t.append(
				'<tr><td>'+esc(a.usuario_nome)+' <small class="text-muted">('+esc(a.usuario_nivel)+')</small></td>'
				+'<td>'+esc(a.setor_nome)+'</td>'
				+'<td><button type="button" class="btn btn-sm btn-outline-danger btn-at-del" data-id="'+a.id+'">×</button></td></tr>'
			);
		});
	});
	waPost({ acao: 'usuarios_lista' }, function(res){
		if(!res || !res.success) return;
		const $u = $('#at-usuario').empty();
		(res.usuarios || []).forEach(function(u){
			$u.append('<option value="'+u.id+'">'+esc(u.nome)+' ('+esc(u.nivel)+')</option>');
		});
	});
}

function promptSetor(s){
	s = s || { id: 0, nome: '', slug: '', ordem: 0, ativo: 1, mensagem_fila: '' };
	Swal.fire({
		title: s.id ? 'Editar setor' : 'Novo setor',
		html:
			'<input id="sw-nome" class="swal2-input" placeholder="Nome" value="'+esc(s.nome)+'">'
			+'<input id="sw-ordem" class="swal2-input" placeholder="Ordem" value="'+(s.ordem||0)+'">'
			+'<textarea id="sw-msg" class="swal2-textarea" placeholder="Mensagem ao entrar na fila">'+esc(s.mensagem_fila||'')+'</textarea>'
			+'<label class="d-block mt-2"><input type="checkbox" id="sw-ativo" '+(parseInt(s.ativo,10)===1?'checked':'')+'> Ativo</label>',
		showCancelButton: true,
		preConfirm: function(){
			return {
				id: s.id || 0,
				nome: $('#sw-nome').val(),
				ordem: $('#sw-ordem').val(),
				mensagem_fila: $('#sw-msg').val(),
				ativo: $('#sw-ativo').is(':checked') ? 1 : 0
			};
		}
	}).then(function(r){
		if(!r.isConfirmed) return;
		const d = r.value;
		d.acao = 'setor_salvar';
		waPost(d, function(res){
			if(!res || !res.success){
				Swal.fire('Erro', (res && res.message) || 'Falha.', 'error');
				return;
			}
			carregarSetoresConfig();
		});
	});
}

$(function(){
	// Evita autofill do navegador (e-mail/login) no campo de busca
	const $busca = $('#wa-busca');
	$busca.val('').attr('readonly', true);
	$busca.on('focus', function(){ $(this).removeAttr('readonly'); });
	$busca.on('blur', function(){
		if(!String($(this).val() || '').trim()){
			$(this).attr('readonly', true);
		}
	});
	setTimeout(function(){ $busca.val(''); }, 100);

	carregarConversas();
	// Atualiza lista + chat aberto a cada 5s (sem recarregar a página)
	waPoll = setInterval(atualizarInbox, 5000);

	// Deep-link: /painel/whatsapp?conversa=ID
	try {
		const params = new URLSearchParams(window.location.search || '');
		const convParam = parseInt(params.get('conversa') || '0', 10);
		if (convParam > 0) {
			setTimeout(function(){ abrirConversa(convParam); }, 400);
		}
	} catch (e) {}

	$('#btn-wa-refresh').on('click', atualizarInbox);
	$('#wa-lista-conversas').on('click', 'a[data-id]', function(e){
		e.preventDefault();
		abrirConversa($(this).data('id'));
	});
	$('#btn-wa-enviar').on('click', enviarMsg);
	$('#wa-texto').on('keydown', function(e){
		if(e.key === 'Enter' && !e.shiftKey){ e.preventDefault(); enviarMsg(); }
	});
	$('.wa-emoji').on('click', function(){
		inserirEmoji($(this).text());
	});
	$('#wa-input-img').on('change', function(){
		const f = this.files && this.files[0];
		if(!f) return;
		const caption = ($('#wa-texto').val() || '').trim();
		enviarMidia(f, 'image', caption);
		$(this).val('');
		if(caption) $('#wa-texto').val('');
	});
	$('#wa-input-doc').on('change', function(){
		const f = this.files && this.files[0];
		if(!f) return;
		const caption = ($('#wa-texto').val() || '').trim();
		enviarMidia(f, 'document', caption);
		$(this).val('');
		if(caption) $('#wa-texto').val('');
	});
	$('#btn-wa-audio').on('click', function(){
		toggleGravacaoAudio();
	});
	$('#btn-wa-audio-file').on('click', function(){
		escolherArquivoAudio();
	});
	$('#btn-wa-assumir').on('click', function(){
		if(!waConversaId) return;
		waPost({ acao: 'assumir', conversa_id: waConversaId }, function(res){
			Swal.fire(res && res.success ? 'OK' : 'Erro', (res && res.message) || '', res && res.success ? 'success' : 'error');
			abrirConversa(waConversaId);
		});
	});
	$('#btn-wa-fechar').on('click', function(){
		if(!waConversaId) return;
		waPost({ acao: 'fechar', conversa_id: waConversaId }, function(res){
			Swal.fire(res && res.success ? 'OK' : 'Erro', (res && res.message) || '', res && res.success ? 'success' : 'error');
			abrirConversa(waConversaId);
		});
	});
	$(document).on('click', '#btn-wa-fechar-todas', function(e){
		e.preventDefault();
		confirmarFecharTodasWa();
	});
	$(document).on('click', '.wa-ind-filtro', function(e){
		e.preventDefault();
		aplicarFiltroWa($(this).data('filtro') || 'todas');
	});
	$('#btn-wa-transferir').on('click', function(){
		if(!waConversaId) return;
		waPost({ acao: 'setores_listar' }, function(res){
			const opts = {};
			(res.setores || []).filter(function(s){ return parseInt(s.ativo,10)===1; }).forEach(function(s){
				opts[s.id] = s.nome;
			});
			Swal.fire({
				title: '1/2 — Escolha o setor',
				input: 'select',
				inputOptions: opts,
				showCancelButton: true,
				confirmButtonText: 'Continuar'
			}).then(function(r){
				if(!r.isConfirmed) return;
				const setorId = r.value;
				waPost({ acao: 'atendentes_setor', setor_id: setorId }, function(resAt){
					const optsAt = { '0': 'Fila do setor (sem atendente)' };
					(resAt.atendentes || []).forEach(function(a){
						optsAt[String(a.usuario_id)] = a.usuario_nome;
					});
					Swal.fire({
						title: '2/2 — Atendente (opcional)',
						input: 'select',
						inputOptions: optsAt,
						inputValue: '0',
						showCancelButton: true,
						confirmButtonText: 'Transferir'
					}).then(function(r2){
						if(!r2.isConfirmed) return;
						waPost({
							acao: 'transferir',
							conversa_id: waConversaId,
							setor_id: setorId,
							atendente_id: r2.value
						}, function(res2){
							Swal.fire(res2 && res2.success ? 'OK' : 'Erro', (res2 && res2.message) || '', res2 && res2.success ? 'success' : 'error');
							abrirConversa(waConversaId);
						});
					});
				});
			});
		});
	});

	$('#wa-filtros').on('click', 'button[data-filtro]', function(){
		aplicarFiltroWa($(this).data('filtro') || 'todas');
	});
	$('#wa-busca').on('input', function(){
		clearTimeout(waBuscaTimer);
		waBuscaTimer = setTimeout(carregarConversas, 350);
	});

	$('button[data-bs-target="#tab-config"]').on('shown.bs.tab', carregarSetoresConfig);
	$('#btn-setor-novo').on('click', function(){ promptSetor(null); });
	$('#tbl-setores').on('click', '.btn-editar-setor', function(){
		const id = parseInt($(this).data('id'), 10);
		waPost({ acao: 'setores_listar' }, function(res){
			const s = (res.setores || []).find(function(x){ return parseInt(x.id,10) === id; });
			if(s) promptSetor(s);
		});
	});
	$('#btn-at-vincular').on('click', function(){
		waPost({
			acao: 'atendente_vincular',
			usuario_id: $('#at-usuario').val(),
			setor_id: $('#at-setor').val()
		}, function(res){
			if(!res || !res.success){
				Swal.fire('Erro', (res && res.message) || 'Falha.', 'error');
				return;
			}
			carregarSetoresConfig();
		});
	});
	$('#tbl-atendentes').on('click', '.btn-at-del', function(){
		waPost({ acao: 'atendente_remover', id: $(this).data('id') }, function(){
			carregarSetoresConfig();
		});
	});
});
