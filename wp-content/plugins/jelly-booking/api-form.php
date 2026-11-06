<?php
defined('ABSPATH') || exit;
add_shortcode('jelly_booking',function() {
    // The editable HTML is shared by WordPress and the standalone page.
    $custom=is_readable(ABSPATH.'custom-booking/index.html');
    $path=$custom?ABSPATH.'custom-booking/index.html':__DIR__.'/custom-booking/index.html';
    if(!is_readable($path)) return '<p>The custom booking template is missing.</p>';
    $html=file_get_contents($path);
    $parts=explode('<!-- booking:start -->',$html,2);
    if(count($parts)!==2) return '<p>The custom booking template needs its booking markers.</p>';
    $markup=explode('<!-- booking:end -->',$parts[1],2)[0];
    $base=$custom?home_url('/custom-booking/'):plugins_url('custom-booking/',__FILE__);
    wp_enqueue_style('jdb',$base.'style.css',array(),'0.4.22');
    wp_enqueue_script('jdb',$base.'app.js',array(),'0.4.22',true);
    wp_localize_script('jdb','JDB',array('api'=>rest_url('jelly/v1/'),'session'=>admin_url('admin-ajax.php?action=jdb_session'),'timezone'=>wp_timezone_string(),'currency'=>get_option('jdb_api_currency','GBP'),'environment'=>jdb_environment(),'submissionsEnabled'=>jdb_submissions_enabled()));
    return $markup;
});

