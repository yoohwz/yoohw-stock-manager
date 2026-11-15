<?php
if ( ! class_exists( 'YSM_Store' ) || get_option( 'ysm_schema_version' ) !== '1' ) {
	throw new RuntimeException( 'Free runtime/schema not loaded.' );
}
if ( ysm_premium_active() ) {
	throw new RuntimeException( 'Unexpected Premium in standalone smoke environment.' );
}
echo 'PASS Free runtime ' . YSM_VERSION . ' / WP ' . get_bloginfo( 'version' ) . ' / WC ' . WC_VERSION . ' / PHP ' . PHP_VERSION . "\n";
