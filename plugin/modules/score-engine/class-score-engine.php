<?php
/**
 * Módulo Score Engine — Penca WC2026.
 *
 * Calcula y almacena los puntos obtenidos por cada usuario en cada partido.
 *
 * Lógica de puntos:
 * - 8 pts: resultado exacto (mismo marcador al 90')
 * - 5 pts: diferencia de goles exacta (ej: pronosticás 2-0, termina 3-1 → diff = 2)
 * - 3 pts: solo ganador correcto (incluyendo empate exacto)
 * - 0 pts: no coincide nada
 *
 * Regla de penales:
 * - Se evalúa siempre el resultado al 90' (no los penales).
 * - En knockout, el "ganador" para efectos de puntos es quien avanza,
 *   pero solo si el partido terminó empatado al 90'. Si hay empate al 90'
 *   y va a penales, NO se puede dar ganador correcto (era empate → 0 o 3 pts
 *   según si acertaste el empate).
 *
 * @package PencaWC2026
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase Penca_Score_Engine.
 */
class Penca_Score_Engine {

	// Constantes de puntos.
	const PUNTOS_EXACTO   = 12; // Marcador exacto
	const PUNTOS_GANADOR  = 6;  // Ganador correcto (no exacto)
	const PUNTOS_EMPATE   = 4;  // Empate acertado (no exacto)
	const PUNTOS_NINGUNO  = 0;  // Sin acierto
	const PUNTOS_GOLES    = 1;  // Extra: total de goles del partido correcto

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Recalcular cuando el match-engine reporta un partido finalizado.
		add_action( 'penca_partido_finalizado', array( $this, 'calcular_puntos_partido' ) );
	}

	// =========================================================================
	// CÁLCULO DE PUNTOS
	// =========================================================================

	/**
	 * Calcula y guarda los puntos de todos los usuarios para un partido.
	 *
	 * Se dispara mediante el hook 'penca_partido_finalizado' cuando:
	 * - El sync de la API detecta que un partido terminó.
	 * - Un admin hace override manual de un resultado.
	 *
	 * @param int $match_id ID del partido en wp_wc_matches.
	 * @return array{procesados: int, errores: int} Resumen del proceso.
	 */
	public function calcular_puntos_partido( int $match_id ): array {
		// Obtener datos reales del partido.
		$match_engine = penca_wc2026()->match_engine;
		$partido      = $match_engine->obtener_partido( $match_id );

		if ( ! $partido ) {
			Penca_Helpers::log(
				'score-engine', 'error',
				"No se encontró el partido {$match_id} para calcular puntos."
			);
			return array( 'procesados' => 0, 'errores' => 1 );
		}

		// Verificar que el partido tiene resultado.
		if ( null === $partido->goles_local || null === $partido->goles_visitante ) {
			Penca_Helpers::log(
				'score-engine', 'warning',
				"Partido {$match_id} sin resultado definido. No se calculan puntos."
			);
			return array( 'procesados' => 0, 'errores' => 0 );
		}

		// Obtener todos los pronósticos para este partido.
		$prediction_engine = penca_wc2026()->prediction_engine;
		$pronosticos       = $prediction_engine->obtener_pronosticos_partido( $match_id );

		if ( empty( $pronosticos ) ) {
			Penca_Helpers::log(
				'score-engine', 'info',
				"Partido {$match_id} sin pronósticos registrados. Nada que calcular."
			);
			return array( 'procesados' => 0, 'errores' => 0 );
		}

		$procesados = 0;
		$errores    = 0;

		foreach ( $pronosticos as $pronostico ) {
			$calculo = $this->calcular_puntos_individual(
				(int) $pronostico->goles_local,
				(int) $pronostico->goles_visitante,
				(int) $partido->goles_local,
				(int) $partido->goles_visitante,
				(bool) $partido->fue_a_penales
			);

			$ok = $this->guardar_puntos(
				(int) $pronostico->user_id,
				$match_id,
				(int) $pronostico->id,
				$calculo['puntos'],
				$calculo['tipo'],
				(int) $partido->goles_local,
				(int) $partido->goles_visitante,
				(int) $pronostico->goles_local,
				(int) $pronostico->goles_visitante,
				$calculo['bono_goles'] ?? false
			);

			if ( $ok ) {
				++$procesados;
			} else {
				++$errores;
			}
		}

		Penca_Helpers::log(
			'score-engine', 'info',
			"Puntos calculados para partido {$match_id}. Procesados: {$procesados} | Errores: {$errores}.",
			array(
				'match_id'           => $match_id,
				'resultado'          => "{$partido->goles_local}-{$partido->goles_visitante}",
				'total_pronosticos'  => count( $pronosticos ),
				'procesados'         => $procesados,
				'errores'            => $errores,
			)
		);

		// Invalidar cache del ranking.
		delete_transient( 'penca_ranking_cache' );
		delete_transient( 'penca_ranking_cache_page_1' );

		return array( 'procesados' => $procesados, 'errores' => $errores );
	}

	/**
	 * Calcula los puntos para un pronóstico individual.
	 *
	 * Sistema de puntos vigente:
	 *
	 * RESULTADO EXACTO (12 pts):
	 *   El marcador al 90' coincide exactamente con el pronóstico.
	 *   Ej: pronosticás 2-1, termina 2-1 → 12 pts.
	 *   No se suma el +1 de goles (está implícito en el exacto).
	 *
	 * GANADOR CORRECTO (6 pts):
	 *   Acertaste quién ganó pero el marcador no es exacto.
	 *   Ej: pronosticás 2-1, termina 3-1 → 6 pts base.
	 *
	 * EMPATE ACERTADO (4 pts):
	 *   Pronosticaste empate (X-X) y el partido terminó empatado, pero
	 *   el marcador exacto no coincide.
	 *   Ej: pronosticás 2-2, termina 1-1 → 4 pts base.
	 *
	 * SIN ACIERTOS (0 pts):
	 *   No coincide ni el ganador ni el tipo de resultado.
	 *
	 * BONO +1 (total de goles del partido correcto):
	 *   Se suma 1 punto extra si la suma de goles pronosticada coincide
	 *   con la suma de goles real, SIEMPRE QUE no sea resultado exacto.
	 *   Ej: pronosticás 2-1 (total 3), termina 3-0 (total 3) → +1 al puntaje base.
	 *   Ej: pronosticás 2-2 (total 4), termina 1-1 (total 2) → sin bono.
	 *
	 * PENALES:
	 *   Se evalúa el resultado al 90'. El partido fue a penales significa
	 *   que terminó empatado al 90'. Se aplica la regla de empate.
	 *
	 * @param int  $pron_local     Goles locales pronosticados.
	 * @param int  $pron_visitante Goles visitante pronosticados.
	 * @param int  $real_local     Goles locales reales (al 90').
	 * @param int  $real_visitante Goles visitante reales (al 90').
	 * @param bool $fue_a_penales  Si el partido se definió por penales.
	 * @return array{puntos: int, tipo: string, bono_goles: bool}
	 */
	public function calcular_puntos_individual(
		int $pron_local,
		int $pron_visitante,
		int $real_local,
		int $real_visitante,
		bool $fue_a_penales = false
	): array {

		// Totales de goles para el bono.
		$total_real = $real_local + $real_visitante;
		$total_pron = $pron_local + $pron_visitante;
		$bono_goles = ( $total_pron === $total_real );

		// --- RESULTADO EXACTO (12 pts, sin bono) ---
		// El marcador coincide exactamente → 12 fijo, el bono no aplica.
		if ( $pron_local === $real_local && $pron_visitante === $real_visitante ) {
			return array(
				'puntos'     => self::PUNTOS_EXACTO,
				'tipo'       => 'exacto',
				'bono_goles' => false,
			);
		}

		// Ganador real y pronosticado para los siguientes casos.
		$ganador_real = $this->obtener_ganador( $real_local, $real_visitante );
		$ganador_pron = $this->obtener_ganador( $pron_local, $pron_visitante );

		// --- EMPATE ACERTADO (4 pts + posible bono) ---
		// Pronosticaste empate Y fue empate (al 90'), pero marcador distinto.
		// Esto incluye partidos que fueron a penales (terminaron 90' empatados).
		if ( 'empate' === $ganador_real && 'empate' === $ganador_pron ) {
			$pts = self::PUNTOS_EMPATE + ( $bono_goles ? self::PUNTOS_GOLES : 0 );
			return array(
				'puntos'     => $pts,
				'tipo'       => 'empate',
				'bono_goles' => $bono_goles,
			);
		}

		// --- GANADOR CORRECTO (6 pts + posible bono) ---
		// Acertaste quién ganó pero el marcador no es exacto.
		if ( $ganador_real === $ganador_pron ) {
			$pts = self::PUNTOS_GANADOR + ( $bono_goles ? self::PUNTOS_GOLES : 0 );
			return array(
				'puntos'     => $pts,
				'tipo'       => 'ganador',
				'bono_goles' => $bono_goles,
			);
		}

		// --- SIN ACIERTOS (0 pts, con posible bono) ---
		$pts = $bono_goles ? self::PUNTOS_GOLES : self::PUNTOS_NINGUNO;
		return array(
			'puntos'     => $pts,
			'tipo'       => 'ninguno',
			'bono_goles' => $bono_goles,
		);
	}

	/**
	 * Determina el ganador de un partido según el marcador.
	 *
	 * @param int $goles_local     Goles del equipo local.
	 * @param int $goles_visitante Goles del equipo visitante.
	 * @return string 'local' | 'visitante' | 'empate'
	 */
	private function obtener_ganador( int $goles_local, int $goles_visitante ): string {
		if ( $goles_local > $goles_visitante ) {
			return 'local';
		}
		if ( $goles_visitante > $goles_local ) {
			return 'visitante';
		}
		return 'empate';
	}

	// =========================================================================
	// PERSISTENCIA DE PUNTOS
	// =========================================================================

	/**
	 * Guarda o actualiza los puntos de un usuario para un partido.
	 *
	 * Usa INSERT ... ON DUPLICATE KEY UPDATE para ser idempotente.
	 * Si ya existe un score para ese par user_id+match_id, lo actualiza
	 * y marca recalculado = 1.
	 *
	 * @param int    $user_id          ID del usuario.
	 * @param int    $match_id         ID del partido.
	 * @param int    $prediction_id    ID del pronóstico.
	 * @param int    $puntos           Puntos calculados.
	 * @param string $tipo_acierto     'exacto'|'ganador'|'empate'|'ninguno'.
	 * @param int    $real_local       Goles reales local.
	 * @param int    $real_visitante   Goles reales visitante.
	 * @param int    $pron_local       Goles pronosticados local.
	 * @param int    $pron_visitante   Goles pronosticados visitante.
	 * @return bool true si se guardó correctamente.
	 */
	private function guardar_puntos(
		int $user_id,
		int $match_id,
		int $prediction_id,
		int $puntos,
		string $tipo_acierto,
		int $real_local,
		int $real_visitante,
		int $pron_local,
		int $pron_visitante,
		bool $bono_goles = false
	): bool {
		global $wpdb;

		$tabla = Penca_Helpers::tabla( 'scores' );
		$ahora = Penca_Helpers::ahora_utc_sql();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$bono_int = $bono_goles ? 1 : 0;
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$tabla}
					(user_id, match_id, prediction_id, puntos, tipo_acierto,
					 goles_local_real, goles_visitante_real,
					 goles_local_pron, goles_visitante_pron,
					 bono_goles, calculado_at, recalculado)
				VALUES (%d, %d, %d, %d, %s, %d, %d, %d, %d, %d, %s, 0)
				ON DUPLICATE KEY UPDATE
					puntos               = VALUES(puntos),
					tipo_acierto         = VALUES(tipo_acierto),
					goles_local_real     = VALUES(goles_local_real),
					goles_visitante_real = VALUES(goles_visitante_real),
					goles_local_pron     = VALUES(goles_local_pron),
					goles_visitante_pron = VALUES(goles_visitante_pron),
					bono_goles           = VALUES(bono_goles),
					calculado_at         = VALUES(calculado_at),
					recalculado          = 1",
				$user_id, $match_id, $prediction_id,
				$puntos, $tipo_acierto,
				$real_local, $real_visitante,
				$pron_local, $pron_visitante,
				$bono_int, $ahora
			)
		);
		// phpcs:enable

		if ( $wpdb->last_error ) {
			Penca_Helpers::log(
				'score-engine', 'error',
				"Error al guardar puntos. Usuario: {$user_id} | Partido: {$match_id}",
				array( 'db_error' => $wpdb->last_error )
			);
			return false;
		}

		return true;
	}

	// =========================================================================
	// CONSULTAS DE PUNTOS
	// =========================================================================

	/**
	 * Obtiene el total de puntos acumulados por un usuario.
	 *
	 * @param int $user_id ID del usuario.
	 * @return int Total de puntos.
	 */
	public function obtener_total_puntos_usuario( int $user_id ): int {
		global $wpdb;
		$tabla = Penca_Helpers::tabla( 'scores' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(SUM(puntos), 0) FROM {$tabla} WHERE user_id = %d", $user_id )
		);
	}

	/**
	 * Obtiene el historial de puntos de un usuario partido por partido.
	 *
	 * @param int $user_id ID del usuario.
	 * @return array
	 */
	public function obtener_historial_usuario( int $user_id ): array {
		global $wpdb;

		$tabla_score = Penca_Helpers::tabla( 'scores' );
		$tabla_match = Penca_Helpers::tabla( 'matches' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					s.puntos,
					s.tipo_acierto,
					s.goles_local_real,
					s.goles_visitante_real,
					s.goles_local_pron,
					s.goles_visitante_pron,
					s.calculado_at,
					m.id           AS match_id,
					m.fase,
					m.grupo,
					m.equipo_local,
					m.equipo_visitante,
					m.kickoff_utc,
					m.fue_a_penales
				FROM {$tabla_score} s
				INNER JOIN {$tabla_match} m ON s.match_id = m.id
				WHERE s.user_id = %d
				ORDER BY m.kickoff_utc ASC",
				$user_id
			)
		) ?? array();
	}

	/**
	 * Obtiene estadísticas de aciertos de un usuario.
	 *
	 * @param int $user_id ID del usuario.
	 * @return array{exactos: int, diferencias: int, ganadores: int, ningunos: int, total_puntos: int}
	 */
	public function obtener_estadisticas_usuario( int $user_id ): array {
		global $wpdb;

		$tabla = Penca_Helpers::tabla( 'scores' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM(CASE WHEN tipo_acierto = 'exacto'     THEN 1 ELSE 0 END) AS exactos,
					SUM(CASE WHEN tipo_acierto = 'empate'     THEN 1 ELSE 0 END) AS empates,
					SUM(CASE WHEN tipo_acierto = 'ganador'    THEN 1 ELSE 0 END) AS ganadores,
					SUM(CASE WHEN tipo_acierto = 'ninguno'    THEN 1 ELSE 0 END) AS ningunos,
					COALESCE(SUM(puntos), 0)                                     AS total_puntos
				FROM {$tabla}
				WHERE user_id = %d",
				$user_id
			)
		);

		return array(
			'exactos'      => (int) ( $stats->exactos ?? 0 ),
			'empates'      => (int) ( $stats->empates ?? 0 ),
			'ganadores'    => (int) ( $stats->ganadores ?? 0 ),
			'ningunos'     => (int) ( $stats->ningunos ?? 0 ),
			'total_puntos' => (int) ( $stats->total_puntos ?? 0 ),
		);
	}
}
