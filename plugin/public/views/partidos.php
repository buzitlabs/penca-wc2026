<?php
/**
 * Vista: Fixture público — [penca_partidos]
 *
 * Muestra todos los partidos y resultados sin requerir login.
 * Sin formulario de pronóstico — solo lectura.
 *
 * Variables disponibles:
 * @var array $partidos  Array de partidos formateados para frontend
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// Agrupar por fase.
$por_fase = [];
foreach ( $partidos as $p ) {
	$por_fase[ $p['fase'] ][] = $p;
}

// Contar stats rápidas.
$total       = count( $partidos );
$finalizados = count( array_filter( $partidos, function( $p ) { return $p['status'] === 'finished'; } ) );
$en_vivo     = count( array_filter( $partidos, function( $p ) { return $p['status'] === 'live'; } ) );
?>
<div class="penca-block penca-partidos" id="penca-partidos">

	<div class="penca-partidos__header">
		<h2 class="penca-block__title">🗓️ Fixture Mundial 2026</h2>
		<div class="penca-partidos__meta">
			<span><?php echo esc_html( $total ); ?> partidos</span>
			<?php if ( $finalizados > 0 ) : ?>
			· <span><?php echo esc_html( $finalizados ); ?> finalizados</span>
			<?php endif; ?>
			<?php if ( $en_vivo > 0 ) : ?>
			· <span class="penca-live-badge">🔴 <?php echo esc_html( $en_vivo ); ?> en vivo</span>
			<?php endif; ?>
		</div>
	</div>

	<?php if ( empty( $partidos ) ) : ?>
		<p class="penca-aviso">El fixture aún no está disponible. Volvé pronto.</p>
	<?php else : ?>

	<!-- Filtros rápidos por status -->
	<div class="penca-partidos__filtros">
		<button class="penca-filtro-btn penca-filtro-btn--activo" data-filtro="">Todos</button>
		<button class="penca-filtro-btn" data-filtro="pending">Próximos</button>
		<?php if ( $en_vivo > 0 ) : ?>
		<button class="penca-filtro-btn" data-filtro="live">🔴 En vivo</button>
		<?php endif; ?>
		<?php if ( $finalizados > 0 ) : ?>
		<button class="penca-filtro-btn" data-filtro="finished">Finalizados</button>
		<?php endif; ?>
	</div>

	<?php foreach ( $por_fase as $fase => $lista ) : ?>
	<div class="penca-fase-section js-fase-section">
		<h3 class="penca-fase-title"><?php echo esc_html( $fase ); ?></h3>
		<div class="penca-partidos-grid">

		<?php foreach ( $lista as $partido ) :
			$status      = $partido['status'];
			$finalizado  = ( $status === 'finished' );
			$en_vivo_p   = ( $status === 'live' );
		?>
		<div class="penca-partido-card penca-partido-card--publico js-partido-card"
			data-status="<?php echo esc_attr( $status ); ?>">

			<!-- Fecha y grupo -->
			<div class="penca-partido-card__fecha">
				<?php echo esc_html( $partido['kickoff_uy_completo'] ); ?>
				<?php if ( $partido['grupo'] ) : ?>
				· <span class="penca-grupo-badge">Grupo <?php echo esc_html( $partido['grupo'] ); ?></span>
				<?php endif; ?>
			</div>

			<!-- Equipos y marcador -->
			<div class="penca-partido-card__match">
				<div class="penca-partido-card__equipo penca-partido-card__equipo--local">
					<?php if ( $partido['codigo_local'] ) : ?>
					<span class="penca-flag fi fi-<?php echo esc_attr( strtolower( $partido['codigo_local'] ) ); ?>"></span>
					<?php endif; ?>
					<span><?php echo esc_html( $partido['equipo_local'] ); ?></span>
				</div>

				<div class="penca-partido-card__vs">
					<?php if ( $finalizado ) : ?>
						<span class="penca-resultado-real penca-resultado-real--final">
							<?php echo esc_html( $partido['goles_local'] . ' - ' . $partido['goles_visitante'] ); ?>
						</span>
						<?php if ( $partido['fue_a_penales'] ) : ?>
						<small class="penca-penales-badge">
							Pen: <?php echo esc_html( $partido['penales_local'] . '-' . $partido['penales_visitante'] ); ?>
						</small>
						<?php endif; ?>
					<?php elseif ( $en_vivo_p ) : ?>
						<span class="penca-live-badge">🔴 En vivo</span>
					<?php else : ?>
						<span class="penca-vs-sep">vs</span>
					<?php endif; ?>
				</div>

				<div class="penca-partido-card__equipo penca-partido-card__equipo--visitante">
					<span><?php echo esc_html( $partido['equipo_visitante'] ); ?></span>
					<?php if ( $partido['codigo_visitante'] ) : ?>
					<span class="penca-flag fi fi-<?php echo esc_attr( strtolower( $partido['codigo_visitante'] ) ); ?>"></span>
					<?php endif; ?>
				</div>
			</div>

			<!-- Estadio -->
			<?php if ( $partido['estadio'] ) : ?>
			<div class="penca-partido-card__estadio">
				📍 <?php echo esc_html( $partido['estadio'] . ', ' . $partido['ciudad'] ); ?>
			</div>
			<?php endif; ?>

			<!-- Badge de estado -->
			<div class="penca-partido-card__status">
				<?php
				$status_labels = [
					'pending'   => '',
					'live'      => '<span class="penca-badge penca-badge--warn">🔴 En vivo</span>',
					'finished'  => '<span class="penca-badge penca-badge--ok">✅ Finalizado</span>',
					'postponed' => '<span class="penca-badge penca-badge--error">Postergado</span>',
					'cancelled' => '<span class="penca-badge penca-badge--error">Cancelado</span>',
				];
				echo $status_labels[ $status ] ?? '';
				?>
			</div>

		</div><!-- .penca-partido-card -->
		<?php endforeach; ?>

		</div><!-- .penca-partidos-grid -->
	</div><!-- .penca-fase-section -->
	<?php endforeach; ?>

	<?php endif; ?>
</div><!-- .penca-partidos -->
