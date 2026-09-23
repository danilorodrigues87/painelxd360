/** Funções compartilhadas da biblioteca de mídias (Marketing, Agenda picker, Campanhas). */
window.SocialBibShared = window.SocialBibShared || {};

(function (S) {
	'use strict';

	S.esc = function (s) {
		return $('<div>').text(s == null ? '' : String(s)).html();
	};

	S.guessTipo = function (mimeOrName) {
		var s = String(mimeOrName || '').toLowerCase();
		if (s.indexOf('video/') === 0 || /\.(mp4|mov|webm)$/.test(s)) return 'video';
		return 'image';
	};

	S.badgeFormato = function (fmt, tipo) {
		if ((tipo || '') === 'video') return '<span class="badge bg-dark">Vídeo</span> ';
		if (fmt === 'story') return '<span class="badge bg-info">Story</span> ';
		return '<span class="badge bg-secondary">Quadrado</span> ';
	};

	S.formatBytes = function (bytes) {
		bytes = parseInt(bytes, 10) || 0;
		if (bytes < 1024) return bytes + ' B';
		if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
		return (bytes / 1048576).toFixed(2) + ' MB';
	};

	/**
	 * @param {string} target - seletor jQuery
	 * @param {Array} items
	 * @param {object} opts - { pickMode, onPick, onDelete, onEdit, onPreview }
	 */
	S.renderGrid = function (target, items, opts) {
		opts = opts || {};
		var pickMode = !!opts.pickMode;
		if (!items || !items.length) {
			$(target).html('<div class="col-12 text-muted">Nenhum arquivo nesta categoria.</div>');
			return;
		}
		$(target).html(items.map(function (it) {
			var u = it.url || '';
			var isVid = (it.tipo || '') === 'video' || S.guessTipo(it.mime || it.path) === 'video';
			var media = isVid
				? '<video src="' + S.esc(u) + '" class="w-100 bib-thumb" style="height:100px;object-fit:cover;cursor:pointer" muted data-url="' + S.esc(u) + '" data-tipo="video" data-titulo="' + S.esc(it.titulo || it.path || '') + '"></video>'
				: '<img src="' + S.esc(u) + '" class="w-100 bib-thumb" style="height:100px;object-fit:cover;cursor:pointer" alt="" data-url="' + S.esc(u) + '" data-tipo="image" data-titulo="' + S.esc(it.titulo || it.path || '') + '">';
			var meta = '<div class="small text-muted">' + S.formatBytes(it.bytes) + (it.usos ? ' · ' + it.usos + ' uso(s)' : '') + '</div>';
			var btn;
			if (pickMode) {
				btn = '<button type="button" class="btn btn-sm btn-primary w-100 bib-pick" data-path="' + S.esc(it.path) + '" data-tipo="' + S.esc(it.tipo || 'image') + '" data-url="' + S.esc(u) + '" data-nome="' + S.esc(it.titulo || it.path || '') + '">Usar</button>';
			} else {
				btn = '<div class="btn-group w-100">'
					+ '<button type="button" class="btn btn-sm btn-outline-secondary bib-ver" data-url="' + S.esc(u) + '" data-tipo="' + S.esc(isVid ? 'video' : 'image') + '" data-titulo="' + S.esc(it.titulo || it.path || '') + '">Ver</button>'
					+ '<button type="button" class="btn btn-sm btn-outline-primary bib-edit" data-id="' + it.id + '" data-titulo="' + S.esc(it.titulo || '') + '" data-formato="' + S.esc(it.formato || 'feed') + '" data-tipo="' + S.esc(it.tipo || 'image') + '">Editar</button>'
					+ '<button type="button" class="btn btn-sm btn-outline-danger bib-del" data-id="' + it.id + '">Excluir</button>'
					+ '</div>';
			}
			return '<div class="col-6 col-md-3 col-lg-2"><div class="border rounded p-1 h-100">' + media
				+ '<div class="small text-truncate mt-1">' + S.badgeFormato(it.formato, it.tipo) + S.esc(it.titulo || it.path || '') + '</div>'
				+ meta + btn + '</div></div>';
		}).join(''));
	};

	S.abrirPreview = function (url, tipo, titulo, modalSel, titleSel, bodySel) {
		if (!url) return;
		$(titleSel || '#bib-preview-titulo').text(titulo || 'Visualizar');
		if (tipo === 'video') {
			$(bodySel || '#bib-preview-body').html('<video src="' + S.esc(url) + '" class="w-100" style="max-height:70vh" controls autoplay></video>');
		} else {
			$(bodySel || '#bib-preview-body').html('<img src="' + S.esc(url) + '" class="img-fluid" style="max-height:70vh" alt="">');
		}
		var el = document.querySelector(modalSel || '#modalBibPreview');
		if (el) bootstrap.Modal.getOrCreateInstance(el).show();
	};
})(window.SocialBibShared);
