const CONFIG_ESCOLA_URL = 'painel/config/empresa';
const LOGO_PADRAO = window.CONFIG_ESCOLA_LOGO_PADRAO || '';
const MODELO_CERT_PADRAO = window.CONFIG_ESCOLA_MODELO_CERT_PADRAO || '';

function esc(s){
	return $('<div>').text(s == null ? '' : String(s)).html();
}

function popularEstados(selected){
	const $sel = $('#escola_estado').empty();
	$sel.append('<option value="">Selecione</option>');
	(window.CONFIG_ESCOLA_ESTADOS || []).forEach(function(e){
		const label = e.sigla ? (e.sigla + ' — ' + e.nome) : e.nome;
		$sel.append('<option value="'+e.id+'">'+esc(label)+'</option>');
	});
	if(selected) $sel.val(String(selected));
}

function carregarCidades(estadoId, cidadeSelected){
	const $cid = $('#escola_cidade').empty();
	if(!estadoId){
		$cid.append('<option value="">Selecione o estado</option>');
		return;
	}
	$cid.append('<option value="">Carregando...</option>');
	$.post(url_base + CONFIG_ESCOLA_URL, { acao: 'cidades', estado: estadoId }, function(res){
		$cid.empty().append('<option value="">Selecione</option>');
		((res && res.cidades) || []).forEach(function(c){
			$cid.append('<option value="'+c.id+'">'+esc(c.nome)+'</option>');
		});
		if(cidadeSelected) $cid.val(String(cidadeSelected));
	}, 'json').fail(function(){
		$cid.empty().append('<option value="">Falha ao carregar</option>');
	});
}

function carregarEscola(){
	$.post(url_base + CONFIG_ESCOLA_URL, { acao: 'carregar' }, function(res){
		if(!res || !res.success){
			Swal.fire('Erro', (res && res.message) || 'Falha ao carregar.', 'error');
			return;
		}
		const e = res.escola;
		$('#escola_nome').val(e.nome || '');
		$('#escola_cpf_cnpj').val(e.cpf_cnpj || '');
		$('#escola_status').val(e.ativo ? 'Ativa' : 'Inativa');
		$('#escola_plano').val(e.plano_nome || 'Personalizado / todos os módulos');
		$('#escola_email').val(e.email || '');
		$('#escola_telefone').val(e.telefone || '');
		$('#escola_site').val(e.site || '');
		$('#escola_instagram').val(e.instagram || '');
		$('#escola_youtube').val(e.youtube || '');
		$('#escola_cep').val(e.cep || '');
		$('#escola_endereco').val(e.endereco || '');
		$('#escola_numero').val(e.numero || '');
		$('#escola_bairro').val(e.bairro || '');
		popularEstados(e.estado || '');
		carregarCidades(e.estado || '', e.cidade || '');
		$('#preview-escola-logo').attr('src', e.logo_url || LOGO_PADRAO);
		$('#preview-modelo-cert').attr('src', e.modelo_certificado_url || MODELO_CERT_PADRAO);
		if(String(window.CONFIG_ESCOLA_TEM_MODELO_CERT) !== '1'){
			$('#wrap-modelo-cert').addClass('d-none');
		}
	}, 'json').fail(function(){
		Swal.fire('Erro', 'Falha ao carregar dados da empresa.', 'error');
	});
}

function salvarEscola(){
	const fd = new FormData();
	fd.append('acao', 'salvar');
	fd.append('email', $('#escola_email').val() || '');
	fd.append('telefone', $('#escola_telefone').val() || '');
	fd.append('site', $('#escola_site').val() || '');
	fd.append('instagram', $('#escola_instagram').val() || '');
	fd.append('youtube', $('#escola_youtube').val() || '');
	fd.append('cep', $('#escola_cep').val() || '');
	fd.append('endereco', $('#escola_endereco').val() || '');
	fd.append('numero', $('#escola_numero').val() || '');
	fd.append('bairro', $('#escola_bairro').val() || '');
	fd.append('estado', $('#escola_estado').val() || '');
	fd.append('cidade', $('#escola_cidade').val() || '');
	const logo = $('#escola_logo')[0] && $('#escola_logo')[0].files[0];
	if(logo) fd.append('logo', logo);
	const modelo = $('#escola_modelo_certificado')[0] && $('#escola_modelo_certificado')[0].files[0];
	if(modelo) fd.append('modelo_certificado', modelo);

	$('#btn-salvar-escola').prop('disabled', true);
	$.ajax({
		url: url_base + CONFIG_ESCOLA_URL,
		method: 'POST',
		data: fd,
		processData: false,
		contentType: false,
		dataType: 'json'
	}).done(function(res){
		$('#btn-salvar-escola').prop('disabled', false);
		if(!res || !res.success){
			Swal.fire('Erro', (res && res.message) || 'Não foi possível salvar.', 'error');
			return;
		}
		$('#escola_logo, #escola_modelo_certificado').val('');
		if(res.logo_url) $('#preview-escola-logo').attr('src', res.logo_url);
		if(res.modelo_certificado_url) $('#preview-modelo-cert').attr('src', res.modelo_certificado_url);
		Swal.fire('OK', res.message, 'success');
	}).fail(function(){
		$('#btn-salvar-escola').prop('disabled', false);
		Swal.fire('Erro', 'Falha ao salvar.', 'error');
	});
}

$(function(){
	popularEstados('');
	carregarEscola();
	$('#escola_estado').on('change', function(){
		carregarCidades($(this).val() || '', '');
	});
	$('#btn-salvar-escola').on('click', salvarEscola);
});
