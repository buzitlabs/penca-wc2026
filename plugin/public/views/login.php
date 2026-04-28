<?php
/**
 * Vista: Login + Registro en tabs — [penca_login]
 *
 * Dos tabs: "Iniciar sesión" y "Registrarse con código".
 * El tab activo se controla por URL param ?tab=registro o por JS.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$tab_activo = sanitize_text_field( $_GET['tab'] ?? 'login' );
?>
<div class="penca-block penca-login-wrap" id="penca-login-wrap">

	<!-- Tabs -->
	<div class="penca-tabs" role="tablist">
		<button class="penca-tab<?php echo 'login' === $tab_activo ? ' penca-tab--activo' : ''; ?>"
			data-tab="penca-tab-login" role="tab"
			aria-selected="<?php echo 'login' === $tab_activo ? 'true' : 'false'; ?>">
			🔐 Iniciar sesión
		</button>
		<button class="penca-tab<?php echo 'registro' === $tab_activo ? ' penca-tab--activo' : ''; ?>"
			data-tab="penca-tab-registro" role="tab"
			aria-selected="<?php echo 'registro' === $tab_activo ? 'true' : 'false'; ?>">
			⚽ Registrarse
		</button>
	</div>

	<!-- Tab: Login -->
	<div id="penca-tab-login"
		class="penca-tab-panel<?php echo 'login' === $tab_activo ? ' penca-tab-panel--activo' : ''; ?>"
		role="tabpanel">

		<form class="penca-form" id="js-form-login" novalidate>

			<div class="penca-form__field">
				<label class="penca-form__label" for="penca-login-user">
					Usuario o email <span class="penca-requerido">*</span>
				</label>
				<input type="text" id="penca-login-user" name="log"
					class="penca-form__input" required
					autocomplete="username" placeholder="Tu usuario o email" />
			</div>

			<div class="penca-form__field">
				<label class="penca-form__label" for="penca-login-pass">
					Contraseña <span class="penca-requerido">*</span>
				</label>
				<div class="penca-form__input-wrap">
					<input type="password" id="penca-login-pass" name="pwd"
						class="penca-form__input" required
						autocomplete="current-password" placeholder="Tu contraseña" />
					<button type="button" class="penca-form__toggle-pass js-toggle-pass" aria-label="Mostrar contraseña">👁️</button>
				</div>
			</div>

			<div class="penca-form__global-msg" id="js-login-msg" role="alert"></div>

			<div class="penca-form__submit">
				<button type="submit" class="penca-btn penca-btn--primary penca-btn--full" id="js-btn-login">
					🔐 Entrar
				</button>
			</div>

			<p class="penca-form__footer-link">
				¿Olvidaste tu contraseña?
				<a href="<?php echo esc_url( wp_lostpassword_url() ); ?>">Recuperala acá</a>.
			</p>

		</form>
	</div>

	<!-- Tab: Registro -->
	<div id="penca-tab-registro"
		class="penca-tab-panel<?php echo 'registro' === $tab_activo ? ' penca-tab-panel--activo' : ''; ?>"
		role="tabpanel">
		<?php
		// Reutilizar el shortcode de registro ya implementado.
		echo do_shortcode( '[penca_registro]' );
		?>
	</div>

</div><!-- .penca-login-wrap -->
