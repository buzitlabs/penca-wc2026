<?php
/**
 * Módulo Access Codes — Penca WC2026.
 *
 * Gestiona el sistema de registro por código único:
 * - Validación de códigos formato MUN26-XXXX-XXXX
 * - Rate limiting: máx 5 intentos fallidos por IP por hora
 * - Registro: crea cuenta WP + marca código como usado + login automático
 * - Generación masiva de códigos para el admin
 * - Exportación CSV
 *
 * @package PencaWC2026
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase Penca_Access_Codes.
 */
class Penca_Access_Codes {

	/** Rol de WordPress asignado a usuarios de la penca. */
	const ROL_PENCA = 'subscriber';

	/**
	 * Constructor.
	 */
	public function __construct() {
		// AJAX para validar y usar un código de acceso (sin login).
		add_action( 'wp_ajax_nopriv_penca_registrar_usuario', array( $this, 'ajax_registrar_usuario' ) );
		add_action( 'wp_ajax_penca_registrar_usuario', array( $this, 'ajax_registrar_usuario' ) );

		// AJAX para verificar si un código existe (sin enviar los demás datos).
		add_action( 'wp_ajax_nopriv_penca_verificar_codigo', array( $this, 'ajax_verificar_codigo' ) );

		// Admin: generar códigos.
		add_action( 'wp_ajax_penca_generar_codigos', array( $this, 'ajax_generar_codigos' ) );

		// Admin: exportar CSV.
		add_action( 'admin_post_penca_exportar_codigos', array( $this, 'exportar_codigos_csv' ) );

		// Shortcode del formulario de registro.
		add_shortcode( 'penca_registro', array( $this, 'shortcode_registro' ) );

	}

	// =========================================================================
	// REGISTRO DE USUARIO
	// =========================================================================

	/**
	 * Registra un usuario usando un código de acceso.
	 *
	 * Proceso completo:
	 * 1. Rate limiting por IP.
	 * 2. Validar formato del código.
	 * 3. Verificar código en BD (existe, está disponible).
	 * 4. Validar username y email.
	 * 5. Crear cuenta de WordPress.
	 * 6. Marcar código como usado.
	 * 7. Login automático.
	 *
	 * Transacción lógica: si cualquier paso falla, no se crean datos parciales.
	 *
	 * @param string $codigo          Código de acceso.
	 * @param string $username        Nombre de usuario elegido.
	 * @param string $email           Email del usuario.
	 * @param string $password        Contraseña elegida.
	 * @param string $display_name    Nombre para mostrar (opcional).
	 * @return array{exito: bool, mensaje: string, user_id: int}
	 */
	public function registrar_usuario(
		string $codigo,
		string $username,
		string $email,
		string $password,
		string $display_name = ''
	): array {

		$ip = Penca_Helpers::obtener_ip_cliente();

		// --- PASO 0: Verificar tope de usuarios registrados ---
		$tope = (int) get_option( 'penca_tope_usuarios', 1000 );
		if ( $this->contar_usuarios_registrados() >= $tope ) {
			Penca_Helpers::log(
				'access-codes', 'warning',
				"Intento de registro bloqueado: tope de {$tope} usuarios alcanzado. IP: {$ip}"
			);
			return array(
				'exito'   => false,
				'mensaje' => 'El registro está cerrado. Se alcanzó el número máximo de participantes.',
				'user_id' => 0,
			);
		}

		// --- PASO 1: Rate limiting ---
		if ( Penca_Helpers::esta_bajo_rate_limit( $ip, 'registro' ) ) {
			Penca_Helpers::log(
				'access-codes', 'warning',
				"Rate limit alcanzado para registro. IP: {$ip}"
			);
			return array(
				'exito'   => false,
				'mensaje' => 'Demasiados intentos fallidos. Por favor esperá una hora antes de intentar nuevamente.',
				'user_id' => 0,
			);
		}

		// --- PASO 2: Validar formato del código ---
		$codigo_limpio = Penca_Helpers::validar_formato_codigo( $codigo );
		if ( null === $codigo_limpio ) {
			Penca_Helpers::incrementar_intentos_fallidos( $ip, 'registro' );
			return array(
				'exito'   => false,
				'mensaje' => 'El formato del código es inválido. Debe ser MUN26-XXXX-XXXX.',
				'user_id' => 0,
			);
		}

		// --- PASO 3: Verificar código en BD ---
		$datos_codigo = $this->obtener_codigo( $codigo_limpio );

		if ( ! $datos_codigo ) {
			Penca_Helpers::incrementar_intentos_fallidos( $ip, 'registro' );
			Penca_Helpers::log(
				'access-codes', 'warning',
				"Código no encontrado: {$codigo_limpio} | IP: {$ip}"
			);
			return array(
				'exito'   => false,
				'mensaje' => 'El código de acceso no es válido.',
				'user_id' => 0,
			);
		}

		if ( 'available' !== $datos_codigo->status ) {
			Penca_Helpers::incrementar_intentos_fallidos( $ip, 'registro' );
			$mensaje = 'used' === $datos_codigo->status
				? 'Este código ya fue utilizado.'
				: 'Este código está bloqueado.';
			return array(
				'exito'   => false,
				'mensaje' => $mensaje,
				'user_id' => 0,
			);
		}

		// --- PASO 4: Validar username y email ---
		$username = sanitize_user( $username, true );
		$email    = sanitize_email( $email );

		$validacion = $this->validar_datos_registro( $username, $email, $password );
		if ( ! $validacion['valido'] ) {
			return array(
				'exito'   => false,
				'mensaje' => $validacion['mensaje'],
				'user_id' => 0,
			);
		}

		// --- PASO 5: Crear cuenta de WordPress ---
		$display_name = ! empty( $display_name )
			? sanitize_text_field( $display_name )
			: $username;

		$user_id = wp_insert_user( array(
			'user_login'   => $username,
			'user_email'   => $email,
			'user_pass'    => $password,
			'display_name' => $display_name,
			'role'         => self::ROL_PENCA,
		) );

		if ( is_wp_error( $user_id ) ) {
			Penca_Helpers::log(
				'access-codes', 'error',
				"Error al crear usuario. Código: {$codigo_limpio} | Error: " . $user_id->get_error_message()
			);
			return array(
				'exito'   => false,
				'mensaje' => 'Error al crear la cuenta. ' . $user_id->get_error_message(),
				'user_id' => 0,
			);
		}

		// --- PASO 6: Marcar código como usado ---
		$codigo_marcado = $this->marcar_codigo_usado( $datos_codigo->id, $user_id, $ip );

		if ( ! $codigo_marcado ) {
			// Si falla el marcado del código, eliminar el usuario creado.
			// Mantener la integridad: no dejar usuarios sin código marcado.
			wp_delete_user( $user_id );
			Penca_Helpers::log(
				'access-codes', 'error',
				"Error al marcar código. Se revirtió la creación del usuario {$user_id}."
			);
			return array(
				'exito'   => false,
				'mensaje' => 'Error interno al procesar el código. Por favor contactá al administrador.',
				'user_id' => 0,
			);
		}

		// Limpiar intentos fallidos de esta IP (registro exitoso).
		Penca_Helpers::limpiar_intentos_fallidos( $ip, 'registro' );

		// --- PASO 7: Login automático ---
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true );

		Penca_Helpers::log(
			'access-codes', 'info',
			"Registro exitoso. Usuario: {$user_id} | Código: {$codigo_limpio} | IP: {$ip}",
			array(
				'user_id'  => $user_id,
				'codigo'   => $codigo_limpio,
				'username' => $username,
				'email'    => $email,
			)
		);

		return array(
			'exito'   => true,
			'mensaje' => '¡Registro exitoso! Ya estás participando en la Penca WC2026.',
			'user_id' => $user_id,
		);
	}

	// =========================================================================
	// GESTIÓN DE CÓDIGOS
	// =========================================================================

	/**
	 * Obtiene un código de la BD por su string.
	 *
	 * @param string $codigo Código en formato MUN26-XXXX-XXXX.
	 * @return object|null Objeto con datos del código, o null si no existe.
	 */
	public function obtener_codigo( string $codigo ): ?object {
		global $wpdb;

		$tabla = Penca_Helpers::tabla( 'codes' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$tabla} WHERE codigo = %s", $codigo )
		);
	}

	/**
	 * Marca un código como usado.
	 *
	 * @param int    $codigo_id ID del código en wp_wc_codes.
	 * @param int    $user_id   ID del usuario que lo usó.
	 * @param string $ip        IP del request.
	 * @return bool
	 */
	private function marcar_codigo_usado( int $codigo_id, int $user_id, string $ip ): bool {
		global $wpdb;

		$tabla = Penca_Helpers::tabla( 'codes' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$resultado = $wpdb->update(
			$tabla,
			array(
				'status'  => 'used',
				'user_id' => $user_id,
				'used_at' => Penca_Helpers::ahora_utc_sql(),
				'used_ip' => $ip,
			),
			array( 'id' => $codigo_id, 'status' => 'available' ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d', '%s' )
		);

		// rows_affected = 0 puede significar que el código ya fue tomado
		// por otra request concurrente (race condition). Rollback implícito.
		return false !== $resultado && $wpdb->rows_affected > 0;
	}

	/**
	 * Genera N códigos únicos y los guarda en la BD.
	 *
	 * Verifica unicidad antes de insertar.
	 * Si hay colisiones (muy improbable), reintenta hasta 3 veces.
	 *
	 * @param int    $cantidad   Cantidad de códigos a generar.
	 * @param int    $admin_id   ID del admin que los genera.
	 * @param string $notas      Notas opcionales (ej: "Lote para evento X").
	 * @return array{generados: int, codigos: array}
	 */
	public function generar_codigos( int $cantidad, int $admin_id, string $notas = '' ): array {
		global $wpdb;

		$tabla    = Penca_Helpers::tabla( 'codes' );
		$generados = 0;
		$codigos   = array();
		$ahora    = Penca_Helpers::ahora_utc_sql();
		$cantidad  = min( $cantidad, 500 ); // Límite de seguridad.

		for ( $i = 0; $i < $cantidad; $i++ ) {
			$codigo   = null;
			$intentos = 0;

			// Generar y verificar unicidad (máx 3 reintentos por colisión).
			while ( $intentos < 3 ) {
				$candidato = Penca_Helpers::generar_codigo();

				// Verificar si ya existe.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$existe = $wpdb->get_var(
					$wpdb->prepare( "SELECT id FROM {$tabla} WHERE codigo = %s", $candidato )
				);

				if ( ! $existe ) {
					$codigo = $candidato;
					break;
				}

				++$intentos;
			}

			if ( null === $codigo ) {
				Penca_Helpers::log(
					'access-codes', 'warning',
					"No se pudo generar código único después de 3 intentos en iteración {$i}."
				);
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$ok = $wpdb->insert(
				$tabla,
				array(
					'codigo'     => $codigo,
					'status'     => 'available',
					'created_by' => $admin_id,
					'created_at' => $ahora,
					'notas'      => sanitize_text_field( $notas ),
				),
				array( '%s', '%s', '%d', '%s', '%s' )
			);

			if ( $ok ) {
				++$generados;
				$codigos[] = $codigo;
			}
		}

		Penca_Helpers::log(
			'access-codes', 'info',
			"Generados {$generados}/{$cantidad} códigos por admin {$admin_id}."
		);

		return array(
			'generados' => $generados,
			'codigos'   => $codigos,
		);
	}

	/**
	 * Obtiene la lista de códigos con filtros opcionales.
	 *
	 * @param array $filtros Filtros: ['status' => 'available', 'limite' => 100].
	 * @return array
	 */
	public function obtener_codigos( array $filtros = array() ): array {
		global $wpdb;

		$tabla  = Penca_Helpers::tabla( 'codes' );
		$where  = array( '1=1' );
		$params = array();
		$limite = min( (int) ( $filtros['limite'] ?? 200 ), 1000 );

		if ( ! empty( $filtros['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = sanitize_text_field( $filtros['status'] );
		}

		$where_sql = implode( ' AND ', $where );

		if ( ! empty( $params ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$codigos = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT c.*, u.display_name AS nombre_usuario
					FROM {$tabla} c
					LEFT JOIN {$wpdb->users} u ON c.user_id = u.ID
					WHERE {$where_sql}
					ORDER BY c.created_at DESC
					LIMIT %d",
					...[...$params, $limite]
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$codigos = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT c.*, u.display_name AS nombre_usuario
					FROM {$tabla} c
					LEFT JOIN {$wpdb->users} u ON c.user_id = u.ID
					WHERE {$where_sql}
					ORDER BY c.created_at DESC
					LIMIT %d",
					$limite
				)
			);
		}

		return $codigos ?? array();
	}

	/**
	 * Bloquea un código manualmente.
	 *
	 * @param int    $codigo_id ID del código.
	 * @param string $motivo    Razón del bloqueo.
	 * @return bool
	 */
	public function bloquear_codigo( int $codigo_id, string $motivo = '' ): bool {
		global $wpdb;

		$tabla = Penca_Helpers::tabla( 'codes' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$resultado = $wpdb->update(
			$tabla,
			array(
				'status'         => 'blocked',
				'blocked_at'     => Penca_Helpers::ahora_utc_sql(),
				'blocked_reason' => sanitize_text_field( $motivo ),
			),
			array( 'id' => $codigo_id, 'status' => 'available' ),
			array( '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);

		return false !== $resultado && $wpdb->rows_affected > 0;
	}

	// =========================================================================
	// VALIDACIONES
	// =========================================================================

	/**
	 * Valida los datos de registro del usuario.
	 *
	 * @param string $username Username elegido.
	 * @param string $email    Email.
	 * @param string $password Contraseña.
	 * @return array{valido: bool, mensaje: string}
	 */
	private function validar_datos_registro( string $username, string $email, string $password ): array {
		// Username.
		if ( empty( $username ) || strlen( $username ) < 3 ) {
			return array( 'valido' => false, 'mensaje' => 'El nombre de usuario debe tener al menos 3 caracteres.' );
		}

		if ( strlen( $username ) > 60 ) {
			return array( 'valido' => false, 'mensaje' => 'El nombre de usuario es demasiado largo.' );
		}

		if ( username_exists( $username ) ) {
			return array( 'valido' => false, 'mensaje' => 'Ese nombre de usuario ya está en uso.' );
		}

		// Email.
		if ( empty( $email ) || ! is_email( $email ) ) {
			return array( 'valido' => false, 'mensaje' => 'El email no es válido.' );
		}

		if ( email_exists( $email ) ) {
			return array( 'valido' => false, 'mensaje' => 'Ese email ya está registrado.' );
		}

		// Password.
		if ( empty( $password ) || strlen( $password ) < 8 ) {
			return array( 'valido' => false, 'mensaje' => 'La contraseña debe tener al menos 8 caracteres.' );
		}

		return array( 'valido' => true, 'mensaje' => '' );
	}

	// =========================================================================
	// EXPORTACIÓN CSV
	// =========================================================================

	/**
	 * Exporta todos los códigos a CSV para descargar desde el admin.
	 *
	 * @return void
	 */
	public function exportar_codigos_csv(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permisos insuficientes.' );
		}

		check_admin_referer( 'penca_exportar_codigos' );

		$codigos = $this->obtener_codigos( array( 'limite' => 1000 ) );

		// Headers HTTP para descarga de archivo.
		$filename = 'penca-wc2026-codigos-' . gmdate( 'Y-m-d' ) . '.csv';
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' );

		// BOM UTF-8 para compatibilidad con Excel.
		fputs( $output, "\xEF\xBB\xBF" );

		// Encabezados del CSV.
		fputcsv( $output, array(
			'Código', 'Estado', 'Usuario', 'Email', 'Usado el (Uruguay)',
			'IP de uso', 'Creado el', 'Notas',
		) );

		foreach ( $codigos as $codigo ) {
			$used_at_uy = $codigo->used_at
				? Penca_Helpers::formatear_fecha( $codigo->used_at, 'completo' )
				: '';

			$email_usuario = '';
			if ( $codigo->user_id ) {
				$user_data     = get_userdata( (int) $codigo->user_id );
				$email_usuario = $user_data ? $user_data->user_email : '';
			}

			fputcsv( $output, array(
				$codigo->codigo,
				$codigo->status,
				$codigo->nombre_usuario ?? '',
				$email_usuario,
				$used_at_uy,
				$codigo->used_ip ?? '',
				Penca_Helpers::formatear_fecha( $codigo->created_at, 'completo' ),
				$codigo->notas ?? '',
			) );
		}

		fclose( $output );
		exit;
	}

	// =========================================================================
	// SHORTCODE
	// =========================================================================

	/**
	 * Shortcode [penca_registro] para incrustar el formulario de registro.
	 *
	 * @param array $atts Atributos del shortcode.
	 * @return string HTML del formulario.
	 */
	public function shortcode_registro( array $atts = array() ): string {
		// Si el usuario ya está logueado, no mostrar el formulario.
		if ( is_user_logged_in() ) {
			return '<p class="penca-aviso">' .
				esc_html__( 'Ya estás participando en la Penca WC2026.', 'penca-wc2026' ) .
				'</p>';
		}

		ob_start();
		$vista = PENCA_PLUGIN_DIR . 'public/views/register.php';
		if ( file_exists( $vista ) ) {
			include $vista;
		}
		return ob_get_clean();
	}

	// =========================================================================
	// AJAX
	// =========================================================================

	/**
	 * Handler AJAX para registrar usuario.
	 *
	 * @return void
	 */
	public function ajax_registrar_usuario(): void {
		check_ajax_referer( 'penca_registro_nonce', 'nonce' );

		$codigo       = sanitize_text_field( $_POST['codigo'] ?? '' );
		$username     = sanitize_user( $_POST['username'] ?? '' );
		$email        = sanitize_email( $_POST['email'] ?? '' );
		$password     = $_POST['password'] ?? '';
		$display_name = sanitize_text_field( $_POST['display_name'] ?? '' );

		if ( empty( $codigo ) || empty( $username ) || empty( $email ) || empty( $password ) ) {
			wp_send_json_error( array( 'mensaje' => 'Todos los campos son obligatorios.' ) );
		}

		$resultado = $this->registrar_usuario( $codigo, $username, $email, $password, $display_name );

		if ( $resultado['exito'] ) {
			wp_send_json_success( array(
				'mensaje'     => $resultado['mensaje'],
				'redirect_url' => get_option( 'penca_redirect_post_registro', home_url( '/' ) ),
			) );
		} else {
			wp_send_json_error( array( 'mensaje' => $resultado['mensaje'] ) );
		}
	}

	/**
	 * Handler AJAX para verificar si un código existe (feedback en tiempo real).
	 *
	 * @return void
	 */
	public function ajax_verificar_codigo(): void {
		$codigo = sanitize_text_field( $_POST['codigo'] ?? '' );

		$formato_valido = Penca_Helpers::validar_formato_codigo( $codigo );

		if ( null === $formato_valido ) {
			wp_send_json_error( array( 'mensaje' => 'Formato de código inválido.' ) );
		}

		$datos_codigo = $this->obtener_codigo( $formato_valido );

		if ( ! $datos_codigo ) {
			wp_send_json_error( array( 'mensaje' => 'Código no encontrado.' ) );
		}

		if ( 'available' !== $datos_codigo->status ) {
			$mensaje = 'used' === $datos_codigo->status ? 'Código ya utilizado.' : 'Código bloqueado.';
			wp_send_json_error( array( 'mensaje' => $mensaje ) );
		}

		wp_send_json_success( array( 'mensaje' => '✓ Código válido.' ) );
	}

	/**
	 * Handler AJAX para generar códigos (solo admin).
	 *
	 * @return void
	 */
	public function ajax_generar_codigos(): void {
		check_ajax_referer( 'penca_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'mensaje' => 'Permisos insuficientes.' ), 403 );
		}

		$cantidad  = min( (int) ( $_POST['cantidad'] ?? 10 ), 500 );
		$notas     = sanitize_text_field( $_POST['notas'] ?? '' );
		$admin_id  = get_current_user_id();

		$resultado = $this->generar_codigos( $cantidad, $admin_id, $notas );

		wp_send_json_success( array(
			'mensaje'   => "Se generaron {$resultado['generados']} códigos.",
			'generados' => $resultado['generados'],
			'codigos'   => $resultado['codigos'],
		) );
	}

	/**
	 * Shortcode [penca_mis_pronosticos] — muestra la grilla de pronósticos del usuario.
	 *
	 * @return string HTML.
	 */
	public function shortcode_mis_pronosticos(): string {
		if ( ! is_user_logged_in() ) {
			$login_url = wp_login_url( get_permalink() );
			return '<p class="penca-aviso">' .
				'Para ver y cargar tus pronósticos tenés que ' .
				'<a href="' . esc_url( $login_url ) . '">iniciar sesión</a>.' .
				'</p>';
		}

		ob_start();
		$vista = PENCA_PLUGIN_DIR . 'public/views/my-predictions.php';
		if ( file_exists( $vista ) ) {
			include $vista;
		}
		return ob_get_clean();
	}

	/**
	 * Cuenta cuántos usuarios subscriber están registrados en WordPress.
	 * Usado para validar el tope de 1.000 participantes.
	 *
	 * @return int
	 */
	public function contar_usuarios_registrados(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(u.ID)
				FROM {$wpdb->users} u
				INNER JOIN {$wpdb->usermeta} um
					ON u.ID = um.user_id
					AND um.meta_key = %s
					AND um.meta_value LIKE %s",
				$wpdb->get_blog_prefix() . 'capabilities',
				'%subscriber%'
			)
		);
	}

}