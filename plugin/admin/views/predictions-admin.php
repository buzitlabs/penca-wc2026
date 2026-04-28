<?php
/**
 * Vista: Pronósticos por partido (Admin/Operador) — Penca WC2026.
 *
 * Variables disponibles:
 * @var array       $partidos      Todos los partidos
 * @var object|null $partido_sel   Partido seleccionado actualmente
 * @var int         $match_id_sel  ID del partido seleccionado
 * @var array       $pronosticos   Pronósticos registrados para el partido seleccionado
 * @var array       $sin_pron      Usuarios sin pronóstico para el partido seleccionado
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="wrap penca-admin-wrap">
<h1>📋 Penca WC2026 — Pronósticos por partido</h1>

<div class="penca-pronosticos-layout">

	<!-- Panel izquierdo: selector de partido -->
	<div class="penca-pronosticos-sidebar">
		<h3>Seleccioná un partido</h3>
		<?php
		$fase_actual = '';
		foreach ( $partidos as $p ) :
			if ( $p->fase !== $fase_actual ) :
				if ( $fase_actual !== '' ) echo '</div>';
				$fase_actual = $p->fase;
				echo '<div class="penca-sidebar-fase"><strong>' . esc_html( $p->fase ) . '</strong>';
			endif;
			$activo = ( (int) $p->id === $match_id_sel ) ? ' penca-sidebar-partido--activo' : '';
		?>
		<a href="<?php echo esc_url( add_query_arg( [ 'page' => 'penca-pronosticos', 'match_id' => $p->id ], admin_url( 'admin.php' ) ) ); ?>"
			class="penca-sidebar-partido<?php echo $activo; ?>">
			<?php echo esc_html( $p->equipo_local . ' vs ' . $p->equipo_visitante ); ?><br>
			<small><?php echo esc_html( Penca_Helpers::formatear_fecha( $p->kickoff_utc, 'corto' ) ); ?></small>
		</a>
		<?php endforeach; ?>
		<?php if ( $fase_actual !== '' ) echo '</div>'; ?>
	</div>

	<!-- Panel derecho: pronósticos del partido seleccionado -->
	<div class="penca-pronosticos-main">
	<?php if ( $partido_sel ) :
		$kickoff_uy     = Penca_Helpers::formatear_fecha( $partido_sel->kickoff_utc, 'corto' );
		$resultado_txt  = ( null !== $partido_sel->goles_local )
			? $partido_sel->goles_local . ' - ' . $partido_sel->goles_visitante
			: 'Sin resultado';
	?>

		<div class="penca-section">
			<h2>
				<?php echo esc_html( $partido_sel->equipo_local . ' vs ' . $partido_sel->equipo_visitante ); ?>
				<span class="penca-badge penca-badge--info"><?php echo esc_html( $kickoff_uy ); ?></span>
				<?php if ( 'finished' === $partido_sel->status ) : ?>
					<span class="penca-badge penca-badge--ok">✅ Resultado: <?php echo esc_html( $resultado_txt ); ?></span>
				<?php endif; ?>
			</h2>

			<!-- Resumen -->
			<div class="penca-cards penca-cards--3" style="margin: 12px 0;">
				<div class="penca-card penca-card--ok">
					<div class="penca-card__val"><?php echo esc_html( count( $pronosticos ) ); ?></div>
					<div class="penca-card__lbl">Con pronóstico</div>
				</div>
				<div class="penca-card penca-card--warn">
					<div class="penca-card__val"><?php echo esc_html( count( $sin_pron ) ); ?></div>
					<div class="penca-card__lbl">Sin pronóstico</div>
				</div>
				<div class="penca-card">
					<div class="penca-card__val"><?php echo esc_html( count( $pronosticos ) + count( $sin_pron ) ); ?></div>
					<div class="penca-card__lbl">Total participantes</div>
				</div>
			</div>

			<!-- Tabla de pronósticos -->
			<?php if ( ! empty( $pronosticos ) ) : ?>
			<h3>Con pronóstico</h3>
			<table class="widefat striped penca-table">
				<thead>
					<tr>
						<th>Participante</th>
						<th>Pronóstico</th>
						<th>Enviado (UY)</th>
						<th>Estado</th>
						<?php if ( 'finished' === $partido_sel->status ) : ?>
						<th>Puntos</th>
						<?php endif; ?>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $pronosticos as $p ) : ?>
				<tr>
					<td>
						<strong><?php echo esc_html( $p->display_name ); ?></strong><br>
						<small class="penca-text-muted"><?php echo esc_html( $p->user_email ); ?></small>
					</td>
					<td class="penca-score-display">
						<?php echo esc_html( $p->pron_local . ' — ' . $p->pron_visitante ); ?>
					</td>
					<td>
						<?php echo esc_html( Penca_Helpers::formatear_fecha( $p->updated_at, 'corto' ) ); ?>
					</td>
					<td>
						<?php echo $p->is_locked
							? '<span class="penca-badge penca-badge--info">🔒 Cerrado</span>'
							: '<span class="penca-badge penca-badge--ok">✅ Abierto</span>'; ?>
					</td>
					<?php if ( 'finished' === $partido_sel->status ) : ?>
					<td>
						<?php if ( null !== $p->puntos ) :
							$clases = [
								'exacto'     => 'penca-badge--ok',
								'diferencia' => 'penca-badge--info',
								'ganador'    => 'penca-badge--warn',
								'ninguno'    => 'penca-badge--error',
							];
							$clase = $clases[ $p->tipo_acierto ] ?? '';
						?>
							<span class="penca-badge <?php echo esc_attr( $clase ); ?>">
								<?php echo esc_html( $p->puntos . ' pts — ' . $p->tipo_acierto ); ?>
							</span>
						<?php else : ?>
							<span class="penca-badge">Sin calcular</span>
						<?php endif; ?>
					</td>
					<?php endif; ?>
				</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>

			<!-- Sin pronóstico -->
			<?php if ( ! empty( $sin_pron ) ) : ?>
			<h3 style="margin-top: 20px;">⚠️ Sin pronóstico (<?php echo count( $sin_pron ); ?>)</h3>
			<table class="widefat striped penca-table">
				<thead><tr><th>Participante</th><th>Email</th></tr></thead>
				<tbody>
				<?php foreach ( $sin_pron as $u ) : ?>
				<tr>
					<td><?php echo esc_html( $u->display_name ); ?></td>
					<td><?php echo esc_html( $u->user_email ); ?></td>
				</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>

			<?php if ( empty( $pronosticos ) && empty( $sin_pron ) ) : ?>
				<p><em>No hay participantes registrados aún.</em></p>
			<?php endif; ?>
		</div>

	<?php else : ?>
		<p class="penca-aviso">Seleccioná un partido de la lista para ver sus pronósticos.</p>
	<?php endif; ?>
	</div>

</div><!-- .penca-pronosticos-layout -->

<style>
.penca-pronosticos-layout { display: flex; gap: 20px; align-items: flex-start; }
.penca-pronosticos-sidebar { width: 240px; flex-shrink: 0; background: #fff; border: 1px solid #ddd; border-radius: 6px; padding: 12px; max-height: 80vh; overflow-y: auto; }
.penca-pronosticos-main { flex: 1; min-width: 0; }
.penca-sidebar-fase { margin-bottom: 8px; }
.penca-sidebar-fase > strong { display: block; font-size: 11px; text-transform: uppercase; color: #666; padding: 4px 0; border-bottom: 1px solid #eee; margin-bottom: 4px; }
.penca-sidebar-partido { display: block; padding: 6px 8px; border-radius: 4px; text-decoration: none; color: #1d2327; font-size: 13px; margin-bottom: 2px; line-height: 1.3; }
.penca-sidebar-partido:hover { background: #f0f6fc; }
.penca-sidebar-partido--activo { background: #1a472a; color: #fff !important; }
.penca-sidebar-partido--activo small { color: #cce; }
.penca-score-display { font-size: 18px; font-weight: 700; text-align: center; }
.penca-text-muted { color: #666; }
@media (max-width: 782px) { .penca-pronosticos-layout { flex-direction: column; } .penca-pronosticos-sidebar { width: 100%; max-height: 200px; } }
</style>
</div>
