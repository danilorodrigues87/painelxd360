(function () {
	var _lista = [];

	function post(data) {
		return $.ajax({
			url: url_base + 'painel/ead/conquistas',
			method: 'POST',
			data: data,
			dataType: 'json',
		});
	}

	function toast(msg, ok) {
		if (typeof Swal !== 'undefined') {
			Swal.fire({ icon: ok ? 'success' : 'error', title: msg, timer: 2200, showConfirmButton: false });
		} else {
			alert(msg);
		}
	}

	function renderLista() {
		var q = ($('#filtro-conq-escola').val() || '').toLowerCase().trim();
		var list = !_lista.length ? [] : _lista.filter(function (c) {
			if (!q) return true;
			var blob = [c.titulo, c.subtitulo, c.slug, c.raridade, c.meta_tipo].join(' ').toLowerCase();
			return blob.indexOf(q) >= 0;
		});
		$('#badge-count').text(_lista.filter(function (c) { return c.ativo_escola; }).length + ' / ' + _lista.length);
		var $tb = $('#tbody-conquistas').empty();
		var $sel = $('#slug-liberar').empty();
		if (!list.length) {
			$tb.append('<tr><td colspan="4" class="text-muted p-3">' + (q ? 'Nenhum resultado no filtro.' : 'Nenhuma conquista cadastrada.') + '</td></tr>');
		} else {
			list.forEach(function (c) {
				var checked = c.ativo_escola ? ' checked' : '';
				$tb.append(
					'<tr>' +
					'<td><div class="form-check form-switch m-0"><input class="form-check-input toggle-conq" type="checkbox" data-slug="' +
					$('<div>').text(c.slug).html() + '"' + checked + '></div></td>' +
					'<td><strong>' + $('<div>').text(c.titulo).html() + '</strong>' +
					(c.subtitulo ? '<div class="small text-muted">' + $('<div>').text(c.subtitulo).html() + '</div>' : '') +
					'<div class="small text-muted">' + $('<div>').text(c.slug).html() + '</div></td>' +
					'<td>' + $('<div>').text(c.raridade || '').html() + '</td>' +
					'<td class="small">' + $('<div>').text((c.meta_tipo || '') + ' ≥ ' + (c.meta_valor || '')).html() + '</td>' +
					'</tr>'
				);
			});
		}
		_lista.forEach(function (c) {
			if (c.ativo_escola) {
				$sel.append(
					$('<option>').val(c.slug).text(c.titulo + ' (' + c.slug + ')')
				);
			}
		});
	}

	function carregar() {
		post({ acao: 'listar' }).done(function (res) {
			if (!res.success) {
				$('#alert-sql-conq').removeClass('d-none').text(res.message || 'Erro');
				return;
			}
			$('#alert-sql-conq').addClass('d-none');
			_lista = res.conquistas || [];
			renderLista();
		}).fail(function () {
			toast('Falha ao carregar conquistas.', false);
		});
	}

	var buscaTimer = null;
	$('#busca-aluno').on('input', function () {
		var q = $(this).val().trim();
		clearTimeout(buscaTimer);
		buscaTimer = setTimeout(function () {
			if (q.length < 2) {
				$('#lista-alunos').empty();
				return;
			}
			post({ acao: 'buscar_alunos', q: q }).done(function (res) {
				var $box = $('#lista-alunos').empty();
				(res.alunos || []).forEach(function (a) {
					$box.append(
						$('<button type="button" class="list-group-item list-group-item-action py-2"></button>')
							.text(a.nome + ' — ' + a.email)
							.data('id', a.id)
							.data('nome', a.nome)
					);
				});
			});
		}, 280);
	});

	$('#filtro-conq-escola').on('input', renderLista);

	$('#lista-alunos').on('click', 'button', function () {
		var id = $(this).data('id');
		var nome = $(this).data('nome');
		$('#id_aluno').val(id);
		$('#aluno-sel').text('Selecionado: ' + nome + ' (#' + id + ')');
		$('#lista-alunos').empty();
		$('#busca-aluno').val(nome);
	});

	$('#tbody-conquistas').on('change', '.toggle-conq', function () {
		var $el = $(this);
		post({
			acao: 'toggle',
			slug: $el.data('slug'),
			ativo: $el.is(':checked') ? 1 : 0,
		}).done(function (res) {
			if (!res.success) {
				$el.prop('checked', !$el.is(':checked'));
				toast(res.message || 'Erro', false);
				return;
			}
			carregar();
		}).fail(function () {
			$el.prop('checked', !$el.is(':checked'));
			toast('Falha ao salvar.', false);
		});
	});

	$('#btn-liberar').on('click', function () {
		var idAluno = $('#id_aluno').val();
		var slug = $('#slug-liberar').val();
		if (!idAluno || !slug) {
			toast('Selecione aluno e conquista.', false);
			return;
		}
		post({ acao: 'liberar', id_aluno: idAluno, slug: slug }).done(function (res) {
			toast(res.message || (res.success ? 'OK' : 'Erro'), !!res.success);
		}).fail(function () {
			toast('Falha ao liberar.', false);
		});
	});

	$(carregar);
})();
