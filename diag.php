<?php
// AI Video Activity — Diagnostic Tool v1.0.119
// Access: /mod/aivideoactivity/diag.php?id=<cmid>
// Requires: site admin or moodle/site:config capability.
// Safe to ship — no data is modified and no external requests are made.
//
// ════════════════════════════════════════════════════════════════════════════
// DIAG COVERAGE RULE — Every diagnostic section covers exactly one layer of
// the stack. When adding a new feature, a corresponding diag section MUST be
// added for each layer that feature touches. Layers are:
//
//  LAYER 1 — DB PERSISTENCE (Section 1)
//      What is stored in aivideoactivity_questions after generation.
//      Check: explanations[], correctIndex, audioData alignment for cardselect.
//      e.g. Does explanations[correctIndex] start with "Correct."?
//
//  LAYER 2 — CARD SELECT EXPLANATION ORDER (Section 2)
//      The FIX-VA-CARDSELECT-EXPLANATION-ORDER check (v1.0.119).
//      Check: for each cardselect question, is explanations[correctIndex] the
//      one that starts with "Correct."?
//
//  LAYER 3 — AUDIO DATA ALIGNMENT (Section 3)
//      Per-card TTS clips added in v1.0.117.
//      Check: audioData.length === cards.length for every cardselect question.
//
//  LAYER 4 — PHP → JS CONFIG (Section 4)
//      What view.php injects into the JS via js_init_call / M.util.js_init_call.
//      Check: voiceoverEnabled, scoringMode, showChapterStamps visible in source.
//
//  LAYER 5 — AMD BUILD (Section 5)
//      The compiled videoactivity.min.js served to the browser.
//      Check: key Card Select symbols present in the built file.
//
//  LAYER 6 — SOURCE INPUT (Section 6)
//      The raw activity record fields needed for generation.
//      Check: videourl or audiosourcetype set, transcript or audiofileurl present.
//
// ════════════════════════════════════════════════════════════════════════════

require_once(__DIR__ . '/../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

// Accept either ?id=X or ?cmid=X (view.php uses ?id= for the cmid).
$cmid = optional_param('id', 0, PARAM_INT) ?: optional_param('cmid', 0, PARAM_INT);
if (!$cmid) {
    print_error('missingparam', 'error', '', 'id or cmid');
}

// Graceful cmid lookup — check if it's a course ID and offer a picker.
$cm_raw = get_coursemodule_from_id('aivideoactivity', $cmid, 0, false, IGNORE_MISSING);
if (!$cm_raw) {
    $css = '<style>body{font-family:sans-serif;margin:2rem;background:#f5f5f5;color:#111;}
h2{font-size:1.2rem;margin-bottom:.5rem;}
.box{background:#fff;border:1px solid #ddd;border-radius:6px;padding:1.2rem 1.5rem;max-width:750px;margin-bottom:1.5rem;}
.err{background:#f8d7da;color:#721c24;}.info{background:#d1ecf1;color:#0c5460;}
table{width:100%;border-collapse:collapse;margin-top:.5rem;}
td,th{padding:.4rem .7rem;font-size:.85rem;border-bottom:1px solid #eee;text-align:left;}
th{background:#f0f0f0;font-weight:600;}a{color:#0070f3;}code{background:#eee;padding:2px 5px;border-radius:3px;}</style>';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>VA Diag — Pick activity</title>' . $css . '</head><body>';
    echo '<h2>AI Video Activity Diagnostic — Choose an Activity</h2>';
    $course_check = $DB->get_record('course', ['id' => $cmid], 'id,fullname', IGNORE_MISSING);
    if ($course_check) {
        echo '<div class="box info"><p><strong>' . htmlspecialchars($course_check->fullname) . '</strong> (course id=' . $cmid . ') — that is a <strong>course</strong> id, not an activity id.</p><p>Pick a Video Activity from this course below:</p></div>';
        $cms = $DB->get_records_sql(
            "SELECT cm.id AS cmid, cm.instance FROM {course_modules} cm JOIN {modules} m ON m.id = cm.module WHERE cm.course = :cid AND m.name = 'aivideoactivity' ORDER BY cm.id ASC",
            ['cid' => $cmid]
        );
        if ($cms) {
            echo '<div class="box"><table><tr><th>Activity</th><th>cmid</th><th>Action</th></tr>';
            foreach ($cms as $r) {
                $name = $DB->get_field('aivideoactivity', 'name', ['id' => $r->instance]);
                $url  = new moodle_url('/mod/aivideoactivity/diag.php', ['id' => $r->cmid]);
                echo '<tr><td>' . htmlspecialchars($name ?: '(unnamed)') . '</td><td>' . $r->cmid . '</td><td><a href="' . $url->out() . '">Run diag</a></td></tr>';
            }
            echo '</table></div>';
        } else {
            echo '<div class="box err"><p>No Video Activity activities found in this course.</p></div>';
        }
    } else {
        echo '<div class="box err"><p><strong>id=' . $cmid . ' is not a valid Video Activity or course id.</strong></p>';
        echo '<p>Navigate to a Video Activity in Moodle and copy the <code>id=</code> number from the URL.</p></div>';
    }
    echo '</body></html>';
    exit;
}

list($course, $cm) = get_course_and_cm_from_cmid($cmid, 'aivideoactivity');
$activity = $DB->get_record('aivideoactivity', ['id' => $cm->instance], '*', MUST_EXIST);

// ── Helpers ────────────────────────────────────────────────────────────────────

function va_diag_pass(string $label, string $value = ''): string {
    return '<tr><td class="label">' . htmlspecialchars($label) . '</td>'
         . '<td class="pass">PASS</td>'
         . '<td class="val">' . htmlspecialchars($value) . '</td></tr>';
}

function va_diag_fail(string $label, string $detail = ''): string {
    return '<tr><td class="label">' . htmlspecialchars($label) . '</td>'
         . '<td class="fail">FAIL</td>'
         . '<td class="val">' . htmlspecialchars($detail) . '</td></tr>';
}

function va_diag_info(string $label, string $value = ''): string {
    return '<tr><td class="label">' . htmlspecialchars($label) . '</td>'
         . '<td class="info">INFO</td>'
         . '<td class="val">' . htmlspecialchars($value) . '</td></tr>';
}

$rows_db        = '';
$rows_expl      = '';
$rows_audio     = '';
$rows_phpjs     = '';
$rows_amd       = '';
$rows_source    = '';
$overall_pass   = true;

// ── SECTION 1: DB persistence — stored questions ───────────────────────────────

$db_questions = $DB->get_records('aivideoactivity_questions',
    ['aivideoactivityid' => $activity->id],
    'questionnumber ASC'
);

if (empty($db_questions)) {
    $rows_db .= va_diag_info('Stored questions', 'No questions generated yet for this activity');
} else {
    $total = count($db_questions);
    $mcq_count       = 0;
    $cardselect_count = 0;
    $other_count     = 0;
    foreach ($db_questions as $dq) {
        $qd = json_decode($dq->questiondata ?? '{}', true);
        $type = $qd['type'] ?? 'unknown';
        if ($type === 'mcq')         $mcq_count++;
        elseif ($type === 'cardselect') $cardselect_count++;
        else                           $other_count++;
    }
    $rows_db .= va_diag_info('Stored questions total', "$total questions (MCQ: $mcq_count, Card Select: $cardselect_count, Other: $other_count)");

    // Check each question's questiondata JSON parses cleanly
    $bad_json = 0;
    foreach ($db_questions as $dq) {
        if (empty($dq->questiondata)) {
            $bad_json++;
        } else {
            $decoded = json_decode($dq->questiondata, true);
            if (!is_array($decoded)) $bad_json++;
        }
    }
    if ($bad_json > 0) {
        $rows_db .= va_diag_fail('questiondata JSON integrity', "$bad_json / $total questions have invalid or empty questiondata JSON");
        $overall_pass = false;
    } else {
        $rows_db .= va_diag_pass('questiondata JSON integrity', "All $total questions have valid JSON questiondata");
    }
}

// ── SECTION 2: Card Select — explanation order check (FIX-VA-CARDSELECT-EXPLANATION-ORDER v1.0.119) ──

if (empty($db_questions)) {
    $rows_expl .= va_diag_info('Card Select questions', 'No questions stored yet');
} else {
    $cs_questions = [];
    foreach ($db_questions as $dq) {
        $qd = json_decode($dq->questiondata ?? '{}', true);
        if (($qd['type'] ?? '') === 'cardselect') {
            $cs_questions[] = ['row' => $dq, 'qd' => $qd];
        }
    }

    if (empty($cs_questions)) {
        $rows_expl .= va_diag_info('Card Select questions', 'No Card Select questions found in this activity');
    } else {
        $total_cs       = count($cs_questions);
        $order_ok       = 0;
        $order_fail     = 0;
        $missing_expl   = 0;
        $extra_correct  = 0;

        foreach ($cs_questions as $csq) {
            $qd           = $csq['qd'];
            $qnum         = $csq['row']->questionnumber ?? '?';
            $cards        = $qd['cards'] ?? [];
            $correctIndex = isset($qd['correctIndex']) ? intval($qd['correctIndex']) : 0;
            $explanations = $qd['explanations'] ?? null;

            if (!is_array($explanations) || count($explanations) !== count($cards)) {
                $rows_expl .= va_diag_fail(
                    "Q{$qnum} Card Select explanations[]",
                    'explanations[] is missing or has wrong length (' . count($explanations ?? []) . ' vs ' . count($cards) . ' cards). Run Regenerate Audio to rebuild.'
                );
                $missing_expl++;
                $overall_pass = false;
                continue;
            }

            // Check that explanations[correctIndex] starts with "Correct."
            $correct_expl = isset($explanations[$correctIndex]) ? trim($explanations[$correctIndex]) : '';
            $starts_correct = (stripos($correct_expl, 'correct') === 0);

            if (!$starts_correct) {
                // Find where "Correct." actually is
                $found_at = -1;
                foreach ($explanations as $ei => $etxt) {
                    if (stripos(trim($etxt), 'correct') === 0) {
                        $found_at = $ei;
                        break;
                    }
                }
                if ($found_at === -1) {
                    $rows_expl .= va_diag_fail(
                        "Q{$qnum} explanations[correctIndex={$correctIndex}]",
                        "No explanation starts with 'Correct.' — all four explanations appear to be 'Incorrect.' text. Re-generate this question."
                    );
                } else {
                    $rows_expl .= va_diag_fail(
                        "Q{$qnum} explanations[correctIndex={$correctIndex}]",
                        "'Correct.' explanation is at index {$found_at}, not at correctIndex={$correctIndex}. This is the FIX-VA-CARDSELECT-EXPLANATION-ORDER bug. Regenerate this question with v1.0.119+ server to fix."
                    );
                }
                $order_fail++;
                $overall_pass = false;
            } else {
                // Also check no wrong-card explanation starts with "Correct."
                $extra_ok = true;
                foreach ($explanations as $ei => $etxt) {
                    if ($ei === $correctIndex) continue;
                    if (stripos(trim($etxt), 'correct') === 0) {
                        $rows_expl .= va_diag_fail(
                            "Q{$qnum} explanations[{$ei}] (wrong card)",
                            "Wrong-card explanation at index {$ei} starts with 'Correct.' — student clicking this card would see 'Correct.' feedback. Regenerate with v1.0.119+ to fix."
                        );
                        $extra_correct++;
                        $extra_ok = false;
                        $overall_pass = false;
                    }
                }
                if ($extra_ok) {
                    $short = mb_substr($correct_expl, 0, 60) . (mb_strlen($correct_expl) > 60 ? '…' : '');
                    $rows_expl .= va_diag_pass(
                        "Q{$qnum} explanations[correctIndex={$correctIndex}]",
                        '"' . $short . '"'
                    );
                    $order_ok++;
                }
            }
        }

        if ($order_fail === 0 && $extra_correct === 0 && $missing_expl === 0) {
            $rows_expl .= va_diag_pass(
                'Card Select explanation order (all questions)',
                "All {$total_cs} Card Select questions have explanations[correctIndex] starting with 'Correct.' — FIX-VA-CARDSELECT-EXPLANATION-ORDER is clean"
            );
        } else {
            $rows_expl .= va_diag_info(
                'Card Select explanation order summary',
                "OK: {$order_ok}, wrong-order: {$order_fail}, extra-Correct.: {$extra_correct}, missing explanations[]: {$missing_expl}"
            );
        }
    }
}

// ── SECTION 3: Audio data alignment ───────────────────────────────────────────

if (empty($db_questions)) {
    $rows_audio .= va_diag_info('Card Select audio data', 'No questions stored yet');
} else {
    $cs_total   = 0;
    $ad_ok      = 0;
    $ad_single  = 0;
    $ad_missing = 0;
    $ad_wrong   = 0;

    foreach ($db_questions as $dq) {
        $qd = json_decode($dq->questiondata ?? '{}', true);
        if (($qd['type'] ?? '') !== 'cardselect') continue;
        $cs_total++;
        $qnum     = $dq->questionnumber ?? '?';
        $cards    = $qd['cards'] ?? [];
        $nc       = count($cards);
        $audioData = $qd['audioData'] ?? null;

        if (!is_array($audioData) || count($audioData) === 0) {
            $rows_audio .= va_diag_info(
                "Q{$qnum} audioData",
                "No audioData — voiceover is off or question was created before v1.0.110. v1.0.118 Chirp top-up will supply clips on demand during student playback."
            );
            $ad_missing++;
        } elseif (count($audioData) === 1 && $nc > 1) {
            $rows_audio .= va_diag_fail(
                "Q{$qnum} audioData length",
                "audioData has 1 clip for {$nc} cards (pre-v1.0.117 generation). Wrong-card clicks fall back to Chirp top-up (v1.0.118). Regenerate Audio to get {$nc} per-card clips and eliminate top-up latency."
            );
            $ad_single++;
            $overall_pass = false;
        } elseif (count($audioData) === $nc) {
            $empty_slots = count(array_filter($audioData, fn($c) => empty($c)));
            if ($empty_slots > 0) {
                $rows_audio .= va_diag_info(
                    "Q{$qnum} audioData",
                    count($audioData) . " clips ({$empty_slots} empty slot(s) — Chirp top-up will fill them on demand)"
                );
            } else {
                $rows_audio .= va_diag_pass(
                    "Q{$qnum} audioData",
                    count($audioData) . " clips aligned with {$nc} cards — per-card TTS is correct"
                );
                $ad_ok++;
            }
        } else {
            $rows_audio .= va_diag_fail(
                "Q{$qnum} audioData length mismatch",
                count($audioData) . " clips vs {$nc} cards — unexpected. Regenerate Audio to fix."
            );
            $ad_wrong++;
            $overall_pass = false;
        }
    }

    if ($cs_total === 0) {
        $rows_audio .= va_diag_info('Card Select audio data', 'No Card Select questions found in this activity');
    } elseif ($ad_wrong === 0 && $ad_single === 0) {
        $rows_audio .= va_diag_pass(
            'Card Select audio alignment (summary)',
            "All {$cs_total} Card Select questions — OK: {$ad_ok}, missing (voiceover off): {$ad_missing}"
        );
    }
}

// ── SECTION 4: PHP → JS config (view.php injected variables) ──────────────────

// We simulate what view.php injects into the page by checking the DB columns
// that feed M.util.js_init_call / js_init_call for this activity.

$voiceover_col = isset($activity->voiceoverenabled) ? intval($activity->voiceoverenabled) : null;
if ($voiceover_col === null) {
    $rows_phpjs .= va_diag_fail('voiceoverEnabled in JS config', 'Column voiceoverenabled missing — DB upgrade may not have run');
    $overall_pass = false;
} elseif ($voiceover_col === 0) {
    $rows_phpjs .= va_diag_info('voiceoverEnabled in JS config', 'Value = 0 (voiceover is OFF for this activity)');
} else {
    $rows_phpjs .= va_diag_pass('voiceoverEnabled in JS config', 'Value = ' . $voiceover_col . ' — voiceover enabled, TTS clips will be played');
}

$scoring_col = isset($activity->scoringmode) ? $activity->scoringmode : null;
if ($scoring_col === null) {
    $rows_phpjs .= va_diag_info('scoringMode in JS config', 'Column scoringmode missing or null — will default in JS');
} else {
    $rows_phpjs .= va_diag_pass('scoringMode in JS config', '"' . $scoring_col . '"');
}

$stamps_col = isset($activity->showchapterstamps) ? intval($activity->showchapterstamps) : null;
if ($stamps_col === null) {
    $rows_phpjs .= va_diag_info('showChapterStamps in JS config', 'Column showchapterstamps missing or null — chapter stamps disabled');
} elseif ($stamps_col === 0) {
    $rows_phpjs .= va_diag_info('showChapterStamps in JS config', 'Value = 0 (chapter stamps OFF for this activity)');
} else {
    $rows_phpjs .= va_diag_pass('showChapterStamps in JS config', 'Value = ' . $stamps_col . ' — chapter stamps enabled');
}

// ── SECTION 5: AMD build check ─────────────────────────────────────────────────

$min_js_path = __DIR__ . '/amd/build/videoactivity.min.js';

if (!file_exists($min_js_path)) {
    $rows_amd .= va_diag_fail('videoactivity.min.js exists', 'File not found at amd/build/videoactivity.min.js — AMD build is missing');
    $overall_pass = false;
} else {
    $min_js = file_get_contents($min_js_path);
    $min_size = strlen($min_js);
    $rows_amd .= va_diag_pass('videoactivity.min.js exists', 'File size: ' . number_format($min_size) . ' bytes');

    // Check for Card Select key symbols
    $symbols = [
        'checkCardSelectAnswer'            => 'Card Select answer checker',
        'fixCardSelectExplanationOrder'    => 'Explanation-order fix label (v1.0.119)',
        'va-card-option'                   => 'Card option CSS class',
        'va-cards-grid'                    => 'Cards grid CSS class',
        'topUpMissingClipAndPlay'          => 'Chirp top-up function (v1.0.118)',
        'explanations'                     => 'explanations[] field reference',
        'correctIndex'                     => 'correctIndex field reference',
        'hasPerCardText'                   => 'Per-card text guard variable',
    ];

    foreach ($symbols as $sym => $desc) {
        if (strpos($min_js, $sym) !== false) {
            $rows_amd .= va_diag_pass("AMD: $sym", $desc);
        } else {
            if (in_array($sym, ['fixCardSelectExplanationOrder'])) {
                // This is a server-side symbol — expected to be absent from JS
                $rows_amd .= va_diag_info("AMD: $sym", "$desc — server-side only, correctly absent from JS");
            } else {
                $rows_amd .= va_diag_fail("AMD: $sym", "$desc — symbol missing from compiled JS; AMD build may be stale");
                $overall_pass = false;
            }
        }
    }

    // Check AMD define wrapper is present (not ES module syntax)
    if (strpos($min_js, "define(") !== false) {
        $rows_amd .= va_diag_pass('AMD define() wrapper', 'Correct AMD format — not ES module syntax');
    } else {
        $rows_amd .= va_diag_fail('AMD define() wrapper', 'define() not found — file may be ES module format which Moodle cannot load');
        $overall_pass = false;
    }
}

// ── SECTION 6: Source input fields ────────────────────────────────────────────

// Check the activity has a video/audio source configured
$videourl = $activity->videourl ?? '';
$audiosourcetype = $activity->audiosourcetype ?? '';
$audiofileurl = $activity->audiofileurl ?? '';

if (!empty($videourl)) {
    $rows_source .= va_diag_pass('videourl', '"' . htmlspecialchars(mb_substr($videourl, 0, 80)) . '"');
} else {
    $rows_source .= va_diag_info('videourl', 'Empty — this activity uses audio or no source configured yet');
}

if (!empty($audiosourcetype)) {
    $rows_source .= va_diag_pass('audiosourcetype', '"' . $audiosourcetype . '"');
    if (!empty($audiofileurl)) {
        $rows_source .= va_diag_pass('audiofileurl', '"' . htmlspecialchars(mb_substr($audiofileurl, 0, 80)) . '"');
    } else {
        $rows_source .= va_diag_info('audiofileurl', 'Empty — audio source type set but no file URL stored yet');
    }
} else {
    $rows_source .= va_diag_info('audiosourcetype', 'Not set (video-only activity or source not yet configured)');
}

// Check transcript is present (needed for question generation)
// FIX-VA-DIAG-TRANSCRIPT-FIELD (v1.0.121): DB column is 'transcripttext', not 'transcript'.
$transcript = $activity->transcripttext ?? '';
if (empty($transcript)) {
    $rows_source .= va_diag_fail('transcript', 'Empty — transcript must be provided before questions can be generated');
    $overall_pass = false;
} else {
    $wc = str_word_count($transcript);
    $rows_source .= va_diag_pass('transcript', "$wc words stored");
}

// ── OUTPUT ─────────────────────────────────────────────────────────────────────

$css = <<<CSS
<style>
body { font-family: sans-serif; margin: 2rem; background: #f5f5f5; color: #111; }
h1   { font-size: 1.4rem; margin-bottom: .25rem; }
h2   { font-size: 1.1rem; margin: 1.5rem 0 .4rem; border-bottom: 1px solid #ccc; padding-bottom: .25rem; }
.box { background: #fff; border: 1px solid #ddd; border-radius: 6px; padding: 1.2rem 1.5rem; max-width: 900px; margin-bottom: 1.5rem; }
.pass-box  { border-color: #5cb85c; background: #f0fff0; }
.fail-box  { border-color: #d9534f; background: #fff5f5; }
table { width: 100%; border-collapse: collapse; margin-top: .5rem; }
td, th { padding: .4rem .7rem; font-size: .85rem; border-bottom: 1px solid #eee; text-align: left; }
th     { background: #f0f0f0; font-weight: 600; }
.label { width: 38%; font-weight: 500; }
.val   { width: 52%; word-break: break-word; }
td.pass, td.fail, td.info { width: 10%; font-weight: 700; text-align: center; }
td.pass { color: #2a7a2a; }
td.fail { color: #c0392b; }
td.info { color: #2980b9; }
a { color: #0070f3; }
code { background: #eee; padding: 2px 5px; border-radius: 3px; }
.overall-pass { background: #d4edda; border: 1px solid #5cb85c; border-radius: 6px; padding: .8rem 1.2rem; max-width: 900px; margin-bottom: 1rem; font-weight: 600; color: #155724; }
.overall-fail { background: #f8d7da; border: 1px solid #d9534f; border-radius: 6px; padding: .8rem 1.2rem; max-width: 900px; margin-bottom: 1rem; font-weight: 600; color: #721c24; }
</style>
CSS;

$view_url   = new moodle_url('/mod/aivideoactivity/view.php', ['id' => $cmid]);
$overall_cls = $overall_pass ? 'overall-pass' : 'overall-fail';
$overall_txt = $overall_pass ? 'ALL CHECKS PASSED' : 'ONE OR MORE CHECKS FAILED — see FAIL rows below';

echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">';
echo '<title>VA Diag — ' . htmlspecialchars($activity->name) . '</title>';
echo $css;
echo '</head><body>';
echo '<h1>AI Video Activity — Diagnostic</h1>';
echo '<p><strong>Activity:</strong> ' . htmlspecialchars($activity->name) . ' &nbsp;|&nbsp; <strong>cmid:</strong> ' . $cmid;
echo ' &nbsp;|&nbsp; <a href="' . $view_url->out() . '">View activity</a></p>';
echo '<div class="' . $overall_cls . '">' . $overall_txt . '</div>';

$sections = [
    ['Section 1: DB Persistence — Stored Questions',                 $rows_db],
    ['Section 2: Card Select Explanation Order (v1.0.119)',          $rows_expl],
    ['Section 3: Card Select Audio Data Alignment (v1.0.117–118)',   $rows_audio],
    ['Section 4: PHP → JS Config (view.php Injected Variables)',     $rows_phpjs],
    ['Section 5: AMD Build — videoactivity.min.js',                  $rows_amd],
    ['Section 6: Source Input Fields',                               $rows_source],
];

foreach ($sections as [$title, $rows]) {
    if (empty($rows)) continue;
    echo '<h2>' . htmlspecialchars($title) . '</h2>';
    echo '<div class="box"><table>';
    echo '<tr><th class="label">Check</th><th>Result</th><th class="val">Detail</th></tr>';
    echo $rows;
    echo '</table></div>';
}

echo '<p style="color:#888;font-size:.8rem;margin-top:2rem">AI Video Activity Diagnostic v1.0.119 — Read-only. No data was modified.</p>';
echo '</body></html>';
