/**
 * JavaScript del panel admin — Penca WC2026
 * Requiere: jQuery (encolado por WordPress)
 * Variable global inyectada por wp_localize_script: pencaAdmin { ajaxUrl, nonce }
 */
/* global pencaAdmin */
(function ($) {
  'use strict';

  // ── Helpers ─────────────────────────────────────────────────────────────
  function showMsg($el, texto, tipo) {
    $el.text(texto)
       .removeClass('penca-inline-msg--ok penca-inline-msg--error')
       .addClass(tipo === 'ok' ? 'penca-inline-msg--ok' : 'penca-inline-msg--error');
  }

  function ajaxPost(action, data, done, fail) {
    $.post(pencaAdmin.ajaxUrl, $.extend({ action, nonce: pencaAdmin.nonce }, data))
      .done(function (res) { res.success ? done(res.data) : fail(res.data?.mensaje || 'Error desconocido'); })
      .fail(function ()    { fail('Error de red.'); });
  }

  // ── Dashboard: cargar stats ─────────────────────────────────────────────
  function cargarStats() {
    ajaxPost('penca_dashboard_stats', {}, function (d) {
      $('#js-stat-partidos').text(d.total_partidos);
      $('#js-stat-pronosticos').text(d.total_pronosticos);
      $('#js-stat-usuarios').text(d.total_usuarios);
      $('#js-stat-codigos').text(d.codigos_usados);
    }, function () {
      // Silencioso si el dashboard no está cargado.
    });
  }

  // ── Dashboard: sync manual ──────────────────────────────────────────────
  $(document).on('click', '#js-sync-manual', function () {
    var $btn = $(this), $msg = $('#js-sync-resultado');
    $btn.prop('disabled', true).text('⏳ Sincronizando...');
    showMsg($msg, '', '');

    ajaxPost('penca_sync_manual', {}, function (d) {
      showMsg($msg, '✅ ' + d.mensaje, 'ok');
      $btn.prop('disabled', false).text('🔄 Sincronizar ahora');
      cargarStats();
    }, function (err) {
      showMsg($msg, '❌ ' + err, 'error');
      $btn.prop('disabled', false).text('🔄 Sincronizar ahora');
    });
  });

  // ── Dashboard: reactivar API primaria ───────────────────────────────────
  $(document).on('click', '#js-reactivar-api', function () {
    var $btn = $(this), $msg = $('#js-reactivar-resultado');
    $btn.prop('disabled', true);
    ajaxPost('penca_reactivar_api', {}, function (d) {
      showMsg($msg, '✅ ' + d.mensaje, 'ok');
      setTimeout(function () { location.reload(); }, 1500);
    }, function (err) {
      showMsg($msg, '❌ ' + err, 'error');
      $btn.prop('disabled', false);
    });
  });

  // ── Códigos: generar ────────────────────────────────────────────────────
  $(document).on('click', '#js-generar-codigos', function () {
    var cantidad = parseInt($('#js-gen-cantidad').val()) || 10;
    var notas    = $('#js-gen-notas').val();
    var $btn     = $(this), $msg = $('#js-gen-resultado');

    $btn.prop('disabled', true).text('⏳ Generando...');
    ajaxPost('penca_generar_codigos', { cantidad, notas }, function (d) {
      showMsg($msg, '✅ ' + d.mensaje, 'ok');
      $btn.prop('disabled', false).text('➕ Generar');
      // Mostrar códigos generados en textarea.
      if (d.codigos && d.codigos.length) {
        $('#js-codigos-textarea').val(d.codigos.join('\n'));
        $('#js-codigos-generados').show();
      }
      setTimeout(function () { location.reload(); }, 2000);
    }, function (err) {
      showMsg($msg, '❌ ' + err, 'error');
      $btn.prop('disabled', false).text('➕ Generar');
    });
  });

  // ── Códigos: copiar al portapapeles ─────────────────────────────────────
  $(document).on('click', '#js-copiar-codigos', function () {
    var texto = $('#js-codigos-textarea').val();
    navigator.clipboard?.writeText(texto).then(function () {
      alert('Códigos copiados al portapapeles.');
    });
  });

  // ── Códigos: filtrar tabla en tiempo real ───────────────────────────────
  $(document).on('input', '#js-filtro-buscar, #js-filtro-status', function () {
    var status = $('#js-filtro-status').val().toLowerCase();
    var buscar = $('#js-filtro-buscar').val().toLowerCase();

    $('#js-tabla-codigos tbody tr').each(function () {
      var $row    = $(this);
      var rowSt   = ($row.data('status') || '').toLowerCase();
      var rowCod  = ($row.data('codigo') || '').toLowerCase();
      var okSt    = !status || rowSt === status;
      var okBusc  = !buscar || rowCod.includes(buscar);
      $row.toggle(okSt && okBusc);
    });
  });

  // ── Códigos: bloquear ───────────────────────────────────────────────────
  $(document).on('click', '.js-bloquear-codigo', function () {
    var id = $(this).data('id');
    if (!confirm('¿Bloquear este código?')) return;
    var motivo = prompt('Motivo del bloqueo (opcional):') || '';
    var $btn = $(this);
    $btn.prop('disabled', true);
    ajaxPost('penca_bloquear_codigo', { codigo_id: id, motivo }, function () {
      location.reload();
    }, function (err) {
      alert('Error: ' + err);
      $btn.prop('disabled', false);
    });
  });

  // ── Override: guardar resultado ─────────────────────────────────────────
  $(document).on('click', '.js-btn-override', function () {
    var $form = $(this).closest('.penca-override-form');
    var $msg  = $form.find('.penca-override-msg');
    var mid   = $form.data('match-id');

    var gl  = $form.find('.js-gl').val();
    var gv  = $form.find('.js-gv').val();
    var pl  = $form.find('.js-pl').val();
    var pv  = $form.find('.js-pv').val();
    var st  = $form.find('.js-status').val();

    if (gl === '' || gv === '') {
      showMsg($msg, '❌ Ingresá los goles', 'error'); return;
    }

    $(this).prop('disabled', true).text('⏳');
    ajaxPost('penca_override_resultado', {
      match_id: mid, goles_local: gl, goles_visitante: gv,
      penales_local: pl, penales_visitante: pv, status: st
    }, function (d) {
      showMsg($msg, '✅ ' + d.mensaje, 'ok');
      $(this).prop('disabled', false).text('💾 Guardar');
    }.bind(this), function (err) {
      showMsg($msg, '❌ ' + err, 'error');
      $(this).prop('disabled', false).text('💾 Guardar');
    }.bind(this));
  });

  // ── Override: filtro de búsqueda ────────────────────────────────────────
  $(document).on('input', '#js-override-buscar', function () {
    var q = $(this).val().toLowerCase();
    $('.penca-override-row').each(function () {
      var txt = ($(this).data('equipo-l') + ' ' + $(this).data('equipo-v') + ' ' + $(this).data('fase')).toLowerCase();
      $(this).toggle(!q || txt.includes(q));
    });
  });

  // ── Logs: refresh manual ─────────────────────────────────────────────────
  var logsAutoRefreshTimer = null;

  function refreshLogs() {
    var modulo = new URLSearchParams(location.search).get('modulo') || '';
    var nivel  = new URLSearchParams(location.search).get('nivel')  || '';
    ajaxPost('penca_obtener_logs_admin', { modulo, nivel, limite: 100 }, function (d) {
      if (!d.logs || !d.logs.length) return;
      var html = '';
      d.logs.forEach(function (l) {
        html += '<tr class="penca-log-row penca-log-row--' + l.nivel + '">' +
          '<td>' + l.id + '</td>' +
          '<td><code>' + l.modulo + '</code></td>' +
          '<td><span class="penca-badge penca-badge--' + l.nivel + '">' + l.nivel.toUpperCase() + '</span></td>' +
          '<td>' + $('<div>').text(l.mensaje).html() + '</td>' +
          '<td>' + (l.user_id || '—') + '</td>' +
          '<td>' + (l.ip_address || '—') + '</td>' +
          '<td>' + l.created_at_uy + '</td>' +
          '</tr>';
      });
      $('#js-logs-tbody').html(html);
      $('#js-logs-count').text('Mostrando ' + d.logs.length + ' entradas (actualizado).');
    }, function () {});
  }

  $(document).on('click', '#js-logs-refresh', refreshLogs);

  $(document).on('change', '#js-autorefresh', function () {
    if ($(this).is(':checked')) {
      logsAutoRefreshTimer = setInterval(refreshLogs, 30000);
    } else {
      clearInterval(logsAutoRefreshTimer);
    }
  });


  // ── Herramientas: recálculo masivo de puntos ─────────────────────────────
  $(document).on('click', '#js-recalcular-todo', function () {
    if (!confirm('¿Recalcular los puntos de TODOS los partidos finalizados? Esto puede tardar algunos segundos.')) return;
    var $btn = $(this), $msg = $('#js-recalcular-msg');
    $btn.prop('disabled', true).text('⏳ Recalculando...');
    showMsg($msg, '', '');
    ajaxPost('penca_recalcular_todo', {}, function (d) {
      showMsg($msg, '✅ ' + d.mensaje, 'ok');
      $btn.prop('disabled', false).text('🔄 Recalcular todos los puntos');
    }, function (err) {
      showMsg($msg, '❌ ' + err, 'error');
      $btn.prop('disabled', false).text('🔄 Recalcular todos los puntos');
    });
  });

  // ── Init ─────────────────────────────────────────────────────────────────
  $(function () {
    // Cargar stats si estamos en el dashboard.
    if ($('#js-stats-grid').length) { cargarStats(); }
  });

}(jQuery));
