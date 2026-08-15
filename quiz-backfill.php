<?php
/**
 * One-time backfill for quizzes added to a course learners are already part-way through.
 *
 * Reuses the optional-quizzes skip primitive, so each backfilled quiz is the same tagged
 * 0% attempt a manual skip records: the quiz is not purchased and no quiz reward or
 * notification hook fires. Only quizzes whose parent step is already complete qualify,
 * which is exactly the content the learner walked past before the quizzes went live.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_menu', 'edsqb_register_admin_page', 11 );

function edsqb_register_admin_page() {
    add_submenu_page(
        'enrollment-shift',
        'Quiz Backfill',
        'Quiz Backfill',
        'manage_options',
        'eds-quiz-backfill',
        'edsqb_render_admin_page'
    );
}

/** Course quizzes in course order, each tagged with the lesson/topic that holds it. */
function edsqb_course_quizzes( $course_id ) {
    static $cache = [];
    if ( isset( $cache[ $course_id ] ) ) {
        return $cache[ $course_id ];
    }

    $quizzes = [];
    $collect = function ( $parent_id, $lesson_id, $topic_id ) use ( $course_id, &$quizzes ) {
        foreach ( (array) learndash_course_get_children_of_step( $course_id, $parent_id, 'sfwd-quiz' ) as $quiz_id ) {
            $quizzes[] = [
                'quiz'   => (int) $quiz_id,
                'lesson' => (int) $lesson_id,
                'topic'  => (int) $topic_id,
            ];
        }
    };

    // ponytail: course-level quizzes are listed but never qualify, they have no parent step
    // to prove the learner passed them. Add a rule here if the course ever gets one.
    $collect( $course_id, 0, 0 );

    foreach ( (array) learndash_course_get_children_of_step( $course_id, $course_id, 'sfwd-lessons' ) as $lesson_id ) {
        $lesson_id = (int) $lesson_id;
        $collect( $lesson_id, $lesson_id, 0 );
        foreach ( (array) learndash_course_get_children_of_step( $course_id, $lesson_id, 'sfwd-topic' ) as $topic_id ) {
            $collect( (int) $topic_id, $lesson_id, (int) $topic_id );
        }
    }

    $cache[ $course_id ] = $quizzes;
    return $quizzes;
}

/**
 * True when the step holding the quiz is already complete for this user.
 *
 * A complete parent also means LearnDash will not re-fire its lesson/topic completion
 * hooks when the quiz is marked, so the backfill stays reward-free.
 */
function edsqb_user_passed_quiz( $progress, $quiz ) {
    if ( $quiz['topic'] ) {
        return ! empty( $progress['topics'][ $quiz['lesson'] ][ $quiz['topic'] ] );
    }

    return $quiz['lesson'] && ! empty( $progress['lessons'][ $quiz['lesson'] ] );
}

function edsqb_backfill_user( $user_id, $course_id, $quizzes, $dry_run ) {
    $progress = learndash_user_get_course_progress( $user_id, $course_id, 'legacy' );
    if ( ! is_array( $progress ) ) {
        return 0;
    }

    $marked = 0;
    foreach ( $quizzes as $quiz ) {
        if (
            ! edsqb_user_passed_quiz( $progress, $quiz )
            || learndash_is_quiz_complete( $user_id, $quiz['quiz'], $course_id )
        ) {
            continue;
        }

        if ( $dry_run || ! is_wp_error( ldoq_record_skip( $user_id, $quiz['quiz'], $course_id ) ) ) {
            ++$marked;
        }
    }

    return $marked;
}

function edsqb_run( $course_id, $offset, $batch, $dry_run ) {
    global $wpdb;

    if ( ! function_exists( 'ldoq_record_skip' ) ) {
        return [ 'error' => 'The LearnDash Optional Quizzes plugin must be active to record the completions.' ];
    }
    if ( get_post_type( $course_id ) !== 'sfwd-courses' ) {
        return [ 'error' => "No course found with ID {$course_id}." ];
    }

    $quizzes = edsqb_course_quizzes( $course_id );
    if ( ! $quizzes ) {
        return [ 'error' => "Course {$course_id} has no quizzes to backfill." ];
    }

    $user_ids = $wpdb->get_col( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = '_sfwd-course_progress' ORDER BY user_id ASC" );
    $total    = count( $user_ids );

    // A backfilled quiz is history, not a checkpoint the learner just cleared, so it must
    // not advance drip dates the way a live skip does.
    remove_action( 'ldoq_quiz_skipped', 'eds_shift_after_skipped_quiz', 10 );

    $users  = 0;
    $marked = 0;
    foreach ( array_slice( $user_ids, $offset, $batch ) as $user_id ) {
        $count = edsqb_backfill_user( (int) $user_id, $course_id, $quizzes, $dry_run );
        if ( $count ) {
            ++$users;
            $marked += $count;
        }
    }

    add_action( 'ldoq_quiz_skipped', 'eds_shift_after_skipped_quiz', 10, 3 );

    return [
        'quizzes' => count( $quizzes ),
        'total'   => $total,
        'done'    => min( $offset + $batch, $total ),
        'next'    => $offset + $batch < $total ? $offset + $batch : 0,
        'users'   => $users,
        'marked'  => $marked,
        'dry_run' => $dry_run,
    ];
}

function edsqb_handle_form_submission() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return [ 'error' => 'You do not have permission to perform this action.' ];
    }
    if ( ! isset( $_POST['edsqb_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['edsqb_nonce'] ) ), 'edsqb_backfill' ) ) {
        return [ 'error' => 'Security check failed. Please try again.' ];
    }

    $course_id = isset( $_POST['edsqb_course_id'] ) ? absint( $_POST['edsqb_course_id'] ) : 0;
    $offset    = isset( $_POST['edsqb_offset'] ) ? absint( $_POST['edsqb_offset'] ) : 0;
    $batch     = isset( $_POST['edsqb_batch'] ) ? absint( $_POST['edsqb_batch'] ) : 0;

    if ( $course_id <= 0 ) {
        return [ 'error' => 'Please enter a valid Course ID.' ];
    }
    if ( $batch <= 0 ) {
        return [ 'error' => 'Please enter a batch size greater than 0.' ];
    }

    return edsqb_run( $course_id, $offset, $batch, ! empty( $_POST['edsqb_dry_run'] ) );
}

function edsqb_render_admin_page() {
    $result = isset( $_POST['edsqb_submit'] ) ? edsqb_handle_form_submission() : null;

    $course_id = isset( $_POST['edsqb_course_id'] ) ? absint( $_POST['edsqb_course_id'] ) : EDS_PROGRESS_COURSE_ID;
    $batch     = isset( $_POST['edsqb_batch'] ) ? absint( $_POST['edsqb_batch'] ) : 50;
    $dry_run   = isset( $_POST['edsqb_submit'] ) ? ! empty( $_POST['edsqb_dry_run'] ) : true;
    $offset    = isset( $result['next'] ) ? (int) $result['next'] : 0;
    ?>
    <div class="wrap">
        <h1>Quiz Backfill</h1>
        <p>
            Marks quizzes complete for learners who already worked past them, so newly published
            quizzes do not block existing progress. A quiz only qualifies when its lesson or topic
            is already complete for that learner. Completions are recorded as 0% skips: nothing is
            purchased, no points or quiz rewards are awarded, and drip dates are left alone.
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
                            ? 'Dry run: %1$d quiz completion(s) across %2$d learner(s) would be recorded. Checked %3$d of %4$d learners against %5$d course quiz(zes).'
                            : 'Recorded %1$d quiz completion(s) across %2$d learner(s). Checked %3$d of %4$d learners against %5$d course quiz(zes).' ),
                        (int) $result['marked'],
                        (int) $result['users'],
                        (int) $result['done'],
                        (int) $result['total'],
                        (int) $result['quizzes']
                    );
                    ?>
                </p>
                <?php if ( $result['next'] ) : ?>
                    <p><strong>More learners remain. Submit again to continue from <?php echo (int) $result['next']; ?>.</strong></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="" style="max-width:600px;">
            <?php wp_nonce_field( 'edsqb_backfill', 'edsqb_nonce' ); ?>
            <input type="hidden" name="edsqb_offset" value="<?php echo esc_attr( $offset ); ?>" />

            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><label for="edsqb_course_id">Course ID</label></th>
                        <td>
                            <input type="number" id="edsqb_course_id" name="edsqb_course_id" class="small-text" min="1" value="<?php echo esc_attr( $course_id ); ?>" required />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="edsqb_batch">Learners per run</label></th>
                        <td>
                            <input type="number" id="edsqb_batch" name="edsqb_batch" class="small-text" min="1" value="<?php echo esc_attr( $batch ); ?>" required />
                            <p class="description">
                                <?php // ponytail: manual continue for a one-time job. Automate the loop if it ever needs to run unattended. ?>
                                Starting at learner <?php echo (int) $offset; ?>. Keep submitting until the summary stops asking to continue.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Dry run</th>
                        <td>
                            <label><input type="checkbox" name="edsqb_dry_run" value="1" <?php checked( $dry_run ); ?> /> Only count what would change</label>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p class="submit">
                <input type="submit" name="edsqb_submit" class="button button-primary button-large" value="Run Backfill" />
            </p>
        </form>
    </div>
    <?php
}
