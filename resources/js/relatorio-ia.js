var _iaPagina = 1;

function irPagina(page) {
 carregarUsoIa(page || 1);
}

function coletarFiltrosIa(page) {
 var data = { page: page || 1 };
 $('#formFiltroIa').serializeArray().forEach(function (item) {
  data[item.name] = item.value;
 });
 return data;
}

function atualizarResumo(resumo) {
 if (!resumo) return;
 $('#resumo-calls').text(Number(resumo.total_calls || 0).toLocaleString('pt-BR'));
 $('#resumo-tokens').text(Number(resumo.total_tokens || 0).toLocaleString('pt-BR'));
 $('#resumo-ok').text(Number(resumo.success_calls || 0).toLocaleString('pt-BR'));
}

function carregarUsoIa(page) {
 page = page || 1;
 _iaPagina = page;

 $.ajax({
  url: url_base + listagemIa,
  method: 'post',
  data: coletarFiltrosIa(page),
  dataType: 'json',
  success: function (result) {
   if (!result || result.success === false) {
    $('#listar-ia').html('<div class="alert alert-warning">' + (result && result.message ? result.message : 'Erro ao carregar.') + '</div>');
    return;
   }
   $('#listar-ia').html(result.itens || '');
   $('#pagination').html(result.pagination || '');
   atualizarResumo(result.resumo);
  },
  error: function () {
   $('#listar-ia').html('<div class="alert alert-danger">Erro ao carregar relatório.</div>');
  }
 });
}

$(document).ready(function () {
 var hoje = new Date();
 var primeiro = new Date(hoje.getFullYear(), hoje.getMonth(), 1);
 $('#filtro-de').val(primeiro.toISOString().slice(0, 10));
 $('#filtro-ate').val(hoje.toISOString().slice(0, 10));
 carregarUsoIa(1);
});

$(document).on('submit', '#formFiltroIa', function (e) {
 e.preventDefault();
 carregarUsoIa(1);
});
