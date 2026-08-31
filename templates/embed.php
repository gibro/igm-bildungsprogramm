<?php
/**
 * Rahmenloses Template für den Einbettungsmodus (siehe class-bi-embed.php).
 *
 * Bewusst ein eigenes, vollständiges HTML-Dokument statt get_header() ohne
 * Kopfbereich: Bei Block-Themes gibt es gar keinen get_header()-Aufruf mehr,
 * den man umgehen könnte, und was ein Theme in seinen Rahmen legt, ist nicht
 * vorhersehbar. Hier steht nur, was in den Rahmen gehört.
 *
 * wp_head()/wp_footer() bleiben: Von dort kommen die Stylesheets des Plugins
 * und des Themes, flatpickr und die Filterleiste. Ohne sie wäre die Ausgabe
 * unformatiert.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<main id="primary" class="bi-embed__main bi-seite-main">
	<?php
	if ( have_posts() ) {
		while ( have_posts() ) {
			the_post();

			if ( bi_is_seminar_post( get_the_ID() ) ) {
				echo BI_Detail::render( get_the_ID() );      // phpcs:ignore – intern escaped
			} elseif ( BI_Reihen::CPT === get_post_type() ) {
				echo BI_Reihen::render( get_the_ID() );      // phpcs:ignore – intern escaped
			} else {
				// Normale Seite: Suchmaske, Trefferliste, Anmeldung – alles
				// steckt als Shortcode im Inhalt, den the_content() auflöst.
				the_content();
			}
		}
	} else {
		echo '<p class="bi-embed__leer">Dieser Inhalt ist nicht (mehr) verfügbar.</p>';
	}
	wp_reset_postdata();
	?>
</main>
<?php wp_footer(); ?>
</body>
</html>
