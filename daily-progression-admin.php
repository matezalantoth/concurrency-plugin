<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_menu', function () {
    add_submenu_page( 'enrollment-shift', 'Daily Letters', 'Daily Letters', 'manage_options', 'eds-daily-letters', 'eds_daily_admin_page' );
} );

/** Original drip dates, including chapter dates, with only our additional gate removed. */
function eds_daily_original_date( $user_id, $topic_id ) {
    // LearnDash may lazily persist an activity-derived course anchor even during a read.
    // Preview must not do that; suppress only that write while asking its native date API.
    $read_only = function ( $check, $object_id, $key ) use ( $user_id ) {
        return (int) $object_id === (int) $user_id && 'course_' . EDS_PROGRESS_COURSE_ID . '_access_from' === $key ? false : $check;
    };
    add_filter( 'update_user_metadata', $read_only, 10, 3 );
    remove_filter( 'ld_lesson_access_from', 'eds_daily_access_from', 100 );
    try {
        $date = 0;
        $ids = array_merge( [ $topic_id ], learndash_course_get_all_parent_step_ids( EDS_PROGRESS_COURSE_ID, $topic_id ) );
        foreach ( $ids as $id ) {
            $date = max( $date, (int) ld_lesson_access_from( $id, $user_id, EDS_PROGRESS_COURSE_ID ) );
        }
        return $date;
    } finally {
        remove_filter( 'update_user_metadata', $read_only, 10 );
        add_filter( 'ld_lesson_access_from', 'eds_daily_access_from', 100, 3 );
    }
}

/** Preserve date-unlocked and completed letters, even if old completion ticks are missing. */
function eds_daily_snapshot( $user_id ) {
    $protected = [];
    foreach ( eds_daily_topics() as $id ) {
        if ( ! eds_daily_original_date( $user_id, $id )
            || learndash_is_topic_complete( $user_id, $id, EDS_PROGRESS_COURSE_ID ) ) {
            $protected[] = $id;
        }
    }
    return $protected;
}

function eds_daily_admin_save() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return new WP_Error( 'forbidden', 'Administrator permission is required.' );
    }
    if ( ! isset( $_POST['eds_daily_nonce'] )
        || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['eds_daily_nonce'] ) ), 'eds_daily_settings' ) ) {
        return new WP_Error( 'nonce', 'Security check failed. Reload and try again.' );
    }
    if ( isset( $_POST['eds_daily_save_rollout'] ) ) {
        $rollout = (array) get_option( 'eds_daily_rollout', [] );
        $rollout['enabled'] = ! empty( $_POST['eds_daily_new_accounts'] );
        $rollout['paused']  = ! empty( $_POST['eds_daily_paused'] );
        if ( $rollout['enabled'] && empty( $rollout['since'] ) ) {
            $rollout['since'] = time();
        }
        if ( false === update_option( 'eds_daily_rollout', $rollout, false )
            && $rollout !== get_option( 'eds_daily_rollout', [] ) ) {
            return new WP_Error( 'save', 'Could not save rollout settings.' );
        }
        return 'Rollout settings saved.';
    }
    $user_id = absint( $_POST['eds_daily_user'] ?? 0 );
    $mode    = sanitize_key( wp_unslash( $_POST['eds_daily_mode'] ?? '' ) );
    if ( ! get_userdata( $user_id ) || ! in_array( $mode, [ 'observe', 'enforce', 'off' ], true ) ) {
        return new WP_Error( 'invalid', 'Choose an existing user and a valid mode.' );
    }
    if ( user_can( $user_id, 'manage_options' ) ) {
        return new WP_Error( 'admin', 'Use a learner account. Administrators bypass pacing.' );
    }
    if ( ! function_exists( 'sfwd_lms_has_access' ) || ! sfwd_lms_has_access( EDS_PROGRESS_COURSE_ID, $user_id ) ) {
        return new WP_Error( 'access', 'This learner must have access to the daily letters course first.' );
    }
    $existing = eds_daily_config( $user_id );
    // Starting/restarting or leaving observation preserves everything currently date-open.
    // Re-saving an enforced learner must not expand the archive and bypass daily pacing.
    $protected = 'enforce' === ( $existing['mode'] ?? '' )
        ? ( $existing['protected'] ?? [] ) : eds_daily_snapshot( $user_id );
    $config = [ 'mode' => $mode, 'protected' => $protected ];
    if ( false === update_user_meta( $user_id, '_eds_daily_progression', $config )
        && $config !== get_user_meta( $user_id, '_eds_daily_progression', true ) ) {
        return new WP_Error( 'save', 'Could not save the learner settings.' );
    }
    return 'Learner settings saved. Preserved letters remain subject to ordinary course access and LearnDash prerequisites.';
}

function eds_daily_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $result = isset( $_POST['eds_daily_save_rollout'] ) || isset( $_POST['eds_daily_save_user'] ) ? eds_daily_admin_save() : null;
    $user_id = absint( $_REQUEST['eds_daily_user'] ?? 0 );
    $rollout = (array) get_option( 'eds_daily_rollout', [] );
    $user = $user_id ? get_userdata( $user_id ) : false;
    ?>
    <div class="wrap">
        <h1>Daily Letters — course <?php echo (int) EDS_PROGRESS_COURSE_ID; ?></h1>
        <p>New letters require completion of earlier letters and wait until the next midnight after the previous letter was completed.
            Old date-unlocked letters are preserved for manually enrolled learners. This does not mark anything complete or grant course membership.</p>
        <p>Install default: no learners are changed. Preview a learner, observe, then enforce for a pilot before enabling new accounts.
            Midnight uses the WordPress timezone: <strong><?php echo esc_html( wp_timezone()->getName() ); ?></strong>.</p>
        <?php if ( $result ) : ?>
            <div class="notice notice-<?php echo is_wp_error( $result ) ? 'error' : 'success'; ?>"><p><?php echo esc_html( is_wp_error( $result ) ? $result->get_error_message() : $result ); ?></p></div>
        <?php endif; ?>
        <form method="post">
            <?php wp_nonce_field( 'eds_daily_settings', 'eds_daily_nonce' ); ?>
            <p><label><input type="checkbox" name="eds_daily_new_accounts" value="1" <?php checked( ! empty( $rollout['enabled'] ) ); ?>> Enforce pacing for new accounts</label>
                <?php if ( ! empty( $rollout['since'] ) ) : ?>
                    (created from <?php echo esc_html( wp_date( 'Y-m-d H:i:s T', $rollout['since'] ) ); ?>)
                <?php endif; ?>
            </p>
            <p>Existing accounts require individual opt-in, including existing accounts joining the course later. New accounts start with letter 1; the old minus-12-day enrollment shift cannot unlock extra letters.</p>
            <p><label><input type="checkbox" name="eds_daily_paused" value="1" <?php checked( ! empty( $rollout['paused'] ) ); ?>> Pause all daily pacing (emergency rollback; keep settings)</label></p>
            <button class="button" name="eds_daily_save_rollout" value="1">Save rollout settings</button>
        </form>
        <hr>
        <form method="get">
            <input type="hidden" name="page" value="eds-daily-letters">
            <label for="eds-daily-user">Learner user ID</label>
            <input id="eds-daily-user" type="number" min="1" name="eds_daily_user" value="<?php echo esc_attr( $user_id ?: '' ); ?>" required>
            <button class="button">Preview without saving</button>
        </form>
        <?php if ( $user && function_exists( 'learndash_user_get_course_progress' ) ) :
            $config = eds_daily_config( $user_id );
            $mode = $config['mode'] ?? 'off';
            $preview = in_array( $mode, [ 'observe', 'enforce' ], true ) ? $config
                : [ 'mode' => 'observe', 'protected' => eds_daily_snapshot( $user_id ) ];
            ?>
            <h2><?php echo esc_html( $user->display_name ); ?> — <?php echo esc_html( $mode ); ?></h2>
            <p>The preview shows the additional pacing rule. Existing subscription, drip and quiz requirements still apply. Observation does not restrict the learner.
                All letters already available when enforcement starts remain available; missing old completion ticks still need to be filled in order.</p>
            <form method="post">
                <?php wp_nonce_field( 'eds_daily_settings', 'eds_daily_nonce' ); ?>
                <input type="hidden" name="eds_daily_user" value="<?php echo (int) $user_id; ?>">
                <label for="eds-daily-mode">Learner mode</label>
                <select id="eds-daily-mode" name="eds_daily_mode">
                    <?php foreach ( [ 'off' => 'Off / exempt', 'observe' => 'Observe only', 'enforce' => 'Enforce' ] as $value => $label ) : ?>
                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $mode, $value ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="button button-primary" name="eds_daily_save_user" value="1">Save learner settings</button>
            </form>
            <table class="widefat striped" style="margin-top:16px">
                <thead><tr><th>Letter</th><th>Original drip</th><th>Daily pacing</th></tr></thead><tbody>
                <?php foreach ( eds_daily_topics() as $id ) :
                    $date = eds_daily_original_date( $user_id, $id );
                    $gate = eds_daily_decision( $user_id, $id, $preview );
                    $label = in_array( $id, $preview['protected'], true ) ? 'Preserved archive'
                        : ( $gate ? eds_daily_message( $gate ) : 'No additional lock' );
                    ?>
                    <tr><td><?php echo esc_html( get_the_title( $id ) ); ?></td><td><?php echo esc_html( $date ? wp_date( 'Y-m-d H:i T', $date ) : 'Date unlocked' ); ?></td><td><?php echo esc_html( $label ); ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php elseif ( $user_id ) : ?>
            <p>Learner or LearnDash is unavailable.</p>
        <?php endif; ?>
    </div>
    <?php
}
