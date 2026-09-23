
// CHAMA A FUNÇÃO LISTAR AO CARREGAR A PAGINA
$(document).ready(function(){
 listar(null,1);
});

var _listarReq = null;
var _ultimoFiltroLista = null;
var _buscaFiltroTimer = null;

function coletarFiltrosBarra() {
  var data = {};
  var $bar = $('#barra-filtros-lista');
  if (!$bar.length) {
    return data;
  }
  $bar.find('input[name], select[name]').each(function () {
    var n = $(this).attr('name');
    if (!n) return;
    data[n] = $(this).val();
  });
  return data;
}

function irPagina(page) {
  listar(_ultimoFiltroLista, page || 1);
}

// FUNÇÃO LISTAR CONTEUDOS DA PAGINA
function listar(filtro=null, page=1) {
  if (typeof listagem === 'undefined' || !listagem) {
    return;
  }

  if (arguments.length >= 1) {
    _ultimoFiltroLista = filtro;
  }

  if (_listarReq && typeof _listarReq.abort === 'function') {
    try { _listarReq.abort(); } catch (e) {}
  }

  if ($('#listar').length) {
    var cur = ($('#listar').html() || '').trim();
    if (!cur || cur.indexOf('Carregando') >= 0 || cur.indexOf('table') < 0) {
      $('#listar').html('<div class="p-4 text-center text-muted">Carregando...</div>');
    }
  }

  var data = Object.assign({ page: page || 1 }, coletarFiltrosBarra());
  if (_ultimoFiltroLista !== null && _ultimoFiltroLista !== undefined && _ultimoFiltroLista !== '') {
    data.filtro = _ultimoFiltroLista;
  } else if (data.filtro === undefined) {
    data.filtro = null;
  }

  _listarReq = $.ajax({
    url: url_base + listagem,
    method: 'post',
    data: data,
    dataType: 'json',
    timeout: 30000,
    success: function(result) {
      if (!result || typeof result.itens === 'undefined') {
        $('#listar').html('<div class="alert alert-warning m-3">Não foi possível carregar a lista. Tente novamente.</div>');
        return;
      }
      $('#listar').html(result.itens);
      $('#pagination').html(result.pagination || '');

      $('#fil-todos').removeClass('active');
      $('#fil-diretor').removeClass('active');
      $('#fil-secretario').removeClass('active');
      $('#fil-financeiro').removeClass('active');
      $('#fil-parceiro').removeClass('active');
      $('#fil-cliente').removeClass('active');
      $('#fil-comercial').removeClass('active');
      $('#fil-inativo').removeClass('active');

      if (filtro == null || filtro === '' || filtro === '0' || filtro === 0) {
        $('#fil-todos').addClass('active');
      } else if (filtro == 'Diretor') {
        $('#fil-diretor').addClass('active');
      } else if (filtro == 'Secretario') {
        $('#fil-secretario').addClass('active');
      } else if (filtro == 'Financeiro') {
        $('#fil-financeiro').addClass('active');
      } else if (filtro == 'Comercial') {
        $('#fil-comercial').addClass('active');
      } else if (filtro == 'inativo') {
        $('#fil-inativo').addClass('active');
      } else {
        $('#fil-todos').addClass('active');
      }
    },
    error: function(xhr, status) {
      if (status === 'abort') {
        return;
      }
      var msg = 'Falha ao carregar a lista.';
      if (status === 'timeout') {
        msg = 'A lista demorou demais. Tente de novo.';
      } else if (xhr && xhr.responseText && xhr.responseText.indexOf('crashed') !== -1) {
        msg = 'Tabela do banco corrompida. No phpMyAdmin rode REPAIR TABLE na tabela indicada no console.';
      }
      console.log('listar error:', status, xhr && xhr.status, xhr && xhr.responseText ? xhr.responseText.substring(0, 400) : '');
      $('#listar').html('<div class="alert alert-danger m-3">' + msg + ' <button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="listar(null,1)">Tentar novamente</button></div>');
    }
  });
}

$(document).on('change', '#barra-filtros-lista select[name]', function () {
  listar(null, 1);
});
$(document).on('input', '#barra-filtros-lista input[name="busca"]', function () {
  clearTimeout(_buscaFiltroTimer);
  _buscaFiltroTimer = setTimeout(function () {
    listar(null, 1);
  }, 350);
});
$(document).on('keydown', '#barra-filtros-lista input[name="busca"]', function (e) {
  if (e.key === 'Enter') {
    e.preventDefault();
    clearTimeout(_buscaFiltroTimer);
    listar(null, 1);
  }
});
$(document).on('click', '#barra-filtros-lista .btn-aplicar-filtros', function (e) {
  e.preventDefault();
  listar(null, 1);
});
$(document).on('click', '#barra-filtros-lista .btn-limpar-filtros', function (e) {
  e.preventDefault();
  var $bar = $('#barra-filtros-lista');
  $bar.find('input[name="busca"]').val('');
  $bar.find('select').each(function () {
    $(this).val($(this).find('option:first').val());
  });
  listar(null, 1);
});



// FUNÇÃO DE EXCLUSÃO
function excluir(id) {

    Swal.fire({
      title: "Você tem certeza que quer excluir esse item?",
      text: "Isso não poderá ser recuperado!",
      icon: "warning",
      showCancelButton: true,
      confirmButtonColor: "#3085d6",
      cancelButtonColor: "#d33",
      confirmButtonText: "Sim, excluir!"
  }).then((result) => {

      if (result.isConfirmed) {

       $.ajax({
        url: url_base+deletar,
        method: "post",
        data: {id},
        dataType: "json",
        success: function(result){
            if(result){
                result = "Item excluido com sucesso!"
                let status = "success"
            } else {
                let status = "error"
            }
            Swal.fire({
              title: "Excluido!",
              text: result,
              icon: status
          });
            listar(null,1);
        },

    })

   }
});

}


// cancelar_contrato — ver encargos-cancelamento.js (matrículas)

function encerrar_contrato(id) {
  if (typeof encerrar === 'undefined' || !encerrar) {
    return;
  }
  Swal.fire({
    title: "Encerrar esta matrícula?",
    text: "Marca o contrato como encerrado (conclusão). Só é permitido se não houver parcelas em aberto — use Cancelar contrato para regularizar débitos antes.",
    icon: "question",
    showCancelButton: true,
    confirmButtonText: "Sim, encerrar"
  }).then(function (result) {
    if (!result.isConfirmed) return;
    $.ajax({
      url: url_base + encerrar,
      method: "post",
      data: { id: id },
      dataType: "json",
      success: function (res) {
        var ok = res && res.ok;
        Swal.fire({
          title: ok ? "Encerrado!" : "Atenção",
          text: (res && res.message) ? res.message : (ok ? "OK" : "Erro"),
          icon: ok ? "success" : "error"
        });
        listar(null, 1);
      },
      error: function () {
        Swal.fire({ title: "Erro", text: "Falha ao encerrar.", icon: "error" });
      }
    });
  });
}


// FUNÇÃO QUE EXECUTA UM CREATE OU UPDATE DE DADOS COM ENVIO DE IMAGEM
$(document).on("submit", "#formEmpresa", function(event) {
    event.preventDefault(); // Evita o envio do formulário de forma tradicional

    // Criando um objeto FormData para enviar dados, incluindo arquivos
    var formData = new FormData(this);

    $.ajax({
       url: url_base+edicao,
       type: "POST",
       data: formData, // Enviando os dados como FormData
       contentType: false, // Impede que o jQuery defina automaticamente o Content-Type (é necessário para upload de arquivos)
       processData: false, // Impede que o jQuery processe os dados
       dataType: "json",
       success: function(response) {
        console.log(response)
            // Processar a resposta
            // Verifica se o JSON contém o campo 'erro'
            if (response.erro) {
                // Exibe o erro se existir
                $("#response").html('<div class="alert alert-danger">' + response.erro + '</div>');
            } else {
                // Fecha o modal e exibe a mensagem de sucesso
                $('#btn-fechar').click();
                Swal.fire({
                    title: "Muito bem!",
                    text: "Os dados foram atualizados com sucesso.",
                    icon: "success"
                });
                // Chama a função listar com o filtro retornado
                listar(response.filtro, 1);
            }
        },
        error: function(xhr, status, error) {
            // Lida com erros de requisição
            $("#response").html('<div class="alert alert-danger">Ocorreu um erro ao processar a solicitação.</div>');
            console.log("Erro:", error);
        }
    });
});

$(document).on("submit", "#form", function(event) {
    event.preventDefault();

    // === ADICIONE ISSO AQUI ===
    // Sincroniza o conteúdo do CKEditor com o textarea original
    if (typeof meuEditor !== 'undefined') {
        document.querySelector('#editor').value = meuEditor.getData();
    }
    // ==========================

    // Agora o FormData vai capturar o texto já atualizado com as tags HTML
    var formData = new FormData(this);

    $.ajax({
        url: url_base + edicao,
        type: "POST",
        dataType: "json",
        data: formData,
        processData: false,
        contentType: false,
        success: function(response) {
     
            if (response.erro) {
                $("#response").html('<div class="alert alert-danger">' + response.erro + '</div>');
            } else {
                $('#btn-fechar').click();
                Swal.fire({
                    title: "Muito bem!",
                    text: "Os dados foram atualizados com sucesso.",
                    icon: "success"
                });
                listar(response.filtro, 1);
            }
        },
        error: function(xhr, status, error) {
            $("#response").html('<div class="alert alert-danger">Ocorreu um erro ao processar a solicitação.</div>');
            console.log("Erro:", error);
        }
    });
});


// FUNÇÃO QUE CARREGA A MODAL E OS DADOS
function showFormModal() {
	var el = document.getElementById('formModal');
	if (!el) return;
	try {
		if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
			bootstrap.Modal.getOrCreateInstance(el).show();
			return;
		}
	} catch (e) {}
	if (typeof $ !== 'undefined' && $.fn && typeof $(el).modal === 'function') {
		$(el).modal('show');
		return;
	}
	$(el).addClass('show').css({display: 'block'});
	$('body').addClass('modal-open');
}

function list_itens(id, funcao) {

   $.ajax({
    url: url_base+formulario,
    method: "post",
    data: { id: id || '', funcao: funcao },
    dataType: "json",
    success: function(result) {

      if (result && typeof result === 'object' && result.form) {
        $('#listar-dados').html(result.form);
        if (result.cidade) {
          selectEstado(result.cidade);
        }
      } else if (typeof result === 'string') {
        $('#listar-dados').html(result);
      } else if (result && result.erro) {
        Swal.fire('Erro', result.erro, 'error');
        return;
      } else {
        Swal.fire('Erro', 'Resposta inválida ao abrir o formulário.', 'error');
        return;
      }

      if (typeof formulario !== 'undefined' && formulario === 'painel/matriculas/form') {
        if (typeof selectAluno === 'function') {
          selectAluno();
        }
      }

      showFormModal();
    },
    error: function(xhr) {
      var msg = 'Falha ao abrir o formulário.';
      if (xhr && xhr.responseText) {
        console.log('list_itens error:', xhr.status, xhr.responseText.substring(0, 500));
      }
      if (typeof Swal !== 'undefined') {
        Swal.fire('Erro', msg, 'error');
      } else {
        alert(msg);
      }
    }
  });

}


// FUNÇÃO QUE CARREGA A MODAL E OS DADOS
function darBaixa(id) {

   $.ajax({
    url: url_base+formBaixa,
    method: "post",
    data: {id},
    dataType: "text",
    success: function(result) {

        $('#formModal').modal('hide');
        $('#body_dar_baixa').html(result);
        $('#modalDarBaixa').modal('show');
        if (typeof atualizarTotalEncargosPagamento === 'function') {
            atualizarTotalEncargosPagamento();
        }

    },

});

}




function selectAluno(id_aluno) {
    // Verifica se id_aluno é numérico e diferente de 0
    if (!isNaN(id_aluno) && id_aluno != 0) {
        // Faz a requisição AJAX
        $.ajax({
            type: 'POST',
            url: url_base + buscaResponsavel,
            data: { id_aluno: id_aluno },  // Passa o id do aluno
            dataType: "json",
            success: function(result) {
                console.log("Resultado da requisição AJAX:", result);

                // Verifica se o resultado é válido
                if (result) {
                    document.getElementById("nome_responsavel").value = result.nome;
                    document.getElementById("id_responsavel").value = result.id;
                }
            },
            error: function(xhr, status, error) {
                console.error("Erro na requisição AJAX:", status, error);
            }
        });
    } else {
        console.log("ID do aluno inválido ou igual a 0.");
    }
}

