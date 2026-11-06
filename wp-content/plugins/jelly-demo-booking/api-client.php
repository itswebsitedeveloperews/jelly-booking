<?php
defined('ABSPATH') || exit;

function jdb_environment() {
    $env = get_option('jdb_api_environment','test');
    return isset(jdb_api_urls()[$env]) ? $env : 'test';
}
function jdb_fingerprint($env) { return hash('sha256', get_option('jdb_api_credentials_'.$env,'')); }
function jdb_failure($code,$message,$status=503) { return new WP_Error($code,$message,array('status'=>$status)); }
function jdb_submissions_enabled() { return get_option('jdb_allow_submissions',false) === '1'; }
function jdb_api_object($result) {
    if (is_wp_error($result)) return $result;
    if (isset($result[0]) && is_array($result[0]) && count($result)===1) return $result[0];
    return $result;
}
function jdb_normalize_response($data) {
    if(!is_array($data)) return $data;
    $normalized=array();
    foreach($data as $key=>$value) {
        $canonical=is_string($key)?ucfirst($key):$key;
        $normalized[$canonical]=jdb_normalize_response($value);
    }
    return $normalized;
}
function jdb_ready($env) {
    return hash_equals(jdb_fingerprint($env),(string)get_option('jdb_api_verified_'.$env,''));
}
function jdb_transport($env,$path,$method,$data,$token='') {
    if (in_array($path,array('patients','checkPatient','appointments'),true) && !jdb_submissions_enabled()) return jdb_failure('submissions_disabled','Submissions are disabled. No patient information or booking was sent.',403);
    if (in_array($path,array('patients','checkPatient','appointments'),true)) return jdb_payment_guard();
    if (!isset(jdb_api_urls()[$env]) || !in_array($path,array('token','getLocations','getApptTypes','getUsers','getTimeSlots','checkPatient','patients','appointments'),true)) return jdb_failure('endpoint','Unsupported Jelly request.');
    $headers = array('Accept'=>'application/json','Content-Type'=>'application/json');
    if ($token) $headers['Authorization'] = 'Bearer '.$token;
    if (in_array($path,array('getTimeSlots','checkPatient'),true)) {
        global $wpdb;
        $lock='jdb_limit_'.md5($env.$path);
        if (!$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 2)',$lock))) return jdb_failure('busy','Jelly is busy. Please wait a moment.',429);
        try {
            $key='jdb_limit_'.md5($env.$path.gmdate('YmdHi'));
            $count=(int)get_transient($key);
            if ($count>=18) return jdb_failure('rate','Jelly availability requests are temporarily limited. Please wait one minute.',429);
            set_transient($key,$count+1,70);
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
    }
    // WordPress overrides data_format for GET; set it on the underlying Requests hook.
    $target=jdb_api_urls()[$env].$path;
    $json_get=function(&$url,&$request_headers,&$body,&$type,&$options) use($target) {
        if($url===$target && $type==='GET') $options['data_format']='body';
    };
    add_action('requests-requests.before_request',$json_get,10,5);
    try {
        $response = wp_remote_request($target,array('method'=>$method,'headers'=>$headers,'body'=>wp_json_encode($data),'data_format'=>'body','timeout'=>25,'redirection'=>0,'sslverify'=>true));
    } catch(Throwable $e) { $response=new WP_Error('jelly_transport','Jelly request could not be completed.'); }
    finally { remove_action('requests-requests.before_request',$json_get,10); }
    if (is_wp_error($response)) return jdb_failure('transport','Could not reach Jelly. Check server connectivity and TLS certificates.');
    $status = wp_remote_retrieve_response_code($response);
    if ($status===204) return array();
    if ($status<200 || $status>=300) {
        if (in_array($status,array(401,403),true)) { delete_transient('jdb_token_'.$env); delete_option('jdb_api_verified_'.$env); }
        return jdb_failure('jelly_http_'.$status,'Jelly returned HTTP '.$status.'. Check API permissions, credentials and any required IP authorisation.',in_array($status,array(400,409,429),true)?$status:502);
    }
    $decoded = json_decode(wp_remote_retrieve_body($response),true);
    if ($path==='getTimeSlots' && is_string($decoded) && rtrim(strtolower(trim($decoded)),'.')==='no timeslots found') return array();
    if (!is_array($decoded)) return jdb_failure('jelly_format','Jelly returned an unexpected response. Ask your administrator to check the API.');
    return jdb_normalize_response($decoded);
}
function jdb_token($env) {
    $cached = get_transient('jdb_token_'.$env);
    if (is_array($cached) && ($cached['fingerprint']??'')===jdb_fingerprint($env)) {
        try { return jdb_api_decrypt($cached['secret'])['token']; } catch(Throwable $e) { delete_transient('jdb_token_'.$env); }
    }
    try { $credentials = jdb_api_credentials($env); } catch(Throwable $e) { return jdb_failure('credentials','Saved Jelly credentials cannot be decrypted. Save them again.'); }
    if (empty($credentials['code']) || empty($credentials['user']) || empty($credentials['password'])) return jdb_failure('setup','Jelly is not configured. An administrator must save credentials and test the connection.');
    // Token creation is limited by Jelly to one per minute. Atomic option prevents concurrent requests.
    $lock = 'jdb_token_attempt_'.$env;
    $last = (int)get_option($lock,0);
    if ($last && $last<time()-65) delete_option($lock);
    if (!add_option($lock,time(),'','no')) return jdb_failure('token_cooldown','Wait one minute before testing Jelly authentication again.',429);
    $result = jdb_transport($env,'token','POST',array('Code'=>$credentials['code'],'User'=>$credentials['user'],'Password'=>$credentials['password']));
    $result = jdb_api_object($result);
    if (is_wp_error($result)) return $result;
    if (!isset($result['ReturnCode']) || (int)$result['ReturnCode']!==0 || empty($result['Token']) || !is_string($result['Token'])) return jdb_failure('authentication','Jelly did not authorise these credentials. Check the access code, user, password and API permissions.',502);
    try { $secret = jdb_api_encrypt(array('token'=>$result['Token'])); } catch(Throwable $e) { return jdb_failure('encryption','Could not securely store the Jelly token.'); }
    $ttl = 6*DAY_IN_SECONDS;
    $parts = explode('.',$result['Token']);
    $claims = count($parts)===3 ? json_decode(base64_decode(strtr($parts[1],'-_','+/')),true) : null;
    if (isset($claims['exp'])) $ttl = max(1,min($ttl,(int)$claims['exp']-time()-60));
    set_transient('jdb_token_'.$env,array('fingerprint'=>jdb_fingerprint($env),'secret'=>$secret),$ttl);
    return $result['Token'];
}
function jdb_call($path,$data=array(),$method='GET',$env=null) {
    $env = $env ?: jdb_environment();
    $token = jdb_token($env);
    if (is_wp_error($token)) return $token;
    $result=jdb_transport($env,$path,$method,$data,$token);
    return in_array($path,array('patients','checkPatient','appointments'),true)?jdb_api_object($result):$result;
}
add_action('admin_post_jdb_test_api',function() {
    if (!current_user_can('manage_options')) wp_die('Administrator access required.','',array('response'=>403));
    check_admin_referer('jdb_test_api');
    $env = isset($_POST['environment']) && is_string($_POST['environment']) ? sanitize_key($_POST['environment']) : '';
    if (!isset(jdb_api_urls()[$env])) wp_die('Invalid environment.','',array('response'=>400));
    $response = jdb_call('getLocations',array('Site'=>(int)get_option('jdb_api_site',1)), 'GET',$env);
    $rows = is_wp_error($response)?$response:jdb_list($response);
    $ok = !is_wp_error($rows);
    if ($ok) update_option('jdb_api_verified_'.$env,jdb_fingerprint($env),false); else delete_option('jdb_api_verified_'.$env);
    update_option('jdb_api_test_result_'.$env,array('ok'=>$ok,'message'=>$ok?'Connection successful. Authentication and location access verified ('.count($rows).' locations).':$rows->get_error_message(),'at'=>time()),false);
    wp_safe_redirect(add_query_arg(array('page'=>'jdb-api-settings','environment'=>$env),admin_url('admin.php'))); exit;
});
add_action('admin_post_jdb_save_scope',function() {
    if (!current_user_can('manage_options')) wp_die('Administrator access required.','',array('response'=>403));
    check_admin_referer('jdb_save_scope');
    $site = filter_var($_POST['site']??null,FILTER_VALIDATE_INT,array('options'=>array('min_range'=>1,'max_range'=>99999)));
    if (!$site) wp_die('Invalid site number.','',array('response'=>400));
    $currency=isset($_POST['currency']) && is_string($_POST['currency'])?strtoupper(trim(wp_unslash($_POST['currency']))):'';
    if(!preg_match('/^[A-Z]{3}$/',$currency)) wp_die('Enter a three-letter currency code.','',array('response'=>400));
    update_option('jdb_api_currency',$currency,false);
    update_option('jdb_api_site',$site,false); update_option('jdb_api_reservations',empty($_POST['reservations'])?0:1,false);
    wp_safe_redirect(admin_url('admin.php?page=jdb-api-settings')); exit;
});
