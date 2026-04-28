<?php
/**
 * Instalador de base de datos del plugin Penca WC2026.
 *
 * Responsable de crear, actualizar y migrar todas las tablas del plugin.
 * Usa dbDelta() de WordPress para crear/alterar tablas de forma segura.
 *
 * Tablas gestionadas:
 * - wp_wc_matches       — Partidos cacheados desde la API
 * - wp_wc_predictions   — Pronósticos por usuario/partido
 * - wp_wc_scores        — Puntos calculados por usuario/partido
 * - wp_wc_codes         — Códigos de acceso únicos MUN26-XXXX-XXXX
 * - wp_wc_logs          — Log del sistema
 *
 * @package PencaWC2026
 * @since   1.0.0
 */

// Prevenir acceso directo al archivo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase Penca_Installer.
 *
 * Todos los métodos son estáticos porque el instalador no necesita estado
 * propio — opera directamente sobre la base de datos global de WordPress.
 */
class Penca_Installer {

	// =========================================================================
	// MÉTODO PRINCIPAL DE INSTALACIÓN
	// =========================================================================

	/**
	 * Instala o actualiza todas las tablas del plugin.
	 *
	 * Se llama desde el hook de activación y también cuando se detecta
	 * una versión de BD desactualizada.
	 *
	 * dbDelta() es idempotente: si la tabla ya existe y el schema es igual,
	 * no hace nada. Si hay columnas nuevas, las agrega. No elimina columnas.
	 *
	 * @return void
	 */
	public static function instalar(): void {

		// dbDelta() requiere este archivo explícitamente.
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Crear cada tabla.
		self::crear_tabla_matches();
		self::crear_tabla_predictions();
		self::crear_tabla_scores();
		self::crear_tabla_codes();
		self::crear_tabla_logs();

		// Guardar la versión instalada en wp_options.
		update_option( PENCA_DB_VERSION_OPTION, PENCA_DB_VERSION );

		// Registrar la instalación en el log (si la tabla de logs ya existe).
		Penca_Helpers::log(
			'sistema',
			'info',
			sprintf(
				'Plugin instalado/actualizado. Versión BD: %s',
				PENCA_DB_VERSION
			)
		);
	}

	// =========================================================================
	// MÉTODO DE MIGRACIÓN
	// =========================================================================

	/**
	 * Ejecuta migraciones entre versiones de base de datos.
	 *
	 * Se llama automáticamente desde el singleton cuando detecta que
	 * la versión instalada es menor a la versión actual del plugin.
	 *
	 * Para agregar una migración futura:
	 * 1. Incrementar PENCA_DB_VERSION en penca-wc2026.php
	 * 2. Agregar un bloque if/version_compare acá
	 * 3. Ejecutar self::instalar() al final para sincronizar el schema
	 *
	 * @param string $version_anterior Versión de BD actualmente instalada.
	 * @param string $version_nueva    Versión de BD a la que migrar.
	 * @return void
	 */
	public static function migrar( string $version_anterior, string $version_nueva ): void {

		Penca_Helpers::log(
			'sistema',
			'info',
			sprintf(
				'Iniciando migración de BD: %s → %s',
				$version_anterior,
				$version_nueva
			)
		);

		/*
		 * Ejemplo de migración futura a versión 1.1.0:
		 *
		 * if ( version_compare( $version_anterior, '1.1.0', '<' ) ) {
		 *     global $wpdb;
		 *     $tabla = $wpdb->prefix . PENCA_TABLE_PREFIX . 'predictions';
		 *     $wpdb->query( "ALTER TABLE {$tabla} ADD COLUMN nuevo_campo VARCHAR(50) DEFAULT '' AFTER campo_existente" );
		 * }
		 */

		// Siempre re-ejecutar instalar() al final para aplicar cambios de schema.
		self::instalar();

		Penca_Helpers::log(
			'sistema',
			'info',
			sprintf(
				'Migración de BD completada: %s → %s',
				$version_anterior,
				$version_nueva
			)
		);
	}

	// =========================================================================
	// MÉTODO DE VERIFICACIÓN
	// =========================================================================

	/**
	 * Verifica si todas las tablas del plugin existen en la BD.
	 *
	 * Útil para el dashboard de admin y para verificar el estado
	 * del entorno antes de operaciones críticas.
	 *
	 * @return array{
	 *   matches: bool,
	 *   predictions: bool,
	 *   scores: bool,
	 *   codes: bool,
	 *   logs: bool,
	 *   todas_ok: bool
	 * } Estado de cada tabla.
	 */
	public static function verificar_tablas(): array {
		global $wpdb;

		$prefijo = $wpdb->prefix . PENCA_TABLE_PREFIX;

		$tablas = array(
			'matches'     => $prefijo . 'matches',
			'predictions' => $prefijo . 'predictions',
			'scores'      => $prefijo . 'scores',
			'codes'       => $prefijo . 'codes',
			'logs'        => $prefijo . 'logs',
		);

		$resultado = array();
		$todas_ok  = true;

		foreach ( $tablas as $nombre => $tabla_completa ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$existe = $wpdb->get_var( "SHOW TABLES LIKE '{$tabla_completa}'" ) === $tabla_completa;

			$resultado[ $nombre ] = $existe;

			if ( ! $existe ) {
				$todas_ok = false;
			}
		}

		$resultado['todas_ok'] = $todas_ok;

		return $resultado;
	}

	// =========================================================================
	// CREACIÓN DE TABLAS
	// =========================================================================

	/**
	 * Crea la tabla wp_wc_matches.
	 *
	 * Almacena los partidos del Mundial FIFA 2026 cacheados desde la API.
	 * Los datos se actualizan periódicamente vía cron (módulo api-sync).
	 * Nunca se muestran datos directamente desde la API — siempre desde aquí.
	 *
	 * Campos clave:
	 * - api_match_id: ID del partido en la API externa (puede ser de primaria o fallback)
	 * - kickoff_utc: hora de inicio en UTC (se convierte a UTC-3 en la presentación)
	 * - predictions_locked: barrera de seguridad para cierre de pronósticos
	 * - status: pending | live | finished | postponed | cancelled
	 * - api_source: primary | fallback (para saber de dónde vienen los datos)
	 *
	 * @return void
	 */
	private static function crear_tabla_matches(): void {
		global $wpdb;

		$tabla   = $wpdb->prefix . PENCA_TABLE_PREFIX . 'matches';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$tabla} (
			id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
			api_match_id    VARCHAR(100)    NOT NULL DEFAULT '',
			api_source      VARCHAR(20)     NOT NULL DEFAULT 'primary'
			                                COMMENT 'Fuente de datos: primary | fallback',
			fase            VARCHAR(100)    NOT NULL DEFAULT ''
			                                COMMENT 'Fase del torneo: grupo, octavos, etc.',
			grupo           VARCHAR(10)     NOT NULL DEFAULT ''
			                                COMMENT 'Letra del grupo (A-L). Vacío en knockout.',
			equipo_local    VARCHAR(100)    NOT NULL DEFAULT '',
			equipo_visitante VARCHAR(100)   NOT NULL DEFAULT '',
			codigo_local    VARCHAR(10)     NOT NULL DEFAULT ''
			                                COMMENT 'Código ISO del equipo local (ej: URY)',
			codigo_visitante VARCHAR(10)    NOT NULL DEFAULT ''
			                                COMMENT 'Código ISO del equipo visitante',
			kickoff_utc     DATETIME        NOT NULL
			                                COMMENT 'Hora de inicio en UTC. Mostrar en UTC-3.',
			estadio         VARCHAR(150)    NOT NULL DEFAULT '',
			ciudad          VARCHAR(100)    NOT NULL DEFAULT '',
			pais_sede       VARCHAR(100)    NOT NULL DEFAULT '',
			goles_local     TINYINT UNSIGNED NULL     DEFAULT NULL
			                                COMMENT 'NULL = partido no jugado aún',
			goles_visitante TINYINT UNSIGNED NULL     DEFAULT NULL,
			penales_local   TINYINT UNSIGNED NULL     DEFAULT NULL
			                                COMMENT 'Solo en knockout si hubo penales',
			penales_visitante TINYINT UNSIGNED NULL   DEFAULT NULL,
			fue_a_penales   TINYINT(1)      NOT NULL DEFAULT 0
			                                COMMENT '1 si el partido se definió por penales',
			status          VARCHAR(20)     NOT NULL DEFAULT 'pending'
			                                COMMENT 'pending | live | finished | postponed | cancelled',
			predictions_locked TINYINT(1)  NOT NULL DEFAULT 0
			                                COMMENT 'Barrera final de cierre de pronósticos. 1 = cerrado.',
			override_manual TINYINT(1)      NOT NULL DEFAULT 0
			                                COMMENT '1 si un admin editó el resultado manualmente',
			api_raw_data    LONGTEXT        NULL      DEFAULT NULL
			                                COMMENT 'JSON crudo de la API para debugging',
			last_sync_utc   DATETIME        NULL      DEFAULT NULL
			                                COMMENT 'Último sync con la API (UTC)',
			created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY   uk_api_match_id (api_match_id),
			KEY          idx_status (status),
			KEY          idx_kickoff_utc (kickoff_utc),
			KEY          idx_predictions_locked (predictions_locked),
			KEY          idx_fase_grupo (fase, grupo)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Crea la tabla wp_wc_predictions.
	 *
	 * Almacena los pronósticos que cada usuario hace para cada partido.
	 * Un usuario puede tener un solo pronóstico por partido (UNIQUE en user+match).
	 *
	 * Restricciones de negocio importantes:
	 * - Solo se puede pronosticar antes de que predictions_locked = 1 en wp_wc_matches
	 * - Se valida en frontend, backend Y base de datos (triple validación)
	 * - El campo locked_at registra cuándo se cerró para auditoría
	 *
	 * @return void
	 */
	private static function crear_tabla_predictions(): void {
		global $wpdb;

		$tabla   = $wpdb->prefix . PENCA_TABLE_PREFIX . 'predictions';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$tabla} (
			id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
			user_id         BIGINT UNSIGNED NOT NULL
			                                COMMENT 'ID del usuario de WordPress (wp_users)',
			match_id        INT UNSIGNED    NOT NULL
			                                COMMENT 'ID del partido en wp_wc_matches',
			goles_local     TINYINT UNSIGNED NOT NULL DEFAULT 0
			                                COMMENT 'Goles pronosticados para el equipo local',
			goles_visitante TINYINT UNSIGNED NOT NULL DEFAULT 0,
			submitted_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
			                                COMMENT 'Cuándo se envió el pronóstico (UTC)',
			updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
			                                COMMENT 'Última modificación (UTC). NULL si nunca se modificó.',
			is_locked       TINYINT(1)      NOT NULL DEFAULT 0
			                                COMMENT '1 si el pronóstico ya no puede modificarse',
			locked_at       DATETIME        NULL      DEFAULT NULL
			                                COMMENT 'Cuándo se bloqueó el pronóstico (UTC)',
			ip_address      VARCHAR(45)     NOT NULL DEFAULT ''
			                                COMMENT 'IP al momento de enviar (IPv4 o IPv6)',
			user_agent      VARCHAR(500)    NOT NULL DEFAULT ''
			                                COMMENT 'User agent del browser al momento de enviar',
			PRIMARY KEY  (id),
			UNIQUE KEY   uk_user_match (user_id, match_id)
			             COMMENT 'Un usuario solo puede tener un pronóstico por partido',
			KEY          idx_user_id (user_id),
			KEY          idx_match_id (match_id),
			KEY          idx_is_locked (is_locked),
			KEY          idx_submitted_at (submitted_at)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Crea la tabla wp_wc_scores.
	 *
	 * Almacena los puntos calculados para cada pronóstico una vez que el
	 * partido terminó y el score-engine procesó los resultados.
	 *
	 * Lógica de puntos (ver módulo score-engine para implementación):
	 * - 8 pts: resultado exacto (goles_local Y goles_visitante exactos)
	 * - 5 pts: diferencia de goles correcta (ej: 2-0 vs 3-1)
	 * - 3 pts: solo ganador correcto
	 * - 0 pts: no coincide nada
	 *
	 * Penales: se evalúa al 90'. El ganador en knockout es quien avanza.
	 *
	 * @return void
	 */
	private static function crear_tabla_scores(): void {
		global $wpdb;

		$tabla   = $wpdb->prefix . PENCA_TABLE_PREFIX . 'scores';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$tabla} (
			id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
			user_id         BIGINT UNSIGNED NOT NULL,
			match_id        INT UNSIGNED    NOT NULL,
			prediction_id   INT UNSIGNED    NOT NULL,
			puntos          TINYINT UNSIGNED NOT NULL DEFAULT 0
			                                COMMENT 'Puntos obtenidos: 0, 3, 5 u 8',
			tipo_acierto    VARCHAR(20)     NOT NULL DEFAULT 'ninguno'
			                                COMMENT 'exacto | diferencia | ganador | ninguno',
			goles_local_real TINYINT UNSIGNED NOT NULL DEFAULT 0
			                                COMMENT 'Resultado real al momento del cálculo',
			goles_visitante_real TINYINT UNSIGNED NOT NULL DEFAULT 0,
			goles_local_pron TINYINT UNSIGNED NOT NULL DEFAULT 0
			                                COMMENT 'Pronóstico del usuario',
			goles_visitante_pron TINYINT UNSIGNED NOT NULL DEFAULT 0,
			bono_goles           TINYINT(1)       NOT NULL DEFAULT 0 COMMENT 'Total goles acertado: +1 pt extra',
			calculado_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
			                                COMMENT 'Cuándo se calculó el puntaje (UTC)',
			recalculado     TINYINT(1)      NOT NULL DEFAULT 0
			                                COMMENT '1 si fue recalculado (ej: override manual de admin)',
			PRIMARY KEY  (id),
			UNIQUE KEY   uk_user_match (user_id, match_id)
			             COMMENT 'Un score por usuario por partido',
			KEY          idx_user_id (user_id),
			KEY          idx_match_id (match_id),
			KEY          idx_puntos (puntos),
			KEY          idx_tipo_acierto (tipo_acierto)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Crea la tabla wp_wc_codes.
	 *
	 * Almacena los códigos de acceso únicos para el registro.
	 * Formato del código: MUN26-XXXX-XXXX (generados por el admin).
	 *
	 * Estados del código:
	 * - available: disponible para usar
	 * - used: ya fue utilizado para crear una cuenta
	 * - blocked: bloqueado manualmente por el admin
	 *
	 * @return void
	 */
	private static function crear_tabla_codes(): void {
		global $wpdb;

		$tabla   = $wpdb->prefix . PENCA_TABLE_PREFIX . 'codes';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$tabla} (
			id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
			codigo          VARCHAR(20)     NOT NULL
			                                COMMENT 'Formato: MUN26-XXXX-XXXX',
			status          VARCHAR(20)     NOT NULL DEFAULT 'available'
			                                COMMENT 'available | used | blocked',
			user_id         BIGINT UNSIGNED NULL      DEFAULT NULL
			                                COMMENT 'ID del usuario que usó el código. NULL si no fue usado.',
			used_at         DATETIME        NULL      DEFAULT NULL
			                                COMMENT 'Cuándo se usó el código (UTC)',
			used_ip         VARCHAR(45)     NOT NULL DEFAULT ''
			                                COMMENT 'IP desde donde se usó el código',
			created_by      BIGINT UNSIGNED NOT NULL DEFAULT 0
			                                COMMENT 'ID del admin que generó el código',
			created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			blocked_at      DATETIME        NULL      DEFAULT NULL
			                                COMMENT 'Cuándo fue bloqueado (UTC)',
			blocked_reason  VARCHAR(255)    NOT NULL DEFAULT ''
			                                COMMENT 'Motivo del bloqueo (opcional)',
			notas           VARCHAR(500)    NOT NULL DEFAULT ''
			                                COMMENT 'Notas del admin (ej: destinatario del código)',
			PRIMARY KEY  (id),
			UNIQUE KEY   uk_codigo (codigo),
			KEY          idx_status (status),
			KEY          idx_user_id (user_id),
			KEY          idx_created_at (created_at)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Crea la tabla wp_wc_logs.
	 *
	 * Registro centralizado de eventos del sistema.
	 * Todos los módulos escriben aquí vía Penca_Helpers::log().
	 *
	 * Módulos que escriben logs:
	 * - api-sync: sincronizaciones, errores de API, switch a fallback
	 * - match-engine: cambios de estado, cierre de pronósticos
	 * - prediction-engine: envíos, intentos de modificación bloqueados
	 * - score-engine: cálculos de puntos, recálculos
	 * - access-codes: registro de usuarios, intentos fallidos, rate limiting
	 * - sistema: activación, migración, errores críticos
	 *
	 * @return void
	 */
	private static function crear_tabla_logs(): void {
		global $wpdb;

		$tabla   = $wpdb->prefix . PENCA_TABLE_PREFIX . 'logs';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$tabla} (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			modulo          VARCHAR(50)     NOT NULL DEFAULT ''
			                                COMMENT 'Módulo que generó el log: api-sync, match-engine, etc.',
			nivel           VARCHAR(20)     NOT NULL DEFAULT 'info'
			                                COMMENT 'Nivel de severidad: info | warning | error | critical',
			mensaje         TEXT            NOT NULL
			                                COMMENT 'Descripción del evento',
			contexto        LONGTEXT        NULL      DEFAULT NULL
			                                COMMENT 'JSON con datos adicionales para debugging',
			user_id         BIGINT UNSIGNED NULL      DEFAULT NULL
			                                COMMENT 'ID del usuario relacionado (si aplica)',
			ip_address      VARCHAR(45)     NOT NULL DEFAULT ''
			                                COMMENT 'IP del request (si aplica)',
			created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
			                                COMMENT 'Timestamp en UTC',
			PRIMARY KEY  (id),
			KEY          idx_modulo (modulo),
			KEY          idx_nivel (nivel),
			KEY          idx_created_at (created_at),
			KEY          idx_modulo_nivel (modulo, nivel)
		) {$charset};";

		dbDelta( $sql );
	}
}
