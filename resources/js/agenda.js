// CHAMA A FUNÇÃO LISTAR AO CARREGAR A PAGINA
function diaAtualAgenda() {
 var d = new Date().getDay();
 return d === 0 ? 1 : d;
}

var _filtroDiaAtual = null;
var _filtroLabAtual = 0;
var _paginaAtual = 1;

function getFiltroDiaAtivo() {
 if (_filtroDiaAtual !== null && _filtroDiaAtual !== undefined) {
  return _filtroDiaAtual;
 }
 for (var i = 1; i <= 6; i++) {
  if ($('#fil-' + i).hasClass('active')) {
   return i;
  }
 }
 return diaAtualAgenda();
}

function getFiltroLabAtivo() {
 var el = document.getElementById('filtro_laboratorio');
 if (el) {
  return parseInt(el.value, 10) || 0;
 }
 return _filtroLabAtual || 0;
}

function recarregarLista() {
 listar(_filtroDiaAtual, _paginaAtual);
}

function irPagina(page) {
 listar(getFiltroDiaAtivo(), page || 1);
}

$(document).ready(function(){
 listar(diaAtualAgenda(), 1);
});

// FUNÇÃO LISTAR CONTEUDOS DA PAGINA
function listar(filtro, page) {
 page = page || 1;
 if (filtro !== null && filtro !== undefined && filtro !== '') {
  _filtroDiaAtual = parseInt(filtro, 10);
 }
 _filtroLabAtual = getFiltroLabAtivo();
 _paginaAtual = page;

 $.ajax({
    url: url_base+listaAgenda,
    method: "post",
    data: {
     filtro: _filtroDiaAtual,
     page: page,
     laboratorio_id: _filtroLabAtual
    },
    dataType: "json",
    success: function(result){
     if (!result || typeof result.itens === 'undefined') {
      $('#listar').html('<div class="alert alert-warning m-3">Não foi possível carregar a lista.</div>');
      return;
     }

     $('#listar').html(result.itens);
     $('#pagination').html(result.pagination || '');

     if (result.laboratorio_id !== undefined && $('#filtro_laboratorio').length) {
      $('#filtro_laboratorio').val(String(result.laboratorio_id || 0));
      _filtroLabAtual = parseInt(result.laboratorio_id, 10) || 0;
     }

     $('#fil-1').removeClass('active');
     $('#fil-2').removeClass('active');
     $('#fil-3').removeClass('active');
     $('#fil-4').removeClass('active');
     $('#fil-5').removeClass('active');
     $('#fil-6').removeClass('active');

     var dia = parseInt(result.filtro, 10) || _filtroDiaAtual;
     _filtroDiaAtual = dia;
     if (dia >= 1 && dia <= 6) {
      $('#fil-' + dia).addClass('active');
     }
    },
    error: function(xhr, status, err) {
     $('#listar').html('<div class="alert alert-danger m-3">Erro ao carregar agendamentos. Tente novamente.</div>');
    }
 });
}

// FUNÇÃO QUE CARREGA A MODAL E OS DADOS
var _ultimoHorarioVer = null;

function ver_info(id, funcao) {
 _ultimoHorarioVer = id;

   $.ajax({
    url: url_base+formulario,
    method: "post",
    data: { id, funcao },
    dataType: "text",
    success: function(result) {

  	$('#listar-dados').html(result);
	 $('#formModal').modal('show');

},

});

}

// FUNÇÃO DE EXCLUSÃO (remove slot do plano semanal)
function excluir(id) {

    $('#formModal').modal('hide');
    $('#formAgendamento').modal('hide');
    Swal.fire({
      title: "Remover este horário do plano do aluno?",
      text: "O aluno deixará de frequentar este horário.",
      icon: "warning",
      showCancelButton: true,
      confirmButtonColor: "#3085d6",
      cancelButtonColor: "#d33",
      confirmButtonText: "Sim, remover!"
  }).then((result) => {

      if (result.isConfirmed) {
   
       $.ajax({
        url: url_base+deletar,
        method: "post",
        data: {id},
        dataType: "text",
        success: function(result){
            var ok = String(result).trim() === '1';

            if(ok){
                Swal.fire({
              title: "Removido!",
              text: "Horário removido do plano.",
              icon: "success"
          });
            } else {
                Swal.fire({
              title: "Erro",
              text: "Não foi possível remover.",
              icon: "error"
          });
            }

            recarregarLista();
            if (ok && _ultimoHorarioVer) {
             ver_info(_ultimoHorarioVer, 'editar');
            }

        },
        error: function() {
         Swal.fire({ title: "Erro", text: "Falha na comunicação.", icon: "error" });
        }

    
    })

   }
});

}

function editar(id_agenda, funcao) {

   $.ajax({
    url: url_base+edicao,
    method: "post",
    data: {
     id_agenda: id_agenda || '',
     funcao: funcao,
     dia_semana: getFiltroDiaAtivo()
    },
    dataType: "json",
    success: function(result) {

    $('#body_agendamento').html(result.form);
    $('#formAgendamento').modal('show');
    select_dia_semana(result.id_horario || 0);

},

});

}

function infoAlunoPlano() {
  var id_aluno = document.getElementById("id_aluno").value;
  if(!id_aluno || id_aluno == 0){
    $('#info-plano').addClass('d-none');
    return;
  }

  $.ajax({
    url: url_base + 'painel/agenda/laboratorio/aluno',
    method: 'post',
    data: { id_aluno },
    dataType: 'json',
    success: function(info){
      var limite = info.aulas_semanais || 0;
      var atual = info.planos_ativos || 0;
      var txt = 'Aulas/semana na matrícula: <strong>'+limite+'</strong> · No plano: <strong>'+atual+'</strong>';
      if(limite > 0 && atual >= limite){
        txt += ' <span class="text-danger">(limite atingido)</span>';
      }
      $('#info-plano').html(txt).removeClass('d-none');

      if(info.id_trilha){
        $('#id_trilha').val(info.id_trilha);
      }
    }
  });
}

function select_dia_semana(id_horario) {
  id_horario = id_horario || 0;
  var dia_semana = document.getElementById("dia_semana").value;
  var laboratorio_id = document.getElementById("laboratorio_id") ? document.getElementById("laboratorio_id").value : 0;
  
  $.ajax({
   type: 'POST',
   url: url_base+'painel/agenda/laboratorio/horarios',
   data: {dia_semana, id_horario: id_horario, laboratorio_id},
   success: function(e) {
    document.getElementById("horarios").innerHTML = e;
  }
})
  
}

// FUNÇÃO QUE EXECUTA UM CREATE OU UPDATE DE DADOS
$(document).on("submit", "#form", function(event) {
    event.preventDefault();

    $.ajax({
        url: url_base + salvar,
        type: "POST",
        dataType: "text",
        data: $(this).serialize(),
        success: function(response) {

          if (response.trim() !== "salvo") {
                $("#response").html('<div class="alert alert-danger">' + response + '</div>');

            } else {

                Swal.fire({
                    title: "Ótimo!",
                    text: "Horário adicionado ao plano semanal.",
                    icon: "success"
               });
                $('#btn-fechar-ag').click();

                recarregarLista();

            }

        },
        error: function(xhr, status, error) {
            $("#response").html('<div class="alert alert-danger">Ocorreu um erro ao processar a solicitação.</div>');
            console.log("Erro:", error);
        }
    });
});

// ——— Reposição / avulso ———
function abrirAvulso() {
  $.ajax({
    url: url_base + 'painel/agenda/laboratorio/avulso/form',
    method: 'post',
    dataType: 'text',
    success: function (html) {
      $('#listar-dados').html(html);
      $('#formModal').modal('show');
      setTimeout(function () {
        carregarHorariosAvulso();
        listarAvulsosHoje();
      }, 200);
    }
  });
}

function infoAlunoAvulso() {
  var id_aluno = $('#av_id_aluno').val();
  if (!id_aluno || id_aluno == 0) return;
  $.ajax({
    url: url_base + 'painel/agenda/laboratorio/aluno',
    method: 'post',
    data: { id_aluno },
    dataType: 'json',
    success: function (info) {
      if (info.id_trilha) $('#av_id_trilha').val(info.id_trilha);
    }
  });
}

function carregarHorariosAvulso() {
  var data = $('#av_data').val();
  if (!data) return;
  var d = new Date(data + 'T12:00:00');
  var dia = d.getDay();
  if (dia === 0) dia = 1;
  $('#av_dia_semana').val(dia);
  var laboratorio_id = $('#av_laboratorio_id').val() || 0;
  $.ajax({
    type: 'POST',
    url: url_base + 'painel/agenda/laboratorio/horarios',
    data: { dia_semana: dia, laboratorio_id: laboratorio_id },
    success: function (e) {
      $('#av_horarios').html(e);
    }
  });
}

function listarAvulsosHoje() {
  var data = $('#av_data').val() || '';
  if (!$('#lista-avulsos-wrap').length) {
    $('#form-avulso .modal-body').append('<hr><h6 class="mt-2">Repos nesta data</h6><div id="lista-avulsos-wrap"></div>');
  }
  $.ajax({
    url: url_base + 'painel/agenda/laboratorio/avulso/listar',
    method: 'post',
    data: { data },
    dataType: 'json',
    success: function (res) {
      $('#lista-avulsos-wrap').html(res.html || '');
    }
  });
}

$(document).on('change', '#av_data', function () {
  carregarHorariosAvulso();
  listarAvulsosHoje();
});

$(document).on('submit', '#form-avulso', function (event) {
  event.preventDefault();
  $.ajax({
    url: url_base + 'painel/agenda/laboratorio/avulso/salvar',
    type: 'POST',
    dataType: 'text',
    data: $(this).serialize(),
    success: function (response) {
      if (response.trim() !== 'salvo') {
        $('#response-avulso').html('<div class="alert alert-danger">' + response + '</div>');
      } else {
        Swal.fire({ title: 'Ótimo!', text: 'Reposição agendada.', icon: 'success' });
        listarAvulsosHoje();
        $('#form-avulso')[0].reset();
        $('#av_data').val(new Date().toISOString().slice(0, 10));
        carregarHorariosAvulso();
      }
    },
    error: function () {
      $('#response-avulso').html('<div class="alert alert-danger">Erro ao salvar.</div>');
    }
  });
});

function excluirAvulso(id) {
  Swal.fire({
    title: 'Remover esta reposição?',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: 'Sim, remover'
  }).then(function (r) {
    if (!r.isConfirmed) return;
    $.ajax({
      url: url_base + 'painel/agenda/laboratorio/avulso/excluir',
      method: 'post',
      data: { id },
      success: function () {
        listarAvulsosHoje();
      }
    });
  });
}
