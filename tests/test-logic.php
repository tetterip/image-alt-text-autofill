<?php
/**
 * Tests for Tet_Alt_Logic.
 *
 * @package Image_Alt_Text_Autofill
 */

$l = 'Tet_Alt_Logic';

test( 'filename: words from dashes and underscores', function () use ( $l ) {
	eq( 'Red leather sofa', $l::clean_filename( 'red-leather_sofa.jpg' ) );
} );

test( 'filename: WordPress size, scaled and edit suffixes', function () use ( $l ) {
	eq( 'Garden chair', $l::clean_filename( 'garden-chair-1024x768.jpg' ) );
	eq( 'Garden chair', $l::clean_filename( 'garden-chair-scaled.jpeg' ) );
	eq( 'Garden chair', $l::clean_filename( 'garden-chair-e1712345678-300x200.webp' ) );
	eq( 'Garden chair', $l::clean_filename( 'https://example.com/wp-content/uploads/2026/11/garden-chair-2.png' ) );
} );

test( 'filename: camelCase and url-encoded Greek', function () use ( $l ) {
	eq( 'Blue Lamp', $l::clean_filename( 'BlueLamp.png' ) );
	eq( 'Καναπές δερμάτινος', $l::clean_filename( rawurlencode( 'καναπές-δερμάτινος' ) . '.jpg' ) );
} );

test( 'filename: meaningless camera, hash and screenshot names', function () use ( $l ) {
	foreach ( array( 'IMG_1234.JPG', 'DSC00012.jpg', 'PXL_20261103_101500123.jpg', 'Screenshot 2026-01-05 at 10.30.15.png', 'WhatsApp Image 2026-03-01 at 12.00.00.jpeg', '5f3a9c0e1b2d4e6f.jpg', '123456.png', 'a.jpg', 'unnamed.png' ) as $f ) {
		eq( '', $l::clean_filename( $f ), $f );
	}
} );

test( 'filename: real words with numbers survive', function () use ( $l ) {
	eq( 'Tiles 60x60 grey', $l::clean_filename( 'tiles 60x60 grey.jpg' ) );
	eq( 'Summer collection 2026', $l::clean_filename( 'summer-collection-2026.jpg' ) );
} );

test( 'template: fills tags', function () use ( $l ) {
	eq( 'Sofa - My Shop', $l::apply_template( '{title} - {site}', array( 'title' => 'Sofa', 'site' => 'My Shop' ) ) );
} );

test( 'template: drops separators next to empty tags', function () use ( $l ) {
	eq( 'My Shop', $l::apply_template( '{caption} - {site}', array( 'caption' => '', 'site' => 'My Shop' ) ) );
	eq( 'Sofa', $l::apply_template( '{title} | {caption}', array( 'title' => 'Sofa' ) ) );
	eq( 'Sofa - Shop', $l::apply_template( '{title} - {caption} - {site}', array( 'title' => 'Sofa', 'site' => 'Shop' ) ) );
	eq( '', $l::apply_template( '{caption}', array() ) );
} );

test( 'template: keeps literal text', function () use ( $l ) {
	eq( 'Photo of Sofa', $l::apply_template( 'Photo of {title}', array( 'title' => 'Sofa' ) ) );
} );

test( 'sanitize: strips labels, quotes and "image of"', function () use ( $l ) {
	eq( 'Red sofa in a bright room', $l::sanitize_alt( 'Alt text: "An image of red sofa in a bright room"' ) );
	eq( 'Κόκκινος καναπές', $l::sanitize_alt( '«Εικόνα με κόκκινος καναπές»' ) );
	eq( 'Two cats', $l::sanitize_alt( "<b>two</b>\n  cats" ) );
} );

test( 'sanitize: truncates on a word boundary', function () use ( $l ) {
	$alt = $l::sanitize_alt( 'A wooden dining table with six chairs next to a large window', 30 );
	eq( 'A wooden dining table with six', $alt );
	eq( true, mb_strlen( $l::sanitize_alt( str_repeat( 'α', 200 ), 125 ) ) === 125 );
} );

test( 'img: needs alt', function () use ( $l ) {
	eq( true, $l::img_needs_alt( '<img src="a.jpg">', true ) );
	eq( true, $l::img_needs_alt( '<img src="a.jpg" alt="">', true ) );
	eq( false, $l::img_needs_alt( '<img src="a.jpg" alt="">', false ) );
	eq( false, $l::img_needs_alt( '<img src="a.jpg" alt="Sofa">', true ) );
	eq( true, $l::img_needs_alt( '<img src="a.jpg" alt>', true ) );
	eq( false, $l::img_needs_alt( "<img src='a.jpg' alt='Sofa'>", true ) );
	eq( true, $l::img_needs_alt( '<img data-alt="x" src="a.jpg">', true ), 'data-alt is not alt' );
} );

test( 'img: set alt replaces or inserts and escapes', function () use ( $l ) {
	eq( '<img alt="Sofa &amp; &quot;chair&quot;" src="a.jpg">', $l::img_set_alt( '<img src="a.jpg">', 'Sofa & "chair"' ) );
	eq( '<img src="a.jpg" alt="Sofa">', $l::img_set_alt( '<img src="a.jpg" alt="">', 'Sofa' ) );
	eq( '<img src="a.jpg" alt="Sofa" />', $l::img_set_alt( '<img src="a.jpg" alt />', 'Sofa' ) );
	eq( '<img data-alt="x" alt="Sofa" src="a.jpg">', $l::img_set_alt( '<img data-alt="x" src="a.jpg">', 'Sofa' ) === '<img alt="Sofa" data-alt="x" src="a.jpg">' ? '<img data-alt="x" alt="Sofa" src="a.jpg">' : 'bad' );
} );

test( 'img: attribute reader', function () use ( $l ) {
	eq( 'a b.jpg', $l::img_attr( '<img src="a b.jpg">', 'src' ) );
	eq( 'x', $l::img_attr( '<img src=x>', 'src' ) );
	eq( null, $l::img_attr( '<img src="x">', 'alt' ) );
} );

test( 'language name', function () use ( $l ) {
	eq( 'Greek (el)', $l::language_name( 'el' ) );
	eq( 'German (de_DE)', $l::language_name( 'de_DE' ) );
	eq( 'xx_YY', $l::language_name( 'xx_YY' ) );
} );

test( 'may_write scopes', function () use ( $l ) {
	eq( true, $l::may_write( 'missing', '', '' ) );
	eq( false, $l::may_write( 'missing', 'Sofa', 'template' ) );
	eq( true, $l::may_write( 'auto', 'Sofa', 'template' ) );
	eq( false, $l::may_write( 'auto', 'Sofa', 'ai' ) );
	eq( false, $l::may_write( 'auto', 'Sofa', '' ) );
	eq( true, $l::may_write( 'all', 'Sofa', '' ) );
} );
