# Changelog - AI Video Activity Module

All notable changes to this plugin will be documented in this file.

## [1.0.70] - 2026-04-23

### Fixed
- **FIX-VA-AUDIO-NODECOUNT** — Web Audio API oscillator and gainNode pairs were never disconnected from the AudioContext after stopping. Chrome enforces a limit of ~100 AudioNodes and silently drops all subsequent audio once it is reached. This caused voiceover/sound effects to stop playing after question 3 or 4. Fixed by adding `oscillator.onended` disconnect handlers in `playCorrectSound`, `playIncorrectSound`, `playLevelCompleteSound`, and `playTryAgainSound` so each node is cleanly disconnected from the context immediately after playback ends.
- **FIX-VA-EDIT-ICON-PICKER** — Card Select question type in edit mode was missing icon editing: icon SVGs disappeared from the edit form and there was no control to change them. The `buildEditForms()` function for `cardselect` now renders a `<select>` dropdown listing all 15 supported icon names with a live SVG preview. `saveEdits()` reads the chosen value and persists it to the card data so the teacher-selected icon survives Save and re-edit cycles.

### Added
- **Scoring mode setting** — New activity-level option in Grade Settings: "Retry until correct" (default, preserves existing behavior) vs "First attempt only" (score reflects genuine accuracy). In first-attempt mode MCQ, Card Select, Ordering, Matching, and Fill-in-the-Blank questions all move the student forward after a wrong answer without resetting the question for retry. Score is calculated from the answers given on the first attempt only — wrong answers count as 0 points — so teachers get a true assessment result instead of everyone finishing at 100%. DB migration adds `scoringmode INT(1) DEFAULT 0` column. JS config passes `scoringMode` integer from `view.php` to both teacher-preview and student AMD call.

## [1.0.53] - 2026-04-09

### Added
- **Matching question redesign** — Replaced the plain dropdown-per-row layout with a polished two-column click-to-pair card UI. Terms appear in the left column, shuffled definitions in the right column. Selecting a term highlights it (blue); clicking a definition pairs them with a numbered colour-coded badge (each pair gets a unique accent colour from an 8-colour palette). Mis-paired items can be re-selected at any time before submitting. A "Check Matches" button appears only once all pairs are formed. On submit: correct pairs turn green with a tick SVG, incorrect pairs turn red with an X SVG, incorrect pairs clear after 1.8 s for retry. A progress counter ("2 of 4 matched") shows while pairing. The instruction text updates contextually ("Click a term…" → "Now click the matching definition…"). No DB changes.
- **Results screen redesign** — End-of-quiz screen now matches the Knowledge Check polish. `showResults()` dynamically builds a full `va-results-card` with: gradient header badge, animated SVG score ring with percentage counter (eased animation, tier-coloured), performance-tier title + message (Perfect / Well Done / Excellent / Good Progress / Keep Practicing), stats row (Correct / Incorrect / Questions), optional passing-grade pill (green ✓ / red ✗), and context-aware action buttons (Retake Quiz with remaining-attempts label + Back to Course). CSS adds `.va-score-percent.excellent` and `.va-score-percent.needs-work` colour variants. `courseUrl` added to both teacher and student JS configs so the Back to Course button is always correct. No DB changes.

### Fixed
- **FIX-VA-FLASHCARD-DOUBLE** — `advanceCard()` now has a `finished` guard flag that fires `score++` at most once per flashcard question, even if the student clicks "Got it!" or "Still learning" multiple times on the last card before the Next button appears. The options area is cleared immediately when the set completes, so the self-assessment buttons are no longer clickable after submission.
- **FIX-VA-FLASHCARD-WIDTH** — `.va-flashcards-container` now has explicit `display:block; width:100%; min-width:0; box-sizing:border-box` and `.va-flashcard-wrapper` gains `width:100%; min-width:0` to prevent Moodle theme flex contexts from shrinking the flashcard to content width instead of filling the quiz panel.

## [1.0.52] - 2026-04-09

### Added
- **Show video above questions** — New activity setting (`showvideoduringquiz`). When enabled, the YouTube player stays visible above the quiz panel while the student answers questions; only the watch-progress bar and action buttons are hidden. When disabled (default) the video section hides as before.
- **Show chapter timestamp links** — New activity setting (`showchapterstamps`). When enabled, a clickable "Jump to X:XX" button appears above each question. Clicking seeks the video to the AI-identified timestamp nearest to the chapter that question covers and resumes playback. The AI prompt now returns `timestamp_seconds` (int|null) per question.

All notable changes to this plugin will be documented in this file.

## [1.0.44] - 2026-04-02

### Fixed
- **BUG-VA-MCQ-LABEL** — MCQ answer checking now uses letter-label comparison (A/B/C/D) to eliminate type-coercion false-negatives. A `selectedLabel` variable stores the clicked option's letter via `data-label` attribute; `checkAnswer` compares `selectedLabel` against `letters[parseInt(correctAnswer)]` — completely sidestepping any `string === integer` mismatch. Falls back to index comparison when `selectedLabel` is `null`. Correct-answer highlighting also uses `parseInt(correctAnswer)`. `selectedLabel` is reset to `null` at the same points `selectedAnswer` is reset (module init, `startStudentQuiz`, `showQuestion`, cardselect try-again). `version.php` → `2026040200144`.

## [1.0.43] - 2026-04-02

### Fixed
- **BUG-VA-SHUFFLE-EDIT** — When shuffle was enabled, the MCQ edit modal rendered options in shuffled order. Any teacher save went back to the server in shuffled order with the shuffled `correctAnswer` index — so the next student received double-shuffled options with the wrong correct answer highlighted. Fix: before rendering the edit modal loop, de-shuffle `editOptions` and `editExplanations` using the `shuffledToOriginal` mapping already stored at shuffle time (`origIdx = shuffledToOriginal[shuffIdx]`). `editCorrect` uses `originalCorrectIndex` for the correct-radio pre-selection. Saves now go to the server in canonical option order. `version.php` → `2026040200143`.

## [1.0.40] - 2026-03-28

### Fixed
- **MCQ-GRADING**: `correctAnswer`/`correctIndex` returned from the DB as strings were compared with `===` against the integer `selectedIndex` — every MCQ question marked the student's answer Wrong unless they chose option 0. Fix: `parseInt()` applied to both values before comparison.
- **ORDERING-VOICEOVER-WRONG**: The wrong-answer branch in ordering questions called `q.audioData[0].play()` (praise voiceover) on every incorrect attempt. Removed the erroneous playback call.

## [1.0.39] - 2026-03-27

### Fixed
- **BUG-VA-REGEN-TIMEOUT** — `ajaxCall()` had no timeout parameter, so `regenerateinstructions` calls could silently hang indefinitely on slow AI responses. Added optional `timeout` argument to `ajaxCall()`; the regenerateinstructions call passes 180 000 ms (matching the PHP CURL timeout).
- **BUG-VA-REGEN-TYPES** — `handleRegenerate()` mapped `quizData` to a subset object containing only `question`, `options`, `explanations`, and `correctAnswer`, stripping the `type` field. The server then regenerated every question as MCQ regardless of original type. Fixed by passing the full `quizData` objects so the server receives the `type` field and preserves non-MCQ question structures.

## [1.0.38] - 2026-03-27

### Fixed
- **VOICEOVER-NON-MCQ** — Voiceover was silent for all non-MCQ question types (matching, ordering, column sort, category sort). The three audio regeneration routes only processed `q.explanations` (MCQ-only array); non-MCQ types use `q.explanation` (singular). All three routes now detect non-MCQ types and generate `audioData[0]` from `q.explanation`.
- **ORDERING-VOICEOVER-ON-WRONG** — When a student checked an ordering question and got it wrong, no voiceover played. The incorrect path now plays `audioData[0]` after showing the incorrect feedback message.
