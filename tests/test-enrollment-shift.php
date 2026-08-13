<?php

if ( PHP_SAPI !== 'cli' ) {
    exit;
}

define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );

function add_action() {}
function add_filter() {}
function wp_timezone() {
    return new DateTimeZone( 'Europe/London' );
}
function learndash_group_enrolled_courses() {
    return [ 101, 102 ];
}
function update_user_meta( $user_id, $key, $value ) {
    $GLOBALS['eds_test_meta'][ $user_id ][ $key ] = $value;
}
function get_user_meta( $user_id, $key ) {
    return $GLOBALS['eds_test_meta'][ $user_id ][ $key ] ?? '';
}
function ld_update_course_access() {}
function get_the_title( $topic_id ) {
    return [ 201 => '13. First', 202 => '14. Second', 203 => '15. Third' ][ $topic_id ];
}
function learndash_get_course_steps() {
    return [ 201, 202, 203 ];
}
function learndash_get_setting() {
    return 0;
}
function absint( $value ) {
    return abs( (int) $value );
}
function current_time() {
    return '2026-03-20';
}
function learndash_get_users_group_ids() {
    return [ 7, 2528 ];
}
function learndash_user_course_last_step() {
    return $GLOBALS['eds_test_last_step'] ?? 0;
}
function learndash_get_course_id( $topic_id ) {
    return in_array( $topic_id, [ 201, 202, 203 ], true ) ? 100 : 0;
}
function get_post_type( $post_id ) {
    return $post_id === 100 ? 'sfwd-courses' : 'sfwd-topic';
}
function is_user_logged_in() {
    return true;
}
function get_current_user_id() {
    return 42;
}

class WC_Subscription {
    public function get_date_created() {
        return new DateTimeImmutable( '2026-03-20 10:30:00', new DateTimeZone( 'UTC' ) );
    }

    public function get_user_id() {
        return 42;
    }
}

require __DIR__ . '/../concurrency-plugin.php';

$subscription = new WC_Subscription();
$result       = eds_delay_subscription_group_enrollment( true, 7, $subscription );
$actual       = $GLOBALS['eds_test_meta'][42]['group_7_access_from'];

if (
    $result !== false
    || gmdate( 'Y-m-d H:i:s', $actual ) !== '2026-03-08 00:00:00'
    || $GLOBALS['eds_test_meta'][42]['learndash_group_7_enrolled_at'] !== $actual
    || $GLOBALS['eds_test_meta'][42]['course_101_access_from'] !== $actual
) {
    throw new RuntimeException( 'The minus-12-day enrollment shift failed.' );
}

if ( ! eds_shift_after_skipped_quiz( [ 'quiz' => 20 ], 42, 101 ) ) {
    throw new RuntimeException( 'A skipped quiz did not advance the enrollment.' );
}
if (
    gmdate( 'Y-m-d H:i:s', $GLOBALS['eds_test_meta'][42]['group_7_access_from'] ) !== '2026-03-07 00:00:00'
    || eds_shift_after_purchased_quiz( 20, 42, 101 )
    || gmdate( 'Y-m-d H:i:s', $GLOBALS['eds_test_meta'][42]['course_101_access_from'] ) !== '2026-03-07 00:00:00'
) {
    throw new RuntimeException( 'A skipped quiz was advanced more than once when later purchased.' );
}

$target = eds_get_topic_target( 202, 100 );
if ( $target !== [ 'topic_id' => 202, 'visible_after' => 1 ] ) {
    throw new RuntimeException( 'The last-accessed topic lookup failed.' );
}

if ( gmdate( 'Y-m-d H:i:s', eds_progress_enrollment_timestamp( 2, '2026-03-20' ) ) !== '2026-03-18 00:00:00' ) {
    throw new RuntimeException( 'The progress enrollment alignment failed.' );
}

$GLOBALS['eds_test_meta'][42]['_eds_previous_activity'] = [
    'date'        => '2026-03-17',
    'topic_id'    => 202,
    'recorded_at' => 1773705600,
];
$GLOBALS['eds_test_meta'][42]['course_100_access_from'] = 1773705600;
$GLOBALS['eds_test_last_step'] = 203;

eds_maybe_align_returning_user( 42 );

$alignment = $GLOBALS['eds_test_meta'][42]['_eds_last_progress_alignment'];
if (
    $alignment['topic_id'] !== 202
    || $alignment['missed_days'] !== 2
    || gmdate( 'Y-m-d H:i:s', $alignment['to'] ) !== '2026-03-19 00:00:00'
) {
    throw new RuntimeException( 'The previous activity was not used for enrollment alignment.' );
}

eds_store_current_activity();
$stored_activity = $GLOBALS['eds_test_meta'][42]['_eds_previous_activity'];
if ( $stored_activity['date'] !== '2026-03-20' || $stored_activity['topic_id'] !== 203 ) {
    throw new RuntimeException( 'The current activity snapshot was not stored after alignment.' );
}

echo "Enrollment shift check passed.\n";
