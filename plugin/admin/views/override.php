<?php
/**
 * Vista: Override Manual de Resultados — Penca WC2026.
 *
 * Variables disponibles:
 * @var array $partidos  Lista de objetos partido desde wp_wc_matches
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// Agrupar partidos por fase para facilitar la navegación.
$partidos_por_fase = [];
foreach ( $partidos as $p ) {
	$partidos_por_fase[ $p->fase ][] = $p;
}
?>
<div class="wrap penca-admin-wrap">
<h1>⚙️ Penca WC2026 — Override de Resultados</h1>

<div class="notice notice-info">
	<p>
		<strong>¿Cuándo usar override?</strong> Cuando la API no actualizó un resultado o lo trajo incorrecto.
		Al guardar, el partido queda marcado como <code>override_manual = 1</code> y los syncs futuros
		no lo sobreescribirán. Los puntos se recalculan automáticamente.
	</p>
</div>

<?php if ( empty( $partidos ) ) : ?>
	<p><em>No hay partidos en la base de datos. Ejecutá un sync desde el Dashboard.</em></p>
<?php else : ?>

<!-- Filtro de búsqueda rápida -->
<p>
	<input type="text" id="js-override-buscar"
		placeholder="Buscar equipo o fase..." class="regular-text" />
</p>

<?php foreach ( $partidos_por_fase as $fase => $lista ) : ?>
<div class="penca-section penca-section--override">
	<h2><?php echo esc_html( $fase ?: 'Sin fase asignada' ); ?></h2>
	<table class="widefat striped penca-table penca-table--override" id="js-override-tabla">
		<thead>
			<tr>
				<th>Partido</th>
				<th>Kickoff (UY)</th>
				<th>Estado</th>
				<th>Resultado actual</th>
				<th>Editar resultado</th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $lista as $partido ) :
			$kickoff_uy    = Penca_Helpers::formatear_fecha( $partido->kickoff_utc, 'corto' );
			$goles_l       = $partido->goles_local ?? '';
			$goles_v       = $partido->goles_visitante ?? '';
			$pen_l         = $partido->penales_local ?? '';
			$pen_v         = $partido->penales_visitante ?? '';
			$resultado_txt = ( '' !== $goles_l ) ? "{$goles_l} - {$goles_v}" : 'Sin resultado';
			if ( $partido->fue_a_penales ) $resultado_txt .= " (P: {$pen_l}-{$pen_v})";
		?>
		<tr class="penca-override-row"
			data-equipo-l="<?php echo esc_attr( $partido->equipo_local ); ?>"
			data-equipo-v="<?php echo esc_attr( $partido->equipo_visitante ); ?>"
			data-fase="<?php echo esc_attr( $partido->fase ); ?>">
			<td>
				<strong><?php echo esc_html( $partido->equipo_local ); ?></strong>
				vs
				<strong><?php echo esc_html( $partido->equipo_visitante ); ?></strong>
				<?php if ( $partido->override_manual ) : ?>
					<span class="penca-badge penca-badge--info" title="Este partido fue editado manualmente">
						✏️ Override
					</span>
				<?php endif; ?>
			</td>
			<td><?php echo esc_html( $kickoff_uy ); ?></td>
			<td>
				<?php
				$status_badges = [
					'pending'   => '<span class="penca-badge">Pendiente</span>',
					'live'      => '<span class="penca-badge penca-badge--warn">🔴 En vivo</span>',
					'finished'  => '<span class="penca-badge penca-badge--ok">✅ Finalizado</span>',
					'postponed' => '<span class="penca-badge penca-badge--error">Postergado</span>',
					'cancelled' => '<span class="penca-badge penca-badge--error">Cancelado</span>',
				];
				echo $status_badges[ $partido->status ] ?? esc_html( $partido->status );
				?>
			</td>
			<td><?php echo esc_html( $resultado_txt ); ?></td>
			<td>
				<div class="penca-override-form" data-match-id="<?php echo esc_attr( $partido->id ); ?>">
					<div class="penca-override-inputs">
						<input type="number" class="small-text js-gl" placeholder="L"
							min="0" max="30" value="<?php echo esc_attr( $goles_l ); ?>" />
						<span>—</span>
						<input type="number" class="small-text js-gv" placeholder="V"
							min="0" max="30" value="<?php echo esc_attr( $goles_v ); ?>" />
						<span style="margin-left:8px; color:#666;">Pen:</span>
						<input type="number" class="small-text js-pl" placeholder="L"
							min="0" max="30" value="<?php echo esc_attr( $pen_l ); ?>" title="Penales local (dejar vacío si no hubo)" />
						<span>—</span>
						<input type="number" class="small-text js-pv" placeholder="V"
							min="0" max="30" value="<?php echo esc_attr( $pen_v ); ?>" title="Penales visitante" />
						<select class="js-status">
							<?php foreach ( ['pending','live','finished','postponed','cancelled'] as $st ) : ?>
							<option value="<?php echo $st; ?>"
								<?php selected( $partido->status, $st ); ?>>
								<?php echo ucfirst( $st ); ?>
							</option>
							<?php endforeach; ?>
						</select>
						<button class="button button-primary js-btn-override">💾 Guardar</button>
						<span class="penca-override-msg penca-inline-msg"></span>
					</div>
				</div>
			</td>
		</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</div>
<?php endforeach; ?>

<?php endif; ?>
</div><!-- .wrap -->
