const MASTER_EAD_CTI_URL = 'master/ead-cursos';

function esc(s) {
	return $('<div>').text(s == null ? '' : String(s)).html();
}

function postMaster(dados, ok) {
	$.post(url_base + MASTER_EAD_CTI_URL, dados, function (res) {
		if (!res || !res.success) {
			Swal.fire('Erro', (res && res.message) || 'Falha.', 'error');
			return;
		}
		if (typeof ok === 'function') {
			ok(res);
		}
	}, 'json').fail(function (xhr) {
		var msg = 'Falha na comunicação com o servidor.';
		if (xhr && xhr.responseText) {
			try {
				var j = JSON.parse(xhr.responseText);
				if (j && j.message) {
					msg = j.message;
				}
			} catch (e) { /* resposta não-JSON */ }
		}
		Swal.fire('Erro', msg, 'error');
	});
}

function badgeStatus(status) {
	if (status === 'publicado') return '<span class="badge bg-success">Publicado</span>';
	if (status === 'rascunho') return '<span class="badge bg-warning text-dark">Rascunho</span>';
	return '<span class="badge bg-secondary">Sem conteúdo</span>';
}

function renderLista(itens) {
	const $tb = $('#lista-cursos-cti').empty();
	if (!itens || !itens.length) {
		$tb.append('<tr><td colspan="5" class="text-center text-muted py-4">Nenhum curso CTI. Clique em Novo curso.</td></tr>');
		return;
	}
	itens.forEach(function (c) {
		var editorUrl = url_base + 'master/ead-cursos/editor/' + encodeURIComponent(c.id);
		$tb.append(
			'<tr>'
			+ '<td><strong>' + esc(c.nome) + '</strong></td>'
			+ '<td>' + esc(c.carga_h || '—') + '</td>'
			+ '<td>' + esc(c.aulas || 0) + '</td>'
			+ '<td>' + badgeStatus(c.status) + '</td>'
			+ '<td class="text-end text-nowrap">'
			+ '<a href="' + esc(editorUrl) + '" class="btn btn-sm btn-outline-primary me-1"><i class="fas fa-pen"></i> Editor</a>'
			+ '<button type="button" class="btn btn-sm btn-outline-secondary me-1 btn-toggle-pub-cti" data-id="' + c.id + '">' + (c.publicado ? 'Despublicar' : 'Publicar') + '</button>'
			+ '<button type="button" class="btn btn-sm btn-outline-danger btn-excluir-cti" data-id="' + c.id + '"><i class="fas fa-trash"></i></button>'
			+ '</td></tr>'
		);
	});
}

function carregar() {
	postMaster({ acao: 'listar' }, function (res) {
		renderLista(res.itens || []);
	});
}

function mostrarErroUrl() {
	try {
		var params = new URLSearchParams(window.location.search || '');
		var erro = params.get('erro');
		if (!erro) return;
		Swal.fire('Erro', decodeURIComponent(erro.replace(/\+/g, ' ')), 'error');
		if (window.history && window.history.replaceState) {
			window.history.replaceState({}, document.title, url_base + MASTER_EAD_CTI_URL);
		}
	} catch (e) { /* ignore */ }
}

$(function () {
	mostrarErroUrl();
	carregar();

	$('#btn-novo-curso-cti').on('click', function () {
		Swal.fire({
			title: 'Novo curso CTI',
			input: 'text',
			inputPlaceholder: 'Título do curso',
			inputValue: 'Novo curso CTI',
			showCancelButton: true,
			confirmButtonText: 'Criar'
		}).then(function (r) {
			if (!r.isConfirmed) return;
			postMaster({ acao: 'criar', titulo: r.value || '' }, function (res) {
				Swal.fire('OK', res.message || 'Criado.', 'success');
				carregar();
			});
		});
	});

	$(document).on('click', '.btn-toggle-pub-cti', function () {
		var id = $(this).data('id');
		postMaster({ acao: 'toggle_publicado', id: id }, function () {
			carregar();
		});
	});

	$(document).on('click', '.btn-excluir-cti', function () {
		var id = $(this).data('id');
		Swal.fire({
			title: 'Excluir curso CTI?',
			text: 'Escolas perderão o provisionamento deste curso.',
			icon: 'warning',
			showCancelButton: true,
			confirmButtonText: 'Excluir',
			confirmButtonColor: '#dc3545'
		}).then(function (r) {
			if (!r.isConfirmed) return;
			postMaster({ acao: 'excluir', id: id }, function () {
				carregar();
			});
		});
	});
});
