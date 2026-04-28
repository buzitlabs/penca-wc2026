<?php
/**
 * Módulo Prediction Engine — Penca WC2026.
 *
 * Gestiona el ciclo completo de pronósticos:
 * - Crear pronóstico (con triple validación de cierre)
 * - Actualizar pronóstico (solo si el partido sigue abierto)
 * - Leer pronósticos de un usuario
 * - Validar estado antes de cada operación
 *
 * Triple validación del cierre:
 * 1. Frontend: botón deshabilitado (JavaScript)
 * 2. Este módulo: verificación en backend antes de guardar en BD
 * 3. BD: campo predictions_locked consultado directo al guardar
 *
 * @package PencaWC2026
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase Penca_Prediction_Engine.
 */
class Penca_Prediction_Engine {

	/**
	 * Constructor.
	 */
	public function __construct() {
		// AJAX para guardar pronóstico (requiere login).
		add_action( 'wp_ajax_penca_guardar_pronostico', array( $this, 'ajax_guardar_pronostico' ) );

		// AJAX para obtener pronósticos del usuario actual.
		add_action( 'wp_ajax_penca_obtener_mis_pronosticos', array( $this, 'ajax_obtener_mis_pronosticos' ) );
	}

	// =========================================================================
	// CREAR / ACTUALIZAR PRONÓSTICO
	// =========================================================================

	/**
	 * Guarda un pronóstico de usuario para un partido.
	 *
	 * Si el usuario ya tiene pronóstico para ese partido, lo actualiza.
	 * Si no tiene, lo crea.
	 *
	 * Validaciones (triple barrera):
	 * 1. Usuario autenticado.
	 * 2. Partido existe y tiene predictions_locked = 0 (consulta directa a BD).
	 * 3. INSERT usa cláusula que verifica predictions_locked en el momento de escribir.
	 *
	 * @param int $user_id         ID del usuario de WordPress.
	 * @param int $match_id        ID del partido en wp_wc_matches.
	 * @param int $goles_local     Goles pronosticados para el local.
	 * @param int $goles_visitante Goles pronosticados para el visitante.
	 * @return array{exito: bool, mensaje: string, prediction_id: int}
	 */
	public function guardar_pronostico(
		int $user_id,
		int $match_id,
		int $goles_local,
		int $goles_visitante
	): array {

		// --- Validación 1: usuario existe ---
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return array(
				'exito'         => false,
				'mensaje'       => 'Usuario no válido.',
				'prediction_id' => 0,
			);
		}

		// --- Validación 2: valores numéricos razonables ---
		if ( $goles_local < 0 || $goles_local > 20 || $goles_visitante < 0 || $goles_visitante > 20 ) {
			return array(
				'exito'         => false,
				'mensaje'       => 'Valores de goles fuera de rango.',
				'prediction_id' => 0,
			);
		}

		// --- Validación 3: partido existe y pronósticos están abiertos (BACKEND) ---
		$match_engine = penca_wc2026()->match_engine;
		if ( ! $match_engine->esta_abierto_para_pronosticos( $match_id ) ) {
			Penca_Helpers::log(
				'prediction-engine', 'warning',
				"Intento de pronóstico bloqueado. Partido: {$match_id} | Usuario: {$user_id}",
				array( 'match_id' => $match_id, 'user_id' => $user_id )
			);
			return array(
				'exito'         => false,
				'mensaje'       => 'Los pronósticos para este partido ya están cerrados.',
				'prediction_id' => 0,
			);
		}

		// --- Persistencia: INSERT con verificación en BD ---
		$resultado = $this->persistir_pronostico( $user_id, $match_id, $goles_local, $goles_visitante );

		if ( ! $resultado['exito'] ) {
			return $resultado;
		}

		Penca_Helpers::log(
			'prediction-engine', 'info',
			"Pronóstico guardado. Usuario: {$user_id} | Partido: {$match_id} | {$goles_local}-{$goles_visitante}",
			array(
				'user_id'         => $user_id,
				'match_id'        => $match_id,
				'goles_local'     => $goles_local,
				'goles_visitante' => $goles_visitante,
			)
		);

		return $resultado;
	}

	/**
	 * Persiste el pronóstico en la BD.
	 *
	 * Usa INSERT ... ON DUPLICATE KEY UPDATE.
	 * La clave única (user_id, match_id) garantiza un solo pronóstico por par.
	 *
	 * BARRERA BD: Solo actualiza si el partido sigue abierto.
	 * Si predictions_locked cambió a 1 entre la validación backend y el INSERT,
	 * la consulta no actualiza nada y se detecta por rows_affected = 0.
	 *
	 * @param int $user_id         ID del usuario.
	 * @param int $match_id        ID del partido.
	 * @param int $goles_local     Pronóstico local.
	 * @param int $goles_visitante Pronóstico visitante.
	 * @return array{exito: bool, mensaje: string, prediction_id: int}
	 */
	private function persistir_pronostico(
		int $user_id,
		int $match_id,
		int $goles_local,
		int $goles_visitante
	): array {
		global $wpdb;

		$tabla_pred  = Penca_Helpers::tabla( 'predictions' );
		$tabla_match = Penca_Helpers::tabla( 'matches' );
		$ahora       = Penca_Helpers::ahora_utc_sql();
		$ip          = Penca_Helpers::obtener_ip_cliente();
		$user_agent  = sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' );

		/**
		 * INSERT con subquery de validación BD.
		 *
		 * El INSERT solo se ejecuta si el partido tiene predictions_locked = 0.
		 * Esto es la tercera barrera: si entre la validación PHP y el INSERT
		 * alguien cerró el partido, la escritura falla silenciosamente.
		 *
		 * En el ON DUPLICATE KEY UPDATE también se verifica que el partido
		 * siga abierto para no actualizar pronósticos de partidos cerrados.
		 */
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$tabla_pred}
					(user_id, match_id, goles_local, goles_visitante, submitted_at, updated_at, ip_address, user_agent)
				SELECT %d, %d, %d, %d, %s, %s, %s, %s
				FROM {$tabla_match}
				WHERE id = %d AND predictions_locked = 0
				ON DUPLICATE KEY UPDATE
					goles_local     = IF(
						(SELECT predictions_locked FROM {$tabla_match} WHERE id = %d) = 0,
						VALUES(goles_local),
						goles_local
					),
					goles_visitante = IF(
						(SELECT predictions_locked FROM {$tabla_match} WHERE id = %d) = 0,
						VALUES(goles_visitante),
						goles_visitante
					),
					updated_at      = IF(
						(SELECT predictions_locked FROM {$tabla_match} WHERE id = %d) = 0,
						VALUES(updated_at),
						updated_at
					),
					ip_address      = VALUES(ip_address),
					user_agent      = VALUES(user_agent)",
				$user_id,
				$match_id,
				$goles_local,
				$goles_visitante,
				$ahora,
				$ahora,
				$ip,
				substr( $user_agent, 0, 500 ),
				$match_id,
				$match_id,
				$match_id,
				$match_id
			)
		);
		// phpcs:enable

		if ( $wpdb->last_error ) {
			Penca_Helpers::log(
				'prediction-engine', 'error',
				"Error de BD al guardar pronóstico. Usuario: {$user_id} | Partido: {$match_id}",
				array( 'db_error' => $wpdb->last_error )
			);
			return array(
				'exito'         => false,
				'mensaje'       => 'Error interno al guardar el pronóstico.',
				'prediction_id' => 0,
			);
		}

		// rows_affected = 0 significa que el partido estaba cerrado en la BD.
		if ( 0 === $wpdb->rows_affected ) {
			return array(
				'exito'         => false,
				'mensaje'       => 'El partido ya no admite pronósticos (verificado en base de datos).',
				'prediction_id' => 0,
			);
		}

		// Obtener el ID del pronóstico (nuevo o actualizado).
		$prediction_id = $wpdb->insert_id > 0
			? $wpdb->insert_id
			: $this->obtener_id_pronostico( $user_id, $match_id );

		return array(
			'exito'         => true,
			'mensaje'       => 'Pronóstico guardado correctamente.',
			'prediction_id' => $prediction_id,
		);
	}

	// =========================================================================
	// LECTURA DE PRONÓSTICOS
	// =========================================================================

	/**
	 * Obtiene todos los pronósticos de un usuario.
	 *
	 * Hace JOIN con wp_wc_matches para traer los datos del partido.
	 * Usado en el perfil del usuario y en la vista "mis pronósticos".
	 *
	 * @param int $user_id ID del usuario.
	 * @return array Array de objetos con datos del pronóstico + partido.
	 */
	public function obtener_pronosticos_usuario( int $user_id ): array {
		global $wpdb;

		$tabla_pred  = Penca_Helpers::tabla( 'predictions' );
		$tabla_match = Penca_Helpers::tabla( 'matches' );
		$tabla_score = Penca_Helpers::tabla( 'scores' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					p.id              AS prediction_id,
					p.goles_local     AS pron_local,
					p.goles_visitante AS pron_visitante,
					p.submitted_at,
					p.updated_at      AS pron_updated_at,
					p.is_locked,
					m.id              AS match_id,
					m.fase,
					m.grupo,
					m.equipo_local,
					m.equipo_visitante,
					m.codigo_local,
					m.codigo_visitante,
					m.kickoff_utc,
					m.goles_local     AS resultado_local,
					m.goles_visitante AS resultado_visitante,
					m.fue_a_penales,
					m.status          AS match_status,
					m.predictions_locked,
					s.puntos,
					s.tipo_acierto
				FROM {$tabla_pred} p
				INNER JOIN {$tabla_match} m ON p.match_id = m.id
				LEFT JOIN {$tabla_score} s ON s.user_id = p.user_id AND s.match_id = m.id
				WHERE p.user_id = %d
				ORDER BY m.kickoff_utc ASC",
				$user_id
			)
		) ?? array();
	}

	/**
	 * Obtiene el pronóstico de un usuario para un partido específico.
	 *
	 * @param int $user_id  ID del usuario.
	 * @param int $match_id ID del partido.
	 * @return object|null
	 */
	public function obtener_pronostico( int $user_id, int $match_id ): ?object {
		global $wpdb;

		$tabla = Penca_Helpers::tabla( 'predictions' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$tabla} WHERE user_id = %d AND match_id = %d",
				$user_id,
				$match_id
			)
		);
	}

	/**
	 * Obtiene el ID de un pronóstico por user_id + match_id.
	 *
	 * @param int $user_id  ID del usuario.
	 * @param int $match_id ID del partido.
	 * @return int ID del pronóstico, o 0 si no existe.
	 */
	private function obtener_id_pronostico( int $user_id, int $match_id ): int {
		global $wpdb;

		$tabla = Penca_Helpers::tabla( 'predictions' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$tabla} WHERE user_id = %d AND match_id = %d",
				$user_id,
				$match_id
			)
		);
	}

	/**
	 * Obtiene todos los pronósticos de un partido (para el score-engine).
	 *
	 * @param int $match_id ID del partido.
	 * @return array Array de objetos con user_id, goles_local, goles_visitante.
	 */
	public function obtener_pronosticos_partido( int $match_id ): array {
		global $wpdb;

		$tabla = Penca_Helpers::tabla( 'predictions' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_id, goles_local, goles_visitante FROM {$tabla} WHERE match_id = %d",
				$match_id
			)
		) ?? array();
	}

	/**
	 * Marca todos los pronósticos de un partido como bloqueados.
	 *
	 * Llamado cuando el match-engine cierra las predicciones de un partido.
	 *
	 * @param int $match_id ID del partido.
	 * @return int Cantidad de pronósticos bloqueados.
	 */
	public function bloquear_pronosticos_partido( int $match_id ): int {
		global $wpdb;

		$tabla  = Penca_Helpers::tabla( 'predictions' );
		$ahora  = Penca_Helpers::ahora_utc_sql();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			$tabla,
			array(
				'is_locked' => 1,
				'locked_at' => $ahora,
			),
			array( 'match_id' => $match_id, 'is_locked' => 0 ),
			array( '%d', '%s' ),
			array( '%d', '%d' )
		);

		return (int) $wpdb->rows_affected;
	}

	// =========================================================================
	// ESTADÍSTICAS
	// =========================================================================

	/**
	 * Obtiene cuántos pronósticos tiene un partido.
	 *
	 * @param int $match_id ID del partido.
	 * @return int
	 */
	public function contar_pronosticos_partido( int $match_id ): int {
		global $wpdb;
		$tabla = Penca_Helpers::tabla( 'predictions' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$tabla} WHERE match_id = %d", $match_id )
		);
	}

	/**
	 * Cuántos partidos tiene pronosticados el usuario.
	 *
	 * @param int $user_id ID del usuario.
	 * @return int
	 */
	public function contar_pronosticos_usuario( int $user_id ): int {
		global $wpdb;
		$tabla = Penca_Helpers::tabla( 'predictions' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$tabla} WHERE user_id = %d", $user_id )
		);
	}

	// =========================================================================
	// AJAX
	// =========================================================================

	/**
	 * Handler AJAX para guardar pronóstico.
	 *
	 * @return void
	 */
	public function ajax_guardar_pronostico(): void {
		// Verificar que el usuario está logueado.
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'mensaje' => 'Debes iniciar sesión para pronosticar.' ), 401 );
		}

		check_ajax_referer( 'penca_pronostico_nonce', 'nonce' );

		$user_id         = get_current_user_id();
		$match_id        = (int) ( $_POST['match_id'] ?? 0 );
		$goles_local     = (int) ( $_POST['goles_local'] ?? 0 );
		$goles_visitante = (int) ( $_POST['goles_visitante'] ?? 0 );

		if ( $match_id <= 0 ) {
			wp_send_json_error( array( 'mensaje' => 'Partido no válido.' ) );
		}

		$resultado = $this->guardar_pronostico( $user_id, $match_id, $goles_local, $goles_visitante );

		if ( $resultado['exito'] ) {
			wp_send_json_success( array(
				'mensaje'       => $resultado['mensaje'],
				'prediction_id' => $resultado['prediction_id'],
			) );
		} else {
			wp_send_json_error( array( 'mensaje' => $resultado['mensaje'] ) );
		}
	}

	/**
	 * Handler AJAX para obtener los pronósticos del usuario actual.
	 *
	 * @return void
	 */
	public function ajax_obtener_mis_pronosticos(): void {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'mensaje' => 'Debes iniciar sesión.' ), 401 );
		}

		check_ajax_referer( 'penca_pronostico_nonce', 'nonce' );

		$user_id      = get_current_user_id();
		$pronosticos  = $this->obtener_pronosticos_usuario( $user_id );

		// Agregar campos formateados para el frontend.
		$formateados = array_map( function( $p ) {
			$p->kickoff_uy  = Penca_Helpers::formatear_fecha( $p->kickoff_utc, 'corto' );
			$p->abierto     = ! (bool) $p->predictions_locked;
			return $p;
		}, $pronosticos );

		wp_send_json_success( array(
			'pronosticos' => $formateados,
			'total'       => count( $formateados ),
		) );
	}
}
