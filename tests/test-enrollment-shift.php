<?php

if ( PHP_SAPI !== 'cli' ) {
    exit;
}

define( 'ABSPATH', __DIR__ );

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
function ld_update_course_access() {}

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
    || gmdate( 'Y-m-d H:i:s', $actual ) !== '2026-03-31 23:00:00'
    || $GLOBALS['eds_test_meta'][42]['learndash_group_7_enrolled_at'] !== $actual
    || $GLOBALS['eds_test_meta'][42]['course_101_access_from'] !== $actual
) {
    throw new RuntimeException( 'The 12-day enrollment shift failed.' );
}

echo "Enrollment shift check passed.\n";
