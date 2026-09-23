(function () {
	var categorias = [];
	var produtos = [];

	function toastOk(msg) {
		Swal.fire({
			toast: true,
			position: 'top-end',
			icon: 'success',
			title: msg || 'OK',
			showConfirmButton: false,
			timer: 2200,
		});
	}

	function toastErr(msg) {
		Swal.fire({
			icon: 'error',
			title: 'Atenção',
			text: msg || 'Ocorreu um erro.',
			confirmButtonText: 'OK',
		});
	}

	function post(data) {
		return $.ajax({
			url: url_base + 'painel/estoque',
			method: 'POST',
			data: data,
			dataType: 'json',
		});
	}

	function moeda(v) {
		return (Number(v) || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
	}

	function esc(s) {
		return $('<div>').text(s == null ? '' : String(s)).html();
	}

	function carregarCategorias() {
		return post({ acao: 'categorias' }).done(function (res) {
			categorias = (res && res.categorias) || [];
		});
	}

	function optionsCategorias(selected) {
		var html = '<option value="">Sem categoria</option>';
		categorias.forEach(function (c) {
			html +=
				'<option value="' +
				c.id +
				'"' +
				(String(selected) === String(c.id) ? ' selected' : '') +
				'>' +
				esc(c.nome) +
				'</option>';
		});
		return html;
	}

	function renderLista() {
		var tb = $('#stq-tbody');
		if (!produtos.length) {
			tb.html('<tr><td colspan="7" class="text-muted p-3">Nenhum produto encontrado.</td></tr>');
			return;
		}
		var html = '';
		produtos.forEach(function (p) {
			html +=
				'<tr data-id="' +
				p.id +
				'">' +
				'<td><strong>' +
				esc(p.nome) +
				'</strong>' +
				(p.descricao
					? '<div class="small text-muted">' + esc(p.descricao).slice(0, 80) + '</div>'
					: '') +
				'</td>' +
				'<td>' +
				esc(p.sku || '—') +
				'</td>' +
				'<td>' +
				esc(p.categoria || '—') +
				'</td>' +
				'<td class="text-end">' +
				p.quantidade +
				'</td>' +
				'<td class="text-end">' +
				moeda(p.valor_custo) +
				'</td>' +
				'<td class="text-end">' +
				moeda(p.valor_venda) +
				'</td>' +
				'<td class="text-end text-nowrap">' +
				'<button type="button" class="btn btn-sm btn-outline-secondary btn-edit" title="Editar"><i class="fa-solid fa-pen"></i></button> ' +
				'<button type="button" class="btn btn-sm btn-outline-primary btn-mov" title="Movimentar"><i class="fa-solid fa-boxes-stacked"></i></button> ' +
				'<button type="button" class="btn btn-sm btn-outline-danger btn-del" title="Inativar"><i class="fa-solid fa-trash"></i></button>' +
				'</td></tr>';
		});
		tb.html(html);
	}

	function carregar() {
		var q = ($('#stq-busca').val() || '').trim();
		post({ acao: 'listar', q: q }).done(function (res) {
			if (!res || !res.success) {
				toastErr((res && res.message) || 'Falha ao listar.');
				return;
			}
			produtos = res.produtos || [];
			renderLista();
		});
	}

	function formProduto(p) {
		p = p || {};
		return (
			'<div class="text-start">' +
			'<label class="form-label small mb-0">Nome *</label>' +
			'<input id="fp-nome" class="form-control form-control-sm mb-2" value="' +
			esc(p.nome || '') +
			'">' +
			'<label class="form-label small mb-0">SKU</label>' +
			'<input id="fp-sku" class="form-control form-control-sm mb-2" value="' +
			esc(p.sku || '') +
			'">' +
			'<label class="form-label small mb-0">Categoria</label>' +
			'<select id="fp-cat" class="form-select form-select-sm mb-2">' +
			optionsCategorias(p.id_categoria) +
			'</select>' +
			'<div class="row g-2 mb-2">' +
			'<div class="col-6"><label class="form-label small mb-0">Custo</label>' +
			'<input id="fp-custo" type="number" step="0.01" min="0" class="form-control form-control-sm" value="' +
			(p.valor_custo != null ? p.valor_custo : 0) +
			'"></div>' +
			'<div class="col-6"><label class="form-label small mb-0">Venda</label>' +
			'<input id="fp-venda" type="number" step="0.01" min="0" class="form-control form-control-sm" value="' +
			(p.valor_venda != null ? p.valor_venda : 0) +
			'"></div></div>' +
			(!p.id
				? '<label class="form-label small mb-0">Estoque inicial</label>' +
				  '<input id="fp-qtd" type="number" min="0" class="form-control form-control-sm mb-2" value="0">'
				: '') +
			'<label class="form-label small mb-0">Descrição</label>' +
			'<textarea id="fp-desc" class="form-control form-control-sm" rows="2">' +
			esc(p.descricao || '') +
			'</textarea>' +
			'</div>'
		);
	}

	function abrirProduto(p) {
		carregarCategorias().always(function () {
			Swal.fire({
				title: p && p.id ? 'Editar produto' : 'Novo produto',
				html: formProduto(p),
				showCancelButton: true,
				confirmButtonText: 'Salvar',
				cancelButtonText: 'Cancelar',
				focusConfirm: false,
				preConfirm: function () {
					var nome = ($('#fp-nome').val() || '').trim();
					if (!nome) {
						Swal.showValidationMessage('Informe o nome.');
						return false;
					}
					return {
						id: (p && p.id) || 0,
						nome: nome,
						sku: ($('#fp-sku').val() || '').trim(),
						id_categoria: $('#fp-cat').val() || '',
						valor_custo: $('#fp-custo').val(),
						valor_venda: $('#fp-venda').val(),
						quantidade: $('#fp-qtd').length ? $('#fp-qtd').val() : 0,
						descricao: ($('#fp-desc').val() || '').trim(),
					};
				},
			}).then(function (r) {
				if (!r.isConfirmed) return;
				var data = r.value;
				data.acao = 'salvar_produto';
				post(data).done(function (res) {
					if (!res || !res.success) {
						toastErr((res && res.message) || 'Falha ao salvar.');
						return;
					}
					toastOk(res.message);
					carregar();
				});
			});
		});
	}

	function abrirCategoria() {
		carregarCategorias().always(function () {
			var opts = '<option value="">— Nova categoria —</option>';
			categorias.forEach(function (c) {
				opts += '<option value="' + c.id + '">' + esc(c.nome) + '</option>';
			});
			Swal.fire({
				title: 'Categorias',
				html:
					'<label class="form-label small mb-0 text-start d-block">Selecionar</label>' +
					'<select id="fc-id" class="form-select form-select-sm mb-2">' +
					opts +
					'</select>' +
					'<label class="form-label small mb-0 text-start d-block">Nome *</label>' +
					'<input id="fc-nome" class="form-control form-control-sm mb-2" placeholder="Nome">' +
					'<label class="form-label small mb-0 text-start d-block">Descrição</label>' +
					'<input id="fc-desc" class="form-control form-control-sm" placeholder="Opcional">',
				showCancelButton: true,
				confirmButtonText: 'Salvar',
				cancelButtonText: 'Cancelar',
				didOpen: function () {
					$('#fc-id').on('change', function () {
						var id = Number($(this).val() || 0);
						if (!id) {
							$('#fc-nome').val('');
							$('#fc-desc').val('');
							return;
						}
						var cat = categorias.find(function (c) {
							return c.id === id;
						});
						$('#fc-nome').val(cat ? cat.nome : '');
						$('#fc-desc').val(cat ? cat.descricao || '' : '');
					});
				},
				preConfirm: function () {
					var nome = ($('#fc-nome').val() || '').trim();
					if (!nome) {
						Swal.showValidationMessage('Informe o nome.');
						return false;
					}
					return {
						id: $('#fc-id').val() || 0,
						nome: nome,
						descricao: ($('#fc-desc').val() || '').trim(),
					};
				},
			}).then(function (r) {
				if (!r.isConfirmed) return;
				post({
					acao: 'salvar_categoria',
					id: r.value.id,
					nome: r.value.nome,
					descricao: r.value.descricao,
				}).done(function (res) {
					if (!res || !res.success) {
						toastErr((res && res.message) || 'Falha.');
						return;
					}
					toastOk(res.message);
					carregarCategorias();
					carregar();
				});
			});
		});
	}

	function abrirMov(p) {
		Swal.fire({
			title: 'Movimentar: ' + (p.nome || ''),
			html:
				'<p class="small text-muted mb-2">Estoque atual: <strong>' +
				p.quantidade +
				'</strong></p>' +
				'<label class="form-label small mb-0">Tipo</label>' +
				'<select id="fm-tipo" class="form-select form-select-sm mb-2">' +
				'<option value="entrada">Entrada (+)</option>' +
				'<option value="ajuste">Ajuste (definir saldo)</option>' +
				'</select>' +
				'<label class="form-label small mb-0">Quantidade / novo saldo</label>' +
				'<input id="fm-qtd" type="number" min="1" class="form-control form-control-sm mb-2" value="1">' +
				'<label class="form-label small mb-0">Observação</label>' +
				'<input id="fm-obs" class="form-control form-control-sm">',
			showCancelButton: true,
			confirmButtonText: 'Confirmar',
			preConfirm: function () {
				var qtd = parseInt($('#fm-qtd').val(), 10);
				if (!qtd || qtd < 0) {
					Swal.showValidationMessage('Quantidade inválida.');
					return false;
				}
				return {
					tipo: $('#fm-tipo').val(),
					quantidade: qtd,
					observacao: ($('#fm-obs').val() || '').trim(),
				};
			},
		}).then(function (r) {
			if (!r.isConfirmed) return;
			post({
				acao: 'movimentar',
				id_produto: p.id,
				tipo: r.value.tipo,
				quantidade: r.value.quantidade,
				observacao: r.value.observacao,
			}).done(function (res) {
				if (!res || !res.success) {
					toastErr((res && res.message) || 'Falha.');
					return;
				}
				toastOk(res.message);
				carregar();
			});
		});
	}

	$('#btn-stq-buscar').on('click', carregar);
	$('#stq-busca').on('keydown', function (e) {
		if (e.key === 'Enter') {
			e.preventDefault();
			carregar();
		}
	});
	$('#btn-novo-prod').on('click', function () {
		abrirProduto(null);
	});
	$('#btn-nova-cat').on('click', abrirCategoria);

	$('#stq-tbody').on('click', '.btn-edit', function () {
		var id = Number($(this).closest('tr').data('id'));
		var p = produtos.find(function (x) {
			return x.id === id;
		});
		if (p) abrirProduto(p);
	});
	$('#stq-tbody').on('click', '.btn-mov', function () {
		var id = Number($(this).closest('tr').data('id'));
		var p = produtos.find(function (x) {
			return x.id === id;
		});
		if (p) abrirMov(p);
	});
	$('#stq-tbody').on('click', '.btn-del', function () {
		var id = Number($(this).closest('tr').data('id'));
		var p = produtos.find(function (x) {
			return x.id === id;
		});
		if (!p) return;
		Swal.fire({
			icon: 'warning',
			title: 'Inativar produto?',
			text: p.nome,
			showCancelButton: true,
			confirmButtonText: 'Inativar',
			cancelButtonText: 'Cancelar',
		}).then(function (r) {
			if (!r.isConfirmed) return;
			post({ acao: 'inativar_produto', id: id }).done(function (res) {
				if (!res || !res.success) {
					toastErr((res && res.message) || 'Falha.');
					return;
				}
				toastOk(res.message);
				carregar();
			});
		});
	});

	carregarCategorias();
	carregar();
})();
