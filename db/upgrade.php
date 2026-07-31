<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * AI Video Activity upgrade steps.
 *
 * @package    mod_aivideoactivity
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

function xmldb_aivideoactivity_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026030116) {
        $table = new xmldb_table('aivideoactivity');

        $field = new xmldb_field('mediatype', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'video', 'introformat');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('audiourl', XMLDB_TYPE_CHAR, '1000', null, null, null, null, 'youtubeurl');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026030116, 'aivideoactivity');
    }

    if ($oldversion < 2026030600123) {
        // v1.0.23: Removed auto-fetch transcript feature — no DB schema changes.
        upgrade_mod_savepoint(true, 2026030600123, 'aivideoactivity');
    }

    if ($oldversion < 2026030600125) {
        // v1.0.25: Live attempts badge fix — no DB schema changes.
        upgrade_mod_savepoint(true, 2026030600125, 'aivideoactivity');
    }

    if ($oldversion < 2026030600126) {
        // v1.0.26: Flashcard UI — removed solid blue/green gradient fills from
        // front/back cards. Cards now use clean white/neutral background with
        // color accent via top border only (blue for front, green for back).
        // No DB schema changes.
        upgrade_mod_savepoint(true, 2026030600126, 'aivideoactivity');
    }

    if ($oldversion < 2026031200128) {
        // v1.0.27 through v1.0.28: Continue-attempt position fix, True/False
        // statement parsing fix, progress restoration fix — no DB schema changes.
        upgrade_mod_savepoint(true, 2026031200128, 'aivideoactivity');
    }

    if ($oldversion < 2026031600129) {
        // v1.0.29: AMD build sync — build/videoactivity.js updated to match
        // src/videoactivity.js (statement JSON-parse fix + localStorage progress
        // priority were missing from build files). No DB schema changes.
        upgrade_mod_savepoint(true, 2026031600129, 'aivideoactivity');
    }

    if ($oldversion < 2026032200131) {
        // v1.0.31: ETA BANNERS — Estimated Time to Complete banners for teacher + student views.
        // No DB schema changes.
        upgrade_mod_savepoint(true, 2026032200131, 'aivideoactivity');
    }

    // v1.0.32: VERSION BUMP — Maintenance release. No DB changes.
    if ($oldversion < 2026032200132) {
        upgrade_mod_savepoint(true, 2026032200132, 'aivideoactivity');
    }

    // v1.0.33: ETA recalibrate.
    if ($oldversion < 2026032200133) {
        upgrade_mod_savepoint(true, 2026032200133, 'aivideoactivity');
    }

    // v1.0.34: Course info time estimation update — 2 min per question.
    if ($oldversion < 2026032300134) {
        upgrade_mod_savepoint(true, 2026032300134, 'aivideoactivity');
    }

    // v1.0.35: Industry & Sector dropdown unification. No DB schema changes.
    if ($oldversion < 2026032400135) {
        upgrade_mod_savepoint(true, 2026032400135, 'aivideoactivity');
    }

    // v1.0.36: BUG FIX — question counter, categorysort matching, dropdown CSS, voiceover fallback, regenerate instructions. No DB schema changes.
    if ($oldversion < 2026032700136) {
        upgrade_mod_savepoint(true, 2026032700136, 'aivideoactivity');
    }

    // v1.0.37: VERSION BUMP — Clean release increment. No code or DB schema changes.
    if ($oldversion < 2026032700137) {
        upgrade_mod_savepoint(true, 2026032700137, 'aivideoactivity');
    }

    // v1.0.38: FIX — Voiceover now plays for all non-MCQ question types (matching, ordering,
    //   column sort, category sort). The audio regeneration routes (regenerate-audio,
    //   regenerate-settings, regenerate-instructions) previously only processed q.explanations
    //   (an MCQ-only array), leaving non-MCQ types with empty audioData. Fixed to use
    //   q.explanation (the single explanation field for non-MCQ types) to generate audioData[0].
    //   Also added voiceover playback when an ordering answer is checked and incorrect,
    //   so students hear the explanation audio on both correct and incorrect attempts.
    if ($oldversion < 2026032700138) {
        upgrade_mod_savepoint(true, 2026032700138, 'aivideoactivity');
    }

    // v1.0.39: BUG FIX — ajaxCall() timeout added (180 000 ms) for regenerateinstructions.
    //   handleRegenerate() now passes full quizData objects (preserving type field) instead
    //   of an MCQ-only subset. No DB schema changes.
    if ($oldversion < 2026032700139) {
        upgrade_mod_savepoint(true, 2026032700139, 'aivideoactivity');
    }

    // v1.0.40: BUG FIX — (1) MCQ-GRADING: correctAnswer/correctIndex from DB as strings
    //   compared === against integer selectedIndex — every MCQ marked Wrong unless option 0
    //   was chosen. Fix: parseInt() applied before comparison. (2) ORDERING-VO-WRONG:
    //   wrong-answer branch called q.audioData[0].play() (praise audio) on incorrect attempts.
    //   Removed. No DB schema changes.
    if ($oldversion < 2026032800140) {
        upgrade_mod_savepoint(true, 2026032800140, 'aivideoactivity');
    }

    if ($oldversion < 2026040100141) {
        upgrade_mod_savepoint(true, 2026040100141, 'aivideoactivity');
    }

    // v1.0.42: VERSION BUMP — Clean release following v1.0.41 bug fixes (seek prevention,
    // teacher quiz reset, teacher preview). No DB schema changes.
    if ($oldversion < 2026040100142) {
        upgrade_mod_savepoint(true, 2026040100142, 'aivideoactivity');
    }

    // v1.0.43 FIX-VA-SHUFFLE-EDIT: Edit modal rendered options in shuffled order so
    //   teacher saves overwrote server data with shuffled options + shuffled correctAnswer.
    //   Students on the next attempt got double-shuffled options with wrong correct answer.
    //   Fix: de-shuffle using shuffledToOriginal before rendering edit modal; use
    //   originalCorrectIndex for correct-radio pre-selection. No DB schema changes.
    //   AMD: videoactivity.js updated. version.php → 2026040200143.
    if ($oldversion < 2026040200143) {
        upgrade_mod_savepoint(true, 2026040200143, 'aivideoactivity');
    }

    // v1.0.44 FIX-VA-MCQ-LABEL: MCQ answer check now uses letter-label comparison (A/B/C/D)
    //   with parseInt(correctAnswer) to eliminate type-coercion false-negatives. selectedLabel
    //   variable tracks the selected option's letter; checkAnswer falls back to index comparison
    //   if selectedLabel is null. Correct-answer highlighting also uses parseInt(correctAnswer).
    //   No DB schema changes. AMD: videoactivity.js updated. version.php → 2026040200144.
    if ($oldversion < 2026040200144) {
        upgrade_mod_savepoint(true, 2026040200144, 'aivideoactivity');
    }

    // v1.0.45 SERVER BUG FIX (x2):
    //   (1) Answer/explanation mismatch: fixExplanationOrder() (server/routes.ts) previously
    //       changed correctAnswer to match where the "Correct." explanation happened to be in the
    //       array, causing the wrong MCQ option to be highlighted as correct. Fix: the function now
    //       swaps explanations so explanations[correctAnswer] holds "Correct." — options and
    //       correctAnswer index are unchanged.
    //   (2) Q6 malformed hard-fail: /api/videoactivity-regenerate-instructions returned HTTP 500 for
    //       the entire batch when any single AI-returned question was malformed (wrong option count,
    //       etc.). Fix: malformed slots fall back per-slot to the original question data; non-MCQ
    //       question types are preserved unchanged instead of being rejected.
    //   No plugin PHP/JS changes. No DB schema changes. version.php → 2026040300145.
    if ($oldversion < 2026040300145) {
        upgrade_mod_savepoint(true, 2026040300145, 'aivideoactivity');
    }

    // v1.0.46: BUG FIX (quiz gate for Continue Attempt).
    //   The va-continue-attempt-btn button was rendered without the disabled attribute
    //   even when watchmode !== 'none'. A student with an in-progress attempt could click
    //   "Continue Attempt" without watching the required video. Fix: view.php now echoes
    //   disabled on va-continue-attempt-btn whenever watchmode !== 'none', matching the
    //   existing gate on va-start-quiz-btn. The JS enableQuizButton() already enables both
    //   buttons when the watch requirement is fulfilled — no JS changes required.
    //   No DB schema changes. PHP-only: view.php. version.php → 2026040700146.
    if ($oldversion < 2026040700146) {
        upgrade_mod_savepoint(true, 2026040700146, 'aivideoactivity');
    }

    // v1.0.47 — VERSION BUMP: Clean release following full production audit.
    //   Deep 6-location sync check completed and verified: version.php, db/upgrade.php,
    //   BUILD_INFO.json, server/routes.ts zipFile, client/src/lib/pluginConfig.ts, and
    //   public/downloads ZIP all confirmed consistent. No code changes.
    //   No DB schema changes. version.php → 2026040700147.
    if ($oldversion < 2026040700147) {
        upgrade_mod_savepoint(true, 2026040700147, 'aivideoactivity');
    }

    // v1.0.48 — TESTER FEEDBACK FIXES (3 bugs):
    //   FIX-VA-GATE: handleRetake() now shows va-start-section and resets watchRequirementMet
    //     instead of calling handleStartAttempt() directly — watch gate is re-enforced on retake.
    //   FIX-VA-FLASHCARD-SCORE: Flashcard cards now show "Got it!" / "Still learning" buttons;
    //     score++ only fires when the student marks ALL cards as "Got it!".
    //   FIX-VA-TRYAGAIN-LABEL: tryAgain() now resets selectedLabel=null and updates it in the
    //     re-bound click handler so a correct retry after a wrong attempt scores correctly.
    //   AMD triple-match applied: amd/build/videoactivity.js + videoactivity.min.js synced.
    //   No DB schema changes. version.php → 2026040800148.
    if ($oldversion < 2026040800148) {
        upgrade_mod_savepoint(true, 2026040800148, 'aivideoactivity');
    }

    // v1.0.49 - VERSION BUMP: Clean release following full tester-feedback cycle.
    //   All fixes from v1.0.48 (FIX-VA-GATE, FIX-VA-FLASHCARD-SCORE, FIX-VA-TRYAGAIN-LABEL)
    //   confirmed in ZIP and all 6 delivery locations. No code changes. AMD MD5 unchanged: bbe3d2c2c9b279c9de3484b2fde6e7c8.
    //   No DB schema changes. version.php → 2026040800149.
    if ($oldversion < 2026040800149) {
        upgrade_mod_savepoint(true, 2026040800149, 'aivideoactivity');
    }

    // v1.0.50 - BUG FIX (FIX-VA-VOICEOVER-MIME + FIX-VA-CATSORT-NORMALIZE + FIX-VA-FLASHCARD-FORMAT):
    //   FIX-VA-VOICEOVER-MIME: playVoiceover() used audio/mp3 MIME type but server
    //     generates OGG_OPUS audio — caused silent failures on all activity types. Fix: 'audio/ogg'.
    //   FIX-VA-CATSORT-NORMALIZE: Prompt example used numeric indices ({"category":0}) while
    //     description said string names — AI generated "0" strings which server validation filtered.
    //     Fix: example now uses string names; server validation normalises string-numbers; JS
    //     comparator extended with parseInt fallback.
    //   FIX-VA-FLASHCARD-FORMAT: Flashcard prompt changed from Q&A style to term/definition
    //     style (front = key term, back = definition). Both description and example updated.
    //   No DB schema changes. Files: server/routes.ts, amd/src/videoactivity.js, version.php → 202604090050.
    // ⚠ FORMAT BUG: 202604090050 is 12-digit. It is numerically LESS than v1.0.49's
    //   savepoint 2026040800149 (13-digit), so any site on v1.0.49 or earlier sees
    //   ($oldversion < 202604090050) as FALSE and silently skips this block. No DB
    //   changes here, so the skip is harmless. Fixed in v1.0.54 by using 13-digit format.
    if ($oldversion < 202604090050) {
        upgrade_mod_savepoint(true, 202604090050, 'aivideoactivity');
    }

    // v1.0.51 - FIX: Credit cost formula text reworked to be unambiguous. No DB schema changes.
    //   version.php → 202604100051.
    // ⚠ FORMAT BUG: 202604100051 is 12-digit. Same issue as v1.0.50 above — SKIPPED for
    //   sites on v1.0.49 or earlier. No DB changes here, so the skip is harmless.
    // ⚠ MISSING SAVEPOINT: This savepoint was absent from the original v1.0.51 release.
    //   Added here retrospectively. The block has no DB changes so it is safe to run at any point.
    if ($oldversion < 202604100051) {
        upgrade_mod_savepoint(true, 202604100051, 'aivideoactivity');
    }

    // v1.0.52 - NEW FEATURES: "Show video above questions" + "Show chapter timestamp links".
    //   (1) showvideoduringquiz (int, default 0): When enabled, the video player stays visible
    //       above the quiz questions while the student answers. When disabled (default), the
    //       video section hides when the quiz begins.
    //   (2) showchapterstamps (int, default 0): When enabled, each question displays a
    //       clickable timestamp link that jumps the video to the point in the transcript where
    //       the question topic is covered. Timestamps are extracted by the AI from YouTube-style
    //       timestamps in the transcript (e.g. "1:09" → 69 seconds).
    //   DB: adds aivideoactivity.showvideoduringquiz + aivideoactivity.showchapterstamps.
    //   version.php → 202604100052.
    // ⚠ FORMAT BUG: 202604100052 is 12-digit. Same issue as v1.0.50 above — SKIPPED for
    //   sites on v1.0.49 or earlier. THIS BLOCK CONTAINS DB SCHEMA CHANGES (showvideoduringquiz,
    //   showchapterstamps). Sites upgrading from v1.0.49 do NOT get these columns added here;
    //   they are backfilled in the v1.0.54 block below with field_exists() guards.
    if ($oldversion < 202604100052) {
        $table = new xmldb_table('aivideoactivity');

        $field1 = new xmldb_field('showvideoduringquiz', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'timemodified');
        if (!$dbman->field_exists($table, $field1)) {
            $dbman->add_field($table, $field1);
        }

        $field2 = new xmldb_field('showchapterstamps', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'showvideoduringquiz');
        if (!$dbman->field_exists($table, $field2)) {
            $dbman->add_field($table, $field2);
        }

        upgrade_mod_savepoint(true, 202604100052, 'aivideoactivity');
    }

    // v1.0.53 - BUG FIX (FIX-VA-FLASHCARD-DOUBLE + FIX-VA-FLASHCARD-WIDTH). No DB changes.
    //   version.php → 202604100053.
    // ⚠ FORMAT BUG: 202604100053 is 12-digit. Same issue as v1.0.50 above — SKIPPED for
    //   sites on v1.0.49 or earlier. No DB changes here, so the skip is harmless.
    // ⚠ MISSING SAVEPOINT: This savepoint was absent from the original v1.0.53 release.
    //   Added here retrospectively. The block has no DB changes so it is safe to run at any point.
    if ($oldversion < 202604100053) {
        upgrade_mod_savepoint(true, 202604100053, 'aivideoactivity');
    }

    // v1.0.54: FIX-VA-NUMERIC-VERSION — Corrects the 12-digit savepoint format used in
    //   v1.0.50/51/52/53. Sites upgrading from v1.0.49 (last 13-digit: 2026040800149) could
    //   not install v1.0.50-53 because those savepoints are numerically LESS than 2026040800149
    //   — Moodle saw them as "higher version already installed". This block uses 13-digit
    //   savepoint 2026040900054 which IS greater than 2026040800149, unblocking upgrades.
    //   DB BACKFILL: Adds showvideoduringquiz and showchapterstamps for sites that missed
    //   the v1.0.52 DB changes. field_exists() guards make the block idempotent (safe to run
    //   even if the columns already exist from a fresh v1.0.50-53 install).
    //   No AMD changes. version.php → 2026040900054.
    if ($oldversion < 2026040900054) {
        $table = new xmldb_table('aivideoactivity');

        $field1 = new xmldb_field('showvideoduringquiz', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'timemodified');
        if (!$dbman->field_exists($table, $field1)) {
            $dbman->add_field($table, $field1);
        }

        $field2 = new xmldb_field('showchapterstamps', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'showvideoduringquiz');
        if (!$dbman->field_exists($table, $field2)) {
            $dbman->add_field($table, $field2);
        }

        upgrade_mod_savepoint(true, 2026040900054, 'aivideoactivity');
    }

    // v1.0.55 - SERVER FIX (FIX-VA-TIMEOUT): Question generation timed out on complex
    //   mixed-mode or long-transcript requests. Root cause: token budget was
    //   Math.max(4000, count*450) — for ≤8 questions this gave 4000 tokens → callOpenAI
    //   timeout = 4000×8 = 32 s, insufficient for gpt-4o-mini on multi-format prompts.
    //   Fix: raised to Math.max(8000, count*600) in server/routes.ts → 64 s (5-13q),
    //   72 s (15q), 96 s (20q). No DB schema changes. PHP/JS unchanged.
    //   version.php → 2026040900055.
    if ($oldversion < 2026040900055) {
        upgrade_mod_savepoint(true, 2026040900055, 'aivideoactivity');
    }

    // v1.0.56 - BUG FIX (FIX-VA-CORRECT-ANSWER + FIX-VA-FLIPCARD-BTN):
    //   (1) Correct answer always position A in regenerated questions — AI prompts hardcoded
    //   "correctAnswer":0. Fix: server/routes.ts applies shuffleQuestionAnswers() after
    //   parsing regenerated VA instructions; translation route always preserves original
    //   correctAnswer position. (2) Flashcard "Got it!"/"Still learning" buttons replaced
    //   with a single "Next Card" button (or "Next" on the final card) for cleaner UX.
    //   No DB schema changes. AMD: videoactivity.js updated (src=build=min). version.php → 2026041000056.
    if ($oldversion < 2026041000056) {
        upgrade_mod_savepoint(true, 2026041000056, 'aivideoactivity');
    }

    // v1.0.57 - AUTO-TEST CONFIRMATION: All ongoing tester issues (reported at v1.0.47)
    //   confirmed resolved via code audit. (1) Flashcard layout: fixed in v1.0.53
    //   (FIX-VA-FLASHCARD-WIDTH: .va-flashcards-container gains display:block; width:100%;
    //   box-sizing:border-box; min-width:0 — prevents Moodle flex context from shrinking cards
    //   to content width instead of filling the quiz panel). (2) Final score double-counting:
    //   fixed in v1.0.53 (FIX-VA-FLASHCARD-DOUBLE: 'finished' guard flag added to advanceCard()
    //   ensures score++ fires at most once per flashcard question even on rapid button clicks).
    //   No code changes. No DB schema changes. AMD unchanged. version.php → 2026041000057.
    if ($oldversion < 2026041000057) {
        upgrade_mod_savepoint(true, 2026041000057, 'aivideoactivity');
    }

    // v1.0.58 - BUG FIXES (FIX-VA-SCORE-RESTORE + FIX-VA-FLASHCARD-QUESTION):
    //   (1) FIX-VA-SCORE-RESTORE: Score was reset to 0 on 'Continue Attempt' because
    //   localStorage only stored currentQuestionIndex. Now stores JSON {q, s} so both
    //   question index AND score are restored when continuing an in-progress attempt.
    //   Fixes incorrect (lower than expected) final percentage for multi-session attempts.
    //   (2) FIX-VA-FLASHCARD-QUESTION: AI prompt was instructing 'Do NOT phrase fronts as
    //   questions' — changed so flashcard fronts are now short recall questions (e.g. 'What
    //   is a hazard?') matching the 'Question' label shown on the front face of the card.
    //   No DB schema changes. AMD: videoactivity.js updated. version.php → 2026041000058.
    if ($oldversion < 2026041000058) {
        upgrade_mod_savepoint(true, 2026041000058, 'aivideoactivity');
    }

    // v1.0.59 - FIX-FLASHCARD-FEEDBACK: Feedback now appears inline below the last flashcard
    //   instead of clearing the card and showing a blank completion slide.
    //   After the student flips the last card and clicks "Next", the card stays visible
    //   (answer/back face) and feedback + "Next Activity"/"Finish Quiz" button render below it.
    //   The old optCont.innerHTML='' + showInteractiveFeedback() path replaced with an inline
    //   renderLastCardWithFeedback block. CSS: .va-flashcard-completion + .va-flashcard-completion-btn added.
    //   AMD: videoactivity.js updated (src=build=min, MD5: d42799e484fc12fcbebadff5f6f7ad8c).
    //   No DB schema changes. version.php → 2026041100059.
    if ($oldversion < 2026041100059) {
        upgrade_mod_savepoint(true, 2026041100059, 'aivideoactivity');
    }

    // v1.0.60 - FIX-SCORE-RING-SPACING: The score circle on the results screen was too small
    //   relative to the percentage text — "100%" at 42px font sat only ~9px from the inner
    //   ring edge. Fix: enlarged SVG from 160x160 (r=65) to 180x180 (r=75); inner clear area
    //   grows from 118px to 138px diameter, giving ~19px comfortable margin on all sides.
    //   circumference updated from 408 to 471 (2π×75). viewBox, cx, cy updated to match.
    //   CSS: .va-score-ring + .va-score-ring svg widths updated; stroke-dasharray 408→471.
    //   AMD: videoactivity.js updated (src=build=min, MD5: 59ce58a91ff6fc65e9b52e95cbfd5ca5).
    //   No DB schema changes. version.php → 2026041100060.
    if ($oldversion < 2026041100060) {
        upgrade_mod_savepoint(true, 2026041100060, 'aivideoactivity');
    }

    // v1.0.61 - Three matching/ordering bug fixes:
    //   (1) BUG-VA-MATCH-FALSE-POSITIVE: Old dropdown matching (v1.0.59 and earlier) graded
    //       allCorrect=true if every dropdown was filled, regardless of correctness — causing
    //       false "All matched correctly!" when some answers were wrong. The click-card UI
    //       introduced in v1.0.60 fixes this conceptually; v1.0.61 adds an explicit
    //       correctPairings map to the allCorrect check and the retry-timeout deletion loop so
    //       the invariant is self-documenting and protected against future regressions.
    //   (2) BUG-VA-MATCH-FEEDBACK-PARAM: showMatchingFeedback(q, isCorrect) ignored its
    //       isCorrect parameter and always rendered the success message "All matched correctly!"
    //       regardless of the actual grading result. Fixed to use the parameter so incorrect
    //       results render the va-feedback-incorrect style and title.
    //   (3) BUG-VA-ORD-FEEDBACK-STALE: After a wrong ordering attempt the shake animation
    //       cleared after 1500ms but the "Not quite right" feedback text remained permanently
    //       visible while the student re-arranged items. Fixed to clear the feedback alongside
    //       the shake animation so the student sees a clean state while re-ordering; feedback
    //       re-appears fresh on the next "Check Order" click if still incorrect.
    //   No DB schema changes. AMD: videoactivity.js updated. version.php → 2026041500061.
    if ($oldversion < 2026041500061) {
        upgrade_mod_savepoint(true, 2026041500061, 'aivideoactivity');
    }

    // v1.0.62 - FEATURE: Add "Remove" button to each question card in the Edit Questions section.
    //   Teachers can now delete individual questions from a generated activity without
    //   regenerating. A trash-icon "Remove" button appears in the question header; clicking it
    //   prompts for confirmation then splices the question from quizData and rebuilds the edit
    //   form with corrected numbering. Removing the last question is blocked with an alert.
    //   JS-only change: amd/src/videoactivity.js (buildEditForms + bindTeacherEvents).
    //   CSS-only change: styles.css (.va-edit-question-header-right, .va-delete-question-btn).
    //   No DB schema changes. version.php → 2026041600062.
    if ($oldversion < 2026041600062) {
        upgrade_mod_savepoint(true, 2026041600062, 'aivideoactivity');
    }

    // v1.0.65 - BUG FIX (FIX-VA-AUDIO-RESUME + FIX-VA-FLASHCARD-SOUND + FIX-VA-REGEN-CARDSELECT
    //   + FIX-VA-CATSORT-DISTRIBUTION):
    //   (1) FIX-VA-AUDIO-RESUME: All tone-sound functions (playCorrectSound, playIncorrectSound,
    //       playLevelCompleteSound, playTryAgainSound) now call ctx.resume().then() before
    //       scheduling oscillators. Fixes silent audio on all non-MCQ activity types in Moodle
    //       iframes where the browser's autoplay policy suspends the AudioContext.
    //   (2) FIX-VA-FLASHCARD-SOUND: Flashcard completion now calls playCorrectSound() or
    //       playIncorrectSound() — these were entirely missing from the flashcard advanceCard()
    //       completion path.
    //   (3) FIX-VA-REGEN-CARDSELECT: The regen-audio, regen-settings, and regen-instructions
    //       routes were treating cardselect like MCQ (using q.explanations for per-option audio).
    //       cardselect uses q.explanation (singular) and plays audioData[0] in JS — now correctly
    //       goes through the single-explanation audio path like all other non-MCQ types.
    //   (4) FIX-VA-CATSORT-DISTRIBUTION: Strengthened categorysort prompt rules to prevent AI
    //       from assigning all items to the same category. Added CRITICAL DISTRIBUTION RULE
    //       requiring items to be spread across ALL 3 categories with at least 2 items each.
    //   No DB schema changes. Files: amd/src/videoactivity.js, server/routes.ts. version.php → 2026041700065.
    if ($oldversion < 2026041700065) {
        upgrade_mod_savepoint(true, 2026041700065, 'aivideoactivity');
    }

    // v1.0.66 - Three UX improvements:
    //   (1) BUG-VA-FIB-EDIT: Fill-in-the-blank questions previously had no edit form — teachers
    //       could only delete them. buildEditForms() now renders a passage textarea (with ___N___
    //       placeholders), per-blank answer inputs, and a comma-separated distractors field.
    //       saveEdits() reads and validates these fields and reconstructs q.text, q.blanks, and
    //       q.distractors correctly. 'fillinblank' added to typeLabels. CSS added for new fields.
    //   (2) BUG-VA-VIDEO-QUIZ-HIDDEN: When "Show video while answering questions" is enabled, the
    //       video card (va-start-section) was visible but scrolled out of view when the student
    //       read down to the quiz questions below. Fixed by adding position:sticky + top:0 +
    //       z-index:100 to .va-video-during-quiz in styles.css, pinning the video to the top of
    //       the viewport while the student scrolls through quiz questions.
    //   No DB schema changes. Files: amd/src/videoactivity.js (triple-matched), styles.css.
    //   version.php → 2026042000066.
    if ($oldversion < 2026042000066) {
        upgrade_mod_savepoint(true, 2026042000066, 'aivideoactivity');
    }

    // v1.0.67 — No DB schema changes.
    // FIX-VA-AUDIO-TYPE: Voiceover was silently skipped for all non-MCQ question types after
    //   using the 'Generate Audio' button or the settings/instructions regeneration flows.
    //   Root cause: regenerateAudio() and regenerateAudioWithCallback() sent only MCQ fields
    //   (id, question, options, explanations, correctAnswer) — omitting type and explanation.
    //   The server treated all questions as MCQ; for non-MCQ types q.explanations was undefined,
    //   producing an array of empty audio strings. Empty audioData[0] caused playVoiceover() to
    //   skip silently with no error. Fix: both functions now include type, explanation, and all
    //   type-specific fields (cards, pairs, columns, items, categories) in the payload — matching
    //   the full question object format already used by saveEditedQuestions().
    //   AMD changes: videoactivity.js (src + build + min). version.php → 2026042100067.
    if ($oldversion < 2026042100067) {
        upgrade_mod_savepoint(true, 2026042100067, 'aivideoactivity');
    }

    // v1.0.68 — No DB schema changes.
    // FIX-VA-MCQ-AUDIO-INDEX: AI occasionally returns 5+ MCQ options. Server normalisation now
    //   truncates to exactly 4 options (keeping the correct answer), 4 explanations (sliced), and
    //   validates correctAnswer stays in [0,3]. This prevents audio index drift where options[4]
    //   had no corresponding audioData[4] causing the wrong explanation clip to play.
    // FIX-VA-CATSORT-DISTRIBUTION: Category Sort questions where all items belong to the same
    //   category are now rejected server-side. Requires ≥2 distinct categories in the item set.
    //   Prevents students from scoring 100% by placing every item in a single bucket.
    // FIX-VA-MATCH-UX: Matching activity step-indicator bar replaces the old italic instruction
    //   text. Step 1 (tap a term) highlights active; Step 2 (tap its match) becomes active once
    //   a term is selected. Numbered badges on term cards persist until paired. Column headers
    //   now include sub-labels "(tap to select)" / "(tap to match)" for clarity.
    //   AMD changes: videoactivity.js (src + build + min), styles.css. version.php → 2026042100068.
    if ($oldversion < 2026042100068) {
        upgrade_mod_savepoint(true, 2026042100068, 'aivideoactivity');
    }
    // v1.0.69: AMD ENCODING FIX: All non-ASCII characters (em dashes, arrows, box-drawing chars, ellipsis, bullets, emoji, accented Latin) scrubbed from all AMD JS files (amd/src, amd/build, amd/build/*.min.js). Root cause of Moodle primary/secondary navigation menus disappearing site-wide: non-ASCII bytes in any installed plugin's AMD file cause a SyntaxError inside RequireJS's first.js bundle, throwing "No define call for core/first" and aborting the entire AMD module chain. No PHP, DB schema, or functional changes in this release.
    if ($oldversion < 2026042200069) {
        upgrade_mod_savepoint(true, 2026042200069, 'aivideoactivity');
    }

    // v1.0.70: Add scoringmode column.
    // FIX-VA-SCORING-MODE: Adds "First attempt only" scoring option so teachers can configure activities
    // to score based on first answer accuracy rather than retry-until-correct (which always gives 100%).
    // FIX-VA-AUDIO-NODECOUNT: Web Audio API oscillator+gainNode nodes were never disconnected after
    // stopping, causing node accumulation that silently drops all sounds after question 3-4 per session.
    // FIX-VA-EDIT-ICON-PICKER: Card Select edit form now shows a 15-option icon picker per card so
    // teachers can change the section icons. Previously icons disappeared in edit mode with no way to edit.
    if ($oldversion < 2026042300070) {
        $table = new xmldb_table('aivideoactivity');
        $field = new xmldb_field('scoringmode', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'passinggrade');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2026042300070, 'aivideoactivity');
    }

    // v1.0.71: JS/CSS fix only — no DB schema change.
    // FIX-VA-EDIT-REGEN: The "Regenerate Questions" button inside the Edit Questions section had no
    // event binding, so clicking it did nothing. Fixed by adding handleEditRegenerate() and wiring
    // #va-edit-regenerate-btn. Also fixed updateRegenCountDisplay() to update #va-edit-regen-count
    // alongside #va-ready-regen-count, and handleRegenerate() to restore button HTML (with SVG icon)
    // correctly after ajax resolves.
    // FIX-VA-PER-QUESTION-REGEN: Per-question "Regenerate" button added to each question card in
    // buildEditForms(). Clicking it sends only that question to /api/videoactivity-regenerate-
    // instructions and replaces just that entry in quizData, then rebuilds the edit form.
    if ($oldversion < 2026042300071) {
        upgrade_mod_savepoint(true, 2026042300071, 'aivideoactivity');
    }

    // v1.0.72 - AMD-SYNC-FIX: videoactivity.min.js was stale — it was built before the
    //   v1.0.71 per-question regen additions (handleSingleRegenerate, va-regen-question-btn)
    //   were applied, so the minified file shipped to Moodle sites was missing those
    //   features despite the src/build.js being correct. Fix: rebuilt min.js from
    //   current src with terser; scrubbed 2 non-ASCII bytes. src md5 = 4b07ad43.
    //   No PHP, DB schema changes.
    //   version.php -> 2026042300072.
    if ($oldversion < 2026042300072) {
        upgrade_mod_savepoint(true, 2026042300072, 'aivideoactivity');
    }

    // v1.0.73 - FIX-VA-TIMESTAMP-SAVE: "Show chapter timestamp links" buttons were not
    //   appearing for students even when the AI had assigned timestamps to questions.
    //   Root cause: saveQuestionsToDatabase() and saveEditedQuestions() both built an
    //   explicit field allowlist when serialising quizData for the DB — timestamp_seconds
    //   was missing from that allowlist, so it was silently stripped before saving.
    //   Students then loaded the saved questions without timestamp_seconds, causing the
    //   chapter stamp button condition to fail. Fix: added
    //   `if (q.timestamp_seconds != null) qObj.timestamp_seconds = q.timestamp_seconds;`
    //   to both save functions in amd/src/videoactivity.js.
    //   AMD rebuild (src+build+min). No DB schema changes.
    //   version.php -> 2026042400073.
    if ($oldversion < 2026042400073) {
        upgrade_mod_savepoint(true, 2026042400073, 'aivideoactivity');
    }

    // v1.0.74 - AMD-TRIPLE-MATCH: videoactivity.min.js was built with terser in v1.0.73,
    //   producing a different MD5 (670ac8cb) from src/build (90b1489f). The release script
    //   mandates triple-match (src=build=min.js via cp). Rebuilt min.js via cp from src;
    //   all three AMD files now share MD5 90b1489f. No JS behaviour changes.
    //   No PHP or DB schema changes.
    //   version.php → 2026042400074.
    if ($oldversion < 2026042400074) {
        upgrade_mod_savepoint(true, 2026042400074, 'aivideoactivity');
    }

    // v1.0.75 - REMOVE-REGEN-BTN: Removed per-question "Regenerate" button from the
    //   question editor header row. The button (va-regen-question-btn) and its click
    //   handler were removed from videoactivity.js. No DB schema changes.
    //   AMD triple-matched (src=build=min). version.php → 2026042400075.
    if ($oldversion < 2026042400075) {
        upgrade_mod_savepoint(true, 2026042400075, 'aivideoactivity');
    }

    // v1.0.76 - FIX-VA-REGEN-PAYLOAD: "Regenerate Questions" button was failing with
    //   "API request failed" or "Regeneration failed" when voiceover-enabled questions existed.
    //   Root cause: quizData in JS includes audioData (base64 MP3, 100-500KB per explanation).
    //   For 10 questions * 4 explanations that's 4-20MB — blowing past the server's 1MB body
    //   limit, causing a 413 rejection that PHP reported as "API request failed".
    //   Fix A: handleRegenerate() and handleEditRegenerate() now strip audioData from each
    //   question object before JSON-encoding, reducing payload from MB to KB.
    //   Fix B: server/index.ts adds a 10mb body limit for /api/videoactivity-regenerate-instructions
    //   and /api/videoactivity-regenerate-settings as belt-and-suspenders for older installs.
    //   Bonus fix: handleEditRegenerate() success path now re-enables the button (was only
    //   re-enabled on failure, leaving it stuck as "Regenerating..." after a successful call).
    //   AMD triple-matched (src=build=min, MD5 13074c08). No PHP or DB schema changes.
    //   version.php → 2026042400076.
    if ($oldversion < 2026042400076) {
        upgrade_mod_savepoint(true, 2026042400076, 'aivideoactivity');
    }

    // v1.0.77 - FIX-REGEN-GEMINI-FALLBACK: 'Regenerate Questions' was returning "The AI service
    //   is temporarily busy" every time Gemini hit a 429 rate limit. Root cause: generateWithRetry()
    //   retried Gemini twice (8s + 16s), then attempted to import the 'openai' npm package as a
    //   fallback — but that package is not installed in production, so the import threw
    //   "Cannot find package 'openai'" and the entire fallback path crashed, propagating the busy
    //   error to the user. Fix A: replaced the broken OpenAI fallback with gemini-2.0-flash-lite
    //   (same GEMINI_API_KEY, separate model quota, no external package). Fix B: increased
    //   maxRetries 2→3 so the system waits 8s + 16s + 32s (56s total) before triggering the
    //   fallback model. Server-only fix (server/routes.ts generateWithRetry). No JS, PHP, or DB
    //   schema changes. version.php → 2026042400077.
    if ($oldversion < 2026042400077) {
        upgrade_mod_savepoint(true, 2026042400077, 'aivideoactivity');
    }

    // v1.0.78 - NAV FIX: Three BUG_FIXES.md issues resolved:
    //   (1) exit; in view.php replaced with return; -- exit; killed Moodle's JS pipeline
    //       when the plugin was not configured, hiding primary/secondary nav site-wide.
    //   (2) @import url(Google Fonts) removed from styles.css -- @import in a globally-loaded
    //       CSS file is invalid when Moodle concatenates plugin CSS, risking minifier corruption.
    //   (3) settings.php guard strengthened: if ($hassiteconfig && isset($settings)) --
    //       defensive check per BUG_FIXES.md to prevent silent admin-tree corruption.
    //   No DB schema changes. version.php -> 2026042500078.
    if ($oldversion < 2026042500078) {
        upgrade_mod_savepoint(true, 2026042500078, 'aivideoactivity');
    }

    // v1.0.79 - BUG FIX (BUG-REGEN-RETRY + BUG-REGEN-AUDIO-CURL + BUG-REGEN-SETTINGS-CURL
    //   + BUG-REGEN-INSTRUCTIONS-CURL + FIX-VA-VIDEO-QUIZ-SPACING):
    //   (1) handleRegenerate(), handleEditRegenerate(), and handleSingleRegenerate() in
    //       videoactivity.js had zero retry logic — a single transient ok:false busy response
    //       immediately surfaced as an alert. Fixed by refactoring each into a
    //       doRequest(attemptsLeft=2) inner function supporting up to 3 total attempts with
    //       5s delay between retries; busy/rate-limit/temporarily keywords trigger retry
    //       path, only showing error after all attempts exhausted.
    //   (2) All three regenerate PHP cases (regenerateaudio, regeneratewithsettings,
    //       regenerateinstructions) in ajax.php used raw curl_init() bypassing Moodle
    //       proxy/SSL/redirect config. Fixed all three with Moodle \curl class + 3-attempt
    //       retry loop (sleep 5s) for HTTP 429/503 and ok:false busy responses.
    //   (3) va-video-during-quiz sticky card lacked background (content bled through during
    //       scroll) and had insufficient z-index for Moodle Boost — fixed in styles.css:
    //       background: var(--va-bg-card), z-index raised 100->200, padding-bottom 8px.
    //       Added #va-quiz-player { margin-top: 16px } so quiz player is never flush against
    //       the sticky video card.
    //   AMD triple-match: src=build=min MD5 e4495bd63d2004ca98dc2511cc78cf9e.
    //   No DB schema changes. version.php -> 2026042800079.
    if ($oldversion < 2026042800079) {
        upgrade_mod_savepoint(true, 2026042800079, 'aivideoactivity');
    }

    // v1.0.80 — FIX-VA-EDIT-REGEN-CONFIRM:
    //   After a successful "Regenerate Questions" in the edit section, the form silently
    //   rebuilt with no confirmation message — teachers couldn't tell whether the action
    //   had taken effect. Fix: doEditRequest() now updates a new #va-edit-summary element
    //   in view.php with "N questions regenerated successfully!" (auto-clears after 5 s).
    //   No DB schema changes. version.php → 2026042800080.
    if ($oldversion < 2026042800080) {
        upgrade_mod_savepoint(true, 2026042800080, 'aivideoactivity');
    }

    // v1.0.81 — FIX-VA-REGEN-SEQUENTIAL:
    //   handleRegenerate and handleEditRegenerate replaced single large-batch AJAX with sequential
    //   per-question requests. Sending all questions in one payload caused the AI API to respond
    //   "service busy" or time out. Fix: each question regenerated one at a time with a 1.5 s
    //   delay between requests. Button shows "Regenerating question X of N..." progress.
    //   On individual question failure: retries up to 2 times then skips and continues (saves
    //   successfully regenerated questions). AMD triple-match videoactivity.js md5
    //   671bdcc74971a1e318246ed5e0200615. No DB schema changes. version.php → 2026042800081.
    if ($oldversion < 2026042800081) {
        upgrade_mod_savepoint(true, 2026042800081, 'aivideoactivity');
    }

    // v1.0.82 — BUG-REGEN-TIMEOUT:
    //   PHP retry loop (3 attempts × CURLOPT_TIMEOUT 150s + sleep(5) between) could run up to
    //   460 seconds. The JS AJAX timeout is only 90 seconds, so JS always fired .fail() long
    //   before PHP returned a response. Also, many Moodle servers enforce max_execution_time=30-60s
    //   at the web server level, which killed PHP mid-curl producing no JSON output — JS got a
    //   blank response and failed. Fix: removed PHP retry loop (JS already retries 3× per question),
    //   CURLOPT_TIMEOUT reduced to 75s (strictly below the 90s JS timeout), CURLOPT_CONNECTTIMEOUT
    //   added at 10s for fast-fail on DNS/TCP issues, set_time_limit(120) to prevent server killing
    //   PHP before curl completes. Applied to both regeneratewithsettings and regenerateinstructions
    //   actions in ajax.php. PHP-only fix — no JS/AMD changes, no DB schema changes.
    //   version.php → 2026042800082.
    if ($oldversion < 2026042800082) {
        upgrade_mod_savepoint(true, 2026042800082, 'aivideoactivity');
    }

    // v1.0.83 — BUG-CURL-RESETOPT:
    //   Moodle's \curl::post() calls resetopt() internally before applying the post-specific
    //   options (CURLOPT_POST, CURLOPT_POSTFIELDS, CURLOPT_URL). Any options set via setopt()
    //   BEFORE calling post() are silently discarded. This caused the Content-Type: application/json
    //   header and the custom timeouts to never reach the external API — the API received no JSON
    //   content-type, could not parse the body, and rejected every regenerate request. Fix: pass
    //   curl options as the 3rd argument to post() so they are applied via request() AFTER the
    //   internal reset. Applied to both regeneratewithsettings and regenerateinstructions in ajax.php.
    //   PHP-only fix — no JS/AMD changes, no DB schema changes. version.php → 2026042800083.
    if ($oldversion < 2026042800083) {
        upgrade_mod_savepoint(true, 2026042800083, 'aivideoactivity');
    }

    // v1.0.84 — FIX-VA-STICKY-VIDEO-SCROLL:
    //   When "Show Video During Quiz" is enabled the video card is position:sticky top:0.
    //   As the student scrolls down to read answer options the top of the quiz card
    //   (question text + option A) slides behind the sticky video and becomes unreadable.
    //   Fix: after every question renders, JS measures the sticky video card bottom via
    //   getBoundingClientRect and calls scrollBy() to bring the quiz card top back to
    //   8px below the sticky video bottom. CSS scroll-margin-top:360px added to
    //   #va-quiz-player as a secondary guard for any scrollIntoView() callers.
    //   AMD: videoactivity.js (src+build+min). CSS: styles.css.
    //   No PHP changes, no DB schema changes. version.php → 2026042800084.
    if ($oldversion < 2026042800084) {
        upgrade_mod_savepoint(true, 2026042800084, 'aivideoactivity');
    }

    // v1.0.85 — FIX-VA-STICKY-VIDEO-SCROLL (revised):
    //   The v1.0.84 scrollBy() fix was insufficient — the student could still freely
    //   scroll the quiz card behind the sticky video after question load. Root cause:
    //   position:sticky keeps the quiz card in normal page flow so any user scroll can
    //   hide it behind the video. Fix: replace sticky with a viewport-locked flex layout.
    //   On quiz start JS measures the container's top offset and sets
    //   --va-quiz-available-height=(100vh - offset), adds va-quiz-with-video-active to
    //   #va-app. CSS makes #va-app a fixed-height flex column: video card is static
    //   flex-shrink:0 at top, quiz card is flex:1 overflow-y:auto and scrolls
    //   independently inside the panel. Layout removed on quiz end and retake.
    //   AMD: videoactivity.js (src+build+min). CSS: styles.css.
    //   No PHP changes, no DB schema changes. version.php → 2026042800085.
    if ($oldversion < 2026042800085) {
        upgrade_mod_savepoint(true, 2026042800085, 'aivideoactivity');
    }

    // v1.0.86 — FIX-VA-STICKY-VIDEO-SCROLL (final):
    //   Removed position:sticky entirely from .va-video-during-quiz and reverted all
    //   v1.0.84/v1.0.85 complexity. Sticky caused the quiz card to scroll behind the
    //   video on free scroll — no CSS fix can prevent a sibling from sliding behind a
    //   sticky element without changing the layout model. v1.0.84 (scrollBy one-shot)
    //   and v1.0.85 (viewport-locked flex) both caused regressions. Correct fix:
    //   remove sticky entirely; normal document flow — video above, questions below —
    //   is the only approach that never causes overlap.
    //   AMD: videoactivity.js (src+build+min). CSS: styles.css.
    //   No PHP changes, no DB schema changes. version.php → 2026042800086.
    if ($oldversion < 2026042800086) {
        upgrade_mod_savepoint(true, 2026042800086, 'aivideoactivity');
    }

    // v1.0.87 — FIX-VA-STICKY-VIDEO-SCROLL (v1.0.87):
    //   Fullscreen focused quiz mode. When quiz starts body.va-body-quiz-active is added.
    //   CSS makes #va-app position:fixed inset:0 z-index:9999 flex-column. Video is
    //   flex-shrink:0 max-height:48vh, quiz is flex:1 overflow-y:auto. Both always
    //   visible simultaneously. Removed on quiz end and retake. No JS height calc.
    //   AMD: videoactivity.js (src+build+min). CSS: styles.css.
    //   No PHP changes, no DB schema changes. version.php → 2026042800087.
    if ($oldversion < 2026042800087) {
        upgrade_mod_savepoint(true, 2026042800087, 'aivideoactivity');
    }

    // v1.0.88 — FIX-VA-STICKY-VIDEO-SCROLL (v1.0.88):
    //   Compact video player during quiz. Reverted v1.0.87 fullscreen overlay (covered
    //   the Moodle page). Correct fix: stay in normal Moodle page flow. CSS shrinks the
    //   video iframe to 220px during the quiz so the compact video and full quiz card
    //   both fit on-screen simultaneously. JS scrolls the start section into view when
    //   quiz begins. No sticky, no fixed, no overlay.
    //   AMD: videoactivity.js (src+build+min). CSS: styles.css.
    //   No PHP changes, no DB schema changes. version.php → 2026042800088.
    if ($oldversion < 2026042800088) {
        upgrade_mod_savepoint(true, 2026042800088, 'aivideoactivity');
    }

    // v1.0.89 — FIX-VA-REGEN-BATCH:
    //   Replace slow sequential per-question regeneration with a single batch call.
    //   Per-question approach sent N separate AJAX calls with 1.5 s gaps and 10 s
    //   "busy" retry delays, causing "Q{n} busy — retrying…" UI stalls. Batch sends
    //   all questions in one request; server calls Gemini once for the whole set.
    //   PHP curl timeout raised 75 s → 160 s, set_time_limit 120 → 200. JS AJAX
    //   timeout raised 90 s → 180 s. AMD: videoactivity.js (src+build+min) triple-
    //   match MD5: 2ee4d1c0845d82052142e4186cd42370. PHP: ajax.php. No DB changes.
    //   version.php → 2026042900089.
    if ($oldversion < 2026042900089) {
        upgrade_mod_savepoint(true, 2026042900089, 'aivideoactivity');
    }

    // v1.0.90 — FIX-VA-REGEN-ASYNC:
    //   Regenerate Questions stuck on "Retrying…" — the external API's
    //   regenerateinstructions endpoint now starts a background job and returns
    //   {ok:true, jobId:"..."} immediately. All three JS regeneration handlers
    //   (doReadyBatch, doEditBatch, doSingleRequest) only checked response.questions
    //   and retried each time. Fix: pollRegenJob() polls the existing status action
    //   every 2s until complete; all handlers detect jobId first and start polling.
    //   AMD: videoactivity.js (src=build=min) triple-match MD5:
    //   0f984b8f6d0909eacb2f4a2645fb18fa. No PHP changes. No DB schema changes.
    //   version.php → 2026042900090.
    if ($oldversion < 2026042900090) {
        upgrade_mod_savepoint(true, 2026042900090, 'aivideoactivity');
    }

    // v1.0.91 — FIX-VA-TIMESTAMP-STUDENT:
    //   "Jump to X:XX" chapter timestamp buttons were visible to teachers only.
    //   Root cause: loadQuestionsFromDatabase() built explicit return objects for
    //   mcq and cardselect types that did not include timestamp_seconds — so the
    //   field was undefined for students. Teacher preview uses inline-embedded PHP
    //   config which includes timestamp_seconds directly. Fix: added
    //   timestamp_seconds to both mcq and cardselect return objects in JS.
    //   AMD: videoactivity.js (src=build=min) triple-match MD5:
    //   93830af959e4a4c0e11c2a4e5d0bab33. No PHP changes. No DB schema changes.
    //   version.php → 2026042900091.
    if ($oldversion < 2026042900091) {
        upgrade_mod_savepoint(true, 2026042900091, 'aivideoactivity');
    }

    // v1.0.92 - FIX-VA-BADGE-LAYOUT + FIX-VA-TIMESTAMP-OFFSET: Two student-facing fixes.
    //   (1) Badge layout: .va-video-actions had no flex CSS, so the "Attempts Used" badge
    //   appeared below the button on some screens and to the right on others. Fix: added
    //   display:flex; align-items:center; flex-wrap:wrap; gap:16px; margin-top:16px to
    //   .va-video-actions (styles.css). Removed now-redundant margin-top from .va-start-quiz-btn.
    //   (2) Timestamp offset: "Jump to X:XX" was seeking 1 second past the relevant content.
    //   AI transcripts mark when a word is uttered, not when the concept begins. Fix: compute
    //   seekSecs = Math.max(0, stampSecs - 1) in videoactivity.js; button label and seekTo
    //   both use seekSecs. CSS: styles.css. JS: videoactivity.js AMD triple-match MD5:
    //   47a2ed90e0cc24ba41c460ff73fb6299. No PHP changes. No DB schema changes.
    //   version.php → 2026042900092.
    if ($oldversion < 2026042900092) {
        upgrade_mod_savepoint(true, 2026042900092, 'aivideoactivity');
    }

    if ($oldversion < 2026042900093) {
        // FIX-VA-MCQ-SINGLE-ANSWER + FIX-VA-TIMESTAMP-ACCURATE + FIX-VA-SIMPLE-QUESTIONS:
        // Three server-side AI prompt improvements to the question generation engine.
        // (1) MCQ questions now always have exactly 1 correct answer — removed the DILEMMA/
        //     TRADE-OFF framing from the system prompt that was causing the AI to produce
        //     multiple plausible-correct options, confusing students.
        // (2) Timestamp links now point to where the relevant topic BEGINS to be discussed
        //     in the video (not "1-2 seconds before the answer is stated"), so Jump-to clicks
        //     land at the correct segment start.
        // (3) Question style simplified: COMPETING APPROACHES / HIDDEN CONSEQUENCES analysis
        //     replaced with KEY FACTS / KEY STEPS / KEY CONCEPTS — questions ask directly
        //     about what the video says in plain language.
        // Server-only fix (routes.ts). No PHP, no AMD, no DB schema changes.
        upgrade_mod_savepoint(true, 2026042900093, 'aivideoactivity');
    }

    if ($oldversion < 2026042900094) {
        // FIX-VA-REGEN-TIMESTAMP + FIX-REGEN-FALLBACK (v1.0.94):
        // Two fixes restoring Jump-to timestamp links after batch question regeneration.
        // (1) FIX-VA-REGEN-TIMESTAMP: both Ready-screen and Edit-Questions batch regen paths
        //     (allReadyQuestions / allEditQuestions) omitted timestamp_seconds — server
        //     preservation branch never ran, silently dropping Jump-to links after bulk regen.
        // (2) FIX-REGEN-FALLBACK: generateWithRetry() fallback was gemini-2.0-flash-lite which
        //     returned HTTP 404 — replaced with gemini-1.5-flash → gemini-1.5-flash-8b chain.
        // AMD: videoactivity.js (src=build=min) MD5: ce00fa7fa965bb58bf435a7415e9f9c8.
        // Server: routes.ts. No PHP, no DB schema changes.
        upgrade_mod_savepoint(true, 2026042900094, 'aivideoactivity');
    }

    if ($oldversion < 2026043000096) {
        // FIX-VA-REGEN-PROMPT (v1.0.96): "Regenerate Questions" button had two bugs.
        // (1) The server prompt hardcoded Bloom's Taxonomy Level 3 + "50% SCENARIO-BASED
        //     questions", forcing the AI to produce complex workplace scenario questions
        //     completely different from the simple, video-aligned originals. Fixed: prompt
        //     now explicitly instructs the AI to match the style of the original questions
        //     — direct, factual, no scenario framing, no fictional characters.
        // (2) Prompt showed only ONE placeholder object in the return structure template
        //     without specifying how many to produce. Gemini returned exactly 1 question.
        //     Fixed: prompt now says "Return EXACTLY N questions" (N = questions.length)
        //     and "The array MUST contain exactly N objects". Server safety net added:
        //     if AI still returns fewer questions, pad with originals so no questions lost.
        // Server-only fix (routes.ts). No PHP, no AMD, no DB schema changes.
        upgrade_mod_savepoint(true, 2026043000096, 'aivideoactivity');
    }

    // v1.0.97 - VERSION BUMP: Clean release. AMD triple-match verified:
    //   videoactivity.js (src=build=min) MD5: ce00fa7fa965bb58bf435a7415e9f9c8.
    //   All 6 delivery locations confirmed in sync. No code changes. No DB schema changes.
    //   version.php → 2026043000097.
    if ($oldversion < 2026043000097) {
        upgrade_mod_savepoint(true, 2026043000097, 'aivideoactivity');
    }

    if ($oldversion < 2026050100098) {
        // FIX-VA-REGEN-TYPEFIELDS (v1.0.98): "Regenerate Questions" only preserved Q1
        // properly; Q2-Q5 lost their type-specific fields — matching questions lost their
        // pairs, T/F (cardselect) lost their explanation, sort/columnsort/categorysort lost
        // items+categories+columns, fill-in-blank lost its blanks, ordering lost
        // correctOrder.
        // Root cause: the JS payload built by handleRegenerate() and handleEditRegenerate()
        // forwarded only MCQ-shaped fields (question, options, explanations, correctIndex,
        // mappingTopic, mappingCriteria, timestamp_seconds) and stripped every type-specific
        // field. The server's non-MCQ preserve branch did `regeneratedQuestions[i] = originalQ`
        // using the stripped payload — silently destroying every non-MCQ structure. Q1 looked
        // fine because the AI is biased to start with MCQ.
        // Fix: Object.assign({}, q0, {...overrides}) so pairs/cards/items/categories/columns/
        // correctOrder/explanation/blanks all survive the round-trip; audioData stripped to
        // keep payload small (server regenerates audio).
        // AMD triple-match verified: videoactivity.js (src=build=min) MD5
        // c57a4402d8a9eeff39251c1755bd1545. JS-only fix; no DB schema, no server changes.
        // version.php → 2026050100098.
        upgrade_mod_savepoint(true, 2026050100098, 'aivideoactivity');
    }

    if ($oldversion < 2026050100099) {
        // FIX-VA-REGEN-GROUNDING (v1.0.99): "Regenerate Questions" produced generic content
        // that drifted away from the actual video. Root cause: the regenerate-instructions
        // endpoint never received the video transcript — the prompt only contained the OLD
        // questions, so Gemini had nothing to ground new questions in and invented topics.
        // Fix is in 2 layers:
        //   (1) plugin's regenerateinstructions action in ajax.php now loads
        //       $videoactivity->transcripttext from the activity row and forwards it as
        //       `transcript` in the payload to /api/videoactivity-regenerate-instructions
        //       (defensive 200k-char trim to match the generate-path ceiling).
        //   (2) SaaS endpoint accepts the new transcript field and injects it into the
        //       Gemini prompt as the AUTHORITATIVE source-of-truth with explicit
        //       "MUST be answerable from this transcript and MUST NOT introduce facts not
        //       present here" wording.
        // Regenerated questions now stay tightly grounded in the same source material the
        // original generate call used. Backwards compatible — SaaS still works for legacy
        // plugin clients (pre-1.0.99) that don't send a transcript, just degraded to old
        // behaviour. No DB schema changes — code-only release; this savepoint is a no-op
        // marker for Moodle's upgrade tracker.
        // version.php → 2026050100099.
        upgrade_mod_savepoint(true, 2026050100099, 'aivideoactivity');
    }

    if ($oldversion < 2026050100100) {
        // FIX-VA-REGEN-NONMCQ (v1.0.100): "Regenerate Questions" only regenerated Q1 (MCQ)
        // and silently kept Q2+ unchanged in mixed-format activities. Two interlocking bugs:
        //   (1) The regenerate-instructions Gemini prompt only described the MCQ JSON shape,
        //       with no template for matching/ordering/cardselect/columnsort/categorysort/
        //       flashcards/truefalseswipe/fillinblank, so the AI returned MCQ-shaped objects
        //       for every question regardless of its original type.
        //   (2) The server then ran an unconditional gate
        //       `if (originalQ.type !== "mcq") { regeneratedQuestions[i] = originalQ; continue; }`
        //       which silently DISCARDED every non-MCQ regeneration and substituted the
        //       original question. Net effect: only Q1 (typically MCQ) actually regenerated;
        //       Q2-Q5 in mixed activities silently kept their old wording.
        // Fix is server-side only (server/routes.ts), in 2 layers:
        //   (a) Prompt rewritten with PER-TYPE FIELD REQUIREMENTS section explaining the
        //       exact JSON shape Gemini must preserve for each of the 9 types, instructing
        //       it to keep the "type" field, structural fields (option/pair/item/blank
        //       counts), indices (correctAnswer/correctIndex), and boolean correct flags
        //       unchanged — rewording only natural-language text.
        //   (b) Server validation logic replaced the blanket isNonMcq gate with a per-type
        //       validateRegenerated() validator that checks each type's required shape
        //       (count of pairs/items/cards/blanks/statements matches original, required
        //       fields present and well-typed). Accepts the AI's regenerated text when the
        //       shape is valid; falls back to original ONLY for the specific question whose
        //       shape is broken. Plus type-specific post-processing forces structural fields
        //       back from original where the AI cannot be trusted (cardselect icons,
        //       columnsort item.column, truefalseswipe statement.correct, MCQ correctAnswer).
        // Plugin code unchanged from v1.0.99 — this is a SaaS-side fix; the version bump
        // is purely for traceability. No DB schema changes — savepoint is a no-op marker.
        // Backwards compatible: works with any plugin version >= v1.0.99 that forwards the
        // transcript. version.php → 2026050100100.
        upgrade_mod_savepoint(true, 2026050100100, 'aivideoactivity');
    }

    if ($oldversion < 2026050100101) {
        // FIX-VA-REGEN-PERTYPE-CALL (v1.0.101): "Regenerate Questions" still appeared to
        // only regenerate Q1 in mixed-format activities even after v1.0.100's per-type
        // validation fix. Deployment logs confirmed the v1.0.100 single-batched-Gemini-call
        // approach was returning structurally-valid but mostly-identical content for
        // non-MCQ types — mixed-type batched generation is unreliable because the AI
        // cannot consistently honour 9 different schemas in one response.
        // Fix (SaaS-only, server/routes.ts): replaced the single batched call with ONE
        // Gemini call PER QUESTION using a TYPE-SPECIFIC prompt. Each call sees only one
        // canonical schema, so the AI cannot confuse types or "average" across them. New
        // typeSchemas dictionary holds the per-type prompt fragments. New
        // buildSingleQuestionPrompt() composes the per-question prompt from the original
        // question + the matching schema. New parseSingleQuestion() tolerates object,
        // array-of-one, or {questions:[...]} response shapes. Sequential loop respects
        // Gemini rate limits; generateWithRetry handles 429 back-offs. On per-question
        // failure (parse error, exception) the loop falls back to the original for THAT
        // question only — other questions keep regenerating. Existing v1.0.100
        // validateRegenerated() and per-type post-processing (icon preservation, column
        // assignment, T/F booleans, MCQ correctAnswer index) all still run on each
        // per-question result.
        // Per-question timing: ~5s × 5 questions = ~25s, well within the 180s JS timeout
        // and 160s curl timeout in ajax.php.
        // Plugin code unchanged from v1.0.100 — this is a SaaS-side fix; the version bump
        // is purely for traceability. No DB schema changes — savepoint is a no-op marker.
        // No AMD changes (md5 c57a4402d8a9eeff39251c1755bd1545 unchanged). Backwards
        // compatible: works with any plugin version >= v1.0.99 that forwards the
        // transcript. version.php → 2026050100101.
        upgrade_mod_savepoint(true, 2026050100101, 'aivideoactivity');
    }

    if ($oldversion < 2026050100102) {
        // FIX-VA-REGEN-ECHO-GUARD (v1.0.102): "Regenerate Questions" appeared to leave
        // Ordering questions unchanged even after v1.0.101's per-question per-type Gemini
        // calls. Production logs confirmed the failure mode: Q4 (ordering) returned in
        // 1.2s vs Q3 (matching) at 10s — Gemini-Flash 2.0 in JSON mode echoes the original
        // JSON near-verbatim when items are short procedural steps (3-7 words like
        // "Identify the hazard") and the full original is included in the prompt. The
        // structurally-valid echo passed v1.0.100's validateRegenerated() so the server
        // accepted the regenerated content despite it being identical wording.
        // Fix (SaaS-only, server/routes.ts): post-call echo-rate detection. For short-item
        // types only (ordering, matching, columnsort, categorysort), we extract the
        // rewordable items from both original and regenerated, normalise (lowercase, strip
        // punctuation), and compute the fraction of items character-identical at each
        // index. If echo-rate >= 50%, retry ONCE with a stronger anti-echo prompt (built
        // by buildAntiEchoBlock) that includes a per-type concrete "DO NOT return identical
        // text" example and tells the AI its previous attempt failed. After the second
        // pass, whichever has the lower echo-rate wins. Echo-rates are logged on every
        // call for future debugging visibility.
        // Long-text types (mcq, cardselect, flashcards, truefalseswipe, fillinblank) skip
        // the echo guard entirely — they have plenty of free text and rarely echo.
        // Plugin code unchanged from v1.0.101 — this is a SaaS-side fix; the version bump
        // is purely for traceability. No DB schema changes — savepoint is a no-op marker.
        // No AMD changes. Backwards compatible: works with any plugin version >= v1.0.99
        // that forwards the transcript. version.php → 2026050100102.
        upgrade_mod_savepoint(true, 2026050100102, 'aivideoactivity');
    }

    if ($oldversion < 2026050100103) {
        // FIX-VA-VOICEOVER-DIAG (v1.0.103): New activities created with voiceover ON
        // play silently for both teacher preview and student attempt. Server pipeline
        // (generate → processVideoActivity → generateTTSBuffer → status) and plugin
        // pipeline (savequestions stores audioData in dedicated `audiodata` TEXT column
        // and inside questiondata JSON; getquestions overrides with the dedicated
        // column) all looked correct on inspection. The user-supplied student console
        // showed [VA] Loaded questions: 5 followed by [VA] Answer saved successfully
        // entries but ZERO [VA] Voiceover playback failed / error lines, proving
        // playVoiceover() was never reached and q.audioData[i] was falsy at every
        // callsite. To pinpoint where audioData is being lost, this build adds five
        // diagnostic checkpoints in videoactivity.js (no behaviour changes):
        //   1. After /api/videoactivity-status returns completed: [VA-DIAG] gen Q{n}
        //   2. Immediately before savequestions AJAX:           [VA-DIAG] save Q{n}
        //   3. Immediately after getquestions AJAX:             [VA-DIAG] load Q{n}
        //   4. At every playVoiceover guard (5 callsites):      [VA-DIAG] guard ...
        //   5. On every entry to playVoiceover():               [VA-DIAG] playVoiceover called
        // No DB schema changes — savepoint is a no-op marker. AMD-only changes (src,
        // build, build/min). version.php → 2026050100103.
        upgrade_mod_savepoint(true, 2026050100103, 'aivideoactivity');
    }

    if ($oldversion < 2026050100104) {
        // FIX-VA-AUEN (v1.0.104): Australian English audit + timestamp pipeline
        // verification. Three user-facing American spellings replaced with the
        // Australian form:
        //   1. lang/en/aivideoactivity.php $string['score_needs_improvement'] =
        //      'Keep practising!' (was 'Keep practicing!') — results-screen
        //      subtitle for low-score tier.
        //   2. amd/src/videoactivity.js Warehousing subindustry option
        //      'Order Fulfilment' (was 'Order Fulfillment') in
        //      INDUSTRY_SUBCATEGORIES used by the activity-creation industry
        //      picker.
        //   3. amd/src/videoactivity.js showResults() needs-work tier title
        //      'Keep Practising!' (was 'Keep Practicing!') in the dynamic
        //      results-card animated header.
        // Other regex matches (color, behavior, center, program*) verified as
        // JS API names (e.g. scrollIntoView({behavior:'smooth'})), CSS property
        // names, and component classnames — must remain American.
        // Timestamp pipeline audited end-to-end and confirmed working: DB column
        // showchapterstamps present (install.xml + earlier upgrade backfill at
        // lines 277, 312); JS save/load/regen paths all preserve timestamp_seconds
        // (FIX-VA-TIMESTAMP-SAVE v1.0.73, FIX-VA-REGEN-TIMESTAMP v1.0.94); display
        // logic at amd/src/videoactivity.js:2418 uses FIX-VA-TIMESTAMP-OFFSET to
        // subtract 1s for natural lead-in (v1.0.92); audio mode suppresses the
        // jump button. Server-side preserves timestamp_seconds across both
        // regenerate-settings and regenerate-instructions endpoints. AI prompt
        // instructs Gemini to find the transcript line where the topic BEGINS.
        // No stale or orphan JS files in plugin tree (only amd/src + amd/build).
        // No DB schema changes — savepoint is a no-op marker. AMD-only rebuild
        // (src, build, build/min triple-match e781d45526bd6186bef0976c6136b257).
        // version.php → 2026050100104.
        upgrade_mod_savepoint(true, 2026050100104, 'aivideoactivity');
    }

    if ($oldversion < 2026050200105) {
        // FIX-VA-EDITOR-FLASHCARD-TFS + FIX-VA-CARDSELECT-VOICEOVER-WRONG +
        // FIX-VA-CATSORT-EDIT-NORMALIZE (v1.0.105): three confirmed bugs in
        // amd/src/videoactivity.js. (1) Edit Questions screen had no per-type
        // render or save branch for Flashcards or True/False Swipe — both types
        // fell through to the generic else that only read/wrote q.question, so
        // the actual card front/back text and the per-statement T/F answers
        // and explanations were effectively read-only after AI generation. Now
        // both types have a full editor (per-card front+back inputs, per-
        // statement text + True/False radio + explanation) and matching save
        // blocks that validate and persist the type-specific structure. The
        // top-level "question text cannot be empty" validator now permits a
        // blank stem for flashcards because the AI schema for that type has no
        // "question" field. (2) checkCardSelectAnswer() always played
        // q.audioData[0] on both correct AND incorrect clicks, but
        // q.audioData[0] holds the TTS audio for the CORRECT answer — on a
        // wrong click the audio audibly handed the answer to the student
        // before retry. Now gated on isCorrect OR scoringMode === 1
        // (first-attempt mode where there is no retry). (3) Category Sort
        // editor corrupted every item into category 0 on Save: dropdown
        // pre-select used strict numeric equality but the AI emits
        // item.category as the string name ("Mammals"), so the dropdown
        // silently fell back to <option value=0> for every item. Fix accepts
        // numeric index, string-of-number, or canonical category name on the
        // render side, and persists the canonical string category name on
        // save so the round-trip back into the editor is stable and the
        // regenerate-instructions prompt sees the same shape it expects.
        // No DB schema changes — savepoint is a no-op marker. AMD-only rebuild
        // (src, build, build/min). version.php → 2026050200105.
        upgrade_mod_savepoint(true, 2026050200105, 'aivideoactivity');
    }

    // v1.0.115 - SYNC-VA-VERSION-DRIFT: Bump-only release that closes 3 sync
    //   drift issues caught in audit. (1) BUILD_INFO.json was stale at "1.0.113"
    //   while version.php numeric had already advanced to 2026050300114 — bumped
    //   BUILD_INFO to 1.0.115 + numeric 2026050300115 with fresh build_timestamp.
    //   (2) $plugin->release string was "1.0.113" while $plugin->version had
    //   advanced to 2026050300114 — corrected to "1.0.115" so the Quick Links
    //   block "Update Available" badge displays the right number. (3) upgrade.php
    //   had no savepoint for either 114 or 115 — this single 2026050300115
    //   savepoint covers both gaps as a no-op marker. (4) Stale v1.0.113 + v1.0.114
    //   ZIPs removed; v1.0.115 ZIP rebuilt. (5) PluginAuditPage.tsx audit row
    //   updated. AMD JS audit ran clean: videoactivity.js triple-match holds
    //   (src=build=build.min identical MD5) and define([jquery], function($) {})
    //   format verified — no ES module syntax. No DB schema changes. AMD-only
    //   rebuild not required (no JS changes this release). version.php →
    //   2026050300115.
    if ($oldversion < 2026050300115) {
        upgrade_mod_savepoint(true, 2026050300115, 'aivideoactivity');
    }

    // v1.0.116 - FIX-VA-CARDSELECT-PERCARD-EDITOR: real fix for mum's repeated
    //   report that Card Select wrong-card clicks always show a generic-looking
    //   explanation instead of the specific one for the picked card. Root cause
    //   was a two-part editor gap that's been silently corrupting Card Select
    //   questions since v1.0.110: (a) renderEditQuestions cardselect branch
    //   never rendered UI for q.explanations[] — only one overall textarea
    //   bound to q.explanation (singular). (b) saveEdits cardselect branch
    //   DROPPED q.explanations[] entirely on every save, so the moment mum
    //   clicked Save on any cardselect question the per-card array was wiped
    //   from the DB and every wrong-click fell back to q.explanation forever.
    //   v1.0.116 fixes both halves of the editor + adds an empty-slot fallback
    //   in the wrong-click handler. AMD triple-match enforced. No DB schema
    //   changes — savepoint is a no-op marker. version.php → 2026050300116.
    if ($oldversion < 2026050300116) {
        upgrade_mod_savepoint(true, 2026050300116, 'aivideoactivity');
    }

    // v1.0.117 - FIX-VA-CARDSELECT-INITIAL-GEN + FIX-VA-CARDSELECT-RUNTIME-FALLBACK:
    //   real fix for mum's STILL BROKEN cardselect on freshly created quizzes. Console
    //   log on a brand-new v1.0.116 quiz proved Q2 cardselect arrived at the player
    //   with audioData=array len=1 hasPerCardText=false — meaning even fresh AI-generated
    //   cardselect questions were landing in mum's DB without per-card data. Root cause
    //   was on the SERVER side: the initial generation path in processVideoActivity
    //   treated cardselect as "non-MCQ" and only generated ONE audio clip from
    //   q.explanation (singular), discarding the AI's explanations[4] array. v1.0.117
    //   fixes the server audio loop to mirror the regenerate-audio path (per-card branch
    //   + auto-upgrade-from-cards when AI omits explanations[]). Plus a defensive
    //   client-side runtime fallback in checkCardSelectAnswer that synthesises per-card
    //   "Incorrect. {label} isn't quite the right fit..." text from the chosen card's
    //   label even on legacy questions whose data has never been healed — guaranteeing
    //   the student NEVER sees the answer-revealing q.explanation (singular, which IS
    //   the correct-card explanation) on a wrong click. AMD triple-match enforced
    //   (src + build + min identical MD5 8c714008b8103555e3ed28e9b8f212e4). No DB
    //   schema changes — savepoint is a no-op marker. version.php → 2026050300117.
    if ($oldversion < 2026050300117) {
        upgrade_mod_savepoint(true, 2026050300117, 'aivideoactivity');
    }

    // v1.0.119 - FIX-VA-CARDSELECT-EXPLANATION-ORDER + FIX-VA-CARD-LAYOUT:
    //   Bug 1: AI sometimes generates cardselect explanations[] where the
    //   "Correct." entry is at index 0 regardless of what correctIndex is —
    //   e.g. correctIndex=2 but explanations[0]="Correct. ..." causing students
    //   to see "Correct." feedback when clicking the wrong card and "Incorrect."
    //   when clicking the right one. Fix: new fixCardSelectExplanationOrder()
    //   helper in server/routes.ts mirrors fixExplanationOrder() for MCQ — it
    //   swaps explanations so explanations[correctIndex] always starts with
    //   "Correct." and sanitises any other slots that accidentally start with
    //   "Correct." Called at four sites: processedQuestions map (initial gen),
    //   both voiceover auto-upgrade blocks (regenerate-audio + initial-gen),
    //   and the regenerate-instructions cardselect post-processing block.
    //   Bug 2: .va-card-option had justify-content: center in styles.css,
    //   causing card content to float at different vertical positions when card
    //   descriptions vary in length. Fixed to justify-content: flex-start with
    //   padding-top: 24px so all cards top-align their icon/label/description.
    //   No DB schema changes — savepoint is a no-op marker.
    //   version.php → 2026050300119.
    if ($oldversion < 2026050300119) {
        upgrade_mod_savepoint(true, 2026050300119, 'aivideoactivity');
    }

    // v1.0.120 - VERSION-BUMP-ONLY: re-release of v1.0.119 fixes under a new
    //   version number because the live site was already running v1.0.119 before
    //   the v1.0.119 fixes were deployed. Contents identical to v1.0.119:
    //   FIX-VA-CARDSELECT-EXPLANATION-ORDER + FIX-VA-CARD-LAYOUT + diag.php.
    //   No DB schema changes — savepoint is a no-op marker.
    //   version.php → 2026050300120.
    if ($oldversion < 2026050300120) {
        upgrade_mod_savepoint(true, 2026050300120, 'aivideoactivity');
    }

    // v1.0.121 — FIX-VA-DIAG-TRANSCRIPT-FIELD + PROMPT-MCQ-MUTUAL-EXCLUSIVITY +
    //   PROMPT-CARDSELECT-DISTINCT + PROMPT-TIMESTAMP-SPREAD.
    //   (1) diag.php: $activity->transcript → $activity->transcripttext (correct DB column name).
    //   (2) AI prompt: added mandatory mutual exclusivity test to MCQ — only ONE option unambiguously correct.
    //   (3) AI prompt: added distinct-cards rule to Card Select — each card must describe a different concept.
    //   (4) AI prompt: added spread rule to timestamp — each question must reference a different video segment.
    //   No DB schema changes — savepoint is a no-op marker.
    //   version.php → 2026050400121.
    if ($oldversion < 2026050400121) {
        upgrade_mod_savepoint(true, 2026050400121, 'aivideoactivity');
    }

    // v1.0.122 — FIX-VA-CARDSELECT-FIELD-LAYOUT.
    //   Card Select editor: Label and Description inputs were bare inline-block <input> elements
    //   with no wrapping structure or CSS, so they sat side-by-side in a cramped truncated row.
    //   Each field now lives in its own .va-edit-card-field-row div with a labelled header, and
    //   new CSS in styles.css makes them full-width and clearly distinguishable.
    //   AMD rebuild: videoactivity.js src+build+min triple-match.
    //   No DB schema changes — savepoint is a no-op marker.
    //   version.php → 2026050500122.
    if ($oldversion < 2026050500122) {
        upgrade_mod_savepoint(true, 2026050500122, 'aivideoactivity');
    }

    // v1.0.123 — FIX-VA-VIDEO-QUIZ-OVERLAP + FIX-VA-CARDSELECT-AUDIO-ORDER.
    //   No DB schema changes — savepoint is a no-op marker.
    //   version.php → 2026050700123.
    if ($oldversion < 2026050700123) {
        upgrade_mod_savepoint(true, 2026050700123, 'aivideoactivity');
    }

    // v1.0.124 — FIX-VA-CARDSELECT-STALE-AUDIO + FIX-VA-CARDSELECT-VOICEOVER-PREFIX.
    //   topUpMissingClipAndPlay now guards against playing stale audio after the student
    //   has navigated away from the question. ensureVoiceoverPrefix() guarantees the
    //   Chirp TTS text always starts with "Correct." or "Incorrect.".
    //   AMD-only rebuild (src+build+min triple-match). No DB schema changes.
    //   version.php → 2026051100124.
    if ($oldversion < 2026051100124) {
        upgrade_mod_savepoint(true, 2026051100124, 'aivideoactivity');
    }

    // v1.0.125 — FIX-VA-CARDSELECT-CLIENTSIDE-ALIGN.
    //   Root-fix for "correct card picked but voiceover says Incorrect." Client-side
    //   alignment pass added to the cardselect branch of the quizData map (before shuffle)
    //   to fix misaligned explanations[]/audioData[] for legacy questions already in DBs
    //   that predate the server-side fixCardSelectExplanationOrder() fix.
    //   AMD-only rebuild (src+build+min triple-match). No DB schema changes.
    //   version.php → 2026051100125.
    if ($oldversion < 2026051100125) {
        upgrade_mod_savepoint(true, 2026051100125, 'aivideoactivity');
    }

    // v1.0.126: FIX-CURL-BATCH — ajax.php switched all raw curl_init() calls to Moodle \curl
    //   wrapper. No DB schema changes.
    if ($oldversion < 2026051200126) {
        upgrade_mod_savepoint(true, 2026051200126, 'aivideoactivity');
    }

    // v1.0.127: FIX-VA-CARDSELECT-AUDIO-LENGTH-ALIGN — dual-layer fix (PHP server-side + JS
    //   client-side Pass 1.5) for remaining "correct card says Incorrect." voiceover bug on
    //   cardselect questions where server previously fixed explanation TEXT order but NOT audio
    //   order. No DB schema changes.
    if ($oldversion < 2026051300127) {
        upgrade_mod_savepoint(true, 2026051300127, 'aivideoactivity');
    }

    // v1.0.128: FIX-VA-CARDSELECT-AUDIO-NOEXPL-ALIGN — removed && origExplanations gate from
    //   JS Pass 1.5 audio-length alignment. Questions with per-card audio but no per-card
    //   explanations bypassed Pass 1.5 entirely, leaving audio[0] as the "Correct." clip
    //   regardless of correctIndex. No DB schema changes.
    if ($oldversion < 2026051300128) {
        upgrade_mod_savepoint(true, 2026051300128, 'aivideoactivity');
    }

    // v1.0.130: FIX-VA-NULL-CORRECTANSWER — MCQ shuffle silently defaulted
    //   newCorrectIndex to 0 when correctAnswer was null (not undefined) in
    //   questiondata, making the first displayed option always appear correct
    //   after shuffling. Root cause: `q.correctAnswer !== undefined` passes for
    //   null values, so parseInt(null,10)=NaN and the origIndex===correctIdx
    //   match never fired. Fix: use != null (covers both null and undefined),
    //   compute correctIdx once outside the loop, clamp NaN/OOB to 0.
    //   Same null-guard applied to checkAnswer(), saveQuestionsToDatabase(),
    //   saveEditedQuestions(), and both audio-regen API payload builders so
    //   null/undefined never reaches the DB or the correctness check.
    //   No DB schema changes — savepoint is a no-op marker.
    if ($oldversion < 2026051900131) {
        // FIX-VA-CARDSELECT-PREWARM (v1.0.131): pre-warm missing per-card audio clips
        // the moment a card-select question is displayed. Background Chirp API calls are
        // fired for every card whose audioData slot is empty, using the same explanation
        // text and voice settings as the on-demand topUpMissingClipAndPlay() fallback.
        // Results are cached in q.audioData[] so checkCardSelectAnswer() plays instantly
        // instead of waiting ~5 s for a live API call. No schema changes.
        upgrade_mod_savepoint(true, 2026051900131, 'aivideoactivity');
    }

    if ($oldversion < 2026072300224) {
        // FIX-API-DOMAIN: Updated all API endpoint URLs from lms-labs.com to lms-labs.com.
        // lms-labs.com has no DNS resolution from Moodle server side; lms-labs.com is the
        // correct working domain. All ajax.php, api_client, unlock_verifier, lib.php calls updated.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072300224, 'aivideoactivity');
    }

    if ($oldversion < 2026072300225) {
        // FIX-API-DOMAIN: Reverted API endpoint to lms-labs.com (correct domain).
        // essaygraderai.app was the original single-plugin domain; lms-labs.com is correct.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) { opcache_invalidate($_full, true); }
            }
        } elseif (function_exists('opcache_reset')) { opcache_reset(); }
        upgrade_mod_savepoint(true, 2026072300225, 'aivideoactivity');
    }

    if ($oldversion < 2026072300226) {
        // Domain update: lms-labs.com → lms-labs.com
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) { opcache_invalidate($_full, true); }
            }
        } elseif (function_exists('opcache_reset')) { opcache_reset(); }
        upgrade_mod_savepoint(true, 2026072300226, 'aivideoactivity');
    }

    return true;
}