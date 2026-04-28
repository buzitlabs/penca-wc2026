<?php
/**
 * Vista: Gestión de Códigos — Penca WC2026.
 *
 * Variables disponibles (inyectadas desde Penca_Admin::pagina_codigos):
 * @var int   $codigos_disponibles  Cantidad de códigos disponibles
 * @var int   $codigos_usados       Cantidad de códigos usados
 * @var int   $codigos_bloqueados   Cantidad de códigos bloqueados
 * @var array $todos_los_codigos    Lista de códigos (máx 200)
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="wrap penca-admin-wrap">
<h1>🎫 Penca WC2026 — Códigos de Acceso</h1>

<!-- Stats rápidas -->
<div class="penca-cards penca-cards--3">
	<div class="penca-card penca-card--ok">
		<div class="penca-card__val"><?php echo esc_html( $codigos_disponibles ); ?></div>
		<div class="penca-card__lbl">Disponibles</div>
	</div>
	<div class="penca-card penca-card--info">
		<div class="penca-card__val"><?php echo esc_html( $codigos_usados ); ?></div>
		<div class="penca-card__lbl">Usados</div>
	</div>
	<div class="penca-card penca-card--warn">
		<div class="penca-card__val"><?php echo esc_html( $codigos_bloqueados ); ?></div>
		<div class="penca-card__lbl">Bloqueados</div>
	</div>
</div>

<!-- Generador de códigos -->
<div class="penca-section">
	<h2>Generar nuevos códigos</h2>
	<div class="penca-form-inline">
		<label>
			Cantidad:
			<input type="number" id="js-gen-cantidad" value="10" min="1" max="500" class="small-text" />
		</label>
		<label>
			Notas (opcional):
			<input type="text" id="js-gen-notas" placeholder="Ej: Lote para evento 15/6" class="regular-text" />
		</label>
		<button class="button button-primary" id="js-generar-codigos">➕ Generar</button>
		<span id="js-gen-resultado" class="penca-inline-msg"></span>
	</div>

	<!-- Tabla de códigos generados en esta sesión -->
	<div id="js-codigos-generados" style="display:none; margin-top:12px;">
		<h4>Códigos generados:</h4>
		<textarea id="js-codigos-textarea" rows="6" class="large-text" readonly></textarea>
		<p><button class="button" id="js-copiar-codigos">📋 Copiar al portapapeles</button></p>
	</div>
</div>

<!-- Exportar CSV -->
<div class="penca-section">
	<h2>Exportar</h2>
	<p>
		<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=penca_exportar_codigos' ), 'penca_exportar_codigos' ) ); ?>"
			class="button">
			📥 Exportar todos a CSV
		</a>
		<span class="description"> — Descarga un CSV con todos los códigos, estado, usuario asignado y fecha de uso.</span>
	</p>
</div>

<!-- Lista de códigos -->
<div class="penca-section">
	<h2>Listado de códigos</h2>

	<!-- Filtros -->
	<div class="penca-filters" style="margin-bottom:12px;">
		<label>Filtrar por estado:
			<select id="js-filtro-status">
				<option value="">Todos</option>
				<option value="available">Disponibles</option>
				<option value="used">Usados</option>
				<option value="blocked">Bloqueados</option>
			</select>
		</label>
		<label style="margin-left:12px;">Buscar código:
			<input type="text" id="js-filtro-buscar" placeholder="MUN26-..." class="regular-text" />
		</label>
	</div>

	<table class="widefat striped penca-table" id="js-tabla-codigos">
		<thead>
			<tr>
				<th>Código</th>
				<th>Estado</th>
				<th>Usuario</th>
				<th>Usado el (UY)</th>
				<th>IP de uso</th>
				<th>Notas</th>
				<th>Acciones</th>
			</tr>
		</thead>
		<tbody>
		<?php if ( empty( $todos_los_codigos ) ) : ?>
			<tr><td colspan="7"><em>No hay códigos generados aún.</em></td></tr>
		<?php else : ?>
			<?php foreach ( $todos_los_codigos as $codigo ) : ?>
			<tr data-status="<?php echo esc_attr( $codigo->status ); ?>"
				data-codigo="<?php echo esc_attr( $codigo->codigo ); ?>">
				<td><code><?php echo esc_html( $codigo->codigo ); ?></code></td>
				<td>
					<?php
					$badges = [
						'available' => '<span class="penca-badge penca-badge--ok">Disponible</span>',
						'used'      => '<span class="penca-badge penca-badge--info">Usado</span>',
						'blocked'   => '<span class="penca-badge penca-badge--error">Bloqueado</span>',
					];
					echo $badges[ $codigo->status ] ?? esc_html( $codigo->status );
					?>
				</td>
				<td><?php echo esc_html( $codigo->nombre_usuario ?? '—' ); ?></td>
				<td><?php echo $codigo->used_at
					? esc_html( Penca_Helpers::formatear_fecha( $codigo->used_at, 'corto' ) )
					: '—'; ?></td>
				<td><?php echo esc_html( $codigo->used_ip ?? '—' ); ?></td>
				<td><?php echo esc_html( $codigo->notas ?? '' ); ?></td>
				<td>
					<?php if ( 'available' === $codigo->status ) : ?>
					<button class="button button-small js-bloquear-codigo"
						data-id="<?php echo esc_attr( $codigo->id ); ?>">
						🚫 Bloquear
					</button>
					<?php endif; ?>
				</td>
			</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table>
	<?php if ( count( $todos_los_codigos ) === 200 ) : ?>
	<p class="description">⚠️ Mostrando solo los últimos 200. Usá el CSV para ver todos.</p>
	<?php endif; ?>
</div>

</div><!-- .wrap -->
