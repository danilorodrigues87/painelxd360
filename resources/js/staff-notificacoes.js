(function () {
	'use strict';

	var pollMs = 30000;
	var pollTimer = null;
	var aberto = false;

	function baseUrl() {
		if (typeof url_base !== 'undefined' && url_base) {
			return String(url_base).replace(/\/?$/, '');
		}
		var origin = window.location.origin || '';
		var path = window.location.pathname || '/';
		var match = path.match(/^(.*)\/(?:painel|master)(?:\/.*)?$/);
		if (match) {
			var basePath = match[1] || '';
			return origin + basePath;
		}
		return origin;
	}

	function postNotif(data, cb) {
		$.ajax({
			url: baseUrl() + '/painel/notificacoes',
			method: 'POST',
			data: data,
			dataType: 'json'
		}).done(function (res) {
			if (typeof cb === 'function') cb(res || {});
		}).fail(function () {
			if (typeof cb === 'function') cb({ success: false });
		});
	}

	function esc(s) {
		return $('<div/>').text(s == null ? '' : String(s)).html();
	}

	function fmtTempo(iso) {
		if (!iso) return '';
		var d = new Date(String(iso).replace(' ', 'T'));
		if (isNaN(d.getTime())) return iso;
		var now = new Date();
		var diff = Math.floor((now - d) / 1000);
		if (diff < 60) return 'agora';
		if (diff < 3600) return Math.floor(diff / 60) + ' min';
		if (diff < 86400) return Math.floor(diff / 3600) + ' h';
		return d.toLocaleDateString('pt-BR');
	}

	function renderBadge(n) {
		var $b = $('#staff-notif-badge');
		n = parseInt(n, 10) || 0;
		if (n <= 0) {
			$b.addClass('d-none').text('0');
			return;
		}
		$b.removeClass('d-none').text(n > 99 ? '99+' : String(n));
	}

	function isMobile() {
		return window.matchMedia('(max-width: 767.98px)').matches;
	}

	function openMobileBackdrop() {
		if (!isMobile() || $('.staff-notif-backdrop').length) {
			return;
		}
		var $bd = $('<div class="staff-notif-backdrop" aria-hidden="true"></div>');
		$bd.on('click', function () {
			var toggle = document.getElementById('navNotificacoes');
			if (toggle && typeof bootstrap !== 'undefined') {
				bootstrap.Dropdown.getOrCreateInstance(toggle).hide();
			}
		});
		$('body').append($bd);
	}

	function closeMobileBackdrop() {
		$('.staff-notif-backdrop').remove();
	}

	function renderLista(itens, sqlOk, msg) {
		var $box = $('#staff-notif-lista').empty();
		if (!sqlOk) {
			$box.append('<div class="px-3 py-3 text-warning small">' + esc(msg || 'Execute database/staff_notificacoes.sql') + '</div>');
			return;
		}
		if (!itens || !itens.length) {
			$box.append('<div class="px-3 py-3 text-muted small">Nenhuma notificação recente.</div>');
			return;
		}
		itens.forEach(function (n) {
			var lida = !!n.lida;
			var href = n.link ? (n.link.indexOf('http') === 0 ? n.link : baseUrl() + n.link) : '#';
			var $a = $('<a href="#" class="staff-notif-item dropdown-item py-2 px-3 border-bottom"></a>');
			$a.attr('data-id', n.id);
			if (!lida) $a.addClass('staff-notif-unread');
			$a.html(
				'<div class="d-flex gap-2 align-items-start">'
				+ '<div class="pt-1 flex-shrink-0"><i class="' + esc(n.tipo_icon || 'fas fa-bell') + '"></i></div>'
				+ '<div class="flex-grow-1 min-w-0">'
				+ '<div class="fw-semibold small staff-notif-titulo">' + esc(n.titulo) + '</div>'
				+ '<div class="text-muted small staff-notif-msg">' + esc(n.mensagem) + '</div>'
				+ '<div class="text-muted staff-notif-time" style="font-size:11px;">' + esc(fmtTempo(n.created_at)) + '</div>'
				+ '</div></div>'
			);
			$a.on('click', function (e) {
				e.preventDefault();
				var id = parseInt($(this).data('id'), 10);
				postNotif({ acao: 'marcar_lida', id: id }, function () {
					if (n.link) window.location.href = href;
					else carregar(true);
				});
			});
			$box.append($('<div></div>').append($a));
		});
	}

	function carregar(silent) {
		postNotif({ acao: 'listar' }, function (res) {
			if (!res || !res.success) {
				if (!silent) {
					$('#nav-notif-wrap').removeClass('d-none');
					renderLista([], false, 'Não foi possível carregar notificações. Confira se os arquivos foram publicados no servidor.');
				}
				return;
			}
			$('#nav-notif-wrap').removeClass('d-none');
			if (res.habilitado === false) {
				renderBadge(0);
				if (aberto || !silent) {
					renderLista([], true, 'Sem módulos de atendimento (WhatsApp / Redes sociais) neste usuário.');
				}
				return;
			}
			renderBadge(res.nao_lidas);
			if (aberto || !silent) {
				renderLista(res.itens || [], res.sql_ok !== false, res.message);
			}
		});
	}

	function contagem() {
		postNotif({ acao: 'contagem' }, function (res) {
			if (res && res.success) {
				$('#nav-notif-wrap').removeClass('d-none');
				if (res.habilitado !== false) {
					renderBadge(res.nao_lidas);
				}
			}
		});
	}

	$(function () {
		carregar(true);

		pollTimer = setInterval(function () {
			if (aberto) {
				carregar(true);
			} else {
				contagem();
			}
		}, pollMs);

		$('#navNotificacoes').on('show.bs.dropdown', function () {
			aberto = true;
			openMobileBackdrop();
			carregar(false);
		}).on('shown.bs.dropdown', function () {
			if (isMobile()) {
				var $menu = $('#nav-notif-wrap .staff-notif-menu');
				$menu.css({ transform: 'none', inset: 'auto 8px auto 8px' });
			}
		}).on('hide.bs.dropdown', function () {
			aberto = false;
			closeMobileBackdrop();
		});

		$('#staff-notif-marcar-todas').on('click', function (e) {
			e.preventDefault();
			e.stopPropagation();
			postNotif({ acao: 'marcar_todas' }, function () {
				carregar(false);
			});
		});
	});
})();
