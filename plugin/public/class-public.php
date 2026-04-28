<?php
/**
 * Frontend Público — Penca WC2026.
 *
 * Gestiona:
 * - Encolar CSS y JS del frontend
 * - Pasar variables PHP a JavaScript
 * - Rewrite rules para URLs amigables de perfil
 * - Todos los shortcodes del frontend (coordinados con el diseño web)
 *
 * MAPA COMPLETO DE SHORTCODES:
 *
 * [penca_login]          → /login/         Formulario login + registro en tabs
 * [penca_registro]       → /login/         Solo el tab de registro (en access-codes)
 * [penca_pronosticos]    → /pronosticos/   Grilla de pronósticos (alias de mis_pronosticos)
 * [penca_mis_pronosticos]→ /mi-cuenta/     Ídem (nombre interno)
 * [penca_cuenta]         → /mi-cuenta/     Todo en una página: pronósticos + historial + puntos
 * [penca_mis_puntos]     → /mi-cuenta/     Solo resumen de puntos y posición del usuario actual
 * [penca_partidos]       → /fixture/       Fixture público (sin login), solo lectura
 * [penca_ranking]        → /ranking/       Tabla de posiciones pública (en ranking-engine)
 * [penca_perfil]         → /perfil/        Perfil de usuario (en ranking-engine)
 *
 * @package PencaWC2026
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase Penca_Public.
 */
class Penca_Public {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'encolar_assets' ) );
		add_action( 'init', array( $this, 'registrar_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'agregar_query_vars' ) );

		// ── Shortcodes ──────────────────────────────────────────────────────
		// Formulario de login con tabs login/registro.
		add_shortcode( 'penca_login', array( $this, 'shortcode_login' ) );

		// Alias de penca_mis_pronosticos (nombre usado en el diseño web).
		add_shortcode( 'penca_pronosticos', array( $this, 'shortcode_pronosticos' ) );

		// Página completa "Mi Cuenta": pronósticos + historial + mis puntos en tabs.
		add_shortcode( 'penca_cuenta', array( $this, 'shortcode_cuenta' ) );

		// Solo el resumen de puntos y posición del usuario actual.
		add_shortcode( 'penca_mis_puntos', array( $this, 'shortcode_mis_puntos' ) );

		// Fixture público: todos los partidos y resultados, sin login.
		add_shortcode( 'penca_partidos', array( $this, 'shortcode_partidos' ) );

		// AJAX: login desde formulario propio.
		add_action( 'wp_ajax_nopriv_penca_login_usuario', array( $this, 'ajax_login_usuario' ) );

		// Alias interno (nombre original del plugin, para compatibilidad).
		add_shortcode( 'penca_mis_pronosticos', array( $this, 'shortcode_pronosticos' ) );
	}

	// =========================================================================
	// ASSETS
	// =========================================================================

	/**
	 * Encola CSS y JS del frontend.
	 *
	 * @return void
	 */
	public function encolar_assets(): void {
		// Banderas de países (flag-icons CDN).
		wp_enqueue_style(
			'flag-icons',
			'https://cdn.jsdelivr.net/npm/flag-icons@7.2.3/css/flag-icons.min.css',
			array(),
			'7.2.3'
		);

		wp_enqueue_style(
			'penca-public',
			PENCA_PLUGIN_URL . 'public/assets/css/public.css',
			array( 'flag-icons' ),
			PENCA_VERSION
		);

		wp_enqueue_script(
			'penca-public',
			PENCA_PLUGIN_URL . 'public/assets/js/public.js',
			array( 'jquery' ),
			PENCA_VERSION,
			true
		);

		$usuario_actual = wp_get_current_user();

		wp_localize_script( 'penca-public', 'pencaPublic', array(
			'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
			'pronosticoNonce' => wp_create_nonce( 'penca_pronostico_nonce' ),
			'registroNonce'   => wp_create_nonce( 'penca_registro_nonce' ),
			'loginNonce'      => wp_create_nonce( 'penca_login_nonce' ),
			'isLoggedIn'      => is_user_logged_in(),
			'userId'          => $usuario_actual->ID,
			'displayName'     => esc_js( $usuario_actual->display_name ),
			'timezone'        => PENCA_TIMEZONE,
			'loginUrl'        => wp_login_url(),
			'pronosticosUrl'  => esc_url( home_url( '/pronosticos/' ) ),
		) );
	}

	// =========================================================================
	// REWRITE RULES
	// =========================================================================

	/** @return void */
	public function registrar_rewrite_rules(): void {
		add_rewrite_rule(
			'^penca/perfil/([0-9]+)/?$',
			'index.php?penca_perfil_user_id=$matches[1]',
			'top'
		);
	}

	/** @param array $vars @return array */
	public function agregar_query_vars( array $vars ): array {
		$vars[] = 'penca_perfil_user_id';
		return $vars;
	}

	// =========================================================================
	// SHORTCODES
	// =========================================================================

	/**
	 * [penca_login] — Formulario de acceso con dos tabs: Iniciar sesión / Registrarse.
	 *
	 * Si el usuario ya está logueado, muestra un mensaje con enlace a pronósticos.
	 *
	 * @return string HTML.
	 */
	public function shortcode_login(): string {
		if ( is_user_logged_in() ) {
			$url = home_url( '/pronosticos/' );
			return '<div class="penca-block penca-ya-logueado">' .
				'<p>Ya estás dentro. <a href="' . esc_url( $url ) . '" class="penca-btn penca-btn--primary">Ver mis pronósticos →</a></p>' .
				'</div>';
		}

		ob_start();
		include PENCA_PLUGIN_DIR . 'public/views/login.php';
		return ob_get_clean();
	}

	/**
	 * [penca_pronosticos] — Alias de [penca_mis_pronosticos].
	 *
	 * Nombre usado en el diseño web (/pronosticos/).
	 * Muestra la grilla de partidos para pronosticar (requiere login).
	 *
	 * @return string HTML.
	 */
	public function shortcode_pronosticos(): string {
		return $this->shortcode_mis_pronosticos_interno();
	}

	/**
	 * [penca_cuenta] — Página completa "Mi Cuenta".
	 *
	 * Tres secciones en tabs:
	 * 1. Mis Pronósticos: partidos abiertos + cerrados con resultado
	 * 2. Historial: partido a partido con puntos obtenidos
	 * 3. Mis Puntos: resumen estadístico + posición en ranking
	 *
	 * @return string HTML.
	 */
	public function shortcode_cuenta(): string {
		if ( ! is_user_logged_in() ) {
			return $this->html_requiere_login();
		}

		ob_start();
		include PENCA_PLUGIN_DIR . 'public/views/cuenta.php';
		return ob_get_clean();
	}

	/**
	 * [penca_mis_puntos] — Resumen de puntos y posición del usuario actual.
	 *
	 * Widget compacto: posición en ranking, total de puntos, exactos/diferencias/ganadores.
	 * Puede usarse embebido dentro de otras páginas.
	 *
	 * @return string HTML.
	 */
	public function shortcode_mis_puntos(): string {
		if ( ! is_user_logged_in() ) {
			return $this->html_requiere_login();
		}

		$user_id      = get_current_user_id();
		$score_engine = penca_wc2026()->score_engine;
		$rank_engine  = penca_wc2026()->ranking_engine;

		$stats    = $score_engine->obtener_estadisticas_usuario( $user_id );
		$posicion = $rank_engine->obtener_posicion_usuario( $user_id );
		$usuario  = wp_get_current_user();

		ob_start();
		include PENCA_PLUGIN_DIR . 'public/views/mis-puntos.php';
		return ob_get_clean();
	}

	/**
	 * [penca_partidos] — Fixture público de todos los partidos.
	 *
	 * Muestra partidos y resultados sin requerir login.
	 * Sin formulario de pronóstico — solo lectura.
	 *
	 * @param array $atts Atributos del shortcode (fase, grupo, status).
	 * @return string HTML.
	 */
	public function shortcode_partidos( array $atts = array() ): string {
		$atts = shortcode_atts( array(
			'fase'   => '',
			'grupo'  => '',
			'status' => '',
		), $atts );

		$match_engine = penca_wc2026()->match_engine;
		$filtros      = array_filter( array(
			'fase'   => sanitize_text_field( $atts['fase'] ),
			'grupo'  => sanitize_text_field( $atts['grupo'] ),
			'status' => sanitize_text_field( $atts['status'] ),
		) );

		$partidos = $match_engine->obtener_fixture_frontend();

		// Aplicar filtros si se pasaron como atributos.
		if ( ! empty( $filtros ) ) {
			$partidos = array_filter( $partidos, function( $p ) use ( $filtros ) {
				foreach ( $filtros as $campo => $valor ) {
					if ( isset( $p[ $campo ] ) && strtolower( $p[ $campo ] ) !== strtolower( $valor ) ) {
						return false;
					}
				}
				return true;
			} );
		}

		ob_start();
		include PENCA_PLUGIN_DIR . 'public/views/partidos.php';
		return ob_get_clean();
	}

	// =========================================================================
	// HELPERS INTERNOS
	// =========================================================================

	/**
	 * Lógica compartida de [penca_pronosticos] y [penca_mis_pronosticos].
	 *
	 * @return string HTML.
	 */
	private function shortcode_mis_pronosticos_interno(): string {
		if ( ! is_user_logged_in() ) {
			return $this->html_requiere_login();
		}

		ob_start();
		$vista = PENCA_PLUGIN_DIR . 'public/views/my-predictions.php';
		if ( file_exists( $vista ) ) {
			include $vista;
		}
		return ob_get_clean();
	}

	/**
	 * HTML estándar para secciones que requieren login.
	 *
	 * @return string HTML.
	 */
	private function html_requiere_login(): string {
		$login_url = get_option( 'penca_pagina_registro', 0 )
			? get_permalink( get_option( 'penca_pagina_registro' ) )
			: wp_login_url( get_permalink() );

		return '<div class="penca-block penca-requiere-login">' .
			'<p class="penca-aviso">' .
				'Para acceder a esta sección tenés que ' .
				'<a href="' . esc_url( $login_url ) . '">iniciar sesión</a>.' .
			'</p>' .
			'</div>';
	}

	/**
	 * Handler AJAX para login desde el formulario [penca_login].
	 *
	 * Usa wp_signon() para autenticar y genera la cookie de sesión.
	 * Redirige a pronósticos tras el login exitoso.
	 *
	 * @return void
	 */
	public function ajax_login_usuario(): void {
		check_ajax_referer( 'penca_login_nonce', 'nonce' );

		$log = sanitize_user( wp_unslash( $_POST['log'] ?? '' ) );
		$pwd = $_POST['pwd'] ?? '';

		if ( empty( $log ) || empty( $pwd ) ) {
			wp_send_json_error( array( 'mensaje' => 'Completá usuario y contraseña.' ) );
		}

		$usuario = wp_signon(
			array(
				'user_login'    => $log,
				'user_password' => $pwd,
				'remember'      => true,
			),
			false
		);

		if ( is_wp_error( $usuario ) ) {
			Penca_Helpers::log(
				'access-codes', 'warning',
				"Intento de login fallido. Usuario: {$log} | IP: " . Penca_Helpers::obtener_ip_cliente()
			);
			wp_send_json_error( array( 'mensaje' => 'Usuario o contraseña incorrectos.' ) );
		}

		// Login exitoso — definir redirect.
		$redirect = get_option( 'penca_redirect_post_registro', home_url( '/pronosticos/' ) );

		Penca_Helpers::log(
			'access-codes', 'info',
			"Login exitoso. Usuario ID: {$usuario->ID}",
			array( 'user_id' => $usuario->ID )
		);

		wp_send_json_success( array(
			'mensaje'      => 'Bienvenido, ' . esc_html( $usuario->display_name ) . '.',
			'redirect_url' => $redirect,
		) );
	}

}