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
add_filter( 'learndash_woocommerce_reset_subscription_course_access_from', 'eds_delay_subscription_course_enrollment', 10, 3 );
add_filter( 'learndash_woocommerce_reset_subscription_group_access_from', 'eds_delay_subscription_group_enrollment', 10, 3 );
add_action( 'ldoq_quiz_skipped', 'eds_shift_after_skipped_quiz', 10, 3 );
add_action( 'voap_quiz_purchased', 'eds_shift_after_purchased_quiz', 10, 3 );

define( 'EDS_PROGRESS_COURSE_ID', 100 );

require_once __DIR__ . '/quiz-backfill.php';

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

function eds_shift_course_enrollment_back_one_day( $user_id, $course_id ) {
    if ( ! $user_id || ! $course_id || ! function_exists( 'learndash_get_users_group_ids' ) ) {
        return false;
    }

    $shifted = false;
    foreach ( array_map( 'intval', (array) learndash_get_users_group_ids( $user_id ) ) as $group_id ) {
        $courses = array_map( 'intval', (array) learndash_group_enrolled_courses( $group_id ) );
        if ( ! in_array( (int) $course_id, $courses, true ) ) {
            continue;
        }

        $timestamp = (int) get_user_meta( $user_id, "learndash_group_{$group_id}_enrolled_at", true );
        $timestamp = $timestamp ?: (int) get_user_meta( $user_id, "group_{$group_id}_access_from", true );
        if ( $timestamp ) {
            eds_set_group_enrollment_timestamp( $user_id, $group_id, eds_shift_enrollment_timestamp( $timestamp, '-', 1, 'days' ), $courses );
            $shifted = true;
        }
    }

    if ( $shifted ) {
        return true;
    }

    $key       = "course_{$course_id}_access_from";
    $timestamp = (int) get_user_meta( $user_id, $key, true );
    if ( ! $timestamp ) {
        return false;
    }

    update_user_meta( $user_id, $key, eds_shift_enrollment_timestamp( $timestamp, '-', 1, 'days' ) );
    ld_update_course_access( $user_id, $course_id, false );
    return true;
}

function eds_advance_quiz_checkpoint( $quiz_id, $user_id, $course_id ) {
    $quiz_id   = absint( $quiz_id );
    $user_id   = absint( $user_id );
    $course_id = absint( $course_id );
    if ( ! $quiz_id || ! $user_id || ! $course_id ) {
        return false;
    }

    $advanced = get_user_meta( $user_id, '_eds_quiz_advancements', true );
    $advanced = is_array( $advanced ) ? $advanced : [];
    $key      = "{$course_id}:{$quiz_id}";
    if ( isset( $advanced[ $key ] ) || ! eds_shift_course_enrollment_back_one_day( $user_id, $course_id ) ) {
        return false;
    }

    $advanced[ $key ] = time();
    update_user_meta( $user_id, '_eds_quiz_advancements', $advanced );
    return true;
}

function eds_shift_after_skipped_quiz( $attempt, $user_id, $course_id ) {
    return eds_advance_quiz_checkpoint( $attempt['quiz'] ?? 0, $user_id, $course_id );
}

function eds_shift_after_purchased_quiz( $quiz_id, $user_id, $course_id ) {
    return eds_advance_quiz_checkpoint( $quiz_id, $user_id, $course_id );
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
