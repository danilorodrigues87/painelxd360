
var _relatorioPagina = 1;

function coletarDadosRelatorio(page) {
 var data = { page: page || 1 };
 var $form = $('#formBusca');
 if ($form.length) {
  $form.serializeArray().forEach(function (item) {
   data[item.name] = item.value;
  });
 }
 return data;
}

function irPagina(page) {
 listar(null, page || 1);
}

// LISTAGEM POR BUSCA
$(document).on("submit", "#formBusca", function(event) {
    event.preventDefault();
    listar(null, 1);
});

// CHAMA A FUNÇÃO LISTAR AO CARREGAR A PAGINA
$(document).ready(function(){
 listar(null,1);
 prepararImpressaoRelatorio();
})

/** Oculta botões/filtros na impressão do navegador (fallback além do CSS). */
function prepararImpressaoRelatorio() {
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
        document.querySelectorAll(
            '#filtragem, #relatorio-filtragem, .relatorio-acoes, #listar .btn-group, #listar button, #pagination'
        ).forEach(esconder);
    });

    window.addEventListener('afterprint', restaurar);
}

// FUNÇÃO LISTAR CONTEUDOS DA PAGINA
function listar(filtro, page) {
 page = page || 1;
 _relatorioPagina = page;

 $.ajax({
    url: url_base+listagem,
    method: "post",
    data: coletarDadosRelatorio(page),
    dataType: "json",
    success: function(result){
        if (!result) return;
        if (result.filtragem !== undefined) {
            $('#filtragem').html(result.filtragem);
        }
        if (result.itens !== undefined) {
            $('#listar').html(result.itens);
        }
        $('#pagination').html(result.pagination || '');
    },
    error: function() {
        $('#listar').html('<div class="alert alert-danger">Erro ao carregar relatório.</div>');
    }
 });
}

/** Força fundo branco e texto preto no clone (ignora tema escuro do painel). */
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

/** Clone pronto para PDF: fundo branco, sem botões, cabeçalho/rodapé visíveis. */
function prepararRelatorioParaImpressao(node) {
    var clone = node.cloneNode(true);

    clone.querySelectorAll('.relatorio-acoes, .no-print, .d-print-none, .btn-group, button, .btn, script').forEach(function (el) {
        el.remove();
    });

    clone.querySelectorAll('.relatorio-cabecalho .d-flex').forEach(function (el) {
        el.style.display = 'flex';
    });

    aplicarCoresClarasPdf(clone);

    return clone;
}

function gerarPdf() {
    var conteudoCompleto = document.querySelector("#listar .relatorio-financeiro-impressao")
        || document.querySelector("#listar");
    if (!conteudoCompleto) return;

    var conteudoClone = prepararRelatorioParaImpressao(conteudoCompleto);

    var wrapper = document.createElement("div");
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
        filename: 'relatorio-financeiro.pdf',
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: {
            scale: 2,
            useCORS: true,
            backgroundColor: '#ffffff'
        },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'landscape' }
    };

    html2pdf().from(wrapper).set(opt).save();
}
