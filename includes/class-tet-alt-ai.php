<?php
/**
 * AI descriptions through the WordPress AI Client (WordPress 7.0+).
 *
 * The site owner picks the provider and enters its key in Settings → Connectors;
 * this plugin never sees or stores a key.
 *
 * @package Image_Alt_Text_Autofill
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the prompt, sends the image, cleans the answer.
 */
class Tet_Alt_AI {

	/** Image types vision models accept. */
	const MIME_TYPES = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );

	/** Largest file we send inline (bytes); bigger originals use a smaller generated size. */
	const MAX_BYTES = 4194304;

	/** A 1×1 PNG, used to ask the AI Client whether a configured model accepts images. */
	const PROBE_PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

	/** @var array{available:bool,reason:string}|null */
	private static $status = null;

	/**
	 * Whether AI can be used, and why not. No API call is made: the AI Client matches the
	 * prompt (text + image in, text out) against the models of the configured providers.
	 *
	 * @return array{available:bool,reason:string} reason: '' | wp_version | disabled | no_provider.
	 */
	public static function status(): array {
		if ( null !== self::$status ) {
			return self::$status;
		}
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return self::$status = array( 'available' => false, 'reason' => 'wp_version' );
		}
		if ( function_exists( 'wp_supports_ai' ) && ! wp_supports_ai() ) {
			return self::$status = array( 'available' => false, 'reason' => 'disabled' );
		}
		try {
			$ok = (bool) wp_ai_client_prompt( 'Describe this image.' )
				->with_file( self::PROBE_PNG, 'image/png' )
				->is_supported_for_text_generation();
		} catch ( \Throwable $e ) {
			$ok = false;
		}
		return self::$status = array( 'available' => $ok, 'reason' => $ok ? '' : 'no_provider' );
	}

	public static function available(): bool {
		return self::status()['available'];
	}

	/**
	 * Describes an attachment.
	 *
	 * @param int $id Attachment ID.
	 * @return string|WP_Error Alt text.
	 */
	public static function describe( int $id ) {
		if ( ! self::available() ) {
			return new WP_Error( 'tet_alt_ai_unavailable', __( 'No AI provider that can read images is set up.', 'image-alt-text-autofill' ) );
		}
		$image = self::image_data( $id );
		if ( is_wp_error( $image ) ) {
			return $image;
		}

		$max     = (int) Tet_Alt_Settings::get( 'max_length' );
		$context = Tet_Alt_Settings::on( 'ai_context' ) ? self::context( $id ) : '';

		try {
			$builder = wp_ai_client_prompt( self::user_prompt( $context ) )
				->with_file( $image['data_uri'], $image['mime'] )
				->using_system_instruction( self::system_instruction( $max ) )
				->using_temperature( 0.2 )
				->using_max_tokens( 400 );

			/**
			 * Filters the AI prompt builder before the request, e.g. to prefer a model:
			 * `$builder->using_model_preference( 'claude-haiku-4-5', 'gpt-5-mini' )`.
			 *
			 * @param WP_AI_Client_Prompt_Builder $builder Builder.
			 * @param int                         $id      Attachment ID.
			 */
			$builder = apply_filters( 'tet_alt_ai_prompt', $builder, $id );
			$text    = $builder->generate_text();
		} catch ( \Throwable $e ) {
			return new WP_Error( 'tet_alt_ai_error', $e->getMessage() );
		}

		if ( is_wp_error( $text ) ) {
			return $text;
		}
		$alt = Tet_Alt_Logic::sanitize_alt( (string) $text, $max );
		if ( '' === $alt ) {
			return new WP_Error( 'tet_alt_ai_empty', __( 'The AI returned no description.', 'image-alt-text-autofill' ) );
		}
		return $alt;
	}

	/**
	 * Instructions for the model.
	 *
	 * @param int $max Maximum length.
	 */
	public static function system_instruction( int $max ): string {
		$lines = array(
			'You write alt text for images on a website, for people who use screen readers.',
			'Write it in ' . Tet_Alt_Logic::language_name( determine_locale() ) . '.',
			"Describe what matters in the image in one short sentence or phrase, at most {$max} characters.",
			'Do not start with "image of", "photo of" or similar, and do not mention that it is an image unless it is a screenshot, illustration, chart or logo.',
			'Include visible text only if it matters. Do not guess names of people, brands or places that are not obvious or given in the context.',
			'Reply with the alt text only: no quotes, no label, no explanation.',
		);
		$extra = trim( (string) Tet_Alt_Settings::get( 'ai_instructions' ) );
		if ( '' !== $extra ) {
			$lines[] = 'Site owner notes: ' . $extra;
		}
		/**
		 * Filters the system instruction sent with every image.
		 *
		 * @param string $instruction Instruction.
		 * @param int    $max         Maximum length.
		 */
		return (string) apply_filters( 'tet_alt_ai_instruction', implode( "\n", $lines ), $max );
	}

	/**
	 * The user turn: the request plus optional context.
	 *
	 * @param string $context Context lines.
	 */
	private static function user_prompt( string $context ): string {
		$prompt = 'Write the alt text for this image.';
		return '' !== $context ? $prompt . "\n\nContext:\n" . $context : $prompt;
	}

	/**
	 * Site name and the title of the post / product the image belongs to.
	 *
	 * @param int $id Attachment ID.
	 */
	public static function context( int $id ): string {
		$lines = array();
		$site  = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		if ( '' !== $site ) {
			$lines[] = 'Website: ' . $site;
		}
		$parent = (int) wp_get_post_parent_id( $id );
		if ( $parent ) {
			$type  = get_post_type( $parent );
			$label = 'product' === $type ? 'Product' : 'Used in';
			$title = wp_strip_all_tags( get_the_title( $parent ) );
			if ( '' !== $title ) {
				$lines[] = $label . ': ' . $title;
			}
		}
		$caption = wp_strip_all_tags( (string) wp_get_attachment_caption( $id ) );
		if ( '' !== $caption ) {
			$lines[] = 'Caption: ' . $caption;
		}
		/**
		 * Filters the context lines sent with the image.
		 *
		 * @param string[] $lines Lines.
		 * @param int      $id    Attachment ID.
		 */
		$lines = (array) apply_filters( 'tet_alt_ai_context', $lines, $id );
		return implode( "\n", array_map( 'strval', $lines ) );
	}

	/**
	 * The image as a data URI. Inline data works for sites the provider can't reach (local, staging, behind a login).
	 * Uses the "large" size when there is one, so a 20 MB original isn't sent.
	 *
	 * @param int $id Attachment ID.
	 * @return array{data_uri:string,mime:string}|WP_Error
	 */
	public static function image_data( int $id ) {
		$file = self::pick_file( $id );
		if ( ! $file ) {
			return new WP_Error( 'tet_alt_no_file', __( 'The image file was not found.', 'image-alt-text-autofill' ) );
		}
		$type = wp_check_filetype( $file );
		$mime = (string) ( $type['type'] ?? '' );
		if ( ! in_array( $mime, self::MIME_TYPES, true ) ) {
			return new WP_Error( 'tet_alt_unsupported', __( 'This image type cannot be sent to the AI (only JPEG, PNG, GIF and WebP).', 'image-alt-text-autofill' ) );
		}
		$size = (int) filesize( $file );
		if ( $size <= 0 || $size > self::MAX_BYTES ) {
			return new WP_Error( 'tet_alt_too_big', __( 'The image is too large to send to the AI.', 'image-alt-text-autofill' ) );
		}
		$bytes = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		if ( false === $bytes ) {
			return new WP_Error( 'tet_alt_no_file', __( 'The image file was not found.', 'image-alt-text-autofill' ) );
		}
		return array(
			'data_uri' => 'data:' . $mime . ';base64,' . base64_encode( $bytes ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- image upload to the AI provider.
			'mime'     => $mime,
		);
	}

	/**
	 * Local path of the best size to send: large → medium_large → medium → original.
	 *
	 * @param int $id Attachment ID.
	 */
	private static function pick_file( int $id ): string {
		$original = (string) get_attached_file( $id );
		$meta     = wp_get_attachment_metadata( $id );
		$dir      = $original ? dirname( $original ) : '';
		$sizes    = is_array( $meta ) && ! empty( $meta['sizes'] ) ? $meta['sizes'] : array();
		foreach ( array( 'large', 'medium_large', 'medium' ) as $size ) {
			if ( ! empty( $sizes[ $size ]['file'] ) ) {
				$path = $dir . '/' . $sizes[ $size ]['file'];
				if ( is_readable( $path ) && filesize( $path ) <= self::MAX_BYTES ) {
					return $path;
				}
			}
		}
		return ( $original && is_readable( $original ) ) ? $original : '';
	}
}
