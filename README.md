# Enrollment Date Shifter 1.5.0 — daily letter progression

## Finding

Confirmed in this workspace's code and local database, not on the production server:

- Course **100**, “Napi olvasnivaló”, contains the letters as **`sfwd-topic`** posts. Lessons are chapter containers.
- Letter 2 has `visible_after = 1`, letter 3 has `visible_after = 2`, etc. LearnDash calculates availability from the enrollment anchor plus elapsed days. It does not consult completion in that calculation.
- The existing shifter adjusts subscription anchors and advances them on quiz skips/purchases. It has no login or incomplete-letter pause. The active local snippets/automations contain no such pause either.
- LearnDash's separate linear-completion check explains why a missing “I have read” tick can prevent completing later letters. Once the missing step is completed, elapsed drip dates alone impose no new daily wait.

The offline regression test reproduces the date-unlock bug using the installed LearnDash implementation. The disposable-database integration test also reproduces it in WordPress, then verifies actual completion writes with the fix enabled.

## Implemented rule

An additional gate in the existing plugin, scoped to course 100:

1. New paced learners can read the first letter when ordinary course/drip access permits it.
2. Subsequent letters require earlier letters to be completed. Logging in, opening a page, and days spent away do not advance this gate.
3. The next letter becomes eligible at **the first midnight after its predecessor's successful completion**, in the WordPress site's timezone. It is not a rolling 24-hour wait. Completing at 23:59 can permit the next letter at 00:00.
4. Later original drip dates, membership/subscription access and LearnDash's quiz/prerequisite requirements still apply. The gate never grants membership or moves enrollment dates.
5. Completed letters remain readable. Chapters do not consume daily letters. Topic quizzes inherit the letter's gate; quiz-related enrollment shifts cannot override it.

The code reuses LearnDash's existing topic-completion flags and `activity_completed` timestamps instead of writing a second completion clock on a browser click. Its completion filter rejects locked submissions on the server, including stale forms and forced calls to LearnDash's completion primitive. The date filter supplies navigation availability; the page guard runs before templates/Elementor render, and REST preparation removes locked bodies. Content already downloaded in a browser cannot be revoked.

There is no cron job, timer queue, login dependency, enrollment rewrite, fabricated completion, or automatic reward backfill. An incomplete managed letter stays incomplete indefinitely; midnight alone cannot unlock its successor. Missing completion timestamps for newly paced letters fail closed with a support message. Old preserved imports without timestamps are tolerated.

## Existing learners and rollout

**Installation changes nobody's pacing.** Use **Enrollment Shifter → Daily Letters**.

- **Preview:** enter a learner's numeric WordPress user ID. The table shows original drip availability, the preserved archive, and the additional pacing decision. It does not save pacing settings, completion flags, or enrollment dates.
- **Observe:** save a per-user trial policy without restricting that learner. The preview shows what the policy would block. It does not send notifications or create an audit log.
- **Enforce:** pace a selected learner. On initial opt-in, all currently date-unlocked or completed letters are preserved, even where completion ticks are missing. On observe → enforce, access is snapshotted again so letters unlocked during observation are preserved too. Re-saving an already enforced learner does not expand the archive.
- **Off / exempt:** explicitly exempt an account, including one otherwise included by automatic rollout.
- **Enforce pacing for new accounts:** creates a fixed account-creation cutoff the first time it is enabled. Accounts created since that cutoff use pacing from letter 1. Older accounts, including existing accounts enrolling later, require individual opt-in. This deliberately avoids guessing a new enrollment date from mutable/shifting enrollment metadata.
- **Pause all daily pacing:** immediately bypass this additional gate for every cohort, retaining settings. Ordinary LearnDash access remains in force. Uncheck to resume. If someone read ahead while paused, review/exempt them before resuming; a temporary pause does not permanently expand their archive. The new-account cutoff is retained when disabled and re-enabled.

Legacy users with the entire course already unlocked keep that access. Existing active learners can revisit and tick their old letters without a daily wait *inside the preserved archive*. LearnDash still requires the old missing ticks in order; the preview identifies the earliest missing letter before new progression. Finishing the last preserved letter today permits progression beyond the archive no earlier than the next midnight.

**Tradeoff:** preserving existing access deliberately permits catching up multiple old letters. It is not possible to guarantee both “never relock any old readable letter” and “only one of those old letters is readable per day.” The one-per-day rule applies to new progression, beyond the preserved archive.

The local site's timezone was UTC (`timezone_string` empty, `gmt_offset = 0`) when inspected. Verify the intended production timezone before enabling pacing; select `Europe/Budapest` if Hungarian local midnight is intended. The plugin displays the configured timezone and does not change it. DST is handled with calendar dates, including 23/25-hour days. Later original LearnDash drip dates still apply independently.

## Testing without production users

From `/Users/matezalantoth/wp`:

```sh
docker exec wp-wordpress-1 php /var/www/html/wp-content/plugins/concurrency-plugin/tests/test-daily-progression.php
sh wp-data/wp-content/plugins/concurrency-plugin/tests/run-daily-integration.sh
docker exec wp-wordpress-1 php /var/www/html/wp-content/plugins/concurrency-plugin/tests/test-enrollment-shift.php
docker exec wp-wordpress-1 php /var/www/html/wp-content/plugins/concurrency-plugin/tests/test-quiz-backfill.php
```

The first test uses in-memory learners, real WordPress hooks and the installed LearnDash drip code, with an injectable clock. It covers missed days, exact midnight, DST, archive catchup, complete-course legacy access, missing timestamps, quiz parents, direct submissions, REST, other courses, later native dates, observation, new-account selection, exemptions, pause and input validation.

The integration runner uses this workspace's existing `wp-db-1` and `wp-wordpress-1` Docker containers. It creates a uniquely named disposable database, copies only course content and selected settings, creates synthetic users, and loads WordPress + LearnDash + this plugin. It copies **no real users, usermeta or learner activity**. Mail, HTTP, cron, themes, MU plugins and other plugins are disabled. The original database is read only. The disposable database is dropped on exit. The runner requires Docker and the repository's local container setup; it is not a production command.

The integration checks real completion writes and rejection, next-day continuation, duplicate completion, native REST preparation, preservation of the actual course archive, read-only decisions/preview, administrator settings and rollback. Time changes affect only disposable fixture rows, never the OS clock or real learners.

Before production enforcement:

1. Use a separate staging clone with outbound email, payments, webhooks, analytics and scheduled integrations blocked. Use synthetic/anonymized learners, not copied production identities.
2. Test the real Elementor letter template, navigation, back/next buttons, quiz completion/skip/purchase and “I have read” button as a **non-admin**. Check mobile and a persistent login session. Administrators deliberately bypass pacing.
3. Test learners at the beginning, a chapter/quiz boundary, with missing old ticks, and with full legacy access. Test repeated/stale submissions and two tabs. Check subscription expiry/reactivation still controls membership. Those third-party UI/integration paths are not fully exercised by the isolated integration runner.
4. Ensure authenticated letter pages bypass page/CDN caching. A PHP gate cannot protect content served from an upstream cache before WordPress runs. Check that the explanatory lock message appears instead of letter content.
5. Preview/observe a small pilot, explicitly enforce those accounts, and monitor support reports before enabling new-account rollout. Use the pause checkbox for immediate rollback without altering progress or shifter functionality.

## Scope and limitations

- Letter order comes from the published topics in course 100's LearnDash builder. Review the preview after reordering, deleting or adding steps. The preserved archive stores post IDs, not a guessed letter number.
- This course's published topics are treated as letters. If non-letter topics are introduced, add an explicit selection rule then.
- The decision reads current progress on each call; it does not cache a decision across completions. LearnDash's successful completion record supplies the clock, so a duplicate browser click cannot create a second cooldown counter.
- This is an additional access/normal-completion gate, not a replacement for permissions on administrative imports or integrations that write progress tables directly. Such writes must remain restricted to trusted administrators.
- No production code, settings, enrollment data, or learner records were changed during this implementation. Full production/template behavior remains a staging acceptance check.

References: [LearnDash availability hook](https://developers.learndash.com/hook/ld_lesson_access_from/), [WordPress timezone handling](https://developer.wordpress.org/reference/functions/wp_timezone/). Installed source was used to verify the integration points.
