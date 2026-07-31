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
 * AJAX handler for AI Video Activity.
 *
 * @package    mod_aivideoactivity
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$action = required_param('action', PARAM_ALPHA);
$sesskey = required_param('sesskey', PARAM_RAW);

// Validate session.
if (!confirm_sesskey($sesskey)) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Invalid session']);
    exit;
}

// Actions that require cmid and capability check.
$secured_actions = ['generate', 'status', 'getcredits', 'getindustries', 'regenerateaudio', 'regeneratewithsettings', 'regenerateinstructions', 'savevoicesettings', 'savevideosettings', 'ttssingle'];

// Get cmid for secured actions.
$cmid = 0;
$cm = null;
$course = null;
$videoactivity = null;
$context = null;

if (in_array($action, $secured_actions)) {
    $cmid = required_param('cmid', PARAM_INT);
    $cm = get_coursemodule_from_id('aivideoactivity', $cmid, 0, false, MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
    $videoactivity = $DB->get_record('aivideoactivity', ['id' => $cm->instance], '*', MUST_EXIST);
    
    require_login($course, false, $cm);
    $context = context_module::instance($cm->id);
    
    // Generate and regenerate actions require create capability.
    if ($action === 'generate' || $action === 'regenerateaudio' || $action === 'regeneratewithsettings' || $action === 'regenerateinstructions' || $action === 'savevoicesettings' || $action === 'savevideosettings') {
        require_capability('mod/aivideoactivity:create', $context);
    } else {
        require_capability('mod/aivideoactivity:view', $context);
    }
}

// Release session lock before long-running API calls to prevent blocking other requests.
\core\session\manager::write_close();

// Get configuration.
$apibase = get_config('mod_aivideoactivity', 'apiurl');
if (empty($apibase)) {
    $apibase = 'https://lms-labs.com';
}
// Remove trailing slash if present.
$apibase = rtrim($apibase, '/');

// Explicitly include aiconfig lib.php if available
$aiconfiglib = $CFG->dirroot . '/local/aiconfig/lib.php';
if (file_exists($aiconfiglib)) {
    require_once($aiconfiglib);
}

// Priority 1: Central Config (recommended for multi-plugin setups)
$siteid = '';
$apikey = '';
if (function_exists('local_aiconfig_get_siteid')) {
    $siteid = trim(local_aiconfig_get_siteid() ?? '');
}
if (function_exists('local_aiconfig_get_apikey')) {
    $apikey = trim(local_aiconfig_get_apikey() ?? '');
}

// Priority 2: Plugin settings as fallback
if (empty($siteid)) {
    $siteid = trim(get_config('mod_aivideoactivity', 'siteid') ?? '');
}
if (empty($apikey)) {
    $apikey = trim(get_config('mod_aivideoactivity', 'apikey') ?? '');
}

header('Content-Type: application/json');

switch ($action) {
    case 'getcredits':
        // Debug: Check if credentials are configured (including whitespace-only check).
        if (strlen($siteid) === 0 || strlen($apikey) === 0) {
            echo json_encode([
                'ok' => false, 
                'error' => 'Plugin not configured: Missing Site ID or API Key. Go to Site admin → Plugins → Activity modules → AI Video Activity.',
                'debug' => [
                    'hasSiteId' => strlen($siteid) > 0,
                    'hasApiKey' => strlen($apikey) > 0,
                    'apiBase' => $apibase,
                    'siteIdLength' => strlen($siteid),
                    'apiKeyLength' => strlen($apikey)
                ]
            ]);
            break;
        }

        // Fetch credits from API (GET request with query parameters).
        // Note: Must specify '&' as separator - some PHP configs default to '&amp;' which breaks URLs
        $url = $apibase . '/api/credits?' . http_build_query([
            'siteId' => $siteid,
            'apiKey' => $apikey,
        ], '', '&');

        $curl = new \curl();
        $curl->setopt(['CURLOPT_TIMEOUT' => 30, 'CURLOPT_SSL_VERIFYPEER' => true, 'CURLOPT_FOLLOWLOCATION' => true]);
        $response = $curl->get($url);
        $curlerror = $curl->error;
        $httpcode = $curl->info['http_code'];

        if ($curlerror) {
            echo json_encode([
                'ok' => false, 
                'error' => 'Connection failed: ' . $curlerror,
                'debug' => ['httpCode' => $httpcode, 'curlError' => $curlerror]
            ]);
            break;
        }

        if ($httpcode === 200) {
            $result = json_decode($response, true);
            if (isset($result['credits'])) {
                echo json_encode(['ok' => true, 'credits' => $result['credits']]);
            } else {
                echo json_encode([
                    'ok' => false, 
                    'error' => 'Invalid API response format',
                    'debug' => ['httpCode' => $httpcode, 'response' => substr($response, 0, 200)]
                ]);
            }
        } else {
            $result = json_decode($response, true);
            echo json_encode([
                'ok' => false, 
                'error' => isset($result['error']) ? $result['error'] : 'API returned HTTP ' . $httpcode,
                'debug' => [
                    'httpCode' => $httpcode, 
                    'response' => substr($response, 0, 200),
                    'requestUrl' => preg_replace('/apiKey=[^&]+/', 'apiKey=[REDACTED]', $url),
                    'siteIdSent' => $siteid,
                    'apiKeyLengthSent' => strlen($apikey)
                ]
            ]);
        }
        break;

    case 'getindustries':
        // Fetch industries list.
        $url = $apibase . '/api/industries';
        $curl = new \curl();
        $curl->setopt(['CURLOPT_TIMEOUT' => 15]);
        $response = $curl->get($url);
        $httpcode = $curl->info['http_code'];

        if ($httpcode === 200) {
            $result = json_decode($response, true);
            echo json_encode(['ok' => true, 'industries' => $result['industries'] ?? []]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Failed to fetch industries']);
        }
        break;

    case 'generate':
        // Start video activity question generation from transcript.
        $transcriptraw = required_param('transcript', PARAM_RAW);
        $transcript = clean_param($transcriptraw, PARAM_TEXT);
        
        // Validate transcript not empty.
        if (empty(trim($transcript))) {
            echo json_encode(['ok' => false, 'error' => 'Transcript is required']);
            break;
        }
        
        // Validate minimum length.
        if (strlen(trim($transcript)) < 50) {
            echo json_encode(['ok' => false, 'error' => 'Transcript must be at least 50 characters']);
            break;
        }
        
        // Limit transcript length to prevent abuse.
        if (strlen($transcript) > 50000) {
            echo json_encode(['ok' => false, 'error' => 'Transcript text too long (max 50,000 characters)']);
            break;
        }
        
        $numquestions = optional_param('numQuestions', 5, PARAM_INT);
        $numquestions = max(1, min(20, $numquestions)); // Clamp between 1-20.
        
        // Voice settings.
        $voiceoverenabled = optional_param('voiceoverEnabled', 0, PARAM_INT);
        $voicelanguage = optional_param('voiceLanguage', 'en-AU', PARAM_TEXT);
        $voicegender = optional_param('voiceGender', 'female', PARAM_ALPHA);
        $voiceid = optional_param('voiceId', 'Aoede', PARAM_ALPHA);
        
        // Extra AI instructions (sanitized).
        $extrainstructions = clean_param(optional_param('extraInstructions', '', PARAM_TEXT), PARAM_TEXT);
        if (strlen($extrainstructions) > 2000) {
            $extrainstructions = substr($extrainstructions, 0, 2000);
        }

        // Question type and scenario context.
        $questiontype = optional_param('questionType', 'application', PARAM_ALPHA);
        if (!in_array($questiontype, ['application', 'scenario', 'mixed'])) {
            $questiontype = 'application';
        }
        $bloomlevel = optional_param('bloomLevel', 3, PARAM_INT);
        if ($bloomlevel < 1 || $bloomlevel > 6) {
            $bloomlevel = 3;
        }
        $scenariocountry = optional_param('scenarioCountry', '', PARAM_TEXT);
        $scenarioindustry = optional_param('scenarioIndustry', '', PARAM_TEXT);
        $scenariosubindustry = clean_param(optional_param('scenarioSubindustry', '', PARAM_TEXT), PARAM_TEXT);
        $scenariojoblevel = clean_param(optional_param('scenarioJobLevel', '', PARAM_TEXT), PARAM_TEXT);
        $scenariojobroles = clean_param(optional_param('scenarioJobRoles', '', PARAM_TEXT), PARAM_TEXT);

        $selectedformatsraw = optional_param('selectedFormats', '', PARAM_TEXT);
        $allowedformats = ['mcq', 'cardselect', 'matching', 'ordering', 'columnsort', 'categorysort', 'flashcards', 'truefalseswipe', 'fillinblank'];
        $selectedformats = [];
        if (!empty($selectedformatsraw)) {
            $parsed = json_decode($selectedformatsraw, true);
            if (is_array($parsed)) {
                foreach ($parsed as $fmt) {
                    if (in_array($fmt, $allowedformats)) {
                        $selectedformats[] = $fmt;
                    }
                }
            }
        }
        if (empty($selectedformats)) {
            $selectedformats = ['mcq'];
        }

        $url = $apibase . '/api/generate-videoactivity';
        $payload = [
            'siteId' => $siteid,
            'apiKey' => $apikey,
            'cmid' => $cmid,
            'videoactivityId' => $videoactivity->id,
            'transcript' => $transcript,
            'numQuestions' => $numquestions,
            'voiceoverEnabled' => (bool)$voiceoverenabled,
            'voiceLanguage' => $voicelanguage,
            'voiceGender' => $voicegender,
            'voiceId' => $voiceid,
            'extraInstructions' => $extrainstructions,
            'questionType' => $questiontype,
            'bloomLevel' => $bloomlevel,
            'scenarioCountry' => $scenariocountry,
            'scenarioIndustry' => $scenarioindustry,
            'scenarioSubindustry' => $scenariosubindustry,
            'scenarioJobLevel' => $scenariojoblevel,
            'scenarioJobRoles' => $scenariojobroles,
            'selectedFormats' => $selectedformats,
        ];
        $data = json_encode($payload);

        $curl = new \curl();
        $curl->setopt(['CURLOPT_TIMEOUT' => 120]);
        $curl->setHeader(['Content-Type: application/json']);
        $response = $curl->post($url, $data);
        $curlerror = $curl->error;
        $httpcode = $curl->info['http_code'];

        if ($curlerror) {
            echo json_encode(['ok' => false, 'error' => 'Connection error: ' . $curlerror]);
            break;
        }

        if ($httpcode === 200) {
            $result = json_decode($response, true);
            if ($result === null) {
                echo json_encode(['ok' => false, 'error' => 'Invalid API response']);
            } else {
                echo json_encode($result);
            }
        } else {
            $result = json_decode($response, true);
            $error = isset($result['error']) ? $result['error'] : 'API request failed (HTTP ' . $httpcode . ')';
            echo json_encode(['ok' => false, 'error' => $error]);
        }
        break;

    case 'status':
        // Check generation status.
        $jobid = required_param('jobId', PARAM_RAW);

        $url = $apibase . '/api/videoactivity-status/' . urlencode($jobid);

        $curl = new \curl();
        $curl->setopt(['CURLOPT_TIMEOUT' => 30]);
        $response = $curl->get($url);
        $httpcode = $curl->info['http_code'];

        if ($httpcode === 200) {
            $result = json_decode($response, true);
            echo json_encode($result);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Failed to check status']);
        }
        break;

    case 'savequestions':
        // Save generated questions to the database.
        $cmid = required_param('cmid', PARAM_INT);
        $questions = required_param('questions', PARAM_RAW);

        $cm = get_coursemodule_from_id('aivideoactivity', $cmid, 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        require_login($course, false, $cm);
        $context = context_module::instance($cm->id);
        require_capability('mod/aivideoactivity:create', $context);

        $questionsdata = json_decode($questions, true);
        if (!is_array($questionsdata)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid questions data']);
            break;
        }

        // Clear existing questions.
        $DB->delete_records('aivideoactivity_questions', ['aivideoactivityid' => $cm->instance]);

        // Allowed question types.
        $validtypes = ['mcq', 'cardselect', 'matching', 'ordering', 'columnsort', 'categorysort', 'flashcards', 'truefalseswipe', 'fillinblank'];

        // Insert new questions.
        $questionnumber = 1;
        foreach ($questionsdata as $q) {
            $record = new stdClass();
            $record->aivideoactivityid = $cm->instance;
            $record->questionnumber = $questionnumber++;
            $record->questiontext = $q['question'] ?? '';

            // Determine question type.
            $qtype = isset($q['type']) && in_array($q['type'], $validtypes) ? $q['type'] : 'mcq';
            $record->questiontype = $qtype;

            // Store full question data as JSON for all types.
            $record->questiondata = json_encode($q);

            // For MCQ, also populate legacy answer fields for backward compat.
            if ($qtype === 'mcq') {
                $record->answer1 = $q['options'][0] ?? '';
                $record->answer2 = $q['options'][1] ?? '';
                $record->answer3 = $q['options'][2] ?? '';
                $record->answer4 = $q['options'][3] ?? '';
                $record->correctanswer = $q['correctAnswer'] ?? 0;
                $explanations = $q['explanations'] ?? [];
                $record->feedback1 = $explanations[0] ?? '';
                $record->feedback2 = $explanations[1] ?? '';
                $record->feedback3 = $explanations[2] ?? '';
                $record->feedback4 = $explanations[3] ?? '';
            } else {
                $record->answer1 = '';
                $record->answer2 = '';
                $record->answer3 = '';
                $record->answer4 = '';
                $record->correctanswer = 0;
                $record->feedback1 = '';
                $record->feedback2 = '';
                $record->feedback3 = '';
                $record->feedback4 = '';
            }

            // Save audio data if available.
            if (!empty($q['audioData'])) {
                $record->audiodata = is_string($q['audioData']) ? $q['audioData'] : json_encode($q['audioData']);
            }
            $DB->insert_record('aivideoactivity_questions', $record);
        }

        // Update question count.
        $DB->set_field('aivideoactivity', 'questioncount', count($questionsdata), ['id' => $cm->instance]);

        echo json_encode(['ok' => true, 'saved' => count($questionsdata)]);
        break;

    case 'startattempt':
        // Start a new attempt for a student.
        $cmid = required_param('cmid', PARAM_INT);

        $cm = get_coursemodule_from_id('aivideoactivity', $cmid, 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        $videoactivity = $DB->get_record('aivideoactivity', ['id' => $cm->instance], '*', MUST_EXIST);

        require_login($course, false, $cm);
        $context = context_module::instance($cm->id);
        require_capability('mod/aivideoactivity:view', $context);

        $userid = $USER->id;

        // Use transaction to prevent race conditions (duplicate in-progress attempts).
        $transaction = $DB->start_delegated_transaction();
        try {
            // Check if there's an in-progress attempt (inside transaction for consistency).
            $inprogress = $DB->get_record('aivideoactivity_attempts', [
                'aivideoactivityid' => $videoactivity->id,
                'userid' => $userid,
                'status' => 0,
            ]);

            if ($inprogress) {
                $transaction->allow_commit();
                echo json_encode([
                    'ok' => true,
                    'attemptid' => $inprogress->id,
                    'resumed' => true,
                    'currentquestion' => (int)$inprogress->currentquestion,
                    'answers' => json_decode($inprogress->answers, true) ?: [],
                ]);
                break;
            }

            // Check if user can start a new attempt.
            if (!aivideoactivity_can_attempt($videoactivity, $userid)) {
                $transaction->allow_commit();
                $maxattempts = aivideoactivity_effective_maxattempts($videoactivity, $userid);
                echo json_encode([
                    'ok' => false,
                    'error' => get_string('attemptslimitreached', 'mod_aivideoactivity', $maxattempts),
                ]);
                break;
            }

            // Create new attempt.
            $now = time();
            $attempt = new stdClass();
            $attempt->aivideoactivityid = $videoactivity->id;
            $attempt->userid = $userid;
            $attempt->currentquestion = 0;
            $attempt->answers = '{}';
            $attempt->correctcount = 0;
            $attempt->totalcount = 0;
            $attempt->status = 0;
            $attempt->timecreated = $now;
            $attempt->timemodified = $now;
            $attempt->timestarted = $now;
            $attempt->timeended = null;

            $attemptid = $DB->insert_record('aivideoactivity_attempts', $attempt);
            $transaction->allow_commit();

            echo json_encode([
                'ok' => true,
                'attemptid' => $attemptid,
                'resumed' => false,
                'currentquestion' => 0,
                'answers' => [],
            ]);
        } catch (Exception $e) {
            $transaction->rollback($e);
            echo json_encode(['ok' => false, 'error' => 'Failed to start attempt. Please try again.']);
        }
        break;

    case 'saveanswer':
        // Save a single answer during an attempt.
        $attemptid = required_param('attemptid', PARAM_INT);
        $questionid = required_param('questionid', PARAM_INT);
        $answerindex = optional_param('answerindex', -1, PARAM_INT);

        $attempt = $DB->get_record('aivideoactivity_attempts', ['id' => $attemptid], '*', MUST_EXIST);

        // Authenticate user against the course.
        $videoactivity = $DB->get_record('aivideoactivity', ['id' => $attempt->aivideoactivityid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('aivideoactivity', $videoactivity->id, 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        require_login($course, false, $cm);

        // Verify user owns this attempt.
        if ($attempt->userid != $USER->id) {
            echo json_encode(['ok' => false, 'error' => 'Invalid attempt']);
            break;
        }

        if ($attempt->status != 0) {
            echo json_encode(['ok' => false, 'error' => 'Attempt already completed']);
            break;
        }

        // Get question and verify it belongs to the same video activity as the attempt.
        $question = $DB->get_record('aivideoactivity_questions', ['id' => $questionid], '*', MUST_EXIST);
        if ((int)$question->aivideoactivityid !== (int)$attempt->aivideoactivityid) {
            echo json_encode(['ok' => false, 'error' => 'Question does not belong to this activity']);
            break;
        }

        // Determine question type.
        $qtype = !empty($question->questiontype) ? $question->questiontype : 'mcq';

        // For non-MCQ types, client sends iscorrect directly (client validates correctness).
        // For MCQ, server validates against correctanswer field.
        if ($qtype === 'mcq') {
            if ($answerindex < 0 || $answerindex > 3) {
                echo json_encode(['ok' => false, 'error' => 'Invalid answer index']);
                break;
            }
            $iscorrect = ($answerindex == $question->correctanswer);
        } else {
            // Non-MCQ: client sends iscorrect flag since correctness is complex.
            $iscorrect = (bool)optional_param('iscorrect', 0, PARAM_INT);
            $answerindex = $iscorrect ? 1 : 0;
        }

        // Update answers JSON and recalculate counts.
        $answers = json_decode($attempt->answers, true) ?: [];
        $answers[$questionid] = [
            'answer' => $answerindex,
            'iscorrect' => $iscorrect,
        ];

        // Recalculate correct/total counts.
        $correctcount = 0;
        $totalcount = 0;
        foreach ($answers as $qid => $ans) {
            $totalcount++;
            if (!empty($ans['iscorrect'])) {
                $correctcount++;
            }
        }

        $attempt->answers = json_encode($answers);
        // Track progress using question number (sequential index), not database ID.
        $attempt->currentquestion = max((int)$attempt->currentquestion, (int)$question->questionnumber);
        $attempt->correctcount = $correctcount;
        $attempt->totalcount = $totalcount;
        $attempt->timemodified = time();

        $DB->update_record('aivideoactivity_attempts', $attempt);

        echo json_encode([
            'ok' => true,
            'iscorrect' => $iscorrect,
            'correctanswer' => (int)$question->correctanswer,
        ]);
        break;

    case 'finishattempt':
        // Finish an attempt.
        $attemptid = required_param('attemptid', PARAM_INT);

        $attempt = $DB->get_record('aivideoactivity_attempts', ['id' => $attemptid], '*', MUST_EXIST);

        // Get video activity and authenticate user against the course.
        $videoactivity = $DB->get_record('aivideoactivity', ['id' => $attempt->aivideoactivityid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('aivideoactivity', $videoactivity->id, 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        require_login($course, false, $cm);

        if ($attempt->userid != $USER->id) {
            echo json_encode(['ok' => false, 'error' => 'Invalid attempt']);
            break;
        }

        if ($attempt->status != 0) {
            echo json_encode(['ok' => false, 'error' => 'Attempt already completed']);
            break;
        }

        // Calculate score.
        $answers = json_decode($attempt->answers, true) ?: [];
        $correctcount = 0;
        $totalcount = 0;

        foreach ($answers as $qid => $ans) {
            $totalcount++;
            if (!empty($ans['iscorrect'])) {
                $correctcount++;
            }
        }

        // Update attempt.
        $now = time();
        $attempt->status = 1; // Completed.
        $attempt->correctcount = $correctcount;
        $attempt->totalcount = $totalcount;
        $attempt->timemodified = $now;
        $attempt->timeended = $now;

        $DB->update_record('aivideoactivity_attempts', $attempt);

        // Update grade in gradebook FIRST (before completion check).
        // Completion may depend on "Require passing grade" which reads from gradebook.
        aivideoactivity_update_grades($videoactivity, $USER->id);

        // Now update completion - grade is already written so passing grade check works.
        $completion = new completion_info($course);
        if ($completion->is_enabled($cm)) {
            $completion->update_state($cm, COMPLETION_UNKNOWN, $USER->id);
        }

        // Check if user has now used all attempts, send notification.
        if (!aivideoactivity_can_attempt($videoactivity, $USER->id)) {
            // User just used their last attempt.
            $user = $DB->get_record('user', ['id' => $USER->id]);
            aivideoactivity_send_attempts_notification($videoactivity, $course, $cm, $user);
        }

        echo json_encode([
            'ok' => true,
            'correctcount' => $correctcount,
            'totalcount' => $totalcount,
        ]);
        break;

    case 'getquestions':
        // Get questions for the activity.
        $cmid = required_param('cmid', PARAM_INT);

        $cm = get_coursemodule_from_id('aivideoactivity', $cmid, 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

        require_login($course, false, $cm);
        $context = context_module::instance($cm->id);
        require_capability('mod/aivideoactivity:view', $context);

        $questions = $DB->get_records('aivideoactivity_questions', 
            ['aivideoactivityid' => $cm->instance], 
            'questionnumber ASC'
        );

        $result = [];
        foreach ($questions as $q) {
            // Parse audio data if available.
            $audioData = null;
            if (!empty($q->audiodata)) {
                $audioData = json_decode($q->audiodata, true);
            }

            // If questiondata exists, use the full JSON (supports all 6 question types).
            if (!empty($q->questiondata)) {
                $qdata = json_decode($q->questiondata, true);
                if (is_array($qdata)) {
                    $qdata['id'] = (int)$q->id;
                    $qdata['questionnumber'] = (int)$q->questionnumber;
                    if ($audioData) {
                        $qdata['audioData'] = $audioData;
                    }

                    // FIX-VA-CARDSELECT-AUDIO-LENGTH-ALIGN (v1.0.127): server-side audio
                    // realignment for cardselect questions where the server previously fixed
                    // explanation TEXT order (explanations[correctIndex]="Correct.") but did
                    // NOT fix audioData order — leaving audioData[0] as the "Correct." clip
                    // while audioData[correctIndex] is an "Incorrect." clip. The client-side
                    // Pass 1 alignment (v1.0.125) skips these questions because it only acts
                    // when explanations[correctIndex] does NOT start with "Correct." — which
                    // it already does after the text-only fix — so audio remains misaligned.
                    //
                    // Detection: the "Correct. [detailed explanation]" TTS clip is reliably
                    // longer than an "Incorrect. [short card label] isn't quite right" clip,
                    // so comparing base64 string lengths (proportional to audio byte length)
                    // gives a sound proxy. If audioData[0] is 10%+ longer than
                    // audioData[correctIndex] AND correctIndex != 0, swap them — audio[0] is
                    // almost certainly the misaligned "Correct." narration from before the
                    // text-order fix. For correctly-ordered questions audioData[correctIndex]
                    // IS the longest clip, so the comparison returns false and no swap occurs.
                    if (isset($qdata['type']) && $qdata['type'] === 'cardselect'
                        && isset($qdata['correctIndex']) && (int)$qdata['correctIndex'] !== 0
                        && isset($qdata['explanations']) && is_array($qdata['explanations'])
                        && isset($qdata['audioData']) && is_array($qdata['audioData'])
                        && isset($qdata['cards']) && is_array($qdata['cards'])
                        && count($qdata['audioData']) === count($qdata['cards'])) {

                        $cidx = (int)$qdata['correctIndex'];
                        $corrExpl = isset($qdata['explanations'][$cidx]) ? (string)$qdata['explanations'][$cidx] : '';

                        // Only act when text is already at the correct slot (Pass 1 would skip).
                        if (stripos(trim($corrExpl), 'correct') === 0
                            && isset($qdata['audioData'][0]) && strlen((string)$qdata['audioData'][0]) > 0
                            && isset($qdata['audioData'][$cidx]) && strlen((string)$qdata['audioData'][$cidx]) > 0
                            && strlen((string)$qdata['audioData'][0]) > strlen((string)$qdata['audioData'][$cidx]) * 1.1) {

                            // audio[0] is 10%+ longer — almost certainly the misaligned
                            // "Correct." clip. Swap it to audioData[correctIndex].
                            $tmpAudio = $qdata['audioData'][0];
                            $qdata['audioData'][0] = $qdata['audioData'][$cidx];
                            $qdata['audioData'][$cidx] = $tmpAudio;
                        }
                    }

                    $result[] = $qdata;
                    continue;
                }
            }

            // Legacy fallback: MCQ-only format from answer1-4 fields.
            $result[] = [
                'id' => (int)$q->id,
                'questionnumber' => (int)$q->questionnumber,
                'type' => 'mcq',
                'question' => $q->questiontext,
                'options' => [$q->answer1, $q->answer2, $q->answer3, $q->answer4],
                'explanations' => [$q->feedback1, $q->feedback2, $q->feedback3, $q->feedback4],
                'correctAnswer' => (int)$q->correctanswer,
                'audioData' => $audioData,
            ];
        }

        echo json_encode(['ok' => true, 'questions' => $result]);
        break;

    case 'getattemptinfo':
        // Get attempt info for student view.
        $cmid = required_param('cmid', PARAM_INT);

        $cm = get_coursemodule_from_id('aivideoactivity', $cmid, 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        $videoactivity = $DB->get_record('aivideoactivity', ['id' => $cm->instance], '*', MUST_EXIST);

        require_login($course, false, $cm);
        $context = context_module::instance($cm->id);
        require_capability('mod/aivideoactivity:view', $context);

        $userid = $USER->id;

        // Get attempt counts.
        $used = aivideoactivity_count_attempts($videoactivity->id, $userid);
        $max = aivideoactivity_effective_maxattempts($videoactivity, $userid);
        $canattempt = aivideoactivity_can_attempt($videoactivity, $userid);

        // Check for in-progress attempt.
        $inprogress = $DB->get_record('aivideoactivity_attempts', [
            'aivideoactivityid' => $videoactivity->id,
            'userid' => $userid,
            'status' => 0,
        ]);

        // Get previous attempts.
        $attempts = $DB->get_records('aivideoactivity_attempts', [
            'aivideoactivityid' => $videoactivity->id,
            'userid' => $userid,
            'status' => 1,
        ], 'id ASC');

        $attemptlist = [];
        $attemptnum = 1;
        foreach ($attempts as $a) {
            $attemptlist[] = [
                'id' => (int)$a->id,
                'number' => $attemptnum++,
                'score' => $a->correctcount . '/' . $a->totalcount,
                'timestarted' => userdate($a->timestarted),
                'timeended' => $a->timeended ? userdate($a->timeended) : '',
            ];
        }

        echo json_encode([
            'ok' => true,
            'attemptsused' => $used,
            'maxattempts' => $max,
            'canattempt' => $canattempt,
            'inprogress' => $inprogress ? (int)$inprogress->id : null,
            'attempts' => $attemptlist,
        ]);
        break;

    case 'regenerateaudio':
        // Regenerate voiceover audio for existing questions.
        $questionsjson = required_param('questions', PARAM_RAW);
        $voicelanguage = optional_param('voiceLanguage', 'en-AU', PARAM_TEXT);
        $voiceid = optional_param('voiceId', 'Aoede', PARAM_ALPHA);

        $questionsdata = json_decode($questionsjson, true);
        if (!is_array($questionsdata) || empty($questionsdata)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid questions data']);
            break;
        }

        $url = $apibase . '/api/videoactivity-regenerate-audio';
        $payload = json_encode([
            'siteId' => $siteid,
            'apiKey' => $apikey,
            'questions' => $questionsdata,
            'voiceLanguage' => $voicelanguage,
            'voiceId' => $voiceid,
        ]);

        // BUG-REGEN-AUDIO-CURL: Replace raw curl_init() with Moodle \curl class so that the
        // site's proxy, SSL CA bundle, and redirect configuration are honoured. Also add a
        // 3-attempt retry loop for transient HTTP 429/503 or ok:false busy responses.
        $ra_result   = null;
        $ra_httpcode = 0;
        $ra_error    = '';
        for ($ra_attempt = 1; $ra_attempt <= 3; $ra_attempt++) {
            $ra_ch = new \curl();
            $ra_ch->setopt([
                'CURLOPT_TIMEOUT'    => 110,
                'CURLOPT_HTTPHEADER' => ['Content-Type: application/json'],
            ]);
            $ra_raw      = $ra_ch->post($url, $payload);
            $ra_error    = $ra_ch->error;
            $ra_info     = $ra_ch->get_info();
            $ra_httpcode = (int)$ra_info['http_code'];

            if ($ra_error) {
                break; // Connection-level error — no benefit retrying.
            }

            $ra_result    = json_decode($ra_raw, true);
            $ra_transient = ($ra_httpcode === 429 || $ra_httpcode === 503 ||
                ($ra_httpcode === 200 && $ra_result !== null &&
                 isset($ra_result['ok']) && $ra_result['ok'] === false &&
                 isset($ra_result['error']) && stripos($ra_result['error'], 'busy') !== false));

            if (!$ra_transient || $ra_attempt >= 3) {
                break;
            }
            sleep(5);
        }

        if ($ra_error) {
            echo json_encode(['ok' => false, 'error' => 'Connection error: ' . $ra_error]);
            break;
        }

        if ($ra_httpcode === 200) {
            if ($ra_result === null) {
                echo json_encode(['ok' => false, 'error' => 'Invalid API response']);
            } else {
                echo json_encode($ra_result);
            }
        } else {
            $ra_errmsg = isset($ra_result['error']) ? $ra_result['error'] : 'API request failed (HTTP ' . $ra_httpcode . ')';
            echo json_encode(['ok' => false, 'error' => $ra_errmsg]);
        }
        break;

    case 'ttssingle':
        // FIX-VA-CARDSELECT-CHIRP-FALLBACK (v1.0.118): on-demand single-clip Chirp HD
        // TTS for player-side top-up when a per-card audio slot is missing. Mirrors
        // the regenerateaudio action above but expects a single text payload and
        // returns a single base64 OGG_OPUS clip. PROJECT RULE for this plugin:
        // NEVER fall back to browser Web Speech — every voiceover comes from
        // Google Chirp via this pipeline so quality is uniform across cards.
        $tts_text = required_param('text', PARAM_TEXT);
        $tts_voicelanguage = optional_param('voiceLanguage', 'en-AU', PARAM_TEXT);
        $tts_voiceid = optional_param('voiceId', 'Aoede', PARAM_ALPHA);

        if (strlen(trim($tts_text)) === 0) {
            echo json_encode(['ok' => false, 'error' => 'Text required']);
            break;
        }

        $tts_url = $apibase . '/api/videoactivity-tts-single';
        $tts_payload = json_encode([
            'siteId'        => $siteid,
            'apiKey'        => $apikey,
            'text'          => $tts_text,
            'voiceLanguage' => $tts_voicelanguage,
            'voiceId'       => $tts_voiceid,
        ]);

        $tts_ch = new \curl();
        $tts_ch->setopt([
            'CURLOPT_TIMEOUT'    => 30,
            'CURLOPT_HTTPHEADER' => ['Content-Type: application/json'],
        ]);
        $tts_raw      = $tts_ch->post($tts_url, $tts_payload);
        $tts_error    = $tts_ch->error;
        $tts_info     = $tts_ch->get_info();
        $tts_httpcode = (int)$tts_info['http_code'];

        if ($tts_error) {
            echo json_encode(['ok' => false, 'error' => 'Connection error: ' . $tts_error]);
            break;
        }

        $tts_result = json_decode($tts_raw, true);
        if ($tts_httpcode === 200 && $tts_result !== null) {
            echo json_encode($tts_result);
        } else {
            $tts_errmsg = isset($tts_result['error']) ? $tts_result['error'] : 'TTS request failed (HTTP ' . $tts_httpcode . ')';
            echo json_encode(['ok' => false, 'error' => $tts_errmsg]);
        }
        break;

    case 'regeneratewithsettings':
        $questionsjson = required_param('questions', PARAM_RAW);
        $voicelanguage = optional_param('voiceLanguage', 'en-AU', PARAM_TEXT);
        $voiceoverenabled = optional_param('voiceoverEnabled', 0, PARAM_INT);
        $voicegender = optional_param('voiceGender', 'female', PARAM_ALPHA);
        $voiceid = optional_param('voiceId', 'Aoede', PARAM_ALPHA);

        $questionsdata = json_decode($questionsjson, true);
        if (!is_array($questionsdata) || empty($questionsdata)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid questions data']);
            break;
        }

        $url = $apibase . '/api/videoactivity-regenerate-settings';
        $payload = json_encode([
            'siteId' => $siteid,
            'apiKey' => $apikey,
            'questions' => $questionsdata,
            'voiceLanguage' => $voicelanguage,
            'voiceoverEnabled' => (bool)$voiceoverenabled,
            'voiceGender' => $voicegender,
            'voiceId' => $voiceid,
        ]);

        // BUG-REGEN-TIMEOUT (v1.0.82): The previous code had a PHP retry loop (3 attempts ×
        // CURLOPT_TIMEOUT 150s + sleep(5) between) that could run for up to 460 seconds. The JS
        // AJAX timeout is only 90 seconds, so JS always fired .fail() long before PHP returned.
        // Additionally many Moodle servers enforce max_execution_time = 30-60s at the web server
        // level, which killed the PHP process mid-curl producing no JSON — JS got a blank response.
        // Fix:
        //   1. set_time_limit(120): reset server execution limit for this request so PHP is not
        //      killed before curl completes.
        //   2. Remove the PHP retry loop — JS already retries up to 3× per question. PHP retrying
        //      just multiplies latency and guarantees JS timeout.
        //   3. CURLOPT_TIMEOUT => 75: strictly below the 90s JS AJAX timeout so PHP always
        //      returns (success or failure) before JS abandons the request.
        //   4. CURLOPT_CONNECTTIMEOUT => 10: fast-fail on DNS/TCP problems.
        // BUG-CURL-RESETOPT (v1.0.83): Moodle's \curl::post() calls resetopt() internally before
        // applying the post-specific options (CURLOPT_POST, CURLOPT_POSTFIELDS, CURLOPT_URL). Any
        // options set via setopt() BEFORE calling post() are silently discarded. This caused the
        // Content-Type: application/json header and the custom timeouts to never reach the external
        // API — the API received no JSON content-type, could not parse the body, and rejected every
        // request. Fix: pass curl options as the 3rd argument to post() so they are applied via
        // request() AFTER the internal reset, not before it.
        set_time_limit(120);
        $rws_ch  = new \curl();
        $rws_raw = $rws_ch->post($url, $payload, [
            'CURLOPT_TIMEOUT'        => 75,
            'CURLOPT_CONNECTTIMEOUT' => 10,
            'CURLOPT_HTTPHEADER'     => ['Content-Type: application/json'],
        ]);
        $rws_error    = $rws_ch->error;
        $rws_info     = $rws_ch->get_info();
        $rws_httpcode = (int)$rws_info['http_code'];
        $rws_result   = json_decode($rws_raw, true);

        if ($rws_error) {
            echo json_encode(['ok' => false, 'error' => 'Connection error: ' . $rws_error]);
            break;
        }

        if ($rws_httpcode === 200) {
            if ($rws_result === null) {
                echo json_encode(['ok' => false, 'error' => 'Invalid API response']);
            } else {
                echo json_encode($rws_result);
            }
        } else {
            $rws_errmsg = isset($rws_result['error']) ? $rws_result['error'] : 'API request failed (HTTP ' . $rws_httpcode . ')';
            echo json_encode(['ok' => false, 'error' => $rws_errmsg]);
        }
        break;

    case 'regenerateinstructions':
        $questionsjson = required_param('questions', PARAM_RAW);
        $extrainstructions = optional_param('extraInstructions', '', PARAM_RAW);
        $voicelanguage = optional_param('voiceLanguage', 'en-AU', PARAM_TEXT);
        $voiceoverenabled = optional_param('voiceoverEnabled', 0, PARAM_INT);
        $voicegender = optional_param('voiceGender', 'female', PARAM_ALPHA);
        $voiceid = optional_param('voiceId', 'Aoede', PARAM_ALPHA);

        $questionsdata = json_decode($questionsjson, true);
        if (!is_array($questionsdata) || empty($questionsdata)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid questions data']);
            break;
        }

        // FIX-VA-REGEN-GROUNDING (v1.0.99): Forward the persisted video transcript so the SaaS
        // regenerator has the same source-of-truth as the original generate call. Without this,
        // the AI only saw the OLD questions (no transcript) and drifted into generic content
        // that no longer reflected what the video actually says. Trim defensively to the same
        // 200k-char ceiling the generate path uses so payloads stay under the curl limit.
        $va_transcript = isset($videoactivity->transcripttext) ? (string)$videoactivity->transcripttext : '';
        if (strlen($va_transcript) > 200000) {
            $va_transcript = substr($va_transcript, 0, 200000);
        }

        $url = $apibase . '/api/videoactivity-regenerate-instructions';
        $payload = json_encode([
            'siteId' => $siteid,
            'apiKey' => $apikey,
            'activityId' => (string)$cm->instance,
            'questions' => $questionsdata,
            'extraInstructions' => $extrainstructions,
            'voiceLanguage' => $voicelanguage,
            'voiceoverEnabled' => (bool)$voiceoverenabled,
            'voiceGender' => $voicegender,
            'voiceId' => $voiceid,
            'transcript' => $va_transcript,
        ]);

        // BUG-REGEN-TIMEOUT (v1.0.82): Same fix as regeneratewithsettings above.
        // PHP retry loop (3×150s) ran far longer than the 90s JS AJAX timeout, causing JS to
        // always fire .fail() before PHP returned. Server max_execution_time (30-60s) also killed
        // PHP mid-curl producing no JSON output. Fix: single attempt, 75s curl timeout, connect
        // timeout 10s, set_time_limit(120) so the server does not kill PHP before curl returns.
        // BUG-CURL-RESETOPT (v1.0.83): Same fix as regeneratewithsettings above — pass curl
        // options as 3rd argument to post() so they survive the internal resetopt() call.
        // FIX-VA-REGEN-BATCH (v1.0.89): JS now sends all questions in a single batch call with a
        // 180s AJAX timeout. Raised curl timeout to 160s (below JS) and set_time_limit to 200s
        // (above curl) so PHP does not get killed before the batch response arrives.
        set_time_limit(200);
        $ri_ch  = new \curl();
        $ri_raw = $ri_ch->post($url, $payload, [
            'CURLOPT_TIMEOUT'        => 160,
            'CURLOPT_CONNECTTIMEOUT' => 10,
            'CURLOPT_HTTPHEADER'     => ['Content-Type: application/json'],
        ]);
        $ri_error    = $ri_ch->error;
        $ri_info     = $ri_ch->get_info();
        $ri_httpcode = (int)$ri_info['http_code'];
        $ri_result   = json_decode($ri_raw, true);

        if ($ri_error) {
            echo json_encode(['ok' => false, 'error' => 'Connection error: ' . $ri_error]);
            break;
        }

        if ($ri_httpcode === 200) {
            if ($ri_result === null) {
                echo json_encode(['ok' => false, 'error' => 'Invalid API response']);
            } else {
                echo json_encode($ri_result);
            }
        } else {
            $ri_errmsg = isset($ri_result['error']) ? $ri_result['error'] : 'API request failed (HTTP ' . $ri_httpcode . ')';
            echo json_encode(['ok' => false, 'error' => $ri_errmsg]);
        }
        break;

    case 'savevoicesettings':
        $cmid = required_param('cmid', PARAM_INT);
        $voiceoverenabled = required_param('voiceoverEnabled', PARAM_INT);
        $voicelanguage = optional_param('voiceLanguage', 'en-AU', PARAM_TEXT);
        $voicegender = optional_param('voiceGender', 'female', PARAM_ALPHA);
        $voicestyle = optional_param('voiceStyle', 'Aoede', PARAM_ALPHANUMEXT);

        $cm = get_coursemodule_from_id('aivideoactivity', $cmid, 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        require_login($course, false, $cm);
        $context = context_module::instance($cm->id);
        require_capability('mod/aivideoactivity:create', $context);

        $DB->set_field('aivideoactivity', 'voiceoverenabled', $voiceoverenabled ? 1 : 0, ['id' => $cm->instance]);
        $DB->set_field('aivideoactivity', 'voicelanguage', $voicelanguage, ['id' => $cm->instance]);
        $DB->set_field('aivideoactivity', 'voicegender', $voicegender, ['id' => $cm->instance]);
        $DB->set_field('aivideoactivity', 'voicestyle', $voicestyle, ['id' => $cm->instance]);
        $DB->set_field('aivideoactivity', 'timemodified', time(), ['id' => $cm->instance]);

        // If voiceover was disabled, strip audio data from all questions.
        if (!$voiceoverenabled) {
            $questions = $DB->get_records('aivideoactivity_questions', ['aivideoactivityid' => $cm->instance]);
            foreach ($questions as $q) {
                if (!empty($q->audiodata)) {
                    $DB->set_field('aivideoactivity_questions', 'audiodata', null, ['id' => $q->id]);
                }
            }
        }

        echo json_encode(['ok' => true, 'message' => 'Voice settings saved']);
        break;

    case 'savevideosettings':
        // Save video settings (YouTube URL, watch mode, watch seconds).
        $youtubeurl = required_param('youtubeUrl', PARAM_URL);
        $watchmode = required_param('watchMode', PARAM_ALPHA);
        $watchseconds = optional_param('watchSeconds', 0, PARAM_INT);

        // Validate watchMode.
        if (!in_array($watchmode, ['all', 'seconds', 'none'])) {
            echo json_encode(['ok' => false, 'error' => 'Invalid watch mode. Must be all, seconds, or none.']);
            break;
        }

        $DB->set_field('aivideoactivity', 'youtubeurl', $youtubeurl, ['id' => $cm->instance]);
        $DB->set_field('aivideoactivity', 'watchmode', $watchmode, ['id' => $cm->instance]);
        $DB->set_field('aivideoactivity', 'watchseconds', $watchseconds, ['id' => $cm->instance]);
        $DB->set_field('aivideoactivity', 'timemodified', time(), ['id' => $cm->instance]);

        echo json_encode(['ok' => true, 'message' => 'Video settings saved']);
        break;

    case 'bulkregencount':
        // FEAT-VA-BULK-CARDSELECT-AUDIO-UPGRADE (v1.0.114): Site-admin only.
        // Counts every cardselect question across the whole site whose
        // audiodata column is missing, empty, or holds fewer than 2 base64
        // clips (legacy single-clip pre-v1.0.110 format). Used by the bulk
        // upgrade page to size its progress bar before processing.
        if (!is_siteadmin()) {
            echo json_encode(['ok' => false, 'error' => get_string('bulkregen_admin_only', 'mod_aivideoactivity')]);
            break;
        }
        $bc_total = 0;
        $bc_legacy = 0;
        $bc_records = $DB->get_recordset_select(
            'aivideoactivity_questions',
            "questiontype = :qtype",
            ['qtype' => 'cardselect'],
            'id',
            'id, audiodata'
        );
        foreach ($bc_records as $bc_r) {
            $bc_total++;
            $bc_audio = !empty($bc_r->audiodata) ? json_decode($bc_r->audiodata, true) : null;
            if (!is_array($bc_audio) || count($bc_audio) < 2) {
                $bc_legacy++;
            }
        }
        $bc_records->close();
        echo json_encode([
            'ok' => true,
            'total_cardselect' => $bc_total,
            'legacy_cardselect' => $bc_legacy,
        ]);
        break;

    case 'bulkregenstep':
        // FEAT-VA-BULK-CARDSELECT-AUDIO-UPGRADE (v1.0.114): Site-admin only.
        // Processes ONE legacy cardselect question per HTTP call so the bulk
        // upgrade page never times out, even on large sites. The browser
        // page polls this endpoint in a loop (passing the last-processed
        // record id back in `lastid`) until `done` is true.
        // Pulls the next legacy cardselect question (audiodata length < 2),
        // calls /api/videoactivity-regenerate-audio (which already auto-
        // upgrades legacy single-clip questions to 4 per-card clips via
        // v1.0.112 logic), and persists the returned audiodata + per-card
        // explanations back to the DB. Returns next id, processed/total
        // counts, and a one-line status message for the progress UI.
        if (!is_siteadmin()) {
            echo json_encode(['ok' => false, 'error' => get_string('bulkregen_admin_only', 'mod_aivideoactivity')]);
            break;
        }
        $bs_lastid = optional_param('lastid', 0, PARAM_INT);

        // Find the next legacy cardselect question with id > lastid.
        $bs_target = null;
        $bs_records = $DB->get_recordset_select(
            'aivideoactivity_questions',
            "questiontype = :qtype AND id > :lastid",
            ['qtype' => 'cardselect', 'lastid' => $bs_lastid],
            'id ASC',
            'id, aivideoactivityid, questionnumber, questiondata, audiodata'
        );
        foreach ($bs_records as $bs_r) {
            $bs_audio = !empty($bs_r->audiodata) ? json_decode($bs_r->audiodata, true) : null;
            if (!is_array($bs_audio) || count($bs_audio) < 2) {
                $bs_target = $bs_r;
                break;
            }
        }
        $bs_records->close();

        if (!$bs_target) {
            echo json_encode([
                'ok' => true,
                'done' => true,
                'message' => get_string('bulkregen_done', 'mod_aivideoactivity'),
            ]);
            break;
        }

        // Decode questiondata (full question JSON), attach the legacy audiodata
        // so the regen route can detect single-clip cardselect and auto-upgrade.
        $bs_qdata = json_decode($bs_target->questiondata, true);
        if (!is_array($bs_qdata)) {
            // Corrupt row — skip it but keep walking.
            echo json_encode([
                'ok' => true,
                'done' => false,
                'lastid' => (int)$bs_target->id,
                'skipped' => true,
                'message' => 'Skipped corrupt row id=' . (int)$bs_target->id,
            ]);
            break;
        }
        $bs_existing_audio = !empty($bs_target->audiodata) ? json_decode($bs_target->audiodata, true) : [];
        if (is_array($bs_existing_audio)) {
            $bs_qdata['audioData'] = $bs_existing_audio;
        }

        // Read voice config from the parent activity so the regenerated clips
        // match the activity's existing voice settings.
        $bs_va = $DB->get_record('aivideoactivity', ['id' => $bs_target->aivideoactivityid], 'id, voicelanguage, voicestyle');
        $bs_voicelang = ($bs_va && !empty($bs_va->voicelanguage)) ? $bs_va->voicelanguage : 'en-AU';
        $bs_voiceid = ($bs_va && !empty($bs_va->voicestyle)) ? $bs_va->voicestyle : 'Aoede';

        // Call the SaaS regenerate-audio route with this single question.
        $bs_url = $apibase . '/api/videoactivity-regenerate-audio';
        $bs_payload = json_encode([
            'siteId' => $siteid,
            'apiKey' => $apikey,
            'questions' => [$bs_qdata],
            'voiceLanguage' => $bs_voicelang,
            'voiceId' => $bs_voiceid,
        ]);

        set_time_limit(180);
        $bs_ch = new \curl();
        $bs_raw = $bs_ch->post($bs_url, $bs_payload, [
            'CURLOPT_TIMEOUT' => 110,
            'CURLOPT_CONNECTTIMEOUT' => 10,
            'CURLOPT_HTTPHEADER' => ['Content-Type: application/json'],
        ]);
        $bs_err = $bs_ch->error;
        $bs_info = $bs_ch->get_info();
        $bs_httpcode = (int)$bs_info['http_code'];

        if ($bs_err || $bs_httpcode !== 200) {
            echo json_encode([
                'ok' => false,
                'done' => false,
                'lastid' => (int)$bs_target->id,
                'error' => $bs_err ? ('Connection error: ' . $bs_err) : ('HTTP ' . $bs_httpcode),
            ]);
            break;
        }

        $bs_resp = json_decode($bs_raw, true);
        if (!is_array($bs_resp) || empty($bs_resp['ok']) || empty($bs_resp['questions']) || empty($bs_resp['questions'][0])) {
            echo json_encode([
                'ok' => false,
                'done' => false,
                'lastid' => (int)$bs_target->id,
                'error' => isset($bs_resp['error']) ? $bs_resp['error'] : 'Invalid API response',
            ]);
            break;
        }

        $bs_newq = $bs_resp['questions'][0];
        $bs_newaudio = isset($bs_newq['audioData']) ? $bs_newq['audioData'] : null;

        // Sanity check: only persist if the API actually returned >= 2 clips,
        // otherwise we'd be no-op-overwriting and counting it as upgraded.
        if (!is_array($bs_newaudio) || count($bs_newaudio) < 2) {
            echo json_encode([
                'ok' => false,
                'done' => false,
                'lastid' => (int)$bs_target->id,
                'error' => 'API returned fewer than 2 audio clips — upgrade not applied',
            ]);
            break;
        }

        // Persist: refresh both columns. questiondata gets the full updated
        // question (which now includes explanations[4] from the regen route);
        // audiodata gets the new 4-clip array as a JSON string for the
        // dedicated column the get-questions reader uses.
        $bs_update = new stdClass();
        $bs_update->id = $bs_target->id;
        $bs_update->questiondata = json_encode($bs_newq);
        $bs_update->audiodata = json_encode($bs_newaudio);
        $DB->update_record('aivideoactivity_questions', $bs_update);

        echo json_encode([
            'ok' => true,
            'done' => false,
            'lastid' => (int)$bs_target->id,
            'aivideoactivityid' => (int)$bs_target->aivideoactivityid,
            'questionnumber' => (int)$bs_target->questionnumber,
            'clips' => count($bs_newaudio),
            'message' => 'Upgraded question id=' . (int)$bs_target->id . ' (' . count($bs_newaudio) . ' clips)',
        ]);
        break;

    default:
        echo json_encode(['ok' => false, 'error' => 'Unknown action']);
}
