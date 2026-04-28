<?php
/**
 * Vista: Visor de Logs — Penca WC2026.
 *
 * Variables disponibles:
 * @var array  $logs          Logs filtrados
 * @var string $modulo_filtro Módulo seleccionado como filtro
 * @var string $nivel_filtro  Nivel seleccionado como filtro
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$modulos_disponibles = ['api-sync','match-engine','prediction-engine','score-engine','ranking-engine','access-codes','sistema'];
$niveles_disponibles = ['info','warning','error','critical'];
?>
<div class="wrap penca-admin-wrap">
<h1>📋 Penca WC2026 — Logs del Sistema</h1>

<!-- Filtros -->
<form method="get" class="penca-form-inline" style="margin-bottom:16px;">
	<input type="hidden" name="page" value="penca-logs" />
	<label>Módulo:
		<select name="modulo">
			<option value="">Todos</option>
			<?php foreach ( $modulos_disponibles as $m ) : ?>
			<option value="<?php echo esc_attr( $m ); ?>"
				<?php selected( $modulo_filtro, $m ); ?>>
				<?php echo esc_html( $m ); ?>
			</option>
			<?php endforeach; ?>
		</select>
	</label>
	<label style="margin-left:12px;">Nivel:
		<select name="nivel">
			<option value="">Todos</option>
			<?php foreach ( $niveles_disponibles as $n ) : ?>
			<option value="<?php echo esc_attr( $n ); ?>"
				<?php selected( $nivel_filtro, $n ); ?>>
				<?php echo esc_html( strtoupper( $n ) ); ?>
			</option>
			<?php endforeach; ?>
		</select>
	</label>
	<button type="submit" class="button" style="margin-left:8px;">Filtrar</button>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=penca-logs' ) ); ?>"
		class="button" style="margin-left:4px;">Limpiar</a>

	<span style="margin-left:16px;">
		<button type="button" class="button" id="js-logs-refresh">🔄 Actualizar</button>
		<label style="margin-left:8px;">
			<input type="checkbox" id="js-autorefresh" /> Auto-refresh cada 30s
		</label>
	</span>

	<span style="float:right;">
		<button type="button" class="button button-link-delete" id="js-limpiar-logs"
			data-confirm="¿Eliminar logs de más de 30 días?">
			🗑️ Limpiar logs viejos
		</button>
	</span>
</form>

<!-- Contador -->
<p class="description" id="js-logs-count">
	Mostrando <?php echo esc_html( count( $logs ) ); ?> entradas.
	<?php if ( ! empty( $modulo_filtro ) ) echo 'Módulo: <strong>' . esc_html( $modulo_filtro ) . '</strong>. '; ?>
	<?php if ( ! empty( $nivel_filtro ) ) echo 'Nivel: <strong>' . esc_html( strtoupper( $nivel_filtro ) ) . '</strong>.'; ?>
</p>

<!-- Tabla de logs -->
<table class="widefat striped penca-table penca-table--logs" id="js-logs-tabla">
	<thead>
		<tr>
			<th style="width:50px;">ID</th>
			<th style="width:110px;">Módulo</th>
			<th style="width:80px;">Nivel</th>
			<th>Mensaje</th>
			<th style="width:60px;">User</th>
			<th style="width:105px;">IP</th>
			<th style="width:130px;">Fecha (UY)</th>
		</tr>
	</thead>
	<tbody id="js-logs-tbody">
	<?php if ( empty( $logs ) ) : ?>
		<tr><td colspan="7"><em>Sin logs para los filtros seleccionados.</em></td></tr>
	<?php else : ?>
		<?php foreach ( $logs as $log ) :
			$contexto_decoded = ! empty( $log->contexto ) ? json_decode( $log->contexto, true ) : [];
		?>
		<tr class="penca-log-row penca-log-row--<?php echo esc_attr( $log->nivel ); ?>">
			<td><?php echo esc_html( $log->id ); ?></td>
			<td><code><?php echo esc_html( $log->modulo ); ?></code></td>
			<td><span class="penca-badge penca-badge--<?php echo esc_attr( $log->nivel ); ?>">
				<?php echo esc_html( strtoupper( $log->nivel ) ); ?>
			</span></td>
			<td>
				<?php echo esc_html( $log->mensaje ); ?>
				<?php if ( ! empty( $contexto_decoded ) ) : ?>
				<details class="penca-log-context">
					<summary>Ver contexto</summary>
					<pre><?php echo esc_html( wp_json_encode( $contexto_decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></pre>
				</details>
				<?php endif; ?>
			</td>
			<td><?php echo $log->user_id ? esc_html( $log->user_id ) : '—'; ?></td>
			<td><?php echo esc_html( $log->ip_address ?? '—' ); ?></td>
			<td title="<?php echo esc_attr( Penca_Helpers::tiempo_transcurrido( $log->created_at ) ); ?>">
				<?php echo esc_html( Penca_Helpers::formatear_fecha( $log->created_at, 'corto' ) ); ?>
			</td>
		</tr>
		<?php endforeach; ?>
	<?php endif; ?>
	</tbody>
</table>

<p class="description" style="margin-top:8px;">
	Los logs se conservan por 90 días. El auto-refresh usa AJAX (no recarga la página).
</p>

</div><!-- .wrap -->
