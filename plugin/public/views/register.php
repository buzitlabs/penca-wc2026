<?php
/**
 * Vista: Formulario de Registro — Penca WC2026.
 *
 * Renderizada via shortcode [penca_registro].
 * No recibe variables externas; toda la lógica va por AJAX.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="penca-registro penca-block" id="penca-registro">

	<div class="penca-registro__header">
		<h2 class="penca-registro__title">⚽ Unite a la Penca WC2026</h2>
		<p class="penca-registro__desc">
			Necesitás un código de acceso para registrarte.<br>
			Si no tenés uno, pedíselo al organizador.
		</p>
	</div>

	<form class="penca-form" id="js-form-registro" novalidate>

		<!-- Código de acceso -->
		<div class="penca-form__field">
			<label class="penca-form__label" for="penca-codigo">
				Código de acceso <span class="penca-requerido">*</span>
			</label>
			<div class="penca-form__input-wrap">
				<input type="text" id="penca-codigo" name="codigo"
					class="penca-form__input" required
					placeholder="MUN26-XXXX-XXXX"
					maxlength="14"
					autocomplete="off"
					style="text-transform:uppercase; letter-spacing:2px;" />
				<span class="penca-form__field-msg" id="js-codigo-msg"></span>
			</div>
			<p class="penca-form__help">Formato: MUN26-XXXX-XXXX</p>
		</div>

		<!-- Display name -->
		<div class="penca-form__field">
			<label class="penca-form__label" for="penca-display-name">
				Tu nombre (como aparecerás en el ranking) <span class="penca-requerido">*</span>
			</label>
			<input type="text" id="penca-display-name" name="display_name"
				class="penca-form__input" required
				placeholder="Ej: Rodrigo P." maxlength="60" />
		</div>

		<!-- Username -->
		<div class="penca-form__field">
			<label class="penca-form__label" for="penca-username">
				Usuario (para iniciar sesión) <span class="penca-requerido">*</span>
			</label>
			<input type="text" id="penca-username" name="username"
				class="penca-form__input" required
				placeholder="minimo 3 caracteres" minlength="3" maxlength="60"
				autocomplete="username" />
			<p class="penca-form__help">Solo letras, números y guiones bajos.</p>
		</div>

		<!-- Email -->
		<div class="penca-form__field">
			<label class="penca-form__label" for="penca-email">
				Email <span class="penca-requerido">*</span>
			</label>
			<input type="email" id="penca-email" name="email"
				class="penca-form__input" required
				placeholder="tu@email.com"
				autocomplete="email" />
		</div>

		<!-- Password -->
		<div class="penca-form__field">
			<label class="penca-form__label" for="penca-password">
				Contraseña <span class="penca-requerido">*</span>
			</label>
			<div class="penca-form__input-wrap">
				<input type="password" id="penca-password" name="password"
					class="penca-form__input" required
					minlength="8" placeholder="Mínimo 8 caracteres"
					autocomplete="new-password" />
				<button type="button" class="penca-form__toggle-pass" id="js-toggle-pass"
					aria-label="Mostrar/ocultar contraseña">👁️</button>
			</div>
			<p class="penca-form__help">Mínimo 8 caracteres.</p>
		</div>

		<!-- Mensaje de error/éxito global -->
		<div class="penca-form__global-msg" id="js-registro-msg" role="alert"></div>

		<!-- Submit -->
		<div class="penca-form__submit">
			<button type="submit" class="penca-btn penca-btn--primary penca-btn--full" id="js-btn-registro">
				⚽ Registrarme y jugar
			</button>
		</div>

	</form>

	<p class="penca-registro__footer">
		¿Ya tenés cuenta? <a href="<?php echo esc_url( wp_login_url() ); ?>">Iniciá sesión</a>.
	</p>

</div><!-- .penca-registro -->
