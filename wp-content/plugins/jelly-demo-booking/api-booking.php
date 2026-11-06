<?php
defined('ABSPATH') || exit;
function jdb_install_api_table() {
    if (get_option('jdb_api_schema')==='1') return;
    global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php';
    dbDelta("CREATE TABLE {$wpdb->prefix}jdb_api_bookings (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        request_key varchar(64) NOT NULL,
        payload_hash varchar(64) NOT NULL,
        environment varchar(8) NOT NULL,
        state varchar(24) NOT NULL,
        patient_id varchar(40) NOT NULL DEFAULT '',
        appointment_id varchar(40) NOT NULL DEFAULT '',
        created_at datetime NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY request_key (request_key)
    ) ".$wpdb->get_charset_collate().';');
    update_option('jdb_api_schema','1',false);
}
add_action('init','jdb_install_api_table');
function jdb_uuid($id) { return is_string($id) && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i',$id); }
function jdb_patient_input($r) {
    $out=array();
    foreach(array('firstName','lastName','email','mobile','dob','portalPassword') as $key) {
        $v=$r[$key]??''; if(!is_string($v)) return jdb_failure('patient','Invalid patient details.',400);
        $out[$key]=$key==='portalPassword'?$v:trim($v);
    }
    if (!$out['firstName'] || !$out['lastName'] || strlen($out['firstName'])>80 || strlen($out['lastName'])>80 || !is_email($out['email']) || strlen($out['email'])>50 || strlen($out['mobile'])>20 || strlen($out['portalPassword'])>30) return jdb_failure('patient','Enter your name, a valid email and valid contact details.',400);
    if (preg_match('/[\x00-\x1F\x7F]/',implode('',$out))) return jdb_failure('patient','Invalid characters in patient details.',400);
    $dob=DateTimeImmutable::createFromFormat('!Y-m-d',$out['dob'],wp_timezone());
    if (!$dob || $dob->format('Y-m-d')!==$out['dob'] || $dob>new DateTimeImmutable('today',wp_timezone()) || $dob<new DateTimeImmutable('1900-01-01')) return jdb_failure('patient','Enter a valid date of birth.',400);
    if ($r['consent']!==true || !is_bool($r['existing'])) return jdb_failure('consent','Confirm the booking and data-sharing notice.',400);
    if ($r['existing'] && !$out['portalPassword']) return jdb_failure('patient','Existing patients must enter their Jelly patient portal password.',400);
    return $out;
}
function jdb_submit_booking($r) {
    if (!jdb_submissions_enabled()) return jdb_failure('submissions_disabled','Submissions are disabled. No patient information or booking was sent.',403);
    return jdb_payment_guard();
}

add_action('admin_menu',function(){add_submenu_page('jdb-bookings','Jelly booking activity','Jelly booking activity','manage_options','jdb-api-activity','jdb_api_activity');},20);
function jdb_api_activity() {
    if(!current_user_can('manage_options')) return;
    global $wpdb; $rows=$wpdb->get_results("SELECT environment,state,appointment_id,created_at FROM {$wpdb->prefix}jdb_api_bookings ORDER BY id DESC LIMIT 100");
    echo '<div class="wrap"><h1>Jelly booking activity</h1><p>Latest 100 attempts. Review incomplete attempts in Jelly before retrying. Dates below are UTC. Confirmed bookings are held in Jelly.</p><table class="widefat striped"><thead><tr><th>Environment</th><th>State</th><th>Appointment ID</th><th>Attempted at (UTC)</th></tr></thead><tbody>';
    foreach($rows as $r){echo '<tr>'; foreach((array)$r as $v) echo '<td>'.esc_html($v).'</td>'; echo '</tr>';}
    if(!$rows) echo '<tr><td colspan="4">No API booking attempts yet.</td></tr>';
    echo '</tbody></table></div>';
}
