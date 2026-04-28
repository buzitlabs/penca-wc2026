<?php
/**
 * Plugin Name:       Penca WC2026
 * Plugin URI:        https://penca-wc2026.local
 * Description:       Plataforma de pronósticos deportivos para el Mundial FIFA 2026.
 *                    Desarrollada para organización benéfica uruguaya. Incluye
 *                    fixture en tiempo real, pronósticos por usuario, ranking público
 *                    y registro por código de acceso único.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            Desarrollado para organización benéfica uruguaya
 * Author URI:        https://penca-wc2026.local
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       penca-wc2026
 * Domain Path:       /languages
 *
 * @package PencaWC2026
 */

// Prevenir acceso directo al archivo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// =============================================================================
// CONSTANTES GLOBALES
// =============================================================================

/** Versión del plugin. Se usa para cache busting y migraciones de BD. */
define( 'PENCA_VERSION', '1.0.0' );

/**
 * Versión de la base de datos.
 * Incrementar cuando se modifiquen tablas para disparar migraciones.
 */
define( 'PENCA_DB_VERSION', '1.0.0' );

/** Path absoluto al directorio raíz del plugin (con trailing slash). */
define( 'PENCA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/** URL pública al directorio raíz del plugin (con trailing slash). */
define( 'PENCA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/** Prefijo para todas las tablas del plugin en la base de datos. */
define( 'PENCA_TABLE_PREFIX', 'wc_' );

/**
 * Zona horaria de referencia para mostrar horarios a los usuarios.
 * Todos los timestamps se almacenan en UTC y se convierten a esta zona.
 */
define( 'PENCA_TIMEZONE', 'America/Montevideo' );

/**
 * Offset UTC de Uruguay en segundos.
 * Uruguay es UTC-3. Se usa como fallback si la extensión DateTime no está disponible.
 */
define( 'PENCA_UTC_OFFSET', -3 * HOUR_IN_SECONDS );

/** Nombre de la opción en wp_options que almacena la versión de BD instalada. */
define( 'PENCA_DB_VERSION_OPTION', 'penca_wc2026_db_version' );

/** Opción que almacena el estado de la API activa (primaria o fallback). */
define( 'PENCA_API_STATUS_OPTION', 'penca_wc2026_api_status' );

/** URL base de la API primaria. */
define( 'PENCA_API_PRIMARY_URL', 'https://api.wc2026api.com' );

/** URL base de la API fallback. */
define( 'PENCA_API_FALLBACK_URL', 'https://www.thesportsdb.com/api/v1/json' );

/** Máximo de requests diarios permitidos en la API primaria (plan free). */
define( 'PENCA_API_DAILY_LIMIT', 100 );

/**
 * Fallos consecutivos de API primaria antes de hacer switch automático a fallback.
 * Ver módulo api-sync para la lógica de conteo.
 */
define( 'PENCA_API_FAIL_THRESHOLD', 2 );

/** Máximo de intentos de registro fallidos por IP antes de bloquear. */
define( 'PENCA_RATE_LIMIT_MAX_ATTEMPTS', 5 );

/** Ventana de tiempo en segundos para el rate limiting de registro (1 hora). */
define( 'PENCA_RATE_LIMIT_WINDOW', HOUR_IN_SECONDS );

// =============================================================================
// CARGA DE DEPENDENCIAS
// =============================================================================

/**
 * Carga los archivos del plugin en el orden correcto:
 * 1. Helpers — utilidades globales que todo el resto puede usar.
 * 2. Installer — gestión de tablas de BD.
 * 3. Módulos — cada uno es independiente.
 * 4. Admin — panel de administración.
 * 5. Public — frontend para usuarios.
 */
function penca_wc2026_cargar_dependencias(): void {

	// 1. Utilidades globales — deben cargarse primero.
	require_once PENCA_PLUGIN_DIR . 'includes/class-helpers.php';

	// 2. Instalador de BD — necesita helpers para logging.
	require_once PENCA_PLUGIN_DIR . 'includes/class-installer.php';

	// 3. Módulos independientes — orden sin dependencias cruzadas.
	require_once PENCA_PLUGIN_DIR . 'modules/api-sync/class-api-sync.php';
	require_once PENCA_PLUGIN_DIR . 'modules/match-engine/class-match-engine.php';
	require_once PENCA_PLUGIN_DIR . 'modules/prediction-engine/class-prediction-engine.php';
	require_once PENCA_PLUGIN_DIR . 'modules/score-engine/class-score-engine.php';
	require_once PENCA_PLUGIN_DIR . 'modules/ranking-engine/class-ranking-engine.php';
	require_once PENCA_PLUGIN_DIR . 'modules/access-codes/class-access-codes.php';

	// 4. Panel de administración — solo en contexto admin.
	if ( is_admin() ) {
		require_once PENCA_PLUGIN_DIR . 'admin/class-admin.php';
	}

	// 5. Frontend público.
	require_once PENCA_PLUGIN_DIR . 'public/class-public.php';
}

// =============================================================================
// HOOKS DE CICLO DE VIDA DEL PLUGIN
// =============================================================================

/**
 * Hook de activación del plugin.
 *
 * Se ejecuta UNA SOLA VEZ cuando el admin activa el plugin desde el panel.
 * Crea todas las tablas de BD, guarda la versión y registra el evento.
 *
 * IMPORTANTE: No usar esta función para hooks o acciones que se ejecuten
 * en cada carga. Solo para setup inicial.
 *
 * @return void
 */
function penca_wc2026_activar(): void {

	// Cargar dependencias necesarias para la activación.
	require_once PENCA_PLUGIN_DIR . 'includes/class-helpers.php';
	require_once PENCA_PLUGIN_DIR . 'includes/class-installer.php';

	// Crear o actualizar todas las tablas de la base de datos.
	Penca_Installer::instalar();

	// Limpiar reglas de reescritura para que los custom endpoints funcionen.
	flush_rewrite_rules();
}

register_activation_hook( __FILE__, 'penca_wc2026_activar' );

/**
 * Hook de desactivación del plugin.
 *
 * Se ejecuta cuando el admin desactiva el plugin.
 * NO borramos datos aquí — eso se hace solo en desinstalación.
 * Sí limpiamos transients y crons programados.
 *
 * @return void
 */
function penca_wc2026_desactivar(): void {

	// Limpiar transients del plugin para evitar datos obsoletos.
	delete_transient( 'penca_api_status' );
	delete_transient( 'penca_ranking_cache' );

	// Eliminar el cron del sistema si está programado.
	// Nota: el cron real del servidor se gestiona desde cPanel,
	// pero el hook de WP que dispara el cron interno se limpia aquí.
	$timestamp = wp_next_scheduled( 'penca_cron_api_sync' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'penca_cron_api_sync' );
	}

	// Limpiar reglas de reescritura.
	flush_rewrite_rules();
}

register_deactivation_hook( __FILE__, 'penca_wc2026_desactivar' );

// =============================================================================
// CLASE PRINCIPAL — SINGLETON
// =============================================================================

/**
 * Clase principal del plugin Penca WC2026.
 *
 * Implementa el patrón Singleton para garantizar que solo exista
 * una instancia del plugin en memoria durante cada request.
 *
 * @since 1.0.0
 */
final class Penca_WC2026 {

	/**
	 * Instancia única de la clase.
	 *
	 * @var Penca_WC2026|null
	 */
	private static ?Penca_WC2026 $instance = null;

	/**
	 * Instancia del módulo API Sync.
	 *
	 * @var Penca_Api_Sync|null
	 */
	public ?Penca_Api_Sync $api_sync = null;

	/**
	 * Instancia del módulo Match Engine.
	 *
	 * @var Penca_Match_Engine|null
	 */
	public ?Penca_Match_Engine $match_engine = null;

	/**
	 * Instancia del módulo Prediction Engine.
	 *
	 * @var Penca_Prediction_Engine|null
	 */
	public ?Penca_Prediction_Engine $prediction_engine = null;

	/**
	 * Instancia del módulo Score Engine.
	 *
	 * @var Penca_Score_Engine|null
	 */
	public ?Penca_Score_Engine $score_engine = null;

	/**
	 * Instancia del módulo Ranking Engine.
	 *
	 * @var Penca_Ranking_Engine|null
	 */
	public ?Penca_Ranking_Engine $ranking_engine = null;

	/**
	 * Instancia del módulo Access Codes.
	 *
	 * @var Penca_Access_Codes|null
	 */
	public ?Penca_Access_Codes $access_codes = null;

	/**
	 * Constructor privado — impide instanciación directa.
	 * Usar Penca_WC2026::get_instance() en su lugar.
	 */
	private function __construct() {}

	/**
	 * Impide la clonación del singleton.
	 *
	 * @return void
	 */
	public function __clone() {}

	/**
	 * Impide la deserialización del singleton.
	 *
	 * @return void
	 * @throws \Exception Si se intenta deserializar.
	 */
	public function __wakeup(): void {
		throw new \Exception( 'No se puede deserializar el singleton Penca_WC2026.' );
	}

	/**
	 * Obtiene la instancia única del plugin.
	 *
	 * Crea la instancia si no existe, la inicializa y la retorna.
	 * Patrón Singleton thread-safe para PHP (PHP es single-threaded por request).
	 *
	 * @return Penca_WC2026
	 */
	public static function get_instance(): Penca_WC2026 {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->inicializar();
		}

		return self::$instance;
	}

	/**
	 * Inicializa el plugin: carga dependencias y registra módulos.
	 *
	 * Se ejecuta una sola vez cuando se crea la instancia.
	 *
	 * @return void
	 */
	private function inicializar(): void {

		// Cargar todos los archivos del plugin.
		penca_wc2026_cargar_dependencias();

		// Cargar traducciones (i18n).
		add_action( 'plugins_loaded', array( $this, 'cargar_textdomain' ) );

		// Inicializar módulos en el momento correcto del ciclo de WP.
		add_action( 'plugins_loaded', array( $this, 'inicializar_modulos' ), 10 );

		// Verificar si hay una migración de BD pendiente.
		add_action( 'plugins_loaded', array( $this, 'verificar_migracion_bd' ), 5 );
	}

	/**
	 * Carga el textdomain para internacionalización.
	 *
	 * @return void
	 */
	public function cargar_textdomain(): void {
		load_plugin_textdomain(
			'penca-wc2026',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);
	}

	/**
	 * Instancia cada módulo y los almacena en propiedades públicas.
	 *
	 * Cada módulo es independiente y se inicializa aquí.
	 * Las dependencias entre módulos se inyectan vía constructor o métodos.
	 *
	 * @return void
	 */
	public function inicializar_modulos(): void {
		$this->api_sync          = new Penca_Api_Sync();
		$this->match_engine      = new Penca_Match_Engine();
		$this->prediction_engine = new Penca_Prediction_Engine();
		$this->score_engine      = new Penca_Score_Engine();
		$this->ranking_engine    = new Penca_Ranking_Engine();
		$this->access_codes      = new Penca_Access_Codes();

		// Inicializar admin solo en contexto de administración.
		if ( is_admin() ) {
			new Penca_Admin();
		}

		// Inicializar frontend público.
		new Penca_Public();
	}

	/**
	 * Verifica si la versión de BD instalada coincide con la actual.
	 * Si no coincide, dispara el proceso de migración.
	 *
	 * @return void
	 */
	public function verificar_migracion_bd(): void {
		$version_instalada = get_option( PENCA_DB_VERSION_OPTION, '0.0.0' );

		if ( version_compare( $version_instalada, PENCA_DB_VERSION, '<' ) ) {
			Penca_Installer::migrar( $version_instalada, PENCA_DB_VERSION );
		}
	}
}

// =============================================================================
// FUNCIÓN DE ACCESO GLOBAL AL PLUGIN
// =============================================================================

/**
 * Función global para acceder a la instancia del plugin.
 *
 * Uso desde cualquier parte del código:
 *   penca_wc2026()->api_sync->sincronizar();
 *   penca_wc2026()->ranking_engine->obtener_ranking();
 *
 * @return Penca_WC2026
 */
function penca_wc2026(): Penca_WC2026 {
	return Penca_WC2026::get_instance();
}

// =============================================================================
// PUNTO DE ENTRADA — Arrancar el plugin
// =============================================================================

// Iniciar el plugin. La instancia se mantiene en memoria durante todo el request.
penca_wc2026();
