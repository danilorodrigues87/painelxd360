const MASTER_USUARIOS_URL = 'master/usuarios';

/** @type {number|null} */
let editingUsuarioId = null;

const $modal = () => $('#modalUsuarioMaster');

function esc(s) {
	return $('<div>').text(s == null ? '' : String(s)).html();
}

function limpar() {
	editingUsuarioId = null;
	const $m = $modal();
	$m.find('#master_usuario_id, #master_usuario_email_antigo').val('');
	$m.find('#master_usuario_nome, #master_usuario_email, #master_usuario_whatsapp, #master_usuario_cpf, #master_usuario_rg, #master_usuario_senha').val('');
	$m.find('#master_usuario_ativo').val('1');
	$m.find('.chk-master-perm').prop('checked', false);
	$('#titulo-modal-usuario').text('Novo operador');
	$('#wrap-senha-novo').removeClass('d-none');
	$m.find('input, select').prop('disabled', false);
	$('#btn-salvar-usuario-master').prop('disabled', false);
}

function marcarPermissoes(slugs) {
	const set = {};
	(slugs || []).forEach(function (s) { set[s] = true; });
	$modal().find('.chk-master-perm').each(function () {
		const name = $(this).attr('name') || '';
		const slug = name.replace(/^perm_master_/, '');
		$(this).prop('checked', !!set[slug]);
	});
}

function coletarPermissoesPayload() {
	const payload = {};
	$modal().find('.chk-master-perm').each(function () {
		if ($(this).is(':checked')) {
			payload[$(this).attr('name')] = 1;
		}
	});
	return payload;
}

function renderLista(usuarios) {
	const $tb = $('#lista-usuarios-master').empty();
	if (!usuarios || !usuarios.length) {
		$tb.append('<tr><td colspan="5" class="text-center text-muted py-4">Nenhum usuário.</td></tr>');
		return;
	}
	usuarios.forEach(function (u) {
		const badge = u.ativo ? '<span class="badge bg-success">Ativo</span>' : '<span class="badge bg-secondary">Inativo</span>';
		const superBadge = u.is_super ? ' <span class="badge bg-dark">super</span>' : '';
		const btns = u.is_super
			? '<span class="text-muted small">Meu perfil</span>'
			: '<button type="button" class="btn btn-sm btn-outline-primary me-1 btn-editar-usuario" data-id="' + u.id + '"><i class="fas fa-edit"></i></button>'
				+ '<button type="button" class="btn btn-sm btn-outline-warning me-1 btn-reset-senha-usuario" data-id="' + u.id + '"><i class="fas fa-key"></i></button>'
				+ '<button type="button" class="btn btn-sm btn-outline-danger btn-excluir-usuario" data-id="' + u.id + '"><i class="fas fa-trash"></i></button>';
		$tb.append(
			'<tr>'
			+ '<td><strong>' + esc(u.nome) + '</strong>' + superBadge + '</td>'
			+ '<td>' + esc(u.email) + '</td>'
			+ '<td>' + esc(u.cpf || '—') + '</td>'
			+ '<td>' + badge + '</td>'
			+ '<td class="text-end">' + btns + '</td>'
			+ '</tr>'
		);
	});
}

function carregar() {
	$.post(url_base + MASTER_USUARIOS_URL, { acao: 'listar' }, function (res) {
		if (!res || !res.success) {
			Swal.fire('Erro', (res && res.message) || 'Falha.', 'error');
			return;
		}
		renderLista(res.usuarios || []);
	}, 'json');
}

function abrir(id) {
	const uid = parseInt(id, 10);
	if (!uid) return;
	limpar();
	editingUsuarioId = uid;

	$.post(url_base + MASTER_USUARIOS_URL, { acao: 'detalhes', id: uid }, function (res) {
		if (!res || !res.success) {
			Swal.fire('Erro', (res && res.message) || 'Falha.', 'error');
			return;
		}
		const u = res.usuario;
		if (u.is_super) {
			Swal.fire('Info', 'Super-admins são editados em Meu perfil.', 'info');
			return;
		}
		const $m = $modal();
		editingUsuarioId = parseInt(u.id, 10);
		$m.find('#master_usuario_id').val(editingUsuarioId);
		$m.find('#master_usuario_email_antigo').val(u.email || '');
		$m.find('#master_usuario_nome').val(u.nome || '');
		$m.find('#master_usuario_email').val(u.email || '');
		$m.find('#master_usuario_whatsapp').val(u.whatsapp || '');
		$m.find('#master_usuario_cpf').val(u.cpf || '');
		$m.find('#master_usuario_rg').val(u.rg || '');
		$m.find('#master_usuario_ativo').val(u.ativo ? '1' : '0');
		marcarPermissoes(u.acesso || []);
		$('#titulo-modal-usuario').text('Editar operador');
		$('#wrap-senha-novo').addClass('d-none');
		$m.modal('show');
	}, 'json');
}

function salvar() {
	const $m = $modal();
	const isEdit = editingUsuarioId !== null && editingUsuarioId > 0;
	const dados = Object.assign({
		acao: 'salvar',
		modo: isEdit ? 'editar' : 'criar',
		id: editingUsuarioId || parseInt($m.find('#master_usuario_id').val(), 10) || 0,
		nome: $m.find('#master_usuario_nome').val(),
		email: $m.find('#master_usuario_email').val(),
		email_antigo: $m.find('#master_usuario_email_antigo').val(),
		whatsapp: $m.find('#master_usuario_whatsapp').val(),
		cpf: $m.find('#master_usuario_cpf').val(),
		rg: $m.find('#master_usuario_rg').val(),
		ativo: $m.find('#master_usuario_ativo').val(),
		senha: $m.find('#master_usuario_senha').val()
	}, coletarPermissoesPayload());

	if (!String(dados.nome || '').trim()) {
		Swal.fire('Atenção', 'Informe o nome.', 'warning');
		return;
	}

	$.post(url_base + MASTER_USUARIOS_URL, dados, function (res) {
		if (!res || !res.success) {
			Swal.fire('Erro', (res && res.message) || 'Falha.', 'error');
			return;
		}
		$m.modal('hide');
		Swal.fire('OK', res.message, 'success');
		carregar();
	}, 'json');
}

$(function () {
	carregar();
	$('#btn-novo-usuario-master').on('click', function () {
		limpar();
		$modal().modal('show');
	});
	$('#btn-salvar-usuario-master').on('click', salvar);
	$(document).on('click', '.btn-editar-usuario', function () { abrir($(this).data('id')); });
	$(document).on('click', '.btn-excluir-usuario', function () {
		const id = $(this).data('id');
		Swal.fire({ title: 'Excluir operador?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Excluir' })
			.then(function (r) {
				if (!r.isConfirmed) return;
				$.post(url_base + MASTER_USUARIOS_URL, { acao: 'excluir', id: id }, function (res) {
					if (!res || !res.success) {
						Swal.fire('Erro', (res && res.message) || 'Falha.', 'error');
						return;
					}
					carregar();
				}, 'json');
			});
	});
	$(document).on('click', '.btn-reset-senha-usuario', function () {
		const id = $(this).data('id');
		Swal.fire({
			title: 'Nova senha',
			input: 'password',
			inputPlaceholder: 'Mín. 8 caracteres',
			showCancelButton: true,
			confirmButtonText: 'Redefinir'
		}).then(function (r) {
			if (!r.isConfirmed) return;
			$.post(url_base + MASTER_USUARIOS_URL, { acao: 'reset_senha', id: id, senha: r.value || '12345678' }, function (res) {
				if (!res || !res.success) {
					Swal.fire('Erro', (res && res.message) || 'Falha.', 'error');
					return;
				}
				Swal.fire('OK', res.message, 'success');
			}, 'json');
		});
	});
	$modal().on('hidden.bs.modal', limpar);
});
