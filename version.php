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
 * AI Video Activity v1.0.117 - FIX-VA-CARDSELECT-INITIAL-GEN + FIX-VA-CARDSELECT-RUNTIME-FALLBACK
 *                              real fix for mum's STILL BROKEN cardselect on freshly
 *                              created quizzes. Console log proved Q2 cardselect arrived
 *                              with audioData=array len=1 hasPerCardText=false even on
 *                              brand-new AI generation under v1.0.116. Root cause: the
 *                              initial generation path in processVideoActivity was
 *                              treating cardselect as "non-MCQ" and only generating ONE
 *                              audio clip from q.explanation (singular) — completely
 *                              ignoring q.explanations[4] when the AI returned it.
 *                              v1.0.117 fixes the initial-generation audio loop to mirror
 *                              the regenerate-audio route (per-card branch + auto-upgrade
 *                              from cards when AI omits explanations[]) so every fresh
 *                              cardselect question now lands in mum's DB with proper
 *                              explanations[4]+audioData[4]. Plus a defensive runtime
 *                              fallback in checkCardSelectAnswer that synthesises per-card
 *                              "Incorrect. {label} isn't quite the right fit..." text
 *                              from the chosen card's label even on legacy questions
 *                              with no explanations[] at all — guaranteeing the student
 *                              NEVER sees the answer-revealing q.explanation on a wrong
 *                              click, regardless of how broken the question's data is.
 *
 * v1.0.117: TWO BUG FIXES (one root-cause server fix + one defensive client fix):
 *
 *   FIX-VA-CARDSELECT-INITIAL-GEN (server/routes.ts processVideoActivity audio loop,
 *     line 30493+): The initial generation path treated cardselect as "non-MCQ" with
 *     `q.type === 'mcq' ? q.explanations : [q.explanation]` — meaning every NEW
 *     cardselect question got a single TTS clip from q.explanation (singular) on first
 *     generation, no matter what the AI returned. Even when the AI returned a perfect
 *     explanations[4] array (per the prompt schema at line 29154), the server discarded
 *     it for audio purposes. The fix: (1) prepend the same legacy auto-upgrade block as
 *     the regenerate-audio route — if cardselect arrives with explanations[] missing or
 *     not aligned with cards, synthesize 4 entries from the cards (correct = "Correct. "
 *     + q.explanation; incorrect = generic per-card "Incorrect. {label} isn't quite
 *     the right fit..."). (2) extend the audio branch condition: cardselect-with-
 *     per-card-explanations now goes through the same per-clip TTS loop as MCQ. After
 *     this fix every fresh cardselect question lands in the player with adLen=4 and
 *     hasPerCardText=true; the per-card narration plays correctly on every click,
 *     correct or incorrect. No prompt change. No client change required for this half
 *     of the fix.
 *
 *   FIX-VA-CARDSELECT-RUNTIME-FALLBACK (amd/src/videoactivity.js
 *     checkCardSelectAnswer, line 3877+): Defensive belt-and-braces fix in the player
 *     for legacy/wiped questions. Previously when q.explanations[] was missing entirely
 *     (un-healed legacy question whose data was wiped before any v1.0.110+ heal/save),
 *     the wrong-click handler fell back to q.explanation (singular, the OVERALL
 *     correct-card explanation) — which IS the answer leak mum has been reporting.
 *     The new branch: on incorrect click with no per-card text array, build a per-card
 *     "Incorrect. {pickedLabel} isn't quite the right fit here..." string on the fly
 *     from the chosen card's label. This guarantees no answer leak ever happens on a
 *     wrong click — even on the very oldest legacy questions in mum's DB that were
 *     created before v1.0.110 and have never been regenerated. Correct clicks still
 *     show q.explanation because that text describes the card the student got right
 *     (safe to show).
 *
 *   Sync points: version.php → 2026050300117; AMD triple-match (src+build+min identical
 *     MD5 8c714008b8103555e3ed28e9b8f212e4); BUILD_INFO.json → 1.0.117; upgrade.php
 *     savepoint 2026050300117 (no-op marker); server/routes.ts plugin registry →
 *     mod_aivideoactivity_v1.0.117.zip; client/src/lib/pluginConfig.ts → "1.0.117" +
 *     new release entry; public/downloads/mod_aivideoactivity_v1.0.117.zip built;
 *     v1.0.116 ZIP removed; replit.md milestone appended.
 *
 * v1.0.114 - FIX-VA-TIMESTAMP-PRESERVE + FIX-VA-TIMESTAMP-FALLBACK
 *                              + FEAT-VA-BULK-CARDSELECT-AUDIO-UPGRADE
 *                              real fix for both "timestamps not accurate" and
 *                              "cardselect voiceover not playing on legacy
 *                              questions" — ships server-side timestamp
 *                              preservation in YouTube auto-fetch, server-side
 *                              fallback that maps AI nulls to nearest [MM:SS]
 *                              transcript marker by keyword overlap, and a new
 *                              admin page that bulk-upgrades every legacy
 *                              cardselect question's audio across the whole
 *                              site in one click.
 *
 * v1.0.114: TWO BUG FIXES + ONE ADMIN FEATURE.
 *
 *   FIX-VA-TIMESTAMP-PRESERVE (timestamps never accurate, root cause #1):
 *     Mum's v1.0.113 console log proved that 8 of 9 questions returned
 *     timestamp_seconds=null and rendered the "~MM:SS" fallback. The
 *     AI prompt was already correct ("integer seconds since video start").
 *     The bug: /api/fetch-youtube-transcript joined youtube-caption-extractor
 *     output with .map(s => s.text).join(" "), DROPPING every caption's
 *     start time. So even when the trainer used the auto-fetch button on a
 *     YouTube video with perfect timecoded captions, the AI received plain
 *     prose with zero time markers and (correctly) returned null. Fix: format
 *     each caption block as "[MM:SS] text" and join with newlines. The AI now
 *     sees one timestamped block per caption and can confidently return real
 *     timestamp_seconds for every question. Server-only edit at the
 *     /api/fetch-youtube-transcript route.
 *
 *   FIX-VA-TIMESTAMP-FALLBACK (timestamps never accurate, root cause #2):
 *     Even with FIX-VA-TIMESTAMP-PRESERVE, the AI may still return null for
 *     individual questions (model is non-deterministic; manually pasted
 *     transcripts may have inconsistent time markers). New post-processing
 *     step in processVideoActivity scans the raw transcript for [MM:SS] /
 *     MM:SS / H:MM:SS markers, splits it into timestamped blocks, and for
 *     every question with timestamp_seconds === null finds the block whose
 *     text shares the most >=4-char keywords with the question stem and
 *     assigns that block's timestamp. This guarantees that whenever the
 *     transcript contains ANY time markers, every question gets a real
 *     content-grounded timestamp instead of an even-distribution fallback.
 *     Server-only edit (routes.ts: applyTranscriptTimestampFallback helper +
 *     one call site in processVideoActivity). No prompt changes, no DB.
 *
 *   FEAT-VA-BULK-CARDSELECT-AUDIO-UPGRADE (cardselect voiceover not playing
 *     on incorrect cards for existing legacy questions):
 *     Mum's v1.0.113 console log proved that her cardselect Q2 had
 *     adLen=1 hasPerCardText=false — i.e. it was a legacy question created
 *     before v1.0.110, with only one audio clip narrating the correct
 *     answer. The v1.0.113 anti-cheat guard correctly suppressed that single
 *     clip on a wrong selection (would otherwise read out the correct
 *     answer). The fix exists in code (v1.0.110 added per-card audio for
 *     newly-generated cardselects, v1.0.112 added auto-upgrade in the
 *     regenerate-audio route) but does not retroactively apply to existing
 *     content unless the trainer clicks "Regenerate audio" on each question
 *     by hand. Mum has dozens. New admin page bulkregen_cardselect.php +
 *     two new ajax.php actions (bulkregencount + bulkregenstep, both
 *     siteadmin-only) walk the entire mdl_aivideoactivity_questions table,
 *     find every cardselect question whose audiodata has fewer than 2
 *     clips (legacy single-clip), call /api/videoactivity-regenerate-audio
 *     for each one (which already auto-upgrades via v1.0.112 logic),
 *     persist the returned 4-clip audiodata + per-card explanations back to
 *     the DB, and report progress live in the browser. After running once,
 *     every existing cardselect question across the whole site plays the
 *     wrong-card voiceover correctly with no per-question editor work.
 *     Files: admin/bulkregen_cardselect.php (new), ajax.php (two new
 *     actions), settings.php (link to admin page), lang/en strings.
 *
 *   Sync points: version.php → 2026050300114; AMD triple-match (src+build+
 *     min identical MD5); BUILD_INFO.json → 1.0.114; server/routes.ts
 *     plugin registry → mod_aivideoactivity_v1.0.114.zip;
 *     client/src/lib/pluginConfig.ts → "1.0.114" + new release entry;
 *     public/downloads/mod_aivideoactivity_v1.0.114.zip built; v1.0.113
 *     ZIP removed; replit.md milestone appended.
 *
 * v1.0.110: BUG FIX (1 bug, full vertical fix):
 *
 *   FIX-VA-CARDSELECT-PERCARD-VOICEOVER: Mum reported (again) that on
 *   cardselect questions, the voiceover behaviour after picking a card was
 *   wrong. v1.0.108 played the correct-card audio on every wrong click and
 *   spoiled the answer. v1.0.109 reverted to silence on wrong clicks (only
 *   playing on correct OR scoringMode===1). Mum then said: silence on wrong
 *   feels broken — she wants a SPOKEN explanation on incorrect clicks too,
 *   just one that does not give away the right answer.
 *
 *   The previous patches were both forced into bad choices because cardselect
 *   was generating only ONE audio clip per question (q.audioData[0] = the
 *   correct card's narration) — there was no per-card narration to play on a
 *   wrong click without leaking the answer. v1.0.110 fixes this at the source
 *   by giving cardselect the same per-option treatment MCQ has had all along:
 *
 *     - Server-side AI prompt (server/routes.ts main + regen): cardselect
 *       schema now requires explanations[] (4 strings, one per card, indexed
 *       positionally to match cards). The card at correctIndex starts with
 *       "Correct."; the other three start with "Incorrect." and explain why
 *       that specific card is wrong without naming the correct card outright.
 *
 *     - Server-side TTS regen (server/routes.ts, all 3 sites: regenerate-audio,
 *       regenerate-settings, regenerate-instructions): when cardselect carries
 *       explanations[4], generate 4 TTS clips into audioData[]; legacy
 *       single-explanation cardselect questions still get a single clip.
 *
 *     - Plugin (amd/src/videoactivity.js): cardselect quizData mapping now
 *       shuffles explanations[] and audioData[] positionally with the cards
 *       so card[displayIdx].explanation/audio remain aligned after shuffle.
 *       checkCardSelectAnswer reads q.explanations[selectedAnswer] and plays
 *       q.audioData[selectedAnswer] when the per-card arrays are present —
 *       safe on both correct AND incorrect because each clip narrates only
 *       its own card. Legacy questions (no explanations[]) fall back to the
 *       v1.0.109 gate (correct OR scoringMode===1) which still protects
 *       against revealing the correct-card narration on wrong clicks.
 *
 *   Backward compat: ALL of mum's existing cardselect questions in the DB
 *   continue to work exactly as in v1.0.109 (silence on wrong, audio on
 *   correct). To upgrade an existing cardselect question to per-card audio,
 *   open Edit Questions in Moodle and click "Regenerate audio" — the server
 *   will re-call the AI under the new prompt and return per-card explanations
 *   plus 4 audio clips.
 *
 *   AMD-only rebuild (src + build + min triple-match). DB schema unchanged
 *   (questiondata column is JSON, already round-trips explanations[] without
 *   any PHP change). version.php -> 2026050200110.
 *
 * v1.0.109: BUG FIX (3 bugs):
 *
 *   (1) FIX-VA-CARDSELECT-VOICEOVER-CORRECT-ONLY: Direct revert of the v1.0.108
 *       change in `checkCardSelectAnswer()`. Mum reported "When the student
 *       selects the wrong card, the system is still playing the explanation
 *       for the correct card" — exactly the answer-leak v1.0.105 was guarding
 *       against. The v1.0.108 reasoning ("the on-screen text already shows
 *       the explanation, so playing the audio is no extra leak") was wrong:
 *       audio is much more attention-grabbing than the text panel, and the
 *       student hearing "X is the correct answer because Y" within a second
 *       of clicking the wrong card effectively spoils the retry. Card Select
 *       TTS is generated server-side as a SINGLE clip per question
 *       (q.audioData[0] = the correct answer's spoken explanation), unlike
 *       MCQ which generates per-option clips at q.audioData[selectedAnswer].
 *       With only one clip available, the only safe behaviour on a wrong
 *       click is silence — let the student read the on-screen feedback and
 *       retry. Restored the v1.0.105 gate: play only on correct, OR when
 *       scoringMode === 1 (first-attempt mode where there is no retry so
 *       revealing the answer is harmless).
 *
 *   (2) FIX-VA-RETAKE-LOADING-BTN: When the student clicks the start/continue
 *       button, `handleStartAttempt`/`handleContinueAttempt` set
 *       `btn.textContent = 'Loading...'` while the AJAX round-trip is in
 *       flight. textContent assignment clobbers BOTH the play-triangle SVG
 *       and the proper label. The questions then load, the start section is
 *       hidden, and the button is no longer visible — but the DOM node still
 *       carries the 'Loading...' text with no icon. After the student
 *       finishes the attempt and clicks Retake, `handleRetake()` shows the
 *       start section again, exposing the same stale button labelled
 *       'Loading...'. The v1.0.108 retake bypass added watchRequirementMet
 *       resets but did not restore the button HTML. Fix: (a) added a module
 *       constant VA_PLAY_SVG with the icon markup, (b) added a helper
 *       `resetStartQuizButtonHtml(useRetakeLabel)` that rebuilds the button
 *       innerHTML as `<svg> + label`, (c) `handleRetake()` calls the helper
 *       with `useRetakeLabel=true` after every retake, (d) the .fail
 *       handlers in `handleStartAttempt` use the helper too (they previously
 *       set textContent='Start Quiz' which had the same SVG-clobber bug,
 *       just less visible because failures are rare). Also handles a
 *       subtler bug for users whose page first loaded with a Continue
 *       button (had an in-progress attempt): after that attempt completes,
 *       the in-progress is now status=1 and clicking Continue would post
 *       answers to a closed attempt. `handleRetake` now re-ids the
 *       continue button to 'va-start-quiz-btn' so the delegated click
 *       handler routes to `handleStartAttempt` (which calls 'startattempt'
 *       and creates a fresh attempt) instead of `handleContinueAttempt`
 *       (which would target the stale attemptid). Two new config strings
 *       passed from view.php: `startQuizLabel` and `retakeQuizLabel` (both
 *       already existed as Moodle lang strings; just exposed to JS).
 *
 *   (3) FIX-VA-TIMESTAMP-DIAG: Mum reported the per-question "Jump to X:XX"
 *       chapter timestamp links stopped showing. None of the v1.0.108
 *       changes touched the chapter-stamp render path (`showQuestion()` at
 *       the va-chapter-stamp DOM block) or the storage/round-trip paths
 *       for `q.timestamp_seconds` (saveQuestionsToDatabase preserves it,
 *       saveEditedQuestions preserves it, the regenerate batch payload
 *       includes it, ajax.php getquestions returns the full questiondata
 *       JSON unchanged). The render is gated on three independent signals:
 *       `config.showChapterStamps` (activity-level toggle, DB column
 *       defaults to 0), `q.timestamp_seconds != null` (data — present iff
 *       the AI generation included a timestamp for that question), and
 *       `config.mediaType !== 'audio'` (audio-mode activities suppress
 *       stamps intentionally). Without a reproduction, added a console
 *       diagnostic log inside `showQuestion()` that prints all three
 *       signals plus the resulting `will_render` boolean for every
 *       question shown. With this log mum can open the browser console on
 *       the affected activity, click through her questions, and confirm
 *       which leg is failing — almost certainly either the activity
 *       toggle is off (config) or her last regenerate dropped the
 *       timestamps (data). No behaviour change on the render path itself.
 *
 *   AMD rebuild (src + build + min triple-match). PHP change in view.php
 *   (two new config strings only — no UI changes). No DB schema changes —
 *   savepoint bumps to 2026050200109 only as a marker. Backwards
 *   compatible. version.php → 2026050200109.
 *
 * v1.0.108 - FIX-VA-RETAKE-FREE-VIDEO + FIX-VA-CARDSELECT-VOICEOVER-PLAY:
 *                              two student-experience bugs reported by mum.
 *
 * v1.0.108: BUG FIX (2 bugs):
 *
 *   (1) FIX-VA-RETAKE-FREE-VIDEO: When a student finished a quiz attempt and
 *       clicked Retake — or simply revisited the activity in a new session
 *       after a prior completed attempt — the activity re-imposed the full
 *       watch gate as if the video had never been watched. The Start/Retake
 *       button stayed disabled until the entire video played end-to-end again,
 *       AND the YouTube/audio scrubber was locked by the seek-prevention logic
 *       (`if (!watchRequirementMet && currentTime > maxWatchedPosition + 2)
 *       seekTo(maxWatchedPosition)`) so the student couldn't even jump to the
 *       section they wanted to revise. Mum reported this as a blocker — once
 *       a student has watched the video once, they should be free to control
 *       playback on every subsequent attempt. Fix in 3 places: (a) view.php
 *       computes `$haspreviousattempts` ONCE up-front and uses it to gate the
 *       `disabled` attribute on `va-start-quiz-btn` so the button is rendered
 *       enabled when the student has at least one completed attempt; the
 *       `va-continue-attempt-btn` (in-progress case) is never rendered
 *       disabled because by definition that student was already past the
 *       watch gate when they started the in-progress attempt. (b) view.php
 *       passes the new `hasPreviousAttempts` boolean into the JS config.
 *       (c) videoactivity.js: at student init, if `watchMode !== 'none'` AND
 *       (`hasInProgress` OR `hasPreviousAttempts`) the JS sets
 *       `watchRequirementMet = true`, sets `maxWatchedPosition =
 *       Number.MAX_SAFE_INTEGER` (so the snap-back guard's
 *       `currentTime > maxWatchedPosition + 2` clause can never be true,
 *       letting the student seek freely on YouTube and audio), hides the
 *       watch-progress text, fills the progress bar to 100%, and calls
 *       `enableQuizButton()`. (d) videoactivity.js `handleRetake()` no longer
 *       re-imposes the watch gate at all — by definition the student just
 *       completed an attempt, so the gate has nothing to enforce. Same trio
 *       of resets is applied (watchRequirementMet, maxWatchedPosition,
 *       progress UI, enable button) so a freshly-clicked Retake immediately
 *       presents an enabled Start Quiz button and an unrestricted scrubber.
 *
 *   (2) FIX-VA-CARDSELECT-VOICEOVER-PLAY: The v1.0.105 fix
 *       (FIX-VA-CARDSELECT-VOICEOVER-WRONG) suppressed Card Select voiceover
 *       on incorrect clicks because q.audioData[0] holds the TTS for the
 *       correct answer's explanation and we worried that playing it on a
 *       wrong click would "leak" the answer before the student had a chance
 *       to retry. The reasoning was wrong: the on-screen feedback panel
 *       ALREADY renders q.explanation in plain text on incorrect attempts
 *       (see the `feedbackContainer.innerHTML` assembly in
 *       `checkCardSelectAnswer`), so the explanation is visible regardless.
 *       Suppressing only the audio gave students with TTS enabled a worse
 *       experience than students reading silently, with no actual answer
 *       protection. Mum reported this as a bug ("no voiceover on incorrect").
 *       Fix: removed the `(isCorrect || config.scoringMode === 1)` gate;
 *       the call site now plays `q.audioData[0]` whenever audio is present,
 *       matching MCQ semantics where audio plays for whichever option was
 *       selected.
 *
 *   AMD rebuild (src + build + min triple-match). PHP change in view.php
 *   (button gating + new config var). No DB schema changes — savepoint
 *   bumps to 2026050200108 only as a marker. Backwards compatible.
 *   version.php → 2026050200108.
 *
 * v1.0.107: BUG FIX (FIX-VA-SCORE-OVERCOUNT) in amd/src/videoactivity.js:
 *   Mum's results screen on a 9-question quiz showed Correct=10, Incorrect=-1,
 *   Percentage=111% with the score ring sitting above the score label "10 / 9".
 *   That arithmetic is only possible if some question's `score++` ran twice for a
 *   single completion. The plugin has 9 distinct score++ call sites (matching pairs
 *   line 2926, column sort line 3094, category sort line 3160, flashcards line 3203,
 *   true/false swipe line 3410, fill in the blank line 3551, MCQ line 3639, card select
 *   line 3715, ordering line 3800), every one of which was singly-guarded inside its
 *   own handler — but each handler is independent and any one of them could double-fire
 *   under the right race condition (rapid double-click on a Check button that's only
 *   hidden via display:none rather than disabled, a continueBtn whose click handler is
 *   re-bound on each renderTFState() call inside fresh DOM elements, a tryAgain() flow
 *   that doesn't immediately disable the underlying button, etc.). Rather than
 *   whack-a-mole one-by-one, this fix introduces a defensive cross-cutting guard.
 *
 *   New module-scope state added at the top of videoactivity.js alongside the
 *   `score` variable: `var scoredQuestionIndices = {};` and a helper
 *   `tryScoreCurrentQuestion()` that returns false (and does nothing) if
 *   currentQuestionIndex has already been scored on this attempt, otherwise marks
 *   it scored and increments score by 1. Every one of the 9 `score++` call sites
 *   was rewritten to call `tryScoreCurrentQuestion()` instead of bumping `score`
 *   directly. The map is reset to `{}` in startStudentQuiz() and startTeacherPreview()
 *   alongside `score = 0;` and `currentQuestionIndex = 0;`. The existing flashcard
 *   `finished` flag (FIX-VA-FLASHCARD-DOUBLE) is preserved as a per-renderer guard
 *   but the new module-scope guard now sits in front of it as the authoritative
 *   anti-double-count gate.
 *
 *   Net effect: it is now impossible for any question — current or future-added —
 *   to contribute more than +1 to score, regardless of how many handler paths run
 *   for that question or how many DOM listeners fire. score <= totalQuestions
 *   becomes a hard invariant.
 *
 *   AMD rebuild (src + build + min triple-match). No DB schema changes — savepoint
 *   bumps to 2026050200107 only as a marker. No PHP changes. Backwards compatible.
 *   version.php → 2026050200107.
 *
 * v1.0.106: BUG FIX (FIX-VA-EDITOR-FIELD-SIZING + BUG-VA-FIB-FEEDBACK):
 *   Three editor-screen UI defects reported by mum after the v1.0.105 per-type editor ship.
 *   Flashcard front/back inputs converted to <textarea rows="2">, TFS statement input
 *   converted to <textarea rows="2">, FIB editor gained an .va-edit-explanation-text
 *   textarea + saveEdits persists q.explanation.
 *
 * v1.0.105: BUG FIX (3 bugs in amd/src/videoactivity.js):
 *
 *   (1) FIX-VA-EDITOR-FLASHCARD-TFS: The Edit Questions screen had no per-type render or save block for
 *       Flashcards or True/False Swipe questions. Both types fell through to the generic else branch
 *       that only read and wrote q.question, so teachers could change the (often empty) question stem
 *       but never the actual card front/back text or the True/False statements/answers/explanations.
 *       Any AI-generated content for those two types was effectively read-only after generation. Fix
 *       in renderEditQuestions(): added a flashcards branch that renders one row per card (front +
 *       back text inputs) plus an overall explanation textarea, and a truefalseswipe branch that
 *       renders one row per statement (text input + True/False radio + per-statement explanation
 *       textarea) plus an overall explanation textarea. Matching save blocks added in saveEdits()
 *       that validate every card has both front and back populated, and every statement has text and
 *       an explicit True/False marker, then persist the full per-type structure (cards or statements
 *       array). The top-level "question text cannot be empty" validator was tweaked to permit a blank
 *       stem when qtype === 'flashcards' because the AI schema for flashcards has no "question" field
 *       (only {type, cards, explanation}). All other types still require a non-empty stem.
 *
 *   (2) FIX-VA-CARDSELECT-VOICEOVER-WRONG: checkCardSelectAnswer() always played q.audioData[0] on
 *       both correct AND incorrect clicks. q.audioData[0] holds the TTS audio for the correct
 *       answer's explanation — so on a wrong click the audio audibly handed the student the answer
 *       before they had the chance to retry, defeating the entire Try Again flow. The equivalent MCQ
 *       branch correctly plays q.audioData[selectedAnswer] (per-option audio) so each click hears the
 *       audio for whatever was selected. Card Select only ships ONE explanation per question (the
 *       correct answer), so the fix is to gate playback on the student getting it right OR being in
 *       first-attempt mode (config.scoringMode === 1) where there is no retry and revealing the
 *       answer matches the explanation panel that's already shown.
 *
 *   (3) FIX-VA-CATSORT-EDIT-NORMALIZE: The Category Sort editor corrupted every item into category 0
 *       on Save. Two-part bug. (a) The dropdown render line used strict equality
 *       `catItems[csi].category === catOpt` to pre-select the option, but the AI emits
 *       `item.category` as the STRING category name ("Mammals"), not as a numeric index. String !==
 *       number, so the dropdown never pre-selected the right option for any item — the browser
 *       silently fell back to the first <option> (index 0). (b) The save block then read the dropdown
 *       value as a number and persisted it. So when the teacher hit Save without touching the
 *       dropdowns, every item was rewritten as category=0, dumping the entire question into the
 *       first bucket. Render fix accepts numeric index, the string form of the numeric index, OR the
 *       canonical string category name. Save fix persists the STRING category name (the AI's
 *       canonical format) so the round-trip back into the editor is stable AND the regenerate-
 *       instructions prompt receives the same shape it expects.
 *
 *   AMD rebuild (src + build + min triple-match). No DB schema changes — savepoint is a no-op marker.
 *   No PHP changes. version.php → 2026050200105.
 *
 * v1.0.106: BUG FIX (FIX-VA-EDITOR-FIELD-SIZING + BUG-VA-FIB-FEEDBACK):
 *   Three editor-screen UI defects reported by mum after the v1.0.105 per-type editor ship.
 *   (1) FIX-VA-EDITOR-FIELD-SIZING (Flashcards): The Edit Questions screen rendered the
 *       per-card front/back fields as side-by-side single-line <input>s, leaving each box
 *       only ~20 characters wide. Teachers could not see what they were editing without
 *       scrolling each tiny box horizontally. Renderer now emits <textarea rows="2"> for
 *       both front and back; CSS stacks them vertically full-width with min-height 56px and
 *       resize:vertical so long card content is fully visible and editable.
 *   (2) FIX-VA-EDITOR-FIELD-SIZING (TrueFalseSwipe): Same bug shape — the per-statement
 *       text field was a single-line <input> in a narrow column next to the True/False
 *       radios. Renderer now emits <textarea rows="2"> matching the per-statement
 *       explanation textarea below it; both share the same CSS sizing rules so the
 *       statement and its explanation are equally legible.
 *   (3) BUG-VA-FIB-FEEDBACK (Fill in the Blank): Every other question type (mcq,
 *       cardselect, categorysort, flashcards, truefalseswipe) renders an
 *       .va-edit-explanation-text textarea bound to q.explanation so teachers can author
 *       the post-completion feedback the student sees. FIB had no such textarea — there
 *       was no way to edit the feedback at all from the Edit screen. Renderer now appends
 *       the same explanation textarea after the distractors section; saveEdits() FIB branch
 *       reads .va-edit-explanation-text and persists it as `explanation` on the question
 *       object alongside passage/blanks/distractors.
 *   AMD rebuild (src + build + min triple-match). CSS additions to styles.css for the
 *   new textarea sizing. No DB schema changes. No PHP changes. Backwards compatible —
 *   existing FIB questions without an explanation field render the textarea empty.
 *   version.php → 2026050200106.
 *
 * v1.0.104: SPELLING + AUDIT (FIX-VA-AUEN):
 *
 * v1.0.104: SPELLING + AUDIT (FIX-VA-AUEN):
 *   Full sweep for American English in user-facing strings. Three replacements made:
 *     1. lang/en/aivideoactivity.php $string['score_needs_improvement'] = 'Keep practising!'
 *        (was 'Keep practicing!') — appears on results screen for low-score tier.
 *     2. amd/src/videoactivity.js Warehousing subindustry option 'Order Fulfilment'
 *        (was 'Order Fulfillment') — in INDUSTRY_SUBCATEGORIES picker on activity creation.
 *     3. amd/src/videoactivity.js showResults() needs-work tier title 'Keep Practising!'
 *        (was 'Keep Practicing!') — JS results-card animated header.
 *   Other regex matches (color, behavior, center, program*) verified as JS API names,
 *   CSS property names, and component classnames — must remain American.
 *   AMD rebuild: src + build + min triple-match e781d45526bd6186bef0976c6136b257.
 *   No DB schema changes. Server prompt at routes.ts already injects AU spelling rules
 *   via getSpellingInstructions() when voiceLanguage='en-AU' so AI-generated questions
 *   are AU-spelled at source (organisation, behaviour, colour, centre, licence, defence,
 *   programme, labour, honour, realise, analyse, recognise).
 *
 *   Timestamp pipeline audited end-to-end and confirmed working:
 *     - DB column showchapterstamps in install.xml + upgrade backfill (lines 277, 312).
 *     - view.php passes config.showChapterStamps from $videoactivity->showchapterstamps.
 *     - JS load path preserves timestamp_seconds for mcq + cardselect (lines 2264, 2305).
 *     - JS save path preserves timestamp_seconds in saveQuestionsToDatabase +
 *       saveEditedQuestions (lines 999, 1576) — FIX-VA-TIMESTAMP-SAVE from v1.0.73.
 *     - JS regen paths preserve timestamp_seconds in batch + per-question regenerate
 *       (lines 1673, 1782) — FIX-VA-REGEN-TIMESTAMP from v1.0.94.
 *     - Display logic at line 2418 reads q.timestamp_seconds with FIX-VA-TIMESTAMP-OFFSET
 *       subtracting 1s for natural lead-in (v1.0.92), and audio-mode suppression check.
 *     - Server-side preserves timestamp_seconds across regenerate-settings (routes.ts:28950)
 *       and regenerate-instructions (routes.ts:29442).
 *     - AI prompt at routes.ts:29824 instructs Gemini to find the transcript line where
 *       the topic BEGINS (not before/after) with accuracy-critical guidance.
 *
 *   Stale/outdated JS sweep: clean. Only amd/src/videoactivity.js plus the build pair
 *   amd/build/videoactivity.js + amd/build/videoactivity.min.js (all triple-matched).
 *   No .bak/.old/.orig/.tmp files anywhere in the plugin tree.
 *   version.php -> 2026050100104.
 *
 * v1.0.103: DIAGNOSTIC (FIX-VA-VOICEOVER-DIAG):
 *   New activities created with the voiceover toggle ON play silently for both teacher
 *   preview and student attempt. Server pipeline (generate → processVideoActivity →
 *   generateTTSBuffer → status endpoint) and plugin pipeline (savequestions stores
 *   audioData both in dedicated `audiodata` TEXT column and inside questiondata JSON;
 *   getquestions overrides with the dedicated column) all looked correct on inspection.
 *   The user-supplied student console showed [VA] Loaded questions: 5 followed by many
 *   [VA] Answer saved successfully entries but ZERO [VA] Voiceover playback failed /
 *   error lines, proving playVoiceover() was never reached — i.e. q.audioData[i] was
 *   falsy at every callsite. To pinpoint where audioData is being lost, this build adds
 *   five diagnostic checkpoints (no behaviour changes):
 *     1. After /api/videoactivity-status returns completed: [VA-DIAG] gen Q{n} ...
 *     2. Immediately before savequestions AJAX:           [VA-DIAG] save Q{n} ...
 *     3. Immediately after getquestions AJAX:             [VA-DIAG] load Q{n} ...
 *     4. At every playVoiceover guard (5 callsites):      [VA-DIAG] guard ...
 *     5. On every entry to playVoiceover():               [VA-DIAG] playVoiceover called ...
 *   AMD rebuild (src + build + min). version.php → 2026050100103.
 *
 * v1.0.92 - FIX-VA-BADGE-LAYOUT + FIX-VA-TIMESTAMP-OFFSET: Layout and timestamp accuracy fixes.
 *
 * v1.0.73: BUG FIX (FIX-VA-TIMESTAMP-SAVE):
 *   "Show chapter timestamp links" buttons were not appearing for students even when the AI
 *   had correctly assigned timestamp_seconds to questions. Root cause: both save functions
 *   in videoactivity.js (saveQuestionsToDatabase and saveEditedQuestions) built an explicit
 *   field allowlist when serialising quizData before sending to the DB — timestamp_seconds
 *   was absent from that allowlist, so it was silently stripped on every save. Students
 *   then received questions without timestamp_seconds, causing the chapter stamp button
 *   condition (`q.timestamp_seconds != null`) to always be false. Fix: added
 *   `if (q.timestamp_seconds != null) qObj.timestamp_seconds = q.timestamp_seconds;`
 *   to both save functions. AMD rebuild (src+build+min). No DB schema changes.
 *   version.php → 2026042400073.
 *
 * v1.0.66 - FIX: FIB question editing + sticky video during quiz + section descriptions.
 *
 * v1.0.64: BUG FIX (FIX-VA-REGEN-INSTRUCTIONS-PARSE + FIX-VA-REGEN-TIMESTAMP):
 *   The "Regenerate Questions" button (regenerate-instructions endpoint) was still broken:
 *   (1) FIX-VA-REGEN-INSTRUCTIONS-PARSE: The /api/videoactivity-regenerate-instructions
 *       endpoint was missing responseMimeType:"application/json" on its Gemini call, and
 *       only applied basic markdown-fence stripping before JSON.parse — causing intermittent
 *       "Regeneration failed: Failed to parse AI response" errors. Fixed by adding
 *       responseMimeType:"application/json" and the same multi-strategy JSON extraction
 *       (strip → array-match → bracket-scan) used in the settings-regen endpoint since v1.0.63.
 *   (2) FIX-VA-REGEN-TIMESTAMP: The same endpoint was dropping timestamp_seconds from
 *       regenerated questions. After clicking Regenerate, "Show chapter timestamp links"
 *       buttons disappeared even when timestamps existed. Fixed by copying timestamp_seconds
 *       from the original question into the regenerated question after AI parsing.
 *       Server-only fix (routes.ts). No DB schema changes. version.php → 2026041600064.
 *
 * v1.0.63: BUG FIX (FIX-VA-TIMESTAMP-PRESERVE + FIX-VA-REGEN-PARSE):
 *   (1) The videoactivity-regenerate-settings endpoint was dropping timestamp_seconds from
 *       regenerated questions. After settings regeneration (e.g. language translation) the
 *       "Show chapter timestamp links" button disappeared even when timestamps existed.
 *       Fix: server/routes.ts now copies timestamp_seconds from the original question into
 *       the regenerated question after AI returns.
 *   (2) Improved multi-strategy JSON parsing in the regenerate-settings response handler.
 *       Previously a single JSON.parse() call; now tries stripped/array-match/bracket-scan
 *       strategies (matching the instructions-regen approach) before returning an error.
 *       Server-only fixes. No DB schema changes. version.php → 2026041600063.
 *
 * v1.0.61: BUG FIX (matching + ordering):
 *   (1) BUG-VA-MATCH-FALSE-POSITIVE: Old dropdown matching (v1.0.59 and earlier) graded
 *       allCorrect=true if every dropdown was filled, regardless of whether the selections
 *       were correct — causing false "All matched correctly!" when some answers were wrong.
 *       The click-card UI (v1.0.60+) fixes this conceptually; v1.0.61 adds an explicit
 *       correctPairings map to the allCorrect check and the retry-timeout deletion loop so
 *       the invariant is self-documenting and protected against future regressions.
 *   (2) BUG-VA-MATCH-FEEDBACK-PARAM: showMatchingFeedback(q, isCorrect) ignored its
 *       isCorrect parameter and always showed "All matched correctly!" regardless of the
 *       actual grading result. Fixed to use the parameter so incorrect results get the
 *       va-feedback-incorrect style and title.
 *   (3) BUG-VA-ORD-FEEDBACK-STALE: After a wrong ordering attempt the "Not quite right"
 *       feedback text remained permanently visible while the student re-arranged items.
 *       Fixed to clear it alongside the shake animation (1500ms) so the student sees a
 *       clean state while re-ordering; feedback re-appears fresh on next "Check Order" click.
 *   AMD: videoactivity.js (src=build=min). No DB schema changes. version.php → 2026041500061.
 *
 * v1.0.53: BUG FIX (flashcard card bug):
 *   (1) FIX-VA-FLASHCARD-DOUBLE: A `finished` guard flag was added to `advanceCard()` so that
 *       score++ fires at most once per flashcard question, even if the student clicks "Got it!"
 *       or "Still learning" multiple times on the last card before the Next button appears.
 *       After the set is complete the options area is cleared immediately so the self-assessment
 *       buttons are no longer clickable.
 *   (2) FIX-VA-FLASHCARD-WIDTH: `.va-flashcards-container` now has explicit `display:block;
 *       width:100%; box-sizing:border-box; min-width:0` and `.va-flashcard-wrapper` gains
 *       `width:100%; min-width:0` to prevent Moodle theme flex contexts from shrinking the
 *       card to content width instead of filling the quiz panel.
 *   JS: videoactivity.js (advanceCard). CSS: styles.css (.va-flashcards-container,
 *   .va-flashcard-wrapper). No DB changes. version.php → 202604100053.
 *
 * v1.0.52: NEW FEATURES:
 *   (1) "Show video above questions" — when enabled, the YouTube player remains visible above
 *       the quiz panel while the student answers questions (only the watch-progress bar and
 *       action buttons are hidden). When disabled (default) the video section is hidden as
 *       before. Setting: aivideoactivity.showvideoduringquiz (already in DB from prior schema).
 *   (2) "Show chapter timestamp links" — when enabled, a clickable "Jump to X:XX" button
 *       appears above each question. Clicking seeks the video to the timestamp nearest to the
 *       chapter the question covers. The AI now returns timestamp_seconds (int|null) for each
 *       question. Setting: aivideoactivity.showchapterstamps (DB added in v1.0.52 upgrade).
 *   Changes: videoactivity.js (startStudentQuiz, showQuestion, handleRetake), styles.css,
 *   version.php → 202604100052.
 *
 * v1.0.51: FIX — Credit cost formula text reworked to be unambiguous.
 *   Old: "25 credits (436 words = 15 credits + 10 questions voiceover = 10 credits)"
 *   The trailing "= 10 credits" made the breakdown read as "15 + 10 = 10" (wrong).
 *   New: "25 credits (436 words = 15 credits + 10 voiceover credits for 10 questions)"
 *   The total (25) now clearly equals the two components (15 + 10). JS-only: videoactivity.js.
 *   No DB schema changes. version.php → 202604100051.
 *
 * v1.0.50: BUG FIX (4 fixes):
 *   (1) VOICEOVER MIME TYPE: playVoiceover() was creating Audio with MIME type 'audio/mp3' but
 *       the server generates OGG_OPUS audio. This caused silent playback failures across all activity
 *       types (MCQ, matching, etc.). Fix: changed MIME to 'audio/ogg'. JS-only: videoactivity.js.
 *   (2) CATEGORYSORT EXAMPLE JSON: The server prompt example used numeric indices ({"category": 0})
 *       while the description said string names. When AI generated "category": "0" (string-number),
 *       server validation filtered all items out. Fix: prompt example now uses string names
 *       ({"category": "Category 1"}). Server validation also normalised string-numbers to numeric
 *       index as a fallback. JS check also extended to normalise string-number categories.
 *       server/routes.ts + videoactivity.js.
 *   (3) FLASHCARD FORMAT: Prompt description said "question/prompt + answer" but flashcards should
 *       be TERM/DEFINITION cards (front = key term, back = definition). Updated both the prompt
 *       description and the example JSON in server/routes.ts.
 *   (4) VOICEOVER MIME (JS): videoactivity.js playVoiceover MIME type fix (see item 1).
 *   No DB schema changes. Files: server/routes.ts, amd/src/videoactivity.js, version.php → 202604090050.
 *
 * v1.0.49: BUG FIX (regenerate prompt): Regenerate-question prompt now uses STRICT RULES block
 *   banning micro-edits; requires new stem/options/explanation; enforces 50-char similarity check.
 *   version.php → 2026040800149.
 *
 * v1.0.46: BUG FIX (quiz gate): va-continue-attempt-btn now rendered with disabled attribute
 *   when watchmode !== 'none', matching the existing gate on va-start-quiz-btn. Previously a
 *   student with an in-progress attempt could click "Continue Attempt" without watching the
 *   required video. The JS enableQuizButton() already enables both buttons when the watch
 *   requirement is fulfilled — no JS changes required.
 *   No DB schema changes. PHP-only: view.php. version.php → 2026040700146.
 *
 * v1.0.45: SERVER BUG FIX (x2): (1) Answer/explanation mismatch — fixExplanationOrder() in
 *   server/routes.ts was incorrectly changing correctAnswer to wherever the "Correct." explanation
 *   happened to be in the explanations array, causing the wrong option to be highlighted green (e.g.
 *   student selects B, which is correct, but B was marked incorrect and A was marked correct because
 *   the AI placed the "Correct." explanation at index 0). Fix: the function now SWAPS explanations so
 *   explanations[correctAnswer] holds the "Correct." text — options and correctAnswer are left
 *   unchanged. (2) Q6 malformed hard-fail on Regenerate Instructions — the /api/videoactivity-
 *   regenerate-instructions handler returned HTTP 500 for the entire batch whenever any single
 *   question came back malformed (wrong option count, etc.), producing "AI returned malformed question
 *   6. Please try again." Fix: malformed slots now fall back per-slot to the original question data;
 *   non-MCQ question types (matching, ordering, etc.) are also preserved unchanged. No plugin PHP/JS
 *   changes. No DB schema changes. version.php → 2026040300145.
 *
 * v1.0.33: ETA RECALIBRATE — Increased quiz question time estimate from 45s to 90s
 *           per question. Accounts for reading scenario, thinking, answering, and
 *           reviewing feedback.
 *
 * v1.0.39: BUG FIX — Two regeneration bugs fixed. (1) ajaxCall() now accepts an optional
 * v1.0.40: BUG FIX — MCQ answer grading: correctAnswer/correctIndex returned from the DB as
 *   strings were compared with === against the integer selectedIndex, causing every MCQ
 *   question to report the student's answer as wrong unless they chose option 0.
 *   Fix: parseInt() applied to correctAnswer and correctIndex before comparison.
 *   Ordering questions: wrong-answer branch was calling q.audioData[0].play() (the
 *   correct-answer voiceover) instead of doing nothing; students heard praise audio on every
 *   incorrect ordering attempt. Removed the erroneous playback call. version.php → 2026032800140.
 *
 * v1.0.39: BUG FIX — ajaxCall() timeout added (180 000 ms) for regenerateinstructions.
 *   timeout parameter; regenerateinstructions uses 180 000 ms to match the PHP CURL timeout
 *   and prevent silent hangs. (2) handleRegenerate() was stripping all non-MCQ fields from
 *   questions before sending to the server, causing every question type to revert to MCQ.
 *   Fixed by passing the full quizData objects so the server receives the type field.
 *   version.php → 2026032700139.
 *
 * v1.0.38: VERSION BUMP: Clean release following master release process. version.php → 2026032700138.
 *
 * v1.0.37: VERSION BUMP: Clean release following master release process. version.php → 2026032700137.
 *   when there is at least one question. (2) Category-sort dual comparison fixed for correct
 *   answer detection. (3) Matching dropdown overflow hidden so long options don't break layout.
 *   (4) Voiceover fallback q.question || q.text prevents silent TTS errors when q.text is
 *   missing. (5) Regenerate-instructions route now passes non-MCQ questions through and adds
 *   responseMimeType for Gemini compatibility. version.php → 2026032700136.
 *
 * v1.0.35: INDUSTRY UNIFICATION — Industry SELECT uses same 29-industry list as Content Creator. New #va-scenario-sector SELECT auto-populates sub-sectors. Data collection updated from #va-scenario-subindustry. version.php → 2026032400135.
 *
 * v1.0.32: VERSION BUMP — Maintenance release.
 *
 * v1.0.31: ETA BANNERS — Added "Estimated Time to Complete" banners to both teacher
 *           ready screen (after questions generated) and student start screen.
 *           Clock icon gradient banner with dark mode support.
 *
 * v1.0.30: MULTI-SELECT JOB LEVELS + JOB ROLES — Added multi-select pill buttons for job level
 * and chips text input for job roles (up to 5) inside the Scenario Context section.
 * Sends scenarioJobLevel and scenarioJobRoles in generate payload. Server-side ajax.php updated.
 *
 * v1.0.27: CONTINUE ATTEMPT POSITION FIX — Swapped localStorage/server priority when restoring question
 *          position on "Continue Attempt". localStorage is now preferred (it stores currentQuestionIndex
 *          AFTER increment, pointing to the next unanswered question). Server value is now a cross-device
 *          fallback with +1 applied (server stores last-answered question number). Fixes the bug where
 *          resuming an attempt would re-show the last already-answered question.
 *          TRUE/FALSE STATEMENTS FIX — Added defensive JSON.parse for q.statements when the server
 *          returns the statements array as a JSON string instead of a parsed array. Prevents True/False
 *          questions from appearing blank in the quiz player.
 *
 * v1.0.26: FLASHCARD UI FIX — Removed solid gradient fills from front/back cards.
 * v1.0.25: LIVE ATTEMPTS BADGE FIX — Attempts count badge now updates in real-time when an attempt is completed.
 *          Root cause: PHP rendered $attemptslabel as a static text node in .va-attempts-badge at page load; JS never
 *          touched those spans. finishAttempt() success callback now: (1) increments config.attemptsUsed, (2) calls
 *          updateAttemptsBadge() which clones the SVG icon, rebuilds the label string from attemptsUsedStr +
 *          attemptsUnlimitedStr config values, and updates all .va-attempts-badge spans. Also moved the
 *          config.attemptsUsed increment out of handleRetake() (too late — ran on next-attempt start) into finishAttempt()
 *          success (runs immediately when DB confirms completion). Both badge sizes (header + quiz) update together.
 *
 * v1.0.22: CRITICAL FIX: Continue Attempt button was broken due to JS config key typo (inProgressAttempts → inProgressAttemptId)
 *          which overwrote the correct PHP-rendered data-attemptid with undefined, causing all answer saves to fail silently
 *          during continued attempts. ALSO FIXED: True/False Swipe now shows "Some answers were incorrect." instead of
 *          "Well done!" when the student got some statements wrong.
 *
 * v1.0.21: Confetti only fires on perfect score (100%) — passing grade still plays success sound but no confetti
 *
 * v1.0.20: True/False result indicator — green tick for correct, red cross for incorrect answers
 *
 * v1.0.19: VERSION REBUILD — Full clean rebuild to guarantee Moodle DB recognises latest version. All 5 version locations updated and ZIP rebuilt from source.
 * v1.0.18: CRITICAL FIX: Format distribution overflow caused all questions to be generated as MCQ and wrong question count.
 * When count=3 with 9 formats, Math.max(1,...) forced every format to get ≥1 question (total=8),
 * the last format got count-8=-5 (negative), and the prompt contradictorily listed 8 types while
 * saying "generate 3 total" — AI defaulted to all MCQ. Fixed with floor-based proportional
 * allocation that allows 0 for low-weight formats. Also expanded question count range from 3-20 to 1-20.
 *
 * v1.0.17: SESSION LOCK FIX — Added \core\session\manager::write_close() after auth checks to prevent blocking concurrent requests during AI generation.
 * v1.0.16: Audio file support for listening activities
 * [NEW] Media type selector - teachers choose between YouTube video or audio file
 * [NEW] Audio file upload via Moodle filepicker (MP3, WAV, OGG, M4A, AAC, FLAC, WMA, OPUS, WebM, AIFF, up to 256MB)
 * [NEW] Audio files served securely via Moodle pluginfile API
 * [NEW] HTML5 audio player for students with full playback controls
 * [NEW] Listen gating - same watch modes (listen all, listen X seconds, no requirement) work for audio
 * [NEW] Audio-specific labels and progress messages throughout the interface
 * [NEW] Database upgrade adds mediatype and audiourl columns
 * Teachers upload audio files (English speaking pieces, evacuation sirens, etc.) and students listen then complete quiz activities
 *
 * v1.0.15: 3 new interactive activity types (matching AI Learning Activities plugin)
 * [NEW] Flashcards - flip cards to review key concepts with front/back format
 * [NEW] True or False Swipe - evaluate statements as true or false with explanations
 * [NEW] Fill in the Blank - complete passages using words from a word bank
 * [NEW] Format checkboxes for all 3 new types in the generation form
 * [NEW] Full dark mode support for all new activity types
 * Now supports 9 question formats: MCQ, Card Select, Matching, Ordering, Column Sort, Category Sort, Flashcards, True/False, Fill in the Blank
 *
 * v1.0.14: Fix Moodle 4.x upgrade crash - replace removed MESSAGE_DEFAULT_LOGGEDIN/LOGGEDOFF constants with MESSAGE_PERMITTED in messages.php
 *
 * v1.0.13: Deep learning question engine
 * [NEW] Overhauled AI system prompt from "assessment writer" to "learning architect" — questions now built around decision points, consequences, trade-offs, and competing approaches extracted from content
 * [NEW] Deep content analysis step extracts hidden consequences, common failures, edge cases, cause-effect chains, and underlying logic before generating questions
 * [NEW] Per-type quality guidance: MCQs must present dilemmas, matching must reveal non-obvious relationships, ordering must be counterintuitive, sorts must require judgement
 * [NEW] "Monday Morning Test" — every question must change what the learner does at work
 * [NEW] Explanation quality rules with banned patterns, required structure (principles, evidence, consequences, statistics), and BAD/GOOD examples
 * [FIX] Eliminated generic explanations like "This sorting exercise clarifies the impact of..." — explanations now teach underlying principles, cite evidence, and reveal real-world consequences
 *
 * v1.0.12: Scenario quality & mixed mode fix
 * [FIX] Industry inputs now generate authentic workplace scenarios with specific settings, roles, equipment, and terminology — not generic questions with industry name inserted
 * [FIX] Mixed mode now enforces strict half-application / half-scenario split — application questions no longer get forced into scenario format by Bloom level enforcement
 * [FIX] Application questions explicitly exclude scenario wrappers — focus on conceptual understanding, comparison, and analysis
 * [FIX] Cognitive Depth Rule no longer overrides question type selection at Bloom Level 3+
 *
 * v1.0.11: Answer-feedback pairing fix
 * [FIX] Fixed critical bug where correct feedback appeared with wrong answer option
 * [FIX] Added fixExplanationOrder to all 3 MCQ generation paths (initial, regenerate-settings, regenerate-instructions)
 * [FIX] AI correctAnswer index now corrected to match "Correct." explanation position before shuffling
 *
 * v1.0.9: High-Rigour Question Generation Engine
 * [NEW] Rewritten OpenAI system prompt: psychometrically informed assessment writer with cognitive depth rules
 * [NEW] Two-step generation: internal concept extraction before question construction
 * [NEW] Cognitive enforcement block: self-validation loop rejects weak questions before output
 * [NEW] Distractor engineering rules: plausible, competitive, grounded in content misunderstandings
 * [NEW] Transcript usage rule: no more than 6 consecutive words reused from source content
 * [NEW] Mandatory scenario enforcement at Bloom Level 3+ (every question must start with a scenario)
 * [FIX] Token budget reduced from 800 to 450 per question for tighter, sharper output
 * [FIX] Stronger quality requirements: no transcript echoing, no generic comprehension, diverse concept coverage
 *
 * v1.0.8: Full Edit Support for All Question Types
 * [NEW] Matching questions: edit left/right pairs and explanation
 * [NEW] Ordering questions: edit step items and explanation
 * [NEW] Column Sort questions: edit column names, item text, column assignments, and explanation
 * [NEW] Category Sort questions: edit category names, item text, category assignments, and explanation
 * [FIX] All 6 question types now fully editable (MCQ, Card Select, Matching, Ordering, Column Sort, Category Sort)
 *
 * v1.0.7: Direct Access Crash Fix
 * [FIX] view.php and report.php no longer crash when accessed without course module ID
 * [FIX] Supports both ?id= and ?a= parameters for resilient page resolution
 * [FIX] Graceful error message instead of fatal exception for invalid/missing parameters
 *
 * v1.0.6: Form Submission Bug Fix
 * [FIX] Pressing Enter in form fields no longer causes "missing id parameter" error
 * [FIX] Native form submission prevented - all actions use AJAX only
 *
 * v1.0.5: Credit Balance Display Fix
 * [FIX] Credit balance now displays in cost banner ("Your balance: X credits") instead of showing "--"
 * [FIX] Progress section balance also updates correctly
 * [NEW] Cost formula now shows detailed breakdown (word credits + voiceover credits)
 * [NEW] Transcript cost info dynamically updates to mention voiceover cost when enabled
 *
 * v1.0.4: Voiceover Credit Cost & Timestamp Stripping
 * [NEW] Voiceover adds per-question credit cost to total (1 credit per question)
 * [NEW] Cost display updates dynamically when voiceover is toggled on/off
 * [FIX] Timestamps (e.g. 0:11, 1:02:30) stripped from transcript before word count
 * [FIX] Fair credit calculation - timestamps no longer inflate word count
 *
 * v1.0.3: Card Select replaces True/False
 * [NEW] Card Select question type - 4 visual cards with icon, label, and description
 * [NEW] AI generates distinct concept cards instead of binary True/False statements
 * [NEW] Card shuffle for students, teacher edit support for cards
 * [REMOVED] True/False question type replaced by Card Select
 *
 * v1.0.2: Auto-fetch YouTube Transcript
 * [NEW] Auto-fetch transcript button - automatically pulls captions from YouTube videos
 * [NEW] Falls back gracefully to manual paste if captions unavailable
 * [NEW] Status messages showing fetch progress, success, or fallback instructions
 *
 * v1.0.1: 6 Question Types + Bloom's Taxonomy + Try Again Flow
 * [NEW] 6 question types: MCQ, True/False, Matching, Ordering, Column Sort, Category Sort
 * [NEW] Bloom's Taxonomy levels 1-6 for question difficulty targeting
 * [NEW] Sequential Try Again flow for incorrect answers
 * [NEW] 3 watch modes: must watch all, watch X seconds, no requirement
 * [FIX] Quiz generation and grading improvements
 *
 * v1.0.0: Initial release
 * [NEW] YouTube video player with watch gating (must watch all / X seconds / none)
 * [NEW] AI-generated MCQ questions from video transcript
 * [NEW] 52-language Chirp 3 HD voiceover support
 * [NEW] Confetti on perfect score / passing grade
 * [NEW] Grading, attempts, and reporting system
 *
 * @package    mod_aivideoactivity
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'mod_aivideoactivity';
$plugin->version   = 2026072300228;
$plugin->requires = 2022041900; // Moodle 4.0
$plugin->supported  = [400, 500];  // Moodle 4.0 to 5.x
$plugin->maturity = MATURITY_STABLE;
$plugin->release   = '1.0.135'; // FIX-VA-CARDSELECT-PREWARM: pre-warm missing per-card audio slots when question displays. // FIX-VA-NULL-CORRECTANSWER: MCQ shuffle silently defaulted newCorrectIndex to 0 when correctAnswer was null (not undefined) in questiondata — parseInt(null)=NaN so origIndex===NaN was never true, making the first displayed option always appear correct regardless of what the AI designated. Fix: use != null guard (covers both null and undefined), compute correctIdx once outside the loop with NaN/OOB clamping. Same null-guard applied to checkAnswer(), saveQuestionsToDatabase(), saveEditedQuestions(), and both audio-regen API payload builders so null/undefined never enters the DB or the correctness check. No DB schema changes. Savepoint 2026051400130. // FIX-VA-CARDSELECT-AUDIO-NOEXPL-ALIGN: removed && origExplanations gate from Pass 1.5 audio-length alignment. Questions with per-card audio (audioData.length === cards.length) but NO per-card explanations (origExplanations null) caused Pass 1.5 to skip entirely — the AI's "Correct." TTS clip stayed at slot 0 regardless of correctIndex, producing "Incorrect." voiceover when student selected the right card. No DB schema changes. Savepoint 2026051300128. // FIX-VA-CARDSELECT-AUDIO-LENGTH-ALIGN: dual-layer fix for remaining "correct card says Incorrect." voiceover on cardselect questions where server previously fixed explanation TEXT order (explanations[correctIndex]="Correct.") but NOT audio order (audioData[0] still held the "Correct." TTS clip). The v1.0.125 client-side Pass 1 skipped these questions because their text was already correct, leaving audio misaligned. Fix adds Pass 1.5 in JS (base64-length heuristic: if audioData[0] is 10%+ longer than audioData[correctIndex] and correctIndex!=0, swap — "Correct.[long explanation]" is reliably longer than "Incorrect.[short label]") plus an identical PHP server-side healing block in ajax.php getquestions (runs before the client sees the data). No DB schema changes. Savepoint 2026051300127. // FIX-CURL-BATCH (v1.0.126): ajax.php switched all raw curl_init() calls to Moodle \curl wrapper. Savepoint 2026051200126.
