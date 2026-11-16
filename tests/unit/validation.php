<?php
/** Strict quantity and spreadsheet formula boundary tests. */
define( 'ABSPATH', __DIR__ );
require dirname( __DIR__, 2 ) . '/inc/functions.php';
foreach ( [ '0' => 0.0, '-2' => -2.0, '12.125' => 12.125, '999999999999.999999' => 999999999999.999999 ] as $raw => $expected ) {
	if ( ysm_quantity( $raw ) !== $expected ) {
		throw new RuntimeException( 'Valid quantity rejected: ' . $raw );
	}
}
foreach ( [ '', null, [], '1e3', 'nan', 'INF', ' 2', '2x', '1.1234567', '1000000000000', true ] as $raw ) {
	if ( null !== ysm_quantity( $raw ) ) {
		throw new RuntimeException( 'Invalid quantity accepted.' );
	}
}
foreach ( [ '=SUM(A1)', '+cmd', '-1', '@SUM', "\t=cmd", "\r+cmd" ] as $cell ) {
	if ( "'" . $cell !== ysm_csv_row( [ $cell ] )[0] ) {
		throw new RuntimeException( 'Formula cell not neutralized.' );
	}
}
if ( [ 'Product', '12' ] !== ysm_csv_row( [ 'Product', '12' ] ) ) {
	throw new RuntimeException( 'Plain CSV changed.' );
}
echo "PASS strict quantities and CSV formula boundaries\n";
