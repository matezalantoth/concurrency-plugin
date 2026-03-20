<?php
/**
 * Plugin Name: Enrollment Date Shifter
 * Description: Shift a LearnDash user's group enrollment date forwards or backwards.
 * Version: 1.0.0
 * Author: Concurrency
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_menu', 'eds_register_admin_page' );
add_action( 'wp_login', 'eds_auto_adjust_enrollment_on_login', 10, 2 );

function eds_auto_adjust_enrollment_on_login( $user_login, $user ) {
    $user_id = $user->ID;

    $today_str = current_time( 'Y-m-d' );

    $last_adjusted = get_user_meta( $user_id, '_eds_last_adjusted', true );
    if ( $last_adjusted === $today_str ) {
        return;
    }

    $last_date_str = get_user_meta( $user_id, 'voa_streak_date', true );
    if ( empty( $last_date_str ) ) {
        return;
    }

    $wp_timezone_str = wp_timezone_string();
    $wp_timezone     = new DateTimeZone( $wp_timezone_str ? $wp_timezone_str : 'UTC' );

    $today     = new DateTime( $today_str, $wp_timezone );
    $last_date = new DateTime( $last_date_str, $wp_timezone );

    $diff_days = (int) $today->diff( $last_date )->days;

    // diff gives absolute days between the two dates.
    // missed_days = diff - 1: last active day itself is not a missed day.
    $missed_days = $diff_days - 1;

    if ( $missed_days < 1 ) {
        return;
    }

    if ( ! function_exists( 'learndash_get_users_group_ids' ) || ! function_exists( 'learndash_group_enrolled_courses' ) || ! function_exists( 'ld_update_course_access' ) ) {
        return;
    }

    $group_ids = learndash_get_users_group_ids( $user_id );
    if ( empty( $group_ids ) ) {
        return;
    }

    foreach ( $group_ids as $group_id ) {
        $access_from_key = "group_{$group_id}_access_from";
        $enrolled_at_key = "learndash_group_{$group_id}_enrolled_at";

        $enrollment = (int) get_user_meta( $user_id, $enrolled_at_key, true );
        if ( ! $enrollment ) {
            $enrollment = (int) get_user_meta( $user_id, $access_from_key, true );
        }

        if ( ! $enrollment ) {
            continue;
        }

        $enrollment_dt = new DateTime( '@' . $enrollment );
        $enrollment_dt->setTimezone( $wp_timezone );
        $enrollment_dt->modify( "+{$missed_days} days" );

        $date_str     = $enrollment_dt->format( 'Y-m-d' );
        $new_datetime = new DateTime( "{$date_str} 00:00:00", $wp_timezone );
        $new_timestamp = $new_datetime->getTimestamp();

        update_user_meta( $user_id, $access_from_key, $new_timestamp );
        update_user_meta( $user_id, $enrolled_at_key, $new_timestamp );

        $group_courses = learndash_group_enrolled_courses( $group_id );
        if ( ! empty( $group_courses ) ) {
            foreach ( $group_courses as $course_id ) {
                update_user_meta( $user_id, "course_{$course_id}_access_from", $new_timestamp );
                ld_update_course_access( $user_id, $course_id, false );
            }
        }
    }

    update_user_meta( $user_id, '_eds_last_adjusted', $today_str );
    update_user_meta( $user_id, 'voa_streak_date', $today_str );
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

    $wp_timezone_str = wp_timezone_string();
    $wp_timezone     = new DateTimeZone( $wp_timezone_str ? $wp_timezone_str : 'UTC' );

    $datetime = new DateTime( '@' . $enrollment );
    $datetime->setTimezone( $wp_timezone );

    $modify_str = "{$direction}{$amount} {$unit}";
    $datetime->modify( $modify_str );

    if ( $unit === 'days' ) {
        $date_str    = $datetime->format( 'Y-m-d' );
        $new_datetime = new DateTime( "{$date_str} 00:00:00", $wp_timezone );
    } else {
        $new_datetime = $datetime;
    }

    $new_enrollment = $new_datetime->getTimestamp();

    update_user_meta( $user_id, $access_from_key, $new_enrollment );
    update_user_meta( $user_id, $enrolled_at_key, $new_enrollment );

    foreach ( $group_courses as $course_id ) {
        update_user_meta( $user_id, "course_{$course_id}_access_from", $new_enrollment );
        ld_update_course_access( $user_id, $course_id, false );
    }

    $direction_label = $direction === '+' ? 'forward' : 'backward';
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
