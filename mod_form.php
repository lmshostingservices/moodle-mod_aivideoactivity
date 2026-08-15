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
 * AI Video Activity instance add/edit form.
 *
 * @package    mod_aivideoactivity
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

/**
 * Module instance settings form.
 */
class mod_aivideoactivity_mod_form extends moodleform_mod {
    /**
     * Defines forms elements.
     */
    public function definition() {
        global $CFG;
        $mform = $this->_form;

        // General settings.
        $mform->addElement('header', 'general', get_string('general', 'form'));

        // Name field.
        $mform->addElement('text', 'name', get_string('videoactivityname', 'mod_aivideoactivity'), ['size' => '64']);
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('name', PARAM_TEXT);
        } else {
            $mform->setType('name', PARAM_CLEANHTML);
        }
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        // Description.
        $this->standard_intro_elements();

        // Media settings header.
        $mform->addElement('header', 'videosettings', get_string('mediasettings', 'mod_aivideoactivity'));

        // Media type selector.
        $mediatypeoptions = [
            'video' => get_string('mediatype_video', 'mod_aivideoactivity'),
            'audio' => get_string('mediatype_audio', 'mod_aivideoactivity'),
        ];
        $mform->addElement('select', 'mediatype', get_string('mediatype', 'mod_aivideoactivity'), $mediatypeoptions);
        $mform->setDefault('mediatype', 'video');
        $mform->addHelpButton('mediatype', 'mediatype', 'mod_aivideoactivity');

        // YouTube URL field (shown when mediatype is video).
        $mform->addElement('text', 'youtubeurl', get_string('youtubeurl', 'mod_aivideoactivity'), ['size' => '64']);
        $mform->setType('youtubeurl', PARAM_URL);
        $mform->addHelpButton('youtubeurl', 'youtubeurl', 'mod_aivideoactivity');
        $mform->hideIf('youtubeurl', 'mediatype', 'eq', 'audio');

        // Audio file upload (shown when mediatype is audio).
        $mform->addElement('filepicker', 'audiofile', get_string('audiofile', 'mod_aivideoactivity'), null, [
            'maxbytes' => 0,
            'accepted_types' => ['.mp3', '.wav', '.ogg', '.m4a', '.aac', '.flac', '.wma', '.opus', '.webm', '.aiff'],
            'return_types' => FILE_INTERNAL,
        ]);
        $mform->addHelpButton('audiofile', 'audiofile', 'mod_aivideoactivity');
        $mform->hideIf('audiofile', 'mediatype', 'eq', 'video');

        // Watch mode dropdown.
        $watchmodeoptions = [
            'all' => get_string('watchmode_all', 'mod_aivideoactivity'),
            'seconds' => get_string('watchmode_seconds', 'mod_aivideoactivity'),
            'none' => get_string('watchmode_none', 'mod_aivideoactivity'),
        ];
        $mform->addElement('select', 'watchmode', get_string('watchmode', 'mod_aivideoactivity'), $watchmodeoptions);
        $mform->setDefault('watchmode', 'all');
        $mform->addHelpButton('watchmode', 'watchmode', 'mod_aivideoactivity');

        // Watch seconds field (only visible when watchmode is 'seconds').
        $mform->addElement('text', 'watchseconds', get_string('watchseconds', 'mod_aivideoactivity'));
        $mform->setType('watchseconds', PARAM_INT);
        $mform->setDefault('watchseconds', 60);
        $mform->addHelpButton('watchseconds', 'watchseconds', 'mod_aivideoactivity');
        $mform->hideIf('watchseconds', 'watchmode', 'neq', 'seconds');

        // Show video above questions while student answers.
        $mform->addElement('advcheckbox', 'showvideoduringquiz', get_string('showvideoduringquiz', 'mod_aivideoactivity'), '', [], [0, 1]);
        $mform->setDefault('showvideoduringquiz', 0);
        $mform->addHelpButton('showvideoduringquiz', 'showvideoduringquiz', 'mod_aivideoactivity');
        $mform->hideIf('showvideoduringquiz', 'mediatype', 'eq', 'audio');

        // Show clickable chapter timestamp links per question.
        $mform->addElement('advcheckbox', 'showchapterstamps', get_string('showchapterstamps', 'mod_aivideoactivity'), '', [], [0, 1]);
        $mform->setDefault('showchapterstamps', 0);
        $mform->addHelpButton('showchapterstamps', 'showchapterstamps', 'mod_aivideoactivity');
        $mform->hideIf('showchapterstamps', 'mediatype', 'eq', 'audio');

        // Attempt settings header.
        $mform->addElement('header', 'attemptsettings', get_string('attemptsettings', 'mod_aivideoactivity'));

        // Maximum attempts field.
        $mform->addElement('text', 'maxattempts', get_string('attemptlimit', 'mod_aivideoactivity'));
        $mform->setType('maxattempts', PARAM_INT);
        $mform->setDefault('maxattempts', 0);
        $mform->addHelpButton('maxattempts', 'attemptlimit', 'mod_aivideoactivity');

        // CC Email for notifications.
        $mform->addElement('text', 'ccemail', get_string('ccemail', 'mod_aivideoactivity'), ['size' => '64']);
        $mform->setType('ccemail', PARAM_RAW); // pipeline-ignore: PARAM_RAW — free-form rich text/HTML, escaped or format_text()d on output
        $mform->addHelpButton('ccemail', 'ccemail', 'mod_aivideoactivity');

        // Grade settings header.
        $mform->addElement('header', 'gradesettings', get_string('gradesettings', 'mod_aivideoactivity'));

        $mform->addElement('modgrade', 'grade', get_string('modgrade', 'grades'));
        $mform->setDefault('grade', 100);

        // Passing grade (numeric value, e.g. 80 out of 100).
        $mform->addElement('text', 'gradepass', get_string('gradepass', 'grades'));
        $mform->setType('gradepass', PARAM_FLOAT);
        $mform->setDefault('gradepass', 0);
        $mform->addHelpButton('gradepass', 'passinggrade', 'mod_aivideoactivity');

        // Scoring mode: retry until correct vs first attempt only.
        $scoringmodeoptions = [
            0 => get_string('scoringmode_retry', 'mod_aivideoactivity'),
            1 => get_string('scoringmode_firstonly', 'mod_aivideoactivity'),
        ];
        $mform->addElement('select', 'scoringmode', get_string('scoringmode', 'mod_aivideoactivity'), $scoringmodeoptions);
        $mform->setDefault('scoringmode', 0);
        $mform->addHelpButton('scoringmode', 'scoringmode', 'mod_aivideoactivity');

        // Standard elements.
        $this->standard_coursemodule_elements();

        // Action buttons.
        $this->add_action_buttons();
    }

    /**
     * Add completion rules for this activity.
     *
     * @return array Array of completion rule elements.
     */
    public function add_completion_rules() {
        $mform = $this->_form;
        $suffix = $this->get_suffix();

        $mform->addElement('checkbox', 'completionallcorrect' . $suffix, 
            get_string('completionallcorrect', 'mod_aivideoactivity'));
        $mform->setDefault('completionallcorrect' . $suffix, 0);
        $mform->addHelpButton('completionallcorrect' . $suffix, 'completionallcorrect', 'mod_aivideoactivity');

        return ['completionallcorrect' . $suffix];
    }

    /**
     * Check if a completion rule is enabled.
     *
     * @param array $data Form data.
     * @return bool True if any completion rule is enabled.
     */
    public function completion_rule_enabled($data) {
        $suffix = $this->get_suffix();
        return !empty($data['completionallcorrect' . $suffix]);
    }

    /**
     * Post-process form data before saving.
     *
     * @param object $data Form data object.
     */
    public function data_postprocessing($data) {
        parent::data_postprocessing($data);
        $suffix = $this->get_suffix();
        $data->completionallcorrect = !empty($data->{"completionallcorrect$suffix"}) ? 1 : 0;

        // Normalise gradepass to a float for the gradebook.
        $data->gradepass = !empty($data->gradepass) ? round((float)$data->gradepass, 5) : 0.0;
        // Keep passinggrade column in sync for backwards compatibility (integer column).
        $data->passinggrade = (int)round($data->gradepass);
    }

    /**
     * Pre-process default values before displaying the form.
     *
     * @param array $defaultvalues Reference to default values array.
     */
    public function data_preprocessing(&$defaultvalues) {
        parent::data_preprocessing($defaultvalues);
        $suffix = $this->get_suffix();
        if (isset($defaultvalues['completionallcorrect'])) {
            $defaultvalues["completionallcorrect$suffix"] = $defaultvalues['completionallcorrect'];
        }

        if (!isset($defaultvalues['grade']) || (int)$defaultvalues['grade'] <= 0) {
            $defaultvalues['grade'] = 100;
        }

        // Prepare audio file draft area for filepicker.
        if (isset($defaultvalues['instance'])) {
            $cm = get_coursemodule_from_instance('aivideoactivity', $defaultvalues['instance'], $defaultvalues['course']);
            if ($cm) {
                $context = context_module::instance($cm->id);
                $draftitemid = file_get_submitted_draft_itemid('audiofile');
                file_prepare_draft_area(
                    $draftitemid,
                    $context->id,
                    'mod_aivideoactivity',
                    'audiofile',
                    0,
                    ['maxbytes' => 0, 'accepted_types' => ['.mp3', '.wav', '.ogg', '.m4a', '.aac', '.flac', '.wma', '.opus', '.webm', '.aiff']]
                );
                $defaultvalues['audiofile'] = $draftitemid;
            }
        }

        // Load gradepass from the gradebook grade_items table (authoritative source).
        if (isset($defaultvalues['instance'])) {
            global $CFG;
            require_once($CFG->libdir . '/gradelib.php');
            $gradeitem = grade_item::fetch([
                'itemtype' => 'mod',
                'itemmodule' => 'aivideoactivity',
                'iteminstance' => $defaultvalues['instance'],
                'courseid' => $defaultvalues['course'],
                'itemnumber' => 0,
            ]);
            if ($gradeitem && $gradeitem->gradepass > 0) {
                $defaultvalues['gradepass'] = format_float($gradeitem->gradepass, 5, true, true);
            } else {
                $defaultvalues['gradepass'] = 0;
            }
            // Sync passinggrade to prevent stale values.
            $defaultvalues['passinggrade'] = (int)round($gradeitem ? (float)$gradeitem->gradepass : 0);
        }
    }

    /**
     * Validate form data.
     *
     * @param array $data Form data.
     * @param array $files Uploaded files.
     * @return array Validation errors.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        // Validate maxattempts is non-negative.
        if (isset($data['maxattempts']) && $data['maxattempts'] < 0) {
            $errors['maxattempts'] = get_string('error:negativeattempts', 'mod_aivideoactivity');
        }

        // Validate gradepass is a valid number within range.
        if (!empty($data['gradepass'])) {
            $gradepass = unformat_float($data['gradepass'], true);
            if ($gradepass === false || $gradepass < 0) {
                $errors['gradepass'] = get_string('error:invalidgradepass', 'mod_aivideoactivity');
            } else {
                $grademax = isset($data['grade']) ? (int)$data['grade'] : 100;
                if ($grademax > 0 && $gradepass > $grademax) {
                    $errors['gradepass'] = get_string('error:gradepasstoohigh', 'mod_aivideoactivity');
                }
            }
        }

        // Validate CC email format (if provided).
        if (!empty($data['ccemail'])) {
            // Allow comma-separated emails.
            $emails = array_map('trim', explode(',', $data['ccemail']));
            foreach ($emails as $email) {
                if (!validate_email($email)) {
                    $errors['ccemail'] = get_string('error:invalidemail', 'mod_aivideoactivity');
                    break;
                }
            }
        }

        return $errors;
    }
}
