<?php
/** php wp-data/wp-content/plugins/concurrency-plugin/tests/test-daily-progression.php */
if ( PHP_SAPI !== 'cli' ) { exit; }
define( 'ABSPATH', dirname( __DIR__, 4 ) . '/' );
define( 'WPINC', 'wp-includes' );
define( 'EDS_PROGRESS_COURSE_ID', 100 );
require ABSPATH . WPINC . '/plugin.php';
require ABSPATH . WPINC . '/class-wp-error.php';
require ABSPATH . WPINC . '/class-wp-http-response.php';
require ABSPATH . WPINC . '/rest-api/class-wp-rest-response.php';

// The real WP hook dispatcher and installed LearnDash date functions are used below.
// Storage is in memory. No WordPress bootstrap, SQL, mail, cron, HTTP or production users.
$meta = $options = $progress = $activity = [];
$zone = 'Europe/Budapest';
$current_user = 1;
$admin = false;
$topics = [ 2042, 2047, 2054, 2060 ];
$dates = [ 2047 => 1, 2054 => 2, 2060 => 3 ];
$types = [ 100 => 'sfwd-courses', 1911 => 'sfwd-lessons', 1385 => 'sfwd-quiz', 999 => 'sfwd-topic' ];
foreach ( $topics as $id ) { $types[$id] = 'sfwd-topic'; }
class LearnDash_Settings_Section { public static function get_section_setting() { return 'yes'; } }
function absint( $v ) { return abs( (int) $v ); }
function wp_timezone() { return new DateTimeZone( $GLOBALS['zone'] ); }
function get_user_meta( $user, $key, $single = true ) { return $GLOBALS['meta'][$user][$key] ?? ''; }
function update_user_meta( $user, $key, $value ) { $GLOBALS['meta'][$user][$key] = $value; return true; }
function get_option( $key, $default = false ) { return $GLOBALS['options'][$key] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['options'][$key] = $value; return true; }
function get_userdata( $user ) { return $user ? (object) [ 'ID' => $user, 'user_registered' => $GLOBALS['registered'][$user] ?? '2020-01-01 00:00:00' ] : false; }
function get_current_user_id() { return $GLOBALS['current_user']; }
function user_can( $user, $cap ) { return $user === 99; }
function current_user_can() { return $GLOBALS['admin']; }
function get_post_type( $id ) { return $GLOBALS['types'][$id] ?? ''; }
function get_post_status( $id ) { return 'publish'; }
function get_the_title( $id ) { return 'Letter ' . $id; }
function learndash_get_course_id( $id ) { return $id === 999 ? 200 : 100; }
function learndash_course_get_steps_by_type() { return $GLOBALS['topics']; }
function learndash_course_get_all_parent_step_ids( $course, $id ) { return $id === 1385 ? [ 1911, 2054 ] : ( in_array( $id, $GLOBALS['topics'] ) ? [ 1911 ] : [] ); }
function learndash_user_get_course_progress( $user, $course, $type ) { return [ 'topics' => [ 1911 => $GLOBALS['progress'][$user] ?? [] ] ]; }
function learndash_is_topic_complete( $user, $topic, $course ) { return ! empty( $GLOBALS['progress'][$user][$topic] ); }
function learndash_get_user_activity( $args ) { return isset( $GLOBALS['activity'][$args['user_id']][$args['post_id']] ) ? (object) [ 'activity_completed' => $GLOBALS['activity'][$args['user_id']][$args['post_id']] ] : null; }
function learndash_get_setting( $id, $key ) { return $key === 'visible_after' ? ( $GLOBALS['dates'][$id] ?? 0 ) : ''; }
function learndash_get_course_meta_setting() { return 'free'; }
function learndash_user_group_enrolled_to_course_from( $user, $course, $bypass = false ) { return $GLOBALS['groups'][$user] ?? 0; }
function wp_verify_nonce( $nonce, $action ) { return $nonce === 'valid'; }
function wp_unslash( $value ) { return $value; }
function sanitize_text_field( $value ) { return is_scalar( $value ) ? (string) $value : ''; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z_]/', '', (string) $value ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function is_singular( $types ) { return in_array( get_post_type( get_queried_object_id() ), $types, true ); }
function get_queried_object_id() { return $GLOBALS['queried'] ?? 2047; }
function nocache_headers() {}
function esc_html( $value ) { return htmlspecialchars( $value ); }
function esc_url( $value ) { return $value; }
function get_permalink( $id ) { return '/letter/' . $id; }
function wp_date( $format, $timestamp ) { return ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( wp_timezone() )->format( $format ); }
function wp_die( $message, $title, $args ) { throw new RuntimeException( (string) $args['response'] ); }

require dirname( __DIR__, 2 ) . '/sfwd-lms/includes/course/ld-course-user-functions.php';
require __DIR__ . '/../daily-progression.php';
$checks = 0;
function check( $value, $message ) { ++$GLOBALS['checks']; if ( ! $value ) { throw new RuntimeException( $message ); } }
function complete_letter( $user, $topic, $at ) { $GLOBALS['progress'][$user][$topic] = 1; $GLOBALS['activity'][$user][$topic] = $at; }

$now = time();
$meta[1]['course_100_access_from'] = $now - 86401;
// Reproduce the reported bug in the installed LearnDash code before applying our gate.
check( ! ld_lesson_access_from( 2042, 1, 100 ), 'First letter is available.' );
check( ! ld_lesson_access_from( 2047, 1, 100 ), 'BUG reproduced: second letter date-unlocks despite no completion.' );
check( ld_lesson_access_from( 2054, 1, 100 ) > $now, 'Original later drip is still in the future.' );

$fresh = [ 'mode' => 'enforce', 'protected' => [] ];
$meta[1]['_eds_daily_progression'] = $fresh;
check( ! eds_daily_decision( 1, 2042, $fresh, $now ), 'First letter stays available.' );
check( eds_daily_decision( 1, 2047, $fresh, $now )['reason'] === 'previous', 'Unread first letter blocks the second.' );
check( eds_daily_decision( 1, 2047, $fresh, $now + 40 * 86400 )['reason'] === 'previous', 'Forty missed days do not accumulate letters.' );
check( ld_lesson_access_from( 2047, 1, 100 ) > $now, 'Real LearnDash date API applies the gate.' );
check( ! apply_filters( 'learndash_process_mark_complete', true, (object) [ 'ID' => 2047 ], get_userdata( 1 ) ), 'A stale or direct completion is rejected.' );
check( apply_filters( 'learndash_mark_complete', 'button', (object) [ 'ID' => 2047 ] ) === '', 'Locked button is hidden.' );
$rest = apply_filters( 'rest_prepare_sfwd-topic', new WP_REST_Response( [ 'content' => 'protected body' ] ), (object) [ 'ID' => 2047 ] );
check( $rest->get_status() === 403 && ! isset( $rest->get_data()['content'] ), 'REST cannot return the locked letter body.' );
try { eds_daily_protect_page(); check( false, 'Page should be blocked.' ); } catch ( RuntimeException $e ) { check( $e->getMessage() === '403', 'Direct page blocks before the theme renders.' ); }

complete_letter( 1, 2042, $now );
$midnight = eds_daily_next_midnight( $now );
check( eds_daily_decision( 1, 2047, $fresh, $now )['until'] === $midnight, 'Successful completion waits until midnight.' );
check( eds_daily_decision( 1, 2047, $fresh, $midnight - 1 )['reason'] === 'midnight', 'One second before midnight stays blocked.' );
check( ! eds_daily_decision( 1, 2047, $fresh, $midnight ), 'Exact midnight opens one letter.' );
check( eds_daily_decision( 1, 2054, $fresh, $midnight )['reason'] === 'previous', 'The following letter remains blocked.' );
check( ! eds_daily_decision( 1, 2042, $fresh, $now ), 'Rereading never locks completed letters.' );
check( ! eds_daily_decision( 1, 1911, $fresh, $now ), 'Chapters do not consume daily letters.' );
check( eds_daily_decision( 1, 1385, $fresh, $now )['reason'] === 'previous', 'A topic quiz inherits its locked letter.' );
complete_letter( 1, 2047, $midnight + 1 );
check( eds_daily_decision( 1, 2054, $fresh, $midnight + 2 )['reason'] === 'midnight', 'Each successful new letter imposes its own midnight wait.' );

// Capturing existing access preserves both incomplete letters, but never auto-completes them.
$meta[2]['course_100_access_from'] = $now - 86401;
$protected = eds_daily_snapshot( 2 );
check( $protected === [ 2042, 2047 ], 'Migration preserves exactly the two date-unlocked letters.' );
$existing = [ 'mode' => 'enforce', 'protected' => $protected ];
check( ! eds_daily_decision( 2, 2047, $existing, $now ), 'Old skipped completion does not relock already-readable content.' );
check( eds_daily_decision( 2, 2054, $existing, $now )['previous'] === 2042, 'A new letter points to the earliest missing completion.' );
complete_letter( 2, 2042, $now );
check( ! eds_daily_decision( 2, 2047, $existing, $now ), 'Catching up old checkmarks does not impose a daily wait inside the archive.' );
complete_letter( 2, 2047, $now );
check( eds_daily_decision( 2, 2054, $existing, $now )['reason'] === 'midnight', 'Crossing beyond the preserved archive waits until midnight.' );
check( ! eds_daily_decision( 2, 2054, $existing, $midnight ), 'Archive catchup can then advance normally.' );
$legacy = [ 'mode' => 'enforce', 'protected' => $topics ];
check( ! eds_daily_decision( 3, 2060, $legacy, $now ), 'A legacy learner with full access keeps all letters.' );

// Missing activity between LearnDash saving progress and saving completion time fails closed.
$progress[4] = [ 2042 => 1 ];
check( eds_daily_decision( 4, 2047, $fresh, $now )['reason'] === 'missing_time', 'Incomplete completion writes cannot release the next letter.' );
check( ! eds_daily_decision( 4, 2047, [ 'mode' => 'enforce', 'protected' => [ 2042 ] ], $now ), 'Imported archived completion without an activity timestamp is supported.' );
$activity[4][2042] = $now;
check( eds_daily_decision( 4, 2047, $fresh, $now )['reason'] === 'midnight', 'Completion date becomes authoritative immediately, with no stale decision cache.' );

// Observation, rollout, and rollback never require rewriting progress/enrollment.
$meta[1]['_eds_daily_progression']['mode'] = 'observe';
check( ! eds_daily_gate( 1, 2047 ), 'Observation does not restrict access.' );
$meta[1]['_eds_daily_progression'] = $fresh;
$options['eds_daily_rollout'] = [ 'paused' => true ];
check( ! eds_daily_gate( 1, 2060 ), 'Emergency pause applies to manually enrolled pilots.' );
$options['eds_daily_rollout'] = [ 'enabled' => true, 'since' => $now - 1 ];
$registered[10] = gmdate( 'Y-m-d H:i:s', $now );
check( eds_daily_config( 10 ) === $fresh, 'New accounts receive pacing from letter one.' );
check( ! eds_daily_config( 11 ), 'Existing accounts are not silently migrated.' );
$meta[10]['_eds_daily_progression'] = [ 'mode' => 'off', 'protected' => [] ];
check( eds_daily_config( 10 )['mode'] === 'off', 'An explicit exemption beats automatic rollout.' );
check( ! eds_daily_gate( 1, 999 ), 'Other courses stay unaffected.' );
$meta[99]['_eds_daily_progression'] = $fresh;
check( ! eds_daily_gate( 99, 2060 ), 'Administrators retain preview access.' );
check( ! apply_filters( 'learndash_process_mark_complete', false, (object) [ 'ID' => 2042 ], get_userdata( 1 ) ), 'Another plugin denial is never overridden.' );
$meta[5]['_eds_daily_progression'] = $fresh;
$meta[5]['course_100_access_from'] = $now + 86400;
$dates[2042] = 1;
check( ! apply_filters( 'learndash_process_mark_complete', true, (object) [ 'ID' => 2042 ], get_userdata( 5 ) ), 'Direct completion also respects a later original drip date.' );
$dates[2042] = 0;

// Native group fallback and quiz/subscription date shifts cannot override the added gate.
$meta[6]['_eds_daily_progression'] = $fresh;
$groups[6] = $now - 100 * 86400;
check( ld_lesson_access_from( 2047, 6, 100 ) > $now, 'Even an old group anchor cannot bypass missing completion.' );
check( eds_daily_access_from( $now + 30 * 86400, 2047, 6 ) === $now + 30 * 86400, 'A later native date remains authoritative.' );

foreach ( [ [ '2026-03-29', 23 ], [ '2026-10-25', 25 ] ] as [ $day, $hours ] ) {
    $start = ( new DateTimeImmutable( $day . ' 00:00:00', wp_timezone() ) )->getTimestamp();
    check( eds_daily_next_midnight( $start ) - $start === $hours * 3600, 'Midnight observes DST on ' . $day );
}
$late = ( new DateTimeImmutable( '2026-09-11 23:59:00', wp_timezone() ) )->getTimestamp();
check( eds_daily_next_midnight( $late ) - $late === 60, 'This is next midnight, not a rolling 24-hour wait.' );
check( is_wp_error( eds_daily_admin_save() ), 'Unauthorised settings changes are rejected.' );
$admin = true;
$_POST = [ 'eds_daily_nonce' => 'bad' ];
check( is_wp_error( eds_daily_admin_save() ), 'Invalid nonces are rejected.' );
$_POST = [ 'eds_daily_nonce' => 'valid', 'eds_daily_mode' => 'invalid', 'eds_daily_user' => 1 ];
check( is_wp_error( eds_daily_admin_save() ), 'Invalid modes are rejected.' );
echo "Daily progression: {$checks} checks passed (real WordPress hooks and LearnDash drip code; no database).\n";
