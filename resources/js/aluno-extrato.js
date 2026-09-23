(function () {
	var idAluno = window.EXTRATO_ALUNO_ID;
	var estado = { titulos: [], podeRenegociar: false, aluno: null, totais: null };

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

	function toastInfo(msg) {
		Swal.fire({
			icon: 'info',
			title: 'Atenção',
			text: msg,
			confirmButtonText: 'OK',
		});
	}

	function post(data) {
		return $.ajax({
			url: url_base + 'painel/alunos/' + idAluno + '/extrato',
			method: 'POST',
			data: data,
			dataType: 'json',
		});
	}

	function esc(s) {
		return $('<div>').text(s == null ? '' : String(s)).html();
	}

	function moeda(v) {
		var n = Number(v) || 0;
		return n.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
	}

	function fmtData(d) {
		if (!d || d === '0000-00-00') return '—';
		var p = String(d).slice(0, 10).split('-');
		if (p.length !== 3) return esc(d);
		return p[2] + '/' + p[1] + '/' + p[0];
	}

	function badgeStatus(st) {
		if (st === 'pago') return '<span class="badge bg-success">Pago</span>';
		if (st === 'vencido') return '<span class="badge bg-danger">Vencido</span>';
		if (st === 'cancelada') return '<span class="badge bg-secondary">Cancelada (R$ 0)</span>';
		if (st === 'renegociada') return '<span class="badge bg-secondary">Renegociação</span>';
		return '<span class="badge bg-warning text-dark">Em aberto</span>';
	}

	function renderTotais(t) {
		t = t || {};
		var html =
			'<div class="col-6 col-md-3"><div class="border rounded p-2 small"><div class="text-muted">Pago</div><strong class="text-success">' +
			moeda(t.pago) +
			'</strong></div></div>' +
			'<div class="col-6 col-md-3"><div class="border rounded p-2 small"><div class="text-muted">Em aberto</div><strong class="text-primary">' +
			moeda(t.aberto) +
			'</strong></div></div>' +
			'<div class="col-6 col-md-3"><div class="border rounded p-2 small"><div class="text-muted">Vencido</div><strong class="text-danger">' +
			moeda(t.vencido) +
			'</strong></div></div>' +
			'<div class="col-6 col-md-3"><div class="border rounded p-2 small"><div class="text-muted">Títulos</div><strong>' +
			(t.titulos || 0) +
			'</strong></div></div>';
		if (t.divida_atualizada != null && t.divida_atualizada > 0) {
			html +=
				'<div class="col-12 mt-2"><div class="border rounded p-2 small bg-light">' +
				'<span class="text-muted">Dívida atualizada (com encargos até hoje):</span> ' +
				'<strong class="text-danger">' +
				moeda(t.divida_atualizada) +
				'</strong>' +
				(t.divida_multa > 0 || t.divida_juros > 0
					? ' <span class="text-muted">(multa ' +
						moeda(t.divida_multa) +
						' + juros ' +
						moeda(t.divida_juros) +
						')</span>'
					: '') +
				'</div></div>';
		}
		$('#totais-extrato').html(html);
	}

	function render(res) {
		estado.titulos = res.titulos || [];
		estado.podeRenegociar = !!res.pode_renegociar;
		estado.aluno = res.aluno;
		estado.totais = res.totais;

		renderTotais(res.totais);
		if (estado.podeRenegociar) {
			$('#btn-renegociar').removeClass('d-none');
		} else {
			$('#btn-renegociar').addClass('d-none');
		}

		var html = '';

		// —— Acordos com parcelas e baixa em lote ——
		if ((res.acordos || []).length) {
			html += '<h5 class="mt-2 mb-2">Acordos de renegociação</h5>';
			(res.acordos || []).forEach(function (a) {
				var temAberto = (a.parcelas || []).some(function (p) {
					return p.selecionavel;
				});
				var rowsA = '';
				(a.parcelas || []).forEach(function (t) {
					var chk = t.selecionavel
						? '<input type="checkbox" class="form-check-input chk-titulo chk-acordo-' +
							a.id +
							'" value="' +
							t.id +
							'">'
						: '';
					var btnBaixa = t.selecionavel
						? '<button type="button" class="btn btn-sm btn-outline-success btn-dar-baixa" data-id="' +
							t.id +
							'" data-valor="' +
							(t.total_com_encargos || t.valor) +
							'" data-desc="' +
							esc(t.descricao) +
							'">Só esta</button>'
						: t.status === 'pago'
							? reciboAcoesHtml(t.id, true)
							: '—';
					rowsA +=
						'<tr>' +
						'<td class="text-center">' +
						chk +
						'</td>' +
						'<td class="small">' +
						esc(t.descricao) +
						'</td>' +
						'<td>' +
						fmtData(t.vencimento) +
						'</td>' +
						'<td>' +
						moeda(t.valor) +
						(t.elegivel_encargos && t.total_com_encargos > t.valor
							? '<div class="small text-danger">c/ enc. ' + moeda(t.total_com_encargos) + '</div>'
							: '') +
						'</td>' +
						'<td>' +
						badgeStatus(t.status) +
						'</td>' +
						'<td class="small text-muted">' +
						(t.tipo_pagamento ? esc(t.tipo_pagamento) : '—') +
						'</td>' +
						'<td>' +
						btnBaixa +
						'</td>' +
						'</tr>';
				});
				html +=
					'<div class="card mb-3 border-primary" data-acordo="' +
					a.id +
					'">' +
					'<div class="card-header bg-primary bg-opacity-10 d-flex flex-wrap justify-content-between align-items-center gap-2">' +
					'<div><strong>' +
					esc(a.label) +
					'</strong> · ' +
					((a.valor_entrada || 0) > 0
						? 'Entrada ' +
							moeda(a.valor_entrada) +
							(a.qtd_parcelas > 0
								? ' + ' + a.qtd_parcelas + ' parcela' + (a.qtd_parcelas === 1 ? '' : 's') + ' do restante'
								: '')
						: a.qtd_parcelas + ' parcela' + (a.qtd_parcelas === 1 ? '' : 's')) +
					' · total ' +
					moeda(a.valor_total) +
					(a.observacao ? '<div class="small text-muted">' + esc(a.observacao) + '</div>' : '') +
					'</div>' +
					'<div class="small">Pago ' +
					moeda(a.total_pago) +
					' · Aberto ' +
					moeda(a.total_aberto) +
					(a.total_vencido > 0 ? ' · <span class="text-danger">Vencido ' + moeda(a.total_vencido) + '</span>' : '') +
					'</div></div>' +
					'<div class="card-body p-0">' +
					(temAberto
						? '<div class="px-3 py-2 border-bottom d-flex flex-wrap gap-2 align-items-center">' +
							'<div class="form-check mb-0">' +
							'<input class="form-check-input chk-acordo-todos" type="checkbox" data-acordo="' +
							a.id +
							'" id="chk-ac-todos-' +
							a.id +
							'">' +
							'<label class="form-check-label small" for="chk-ac-todos-' +
							a.id +
							'">Selecionar abertas deste acordo</label></div>' +
							'<button type="button" class="btn btn-sm btn-success btn-baixa-acordo" data-acordo="' +
							a.id +
							'"><i class="fa-solid fa-check-double"></i> Dar baixa nas selecionadas</button>' +
							'</div>'
						: '') +
					'<div class="table-responsive"><table class="table table-sm table-hover mb-0">' +
					'<thead class="table-light"><tr><th style="width:36px"></th><th>Parcela</th><th>Vencimento</th><th>Valor</th><th>Status</th><th>Pgto</th><th></th></tr></thead>' +
					'<tbody>' +
					(rowsA || '<tr><td colspan="7" class="text-muted p-3">Sem parcelas</td></tr>') +
					'</tbody></table></div></div></div>';
			});
		}

		// —— Resumo matrículas ——
		if ((res.matriculas || []).length) {
			html += '<h5 class="mt-3 mb-2">Matrículas</h5>';
			(res.matriculas || []).forEach(function (m) {
				html +=
					'<div class="card mb-2"><div class="card-body py-2 px-3 small d-flex flex-wrap justify-content-between gap-2">' +
					'<div><strong>Matrícula #' +
					m.id +
					'</strong> · ' +
					esc(m.curso) +
					' <span class="text-muted">(' +
					esc(m.status) +
					')</span></div>' +
					'<div>Pago ' +
					moeda(m.total_pago) +
					' · Aberto ' +
					moeda(m.total_aberto) +
					(m.total_vencido > 0 ? ' · <span class="text-danger">Vencido ' + moeda(m.total_vencido) + '</span>' : '') +
					'</div></div></div>';
			});
		}

		// —— Tabela geral (matrícula + acordo) p/ PDF e renegociação ——
		var rows = '';
		(res.titulos || []).forEach(function (t) {
			var chk = t.selecionavel
				? '<input type="checkbox" class="form-check-input chk-titulo chk-geral" value="' + t.id + '">'
				: '';
			var btnBaixa = t.selecionavel
				? '<button type="button" class="btn btn-sm btn-outline-success btn-dar-baixa" data-id="' +
					t.id +
					'" data-valor="' +
					(t.total_com_encargos || t.valor) +
					'" data-desc="' +
					esc(t.descricao) +
					'">Só esta</button>'
				: t.status === 'pago'
					? reciboAcoesHtml(t.id, true)
					: '<span class="small text-muted">—</span>';
			rows +=
				'<tr>' +
				'<td class="text-center">' +
				chk +
				'</td>' +
				'<td class="small">' +
				esc(t.origem_label) +
				(t.referencia === 'Multa rescisória'
					? ' <span class="badge bg-warning text-dark">Multa</span>'
					: '') +
				'</td>' +
				'<td class="small">' +
				esc(t.descricao) +
				'</td>' +
				'<td>' +
				fmtData(t.vencimento) +
				'</td>' +
				'<td>' +
				moeda(t.valor) +
				(t.elegivel_encargos && t.total_com_encargos > t.valor
					? '<div class="small text-danger">c/ enc. ' + moeda(t.total_com_encargos) + '</div>'
					: '') +
				'</td>' +
				'<td>' +
				badgeStatus(t.status) +
				'</td>' +
				'<td class="small text-muted">' +
				(t.tipo_pagamento ? esc(t.tipo_pagamento) : '—') +
				'</td>' +
				'<td>' +
				btnBaixa +
				'</td>' +
				'</tr>';
		});

		html +=
			'<h5 class="mt-3 mb-2">Todos os títulos</h5>' +
			'<p class="small text-muted">Use os checkboxes aqui para renegociar ou baixar várias de origens diferentes. Nos acordos acima, a baixa em lote já fica no card do acordo.</p>' +
			'<div class="card" id="card-titulos-pdf"><div class="card-body p-0">' +
			'<div class="table-responsive"><table class="table table-sm table-hover mb-0">' +
			'<thead class="table-light"><tr>' +
			'<th style="width:36px"><input type="checkbox" class="form-check-input" id="chk-todos" title="Selecionar abertas"></th>' +
			'<th>Origem</th><th>Descrição</th><th>Vencimento</th><th>Valor</th><th>Status</th><th>Pgto</th><th></th>' +
			'</tr></thead><tbody>' +
			(rows || '<tr><td colspan="8" class="text-muted p-3">Nenhum título encontrado.</td></tr>') +
			'</tbody></table></div></div></div>';

		$('#box-extrato').html(html);
		$('#box-extrato')
			.off('click', '.btn-dar-baixa')
			.on('click', '.btn-dar-baixa', function () {
				abrirBaixa($(this).data('id'), parseFloat($(this).data('valor')), String($(this).data('desc') || ''));
			});
		$('#box-extrato')
			.off('click', '.btn-baixa-acordo')
			.on('click', '.btn-baixa-acordo', function () {
				abrirBaixaLote(parseInt($(this).data('acordo'), 10));
			});
		$('#box-extrato')
			.off('change', '.chk-acordo-todos')
			.on('change', '.chk-acordo-todos', function () {
				var idAc = $(this).data('acordo');
				$('.chk-acordo-' + idAc).prop('checked', $(this).is(':checked'));
			});
		$('#chk-todos')
			.off('change')
			.on('change', function () {
				$('.chk-geral').prop('checked', $(this).is(':checked'));
			});
	}

	function abrirBaixa(idTitulo, valor, desc) {
		var hoje = new Date();
		var yyyy = hoje.getFullYear();
		var mm = String(hoje.getMonth() + 1).padStart(2, '0');
		var dd = String(hoje.getDate()).padStart(2, '0');
		var dataDefault = yyyy + '-' + mm + '-' + dd;

		function montarHtmlEncargos(enc) {
			if (!enc || !enc.html_bloco) return '';
			return (
				'<ul class="list-group list-group-flush border rounded mb-2 text-start small">' +
				enc.html_bloco +
				'</ul>'
			);
		}

		function valorSugerido(enc) {
			if (enc && enc.elegivel_encargos) {
				return Number(enc.total_com_encargos) || Number(valor) || 0;
			}
			return Number(valor) || 0;
		}

		function abrirModal(enc) {
			var v0 = valorSugerido(enc);
			Swal.fire({
				title: 'Dar baixa',
				width: 520,
				html:
					'<p class="small text-start mb-2">' +
					esc(desc) +
					'</p>' +
					montarHtmlEncargos(enc) +
					'<div class="text-start">' +
					'<label class="form-label small mb-0">Valor pago (R$)</label>' +
					'<input id="bx-valor" type="number" step="0.01" min="0.01" class="form-control form-control-sm mb-2" value="' +
					v0.toFixed(2) +
					'">' +
					'<label class="form-label small mb-0">Forma de pagamento</label>' +
					'<select id="bx-tipo" class="form-select form-select-sm mb-2">' +
					'<option value="">Selecione</option>' +
					'<option value="Dinheiro">Dinheiro</option>' +
					'<option value="Pix">Pix</option>' +
					'<option value="Cartão">Cartão</option>' +
					'<option value="Transferência">Transferência</option>' +
					'<option value="Boleto">Boleto</option>' +
					'</select>' +
					'<label class="form-label small mb-0">Data do pagamento</label>' +
					'<input id="bx-data" type="date" class="form-control form-control-sm" value="' +
					dataDefault +
					'">' +
					'</div>',
				showCancelButton: true,
				confirmButtonText: 'Confirmar baixa',
				didOpen: function () {
					if (typeof atualizarTotalEncargosPagamento === 'function') {
						atualizarTotalEncargosPagamento();
					}
					$('#bx-data')
						.off('change.extratoEnc')
						.on('change.extratoEnc', function () {
							var dataRef = $(this).val() || dataDefault;
							post({
								acao: 'encargos_titulo',
								id_titulo: idTitulo,
								data_referencia: dataRef,
							}).done(function (r) {
								if (!r || !r.success) return;
								var $wrap = $('.encargos-bloco').closest('ul');
								if (r.html_bloco) {
									if ($wrap.length) {
										$wrap.html(r.html_bloco);
									} else {
										$wrap = $(
											'<ul class="list-group list-group-flush border rounded mb-2 text-start small"></ul>'
										);
										$wrap.html(r.html_bloco);
										$('#bx-valor').closest('.text-start').before($wrap);
									}
								} else if ($wrap.length) {
									$wrap.remove();
								}
								$('#bx-valor').val(valorSugerido(r).toFixed(2));
								if (typeof atualizarTotalEncargosPagamento === 'function') {
									atualizarTotalEncargosPagamento();
								}
							});
						});
				},
				preConfirm: function () {
					var tipo = ($('#bx-tipo').val() || '').trim();
					var v = parseFloat($('#bx-valor').val());
					var data = $('#bx-data').val();
					if (!tipo) {
						Swal.showValidationMessage('Selecione a forma de pagamento.');
						return false;
					}
					if (!(v > 0)) {
						Swal.showValidationMessage('Informe o valor pago.');
						return false;
					}
					if (!data) {
						Swal.showValidationMessage('Informe a data.');
						return false;
					}
					return { valor_pago: v, tipo_pagamento: tipo, data_pagamento: data };
				},
			}).then(function (r) {
				if (!r.isConfirmed) return;
				post({
					acao: 'dar_baixa',
					id_titulo: idTitulo,
					valor_pago: r.value.valor_pago,
					tipo_pagamento: r.value.tipo_pagamento,
					data_pagamento: r.value.data_pagamento,
				}).done(function (res) {
					if (!res || !res.success) {
						toastErr((res && res.message) || 'Falha ao dar baixa.');
						return;
					}
					carregar();
				});
			});
		}

		post({
			acao: 'encargos_titulo',
			id_titulo: idTitulo,
			data_referencia: dataDefault,
		})
			.done(function (res) {
				if (!res || !res.success) {
					abrirModal(null);
					return;
				}
				abrirModal(res);
			})
			.fail(function () {
				abrirModal(null);
			});
	}

	function carregar() {
		$('#alert-extrato').addClass('d-none');
		post({ acao: 'extrato' })
			.done(function (res) {
				if (!res || !res.success) {
					$('#alert-extrato').removeClass('d-none').text((res && res.message) || 'Falha ao carregar.');
					$('#box-extrato').html('');
					return;
				}
				render(res);
			})
			.fail(function () {
				$('#alert-extrato').removeClass('d-none').text('Erro de rede.');
			});
	}

	function idsSelecionados() {
		var ids = [];
		var vistos = {};
		$('#box-extrato .chk-titulo:checked').each(function () {
			if ($(this).hasClass('chk-acordo-todos') || this.id === 'chk-todos') return;
			var id = parseInt($(this).val(), 10);
			if (id > 0 && !vistos[id]) {
				vistos[id] = true;
				ids.push(id);
			}
		});
		return ids;
	}

	function valorTituloParaSoma(t) {
		if (!t) return 0;
		if (t.referencia === 'Multa rescisória') {
			return Number(t.total_com_encargos) || Number(t.valor) || 0;
		}
		if (t.status === 'vencido' && t.total_com_encargos != null) {
			return Number(t.total_com_encargos) || Number(t.valor) || 0;
		}
		return Number(t.valor) || 0;
	}

	function somaSelecionados() {
		var ids = idsSelecionados();
		var sum = 0;
		(estado.titulos || []).forEach(function (t) {
			if (ids.indexOf(t.id) >= 0) sum += valorTituloParaSoma(t);
		});
		return sum;
	}

	function calcResumoRenegociacao(valorTotal, valorEntrada, qtdRest) {
		valorTotal = Number(valorTotal) || 0;
		valorEntrada = Math.max(0, Number(valorEntrada) || 0);
		qtdRest = parseInt(qtdRest, 10) || 0;
		if (valorEntrada > valorTotal) {
			return { ok: false, msg: 'A entrada não pode ser maior que o total.' };
		}
		var restante = Math.round((valorTotal - valorEntrada) * 100) / 100;
		if (valorEntrada <= 0 && qtdRest < 1) {
			return { ok: false, msg: 'Informe a quantidade de parcelas do restante.' };
		}
		if (valorEntrada > 0 && restante > 0 && qtdRest < 1) {
			return { ok: false, msg: 'Informe a quantidade de parcelas do restante.' };
		}
		if (restante <= 0 && qtdRest > 0) {
			return { ok: false, msg: 'Não há saldo restante para parcelar.' };
		}
		var parcRest = 0;
		if (restante > 0 && qtdRest > 0) {
			parcRest = Math.round((restante / qtdRest) * 100) / 100;
		}
		var partes = [];
		if (valorEntrada > 0) {
			partes.push('entrada ' + moeda(valorEntrada));
		}
		if (qtdRest > 0 && restante > 0) {
			partes.push(qtdRest + 'x ' + moeda(parcRest) + ' (restante ' + moeda(restante) + ')');
		} else if (valorEntrada <= 0 && qtdRest > 0) {
			partes.push(qtdRest + 'x ' + moeda(parcRest));
		}
		return {
			ok: true,
			html:
				partes.length > 0
					? partes.join(' + ')
					: 'Informe entrada ou parcelas do restante.',
		};
	}

	function abrirRenegociar() {
		var ids = idsSelecionados();
		if (!ids.length) {
			toastInfo('Selecione os títulos em aberto/vencidos que entrarão no acordo.');
			return;
		}
		var sugerido = somaSelecionados();
		var hoje = new Date();
		var yyyy = hoje.getFullYear();
		var mm = String(hoje.getMonth() + 1).padStart(2, '0');
		var dd = String(hoje.getDate()).padStart(2, '0');
		Swal.fire({
			title: 'Renegociar débitos',
			width: 560,
			html:
				'<p class="small text-start mb-2">' +
				ids.length +
				' título(s) · saldo selecionado <strong>' +
				moeda(sugerido) +
				'</strong></p>' +
				'<div class="text-start">' +
				'<label class="form-label small mb-0">Valor total do acordo (R$)</label>' +
				'<input id="rng-valor" type="number" step="0.01" min="0.01" class="form-control form-control-sm mb-2" value="' +
				sugerido.toFixed(2) +
				'">' +
				'<label class="form-label small mb-0">Valor de entrada (R$)</label>' +
				'<input id="rng-entrada" type="number" step="0.01" min="0" class="form-control form-control-sm mb-2" value="0">' +
				'<label class="form-label small mb-0">Parcelas do restante</label>' +
				'<input id="rng-qtd" type="number" min="0" max="120" class="form-control form-control-sm mb-2" value="3">' +
				'<p id="rng-resumo" class="small text-primary mb-2"></p>' +
				'<label class="form-label small mb-0">Primeiro vencimento (entrada ou 1ª parcela)</label>' +
				'<input id="rng-venc" type="date" class="form-control form-control-sm mb-2" value="' +
				yyyy +
				'-' +
				mm +
				'-' +
				dd +
				'">' +
				'<label class="form-label small mb-0">Observação (opcional)</label>' +
				'<input id="rng-obs" class="form-control form-control-sm" maxlength="500">' +
				'<p class="small text-muted mt-2 mb-0">Com entrada, ela vence na data acima; as demais parcelas começam no mês seguinte. Os títulos antigos ficam como “Renegociação” no histórico.</p>' +
				'</div>',
			showCancelButton: true,
			confirmButtonText: 'Criar acordo',
			didOpen: function () {
				function atualizarResumo() {
					var r = calcResumoRenegociacao(
						parseFloat($('#rng-valor').val()),
						parseFloat($('#rng-entrada').val()),
						parseInt($('#rng-qtd').val(), 10)
					);
					var $el = $('#rng-resumo');
					if (!r.ok) {
						$el.removeClass('text-primary').addClass('text-danger').text(r.msg);
						return;
					}
					$el.removeClass('text-danger').addClass('text-primary').text('Resumo: ' + r.html);
				}
				$('#rng-valor, #rng-entrada, #rng-qtd').on('input change', atualizarResumo);
				atualizarResumo();
			},
			preConfirm: function () {
				var valor = parseFloat($('#rng-valor').val());
				var entrada = parseFloat($('#rng-entrada').val());
				if (isNaN(entrada) || entrada < 0) entrada = 0;
				var qtd = parseInt($('#rng-qtd').val(), 10);
				if (isNaN(qtd) || qtd < 0) qtd = 0;
				var venc = $('#rng-venc').val();
				var r = calcResumoRenegociacao(valor, entrada, qtd);
				if (!(valor > 0)) {
					Swal.showValidationMessage('Informe o valor total.');
					return false;
				}
				if (!r.ok) {
					Swal.showValidationMessage(r.msg);
					return false;
				}
				if (!venc) {
					Swal.showValidationMessage('Informe o primeiro vencimento.');
					return false;
				}
				return {
					valor_total: valor,
					valor_entrada: entrada,
					qtd_parcelas_restante: qtd,
					primeiro_vencimento: venc,
					observacao: ($('#rng-obs').val() || '').trim(),
				};
			},
		}).then(function (r) {
			if (!r.isConfirmed) return;
			post({
				acao: 'renegociar',
				ids_titulos: JSON.stringify(ids),
				valor_total: r.value.valor_total,
				valor_entrada: r.value.valor_entrada,
				qtd_parcelas_restante: r.value.qtd_parcelas_restante,
				primeiro_vencimento: r.value.primeiro_vencimento,
				observacao: r.value.observacao,
			}).done(function (res) {
				if (!res || !res.success) {
					toastErr((res && res.message) || 'Falha ao renegociar.');
					return;
				}
				toastOk(res.message || 'Acordo criado.');
				carregar();
			});
		});
	}

	function aplicarCoresClarasPdf(root) {
		root.querySelectorAll('*').forEach(function (el) {
			if (el.tagName === 'IMG') return;
			el.style.setProperty('color', '#000', 'important');
			el.style.setProperty('background-color', '#fff', 'important');
			el.style.setProperty('background', '#fff', 'important');
			el.style.setProperty('border-color', '#ccc', 'important');
			el.style.setProperty('box-shadow', 'none', 'important');
		});
		root.querySelectorAll('.table-hover tbody tr:nth-child(odd) td, .table-hover tbody tr:nth-child(odd) th').forEach(
			function (el) {
				el.style.setProperty('background-color', '#f5f5f5', 'important');
				el.style.setProperty('color', '#000', 'important');
			}
		);
	}

	function prepararExtratoParaPdf(node) {
		var clone = node.cloneNode(true);
		clone.querySelectorAll('input[type="checkbox"], .btn, button').forEach(function (el) {
			el.remove();
		});
		clone.querySelectorAll('tr').forEach(function (tr) {
			var cells = tr.querySelectorAll('th, td');
			if (cells.length >= 2) {
				cells[cells.length - 1].remove();
				cells[0].remove();
			}
		});
		clone.querySelectorAll('.badge').forEach(function (el) {
			el.style.setProperty('background-color', '#eee', 'important');
			el.style.setProperty('color', '#000', 'important');
		});
		aplicarCoresClarasPdf(clone);
		return clone;
	}

	function baixarPdf() {
		var el = document.getElementById('card-titulos-pdf');
		if (!el || typeof html2pdf === 'undefined') {
			toastErr('Não foi possível gerar o PDF.');
			return;
		}
		var nome = (estado.aluno && estado.aluno.nome) || 'aluno';
		var wrapper = document.createElement('div');
		wrapper.className = 'extrato-pdf-export';
		wrapper.style.background = '#fff';
		wrapper.style.color = '#000';
		wrapper.style.padding = '12px';

		var stylePdf = document.createElement('style');
		stylePdf.textContent =
			'.extrato-pdf-export, .extrato-pdf-export * {' +
			'color: #000 !important; background: #fff !important; background-color: #fff !important;' +
			'border-color: #ccc !important; box-shadow: none !important;' +
			'}';
		wrapper.appendChild(stylePdf);

		var cab = document.createElement('div');
		cab.style.marginBottom = '12px';
		cab.innerHTML =
			'<h4 style="margin:0 0 4px;font-size:16px;color:#000">Extrato financeiro</h4>' +
			'<div style="font-size:12px;color:#333">' +
			esc(nome) +
			(estado.aluno && estado.aluno.email ? ' · ' + esc(estado.aluno.email) : '') +
			' · ' +
			new Date().toLocaleDateString('pt-BR') +
			'</div>';
		wrapper.appendChild(cab);
		wrapper.appendChild(prepararExtratoParaPdf(el));
		aplicarCoresClarasPdf(wrapper);

		var opt = {
			margin: [10, 10, 10, 10],
			filename: 'extrato-' + nome.replace(/\s+/g, '-').toLowerCase() + '.pdf',
			image: { type: 'jpeg', quality: 0.98 },
			html2canvas: { scale: 2, useCORS: true, backgroundColor: '#ffffff' },
			jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
		};
		html2pdf().set(opt).from(wrapper).save();
	}

	function coletarIdsBaixaLote(idAcordo) {
		if (idAcordo) {
			var ids = [];
			var vistos = {};
			$('#box-extrato .chk-acordo-' + idAcordo + ':checked').each(function () {
				var id = parseInt($(this).val(), 10);
				if (id > 0 && !vistos[id]) {
					vistos[id] = true;
					ids.push(id);
				}
			});
			return ids;
		}
		return idsSelecionados();
	}

	function abrirBaixaLote(idAcordo) {
		var ids = coletarIdsBaixaLote(idAcordo || 0);
		if (!ids.length) {
			toastInfo('Marque as parcelas em aberto/vencidas que deseja baixar.');
			return;
		}
		var total = 0;
		(estado.titulos || []).forEach(function (t) {
			if (ids.indexOf(t.id) >= 0) total += valorTituloParaSoma(t);
		});
		var hoje = new Date();
		var yyyy = hoje.getFullYear();
		var mm = String(hoje.getMonth() + 1).padStart(2, '0');
		var dd = String(hoje.getDate()).padStart(2, '0');
		Swal.fire({
			title: 'Dar baixa em ' + ids.length + ' parcela(s)',
			width: 480,
			html:
				'<p class="small text-start mb-2">Total: <strong>' +
				moeda(total) +
				'</strong> (com encargos quando aplicável)</p>' +
				'<div class="text-start">' +
				'<label class="form-label small mb-0">Forma de pagamento</label>' +
				'<select id="bx-lote-tipo" class="form-select form-select-sm mb-2">' +
				'<option value="">Selecione</option>' +
				'<option value="Dinheiro">Dinheiro</option>' +
				'<option value="Pix">Pix</option>' +
				'<option value="Cartão">Cartão</option>' +
				'<option value="Transferência">Transferência</option>' +
				'<option value="Boleto">Boleto</option>' +
				'</select>' +
				'<label class="form-label small mb-0">Data do pagamento</label>' +
				'<input id="bx-lote-data" type="date" class="form-control form-control-sm" value="' +
				yyyy +
				'-' +
				mm +
				'-' +
				dd +
				'">' +
				'</div>',
			showCancelButton: true,
			confirmButtonText: 'Confirmar baixas',
			preConfirm: function () {
				var tipo = ($('#bx-lote-tipo').val() || '').trim();
				var data = $('#bx-lote-data').val();
				if (!tipo) {
					Swal.showValidationMessage('Selecione a forma de pagamento.');
					return false;
				}
				if (!data) {
					Swal.showValidationMessage('Informe a data.');
					return false;
				}
				return { tipo_pagamento: tipo, data_pagamento: data };
			},
		}).then(function (r) {
			if (!r.isConfirmed || !r.value) return;
			post({
				acao: 'dar_baixa_lote',
				ids_titulos: ids.join(','),
				tipo_pagamento: r.value.tipo_pagamento,
				data_pagamento: r.value.data_pagamento,
			})
				.done(function (res) {
					if (!res || !res.success) {
						toastErr((res && res.message) || 'Falha ao dar baixa.');
						return;
					}
					toastOk(res.message || 'Baixas registradas.');
					carregar();
				})
				.fail(function (xhr) {
					var msg = 'Falha ao dar baixa.';
					if (xhr.responseJSON && xhr.responseJSON.message) msg = xhr.responseJSON.message;
					toastErr(msg);
				});
		});
	}

	$('#btn-renegociar').on('click', abrirRenegociar);
	$('#btn-pdf').on('click', baixarPdf);
	$('#btn-baixa-lote').on('click', abrirBaixaLote);
	carregar();
})();
