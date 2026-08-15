<?php

if ( PHP_SAPI !== 'cli' ) {
    exit;
}

define( 'ABSPATH', __DIR__ );
define( 'EDS_PROGRESS_COURSE_ID', 100 );

// Course 100: lesson 10 (topics 11, 12), lesson 20 (topic 21). Quiz per topic, plus a
// lesson-level quiz on 20 and a course-level quiz.
$children = [
    '100:sfwd-lessons' => [ 10, 20 ],
    '10:sfwd-topic'    => [ 11, 12 ],
    '20:sfwd-topic'    => [ 21 ],
    '11:sfwd-quiz'     => [ 111 ],
    '12:sfwd-quiz'     => [ 121 ],
    '21:sfwd-quiz'     => [ 211 ],
    '20:sfwd-quiz'     => [ 201 ],
    '100:sfwd-quiz'    => [ 999 ],
];

$progress = [
    // Finished topic 11, half way through 12, never reached lesson 20.
    7 => [ 'lessons' => [], 'topics' => [ 10 => [ 11 => 1, 12 => 0 ] ] ],
    // Finished lesson 10 and its topics, plus lesson 20 itself but not topic 21.
    8 => [ 'lessons' => [ 10 => 1, 20 => 1 ], 'topics' => [ 10 => [ 11 => 1, 12 => 1 ], 20 => [ 21 => 0 ] ] ],
];

$complete_quizzes = [ '8:121' ]; // User 8 already sat quiz 121 for real.
$recorded         = [];
$hooks            = [ 'ldoq_quiz_skipped' => true ];

function add_action( $hook, $callback = null, $priority = 10, $args = 1 ) {
    global $hooks;
    if ( 'ldoq_quiz_skipped' === $hook ) {
        $hooks[ $hook ] = true;
    }
}
function remove_action( $hook, $callback = null, $priority = 10 ) {
    global $hooks;
    if ( 'ldoq_quiz_skipped' === $hook ) {
        $hooks[ $hook ] = false;
    }
}
function add_submenu_page() {}
function absint( $value ) { return abs( (int) $value ); }
function is_wp_error( $value ) { return false; }
function get_post_type( $id ) { return 100 === (int) $id ? 'sfwd-courses' : ''; }
function learndash_course_get_children_of_step( $course_id, $step_id, $type ) {
    global $children;
    return $children[ "{$step_id}:{$type}" ] ?? [];
}
function learndash_user_get_course_progress( $user_id, $course_id, $type ) {
    global $progress;
    return $progress[ $user_id ] ?? [];
}
function learndash_is_quiz_complete( $user_id, $quiz_id, $course_id ) {
    global $complete_quizzes, $recorded;
    return in_array( "{$user_id}:{$quiz_id}", $complete_quizzes, true )
        || in_array( "{$user_id}:{$quiz_id}", $recorded, true );
}
function ldoq_record_skip( $user_id, $quiz_id, $course_id ) {
    global $recorded, $hooks;
    $recorded[] = "{$user_id}:{$quiz_id}";
    if ( $hooks['ldoq_quiz_skipped'] ) {
        $recorded[] = "drip-shift:{$user_id}";
    }
    return [ 'quiz' => $quiz_id ];
}

class wpdb_stub {
    public $usermeta = 'wp_usermeta';
    public function get_col() { return [ 7, 8 ]; }
}
$GLOBALS['wpdb'] = new wpdb_stub();

require dirname( __DIR__ ) . '/quiz-backfill.php';

function check( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$message}\n" );
        exit( 1 );
    }
}

$dry = edsqb_run( 100, 0, 50, true );
check( 5 === $dry['quizzes'], 'Every quiz in the course is considered.' );
check( 3 === $dry['marked'] && 2 === $dry['users'], 'Dry run counts only quizzes behind each learner.' );
check( [] === $recorded, 'Dry run writes nothing.' );

$run = edsqb_run( 100, 0, 50, false );
check( 3 === $run['marked'], 'The live run records the same set the dry run predicted.' );
check(
    [ '7:111', '8:111', '8:201' ] === $recorded,
    'Passed topics and lessons backfill, unreached steps, course-level quizzes and real attempts do not.'
);
check( $hooks['ldoq_quiz_skipped'], 'The drip-shift hook is restored after the run.' );

$before = $recorded;
$second = edsqb_run( 100, 0, 50, false );
check( 0 === $second['marked'] && $before === $recorded, 'Re-running is a no-op once the backfill is done.' );

$page = edsqb_run( 100, 0, 1, true );
check( 1 === $page['next'] && 2 === $page['total'], 'Batching reports where the next run continues from.' );
check( 0 === edsqb_run( 100, 1, 1, true )['next'], 'The final batch reports no remainder.' );

echo "Quiz backfill tests passed.\n";
