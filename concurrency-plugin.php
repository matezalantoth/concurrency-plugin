<?php
/**
 * Plugin Name: Enrollment Date Shifter
 * Description: Shift a LearnDash user's group enrollment date forwards or backwards.
 * Version: 1.3.0
 * Author: Concurrency
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_menu', 'eds_register_admin_page' );
add_action( 'wp_login', 'eds_auto_adjust_enrollment_on_login', 10, 2 );
add_action( 'wp', 'eds_auto_adjust_enrollment_for_current_user' );
add_filter( 'learndash_woocommerce_reset_subscription_course_access_from', 'eds_delay_subscription_course_enrollment', 10, 3 );
add_filter( 'learndash_woocommerce_reset_subscription_group_access_from', 'eds_delay_subscription_group_enrollment', 10, 3 );

define( 'EDS_PROGRESS_GROUP_ID', 2528 );
define( 'EDS_PROGRESS_COURSE_ID', 100 );

function eds_shift_enrollment_timestamp( $timestamp, $direction, $amount, $unit ) {
    $datetime = new DateTime( '@' . $timestamp );
    $datetime->setTimezone( wp_timezone() );
    $datetime->modify( "{$direction}{$amount} {$unit}" );

    if ( $unit === 'days' ) {
        $datetime->setTime( 0, 0 );
    }

    return $datetime->getTimestamp();
}

function eds_set_group_enrollment_timestamp( $user_id, $group_id, $timestamp, $group_courses = null ) {
    update_user_meta( $user_id, "group_{$group_id}_access_from", $timestamp );
    update_user_meta( $user_id, "learndash_group_{$group_id}_enrolled_at", $timestamp );

    $group_courses = $group_courses ?? learndash_group_enrolled_courses( $group_id );

    foreach ( (array) $group_courses as $course_id ) {
        update_user_meta( $user_id, "course_{$course_id}_access_from", $timestamp );
        ld_update_course_access( $user_id, $course_id, false );
    }
}

function eds_subscription_enrollment_timestamp( $subscription ) {
    $created = $subscription->get_date_created();

    return $created ? eds_shift_enrollment_timestamp( $created->getTimestamp(), '-', 12, 'days' ) : 0;
}

function eds_delay_subscription_course_enrollment( $reset, $course_id, $subscription ) {
    if ( ! $reset || ! is_a( $subscription, 'WC_Subscription' ) ) {
        return $reset;
    }

    $user_id   = $subscription->get_user_id();
    $timestamp = eds_subscription_enrollment_timestamp( $subscription );

    if ( ! $user_id || ! $timestamp ) {
        return $reset;
    }

    update_user_meta( $user_id, "course_{$course_id}_access_from", $timestamp );
    ld_update_course_access( $user_id, $course_id, false );

    return false;
}

function eds_delay_subscription_group_enrollment( $reset, $group_id, $subscription ) {
    if ( ! $reset || ! is_a( $subscription, 'WC_Subscription' ) ) {
        return $reset;
    }

    $user_id   = $subscription->get_user_id();
    $timestamp = eds_subscription_enrollment_timestamp( $subscription );

    if ( ! $user_id || ! $timestamp ) {
        return $reset;
    }

    eds_set_group_enrollment_timestamp( $user_id, $group_id, $timestamp );

    return false;
}

function eds_auto_adjust_enrollment_on_login( $user_login, $user ) {
    eds_maybe_align_returning_user( $user->ID );
    add_action( 'shutdown', 'eds_store_current_activity' );
}

function eds_auto_adjust_enrollment_for_current_user() {
    if ( is_user_logged_in() ) {
        eds_maybe_align_returning_user( get_current_user_id() );
        add_action( 'shutdown', 'eds_store_current_activity' );
    }
}

function eds_get_topic_number( $topic_id ) {
    return preg_match( '/^\s*(\d+)\s*\./u', get_the_title( $topic_id ), $matches ) ? (int) $matches[1] : 0;
}

function eds_get_topic_target( $topic_id, $course_id ) {
    $topic_ids = (array) learndash_get_course_steps( $course_id, [ 'sfwd-topic' ] );
    $topic_ids = array_map( 'intval', $topic_ids );
    $index = array_search( (int) $topic_id, $topic_ids, true );

    if ( false === $index ) {
        return [];
    }

    $first_number = $topic_ids ? eds_get_topic_number( $topic_ids[0] ) : 0;
    $visible_after = absint( learndash_get_setting( $topic_id, 'visible_after' ) );

    if ( ! $visible_after && $index ) {
        $topic_number = eds_get_topic_number( $topic_id );
        $visible_after = $first_number && $topic_number >= $first_number
            ? $topic_number - $first_number
            : $index;
    }

    return [
        'topic_id'      => (int) $topic_id,
        'visible_after' => $visible_after,
    ];
}

function eds_get_previous_activity( $user_id ) {
    $activity = get_user_meta( $user_id, '_eds_previous_activity', true );
    $activity = is_array( $activity ) ? $activity : [];

    if ( empty( $activity['date'] ) ) {
        $activity['date'] = get_user_meta( $user_id, '_eds_last_active_date', true );
    }
    if ( empty( $activity['date'] ) ) {
        $activity['date'] = get_user_meta( $user_id, 'voa_streak_date', true );
    }
    if ( empty( $activity['topic_id'] ) && function_exists( 'learndash_user_course_last_step' ) ) {
        $activity['topic_id'] = learndash_user_course_last_step( $user_id, EDS_PROGRESS_COURSE_ID );
    }

    return [
        'date'     => $activity['date'] ?? '',
        'topic_id' => absint( $activity['topic_id'] ?? 0 ),
    ];
}

function eds_store_current_activity() {
    if ( ! is_user_logged_in() ) {
        return;
    }

    $user_id = get_current_user_id();
    $previous = eds_get_previous_activity( $user_id );
    $topic_id = function_exists( 'learndash_user_course_last_step' )
        ? absint( learndash_user_course_last_step( $user_id, EDS_PROGRESS_COURSE_ID ) )
        : 0;

    if (
        ! $topic_id
        || get_post_type( $topic_id ) !== 'sfwd-topic'
        || (int) learndash_get_course_id( $topic_id ) !== EDS_PROGRESS_COURSE_ID
    ) {
        $topic_id = $previous['topic_id'];
    }

    update_user_meta(
        $user_id,
        '_eds_previous_activity',
        [
            'date'        => current_time( 'Y-m-d' ),
            'topic_id'    => $topic_id,
            'recorded_at' => time(),
        ]
    );
}

function eds_progress_enrollment_timestamp( $visible_after, $today_str ) {
    $today = new DateTime( $today_str, wp_timezone() );
    $today->setTime( 0, 0 );

    return $today->getTimestamp() - ( absint( $visible_after ) * DAY_IN_SECONDS );
}

function eds_maybe_align_returning_user( $user_id ) {
    $today_str = current_time( 'Y-m-d' );

    $last_adjusted = get_user_meta( $user_id, '_eds_last_adjusted', true );
    if ( $last_adjusted === $today_str ) {
        return;
    }

    $previous_activity = eds_get_previous_activity( $user_id );
    $last_date_str = $previous_activity['date'];

    update_user_meta( $user_id, '_eds_last_adjusted', $today_str );

    if ( empty( $last_date_str ) || empty( $previous_activity['topic_id'] ) ) {
        return;
    }

    $wp_timezone = wp_timezone();

    $today     = new DateTime( $today_str, $wp_timezone );
    $last_date = new DateTime( $last_date_str, $wp_timezone );

    if ( $last_date >= $today ) {
        return;
    }

    $diff_days = (int) $last_date->diff( $today )->days;

    // The last active day itself is not a missed day.
    $missed_days = $diff_days - 1;

    if ( $missed_days < 1 ) {
        return;
    }

    if (
        ! function_exists( 'learndash_get_users_group_ids' )
        || ! function_exists( 'learndash_get_course_steps' )
        || get_post_type( EDS_PROGRESS_COURSE_ID ) !== 'sfwd-courses'
    ) {
        return;
    }

    $group_ids = array_map( 'intval', (array) learndash_get_users_group_ids( $user_id ) );
    if ( ! in_array( EDS_PROGRESS_GROUP_ID, $group_ids, true ) ) {
        return;
    }

    $target = eds_get_topic_target( $previous_activity['topic_id'], EDS_PROGRESS_COURSE_ID );
    if ( ! $target ) {
        return;
    }

    $old_timestamp = (int) get_user_meta( $user_id, 'course_' . EDS_PROGRESS_COURSE_ID . '_access_from', true );
    $new_timestamp = eds_progress_enrollment_timestamp( $target['visible_after'], $today_str );

    eds_set_group_enrollment_timestamp( $user_id, EDS_PROGRESS_GROUP_ID, $new_timestamp, [ EDS_PROGRESS_COURSE_ID ] );
    update_user_meta(
        $user_id,
        '_eds_last_progress_alignment',
        [
            'course_id'   => EDS_PROGRESS_COURSE_ID,
            'topic_id'    => $target['topic_id'],
            'missed_days' => $missed_days,
            'from'        => $old_timestamp,
            'to'          => $new_timestamp,
            'adjusted_at' => time(),
        ]
    );
}

function eds_register_admin_page() {
    add_menu_page(
        'Enrollment Date Shifter',
        'Enrollment Shifter',
        'manage_options',
        'enrollment-shift',
        'eds_render_admin_page',
        'dashicons-calendar-alt',
        80
    );
}

function eds_handle_form_submission() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return [ 'error' => 'You do not have permission to perform this action.' ];
    }

    if ( ! isset( $_POST['eds_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['eds_nonce'] ) ), 'eds_shift_enrollment' ) ) {
        return [ 'error' => 'Security check failed. Please try again.' ];
    }

    $email     = isset( $_POST['eds_email'] ) ? sanitize_email( wp_unslash( $_POST['eds_email'] ) ) : '';
    $group_id  = isset( $_POST['eds_group_id'] ) ? absint( $_POST['eds_group_id'] ) : 0;
    $direction = isset( $_POST['eds_direction'] ) && $_POST['eds_direction'] === 'forward' ? '+' : '-';
    $amount    = isset( $_POST['eds_amount'] ) ? absint( $_POST['eds_amount'] ) : 0;
    $unit      = isset( $_POST['eds_unit'] ) ? sanitize_text_field( wp_unslash( $_POST['eds_unit'] ) ) : 'days';

    if ( ! in_array( $unit, [ 'days', 'hours', 'minutes' ], true ) ) {
        $unit = 'days';
    }

    if ( empty( $email ) ) {
        return [ 'error' => 'Please enter a valid email address.' ];
    }
    if ( $group_id <= 0 ) {
        return [ 'error' => 'Please enter a valid Group ID.' ];
    }
    if ( $amount <= 0 ) {
        return [ 'error' => 'Please enter an amount greater than 0.' ];
    }

    $user = get_user_by( 'email', $email );
    if ( ! $user ) {
        return [ 'error' => "No user found with email: {$email}" ];
    }

    $user_id = $user->ID;

    $group_courses = learndash_group_enrolled_courses( $group_id );
    if ( empty( $group_courses ) ) {
        return [ 'error' => "No courses found for group ID {$group_id}. Make sure this group exists and has courses assigned." ];
    }

    $access_from_key  = "group_{$group_id}_access_from";
    $enrolled_at_key  = "learndash_group_{$group_id}_enrolled_at";

    $enrollment = (int) get_user_meta( $user_id, $enrolled_at_key, true );
    if ( ! $enrollment ) {
        $enrollment = (int) get_user_meta( $user_id, $access_from_key, true );
    }

    if ( ! $enrollment ) {
        return [ 'error' => "No enrollment timestamp found for {$email} in group {$group_id}." ];
    }

    $new_enrollment = eds_shift_enrollment_timestamp( $enrollment, $direction, $amount, $unit );
    eds_set_group_enrollment_timestamp( $user_id, $group_id, $new_enrollment, $group_courses );

    $direction_label = $direction === '+' ? 'forward' : 'backward';
    $new_datetime = new DateTime( '@' . $new_enrollment );
    $new_datetime->setTimezone( wp_timezone() );
    $new_date_display = $new_datetime->format( 'Y-m-d H:i:s T' );

    return [
        'success' => "Successfully shifted {$email}'s enrollment {$direction_label} by {$amount} {$unit} in group {$group_id}. New enrollment date: {$new_date_display}.",
    ];
}

function eds_render_admin_page() {
    $result = null;

    if ( isset( $_POST['eds_submit'] ) ) {
        $result = eds_handle_form_submission();
    }

    $email     = isset( $_POST['eds_email'] ) ? sanitize_email( wp_unslash( $_POST['eds_email'] ) ) : '';
    $group_id  = isset( $_POST['eds_group_id'] ) ? absint( $_POST['eds_group_id'] ) : '';
    $direction = isset( $_POST['eds_direction'] ) ? sanitize_text_field( wp_unslash( $_POST['eds_direction'] ) ) : 'backward';
    $amount    = isset( $_POST['eds_amount'] ) ? absint( $_POST['eds_amount'] ) : 1;
    $unit      = isset( $_POST['eds_unit'] ) ? sanitize_text_field( wp_unslash( $_POST['eds_unit'] ) ) : 'days';
    ?>
    <div class="wrap">
        <h1>Enrollment Date Shifter</h1>
        <p>Use this tool to shift a user's LearnDash group enrollment date forwards or backwards.</p>

        <?php if ( $result !== null ) : ?>
            <?php if ( isset( $result['success'] ) ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html( $result['success'] ); ?></p>
                </div>
            <?php elseif ( isset( $result['error'] ) ) : ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php echo esc_html( $result['error'] ); ?></p>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <div style="max-width:600px; margin-top:20px;">
            <form method="post" action="">
                <?php wp_nonce_field( 'eds_shift_enrollment', 'eds_nonce' ); ?>

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="eds_email">User Email</label>
                            </th>
                            <td>
                                <input
                                    type="email"
                                    id="eds_email"
                                    name="eds_email"
                                    class="regular-text"
                                    value="<?php echo esc_attr( $email ); ?>"
                                    placeholder="user@example.com"
                                    required
                                />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="eds_group_id">Group ID</label>
                            </th>
                            <td>
                                <input
                                    type="number"
                                    id="eds_group_id"
                                    name="eds_group_id"
                                    class="small-text"
                                    value="<?php echo esc_attr( $group_id ); ?>"
                                    min="1"
                                    placeholder="e.g. 2528"
                                    required
                                />
                                <p class="description">The LearnDash group ID to adjust enrollment for.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Direction</th>
                            <td>
                                <fieldset>
                                    <label>
                                        <input
                                            type="radio"
                                            name="eds_direction"
                                            value="backward"
                                            <?php checked( $direction, 'backward' ); ?>
                                        />
                                        Backward (earlier date)
                                    </label>
                                    <br />
                                    <label>
                                        <input
                                            type="radio"
                                            name="eds_direction"
                                            value="forward"
                                            <?php checked( $direction, 'forward' ); ?>
                                        />
                                        Forward (later date)
                                    </label>
                                </fieldset>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="eds_amount">Amount</label>
                            </th>
                            <td>
                                <input
                                    type="number"
                                    id="eds_amount"
                                    name="eds_amount"
                                    class="small-text"
                                    value="<?php echo esc_attr( $amount ); ?>"
                                    min="1"
                                    required
                                />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="eds_unit">Unit</label>
                            </th>
                            <td>
                                <select id="eds_unit" name="eds_unit">
                                    <option value="days" <?php selected( $unit, 'days' ); ?>>Days</option>
                                    <option value="hours" <?php selected( $unit, 'hours' ); ?>>Hours</option>
                                    <option value="minutes" <?php selected( $unit, 'minutes' ); ?>>Minutes</option>
                                </select>
                                <p class="description">When using "Days", the new enrollment time will be set to midnight (00:00:00) of that date.</p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p class="submit">
                    <input
                        type="submit"
                        name="eds_submit"
                        class="button button-primary button-large"
                        value="Shift Enrollment"
                    />
                </p>
            </form>
        </div>
    </div>
    <?php
}
