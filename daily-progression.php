<?php
/** Completion-based daily letters. Opt-in; never rewrites enrollment or completion data. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function eds_daily_config( $user_id ) {
    if ( ! $user_id ) {
        return [];
    }
    $rollout = (array) get_option( 'eds_daily_rollout', [] );
    $config = get_user_meta( $user_id, '_eds_daily_progression', true );
    if ( is_array( $config ) ) {
        return $config;
    }
    $user = get_userdata( $user_id );
    // Account creation is stable; enrollment anchors are deliberately shifted elsewhere.
    if ( ! empty( $rollout['enabled'] ) && ! empty( $rollout['since'] ) && $user
        && strtotime( $user->user_registered . ' UTC' ) >= (int) $rollout['since'] ) {
        return [ 'mode' => 'enforce', 'protected' => [] ];
    }
    return [];
}

function eds_daily_topics() {
    if ( ! function_exists( 'learndash_course_get_steps_by_type' ) ) {
        return [];
    }
    return array_values( array_filter(
        array_map( 'intval', learndash_course_get_steps_by_type( EDS_PROGRESS_COURSE_ID, 'sfwd-topic' ) ),
        function ( $id ) { return 'publish' === get_post_status( $id ); }
    ) );
}

/** A topic quiz inherits its letter's gate. Chapters are containers, not daily letters. */
function eds_daily_topic_for_step( $step_id, $topics ) {
    if ( in_array( (int) $step_id, $topics, true ) ) {
        return (int) $step_id;
    }
    if ( 'sfwd-quiz' === get_post_type( $step_id ) ) {
        foreach ( learndash_course_get_all_parent_step_ids( EDS_PROGRESS_COURSE_ID, $step_id ) as $parent ) {
            if ( in_array( (int) $parent, $topics, true ) ) {
                return (int) $parent;
            }
        }
    }
    return 0;
}

/** Site-local next midnight, including 23/25-hour DST days. */
function eds_daily_next_midnight( $timestamp ) {
    return ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( wp_timezone() )
        ->modify( 'tomorrow' )->setTime( 0, 0 )->getTimestamp();
}

/** Read-only decision; $now is injectable in offline tests, never from an HTTP request. */
function eds_daily_decision( $user_id, $step_id, $config, $now = null ) {
    if ( ! in_array( $config['mode'] ?? '', [ 'observe', 'enforce' ], true ) ) {
        return [];
    }
    $topics = eds_daily_topics();
    $topic  = eds_daily_topic_for_step( $step_id, $topics );
    if ( ! $topic || in_array( $topic, $config['protected'] ?? [], true ) ) {
        return [];
    }
    $now      = $now ?? time();
    $progress = learndash_user_get_course_progress( $user_id, EDS_PROGRESS_COURSE_ID, 'legacy' );
    $complete = [];
    foreach ( (array) ( $progress['topics'] ?? [] ) as $children ) {
        $complete += array_filter( (array) $children );
    }
    // Rereading a completed letter must never consume another day.
    if ( ! empty( $complete[ $topic ] ) ) {
        return [];
    }
    $previous = 0;
    foreach ( $topics as $id ) {
        if ( $id === $topic ) {
            break;
        }
        if ( empty( $complete[ $id ] ) ) {
            // LearnDash's date-only APIs require a future timestamp. The page/preview
            // explains the actual prerequisite; midnight alone does not remove this gate.
            return [ 'reason' => 'previous', 'previous' => $id, 'until' => eds_daily_next_midnight( $now ) ];
        }
        $previous = $id;
    }
    if ( ! $previous ) {
        return [];
    }
    $activity = learndash_get_user_activity( [
        'user_id' => $user_id, 'course_id' => EDS_PROGRESS_COURSE_ID,
        'post_id' => $previous, 'activity_type' => 'topic',
    ] );
    $completed_at = (int) ( $activity->activity_completed ?? 0 );
    if ( ! $completed_at ) {
        // Old imported completions can legitimately lack activity records. Only the
        // explicitly preserved archive receives this exception, never newly paced letters.
        return in_array( $previous, $config['protected'] ?? [], true ) ? []
            : [ 'reason' => 'missing_time', 'previous' => $previous, 'until' => eds_daily_next_midnight( $now ) ];
    }
    $until = eds_daily_next_midnight( $completed_at );
    return $until > $now ? [ 'reason' => 'midnight', 'previous' => $previous, 'until' => $until ] : [];
}

function eds_daily_gate( $user_id, $step_id ) {
    $config = eds_daily_config( $user_id );
    $rollout = (array) get_option( 'eds_daily_rollout', [] );
    if ( ! empty( $rollout['paused'] ) || 'enforce' !== ( $config['mode'] ?? '' )
        || ! function_exists( 'learndash_get_course_id' )
        || EDS_PROGRESS_COURSE_ID !== (int) learndash_get_course_id( $step_id )
        || user_can( $user_id, 'manage_options' ) ) {
        return [];
    }
    return eds_daily_decision( $user_id, $step_id, $config );
}

function eds_daily_access_from( $access_from, $step_id, $user_id ) {
    $gate = eds_daily_gate( $user_id, $step_id );
    return $gate ? max( (int) $access_from, $gate['until'] ) : $access_from;
}
add_filter( 'ld_lesson_access_from', 'eds_daily_access_from', 100, 3 );

/** Completion is validated on the server, including stale forms and programmatic calls. */
function eds_daily_can_mark_complete( $allowed, $post, $user ) {
    if ( ! $allowed || eds_daily_gate( $user->ID, $post->ID ) ) {
        return false;
    }
    $config = eds_daily_config( $user->ID );
    $rollout = (array) get_option( 'eds_daily_rollout', [] );
    if ( empty( $rollout['paused'] ) && 'enforce' === ( $config['mode'] ?? '' ) && ! user_can( $user->ID, 'manage_options' )
        && EDS_PROGRESS_COURSE_ID === (int) learndash_get_course_id( $post->ID )
        && eds_daily_topic_for_step( $post->ID, eds_daily_topics() ) ) {
        // LearnDash's completion primitive does not itself validate drip dates.
        $ids = array_merge( [ $post->ID ], learndash_course_get_all_parent_step_ids( EDS_PROGRESS_COURSE_ID, $post->ID ) );
        foreach ( $ids as $id ) {
            if ( (int) ld_lesson_access_from( $id, $user->ID, EDS_PROGRESS_COURSE_ID ) > time() ) {
                return false;
            }
        }
    }
    return $allowed;
}
add_filter( 'learndash_process_mark_complete', 'eds_daily_can_mark_complete', 100, 3 );

function eds_daily_mark_complete_button( $html, $post ) {
    return eds_daily_gate( get_current_user_id(), $post->ID ) ? '' : $html;
}
add_filter( 'learndash_mark_complete', 'eds_daily_mark_complete_button', 100, 2 );

function eds_daily_message( $gate ) {
    if ( 'previous' === $gate['reason'] ) {
        return 'A továbblépéshez először jelöld elolvasottnak ezt a levelet: ' . get_the_title( $gate['previous'] );
    }
    if ( 'missing_time' === $gate['reason'] ) {
        return 'Az előző levél teljesítési dátuma hiányzik. Kérjük, jelezd az ügyfélszolgálatnak.';
    }
    return 'A következő levél ekkortól olvasható: ' . wp_date( 'Y-m-d H:i T', $gate['until'] );
}

/** Stop before Elementor/custom templates can render locked letter bodies. */
function eds_daily_protect_page() {
    if ( ! is_singular( [ 'sfwd-topic', 'sfwd-lessons', 'sfwd-quiz' ] ) ) {
        return;
    }
    $gate = eds_daily_gate( get_current_user_id(), get_queried_object_id() );
    if ( $gate ) {
        nocache_headers();
        wp_die( '<p>' . esc_html( eds_daily_message( $gate ) ) . '</p><p><a href="'
            . esc_url( get_permalink( $gate['previous'] ) ) . '">Vissza az előző levélhez</a></p>',
            'Napi olvasnivaló', [ 'response' => 403, 'back_link' => true ] );
    }
}
add_action( 'template_redirect', 'eds_daily_protect_page', 0 );

function eds_daily_protect_rest( $response, $post ) {
    $gate = eds_daily_gate( get_current_user_id(), $post->ID );
    if ( $gate ) {
        $response->set_data( [ 'id' => $post->ID, 'code' => 'eds_daily_locked', 'message' => eds_daily_message( $gate ) ] );
        $response->set_status( 403 );
    }
    return $response;
}
foreach ( [ 'sfwd-topic', 'sfwd-lessons', 'sfwd-quiz' ] as $eds_daily_type ) {
    add_filter( 'rest_prepare_' . $eds_daily_type, 'eds_daily_protect_rest', 100, 2 );
}

require_once __DIR__ . '/daily-progression-admin.php';
