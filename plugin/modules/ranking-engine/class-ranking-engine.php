<?php
/**
 * Módulo Ranking Engine — Penca WC2026.
 *
 * Genera y expone la tabla de ranking pública y los perfiles de usuario.
 *
 * Características:
 * - Ranking público (no requiere login para ver)
 * - Perfil de usuario con historial partido a partido
 * - Cache por transient para no golpear la BD en cada request
 * - Invalidación de cache cuando el score-engine actualiza puntos
 * - Shortcodes para incrustar ranking y perfil en páginas de Elementor
 *
 * @package PencaWC2026
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase Penca_Ranking_Engine.
 */
class Penca_Ranking_Engine {

	/** TTL del cache del ranking en segundos (5 minutos). */
	const CACHE_TTL = 5 * MINUTE_IN_SECONDS;

	/** Clave base del transient del ranking. */
	const CACHE_KEY = 'penca_ranking_cache';

	/** Cantidad de usuarios por página en el ranking. */
	const POR_PAGINA = 25;

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Shortcodes para Elementor.
		add_shortcode( 'penca_ranking', array( $this, 'shortcode_ranking' ) );
		add_shortcode( 'penca_perfil', array( $this, 'shortcode_perfil' ) );

		// AJAX para ranking paginado (público).
		add_action( 'wp_ajax_penca_obtener_ranking', array( $this, 'ajax_obtener_ranking' ) );
		add_action( 'wp_ajax_nopriv_penca_obtener_ranking', array( $this, 'ajax_obtener_ranking' ) );

		// AJAX para perfil de usuario (público).
		add_action( 'wp_ajax_penca_obtener_perfil', array( $this, 'ajax_obtener_perfil' ) );
		add_action( 'wp_ajax_nopriv_penca_obtener_perfil', array( $this, 'ajax_obtener_perfil' ) );
	}

	// =========================================================================
	// RANKING
	// =========================================================================

	/**
	 * Obtiene el ranking completo de usuarios ordenado por puntos.
	 *
	 * Con cache: retorna datos cacheados si están frescos.
	 * Sin cache o expirado: recalcula desde la BD.
	 *
	 * Criterios de desempate (en orden):
	 * 1. Total de puntos (DESC)
	 * 2. Cantidad de aciertos exactos (DESC)
	 * 3. Cantidad de aciertos por diferencia (DESC)
	 * 4. Apellido del usuario (ASC, desempate alfabético)
	 *
	 * @param int  $pagina    Página actual (1-based).
	 * @param bool $sin_cache Forzar recálculo ignorando el cache.
	 * @return array{
	 *   ranking: array,
	 *   total_usuarios: int,
	 *   pagina_actual: int,
	 *   total_paginas: int,
	 *   por_pagina: int,
	 *   ultima_actualizacion: string
	 * }
	 */
	public function obtener_ranking( int $pagina = 1, bool $sin_cache = false ): array {
		$clave_cache = self::CACHE_KEY . '_page_' . $pagina;

		// Intentar retornar desde cache.
		if ( ! $sin_cache ) {
			$cached = get_transient( $clave_cache );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		global $wpdb;

		$tabla_score = Penca_Helpers::tabla( 'scores' );
		$tabla_users = $wpdb->users;
		$tabla_usermeta = $wpdb->usermeta;

		$offset = ( max( 1, $pagina ) - 1 ) * self::POR_PAGINA;

		// Contar total de usuarios con al menos un puntaje.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total_usuarios = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT user_id) FROM {$tabla_score}"
		);

		if ( 0 === $total_usuarios ) {
			return $this->resultado_ranking_vacio( $pagina );
		}

		// Obtener ranking paginado con desempate.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$resultados = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					s.user_id,
					u.display_name,
					COALESCE(SUM(s.puntos), 0)                                     AS total_puntos,
					SUM(CASE WHEN s.tipo_acierto = 'exacto'     THEN 1 ELSE 0 END) AS exactos,
					SUM(CASE WHEN s.tipo_acierto = 'diferencia' THEN 1 ELSE 0 END) AS diferencias,
					SUM(CASE WHEN s.tipo_acierto = 'ganador'    THEN 1 ELSE 0 END) AS ganadores,
					SUM(CASE WHEN s.tipo_acierto = 'ninguno'    THEN 1 ELSE 0 END) AS ningunos,
					COUNT(s.id)                                                     AS partidos_puntuados
				FROM {$tabla_score} s
				INNER JOIN {$tabla_users} u ON s.user_id = u.ID
				GROUP BY s.user_id, u.display_name
				ORDER BY
					total_puntos DESC,
					exactos      DESC,
					diferencias  DESC,
					u.display_name ASC
				LIMIT %d OFFSET %d",
				self::POR_PAGINA,
				$offset
			)
		) ?? array();

		// Calcular posición real (offset + posición en el resultado).
		$posicion_base = $offset + 1;
		$ranking       = array();

		foreach ( $resultados as $index => $usuario ) {
			$ranking[] = array(
				'posicion'           => $posicion_base + $index,
				'user_id'            => (int) $usuario->user_id,
				'display_name'       => esc_html( $usuario->display_name ),
				'total_puntos'       => (int) $usuario->total_puntos,
				'exactos'            => (int) $usuario->exactos,
				'diferencias'        => (int) $usuario->diferencias,
				'ganadores'          => (int) $usuario->ganadores,
				'ningunos'           => (int) $usuario->ningunos,
				'partidos_puntuados' => (int) $usuario->partidos_puntuados,
				'url_perfil'         => $this->obtener_url_perfil( (int) $usuario->user_id ),
			);
		}

		$total_paginas = (int) ceil( $total_usuarios / self::POR_PAGINA );

		$resultado = array(
			'ranking'              => $ranking,
			'total_usuarios'       => $total_usuarios,
			'pagina_actual'        => $pagina,
			'total_paginas'        => $total_paginas,
			'por_pagina'           => self::POR_PAGINA,
			'ultima_actualizacion' => Penca_Helpers::formatear_fecha( Penca_Helpers::ahora_utc_sql(), 'corto' ),
		);

		// Guardar en cache.
		set_transient( $clave_cache, $resultado, self::CACHE_TTL );

		return $resultado;
	}

	/**
	 * Obtiene la posición actual de un usuario específico en el ranking.
	 *
	 * @param int $user_id ID del usuario.
	 * @return int Posición (1-based). 0 si no está en el ranking.
	 */
	public function obtener_posicion_usuario( int $user_id ): int {
		global $wpdb;

		$tabla_score = Penca_Helpers::tabla( 'scores' );
		$tabla_users = $wpdb->users;

		// Subquery: obtener total de puntos del usuario objetivo.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$posicion = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) + 1
				FROM (
					SELECT user_id, SUM(puntos) AS total_puntos
					FROM {$tabla_score}
					GROUP BY user_id
				) ranking
				WHERE total_puntos > (
					SELECT COALESCE(SUM(puntos), 0)
					FROM {$tabla_score}
					WHERE user_id = %d
				)",
				$user_id
			)
		);

		return (int) $posicion;
	}

	// =========================================================================
	// PERFIL DE USUARIO
	// =========================================================================

	/**
	 * Obtiene el perfil completo de un usuario para mostrar en el frontend.
	 *
	 * Incluye:
	 * - Datos del usuario
	 * - Posición en el ranking
	 * - Estadísticas de aciertos
	 * - Historial partido a partido (pronóstico, resultado real, puntos)
	 *
	 * @param int $user_id ID del usuario de WordPress.
	 * @return array|null null si el usuario no existe.
	 */
	public function obtener_perfil_usuario( int $user_id ): ?array {
		$usuario = get_userdata( $user_id );

		if ( ! $usuario ) {
			return null;
		}

		$score_engine = penca_wc2026()->score_engine;

		// Historial partido a partido.
		$historial = $score_engine->obtener_historial_usuario( $user_id );

		// Formatear historial para el frontend.
		$historial_formateado = array_map( function( $item ) {
			return array(
				'match_id'           => (int) $item->match_id,
				'equipo_local'       => esc_html( $item->equipo_local ),
				'equipo_visitante'   => esc_html( $item->equipo_visitante ),
				'fase'               => esc_html( $item->fase ),
				'grupo'              => esc_html( $item->grupo ),
				'kickoff_uy'         => Penca_Helpers::formatear_fecha( $item->kickoff_utc, 'corto' ),
				'resultado_real'     => $item->goles_local_real . '-' . $item->goles_visitante_real,
				'pronostico'         => $item->goles_local_pron . '-' . $item->goles_visitante_pron,
				'puntos'             => (int) $item->puntos,
				'tipo_acierto'       => $item->tipo_acierto,
				'fue_a_penales'      => (bool) $item->fue_a_penales,
				'badge_clase'        => $this->obtener_clase_badge( $item->tipo_acierto ),
				'badge_label'        => $this->obtener_label_badge( $item->tipo_acierto, (int) $item->puntos ),
			);
		}, $historial );

		$stats    = $score_engine->obtener_estadisticas_usuario( $user_id );
		$posicion = $this->obtener_posicion_usuario( $user_id );

		return array(
			'user_id'             => $user_id,
			'display_name'        => esc_html( $usuario->display_name ),
			'posicion_ranking'    => $posicion,
			'total_puntos'        => $stats['total_puntos'],
			'exactos'             => $stats['exactos'],
			'diferencias'         => $stats['diferencias'],
			'ganadores'           => $stats['ganadores'],
			'ningunos'            => $stats['ningunos'],
			'partidos_jugados'    => count( $historial ),
			'historial'           => $historial_formateado,
		);
	}

	// =========================================================================
	// SHORTCODES
	// =========================================================================

	/**
	 * Shortcode [penca_ranking] para incrustar en Elementor.
	 *
	 * @param array $atts Atributos del shortcode.
	 * @return string HTML del ranking.
	 */
	public function shortcode_ranking( array $atts = array() ): string {
		$atts = shortcode_atts( array( 'pagina' => 1 ), $atts );

		ob_start();
		$vista = PENCA_PLUGIN_DIR . 'public/views/ranking.php';
		if ( file_exists( $vista ) ) {
			$datos_ranking = $this->obtener_ranking( (int) $atts['pagina'] );
			include $vista;
		}
		return ob_get_clean();
	}

	/**
	 * Shortcode [penca_perfil] para incrustar en Elementor.
	 *
	 * Muestra el perfil del usuario actual si está logueado,
	 * o de un usuario específico si se pasa user_id.
	 *
	 * @param array $atts Atributos del shortcode.
	 * @return string HTML del perfil.
	 */
	public function shortcode_perfil( array $atts = array() ): string {
		$atts = shortcode_atts( array( 'user_id' => 0 ), $atts );

		$user_id = (int) $atts['user_id'];

		// Si no se especificó user_id, usar el usuario logueado.
		if ( $user_id <= 0 ) {
			$user_id = get_current_user_id();
		}

		if ( $user_id <= 0 ) {
			return '<p class="penca-aviso">' . esc_html__( 'Debes iniciar sesión para ver tu perfil.', 'penca-wc2026' ) . '</p>';
		}

		ob_start();
		$vista = PENCA_PLUGIN_DIR . 'public/views/user-profile.php';
		if ( file_exists( $vista ) ) {
			$datos_perfil = $this->obtener_perfil_usuario( $user_id );
			include $vista;
		}
		return ob_get_clean();
	}

	// =========================================================================
	// AJAX
	// =========================================================================

	/**
	 * Handler AJAX para ranking paginado (público).
	 *
	 * @return void
	 */
	public function ajax_obtener_ranking(): void {
		$pagina = max( 1, (int) ( $_GET['pagina'] ?? 1 ) );
		$datos  = $this->obtener_ranking( $pagina );
		wp_send_json_success( $datos );
	}

	/**
	 * Handler AJAX para perfil de usuario (público).
	 *
	 * @return void
	 */
	public function ajax_obtener_perfil(): void {
		$user_id = (int) ( $_GET['user_id'] ?? 0 );

		if ( $user_id <= 0 ) {
			wp_send_json_error( array( 'mensaje' => 'ID de usuario inválido.' ) );
		}

		$perfil = $this->obtener_perfil_usuario( $user_id );

		if ( null === $perfil ) {
			wp_send_json_error( array( 'mensaje' => 'Usuario no encontrado.' ) );
		}

		wp_send_json_success( $perfil );
	}

	// =========================================================================
	// UTILIDADES
	// =========================================================================

	/**
	 * Genera la URL del perfil de un usuario.
	 *
	 * @param int $user_id ID del usuario.
	 * @return string URL del perfil.
	 */
	private function obtener_url_perfil( int $user_id ): string {
		$pagina_perfil = get_option( 'penca_pagina_perfil', 0 );

		if ( $pagina_perfil > 0 ) {
			return add_query_arg( 'user_id', $user_id, get_permalink( $pagina_perfil ) );
		}

		// Fallback: URL con query string.
		return add_query_arg( array( 'penca-perfil' => $user_id ), home_url( '/' ) );
	}

	/**
	 * Retorna la clase CSS del badge según el tipo de acierto.
	 *
	 * @param string $tipo_acierto Tipo de acierto.
	 * @return string Clase CSS.
	 */
	private function obtener_clase_badge( string $tipo_acierto ): string {
		$clases = array(
			'exacto'  => 'badge--exacto',
			'ganador' => 'badge--ganador',
			'empate'  => 'badge--diferencia',
			'ninguno' => 'badge--ninguno',
		);
		return $clases[ $tipo_acierto ] ?? 'badge--ninguno';
	}

	/**
	 * Retorna el label legible del badge según tipo de acierto y puntos.
	 *
	 * @param string $tipo_acierto Tipo de acierto.
	 * @param int    $puntos       Puntos obtenidos.
	 * @return string Label del badge.
	 */
	private function obtener_label_badge( string $tipo_acierto, int $puntos ): string {
		$labels = array(
			'exacto'  => "🎯 Exacto ({$puntos} pts)",
			'ganador' => "👍 Ganador ({$puntos} pts)",
			'empate'  => "🤝 Empate ({$puntos} pts)",
			'ninguno' => $puntos > 0 ? "⚽ +{$puntos} goles" : '❌ Sin puntos',
		);
		return $labels[ $tipo_acierto ] ?? '❌ Sin puntos';
	}

	/**
	 * Retorna estructura vacía del ranking.
	 *
	 * @param int $pagina Página actual.
	 * @return array
	 */
	private function resultado_ranking_vacio( int $pagina ): array {
		return array(
			'ranking'              => array(),
			'total_usuarios'       => 0,
			'pagina_actual'        => $pagina,
			'total_paginas'        => 0,
			'por_pagina'           => self::POR_PAGINA,
			'ultima_actualizacion' => Penca_Helpers::formatear_fecha( Penca_Helpers::ahora_utc_sql(), 'corto' ),
		);
	}
}
