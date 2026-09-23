var _paginaHorarios = 1;
var _labHorarios = 0;

function getFiltroLabHorarios() {
 var el = document.getElementById('filtro_laboratorio');
 if (el) {
  return parseInt(el.value, 10) || 0;
 }
 return _labHorarios || 0;
}

function irPagina(page) {
 listarHorarios(page || 1);
}

$(document).ready(function(){
 listarHorarios(1);

 var params = new URLSearchParams(window.location.search);
 var lab = params.get('lab');
 if(lab){
  horaForm('', 'novo', lab);
  history.replaceState({}, '', window.location.pathname);
 }
});

function listarHorarios(page) {
 page = page || 1;
 _paginaHorarios = page;
 _labHorarios = getFiltroLabHorarios();

 $.ajax({
  url: url_base + listagem,
  method: 'post',
  data: { page: page, laboratorio_id: _labHorarios },
  dataType: 'json',
  success: function(result){
   if (!result || typeof result.itens === 'undefined') {
    $('#listar').html('<div class="alert alert-warning m-3">Não foi possível carregar a lista.</div>');
    return;
   }
   $('#listar').html(result.itens);
   $('#pagination').html(result.pagination || '');
   if (result.laboratorio_id !== undefined && $('#filtro_laboratorio').length) {
    $('#filtro_laboratorio').val(String(result.laboratorio_id || 0));
    _labHorarios = parseInt(result.laboratorio_id, 10) || 0;
   }
  },
  error: function() {
   $('#listar').html('<div class="alert alert-danger m-3">Erro ao carregar horários.</div>');
  }
 });
}

function horaForm(id, funcao, laboratorio_id) {
 var data = { id, funcao };
 if(laboratorio_id){
  data.laboratorio_id = laboratorio_id;
 } else if (_labHorarios > 0) {
  data.laboratorio_id = _labHorarios;
 }
 $.ajax({
  url: url_base + formulario,
  method: 'post',
  data: data,
  dataType: 'json',
  success: function(result){
   if(result.erro){
    Swal.fire({ title: 'Erro', text: result.erro, icon: 'error' });
    return;
   }
   $('#listar-dados').html(result.form);
   $('#formModal').modal('show');
  }
 });
}

function horaExcluir(id) {
 Swal.fire({
  title: 'Excluir horário?',
  text: 'Alunos agendados neste horário perderão o vínculo.',
  icon: 'warning',
  showCancelButton: true,
  confirmButtonText: 'Sim, excluir'
 }).then(function(result){
  if(!result.isConfirmed) return;
  $.ajax({
   url: url_base + deletar,
   method: 'post',
   data: { id },
   dataType: 'json',
   success: function(ok){
    Swal.fire({ title: ok ? 'Excluído!' : 'Erro', icon: ok ? 'success' : 'error' });
    listarHorarios(_paginaHorarios);
   }
  });
 });
}

$(document).on('submit', '#formHora', function(event){
 event.preventDefault();
 $.ajax({
  url: url_base + edicao,
  type: 'POST',
  data: $(this).serialize(),
  dataType: 'json',
  success: function(response){
   if(response.erro){
    $('#response').html('<div class="alert alert-danger">'+response.erro+'</div>');
   } else {
    $('#formModal').modal('hide');
    Swal.fire({ title: 'Salvo!', icon: 'success' });
    listarHorarios(_paginaHorarios);
   }
  },
  error: function() {
   $('#response').html('<div class="alert alert-danger">Erro ao salvar.</div>');
  }
 });
});
