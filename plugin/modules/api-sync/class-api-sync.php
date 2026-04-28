<?php
/**
 * Módulo API Sync — Penca WC2026.
 *
 * Responsable de sincronizar datos de partidos desde la API externa
 * hacia la base de datos local. Implementa:
 * - Cron real del servidor (no wp-cron nativo)
 * - API primaria: wc2026api.com (100 req/día plan free)
 * - API fallback: TheSportsDB
 * - Switch automático tras 2 fallos consecutivos
 * - Alertas al admin si ambas APIs fallan
 * - Log de todas las operaciones
 *
 * @package PencaWC2026
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase Penca_Api_Sync.
 */
class Penca_Api_Sync {

	/** Opción que cuenta fallos consecutivos de la API primaria. */
	const OPCION_FALLOS_PRIMARIA = 'penca_api_fallos_primaria';

	/** Opción que registra la API actualmente activa. */
	const OPCION_API_ACTIVA = 'penca_api_activa';

	/** Opción que registra el total de requests usados hoy. */
	const OPCION_REQUESTS_HOY = 'penca_api_requests_hoy';

	/** Opción que registra la fecha del último reset de contador. */
	const OPCION_REQUESTS_FECHA = 'penca_api_requests_fecha';

	/** Hook del cron de WordPress que dispara la sincronización. */
	const HOOK_CRON = 'penca_cron_api_sync';

	/**
	 * Constructor. Registra los hooks de WordPress.
	 */
	public function __construct() {
		add_action( self::HOOK_CRON, array( $this, 'ejecutar_sync' ) );
		add_filter( 'cron_schedules', array( $this, 'agregar_intervalo_cron' ) );
		add_action( 'wp_ajax_penca_sync_manual', array( $this, 'ajax_sync_manual' ) );

		if ( ! wp_next_scheduled( self::HOOK_CRON ) ) {
			wp_schedule_event( time(), 'penca_cada_30_min', self::HOOK_CRON );
		}
	}

	// =========================================================================
	// CRON
	// =========================================================================

	/**
	 * Agrega el intervalo personalizado de cada hora al scheduler de WP.
	 *
	 * @param array $schedules Intervalos existentes.
	 * @return array
	 */
	public function agregar_intervalo_cron( array $schedules ): array {
		$schedules['penca_cada_30_min'] = array(
			'interval' => 30 * MINUTE_IN_SECONDS,
			'display'  => __( 'Cada 30 minutos (Penca WC2026)', 'penca-wc2026' ),
		);
		return $schedules;
	}

	/**
	 * Punto de entrada del cron. Se ejecuta cada hora.
	 *
	 * @return void
	 */
	public function ejecutar_sync(): void {
		Penca_Helpers::log( 'api-sync', 'info', 'Iniciando sincronización programada.' );

		if ( $this->es_api_primaria_activa() && ! $this->tiene_requests_disponibles() ) {
			Penca_Helpers::log(
				'api-sync',
				'warning',
				sprintf(
					'Límite diario alcanzado (%d/%d). Sync omitido.',
					$this->obtener_requests_usados_hoy(),
					PENCA_API_DAILY_LIMIT
				)
			);
			return;
		}

		$resultado = $this->sincronizar();

		if ( $resultado['exito'] ) {
			update_option( self::OPCION_FALLOS_PRIMARIA, 0 );
			Penca_Helpers::log(
				'api-sync',
				'info',
				sprintf(
					'Sync exitoso. API: %s | Partidos: %d | Requests hoy: %d/%d',
					$this->obtener_api_activa(),
					$resultado['partidos_procesados'],
					$this->obtener_requests_usados_hoy(),
					PENCA_API_DAILY_LIMIT
				)
			);
		} else {
			$this->manejar_fallo_api( $resultado['error'] );
		}
	}

	// =========================================================================
	// SINCRONIZACIÓN PRINCIPAL
	// =========================================================================

	/**
	 * Ejecuta la sincronización: llama a la API y guarda en BD.
	 *
	 * @return array{exito: bool, partidos_procesados: int, error: string}
	 */
	public function sincronizar(): array {
		$api_activa = $this->obtener_api_activa();
		$datos      = ( 'primary' === $api_activa )
			? $this->obtener_datos_api_primaria()
			: $this->obtener_datos_api_fallback();

		if ( is_wp_error( $datos ) ) {
			return array(
				'exito'               => false,
				'partidos_procesados' => 0,
				'error'               => $datos->get_error_message(),
			);
		}

		$normalizados = $this->normalizar_datos( $datos, $api_activa );

		if ( empty( $normalizados ) ) {
			return array(
				'exito'               => false,
				'partidos_procesados' => 0,
				'error'               => 'La API devolvió datos vacíos o en formato inesperado.',
			);
		}

		$guardados = $this->guardar_partidos( $normalizados, $api_activa );

		return array(
			'exito'               => true,
			'partidos_procesados' => $guardados,
			'error'               => '',
		);
	}

	// =========================================================================
	// API PRIMARIA — wc2026api.com
	// =========================================================================

	/**
	 * Obtiene el fixture completo desde la API primaria.
	 *
	 * @return array|WP_Error
	 */
	private function obtener_datos_api_primaria() {
		$url = PENCA_API_PRIMARY_URL . '/matches';

		$respuesta = wp_remote_get(
			$url,
			array(
				'timeout'    => 15,
				'user-agent' => 'PencaWC2026/' . PENCA_VERSION . ' (WordPress Plugin)',
				'headers'    => array(
					'Accept'        => 'application/json',
					// API key guardada en Ajustes → Configuración.
					'Authorization' => 'Bearer ' . get_option( 'penca_api_primary_key', '' ),
				),
			)
		);

		$this->incrementar_requests_hoy();

		if ( is_wp_error( $respuesta ) ) {
			return new WP_Error(
				'api_primaria_error_red',
				'Error de red en API primaria: ' . $respuesta->get_error_message()
			);
		}

		$codigo_http = wp_remote_retrieve_response_code( $respuesta );
		$cuerpo      = wp_remote_retrieve_body( $respuesta );

		if ( 200 !== $codigo_http ) {
			return new WP_Error(
				'api_primaria_error_http',
				sprintf( 'API primaria devolvió HTTP %d.', $codigo_http )
			);
		}

		$datos = json_decode( $cuerpo, true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $datos ) ) {
			return new WP_Error( 'api_primaria_error_json', 'JSON inválido de API primaria.' );
		}

		return $datos;
	}

	// =========================================================================
	// API FALLBACK — TheSportsDB
	// =========================================================================

	/**
	 * Obtiene datos desde TheSportsDB como fallback.
	 *
	 * @return array|WP_Error
	 */
	private function obtener_datos_api_fallback() {
		$league_id = get_option( 'penca_thesportsdb_league_id', '4429' );
		$url       = add_query_arg(
			array( 'id' => $league_id, 's' => '2026' ),
			PENCA_API_FALLBACK_URL . '/3/eventsseason.php'
		);

		$respuesta = wp_remote_get(
			$url,
			array(
				'timeout'    => 15,
				'user-agent' => 'PencaWC2026/' . PENCA_VERSION . ' (WordPress Plugin)',
				'headers'    => array(
					'Accept'        => 'application/json',
					// API key guardada en Ajustes → Configuración.
					'Authorization' => 'Bearer ' . get_option( 'penca_api_primary_key', '' ),
				),
			)
		);

		if ( is_wp_error( $respuesta ) ) {
			return new WP_Error(
				'api_fallback_error_red',
				'Error de red en API fallback: ' . $respuesta->get_error_message()
			);
		}

		$codigo_http = wp_remote_retrieve_response_code( $respuesta );
		$cuerpo      = wp_remote_retrieve_body( $respuesta );

		if ( 200 !== $codigo_http ) {
			return new WP_Error( 'api_fallback_error_http', sprintf( 'API fallback HTTP %d.', $codigo_http ) );
		}

		$datos = json_decode( $cuerpo, true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $datos ) ) {
			return new WP_Error( 'api_fallback_error_json', 'JSON inválido de API fallback.' );
		}

		return $datos;
	}

	// =========================================================================
	// NORMALIZACIÓN DE DATOS
	// =========================================================================

	/**
	 * Normaliza los datos crudos de cualquier API al formato interno.
	 *
	 * @param array  $datos_crudos Datos de la API.
	 * @param string $fuente       'primary' o 'fallback'.
	 * @return array Partidos normalizados.
	 */
	private function normalizar_datos( array $datos_crudos, string $fuente ): array {
		return ( 'primary' === $fuente )
			? $this->normalizar_api_primaria( $datos_crudos )
			: $this->normalizar_api_fallback( $datos_crudos );
	}

	/**
	 * Normaliza datos de la API primaria (wc2026api.com).
	 *
	 * Schema real verificado en https://api.wc2026api.com/docs:
	 * {
	 *   id, match_number, round, group_name,
	 *   home_team, away_team,
	 *   home_team_code, away_team_code,   (puede no existir en todos los registros)
	 *   stadium, city, country,
	 *   kickoff_utc,                      (ISO 8601, ej: "2026-06-14T21:00:00.000Z")
	 *   home_score, away_score,           (null si no jugado)
	 *   penalties_home, penalties_away,   (null si no hubo)
	 *   status                            (scheduled|live|finished|postponed|cancelled)
	 * }
	 * La API devuelve un array directo (no wrapeado en clave).
	 *
	 * @param array $datos Datos crudos.
	 * @return array
	 */
	private function normalizar_api_primaria( array $datos ): array {
		$normalizados = array();

		// La API devuelve array directo o dentro de 'data' — soportamos ambos.
		$partidos = isset( $datos[0] ) ? $datos : ( $datos['data'] ?? $datos['matches'] ?? array() );

		if ( ! is_array( $partidos ) ) {
			return array();
		}

		foreach ( $partidos as $partido ) {
			if ( ! is_array( $partido ) ) {
				continue;
			}

			// kickoff_utc viene como ISO 8601 con timezone (ej: "2026-06-14T21:00:00.000Z").
			$kickoff_utc = $this->parsear_kickoff( $partido['kickoff_utc'] ?? '' );
			if ( empty( $kickoff_utc ) ) {
				Penca_Helpers::log( 'api-sync', 'warning', 'Partido sin kickoff_utc válido omitido.', array( 'id' => $partido['id'] ?? '?' ) );
				continue;
			}

			// Penales: presentes solo si el partido se fue a penales.
			$penales_local     = isset( $partido['penalties_home'] ) && null !== $partido['penalties_home']
				? (int) $partido['penalties_home'] : null;
			$penales_visitante = isset( $partido['penalties_away'] ) && null !== $partido['penalties_away']
				? (int) $partido['penalties_away'] : null;
			$fue_a_penales     = ( null !== $penales_local && null !== $penales_visitante ) ? 1 : 0;

			// Goles: null si el partido no empezó o no terminó.
			$goles_local     = isset( $partido['home_score'] ) && null !== $partido['home_score']
				? (int) $partido['home_score'] : null;
			$goles_visitante = isset( $partido['away_score'] ) && null !== $partido['away_score']
				? (int) $partido['away_score'] : null;

			// Status: la API usa "scheduled" — mapeamos al interno "pending".
			$status = $this->mapear_status( strtolower( $partido['status'] ?? 'scheduled' ), 'primary' );

			$normalizados[] = array(
				'api_match_id'      => (string) ( $partido['id'] ?? '' ),
				'fase'              => sanitize_text_field( $partido['round'] ?? '' ),
				'grupo'             => sanitize_text_field( strtoupper( $partido['group_name'] ?? '' ) ),
				'equipo_local'      => sanitize_text_field( $partido['home_team'] ?? '' ),
				'equipo_visitante'  => sanitize_text_field( $partido['away_team'] ?? '' ),
				'codigo_local'      => sanitize_text_field( strtoupper( $partido['home_team_code'] ?? '' ) ),
				'codigo_visitante'  => sanitize_text_field( strtoupper( $partido['away_team_code'] ?? '' ) ),
				'kickoff_utc'       => $kickoff_utc,
				'estadio'           => sanitize_text_field( $partido['stadium'] ?? '' ),
				'ciudad'            => sanitize_text_field( $partido['city'] ?? '' ),
				'pais_sede'         => sanitize_text_field( $partido['country'] ?? '' ),
				'goles_local'       => $goles_local,
				'goles_visitante'   => $goles_visitante,
				'penales_local'     => $penales_local,
				'penales_visitante' => $penales_visitante,
				'fue_a_penales'     => $fue_a_penales,
				'status'            => $status,
				'api_raw_data'      => wp_json_encode( $partido ),
			);
		}

		return $normalizados;
	}

	/**
	 * Normaliza datos de TheSportsDB.
	 *
	 * @param array $datos Datos crudos.
	 * @return array
	 */
	private function normalizar_api_fallback( array $datos ): array {
		$normalizados = array();
		$eventos      = $datos['events'] ?? array();

		if ( ! is_array( $eventos ) ) {
			return array();
		}

		foreach ( $eventos as $evento ) {
			if ( ! is_array( $evento ) ) {
				continue;
			}

			$kickoff_utc = $this->parsear_kickoff(
				( $evento['dateEvent'] ?? '' ) . ' ' . ( $evento['strTime'] ?? '00:00:00' )
			);

			if ( empty( $kickoff_utc ) ) {
				continue;
			}

			$status          = $this->mapear_status( strtolower( $evento['strStatus'] ?? 'not started' ), 'fallback' );
			$goles_local     = ( 'finished' === $status && isset( $evento['intHomeScore'] ) ) ? (int) $evento['intHomeScore'] : null;
			$goles_visitante = ( 'finished' === $status && isset( $evento['intAwayScore'] ) ) ? (int) $evento['intAwayScore'] : null;

			$normalizados[] = array(
				'api_match_id'      => 'tsdb_' . ( $evento['idEvent'] ?? '' ),
				'fase'              => sanitize_text_field( $evento['strRound'] ?? '' ),
				'grupo'             => '',
				'equipo_local'      => sanitize_text_field( $evento['strHomeTeam'] ?? '' ),
				'equipo_visitante'  => sanitize_text_field( $evento['strAwayTeam'] ?? '' ),
				'codigo_local'      => '',
				'codigo_visitante'  => '',
				'kickoff_utc'       => $kickoff_utc,
				'estadio'           => sanitize_text_field( $evento['strVenue'] ?? '' ),
				'ciudad'            => sanitize_text_field( $evento['strCity'] ?? '' ),
				'pais_sede'         => sanitize_text_field( $evento['strCountry'] ?? '' ),
				'goles_local'       => $goles_local,
				'goles_visitante'   => $goles_visitante,
				'penales_local'     => null,
				'penales_visitante' => null,
				'fue_a_penales'     => 0,
				'status'            => $status,
				'api_raw_data'      => wp_json_encode( $evento ),
			);
		}

		return $normalizados;
	}

	// =========================================================================
	// PERSISTENCIA EN BASE DE DATOS
	// =========================================================================

	/**
	 * Inserta o actualiza partidos en wp_wc_matches usando INSERT ... ON DUPLICATE KEY UPDATE.
	 *
	 * No toca predictions_locked si ya está en 1.
	 * No toca status si override_manual = 1.
	 *
	 * @param array  $partidos Partidos normalizados.
	 * @param string $fuente   'primary' o 'fallback'.
	 * @return int Cantidad procesada.
	 */
	private function guardar_partidos( array $partidos, string $fuente ): int {
		global $wpdb;

		$tabla      = Penca_Helpers::tabla( 'matches' );
		$procesados = 0;
		$ahora      = Penca_Helpers::ahora_utc_sql();

		foreach ( $partidos as $partido ) {
			if ( empty( $partido['api_match_id'] ) || empty( $partido['equipo_local'] ) ) {
				continue;
			}

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$tabla}
						(api_match_id, api_source, fase, grupo,
						 equipo_local, equipo_visitante, codigo_local, codigo_visitante,
						 kickoff_utc, estadio, ciudad, pais_sede,
						 goles_local, goles_visitante, penales_local, penales_visitante,
						 fue_a_penales, status, api_raw_data, last_sync_utc, created_at, updated_at)
					VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%d,%s,%s,%s,%s,%s)
					ON DUPLICATE KEY UPDATE
						api_source        = VALUES(api_source),
						fase              = VALUES(fase),
						grupo             = VALUES(grupo),
						equipo_local      = VALUES(equipo_local),
						equipo_visitante  = VALUES(equipo_visitante),
						codigo_local      = VALUES(codigo_local),
						codigo_visitante  = VALUES(codigo_visitante),
						kickoff_utc       = VALUES(kickoff_utc),
						estadio           = VALUES(estadio),
						ciudad            = VALUES(ciudad),
						pais_sede         = VALUES(pais_sede),
						goles_local       = VALUES(goles_local),
						goles_visitante   = VALUES(goles_visitante),
						penales_local     = VALUES(penales_local),
						penales_visitante = VALUES(penales_visitante),
						fue_a_penales     = VALUES(fue_a_penales),
						status            = IF(override_manual = 1, status, VALUES(status)),
						api_raw_data      = VALUES(api_raw_data),
						last_sync_utc     = VALUES(last_sync_utc),
						updated_at        = VALUES(updated_at)",
					$partido['api_match_id'], $fuente,
					$partido['fase'], $partido['grupo'],
					$partido['equipo_local'], $partido['equipo_visitante'],
					$partido['codigo_local'], $partido['codigo_visitante'],
					$partido['kickoff_utc'], $partido['estadio'],
					$partido['ciudad'], $partido['pais_sede'],
					$partido['goles_local'], $partido['goles_visitante'],
					$partido['penales_local'], $partido['penales_visitante'],
					$partido['fue_a_penales'], $partido['status'],
					$partido['api_raw_data'], $ahora, $ahora, $ahora
				)
			);
			// phpcs:enable

			if ( $wpdb->last_error ) {
				Penca_Helpers::log(
					'api-sync', 'error',
					'Error al guardar partido: ' . $partido['api_match_id'],
					array( 'db_error' => $wpdb->last_error )
				);
			} else {
				++$procesados;
			}
		}

		return $procesados;
	}

	// =========================================================================
	// GESTIÓN DE FALLOS Y SWITCH DE API
	// =========================================================================

	/**
	 * Maneja un fallo de la API activa.
	 *
	 * @param string $motivo Descripción del error.
	 * @return void
	 */
	private function manejar_fallo_api( string $motivo ): void {
		$api_activa = $this->obtener_api_activa();

		Penca_Helpers::log( 'api-sync', 'error', "Fallo en API {$api_activa}: {$motivo}" );

		if ( 'primary' === $api_activa ) {
			$fallos = (int) get_option( self::OPCION_FALLOS_PRIMARIA, 0 ) + 1;
			update_option( self::OPCION_FALLOS_PRIMARIA, $fallos );

			if ( $fallos >= PENCA_API_FAIL_THRESHOLD ) {
				update_option( self::OPCION_API_ACTIVA, 'fallback' );
				update_option( self::OPCION_FALLOS_PRIMARIA, 0 );

				Penca_Helpers::log(
					'api-sync', 'warning',
					"API primaria falló {$fallos} veces. Switch automático a fallback."
				);

				Penca_Helpers::alertar_admin(
					'Switch automático a API fallback',
					"La API primaria falló {$fallos} veces consecutivas.\nÚltimo error: {$motivo}",
					array( 'fallos' => $fallos, 'error' => $motivo )
				);

				$resultado_fallback = $this->sincronizar();
				if ( ! $resultado_fallback['exito'] ) {
					$this->manejar_ambas_apis_caidas( $motivo, $resultado_fallback['error'] );
				}
			}
		} else {
			$this->manejar_ambas_apis_caidas( 'API primaria previamente caída', $motivo );
		}
	}

	/**
	 * Maneja el caso en que ambas APIs fallan.
	 *
	 * @param string $error_primaria Error de API primaria.
	 * @param string $error_fallback Error de API fallback.
	 * @return void
	 */
	private function manejar_ambas_apis_caidas( string $error_primaria, string $error_fallback ): void {
		Penca_Helpers::alerta_critica(
			'api-sync',
			'AMBAS APIS CAÍDAS. Se mantienen los datos del último sync exitoso.',
			array(
				'error_primaria' => $error_primaria,
				'error_fallback' => $error_fallback,
				'ultimo_sync'    => $this->obtener_ultimo_sync_exitoso(),
			)
		);
	}

	// =========================================================================
	// AJAX — SYNC MANUAL DESDE ADMIN
	// =========================================================================

	/**
	 * Handler AJAX para sincronización manual.
	 *
	 * @return void
	 */
	public function ajax_sync_manual(): void {
		check_ajax_referer( 'penca_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'mensaje' => 'Permisos insuficientes.' ), 403 );
		}

		Penca_Helpers::log( 'api-sync', 'info', 'Sincronización manual iniciada por admin.' );

		$resultado = $this->sincronizar();

		if ( $resultado['exito'] ) {
			wp_send_json_success( array(
				'mensaje'             => "Sync exitoso. {$resultado['partidos_procesados']} partidos procesados.",
				'partidos_procesados' => $resultado['partidos_procesados'],
				'api_activa'          => $this->obtener_api_activa(),
				'requests_hoy'        => $this->obtener_requests_usados_hoy(),
			) );
		} else {
			wp_send_json_error( array( 'mensaje' => 'Error: ' . $resultado['error'] ) );
		}
	}

	// =========================================================================
	// CONTROL DE REQUESTS DIARIOS
	// =========================================================================

	/** @return bool */
	private function tiene_requests_disponibles(): bool {
		$this->verificar_reset_diario();
		return $this->obtener_requests_usados_hoy() < PENCA_API_DAILY_LIMIT;
	}

	/** @return void */
	private function incrementar_requests_hoy(): void {
		$this->verificar_reset_diario();
		update_option( self::OPCION_REQUESTS_HOY, $this->obtener_requests_usados_hoy() + 1 );
	}

	/** @return int */
	public function obtener_requests_usados_hoy(): int {
		return (int) get_option( self::OPCION_REQUESTS_HOY, 0 );
	}

	/** @return void */
	private function verificar_reset_diario(): void {
		$fecha_hoy = gmdate( 'Y-m-d' );
		if ( get_option( self::OPCION_REQUESTS_FECHA, '' ) !== $fecha_hoy ) {
			update_option( self::OPCION_REQUESTS_HOY, 0 );
			update_option( self::OPCION_REQUESTS_FECHA, $fecha_hoy );
		}
	}

	// =========================================================================
	// UTILIDADES PÚBLICAS
	// =========================================================================

	/** @return string 'primary' o 'fallback'. */
	public function obtener_api_activa(): string {
		return get_option( self::OPCION_API_ACTIVA, 'primary' );
	}

	/** @return bool */
	private function es_api_primaria_activa(): bool {
		return 'primary' === $this->obtener_api_activa();
	}

	/** @return void */
	public function reactivar_api_primaria(): void {
		update_option( self::OPCION_API_ACTIVA, 'primary' );
		update_option( self::OPCION_FALLOS_PRIMARIA, 0 );
		Penca_Helpers::log( 'api-sync', 'info', 'API primaria reactivada manualmente por admin.' );
	}

	/** @return string Timestamp legible del último sync (hora Uruguay) o 'Nunca'. */
	public function obtener_ultimo_sync_exitoso(): string {
		global $wpdb;
		$tabla = Penca_Helpers::tabla( 'matches' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ultimo = $wpdb->get_var( "SELECT MAX(last_sync_utc) FROM {$tabla}" );
		return empty( $ultimo ) ? 'Nunca' : Penca_Helpers::formatear_fecha( $ultimo, 'completo' );
	}

	/**
	 * Parsea un string de kickoff de la API a formato MySQL en UTC.
	 *
	 * @param string $kickoff_raw String crudo de la API.
	 * @return string Datetime MySQL o '' si es inválido.
	 */
	private function parsear_kickoff( string $kickoff_raw ): string {
		$kickoff_raw = trim( $kickoff_raw );
		if ( empty( $kickoff_raw ) ) {
			return '';
		}
		try {
			$dt = new DateTimeImmutable( $kickoff_raw, new DateTimeZone( 'UTC' ) );
			return $dt->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $e ) {
			return '';
		}
	}

	/**
	 * Mapea status de la API al status interno del plugin.
	 *
	 * @param string $status_raw Status crudo.
	 * @param string $fuente     'primary' o 'fallback'.
	 * @return string Status interno: pending|live|finished|postponed|cancelled.
	 */
	private function mapear_status( string $status_raw, string $fuente ): string {
		$mapa_primary  = array(
			'finished' => 'finished', 'complete' => 'finished', 'ft' => 'finished',
			'pending'  => 'pending',  'scheduled' => 'pending',  'ns' => 'pending',
			'live'     => 'live',     'in_play'   => 'live',
			'postponed' => 'postponed',
			'cancelled' => 'cancelled', 'canceled' => 'cancelled', 'abandoned' => 'cancelled',
		);
		$mapa_fallback = array(
			'match finished' => 'finished', 'not started' => 'pending',
			'in progress'    => 'live',     'postponed'   => 'postponed',
			'cancelled'      => 'cancelled', 'canceled'   => 'cancelled',
		);
		$mapa = ( 'primary' === $fuente ) ? $mapa_primary : $mapa_fallback;
		return $mapa[ $status_raw ] ?? 'pending';
	}

	/**
	 * Estado resumido del módulo para el dashboard admin.
	 *
	 * @return array
	 */
	public function obtener_estado(): array {
		return array(
			'api_activa'      => $this->obtener_api_activa(),
			'requests_hoy'    => $this->obtener_requests_usados_hoy(),
			'limite_diario'   => PENCA_API_DAILY_LIMIT,
			'fallos_primaria' => (int) get_option( self::OPCION_FALLOS_PRIMARIA, 0 ),
			'ultimo_sync'     => $this->obtener_ultimo_sync_exitoso(),
			'proximo_sync'    => wp_next_scheduled( self::HOOK_CRON )
				? Penca_Helpers::formatear_fecha( (int) wp_next_scheduled( self::HOOK_CRON ), 'corto' )
				: 'No programado',
		);
	}
}
