<?php
defined( 'ABSPATH' ) || exit;

/** Propagate WooCommerce save failures to the submission transaction. */
class CSWA_WC_Order extends WC_Order {
	protected function handle_exception( $e, $message = 'Error' ) {
		throw $e;
	}
}
