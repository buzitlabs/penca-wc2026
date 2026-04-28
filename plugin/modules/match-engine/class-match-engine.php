<?php
/**
 * Módulo Match Engine — Penca WC2026.
 *
 * Gestiona el fixture de partidos expuesto al resto del sistema.
 * Responsabilidades:
 * - Leer partidos desde la BD local (nunca desde la API directamente)
 * - Cierre automático de pronósticos antes del kickoff
 * - Override manual de resultados por parte del admin
 * - Consultas de partidos para el frontend
 *
 * El cierre de pronósticos tiene triple barrera:
 * 1. Frontend: botón deshabilitado / mensaje de partido cerrado
 * 2. Backend: validación en prediction-engine antes de guardar
 * 3. BD: campo predictions_locked = 1 en wp_wc_matches (esta clase lo gestiona)
 *
 * @package PencaWC2026
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase Penca_Match_Engine.
 */
class Penca_Match_Engine {

	/** Minutos antes del kickoff en que se cierran los pronósticos. */
	const MINUTOS_CIERRE_ANTES_KICKOFF = 0;

	/** Hook de cron para verificar cierres pendientes. */
	const HOOK_CRON_CIERRE = 'penca_cron_cierre_predicciones';

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Cron para verificar y cerrar pronósticos próximos a iniciar.
		add_action( self::HOOK_CRON_CIERRE, array( $this, 'cerrar_predicciones_pendientes' ) );

		// Programar cron de cierre cada 5 minutos (usa intervalo de WP).
		// El cron real del servidor debe llamar a wp-cron.php más seguido.
		add_filter( 'cron_schedules', array( $this, 'agregar_intervalo_cron' ) );

		if ( ! wp_next_scheduled( self::HOOK_CRON_CIERRE ) ) {
			wp_schedule_event( time(), 'penca_cada_5_minutos', self::HOOK_CRON_CIERRE );
		}

		// AJAX: override manual de resultado por admin.
		add_action( 'wp_ajax_penca_override_resultado', array( $this, 'ajax_override_resultado' ) );

		// AJAX: obtener partidos para el frontend (no requiere login).
		add_action( 'wp_ajax_penca_obtener_partidos', array( $this, 'ajax_obtener_partidos' ) );
		add_action( 'wp_ajax_nopriv_penca_obtener_partidos', array( $this, 'ajax_obtener_partidos' ) );
	}

	// =========================================================================
	// CRON
	// =========================================================================

	/**
	 * Agrega intervalo de 5 minutos para el cron de cierre.
	 *
	 * @param array $schedules Intervalos existentes.
	 * @return array
	 */
	public function agregar_intervalo_cron( array $schedules ): array {
		if ( ! isset( $schedules['penca_cada_5_minutos'] ) ) {
			$schedules['penca_cada_5_minutos'] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Cada 5 minutos (Penca WC2026)', 'penca-wc2026' ),
			);
		}
		return $schedules;
	}

	/**
	 * Cierra pronósticos de partidos que están por empezar o ya empezaron.
	 *
	 * Busca partidos con predictions_locked = 0 cuyo kickoff_utc ya pasó
	 * (o está dentro del margen de MINUTOS_CIERRE_ANTES_KICKOFF).
	 * Los marca como locked = 1.
	 *
	 * @return int Cantidad de partidos cerrados.
	 */
	public function cerrar_predicciones_pendientes(): int {
		global $wpdb;

		$tabla = Penca_Helpers::tabla( 'matches' );
		$ahora = Penca_Helpers::ahora_utc_sql();

		// Obtener IDs de partidos que deben cerrarse.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$tabla}
				WHERE predictions_locked = 0
				AND status NOT IN ('cancelled', 'postponed')
				AND kickoff_utc <= DATE_ADD(%s, INTERVAL %d MINUTE)",
				$ahora,
				self::MINUTOS_CIERRE_ANTES_KICKOFF
			)
		);

		if ( empty( $ids ) ) {
			return 0;
		}

		$ids_int    = array_map( 'intval', $ids );
		$ids_sql    = implode( ',', $ids_int );
		$cerrados   = 0;

		foreach ( $ids_int as $match_id ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$resultado = $wpdb->update(
				$tabla,
				array(
					'predictions_locked' => 1,
					'updated_at'         => $ahora,
				),
				array( 'id' => $match_id ),
				array( '%d', '%s' ),
				array( '%d' )
			);
			// phpcs:enable

			if ( false !== $resultado ) {
				++$cerrados;
				Penca_Helpers::log(
					'match-engine',
					'info',
					"Pronósticos cerrados para partido ID: {$match_id}",
					array( 'match_id' => $match_id, 'kickoff' => $ahora )
				);
			}
		}

		if ( $cerrados > 0 ) {
			Penca_Helpers::log(
				'match-engine',
				'info',
				"Cierre masivo completado: {$cerrados} partido(s) bloqueado(s)."
			);
		}

		return $cerrados;
	}

	// =========================================================================
	// CONSULTAS DE PARTIDOS
	// =========================================================================

	/**
	 * Obtiene todos los partidos agrupados por fase y grupo.
	 *
	 * @param array $filtros Filtros opcionales: ['status' => 'pending', 'fase' => 'grupo'].
	 * @return array Partidos ordenados por kickoff_utc ASC.
	 */
	public function obtener_partidos( array $filtros = array() ): array {
		global $wpdb;

		$tabla  = Penca_Helpers::tabla( 'matches' );
		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $filtros['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = sanitize_text_field( $filtros['status'] );
		}

		if ( ! empty( $filtros['fase'] ) ) {
			$where[]  = 'fase = %s';
			$params[] = sanitize_text_field( $filtros['fase'] );
		}

		if ( ! empty( $filtros['grupo'] ) ) {
			$where[]  = 'grupo = %s';
			$params[] = sanitize_text_field( strtoupper( $filtros['grupo'] ) );
		}

		$where_sql = implode( ' AND ', $where );

		if ( ! empty( $params ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$partidos = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$tabla} WHERE {$where_sql} ORDER BY kickoff_utc ASC",
					...$params
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$partidos = $wpdb->get_results( "SELECT * FROM {$tabla} WHERE {$where_sql} ORDER BY kickoff_utc ASC" );
		}

		return $partidos ?? array();
	}

	/**
	 * Obtiene un partido por su ID interno.
	 *
	 * @param int $match_id ID del partido en wp_wc_matches.
	 * @return object|null Objeto con los datos del partido, o null si no existe.
	 */
	public function obtener_partido( int $match_id ): ?object {
		global $wpdb;

		$tabla = Penca_Helpers::tabla( 'matches' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$tabla} WHERE id = %d", $match_id )
		);
	}

	/**
	 * Obtiene los partidos próximos (con kickoff en las próximas 48 horas).
	 *
	 * @return array
	 */
	public function obtener_proximos_partidos(): array {
		global $wpdb;

		$tabla = Penca_Helpers::tabla( 'matches' );
		$ahora = Penca_Helpers::ahora_utc_sql();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$tabla}
				WHERE kickoff_utc BETWEEN %s AND DATE_ADD(%s, INTERVAL 48 HOUR)
				AND status IN ('pending', 'live')
				ORDER BY kickoff_utc ASC",
				$ahora,
				$ahora
			)
		) ?? array();
	}

	/**
	 * Verifica si un partido tiene los pronósticos abiertos.
	 *
	 * Consulta directamente la BD (tercera barrera de validación).
	 *
	 * @param int $match_id ID del partido.
	 * @return bool true si se puede pronosticar.
	 */
	public function esta_abierto_para_pronosticos( int $match_id ): bool {
		global $wpdb;

		$tabla = Penca_Helpers::tabla( 'matches' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$locked = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT predictions_locked FROM {$tabla} WHERE id = %d",
				$match_id
			)
		);

		// Si el partido no existe, no se puede pronosticar.
		if ( null === $locked ) {
			return false;
		}

		return '0' === $locked || 0 === (int) $locked;
	}

	/**
	 * Obtiene el fixture completo con datos formateados para el frontend.
	 *
	 * Agrega campos calculados: kickoff en hora Uruguay, estado de apertura, etc.
	 *
	 * @return array Partidos con campos adicionales para el frontend.
	 */
	public function obtener_fixture_frontend(): array {
		$partidos = $this->obtener_partidos();
		$fixture  = array();

		foreach ( $partidos as $partido ) {
			$fixture[] = $this->formatear_partido_para_frontend( $partido );
		}

		return $fixture;
	}

	/**
	 * Agrega campos calculados a un partido para su presentación en el frontend.
	 *
	 * @param object $partido Objeto partido de la BD.
	 * @return array Partido con campos adicionales.
	 */
	public function formatear_partido_para_frontend( object $partido ): array {
		return array(
			'id'                  => (int) $partido->id,
			'fase'                => $partido->fase,
			'grupo'               => $partido->grupo,
			'equipo_local'        => $partido->equipo_local,
			'equipo_visitante'    => $partido->equipo_visitante,
			// Código ISO: usar el de la API, o resolver desde el nombre del equipo.
			'codigo_local'        => ! empty( $partido->codigo_local )
				? $partido->codigo_local
				: Penca_Helpers::codigo_pais_desde_nombre( $partido->equipo_local ),
			'codigo_visitante'    => ! empty( $partido->codigo_visitante )
				? $partido->codigo_visitante
				: Penca_Helpers::codigo_pais_desde_nombre( $partido->equipo_visitante ),
			'kickoff_utc'         => $partido->kickoff_utc,
			'kickoff_uy'          => Penca_Helpers::formatear_fecha( $partido->kickoff_utc, 'corto' ),
			'kickoff_uy_completo' => Penca_Helpers::formatear_fecha( $partido->kickoff_utc, 'completo' ),
			'kickoff_iso'         => Penca_Helpers::formatear_fecha( $partido->kickoff_utc, 'iso' ),
			'estadio'             => $partido->estadio,
			'ciudad'              => $partido->ciudad,
			'pais_sede'           => $partido->pais_sede,
			'goles_local'         => isset( $partido->goles_local ) ? (int) $partido->goles_local : null,
			'goles_visitante'     => isset( $partido->goles_visitante ) ? (int) $partido->goles_visitante : null,
			'penales_local'       => isset( $partido->penales_local ) ? (int) $partido->penales_local : null,
			'penales_visitante'   => isset( $partido->penales_visitante ) ? (int) $partido->penales_visitante : null,
			'fue_a_penales'       => (bool) $partido->fue_a_penales,
			'status'              => $partido->status,
			'pronosticos_abiertos' => ! (bool) $partido->predictions_locked,
			'override_manual'     => (bool) $partido->override_manual,
		);
	}

	// =========================================================================
	// OVERRIDE MANUAL POR ADMIN
	// =========================================================================

	/**
	 * Permite al admin editar manualmente el resultado de un partido.
	 *
	 * Útil cuando la API falla o devuelve datos incorrectos.
	 * Marca el partido con override_manual = 1 para que futuros syncs
	 * no sobreescriban el resultado.
	 *
	 * Después de guardar el resultado, dispara el recálculo de puntos.
	 *
	 * @param int      $match_id         ID del partido.
	 * @param int      $goles_local      Goles del equipo local al 90'.
	 * @param int      $goles_visitante  Goles del equipo visitante al 90'.
	 * @param int|null $penales_local    Goles en penales del equipo local (null si no hubo).
	 * @param int|null $penales_visitante Goles en penales del equipo visitante.
	 * @param string   $status           Nuevo status del partido.
	 * @return bool true si se guardó correctamente.
	 */
	public function override_resultado(
		int $match_id,
		int $goles_local,
		int $goles_visitante,
		?int $penales_local = null,
		?int $penales_visitante = null,
		string $status = 'finished'
	): bool {
		global $wpdb;

		$tabla           = Penca_Helpers::tabla( 'matches' );
		$fue_a_penales   = ( null !== $penales_local && null !== $penales_visitante ) ? 1 : 0;
		$admin_id        = get_current_user_id();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$resultado = $wpdb->update(
			$tabla,
			array(
				'goles_local'       => $goles_local,
				'goles_visitante'   => $goles_visitante,
				'penales_local'     => $penales_local,
				'penales_visitante' => $penales_visitante,
				'fue_a_penales'     => $fue_a_penales,
				'status'            => sanitize_text_field( $status ),
				'override_manual'   => 1,
				'predictions_locked' => 1,
				'updated_at'        => Penca_Helpers::ahora_utc_sql(),
			),
			array( 'id' => $match_id ),
			array( '%d', '%d', '%d', '%d', '%d', '%s', '%d', '%d', '%s' ),
			array( '%d' )
		);

		if ( false === $resultado ) {
			Penca_Helpers::log(
				'match-engine', 'error',
				"Error al guardar override del partido {$match_id}.",
				array( 'db_error' => $wpdb->last_error, 'admin_id' => $admin_id )
			);
			return false;
		}

		Penca_Helpers::log(
			'match-engine', 'info',
			"Override manual guardado para partido {$match_id}.",
			array(
				'admin_id'        => $admin_id,
				'goles_local'     => $goles_local,
				'goles_visitante' => $goles_visitante,
				'penales_local'   => $penales_local,
				'penales_visitante' => $penales_visitante,
				'status'          => $status,
			)
		);

		// Disparar recálculo de puntos para este partido.
		do_action( 'penca_partido_finalizado', $match_id );

		return true;
	}

	// =========================================================================
	// AJAX
	// =========================================================================

	/**
	 * Handler AJAX para override manual de resultado.
	 *
	 * @return void
	 */
	public function ajax_override_resultado(): void {
		check_ajax_referer( 'penca_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'mensaje' => 'Permisos insuficientes.' ), 403 );
		}

		$match_id         = (int) ( $_POST['match_id'] ?? 0 );
		$goles_local      = (int) ( $_POST['goles_local'] ?? 0 );
		$goles_visitante  = (int) ( $_POST['goles_visitante'] ?? 0 );
		$penales_local    = isset( $_POST['penales_local'] ) && '' !== $_POST['penales_local']
			? (int) $_POST['penales_local'] : null;
		$penales_visitante = isset( $_POST['penales_visitante'] ) && '' !== $_POST['penales_visitante']
			? (int) $_POST['penales_visitante'] : null;
		$status           = sanitize_text_field( $_POST['status'] ?? 'finished' );

		if ( $match_id <= 0 ) {
			wp_send_json_error( array( 'mensaje' => 'ID de partido inválido.' ) );
		}

		$ok = $this->override_resultado(
			$match_id,
			$goles_local,
			$goles_visitante,
			$penales_local,
			$penales_visitante,
			$status
		);

		if ( $ok ) {
			wp_send_json_success( array( 'mensaje' => 'Resultado guardado. Puntos recalculados.' ) );
		} else {
			wp_send_json_error( array( 'mensaje' => 'Error al guardar el resultado.' ) );
		}
	}

	/**
	 * Handler AJAX para obtener partidos (público, sin login).
	 *
	 * @return void
	 */
	public function ajax_obtener_partidos(): void {
		// Nonce opcional en el frontend público.
		$filtros = array(
			'status' => sanitize_text_field( $_GET['status'] ?? '' ),
			'fase'   => sanitize_text_field( $_GET['fase'] ?? '' ),
			'grupo'  => sanitize_text_field( $_GET['grupo'] ?? '' ),
		);

		$partidos  = $this->obtener_partidos( array_filter( $filtros ) );
		$formateados = array_map( array( $this, 'formatear_partido_para_frontend' ), $partidos );

		wp_send_json_success( array( 'partidos' => $formateados ) );
	}
}
