const MASTER_DADOS_XD360_URL = 'master/dados-xd360';

let usuariosMasterCache = [];

function buscarCep() {
	const cep = String($('#xd360_cep').val() || '').replace(/\D/g, '');
	if (cep.length !== 8) return;
	$.getJSON('https://viacep.com.br/ws/' + cep + '/json/')
		.done(function (data) {
			if (!data || data.erro) {
				Swal.fire('CEP', 'CEP não encontrado.', 'warning');
				return;
			}
			if (data.logradouro) $('#xd360_endereco').val(data.logradouro);
			if (data.bairro) $('#xd360_bairro').val(data.bairro);
			if (data.localidade) $('#xd360_cidade').val(data.localidade);
			if (data.uf) $('#xd360_uf').val(String(data.uf).toUpperCase());
			if (!$('#xd360_foro').val() && data.localidade && data.uf) {
				$('#xd360_foro').val(data.localidade + '/' + data.uf);
			}
			$('#xd360_numero').trigger('focus');
		})
		.fail(function () {
			Swal.fire('CEP', 'Não foi possível consultar o CEP agora.', 'error');
		});
}

function popularSelectRepresentantes(usuarios, selectedId) {
	usuariosMasterCache = usuarios || [];
	const $sel = $('#xd360_rep_usuario').empty().append('<option value="">— Selecione —</option>');
	usuariosMasterCache.forEach(function (u) {
		const label = u.nome + (u.cpf ? ' · CPF ' + u.cpf : '') + (u.is_super ? ' (super)' : '');
		$sel.append('<option value="' + u.id + '">' + $('<div>').text(label).html() + '</option>');
	});
	if (selectedId) $sel.val(String(selectedId));
	atualizarPreviewRepresentante();
}

function atualizarPreviewRepresentante() {
	const id = parseInt($('#xd360_rep_usuario').val(), 10);
	const u = usuariosMasterCache.find(function (x) { return parseInt(x.id, 10) === id; });
	const $prev = $('#xd360_rep_preview');
	if (!u) {
		$prev.text('Selecione um usuário Master com CPF cadastrado.');
		return;
	}
	const parts = [u.nome];
	if (u.cpf) parts.push('CPF ' + u.cpf);
	if (u.email) parts.push(u.email);
	$prev.text(parts.join(' · '));
}

function preencherForm(d) {
	d = d || {};
	$('#xd360_razao').val(d.razao_social || '');
	$('#xd360_fantasia').val(d.nome_fantasia || '');
	$('#xd360_cnpj').val(d.cnpj || '');
	$('#xd360_email').val(d.email || '');
	$('#xd360_telefone').val(d.telefone || '');
	$('#xd360_site').val(d.site || '');
	$('#xd360_cep').val(d.cep || '');
	$('#xd360_endereco').val(d.endereco || '');
	$('#xd360_numero').val(d.numero || '');
	$('#xd360_bairro').val(d.bairro || '');
	$('#xd360_cidade').val(d.cidade_nome || '');
	$('#xd360_uf').val(d.uf || '');
	$('#xd360_foro').val(d.foro_comarca || '');
	$('#xd360_rep_cargo').val(d.rep_cargo || 'Administrador');
	popularSelectRepresentantes(window._usuariosMaster || [], d.rep_legal_usuario_id || 0);
	return $.Deferred().resolve().promise();
}

function atualizarBadge(completo, faltando) {
	const $b = $('#badge-dados-xd360');
	const $alert = $('#alert-dados-incompletos');
	if (completo) {
		$b.removeClass('bg-secondary bg-warning').addClass('bg-success').text('Completo');
		$alert.addClass('d-none');
		return;
	}
	$b.removeClass('bg-secondary bg-success').addClass('bg-warning').text('Incompleto');
	$alert.removeClass('d-none').html(
		'<strong>Cadastro incompleto para o contrato:</strong> ' + (faltando || []).join(', ')
		+ '. <a href="' + url_base + 'master/dados-xd360">Completar</a>.'
	);
}

function carregarDadosXd360() {
	$.post(url_base + MASTER_DADOS_XD360_URL, { acao: 'carregar' }, function (res) {
		if (!res || !res.success) {
			Swal.fire('Erro', (res && res.message) || 'Falha ao carregar.', 'error');
			return;
		}
		window._usuariosMaster = res.usuarios_master || [];
		preencherForm(res.dados).then(function () {
			atualizarBadge(res.completo, res.faltando);
		});
	}, 'json');
}

function salvarDadosXd360() {
	const payload = {
		acao: 'salvar',
		razao_social: $('#xd360_razao').val(),
		nome_fantasia: $('#xd360_fantasia').val(),
		cnpj: $('#xd360_cnpj').val(),
		email: $('#xd360_email').val(),
		telefone: $('#xd360_telefone').val(),
		site: $('#xd360_site').val(),
		cep: $('#xd360_cep').val(),
		endereco: $('#xd360_endereco').val(),
		numero: $('#xd360_numero').val(),
		bairro: $('#xd360_bairro').val(),
		uf: $('#xd360_uf').val(),
		cidade_nome: $('#xd360_cidade').val(),
		foro_comarca: $('#xd360_foro').val(),
		rep_legal_usuario_id: $('#xd360_rep_usuario').val(),
		rep_cargo: $('#xd360_rep_cargo').val(),
	};
	$('#btn-salvar-dados-xd360').prop('disabled', true);
	$.post(url_base + MASTER_DADOS_XD360_URL, payload, function (res) {
		$('#btn-salvar-dados-xd360').prop('disabled', false);
		if (!res || !res.success) {
			Swal.fire('Erro', (res && res.message) || 'Falha ao salvar.', 'error');
			return;
		}
		Swal.fire('Salvo', res.message, 'success');
		atualizarBadge(res.completo, res.faltando);
	}, 'json').fail(function () {
		$('#btn-salvar-dados-xd360').prop('disabled', false);
		Swal.fire('Erro', 'Falha ao salvar.', 'error');
	});
}

$(function () {
	carregarDadosXd360();
	$('#btn-salvar-dados-xd360').on('click', salvarDadosXd360);
	$('#xd360_cep').on('blur', buscarCep);
	$('#xd360_rep_usuario').on('change', atualizarPreviewRepresentante);
});
