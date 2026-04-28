<?php
/**
 * Vista: Mi Cuenta — [penca_cuenta]
 *
 * Página completa del usuario logueado con tres tabs:
 * 1. Mis Pronósticos — partidos abiertos + cerrados
 * 2. Historial       — partido a partido con puntos
 * 3. Mis Puntos      — resumen estadístico + posición
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$user_id      = get_current_user_id();
$usuario      = wp_get_current_user();
$score_engine = penca_wc2026()->score_engine;
$rank_engine  = penca_wc2026()->ranking_engine;

$stats    = $score_engine->obtener_estadisticas_usuario( $user_id );
$posicion = $rank_engine->obtener_posicion_usuario( $user_id );
?>
<div class="penca-block penca-cuenta" id="penca-cuenta">

	<!-- Header de cuenta -->
	<div class="penca-cuenta__header">
		<div class="penca-cuenta__avatar">
			<?php echo get_avatar( $user_id, 64, '', '', ['class' => 'penca-cuenta__avatar-img'] ); ?>
		</div>
		<div class="penca-cuenta__info">
			<h2 class="penca-cuenta__nombre"><?php echo esc_html( $usuario->display_name ); ?></h2>
			<p class="penca-cuenta__pos">
				<?php
				if ( $posicion === 1 )     echo '🥇 1er lugar';
				elseif ( $posicion === 2 ) echo '🥈 2do lugar';
				elseif ( $posicion === 3 ) echo '🥉 3er lugar';
				elseif ( $posicion > 0 )   echo "# {$posicion} en el ranking";
				else                        echo 'Sin posición aún';
				?>
			</p>
		</div>
		<div class="penca-cuenta__pts-resumen">
			<span class="penca-cuenta__pts-val"><?php echo esc_html( $stats['total_puntos'] ); ?></span>
			<span class="penca-cuenta__pts-lbl">puntos</span>
		</div>
	</div>

	<!-- Tabs -->
	<div class="penca-tabs" role="tablist">
		<button class="penca-tab penca-tab--activo" data-tab="penca-tab-pronosticos" role="tab" aria-selected="true">
			⚽ Mis pronósticos
		</button>
		<button class="penca-tab" data-tab="penca-tab-historial" role="tab" aria-selected="false">
			📋 Historial
		</button>
		<button class="penca-tab" data-tab="penca-tab-puntos" role="tab" aria-selected="false">
			🏆 Mis puntos
		</button>
	</div>

	<!-- Tab 1: Pronósticos -->
	<div id="penca-tab-pronosticos" class="penca-tab-panel penca-tab-panel--activo" role="tabpanel">
		<?php include PENCA_PLUGIN_DIR . 'public/views/my-predictions.php'; ?>
	</div>

	<!-- Tab 2: Historial -->
	<div id="penca-tab-historial" class="penca-tab-panel" role="tabpanel">
		<?php
		$historial = $score_engine->obtener_historial_usuario( $user_id );
		if ( empty( $historial ) ) :
		?>
			<p class="penca-aviso" style="margin-top:20px;">
				Tu historial aparecerá cuando terminen los primeros partidos.
			</p>
		<?php else : ?>
		<div class="penca-historial-list" style="margin-top:16px;">
		<?php foreach ( $historial as $item ) :
			$clases_badge = [
				'exacto'     => 'badge--exacto',
				'diferencia' => 'badge--diferencia',
				'ganador'    => 'badge--ganador',
				'ninguno'    => 'badge--ninguno',
			];
			$labels_badge = [
				'exacto'     => "🎯 Exacto ({$item->puntos} pts)",
				'diferencia' => "✅ Diferencia ({$item->puntos} pts)",
				'ganador'    => "👍 Ganador ({$item->puntos} pts)",
				'ninguno'    => '❌ Sin puntos',
			];
			$clase = $clases_badge[ $item->tipo_acierto ] ?? 'badge--ninguno';
			$label = $labels_badge[ $item->tipo_acierto ] ?? '❌ Sin puntos';
		?>
		<div class="penca-historial-item penca-historial-item--<?php echo esc_attr( $item->tipo_acierto ); ?>">
			<div class="penca-historial-item__fecha">
				<?php echo esc_html( Penca_Helpers::formatear_fecha( $item->kickoff_utc, 'corto' ) ); ?>
			</div>
			<div class="penca-historial-item__partido">
				<span><?php echo esc_html( $item->equipo_local ); ?></span>
				<span class="penca-historial-item__vs">vs</span>
				<span><?php echo esc_html( $item->equipo_visitante ); ?></span>
			</div>
			<div class="penca-historial-item__resultados">
				<div class="penca-historial-item__real">
					<span class="penca-historial-item__lbl">Resultado</span>
					<span class="penca-historial-item__score">
						<?php echo esc_html( $item->goles_local_real . '-' . $item->goles_visitante_real ); ?>
					</span>
				</div>
				<div class="penca-historial-item__pron">
					<span class="penca-historial-item__lbl">Tu pronóstico</span>
					<span class="penca-historial-item__score">
						<?php echo esc_html( $item->goles_local_pron . '-' . $item->goles_visitante_pron ); ?>
					</span>
				</div>
			</div>
			<div class="penca-historial-item__badge">
				<span class="penca-badge <?php echo esc_attr( $clase ); ?>"><?php echo esc_html( $label ); ?></span>
			</div>
		</div>
		<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</div>

	<!-- Tab 3: Mis Puntos -->
	<div id="penca-tab-puntos" class="penca-tab-panel" role="tabpanel">
		<?php include PENCA_PLUGIN_DIR . 'public/views/mis-puntos.php'; ?>
	</div>

</div><!-- .penca-cuenta -->
