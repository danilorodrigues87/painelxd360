function postEad(data) {
	return $.ajax({
		url: url_base + 'painel/ead',
		method: 'POST',
		dataType: 'json',
		data: data
	});
}

function badgeStatus(status) {
	if (status === 'publicado') return '<span class="badge bg-success">Publicado</span>';
	if (status === 'rascunho') return '<span class="badge bg-warning text-dark">Rascunho</span>';
	return '<span class="badge bg-secondary">Sem conteúdo</span>';
}

function carregarListaEad() {
	postEad({ acao: 'listar' }).done(function (res) {
		if (!res || !res.success) {
			if (res && res.sql_ok === false) {
				$('#alert-sql-ead').removeClass('d-none');
			}
			$('#ead-tbody').html('<tr><td colspan="6" class="text-danger">' + ((res && res.message) || 'Falha ao listar') + '</td></tr>');
			return;
		}
		$('#alert-sql-ead').addClass('d-none');
		if (res.xp_ok === false) {
			$('#alert-sql-xp').removeClass('d-none');
		} else {
			$('#alert-sql-xp').addClass('d-none');
		}
		if (res.matricula_ead_ok === false) {
			$('#alert-sql-ead').removeClass('d-none');
		}
		if (!res.itens || !res.itens.length) {
			$('#ead-tbody').html('<tr><td colspan="6" class="text-muted">Nenhum curso EAD. Clique em Novo curso.</td></tr>');
		} else {
			var html = '';
			res.itens.forEach(function (item) {
				var vit = !item.vitrine_ativo
					? '<span class="text-muted">—</span>'
					: (Number(item.vitrine_preco_mensal || 0) <= 0
						? '<span class="badge bg-success">Gratuito</span>'
						: '<span class="badge bg-info text-dark">R$ ' + Number(item.vitrine_preco_mensal || 0).toFixed(2) + '/mês</span>');
				html += '<tr>' +
					'<td>' + $('<div>').text(item.nome).html() + '</td>' +
					'<td>' + (item.carga_h || '—') + '</td>' +
					'<td>' + (item.aulas || 0) + '</td>' +
					'<td>' + badgeStatus(item.status) + '</td>' +
					'<td>' + vit + '</td>' +
					'<td class="text-nowrap">' +
					'<a class="btn btn-sm btn-primary me-1" href="' + url_base + 'painel/ead/curso/' + item.id_curso + '">Metadados</a>' +
					'<button type="button" class="btn btn-sm btn-outline-secondary btn-abrir-editor" data-id="' + item.id_curso + '">Abrir editor</button>' +
					'</td>' +
					'</tr>';
			});
			$('#ead-tbody').html(html);
		}
		renderCtiLista(res.cti_itens || []);
	}).fail(function () {
		$('#ead-tbody').html('<tr><td colspan="6" class="text-danger">Erro de rede.</td></tr>');
	});
}

function renderCtiLista(itens) {
	var $card = $('#card-cursos-cti');
	var $tb = $('#ead-cti-tbody');
	if (!itens || !itens.length) {
		$card.addClass('d-none');
		return;
	}
	$card.removeClass('d-none');
	var html = '';
	itens.forEach(function (item) {
		html += '<tr>' +
			'<td>' + $('<div>').text(item.nome).html() + ' <span class="badge bg-info text-dark ms-1">CTI</span></td>' +
			'<td>' + (item.carga_h || '—') + '</td>' +
			'<td>' + (item.aulas || 0) + '</td>' +
			'<td>' + badgeStatus(item.status) + '</td>' +
			'<td class="text-nowrap">' +
			'<button type="button" class="btn btn-sm btn-primary btn-matricular-cti" data-id="' + item.id_curso + '" data-nome="' + $('<div>').text(item.nome).html() + '">Matricular alunos</button>' +
			'</td></tr>';
	});
	$tb.html(html);
}

function carregarMatriculasCti() {
	var id = parseInt($('#cti_matricula_curso_id').val(), 10);
	if (!id) return;
	postEad({ acao: 'listar_matriculas_ead', id_curso: id }).done(function (res) {
		var $box = $('#cti_lista_matriculas');
		if (!res || !res.success || !res.itens || !res.itens.length) {
			$box.html('<span class="text-muted">Nenhum aluno matriculado.</span>');
			return;
		}
		var html = '<ul class="list-group list-group-flush">';
		res.itens.forEach(function (m) {
			html += '<li class="list-group-item d-flex justify-content-between align-items-center py-2">' +
				'<span>' + $('<div>').text(m.nome).html() + ' <small class="text-muted">' + $('<div>').text(m.email || '').html() + '</small></span>' +
				'<button type="button" class="btn btn-sm btn-outline-danger btn-desmatricular-cti" data-aluno="' + m.id_aluno + '">Remover</button></li>';
		});
		html += '</ul>';
		$box.html(html);
	});
}

function abrirModalMatriculaCti(id, nome) {
	$('#cti_matricula_curso_id').val(id);
	$('#modalMatriculaCtiTitulo').text('Matrículas — ' + (nome || 'Curso CTI'));
	$('#cti_busca_aluno').val('');
	$('#cti_busca_resultados').empty();
	carregarMatriculasCti();
	$('#modalMatriculaCti').modal('show');
}

$(function () {
	carregarListaEad();
	$(document).on('click', '.btn-abrir-editor', function () {
		var id = $(this).data('id');
		var $b = $(this).prop('disabled', true);
		postEad({ acao: 'abrir_editor', id_curso: id }).done(function (res) {
			if (!res || !res.success || !res.url) {
				Swal.fire('Erro', (res && res.message) || 'Não foi possível abrir o editor.', 'error');
				return;
			}
			window.open(res.url, '_blank');
		}).fail(function () {
			Swal.fire('Erro', 'Falha de rede.', 'error');
		}).always(function () {
			$b.prop('disabled', false);
		});
	});
	$('#btn-novo-curso').on('click', function () {
		Swal.fire({
			title: 'Novo curso EAD',
			input: 'text',
			inputLabel: 'Título',
			inputValue: 'Novo curso',
			showCancelButton: true,
			confirmButtonText: 'Criar'
		}).then(function (r) {
			if (!r.isConfirmed) return;
			postEad({ acao: 'criar_curso', titulo: r.value || 'Novo curso' }).done(function (res) {
				if (!res || !res.success) {
					Swal.fire('Erro', (res && res.message) || 'Falha', 'error');
					return;
				}
				window.location.href = url_base + 'painel/ead/curso/' + res.id_curso;
			});
		});
	});

	$(document).on('click', '.btn-matricular-cti', function () {
		abrirModalMatriculaCti($(this).data('id'), $(this).data('nome'));
	});

	$('#btn-cti-buscar-aluno').on('click', function () {
		var q = ($('#cti_busca_aluno').val() || '').trim();
		if (q.length < 2) {
			Swal.fire('Atenção', 'Digite ao menos 2 caracteres.', 'warning');
			return;
		}
		postEad({ acao: 'buscar_alunos', q: q }).done(function (res) {
			var $box = $('#cti_busca_resultados').empty();
			if (!res || !res.success || !res.itens || !res.itens.length) {
				$box.html('<p class="text-muted small mb-0">Nenhum aluno encontrado.</p>');
				return;
			}
			res.itens.forEach(function (a) {
				$box.append(
					'<div class="d-flex justify-content-between align-items-center border rounded p-2 mb-2">' +
					'<span>' + $('<div>').text(a.nome).html() + ' <small class="text-muted">' + $('<div>').text(a.email).html() + '</small></span>' +
					'<button type="button" class="btn btn-sm btn-success btn-matricular-aluno-cti" data-id="' + a.id + '">Matricular</button></div>'
				);
			});
		});
	});

	$(document).on('click', '.btn-matricular-aluno-cti', function () {
		var idCurso = parseInt($('#cti_matricula_curso_id').val(), 10);
		var idAluno = parseInt($(this).data('id'), 10);
		postEad({ acao: 'matricular_ead', id_curso: idCurso, id_aluno: idAluno }).done(function (res) {
			if (!res || !res.success) {
				Swal.fire('Erro', (res && res.message) || 'Falha', 'error');
				return;
			}
			Swal.fire('OK', res.message || 'Matriculado.', 'success');
			carregarMatriculasCti();
		});
	});

	$(document).on('click', '.btn-desmatricular-cti', function () {
		var idCurso = parseInt($('#cti_matricula_curso_id').val(), 10);
		var idAluno = parseInt($(this).data('aluno'), 10);
		postEad({ acao: 'desmatricular_ead', id_curso: idCurso, id_aluno: idAluno }).done(function (res) {
			if (!res || !res.success) {
				Swal.fire('Erro', (res && res.message) || 'Falha', 'error');
				return;
			}
			carregarMatriculasCti();
		});
	});
});
