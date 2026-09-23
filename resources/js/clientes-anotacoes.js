function anotacoesAluno(id){
	$.post(url_base + anotacoes, { id: id, acao: 'form' }, function(res){
		if(!res || !res.success){
			Swal.fire('Atenção', (res && res.message) || 'Não foi possível abrir anotações.', 'warning');
			return;
		}
		$('#listar-notas').html(res.html || '');
		$('#anotacoesModal').modal('show');
	}, 'json').fail(function(){
		Swal.fire('Erro', 'Falha ao carregar anotações.', 'error');
	});
}

$(document).on('click', '#btn-salvar-aluno-obs', function(){
	const alunoId = $(this).data('aluno');
	const texto = ($('#aluno-obs-texto').val() || '').trim();
	if(!texto){
		Swal.fire('Atenção', 'Escreva a observação.', 'warning');
		return;
	}
	const $btn = $(this).prop('disabled', true);
	$.post(url_base + anotacoes, {
		acao: 'salvar',
		aluno_id: alunoId,
		id: alunoId,
		observacao: texto
	}, function(res){
		$btn.prop('disabled', false);
		if(!res || !res.success){
			Swal.fire('Erro', (res && res.message) || 'Não foi possível salvar.', 'error');
			return;
		}
		$('#aluno-obs-texto').val('');
		$('#aluno-obs-lista').html(res.html || '');
		$('#aluno-obs-alert').html('<div class="alert alert-success py-2">Observação salva.</div>');
		setTimeout(function(){ $('#aluno-obs-alert').empty(); }, 2500);
	}, 'json').fail(function(){
		$btn.prop('disabled', false);
		Swal.fire('Erro', 'Falha ao salvar observação.', 'error');
	});
});
