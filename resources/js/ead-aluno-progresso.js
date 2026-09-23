(function () {
	var idAluno = window.EAD_ALUNO_ID;

	function post(data) {
		return $.ajax({
			url: url_base + 'painel/ead/aluno/' + idAluno,
			method: 'POST',
			data: data,
			dataType: 'json',
		});
	}

	function esc(s) {
		return $('<div>').text(s == null ? '' : String(s)).html();
	}

	function badgeItem(it) {
		if (it.needsRewatch) {
			return '<span class="badge bg-warning text-dark">Reassistir</span>';
		}
		if (it.completed) {
			return '<span class="badge bg-success">Concluída</span>';
		}
		if (it.locked) {
			var r = it.lockReason || 'travada';
			return '<span class="badge bg-secondary">' + esc(r) + '</span>';
		}
		return '<span class="badge bg-primary">Disponível</span>';
	}

	function kindLabel(k) {
		if (k === 'assessment') return 'Atividade';
		if (k === 'roleplay') return 'Roleplay';
		return 'Aula';
	}

	function render(cursos) {
		var $box = $('#box-progresso').empty();
		if (!cursos || !cursos.length) {
			$box.html('<div class="alert alert-info">Nenhum curso EAD com matrícula ativa para este aluno.</div>');
			return;
		}
		cursos.forEach(function (c) {
			var rows = '';
			(c.items || []).forEach(function (it) {
				rows +=
					'<tr>' +
					'<td class="small">' + esc(it.moduleTitle) + '</td>' +
					'<td><span class="badge bg-light text-dark border">' + esc(kindLabel(it.kind)) + '</span> ' + esc(it.title) + '</td>' +
					'<td>' + badgeItem(it) + '</td>' +
					'<td class="small text-muted">' +
					(it.unitScore != null ? Math.round(it.unitScore) + '%' : '—') +
					(it.lockMessage ? '<div>' + esc(it.lockMessage) + '</div>' : '') +
					'</td>' +
					'</tr>';
			});
			var aw = c.accessWindow || {};
			var awTxt = aw.message || '—';
			$box.append(
				'<div class="card mb-4">' +
				'<div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">' +
				'<div><strong>' + esc(c.title) + '</strong>' +
				'<div class="small text-muted">' + (c.completedCount || 0) + ' / ' + (c.lessonsCount || 0) +
				' aulas · ' + (c.progressPercent || 0) + '%</div></div>' +
				'<button type="button" class="btn btn-sm btn-outline-primary btn-liberar" data-curso="' + esc(c.id) + '">' +
				'<i class="fa-solid fa-unlock"></i> Liberar próxima aula</button>' +
				'</div>' +
				'<div class="card-body p-0">' +
				'<div class="px-3 py-2 small text-muted border-bottom">' + esc(awTxt) + '</div>' +
				'<div class="table-responsive" style="max-height:420px;overflow:auto;">' +
				'<table class="table table-sm table-hover mb-0">' +
				'<thead class="table-light sticky-top"><tr><th>Módulo</th><th>Item</th><th>Status</th><th>Nota / obs.</th></tr></thead>' +
				'<tbody>' + (rows || '<tr><td colspan="4" class="text-muted p-3">Sem itens</td></tr>') + '</tbody>' +
				'</table></div></div></div>'
			);
		});
	}

	function carregar() {
		post({ acao: 'historico' }).done(function (res) {
			if (!res.success) {
				$('#alert-prog').removeClass('d-none').text(res.message || 'Erro ao carregar.');
				$('#box-progresso').empty();
				return;
			}
			$('#alert-prog').addClass('d-none');
			render(res.cursos || []);
		}).fail(function () {
			$('#alert-prog').removeClass('d-none').text('Falha de rede ao carregar progresso.');
		});
	}

	$(document).on('click', '.btn-liberar', function () {
		var idCurso = $(this).data('curso');
		var $btn = $(this).prop('disabled', true);
		post({ acao: 'liberar_proxima', id_curso: idCurso }).done(function (res) {
			if (typeof Swal !== 'undefined') {
				Swal.fire({ icon: res.success ? 'success' : 'error', title: res.message || (res.success ? 'OK' : 'Erro') });
			} else {
				alert(res.message || '');
			}
			carregar();
		}).fail(function () {
			alert('Falha ao liberar.');
		}).always(function () {
			$btn.prop('disabled', false);
		});
	});

	$(carregar);
})();
