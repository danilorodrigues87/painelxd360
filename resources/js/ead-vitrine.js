function postVitrine(data) {
	return $.ajax({
		url: url_base + 'painel/ead/vitrine',
		method: 'POST',
		dataType: 'json',
		data: data
	});
}

function postEad(data) {
	return $.ajax({
		url: url_base + 'painel/ead',
		method: 'POST',
		dataType: 'json',
		data: data
	});
}

function esc(s) {
	return $('<div>').text(s == null ? '' : String(s)).html();
}

function precoHtml(valor) {
	return Number(valor || 0) <= 0
		? '<span class="badge bg-success">Gratuito</span>'
		: ('R$ ' + Number(valor || 0).toFixed(2) + '<span class="text-muted small">/mês</span>');
}

function capaHtml(url, titulo) {
	if (url) {
		return '<img src="' + esc(url) + '" alt="' + esc(titulo || '') + '" class="card-img-top" style="height:140px;object-fit:cover;">';
	}
	return '<div class="bg-light d-flex align-items-center justify-content-center text-muted border-bottom" style="height:140px;"><span class="small">Sem capa</span></div>';
}

var amostraHls = null;
var amostraCursoId = 0;

function destruirPlayerAmostra() {
	if (amostraHls) {
		try { amostraHls.destroy(); } catch (e) { /* ignore */ }
		amostraHls = null;
	}
	var vid = document.getElementById('amostra-video-hls');
	if (vid) {
		vid.pause();
		vid.removeAttribute('src');
		vid.load();
		vid.classList.add('d-none');
	}
	var iframe = document.getElementById('amostra-iframe');
	if (iframe) iframe.src = '';
	$('#amostra-video-yt').addClass('d-none');
	$('#amostra-video-wrap').addClass('d-none');
}

function cardCatalogo(item) {
	var meta = [];
	if (item.level) meta.push(esc(item.level));
	if (item.carga_h) meta.push(esc(item.carga_h) + 'h');
	if (item.modulos) meta.push(esc(item.modulos) + ' mód.');
	if (item.aulas) meta.push(esc(item.aulas) + ' aulas');
	var btnAssinar = item.assinado
		? '<button type="button" class="btn btn-sm btn-outline-danger btn-cancelar" data-id="' + item.id_curso + '">Cancelar</button>'
		: '<button type="button" class="btn btn-sm btn-success btn-assinar" data-id="' + item.id_curso + '">' +
			(Number(item.preco_mensal || 0) <= 0 ? 'Usar grátis' : 'Assinar') + '</button>';
	return '<div class="col-md-6 col-xl-4">' +
		'<div class="card h-100 shadow-sm">' +
		capaHtml(item.cover_url, item.titulo) +
		'<div class="card-body d-flex flex-column">' +
		'<h5 class="card-title mb-1">' + esc(item.titulo) + '</h5>' +
		'<div class="small text-muted mb-2">' + esc(item.escola) +
		(item.instructor_name ? ' · ' + esc(item.instructor_name) : '') + '</div>' +
		'<p class="card-text small flex-grow-1">' + esc((item.descricao || '').slice(0, 160)) +
		((item.descricao || '').length > 160 ? '…' : '') + '</p>' +
		(meta.length ? '<div class="small text-muted mb-2">' + meta.join(' · ') + '</div>' : '') +
		'<div class="d-flex align-items-center justify-content-between gap-2 mt-auto">' +
		'<div>' + precoHtml(item.preco_mensal) + '</div>' +
		'<div class="text-nowrap">' +
		'<button type="button" class="btn btn-sm btn-outline-primary btn-amostra me-1" data-id="' + item.id_curso + '">Ver amostra</button>' +
		btnAssinar +
		'</div></div></div></div></div>';
}

function cardLicenca(m) {
	return '<div class="col-md-6 col-xl-4">' +
		'<div class="card h-100 shadow-sm">' +
		capaHtml(m.cover_url, m.titulo) +
		'<div class="card-body">' +
		'<h6 class="mb-1">' + esc(m.titulo) + '</h6>' +
		'<div class="small text-muted mb-2">Desde ' + esc(m.inicio || '—') + ' · ' + precoHtml(m.preco_mensal) + '</div>' +
		'<div class="d-flex gap-1 flex-wrap">' +
		'<button type="button" class="btn btn-sm btn-primary btn-matricular-vitrine" data-id="' + m.id_curso + '" data-titulo="' + esc(m.titulo) + '">Matricular alunos</button>' +
		'<button type="button" class="btn btn-sm btn-outline-secondary btn-amostra" data-id="' + m.id_curso + '">Amostra</button>' +
		'<button type="button" class="btn btn-sm btn-outline-danger btn-cancelar" data-id="' + m.id_curso + '">Cancelar</button>' +
		'</div></div></div></div>';
}

function carregarVitrine() {
	postVitrine({ acao: 'listar' }).done(function (res) {
		if (!res || !res.success) {
			if (res && res.sql_ok === false) $('#alert-sql-vitrine').removeClass('d-none');
			$('#vitrine-catalogo').html('<div class="col-12 text-danger">' + esc((res && res.message) || 'Erro') + '</div>');
			return;
		}
		$('#alert-sql-vitrine').addClass('d-none');
		if (!res.itens || !res.itens.length) {
			$('#vitrine-catalogo').html('<div class="col-12 text-muted">Nenhum curso disponível na vitrine. Peça à outra escola para publicar e marcar “Disponibilizar na vitrine”.</div>');
		} else {
			$('#vitrine-catalogo').html(res.itens.map(cardCatalogo).join(''));
		}

		if (!res.minhas || !res.minhas.length) {
			$('#vitrine-minhas').html('<div class="col-12 text-muted">Nenhuma licença ativa.</div>');
		} else {
			$('#vitrine-minhas').html(res.minhas.map(cardLicenca).join(''));
		}
	});
}

function abrirModalAmostra(idCurso) {
	amostraCursoId = idCurso;
	destruirPlayerAmostra();
	$('#amostra-loading').removeClass('d-none');
	$('#amostra-conteudo, #amostra-erro').addClass('d-none');
	$('#amostra-btn-assinar').addClass('d-none').data('id', idCurso);
	var el = document.getElementById('modalAmostraVitrine');
	if (window.bootstrap && bootstrap.Modal) {
		bootstrap.Modal.getOrCreateInstance(el).show();
	} else {
		$(el).modal('show');
	}
	postVitrine({ acao: 'amostra', id_curso: idCurso }).done(function (res) {
		$('#amostra-loading').addClass('d-none');
		if (!res || !res.success) {
			$('#amostra-erro').removeClass('d-none').text((res && res.message) || 'Falha ao carregar amostra.');
			return;
		}
		var c = res.curso || {};
		var escNome = res.escola || {};
		$('#amostra-titulo').text(c.titulo || 'Amostra do curso');
		$('#amostra-meta').html(
			esc(escNome.nome || 'Escola') +
			(c.level ? ' · ' + esc(c.level) : '') +
			(c.carga_h ? ' · ' + esc(c.carga_h) + 'h' : '') +
			(c.instructor_name ? ' · ' + esc(c.instructor_name) : '') +
			' · ' + precoHtml(c.preco_mensal)
		);
		$('#amostra-desc').text(c.descricao || c.description || '');
		$('#amostra-objectives').text(c.objectives ? ('Objetivos: ' + c.objectives) : '');

		if (c.cover_url) {
			$('#amostra-capa').attr('src', c.cover_url).removeClass('d-none');
			$('#amostra-capa-ph').addClass('d-none');
		} else {
			$('#amostra-capa').addClass('d-none').attr('src', '');
			$('#amostra-capa-ph').removeClass('d-none');
		}

		var v = res.video_amostra;
		if (v) {
			$('#amostra-video-wrap').removeClass('d-none');
			$('#amostra-video-titulo').text(v.titulo || 'Vídeo de amostra');
			if (v.provider === 'youtube' && v.embedUrl) {
				$('#amostra-video-yt').removeClass('d-none');
				$('#amostra-iframe').attr('src', v.embedUrl);
			} else if (v.playbackUrl) {
				var videoEl = document.getElementById('amostra-video-hls');
				videoEl.classList.remove('d-none');
				if (videoEl.canPlayType('application/vnd.apple.mpegurl')) {
					videoEl.src = v.playbackUrl;
				} else if (window.Hls && Hls.isSupported()) {
					amostraHls = new Hls({ enableWorker: true });
					amostraHls.loadSource(v.playbackUrl);
					amostraHls.attachMedia(videoEl);
				} else {
					videoEl.src = v.playbackUrl;
				}
			}
		}

		var pdfs = res.pdfs || [];
		if (pdfs.length) {
			$('#amostra-pdfs-wrap').removeClass('d-none');
			var htmlPdf = '';
			pdfs.forEach(function (p) {
				htmlPdf += '<li class="list-group-item px-0 d-flex justify-content-between align-items-center">' +
					'<span>' + esc(p.label) +
					(p.aula ? ' <span class="text-muted small">(' + esc(p.aula) + ')</span>' : '') +
					'</span>' +
					'<a class="btn btn-sm btn-outline-secondary" href="' + esc(p.url) + '" target="_blank" rel="noopener">Abrir PDF</a></li>';
			});
			$('#amostra-pdfs').html(htmlPdf);
		} else {
			$('#amostra-pdfs-wrap').addClass('d-none');
			$('#amostra-pdfs').empty();
		}

		var mods = res.modulos || [];
		if (!mods.length) {
			$('#amostra-modulos').html('<p class="text-muted small mb-0">Sem tópicos cadastrados.</p>');
		} else {
			var htmlM = '';
			mods.forEach(function (m, i) {
				htmlM += '<div class="mb-3"><strong>' + (i + 1) + '. ' + esc(m.titulo) + '</strong><ul class="mb-0 mt-1">';
				(m.aulas || []).forEach(function (a) {
					htmlM += '<li><span>' + esc(a.titulo) + '</span>';
					if (a.descricao) {
						htmlM += '<br><span class="small text-muted">' + esc(a.descricao) + '</span>';
					}
					htmlM += '</li>';
				});
				htmlM += '</ul></div>';
			});
			$('#amostra-modulos').html(htmlM);
		}

		$('#amostra-aviso').text(res.aviso || '');
		var contato = [];
		if (escNome.email) contato.push('E-mail: <a href="mailto:' + esc(escNome.email) + '">' + esc(escNome.email) + '</a>');
		if (escNome.telefone) contato.push('Telefone: ' + esc(escNome.telefone));
		$('#amostra-contato').html(
			'<div><strong>' + esc(escNome.nome || 'Escola') + '</strong></div>' +
			(contato.length ? contato.join(' · ') : '<span class="text-muted">Contato não cadastrado na escola.</span>')
		);

		if (!c.assinado) {
			$('#amostra-btn-assinar')
				.removeClass('d-none')
				.text(Number(c.preco_mensal || 0) <= 0 ? 'Usar grátis' : 'Assinar')
				.data('id', c.id_curso);
		}

		$('#amostra-conteudo').removeClass('d-none');
	}).fail(function () {
		$('#amostra-loading').addClass('d-none');
		$('#amostra-erro').removeClass('d-none').text('Falha de rede ao carregar amostra.');
	});
}

function abrirModalMatricular(idCurso, titulo) {
	$('#modal-id-curso').val(idCurso);
	$('#modal-curso-titulo').text(titulo || '');
	$('#modal-busca-aluno').val('');
	$('#modal-busca-resultados').empty();
	carregarMatriculadosModal(idCurso);
	var el = document.getElementById('modalMatricularVitrine');
	if (window.bootstrap && bootstrap.Modal) {
		bootstrap.Modal.getOrCreateInstance(el).show();
	} else {
		$(el).modal('show');
	}
}

function carregarMatriculadosModal(idCurso) {
	postEad({ acao: 'listar_matriculas_ead', id_curso: idCurso }).done(function (res) {
		if (!res || !res.success || !res.itens || !res.itens.length) {
			$('#modal-matriculados').html('<tr><td colspan="2" class="text-muted">Nenhum aluno ainda.</td></tr>');
			return;
		}
		var html = '';
		res.itens.forEach(function (a) {
			html += '<tr><td>' + esc(a.nome + (a.email ? ' — ' + a.email : '')) + '</td>' +
				'<td><button type="button" class="btn btn-sm btn-outline-danger btn-desmat-modal" data-id="' + a.id_aluno + '">Remover</button></td></tr>';
		});
		$('#modal-matriculados').html(html);
	});
}

$(function () {
	carregarVitrine();
	$(document).on('click', '.btn-assinar', function () {
		postVitrine({ acao: 'assinar', id_curso: $(this).data('id') }).done(function (res) {
			if (!res || !res.success) return Swal.fire('Erro', (res && res.message) || 'Falha', 'error');
			Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: res.message, showConfirmButton: false, timer: 2200 });
			carregarVitrine();
		});
	});
	$('#amostra-btn-assinar').on('click', function () {
		var id = $(this).data('id') || amostraCursoId;
		postVitrine({ acao: 'assinar', id_curso: id }).done(function (res) {
			if (!res || !res.success) return Swal.fire('Erro', (res && res.message) || 'Falha', 'error');
			Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: res.message, showConfirmButton: false, timer: 2200 });
			carregarVitrine();
			var el = document.getElementById('modalAmostraVitrine');
			if (window.bootstrap && bootstrap.Modal) {
				bootstrap.Modal.getOrCreateInstance(el).hide();
			} else {
				$(el).modal('hide');
			}
		});
	});
	$(document).on('click', '.btn-cancelar', function () {
		var id = $(this).data('id');
		Swal.fire({ title: 'Cancelar licença?', showCancelButton: true, confirmButtonText: 'Sim' }).then(function (r) {
			if (!r.isConfirmed) return;
			postVitrine({ acao: 'cancelar', id_curso: id }).done(function (res) {
				if (!res || !res.success) return Swal.fire('Erro', (res && res.message) || 'Falha', 'error');
				carregarVitrine();
			});
		});
	});
	$(document).on('click', '.btn-amostra', function () {
		abrirModalAmostra($(this).data('id'));
	});
	$(document).on('click', '.btn-matricular-vitrine', function () {
		abrirModalMatricular($(this).data('id'), $(this).data('titulo'));
	});

	$('#modalAmostraVitrine').on('hidden.bs.modal', function () {
		destruirPlayerAmostra();
	});

	var buscaTimer = null;
	$('#modal-busca-aluno').on('input', function () {
		var q = $(this).val();
		clearTimeout(buscaTimer);
		if (!q || q.length < 2) {
			$('#modal-busca-resultados').empty();
			return;
		}
		buscaTimer = setTimeout(function () {
			postEad({ acao: 'buscar_alunos', q: q }).done(function (res) {
				var html = '';
				(res.itens || []).forEach(function (a) {
					html += '<button type="button" class="list-group-item list-group-item-action btn-mat-modal" data-id="' + a.id + '">' +
						esc(a.nome + ' — ' + (a.email || '')) + '</button>';
				});
				$('#modal-busca-resultados').html(html || '<div class="text-muted small p-2">Nenhum aluno.</div>');
			});
		}, 300);
	});
	$('#modal-busca-resultados').on('click', '.btn-mat-modal', function () {
		var idCurso = $('#modal-id-curso').val();
		var idAluno = $(this).data('id');
		postEad({ acao: 'matricular_ead', id_curso: idCurso, id_aluno: idAluno }).done(function (res) {
			if (!res || !res.success) return Swal.fire('Erro', (res && res.message) || 'Falha', 'error');
			Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: res.message, showConfirmButton: false, timer: 1800 });
			$('#modal-busca-aluno').val('');
			$('#modal-busca-resultados').empty();
			carregarMatriculadosModal(idCurso);
		});
	});
	$('#modal-matriculados').on('click', '.btn-desmat-modal', function () {
		var idCurso = $('#modal-id-curso').val();
		postEad({ acao: 'desmatricular_ead', id_curso: idCurso, id_aluno: $(this).data('id') }).done(function (res) {
			if (!res || !res.success) return Swal.fire('Erro', (res && res.message) || 'Falha', 'error');
			carregarMatriculadosModal(idCurso);
		});
	});
});
