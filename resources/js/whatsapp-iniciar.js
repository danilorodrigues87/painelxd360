/**
 * Abre WhatsApp Web (wa.me) em nova aba.
 * @param {string} telefone
 */
function montarLinkWaMe(telefone){
	let n = String(telefone || '').replace(/\D+/g, '');
	if(!n) return '';
	if(n.length <= 11) n = '55' + n;
	return 'https://wa.me/' + n;
}

function abrirWhatsappWeb(telefone){
	const url = montarLinkWaMe(telefone);
	if(!url){
		Swal.fire('Atenção', 'Este cadastro não possui WhatsApp.', 'warning');
		return;
	}
	window.open(url, '_blank', 'noopener,noreferrer');
}

/**
 * Inicia atendimento no inbox. Se a escola não tem WhatsApp / não está conectado,
 * abre o WhatsApp Web padrão do navegador.
 * @param {string} telefone
 * @param {string} [nome]
 */
function iniciarAtendimentoWa(telefone, nome){
	const tel = String(telefone || '').trim();
	if(!tel){
		Swal.fire('Atenção', 'Este cadastro não possui WhatsApp.', 'warning');
		return;
	}

	Swal.fire({
		title: 'Abrindo...',
		allowOutsideClick: false,
		didOpen: function(){ Swal.showLoading(); }
	});

	$.post(url_base + 'painel/whatsapp', {
		acao: 'iniciar_atendimento',
		telefone: tel,
		nome: nome || ''
	}, function(res){
		Swal.close();
		if(res && res.success){
			const url = res.redirect || (url_base + 'painel/whatsapp?conversa=' + res.conversa_id);
			window.open(url, '_blank', 'noopener,noreferrer');
			return;
		}
		// Sem módulo / desconectado / indisponível → WhatsApp Web
		if(res && (res.usar_web || res.fallback_wa)){
			window.open(res.fallback_wa || montarLinkWaMe(tel), '_blank', 'noopener,noreferrer');
			return;
		}
		Swal.fire('Atenção', (res && res.message) || 'Não foi possível abrir o WhatsApp.', 'warning');
	}, 'json').fail(function(){
		// Rota bloqueada (módulo), 403, etc.
		Swal.close();
		abrirWhatsappWeb(tel);
	});
}
