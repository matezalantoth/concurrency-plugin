<?php
/**
 * One-time repair for recent group 2528 enrolments.
 * Paste into Code Snippets without this opening PHP tag.
 */

add_action( 'init', function() {
    $group_id         = 2528;
    $days_to_subtract = 24;
    $run_key          = '_eds_group_2528_recent_enrollment_minus_24_v1';

    if ( ! current_user_can( 'manage_options' ) || get_option( $run_key ) ) {
        return;
    }

    if (
        ! function_exists( 'learndash_get_groups_user_ids' )
        || ! function_exists( 'learndash_group_enrolled_courses' )
        || ! function_exists( 'eds_shift_enrollment_timestamp' )
        || ! function_exists( 'eds_set_group_enrollment_timestamp' )
    ) {
        error_log( 'Enrollment backfill skipped: a required plugin is unavailable.' );
        return;
    }

    $user_ids      = (array) learndash_get_groups_user_ids( $group_id );
    $group_courses = (array) learndash_group_enrolled_courses( $group_id );

    if ( ! $user_ids || ! $group_courses ) {
        error_log( "Enrollment backfill skipped: group {$group_id} has no users or courses." );
        return;
    }

    $cutoff  = time() - ( 30 * DAY_IN_SECONDS );
    $updated = 0;

    foreach ( $user_ids as $user_id ) {
        $enrollment = (int) get_user_meta( $user_id, "learndash_group_{$group_id}_enrolled_at", true );

        if ( ! $enrollment ) {
            $enrollment = (int) get_user_meta( $user_id, "group_{$group_id}_access_from", true );
        }

        if ( ! $enrollment || $enrollment < $cutoff ) {
            continue;
        }

        $new_enrollment = eds_shift_enrollment_timestamp( $enrollment, '-', $days_to_subtract, 'days' );
        eds_set_group_enrollment_timestamp( $user_id, $group_id, $new_enrollment, $group_courses );
        ++$updated;
    }

    update_option( $run_key, current_time( 'mysql' ), false );
    error_log( "Enrollment backfill complete: moved {$updated} users in group {$group_id} back {$days_to_subtract} days." );
}, 20 );
