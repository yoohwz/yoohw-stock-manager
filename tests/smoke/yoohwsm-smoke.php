<?php
if ( ! class_exists( 'YOOHWSM_Store' ) || get_option( 'yoohwsm_schema_version' ) !== '1' ) {
	throw new RuntimeException( 'Free runtime/schema not loaded.' );
}
if ( yoohwsm_premium_active() ) {
	throw new RuntimeException( 'Unexpected Premium in standalone smoke environment.' );
}
echo 'PASS Free runtime ' . YOOHWSM_VERSION . ' / WP ' . get_bloginfo( 'version' ) . ' / WC ' . WC_VERSION . ' / PHP ' . PHP_VERSION . "\n";
