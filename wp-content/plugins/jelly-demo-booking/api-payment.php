<?php
defined('ABSPATH') || exit;

// Preparation only. No setting or browser return can activate checkout.
function jdb_payment_guard() {
    return jdb_failure('payment_setup_required','Real checkout is disabled. Provider configuration, payment verification and sandbox approval are required before activation.',503);
}

function jdb_payment_provider($env) {
    $value=get_option('jdb_payment_provider_'.$env,'unknown');
    return in_array($value,array('stripe','ryft'),true)?$value:'unknown';
}

// Offline payload planner; never calls Jelly. Paid must be explicitly agreed with Jelly.
function jdb_payment_plan($appointment,$paid,$success_url,$cancel_url) {
    if (!is_array($appointment) || !is_bool($paid)) return jdb_failure('payment_plan','An appointment and an explicit Jelly Paid flag are required.',400);
    foreach(array($success_url,$cancel_url) as $url) {
        if (!is_string($url) || !filter_var($url,FILTER_VALIDATE_URL) || strtolower((string)parse_url($url,PHP_URL_SCHEME))!=='https' || parse_url($url,PHP_URL_USER) || parse_url($url,PHP_URL_PASS)) return jdb_failure('payment_return','Production return URLs must use HTTPS without credentials.',400);
    }
    return array_merge($appointment,array('UseStripe'=>true,'Paid'=>$paid,'SuccessURL'=>$success_url,'CancelURL'=>$cancel_url,'EmailReceipt'=>false));
}

// Classify a fixture or future Jelly response. This is never proof of payment.
// Ryft host allowlisting and the Stripe checkout adapter remain activation requirements.
function jdb_payment_handoff($raw,$provider) {
    if (!in_array($provider,array('stripe','ryft'),true)) return jdb_failure('payment_provider','Confirm the clinic payment provider first.',409);
    $data=jdb_api_object(jdb_normalize_response($raw));
    if (is_wp_error($data)) return $data;
    if (!is_array($data) || !isset($data['ReturnCode']) || !in_array($data['ReturnCode'],array(0,'0'),true)) return jdb_failure('payment_response','Jelly did not confirm checkout preparation.',502);
    $url=$data['RyftURL']??($data['RyftUrl']??'');
    $id=$data['TransactionId']??'';
    if (!is_string($url) || !is_string($id) || ($url!=='' && $id!=='')) return jdb_failure('payment_response','Ambiguous checkout response. Clinic review required.',502);
    if ($provider==='ryft' && $url!=='' && $id==='') {
        if (!filter_var($url,FILTER_VALIDATE_URL) || strtolower((string)parse_url($url,PHP_URL_SCHEME))!=='https' || parse_url($url,PHP_URL_USER) || parse_url($url,PHP_URL_PASS)) return jdb_failure('payment_url','Invalid checkout URL.',502);
        return array('provider'=>'ryft','status'=>'handoff_unverified','checkoutUrl'=>$url,'confirmed'=>false);
    }
    if ($provider==='stripe' && $id!=='' && $url==='') return array('provider'=>'stripe','status'=>'handoff_unverified','transactionId'=>$id,'confirmed'=>false);
    return jdb_failure('payment_mismatch','Checkout response does not match the confirmed provider.',502);
}

add_action('admin_post_jdb_save_payment','jdb_save_payment');
function jdb_save_payment() {
    if (!current_user_can('manage_options')) wp_die('Administrator access required.','',array('response'=>403));
    check_admin_referer('jdb_save_payment');
    $env=isset($_POST['environment']) && is_string($_POST['environment'])?wp_unslash($_POST['environment']):'';
    $provider=isset($_POST['provider']) && is_string($_POST['provider'])?wp_unslash($_POST['provider']):'';
    if (!isset(jdb_api_urls()[$env]) || !in_array($provider,array('unknown','stripe','ryft'),true)) wp_die('Invalid payment configuration.','',array('response'=>400));
    update_option('jdb_payment_provider_'.$env,$provider,false);
    // Saving a provider does not enable submissions.
    wp_safe_redirect(add_query_arg(array('page'=>'jdb-api-settings','environment'=>$env),admin_url('admin.php')));
    exit;
}

function jdb_payment_settings_panel($env) {
    ?>
    <h2>Payment integration preparation</h2>
    <div class="notice notice-warning inline"><p><strong>Real payments and bookings are blocked.</strong> Selecting a provider does not activate checkout. The booking page continues to offer a local payment simulation only.</p></div>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <input type="hidden" name="action" value="jdb_save_payment"><input type="hidden" name="environment" value="<?php echo esc_attr($env); ?>">
    <?php wp_nonce_field('jdb_save_payment'); ?>
    <p><label for="jdb-payment-provider">Provider confirmed by your clinic or Jelly</label>
    <select id="jdb-payment-provider" name="provider"><?php foreach(array('unknown'=>'Not confirmed yet','stripe'=>'Stripe','ryft'=>'Ryft') as $key=>$label): ?><option value="<?php echo esc_attr($key); ?>" <?php selected(jdb_payment_provider($env),$key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></p>
    <p>Saved separately for test and live environments. API login does not identify your payment provider.</p>
    <?php submit_button('Save provider preference','secondary'); ?></form>
    <p>Before activation, Jelly must confirm the full-payment/deposit flag, checkout setup, slot expiry and payment-status verification. A success redirect alone will never confirm a booking.</p>
    <p><a href="https://www.jellysoftware.com/documents/apiinfo" target="_blank" rel="noopener noreferrer">Jelly API documentation</a></p>
    <?php
}
