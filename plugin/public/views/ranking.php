<?php
/**
 * Vista: Ranking Público — Penca WC2026.
 *
 * Variables disponibles:
 * @var array $datos_ranking  Resultado de Penca_Ranking_Engine::obtener_ranking()
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$ranking       = $datos_ranking['ranking']        ?? [];
$total_pag     = $datos_ranking['total_paginas']  ?? 0;
$pag_actual    = $datos_ranking['pagina_actual']  ?? 1;
$total_usu     = $datos_ranking['total_usuarios'] ?? 0;
$ultima_act    = $datos_ranking['ultima_actualizacion'] ?? '';
?>
<div class="penca-ranking penca-block" id="penca-ranking">

	<div class="penca-ranking__header">
		<h2 class="penca-ranking__title">🏆 Tabla de posiciones</h2>
		<p class="penca-ranking__meta">
			<?php echo esc_html( $total_usu ); ?> participantes
			· Actualizado: <?php echo esc_html( $ultima_act ); ?>
		</p>
	</div>

	<?php if ( empty( $ranking ) ) : ?>
		<p class="penca-aviso">Todavía no hay puntuaciones registradas. ¡Volvé después de los primeros partidos!</p>
	<?php else : ?>

	<!-- Desktop table / Mobile cards (CSS controla cuál se muestra) -->
	<div class="penca-ranking__table-wrap">
		<table class="penca-table penca-ranking__table">
			<thead>
				<tr>
					<th class="col-pos">#</th>
					<th class="col-nombre">Participante</th>
					<th class="col-pts">Pts</th>
					<th class="col-exactos" title="Resultados exactos">🎯</th>
					<th class="col-dif" title="Diferencia exacta">✅</th>
					<th class="col-gan" title="Ganador correcto">👍</th>
					<th class="col-link"></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $ranking as $fila ) :
				$es_usuario_actual = is_user_logged_in() && get_current_user_id() === $fila['user_id'];
			?>
			<tr class="penca-ranking__row<?php echo $es_usuario_actual ? ' penca-ranking__row--yo' : ''; ?>"
				data-user-id="<?php echo esc_attr( $fila['user_id'] ); ?>">
				<td class="col-pos">
					<?php
					if ( 1 === $fila['posicion'] ) echo '<span class="penca-medal penca-medal--oro">🥇</span>';
					elseif ( 2 === $fila['posicion'] ) echo '<span class="penca-medal penca-medal--plata">🥈</span>';
					elseif ( 3 === $fila['posicion'] ) echo '<span class="penca-medal penca-medal--bronce">🥉</span>';
					else echo esc_html( $fila['posicion'] );
					?>
				</td>
				<td class="col-nombre">
					<?php echo esc_html( $fila['display_name'] ); ?>
					<?php if ( $es_usuario_actual ) echo ' <span class="penca-tu-badge">Vos</span>'; ?>
				</td>
				<td class="col-pts penca-pts"><?php echo esc_html( $fila['total_puntos'] ); ?></td>
				<td class="col-exactos"><?php echo esc_html( $fila['exactos'] ); ?></td>
				<td class="col-dif"><?php echo esc_html( $fila['diferencias'] ); ?></td>
				<td class="col-gan"><?php echo esc_html( $fila['ganadores'] ); ?></td>
				<td class="col-link">
					<a href="<?php echo esc_url( $fila['url_perfil'] ); ?>"
						class="penca-btn penca-btn--sm">Ver perfil</a>
				</td>
			</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<!-- Leyenda columnas -->
	<p class="penca-ranking__leyenda">
		<span>🎯 Exacto (8 pts)</span>
		<span>✅ Diferencia (5 pts)</span>
		<span>👍 Ganador (3 pts)</span>
	</p>

	<!-- Paginación -->
	<?php if ( $total_pag > 1 ) : ?>
	<div class="penca-ranking__paginacion" id="js-ranking-paginacion">
		<?php for ( $i = 1; $i <= $total_pag; $i++ ) : ?>
		<button class="penca-btn penca-btn--pag<?php echo $i === $pag_actual ? ' penca-btn--pag-activo' : ''; ?>"
			data-pagina="<?php echo esc_attr( $i ); ?>">
			<?php echo esc_html( $i ); ?>
		</button>
		<?php endfor; ?>
	</div>
	<?php endif; ?>

	<?php endif; ?>
</div><!-- .penca-ranking -->
