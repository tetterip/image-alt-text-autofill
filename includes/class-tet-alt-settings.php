<?php
/**
 * Options and the settings page (Media → Alt Text → Settings).
 *
 * @package Image_Alt_Text_Autofill
 */

defined( 'ABSPATH' ) || exit;

/**
 * One option, `tet_alt_settings`, read through get().
 */
class Tet_Alt_Settings {

	const OPTION = 'tet_alt_settings';

	/** Per-request cache. @var array<string,mixed>|null */
	private static $cache = null;

	/**
	 * Defaults. Strings are English data values; the template is a format, not UI text.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'source'           => 'template', // template | ai.
			'template'         => '{filename}',
			'fallback_parent'  => 'yes',      // Empty template result → title of the post the image is attached to.
			'on_upload'        => 'yes',
			'on_render'        => 'yes',
			'empty_is_missing' => 'yes',
			'max_length'       => 125,
			'ai_context'       => 'yes',      // Send the post / product title with the image.
			'ai_instructions'  => '',
			'ai_fallback'      => 'yes',      // Use the template when AI fails.
		);
	}

	/**
	 * A setting, or all settings.
	 *
	 * @param string $key Setting key; '' for the whole array.
	 * @return mixed
	 */
	public static function get( string $key = '' ) {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}
		if ( '' === $key ) {
			return self::$cache;
		}
		return self::$cache[ $key ] ?? null;
	}

	public static function on( string $key ): bool {
		return 'yes' === self::get( $key );
	}

	public static function flush(): void {
		self::$cache = null;
	}

	public static function init(): void {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'flush' ) );
		add_action( 'add_option_' . self::OPTION, array( __CLASS__, 'flush' ) );
	}

	public static function register(): void {
		register_setting(
			'tet_alt_settings',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Sanitises the posted settings. Unticked checkboxes are absent and stored as 'no'.
	 *
	 * @param mixed $input Posted values.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$d     = self::defaults();
		$out   = array();

		$out['source']   = in_array( $input['source'] ?? '', array( 'template', 'ai' ), true ) ? $input['source'] : $d['source'];
		$template        = sanitize_text_field( wp_unslash( (string) ( $input['template'] ?? '' ) ) );
		$out['template'] = '' !== $template ? $template : $d['template'];

		foreach ( array( 'fallback_parent', 'on_upload', 'on_render', 'empty_is_missing', 'ai_context', 'ai_fallback' ) as $key ) {
			$out[ $key ] = ! empty( $input[ $key ] ) ? 'yes' : 'no';
		}
		$out['max_length']      = min( 250, max( 40, absint( $input['max_length'] ?? $d['max_length'] ) ) );
		$out['ai_instructions'] = sanitize_textarea_field( wp_unslash( (string) ( $input['ai_instructions'] ?? '' ) ) );

		self::flush();
		return $out;
	}

	/** Renders the Settings tab (inside Tet_Alt_Admin::render_page()). */
	public static function render(): void {
		$s    = self::get();
		$name = self::OPTION;
		$ai   = Tet_Alt_AI::status();
		?>
		<form method="post" action="options.php" class="tet-alt-settings">
			<?php settings_fields( 'tet_alt_settings' ); ?>

			<h2><?php esc_html_e( 'How alt text is written', 'image-alt-text-autofill' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Source', 'image-alt-text-autofill' ); ?></th>
					<td>
						<fieldset>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[source]" value="template" <?php checked( 'template', $s['source'] ); ?> /> <?php esc_html_e( 'Template (file name, titles), no external service', 'image-alt-text-autofill' ); ?></label><br />
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[source]" value="ai" <?php checked( 'ai', $s['source'] ); ?> <?php disabled( ! $ai['available'] && 'ai' !== $s['source'] ); ?> /> <?php esc_html_e( 'AI: describe the image with the AI provider set up in WordPress', 'image-alt-text-autofill' ); ?></label>
						</fieldset>
						<?php self::render_ai_status( $ai ); ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="tet-alt-template"><?php esc_html_e( 'Template', 'image-alt-text-autofill' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" id="tet-alt-template" name="<?php echo esc_attr( $name ); ?>[template]" value="<?php echo esc_attr( $s['template'] ); ?>" />
						<p class="description">
							<?php
							/* translators: %s: list of template tags such as {filename}, {title}. */
							printf( esc_html__( 'Tags: %s. A separator next to an empty tag is left out.', 'image-alt-text-autofill' ), '<code>{filename}</code> <code>{title}</code> <code>{caption}</code> <code>{parent}</code> <code>{site}</code>' );
							?>
							<br /><?php esc_html_e( '{filename} is the file name in words, without sizes or camera names such as IMG_1234. {parent} is the post, page or product the image was uploaded to.', 'image-alt-text-autofill' ); ?>
						</p>
						<p><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[fallback_parent]" value="1" <?php checked( self::on( 'fallback_parent' ) ); ?> /> <?php esc_html_e( 'If the template gives nothing, use the title of the post, page or product the image belongs to', 'image-alt-text-autofill' ); ?></label></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="tet-alt-max"><?php esc_html_e( 'Maximum length', 'image-alt-text-autofill' ); ?></label></th>
					<td>
						<input type="number" min="40" max="250" step="1" class="small-text" id="tet-alt-max" name="<?php echo esc_attr( $name ); ?>[max_length]" value="<?php echo esc_attr( (string) $s['max_length'] ); ?>" />
						<?php esc_html_e( 'characters', 'image-alt-text-autofill' ); ?>
						<p class="description"><?php esc_html_e( 'Screen readers handle short alt texts best; 125 is the usual advice.', 'image-alt-text-autofill' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'AI', 'image-alt-text-autofill' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Context', 'image-alt-text-autofill' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[ai_context]" value="1" <?php checked( self::on( 'ai_context' ) ); ?> /> <?php esc_html_e( 'Send the site name and the title of the post, page or product with the image, for more specific descriptions', 'image-alt-text-autofill' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="tet-alt-instructions"><?php esc_html_e( 'Extra instructions', 'image-alt-text-autofill' ); ?></label></th>
					<td>
						<textarea class="large-text" rows="3" id="tet-alt-instructions" name="<?php echo esc_attr( $name ); ?>[ai_instructions]" placeholder="<?php esc_attr_e( 'e.g. We sell handmade ceramics; mention the colour and the glaze.', 'image-alt-text-autofill' ); ?>"><?php echo esc_textarea( $s['ai_instructions'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Optional. Added to the instructions the AI gets. The alt text is always written in the language of the site.', 'image-alt-text-autofill' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'If AI fails', 'image-alt-text-autofill' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[ai_fallback]" value="1" <?php checked( self::on( 'ai_fallback' ) ); ?> /> <?php esc_html_e( 'Use the template instead (AI texts can still replace it later)', 'image-alt-text-autofill' ); ?></label></td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'When', 'image-alt-text-autofill' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'On upload', 'image-alt-text-autofill' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[on_upload]" value="1" <?php checked( self::on( 'on_upload' ) ); ?> /> <?php esc_html_e( 'Write the alt text of new images when they are uploaded, if it is empty', 'image-alt-text-autofill' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'On the site', 'image-alt-text-autofill' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[on_render]" value="1" <?php checked( self::on( 'on_render' ) ); ?> /> <?php esc_html_e( 'Add a template alt text to images shown without one (nothing is saved, no AI)', 'image-alt-text-autofill' ); ?></label><br />
						<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[empty_is_missing]" value="1" <?php checked( self::on( 'empty_is_missing' ) ); ?> /> <?php esc_html_e( 'Treat an empty alt="" as missing', 'image-alt-text-autofill' ); ?></label>
						<p class="description"><?php esc_html_e( 'alt="" marks a decorative image that screen readers skip. Untick this if your editors set it on purpose.', 'image-alt-text-autofill' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Status line under the AI option.
	 *
	 * @param array{available:bool,reason:string} $ai Tet_Alt_AI::status().
	 */
	private static function render_ai_status( array $ai ): void {
		if ( $ai['available'] ) {
			echo '<p class="tet-alt-ai-status tet-alt-ai-status--ok"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> ' . esc_html__( 'An AI provider that can read images is set up.', 'image-alt-text-autofill' ) . '</p>';
			echo '<p class="description">' . esc_html__( 'With AI, each image is sent to that provider to be described. The provider may charge for each request.', 'image-alt-text-autofill' ) . '</p>';
			return;
		}
		$messages = array(
			'wp_version'  => __( 'AI needs WordPress 7.0 or newer.', 'image-alt-text-autofill' ),
			'disabled'    => __( 'AI is turned off on this site (WP_AI_SUPPORT or the wp_supports_ai filter).', 'image-alt-text-autofill' ),
			'no_provider' => __( 'No AI provider that can read images is set up yet.', 'image-alt-text-autofill' ),
		);
		echo '<p class="tet-alt-ai-status tet-alt-ai-status--off"><span class="dashicons dashicons-info" aria-hidden="true"></span> ' . esc_html( $messages[ $ai['reason'] ] ?? $messages['no_provider'] );
		if ( 'no_provider' === $ai['reason'] && current_user_can( 'manage_options' ) ) {
			echo ' <a href="' . esc_url( admin_url( 'options-connectors.php' ) ) . '">' . esc_html__( 'Set one up in Settings → Connectors', 'image-alt-text-autofill' ) . '</a>';
		}
		echo '</p>';
	}
}
