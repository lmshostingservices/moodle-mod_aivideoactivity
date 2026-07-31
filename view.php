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
 * AI Video Activity view page.
 *
 * @package    mod_aivideoactivity
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = optional_param('id', 0, PARAM_INT);
$a  = optional_param('a', 0, PARAM_INT);

if ($id) {
    $cm = get_coursemodule_from_id('aivideoactivity', $id, 0, false, MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
    $videoactivity = $DB->get_record('aivideoactivity', ['id' => $cm->instance], '*', MUST_EXIST);
} else if ($a) {
    $videoactivity = $DB->get_record('aivideoactivity', ['id' => $a], '*', MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $videoactivity->course], '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('aivideoactivity', $videoactivity->id, $course->id, false, MUST_EXIST);
    $id = $cm->id;
} else {
    throw new \moodle_exception('missingparam', '', '', 'id',
        'To access an AI Video Activity, open it from your course page. ' .
        'Direct URL access requires ?id= (course module ID) or ?a= (activity instance ID).');
}

require_login($course, true, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/aivideoactivity:view', $context);

$cancreate = has_capability('mod/aivideoactivity:create', $context);
$canviewreports = has_capability('mod/aivideoactivity:viewreports', $context);

// Explicitly include aiconfig lib.php if available
$aiconfiglib = $CFG->dirroot . '/local/aiconfig/lib.php';
if (file_exists($aiconfiglib)) {
    require_once($aiconfiglib);
}

// Priority 1: Central Config (recommended for multi-plugin setups)
$siteid = '';
$apikey = '';
if (function_exists('local_aiconfig_get_siteid')) {
    $siteid = local_aiconfig_get_siteid();
}
if (function_exists('local_aiconfig_get_apikey')) {
    $apikey = local_aiconfig_get_apikey();
}

// Priority 2: Plugin settings as fallback
if (empty($siteid)) {
    $siteid = get_config('mod_aivideoactivity', 'siteid');
}
if (empty($apikey)) {
    $apikey = get_config('mod_aivideoactivity', 'apikey');
}

// Page setup.
$PAGE->set_url('/mod/aivideoactivity/view.php', ['id' => $id]);
$PAGE->set_title(format_string($videoactivity->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');

// Add CSS.
$PAGE->requires->css('/mod/aivideoactivity/styles.css');

// Trigger module viewed event.
aivideoactivity_view($videoactivity, $course, $cm, $context);

echo $OUTPUT->header();

// Check configuration.
if (empty($siteid) || empty($apikey)) {
    echo $OUTPUT->notification(get_string('not_configured', 'mod_aivideoactivity'), 'warning');
    echo $OUTPUT->footer();
    return;
}

// Check if there are questions saved.
$questioncount = $DB->count_records('aivideoactivity_questions', ['aivideoactivityid' => $videoactivity->id]);

// Load passing grade from gradebook (authoritative source).
$gradepass = 0;
$maxgrade = isset($videoactivity->grade) ? (int)$videoactivity->grade : 100;
if ($maxgrade <= 0) {
    $maxgrade = 100;
}
require_once($CFG->libdir . '/gradelib.php');
$gradeitem = grade_item::fetch([
    'itemtype' => 'mod',
    'itemmodule' => 'aivideoactivity',
    'iteminstance' => $videoactivity->id,
    'courseid' => $course->id,
]);
if ($gradeitem && $gradeitem->gradepass > 0) {
    $gradepass = (float)$gradeitem->gradepass;
}

// Show different views based on capability.
if ($cancreate) {
    // Teacher/creator view - show navigation links.
    echo html_writer::start_div('va-teacher-nav mb-3');
    if ($canviewreports) {
        $reporturl = new moodle_url('/mod/aivideoactivity/report.php', ['id' => $cm->id]);
        echo html_writer::link($reporturl, get_string('attemptsreport', 'mod_aivideoactivity'), ['class' => 'btn btn-secondary mr-2']);
        
        $moreattemptsurl = new moodle_url('/mod/aivideoactivity/moreattempts.php', ['id' => $cm->id]);
        echo html_writer::link($moreattemptsurl, get_string('moreattempts', 'mod_aivideoactivity'), ['class' => 'btn btn-secondary']);
    }
    echo html_writer::end_div();

    ?>
    <div id="va-app" class="va-container">
        <!-- Credits Badge (Teachers Only) -->
        <div class="va-credits-badge">
            <svg class="va-credits-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"/>
                <path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/>
                <path d="M12 18V6"/>
            </svg>
            <span id="va-credit-balance">--</span>
            <span class="va-credits-label"><?php echo get_string('credits_label', 'mod_aivideoactivity'); ?></span>
        </div>

        <!-- Main Form -->
        <div id="va-form-section" class="va-card">
            <h3 class="va-card-title"><?php echo get_string('page_heading', 'mod_aivideoactivity'); ?></h3>
            <p class="va-intro"><?php echo get_string('page_intro', 'mod_aivideoactivity'); ?></p>

            <form id="va-form" onsubmit="return false;">
                <!-- Media Type Selector -->
                <div class="va-form-group">
                    <label for="va-media-type" class="va-label"><?php echo get_string('mediatype', 'mod_aivideoactivity'); ?></label>
                    <select id="va-media-type" class="va-select">
                        <option value="video" <?php echo (!isset($videoactivity->mediatype) || $videoactivity->mediatype === 'video') ? 'selected' : ''; ?>><?php echo get_string('mediatype_video', 'mod_aivideoactivity'); ?></option>
                        <option value="audio" <?php echo (isset($videoactivity->mediatype) && $videoactivity->mediatype === 'audio') ? 'selected' : ''; ?>><?php echo get_string('mediatype_audio', 'mod_aivideoactivity'); ?></option>
                    </select>
                    <small class="va-help"><?php echo get_string('mediatype_help', 'mod_aivideoactivity'); ?></small>
                </div>

                <!-- YouTube URL Input (video mode) -->
                <div class="va-form-group" id="va-youtube-url-group">
                    <label for="va-youtube-url" class="va-label"><?php echo get_string('youtubeurl', 'mod_aivideoactivity'); ?></label>
                    <input type="url" id="va-youtube-url" class="va-input"
                        placeholder="<?php echo get_string('youtubeurl_placeholder', 'mod_aivideoactivity'); ?>"
                        value="<?php echo s(isset($videoactivity->youtubeurl) ? $videoactivity->youtubeurl : ''); ?>">
                    <small class="va-help"><?php echo get_string('youtubeurl_help', 'mod_aivideoactivity'); ?></small>
                    <div id="va-youtube-preview" class="va-youtube-preview" style="display: none;"></div>
                </div>

                <!-- Audio File Info (audio mode) -->
                <div class="va-form-group" id="va-audio-url-group" style="display: none;">
                    <label class="va-label"><?php echo get_string('audiofile', 'mod_aivideoactivity'); ?></label>
                    <?php
                    $teacherfs = get_file_storage();
                    $teacherAudioFiles = $teacherfs->get_area_files($context->id, 'mod_aivideoactivity', 'audiofile', 0, 'id', false);
                    $teacherAudioUrl = '';
                    $teacherAudioFilename = '';
                    if ($teacherAudioFiles) {
                        $taf = reset($teacherAudioFiles);
                        $teacherAudioFilename = $taf->get_filename();
                        $teacherAudioUrl = moodle_url::make_pluginfile_url(
                            $context->id,
                            'mod_aivideoactivity',
                            'audiofile',
                            0,
                            $taf->get_filepath(),
                            $taf->get_filename()
                        )->out(false);
                    }
                    ?>
                    <?php if ($teacherAudioUrl): ?>
                        <div class="va-audio-file-info">
                            <span class="va-audio-filename">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle; margin-right: 4px;">
                                    <path d="M9 18V5l12-2v13"/>
                                    <circle cx="6" cy="18" r="3"/>
                                    <circle cx="18" cy="16" r="3"/>
                                </svg>
                                <?php echo s($teacherAudioFilename); ?>
                            </span>
                            <audio controls preload="metadata" style="width: 100%; margin-top: 8px;">
                                <source src="<?php echo s($teacherAudioUrl); ?>">
                            </audio>
                        </div>
                    <?php else: ?>
                        <div class="va-audio-no-file">
                            <p style="color: var(--va-text-muted, #6b7280); font-style: italic;">No audio file uploaded. Go to the activity settings to upload an audio file (MP3, WAV, OGG, M4A, AAC, FLAC, and more).</p>
                        </div>
                    <?php endif; ?>
                    <small class="va-help"><?php echo get_string('audiofile_teacher_help', 'mod_aivideoactivity'); ?></small>
                </div>

                <!-- Transcript Textarea -->
                <div class="va-form-group">
                    <label for="va-transcript" class="va-label"><?php echo get_string('transcript_label', 'mod_aivideoactivity'); ?></label>
                    <textarea id="va-transcript" class="va-textarea" rows="8"
                        placeholder="<?php echo get_string('transcript_placeholder', 'mod_aivideoactivity'); ?>"><?php echo s(isset($videoactivity->transcripttext) ? $videoactivity->transcripttext : ''); ?></textarea>
                    <small class="va-help">Paste the video transcript here before generating questions. To get a YouTube transcript: open the video, click the three-dot menu below the video, and select "Show transcript" — then copy and paste it here.</small>
                    <div id="va-transcript-cost-info" class="va-transcript-cost-info" style="display: none;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" style="flex-shrink:0;">
                            <circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>
                        </svg>
                        <span><strong id="va-transcript-word-count">0</strong> words &mdash; <strong id="va-transcript-credit-cost">5</strong> credits (5 credits per 150 words, minimum 5)</span>
                    </div>
                </div>

                <!-- Watch Mode Dropdown -->
                <div class="va-form-group">
                    <label for="va-watch-mode" class="va-label"><?php echo get_string('watchmode', 'mod_aivideoactivity'); ?></label>
                    <select id="va-watch-mode" class="va-select">
                        <option value="all" <?php echo (isset($videoactivity->watchmode) && $videoactivity->watchmode === 'all') ? 'selected' : ''; ?>><?php echo get_string('watchmode_all', 'mod_aivideoactivity'); ?></option>
                        <option value="seconds" <?php echo (isset($videoactivity->watchmode) && $videoactivity->watchmode === 'seconds') ? 'selected' : ''; ?>><?php echo get_string('watchmode_seconds', 'mod_aivideoactivity'); ?></option>
                        <option value="none" <?php echo (isset($videoactivity->watchmode) && $videoactivity->watchmode === 'none') ? 'selected' : ''; ?>><?php echo get_string('watchmode_none', 'mod_aivideoactivity'); ?></option>
                    </select>
                    <small class="va-help"><?php echo get_string('watchmode_help', 'mod_aivideoactivity'); ?></small>
                </div>

                <!-- Watch Seconds Input (only visible when mode=seconds) -->
                <div class="va-form-group" id="va-watch-seconds-field" <?php echo (!isset($videoactivity->watchmode) || $videoactivity->watchmode !== 'seconds') ? 'style="display:none;"' : ''; ?>>
                    <label for="va-watch-seconds" class="va-label"><?php echo get_string('watchseconds', 'mod_aivideoactivity'); ?></label>
                    <input type="number" id="va-watch-seconds" class="va-input" min="1" max="9999"
                        value="<?php echo (int)(isset($videoactivity->watchseconds) ? $videoactivity->watchseconds : 0); ?>">
                    <small class="va-help"><?php echo get_string('watchseconds_help', 'mod_aivideoactivity'); ?></small>
                </div>

                <!-- Number of Questions -->
                <div class="va-form-group">
                    <label for="va-num-questions" class="va-label"><?php echo get_string('num_questions_label', 'mod_aivideoactivity'); ?></label>
                    <select id="va-num-questions" class="va-select">
                        <?php for ($i = 1; $i <= 20; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php echo ($i === 5) ? 'selected' : ''; ?>><?php echo $i; ?> questions</option>
                        <?php endfor; ?>
                    </select>
                    <small class="va-help"><?php echo get_string('num_questions_help', 'mod_aivideoactivity'); ?></small>
                </div>

                <!-- Question Style -->
                <div class="va-form-group">
                    <label for="va-question-type" class="va-label"><?php echo get_string('question_type_label', 'mod_aivideoactivity'); ?></label>
                    <select id="va-question-type" class="va-select">
                        <option value="application" selected><?php echo get_string('question_type_application', 'mod_aivideoactivity'); ?></option>
                        <option value="scenario"><?php echo get_string('question_type_scenario', 'mod_aivideoactivity'); ?></option>
                        <option value="mixed"><?php echo get_string('question_type_mixed', 'mod_aivideoactivity'); ?></option>
                    </select>
                    <small class="va-help"><?php echo get_string('question_type_help', 'mod_aivideoactivity'); ?></small>
                </div>

                <!-- Question Formats (checkboxes) -->
                <div class="va-form-group">
                    <label class="va-label"><?php echo get_string('question_formats_label', 'mod_aivideoactivity'); ?></label>
                    <div class="va-format-grid">
                        <label class="va-format-option">
                            <input type="checkbox" id="va-format-mcq" value="mcq" checked>
                            <span class="va-format-check"></span>
                            <span class="va-format-info">
                                <span class="va-format-name"><?php echo get_string('format_mcq', 'mod_aivideoactivity'); ?></span>
                                <span class="va-format-desc"><?php echo get_string('format_mcq_desc', 'mod_aivideoactivity'); ?></span>
                            </span>
                        </label>
                        <label class="va-format-option">
                            <input type="checkbox" id="va-format-cardselect" value="cardselect" checked>
                            <span class="va-format-check"></span>
                            <span class="va-format-info">
                                <span class="va-format-name"><?php echo get_string('format_cardselect', 'mod_aivideoactivity'); ?></span>
                                <span class="va-format-desc"><?php echo get_string('format_cardselect_desc', 'mod_aivideoactivity'); ?></span>
                            </span>
                        </label>
                        <label class="va-format-option">
                            <input type="checkbox" id="va-format-matching" value="matching" checked>
                            <span class="va-format-check"></span>
                            <span class="va-format-info">
                                <span class="va-format-name"><?php echo get_string('format_matching', 'mod_aivideoactivity'); ?></span>
                                <span class="va-format-desc"><?php echo get_string('format_matching_desc', 'mod_aivideoactivity'); ?></span>
                            </span>
                        </label>
                        <label class="va-format-option">
                            <input type="checkbox" id="va-format-ordering" value="ordering" checked>
                            <span class="va-format-check"></span>
                            <span class="va-format-info">
                                <span class="va-format-name"><?php echo get_string('format_ordering', 'mod_aivideoactivity'); ?></span>
                                <span class="va-format-desc"><?php echo get_string('format_ordering_desc', 'mod_aivideoactivity'); ?></span>
                            </span>
                        </label>
                        <label class="va-format-option">
                            <input type="checkbox" id="va-format-columnsort" value="columnsort" checked>
                            <span class="va-format-check"></span>
                            <span class="va-format-info">
                                <span class="va-format-name"><?php echo get_string('format_columnsort', 'mod_aivideoactivity'); ?></span>
                                <span class="va-format-desc"><?php echo get_string('format_columnsort_desc', 'mod_aivideoactivity'); ?></span>
                            </span>
                        </label>
                        <label class="va-format-option">
                            <input type="checkbox" id="va-format-categorysort" value="categorysort" checked>
                            <span class="va-format-check"></span>
                            <span class="va-format-info">
                                <span class="va-format-name"><?php echo get_string('format_categorysort', 'mod_aivideoactivity'); ?></span>
                                <span class="va-format-desc"><?php echo get_string('format_categorysort_desc', 'mod_aivideoactivity'); ?></span>
                            </span>
                        </label>
                        <label class="va-format-option">
                            <input type="checkbox" id="va-format-flashcards" value="flashcards" checked>
                            <span class="va-format-check"></span>
                            <span class="va-format-info">
                                <span class="va-format-name"><?php echo get_string('format_flashcards', 'mod_aivideoactivity'); ?></span>
                                <span class="va-format-desc"><?php echo get_string('format_flashcards_desc', 'mod_aivideoactivity'); ?></span>
                            </span>
                        </label>
                        <label class="va-format-option">
                            <input type="checkbox" id="va-format-truefalseswipe" value="truefalseswipe" checked>
                            <span class="va-format-check"></span>
                            <span class="va-format-info">
                                <span class="va-format-name"><?php echo get_string('format_truefalseswipe', 'mod_aivideoactivity'); ?></span>
                                <span class="va-format-desc"><?php echo get_string('format_truefalseswipe_desc', 'mod_aivideoactivity'); ?></span>
                            </span>
                        </label>
                        <label class="va-format-option">
                            <input type="checkbox" id="va-format-fillinblank" value="fillinblank" checked>
                            <span class="va-format-check"></span>
                            <span class="va-format-info">
                                <span class="va-format-name"><?php echo get_string('format_fillinblank', 'mod_aivideoactivity'); ?></span>
                                <span class="va-format-desc"><?php echo get_string('format_fillinblank_desc', 'mod_aivideoactivity'); ?></span>
                            </span>
                        </label>
                    </div>
                    <small class="va-help"><?php echo get_string('question_formats_help', 'mod_aivideoactivity'); ?></small>
                </div>

                <div class="va-form-group">
                    <label for="va-bloom-level" class="va-label"><?php echo get_string('bloom_level_label', 'mod_aivideoactivity'); ?></label>
                    <select id="va-bloom-level" class="va-select">
                        <option value="1"><?php echo get_string('bloom_level_1', 'mod_aivideoactivity'); ?></option>
                        <option value="2"><?php echo get_string('bloom_level_2', 'mod_aivideoactivity'); ?></option>
                        <option value="3" selected><?php echo get_string('bloom_level_3', 'mod_aivideoactivity'); ?></option>
                        <option value="4"><?php echo get_string('bloom_level_4', 'mod_aivideoactivity'); ?></option>
                        <option value="5"><?php echo get_string('bloom_level_5', 'mod_aivideoactivity'); ?></option>
                        <option value="6"><?php echo get_string('bloom_level_6', 'mod_aivideoactivity'); ?></option>
                    </select>
                    <small class="va-help"><?php echo get_string('bloom_level_help', 'mod_aivideoactivity'); ?></small>
                </div>

                <div class="va-form-group">
                    <label for="va-extra-instructions" class="va-label"><?php echo get_string('extra_instructions_label', 'mod_aivideoactivity'); ?></label>
                    <textarea id="va-extra-instructions" class="va-textarea" rows="3" maxlength="2000" placeholder="<?php echo get_string('extra_instructions_placeholder', 'mod_aivideoactivity'); ?>"></textarea>
                    <small class="va-help"><?php echo get_string('extra_instructions_help', 'mod_aivideoactivity'); ?></small>
                </div>

                <!-- Scenario Context Fields (shown when scenario or mixed selected) -->
                <div id="va-scenario-context" class="va-scenario-context" style="display: none;">
                    <div class="va-context-header">
                        <span class="va-context-title"><?php echo get_string('scenario_context_title', 'mod_aivideoactivity'); ?></span>
                        <small class="va-help"><?php echo get_string('scenario_context_help', 'mod_aivideoactivity'); ?></small>
                    </div>
                    <div class="va-form-row">
                        <div class="va-form-group va-half">
                            <label for="va-scenario-country"><?php echo get_string('scenario_country', 'mod_aivideoactivity'); ?></label>
                            <select id="va-scenario-country" class="va-select">
                                <option value=""><?php echo get_string('select_option', 'mod_aivideoactivity'); ?></option>
                                <option value="Australia" selected>Australia</option>
                                <option value="New Zealand">New Zealand</option>
                                <option value="United Kingdom">United Kingdom</option>
                                <option value="United States">United States</option>
                                <option value="Canada">Canada</option>
                                <option value="Singapore">Singapore</option>
                            </select>
                        </div>
                        <div class="va-form-group va-half">
                            <label for="va-scenario-industry"><?php echo get_string('scenario_industry', 'mod_aivideoactivity'); ?></label>
                            <select id="va-scenario-industry" class="va-select">
                                <option value=""><?php echo get_string('select_option', 'mod_aivideoactivity'); ?></option>
                            </select>
                        </div>
                    </div>
                    <div class="va-form-group">
                        <label for="va-scenario-sector"><?php echo get_string('scenario_subindustry', 'mod_aivideoactivity'); ?></label>
                        <select id="va-scenario-sector" class="va-select" disabled>
                            <option value="">Select industry first...</option>
                        </select>
                    </div>
                    <div class="va-form-row">
                        <div class="va-form-group">
                            <label>Job Level <small class="va-help-inline">(select one or more)</small></label>
                            <div class="va-level-pills" id="va-job-level-pills">
                                <button type="button" class="va-level-pill" data-value="Worker">Worker</button>
                                <button type="button" class="va-level-pill" data-value="Supervisor">Supervisor</button>
                                <button type="button" class="va-level-pill" data-value="Manager">Manager</button>
                                <button type="button" class="va-level-pill" data-value="Executive">Executive</button>
                            </div>
                        </div>
                    </div>
                    <div class="va-form-group">
                        <label for="va-job-role-input">Job Roles <small class="va-help-inline">(up to 5 — press Enter to add)</small></label>
                        <div class="va-role-chips" id="va-job-role-chips"></div>
                        <input type="text" id="va-job-role-input" class="va-input" placeholder="e.g. Site Supervisor, Project Manager...">
                    </div>
                </div>

                <!-- Content Language -->
                <div class="va-form-group">
                    <label for="va-voice-language" class="va-label"><?php echo get_string('voice_language', 'mod_aivideoactivity'); ?></label>
                    <small class="va-help"><?php echo get_string('language_help', 'mod_aivideoactivity'); ?></small>
                    <select id="va-voice-language" class="va-select">
                        <optgroup label="English">
                            <option value="en-AU" selected><?php echo get_string('lang_en_au', 'mod_aivideoactivity'); ?></option>
                            <option value="en-GB"><?php echo get_string('lang_en_gb', 'mod_aivideoactivity'); ?></option>
                            <option value="en-IN"><?php echo get_string('lang_en_in', 'mod_aivideoactivity'); ?></option>
                            <option value="en-US"><?php echo get_string('lang_en_us', 'mod_aivideoactivity'); ?></option>
                        </optgroup>
                        <optgroup label="Spanish">
                            <option value="es-ES"><?php echo get_string('lang_es_es', 'mod_aivideoactivity'); ?></option>
                            <option value="es-US"><?php echo get_string('lang_es_us', 'mod_aivideoactivity'); ?></option>
                        </optgroup>
                        <optgroup label="French">
                            <option value="fr-CA"><?php echo get_string('lang_fr_ca', 'mod_aivideoactivity'); ?></option>
                            <option value="fr-FR"><?php echo get_string('lang_fr_fr', 'mod_aivideoactivity'); ?></option>
                        </optgroup>
                        <optgroup label="German">
                            <option value="de-DE"><?php echo get_string('lang_de_de', 'mod_aivideoactivity'); ?></option>
                        </optgroup>
                        <optgroup label="Portuguese">
                            <option value="pt-BR"><?php echo get_string('lang_pt_br', 'mod_aivideoactivity'); ?></option>
                        </optgroup>
                        <optgroup label="Dutch">
                            <option value="nl-BE"><?php echo get_string('lang_nl_be', 'mod_aivideoactivity'); ?></option>
                            <option value="nl-NL"><?php echo get_string('lang_nl_nl', 'mod_aivideoactivity'); ?></option>
                        </optgroup>
                        <optgroup label="Nordic">
                            <option value="da-DK"><?php echo get_string('lang_da_dk', 'mod_aivideoactivity'); ?></option>
                            <option value="fi-FI"><?php echo get_string('lang_fi_fi', 'mod_aivideoactivity'); ?></option>
                            <option value="nb-NO"><?php echo get_string('lang_nb_no', 'mod_aivideoactivity'); ?></option>
                            <option value="sv-SE"><?php echo get_string('lang_sv_se', 'mod_aivideoactivity'); ?></option>
                        </optgroup>
                        <optgroup label="Eastern European">
                            <option value="bg-BG"><?php echo get_string('lang_bg_bg', 'mod_aivideoactivity'); ?></option>
                            <option value="cs-CZ"><?php echo get_string('lang_cs_cz', 'mod_aivideoactivity'); ?></option>
                            <option value="hr-HR"><?php echo get_string('lang_hr_hr', 'mod_aivideoactivity'); ?></option>
                            <option value="hu-HU"><?php echo get_string('lang_hu_hu', 'mod_aivideoactivity'); ?></option>
                            <option value="pl-PL"><?php echo get_string('lang_pl_pl', 'mod_aivideoactivity'); ?></option>
                            <option value="ro-RO"><?php echo get_string('lang_ro_ro', 'mod_aivideoactivity'); ?></option>
                            <option value="ru-RU"><?php echo get_string('lang_ru_ru', 'mod_aivideoactivity'); ?></option>
                            <option value="sk-SK"><?php echo get_string('lang_sk_sk', 'mod_aivideoactivity'); ?></option>
                            <option value="sl-SI"><?php echo get_string('lang_sl_si', 'mod_aivideoactivity'); ?></option>
                            <option value="sr-RS"><?php echo get_string('lang_sr_rs', 'mod_aivideoactivity'); ?></option>
                            <option value="uk-UA"><?php echo get_string('lang_uk_ua', 'mod_aivideoactivity'); ?></option>
                        </optgroup>
                        <optgroup label="Baltic">
                            <option value="et-EE"><?php echo get_string('lang_et_ee', 'mod_aivideoactivity'); ?></option>
                            <option value="lt-LT"><?php echo get_string('lang_lt_lt', 'mod_aivideoactivity'); ?></option>
                            <option value="lv-LV"><?php echo get_string('lang_lv_lv', 'mod_aivideoactivity'); ?></option>
                        </optgroup>
                        <optgroup label="Southern European">
                            <option value="el-GR"><?php echo get_string('lang_el_gr', 'mod_aivideoactivity'); ?></option>
                            <option value="it-IT"><?php echo get_string('lang_it_it', 'mod_aivideoactivity'); ?></option>
                        </optgroup>
                        <optgroup label="East Asian">
                            <option value="cmn-CN"><?php echo get_string('lang_cmn_cn', 'mod_aivideoactivity'); ?></option>
                            <option value="ja-JP"><?php echo get_string('lang_ja_jp', 'mod_aivideoactivity'); ?></option>
                            <option value="ko-KR"><?php echo get_string('lang_ko_kr', 'mod_aivideoactivity'); ?></option>
                        </optgroup>
                        <optgroup label="Southeast Asian">
                            <option value="id-ID"><?php echo get_string('lang_id_id', 'mod_aivideoactivity'); ?></option>
                            <option value="th-TH"><?php echo get_string('lang_th_th', 'mod_aivideoactivity'); ?></option>
                            <option value="vi-VN"><?php echo get_string('lang_vi_vn', 'mod_aivideoactivity'); ?></option>
                        </optgroup>
                        <optgroup label="South Asian">
                            <option value="bn-IN"><?php echo get_string('lang_bn_in', 'mod_aivideoactivity'); ?></option>
                            <option value="gu-IN"><?php echo get_string('lang_gu_in', 'mod_aivideoactivity'); ?></option>
                            <option value="hi-IN"><?php echo get_string('lang_hi_in', 'mod_aivideoactivity'); ?></option>
                            <option value="kn-IN"><?php echo get_string('lang_kn_in', 'mod_aivideoactivity'); ?></option>
                            <option value="ml-IN"><?php echo get_string('lang_ml_in', 'mod_aivideoactivity'); ?></option>
                            <option value="mr-IN"><?php echo get_string('lang_mr_in', 'mod_aivideoactivity'); ?></option>
                            <option value="ta-IN"><?php echo get_string('lang_ta_in', 'mod_aivideoactivity'); ?></option>
                            <option value="te-IN"><?php echo get_string('lang_te_in', 'mod_aivideoactivity'); ?></option>
                            <option value="ur-IN"><?php echo get_string('lang_ur_in', 'mod_aivideoactivity'); ?></option>
                        </optgroup>
                        <optgroup label="Middle Eastern">
                            <option value="ar-XA"><?php echo get_string('lang_ar_xa', 'mod_aivideoactivity'); ?></option>
                            <option value="he-IL"><?php echo get_string('lang_he_il', 'mod_aivideoactivity'); ?></option>
                            <option value="tr-TR"><?php echo get_string('lang_tr_tr', 'mod_aivideoactivity'); ?></option>
                        </optgroup>
                        <optgroup label="African">
                            <option value="sw-KE"><?php echo get_string('lang_sw_ke', 'mod_aivideoactivity'); ?></option>
                        </optgroup>
                    </select>
                </div>

                <!-- Voiceover Toggle -->
                <div class="va-form-group">
                    <div class="va-toggle-row">
                        <label class="va-toggle-label" for="va-voiceover-toggle">
                            <input type="checkbox" id="va-voiceover-toggle" <?php echo (!empty($videoactivity->voiceoverenabled)) ? 'checked' : ''; ?>>
                            <span><?php echo get_string('voiceover_enabled', 'mod_aivideoactivity'); ?></span>
                        </label>
                        <small class="va-help"><?php echo get_string('voiceover_enabled_help', 'mod_aivideoactivity'); ?></small>
                    </div>
                </div>

                <!-- Voice Settings (only visible when voiceover is enabled) -->
                <div id="va-voice-settings-section" <?php echo (empty($videoactivity->voiceoverenabled)) ? 'style="display:none;"' : ''; ?>>
                    <div class="va-form-row">
                        <div class="va-form-group va-half">
                            <label for="va-voice-gender"><?php echo get_string('voice_gender', 'mod_aivideoactivity'); ?></label>
                            <select id="va-voice-gender" class="va-select">
                                <option value="female"><?php echo get_string('voice_female', 'mod_aivideoactivity'); ?></option>
                                <option value="male"><?php echo get_string('voice_male', 'mod_aivideoactivity'); ?></option>
                            </select>
                        </div>
                        <div class="va-form-group va-half">
                            <label for="va-voice-style"><?php echo get_string('voice_style', 'mod_aivideoactivity'); ?></label>
                            <select id="va-voice-style" class="va-select">
                                <option value="Aoede">Aoede (warm, friendly)</option>
                                <option value="Kore">Kore (clear, professional)</option>
                                <option value="Leda">Leda (soft, nurturing)</option>
                                <option value="Zephyr">Zephyr (energetic, youthful)</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Credit Cost Banner -->
                <div id="preview-stats" class="va-credit-cost-banner" style="display: none;">
                    <div class="va-credit-cost-row">
                        <div class="va-credit-cost-info">
                            <span class="va-credit-cost-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10"/>
                                    <path d="M12 6v6l4 2"/>
                                </svg>
                            </span>
                            <span id="va-credit-formula" class="va-credit-formula" data-testid="text-credit-formula"></span>
                            <span class="va-credit-cost-label">to generate content</span>
                        </div>
                        <div class="va-credit-balance-box">
                            <span class="va-balance-label">Your balance:</span>
                            <span id="va-balance-amount" class="va-balance-amount" data-testid="text-credit-balance">--</span>
                            <span class="va-balance-unit">credits</span>
                            <a href="https://lms-labs.com/pricing" target="_blank" class="va-buy-credits-link" data-testid="link-buy-credits">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                    <circle cx="12" cy="12" r="10"/>
                                    <path d="M12 8v8M8 12h8"/>
                                </svg>
                                Buy more
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Generate Button -->
                <button type="submit" id="va-generate-btn" class="va-btn va-btn-primary" disabled>
                    <?php echo get_string('generate_btn', 'mod_aivideoactivity'); ?>
                </button>
            </form>
        </div>

        <!-- Progress Section (Hidden by default) -->
        <div id="va-progress-section" class="va-card" style="display: none;">
            <!-- Credit cost visible during generation -->
            <div id="va-progress-credit-banner" class="va-credit-cost-banner va-credit-cost-banner--progress">
                <div class="va-credit-cost-row">
                    <div class="va-credit-cost-info">
                        <span class="va-credit-cost-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M12 6v6l4 2"/>
                            </svg>
                        </span>
                        <span id="va-progress-credit-formula" class="va-credit-formula" data-testid="text-progress-credit-formula"></span>
                        <span class="va-credit-cost-label">to generate content</span>
                    </div>
                    <div class="va-credit-balance-box">
                        <span class="va-balance-label">Your balance:</span>
                        <span id="va-progress-balance" class="va-balance-amount" data-testid="text-progress-balance">--</span>
                        <span class="va-balance-unit">credits</span>
                        <a href="https://lms-labs.com/pricing" target="_blank" class="va-buy-credits-link" data-testid="link-progress-buy-credits">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M12 8v8M8 12h8"/>
                            </svg>
                            Buy more
                        </a>
                    </div>
                </div>
            </div>
            <h3 class="va-card-title"><?php echo get_string('generating', 'mod_aivideoactivity'); ?></h3>
            <div class="va-progress-bar">
                <div id="va-progress-fill" class="va-progress-fill"></div>
            </div>
            <p id="va-progress-message" class="va-progress-message"><?php echo get_string('generating', 'mod_aivideoactivity'); ?></p>
        </div>

        <!-- Quiz Ready Section (Hidden by default) -->
        <div id="va-ready-section" class="va-card va-ready-card" style="display: none;">
            <div class="va-ready-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                    <polyline points="22 4 12 14.01 9 11.01"/>
                </svg>
            </div>
            <h3 class="va-ready-title"><?php echo get_string('quiz_ready', 'mod_aivideoactivity'); ?></h3>
            <p id="va-ready-summary" class="va-ready-summary"></p>
            <div id="va-teacher-eta"></div>
            <div class="va-ready-regen-section">
                <div class="va-form-group">
                    <label for="va-ready-extra-instructions" class="va-label">Extra AI Instructions</label>
                    <textarea id="va-ready-extra-instructions" class="va-textarea" rows="3"
                        placeholder="Add or modify instructions for the AI to refine the generated questions..."></textarea>
                    <small class="va-help">Edit these instructions and click Regenerate to refine your questions. First 3 regenerations are free.</small>
                </div>
                <div class="va-regen-controls">
                    <button id="va-ready-regenerate-btn" class="va-btn va-btn-secondary">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px; vertical-align: middle;">
                            <polyline points="1 4 1 10 7 10"/>
                            <polyline points="23 20 23 14 17 14"/>
                            <path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 0 1 3.51 15"/>
                        </svg>
                        Regenerate Questions
                    </button>
                    <span id="va-ready-regen-count" class="va-regen-count"></span>
                </div>
            </div>
            <div class="va-ready-actions">
                <button id="va-preview-btn" class="va-btn va-btn-primary">
                    <?php echo get_string('review', 'mod_aivideoactivity'); ?>
                </button>
                <button id="va-edit-btn" class="va-btn va-btn-secondary">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px; vertical-align: middle;">
                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                    </svg>
                    Edit Questions
                </button>
            </div>
        </div>

        <!-- Edit Questions Section (Hidden by default) -->
        <div id="va-edit-section" class="va-card" style="display: none;">
            <div class="va-edit-header">
                <h3 class="va-card-title">Edit Questions</h3>
                <div class="va-edit-actions">
                    <button id="va-settings-btn" class="va-btn va-btn-outline" title="Settings">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px; vertical-align: middle;">
                            <circle cx="12" cy="12" r="3"/>
                            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                        </svg>
                        Settings
                    </button>
                    <button id="va-save-edits-btn" class="va-btn va-btn-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px; vertical-align: middle;">
                            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                            <polyline points="17 21 17 13 7 13 7 21"/>
                            <polyline points="7 3 7 8 15 8"/>
                        </svg>
                        Save Changes
                    </button>
                    <button id="va-cancel-edits-btn" class="va-btn va-btn-secondary">Cancel</button>
                </div>
            </div>
            <p class="va-edit-info">Edit question text, answer options, correct answer, and explanations.</p>
            <div class="va-edit-regen-section">
                <div class="va-form-group">
                    <label for="va-edit-extra-instructions" class="va-label">Extra AI Instructions</label>
                    <textarea id="va-edit-extra-instructions" class="va-textarea" rows="3"
                        placeholder="Add or modify instructions for the AI to refine the generated questions..."></textarea>
                    <small class="va-help">Edit these instructions and click Regenerate to refine your questions. First 3 regenerations are free.</small>
                </div>
                <div class="va-regen-controls">
                    <button id="va-edit-regenerate-btn" class="va-btn va-btn-secondary">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px; vertical-align: middle;">
                            <polyline points="1 4 1 10 7 10"/>
                            <polyline points="23 20 23 14 17 14"/>
                            <path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 0 1 3.51 15"/>
                        </svg>
                        Regenerate Questions
                    </button>
                    <span id="va-edit-regen-count" class="va-regen-count"></span>
                </div>
                <p id="va-edit-summary" class="va-ready-summary"></p>
            </div>
            <div id="va-edit-questions-container"></div>
        </div>

        <!-- Settings Modal Overlay -->
        <div id="va-settings-overlay" class="va-settings-overlay" style="display: none;">
            <div class="va-settings-modal">
                <div class="va-settings-modal-header">
                    <h3 class="va-settings-modal-title">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="3"/>
                            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                        </svg>
                        Quiz Settings
                    </h3>
                    <button id="va-settings-close-btn" class="va-settings-close" title="Close">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>

                <div class="va-settings-modal-body">
                    <div class="va-settings-section">
                        <h4 class="va-settings-section-title">Content Language</h4>
                        <p class="va-settings-section-desc">Controls the spelling and grammar of generated questions (e.g. colour vs color).</p>
                        <div class="va-form-group" style="margin-bottom: 0;">
                            <select id="va-settings-voice-language" class="va-select">
                                <optgroup label="English">
                                    <option value="en-AU" selected>English (Australia)</option>
                                    <option value="en-GB">English (UK)</option>
                                    <option value="en-IN">English (India)</option>
                                    <option value="en-US">English (US)</option>
                                </optgroup>
                                <optgroup label="Spanish">
                                    <option value="es-ES">Spanish (Spain)</option>
                                    <option value="es-US">Spanish (US)</option>
                                </optgroup>
                                <optgroup label="French">
                                    <option value="fr-CA">French (Canada)</option>
                                    <option value="fr-FR">French (France)</option>
                                </optgroup>
                                <optgroup label="German">
                                    <option value="de-DE">German</option>
                                </optgroup>
                                <optgroup label="Portuguese">
                                    <option value="pt-BR">Portuguese (Brazil)</option>
                                </optgroup>
                                <optgroup label="Dutch">
                                    <option value="nl-BE">Dutch (Belgium)</option>
                                    <option value="nl-NL">Dutch (Netherlands)</option>
                                </optgroup>
                                <optgroup label="Nordic">
                                    <option value="da-DK">Danish</option>
                                    <option value="fi-FI">Finnish</option>
                                    <option value="nb-NO">Norwegian</option>
                                    <option value="sv-SE">Swedish</option>
                                </optgroup>
                                <optgroup label="Eastern European">
                                    <option value="bg-BG">Bulgarian</option>
                                    <option value="cs-CZ">Czech</option>
                                    <option value="hr-HR">Croatian</option>
                                    <option value="hu-HU">Hungarian</option>
                                    <option value="pl-PL">Polish</option>
                                    <option value="ro-RO">Romanian</option>
                                    <option value="ru-RU">Russian</option>
                                    <option value="sk-SK">Slovak</option>
                                    <option value="sl-SI">Slovenian</option>
                                    <option value="sr-RS">Serbian</option>
                                    <option value="uk-UA">Ukrainian</option>
                                </optgroup>
                                <optgroup label="Baltic">
                                    <option value="et-EE">Estonian</option>
                                    <option value="lt-LT">Lithuanian</option>
                                    <option value="lv-LV">Latvian</option>
                                </optgroup>
                                <optgroup label="Southern European">
                                    <option value="el-GR">Greek</option>
                                    <option value="it-IT">Italian</option>
                                </optgroup>
                                <optgroup label="East Asian">
                                    <option value="cmn-CN">Chinese (Mandarin)</option>
                                    <option value="ja-JP">Japanese</option>
                                    <option value="ko-KR">Korean</option>
                                </optgroup>
                                <optgroup label="Southeast Asian">
                                    <option value="id-ID">Indonesian</option>
                                    <option value="th-TH">Thai</option>
                                    <option value="vi-VN">Vietnamese</option>
                                </optgroup>
                                <optgroup label="South Asian">
                                    <option value="bn-IN">Bengali</option>
                                    <option value="gu-IN">Gujarati</option>
                                    <option value="hi-IN">Hindi</option>
                                    <option value="kn-IN">Kannada</option>
                                    <option value="ml-IN">Malayalam</option>
                                    <option value="mr-IN">Marathi</option>
                                    <option value="ta-IN">Tamil</option>
                                    <option value="te-IN">Telugu</option>
                                    <option value="ur-IN">Urdu</option>
                                </optgroup>
                                <optgroup label="Middle Eastern">
                                    <option value="ar-XA">Arabic</option>
                                    <option value="he-IL">Hebrew</option>
                                    <option value="tr-TR">Turkish</option>
                                </optgroup>
                                <optgroup label="African">
                                    <option value="sw-KE">Swahili</option>
                                </optgroup>
                            </select>
                        </div>
                    </div>

                    <div class="va-settings-divider"></div>

                    <div class="va-settings-section">
                        <h4 class="va-settings-section-title">Voiceover</h4>
                        <div class="va-toggle-row" style="margin-bottom: 12px;">
                            <label class="va-toggle-label" for="va-settings-voiceover-toggle">
                                <input type="checkbox" id="va-settings-voiceover-toggle" <?php echo (!empty($videoactivity->voiceoverenabled)) ? 'checked' : ''; ?>>
                                <span>Enable voiceover narration</span>
                            </label>
                            <small class="va-help">AI-generated voice reads explanations aloud after each answer.</small>
                        </div>
                    </div>

                    <div id="va-settings-voice-options">
                        <div class="va-settings-divider"></div>

                        <div class="va-settings-section">
                            <h4 class="va-settings-section-title">Voice Settings</h4>
                            <div class="va-form-row">
                                <div class="va-form-group va-half">
                                    <label for="va-settings-voice-gender">Voice Gender</label>
                                    <select id="va-settings-voice-gender" class="va-select">
                                        <option value="female">Female</option>
                                        <option value="male">Male</option>
                                    </select>
                                </div>
                                <div class="va-form-group va-half">
                                    <label for="va-settings-voice-style">Voice Style</label>
                                    <select id="va-settings-voice-style" class="va-select">
                                        <option value="Aoede">Aoede (warm, friendly)</option>
                                        <option value="Kore">Kore (clear, professional)</option>
                                        <option value="Leda">Leda (soft, nurturing)</option>
                                        <option value="Zephyr">Zephyr (energetic, youthful)</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="va-settings-modal-footer">
                    <p id="va-settings-warning-text" class="va-settings-warning">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        <span id="va-settings-warning-msg">Changing language will regenerate questions and uses credits.</span>
                    </p>
                    <div class="va-settings-footer-actions">
                        <button id="va-settings-cancel-btn" class="va-btn va-btn-secondary">Cancel</button>
                        <button id="va-settings-save-btn" class="va-btn va-btn-primary">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 4px; vertical-align: middle;"><polyline points="23 4 11.5 15.5 6 10"/></svg>
                            Save Settings
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quiz Player (Hidden by default) -->
        <div id="va-quiz-player" class="va-card" style="display: none;">
            <div class="va-quiz-header">
                <span id="va-question-counter" class="va-question-counter"></span>
                <span id="va-quiz-score" class="va-quiz-score"></span>
            </div>
            <div id="va-question-container" class="va-question-container">
                <h4 id="va-question-text" class="va-question-text"></h4>
                <div id="va-options-container" class="va-options"></div>
            </div>
            <div id="va-feedback-container" class="va-feedback" style="display: none;">
                <div id="va-feedback-result" class="va-feedback-result"></div>
                <p id="va-feedback-explanation" class="va-feedback-explanation"></p>
                <button id="va-play-audio-btn" class="va-btn va-btn-secondary">
                    <?php echo get_string('play_explanation', 'mod_aivideoactivity'); ?>
                </button>
            </div>
            <div class="va-quiz-actions">
                <button id="va-check-answer-btn" class="va-btn va-btn-primary" disabled>
                    <?php echo get_string('check_answer', 'mod_aivideoactivity'); ?>
                </button>
                <button id="va-try-again-btn" class="va-btn va-btn-warning" style="display: none;">
                    <?php echo get_string('try_again', 'mod_aivideoactivity'); ?>
                </button>
                <button id="va-next-question-btn" class="va-btn va-btn-primary" style="display: none;">
                    <?php echo get_string('next_question', 'mod_aivideoactivity'); ?>
                </button>
            </div>
        </div>

        <!-- Quiz Results (Hidden by default) -->
        <div id="va-results-section" style="display: none;"></div>
    </div>
    <?php

    // Check for any in-progress student attempts (for edit warning)
    $inprogresscount = $DB->count_records('aivideoactivity_attempts', [
        'aivideoactivityid' => $videoactivity->id,
        'status' => 0, // In progress
    ]);

    $PAGE->requires->js_call_amd('mod_aivideoactivity/videoactivity', 'init', [[
        'cmid' => $cm->id,
        'wwwroot' => $CFG->wwwroot,
        'sesskey' => sesskey(),
        'isTeacher' => true,
        'questionCount' => (int)$questioncount,
        'inProgressAttempts' => (int)$inprogresscount,
        'gradePass' => $gradepass,
        'maxGrade' => $maxgrade,
        'voiceoverEnabled' => isset($videoactivity->voiceoverenabled) ? (int)$videoactivity->voiceoverenabled : 0,
        'scoringMode' => isset($videoactivity->scoringmode) ? (int)$videoactivity->scoringmode : 0,
        'voiceLanguage' => isset($videoactivity->voicelanguage) ? $videoactivity->voicelanguage : 'en-AU',
        'voiceGender' => isset($videoactivity->voicegender) ? $videoactivity->voicegender : 'female',
        'voiceStyle' => isset($videoactivity->voicestyle) ? $videoactivity->voicestyle : 'Aoede',
        'mediaType' => isset($videoactivity->mediatype) ? $videoactivity->mediatype : 'video',
        'youtubeUrl' => isset($videoactivity->youtubeurl) ? $videoactivity->youtubeurl : '',
        'audioUrl' => isset($teacherAudioUrl) ? $teacherAudioUrl : '',
        'audioFilename' => isset($teacherAudioFilename) ? $teacherAudioFilename : '',
        'transcriptText' => isset($videoactivity->transcripttext) ? $videoactivity->transcripttext : '',
        'watchMode' => isset($videoactivity->watchmode) ? $videoactivity->watchmode : 'all',
        'watchSeconds' => isset($videoactivity->watchseconds) ? (int)$videoactivity->watchseconds : 0,
        'showVideoDuringQuiz' => !empty($videoactivity->showvideoduringquiz),
        'showChapterStamps' => !empty($videoactivity->showchapterstamps),
        'courseUrl' => (new moodle_url('/course/view.php', ['id' => $course->id]))->out(),
    ]]);
} else {
    // Student view.
    if ($questioncount == 0) {
        // No questions yet.
        echo html_writer::div(get_string('students_view_message', 'mod_aivideoactivity'), 'alert alert-info');
    } else {
        // Get attempt info.
        $userid = $USER->id;
        $attemptsused = aivideoactivity_count_attempts($videoactivity->id, $userid);
        $maxattempts = aivideoactivity_effective_maxattempts($videoactivity, $userid);
        $canattempt = aivideoactivity_can_attempt($videoactivity, $userid);

        // Check for in-progress attempt.
        $inprogress = $DB->get_record('aivideoactivity_attempts', [
            'aivideoactivityid' => $videoactivity->id,
            'userid' => $userid,
            'status' => 0,
        ]);

        // Build attempts label.
        if ($maxattempts > 0) {
            $attemptslabel = get_string('attemptsused', 'mod_aivideoactivity') . ': ' . $attemptsused . ' / ' . $maxattempts;
        } else {
            $attemptslabel = get_string('attemptsused', 'mod_aivideoactivity') . ': ' . $attemptsused . ' (' . get_string('unlimited', 'mod_aivideoactivity') . ')';
        }

        // Show previous attempts table.
        $attempts = $DB->get_records('aivideoactivity_attempts', [
            'aivideoactivityid' => $videoactivity->id,
            'userid' => $userid,
            'status' => 1,
        ], 'id ASC');

        if ($attempts) {
            echo html_writer::start_tag('details', ['class' => 'va-details mb-3']);
            echo html_writer::tag('summary', get_string('review', 'mod_aivideoactivity'));
            
            $table = new html_table();
            $table->head = [
                get_string('attempt', 'mod_aivideoactivity'),
                get_string('score', 'mod_aivideoactivity'),
                get_string('timeended', 'mod_aivideoactivity'),
            ];
            
            $num = 1;
            foreach ($attempts as $a) {
                $table->data[] = [
                    $num++,
                    $a->correctcount . '/' . $a->totalcount,
                    userdate($a->timeended),
                ];
            }
            
            echo html_writer::table($table);
            echo html_writer::end_tag('details');
        }

        ?>
        <div id="va-app" class="va-container">
            <?php if (!$canattempt && !$inprogress): ?>
                <!-- Limit reached -->
                <div class="va-card">
                    <div class="alert alert-warning">
                        <?php echo get_string('attemptslimitreached', 'mod_aivideoactivity', $maxattempts); ?>
                    </div>
                </div>
            <?php else: ?>
                <!-- Estimated Time Banner -->
                <?php
                    $mediatype = isset($videoactivity->mediatype) ? $videoactivity->mediatype : 'video';
                    $isaudio = ($mediatype === 'audio');
                    $watchmode_s = isset($videoactivity->watchmode) ? $videoactivity->watchmode : 'all';
                    $watchsec = isset($videoactivity->watchseconds) ? (int)$videoactivity->watchseconds : 0;
                    $mediaSec = 0;
                    if ($watchmode_s === 'seconds' && $watchsec > 0) {
                        $mediaSec = $watchsec;
                    } else if ($watchmode_s === 'all') {
                        $mediaSec = 300;
                    }
                    $quizSec = $questioncount * 45;
                    $totalEtaSec = $mediaSec + $quizSec;
                    $etaMin = (int)ceil($totalEtaSec / 60);
                    if ($etaMin < 1) $etaMin = 1;
                    if ($etaMin < 60) {
                        $etaStr = '~' . $etaMin . ' minute' . ($etaMin > 1 ? 's' : '');
                    } else {
                        $etaHrs = floor($etaMin / 60);
                        $etaRem = $etaMin % 60;
                        $etaStr = '~' . $etaHrs . ($etaHrs == 1 ? ' hr ' : ' hrs ') . $etaRem . ' min';
                    }
                    $mediaLabel = $isaudio ? 'audio' : 'video';
                    $etaDetail = ($watchmode_s !== 'none' ? ucfirst($mediaLabel) . ' + ' : '') . $questioncount . ' quiz question' . ($questioncount != 1 ? 's' : '');
                ?>
                <div class="va-eta-banner">
                    <div class="va-eta-icon-wrap">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </div>
                    <div class="va-eta-body">
                        <span class="va-eta-label">Estimated completion time</span>
                        <span class="va-eta-time"><?php echo $etaStr; ?></span>
                        <span class="va-eta-detail"><?php echo $etaDetail; ?></span>
                    </div>
                </div>

                <!-- Media Player Section -->
                <div id="va-start-section" class="va-card">
                    <h3 class="va-card-title"><?php echo get_string($isaudio ? 'audio_player_title' : 'video_player_title', 'mod_aivideoactivity'); ?></h3>
                    <?php if ($isaudio):
                        $fs = get_file_storage();
                        $audiofiles = $fs->get_area_files($context->id, 'mod_aivideoactivity', 'audiofile', 0, 'id', false);
                        $audiofileurl = '';
                        if ($audiofiles) {
                            $audiofile = reset($audiofiles);
                            $audiofileurl = moodle_url::make_pluginfile_url(
                                $context->id,
                                'mod_aivideoactivity',
                                'audiofile',
                                0,
                                $audiofile->get_filepath(),
                                $audiofile->get_filename()
                            )->out(false);
                        }
                    ?>
                    <div id="va-audio-player-container" class="va-audio-container">
                        <audio id="va-audio-player" controls preload="metadata" style="width: 100%;">
                            <?php if ($audiofileurl): ?>
                            <source src="<?php echo s($audiofileurl); ?>">
                            <?php endif; ?>
                            Your browser does not support the audio element.
                        </audio>
                    </div>
                    <?php else: ?>
                    <div id="va-youtube-player-container" class="va-youtube-container">
                    </div>
                    <?php endif; ?>
                    <?php
                    $watchmode = isset($videoactivity->watchmode) ? $videoactivity->watchmode : 'all';
                    if ($watchmode !== 'none'):
                    ?>
                        <div id="va-watch-progress" class="va-watch-progress">
                            <div class="va-watch-progress-bar">
                                <div id="va-watch-progress-fill" class="va-watch-progress-fill" style="width: 0%;"></div>
                            </div>
                            <p id="va-watch-progress-text" class="va-watch-progress-text">
                                <?php echo get_string($isaudio ? 'listen_requirement_msg' : 'watch_requirement_msg', 'mod_aivideoactivity'); ?>
                            </p>
                        </div>
                    <?php endif; ?>
                    <div class="va-video-actions">
                        <?php
                        // FIX-VA-RETAKE-FREE-VIDEO (v1.0.108): Lift haspreviousattempts
                        // up so it gates BOTH the disabled state (button enabled if the
                        // student has already completed at least one attempt — they have
                        // already proved they watched the video, so re-imposing the watch
                        // gate just blocks them from reattempting) AND the existing label
                        // toggle ("Start Quiz" vs "Retake Quiz"). Continue-attempt is
                        // never disabled because by definition the student was already
                        // past the watch gate when they started that in-progress attempt.
                        $haspreviousattempts = $DB->record_exists('aivideoactivity_attempts', [
                            'aivideoactivityid' => $videoactivity->id,
                            'userid' => $userid,
                            'status' => 1
                        ]);
                        $startdisabled = ($watchmode !== 'none' && !$haspreviousattempts) ? 'disabled' : '';
                        ?>
                        <?php if ($inprogress): ?>
                            <button id="va-continue-attempt-btn" class="va-btn va-btn-primary va-btn-lg" data-attemptid="<?php echo $inprogress->id; ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polygon points="5 3 19 12 5 21 5 3"></polygon>
                                </svg>
                                Continue Attempt
                            </button>
                        <?php else: ?>
                            <button id="va-start-quiz-btn" class="va-btn va-btn-primary va-btn-lg" <?php echo $startdisabled; ?>>
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polygon points="5 3 19 12 5 21 5 3"></polygon>
                                </svg>
                                <?php echo get_string($haspreviousattempts ? 'retakequiz' : 'startquiz', 'mod_aivideoactivity'); ?>
                            </button>
                        <?php endif; ?>
                        <span class="va-attempts-badge">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M1 4v6h6"></path>
                                <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path>
                            </svg>
                            <?php echo $attemptslabel; ?>
                        </span>
                    </div>
                </div>

                <!-- Quiz Player (Hidden by default) -->
                <div id="va-quiz-player" class="va-card" style="display: none;">
                    <div class="va-quiz-header">
                        <span id="va-question-counter" class="va-question-counter"></span>
                        <div class="va-quiz-header-right">
                            <span class="va-attempts-badge va-attempts-badge-sm">
                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M1 4v6h6"></path>
                                    <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path>
                                </svg>
                                <?php echo $attemptslabel; ?>
                            </span>
                            <span id="va-quiz-score" class="va-quiz-score"></span>
                        </div>
                    </div>
                    <div id="va-question-container" class="va-question-container">
                        <h4 id="va-question-text" class="va-question-text"></h4>
                        <div id="va-options-container" class="va-options"></div>
                    </div>
                    <div id="va-feedback-container" class="va-feedback" style="display: none;">
                        <div id="va-feedback-result" class="va-feedback-result"></div>
                        <p id="va-feedback-explanation" class="va-feedback-explanation"></p>
                    </div>
                    <div class="va-quiz-actions">
                        <button id="va-check-answer-btn" class="va-btn va-btn-primary" disabled>
                            <?php echo get_string('check_answer', 'mod_aivideoactivity'); ?>
                        </button>
                        <button id="va-try-again-btn" class="va-btn va-btn-warning" style="display: none;">
                            <?php echo get_string('try_again', 'mod_aivideoactivity'); ?>
                        </button>
                        <button id="va-next-question-btn" class="va-btn va-btn-primary" style="display: none;">
                            <?php echo get_string('next_question', 'mod_aivideoactivity'); ?>
                        </button>
                    </div>
                </div>

                <!-- Quiz Results (Hidden by default, JS builds the content) -->
                <div id="va-results-section" style="display: none;"></div>
            <?php endif; ?>
        </div>
        <?php

        // Initialize JS module for student.
        $PAGE->requires->js_call_amd('mod_aivideoactivity/videoactivity', 'init', [[
            'cmid' => $cm->id,
            'wwwroot' => $CFG->wwwroot,
            'sesskey' => sesskey(),
            'isTeacher' => false,
            'questionCount' => (int)$questioncount,
            'attemptsUsed' => $attemptsused,
            'attemptsUsedStr' => get_string('attemptsused', 'mod_aivideoactivity'),
            'attemptsUnlimitedStr' => get_string('unlimited', 'mod_aivideoactivity'),
            'maxAttempts' => $maxattempts,
            'canAttempt' => $canattempt,
            'hasInProgress' => !empty($inprogress),
            'hasPreviousAttempts' => !empty($haspreviousattempts),
            'startQuizLabel' => get_string('startquiz', 'mod_aivideoactivity'),
            'retakeQuizLabel' => get_string('retakequiz', 'mod_aivideoactivity'),
            'inProgressAttemptId' => $inprogress ? (int)$inprogress->id : null,
            'inProgressAttemptQuestion' => $inprogress ? (int)$inprogress->currentquestion : 0,
            'gradePass' => $gradepass,
            'maxGrade' => $maxgrade,
            'mediaType' => isset($videoactivity->mediatype) ? $videoactivity->mediatype : 'video',
            'youtubeUrl' => isset($videoactivity->youtubeurl) ? $videoactivity->youtubeurl : '',
            'audioUrl' => isset($audiofileurl) ? $audiofileurl : '',
            'watchMode' => isset($videoactivity->watchmode) ? $videoactivity->watchmode : 'all',
            'watchSeconds' => isset($videoactivity->watchseconds) ? (int)$videoactivity->watchseconds : 0,
            'showVideoDuringQuiz' => !empty($videoactivity->showvideoduringquiz),
            'showChapterStamps' => !empty($videoactivity->showchapterstamps),
            'scoringMode' => isset($videoactivity->scoringmode) ? (int)$videoactivity->scoringmode : 0,
            'courseUrl' => (new moodle_url('/course/view.php', ['id' => $course->id]))->out(),
        ]]);
    }
}

echo $OUTPUT->footer();
