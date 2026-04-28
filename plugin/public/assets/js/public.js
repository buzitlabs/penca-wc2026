/**
 * JavaScript del frontend — Penca WC2026
 * Requiere: jQuery
 * Variable global: pencaPublic { ajaxUrl, pronosticoNonce, registroNonce, isLoggedIn, userId }
 */
/* global pencaPublic */
(function ($) {
  'use strict';

  // ── Helpers ───────────────────────────────────────────────────────────────
  function showMsg($el, texto, tipo) {
    $el.text(texto)
       .removeClass('penca-pron-msg--ok penca-pron-msg--error penca-msg--ok penca-msg--error')
       .addClass(tipo === 'ok' ? 'penca-pron-msg--ok' : 'penca-pron-msg--error');
  }

  function ajaxPost(action, data, nonce, done, fail) {
    $.post(pencaPublic.ajaxUrl, $.extend({ action, nonce }, data))
      .done(function (res) { res.success ? done(res.data) : fail(res.data?.mensaje || 'Error desconocido'); })
      .fail(function ()    { fail('Error de conexión. Intentá de nuevo.'); });
  }


  // ══════════════════════════════════════════════════════════════════════════
  // TABS (login, cuenta, etc.)
  // ══════════════════════════════════════════════════════════════════════════
  $(document).on('click', '.penca-tab', function () {
    var $tab    = $(this);
    var tabId   = $tab.data('tab');
    var $wrap   = $tab.closest('.penca-block, .penca-cuenta, .penca-login-wrap');

    // Desactivar todos los tabs del mismo bloque.
    $wrap.find('.penca-tab').removeClass('penca-tab--activo').attr('aria-selected', 'false');
    $wrap.find('.penca-tab-panel').removeClass('penca-tab-panel--activo');

    // Activar el seleccionado.
    $tab.addClass('penca-tab--activo').attr('aria-selected', 'true');
    $('#' + tabId).addClass('penca-tab-panel--activo');
  });

  // ══════════════════════════════════════════════════════════════════════════
  // LOGIN AJAX
  // ══════════════════════════════════════════════════════════════════════════
  $(document).on('submit', '#js-form-login', function (e) {
    e.preventDefault();
    var $btn = $('#js-btn-login');
    var $msg = $('#js-login-msg');
    var log  = $('#penca-login-user').val().trim();
    var pwd  = $('#penca-login-pass').val();

    if (!log || !pwd) {
      $msg.text('Completá usuario y contraseña.').removeClass().addClass('penca-form__global-msg penca-msg--error').show();
      return;
    }

    $btn.prop('disabled', true).text('⏳ Ingresando...');

    $.post(pencaPublic.ajaxUrl, {
      action: 'penca_login_usuario',
      nonce:  pencaPublic.loginNonce,
      log:    log,
      pwd:    pwd
    }).done(function (res) {
      if (res.success) {
        $msg.text('✅ Ingresando...').removeClass().addClass('penca-form__global-msg penca-msg--ok').show();
        setTimeout(function () { window.location.href = res.data.redirect_url; }, 800);
      } else {
        $msg.text('❌ ' + (res.data?.mensaje || 'Usuario o contraseña incorrectos.')).removeClass().addClass('penca-form__global-msg penca-msg--error').show();
        $btn.prop('disabled', false).text('🔐 Entrar');
      }
    }).fail(function () {
      $msg.text('❌ Error de conexión.').removeClass().addClass('penca-form__global-msg penca-msg--error').show();
      $btn.prop('disabled', false).text('🔐 Entrar');
    });
  });

  // Toggle de contraseña para el login también.
  $(document).on('click', '.js-toggle-pass', function () {
    var $input = $(this).siblings('input[type="password"], input[type="text"]');
    var tipo   = $input.attr('type') === 'password' ? 'text' : 'password';
    $input.attr('type', tipo);
    $(this).text(tipo === 'password' ? '👁️' : '🙈');
  });

  // ══════════════════════════════════════════════════════════════════════════
  // FIXTURE PÚBLICO — Filtros por status
  // ══════════════════════════════════════════════════════════════════════════
  $(document).on('click', '.penca-filtro-btn', function () {
    var $btn    = $(this);
    var filtro  = $btn.data('filtro');

    $btn.siblings('.penca-filtro-btn').removeClass('penca-filtro-btn--activo');
    $btn.addClass('penca-filtro-btn--activo');

    $('.js-partido-card').each(function () {
      var status = $(this).data('status');
      var mostrar = !filtro || status === filtro;
      $(this).toggle(mostrar);
    });

    // Ocultar secciones de fase que quedaron vacías.
    $('.js-fase-section').each(function () {
      var hayVisibles = $(this).find('.js-partido-card:visible').length > 0;
      $(this).toggle(hayVisibles);
    });
  });

  // ══════════════════════════════════════════════════════════════════════════
  // PRONÓSTICOS — Guardar / actualizar
  // ══════════════════════════════════════════════════════════════════════════
  $(document).on('click', '.js-btn-guardar-pron', function () {
    var $btn  = $(this);
    var $form = $btn.closest('.js-pron-form');
    var $msg  = $form.find('.js-pron-msg');
    var mid   = $form.data('match-id');
    var gl    = parseInt($form.find('.js-pron-local').val());
    var gv    = parseInt($form.find('.js-pron-visitante').val());

    if (isNaN(gl) || isNaN(gv) || gl < 0 || gl > 20 || gv < 0 || gv > 20) {
      showMsg($msg, '❌ Ingresá valores entre 0 y 20.', 'error'); return;
    }

    $btn.prop('disabled', true).text('⏳');
    showMsg($msg, '', '');

    ajaxPost('penca_guardar_pronostico', {
      match_id: mid, goles_local: gl, goles_visitante: gv
    }, pencaPublic.pronosticoNonce, function (d) {
      showMsg($msg, '✅ Guardado', 'ok');
      $btn.prop('disabled', false).text('✏️ Actualizar');
      // Marcar la card como con-pronóstico.
      $btn.closest('.penca-partido-card').addClass('penca-partido-card--con-pron');
      // Limpiar el mensaje después de 3s.
      setTimeout(function () { $msg.text(''); }, 3000);
    }, function (err) {
      showMsg($msg, '❌ ' + err, 'error');
      $btn.prop('disabled', false).text('💾 Guardar');
    });
  });

  // ══════════════════════════════════════════════════════════════════════════
  // RANKING — Paginación AJAX
  // ══════════════════════════════════════════════════════════════════════════
  $(document).on('click', '.penca-btn--pag', function () {
    var pagina = parseInt($(this).data('pagina'));
    if (isNaN(pagina)) return;

    var $container = $('#penca-ranking');
    $container.css('opacity', '.5');

    ajaxPost('penca_obtener_ranking', { pagina }, pencaPublic.pronosticoNonce, function (d) {
      // Actualizar filas de la tabla.
      var tbody = '';
      (d.ranking || []).forEach(function (f) {
        var medallas = { 1: '🥇', 2: '🥈', 3: '🥉' };
        var pos = medallas[f.posicion] || f.posicion;
        var esYo = pencaPublic.isLoggedIn && parseInt(pencaPublic.userId) === f.user_id;
        tbody += '<tr class="penca-ranking__row' + (esYo ? ' penca-ranking__row--yo' : '') + '">' +
          '<td class="col-pos">' + pos + '</td>' +
          '<td class="col-nombre">' + $('<div>').text(f.display_name).html() + (esYo ? ' <span class="penca-tu-badge">Vos</span>' : '') + '</td>' +
          '<td class="col-pts penca-pts">' + f.total_puntos + '</td>' +
          '<td class="col-exactos">' + f.exactos + '</td>' +
          '<td class="col-dif">' + f.diferencias + '</td>' +
          '<td class="col-gan">' + f.ganadores + '</td>' +
          '<td class="col-link"><a href="' + f.url_perfil + '" class="penca-btn penca-btn--sm">Ver perfil</a></td>' +
          '</tr>';
      });
      $container.find('.penca-ranking__table tbody').html(tbody);

      // Actualizar paginación activa.
      $container.find('.penca-btn--pag').removeClass('penca-btn--pag-activo');
      $container.find('.penca-btn--pag[data-pagina="' + pagina + '"]').addClass('penca-btn--pag-activo');
      $container.css('opacity', '1');
    }, function () {
      $container.css('opacity', '1');
    });
  });

  // ══════════════════════════════════════════════════════════════════════════
  // REGISTRO — Formulario con validación en tiempo real
  // ══════════════════════════════════════════════════════════════════════════
  // Verificar código en tiempo real (debounce 600ms).
  var codigoTimer = null;
  $(document).on('input', '#penca-codigo', function () {
    clearTimeout(codigoTimer);
    var $input = $(this);
    var $msg   = $('#js-codigo-msg');
    var val    = $input.val().toUpperCase().trim();
    $input.val(val);

    // Formato básico: ya tiene 14 chars → verificar.
    if (val.length < 14) { $msg.text('').css('color', ''); return; }

    codigoTimer = setTimeout(function () {
      $msg.text('Verificando...').css('color', '#6c757d');
      $.post(pencaPublic.ajaxUrl, {
        action: 'penca_verificar_codigo',
        codigo: val
      }).done(function (res) {
        if (res.success) {
          $msg.text('✅ ' + res.data.mensaje).css('color', '#198754');
        } else {
          $msg.text('❌ ' + (res.data?.mensaje || 'Código inválido')).css('color', '#dc3545');
        }
      });
    }, 600);
  });

  // Mostrar/ocultar contraseña.
  $(document).on('click', '#js-toggle-pass', function () {
    var $input = $('#penca-password');
    var tipo   = $input.attr('type') === 'password' ? 'text' : 'password';
    $input.attr('type', tipo);
    $(this).text(tipo === 'password' ? '👁️' : '🙈');
  });

  // Submit del formulario de registro.
  $(document).on('submit', '#js-form-registro', function (e) {
    e.preventDefault();

    var $form   = $(this);
    var $btn    = $('#js-btn-registro');
    var $msg    = $('#js-registro-msg');
    var codigo  = $('#penca-codigo').val().trim();
    var uname   = $('#penca-username').val().trim();
    var email   = $('#penca-email').val().trim();
    var pass    = $('#penca-password').val();
    var dname   = $('#penca-display-name').val().trim();

    // Validaciones básicas frontend.
    if (!codigo || !uname || !email || !pass || !dname) {
      $msg.text('Completá todos los campos.').removeClass().addClass('penca-form__global-msg penca-msg--error');
      return;
    }
    if (pass.length < 8) {
      $msg.text('La contraseña debe tener al menos 8 caracteres.').removeClass().addClass('penca-form__global-msg penca-msg--error');
      return;
    }

    $btn.prop('disabled', true).text('⏳ Registrando...');
    $msg.removeClass().addClass('penca-form__global-msg').hide();

    ajaxPost('penca_registrar_usuario', {
      codigo, username: uname, email, password: pass, display_name: dname
    }, pencaPublic.registroNonce, function (d) {
      $msg.text('✅ ' + d.mensaje).removeClass().addClass('penca-form__global-msg penca-msg--ok').show();
      $form[0].reset();
      // Redirigir después de 1.5 segundos.
      setTimeout(function () {
        window.location.href = d.redirect_url || window.location.href;
      }, 1500);
    }, function (err) {
      $msg.text('❌ ' + err).removeClass().addClass('penca-form__global-msg penca-msg--error').show();
      $btn.prop('disabled', false).text('⚽ Registrarme y jugar');
    });
  });

  // ══════════════════════════════════════════════════════════════════════════
  // Countdown timer al kickoff (si hay tarjetas con data-kickoff-iso)
  // ══════════════════════════════════════════════════════════════════════════
  function actualizarCountdowns() {
    $('.penca-partido-card[data-kickoff-iso]').each(function () {
      var iso  = $(this).data('kickoff-iso');
      var $el  = $(this).find('.penca-countdown');
      if (!$el.length || !iso) return;
      var diff = new Date(iso) - new Date();
      if (diff <= 0) { $el.text('En juego o finalizado'); return; }
      var h  = Math.floor(diff / 3600000);
      var m  = Math.floor((diff % 3600000) / 60000);
      var s  = Math.floor((diff % 60000) / 1000);
      $el.text(h + 'h ' + m + 'm ' + s + 's');
    });
  }
  setInterval(actualizarCountdowns, 1000);
  actualizarCountdowns();

}(jQuery));
