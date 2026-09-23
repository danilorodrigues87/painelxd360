/* global url_base, INADIMPLENTES_URL, cancelar_contrato, html2pdf */

var inadAbaAtual = 'inadimplentes';
var inadPagina = 1;
var inadPerPage = 50;
var inadUltimaResposta = null;

function escHtml(s) {
	return String(s || '')
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;');
}

function moedaBr(n) {
	var v = parseFloat(n);
	if (isNaN(v)) return 'R$ 0,00';
	return 'R$ ' + v.toFixed(2).replace('.', ',');
}

function renderPaginacaoInad($el, total, page, perPage, onPage) {
	var pages = perPage > 0 ? Math.max(1, Math.ceil(total / perPage)) : 1;
	if (pages <= 1) {
		$el.empty();
		return;
	}
	var html = '<ul class="pagination pagination-sm mb-0 justify-content-end">';
	html +=
		'<li class="page-item' +
		(page <= 1 ? ' disabled' : '') +
		'"><a class="page-link" href="#" data-p="' +
		(page - 1) +
		'">«</a></li>';
	for (var i = 1; i <= pages; i++) {
		if (pages > 9 && Math.abs(i - page) > 3 && i !== 1 && i !== pages) {
			if (i === 2 || i === pages - 1) {
				html += '<li class="page-item disabled"><span class="page-link">…</span></li>';
			}
			continue;
		}
		html +=
			'<li class="page-item' +
			(i === page ? ' active' : '') +
			'"><a class="page-link" href="#" data-p="' +
			i +
			'">' +
			i +
			'</a></li>';
	}
	html +=
		'<li class="page-item' +
		(page >= pages ? ' disabled' : '') +
		'"><a class="page-link" href="#" data-p="' +
		(page + 1) +
		'">»</a></li>';
	html += '</ul>';
	html =
		'<div class="d-flex justify-content-between align-items-center flex-wrap gap-2"><small class="text-muted">' +
		total +
		' registro(s)</small>' +
		html +
		'</div>';
	$el.html(html);
	$el.find('a.page-link[data-p]').on('click', function (e) {
		e.preventDefault();
		var p = parseInt($(this).data('p'), 10);
		if (!p || p < 1 || p > pages || p === page) return;
		onPage(p);
	});
}

function coletarFiltrosInad() {
	var data = {};
	$('#form-filtros-inad').serializeArray().forEach(function (item) {
		data[item.name] = item.value;
	});
	data.page = inadPagina;
	data.per_page = inadPerPage;
	return data;
}

function coletarFiltrosAbandono() {
	var data = {};
	$('#form-filtros-abandono').serializeArray().forEach(function (item) {
		data[item.name] = item.value;
	});
	data.somente_com_debito = $('#abd-debito').is(':checked') ? 1 : 0;
	data.page = inadPagina;
	data.per_page = inadPerPage;
	return data;
}

function coletarFiltrosAtual() {
	return inadAbaAtual === 'abandono' ? coletarFiltrosAbandono() : coletarFiltrosInad();
}

function theadInadimplentes() {
	return (
		'<tr>' +
		'<th>Aluno</th>' +
		'<th>Curso</th>' +
		'<th>Status</th>' +
		'<th class="text-center">Parc. atraso</th>' +
		'<th>1º venc.</th>' +
		'<th class="text-end">Dívida</th>' +
		'<th class="text-center">Acordo</th>' +
		'<th class="text-end no-print">Ações</th>' +
		'</tr>'
	);
}

function theadAbandono() {
	return (
		'<tr>' +
		'<th>Aluno</th>' +
		'<th>Curso</th>' +
		'<th>Status</th>' +
		'<th>Última presença</th>' +
		'<th class="text-center">Dias s/ pres.</th>' +
		'<th class="text-center">Inadimpl.</th>' +
		'<th class="text-end">Dívida</th>' +
		'<th class="text-end no-print">Ações</th>' +
		'</tr>'
	);
}

function botoesAcao(linha) {
	var idMat = parseInt(linha.id_matricula, 10) || 0;
	var idAluno = parseInt(linha.id_aluno, 10) || 0;
	var html =
		'<div class="btn-group btn-group-sm">' +
		'<a class="btn btn-outline-primary" href="' +
		url_base +
		'painel/alunos/' +
		idAluno +
		'/extrato" title="Extrato"><i class="fa-solid fa-file-invoice-dollar"></i></a>' +
		'<a class="btn btn-outline-secondary" href="' +
		url_base +
		'painel/agenda/diario" title="Diário"><i class="fa-regular fa-calendar-check"></i></a>';
	if (linha.pode_cancelar && idMat > 0) {
		html +=
			'<button type="button" class="btn btn-outline-danger btn-cancelar-contrato" data-id="' +
			idMat +
			'" title="Cancelar contrato"><i class="fa-solid fa-ban"></i></button>';
	}
	if (linha.pode_regularizar && idMat > 0) {
		html +=
			'<button type="button" class="btn btn-outline-warning btn-regularizar-financeiro" data-id="' +
			idMat +
			'" title="Regularizar financeiro"><i class="fa-solid fa-scale-balanced"></i></button>';
	}
	html += '</div>';
	return html;
}

function renderLinhaInad(l) {
	var acordo = l.tem_acordo_vencido
		? '<span class="badge bg-warning text-dark">Vencido</span>'
		: '<span class="text-muted">—</span>';
	return (
		'<tr>' +
		'<td><div class="fw-semibold">' +
		escHtml(l.nome) +
		'</div><div class="small text-muted">' +
		escHtml(l.email) +
		'</div></td>' +
		'<td>' +
		escHtml(l.curso) +
		'</td>' +
		'<td>' +
		escHtml(l.status_label) +
		'</td>' +
		'<td class="text-center">' +
		(l.qtd_parcelas_atraso || 0) +
		'</td>' +
		'<td class="text-nowrap">' +
		escHtml(l.primeiro_vencimento_br) +
		'</td>' +
		'<td class="text-end text-nowrap">' +
		moedaBr(l.divida_total) +
		'</td>' +
		'<td class="text-center">' +
		acordo +
		'</td>' +
		'<td class="text-end no-print">' +
		botoesAcao(l) +
		'</td>' +
		'</tr>'
	);
}

function renderLinhaAbandono(l) {
	var inad = l.inadimplente
		? '<span class="badge bg-danger">Sim</span>'
		: '<span class="badge bg-secondary">Não</span>';
	var dias = l.dias_sem_presenca != null ? l.dias_sem_presenca : '—';
	return (
		'<tr>' +
		'<td><div class="fw-semibold">' +
		escHtml(l.nome) +
		'</div><div class="small text-muted">' +
		escHtml(l.email) +
		'</div></td>' +
		'<td>' +
		escHtml(l.curso) +
		'</td>' +
		'<td>' +
		escHtml(l.status_label) +
		'</td>' +
		'<td class="text-nowrap">' +
		escHtml(l.ultima_presenca_br) +
		'</td>' +
		'<td class="text-center">' +
		dias +
		'</td>' +
		'<td class="text-center">' +
		inad +
		'</td>' +
		'<td class="text-end text-nowrap">' +
		moedaBr(l.divida_total) +
		'</td>' +
		'<td class="text-end no-print">' +
		botoesAcao(l) +
		'</td>' +
		'</tr>'
	);
}

function atualizarResumo(res) {
	var tot = (res && res.totais) || {};
	var txt =
		(tot.registros || 0) +
		' matrícula(s) · Dívida total: ' +
		moedaBr(tot.soma_divida || 0);
	$('#inad-resumo-totais').text(txt);
}

function renderTabela(res) {
	inadUltimaResposta = res;
	var linhas = (res && res.linhas) || [];
	var $tbody = $('#inad-tbody');
	if (inadAbaAtual === 'abandono') {
		$('#inad-thead').html(theadAbandono());
		if (!linhas.length) {
			$tbody.html('<tr><td colspan="8" class="text-center text-muted py-4">Nenhum registro encontrado.</td></tr>');
		} else {
			$tbody.html(linhas.map(renderLinhaAbandono).join(''));
		}
	} else {
		$('#inad-thead').html(theadInadimplentes());
		if (!linhas.length) {
			$tbody.html('<tr><td colspan="8" class="text-center text-muted py-4">Nenhum registro encontrado.</td></tr>');
		} else {
			$tbody.html(linhas.map(renderLinhaInad).join(''));
		}
	}
	atualizarResumo(res);
	renderPaginacaoInad(
		$('#inad-paginacao'),
		parseInt(res.total_registros, 10) || 0,
		parseInt(res.page, 10) || 1,
		parseInt(res.per_page, 10) || inadPerPage,
		function (p) {
			inadPagina = p;
			listar();
		}
	);
}

function listar() {
	var acao = inadAbaAtual === 'abandono' ? 'listar_abandono' : 'listar_inadimplentes';
	var data = coletarFiltrosAtual();
	data.acao = acao;
	$('#inad-tbody').html('<tr><td colspan="8" class="text-center text-muted py-4">Carregando…</td></tr>');

	$.ajax({
		url: url_base + INADIMPLENTES_URL,
		method: 'post',
		data: data,
		dataType: 'json',
		success: function (res) {
			if (!res || !res.success) {
				$('#inad-tbody').html(
					'<tr><td colspan="8" class="alert alert-danger mb-0">Erro ao carregar lista.</td></tr>'
				);
				return;
			}
			renderTabela(res);
		},
		error: function () {
			$('#inad-tbody').html(
				'<tr><td colspan="8" class="alert alert-danger mb-0">Falha na requisição.</td></tr>'
			);
		},
	});
}

function buscarTodosParaExporte(callback) {
	var acao = inadAbaAtual === 'abandono' ? 'listar_abandono' : 'listar_inadimplentes';
	var data = coletarFiltrosAtual();
	data.acao = acao;
	data.page = 1;
	data.per_page = 0;

	$.ajax({
		url: url_base + INADIMPLENTES_URL,
		method: 'post',
		data: data,
		dataType: 'json',
		success: function (res) {
			if (!res || !res.success) {
				Swal.fire('Erro', 'Não foi possível carregar os dados para exportação.', 'error');
				return;
			}
			callback(res);
		},
		error: function () {
			Swal.fire('Erro', 'Falha ao exportar.', 'error');
		},
	});
}

function exportarCsv() {
	var data = coletarFiltrosAtual();
	data.acao = 'exportar_csv';
	data.aba = inadAbaAtual;
	$('#btn-export-csv').prop('disabled', true);

	$.ajax({
		url: url_base + INADIMPLENTES_URL,
		method: 'post',
		data: data,
		xhrFields: { responseType: 'blob' },
		success: function (blob, _status, xhr) {
			$('#btn-export-csv').prop('disabled', false);
			var nome = 'inadimplentes_' + new Date().toISOString().slice(0, 10) + '.csv';
			var disp = xhr.getResponseHeader('Content-Disposition');
			if (disp && disp.indexOf('filename=') !== -1) {
				var m = disp.match(/filename="?([^";]+)"?/);
				if (m && m[1]) nome = m[1];
			}
			var url = URL.createObjectURL(blob);
			var a = document.createElement('a');
			a.href = url;
			a.download = nome;
			document.body.appendChild(a);
			a.click();
			document.body.removeChild(a);
			URL.revokeObjectURL(url);
		},
		error: function () {
			$('#btn-export-csv').prop('disabled', false);
			Swal.fire('Erro', 'Falha ao exportar CSV.', 'error');
		},
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
}

function montarHtmlPdf(res) {
	var linhas = res.linhas || [];
	var tot = res.totais || {};
	var titulo = inadAbaAtual === 'abandono' ? 'Abandono (sem presença)' : 'Inadimplentes';
	var filtros = coletarFiltrosAtual();
	var meta = 'Gerado em ' + new Date().toLocaleString('pt-BR');
	if (inadAbaAtual === 'abandono') {
		meta += ' · Sem presença há ' + (filtros.meses_sem_presenca || 3) + ' mês(es)';
	} else {
		meta += ' · Atraso mín. ' + (filtros.dias_atraso_min || 1) + ' dia(s)';
	}

	var html =
		'<div class="relatorio-financeiro-impressao p-2" style="background:#fff;color:#000;">' +
		'<h2 style="font-size:1.25rem;margin:0 0 8px;">' +
		escHtml(titulo) +
		'</h2>' +
		'<p style="font-size:12px;margin:0 0 12px;color:#444;">' +
		escHtml(meta) +
		'</p>' +
		'<p style="font-size:12px;margin:0 0 12px;"><strong>' +
		(tot.registros || 0) +
		'</strong> matrícula(s) · Dívida total: <strong>' +
		moedaBr(tot.soma_divida) +
		'</strong></p>' +
		'<table class="table table-bordered table-sm" style="width:100%;font-size:11px;border-collapse:collapse;">' +
		'<thead><tr style="background:#f5f5f5;">';

	if (inadAbaAtual === 'abandono') {
		html +=
			'<th>Aluno</th><th>Curso</th><th>Status</th><th>Última pres.</th><th>Dias</th><th>Inad.</th><th>Dívida</th></tr></thead><tbody>';
		linhas.forEach(function (l) {
			html +=
				'<tr><td>' +
				escHtml(l.nome) +
				'</td><td>' +
				escHtml(l.curso) +
				'</td><td>' +
				escHtml(l.status_label) +
				'</td><td>' +
				escHtml(l.ultima_presenca_br) +
				'</td><td>' +
				(l.dias_sem_presenca != null ? l.dias_sem_presenca : '—') +
				'</td><td>' +
				(l.inadimplente ? 'Sim' : 'Não') +
				'</td><td>' +
				moedaBr(l.divida_total) +
				'</td></tr>';
		});
	} else {
		html +=
			'<th>Aluno</th><th>Curso</th><th>Status</th><th>Parc.</th><th>1º venc.</th><th>Dívida</th><th>Acordo</th></tr></thead><tbody>';
		linhas.forEach(function (l) {
			html +=
				'<tr><td>' +
				escHtml(l.nome) +
				'</td><td>' +
				escHtml(l.curso) +
				'</td><td>' +
				escHtml(l.status_label) +
				'</td><td>' +
				(l.qtd_parcelas_atraso || 0) +
				'</td><td>' +
				escHtml(l.primeiro_vencimento_br) +
				'</td><td>' +
				moedaBr(l.divida_total) +
				'</td><td>' +
				(l.tem_acordo_vencido ? 'Sim' : 'Não') +
				'</td></tr>';
		});
	}

	html += '</tbody></table></div>';
	return html;
}

function exportarPdf() {
	if (typeof html2pdf === 'undefined') {
		Swal.fire('Erro', 'Biblioteca PDF não carregada.', 'error');
		return;
	}
	$('#btn-export-pdf').prop('disabled', true);
	buscarTodosParaExporte(function (res) {
		var linhas = (res && res.linhas) || [];
		if (!linhas.length) {
			$('#btn-export-pdf').prop('disabled', false);
			Swal.fire('Aviso', 'Nenhum registro para exportar.', 'warning');
			return;
		}

		var wrapper = document.createElement('div');
		wrapper.className = 'relatorio-pdf-export';
		wrapper.style.background = '#fff';
		wrapper.style.color = '#000';
		wrapper.style.padding = '12px';
		wrapper.style.width = '1100px';

		var stylePdf = document.createElement('style');
		stylePdf.textContent =
			'.relatorio-pdf-export, .relatorio-pdf-export * {' +
			'color: #000 !important; background: #fff !important; background-color: #fff !important;' +
			'border-color: #ccc !important; box-shadow: none !important;' +
			'}' +
			'.relatorio-pdf-export table { width: 100%; border-collapse: collapse; font-size: 11px; }' +
			'.relatorio-pdf-export th, .relatorio-pdf-export td { border: 1px solid #ccc; padding: 4px 6px; }' +
			'.relatorio-pdf-export thead th { background: #f5f5f5 !important; }';
		wrapper.appendChild(stylePdf);
		var tempContent = document.createElement('div');
		tempContent.innerHTML = montarHtmlPdf(res);
		while (tempContent.firstChild) {
			wrapper.appendChild(tempContent.firstChild);
		}
		aplicarCoresClarasPdf(wrapper);

		var nome =
			(inadAbaAtual === 'abandono' ? 'abandono' : 'inadimplentes') +
			'_' +
			new Date().toISOString().slice(0, 10) +
			'.pdf';
		var opt = {
			margin: [8, 8, 8, 8],
			filename: nome,
			image: { type: 'jpeg', quality: 0.98 },
			html2canvas: {
				scale: 2,
				useCORS: true,
				backgroundColor: '#ffffff',
			},
			jsPDF: { unit: 'mm', format: 'a4', orientation: 'landscape' },
			pagebreak: { mode: ['css', 'legacy'], avoid: ['tr'] },
		};

		document.body.appendChild(wrapper);
		html2pdf()
			.set(opt)
			.from(wrapper)
			.save()
			.then(function () {
				if (wrapper.parentNode) {
					document.body.removeChild(wrapper);
				}
				$('#btn-export-pdf').prop('disabled', false);
			})
			.catch(function () {
				if (wrapper.parentNode) {
					document.body.removeChild(wrapper);
				}
				$('#btn-export-pdf').prop('disabled', false);
				Swal.fire('Erro', 'Falha ao gerar PDF.', 'error');
			});
	});
}

$(document).ready(function () {
	$('#tabs-inadimplentes button[data-bs-toggle="tab"]').on('shown.bs.tab', function () {
		inadAbaAtual = $(this).data('aba') || 'inadimplentes';
		inadPagina = 1;
		listar();
	});

	$('#btn-filtrar-inad, #btn-filtrar-abd').on('click', function () {
		inadPagina = 1;
		listar();
	});

	$('.btn-limpar-inad').on('click', function () {
		$('#form-filtros-inad')[0].reset();
		$('#inad-curso').val('');
		inadPagina = 1;
		listar();
	});

	$('.btn-limpar-abd').on('click', function () {
		$('#form-filtros-abandono')[0].reset();
		$('#abd-curso').val('');
		$('#abd-meses').val(3);
		inadPagina = 1;
		listar();
	});

	$('#form-filtros-inad, #form-filtros-abandono').on('keydown', 'input', function (e) {
		if (e.key === 'Enter') {
			e.preventDefault();
			inadPagina = 1;
			listar();
		}
	});

	$('#btn-export-csv').on('click', exportarCsv);
	$('#btn-export-pdf').on('click', exportarPdf);

	$(document).on('click', '.btn-cancelar-contrato', function () {
		var id = parseInt($(this).data('id'), 10);
		if (id > 0 && typeof cancelar_contrato === 'function') {
			cancelar_contrato(id);
		}
	});

	$(document).on('click', '.btn-regularizar-financeiro', function () {
		var id = parseInt($(this).data('id'), 10);
		if (id > 0 && typeof regularizar_financeiro === 'function') {
			regularizar_financeiro(id);
		}
	});

	window.carregarInadimplentes = function () {
		listar();
	};

	listar();
});
