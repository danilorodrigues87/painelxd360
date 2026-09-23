(function () {
	var catalogo = [];
	var carrinho = [];

	function toastOk(msg) {
		Swal.fire({
			toast: true,
			position: 'top-end',
			icon: 'success',
			title: msg || 'OK',
			showConfirmButton: false,
			timer: 2500,
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
			url: url_base + 'painel/estoque/pdv',
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

	function totalCarrinho() {
		return carrinho.reduce(function (acc, it) {
			return acc + it.qtd * it.valor_unitario;
		}, 0);
	}

	function renderCarrinho() {
		var tb = $('#pdv-carrinho');
		if (!carrinho.length) {
			tb.html('<tr><td colspan="4" class="text-muted p-3">Vazio</td></tr>');
			$('#pdv-total').text(moeda(0));
			return;
		}
		var html = '';
		carrinho.forEach(function (it, idx) {
			html +=
				'<tr data-idx="' +
				idx +
				'">' +
				'<td><div class="fw-semibold">' +
				esc(it.nome) +
				'</div><div class="small text-muted">' +
				moeda(it.valor_unitario) +
				' · est. ' +
				it.estoque +
				'</div></td>' +
				'<td class="text-center"><input type="number" min="1" max="' +
				it.estoque +
				'" class="form-control form-control-sm pdv-qtd" value="' +
				it.qtd +
				'" style="width:64px;margin:auto"></td>' +
				'<td class="text-end">' +
				moeda(it.qtd * it.valor_unitario) +
				'</td>' +
				'<td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger btn-rm">&times;</button></td>' +
				'</tr>';
		});
		tb.html(html);
		$('#pdv-total').text(moeda(totalCarrinho()));
	}

	function renderCatalogo() {
		var tb = $('#pdv-produtos');
		if (!catalogo.length) {
			tb.html('<tr><td colspan="4" class="text-muted p-3">Nenhum produto com estoque.</td></tr>');
			return;
		}
		var html = '';
		catalogo.forEach(function (p) {
			html +=
				'<tr>' +
				'<td><strong>' +
				esc(p.nome) +
				'</strong>' +
				(p.sku ? '<div class="small text-muted">' + esc(p.sku) + '</div>' : '') +
				'</td>' +
				'<td class="text-end">' +
				p.quantidade +
				'</td>' +
				'<td class="text-end">' +
				moeda(p.valor_venda) +
				'</td>' +
				'<td class="text-end"><button type="button" class="btn btn-sm btn-primary btn-add" data-id="' +
				p.id +
				'">+</button></td>' +
				'</tr>';
		});
		tb.html(html);
	}

	function addItem(p) {
		var existing = carrinho.find(function (x) {
			return x.id_produto === p.id;
		});
		if (existing) {
			if (existing.qtd >= p.quantidade) {
				toastErr('Estoque insuficiente.');
				return;
			}
			existing.qtd += 1;
			existing.estoque = p.quantidade;
		} else {
			carrinho.push({
				id_produto: p.id,
				nome: p.nome,
				qtd: 1,
				valor_unitario: Number(p.valor_venda) || 0,
				estoque: p.quantidade,
			});
		}
		renderCarrinho();
	}

	function buscar() {
		var q = ($('#pdv-busca').val() || '').trim();
		post({ acao: 'buscar', q: q }).done(function (res) {
			if (!res || !res.success) {
				toastErr((res && res.message) || 'Falha na busca.');
				return;
			}
			catalogo = res.produtos || [];
			renderCatalogo();
		});
	}

	function carregarUltimas() {
		post({ acao: 'ultimas' }).done(function (res) {
			var ul = $('#pdv-ultimas');
			var vendas = (res && res.vendas) || [];
			if (!vendas.length) {
				ul.html('<li class="list-group-item text-muted">Nenhuma venda ainda.</li>');
				return;
			}
			var html = '';
			vendas.forEach(function (v) {
				var resumo = (v.itens || [])
					.map(function (i) {
						return i.nome + ' x' + i.qtd;
					})
					.join(', ');
				html +=
					'<li class="list-group-item">' +
					'<div class="d-flex justify-content-between">' +
					'<strong>#' +
					v.id +
					'</strong><span>' +
					moeda(v.total) +
					'</span></div>' +
					'<div class="small text-muted">' +
					esc(v.tipo_pagamento) +
					' · ' +
					esc(v.created_at) +
					'</div>' +
					'<div class="small">' +
					esc(resumo) +
					'</div></li>';
			});
			ul.html(html);
		});
	}

	function finalizar() {
		if (!carrinho.length) {
			toastErr('Carrinho vazio.');
			return;
		}
		var total = totalCarrinho();
		Swal.fire({
			icon: 'question',
			title: 'Confirmar venda?',
			html:
				'<p class="mb-1">Total: <strong>' +
				moeda(total) +
				'</strong></p>' +
				'<p class="small text-muted mb-0">Pagamento: ' +
				esc($('#pdv-pagamento').val()) +
				'</p>',
			showCancelButton: true,
			confirmButtonText: 'Confirmar',
			cancelButtonText: 'Cancelar',
		}).then(function (r) {
			if (!r.isConfirmed) return;
			var itens = carrinho.map(function (it) {
				return {
					id_produto: it.id_produto,
					qtd: it.qtd,
					valor_unitario: it.valor_unitario,
				};
			});
			post({
				acao: 'finalizar',
				itens: JSON.stringify(itens),
				tipo_pagamento: $('#pdv-pagamento').val(),
				observacao: ($('#pdv-obs').val() || '').trim(),
			}).done(function (res) {
				if (!res || !res.success) {
					toastErr((res && res.message) || 'Falha ao finalizar.');
					return;
				}
				toastOk(res.message || 'Venda registrada.');
				carrinho = [];
				$('#pdv-obs').val('');
				renderCarrinho();
				buscar();
				carregarUltimas();
			});
		});
	}

	$('#btn-pdv-buscar').on('click', buscar);
	$('#pdv-busca').on('keydown', function (e) {
		if (e.key === 'Enter') {
			e.preventDefault();
			buscar();
		}
	});
	$('#pdv-produtos').on('click', '.btn-add', function () {
		var id = Number($(this).data('id'));
		var p = catalogo.find(function (x) {
			return x.id === id;
		});
		if (p) addItem(p);
	});
	$('#pdv-carrinho').on('change', '.pdv-qtd', function () {
		var idx = Number($(this).closest('tr').data('idx'));
		var it = carrinho[idx];
		if (!it) return;
		var q = parseInt($(this).val(), 10);
		if (!q || q < 1) q = 1;
		if (q > it.estoque) q = it.estoque;
		it.qtd = q;
		renderCarrinho();
	});
	$('#pdv-carrinho').on('click', '.btn-rm', function () {
		var idx = Number($(this).closest('tr').data('idx'));
		carrinho.splice(idx, 1);
		renderCarrinho();
	});
	$('#btn-pdv-finalizar').on('click', finalizar);

	buscar();
	carregarUltimas();
})();
