<?php
/**
 * Single-Template für den Beitragstyp bi_reihe (Ausbildungsreihe).
 *
 * Bleibt im Theme-Rahmen (Header/Footer). Die Breitenbegrenzung sitzt in der
 * Ausgabe selbst (.igm-breite), damit Kennzahlenband und Trennstrich über die
 * volle Breite laufen können.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<main id="primary" class="bi-seite-main">
	<?php
	while ( have_posts() ) {
		the_post();
		echo BI_Reihen::render( get_the_ID() ); // phpcs:ignore – intern escaped
	}
	?>
</main>
<?php
get_footer();
