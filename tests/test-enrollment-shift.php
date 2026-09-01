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

function eds_test_date( $key ) {
    $value = $GLOBALS['eds_test_meta'][42][ $key ] ?? null;

    return null === $value ? 'unset' : gmdate( 'Y-m-d H:i:s', $value );
}

function eds_test_check( $passed, $message ) {
    if ( ! $passed ) {
        throw new RuntimeException( $message );
    }
}

$subscription = new WC_Subscription();

eds_test_check( false === eds_delay_subscription_group_enrollment( true, 7, $subscription ), 'The group filter must take over from LearnDash WooCommerce.' );
eds_test_check( eds_test_date( 'group_7_access_from' ) === '2026-03-08 00:00:00', 'The minus-12-day enrollment shift failed.' );
eds_test_check( eds_test_date( 'course_101_access_from' ) === '2026-03-08 00:00:00', 'The group courses were not anchored with the group.' );

// learndash_group_{id}_enrolled_at is LearnDash's enrollment record. Reports and the GamiPress
// membership-days achievements read it, so drip tuning must not write to it.
eds_test_check( eds_test_date( 'learndash_group_7_enrolled_at' ) === 'unset', 'The enrollment record was overwritten with a drip anchor.' );

eds_test_check( eds_shift_after_skipped_quiz( [ 'quiz' => 20 ], 42, 101 ), 'A skipped quiz did not advance the enrollment.' );
eds_test_check( eds_test_date( 'course_101_access_from' ) === '2026-03-07 00:00:00', 'A skipped quiz did not move the course anchor back a day.' );
eds_test_check( ! eds_shift_after_purchased_quiz( 20, 42, 101 ), 'A skipped quiz advanced again when it was later purchased.' );
eds_test_check( eds_test_date( 'course_101_access_from' ) === '2026-03-07 00:00:00', 'A repeat checkpoint moved the anchor.' );

// A checkpoint moves the course it was cleared in and nothing else.
eds_test_check( eds_test_date( 'group_7_access_from' ) === '2026-03-08 00:00:00', 'A quiz checkpoint moved the group anchor.' );
eds_test_check( eds_test_date( 'course_102_access_from' ) === '2026-03-08 00:00:00', 'A quiz checkpoint moved a course it was not cleared in.' );

// LearnDash re-stamps learndash_group_{id}_enrolled_at to time() on every group add, so a renewal
// or a re-add leaves it far ahead of a long-standing learner's course anchor. A quiz checkpoint
// must still only step that anchor back a day, never adopt the group's date.
$GLOBALS['eds_test_meta'][42]['learndash_group_7_enrolled_at'] = time();
$GLOBALS['eds_test_meta'][42]['group_7_access_from']           = time();

eds_test_check( eds_shift_after_purchased_quiz( 21, 42, 101 ), 'A purchased quiz did not advance the enrollment.' );
eds_test_check( eds_test_date( 'course_101_access_from' ) === '2026-03-06 00:00:00', 'A recent group stamp dragged the course drip anchor forward.' );

// Re-activating the subscription restores access. It must not take back the letters the
// checkpoints already unlocked.
eds_test_check( false === eds_delay_subscription_group_enrollment( true, 7, $subscription ), 'The group filter must take over on re-activation.' );
eds_test_check( eds_test_date( 'course_101_access_from' ) === '2026-03-06 00:00:00', 'Re-activation clawed back the quiz checkpoints.' );
eds_test_check( eds_test_date( 'group_7_access_from' ) === '2026-03-08 00:00:00', 'Re-activation did not pull the group anchor back.' );

// The manual shifter moves every anchor by the same amount, each from its own value.
eds_test_check( eds_shift_enrollment_meta( 42, 'group_7_access_from', '-', 2, 'days' ), 'The manual shift reported nothing to move.' );
eds_shift_enrollment_meta( 42, 'course_101_access_from', '-', 2, 'days' );
eds_test_check( eds_test_date( 'group_7_access_from' ) === '2026-03-06 00:00:00', 'The manual shift did not move the group anchor.' );
eds_test_check( eds_test_date( 'course_101_access_from' ) === '2026-03-04 00:00:00', 'The manual shift flattened the course anchor onto the group.' );
eds_test_check( ! eds_shift_enrollment_meta( 42, 'course_999_access_from', '-', 2, 'days' ), 'The manual shift invented an anchor that was never stored.' );

echo "Enrollment shift check passed.\n";
