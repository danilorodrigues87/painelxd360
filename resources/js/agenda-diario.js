var diarioWaPoll = null;
var diarioCampanhaPollId = null;

function dataBrFromIso(iso) {
	if (!iso || !/^\d{4}-\d{2}-\d{2}$/.test(iso)) return iso || '';
	var p = iso.split('-');
	return p[2] + '/' + p[1] + '/' + p[0];
}

function hojeIso() {
	return new Date().toISOString().slice(0, 10);
}

$(document).ready(function () {
	$('#data-diario').val(hojeIso());
	carregarDiario();

	$('#btn-diario-wa-salvar-msg').on('click', function () {
		salvarMensagensDiarioWa();
	});
	$('#btn-diario-wa-lembrete').on('click', function () {
		enviarDiarioWa('lembrete');
	});
	$('#btn-diario-wa-faltas').on('click', function () {
		enviarDiarioWa('faltas');
	});
});

function carregarDiario() {
	var data = $('#data-diario').val();
	var laboratorio_id = $('#lab-diario').val() || 0;

	$.ajax({
		url: url_base + listagem,
		method: 'post',
		data: { data: data, laboratorio_id: laboratorio_id },
		dataType: 'json',
		success: function (result) {
			var labAtual = $('#lab-diario').val();
			$('#listar').html(result.table);
			if (result.labs_options) {
				$('#lab-diario').html(result.labs_options);
				if (labAtual) {
					$('#lab-diario').val(labAtual);
				}
			}
			var dataBr = result.data_br || dataBrFromIso(result.data);
			$('#diario-data-legenda').text(
				result.total
					? result.total + ' aula(s) em ' + dataBr + '. Status padrão: Aguardando — marque Presente quando o aluno chegar.'
					: 'Nenhuma aula em ' + dataBr + '.'
			);
			atualizarUiWhatsapp(result);
		}
	});
}

function atualizarUiWhatsapp(result) {
	var $card = $('#card-diario-whatsapp');
	if (!result.whatsapp_plano) {
		$card.addClass('d-none');
		return;
	}
	$card.removeClass('d-none');
	$('#diario-wa-lembrete').val(result.mensagem_lembrete || '');
	$('#diario-wa-faltas').val(result.mensagem_faltas || '');

	var horarioAtual = $('#diario-wa-horario').val();
	var $selHor = $('#diario-wa-horario');
	$selHor.find('option:not(:first)').remove();
	var horarios = result.horarios_lembrete || [];
	horarios.forEach(function (h) {
		$selHor.append($('<option>', { value: h.id, text: h.label }));
	});
	if (horarioAtual && $selHor.find('option[value="' + horarioAtual + '"]').length) {
		$selHor.val(horarioAtual);
	} else if (horarios.length === 1) {
		$selHor.val(String(horarios[0].id));
	}

	var statusTxt = result.whatsapp_conectado ? 'Conectado' : (result.whatsapp_motivo || 'Desconectado');
	var $badge = $('#diario-wa-status');
	$badge.text(statusTxt);
	$badge.removeClass('bg-success bg-warning bg-secondary');
	if (result.whatsapp_conectado) {
		$badge.addClass('bg-success');
	} else {
		$badge.addClass('bg-warning');
	}

	var podeEnviar = !!result.whatsapp_conectado;
	$('#btn-diario-wa-lembrete, #btn-diario-wa-faltas').prop('disabled', !podeEnviar);
}

function payloadDiarioWa() {
	return {
		data: $('#data-diario').val(),
		laboratorio_id: $('#lab-diario').val() || 0,
		id_horario: $('#diario-wa-horario').val() || 0,
		mensagem_lembrete: $('#diario-wa-lembrete').val(),
		mensagem_faltas: $('#diario-wa-faltas').val()
	};
}

function salvarMensagensDiarioWa() {
	$.ajax({
		url: url_base + diarioWhatsappUrl,
		method: 'post',
		data: $.extend({ acao: 'salvar_mensagens' }, payloadDiarioWa()),
		dataType: 'json',
		success: function (res) {
			if (!res.success) {
				Swal.fire({ title: 'Atenção', text: res.message || 'Erro ao salvar.', icon: 'warning' });
				return;
			}
			Swal.fire({ title: 'Salvo', text: res.message, icon: 'success', timer: 2000, showConfirmButton: false });
		},
		error: function () {
			Swal.fire({ title: 'Erro', text: 'Falha de rede.', icon: 'error' });
		}
	});
}

function enviarDiarioWa(tipo) {
	if (tipo === 'lembrete' && !$('#diario-wa-horario').val()) {
		Swal.fire({ title: 'Selecione o horário', text: 'Escolha o horário da turma antes de enviar o lembrete.', icon: 'info' });
		return;
	}

	var previewAcao = tipo === 'lembrete' ? 'preview_lembrete' : 'preview_faltas';
	var enviarAcao = tipo === 'lembrete' ? 'enviar_lembrete' : 'enviar_faltas';
	var titulo = tipo === 'lembrete' ? 'Enviar lembrete?' : 'Enviar aviso de faltas?';

	$.ajax({
		url: url_base + diarioWhatsappUrl,
		method: 'post',
		data: $.extend({ acao: previewAcao }, payloadDiarioWa()),
		dataType: 'json',
		success: function (prev) {
			if (!prev.success) {
				Swal.fire({ title: 'Atenção', text: prev.message || 'Não foi possível listar destinatários.', icon: 'warning' });
				return;
			}
			if (!prev.total) {
				var msgVazio = tipo === 'lembrete'
					? 'Nenhum aluno agendado neste horário com WhatsApp válido (ou todos já estão Presente/Reposição).'
					: 'Nenhuma falta registrada para esta data (salve o diário com status Falta).';
				Swal.fire({ title: 'Nenhum destinatário', text: msgVazio, icon: 'info' });
				return;
			}
			var html = '<p><strong>' + prev.total + '</strong> aluno(s) receberão a mensagem.</p>';
			if (tipo === 'lembrete' && prev.horario_label) {
				html += '<p class="small mb-2">Horário: <strong>' + $('<div>').text(prev.horario_label).html() + '</strong></p>';
			}
			if (prev.amostra && prev.amostra.length) {
				html += '<ul class="text-start small mb-0">';
				prev.amostra.forEach(function (a) {
					html += '<li>' + $('<div>').text(a).html() + '</li>';
				});
				if (prev.total > prev.amostra.length) {
					html += '<li class="text-muted">… e mais ' + (prev.total - prev.amostra.length) + '</li>';
				}
				html += '</ul>';
			}
			html += '<p class="small text-muted mt-2 mb-0">Envio na fila de campanhas (intervalo entre disparos).</p>';

			Swal.fire({
				title: titulo,
				html: html,
				icon: 'question',
				showCancelButton: true,
				confirmButtonText: 'Enviar',
				cancelButtonText: 'Cancelar'
			}).then(function (r) {
				if (!r.isConfirmed) return;
				$.ajax({
					url: url_base + diarioWhatsappUrl,
					method: 'post',
					data: $.extend({ acao: enviarAcao }, payloadDiarioWa()),
					dataType: 'json',
					success: function (res) {
						if (!res.ok && !res.success) {
							Swal.fire({ title: 'Erro', text: res.message || 'Falha no envio.', icon: 'error' });
							return;
						}
						var campId = res.campanha_id;
						Swal.fire({
							title: 'Enviando',
							text: res.message || 'Mensagens na fila.',
							icon: 'success',
							showCancelButton: true,
							confirmButtonText: 'Ver campanhas',
							cancelButtonText: 'Ok'
						}).then(function (r2) {
							if (r2.isConfirmed && campId) {
								window.location.href = url_base + 'painel/campanhas';
							}
						});
						if (campId) {
							iniciarPollDiarioWa(campId);
						}
					},
					error: function () {
						Swal.fire({ title: 'Erro', text: 'Falha de rede.', icon: 'error' });
					}
				});
			});
		},
		error: function () {
			Swal.fire({ title: 'Erro', text: 'Falha ao consultar destinatários.', icon: 'error' });
		}
	});
}

function iniciarPollDiarioWa(campanhaId) {
	pararPollDiarioWa();
	diarioCampanhaPollId = campanhaId;
	diarioWaPoll = setInterval(function () {
		$.ajax({
			url: url_base + diarioWhatsappUrl,
			method: 'post',
			data: { acao: 'processar_fila' },
			dataType: 'json'
		});
	}, 35000);
	setTimeout(function () {
		$.ajax({
			url: url_base + diarioWhatsappUrl,
			method: 'post',
			data: { acao: 'processar_fila' },
			dataType: 'json'
		});
	}, 2000);
}

function pararPollDiarioWa() {
	if (diarioWaPoll) {
		clearInterval(diarioWaPoll);
		diarioWaPoll = null;
	}
}

$(document).on('submit', '#formDiario', function (event) {
	event.preventDefault();
	$.ajax({
		url: url_base + salvarDiario,
		type: 'POST',
		data: $(this).serialize(),
		dataType: 'json',
		success: function (response) {
			if (response.erro) {
				Swal.fire({ title: 'Atenção', text: response.erro, icon: 'warning' });
			} else {
				Swal.fire({ title: 'Diário salvo!', icon: 'success', timer: 1800, showConfirmButton: false });
			}
		},
		error: function () {
			Swal.fire({ title: 'Erro', text: 'Não foi possível salvar.', icon: 'error' });
		}
	});
});
