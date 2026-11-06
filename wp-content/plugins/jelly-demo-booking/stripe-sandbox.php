<?php
defined('ABSPATH') || exit;
function jdb_stripe_secrets(){try{$s=get_option('jdb_stripe_sandbox','');return $s?jdb_api_decrypt($s):array();}catch(Throwable $e){return array();}}
function jdb_stripe_request($path,$body=null,$idempotency=''){
    $key=jdb_stripe_secrets()['key']??'';
    if(!preg_match('/^sk_test_[A-Za-z0-9]+$/',$key))return new WP_Error('stripe_setup','Stripe sandbox is not configured. Ask the administrator to save a Stripe test secret key.',array('status'=>503));
    $args=array('timeout'=>20,'method'=>$body===null?'GET':'POST','headers'=>array('Authorization'=>'Bearer '.$key));
    if($body!==null){$args['headers']['Content-Type']='application/x-www-form-urlencoded';$args['body']=http_build_query($body);}
    if($idempotency)$args['headers']['Idempotency-Key']=$idempotency;
    $r=wp_remote_request('https://api.stripe.com/v1/'.$path,$args);
    if(is_wp_error($r))return new WP_Error('stripe_network','Could not reach Stripe. Please retry.',array('status'=>502));
    $data=json_decode(wp_remote_retrieve_body($r),true);
    if(wp_remote_retrieve_response_code($r)!==200||!is_array($data))return new WP_Error('stripe_error','Stripe could not complete this sandbox request. Check the sandbox credentials.',array('status'=>502));
    if(($data['livemode']??null)!==false)return new WP_Error('stripe_live','Live Stripe responses are blocked.',array('status'=>403));
    return $data;
}
function jdb_stripe_create($r){
    // No patient data or Jelly write operation is used in this independent test.
    $public=jdb_stripe_secrets()['public']??'';
    if(!preg_match('/^pk_test_[A-Za-z0-9]+$/',$public))return new WP_Error('stripe_public','Save your Stripe test publishable key in Jelly Booking → Stripe sandbox to enable the embedded payment form.',array('status'=>503));
    $token=bin2hex(random_bytes(24));
    $data=jdb_stripe_request('payment_intents',array('amount'=>100,'currency'=>'gbp','payment_method_types'=>array('card'),'description'=>'Sandbox card test — no appointment'),'jdb-sandbox-'.$token);
    if(is_wp_error($data))return $data;
    if(strpos($data['id']??'','pi_')!==0||empty($data['client_secret']))return new WP_Error('stripe_checkout','Stripe did not return a valid test checkout.',array('status'=>502));
    set_transient('jdb_stripe_test_'.hash('sha256',$token),array('id'=>$data['id']),DAY_IN_SECONDS);
    return array('clientSecret'=>$data['client_secret'],'publishableKey'=>$public,'token'=>$token);
}
function jdb_stripe_status($r){
    $token=(string)$r['token'];if(!preg_match('/^[a-f0-9]{48}$/',$token))return new WP_Error('test_token','Invalid test session.',array('status'=>400));
    $record=get_transient('jdb_stripe_test_'.hash('sha256',$token));if(!$record)return new WP_Error('test_expired','This test session has expired.',array('status'=>404));
    $intent=strpos($record['id'],'pi_')===0;
    $data=jdb_stripe_request(($intent?'payment_intents/':'checkout/sessions/').rawurlencode($record['id']));if(is_wp_error($data))return $data;
    $paid=($data['id']??'')===$record['id']&&($data['currency']??'')==='gbp'&&($intent?(($data['status']??'')==='succeeded'&&($data['amount']??0)===100&&($data['amount_received']??0)===100):(($data['status']??'')==='complete'&&($data['payment_status']??'')==='paid'&&($data['amount_total']??0)===100));
    return array('paid'=>$paid,'message'=>$paid?'Stripe verified the £1 sandbox payment. No real charge or Jelly booking was created.':'The sandbox payment has not been verified as paid. No booking was created.');
}
function jdb_stripe_webhook($r){
    $secret=jdb_stripe_secrets()['webhook']??'';$raw=$r->get_body();$parts=array();foreach(explode(',',$r->get_header('stripe-signature')) as $part){$pair=explode('=',$part,2);if(count($pair)===2)$parts[$pair[0]][]=$pair[1];}
    $time=(int)($parts['t'][0]??0);$valid=false;
    if($secret&&abs(time()-$time)<=300){$expected=hash_hmac('sha256',$time.'.'.$raw,$secret);foreach($parts['v1']??array() as $signature)if(hash_equals($expected,$signature))$valid=true;}
    $event=json_decode($raw,true);
    if(!$valid||!is_array($event)||($event['livemode']??null)!==false)return new WP_Error('stripe_signature','Invalid sandbox webhook.',array('status'=>400));
    // Acknowledgement only: no fulfilment and no Jelly calls. Return status uses Stripe retrieval.
    return array('received'=>true,'bookingCreated'=>false);
}
add_action('rest_api_init',function(){
    foreach(array('stripe-test-checkout'=>array('POST','jdb_stripe_create'),'stripe-test-status'=>array('GET','jdb_stripe_status')) as $path=>$config)register_rest_route('jelly/v1','/'.$path,array('methods'=>$config[0],'callback'=>$config[1],'permission_callback'=>'jdb_public_access'));
    register_rest_route('jelly/v1','/stripe-test-webhook',array('methods'=>'POST','callback'=>'jdb_stripe_webhook','permission_callback'=>'__return_true'));
});
add_action('admin_menu',function(){add_submenu_page('jdb-bookings','Stripe sandbox','Stripe sandbox','manage_options','jdb-stripe-sandbox','jdb_stripe_admin');},20);
function jdb_stripe_admin(){
    if(!current_user_can('manage_options'))return;
    if(isset($_POST['jdb_stripe_save'])){check_admin_referer('jdb_stripe_save');$saved=jdb_stripe_secrets();$key=trim(wp_unslash($_POST['test_key']??''));$webhook=trim(wp_unslash($_POST['webhook']??''));$public=trim(wp_unslash($_POST['public_key']??''));
        if(isset($_POST['clear'])){delete_option('jdb_stripe_sandbox');echo '<div class="notice notice-success"><p>Sandbox credentials cleared.</p></div>';}
        elseif(($public&&!preg_match('/^pk_test_[A-Za-z0-9]+$/',$public))||($key&&!preg_match('/^sk_test_[A-Za-z0-9]+$/',$key))||($webhook&&!preg_match('/^whsec_[A-Za-z0-9]+$/',$webhook))){echo '<div class="notice notice-error"><p>Not saved. Only Stripe test secret/publishable keys and valid webhook secrets are accepted. Live keys are blocked.</p></div>';}
        else {update_option('jdb_stripe_sandbox',jdb_api_encrypt(array('public'=>$public?:($saved['public']??''),'key'=>$key?:($saved['key']??''),'webhook'=>$webhook?:($saved['webhook']??''))),false);echo '<div class="notice notice-success"><p>Sandbox settings saved. No payment was created.</p></div>';}
    }
    echo '<div class="wrap"><h1>Stripe sandbox</h1><p>Independent £1 GBP test checkout. Real payments and Jelly submissions stay blocked. Use fictional details only.</p><p>Credentials: '.(!empty(jdb_stripe_secrets()['key'])?'test key saved':'not configured').'. Publishable key: '.(!empty(jdb_stripe_secrets()['public'])?'saved':'not configured').'. Secret fields are never filled back in.</p><form method="post">';wp_nonce_field('jdb_stripe_save');
    echo '<p><label>Stripe test publishable key (pk_test_)<br><input type="text" name="public_key" size="65" autocomplete="off"></label><br>Leave blank to keep the saved key. Required for embedded checkout.</p><p><label>Stripe test secret key (sk_test_)<br><input type="password" name="test_key" size="65" autocomplete="new-password"></label></p><p><label>Webhook signing secret (whsec_, optional for return-page testing)<br><input type="password" name="webhook" size="65" autocomplete="new-password"></label></p><p><label><input type="checkbox" name="clear"> Clear saved sandbox credentials</label></p><button class="button button-primary" name="jdb_stripe_save" value="1">Save sandbox settings</button></form><h2>Testing</h2><p>Use card 4242 4242 4242 4242, any future expiry, and any three-digit CVC in Stripe test mode. Use 4000 0000 0000 0002 for a declined card.</p><p>Local webhook forwarding (requires Stripe CLI logged into your sandbox):</p><code>stripe listen --forward-to http://localhost/jelly-booking/?rest_route=/jelly/v1/stripe-test-webhook</code><p>Save the signing secret printed by Stripe CLI above. A public webhook cannot call localhost directly.</p><p>Payment return is verified on the server by retrieving the Stripe session. Webhooks only acknowledge signed test events; they never create bookings.</p></div>';
}
