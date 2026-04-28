<?php
/**
 * Utilidades globales del plugin Penca WC2026.
 *
 * Clase de métodos estáticos disponibles para todos los módulos.
 * Centraliza: conversión de zonas horarias, formateo de fechas,
 * logging de eventos y envío de alertas al admin.
 *
 * Todos los métodos son estáticos para facilitar el uso sin
 * necesidad de instanciar la clase.
 *
 * @package PencaWC2026
 * @since   1.0.0
 */

// Prevenir acceso directo al archivo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase Penca_Helpers.
 */
class Penca_Helpers {

	// =========================================================================
	// ZONA HORARIA Y TIMESTAMPS
	// =========================================================================

	/**
	 * Convierte un timestamp UTC a hora de Uruguay (America/Montevideo, UTC-3).
	 *
	 * Uruguay no tiene horario de verano desde 2015, por lo que el offset
	 * es fijo en UTC-3. Sin embargo, usamos la zona horaria real (no el offset
	 * fijo) para ser precisos ante cualquier cambio futuro.
	 *
	 * Esta función acepta múltiples formatos de entrada:
	 * - Objeto DateTimeInterface (DateTime, DateTimeImmutable)
	 * - String de fecha/hora compatible con DateTime (ej: '2026-06-11 20:00:00')
	 * - Integer con timestamp Unix
	 *
	 * @param $timestamp_utc Fecha/hora en UTC.
	 * @return DateTimeImmutable Objeto DateTime en zona horaria de Uruguay.
	 * @throws \InvalidArgumentException Si el tipo de entrada no es válido.
	 * @throws \Exception Si el string de fecha no es válido.
	 */
	public static function utc_a_uruguay( $timestamp_utc ): DateTimeImmutable {

		$zona_horaria_uy = new DateTimeZone( PENCA_TIMEZONE );

		// Si ya es un objeto DateTimeInterface, convertir la zona.
		if ( $timestamp_utc instanceof DateTimeInterface ) {
			$dt_immutable = DateTimeImmutable::createFromInterface( $timestamp_utc );
			return $dt_immutable->setTimezone( $zona_horaria_uy );
		}

		// Si es un timestamp Unix (entero), crear DateTime desde él.
		if ( is_int( $timestamp_utc ) ) {
			$dt = new DateTimeImmutable( '@' . $timestamp_utc, new DateTimeZone( 'UTC' ) );
			return $dt->setTimezone( $zona_horaria_uy );
		}

		// Si es un string, asumir que está en UTC y convertir.
		if ( is_string( $timestamp_utc ) ) {
			// Crear en UTC explícitamente para evitar ambigüedades.
			$dt = new DateTimeImmutable( $timestamp_utc, new DateTimeZone( 'UTC' ) );
			return $dt->setTimezone( $zona_horaria_uy );
		}

		// Si llegamos acá, el tipo no es soportado.
		throw new \InvalidArgumentException(
			sprintf(
				'Penca_Helpers::utc_a_uruguay() recibió un tipo no soportado: %s',
				gettype( $timestamp_utc )
			)
		);
	}

	/**
	 * Convierte un datetime de Uruguay de vuelta a UTC para almacenar en BD.
	 *
	 * Función inversa de utc_a_uruguay(). Útil cuando el admin ingresa
	 * una fecha/hora en horario uruguayo y hay que guardarla en UTC.
	 *
	 * @param DateTimeInterface|string $datetime_uruguay Fecha/hora en hora Uruguay.
	 * @return DateTimeImmutable Objeto DateTime en UTC.
	 * @throws \Exception Si el string de fecha no es válido.
	 */
	public static function uruguay_a_utc( DateTimeInterface|string $datetime_uruguay ): DateTimeImmutable {

		$zona_utc = new DateTimeZone( 'UTC' );
		$zona_uy  = new DateTimeZone( PENCA_TIMEZONE );

		if ( $datetime_uruguay instanceof DateTimeInterface ) {
			$dt_immutable = DateTimeImmutable::createFromInterface( $datetime_uruguay );
			return $dt_immutable->setTimezone( $zona_utc );
		}

		// Crear el DateTime asumiendo que el string está en hora Uruguay.
		$dt = new DateTimeImmutable( $datetime_uruguay, $zona_uy );
		return $dt->setTimezone( $zona_utc );
	}

	/**
	 * Obtiene el timestamp UTC actual como objeto DateTimeImmutable.
	 *
	 * Centralizado aquí para facilitar mocking en tests.
	 *
	 * @return DateTimeImmutable Momento actual en UTC.
	 */
	public static function ahora_utc(): DateTimeImmutable {
		return new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Obtiene el timestamp UTC actual en formato MySQL (YYYY-MM-DD HH:MM:SS).
	 *
	 * Útil para insertar directamente en columnas DATETIME de la BD.
	 *
	 * @return string Timestamp en formato MySQL, UTC.
	 */
	public static function ahora_utc_sql(): string {
		return self::ahora_utc()->format( 'Y-m-d H:i:s' );
	}

	// =========================================================================
	// FORMATEO DE FECHAS
	// =========================================================================

	/**
	 * Formatea una fecha/hora UTC para mostrarla al usuario en hora Uruguay.
	 *
	 * Retorna una string legible con la fecha y hora del partido en horario
	 * uruguayo. El formato por defecto es "Lun 11 Jun · 17:00 hs".
	 *
	 * Formatos disponibles:
	 * - 'completo'  → "Lunes 11 de Junio de 2026 · 17:00 hs"
	 * - 'corto'     → "Lun 11 Jun · 17:00 hs" (default)
	 * - 'hora'      → "17:00 hs"
	 * - 'fecha'     → "Lun 11 Jun"
	 * - 'iso'       → "2026-06-11T17:00:00-03:00" (para atributos HTML datetime)
	 * - 'mysql'     → "2026-06-11 17:00:00" (para comparaciones internas)
	 *
	 * @param $timestamp_utc Fecha/hora en UTC.
	 * @param string                       $formato       Formato de salida.
	 * @return string Fecha/hora formateada en hora Uruguay.
	 */
	public static function formatear_fecha(
		$timestamp_utc,
		string $formato = 'corto'
	): string {

		// Convertir a hora Uruguay primero.
		try {
			$dt_uy = self::utc_a_uruguay( $timestamp_utc );
		} catch ( \Exception $e ) {
			// Si hay error de conversión, retornar string vacío y logear.
			self::log( 'sistema', 'error', 'Error en formatear_fecha: ' . $e->getMessage() );
			return '';
		}

		// Nombres en español para días y meses.
		$dias   = array( 'Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb' );
		$dias_completos = array( 'Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado' );
		$meses  = array( '', 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic' );
		$meses_completos = array(
			'',
			'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
			'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre',
		);

		$dia_semana = (int) $dt_uy->format( 'w' ); // 0 = domingo
		$dia_mes    = (int) $dt_uy->format( 'j' );
		$mes_num    = (int) $dt_uy->format( 'n' );
		$anio       = $dt_uy->format( 'Y' );
		$hora       = $dt_uy->format( 'H:i' );

		switch ( $formato ) {
			case 'completo':
				return sprintf(
					'%s %d de %s de %s · %s hs',
					$dias_completos[ $dia_semana ],
					$dia_mes,
					$meses_completos[ $mes_num ],
					$anio,
					$hora
				);

			case 'corto':
				return sprintf(
					'%s %d %s · %s hs',
					$dias[ $dia_semana ],
					$dia_mes,
					$meses[ $mes_num ],
					$hora
				);

			case 'hora':
				return $hora . ' hs';

			case 'fecha':
				return sprintf(
					'%s %d %s',
					$dias[ $dia_semana ],
					$dia_mes,
					$meses[ $mes_num ]
				);

			case 'iso':
				// Formato ISO 8601 con offset, útil para atributos HTML datetime.
				return $dt_uy->format( 'c' );

			case 'mysql':
				return $dt_uy->format( 'Y-m-d H:i:s' );

			default:
				// Si el formato no es reconocido, usar 'corto' como fallback.
				return sprintf(
					'%s %d %s · %s hs',
					$dias[ $dia_semana ],
					$dia_mes,
					$meses[ $mes_num ],
					$hora
				);
		}
	}

	/**
	 * Retorna hace cuánto tiempo ocurrió un evento, en lenguaje natural.
	 *
	 * Útil para mostrar "Hace 5 minutos", "Hace 2 horas" en el visor de logs.
	 *
	 * @param DateTimeInterface|string $timestamp_utc Timestamp del evento en UTC.
	 * @return string Tiempo transcurrido en lenguaje natural.
	 */
	public static function tiempo_transcurrido( DateTimeInterface|string $timestamp_utc ): string {

		try {
			if ( is_string( $timestamp_utc ) ) {
				$dt_evento = new DateTimeImmutable( $timestamp_utc, new DateTimeZone( 'UTC' ) );
			} else {
				$dt_evento = DateTimeImmutable::createFromInterface( $timestamp_utc );
			}

			$ahora   = self::ahora_utc();
			$diff    = $ahora->diff( $dt_evento );
			$segundos = abs( $ahora->getTimestamp() - $dt_evento->getTimestamp() );

		} catch ( \Exception $e ) {
			return 'fecha desconocida';
		}

		if ( $segundos < 60 ) {
			return 'Hace menos de 1 minuto';
		}

		if ( $segundos < 3600 ) {
			$mins = (int) floor( $segundos / 60 );
			return sprintf( 'Hace %d %s', $mins, 1 === $mins ? 'minuto' : 'minutos' );
		}

		if ( $segundos < 86400 ) {
			$horas = (int) floor( $segundos / 3600 );
			return sprintf( 'Hace %d %s', $horas, 1 === $horas ? 'hora' : 'horas' );
		}

		$dias = (int) floor( $segundos / 86400 );
		return sprintf( 'Hace %d %s', $dias, 1 === $dias ? 'día' : 'días' );
	}

	// =========================================================================
	// LOGGING DE EVENTOS
	// =========================================================================

	/**
	 * Registra un evento en la tabla wp_wc_logs.
	 *
	 * Función central de logging del plugin. Todos los módulos deben
	 * usar esta función en lugar de escribir directamente en la tabla.
	 *
	 * Niveles de severidad:
	 * - 'info'     → Eventos normales del sistema (sincronizaciones, cálculos)
	 * - 'warning'  → Situaciones anómalas no críticas (API lenta, retry)
	 * - 'error'    → Errores recuperables (API falló, se usó fallback)
	 * - 'critical' → Errores que requieren atención inmediata (ambas APIs caídas)
	 *
	 * @param string $modulo   Nombre del módulo que genera el log (ej: 'api-sync').
	 * @param string $nivel    Nivel de severidad: info | warning | error | critical.
	 * @param string $mensaje  Descripción del evento.
	 * @param array  $contexto Datos adicionales para debugging (se serializa como JSON).
	 * @param int    $user_id  ID del usuario relacionado (0 si no aplica).
	 * @return int|false ID del registro insertado, o false si falló.
	 */
	public static function log(
		string $modulo,
		string $nivel,
		string $mensaje,
		array $contexto = array(),
		int $user_id = 0
	): ?int {

		global $wpdb;

		// Validar nivel para evitar valores no esperados.
		$niveles_validos = array( 'info', 'warning', 'error', 'critical' );
		if ( ! in_array( $nivel, $niveles_validos, true ) ) {
			$nivel = 'info';
		}

		$tabla = $wpdb->prefix . PENCA_TABLE_PREFIX . 'logs';

		// Obtener IP del request actual (si existe).
		$ip = self::obtener_ip_cliente();

		$datos = array(
			'modulo'     => sanitize_text_field( $modulo ),
			'nivel'      => $nivel,
			'mensaje'    => sanitize_textarea_field( $mensaje ),
			'contexto'   => ! empty( $contexto ) ? wp_json_encode( $contexto ) : null,
			'user_id'    => $user_id > 0 ? $user_id : null,
			'ip_address' => $ip,
			'created_at' => self::ahora_utc_sql(),
		);

		$formatos = array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' );

		// Ajustar formato si user_id o contexto son NULL.
		if ( null === $datos['user_id'] ) {
			$formatos[4] = null; // Será manejado por WordPress como NULL.
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$resultado = $wpdb->insert( $tabla, $datos, $formatos );

		if ( false === $resultado ) {
			// No podemos logear el error de log en la BD (loop infinito).
			// Escribir en el log de errores de PHP como último recurso.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'[penca-wc2026] Error al insertar log en BD. Módulo: %s | Nivel: %s | Mensaje: %s | DB Error: %s',
					$modulo,
					$nivel,
					$mensaje,
					$wpdb->last_error
				)
			);
			return false;
		}

		return $wpdb->insert_id;
	}

	/**
	 * Obtiene los últimos N registros del log, con filtros opcionales.
	 *
	 * Usado por el visor de logs del panel admin.
	 *
	 * @param int    $limite  Cantidad máxima de registros a retornar.
	 * @param string $modulo  Filtrar por módulo (vacío = todos).
	 * @param string $nivel   Filtrar por nivel (vacío = todos).
	 * @return array<int, object> Array de objetos con los registros del log.
	 */
	public static function obtener_logs(
		int $limite = 100,
		string $modulo = '',
		string $nivel = ''
	): array {

		global $wpdb;

		$tabla  = $wpdb->prefix . PENCA_TABLE_PREFIX . 'logs';
		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $modulo ) ) {
			$where[]  = 'modulo = %s';
			$params[] = sanitize_text_field( $modulo );
		}

		if ( ! empty( $nivel ) ) {
			$where[]  = 'nivel = %s';
			$params[] = sanitize_text_field( $nivel );
		}

		$params[] = max( 1, min( $limite, 1000 ) ); // Límite máximo: 1000.

		$where_sql = implode( ' AND ', $where );

		if ( ! empty( $params ) && count( $params ) > 1 ) {
			// Hay filtros de módulo o nivel.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$resultados = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$tabla} WHERE {$where_sql} ORDER BY id DESC LIMIT %d",
					...$params
				)
			);
		} else {
			// Sin filtros.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$resultados = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$tabla} ORDER BY id DESC LIMIT %d",
					$limite
				)
			);
		}

		return $resultados ?? array();
	}

	/**
	 * Elimina logs anteriores a N días. Para usar en limpieza periódica.
	 *
	 * @param int $dias_a_mantener Mantener logs de los últimos N días. Default: 30.
	 * @return int Cantidad de registros eliminados.
	 */
	public static function limpiar_logs_antiguos( int $dias_a_mantener = 30 ): int {
		global $wpdb;

		$tabla = $wpdb->prefix . PENCA_TABLE_PREFIX . 'logs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$tabla} WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
				max( 1, $dias_a_mantener )
			)
		);

		$eliminados = $wpdb->rows_affected;

		self::log(
			'sistema',
			'info',
			sprintf( 'Limpieza de logs: %d registros eliminados (más de %d días)', $eliminados, $dias_a_mantener )
		);

		return $eliminados;
	}

	// =========================================================================
	// ALERTAS POR EMAIL AL ADMIN
	// =========================================================================

	/**
	 * Envía una alerta por email al administrador del sitio.
	 *
	 * Se usa para situaciones críticas que requieren atención inmediata:
	 * - Ambas APIs caídas
	 * - Error de base de datos
	 * - Cualquier evento de nivel 'critical'
	 *
	 * Incluye rate limiting básico por transient para evitar spam de emails
	 * ante errores repetitivos (máximo 1 email por tipo de alerta por hora).
	 *
	 * @param string $asunto    Asunto del email.
	 * @param string $mensaje   Cuerpo del email (texto plano o HTML).
	 * @param array  $contexto  Datos adicionales que se adjuntan al email.
	 * @param bool   $forzar    Si es true, ignora el rate limiting. Default: false.
	 * @return bool true si el email se envió, false si no.
	 */
	public static function alertar_admin(
		string $asunto,
		string $mensaje,
		array $contexto = array(),
		bool $forzar = false
	): bool {

		// Rate limiting: evitar spam de emails.
		// Usamos un transient con clave basada en el asunto.
		if ( ! $forzar ) {
			$clave_transient = 'penca_alerta_' . md5( $asunto );
			if ( get_transient( $clave_transient ) ) {
				// Ya se envió este tipo de alerta en la última hora. No repetir.
				return false;
			}
			// Marcar que ya enviamos esta alerta. Expira en 1 hora.
			set_transient( $clave_transient, true, HOUR_IN_SECONDS );
		}

		// Email del administrador del sitio.
		$email_admin = get_option( 'admin_email' );

		if ( empty( $email_admin ) ) {
			self::log( 'sistema', 'error', 'No se pudo enviar alerta: admin_email no configurado.' );
			return false;
		}

		// Construir el cuerpo del email.
		$timestamp_uy = self::formatear_fecha( self::ahora_utc_sql(), 'completo' );

		$cuerpo = sprintf(
			"🚨 ALERTA — Penca WC2026\n\n" .
			"Fecha/hora (Uruguay): %s\n\n" .
			"Mensaje:\n%s\n\n",
			$timestamp_uy,
			$mensaje
		);

		// Agregar contexto si existe.
		if ( ! empty( $contexto ) ) {
			$cuerpo .= "Datos adicionales:\n" . wp_json_encode( $contexto, JSON_PRETTY_PRINT ) . "\n\n";
		}

		$cuerpo .= sprintf(
			"---\n" .
			"Este email fue generado automáticamente por el plugin Penca WC2026.\n" .
			"Sitio: %s\n" .
			"Ver logs: %s",
			get_site_url(),
			admin_url( 'admin.php?page=penca-logs' )
		);

		// Headers para email de texto plano.
		$headers = array(
			'Content-Type: text/plain; charset=UTF-8',
			'From: Penca WC2026 <' . $email_admin . '>',
		);

		$asunto_completo = '[Penca WC2026] ' . $asunto;

		$enviado = wp_mail( $email_admin, $asunto_completo, $cuerpo, $headers );

		// Registrar el intento en el log.
		self::log(
			'sistema',
			$enviado ? 'info' : 'error',
			sprintf(
				'Alerta admin %s: "%s"',
				$enviado ? 'enviada' : 'FALLÓ al enviar',
				$asunto
			),
			array(
				'email_destino' => $email_admin,
				'asunto'        => $asunto_completo,
			)
		);

		return $enviado;
	}

	/**
	 * Atajo para enviar alerta crítica: loguea como 'critical' Y envía email.
	 *
	 * Usar cuando ambas condiciones son necesarias simultáneamente.
	 *
	 * @param string $modulo   Módulo que genera la alerta.
	 * @param string $mensaje  Descripción del problema.
	 * @param array  $contexto Datos adicionales.
	 * @return void
	 */
	public static function alerta_critica( string $modulo, string $mensaje, array $contexto = array() ): void {
		// Primero logear en BD.
		self::log( $modulo, 'critical', $mensaje, $contexto );

		// Luego enviar email al admin.
		self::alertar_admin(
			sprintf( 'Error crítico en módulo: %s', $modulo ),
			$mensaje,
			$contexto
		);
	}

	// =========================================================================
	// UTILIDADES DE SEGURIDAD Y RED
	// =========================================================================

	/**
	 * Obtiene la IP real del cliente, considerando proxies y CDNs.
	 *
	 * Maneja los headers más comunes usados por proxies.
	 * Sanitiza la IP para evitar inyecciones.
	 *
	 * @return string IP del cliente, o '0.0.0.0' si no se puede determinar.
	 */
	public static function obtener_ip_cliente(): string {

		// Lista de headers a verificar, en orden de prioridad.
		$headers_posibles = array(
			'HTTP_CF_CONNECTING_IP', // Cloudflare.
			'HTTP_X_FORWARDED_FOR',  // Proxies estándar (puede ser lista).
			'HTTP_X_REAL_IP',        // Nginx proxy.
			'REMOTE_ADDR',           // IP directa (fallback).
		);

		foreach ( $headers_posibles as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				// X_FORWARDED_FOR puede tener múltiples IPs separadas por coma.
				$ip_raw = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
				$ip     = trim( explode( ',', $ip_raw )[0] );

				// Validar que sea una IP válida.
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return '0.0.0.0';
	}

	/**
	 * Verifica si una IP está bajo rate limiting para una acción específica.
	 *
	 * Usa transients de WordPress para trackear intentos fallidos.
	 * La clave del transient incluye la IP y el tipo de acción.
	 *
	 * @param string $ip      IP a verificar.
	 * @param string $accion  Tipo de acción (ej: 'registro', 'login').
	 * @param int    $maximo  Máximo de intentos permitidos en la ventana.
	 * @param int    $ventana Ventana de tiempo en segundos. Default: 1 hora.
	 * @return bool true si está bloqueada, false si puede continuar.
	 */
	public static function esta_bajo_rate_limit(
		string $ip,
		string $accion,
		int $maximo = PENCA_RATE_LIMIT_MAX_ATTEMPTS,
		int $ventana = PENCA_RATE_LIMIT_WINDOW
	): bool {

		$clave    = 'penca_rl_' . md5( $ip . $accion );
		$intentos = (int) get_transient( $clave );

		return $intentos >= $maximo;
	}

	/**
	 * Incrementa el contador de intentos fallidos para rate limiting.
	 *
	 * Llamar cuando un intento falla (ej: código de acceso inválido).
	 *
	 * @param string $ip      IP del cliente.
	 * @param string $accion  Tipo de acción.
	 * @param int    $ventana Ventana de tiempo en segundos.
	 * @return int Cantidad de intentos acumulados.
	 */
	public static function incrementar_intentos_fallidos(
		string $ip,
		string $accion,
		int $ventana = PENCA_RATE_LIMIT_WINDOW
	): int {

		$clave    = 'penca_rl_' . md5( $ip . $accion );
		$intentos = (int) get_transient( $clave );

		$intentos++;

		// Si es el primer intento, crear el transient con TTL.
		// Si ya existe, actualizar el valor pero mantener el TTL original
		// (esto es un trade-off: set_transient reinicia el TTL).
		// Para máxima precisión se necesitaría usar la BD directamente.
		set_transient( $clave, $intentos, $ventana );

		return $intentos;
	}

	/**
	 * Limpia el contador de intentos fallidos para una IP+acción.
	 *
	 * Llamar cuando un intento tiene éxito (ej: código válido ingresado).
	 *
	 * @param string $ip     IP del cliente.
	 * @param string $accion Tipo de acción.
	 * @return void
	 */
	public static function limpiar_intentos_fallidos( string $ip, string $accion ): void {
		$clave = 'penca_rl_' . md5( $ip . $accion );
		delete_transient( $clave );
	}

	// =========================================================================
	// UTILIDADES GENERALES
	// =========================================================================

	/**
	 * Sanitiza y valida el formato de un código de acceso.
	 *
	 * Formato esperado: MUN26-XXXX-XXXX donde X es alfanumérico mayúscula.
	 *
	 * @param string $codigo Código a validar.
	 * @return string|false Código sanitizado en mayúsculas, o false si no es válido.
	 */
	public static function validar_formato_codigo( string $codigo ): ?string {
		$codigo_limpio = strtoupper( trim( sanitize_text_field( $codigo ) ) );

		if ( preg_match( '/^MUN26-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $codigo_limpio ) ) {
			return $codigo_limpio;
		}

		return false;
	}

	/**
	 * Genera un código de acceso único en formato MUN26-XXXX-XXXX.
	 *
	 * Usa caracteres alfanuméricos mayúsculos excluyendo caracteres
	 * ambiguos (0, O, I, 1) para evitar confusión visual.
	 *
	 * @return string Código generado (sin verificar unicidad en BD).
	 */
	public static function generar_codigo(): string {
		// Caracteres permitidos (sin ambiguos: 0, O, I, 1).
		$chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

		$parte1 = '';
		$parte2 = '';

		for ( $i = 0; $i < 4; $i++ ) {
			$parte1 .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
			$parte2 .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
		}

		return sprintf( 'MUN26-%s-%s', $parte1, $parte2 );
	}

	/**
	 * Retorna el nombre de una tabla del plugin con el prefijo correcto.
	 *
	 * Centralizado para evitar concatenar prefijos manualmente en cada módulo.
	 *
	 * Uso: Penca_Helpers::tabla('matches') → 'wp_wc_matches'
	 *
	 * @param string $nombre Nombre corto de la tabla (sin prefijo).
	 * @return string Nombre completo de la tabla.
	 */
	public static function tabla( string $nombre ): string {
		global $wpdb;
		return $wpdb->prefix . PENCA_TABLE_PREFIX . $nombre;
	}

	/**
	 * Renderiza el HTML de una bandera de país usando flag-icons.
	 *
	 * Usa el código ISO 3166-1 alpha-2 del país (2 letras, ej: 'UY', 'AR', 'BR').
	 * Si no hay código, retorna string vacío.
	 *
	 * @param string $codigo Código ISO del país (2 letras).
	 * @param string $alt    Texto alternativo.
	 * @return string HTML de la bandera.
	 */
	public static function bandera( string $codigo, string $alt = '' ): string {
		$codigo = strtolower( trim( $codigo ) );
		if ( empty( $codigo ) || strlen( $codigo ) !== 2 ) {
			return '';
		}
		return sprintf(
			'<span class="fi fi-%s penca-bandera" title="%s" aria-label="%s"></span>',
			esc_attr( $codigo ),
			esc_attr( $alt ?: strtoupper( $codigo ) ),
			esc_attr( $alt ?: strtoupper( $codigo ) )
		);
	}

	/**
	 * Mapeo de nombre de equipo a código ISO 3166-1 alpha-2.
	 *
	 * Fallback para cuando la API no devuelve el campo home_team_code.
	 * Cubre los 48 equipos clasificados al Mundial 2026.
	 *
	 * @param string $nombre Nombre del equipo en inglés.
	 * @return string Código ISO de 2 letras en minúsculas, o '' si no se encuentra.
	 */
	public static function codigo_pais_desde_nombre( string $nombre ): string {
		$mapa = array(
			// América del Norte
			'USA'               => 'us', 'United States' => 'us', 'United States of America' => 'us',
			'Mexico'            => 'mx', 'México' => 'mx',
			'Canada'            => 'ca', 'Canadá' => 'ca',
			// América del Sur
			'Brazil'            => 'br', 'Brasil' => 'br',
			'Argentina'         => 'ar',
			'Uruguay'           => 'uy',
			'Colombia'          => 'co',
			'Chile'             => 'cl',
			'Ecuador'           => 'ec',
			'Paraguay'          => 'py',
			'Peru'              => 'pe', 'Perú' => 'pe',
			'Bolivia'           => 'bo',
			'Venezuela'         => 've',
			// Europa
			'Germany'           => 'de', 'Alemania' => 'de',
			'France'            => 'fr', 'Francia' => 'fr',
			'Spain'             => 'es', 'España' => 'es',
			'England'           => 'gb-eng',
			'Portugal'          => 'pt',
			'Netherlands'       => 'nl', 'Holland' => 'nl',
			'Belgium'           => 'be', 'Bélgica' => 'be',
			'Italy'             => 'it', 'Italia' => 'it',
			'Switzerland'       => 'ch', 'Suiza' => 'ch',
			'Croatia'           => 'hr', 'Croacia' => 'hr',
			'Serbia'            => 'rs',
			'Denmark'           => 'dk', 'Dinamarca' => 'dk',
			'Austria'           => 'at',
			'Turkey'            => 'tr', 'Türkiye' => 'tr', 'Turquía' => 'tr',
			'Ukraine'           => 'ua', 'Ucrania' => 'ua',
			'Poland'            => 'pl', 'Polonia' => 'pl',
			'Czech Republic'    => 'cz', 'Czechia' => 'cz',
			'Hungary'           => 'hu', 'Hungría' => 'hu',
			'Slovakia'          => 'sk',
			'Scotland'          => 'gb-sct',
			'Wales'             => 'gb-wls',
			'Bosnia-Herzegovina' => 'ba', 'Bosnia and Herzegovina' => 'ba',
			'Albania'           => 'al',
			'Romania'           => 'ro', 'Rumania' => 'ro',
			'Greece'            => 'gr', 'Grecia' => 'gr',
			'Iceland'           => 'is', 'Islandia' => 'is',
			// África
			'Morocco'           => 'ma', 'Marruecos' => 'ma',
			'South Africa'      => 'za', 'Sudáfrica' => 'za',
			'Egypt'             => 'eg', 'Egipto' => 'eg',
			'Nigeria'           => 'ng',
			'Senegal'           => 'sn',
			'Cameroon'          => 'cm', 'Camerún' => 'cm',
			'Ghana'             => 'gh',
			'Ivory Coast'       => 'ci', "Côte d'Ivoire" => 'ci',
			'Algeria'           => 'dz', 'Argelia' => 'dz',
			'Tunisia'           => 'tn', 'Túnez' => 'tn',
			'DR Congo'          => 'cd',
			'Mali'              => 'ml',
			// Asia
			'Japan'             => 'jp', 'Japón' => 'jp',
			'South Korea'       => 'kr', 'Korea Republic' => 'kr',
			'Saudi Arabia'      => 'sa', 'Arabia Saudita' => 'sa',
			'Iran'              => 'ir',
			'Australia'         => 'au',
			'Qatar'             => 'qa',
			'China'             => 'cn',
			'Indonesia'         => 'id',
			'Uzbekistan'        => 'uz',
			// CONCACAF
			'Costa Rica'        => 'cr',
			'Honduras'          => 'hn',
			'Panama'            => 'pa', 'Panamá' => 'pa',
			'Jamaica'           => 'jm',
			'Cuba'              => 'cu',
		);

		$nombre_limpio = trim( $nombre );
		if ( isset( $mapa[ $nombre_limpio ] ) ) {
			return $mapa[ $nombre_limpio ];
		}

		// Intento con primera palabra (ej: "South Korea" → buscar completo primero).
		return '';
	}

}