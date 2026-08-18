<?php
/**
 * One-time seed of the progress snapshot every learner is missing.
 *
 * Without a snapshot the daily alignment has to guess a learner's high-water mark on their next
 * visit. This writes it up front from LearnDash's activity log, which records every letter that was
 * opened whether or not the learner ever clicked the done button.
 *
 * Everyone is stamped as active on the day the seed runs, so seeding never clamps anybody by
 * itself. The alignment takes over from the first day a learner actually misses.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_menu', 'edsas_register_admin_page', 12 );

function edsas_register_admin_page() {
    add_submenu_page(
        'enrollment-shift',
        'Activity Seed',
        'Activity Seed',
        'manage_options',
        'eds-activity-seed',
        'edsas_render_admin_page'
    );
}

/** Furthest topic opened, per learner, for the whole course in one query. */
function edsas_furthest_topic_by_user() {
    global $wpdb;

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            'SELECT DISTINCT user_id, post_id FROM ' . esc_sql( LDLMS_DB::get_table_name( 'user_activity' ) )
            . ' WHERE course_id = %d AND activity_type = %s',
            EDS_PROGRESS_COURSE_ID,
            'topic'
        )
    );

    $topics_by_user = [];
    foreach ( (array) $rows as $row ) {
        $topics_by_user[ (int) $row->user_id ][] = (int) $row->post_id;
    }

    return array_filter( array_map( 'eds_furthest_of', $topics_by_user ) );
}

function edsas_run( $dry_run ) {
    if ( ! class_exists( 'LDLMS_DB' ) ) {
        return [ 'error' => 'LearnDash must be active to read the activity log.' ];
    }

    $today  = current_time( 'Y-m-d' );
    $seeded = 0;
    $fixed  = 0;

    $furthest_by_user = edsas_furthest_topic_by_user();

    foreach ( $furthest_by_user as $user_id => $topic_id ) {
        $stored = get_user_meta( $user_id, '_eds_previous_activity', true );
        $stored = is_array( $stored ) ? $stored : [];

        // A snapshot already ahead of the activity log is the learner's own progress, leave it.
        if ( ! empty( $stored['topic_id'] ) && eds_topic_drip_day( (int) $stored['topic_id'] ) >= eds_topic_drip_day( $topic_id ) ) {
            continue;
        }

        if ( empty( $stored['topic_id'] ) ) {
            ++$seeded;
        } else {
            ++$fixed;
        }

        if ( ! $dry_run ) {
            update_user_meta(
                $user_id,
                '_eds_previous_activity',
                [
                    'date'        => $today,
                    'topic_id'    => $topic_id,
                    'recorded_at' => time(),
                ]
            );
        }
    }

    return [
        'learners' => count( $furthest_by_user ),
        'seeded'   => $seeded,
        'fixed'    => $fixed,
        'dry_run'  => $dry_run,
    ];
}

function edsas_handle_form_submission() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return [ 'error' => 'You do not have permission to perform this action.' ];
    }
    if ( ! isset( $_POST['edsas_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['edsas_nonce'] ) ), 'edsas_seed' ) ) {
        return [ 'error' => 'Security check failed. Please try again.' ];
    }

    return edsas_run( ! empty( $_POST['edsas_dry_run'] ) );
}

function edsas_render_admin_page() {
    $result  = isset( $_POST['edsas_submit'] ) ? edsas_handle_form_submission() : null;
    $dry_run = isset( $_POST['edsas_submit'] ) ? ! empty( $_POST['edsas_dry_run'] ) : true;
    ?>
    <div class="wrap">
        <h1>Activity Seed</h1>
        <p>
            Gives every learner in course <?php echo (int) EDS_PROGRESS_COURSE_ID; ?> the progress snapshot the
            daily alignment reads, set to the furthest letter they ever opened. Letters that were read
            without pressing the done button still count. Everyone is recorded as active today, so this
            run does not move anybody's enrollment date on its own.
        </p>

        <?php if ( isset( $result['error'] ) ) : ?>
            <div class="notice notice-error"><p><?php echo esc_html( $result['error'] ); ?></p></div>
        <?php elseif ( is_array( $result ) ) : ?>
            <div class="notice notice-<?php echo $result['dry_run'] ? 'info' : 'success'; ?>">
                <p>
                    <?php
                    printf(
                        /* translators: run summary */
                        esc_html( $result['dry_run']
                            ? 'Dry run: %1$d learner(s) would be seeded and %2$d stale snapshot(s) corrected, out of %3$d learner(s) with activity.'
                            : 'Seeded %1$d learner(s) and corrected %2$d stale snapshot(s), out of %3$d learner(s) with activity.' ),
                        (int) $result['seeded'],
                        (int) $result['fixed'],
                        (int) $result['learners']
                    );
                    ?>
                </p>
            </div>
        <?php endif; ?>

        <form method="post" action="" style="max-width:600px;">
            <?php wp_nonce_field( 'edsas_seed', 'edsas_nonce' ); ?>
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row">Dry run</th>
                        <td>
                            <label><input type="checkbox" name="edsas_dry_run" value="1" <?php checked( $dry_run ); ?> /> Only count what would change</label>
                            <?php // ponytail: one query plus one write per learner, no batching. Add it if the course ever outgrows a single request. ?>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p class="submit">
                <input type="submit" name="edsas_submit" class="button button-primary button-large" value="Run Seed" />
            </p>
        </form>
    </div>
    <?php
}
