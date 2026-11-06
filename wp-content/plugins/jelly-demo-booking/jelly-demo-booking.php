<?php
/**
 * Plugin Name: Jelly Booking
 * Description: Five-step booking using the Jelly API. Shortcode: [jelly_booking]
 * Version: 0.3.0
 */
defined('ABSPATH') || exit;
require_once __DIR__.'/api-settings.php';
require_once __DIR__.'/api-client.php';
require_once __DIR__.'/api-catalog.php';
require_once __DIR__.'/api-booking.php';
require_once __DIR__.'/api-payment.php';
require_once __DIR__.'/api-form.php';
require_once __DIR__.'/stripe-sandbox.php';
register_activation_hook(__FILE__,'jdb_install_api_table');
add_action('admin_menu',function(){
    add_menu_page('Jelly Booking','Jelly Booking','manage_options','jdb-bookings','jdb_api_activity','dashicons-calendar-alt');
});
