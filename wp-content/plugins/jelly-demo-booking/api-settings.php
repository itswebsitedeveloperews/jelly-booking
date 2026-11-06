<?php
defined('ABSPATH') || exit;

function jdb_api_urls() {
    return array('test'=>'https://www.jellypx1.com/api/', 'live'=>'https://www.jellypx.com/api/');
}
function jdb_api_encrypt($credentials) {
    if (!function_exists('openssl_encrypt')) throw new RuntimeException('Encryption is unavailable on this server.');
    $iv = random_bytes(12);
    $key = hash('sha256', wp_salt('auth') . wp_salt('secure_auth'), true);
    $tag = '';
    $cipher = openssl_encrypt(wp_json_encode($credentials), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'jdb-api-v1');
    if ($cipher === false) throw new RuntimeException('Could not encrypt credentials.');
    return base64_encode($iv . $tag . $cipher);
}
function jdb_api_credentials($environment) {
    $stored = get_option('jdb_api_credentials_' . $environment, '');
    if (!$stored) return array('code'=>'', 'user'=>'', 'password'=>'');
    $data = jdb_api_decrypt($stored);
    if (!is_array($data) || !isset($data['code'],$data['user'],$data['password'])) throw new RuntimeException('Saved credentials cannot be read.');
    return $data;
}
function jdb_api_decrypt($stored) {
    $bytes = base64_decode($stored, true);
    if (!$bytes || strlen($bytes) < 29 || !function_exists('openssl_decrypt')) throw new RuntimeException('Saved credentials cannot be read.');
    $key = hash('sha256', wp_salt('auth') . wp_salt('secure_auth'), true);
    $plain = openssl_decrypt(substr($bytes,28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($bytes,0,12), substr($bytes,12,16), 'jdb-api-v1');
    $data = $plain === false ? null : json_decode($plain,true);
    if (!is_array($data)) throw new RuntimeException('Saved credentials cannot be read.');
    return $data;
}
add_action('admin_menu', function() {
    add_submenu_page('jdb-bookings','Jelly API settings','Jelly API settings','manage_options','jdb-api-settings','jdb_api_settings_page');
}, 20);
add_action('admin_post_jdb_save_api', 'jdb_api_save_settings');
function jdb_api_save_settings() {
    if (!current_user_can('manage_options')) wp_die('Administrator access required.', '', array('response'=>403));
    check_admin_referer('jdb_save_api');
    $status = 'saved';
    try {
        $env = isset($_POST['environment']) && is_string($_POST['environment']) ? wp_unslash($_POST['environment']) : '';
        if (!isset(jdb_api_urls()[$env])) throw new InvalidArgumentException('Invalid environment.');
        if (!empty($_POST['clear_credentials'])) {
            delete_option('jdb_api_credentials_'.$env);
            $status = 'cleared';
        } else {
            $existing = jdb_api_credentials($env);
            $credentials = array();
            foreach (array('code','user','password') as $field) {
                if (!isset($_POST[$field]) || !is_string($_POST[$field])) throw new InvalidArgumentException('Invalid field.');
                $value = wp_unslash($_POST[$field]);
                if ($field !== 'password') $value = trim($value);
                if (strlen($value) > 512 || preg_match('/[\x00-\x1F\x7F]/', $value)) throw new InvalidArgumentException('Invalid field.');
                $credentials[$field] = $value === '' ? $existing[$field] : $value;
                if ($credentials[$field] === '') throw new InvalidArgumentException('All credentials are required.');
            }
            update_option('jdb_api_credentials_'.$env, jdb_api_encrypt($credentials), false);
        }
        update_option('jdb_api_environment', $env, false);
        delete_option('jdb_api_verified_'.$env);
        delete_transient('jdb_token_'.$env);
        delete_option('jdb_api_test_result_'.$env);
    } catch (InvalidArgumentException $e) { $status = 'invalid'; }
      catch (Throwable $e) { $status = 'storage_error'; }
    wp_safe_redirect(add_query_arg(array('page'=>'jdb-api-settings','environment'=>isset(jdb_api_urls()[$env ?? ''])?$env:'test','jdb_status'=>$status), admin_url('admin.php')));
    exit;
}
function jdb_api_settings_page() {
    if (!current_user_can('manage_options')) return;
    $env = isset($_GET['environment']) && is_string($_GET['environment']) ? sanitize_key($_GET['environment']) : get_option('jdb_api_environment','test');
    if (!isset(jdb_api_urls()[$env])) $env = 'test';
    $credentials = array('code'=>'','user'=>'','password'=>''); $storage_error = false;
    try { $credentials = jdb_api_credentials($env); } catch (Throwable $e) { $storage_error = true; }
    $saved = !empty($credentials['code']) && !empty($credentials['user']) && !empty($credentials['password']);
    $messages = array('saved'=>'Credentials saved locally. They have not been verified with Jelly.', 'cleared'=>'Saved credentials removed for this environment.', 'invalid'=>'Nothing was saved. Enter all three credentials on first setup, and use valid text up to 512 characters per field.', 'storage_error'=>'Nothing was saved. The encrypted settings could not be read or written. Clear saved credentials for this environment and enter them again.');
    $status = isset($_GET['jdb_status']) && is_string($_GET['jdb_status']) ? sanitize_key($_GET['jdb_status']) : '';
    ?>
    <div class="wrap"><h1>Jelly API settings</h1>
    <p>Add your Jelly details here. Only WordPress administrators can view this page or change these settings.</p>
    <div class="notice notice-info inline"><p><strong>Booking source: Jelly API.</strong> Save credentials, then test the connection. No sample data is used. <strong><?php echo jdb_submissions_enabled()?'Checkout blocked: payment integration is not activated.':'Preview mode: patient verification, registration and appointment submissions are disabled on the server.'; ?></strong></p></div>
    <?php if(isset($messages[$status])): ?><div role="status" class="notice notice-<?php echo in_array($status,array('invalid','storage_error'),true)?'error':'success'; ?> inline"><p><?php echo esc_html($messages[$status]); ?></p></div><?php endif; ?>
    <?php if($storage_error): ?><div class="notice notice-error inline"><p>Saved credentials cannot be decrypted. Clear this environment’s saved credentials, then enter them again. Changing WordPress security salts makes previously saved credentials unreadable.</p></div><?php endif; ?>
    <h2 class="nav-tab-wrapper" aria-label="Jelly environment"><?php foreach(jdb_api_urls() as $key=>$url): ?><a class="nav-tab <?php echo $key===$env?'nav-tab-active':''; ?>" <?php if($key===$env) echo 'aria-current="page"'; ?> href="<?php echo esc_url(add_query_arg(array('page'=>'jdb-api-settings','environment'=>$key),admin_url('admin.php'))); ?>"><?php echo $key==='test'?'Test environment (recommended)':'Live environment'; ?></a><?php endforeach; ?></h2>
    <p><strong>Credentials:</strong> <?php echo $saved?'Saved locally.':'Not configured.'; ?> Test and live credentials are stored separately. <strong>Active booking environment:</strong> <?php echo esc_html(get_option('jdb_api_environment','test')); ?>.</p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" autocomplete="off">
    <input type="hidden" name="action" value="jdb_save_api"><input type="hidden" name="environment" value="<?php echo esc_attr($env); ?>"><?php wp_nonce_field('jdb_save_api'); ?>
    <table class="form-table" role="presentation"><tbody>
    <tr><th scope="row">API base URL</th><td><code><?php echo esc_html(jdb_api_urls()[$env]); ?></code><p class="description">Fixed to Jelly’s documented <?php echo esc_html($env); ?> server.</p></td></tr>
    <tr><th scope="row"><label for="jdb-code">API access code</label></th><td><input class="regular-text" id="jdb-code" name="code" type="password" maxlength="512" autocomplete="new-password" value="" aria-describedby="jdb-code-help"><p class="description" id="jdb-code-help"><?php echo $credentials['code']!==''?'Access code saved. Leave blank to keep it.':'Enter the access code supplied by Jelly.'; ?></p></td></tr>
    <tr><th scope="row"><label for="jdb-user">API username</label></th><td><input class="regular-text" id="jdb-user" name="user" type="text" maxlength="512" autocomplete="off" value="<?php echo esc_attr($credentials['user']); ?>"><p class="description">Jelly user authorised for your clinic’s API access.</p></td></tr>
    <tr><th scope="row"><label for="jdb-password">API password</label></th><td><input class="regular-text" id="jdb-password" name="password" type="password" maxlength="512" autocomplete="new-password" value="" aria-describedby="jdb-password-help"><p class="description" id="jdb-password-help"><?php echo $credentials['password']!==''?'Password saved. Leave blank to keep it.':'Enter the password for your API user.'; ?></p></td></tr>
    <tr><th scope="row">Remove saved credentials</th><td><label><input type="checkbox" name="clear_credentials" value="1"> Clear credentials for this <?php echo esc_html($env); ?> environment when saving.</label></td></tr>
    </tbody></table>
    <?php submit_button('Save Jelly details'); ?></form>
    <?php jdb_payment_settings_panel($env); ?>
    <h2>Connection test</h2><p>Tests the saved credentials for this tab by requesting a token and reading locations. It does not create patients, appointments or payments. Save changes before testing.</p>
    <?php $result = get_option('jdb_api_test_result_'.$env); if(is_array($result)): ?><div class="notice notice-<?php echo !empty($result['ok'])?'success':'error'; ?> inline" role="status"><p><?php echo esc_html($result['message']); ?> — <?php echo esc_html(wp_date('j M Y H:i:s', $result['at'])); ?></p></div><?php endif; ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="jdb_test_api"><input type="hidden" name="environment" value="<?php echo esc_attr($env); ?>"><?php wp_nonce_field('jdb_test_api'); submit_button('Test connection','secondary','submit',true,$saved?array():array('disabled'=>'disabled')); ?></form>
    <h2>Availability scope</h2><p>Default site is 1. Use location reservations when your Jelly account schedules availability by location; use site scope for multi-site availability. Confirm these settings with your Jelly administrator.</p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="jdb_save_scope"><?php wp_nonce_field('jdb_save_scope'); ?>
    <p><label for="jdb-site">Jelly site number</label> <input id="jdb-site" name="site" type="number" min="1" max="99999" value="<?php echo esc_attr(get_option('jdb_api_site',1)); ?>"></p>
    <p><label for="jdb-currency">Clinic currency</label> <input id="jdb-currency" name="currency" type="text" maxlength="3" pattern="[A-Z]{3}" value="<?php echo esc_attr(get_option('jdb_api_currency','GBP')); ?>"> <span class="description">Three-letter currency code, for example GBP. Jelly’s service API returns prices in the clinic’s local currency.</span></p>
    <p><label><input type="checkbox" name="reservations" value="1" <?php checked(get_option('jdb_api_reservations',1),1); ?>> Use location time reservations</label></p>
    <?php submit_button('Save availability scope','secondary'); ?></form>
    <p>Credentials are encrypted in the local database using this installation’s WordPress security salts. The password and access code are never filled back into the form. Keep backups of the database and wp-config.php private.</p>
    <p>If Jelly requires IP authorisation, ask them to approve your connection before we test authentication.</p>
    </div>
    <?php
}
