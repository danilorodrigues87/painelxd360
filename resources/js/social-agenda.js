(function () {
  'use strict';

  var API = url_base + '/painel/social';
  var UPLOAD = url_base + '/painel/social/upload';
  var posts = [];
  var view = 'semana';
  var anchor = startOfWeek(new Date());
  var modalInst = null;
  var bibPickInst = null;
  var bibPickFormato = 'feed';
  var S = window.SocialBibShared;
  /** @type {{path:string,tipo:string}[]} */
  var selectedMidias = [];
  var pollBusy = false;
  var POLL_MS = 45000;

  function pad(n) { return n < 10 ? '0' + n : '' + n; }
  function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function startOfWeek(d) {
    var x = new Date(d.getFullYear(), d.getMonth(), d.getDate());
    var day = x.getDay();
    var diff = day === 0 ? -6 : 1 - day;
    x.setDate(x.getDate() + diff);
    return x;
  }
  function addDays(d, n) { var x = new Date(d); x.setDate(x.getDate() + n); return x; }
  function fmtLabel(d) { return pad(d.getDate()) + '/' + pad(d.getMonth() + 1); }
  function fmtDtLocal(iso) {
    if (!iso) return '';
    return String(iso).replace(' ', 'T').slice(0, 16);
  }
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function badgeStatus(st) {
    var map = { agendado: 'primary', publicado: 'success', erro: 'danger', rascunho: 'secondary', cancelado: 'dark', publicando: 'warning' };
    return '<span class="badge bg-' + (map[st] || 'secondary') + '">' + esc(st) + '</span>';
  }
  function badgeFormato(f) {
    var map = { feed: 'info', story: 'warning', reel: 'danger', carousel: 'secondary' };
    return '<span class="badge bg-' + (map[f] || 'secondary') + '">' + esc(f || 'feed') + '</span>';
  }
  function guessTipo(pathOrMime) {
    var s = String(pathOrMime || '').toLowerCase();
    if (s.indexOf('video') === 0 || /\.(mp4|mov|m4v)(\?|$)/.test(s)) return 'video';
    return 'image';
  }

  function postApi(data) {
    return $.ajax({
      url: API,
      method: 'POST',
      data: data,
      dataType: 'json'
    });
  }

  function filtrosPass(p) {
    var st = ($('#filtro-status').val() || '').trim();
    var fo = ($('#filtro-formato').val() || '').trim();
    if (st && p.status !== st) return false;
    if (fo && (p.formato || 'feed') !== fo) return false;
    return true;
  }

  function loadMetaAndWorker() {
    postApi({ acao: 'status_meta' }).done(function (r) {
      if (!r || !r.success) return;
      if (!r.pronto) $('#alert-meta-off').removeClass('d-none');
      else $('#alert-meta-off').addClass('d-none');
      if (r.biblioteca_ok === false || r.historico_ok === false || r.worker_ok === false) {
        $('#alert-sql-social').removeClass('d-none');
      }
    });
    postApi({ acao: 'status_worker' }).done(function (r) {
      if (!r || !r.success) {
        $('#worker-status-txt').text('indisponível');
        return;
      }
      var u = r.ultima;
      var t = u
        ? ('última execução em ' + (u.created_at || '') + ' · publicados ' + (u.ok || 0) + ' · erros ' + (u.erro || 0))
        : 'ainda não houve execução automática';
      $('#worker-status-txt').text(t);
      $('#cron-hint').text(r.hint || 'Com a agenda aberta, posts devidos saem a cada ~45s. Em produção, configure o cron do servidor.');
    });
  }

  /** Poll seguro: um request por vez, lote pequeno, só refresca UI se processou algo. */
  function pollPublicarDevidos() {
    if (pollBusy || document.hidden) return;
    pollBusy = true;
    postApi({ acao: 'worker', silencioso: 1, limite: 5 })
      .done(function (r) {
        if (!r || !r.success) return;
        var s = r.resumo || {};
        var n = (s.ok || 0) + (s.erro || 0);
        if (n > 0) {
          loadPeriodo();
          loadMetaAndWorker();
        }
      })
      .always(function () { pollBusy = false; });
  }

  function loadPeriodo() {
    var data;
    if (view === 'mes') {
      data = { acao: 'mes', mes: anchor.getFullYear() + '-' + pad(anchor.getMonth() + 1) };
    } else {
      data = { acao: 'semana', inicio: ymd(anchor) };
    }
    postApi(data)
      .done(function (r) {
        if (r && r.sql_ok === false) {
          $('#alert-sql-social').removeClass('d-none');
          posts = [];
          renderPeriodo();
          renderLista();
          return;
        }
        posts = ((r && r.itens) || []).filter(filtrosPass);
        renderPeriodo();
        renderLista();
      })
      .fail(function () {
        $('#lista-posts').html('<li class="list-group-item text-danger">Falha ao carregar.</li>');
      });
  }

  function renderPeriodo() {
    if (view === 'mes') {
      $('#wrap-semana').addClass('d-none');
      $('#wrap-mes').removeClass('d-none');
      $('#label-periodo').text(pad(anchor.getMonth() + 1) + '/' + anchor.getFullYear());
      renderMes();
    } else {
      $('#wrap-mes').addClass('d-none');
      $('#wrap-semana').removeClass('d-none');
      $('#label-periodo').text(fmtLabel(anchor) + ' — ' + fmtLabel(addDays(anchor, 6)));
      renderSemana();
    }
  }

  function postsDoDia(ymdStr) {
    return posts.filter(function (p) {
      return String(p.agendado_em || '').slice(0, 10) === ymdStr;
    });
  }

  function cardMini(p) {
    return '<button type="button" class="btn btn-sm btn-light border text-start w-100 mb-1 post-chip" data-id="' + p.id + '">' +
      badgeFormato(p.formato) + ' ' + badgeStatus(p.status) +
      '<div class="small text-truncate">' + esc((p.caption || '').slice(0, 40) || '(sem legenda)') + '</div></button>';
  }

  function renderSemana() {
    var dias = [];
    var i;
    for (i = 0; i < 7; i++) dias.push(addDays(anchor, i));
    $('#thead-dias').html('<th>Seg</th><th>Ter</th><th>Qua</th><th>Qui</th><th>Sex</th><th>Sáb</th><th>Dom</th>');
    var cells = dias.map(function (d) {
      var key = ymd(d);
      var list = postsDoDia(key);
      return '<td class="p-1 align-top"><div class="small fw-bold mb-1">' + fmtLabel(d) + '</div>' +
        (list.map(cardMini).join('') || '<span class="text-muted small">—</span>') + '</td>';
    }).join('');
    $('#tbody-semana').html('<tr>' + cells + '</tr>');
  }

  function renderMes() {
    var ano = anchor.getFullYear();
    var mes = anchor.getMonth();
    var start = startOfWeek(new Date(ano, mes, 1));
    var nomes = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];
    $('#thead-mes').html(nomes.map(function (n) { return '<th class="text-center small">' + n + '</th>'; }).join(''));
    var rows = '';
    var cur = new Date(start);
    var w;
    for (w = 0; w < 6; w++) {
      rows += '<tr>';
      var d;
      for (d = 0; d < 7; d++) {
        var key = ymd(cur);
        var inMonth = cur.getMonth() === mes;
        var list = postsDoDia(key);
        rows += '<td class="p-1' + (inMonth ? '' : ' text-muted bg-light') + '" style="height:90px;min-width:90px">' +
          '<div class="small fw-bold">' + cur.getDate() + '</div>' +
          list.slice(0, 3).map(cardMini).join('') +
          (list.length > 3 ? '<div class="small text-muted">+' + (list.length - 3) + '</div>' : '') +
          '</td>';
        cur = addDays(cur, 1);
      }
      rows += '</tr>';
      if (cur.getMonth() !== mes && w >= 3) break;
    }
    $('#tbody-mes').html(rows);
  }

  function renderLista() {
    if (!posts.length) {
      $('#lista-posts').html('<li class="list-group-item text-muted">Nenhum post no período.</li>');
      return;
    }
    $('#lista-posts').html(posts.map(function (p) {
      return '<li class="list-group-item list-group-item-action post-chip" data-id="' + p.id + '" style="cursor:pointer">' +
        '<div class="d-flex justify-content-between gap-2">' +
        '<span>' + badgeFormato(p.formato) + ' ' + badgeStatus(p.status) + '</span>' +
        '<small class="text-muted">' + esc(String(p.agendado_em || '').slice(0, 16)) + '</small></div>' +
        '<div class="small text-truncate">' + esc((p.caption || '').slice(0, 80) || '(sem legenda)') + '</div></li>';
    }).join(''));
  }

  function showDetalhe(id) {
    var p = posts.find(function (x) { return String(x.id) === String(id); });
    if (!p) {
      $('#painel-detalhe').html('<p class="text-muted small">Post não está na lista do período.</p>');
      return;
    }
    var midia = (p.midias || []).map(function (m) {
      var u = m.url || '';
      return '<div class="small text-truncate"><a href="' + esc(u) + '" target="_blank" rel="noopener">' + esc(m.path || m.url || 'mídia') + '</a></div>';
    }).join('');
    var acoes = '';
    if (p.status === 'agendado' || p.status === 'erro' || p.status === 'rascunho') {
      acoes += '<button type="button" class="btn btn-sm btn-success me-1" id="btn-pub-agora" data-id="' + p.id + '">Publicar agora</button>';
      acoes += '<button type="button" class="btn btn-sm btn-outline-danger" id="btn-cancelar" data-id="' + p.id + '">Cancelar</button>';
    }
    $('#painel-detalhe').html(
      '<div class="mb-2">' + badgeFormato(p.formato) + ' ' + badgeStatus(p.status) + ' · ' + esc(p.canais || '') + '</div>' +
      '<p class="small">' + esc(p.caption || '(sem legenda)') + '</p>' +
      '<p class="small text-muted mb-1">Agendado: ' + esc(p.agendado_em || '—') + '</p>' +
      (p.publicado_em ? '<p class="small text-muted mb-1">Publicado: ' + esc(p.publicado_em) + '</p>' : '') +
      (p.erro_msg ? '<p class="small text-danger">' + esc(p.erro_msg) + '</p>' : '') +
      midia +
      '<div class="mt-2">' + acoes + '</div>'
    );
  }

  function syncFormatoUi() {
    var f = $('#post_formato').val();
    var igOnly = f === 'story' || f === 'reel';
    $('#aviso-formato-ig').toggleClass('d-none', !igOnly);
    if (igOnly) $('#post_canais').val('instagram').prop('disabled', true);
    else $('#post_canais').prop('disabled', false);
  }

  function renderSelectedPreview() {
    $('#post_preview_list').html(selectedMidias.map(function (m, i) {
      return '<span class="badge bg-secondary me-1">' + esc(m.tipo) + ': ' + esc(m.path || m.url_externa || '') +
        ' <a href="#" class="text-white text-decoration-none bib-rm" data-i="' + i + '">×</a></span>';
    }).join(''));
  }

  function openModal() {
    selectedMidias = [];
    $('#post_id').val('');
    $('#post_caption').val('');
    $('#post_url').val('');
    $('#post_lote').val('');
    $('#post_arquivo').val('');
    $('#post_formato').val('feed');
    $('#post_canais').val('ambos').prop('disabled', false);
    var now = new Date();
    now.setMinutes(now.getMinutes() + 5 - (now.getMinutes() % 5));
    $('#post_agendado').val(ymd(now) + 'T' + pad(now.getHours()) + ':' + pad(now.getMinutes()));
    syncFormatoUi();
    $('#caption_count').text('0');
    renderSelectedPreview();
    if (!modalInst) modalInst = new bootstrap.Modal(document.getElementById('modalPost'));
    modalInst.show();
  }

  function loadBibPickGrid() {
    var payload = { acao: 'biblioteca_listar', tipo: 'image' };
    if (bibPickFormato) payload.formato = bibPickFormato;
    postApi(payload).done(function (r) {
      S.renderGrid('#bib-pick-grid', (r && r.itens) || [], { pickMode: true });
    });
  }

  function loadHistorico() {
    postApi({ acao: 'historico' }).done(function (r) {
      var postsH = (r && r.posts) || [];
      var logs = (r && r.logs) || [];
      $('#hist-posts').html(postsH.length ? postsH.map(function (p) {
        return '<li class="list-group-item">' + badgeFormato(p.formato) + ' ' + badgeStatus(p.status) +
          ' <small class="text-muted">' + esc(String(p.agendado_em || '').slice(0, 16)) + '</small>' +
          '<div class="text-truncate">' + esc((p.caption || '').slice(0, 60)) + '</div></li>';
      }).join('') : '<li class="list-group-item text-muted">Vazio</li>');
      $('#hist-logs').html(logs.length ? logs.map(function (l) {
        var ok = l.status === 'ok' || l.status === 'parcial';
        return '<li class="list-group-item">' +
          '<span class="badge bg-' + (ok ? 'success' : 'danger') + '">' + esc(l.status || '') + '</span> ' +
          esc(l.canais || '') + ' · post #' + esc(l.id_post) +
          ' <small class="text-muted">' + esc(l.created_at || '') + '</small>' +
          (l.mensagem ? '<div class="text-danger">' + esc(l.mensagem) + '</div>' : '') +
          '</li>';
      }).join('') : '<li class="list-group-item text-muted">Vazio' + (r && r.sql_log_ok === false ? ' — execute social_fase_a_produto.sql' : '') + '</li>');
    });
  }

  function uploadOne(file, formato) {
    var fd = new FormData();
    fd.append('arquivo', file);
    if (formato) fd.append('formato', formato);
    return $.ajax({
      url: UPLOAD,
      method: 'POST',
      data: fd,
      processData: false,
      contentType: false,
      dataType: 'json'
    });
  }

  function uploadFiles(files) {
    var list = Array.prototype.slice.call(files || []);
    var chain = $.Deferred().resolve([]).promise();
    list.forEach(function (file) {
      chain = chain.then(function (acc) {
        var fmt = guessTipo(file.type || file.name) === 'video' ? '' : ($('#bib-upload-formato').val() || 'feed');
        return uploadOne(file, fmt).then(function (r) {
          if (r && r.success && r.path) {
            acc.push({ path: r.path, tipo: r.tipo || guessTipo(r.mime || file.name), url_externa: '' });
          }
          return acc;
        }, function () { return acc; });
      });
    });
    return chain;
  }

  $(function () {
    loadMetaAndWorker();
    loadPeriodo();
    setTimeout(pollPublicarDevidos, 3000);
    setInterval(pollPublicarDevidos, POLL_MS);
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) pollPublicarDevidos();
    });

    $('#btn-view-semana').on('click', function () {
      view = 'semana';
      $(this).addClass('active');
      $('#btn-view-mes').removeClass('active');
      anchor = startOfWeek(anchor);
      loadPeriodo();
    });
    $('#btn-view-mes').on('click', function () {
      view = 'mes';
      $(this).addClass('active');
      $('#btn-view-semana').removeClass('active');
      anchor = new Date(anchor.getFullYear(), anchor.getMonth(), 1);
      loadPeriodo();
    });
    $('#btn-nav-prev').on('click', function () {
      if (view === 'mes') anchor = new Date(anchor.getFullYear(), anchor.getMonth() - 1, 1);
      else anchor = addDays(anchor, -7);
      loadPeriodo();
    });
    $('#btn-nav-next').on('click', function () {
      if (view === 'mes') anchor = new Date(anchor.getFullYear(), anchor.getMonth() + 1, 1);
      else anchor = addDays(anchor, 7);
      loadPeriodo();
    });
    $('#filtro-status,#filtro-formato').on('change', loadPeriodo);

    $('#tabs-social .nav-link').on('click', function () {
      var tab = $(this).data('tab');
      $('#tabs-social .nav-link').removeClass('active');
      $(this).addClass('active');
      $('#pane-agenda,#pane-historico').addClass('d-none');
      $('#pane-' + tab).removeClass('d-none');
      if (tab === 'historico') loadHistorico();
    });

    $(document).on('click', '.post-chip', function () {
      showDetalhe($(this).data('id'));
    });

    $('#btn-novo-post').on('click', openModal);
    $('#post_formato').on('change', syncFormatoUi);
    $('#post_caption').on('input', function () {
      $('#caption_count').text(String($(this).val() || '').length);
    });
    $(document).on('click', '.bib-rm', function (e) {
      e.preventDefault();
      selectedMidias.splice(parseInt($(this).data('i'), 10), 1);
      renderSelectedPreview();
    });

    $('#btn-abrir-bib').on('click', function () {
      var pf = $('#post_formato').val();
      bibPickFormato = (pf === 'story') ? 'story' : (pf === 'feed' ? 'feed' : '');
      $('#bib-pick-filtro .nav-link').removeClass('active');
      $('#bib-pick-filtro .nav-link[data-formato="' + (bibPickFormato || '') + '"]').addClass('active');
      loadBibPickGrid();
      if (!bibPickInst) bibPickInst = new bootstrap.Modal(document.getElementById('modalBibPick'));
      bibPickInst.show();
    });
    $('#bib-pick-filtro .nav-link').on('click', function () {
      bibPickFormato = String($(this).data('formato') || '');
      $('#bib-pick-filtro .nav-link').removeClass('active');
      $(this).addClass('active');
      loadBibPickGrid();
    });
    $(document).on('click', '.bib-pick', function () {
      selectedMidias.push({ path: String($(this).data('path') || ''), tipo: String($(this).data('tipo') || 'image'), url_externa: '' });
      renderSelectedPreview();
      if (bibPickInst) bibPickInst.hide();
    });

    $(document).on('click', '.bib-ver, .bib-thumb', function (e) {
      e.preventDefault();
      e.stopPropagation();
      S.abrirPreview($(this).data('url'), $(this).data('tipo'), $(this).data('titulo'));
    });

    $('#modalBibPreview').on('hidden.bs.modal', function () {
      $('#bib-preview-body').empty();
    });

    $('#btn-salvar-post').on('click', function () {
      var formato = $('#post_formato').val();
      var caption = $('#post_caption').val() || '';
      var agendado = $('#post_agendado').val();
      var url = ($('#post_url').val() || '').trim();
      var loteRaw = ($('#post_lote').val() || '').trim();
      var files = document.getElementById('post_arquivo').files;
      var $btn = $(this).prop('disabled', true);

      function finish(midias) {
        if (url) midias.push({ path: '', url_externa: url, tipo: guessTipo(url) });
        if (!midias.length) {
          alert('Envie mídia, escolha da biblioteca ou informe URL HTTPS.');
          $btn.prop('disabled', false);
          return;
        }
        var lote = [];
        if (loteRaw) {
          loteRaw.split(/[\n,;]+/).forEach(function (h) {
            h = h.trim();
            if (h) lote.push(h);
          });
        }
        var payload = {
          acao: 'salvar',
          formato: formato,
          canais: $('#post_canais').val(),
          caption: caption,
          status: 'agendado',
          agendado_em: agendado ? agendado.replace('T', ' ') : '',
          midias: JSON.stringify(midias)
        };
        if (lote.length) payload.lote_horarios = JSON.stringify(lote);
        postApi(payload)
          .done(function (r) {
            if (!r || !r.success) {
              alert((r && r.message) || 'Erro ao salvar');
              return;
            }
            if (modalInst) modalInst.hide();
            loadPeriodo();
            loadMetaAndWorker();
          })
          .fail(function () { alert('Falha na requisição'); })
          .always(function () { $btn.prop('disabled', false); });
      }

      uploadFiles(files).done(function (uploaded) {
        finish(selectedMidias.concat(uploaded));
      }).fail(function () {
        $btn.prop('disabled', false);
        alert('Falha no upload');
      });
    });

    $(document).on('click', '#btn-pub-agora', function () {
      var id = $(this).data('id');
      postApi({ acao: 'publicar_agora', id: id }).done(function (r) {
        if (!r || !r.success) alert((r && r.message) || 'Falha');
        loadPeriodo();
        loadMetaAndWorker();
      });
    });
    $(document).on('click', '#btn-cancelar', function () {
      var id = $(this).data('id');
      if (!confirm('Cancelar este post?')) return;
      postApi({ acao: 'cancelar', id: id }).done(function () {
        loadPeriodo();
        $('#painel-detalhe').html('<p class="text-muted small mb-0">Cancelado.</p>');
      });
    });

    $('#btn-worker').on('click', function () {
      var $b = $(this).prop('disabled', true);
      postApi({ acao: 'worker' })
        .done(function (r) {
          var s = r && r.resumo ? r.resumo : {};
          alert(r && r.success
            ? ('Processados: ' + (s.processados || 0) + ' · ok: ' + (s.ok || 0) + ' · erros: ' + (s.erro || 0))
            : ((r && r.message) || 'Falha'));
          loadPeriodo();
          loadMetaAndWorker();
        })
        .always(function () { $b.prop('disabled', false); });
    });
  });
})();
