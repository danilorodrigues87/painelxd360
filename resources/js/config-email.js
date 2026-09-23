const CONFIG_EMAIL_URL = 'painel/config/comunicacao';
const COM_TAB_MAP = {
	smtp: '#tab-com-smtp',
	auto: '#tab-com-auto',
	wa: '#tab-com-wa',
	whatsapp: '#tab-com-wa',
	audit: '#tab-com-audit',
	auditoria: '#tab-com-audit'
};
const COM_TAB_REV = {
	'#tab-com-smtp': 'smtp',
	'#tab-com-auto': 'auto',
	'#tab-com-wa': 'wa',
	'#tab-com-audit': 'audit'
};

function aplicarPreset(preset){
	if(preset === 'gmail'){
		$('#smtp_host').val('smtp.gmail.com');
		$('#smtp_port').val(587);
		$('#smtp_encryption').val('tls');
	}
	if(preset === 'outlook'){
		$('#smtp_host').val('smtp.office365.com');
		$('#smtp_port').val(587);
		$('#smtp_encryption').val('tls');
	}
	if(preset === 'corp'){
		$('#smtp_host').val('');
		$('#smtp_port').val(587);
		$('#smtp_encryption').val('tls');
	}
}

function atualizarAlertaModo(data){
	const sistema = data.sistema || {};
	const modo = data.modo_envio || 'sistema';

	if(modo === 'escola'){
		$('#alert-modo-envio')
			.removeClass('alert-info alert-warning')
			.addClass('alert-success')
			.html('<i class="fas fa-check-circle"></i> Envios da escola usarão o <strong>SMTP configurado</strong> abaixo.');
		return;
	}

	if(sistema.configurado){
		$('#alert-modo-envio')
			.removeClass('alert-success alert-warning')
			.addClass('alert-info')
			.html('<i class="fas fa-info-circle"></i> Envios da escola usarão o e-mail padrão do sistema: <strong>'+sistema.from_email+'</strong>.');
		return;
	}

	$('#alert-modo-envio')
		.removeClass('alert-info alert-success')
		.addClass('alert-warning')
		.html('<i class="fas fa-exclamation-triangle"></i> Nenhum SMTP da escola ativo e o e-mail do sistema não está configurado no servidor (.env).');
}

function preencherFormulario(data){
	const cfg = data.config || {};

	$('#smtp_ativo').prop('checked', parseInt(cfg.smtp_ativo, 10) === 1);
	$('#smtp_host').val(cfg.smtp_host || '');
	$('#smtp_port').val(cfg.smtp_port || 587);
	$('#smtp_user').val(cfg.smtp_user || '');
	$('#smtp_from_email').val(cfg.smtp_from_email || '');
	$('#smtp_from_name').val(cfg.smtp_from_name || '');
	$('#smtp_encryption').val(cfg.smtp_encryption || 'tls');
	$('#email_delay_segundos').val(cfg.email_delay_segundos || 3);
	$('#email_max_hora').val(cfg.email_max_hora || 80);
	$('#smtp_pass').val('');

	if(cfg.tem_senha){
		$('#hint-senha').text('Senha já cadastrada. Deixe em branco para manter.');
	} else {
		$('#hint-senha').text('');
	}

	const sistema = data.sistema || {};
	if(sistema.configurado){
		$('#info-sistema').html(
			'<div><strong>Remetente:</strong> '+sistema.from_email+'</div>'
			+'<div><strong>Nome:</strong> '+(sistema.from_name || 'CTI Educacional')+'</div>'
		);
	} else {
		$('#info-sistema').html('<span class="text-warning">Não configurado no .env do servidor.</span>');
	}

	atualizarAlertaModo(data);
	preencherCobranca(data);
	preencherAniversario(data);
	preencherCronComunicacao(data.cron_comunicacao || {});
	preencherWhatsapp(data.whatsapp || {});

	if(data.aviso_smtp){
		Swal.fire('SMTP da escola', data.aviso_smtp, 'warning');
	}
}

function badgeStatusWa(status, conectado){
	if(conectado) return '<span class="badge bg-success">conectado</span>';
	const s = (status || 'unknown').toLowerCase();
	if(s === 'connecting' || s === 'qr') return '<span class="badge bg-warning text-dark">'+s+'</span>';
	if(s === 'not_created') return '<span class="badge bg-secondary">não criada</span>';
	return '<span class="badge bg-secondary">'+s+'</span>';
}

function normalizarSrcQr(qr){
	if(!qr) return null;
	qr = String(qr).trim();
	if(!qr) return null;
	if(qr.indexOf('data:image') === 0 || qr.indexOf('http://') === 0 || qr.indexOf('https://') === 0){
		return qr;
	}
	// Texto bruto do WhatsApp (ex.: 2@...) → gera imagem do QR
	return 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&ecc=M&margin=10&data='
		+ encodeURIComponent(qr);
}

let waPairingTimer = null;
let waLastQrAt = 0;
let waPareando = false;

function pararPareamentoWa(){
	waPareando = false;
	if(waPairingTimer){
		clearInterval(waPairingTimer);
		waPairingTimer = null;
	}
}

function atualizarBadgeWa(w){
	if(!w) return;
	if(w.status !== undefined){
		$('#wa-status-label').replaceWith('<span id="wa-status-label">'+badgeStatusWa(w.status, w.conectado)+'</span>');
	}
	if(w.instance) $('#wa-instance').text(w.instance);
	if(w.numero !== undefined && w.numero !== null && w.numero !== ''){
		$('#wa-numero').text(w.numero);
	}
}

function iniciarPareamentoWa(){
	pararPareamentoWa();
	waPareando = true;
	waLastQrAt = Date.now();
	waPairingTimer = setInterval(function(){
		if(!waPareando) return;
		$.post(url_base + CONFIG_EMAIL_URL, { acao: 'whatsapp_status' }, function(res){
			if(!res || !res.success || !waPareando) return;
			const w = res.whatsapp || {};
			// Só badge/número — NUNCA limpa o QR nem acumula alerta (status não traz qrcode)
			atualizarBadgeWa(w);
			if(w.conectado){
				pararPareamentoWa();
				mostrarQr(null, false);
				Swal.fire('Conectado!', 'WhatsApp pareado com sucesso.', 'success');
				whatsappStatus();
				return;
			}
			const st = String(w.status || '').toLowerCase();
			// Renova QR só se ainda estiver pareando e o código já tiver ~35s
			if(st !== 'not_created' && (Date.now() - waLastQrAt > 35000)){
				waLastQrAt = Date.now();
				$.post(url_base + CONFIG_EMAIL_URL, { acao: 'whatsapp_qr' }, function(r2){
					if(!r2 || !r2.success || !waPareando) return;
					const w2 = r2.whatsapp || r2;
					atualizarBadgeWa(w2);
					if(w2.qrcode){
						mostrarQr(w2.qrcode, false);
					}
					if(w2.conectado){
						pararPareamentoWa();
						mostrarQr(null, false);
						Swal.fire('Conectado!', 'WhatsApp pareado com sucesso.', 'success');
					}
				}, 'json');
			}
		}, 'json');
	}, 5000);
}

function mostrarQr(qr, iniciarPoll){
	if(iniciarPoll === undefined) iniciarPoll = true;
	const src = normalizarSrcQr(qr);
	if(src){
		$('#wa-qrcode').attr('src', src).removeClass('d-none');
		$('#wa-qr-placeholder').addClass('d-none');
		if(iniciarPoll){
			iniciarPareamentoWa();
		} else {
			waPareando = true;
		}
	} else {
		$('#wa-qrcode').addClass('d-none').attr('src', '');
		$('#wa-qr-placeholder').removeClass('d-none').text('Nenhum QR carregado. Use “Trocar número” se estiver travado em Connecting.');
	}
}

function preencherWhatsapp(w, opts){
	opts = opts || {};
	const msgs = [];
	if(!w.colunas_ok || !w.tabelas_ok || !w.configurado_env){
		if(!w.configurado_env) msgs.push('Configure EVOLUTION_URL e EVOLUTION_API_KEY no .env.');
		if(!w.colunas_ok || !w.tabelas_ok) msgs.push('Execute o SQL de WhatsApp/Evolution no phpMyAdmin.');
	}
	// Durante o pareamento, ignora "instância não existe" (falso positivo / corrida com o poll)
	if(w.erro && !(waPareando && String(w.status || '') === 'not_created')){
		msgs.push(w.erro);
	}
	if (w.conectado && w.webhook_ok === false) {
		msgs.push('Webhook não configurado na Evolution — mensagens de clientes podem não chegar. Clique em “Conectar / QR” ou “Atualizar status”.');
	}

	atualizarBadgeWa(w);
	$('#wa-webhook').text(w.webhook_url || '—');
	$('#evolution_ativo').prop('checked', parseInt(w.ativo, 10) === 1);
	$('#whatsapp_delay_segundos').val(w.delay || 60);
	$('#whatsapp_max_hora').val(w.max_hora || 20);
	const grupoDelaySeg = parseInt(w.grupo_delay, 10) || 600;
	$('#whatsapp_grupo_delay_minutos').val(Math.max(1, Math.round(grupoDelaySeg / 60)));
	if(w.variar_texto_ok === false){
		$('#whatsapp_variar_texto').prop('disabled', true);
		$('#wrap-variar-texto-wa .form-text').append(' Execute database/whatsapp_anti_ban.sql.');
	} else {
		$('#whatsapp_variar_texto').prop('disabled', false);
		$('#whatsapp_variar_texto').prop('checked', parseInt(w.variar_texto, 10) === 1);
	}
	if(w.grupo_delay_ok === false){
		$('#whatsapp_grupo_delay_minutos').prop('disabled', true);
		msgs.push('Para intervalo de grupos, execute o SQL da coluna whatsapp_grupo_delay_segundos.');
	} else {
		$('#whatsapp_grupo_delay_minutos').prop('disabled', false);
	}
	if(w.horario_inicio !== undefined){
		$('#whatsapp_horario_inicio').val(String(w.horario_inicio || '').substring(0, 5));
		$('#whatsapp_horario_fim').val(String(w.horario_fim || '').substring(0, 5));
		$('#whatsapp_dias').val(w.dias || '1,2,3,4,5');
		$('#whatsapp_msg_fora').val(w.msg_fora || '');
	}
	if(w.campanha_respeitar_expediente_ok === false){
		$('#campanha_respeitar_expediente').prop('disabled', true);
		$('#wrap-campanha-respeitar-expediente .form-text').append(' Execute database/campanha_respeitar_expediente.sql.');
	} else {
		$('#campanha_respeitar_expediente').prop('disabled', false);
		$('#campanha_respeitar_expediente').prop('checked', parseInt(w.campanha_respeitar_expediente, 10) === 1);
	}
	if(w.horario_ok === false){
		msgs.push('Para horário de expediente, execute o SQL das colunas whatsapp_horario_* (veja checklist).');
	}
	preencherMenuWhatsapp(w);

	if(msgs.length){
		$('#alert-whatsapp-sql').removeClass('d-none').html(msgs.join('<br>'));
	} else {
		$('#alert-whatsapp-sql').addClass('d-none').empty();
	}

	// Só altera o QR se a resposta trouxe um código novo (status sempre manda null)
	if(w.qrcode){
		mostrarQr(w.qrcode, opts.iniciarPoll !== false);
	} else if(!waPareando && opts.limparQr){
		mostrarQr(null, false);
	}
}

function renderChecklistWa(checklist){
	const $ul = $('#wa-checklist').empty();
	const itens = (checklist && checklist.itens) || [];
	if(!itens.length){
		$ul.append('<li class="text-muted">Clique em Atualizar para carregar o checklist.</li>');
		return;
	}
	itens.forEach(function(it){
		const icon = it.ok ? '✅' : '⚠️';
		let html = '<li class="mb-1">'+icon+' '+escHtmlWa(it.label || '');
		if(it.detalhe){
			html += '<br><span class="text-muted">'+escHtmlWa(it.detalhe)+'</span>';
		}
		html += '</li>';
		$ul.append(html);
	});
}

function escHtmlWa(s){
	return $('<div>').text(s == null ? '' : String(s)).html();
}

function aplicarRespostaWhatsapp(res){
	const w = res.whatsapp || res;
	preencherWhatsapp(Object.assign({}, {
		colunas_ok: true,
		tabelas_ok: true,
		configurado_env: true
	}, w), { iniciarPoll: true });
	if(w.qrcode){
		mostrarQr(w.qrcode, true);
	}
}

function whatsappStatus(){
	$.post(url_base + CONFIG_EMAIL_URL, { acao: 'whatsapp_status' }, function(res){
		if(!res || !res.success){
			Swal.fire('Erro', (res && res.message) ? res.message : 'Falha ao consultar status.', 'error');
			return;
		}
		preencherWhatsapp(res.whatsapp || {}, { limparQr: !waPareando, iniciarPoll: false });
		if(res.checklist) renderChecklistWa(res.checklist);
	}, 'json');
}

function whatsappConectar(){
	$('#btn-wa-conectar').prop('disabled', true);
	Swal.fire({ title: 'Preparando QR...', allowOutsideClick: false, didOpen: function(){ Swal.showLoading(); } });
	$.post(url_base + CONFIG_EMAIL_URL, { acao: 'whatsapp_conectar' }, function(res){
		$('#btn-wa-conectar').prop('disabled', false);
		Swal.close();
		if(!res || !res.success){
			pararPareamentoWa();
			Swal.fire('Erro', (res && res.message) ? res.message : 'Falha ao conectar.', 'error');
			return;
		}
		aplicarRespostaWhatsapp(res);
		if(res.whatsapp && res.whatsapp.conectado){
			pararPareamentoWa();
			Swal.fire('WhatsApp', res.message || 'Já conectado.', 'success');
		} else {
			Swal.fire({
				title: 'Escaneie o QR',
				html: (res.message || 'Abra o WhatsApp → Aparelhos conectados → Conectar um aparelho.')
					+ '<br><small class="text-muted">Escaneie em até ~40 segundos. A tela acompanha sozinha.</small>',
				icon: 'info'
			});
		}
	}, 'json').fail(function(){
		$('#btn-wa-conectar').prop('disabled', false);
		Swal.close();
		Swal.fire('Erro', 'Falha na requisição.', 'error');
	});
}

function whatsappQr(){
	$.post(url_base + CONFIG_EMAIL_URL, { acao: 'whatsapp_qr' }, function(res){
		if(!res || !res.success){
			Swal.fire('Erro', (res && res.message) ? res.message : 'Falha ao obter QR.', 'error');
			return;
		}
		aplicarRespostaWhatsapp(res);
	}, 'json');
}

function preencherMenuWhatsapp(w) {
	const menu = (w && w.menu) ? w.menu : {};
	const menuOk = w && w.menu_ok !== false;

	if (!menuOk) {
		$('#alert-whatsapp-menu-sql').removeClass('d-none');
		$('#wrap-whatsapp-menu').find('input, textarea').prop('disabled', true);
		return;
	}

	$('#alert-whatsapp-menu-sql').addClass('d-none');
	$('#wrap-whatsapp-menu').find('input, textarea').prop('disabled', false);
	$('#whatsapp_menu_ativo').prop('checked', menu.menu_ativo !== false);
	$('#whatsapp_menu_manual_ativo').prop('checked', menu.menu_manual_ativo !== false);
	$('#whatsapp_menu_titulo').val(menu.titulo || '');
	$('#whatsapp_menu_rodape').val(menu.rodape || '');
	$('#whatsapp_menu_msg_invalida').val(menu.msg_invalida || '');
	$('#whatsapp_menu_palavras').val((menu.palavras || []).join(','));
	atualizarPreviewMenuWa();
	toggleMenuManualUi();
}

function toggleMenuManualUi() {
	const autoOn = $('#whatsapp_menu_ativo').is(':checked');
	$('#wrap-menu-manual').toggleClass('opacity-50', !autoOn);
}

function atualizarPreviewMenuWa() {
	const titulo = ($('#whatsapp_menu_titulo').val() || 'Olá! Sou o assistente virtual. Escolha o setor digitando o *número*:').trim();
	const rodape = ($('#whatsapp_menu_rodape').val() || '').trim();
	const linhas = [titulo, '', '*1* - Comercial', '*2* - Financeiro', '*3* - Secretaria', '*4* - Pedagógico'];
	if (rodape) {
		linhas.push('', rodape);
	}
	$('#wa-menu-preview').text(linhas.join('\n'));
}

function whatsappSalvar(){
	$.post(url_base + CONFIG_EMAIL_URL, {
		acao: 'whatsapp_salvar',
		evolution_ativo: $('#evolution_ativo').is(':checked') ? 1 : 0,
		whatsapp_delay_segundos: $('#whatsapp_delay_segundos').val(),
		whatsapp_max_hora: $('#whatsapp_max_hora').val(),
		whatsapp_grupo_delay_minutos: $('#whatsapp_grupo_delay_minutos').val(),
		whatsapp_variar_texto: $('#whatsapp_variar_texto').is(':checked') ? 1 : 0,
		whatsapp_horario_inicio: $('#whatsapp_horario_inicio').val(),
		whatsapp_horario_fim: $('#whatsapp_horario_fim').val(),
		whatsapp_dias: $('#whatsapp_dias').val(),
		whatsapp_msg_fora: $('#whatsapp_msg_fora').val(),
		campanha_respeitar_expediente: $('#campanha_respeitar_expediente').is(':checked') ? 1 : 0,
		whatsapp_menu_ativo: $('#whatsapp_menu_ativo').is(':checked') ? 1 : 0,
		whatsapp_menu_manual_ativo: $('#whatsapp_menu_manual_ativo').is(':checked') ? 1 : 0,
		whatsapp_menu_titulo: $('#whatsapp_menu_titulo').val(),
		whatsapp_menu_rodape: $('#whatsapp_menu_rodape').val(),
		whatsapp_menu_msg_invalida: $('#whatsapp_menu_msg_invalida').val(),
		whatsapp_menu_palavras: $('#whatsapp_menu_palavras').val()
	}, function(res){
		if(!res || !res.success){
			Swal.fire('Erro', (res && res.message) ? res.message : 'Falha ao salvar.', 'error');
			return;
		}
		Swal.fire('Salvo', res.message, 'success');
		whatsappStatus();
	}, 'json');
}

function whatsappTestar(){
	$('#btn-wa-testar').prop('disabled', true);
	$.post(url_base + CONFIG_EMAIL_URL, {
		acao: 'whatsapp_testar',
		whatsapp_teste: $('#whatsapp_teste').val(),
		whatsapp_msg_teste: $('#whatsapp_msg_teste').val()
	}, function(res){
		$('#btn-wa-testar').prop('disabled', false);
		if(!res || !res.success){
			Swal.fire('Falha', (res && res.message) ? res.message : 'Não enviou.', 'error');
			return;
		}
		Swal.fire('Enviado', res.message, 'success');
	}, 'json').fail(function(){
		$('#btn-wa-testar').prop('disabled', false);
		Swal.fire('Erro', 'Falha ao testar.', 'error');
	});
}

function whatsappSincronizar(){
	$('#btn-wa-sincronizar').prop('disabled', true);
	$.post(url_base + CONFIG_EMAIL_URL, { acao: 'whatsapp_sincronizar' }, function(res){
		$('#btn-wa-sincronizar').prop('disabled', false);
		if(!res || !res.success){
			Swal.fire('Erro', (res && res.message) ? res.message : 'Falha ao sincronizar.', 'error');
			return;
		}
		if(res.whatsapp){
			preencherWhatsapp(res.whatsapp, { limparQr: !res.whatsapp.conectado });
		}
		Swal.fire('Sincronizado', res.message || 'OK', res.whatsapp && res.whatsapp.webhook_ok === false ? 'warning' : 'success');
	}, 'json').fail(function(){
		$('#btn-wa-sincronizar').prop('disabled', false);
		Swal.fire('Erro', 'Falha na requisição.', 'error');
	});
}

function whatsappDesconectar(){
	Swal.fire({
		title: 'Desconectar WhatsApp?',
		html: 'Escolha como desconectar:<br>'
			+ '<small class="text-muted"><strong>Só desconectar</strong> — encerra a sessão no aparelho.<br>'
			+ '<strong>Remover instância</strong> — apaga na Evolution (recomendado se não conseguir reconectar).</small>',
		icon: 'warning',
		showDenyButton: true,
		showCancelButton: true,
		confirmButtonText: 'Só desconectar',
		denyButtonText: 'Remover instância',
		cancelButtonText: 'Cancelar'
	}).then(function(r){
		if (r.isDismissed) return;
		var apagar = r.isDenied ? 1 : 0;
		$.post(url_base + CONFIG_EMAIL_URL, { acao: 'whatsapp_desconectar', apagar_instancia: apagar }, function(res){
			if(!res || !res.success){
				Swal.fire({
					title: 'Erro',
					html: (res && res.message) ? res.message : 'Falha ao desconectar.',
					icon: 'error',
					footer: apagar ? '' : 'Tente “Remover instância” ou “Trocar número”.'
				});
				return;
			}
			pararPareamentoWa();
			mostrarQr(null, false);
			whatsappStatus();
			Swal.fire(apagar ? 'Removido' : 'OK', res.message, apagar ? 'success' : (res.warning ? 'warning' : 'success'));
		}, 'json');
	});
}

function whatsappRecriar(){
	Swal.fire({
		title: 'Trocar número / recriar?',
		html: 'Isso <strong>apaga a instância</strong> na Evolution e gera um QR novo para parear outro WhatsApp.<br>Use também se você excluiu a instância pelo painel da Evolution.',
		icon: 'warning',
		showCancelButton: true,
		confirmButtonText: 'Recriar e gerar QR',
		cancelButtonText: 'Cancelar'
	}).then(function(r){
		if(!r.isConfirmed) return;
		$('#btn-wa-recriar, #btn-wa-conectar').prop('disabled', true);
		Swal.fire({ title: 'Recriando instância...', allowOutsideClick: false, didOpen: function(){ Swal.showLoading(); } });
		$.post(url_base + CONFIG_EMAIL_URL, { acao: 'whatsapp_recriar' }, function(res){
			$('#btn-wa-recriar, #btn-wa-conectar').prop('disabled', false);
			Swal.close();
			if(!res || !res.success){
				pararPareamentoWa();
				Swal.fire({
					title: 'Erro',
					html: '<div style="text-align:left;word-break:break-word;font-size:13px;">'
						+ $('<div>').text((res && res.message) ? res.message : 'Falha ao recriar.').html()
						+ '</div>',
					icon: 'error',
					width: 640
				});
				return;
			}
			aplicarRespostaWhatsapp(res);
			if(res.whatsapp && res.whatsapp.conectado){
				pararPareamentoWa();
				Swal.fire('WhatsApp', res.message || 'Já conectado.', 'success');
			} else {
				Swal.fire({
					title: 'Escaneie o QR agora',
					html: 'Instância recriada. No celular: <strong>WhatsApp → Aparelhos conectados → Conectar</strong>.<br>'
						+ '<small class="text-muted">Faça o scan em até ~40s. Não use o painel da Evolution ao mesmo tempo.</small>',
					icon: 'info'
				});
			}
		}, 'json').fail(function(){
			$('#btn-wa-recriar, #btn-wa-conectar').prop('disabled', false);
			Swal.close();
			Swal.fire('Erro', 'Falha na requisição.', 'error');
		});
	});
}

function formatUltimaExecucaoWorker(run){
	if(!run || !run.created_at){
		return 'Nunca registrado';
	}
	let txt = run.created_at;
	if(run.origem){
		txt += ' ('+run.origem+')';
	}
	const parts = [];
	if(run.enviados != null) parts.push(run.enviados+' enviado(s)');
	if(run.erros != null && parseInt(run.erros, 10) > 0) parts.push(run.erros+' erro(s)');
	if(parts.length) txt += ' — '+parts.join(', ');
	return txt;
}

function preencherCronComunicacao(cron){
	const cob = cron.cobranca || {};
	const aniv = cron.aniversario || {};
	let htmlCob = '<strong>Cron diário (08:00):</strong> ';
	if(cob.url_cron){
		htmlCob += '<code>'+cob.url_cron+'</code>';
	} else {
		htmlCob += '<code>'+(cob.cron_cli || 'php worker/cobranca.php')+'</code>';
	}
	htmlCob += '<br><span class="text-muted">Última execução: '+formatUltimaExecucaoWorker(cob.ultima)+'</span>';
	if(!cron.worker_ok){
		htmlCob += '<br><span class="text-warning">Execute <code>database/comunicacao_fase6.sql</code> para registrar execuções.</span>';
	}
	$('#cobranca-cron-hint').html(htmlCob);

	let htmlAniv = '<strong>Cron diário (08:05):</strong> ';
	if(aniv.url_cron){
		htmlAniv += '<code>'+aniv.url_cron+'</code>';
	} else {
		htmlAniv += '<code>'+(aniv.cron_cli || 'php worker/aniversario.php')+'</code>';
	}
	htmlAniv += '<br><span class="text-muted">Última execução: '+formatUltimaExecucaoWorker(aniv.ultima)+'</span>';
	if(cron.hint){
		htmlAniv += '<br><span class="text-muted">'+cron.hint+'</span>';
	}
	$('#aniversario-cron-hint').html(htmlAniv);

	let htmlAudit = '';
	if(cob.url_cron){
		htmlAudit += '<p class="mb-2"><strong>Cobrança (08:00)</strong><br><code class="small">'+cob.url_cron+'</code><br>'
			+'<span class="text-muted">'+formatUltimaExecucaoWorker(cob.ultima)+'</span></p>';
	}
	if(aniv.url_cron){
		htmlAudit += '<p class="mb-2"><strong>Aniversário (08:05)</strong><br><code class="small">'+aniv.url_cron+'</code><br>'
			+'<span class="text-muted">'+formatUltimaExecucaoWorker(aniv.ultima)+'</span></p>';
	}
	if(cron.hint){
		htmlAudit += '<p class="text-muted mb-0">'+cron.hint+'</p>';
	}
	if(!cron.worker_ok){
		htmlAudit += '<p class="text-warning mb-0 mt-2">Execute <code>database/comunicacao_fase6.sql</code> para registrar execuções.</p>';
	}
	$('#audit-cron-resumo').html(htmlAudit || '<span class="text-muted">Sem dados de cron.</span>');
}

function preencherAniversario(data){
	const a = data.aniversario || {};
	const tpl = data.templates_aniversario || a.templates || {};

	if(!a.colunas_ok || !a.log_ok){
		$('#alert-aniversario-sql').removeClass('d-none').html(
			'Execute o SQL de aniversário no phpMyAdmin para habilitar esta função.'
		);
	} else {
		$('#alert-aniversario-sql').addClass('d-none');
	}

	$('#aniversario_ativo').prop('checked', parseInt(a.aniversario_ativo, 10) === 1);
	$('#aniversario_whatsapp_ativo').prop('checked', parseInt(a.aniversario_whatsapp_ativo, 10) === 1);
	$('#aniversario_apenas_matriculados').prop('checked', parseInt(a.aniversario_apenas_matriculados, 10) === 1);
	$('#aniversario_assunto').val(a.aniversario_assunto || tpl.assunto || '');
	$('#aniversario_mensagem').val(a.aniversario_mensagem || tpl.mensagem || '');
	if(!a.wa_automacao_ok){
		$('#alert-aniversario-sql').removeClass('d-none').append(
			'<br>Para WhatsApp automático, execute: <code>ALTER TABLE escola_integracoes ADD COLUMN aniversario_whatsapp_ativo TINYINT(1) NOT NULL DEFAULT 0;</code>'
		);
	}
}

function preencherCobranca(data){
	const c = data.cobranca || {};
	const tpl = data.templates_padrao || {};

	if(!c.colunas_ok || !c.log_ok){
		$('#alert-cobranca-sql').removeClass('d-none').html(
			'Execute o SQL de cobrança automática no phpMyAdmin para habilitar esta função.'
		);
	} else {
		$('#alert-cobranca-sql').addClass('d-none');
	}

	$('#cobranca_ativo').prop('checked', parseInt(c.cobranca_ativo, 10) === 1);
	$('#cobranca_dias_antes').val(c.cobranca_dias_antes || '3,5');
	$('#cobranca_aviso_vencimento').prop('checked', parseInt(c.cobranca_aviso_vencimento, 10) === 1);
	$('#cobranca_dias_depois').val(c.cobranca_dias_depois || '1,3,7');
	$('#cobranca_enviar_responsavel').prop('checked', parseInt(c.cobranca_enviar_responsavel, 10) === 1);
	$('#cobranca_whatsapp_ativo').prop('checked', parseInt(c.cobranca_whatsapp_ativo, 10) === 1);
	if(!c.wa_automacao_ok){
		$('#alert-cobranca-sql').removeClass('d-none').append(
			'<br>Para WhatsApp automático, execute: <code>ALTER TABLE escola_integracoes ADD COLUMN cobranca_whatsapp_ativo TINYINT(1) NOT NULL DEFAULT 0;</code>'
		);
	}

	$('#cobranca_assunto_antes').val(c.cobranca_assunto_antes || tpl.assunto_antes || '');
	$('#cobranca_assunto_vencimento').val(c.cobranca_assunto_vencimento || tpl.assunto_vencimento || '');
	$('#cobranca_assunto_atraso').val(c.cobranca_assunto_atraso || tpl.assunto_atraso || '');
	$('#cobranca_msg_antes').val(c.cobranca_msg_antes || tpl.msg_antes || '');
	$('#cobranca_msg_vencimento').val(c.cobranca_msg_vencimento || tpl.msg_vencimento || '');
	$('#cobranca_msg_atraso').val(c.cobranca_msg_atraso || tpl.msg_atraso || '');
}

function coletarDadosFormulario(){
	return {
		acao: 'salvar',
		smtp_ativo: $('#smtp_ativo').is(':checked') ? 1 : 0,
		smtp_host: $('#smtp_host').val(),
		smtp_port: $('#smtp_port').val(),
		smtp_user: $('#smtp_user').val(),
		smtp_pass: $('#smtp_pass').val(),
		smtp_from_email: $('#smtp_from_email').val(),
		smtp_from_name: $('#smtp_from_name').val(),
		smtp_encryption: $('#smtp_encryption').val(),
		email_delay_segundos: $('#email_delay_segundos').val(),
		email_max_hora: $('#email_max_hora').val(),
		cobranca_ativo: $('#cobranca_ativo').is(':checked') ? 1 : 0,
		cobranca_dias_antes: $('#cobranca_dias_antes').val(),
		cobranca_aviso_vencimento: $('#cobranca_aviso_vencimento').is(':checked') ? 1 : 0,
		cobranca_dias_depois: $('#cobranca_dias_depois').val(),
		cobranca_enviar_responsavel: $('#cobranca_enviar_responsavel').is(':checked') ? 1 : 0,
		cobranca_whatsapp_ativo: $('#cobranca_whatsapp_ativo').is(':checked') ? 1 : 0,
		cobranca_assunto_antes: $('#cobranca_assunto_antes').val(),
		cobranca_assunto_vencimento: $('#cobranca_assunto_vencimento').val(),
		cobranca_assunto_atraso: $('#cobranca_assunto_atraso').val(),
		cobranca_msg_antes: $('#cobranca_msg_antes').val(),
		cobranca_msg_vencimento: $('#cobranca_msg_vencimento').val(),
		cobranca_msg_atraso: $('#cobranca_msg_atraso').val(),
		aniversario_ativo: $('#aniversario_ativo').is(':checked') ? 1 : 0,
		aniversario_whatsapp_ativo: $('#aniversario_whatsapp_ativo').is(':checked') ? 1 : 0,
		aniversario_apenas_matriculados: $('#aniversario_apenas_matriculados').is(':checked') ? 1 : 0,
		aniversario_assunto: $('#aniversario_assunto').val(),
		aniversario_mensagem: $('#aniversario_mensagem').val()
	};
}

function carregarConfiguracao(){
	$.post(url_base + CONFIG_EMAIL_URL, { acao: 'carregar' }, function(res){
		if(!res || !res.success){
			Swal.fire('Erro', (res && res.message) ? res.message : 'Não foi possível carregar.', 'error');
			return;
		}
		preencherFormulario(res);
		if(res.aviso){
			Swal.fire('Atenção', res.aviso, 'warning');
		}
	}, 'json').fail(function(xhr){
		let msg = 'Falha ao carregar configurações.';
		if(xhr && xhr.responseText && xhr.responseText.indexOf('escola_integracoes') !== -1){
			msg = 'A tabela escola_integracoes não existe. Execute o SQL de criação no phpMyAdmin.';
		}
		Swal.fire('Erro', msg, 'error');
	});
}

function salvarConfiguracao(){
	const dados = coletarDadosFormulario();

	$.post(url_base + CONFIG_EMAIL_URL, dados, function(res){
		if(!res || !res.success){
			Swal.fire('Erro', (res && res.message) ? res.message : 'Não foi possível salvar.', 'error');
			return;
		}
		Swal.fire('Salvo', res.message, 'success');
		carregarConfiguracao();
	}, 'json').fail(function(){
		Swal.fire('Erro', 'Falha ao salvar configurações.', 'error');
	});
}

function testarEmail(){
	const dados = coletarDadosFormulario();
	dados.acao = 'testar';
	dados.email_teste = $('#email_teste').val();

	$('#btn-testar-email').prop('disabled', true);

	$.post(url_base + CONFIG_EMAIL_URL, dados, function(res){
		$('#btn-testar-email').prop('disabled', false);

		if(!res || !res.success){
			Swal.fire('Falha no teste', (res && res.message) ? res.message : 'Não foi possível enviar.', 'error');
			return;
		}

		Swal.fire('Enviado', res.message, 'success');
	}, 'json').fail(function(){
		$('#btn-testar-email').prop('disabled', false);
		Swal.fire('Erro', 'Falha ao testar envio.', 'error');
	});
}

function previewCobranca(){
	const dados = coletarDadosFormulario();
	dados.acao = 'preview_cobranca';

	$('#btn-preview-cobranca').prop('disabled', true);
	$('#preview-cobranca-resultado').html('<span class="text-muted">Simulando...</span>');

	$.post(url_base + CONFIG_EMAIL_URL, dados, function(res){
		$('#btn-preview-cobranca').prop('disabled', false);

		if(!res || !res.success){
			Swal.fire('Erro', (res && res.message) ? res.message : 'Falha na simulação.', 'error');
			$('#preview-cobranca-resultado').html('');
			return;
		}

		const p = res.preview || {};

		if(!p.ok){
			const msg = p.message || 'Não foi possível simular. Verifique o SQL de cobrança.';
			$('#preview-cobranca-resultado').html('<span class="text-danger">'+msg+'</span>');
			Swal.fire('Atenção', msg, 'warning');
			return;
		}

		let html = '<strong>Hoje seriam enviados:</strong> '+(p.total || 0)+' e-mail(s).';
		if(!p.cobranca_ativa){
			html += ' <span class="text-muted">(simulação — cobrança ainda desativada)</span>';
		}
		if(p.enviados_hoje){
			html += '<br><small>Já enviados hoje (log): '+p.enviados_hoje+'.</small>';
		}
		if(p.itens && p.itens.length){
			html += '<ul class="mb-0 mt-2">';
			p.itens.slice(0, 10).forEach(function(i){
				const emails = (i.emails && i.emails.length) ? i.emails.join(', ') : 'sem e-mail';
				html += '<li>'+i.nome+' — '+i.label+' <small>('+emails+')</small></li>';
			});
			if(p.itens.length > 10){
				html += '<li>... e mais '+(p.itens.length - 10)+'</li>';
			}
			html += '</ul>';
		} else {
			html += '<p class="mb-0 mt-2 text-muted">Nenhum título em aberto coincide com as regras de hoje (ou já foram avisados).</p>';
		}

		$('#preview-cobranca-resultado').html(html);

		Swal.fire({
			title: 'Simulação de hoje',
			html: html,
			icon: (p.total > 0) ? 'info' : 'question',
			confirmButtonText: 'OK'
		});
	}, 'json').fail(function(xhr){
		$('#btn-preview-cobranca').prop('disabled', false);
		$('#preview-cobranca-resultado').html('');
		let msg = 'Falha na simulação.';
		if(xhr && xhr.responseText && xhr.responseText.indexOf('cobranca_') !== -1){
			msg = 'Colunas de cobrança não existem. Execute o SQL no phpMyAdmin.';
		}
		Swal.fire('Erro', msg, 'error');
	});
}

function executarCobranca(){
	Swal.fire({
		title: 'Enviar cobranças agora?',
		text: 'Serão enviados os avisos pendentes de hoje.',
		icon: 'question',
		showCancelButton: true,
		confirmButtonText: 'Enviar'
	}).then(function(r){
		if(!r.isConfirmed) return;
		$('#btn-executar-cobranca').prop('disabled', true);
		Swal.fire({ title: 'Enviando...', allowOutsideClick: false, didOpen: function(){ Swal.showLoading(); } });
		$.post(url_base + CONFIG_EMAIL_URL, { acao: 'executar_cobranca' }, function(res){
			$('#btn-executar-cobranca').prop('disabled', false);
			Swal.close();
			if(!res || !res.success){
				Swal.fire('Erro', (res && res.message) ? res.message : 'Falha no envio.', 'error');
				return;
			}
			Swal.fire('Concluído', res.message, 'success');
			previewCobranca();
			carregarConfiguracao();
		}, 'json');
	});
}

function montarBlocoAuditoria(titulo, bloco){
	if(!bloco){
		return '';
	}
	let html = '<h6 class="mt-3 mb-2">'+titulo+'</h6>';
	html += '<p class="mb-1"><strong>'+(bloco.total || 0)+'</strong> problema(s) encontrado(s).</p>';
	if(bloco.por_motivo){
		html += '<ul class="small text-start mb-2">';
		Object.keys(bloco.por_motivo).forEach(function(m){
			html += '<li>'+m+': <strong>'+bloco.por_motivo[m]+'</strong></li>';
		});
		html += '</ul>';
	}
	if(bloco.itens && bloco.itens.length){
		html += '<div class="table-responsive" style="max-height:220px;"><table class="table table-sm table-striped text-start mb-0">';
		html += '<thead><tr><th>Tipo</th><th>Nome</th><th>Contato</th><th>Motivo</th></tr></thead><tbody>';
		bloco.itens.forEach(function(i){
			const contato = i.contato || i.email || '—';
			html += '<tr><td>'+i.tipo+'</td><td>'+i.nome+'</td><td><code>'+contato+'</code></td><td class="small">'+i.motivo+'</td></tr>';
		});
		html += '</tbody></table></div>';
	} else {
		html += '<p class="text-success small mb-0">Nenhum problema detectado na amostra.</p>';
	}
	if(bloco.truncado){
		html += '<p class="small text-muted mt-1">Lista limitada a 150 registros.</p>';
	}
	return html;
}

function auditarEmails(){
	$('#btn-auditar-emails').prop('disabled', true);
	$.post(url_base + CONFIG_EMAIL_URL, { acao: 'auditar_emails' }, function(res){
		$('#btn-auditar-emails').prop('disabled', false);

		if(!res || !res.success){
			Swal.fire('Erro', (res && res.message) ? res.message : 'Falha na auditoria.', 'error');
			return;
		}

		const a = res.auditoria || {};
		let html = '<p class="mb-2">Total: <strong>'+(a.total || 0)+'</strong> problema(s) em e-mails e WhatsApp.</p>';
		html += montarBlocoAuditoria('E-mails inválidos ou fictícios', a.emails);
		html += montarBlocoAuditoria('WhatsApp inválido', a.whatsapp);
		html += '<p class="small text-muted mt-2 mb-0">Corrija nos cadastros de Alunos, Responsáveis e Leads antes de campanhas ou automações.</p>';

		Swal.fire({
			title: 'Auditoria de contatos',
			html: html,
			width: 760,
			confirmButtonText: 'OK'
		});
	}, 'json').fail(function(){
		$('#btn-auditar-emails').prop('disabled', false);
		Swal.fire('Erro', 'Falha ao auditar contatos.', 'error');
	});
}

function previewAniversario(){
	const dados = coletarDadosFormulario();
	dados.acao = 'preview_aniversario';

	$('#btn-preview-aniversario').prop('disabled', true);
	$.post(url_base + CONFIG_EMAIL_URL, dados, function(res){
		$('#btn-preview-aniversario').prop('disabled', false);
		if(!res || !res.success){
			Swal.fire('Erro', (res && res.message) ? res.message : 'Falha na simulação.', 'error');
			return;
		}
		const p = res.preview || {};
		if(!p.ok){
			Swal.fire('Atenção', p.message || 'SQL pendente.', 'warning');
			return;
		}
		let html = '<strong>Aniversariantes hoje:</strong> '+(p.total || 0);
		if(!p.ativo){
			html += ' <span class="text-muted">(simulação — automação desativada)</span>';
		}
		if(p.itens && p.itens.length){
			html += '<ul class="mb-0 mt-2 text-start">';
			p.itens.slice(0, 10).forEach(function(i){
				html += '<li>'+i.nome+' &lt;'+i.contato+'&gt;</li>';
			});
			html += '</ul>';
		}
		$('#preview-aniversario-resultado').html(html);
		Swal.fire({ title: 'Aniversariantes de hoje', html: html, icon: 'info' });
	}, 'json');
}

function executarAniversario(){
	Swal.fire({
		title: 'Enviar aniversários agora?',
		text: 'Serão enviados os e-mails pendentes de hoje.',
		icon: 'question',
		showCancelButton: true,
		confirmButtonText: 'Enviar'
	}).then(function(r){
		if(!r.isConfirmed) return;
		$('#btn-executar-aniversario').prop('disabled', true);
		Swal.fire({ title: 'Enviando...', allowOutsideClick: false, didOpen: function(){ Swal.showLoading(); } });
		$.post(url_base + CONFIG_EMAIL_URL, { acao: 'executar_aniversario' }, function(res){
			$('#btn-executar-aniversario').prop('disabled', false);
			Swal.close();
			if(!res || !res.success){
				Swal.fire('Erro', (res && res.message) ? res.message : 'Falha no envio.', 'error');
				return;
			}
			Swal.fire('Concluído', res.message, 'success');
			previewAniversario();
			carregarConfiguracao();
		}, 'json');
	});
}

function ativarAbaComunicacao(chave){
	const alvo = COM_TAB_MAP[String(chave || '').toLowerCase()];
	if(!alvo) return;
	const btn = document.querySelector('#com-nav-tabs button[data-bs-target="'+alvo+'"]');
	if(btn && window.bootstrap && bootstrap.Tab){
		bootstrap.Tab.getOrCreateInstance(btn).show();
	}
}

function lerAbaComunicacaoUrl(){
	try {
		const params = new URLSearchParams(window.location.search || '');
		const tab = params.get('tab');
		if(tab) ativarAbaComunicacao(tab);
	} catch (e) {}
}

$(function(){
	carregarConfiguracao();
	lerAbaComunicacaoUrl();

	$('#com-nav-tabs button[data-bs-toggle="tab"]').on('shown.bs.tab', function(e){
		const target = $(e.target).attr('data-bs-target') || '';
		const chave = COM_TAB_REV[target];
		if(!chave) return;
		try {
			const url = new URL(window.location.href);
			url.searchParams.set('tab', chave);
			history.replaceState(null, '', url.pathname + url.search);
		} catch (err) {}
	});

	$('#btn-preset-gmail').on('click', function(){ aplicarPreset('gmail'); });
	$('#btn-preset-outlook').on('click', function(){ aplicarPreset('outlook'); });
	$('#btn-preset-corp').on('click', function(){ aplicarPreset('corp'); });
	$('#btn-salvar-smtp, #btn-salvar-automacoes').on('click', salvarConfiguracao);
	$('#btn-testar-email').on('click', testarEmail);
	$('#btn-preview-cobranca').on('click', previewCobranca);
	$('#btn-executar-cobranca').on('click', executarCobranca);
	$('#btn-auditar-emails').on('click', auditarEmails);
	$('#btn-preview-aniversario').on('click', previewAniversario);
	$('#btn-executar-aniversario').on('click', executarAniversario);
	$('#btn-wa-status').on('click', whatsappStatus);
	$('#btn-wa-checklist').on('click', whatsappStatus);
	$('#btn-wa-sincronizar').on('click', whatsappSincronizar);
	$('#btn-wa-conectar').on('click', whatsappConectar);
	$('#btn-wa-recriar').on('click', whatsappRecriar);
	$('#btn-wa-qr').on('click', whatsappQr);
	$('#btn-wa-salvar').on('click', whatsappSalvar);
	$('#btn-wa-testar').on('click', whatsappTestar);
	$('#btn-wa-desconectar').on('click', whatsappDesconectar);
	$('#whatsapp_menu_ativo').on('change', toggleMenuManualUi);
	$('.wa-menu-field, #whatsapp_menu_titulo, #whatsapp_menu_rodape').on('input', atualizarPreviewMenuWa);
});
