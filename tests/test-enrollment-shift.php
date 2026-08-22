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
function absint( $value ) {
    return abs( (int) $value );
}
function learndash_get_users_group_ids() {
    return [ 7, 2528 ];
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

echo "Enrollment shift check passed.\n";
