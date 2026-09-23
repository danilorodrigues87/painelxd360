$(document).ready(function(){
	listar(null, 1);
});

function listar(filtro, page) {
	page = page || 1;
	$.ajax({
		url: url_base + listagem,
		method: 'post',
		data: { filtro, page },
		dataType: 'json',
		success: function(result){
			$('#listar').html(result.itens);
			$('#pagination').html(result.pagination);
		}
	});
}

function labForm(id, funcao) {
	$.ajax({
		url: url_base + formulario,
		method: 'post',
		data: { id, funcao },
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

function labExcluir(id) {
	Swal.fire({
		title: 'Excluir laboratório?',
		text: 'Horários vinculados podem ficar sem laboratório.',
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
				listar(null, 1);
			}
		});
	});
}

$(document).on('submit', '#formLab', function(event){
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
				if(response.novo && response.id){
					Swal.fire({
						title: 'Laboratório salvo!',
						text: 'Agora cadastre os horários deste laboratório para poder agendar alunos.',
						icon: 'success',
						showCancelButton: true,
						confirmButtonText: 'Criar horários',
						cancelButtonText: 'Depois'
					}).then(function(r){
						if(r.isConfirmed){
							window.location = url_base + 'painel/agenda/horarios?lab=' + response.id;
						} else {
							listar(null, 1);
						}
					});
				} else {
					Swal.fire({ title: 'Salvo!', icon: 'success' });
					listar(null, 1);
				}
			}
		}
	});
});
