
$(document).on('submit', '#formBusca', function (event) {
	event.preventDefault();
	listarPresenca();
});

$(document).ready(function () {
	listarPresenca();
	prepararImpressaoRelatorioPresenca();
});

function coletarDadosRelatorioPresenca() {
	var data = {};
	var $form = $('#formBusca');
	if ($form.length) {
		$form.serializeArray().forEach(function (item) {
			data[item.name] = item.value;
		});
	}
	return data;
}

function listarPresenca() {
	$.ajax({
		url: url_base + listagem,
		method: 'post',
		data: coletarDadosRelatorioPresenca(),
		dataType: 'json',
		success: function (result) {
			if (!result) return;
			if (result.filtragem !== undefined) {
				$('#filtragem').html(result.filtragem);
			}
			if (result.itens !== undefined) {
				$('#listar').html(result.itens).removeClass('text-muted small');
			}
		},
		error: function (xhr) {
			var msg = 'Erro ao carregar relatório.';
			if (xhr && xhr.responseText) {
				console.error('listarPresenca:', xhr.status, xhr.responseText.substring(0, 500));
			}
			$('#listar').html('<div class="alert alert-danger">' + msg + '</div>').removeClass('text-muted small');
		},
	});
}

function prepararImpressaoRelatorioPresenca() {
	var ocultos = [];

	function esconder(el) {
		if (!el || el.getAttribute('data-print-hidden') === '1') return;
		ocultos.push({ el: el, display: el.style.display, visibility: el.style.visibility });
		el.setAttribute('data-print-hidden', '1');
		el.style.display = 'none';
		el.style.visibility = 'hidden';
	}

	function restaurar() {
		ocultos.forEach(function (item) {
			item.el.style.display = item.display;
			item.el.style.visibility = item.visibility;
			item.el.removeAttribute('data-print-hidden');
		});
		ocultos = [];
	}

	window.addEventListener('beforeprint', function () {
		document.querySelectorAll('#filtragem, #relatorio-filtragem, #listar button, #listar .btn').forEach(esconder);
	});

	window.addEventListener('afterprint', restaurar);
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
	root.querySelectorAll('.table-striped tbody tr:nth-child(odd) td, .table-striped tbody tr:nth-child(odd) th').forEach(function (el) {
		el.style.setProperty('background-color', '#f5f5f5', 'important');
		el.style.setProperty('color', '#000', 'important');
	});
}

function prepararRelatorioPresencaParaPdf(node) {
	var clone = node.cloneNode(true);
	clone.querySelectorAll('.no-print, .d-print-none, .btn-group, button, .btn, script').forEach(function (el) {
		el.remove();
	});
	clone.querySelectorAll('.relatorio-cabecalho .d-flex').forEach(function (el) {
		el.style.display = 'flex';
	});
	aplicarCoresClarasPdf(clone);
	return clone;
}

function gerarPdfPresenca() {
	var conteudoCompleto =
		document.querySelector('#listar .relatorio-financeiro-impressao') || document.querySelector('#listar');
	if (!conteudoCompleto) return;

	var conteudoClone = prepararRelatorioPresencaParaPdf(conteudoCompleto);
	var wrapper = document.createElement('div');
	wrapper.className = 'relatorio-pdf-export';
	wrapper.style.background = '#fff';
	wrapper.style.color = '#000';
	wrapper.style.padding = '8px';

	var stylePdf = document.createElement('style');
	stylePdf.textContent =
		'.relatorio-pdf-export, .relatorio-pdf-export * {' +
		'color: #000 !important; background: #fff !important; background-color: #fff !important;' +
		'border-color: #ccc !important; box-shadow: none !important;' +
		'}' +
		'.relatorio-pdf-export .table-striped > tbody > tr:nth-of-type(odd) > * {' +
		'background-color: #f5f5f5 !important; color: #000 !important;' +
		'}';
	wrapper.appendChild(stylePdf);
	wrapper.appendChild(conteudoClone);
	aplicarCoresClarasPdf(wrapper);

	var opt = {
		margin: [8, 8, 8, 8],
		filename: 'relatorio-presenca.pdf',
		image: { type: 'jpeg', quality: 0.98 },
		html2canvas: {
			scale: 2,
			useCORS: true,
			backgroundColor: '#ffffff',
		},
		jsPDF: { unit: 'mm', format: 'a4', orientation: 'landscape' },
	};

	html2pdf().from(wrapper).set(opt).save();
}
