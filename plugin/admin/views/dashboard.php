<?php
/**
 * Vista: Dashboard Admin — Penca WC2026.
 *
 * Variables disponibles (inyectadas desde Penca_Admin::pagina_dashboard):
 * @var array  $estado_api     Estado del módulo api-sync
 * @var array  $estado_tablas  Estado de las tablas en BD
 * @var array  $logs_recientes Últimos errores del sistema
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="wrap penca-admin-wrap">
<h1 class="penca-admin-title">⚽ Penca WC2026 — Dashboard</h1>

<?php if ( ! $estado_tablas['todas_ok'] ) : ?>
<div class="notice notice-error is-dismissible">
	<p><strong>⚠ Error BD:</strong> Tablas faltantes.
	<a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>">Desactivá y reactivá el plugin.</a></p>
</div>
<?php endif; ?>

<?php if ( 'fallback' === $estado_api['api_activa'] ) : ?>
<div class="notice notice-warning">
	<p><strong>⚠ API en modo fallback</strong> — Se está usando TheSportsDB.
	<button class="button button-small" id="js-reactivar-api">Reactivar API primaria</button>
	<span id="js-reactivar-resultado"></span></p>
</div>
<?php endif; ?>

<!-- Stats Cards -->
<div class="penca-cards" id="js-stats-grid">
	<div class="penca-card">
		<div class="penca-card__icon">🏟️</div>
		<div class="penca-card__val" id="js-stat-partidos">—</div>
		<div class="penca-card__lbl">Partidos en BD</div>
	</div>
	<div class="penca-card">
		<div class="penca-card__icon">📝</div>
		<div class="penca-card__val" id="js-stat-pronosticos">—</div>
		<div class="penca-card__lbl">Pronósticos</div>
	</div>
	<div class="penca-card">
		<div class="penca-card__icon">👥</div>
		<div class="penca-card__val" id="js-stat-usuarios">—</div>
		<div class="penca-card__lbl">Usuarios activos</div>
	</div>
	<div class="penca-card">
		<div class="penca-card__icon">🎫</div>
		<div class="penca-card__val" id="js-stat-codigos">—</div>
		<div class="penca-card__lbl">Códigos usados</div>
	</div>
</div>

<!-- Estado API -->
<div class="penca-section">
	<h2>Estado de la API</h2>
	<table class="widefat striped penca-table">
		<tbody>
		<tr>
			<th>API activa</th>
			<td>
				<?php if ( 'primary' === $estado_api['api_activa'] ) : ?>
					<span class="penca-badge penca-badge--ok">✅ Primaria (wc2026api.com)</span>
				<?php else : ?>
					<span class="penca-badge penca-badge--warn">⚠️ Fallback (TheSportsDB)</span>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th>Requests usados hoy</th>
			<td>
				<?php
				$r   = (int) $estado_api['requests_hoy'];
				$lim = (int) $estado_api['limite_diario'];
				$pct = $lim > 0 ? round( $r / $lim * 100 ) : 0;
				echo esc_html( "{$r} / {$lim} ({$pct}%)" );
				if ( $pct >= 80 ) echo ' <span class="penca-badge penca-badge--warn">⚠️ Límite cercano</span>';
				?>
			</td>
		</tr>
		<tr>
			<th>Último sync exitoso</th>
			<td><?php echo esc_html( $estado_api['ultimo_sync'] ); ?></td>
		</tr>
		<tr>
			<th>Próximo sync programado</th>
			<td><?php echo esc_html( $estado_api['proximo_sync'] ); ?></td>
		</tr>
		<tr>
			<th>Fallos consecutivos</th>
			<td>
				<?php
				$f = (int) $estado_api['fallos_primaria'];
				echo esc_html( $f );
				if ( $f > 0 ) echo ' <span class="penca-badge penca-badge--warn">' . esc_html( $f . ' / ' . PENCA_API_FAIL_THRESHOLD ) . '</span>';
				?>
			</td>
		</tr>
		</tbody>
	</table>
	<p class="penca-actions">
		<button class="button button-primary" id="js-sync-manual">🔄 Sincronizar ahora</button>
		<span id="js-sync-resultado" class="penca-inline-msg"></span>
	</p>
</div>

<!-- Estado BD -->
<div class="penca-section">
	<h2>Estado de Base de Datos</h2>
	<table class="widefat striped penca-table">
		<thead><tr><th>Tabla</th><th>Estado</th></tr></thead>
		<tbody>
		<?php
		$tablas_nombres = [
			'matches'     => 'wp_wc_matches — Partidos',
			'predictions' => 'wp_wc_predictions — Pronósticos',
			'scores'      => 'wp_wc_scores — Puntos',
			'codes'       => 'wp_wc_codes — Códigos de acceso',
			'logs'        => 'wp_wc_logs — Logs del sistema',
		];
		foreach ( $tablas_nombres as $key => $nombre ) :
			$ok = ! empty( $estado_tablas[ $key ] );
		?>
		<tr>
			<td><?php echo esc_html( $nombre ); ?></td>
			<td><?php echo $ok
				? '<span class="penca-badge penca-badge--ok">✅ OK</span>'
				: '<span class="penca-badge penca-badge--error">❌ Falta</span>'; ?></td>
		</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</div>

<!-- Últimos errores -->
<?php if ( ! empty( $logs_recientes ) ) : ?>
<div class="penca-section">
	<h2>⚠️ Últimos errores</h2>
	<table class="widefat striped penca-table">
		<thead><tr><th>Módulo</th><th>Nivel</th><th>Mensaje</th><th>Cuándo (UY)</th></tr></thead>
		<tbody>
		<?php foreach ( $logs_recientes as $log ) : ?>
		<tr>
			<td><code><?php echo esc_html( $log->modulo ); ?></code></td>
			<td><span class="penca-badge penca-badge--<?php echo esc_attr( $log->nivel ); ?>">
				<?php echo esc_html( strtoupper( $log->nivel ) ); ?>
			</span></td>
			<td><?php echo esc_html( $log->mensaje ); ?></td>
			<td><?php echo esc_html( Penca_Helpers::tiempo_transcurrido( $log->created_at ) ); ?></td>
		</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=penca-logs' ) ); ?>">Ver todos los logs →</a></p>
</div>
<?php endif; ?>

</div><!-- .wrap -->
