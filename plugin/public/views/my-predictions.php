<?php
/**
 * Vista: Mis Pronósticos — [penca_pronosticos] / [penca_mis_pronosticos]
 * Diseño opción A: bandera + nombre encima del input, marcador centrado.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$match_engine      = penca_wc2026()->match_engine;
$prediction_engine = penca_wc2026()->prediction_engine;
$user_id           = get_current_user_id();

$fixture     = $match_engine->obtener_fixture_frontend();
$pronosticos = $prediction_engine->obtener_pronosticos_usuario( $user_id );

$pron_por_partido = array();
foreach ( $pronosticos as $p ) {
	$pron_por_partido[ $p->match_id ] = $p;
}

$fixture_por_fase = array();
foreach ( $fixture as $p ) {
	$fase = $p['fase'] ?: 'General';
	$fixture_por_fase[ $fase ][] = $p;
}

$total_partidos = count( $fixture );
$con_pronostico = count( $pron_por_partido );
$abiertos       = 0;
foreach ( $fixture as $p ) {
	if ( $p['pronosticos_abiertos'] ) $abiertos++;
}
?>
<div class="penca-mis-pronos" id="penca-mis-pronos">

	<?php if ( $total_partidos > 0 ) : ?>
	<div class="penca-progreso-wrap">
		<div class="penca-progreso-info">
			<span>Pronosticados: <strong><?php echo (int) $con_pronostico; ?> / <?php echo (int) $total_partidos; ?></strong></span>
			<?php if ( $abiertos > 0 ) : ?>
			<span class="penca-badge penca-badge--warn"><?php echo (int) $abiertos; ?> abiertos</span>
			<?php endif; ?>
		</div>
		<div class="penca-progreso-bar">
			<div class="penca-progreso-bar__fill" style="width:<?php echo $total_partidos > 0 ? round( $con_pronostico / $total_partidos * 100 ) : 0; ?>%"></div>
		</div>
	</div>
	<?php endif; ?>

	<?php if ( empty( $fixture ) ) : ?>
		<p class="penca-aviso">El fixture aún no está disponible.</p>
	<?php else : ?>

	<?php foreach ( $fixture_por_fase as $fase => $partidos ) : ?>
	<div class="penca-fase-section">
		<h3 class="penca-fase-title"><?php echo esc_html( $fase ); ?></h3>
		<div class="penca-partidos-grid">

		<?php foreach ( $partidos as $partido ) :
			$mid        = $partido['id'];
			$abierto    = $partido['pronosticos_abiertos'];
			$finalizado = ( $partido['status'] === 'finished' );
			$en_vivo    = ( $partido['status'] === 'live' );
			$pron       = isset( $pron_por_partido[ $mid ] ) ? $pron_por_partido[ $mid ] : null;
			$pron_l     = ( $pron !== null ) ? (int) $pron->pron_local     : null;
			$pron_v     = ( $pron !== null ) ? (int) $pron->pron_visitante : null;
			$tiene_pron = ( $pron !== null );

			// Códigos ISO para banderas.
			$cod_l = strtolower( $partido['codigo_local'] );
			$cod_v = strtolower( $partido['codigo_visitante'] );

			// Badge de acierto.
			$badge_clase = '';
			$badge_label = '';
			if ( $finalizado && $tiene_pron && isset( $pron->puntos ) ) {
				$tipo = isset( $pron->tipo_acierto ) ? $pron->tipo_acierto : 'ninguno';
				$pts  = (int) $pron->puntos;
				$badge_clases = array( 'exacto' => 'badge--exacto', 'ganador' => 'badge--ganador', 'empate' => 'badge--diferencia', 'ninguno' => 'badge--ninguno' );
				$badge_labels = array( 'exacto' => "🎯 Exacto ({$pts} pts)", 'ganador' => "👍 Ganador ({$pts} pts)", 'empate' => "🤝 Empate ({$pts} pts)", 'ninguno' => ( $pts > 0 ? "⚽ +{$pts} goles" : '❌ Sin puntos' ) );
				$badge_clase = isset( $badge_clases[ $tipo ] ) ? $badge_clases[ $tipo ] : 'badge--ninguno';
				$badge_label = isset( $badge_labels[ $tipo ] ) ? $badge_labels[ $tipo ] : '❌ Sin puntos';
			}
		?>
		<div class="penca-partido-card<?php
			echo $tiene_pron ? ' penca-partido-card--con-pron' : '';
			echo ! $abierto  ? ' penca-partido-card--cerrado'  : '';
		?>" data-match-id="<?php echo esc_attr( $mid ); ?>">

			<!-- Fecha y grupo -->
			<div class="penca-partido-card__fecha">
				<?php echo esc_html( $partido['kickoff_uy_completo'] ); ?>
				<?php if ( $partido['grupo'] ) : ?>
				· <span class="penca-grupo-badge">Grupo <?php echo esc_html( $partido['grupo'] ); ?></span>
				<?php endif; ?>
			</div>

			<!-- OPCIÓN A: 3 columnas — local | centro | visitante -->
			<div class="penca-match-a">

				<!-- Local: bandera + nombre + input -->
				<div class="penca-match-a__equipo">
					<?php if ( $cod_l && strlen( $cod_l ) === 2 ) : ?>
					<span class="fi fi-<?php echo esc_attr( $cod_l ); ?> penca-bandera-lg"></span>
					<?php else : ?>
					<span class="penca-bandera-placeholder"></span>
					<?php endif; ?>
					<span class="penca-match-a__nombre"><?php echo esc_html( $partido['equipo_local'] ); ?></span>

					<?php if ( $abierto ) : ?>
					<input type="number"
						class="penca-match-a__input js-pron-local"
						min="0" max="20" placeholder="0"
						value="<?php echo ( $pron_l !== null ) ? esc_attr( $pron_l ) : ''; ?>" />
					<?php elseif ( $tiene_pron ) : ?>
					<div class="penca-match-a__locked-score"><?php echo ( $pron_l !== null ) ? esc_html( $pron_l ) : '—'; ?></div>
					<?php else : ?>
					<div class="penca-match-a__locked-score penca-match-a__locked-score--empty">—</div>
					<?php endif; ?>
				</div>

				<!-- Centro: VS o resultado real -->
				<div class="penca-match-a__centro">
					<?php if ( $finalizado ) : ?>
						<div class="penca-match-a__result">
							<span class="penca-match-a__result-score"><?php echo esc_html( $partido['goles_local'] ); ?></span>
							<span class="penca-match-a__result-sep">-</span>
							<span class="penca-match-a__result-score"><?php echo esc_html( $partido['goles_visitante'] ); ?></span>
						</div>
						<div class="penca-match-a__result-label">Final</div>
						<?php if ( $partido['fue_a_penales'] ) : ?>
						<div class="penca-match-a__penales">Pen <?php echo esc_html( $partido['penales_local'] . '-' . $partido['penales_visitante'] ); ?></div>
						<?php endif; ?>
					<?php elseif ( $en_vivo ) : ?>
						<div class="penca-match-a__live">EN VIVO</div>
					<?php else : ?>
						<div class="penca-match-a__vs">vs</div>
					<?php endif; ?>
				</div>

				<!-- Visitante: bandera + nombre + input -->
				<div class="penca-match-a__equipo">
					<?php if ( $cod_v && strlen( $cod_v ) === 2 ) : ?>
					<span class="fi fi-<?php echo esc_attr( $cod_v ); ?> penca-bandera-lg"></span>
					<?php else : ?>
					<span class="penca-bandera-placeholder"></span>
					<?php endif; ?>
					<span class="penca-match-a__nombre"><?php echo esc_html( $partido['equipo_visitante'] ); ?></span>

					<?php if ( $abierto ) : ?>
					<input type="number"
						class="penca-match-a__input js-pron-visitante"
						min="0" max="20" placeholder="0"
						value="<?php echo ( $pron_v !== null ) ? esc_attr( $pron_v ) : ''; ?>" />
					<?php elseif ( $tiene_pron ) : ?>
					<div class="penca-match-a__locked-score"><?php echo ( $pron_v !== null ) ? esc_html( $pron_v ) : '—'; ?></div>
					<?php else : ?>
					<div class="penca-match-a__locked-score penca-match-a__locked-score--empty">—</div>
					<?php endif; ?>
				</div>

			</div><!-- .penca-match-a -->

			<!-- Acciones y badge -->
			<div class="penca-partido-card__footer">
				<?php if ( $abierto ) : ?>
				<div class="penca-pron-form js-pron-form" data-match-id="<?php echo esc_attr( $mid ); ?>">
					<button type="button" class="penca-btn penca-btn--guardar penca-btn--full js-btn-guardar-pron">
						<?php echo $tiene_pron ? '✏️ Actualizar pronóstico' : '💾 Guardar pronóstico'; ?>
					</button>
					<span class="penca-pron-msg js-pron-msg"></span>
				</div>
				<?php elseif ( $finalizado && $badge_label ) : ?>
				<div class="penca-partido-card__badge-wrap">
					<span class="penca-badge <?php echo esc_attr( $badge_clase ); ?>"><?php echo esc_html( $badge_label ); ?></span>
				</div>
				<?php elseif ( ! $abierto && ! $tiene_pron ) : ?>
				<div class="penca-pron-missing">🔒 Sin pronóstico registrado</div>
				<?php endif; ?>
			</div>

			<!-- Estadio -->
			<?php if ( $partido['estadio'] ) : ?>
			<div class="penca-partido-card__estadio">
				📍 <?php echo esc_html( $partido['estadio'] . ', ' . $partido['ciudad'] ); ?>
			</div>
			<?php endif; ?>

		</div><!-- .penca-partido-card -->
		<?php endforeach; ?>
		</div>
	</div>
	<?php endforeach; ?>

	<?php endif; ?>
</div>
