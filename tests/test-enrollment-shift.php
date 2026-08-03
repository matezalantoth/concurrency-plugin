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
function ld_update_course_access() {}
function get_the_title( $topic_id ) {
    return [ 201 => '13. First', 202 => '14. Second', 203 => '15. Third' ][ $topic_id ];
}
function learndash_get_course_steps() {
    return [ 201, 202, 203 ];
}
function learndash_is_topic_complete( $user_id, $topic_id ) {
    return $topic_id < 203;
}
function learndash_get_setting() {
    return 0;
}
function absint( $value ) {
    return abs( (int) $value );
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

$target = eds_get_first_incomplete_topic( 42, 100 );
if ( $target !== [ 'topic_id' => 203, 'visible_after' => 2 ] ) {
    throw new RuntimeException( 'The progress target lookup failed.' );
}

if ( gmdate( 'Y-m-d H:i:s', eds_progress_enrollment_timestamp( 2, '2026-03-20' ) ) !== '2026-03-18 00:00:00' ) {
    throw new RuntimeException( 'The progress enrollment alignment failed.' );
}

echo "Enrollment shift check passed.\n";
