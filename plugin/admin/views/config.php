<?php
/**
 * Vista: Configuración — Penca WC2026.
 *
 * Variables disponibles:
 * @var array  $config      Valores actuales de configuración
 * @var array  $paginas_wp  Lista de páginas publicadas de WordPress
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function penca_select_paginas( string $name, int $selected, array $paginas ): void {
	echo '<select name="' . esc_attr( $name ) . '">';
	echo '<option value="0">— Seleccionar —</option>';
	foreach ( $paginas as $p ) {
		$sel = selected( $selected, $p->ID, false );
		echo '<option value="' . esc_attr( $p->ID ) . '"' . $sel . '>' . esc_html( $p->post_title ) . '</option>';
	}
	echo '</select>';
}
?>
<div class="wrap penca-admin-wrap">
<h1>⚙️ Penca WC2026 — Configuración</h1>

<form method="post">
	<?php wp_nonce_field( 'penca_config_nonce' ); ?>

	<!-- API -->
	<div class="penca-section">
		<h2>🔌 APIs</h2>
		<table class="form-table">
			<tr>
				<th><label for="api_primary_key">API Key — WC2026 API</label></th>
				<td>
					<input type="password" id="api_primary_key" name="api_primary_key"
						value="<?php echo esc_attr( $config['api_primary_key'] ); ?>"
						class="regular-text" autocomplete="off" />
					<p class="description">
						Clave de autenticación para wc2026api.com (Bearer token).
						<?php if ( ! empty( $config['api_primary_key'] ) ) : ?>
							<span style="color:#00a32a;">✅ Configurada</span>
						<?php else : ?>
							<span style="color:#d63638;">⚠️ Sin configurar — la API primaria no funcionará</span>
						<?php endif; ?>
					</p>
				</td>
			</tr>
			<tr>
				<th><label for="thesportsdb_league_id">TheSportsDB — League ID</label></th>
				<td>
					<input type="text" id="thesportsdb_league_id" name="thesportsdb_league_id"
						value="<?php echo esc_attr( $config['thesportsdb_league_id'] ); ?>"
						class="small-text" />
					<p class="description">ID del torneo en TheSportsDB (API fallback). Verificar cuando se confirme el torneo 2026.</p>
				</td>
			</tr>
		</table>
	</div>

	<!-- Páginas -->
	<div class="penca-section">
		<h2>📄 Páginas del plugin</h2>
		<p class="description">Asigná una página de WordPress a cada sección. Usá el shortcode correspondiente en el contenido de esa página.</p>
		<table class="form-table">
			<tr>
				<th><label>Ranking público</label></th>
				<td>
					<?php penca_select_paginas( 'pagina_ranking', (int) $config['pagina_ranking'], $paginas_wp ); ?>
					<span class="description"> — Shortcode: <code>[penca_ranking]</code></span>
				</td>
			</tr>
			<tr>
				<th><label>Perfil de usuario</label></th>
				<td>
					<?php penca_select_paginas( 'pagina_perfil', (int) $config['pagina_perfil'], $paginas_wp ); ?>
					<span class="description"> — Shortcode: <code>[penca_perfil]</code></span>
				</td>
			</tr>
			<tr>
				<th><label>Mis pronósticos</label></th>
				<td>
					<?php penca_select_paginas( 'pagina_mis_pronos', (int) $config['pagina_mis_pronos'], $paginas_wp ); ?>
					<span class="description"> — Shortcode: <code>[penca_mis_pronosticos]</code></span>
				</td>
			</tr>
			<tr>
				<th><label>Registro</label></th>
				<td>
					<?php penca_select_paginas( 'pagina_registro', (int) $config['pagina_registro'], $paginas_wp ); ?>
					<span class="description"> — Shortcode: <code>[penca_registro]</code></span>
				</td>
			</tr>
			<tr>
				<th><label for="redirect_post_registro">Redirect post-registro</label></th>
				<td>
					<input type="url" id="redirect_post_registro" name="redirect_post_registro"
						value="<?php echo esc_attr( $config['redirect_post_registro'] ); ?>"
						class="regular-text" />
					<p class="description">URL a la que se redirige al usuario después de registrarse exitosamente.</p>
				</td>
			</tr>
		</table>
	</div>

	<!-- Participantes -->
	<div class="penca-section">
		<h2>👥 Participantes</h2>
		<table class="form-table">
			<tr>
				<th><label for="tope_usuarios">Tope máximo de participantes</label></th>
				<td>
					<input type="number" id="tope_usuarios" name="tope_usuarios"
						value="<?php echo esc_attr( $config['tope_usuarios'] ); ?>"
						min="1" max="5000" class="small-text" />
					<p class="description">
						Cuando se alcance este número de subscribers registrados, el formulario de registro
						se bloqueará automáticamente. Valor recomendado: <strong>1000</strong>.
					</p>
				</td>
			</tr>
		</table>
	</div>

	<!-- Recálculo masivo -->
	<div class="penca-section">
		<h2>🔄 Herramientas</h2>
		<p>
			<button type="button" class="button" id="js-recalcular-todo">
				🔄 Recalcular todos los puntos
			</button>
			<span id="js-recalcular-msg" class="penca-inline-msg"></span>
		</p>
		<p class="description">
			Recalcula los puntos de todos los partidos finalizados. Usá esto solo si corregiste un resultado
			histórico o si hubo un error en la lógica de puntos. Puede tardar algunos segundos.
		</p>
	</div>

	<p class="submit">
		<input type="submit" name="penca_guardar_config" class="button-primary"
			value="💾 Guardar configuración" />
	</p>
</form>
</div>
