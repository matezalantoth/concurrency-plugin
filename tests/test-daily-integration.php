<?php
/** Invoked by run-daily-integration.sh against a disposable database only. */
if ( PHP_SAPI !== 'cli' || ! preg_match( '/^eds_progression_test_[0-9_]+$/', getenv( 'EDS_TEST_DB' ) ?: '' ) ) {
    exit( "Use tests/run-daily-integration.sh.\n" );
}
define( 'ABSPATH', '/var/www/html/' );
define( 'DB_NAME', getenv( 'EDS_TEST_DB' ) );
define( 'DB_USER', getenv( 'WORDPRESS_DB_USER' ) );
define( 'DB_PASSWORD', getenv( 'WORDPRESS_DB_PASSWORD' ) );
define( 'DB_HOST', getenv( 'WORDPRESS_DB_HOST' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'DISABLE_WP_CRON', true );
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
define( 'WPMU_PLUGIN_DIR', __DIR__ . '/no-mu-plugins' );
define( 'WP_DEBUG', false );
$table_prefix = 'wp_';
$_SERVER['HTTP_HOST'] = 'localhost:7070';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
// Pre-initialized WordPress hooks prevent loading mailers, analytics, automations and themes.
foreach ( [
    'pre_option_active_plugins' => static function () { return [ 'sfwd-lms/sfwd_lms.php', 'concurrency-plugin/concurrency-plugin.php' ]; },
    'pre_option_template' => static function () { return '__eds_no_theme__'; },
    'pre_option_stylesheet' => static function () { return '__eds_no_theme__'; },
    'pre_http_request' => static function () { return new WP_Error( 'test_no_http', 'Outbound HTTP disabled in test.' ); },
    'pre_wp_mail' => static function () { return false; },
] as $hook => $callback ) {
    $GLOBALS['wp_filter'][$hook][10][] = [ 'function' => $callback, 'accepted_args' => 1 ];
}
require ABSPATH . 'wp-settings.php';

$checks = 0;
function eds_integration_check( $passed, $message ) {
    ++$GLOBALS['checks'];
    if ( ! $passed ) { throw new RuntimeException( $message ); }
}
$user = wp_insert_user( [ 'user_login' => 'eds-synthetic', 'user_pass' => wp_generate_password(), 'role' => 'subscriber', 'user_email' => 'eds-test@example.invalid' ] );
eds_integration_check( ! is_wp_error( $user ), 'Create synthetic learner.' );
wp_set_current_user( $user );
ld_update_course_access( $user, 100, false );
update_user_meta( $user, 'course_100_access_from', time() - 86401 );
update_option( 'timezone_string', 'Europe/Budapest' );
eds_integration_check( ! ld_lesson_access_from( 2047, $user, 100 ), 'Original bug: second letter opens with first incomplete.' );
update_user_meta( $user, '_eds_daily_progression', [ 'mode' => 'enforce', 'protected' => [] ] );
eds_integration_check( ld_lesson_access_from( 2047, $user, 100 ) > time(), 'Hook gates actual LearnDash availability.' );
eds_integration_check( ! learndash_process_mark_complete( $user, 2047, false, 100, true ), 'Even forced direct completion cannot skip an unread letter.' );
eds_integration_check( learndash_process_mark_complete( $user, 2042, false, 100 ), 'Real first-letter completion succeeds.' );
$activity = learndash_get_user_activity( [ 'user_id' => $user, 'course_id' => 100, 'post_id' => 2042, 'activity_type' => 'topic' ] );
eds_integration_check( ! empty( $activity->activity_completed ), 'LearnDash saves the authoritative completion timestamp.' );
eds_integration_check( eds_daily_gate( $user, 2047 )['reason'] === 'midnight', 'Actual successful completion imposes midnight wait.' );
eds_integration_check( ! learndash_process_mark_complete( $user, 2047, false, 100 ), 'Actual second completion is rejected on the same day.' );
eds_integration_check( ! learndash_is_topic_complete( $user, 2047, 100 ), 'Rejected completion did not alter progress.' );

// Travel through time by changing ONLY this disposable fixture's completion date.
$wpdb->update( LDLMS_DB::get_table_name( 'user_activity' ), [ 'activity_completed' => time() - 86400 ], [ 'activity_id' => $activity->activity_id ] );
eds_integration_check( ! eds_daily_gate( $user, 2047 ), 'Yesterday\'s completion opens the next letter.' );
eds_integration_check( learndash_process_mark_complete( $user, 2047, false, 100 ), 'Real second-letter completion succeeds after the date boundary.' );
eds_integration_check( eds_daily_gate( $user, 2054 )['reason'] === 'midnight', 'Third letter waits after real second completion.' );
learndash_process_mark_complete( $user, 2042, false, 100 );
$repeat = learndash_get_user_activity( [ 'user_id' => $user, 'course_id' => 100, 'post_id' => 2042, 'activity_type' => 'topic' ] );
eds_integration_check( $repeat->activity_completed < time() - 86000, 'Repeated completion does not restart the first letter\'s clock.' );
$controller = new WP_REST_Posts_Controller( 'sfwd-topic' );
$rest = $controller->prepare_item_for_response( get_post( 2054 ), new WP_REST_Request( 'GET' ) );
eds_integration_check( $rest->get_status() === 403 && ! isset( $rest->get_data()['content'] ), 'Actual WordPress REST preparation removes the locked body.' );

$legacy = wp_insert_user( [ 'user_login' => 'eds-legacy', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ] );
ld_update_course_access( $legacy, 100, false );
update_user_meta( $legacy, 'course_100_access_from', time() - 1000 * 86400 );
$preserved = eds_daily_snapshot( $legacy );
eds_integration_check( count( $preserved ) === count( eds_daily_topics() ), 'Full-access legacy snapshot preserves all actual course letters.' );
update_user_meta( $legacy, '_eds_daily_progression', [ 'mode' => 'enforce', 'protected' => $preserved ] );
eds_integration_check( ! eds_daily_gate( $legacy, 2054 ), 'Legacy missing ticks do not remove reading access.' );
eds_integration_check( ! learndash_is_topic_complete( $legacy, 2042, 100 ), 'Preserving access does not fabricate completion.' );

// The administrator preview itself must not change pacing/progress records.
$before = $wpdb->get_results( "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} ORDER BY umeta_id", ARRAY_A );
foreach ( eds_daily_topics() as $id ) {
    eds_daily_decision( $legacy, $id, [ 'mode' => 'observe', 'protected' => $preserved ] );
}
$after = $wpdb->get_results( "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} ORDER BY umeta_id", ARRAY_A );
eds_integration_check( $before === $after, 'Observation decisions do not write user metadata.' );

$imported = wp_insert_user( [ 'user_login' => 'eds-imported', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ] );
learndash_update_user_activity( [ 'user_id' => $imported, 'course_id' => 100, 'post_id' => 100,
    'activity_type' => 'access', 'activity_started' => time() - 1000 * 86400 ] );
eds_daily_snapshot( $imported );
eds_integration_check( ! get_user_meta( $imported, 'course_100_access_from', true ), 'Preview suppresses LearnDash\'s lazy anchor write for imported records.' );

$administrator = wp_insert_user( [ 'user_login' => 'eds-admin', 'user_pass' => wp_generate_password(), 'role' => 'administrator' ] );
wp_set_current_user( $administrator );
$_POST = [ 'eds_daily_nonce' => wp_create_nonce( 'eds_daily_settings' ), 'eds_daily_user' => $legacy, 'eds_daily_mode' => 'observe' ];
eds_integration_check( ! is_wp_error( eds_daily_admin_save() ) && eds_daily_config( $legacy )['mode'] === 'observe', 'Real admin form can enroll an existing learner in observation.' );
$_POST['eds_daily_mode'] = 'enforce';
eds_integration_check( ! is_wp_error( eds_daily_admin_save() ) && eds_daily_config( $legacy )['protected'] === $preserved, 'Enforcement preserves the actual legacy archive.' );
$_POST = [ 'eds_daily_nonce' => wp_create_nonce( 'eds_daily_settings' ), 'eds_daily_save_rollout' => '1', 'eds_daily_paused' => '1' ];
eds_integration_check( ! is_wp_error( eds_daily_admin_save() ) && ! eds_daily_gate( $user, 2054 ), 'Real emergency pause immediately releases the additional gate.' );
$_GET = $_REQUEST = [ 'eds_daily_user' => $legacy ];
$_POST = [];
ob_start();
eds_daily_admin_page();
$admin_html = ob_get_clean();
eds_integration_check( strpos( $admin_html, 'Preserved archive' ) !== false && strpos( $admin_html, 'Europe/Budapest' ) !== false, 'Actual admin preview renders the archive and site timezone.' );
echo "Daily integration: {$checks} checks passed against WordPress + LearnDash, using only synthetic users.\n";
