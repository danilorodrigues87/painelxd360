(function () {
	'use strict';

	var API = 'painel/whatsapp/fluxos';
	var modal = null;
	var passos = []; // { id, type, config, next }
	var uid = 0;
	var simMsgs = [];
	var paginaFluxos = 1;

	function esc(s) {
		return $('<div>').text(s == null ? '' : String(s)).html();
	}

	function renderPaginacao($el, pag, onPage) {
		pag = pag || {};
		var pages = parseInt(pag.pages, 10) || 1;
		var page = parseInt(pag.page, 10) || 1;
		var total = parseInt(pag.total, 10) || 0;
		if (pages <= 1) {
			$el.empty();
			return;
		}
		var html = '<ul class="pagination pagination-sm mb-0 justify-content-end">';
		html += '<li class="page-item' + (page <= 1 ? ' disabled' : '') + '"><a class="page-link" href="#" data-p="' + (page - 1) + '">«</a></li>';
		for (var i = 1; i <= pages; i++) {
			if (pages > 9 && Math.abs(i - page) > 3 && i !== 1 && i !== pages) {
				if (i === 2 || i === pages - 1) html += '<li class="page-item disabled"><span class="page-link">…</span></li>';
				continue;
			}
			html += '<li class="page-item' + (i === page ? ' active' : '') + '"><a class="page-link" href="#" data-p="' + i + '">' + i + '</a></li>';
		}
		html += '<li class="page-item' + (page >= pages ? ' disabled' : '') + '"><a class="page-link" href="#" data-p="' + (page + 1) + '">»</a></li>';
		html += '</ul>';
		if (total) html = '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2"><small class="text-muted">' + total + ' registro(s)</small>' + html + '</div>';
		$el.html(html);
		$el.find('a.page-link[data-p]').on('click', function (e) {
			e.preventDefault();
			var p = parseInt($(this).data('p'), 10);
			if (!p || p < 1 || p > pages || p === page) return;
			onPage(p);
		});
	}

	function novoId() {
		uid += 1;
		return 'n' + uid + '_' + Date.now().toString(36).slice(-4);
	}

	function labelTipo(t) {
		var map = {
			send_text: 'Enviar texto',
			send_media: 'Enviar mídia',
			ask_options: 'Pergunta com opções',
			ask_text: 'Pergunta livre',
			condition: 'Condição',
			delay: 'Delay',
			criar_lead: 'Criar lead CRM',
			set_var: 'Definir variável',
			goto_setor: 'Ir para setor',
			goto_humano: 'Fila humana',
			end: 'Encerrar'
		};
		return map[t] || t;
	}

	function triggerLabel(tr) {
		tr = tr || {};
		if (tr.tipo === 'primeira_msg') return 'Primeira mensagem';
		if (tr.tipo === 'saudacao') return 'Saudação';
		return (tr.modo || 'contem') + ': “' + (tr.palavra || '') + '”';
	}

	function opcoesSetorHtml(selected) {
		var html = '<option value="0">Fila geral</option>';
		(window.WA_SETORES_FLUXO || []).forEach(function (s) {
			html += '<option value="' + s.id + '"' + (String(selected) === String(s.id) ? ' selected' : '') + '>' + esc(s.nome) + '</option>';
		});
		return html;
	}

	function defaultConfig(type) {
		if (type === 'send_text') return { texto: '' };
		if (type === 'send_media') return { tipo: 'image', path: '', caption: '', mimetype: '', nome: '' };
		if (type === 'ask_text') return { texto: 'Qual o seu nome?', var: 'nome' };
		if (type === 'ask_options') {
			return {
				texto: '',
				intro: 'Escolha uma opção digitando o *número*:',
				opcoes: [
					{ num: '1', label: 'Opção A', next: '' },
					{ num: '2', label: 'Opção B', next: '' }
				]
			};
		}
		if (type === 'condition') {
			return { campo: 'ultima_resposta', op: 'contem', valor: '', next_true: '', next_false: '' };
		}
		if (type === 'delay') return { segundos: 1 };
		if (type === 'criar_lead') {
			return { nome_var: 'nome', curso_var: 'curso', origem: 'WhatsApp bot' };
		}
		if (type === 'set_var') return { var: 'interesse', valor: '' };
		if (type === 'goto_setor') return { setor_id: 0, texto: '' };
		if (type === 'goto_humano') return { texto: 'Aguarde, em breve um atendente irá responder.' };
		if (type === 'end') return { texto: 'Obrigado! Atendimento encerrado.' };
		return {};
	}

	function isLinearType(type) {
		return ['send_text', 'send_media', 'ask_text', 'delay', 'criar_lead', 'set_var'].indexOf(type) >= 0;
	}

	function syncNextLinear() {
		for (var i = 0; i < passos.length; i++) {
			var p = passos[i];
			var nextId = passos[i + 1] ? passos[i + 1].id : '';
			if (['ask_options', 'condition'].indexOf(p.type) >= 0) {
				continue;
			}
			p.next = nextId;
			if (isLinearType(p.type)) {
				p.config.next = nextId;
			}
		}
	}

	function renderPassos() {
		syncNextLinear();
		var $box = $('#lista-passos').empty();
		if (!passos.length) {
			$box.html('<p class="text-muted small mb-0">Adicione passos com os botões acima.</p>');
			return;
		}
		var optsNext = '<option value="">(fim)</option>';
		passos.forEach(function (p) {
			optsNext += '<option value="' + esc(p.id) + '">' + esc(p.id + ' — ' + labelTipo(p.type)) + '</option>';
		});

		passos.forEach(function (p, idx) {
			var html = '<div class="card passo-card" data-idx="' + idx + '">'
				+ '<div class="card-header py-2 d-flex justify-content-between align-items-center">'
				+ '<span><strong>' + (idx + 1) + '.</strong> ' + esc(labelTipo(p.type))
				+ ' <code class="small text-muted">' + esc(p.id) + '</code></span>'
				+ '<button type="button" class="btn btn-sm btn-outline-danger btn-rm-passo">Remover</button>'
				+ '</div><div class="card-body py-2">';

			if (p.type === 'send_text' || p.type === 'ask_text' || p.type === 'goto_humano' || p.type === 'end') {
				html += '<label class="form-label small">Texto</label>'
					+ '<textarea class="form-control form-control-sm passo-campo" data-k="texto" rows="2">' + esc(p.config.texto || '') + '</textarea>';
				if (p.type === 'ask_text') {
					html += '<label class="form-label small mt-2">Salvar resposta em variável</label>'
						+ '<input class="form-control form-control-sm passo-campo" data-k="var" value="' + esc(p.config.var || 'resposta') + '">';
				}
			}

			if (p.type === 'send_media') {
				html += '<div class="row g-2"><div class="col-md-3"><label class="form-label small">Tipo</label>'
					+ '<select class="form-select form-select-sm passo-campo" data-k="tipo">'
					+ '<option value="image"' + (p.config.tipo === 'image' ? ' selected' : '') + '>Imagem</option>'
					+ '<option value="audio"' + (p.config.tipo === 'audio' ? ' selected' : '') + '>Áudio</option>'
					+ '<option value="document"' + (p.config.tipo === 'document' ? ' selected' : '') + '>Documento</option>'
					+ '</select></div>'
					+ '<div class="col-md-6"><label class="form-label small">Arquivo</label>'
					+ '<input type="file" class="form-control form-control-sm passo-upload">'
					+ '<div class="form-text small path-midia">' + esc(p.config.path || 'Nenhum arquivo') + '</div></div>'
					+ '<div class="col-md-3"><label class="form-label small">Legenda</label>'
					+ '<input class="form-control form-control-sm passo-campo" data-k="caption" value="' + esc(p.config.caption || '') + '"></div></div>';
			}

			if (p.type === 'ask_options') {
				html += '<label class="form-label small">Texto da pergunta (opcional — se vazio, monta pelas opções)</label>'
					+ '<textarea class="form-control form-control-sm passo-campo" data-k="texto" rows="2">' + esc(p.config.texto || '') + '</textarea>'
					+ '<div class="table-responsive mt-2"><table class="table table-sm"><thead><tr><th>#</th><th>Label</th><th>Próximo passo</th><th></th></tr></thead><tbody class="tbody-opcoes">';
				(p.config.opcoes || []).forEach(function (op, oi) {
					html += '<tr data-oi="' + oi + '">'
						+ '<td><input class="form-control form-control-sm op-num" value="' + esc(op.num) + '" style="width:4rem"></td>'
						+ '<td><input class="form-control form-control-sm op-label" value="' + esc(op.label || '') + '"></td>'
						+ '<td><select class="form-select form-select-sm op-next">' + optsNext + '</select></td>'
						+ '<td><button type="button" class="btn btn-sm btn-outline-danger btn-rm-op">×</button></td></tr>';
				});
				html += '</tbody></table></div>'
					+ '<button type="button" class="btn btn-sm btn-outline-secondary btn-add-op">+ Opção</button>';
			}

			if (p.type === 'condition') {
				html += '<div class="row g-2">'
					+ '<div class="col-md-3"><label class="form-label small">Campo</label>'
					+ '<input class="form-control form-control-sm passo-campo" data-k="campo" value="' + esc(p.config.campo || 'ultima_resposta') + '"></div>'
					+ '<div class="col-md-3"><label class="form-label small">Operador</label>'
					+ '<select class="form-select form-select-sm passo-campo" data-k="op">'
					+ '<option value="contem"' + (p.config.op === 'contem' ? ' selected' : '') + '>Contém</option>'
					+ '<option value="exato"' + (p.config.op === 'exato' ? ' selected' : '') + '>Exato</option>'
					+ '<option value="inicia"' + (p.config.op === 'inicia' ? ' selected' : '') + '>Inicia</option>'
					+ '<option value="fora_expediente"' + (p.config.op === 'fora_expediente' ? ' selected' : '') + '>Fora do expediente</option>'
					+ '</select></div>'
					+ '<div class="col-md-3"><label class="form-label small">Valor</label>'
					+ '<input class="form-control form-control-sm passo-campo" data-k="valor" value="' + esc(p.config.valor || '') + '"></div>'
					+ '<div class="col-md-3"><label class="form-label small">Se verdadeiro →</label>'
					+ '<select class="form-select form-select-sm cond-true">' + optsNext + '</select></div>'
					+ '<div class="col-md-3"><label class="form-label small">Se falso →</label>'
					+ '<select class="form-select form-select-sm cond-false">' + optsNext + '</select></div>'
					+ '</div>';
			}

			if (p.type === 'delay') {
				html += '<label class="form-label small">Segundos (máx. 3)</label>'
					+ '<input type="number" min="0" max="3" class="form-control form-control-sm passo-campo" data-k="segundos" value="' + esc(p.config.segundos || 1) + '" style="max-width:6rem">';
			}

			if (p.type === 'criar_lead') {
				html += '<div class="row g-2">'
					+ '<div class="col-md-4"><label class="form-label small">Var. nome</label>'
					+ '<input class="form-control form-control-sm passo-campo" data-k="nome_var" value="' + esc(p.config.nome_var || 'nome') + '"></div>'
					+ '<div class="col-md-4"><label class="form-label small">Var. curso/interesse</label>'
					+ '<input class="form-control form-control-sm passo-campo" data-k="curso_var" value="' + esc(p.config.curso_var || 'curso') + '"></div>'
					+ '<div class="col-md-4"><label class="form-label small">Origem</label>'
					+ '<input class="form-control form-control-sm passo-campo" data-k="origem" value="' + esc(p.config.origem || 'WhatsApp bot') + '"></div>'
					+ '</div>'
					+ '<div class="form-text small">Cria ou atualiza lead pelo WhatsApp do contato (simulação não grava).</div>';
			}

			if (p.type === 'set_var') {
				html += '<div class="row g-2">'
					+ '<div class="col-md-4"><label class="form-label small">Variável</label>'
					+ '<input class="form-control form-control-sm passo-campo" data-k="var" value="' + esc(p.config.var || '') + '"></div>'
					+ '<div class="col-md-8"><label class="form-label small">Valor (aceita {{variaveis}})</label>'
					+ '<input class="form-control form-control-sm passo-campo" data-k="valor" value="' + esc(p.config.valor || '') + '"></div>'
					+ '</div>';
			}

			if (p.type === 'goto_setor') {
				html += '<label class="form-label small">Setor</label>'
					+ '<select class="form-select form-select-sm passo-campo" data-k="setor_id">' + opcoesSetorHtml(p.config.setor_id) + '</select>'
					+ '<label class="form-label small mt-2">Mensagem (opcional)</label>'
					+ '<textarea class="form-control form-control-sm passo-campo" data-k="texto" rows="2">' + esc(p.config.texto || '') + '</textarea>';
			}

			html += '</div></div>';
			$box.append(html);

			var $card = $box.find('.passo-card').last();
			if (p.type === 'ask_options') {
				$card.find('tr').each(function () {
					var oi = parseInt($(this).data('oi'), 10);
					var op = (p.config.opcoes || [])[oi];
					if (op) $(this).find('.op-next').val(op.next || '');
				});
			}
			if (p.type === 'condition') {
				$card.find('.cond-true').val(p.config.next_true || '');
				$card.find('.cond-false').val(p.config.next_false || '');
			}
		});
	}

	function coletarPassosDoDom() {
		$('#lista-passos .passo-card').each(function () {
			var idx = parseInt($(this).data('idx'), 10);
			var p = passos[idx];
			if (!p) return;
			$(this).find('.passo-campo').each(function () {
				var k = $(this).data('k');
				var v = $(this).val();
				if (k === 'segundos' || k === 'setor_id') v = parseInt(v, 10) || 0;
				p.config[k] = v;
			});
			if (p.type === 'ask_options') {
				var ops = [];
				$(this).find('.tbody-opcoes tr').each(function () {
					ops.push({
						num: String($(this).find('.op-num').val() || '').trim(),
						label: String($(this).find('.op-label').val() || '').trim(),
						next: String($(this).find('.op-next').val() || '')
					});
				});
				p.config.opcoes = ops;
			}
			if (p.type === 'condition') {
				p.config.next_true = String($(this).find('.cond-true').val() || '');
				p.config.next_false = String($(this).find('.cond-false').val() || '');
			}
		});
		syncNextLinear();
	}

	function montarDefinicao() {
		coletarPassosDoDom();
		var nodes = {};
		passos.forEach(function (p) {
			var node = { type: p.type, config: p.config || {} };
			if (p.next) node.next = p.next;
			if (isLinearType(p.type)) {
				node.config.next = p.next || node.config.next || '';
			}
			nodes[p.id] = node;
		});
		return {
			trigger: {
				tipo: $('#tr_tipo').val(),
				modo: $('#tr_modo').val(),
				palavra: $('#tr_palavra').val()
			},
			settings: {
				timeout_horas: parseInt($('#st_timeout_horas').val(), 10) || 0,
				timeout_acao: $('#st_timeout_acao').val() || 'humano'
			},
			start: passos[0] ? passos[0].id : '',
			nodes: nodes
		};
	}

	function renderSimChat(mensagens) {
		var $box = $('#sim-chat').empty();
		if (!mensagens || !mensagens.length) {
			$box.html('<span class="text-muted">Envie uma mensagem para iniciar a simulação.</span>');
			return;
		}
		mensagens.forEach(function (m) {
			var cls = m.from === 'user' ? 'text-end' : 'text-start';
			var badge = m.from === 'user' ? 'bg-primary' : (m.tipo === 'system' ? 'bg-secondary' : 'bg-success');
			var who = m.from === 'user' ? 'Você' : (m.tipo === 'system' ? 'Sistema' : 'Bot');
			$box.append(
				'<div class="mb-1 ' + cls + '">'
				+ '<span class="badge ' + badge + ' me-1">' + who + '</span>'
				+ '<span class="d-inline-block text-start" style="white-space:pre-wrap">' + esc(m.texto || m.detalhe || '') + '</span>'
				+ '</div>'
			);
		});
		$box.scrollTop($box[0].scrollHeight);
	}

	function reiniciarSim() {
		simMsgs = [];
		renderSimChat([]);
	}

	function rodarSimulacao() {
		var def = montarDefinicao();
		if (!def.start) {
			Swal.fire('Atenção', 'Adicione pelo menos um passo antes de simular.', 'warning');
			return;
		}
		$.post(url_base + API, {
			acao: 'simular',
			definicao: JSON.stringify(def),
			mensagens: JSON.stringify(simMsgs.length ? simMsgs : [''])
		}, function (res) {
			if (!res || !res.success) {
				Swal.fire('Atenção', (res && res.message) || 'Falha na simulação', 'warning');
				return;
			}
			renderSimChat(res.mensagens || []);
		}, 'json');
	}

	function abrirEditor(fluxo) {
		fluxo = fluxo || null;
		$('#fluxo_id').val(fluxo ? fluxo.id : '');
		$('#fluxo_nome').val(fluxo ? fluxo.nome : '');
		$('#fluxo_prioridade').val(fluxo ? fluxo.prioridade : 100);
		$('#fluxo_ativo').prop('checked', !fluxo || !!fluxo.ativo);
		var tr = (fluxo && fluxo.trigger) ? fluxo.trigger : { tipo: 'keyword', modo: 'contem', palavra: '' };
		$('#tr_tipo').val(tr.tipo || 'keyword');
		$('#tr_modo').val(tr.modo || 'contem');
		$('#tr_palavra').val(tr.palavra || '');
		var st = (fluxo && fluxo.settings) ? fluxo.settings : { timeout_horas: 24, timeout_acao: 'humano' };
		$('#st_timeout_horas').val(st.timeout_horas != null ? st.timeout_horas : 24);
		$('#st_timeout_acao').val(st.timeout_acao || 'humano');
		toggleTrigger();
		reiniciarSim();

		passos = [];
		uid = 0;
		if (fluxo && fluxo.nodes) {
			var order = [];
			var seen = {};
			var cur = fluxo.start;
			var guard = 0;
			while (cur && fluxo.nodes[cur] && !seen[cur] && guard < 60) {
				seen[cur] = true;
				order.push(cur);
				var n = fluxo.nodes[cur];
				cur = n.next || (n.config && n.config.next) || '';
				if (n.type === 'ask_options' || n.type === 'condition' || n.type === 'end' || n.type === 'goto_setor' || n.type === 'goto_humano') {
					break;
				}
				guard++;
			}
			Object.keys(fluxo.nodes).forEach(function (id) {
				if (!seen[id]) order.push(id);
			});
			order.forEach(function (id) {
				var n = fluxo.nodes[id];
				passos.push({
					id: id,
					type: n.type,
					config: $.extend(true, {}, defaultConfig(n.type), n.config || {}),
					next: n.next || (n.config && n.config.next) || ''
				});
				var m = String(id).match(/^n(\d+)/);
				if (m) uid = Math.max(uid, parseInt(m[1], 10));
			});
		}
		$('#modalFluxoTitulo').text(fluxo ? 'Editar fluxo' : 'Novo fluxo');
		renderPassos();
		modal.show();
	}

	function toggleTrigger() {
		var t = $('#tr_tipo').val();
		var kw = t === 'keyword';
		$('#wrap-tr-modo, #wrap-tr-palavra').toggleClass('d-none', !kw);
	}

	function renderTemplates() {
		var list = window.WA_TEMPLATES_FLUXO || [];
		var $box = $('#lista-templates').empty();
		if (!list.length) {
			$box.html('<p class="text-muted small mb-0">Nenhum template disponível.</p>');
			return;
		}
		var html = '<p class="small text-muted mb-2">Cada template ensina um padrão diferente. Crie inativo → edite → teste no <em>simulador</em> → ative. Não ative dois com o mesmo gatilho (ex.: dois de saudação).</p>'
			+ '<div class="row g-2">';
		list.forEach(function (t) {
			html += '<div class="col-md-4 col-lg-3">'
				+ '<div class="border rounded p-2 h-100 d-flex flex-column">'
				+ '<strong class="small">' + esc(t.nome) + '</strong>'
				+ '<p class="small text-muted mb-2 flex-grow-1" style="font-size:.8rem">' + esc(t.descricao || '') + '</p>'
				+ '<button type="button" class="btn btn-sm btn-outline-primary align-self-start btn-aplicar-tpl" data-id="' + esc(t.id) + '">Usar</button>'
				+ '</div></div>';
		});
		html += '</div>';
		$box.html(html);
	}

	function carregarLista(page) {
		if (page) paginaFluxos = page;
		$.post(url_base + API, { acao: 'listar', page: paginaFluxos }, function (res) {
			var $tb = $('#tbody-fluxos').empty();
			if (!res || !res.success) {
				$tb.append('<tr><td colspan="5" class="text-danger text-center py-4">' + esc((res && res.message) || 'Falha') + '</td></tr>');
				$('#paginacao-fluxos').empty();
				return;
			}
			if (!res.itens.length) {
				$tb.append('<tr><td colspan="5" class="text-muted text-center py-4">Nenhum fluxo. Use um template ou crie o primeiro.</td></tr>');
				$('#paginacao-fluxos').empty();
				window.__waFluxosCache = [];
				return;
			}
			res.itens.forEach(function (f) {
				$tb.append(
					'<tr>'
					+ '<td><strong>' + esc(f.nome) + '</strong></td>'
					+ '<td><small>' + esc(triggerLabel(f.trigger)) + '</small></td>'
					+ '<td>' + esc(f.prioridade) + '</td>'
					+ '<td>' + (f.ativo ? '<span class="badge bg-success">Ativo</span>' : '<span class="badge bg-secondary">Inativo</span>') + '</td>'
					+ '<td class="text-end">'
					+ '<button type="button" class="btn btn-sm btn-outline-primary me-1 btn-editar" data-id="' + f.id + '">Editar</button>'
					+ '<button type="button" class="btn btn-sm btn-outline-secondary me-1 btn-toggle" data-id="' + f.id + '">' + (f.ativo ? 'Desativar' : 'Ativar') + '</button>'
					+ '<button type="button" class="btn btn-sm btn-outline-danger btn-excluir" data-id="' + f.id + '">Excluir</button>'
					+ '</td></tr>'
				);
			});
			window.__waFluxosCache = res.itens;
			if (res.pagination) paginaFluxos = res.pagination.page || paginaFluxos;
			renderPaginacao($('#paginacao-fluxos'), res.pagination, carregarLista);
		}, 'json');
	}

	$(function () {
		modal = new bootstrap.Modal(document.getElementById('modalFluxo'));
		renderTemplates();
		carregarLista();

		$('#btn-novo-fluxo').on('click', function () { abrirEditor(null); });
		$('#tr_tipo').on('change', toggleTrigger);

		$(document).on('click', '.btn-aplicar-tpl', function () {
			var tid = $(this).data('id');
			Swal.fire({
				title: 'Usar este template?',
				text: 'Será criado um fluxo inativo para você editar e ativar.',
				icon: 'question',
				showCancelButton: true,
				confirmButtonText: 'Criar'
			}).then(function (r) {
				if (!r.isConfirmed) return;
				$.post(url_base + API, { acao: 'aplicar_template', template_id: tid }, function (res) {
					if (!res || !res.success) {
						Swal.fire('Atenção', (res && res.message) || 'Falha', 'warning');
						return;
					}
					Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: res.message, showConfirmButton: false, timer: 2200 });
					carregarLista();
					setTimeout(function () {
						var f = (window.__waFluxosCache || []).find(function (x) { return x.id === res.id; });
						if (f) abrirEditor(f);
					}, 400);
				}, 'json');
			});
		});

		$(document).on('click', '[data-add]', function () {
			coletarPassosDoDom();
			var type = $(this).data('add');
			passos.push({ id: novoId(), type: type, config: defaultConfig(type), next: '' });
			renderPassos();
		});

		$(document).on('click', '.btn-rm-passo', function () {
			coletarPassosDoDom();
			var idx = parseInt($(this).closest('.passo-card').data('idx'), 10);
			passos.splice(idx, 1);
			renderPassos();
		});

		$(document).on('click', '.btn-add-op', function () {
			coletarPassosDoDom();
			var idx = parseInt($(this).closest('.passo-card').data('idx'), 10);
			var p = passos[idx];
			if (!p.config.opcoes) p.config.opcoes = [];
			p.config.opcoes.push({ num: String(p.config.opcoes.length + 1), label: '', next: '' });
			renderPassos();
		});

		$(document).on('click', '.btn-rm-op', function () {
			coletarPassosDoDom();
			var $card = $(this).closest('.passo-card');
			var idx = parseInt($card.data('idx'), 10);
			var oi = parseInt($(this).closest('tr').data('oi'), 10);
			passos[idx].config.opcoes.splice(oi, 1);
			renderPassos();
		});

		$(document).on('change', '.passo-upload', function () {
			var $card = $(this).closest('.passo-card');
			var idx = parseInt($card.data('idx'), 10);
			var file = this.files[0];
			if (!file) return;
			var fd = new FormData();
			fd.append('acao', 'upload_midia');
			fd.append('arquivo', file);
			$.ajax({
				url: url_base + API,
				method: 'POST',
				data: fd,
				processData: false,
				contentType: false,
				dataType: 'json'
			}).done(function (res) {
				if (!res || !res.success) {
					Swal.fire('Atenção', (res && res.message) || 'Upload falhou', 'warning');
					return;
				}
				passos[idx].config.path = res.path;
				passos[idx].config.tipo = res.tipo;
				passos[idx].config.mimetype = res.mimetype;
				passos[idx].config.nome = res.nome;
				$card.find('.path-midia').text(res.path);
			});
		});

		$('#btn-sim-reiniciar').on('click', reiniciarSim);
		$('#btn-sim-enviar').on('click', function () {
			var txt = String($('#sim-input').val() || '').trim();
			if (!txt && !simMsgs.length) {
				txt = '';
			} else if (!txt) {
				return;
			}
			simMsgs.push(txt);
			$('#sim-input').val('');
			rodarSimulacao();
		});
		$('#sim-input').on('keydown', function (e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				$('#btn-sim-enviar').click();
			}
		});

		$('#btn-salvar-fluxo').on('click', function () {
			var def = montarDefinicao();
			if (!def.start) {
				Swal.fire('Atenção', 'Adicione pelo menos um passo.', 'warning');
				return;
			}
			$.post(url_base + API, {
				acao: 'salvar',
				id: $('#fluxo_id').val() || '',
				nome: $('#fluxo_nome').val() || '',
				prioridade: $('#fluxo_prioridade').val() || 100,
				ativo: $('#fluxo_ativo').is(':checked') ? 1 : 0,
				definicao: JSON.stringify(def)
			}, function (res) {
				if (!res || !res.success) {
					Swal.fire('Atenção', (res && res.message) || 'Falha', 'warning');
					return;
				}
				modal.hide();
				Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: res.message, showConfirmButton: false, timer: 1800 });
				carregarLista();
			}, 'json');
		});

		$(document).on('click', '.btn-editar', function () {
			var id = parseInt($(this).data('id'), 10);
			var f = (window.__waFluxosCache || []).find(function (x) { return x.id === id; });
			if (f) abrirEditor(f);
		});

		$(document).on('click', '.btn-toggle', function () {
			$.post(url_base + API, { acao: 'toggle', id: $(this).data('id') }, function () {
				carregarLista();
			}, 'json');
		});

		$(document).on('click', '.btn-excluir', function () {
			var id = $(this).data('id');
			Swal.fire({
				title: 'Excluir fluxo?',
				icon: 'warning',
				showCancelButton: true,
				confirmButtonText: 'Excluir'
			}).then(function (r) {
				if (!r.isConfirmed) return;
				$.post(url_base + API, { acao: 'excluir', id: id }, function () {
					carregarLista();
				}, 'json');
			});
		});
	});
})();
