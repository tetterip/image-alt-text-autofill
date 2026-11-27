<?php
/**
 * Generates and saves alt texts for attachments.
 *
 * @package Image_Alt_Text_Autofill
 */

defined( 'ABSPATH' ) || exit;

/**
 * Template or AI, with fallback, plus the write rules.
 */
class Tet_Alt_Generator {

	/** Who wrote the current alt: template | ai. Absent = a person or another plugin. */
	const META_SOURCE = '_tet_alt_source';

	/** The alt text we replaced, kept so a run can be undone by hand. */
	const META_PREVIOUS = '_tet_alt_previous';

	/**
	 * Values for the template tags.
	 *
	 * @param int $id Attachment ID.
	 * @return array<string,string>
	 */
	public static function template_vars( int $id ): array {
		$file   = (string) get_attached_file( $id );
		$parent = (int) wp_get_post_parent_id( $id );
		$title  = wp_strip_all_tags( (string) get_the_title( $id ) );

		// WordPress copies the file name into the title on upload; then it is the file name in words, or nothing.
		$from_file = Tet_Alt_Logic::clean_filename( $file );
		$stem      = (string) pathinfo( $file, PATHINFO_FILENAME );
		if ( '' === $title || 0 === strcasecmp( $title, $stem ) || 0 === strcasecmp( Tet_Alt_Logic::clean_filename( $title ), $from_file ) ) {
			$title = Tet_Alt_Logic::clean_filename( $title );
		}

		$vars = array(
			'filename' => $from_file,
			'title'    => $title,
			'caption'  => wp_strip_all_tags( (string) wp_get_attachment_caption( $id ) ),
			'parent'   => $parent ? wp_strip_all_tags( (string) get_the_title( $parent ) ) : '',
			'site'     => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
		);
		/**
		 * Filters the template tag values; add a key to make a new {tag} available.
		 *
		 * @param array<string,string> $vars Values.
		 * @param int                  $id   Attachment ID.
		 */
		return (array) apply_filters( 'tet_alt_template_vars', $vars, $id );
	}

	/**
	 * Alt text from the template (cheap: used on render too).
	 *
	 * @param int $id Attachment ID.
	 */
	public static function from_template( int $id ): string {
		$vars = self::template_vars( $id );
		$alt  = Tet_Alt_Logic::apply_template( (string) Tet_Alt_Settings::get( 'template' ), $vars );
		if ( '' === $alt && Tet_Alt_Settings::on( 'fallback_parent' ) ) {
			$alt = (string) ( $vars['parent'] ?? '' );
		}
		return Tet_Alt_Logic::sanitize_alt( $alt, (int) Tet_Alt_Settings::get( 'max_length' ) );
	}

	/**
	 * Generates an alt text without saving it.
	 *
	 * @param int    $id     Attachment ID.
	 * @param string $source ai | template | '' (the setting).
	 * @return array{alt:string,source:string,note:string}|WP_Error note: why AI fell back, if it did.
	 */
	public static function generate( int $id, string $source = '' ) {
		if ( ! wp_attachment_is_image( $id ) ) {
			return new WP_Error( 'tet_alt_not_image', __( 'This attachment is not an image.', 'image-alt-text-autofill' ) );
		}
		$source = '' !== $source ? $source : (string) Tet_Alt_Settings::get( 'source' );
		$note   = '';

		// A role that may not use AI gets the template, whatever "If AI fails" says: AI didn't fail, it wasn't tried.
		if ( 'ai' === $source && ! Tet_Alt_AI::user_can() ) {
			$source = 'template';
			$note   = __( 'Your user role is not allowed to use AI on this site, so the template was used.', 'image-alt-text-autofill' );
		}

		if ( 'ai' === $source ) {
			$alt = Tet_Alt_AI::describe( $id );
			if ( ! is_wp_error( $alt ) ) {
				return array( 'alt' => $alt, 'source' => 'ai', 'note' => '' );
			}
			if ( ! Tet_Alt_Settings::on( 'ai_fallback' ) ) {
				return $alt;
			}
			$note = $alt->get_error_message();
		}

		$alt = self::from_template( $id );
		if ( '' === $alt ) {
			return new WP_Error( 'tet_alt_empty', '' !== $note ? $note : __( 'Nothing to build an alt text from (the file name says nothing and the image is not attached to a post).', 'image-alt-text-autofill' ) );
		}
		return array( 'alt' => $alt, 'source' => 'template', 'note' => $note );
	}

	/**
	 * Generates and saves, respecting the scope.
	 *
	 * @param int    $id      Attachment ID.
	 * @param string $source  ai | template | ''.
	 * @param string $scope   missing | auto | all (see Tet_Alt_Logic::may_write()).
	 * @param bool   $dry_run Generate only.
	 * @return array{status:string,alt:string,source:string,note:string,previous:string} status: saved | skipped | preview | error.
	 */
	public static function apply( int $id, string $source = '', string $scope = 'missing', bool $dry_run = false ): array {
		$current = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
		$by      = (string) get_post_meta( $id, self::META_SOURCE, true );
		$base    = array( 'alt' => $current, 'source' => $by, 'note' => '', 'previous' => $current );

		if ( ! Tet_Alt_Logic::may_write( $scope, $current, $by ) ) {
			return array_merge( $base, array( 'status' => 'skipped' ) );
		}
		$result = self::generate( $id, $source );
		if ( is_wp_error( $result ) ) {
			return array_merge( $base, array( 'status' => 'error', 'note' => $result->get_error_message() ) );
		}
		if ( ! $dry_run ) {
			self::save( $id, $result['alt'], $result['source'] );
		}
		return array_merge( $base, $result, array( 'status' => $dry_run ? 'preview' : 'saved' ) );
	}

	/**
	 * Saves an alt text and records who wrote it.
	 *
	 * @param int    $id     Attachment ID.
	 * @param string $alt    Alt text.
	 * @param string $source template | ai.
	 */
	public static function save( int $id, string $alt, string $source ): void {
		$current = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
		if ( '' !== $current && $current !== $alt ) {
			update_post_meta( $id, self::META_PREVIOUS, $current );
		}
		update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( $alt ) );
		update_post_meta( $id, self::META_SOURCE, $source );

		/**
		 * Fires after an alt text was generated and saved.
		 *
		 * @param int    $id     Attachment ID.
		 * @param string $alt    Alt text.
		 * @param string $source template | ai.
		 */
		do_action( 'tet_alt_saved', $id, $alt, $source );
	}

	/**
	 * When someone edits the alt text by hand, it is theirs: forget our source flag.
	 * save() writes the alt first and the flag after it, so its own write passes through here harmlessly.
	 *
	 * @param int    $meta_id   Meta ID.
	 * @param int    $object_id Attachment ID.
	 * @param string $meta_key  Meta key.
	 */
	public static function on_alt_updated( $meta_id, $object_id, $meta_key ): void {
		if ( '_wp_attachment_image_alt' === $meta_key ) {
			delete_post_meta( (int) $object_id, self::META_SOURCE );
		}
	}

	public static function init(): void {
		add_action( 'updated_post_meta', array( __CLASS__, 'on_alt_updated' ), 10, 3 );
		add_action( 'added_post_meta', array( __CLASS__, 'on_alt_updated' ), 10, 3 );
	}
}
