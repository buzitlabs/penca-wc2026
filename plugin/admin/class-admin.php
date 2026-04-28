<?php
/**
 * Panel de Administración — Penca WC2026.
 *
 * Tres niveles de acceso:
 * - Admin    (manage_options):     Dashboard, Logs, Configuración, Códigos, Override, Pronósticos
 * - Editor   (edit_others_posts):  Códigos, Override, Pronósticos
 * - Subscriber (read):             Solo frontend — sin acceso al admin del plugin
 *
 * Capability usada para Operador: 'edit_others_posts' (rol Editor nativo de WordPress).
 *
 * @package PencaWC2026
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase Penca_Admin.
 */
class Penca_Admin {

	/** Capability mínima para acceder al menú del plugin (operador). */
	const CAP_OPERADOR = 'edit_others_posts';

	/** Capability exclusiva del administrador del plugin. */
	const CAP_ADMIN = 'manage_options';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'registrar_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'encolar_assets' ) );

		// AJAX solo-admin.
		add_action( 'wp_ajax_penca_obtener_logs_admin', array( $this, 'ajax_obtener_logs' ) );
		add_action( 'wp_ajax_penca_dashboard_stats',    array( $this, 'ajax_dashboard_stats' ) );
		add_action( 'wp_ajax_penca_reactivar_api',      array( $this, 'ajax_reactivar_api' ) );
		add_action( 'wp_ajax_penca_recalcular_todo',    array( $this, 'ajax_recalcular_todo' ) );

		// AJAX operador + admin.
		add_action( 'wp_ajax_penca_bloquear_codigo',         array( $this, 'ajax_bloquear_codigo' ) );
		add_action( 'wp_ajax_penca_pronosticos_partido_admin', array( $this, 'ajax_pronosticos_partido' ) );
		add_action( 'wp_ajax_penca_sin_pronostico_partido',   array( $this, 'ajax_sin_pronostico_partido' ) );
	}

	// =========================================================================
	// HELPERS DE PERMISOS
	// =========================================================================

	/** @return bool El usuario actual es administrador del plugin. */
	private function es_admin(): bool {
		return current_user_can( self::CAP_ADMIN );
	}

	/** @return bool El usuario puede operar el plugin (admin o editor). */
	private function es_operador(): bool {
		return current_user_can( self::CAP_OPERADOR );
	}

	/** Corta la ejecución si el usuario no es al menos operador. */
	private function requerir_operador(): void {
		if ( ! $this->es_operador() ) {
			wp_die( __( 'No tenés permisos para acceder a esta sección.', 'penca-wc2026' ) );
		}
	}

	/** Corta la ejecución si el usuario no es administrador. */
	private function requerir_admin(): void {
		if ( ! $this->es_admin() ) {
			wp_die( __( 'Esta sección es solo para administradores.', 'penca-wc2026' ) );
		}
	}

	// =========================================================================
	// MENÚ ADMIN
	// =========================================================================

	/**
	 * Registra el menú principal y submenús.
	 *
	 * El menú raíz usa CAP_OPERADOR para que el Editor pueda verlo.
	 * Los submenús solo-admin usan CAP_ADMIN — WordPress los oculta
	 * automáticamente si el usuario no tiene esa capability.
	 *
	 * @return void
	 */
	public function registrar_menu(): void {

		// Menú raíz: visible para operadores y admins.
		add_menu_page(
			__( 'Penca WC2026', 'penca-wc2026' ),
			__( 'Penca WC2026', 'penca-wc2026' ),
			self::CAP_OPERADOR,
			'penca-wc2026',
			array( $this, 'pagina_dashboard' ),
			'dashicons-awards',
			30
		);

		// Dashboard — solo admin.
		add_submenu_page(
			'penca-wc2026',
			__( 'Dashboard', 'penca-wc2026' ),
			__( 'Dashboard', 'penca-wc2026' ),
			self::CAP_ADMIN,
			'penca-wc2026',
			array( $this, 'pagina_dashboard' )
		);

		// Códigos — operador y admin.
		add_submenu_page(
			'penca-wc2026',
			__( 'Códigos de Acceso', 'penca-wc2026' ),
			__( 'Códigos', 'penca-wc2026' ),
			self::CAP_OPERADOR,
			'penca-codigos',
			array( $this, 'pagina_codigos' )
		);

		// Override — operador y admin.
		add_submenu_page(
			'penca-wc2026',
			__( 'Override de Resultados', 'penca-wc2026' ),
			__( 'Override', 'penca-wc2026' ),
			self::CAP_OPERADOR,
			'penca-override',
			array( $this, 'pagina_override' )
		);

		// Pronósticos por partido — operador y admin.
		add_submenu_page(
			'penca-wc2026',
			__( 'Pronósticos por partido', 'penca-wc2026' ),
			__( 'Pronósticos', 'penca-wc2026' ),
			self::CAP_OPERADOR,
			'penca-pronosticos',
			array( $this, 'pagina_pronosticos' )
		);

		// Logs — solo admin.
		add_submenu_page(
			'penca-wc2026',
			__( 'Logs del Sistema', 'penca-wc2026' ),
			__( 'Logs', 'penca-wc2026' ),
			self::CAP_ADMIN,
			'penca-logs',
			array( $this, 'pagina_logs' )
		);

		// Configuración — solo admin.
		add_submenu_page(
			'penca-wc2026',
			__( 'Configuración', 'penca-wc2026' ),
			__( 'Configuración', 'penca-wc2026' ),
			self::CAP_ADMIN,
			'penca-config',
			array( $this, 'pagina_config' )
		);
	}

	// =========================================================================
	// ASSETS
	// =========================================================================

	/**
	 * Encola CSS y JS del admin solo en páginas del plugin.
	 *
	 * @param string $hook Hook de la página actual.
	 * @return void
	 */
	public function encolar_assets( string $hook ): void {
		$paginas_plugin = array(
			'toplevel_page_penca-wc2026',
			'penca-wc2026_page_penca-codigos',
			'penca-wc2026_page_penca-override',
			'penca-wc2026_page_penca-pronosticos',
			'penca-wc2026_page_penca-logs',
			'penca-wc2026_page_penca-config',
		);

		if ( ! in_array( $hook, $paginas_plugin, true ) ) {
			return;
		}

		wp_enqueue_style( 'penca-admin', PENCA_PLUGIN_URL . 'admin/assets/css/admin.css', array(), PENCA_VERSION );
		wp_enqueue_script( 'penca-admin', PENCA_PLUGIN_URL . 'admin/assets/js/admin.js', array( 'jquery' ), PENCA_VERSION, true );

		wp_localize_script( 'penca-admin', 'pencaAdmin', array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'penca_admin_nonce' ),
			'pluginUrl' => PENCA_PLUGIN_URL,
			'esAdmin'   => $this->es_admin() ? '1' : '0',
		) );
	}

	// =========================================================================
	// PÁGINAS
	// =========================================================================

	/** Dashboard — solo admin. */
	public function pagina_dashboard(): void {
		$this->requerir_admin();

		$estado_api     = penca_wc2026()->api_sync->obtener_estado();
		$estado_tablas  = Penca_Installer::verificar_tablas();
		$logs_recientes = Penca_Helpers::obtener_logs( 10, '', 'error' );

		include PENCA_PLUGIN_DIR . 'admin/views/dashboard.php';
	}

	/** Códigos — operador y admin. */
	public function pagina_codigos(): void {
		$this->requerir_operador();

		$access_codes        = penca_wc2026()->access_codes;
		$codigos_disponibles = count( $access_codes->obtener_codigos( array( 'status' => 'available', 'limite' => 1000 ) ) );
		$codigos_usados      = count( $access_codes->obtener_codigos( array( 'status' => 'used',      'limite' => 1000 ) ) );
		$codigos_bloqueados  = count( $access_codes->obtener_codigos( array( 'status' => 'blocked',   'limite' => 1000 ) ) );
		$todos_los_codigos   = $access_codes->obtener_codigos( array( 'limite' => 200 ) );

		// Tope de usuarios: máximo 1.000 registrados.
		$tope_alcanzado = $codigos_usados >= 1000;

		include PENCA_PLUGIN_DIR . 'admin/views/codes.php';
	}

	/** Override — operador y admin. */
	public function pagina_override(): void {
		$this->requerir_operador();

		$partidos = penca_wc2026()->match_engine->obtener_partidos();

		include PENCA_PLUGIN_DIR . 'admin/views/override.php';
	}

	/**
	 * Pronósticos por partido — operador y admin.
	 *
	 * Muestra qué pronosticó cada usuario en un partido seleccionado,
	 * y quiénes no pronosticaron.
	 */
	public function pagina_pronosticos(): void {
		$this->requerir_operador();

		$match_engine = penca_wc2026()->match_engine;
		$partidos     = $match_engine->obtener_partidos();

		// Partido seleccionado (por GET o primero de la lista).
		$match_id_sel = (int) ( $_GET['match_id'] ?? 0 );
		$partido_sel  = null;
		$pronosticos  = array();
		$sin_pron     = array();

		if ( $match_id_sel > 0 ) {
			$partido_sel = $match_engine->obtener_partido( $match_id_sel );
		} elseif ( ! empty( $partidos ) ) {
			$partido_sel  = $partidos[0];
			$match_id_sel = (int) $partido_sel->id;
		}

		if ( $partido_sel ) {
			$pronosticos = $this->obtener_pronosticos_con_usuarios( $match_id_sel );
			$sin_pron    = $this->obtener_usuarios_sin_pronostico( $match_id_sel );
		}

		include PENCA_PLUGIN_DIR . 'admin/views/predictions-admin.php';
	}

	/** Logs — solo admin. */
	public function pagina_logs(): void {
		$this->requerir_admin();

		$modulo_filtro = sanitize_text_field( $_GET['modulo'] ?? '' );
		$nivel_filtro  = sanitize_text_field( $_GET['nivel']  ?? '' );
		$logs          = Penca_Helpers::obtener_logs( 100, $modulo_filtro, $nivel_filtro );

		include PENCA_PLUGIN_DIR . 'admin/views/logs.php';
	}

	/** Configuración — solo admin. */
	public function pagina_config(): void {
		$this->requerir_admin();

		if ( isset( $_POST['penca_guardar_config'] ) ) {
			check_admin_referer( 'penca_config_nonce' );
			$this->guardar_configuracion();
			add_settings_error( 'penca-config', 'config-guardada', 'Configuración guardada.', 'success' );
		}

		$config = array(
			'thesportsdb_league_id'  => get_option( 'penca_thesportsdb_league_id', '4429' ),
			'api_primary_key'        => get_option( 'penca_api_primary_key', '' ),
			'pagina_ranking'         => get_option( 'penca_pagina_ranking', 0 ),
			'pagina_perfil'          => get_option( 'penca_pagina_perfil', 0 ),
			'pagina_mis_pronos'      => get_option( 'penca_pagina_mis_pronos', 0 ),
			'pagina_registro'        => get_option( 'penca_pagina_registro', 0 ),
			'redirect_post_registro' => get_option( 'penca_redirect_post_registro', home_url( '/' ) ),
			'tope_usuarios'          => get_option( 'penca_tope_usuarios', 1000 ),
		);

		$paginas_wp = get_pages( array( 'post_status' => 'publish' ) );
		settings_errors( 'penca-config' );

		include PENCA_PLUGIN_DIR . 'admin/views/config.php';
	}

	// =========================================================================
	// CONFIGURACIÓN
	// =========================================================================

	private function guardar_configuracion(): void {
		update_option( 'penca_thesportsdb_league_id',  sanitize_text_field( $_POST['thesportsdb_league_id'] ?? '4429' ) );
		update_option( 'penca_api_primary_key',        sanitize_text_field( $_POST['api_primary_key'] ?? '' ) );
		update_option( 'penca_pagina_ranking',         (int) ( $_POST['pagina_ranking']   ?? 0 ) );
		update_option( 'penca_pagina_perfil',          (int) ( $_POST['pagina_perfil']    ?? 0 ) );
		update_option( 'penca_pagina_mis_pronos',      (int) ( $_POST['pagina_mis_pronos'] ?? 0 ) );
		update_option( 'penca_pagina_registro',        (int) ( $_POST['pagina_registro']  ?? 0 ) );
		update_option( 'penca_redirect_post_registro', esc_url_raw( $_POST['redirect_post_registro'] ?? home_url( '/' ) ) );
		update_option( 'penca_tope_usuarios',          max( 1, (int) ( $_POST['tope_usuarios'] ?? 1000 ) ) );

		Penca_Helpers::log( 'sistema', 'info', 'Configuración actualizada.', array( 'admin_id' => get_current_user_id() ) );
	}

	// =========================================================================
	// HELPERS INTERNOS — PRONÓSTICOS
	// =========================================================================

	/**
	 * Obtiene todos los pronósticos de un partido con datos del usuario.
	 *
	 * @param int $match_id ID del partido.
	 * @return array
	 */
	private function obtener_pronosticos_con_usuarios( int $match_id ): array {
		global $wpdb;

		$tabla_pred  = Penca_Helpers::tabla( 'predictions' );
		$tabla_score = Penca_Helpers::tabla( 'scores' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					p.id              AS prediction_id,
					p.user_id,
					p.goles_local     AS pron_local,
					p.goles_visitante AS pron_visitante,
					p.submitted_at,
					p.updated_at,
					p.is_locked,
					u.display_name,
					u.user_email,
					s.puntos,
					s.tipo_acierto
				FROM {$tabla_pred} p
				INNER JOIN {$wpdb->users} u ON p.user_id = u.ID
				LEFT  JOIN {$tabla_score} s ON s.user_id = p.user_id AND s.match_id = p.match_id
				WHERE p.match_id = %d
				ORDER BY u.display_name ASC",
				$match_id
			)
		) ?? array();
	}

	/**
	 * Devuelve usuarios registrados (subscribers) que no pronosticaron un partido.
	 *
	 * @param int $match_id ID del partido.
	 * @return array
	 */
	private function obtener_usuarios_sin_pronostico( int $match_id ): array {
		global $wpdb;

		$tabla_pred = Penca_Helpers::tabla( 'predictions' );

		// Todos los subscribers que no tienen predicción para este partido.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT u.ID AS user_id, u.display_name, u.user_email
				FROM {$wpdb->users} u
				INNER JOIN {$wpdb->usermeta} um
					ON u.ID = um.user_id
					AND um.meta_key = %s
					AND um.meta_value LIKE %s
				WHERE u.ID NOT IN (
					SELECT user_id FROM {$tabla_pred} WHERE match_id = %d
				)
				ORDER BY u.display_name ASC",
				$wpdb->get_blog_prefix() . 'capabilities',
				'%subscriber%',
				$match_id
			)
		) ?? array();
	}

	// =========================================================================
	// AJAX
	// =========================================================================

	/** Logs en tiempo real — solo admin. */
	public function ajax_obtener_logs(): void {
		check_ajax_referer( 'penca_admin_nonce', 'nonce' );
		if ( ! $this->es_admin() ) { wp_send_json_error( null, 403 ); }

		$modulo = sanitize_text_field( $_GET['modulo'] ?? '' );
		$nivel  = sanitize_text_field( $_GET['nivel']  ?? '' );
		$limite = min( (int) ( $_GET['limite'] ?? 50 ), 200 );
		$logs   = Penca_Helpers::obtener_logs( $limite, $modulo, $nivel );

		$formateados = array_map( function( $log ) {
			return array(
				'id'            => (int) $log->id,
				'modulo'        => $log->modulo,
				'nivel'         => $log->nivel,
				'mensaje'       => esc_html( $log->mensaje ),
				'contexto'      => $log->contexto,
				'user_id'       => $log->user_id,
				'ip_address'    => $log->ip_address,
				'created_at_uy' => Penca_Helpers::formatear_fecha( $log->created_at, 'corto' ),
				'hace_cuanto'   => Penca_Helpers::tiempo_transcurrido( $log->created_at ),
			);
		}, $logs );

		wp_send_json_success( array( 'logs' => $formateados ) );
	}

	/** Stats del dashboard — solo admin. */
	public function ajax_dashboard_stats(): void {
		check_ajax_referer( 'penca_admin_nonce', 'nonce' );
		if ( ! $this->es_admin() ) { wp_send_json_error( null, 403 ); }

		global $wpdb;
		$tm = Penca_Helpers::tabla( 'matches' );
		$tp = Penca_Helpers::tabla( 'predictions' );
		$tc = Penca_Helpers::tabla( 'codes' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		wp_send_json_success( array(
			'total_partidos'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tm}" ),
			'partidos_finalizados' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tm} WHERE status = 'finished'" ),
			'total_pronosticos'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tp}" ),
			'total_usuarios'       => (int) $wpdb->get_var( "SELECT COUNT(DISTINCT user_id) FROM {$tp}" ),
			'codigos_usados'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tc} WHERE status = 'used'" ),
			'estado_api'           => penca_wc2026()->api_sync->obtener_estado(),
			'estado_tablas'        => Penca_Installer::verificar_tablas(),
		) );
		// phpcs:enable
	}

	/** Reactivar API primaria — solo admin. */
	public function ajax_reactivar_api(): void {
		check_ajax_referer( 'penca_admin_nonce', 'nonce' );
		if ( ! $this->es_admin() ) { wp_send_json_error( null, 403 ); }

		penca_wc2026()->api_sync->reactivar_api_primaria();
		wp_send_json_success( array( 'mensaje' => 'API primaria reactivada.' ) );
	}

	/**
	 * Recálculo masivo de puntos — solo admin.
	 *
	 * Recalcula los puntos de TODOS los partidos finalizados.
	 * Útil si hubo un error histórico en un resultado o en la lógica de puntos.
	 * Puede ser lento si hay muchos partidos — se ejecuta de forma síncrona.
	 */
	public function ajax_recalcular_todo(): void {
		check_ajax_referer( 'penca_admin_nonce', 'nonce' );
		if ( ! $this->es_admin() ) { wp_send_json_error( null, 403 ); }

		global $wpdb;
		$tabla = Penca_Helpers::tabla( 'matches' );

		// Obtener IDs de partidos finalizados con resultado.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col(
			"SELECT id FROM {$tabla}
			WHERE status = 'finished'
			AND goles_local IS NOT NULL
			AND goles_visitante IS NOT NULL"
		);

		if ( empty( $ids ) ) {
			wp_send_json_success( array(
				'mensaje'    => 'No hay partidos finalizados para recalcular.',
				'procesados' => 0,
			) );
		}

		$score_engine = penca_wc2026()->score_engine;
		$total_proc   = 0;
		$total_err    = 0;

		foreach ( $ids as $match_id ) {
			$resultado   = $score_engine->calcular_puntos_partido( (int) $match_id );
			$total_proc += $resultado['procesados'];
			$total_err  += $resultado['errores'];
		}

		// Invalidar cache de ranking.
		for ( $i = 1; $i <= 20; $i++ ) {
			delete_transient( 'penca_ranking_cache_page_' . $i );
		}

		Penca_Helpers::log(
			'score-engine', 'info',
			"Recálculo masivo completado. Partidos: " . count( $ids ) . " | Puntos: {$total_proc} | Errores: {$total_err}.",
			array( 'admin_id' => get_current_user_id() )
		);

		wp_send_json_success( array(
			'mensaje'    => sprintf(
				'Recálculo completado. %d partido(s), %d puntuación(es) actualizadas.',
				count( $ids ),
				$total_proc
			),
			'partidos'   => count( $ids ),
			'procesados' => $total_proc,
			'errores'    => $total_err,
		) );
	}

	/** Bloquear código — operador y admin. */
	public function ajax_bloquear_codigo(): void {
		check_ajax_referer( 'penca_admin_nonce', 'nonce' );
		if ( ! $this->es_operador() ) { wp_send_json_error( null, 403 ); }

		$codigo_id = (int) ( $_POST['codigo_id'] ?? 0 );
		$motivo    = sanitize_text_field( $_POST['motivo'] ?? '' );

		if ( $codigo_id <= 0 ) {
			wp_send_json_error( array( 'mensaje' => 'ID inválido.' ) );
		}

		$ok = penca_wc2026()->access_codes->bloquear_codigo( $codigo_id, $motivo );

		$ok
			? wp_send_json_success( array( 'mensaje' => 'Código bloqueado.' ) )
			: wp_send_json_error( array( 'mensaje' => 'No se pudo bloquear (ya fue usado o no existe).' ) );
	}

	/** Pronósticos de un partido (AJAX) — operador y admin. */
	public function ajax_pronosticos_partido(): void {
		check_ajax_referer( 'penca_admin_nonce', 'nonce' );
		if ( ! $this->es_operador() ) { wp_send_json_error( null, 403 ); }

		$match_id = (int) ( $_GET['match_id'] ?? 0 );
		if ( $match_id <= 0 ) { wp_send_json_error( array( 'mensaje' => 'ID inválido.' ) ); }

		$pronosticos = $this->obtener_pronosticos_con_usuarios( $match_id );
		$sin_pron    = $this->obtener_usuarios_sin_pronostico( $match_id );

		wp_send_json_success( array(
			'pronosticos'     => $pronosticos,
			'sin_pronostico'  => $sin_pron,
			'total_con'       => count( $pronosticos ),
			'total_sin'       => count( $sin_pron ),
		) );
	}

	/** Usuarios sin pronóstico en un partido (AJAX) — operador y admin. */
	public function ajax_sin_pronostico_partido(): void {
		check_ajax_referer( 'penca_admin_nonce', 'nonce' );
		if ( ! $this->es_operador() ) { wp_send_json_error( null, 403 ); }

		$match_id = (int) ( $_GET['match_id'] ?? 0 );
		if ( $match_id <= 0 ) { wp_send_json_error( array( 'mensaje' => 'ID inválido.' ) ); }

		wp_send_json_success( array(
			'sin_pronostico' => $this->obtener_usuarios_sin_pronostico( $match_id ),
		) );
	}
}
