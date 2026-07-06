<?php
/**
 * Single-Template für den Beitragstyp bi_seminar (Seminar-Detailansicht).
 * Bleibt im Theme-Rahmen (Header/Footer) und rendert die Detail-Ausgabe.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<main id="primary" class="bi-detail-main" style="max-width:1180px;margin:40px auto;padding:0 20px;">
	<?php
	while ( have_posts() ) {
		the_post();
		echo BI_Detail::render( get_the_ID() ); // phpcs:ignore – intern escaped
	}
	?>
</main>
<?php
get_footer();
