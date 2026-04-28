<?php
/**
 * Vista: Perfil de Usuario — Penca WC2026.
 *
 * Variables disponibles:
 * @var array|null $datos_perfil  Resultado de Penca_Ranking_Engine::obtener_perfil_usuario()
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( null === $datos_perfil ) {
	echo '<p class="penca-aviso">Usuario no encontrado.</p>';
	return;
}

$historial = $datos_perfil['historial'] ?? [];
?>
<div class="penca-perfil penca-block" id="penca-perfil">

	<!-- Header del perfil -->
	<div class="penca-perfil__header">
		<div class="penca-perfil__avatar">
			<?php echo get_avatar( $datos_perfil['user_id'], 80, '', '', ['class' => 'penca-perfil__avatar-img'] ); ?>
		</div>
		<div class="penca-perfil__info">
			<h2 class="penca-perfil__nombre"><?php echo esc_html( $datos_perfil['display_name'] ); ?></h2>
			<p class="penca-perfil__pos">
				<?php
				$pos = $datos_perfil['posicion_ranking'];
				if ( 1 === $pos )      echo '🥇 1er lugar';
				elseif ( 2 === $pos )  echo '🥈 2do lugar';
				elseif ( 3 === $pos )  echo '🥉 3er lugar';
				else                   echo "# {$pos} en el ranking";
				?>
			</p>
		</div>
		<div class="penca-perfil__pts-big">
			<span class="penca-perfil__pts-val"><?php echo esc_html( $datos_perfil['total_puntos'] ); ?></span>
			<span class="penca-perfil__pts-lbl">puntos</span>
		</div>
	</div>

	<!-- Stats cards -->
	<div class="penca-cards penca-cards--4 penca-perfil__stats">
		<div class="penca-card penca-card--exacto">
			<div class="penca-card__val"><?php echo esc_html( $datos_perfil['exactos'] ); ?></div>
			<div class="penca-card__lbl">🎯 Exactos</div>
		</div>
		<div class="penca-card penca-card--dif">
			<div class="penca-card__val"><?php echo esc_html( $datos_perfil['diferencias'] ); ?></div>
			<div class="penca-card__lbl">✅ Diferencias</div>
		</div>
		<div class="penca-card penca-card--gan">
			<div class="penca-card__val"><?php echo esc_html( $datos_perfil['ganadores'] ); ?></div>
			<div class="penca-card__lbl">👍 Ganadores</div>
		</div>
		<div class="penca-card penca-card--ninguno">
			<div class="penca-card__val"><?php echo esc_html( $datos_perfil['ningunos'] ); ?></div>
			<div class="penca-card__lbl">❌ Sin pts</div>
		</div>
	</div>

	<!-- Historial partido a partido -->
	<div class="penca-perfil__historial">
		<h3>Historial partido a partido</h3>

		<?php if ( empty( $historial ) ) : ?>
			<p class="penca-aviso">Todavía no hay partidos puntuados.</p>
		<?php else : ?>

		<div class="penca-historial-list">
		<?php foreach ( $historial as $item ) : ?>
		<div class="penca-historial-item penca-historial-item--<?php echo esc_attr( $item['tipo_acierto'] ); ?>">
			<div class="penca-historial-item__fecha"><?php echo esc_html( $item['kickoff_uy'] ); ?></div>
			<div class="penca-historial-item__partido">
				<span class="penca-historial-item__equipo"><?php echo esc_html( $item['equipo_local'] ); ?></span>
				<span class="penca-historial-item__vs">vs</span>
				<span class="penca-historial-item__equipo"><?php echo esc_html( $item['equipo_visitante'] ); ?></span>
				<?php if ( $item['fue_a_penales'] ) echo '<span class="penca-pen-badge">Penales</span>'; ?>
			</div>
			<div class="penca-historial-item__resultados">
				<div class="penca-historial-item__real">
					<span class="penca-historial-item__lbl">Resultado</span>
					<span class="penca-historial-item__score"><?php echo esc_html( $item['resultado_real'] ); ?></span>
				</div>
				<div class="penca-historial-item__pron">
					<span class="penca-historial-item__lbl">Pronóstico</span>
					<span class="penca-historial-item__score"><?php echo esc_html( $item['pronostico'] ); ?></span>
				</div>
			</div>
			<div class="penca-historial-item__badge">
				<span class="penca-badge <?php echo esc_attr( $item['badge_clase'] ); ?>">
					<?php echo esc_html( $item['badge_label'] ); ?>
				</span>
			</div>
		</div>
		<?php endforeach; ?>
		</div>

		<?php endif; ?>
	</div>

	<p class="penca-perfil__back">
		<a href="<?php echo esc_url( get_permalink( get_option( 'penca_pagina_ranking', 0 ) ) ); ?>"
			class="penca-btn penca-btn--secondary">
			← Volver al ranking
		</a>
	</p>

</div><!-- .penca-perfil -->
