<?php
/**
 * Single-Template für den Beitragstyp bi_seminar (Seminar-Detailansicht).
 *
 * Bleibt im Theme-Rahmen (Header/Footer). Anders als früher steckt hier keine
 * Breitenbegrenzung mehr: Kennzahlenband und Trennstrich laufen über die volle
 * Breite, die Inhaltsspalten begrenzen sich selbst (.igm-breite, 1180px).
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
		echo BI_Detail::render( get_the_ID() ); // phpcs:ignore – intern escaped
	}
	?>
</main>
<?php
get_footer();
