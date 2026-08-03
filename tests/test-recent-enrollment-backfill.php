<?php

if ( PHP_SAPI !== 'cli' ) {
    exit;
}

define( 'DAY_IN_SECONDS', 86400 );

function add_action( $hook, $callback ) {
    $GLOBALS['eds_test_callback'] = $callback;
}
function current_user_can() {
    return true;
}
function get_option() {
    return false;
}
function learndash_get_groups_user_ids() {
    return [ 10, 20, 30 ];
}
function learndash_group_enrolled_courses() {
    return [ 101, 102 ];
}
function get_user_meta( $user_id, $key ) {
    return $GLOBALS['eds_test_enrollments'][ $user_id ] ?? 0;
}
function eds_shift_enrollment_timestamp( $timestamp, $direction, $amount, $unit ) {
    return $timestamp - ( $amount * DAY_IN_SECONDS );
}
function eds_set_group_enrollment_timestamp( $user_id, $group_id, $timestamp ) {
    $GLOBALS['eds_test_updates'][ $user_id ] = $timestamp;
}
function update_option( $key, $value ) {
    $GLOBALS['eds_test_option'] = $key;
}
function current_time() {
    return '2026-08-03 12:00:00';
}

$now = time();
$GLOBALS['eds_test_enrollments'] = [
    10 => $now,
    20 => $now - ( 31 * DAY_IN_SECONDS ),
    30 => $now + DAY_IN_SECONDS,
];

require __DIR__ . '/../snippets/backfill-recent-group-enrollments.php';
$GLOBALS['eds_test_callback']();

if (
    array_keys( $GLOBALS['eds_test_updates'] ) !== [ 10, 30 ]
    || $GLOBALS['eds_test_updates'][10] !== $GLOBALS['eds_test_enrollments'][10] - ( 24 * DAY_IN_SECONDS )
    || $GLOBALS['eds_test_updates'][30] !== $GLOBALS['eds_test_enrollments'][30] - ( 24 * DAY_IN_SECONDS )
    || $GLOBALS['eds_test_option'] !== '_eds_group_2528_recent_enrollment_minus_24_v1'
) {
    throw new RuntimeException( 'The recent enrollment backfill failed.' );
}

echo "Recent enrollment backfill check passed.\n";
