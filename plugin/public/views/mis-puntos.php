<?php
/**
 * Vista: Mis Puntos — [penca_mis_puntos]
 *
 * Resumen estadístico compacto del usuario actual.
 * También se incluye como tab dentro de [penca_cuenta].
 *
 * Variables disponibles cuando se incluye desde cuenta.php:
 * @var array  $stats    Estadísticas del usuario
 * @var int    $posicion Posición en el ranking
 * @var object $usuario  Objeto WP_User
 *
 * Cuando se llama como shortcode standalone, se calculan acá.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// Si las variables no vienen del contexto padre, calcularlas.
if ( ! isset( $stats ) ) {
	$user_id      = get_current_user_id();
	$usuario      = wp_get_current_user();
	$score_engine = penca_wc2026()->score_engine;
	$rank_engine  = penca_wc2026()->ranking_engine;
	$stats        = $score_engine->obtener_estadisticas_usuario( $user_id );
	$posicion     = $rank_engine->obtener_posicion_usuario( $user_id );
}

$total_jugados = $stats['exactos'] + $stats['diferencias'] + $stats['ganadores'] + $stats['ningunos'];
$pct_acierto   = $total_jugados > 0
	? round( ( $stats['exactos'] + $stats['diferencias'] + $stats['ganadores'] ) / $total_jugados * 100 )
	: 0;
?>
<div class="penca-mis-puntos" style="margin-top:20px;">

	<!-- Posición destacada -->
	<div class="penca-mis-puntos__pos-card">
		<div class="penca-mis-puntos__pos-num">
			<?php
			if ( $posicion === 1 )     echo '🥇';
			elseif ( $posicion === 2 ) echo '🥈';
			elseif ( $posicion === 3 ) echo '🥉';
			else                        echo '#' . esc_html( $posicion );
			?>
		</div>
		<div class="penca-mis-puntos__pos-lbl">Tu posición</div>
		<div class="penca-mis-puntos__total">
			<span class="penca-mis-puntos__pts"><?php echo esc_html( $stats['total_puntos'] ); ?></span>
			<span class="penca-mis-puntos__pts-lbl">puntos totales</span>
		</div>
	</div>

	<!-- Grid de stats -->
	<div class="penca-cards penca-cards--4" style="margin-top:16px;">
		<div class="penca-card penca-card--exacto">
			<div class="penca-card__val"><?php echo esc_html( $stats['exactos'] ); ?></div>
			<div class="penca-card__lbl">🎯 Exactos<br><small>(<?php echo $stats['exactos'] * 8; ?> pts)</small></div>
		</div>
		<div class="penca-card penca-card--dif">
			<div class="penca-card__val"><?php echo esc_html( $stats['diferencias'] ); ?></div>
			<div class="penca-card__lbl">✅ Diferencias<br><small>(<?php echo $stats['diferencias'] * 5; ?> pts)</small></div>
		</div>
		<div class="penca-card penca-card--gan">
			<div class="penca-card__val"><?php echo esc_html( $stats['ganadores'] ); ?></div>
			<div class="penca-card__lbl">👍 Ganadores<br><small>(<?php echo $stats['ganadores'] * 3; ?> pts)</small></div>
		</div>
		<div class="penca-card penca-card--ninguno">
			<div class="penca-card__val"><?php echo esc_html( $stats['ningunos'] ); ?></div>
			<div class="penca-card__lbl">❌ Sin puntos</div>
		</div>
	</div>

	<!-- Barra de progreso de acierto -->
	<?php if ( $total_jugados > 0 ) : ?>
	<div class="penca-mis-puntos__progreso" style="margin-top:20px;">
		<div class="penca-mis-puntos__progreso-header">
			<span>Partidos evaluados: <strong><?php echo esc_html( $total_jugados ); ?></strong></span>
			<span>Acierto: <strong><?php echo esc_html( $pct_acierto ); ?>%</strong></span>
		</div>
		<div class="penca-progreso-bar">
			<div class="penca-progreso-bar__fill" style="width:<?php echo esc_attr( $pct_acierto ); ?>%"></div>
		</div>
	</div>
	<?php endif; ?>

	<!-- Link al ranking -->
	<p style="margin-top:20px; text-align:center;">
		<a href="<?php echo esc_url( get_permalink( get_option( 'penca_pagina_ranking', 0 ) ) ?: home_url( '/ranking/' ) ); ?>"
			class="penca-btn penca-btn--secondary">
			Ver tabla de posiciones →
		</a>
	</p>

</div><!-- .penca-mis-puntos -->
