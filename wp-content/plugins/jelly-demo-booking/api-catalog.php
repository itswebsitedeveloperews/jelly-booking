<?php
defined('ABSPATH') || exit;

function jdb_list($result) {
    if (is_wp_error($result)) return $result;
    if (isset($result[0]['ReturnCode']) && (int)$result[0]['ReturnCode']!==0) return jdb_failure('jelly_result','Jelly could not complete this request. Check the clinic API configuration.',502);
    if (isset($result['ReturnCode']) && (int)$result['ReturnCode']!==0) return jdb_failure('jelly_result','Jelly could not complete this request.',502);
    if (array_keys($result)===range(0,count($result)-1) || !$result) return $result;
    if (isset($result['']) && is_array($result[''])) return $result[''];
    return jdb_failure('jelly_schema','Jelly returned an unexpected list format.',502);
}
function jdb_truth($value) { return filter_var($value,FILTER_VALIDATE_BOOLEAN); }
function jdb_site() { return max(1,(int)get_option('jdb_api_site',1)); }
function jdb_scope_key() { return jdb_environment().'|'.jdb_fingerprint(jdb_environment()).'|'.jdb_site().'|'.get_option('jdb_api_reservations',1); }
function jdb_cached_list($path,$params,$ttl=60,$fresh=false) {
    $key = 'jdb_list_'.md5('v3|'.jdb_scope_key().$path.wp_json_encode($params));
    if (!$fresh) { $cached = get_transient($key); if (is_array($cached)) return $cached; }
    $result = jdb_list(jdb_call($path,$params));
    if (!is_wp_error($result)) set_transient($key,$result,$ttl);
    return $result;
}
function jdb_locations() {
    $params = array('Site'=>jdb_site(),'UseTimeReservations'=>(bool)get_option('jdb_api_reservations',1));
    $rows = jdb_cached_list('getLocations',$params,300);
    if (is_wp_error($rows)) return $rows;
    $out = array();
    foreach($rows as $r) {
        if (empty($r['LocationId']) || empty($r['Name']) || (isset($r['Site']) && (int)$r['Site']!==jdb_site())) continue;
        $out[] = array('id'=>(string)$r['LocationId'],'name'=>(string)$r['Name'],'address'=>implode(', ',array_filter(array($r['AddressLine1']??'',$r['AddressLine2']??'',$r['City']??'',$r['Postcode']??''))),'phone'=>(string)($r['PhoneNo']??''),'category'=>(string)($r['LocationCategory']??''));
    }
    return $out;
}
function jdb_services_api($location) {
    // Public reference catalogue verified 2026-11-02 for this exact location/site.
    // Preview only: never broaden the services accepted for real submissions.
    $reference_ids=array();
    if (!jdb_submissions_enabled() && jdb_environment()==='live' && jdb_site()===1 && $location==='69340ae7-cc0a-4081-baee-e6b3a5a85309') {
        $reference_ids=array('61042fcf-f0ab-49e0-9e3e-cff8f601851a','ebeb3013-1cc7-43b9-9094-6ce7322b8e0f','3ce40c19-3c10-4b3b-8a1a-447d976890a1','e6a68c77-8319-4272-baed-81b2718feae0');
    }
    $params=array('Site'=>jdb_site());
    if (!$reference_ids) $params['LocationId']=$location;
    $rows = jdb_cached_list('getApptTypes',$params);
    if (is_wp_error($rows)) return $rows;
    $out = array();
    foreach($rows as $r) {
        if ($reference_ids && !in_array($r['ApptTypeId']??'', $reference_ids,true)) continue;
        if (empty($r['ApptTypeId']) || empty($r['Name']) || jdb_truth($r['SuppressOnlineBooking']??false) || (int)($r['Duration']??0)<1) continue;
        $out[] = array('id'=>(string)$r['ApptTypeId'],'name'=>(string)$r['Name'],'category'=>(string)($r['ApptCategory']??''),'referencePreview'=>(bool)$reference_ids,'duration'=>(int)$r['Duration'],'cost'=>jdb_truth($r['BookingDontCharge']??false)?0:($r['DefaultCost']??null),'from'=>$r['AvailableFrom']??null,'to'=>$r['AvailableTo']??null);
    }
    return $out;
}
function jdb_users_api($service) {
    $rows = jdb_cached_list('getUsers',array('Site'=>jdb_site(),'ApptTypeId'=>$service));
    if (is_wp_error($rows)) return $rows;
    $out = array();
    foreach($rows as $r) {
        if (empty($r['UserId']) || !jdb_truth($r['HasDiary']??false)) continue;
        if (isset($r['Sites']) && is_array($r['Sites']) && !in_array(jdb_site(),array_map('intval',$r['Sites']),true)) continue;
        $out[] = array('id'=>(string)$r['UserId'],'name'=>(string)($r['DisplayName']??trim(($r['FirstName']??'').' '.($r['LastName']??''))),'title'=>sanitize_text_field($r['JobTitle']??$r['JobTiitle']??''),'biography'=>wp_strip_all_tags($r['Biography']??''),'profileUrl'=>esc_url_raw($r['BiographyURL']??'',array('https')));
    }
    return $out;
}
function jdb_find($list,$id) {
    if (is_wp_error($list)) return $list;
    foreach($list as $item) if (strtolower($item['id'])===strtolower($id)) return $item;
    return jdb_failure('selection','This booking option is no longer available. Please start again.',400);
}
function jdb_selection($r) {
    $loc = jdb_find(jdb_locations(),(string)$r['location']);
    if (is_wp_error($loc)) return $loc;
    $service = jdb_find(jdb_services_api($loc['id']),(string)$r['service']);
    if (is_wp_error($service)) return $service;
    $user = jdb_find(jdb_users_api($service['id']),(string)$r['clinician']);
    if (is_wp_error($user)) return $user;
    return array('location'=>$loc,'service'=>$service,'user'=>$user);
}
function jdb_api_slots($selection,$date,$fresh=false,$allDates=false) {
    $day = DateTimeImmutable::createFromFormat('!Y-m-d',$date,wp_timezone());
    $today = new DateTimeImmutable('today',wp_timezone());
    if (!$day || $day->format('Y-m-d')!==$date || $day<$today || $day>$today->modify('+1 year')) return jdb_failure('date','Choose a date within the next year.',400);
    $params = array('ApptTypeId'=>$selection['service']['id'],'UserId'=>$selection['user']['id'],'StartDate'=>$day->format('Ym').'01','Timezone'=>'UTC');
    if (get_option('jdb_api_reservations',1)) $params['LocationId']=$selection['location']['id']; else $params['Site']=jdb_site();
    $rows = jdb_cached_list('getTimeSlots',$params,45,$fresh);
    if (is_wp_error($rows)) return $rows;
    $out=array(); $now = time();
    foreach($rows as $r) {
        if (empty($r['Start']) || strtolower($r['UserId']??'')!==strtolower($selection['user']['id']) || strtolower($r['LocationId']??'')!==strtolower($selection['location']['id'])) continue;
        if (isset($r['Site']) && (int)$r['Site']!==jdb_site()) continue;
        try { $start = new DateTimeImmutable($r['Start'],new DateTimeZone('UTC')); } catch(Throwable $e) { continue; }
        $local=$start->setTimezone(wp_timezone());
        if ((!$allDates && $local->format('Y-m-d')!==$date) || ($allDates && ($local->format('Y-m-d')<$date || $local->format('Y-m')!==$day->format('Y-m'))) || $start->getTimestamp()<=$now || $local->format('Y-m-d')>$today->modify('+1 year')->format('Y-m-d')) continue;
        if (!empty($selection['service']['from']) && $start->getTimestamp()<strtotime($selection['service']['from'])) continue;
        if (!empty($selection['service']['to']) && $local->format('Y-m-d')>substr($selection['service']['to'],0,10)) continue;
        $out[] = array('start'=>$start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),'label'=>$local->format('H:i'),'date'=>$local->format('Y-m-d'));
    }
    usort($out,function($a,$b){return strcmp($a['start'],$b['start']);});
    return array_values(array_column($out,null,'start'));
}
function jdb_public_access($request) {
    if (!wp_verify_nonce($request->get_header('X-JDB-Nonce'),'jdb_api')) return jdb_failure('nonce','Your session needs refreshing. Please reload the page.',403);
    if (!jdb_ready(jdb_environment())) return jdb_failure('setup','Online booking is not connected yet. Please ask the clinic to test its Jelly connection.');
    $key='jdb_rate_'.md5(($_SERVER['REMOTE_ADDR']??'local').gmdate('YmdHi'));
    $count=(int)get_transient($key);
    if ($count>=30) return jdb_failure('rate','Please wait a minute before trying again.',429);
    set_transient($key,$count+1,70);
    return true;
}
function jdb_session() {
    nocache_headers();
    $catalog=array();
    if (isset($_GET['catalog']) && $_GET['catalog']==='1') {
        $rows=jdb_locations();
        if (!is_wp_error($rows)) $catalog=array('locations'=>$rows);
    }
    wp_send_json_success(array_merge($catalog,array('nonce'=>wp_create_nonce('jdb_api'),'restNonce'=>wp_create_nonce('wp_rest'),'timezone'=>wp_timezone_string(),'currency'=>get_option('jdb_api_currency','GBP'),'environment'=>jdb_environment(),'submissionsEnabled'=>jdb_submissions_enabled(),'today'=>wp_date('Y-m-d'),'maxDate'=>(new DateTimeImmutable('today',wp_timezone()))->modify('+1 year')->format('Y-m-d'))));
}
add_action('wp_ajax_jdb_session','jdb_session'); add_action('wp_ajax_nopriv_jdb_session','jdb_session');
add_action('rest_api_init',function() {
    register_rest_route('jelly/v1','/locations',array('methods'=>'GET','permission_callback'=>'jdb_public_access','callback'=>function(){return jdb_locations();}));
    register_rest_route('jelly/v1','/services',array('methods'=>'GET','permission_callback'=>'jdb_public_access','callback'=>function($r){$loc=jdb_find(jdb_locations(),(string)$r['location']); return is_wp_error($loc)?$loc:jdb_services_api($loc['id']);}));
    register_rest_route('jelly/v1','/clinicians',array('methods'=>'GET','permission_callback'=>'jdb_public_access','callback'=>function($r){$loc=jdb_find(jdb_locations(),(string)$r['location']); if(is_wp_error($loc)) return $loc; $s=jdb_find(jdb_services_api($loc['id']),(string)$r['service']); return is_wp_error($s)?$s:jdb_users_api($s['id']);}));
    register_rest_route('jelly/v1','/slots',array('methods'=>'GET','permission_callback'=>'jdb_public_access','callback'=>function($r){$s=jdb_selection($r); return is_wp_error($s)?$s:jdb_api_slots($s,(string)$r['date']);}));
    register_rest_route('jelly/v1','/availability',array('methods'=>'GET','permission_callback'=>'jdb_public_access','callback'=>function($r){
        $s=jdb_selection($r);if(is_wp_error($s))return $s;
        $date=(string)($r['from']?:wp_date('Y-m-d'));
        $slots=jdb_api_slots($s,$date,false,true);if(is_wp_error($slots))return $slots;
        $dates=array_values(array_unique(array_column($slots,'date')));
        $periods=array();
        foreach($slots as $slot){
            $hour=(int)substr($slot['label'],0,2);$period=$hour<12?'morning':($hour<17?'afternoon':'evening');
            if(!isset($periods[$slot['date']]))$periods[$slot['date']]=array();
            if(!in_array($period,$periods[$slot['date']],true))$periods[$slot['date']][]=$period;
        }
        $day=new DateTimeImmutable($date,wp_timezone());
        $next=$day->modify('first day of this month')->modify('+1 month');
        $max=(new DateTimeImmutable('today',wp_timezone()))->modify('+1 year');
        $end=$next->modify('-1 day'); if($end>$max) $end=$max;
        return array('slots'=>$slots,'periodsByDate'=>$periods,'dates'=>$dates,'firstDate'=>$dates[0]??null,'totalSlots'=>count($slots),'rangeEnd'=>$end->format('Y-m-d'),'nextFrom'=>$next<=$max?$next->format('Y-m-d'):null);
    }));
    register_rest_route('jelly/v1','/bookings',array('methods'=>'POST','permission_callback'=>'jdb_public_access','callback'=>'jdb_submit_booking'));
});

// Read-only photo lookup, restricted to the clinic's public profile website.
function jdb_clinician_photo($request) {
    $loc=jdb_find(jdb_locations(),(string)$request['location']);if(is_wp_error($loc))return $loc;
    $service=jdb_find(jdb_services_api($loc['id']),(string)$request['service']);if(is_wp_error($service))return $service;
    $user=jdb_find(jdb_users_api($service['id']),(string)$request['clinician']);if(is_wp_error($user))return $user;
    $url=$user['profileUrl'];$host=strtolower((string)wp_parse_url($url,PHP_URL_HOST));
    if (!in_array($host,array('adhdcentre.co.uk','www.adhdcentre.co.uk'),true))return array('image'=>'');
    $key='jdb_photo_'.md5($url);$cached=get_transient($key);if(is_array($cached))return $cached;
    $result=array('image'=>'');$response=wp_safe_remote_get($url,array('timeout'=>6,'redirection'=>0,'limit_response_size'=>1048576));
    if(!is_wp_error($response) && wp_remote_retrieve_response_code($response)===200){
        $doc=new DOMDocument();$previous=libxml_use_internal_errors(true);$doc->loadHTML(wp_remote_retrieve_body($response));libxml_clear_errors();libxml_use_internal_errors($previous);
        foreach($doc->getElementsByTagName('meta') as $meta){if($meta->getAttribute('property')==='og:image'){$result['image']=esc_url_raw($meta->getAttribute('content'),array('https'));break;}}
    }
    set_transient($key,$result,$result['image']?DAY_IN_SECONDS:300);return $result;
}
add_action('rest_api_init',function(){register_rest_route('jelly/v1','/clinician-photo',array('methods'=>'GET','permission_callback'=>'jdb_public_access','callback'=>'jdb_clinician_photo'));});
