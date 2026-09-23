(function () {
	function post(data) {
		return $.ajax({
			url: url_base + 'painel/ead/progresso',
			method: 'POST',
			data: data,
			dataType: 'json',
		});
	}

	function esc(s) {
		return $('<div>').text(s == null ? '' : String(s)).html();
	}

	function filtros() {
		return {
			acao: 'listar',
			id_curso: $('#filtro-curso').val() || 0,
			q: $('#filtro-q').val() || '',
			status: $('#filtro-status').val() || 'all',
			min_pct: $('#filtro-min').val() || 0,
		};
	}

	function statusBadge(st) {
		if (st === 'completed') return '<span class="badge bg-success">Concluído</span>';
		if (st === 'in_progress') return '<span class="badge bg-primary">Em andamento</span>';
		return '<span class="badge bg-secondary">Não iniciado</span>';
	}

	function fillCursos(cursos) {
		var $sel = $('#filtro-curso');
		var cur = $sel.val() || '0';
		$sel.find('option:not(:first)').remove();
		(cursos || []).forEach(function (c) {
			$sel.append('<option value="' + esc(c.id) + '">' + esc(c.title) + '</option>');
		});
		$sel.val(cur);
	}

	function renderTotais(t) {
		t = t || {};
		$('#totais-turma').html(
			'<div class="col-6 col-md-3"><div class="border rounded p-2 small"><div class="text-muted">Alunos</div><strong>' +
				(t.alunos_unicos || 0) +
				'</strong></div></div>' +
				'<div class="col-6 col-md-3"><div class="border rounded p-2 small"><div class="text-muted">Concluídos</div><strong class="text-success">' +
				(t.concluidos || 0) +
				'</strong></div></div>' +
				'<div class="col-6 col-md-3"><div class="border rounded p-2 small"><div class="text-muted">Em andamento</div><strong class="text-primary">' +
				(t.em_andamento || 0) +
				'</strong></div></div>' +
				'<div class="col-6 col-md-3"><div class="border rounded p-2 small"><div class="text-muted">Não iniciados</div><strong>' +
				(t.nao_iniciados || 0) +
				'</strong></div></div>'
		);
	}

	function render(itens) {
		var $tb = $('#tbody-turma').empty();
		if (!itens || !itens.length) {
			$tb.html('<tr><td colspan="6" class="text-muted p-3">Nenhum resultado com os filtros atuais.</td></tr>');
			return;
		}
		itens.forEach(function (it) {
			var rewatch =
				it.rewatch_count > 0
					? ' <span class="badge bg-warning text-dark" title="Precisa reassistir">' +
						it.rewatch_count +
						'</span>'
					: '';
			$tb.append(
				'<tr>' +
					'<td><div>' +
					esc(it.aluno_nome) +
					'</div><div class="small text-muted">' +
					esc(it.aluno_email) +
					'</div></td>' +
					'<td>' +
					esc(it.curso_titulo) +
					rewatch +
					'</td>' +
					'<td><strong>' +
					it.progress_percent +
					'%</strong></td>' +
					'<td class="small">' +
					it.completed_count +
					' / ' +
					it.lessons_count +
					'</td>' +
					'<td>' +
					statusBadge(it.status) +
					'</td>' +
					'<td><a class="btn btn-sm btn-outline-secondary" href="' +
					url_base +
					'painel/ead/aluno/' +
					it.id_aluno +
					'">Detalhe</a></td>' +
					'</tr>'
			);
		});
	}

	function carregar() {
		$('#alert-turma').addClass('d-none');
		post(filtros())
			.done(function (res) {
				if (!res || !res.success) {
					$('#alert-turma').removeClass('d-none').text((res && res.message) || 'Falha ao carregar.');
					$('#tbody-turma').html('<tr><td colspan="6" class="text-muted p-3">—</td></tr>');
					return;
				}
				fillCursos(res.cursos);
				renderTotais(res.totais);
				render(res.itens);
			})
			.fail(function () {
				$('#alert-turma').removeClass('d-none').text('Erro de rede ao carregar a turma.');
			});
	}

	function exportCsv() {
		var data = filtros();
		data.acao = 'csv';
		post(data).done(function (res) {
			if (!res || !res.success || !res.csv) {
				alert((res && res.message) || 'Falha ao gerar CSV.');
				return;
			}
			var blob = new Blob([res.csv], { type: 'text/csv;charset=utf-8;' });
			var a = document.createElement('a');
			a.href = URL.createObjectURL(blob);
			a.download = res.filename || 'progresso-ead.csv';
			document.body.appendChild(a);
			a.click();
			a.remove();
			URL.revokeObjectURL(a.href);
		});
	}

	$('#btn-filtrar').on('click', carregar);
	$('#btn-csv').on('click', exportCsv);
	$('#filtro-q').on('keydown', function (e) {
		if (e.key === 'Enter') {
			e.preventDefault();
			carregar();
		}
	});
	carregar();
})();
