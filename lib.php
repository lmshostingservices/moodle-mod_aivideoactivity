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
 * Library of functions for AI Video Activity.
 *
 * @package    mod_aivideoactivity
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Returns the information on whether the module supports a feature.
 *
 * @param string $feature FEATURE_xx constant for requested feature
 * @return mixed True if module supports feature, null if doesn't know
 */
function aivideoactivity_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        case FEATURE_BACKUP_MOODLE2:
            return true;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_ASSESSMENT;
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return true;
        case FEATURE_COMPLETION_HAS_RULES:
            return true;
        case FEATURE_GRADE_HAS_GRADE:
            return true;
        case FEATURE_GRADE_OUTCOMES:
            return false;
        default:
            return null;
    }
}

/**
 * Saves a new instance of the module into the database.
 *
 * @param stdClass $data Form data
 * @param mod_aivideoactivity_mod_form|null $mform The form
 * @return int The id of the newly inserted record
 */
function aivideoactivity_add_instance($data, ?object $mform = null) {
    global $DB;

    $data->timecreated = time();
    $data->timemodified = time();
    
    // Ensure optional fields have defaults.
    if (!isset($data->maxattempts)) {
        $data->maxattempts = 0;
    }
    if (!isset($data->completionallcorrect)) {
        $data->completionallcorrect = 0;
    }
    if (!isset($data->ccemail)) {
        $data->ccemail = '';
    }

    // Media type field.
    if (!isset($data->mediatype)) {
        $data->mediatype = 'video';
    }

    // Video-specific fields.
    if (!isset($data->youtubeurl)) {
        $data->youtubeurl = '';
    }
    if (!isset($data->audiourl)) {
        $data->audiourl = '';
    }
    if (!isset($data->transcripttext)) {
        $data->transcripttext = '';
    }
    if (!isset($data->watchmode)) {
        $data->watchmode = 'all';
    }
    if (!isset($data->watchseconds)) {
        $data->watchseconds = 0;
    }

    if (!isset($data->grade) || (int)$data->grade <= 0) {
        $data->grade = 100;
    }

    $data->id = $DB->insert_record('aivideoactivity', $data);

    // Save audio file from draft area.
    if (!empty($data->audiofile)) {
        $context = context_module::instance($data->coursemodule);
        file_save_draft_area_files(
            $data->audiofile,
            $context->id,
            'mod_aivideoactivity',
            'audiofile',
            0,
            ['maxbytes' => 0, 'accepted_types' => ['.mp3', '.wav', '.ogg', '.m4a', '.aac', '.flac', '.wma', '.opus', '.webm', '.aiff']]
        );
    }

    // Create grade item in gradebook with passing grade.
    if (!isset($data->gradepass)) {
        $data->gradepass = (!empty($data->passinggrade) && (int)$data->passinggrade > 0)
            ? (float)$data->passinggrade : 0;
    }
    aivideoactivity_grade_item_update($data);

    return $data->id;
}

/**
 * Updates an instance of the module in the database.
 *
 * @param stdClass $data Form data
 * @param mod_aivideoactivity_mod_form|null $mform The form
 * @return bool Success/Failure
 */
function aivideoactivity_update_instance($data, ?object $mform = null) {
    global $DB;

    $data->timemodified = time();
    $data->id = $data->instance;

    // Ensure optional fields exist.
    if (!isset($data->maxattempts)) {
        $data->maxattempts = 0;
    }
    if (!isset($data->completionallcorrect)) {
        $data->completionallcorrect = 0;
    }
    if (!isset($data->ccemail)) {
        $data->ccemail = '';
    }

    // Media type field.
    if (!isset($data->mediatype)) {
        $data->mediatype = 'video';
    }

    // Video-specific fields.
    if (!isset($data->youtubeurl)) {
        $data->youtubeurl = '';
    }
    if (!isset($data->audiourl)) {
        $data->audiourl = '';
    }
    if (!isset($data->transcripttext)) {
        $data->transcripttext = '';
    }
    if (!isset($data->watchmode)) {
        $data->watchmode = 'all';
    }
    if (!isset($data->watchseconds)) {
        $data->watchseconds = 0;
    }

    if (!isset($data->grade) || (int)$data->grade <= 0) {
        $data->grade = 100;
    }

    $result = $DB->update_record('aivideoactivity', $data);

    // Save audio file from draft area.
    if (!empty($data->audiofile)) {
        $context = context_module::instance($data->coursemodule);
        file_save_draft_area_files(
            $data->audiofile,
            $context->id,
            'mod_aivideoactivity',
            'audiofile',
            0,
            ['maxbytes' => 0, 'accepted_types' => ['.mp3', '.wav', '.ogg', '.m4a', '.aac', '.flac', '.wma', '.opus', '.webm', '.aiff']]
        );
    }

    // Update grade item in gradebook with passing grade.
    if (!isset($data->gradepass)) {
        $data->gradepass = (!empty($data->passinggrade) && (int)$data->passinggrade > 0)
            ? (float)$data->passinggrade : 0;
    }
    aivideoactivity_grade_item_update($data);

    return $result;
}

/**
 * Removes an instance of the module from the database.
 *
 * @param int $id Id of the module instance
 * @return bool Success/Failure
 */
function aivideoactivity_delete_instance($id) {
    global $DB;

    $videoactivity = $DB->get_record('aivideoactivity', ['id' => $id]);
    if (!$videoactivity) {
        return false;
    }

    // Delete associated records.
    $DB->delete_records('aivideoactivity_questions', ['aivideoactivityid' => $id]);
    $DB->delete_records('aivideoactivity_attempts', ['aivideoactivityid' => $id]);
    $DB->delete_records('aivideoactivity_overrides', ['aivideoactivityid' => $id]);

    // Delete grade item from gradebook.
    aivideoactivity_grade_item_delete($videoactivity);

    // Delete the instance.
    $DB->delete_records('aivideoactivity', ['id' => $id]);

    return true;
}

/**
 * Returns all other caps used in the module.
 *
 * @return array
 */
function aivideoactivity_get_extra_capabilities() {
    return ['moodle/site:accessallgroups'];
}

/**
 * Create/update grade item for given video activity.
 *
 * @param stdClass $videoactivity Video activity object with extra cmidnumber
 * @param mixed $grades Optional array/object of grade(s); 'reset' means reset grades in gradebook
 * @return int 0 if ok, error code otherwise
 */
function aivideoactivity_grade_item_update($videoactivity, $grades = null) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $grademax = isset($videoactivity->grade) ? (int)$videoactivity->grade : 100;
    if ($grademax <= 0) {
        $grademax = 100;
    }

    $passgrade = 0;
    if (!empty($videoactivity->gradepass)) {
        $passgrade = (float)$videoactivity->gradepass;
    } else if (!empty($videoactivity->passinggrade) && (int)$videoactivity->passinggrade > 0) {
        $passgrade = (float)$videoactivity->passinggrade;
    }

    $params = [
        'itemname' => $videoactivity->name,
        'idnumber' => isset($videoactivity->cmidnumber) ? $videoactivity->cmidnumber : null,
        'gradetype' => GRADE_TYPE_VALUE,
        'grademax' => $grademax,
        'grademin' => 0,
        'gradepass' => $passgrade,
    ];

    if ($grades === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }

    $result = grade_update('mod/aivideoactivity', $videoactivity->course, 'mod', 'aivideoactivity',
        $videoactivity->id, 0, $grades, $params);

    return $result;
}

/**
 * Delete grade item for given video activity.
 *
 * @param stdClass $videoactivity Video activity object
 * @return int
 */
function aivideoactivity_grade_item_delete($videoactivity) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    return grade_update('mod/aivideoactivity', $videoactivity->course, 'mod', 'aivideoactivity',
        $videoactivity->id, 0, null, ['deleted' => 1]);
}

/**
 * Update grades in the gradebook.
 *
 * @param stdClass $videoactivity Video activity object
 * @param int $userid Specific user only, 0 means all users
 * @param bool $nullifnone If true and student has no grade, create a null grade
 * @return void
 */
function aivideoactivity_update_grades($videoactivity, $userid = 0, $nullifnone = true) {
    global $CFG, $DB;
    require_once($CFG->libdir . '/gradelib.php');

    // First, ensure grade item exists.
    aivideoactivity_grade_item_update($videoactivity);

    // Get the best completed attempt for each user (highest percentage).
    $params = ['aivideoactivityid' => $videoactivity->id, 'status' => 1];
    $userwhere = '';
    if ($userid) {
        $userwhere = ' AND userid = :userid';
        $params['userid'] = $userid;
    }

    // Get best score per user (highest percentage correct).
    $sql = "SELECT userid, MAX(CASE WHEN totalcount > 0 THEN (correctcount * 100.0 / totalcount) ELSE 0 END) as bestgrade
              FROM {aivideoactivity_attempts}
             WHERE aivideoactivityid = :aivideoactivityid
               AND status = :status
               $userwhere
          GROUP BY userid";

    $usersgrades = $DB->get_records_sql($sql, $params);

    $grades = [];
    foreach ($usersgrades as $usergrade) {
        $grade = new stdClass();
        $grade->userid = $usergrade->userid;
        $grade->rawgrade = round($usergrade->bestgrade, 2);
        $grades[$usergrade->userid] = $grade;
    }

    if (empty($grades) && $nullifnone && $userid) {
        // Create null grade for this user.
        $grade = new stdClass();
        $grade->userid = $userid;
        $grade->rawgrade = null;
        $grades[$userid] = $grade;
    }

    if (!empty($grades)) {
        grade_update('mod/aivideoactivity', $videoactivity->course, 'mod', 'aivideoactivity',
            $videoactivity->id, 0, $grades);
    }
}

/**
 * Return grade for given user or all users.
 *
 * @param stdClass $videoactivity Video activity object
 * @param int $userid Optional user id, 0 means all users
 * @return array Array of grades, false if none
 */
function aivideoactivity_get_user_grades($videoactivity, $userid = 0) {
    global $DB;

    $params = ['aivideoactivityid' => $videoactivity->id, 'status' => 1];
    $userwhere = '';
    if ($userid) {
        $userwhere = ' AND userid = :userid';
        $params['userid'] = $userid;
    }

    // Get best score per user.
    $sql = "SELECT userid, MAX(CASE WHEN totalcount > 0 THEN (correctcount * 100.0 / totalcount) ELSE 0 END) as rawgrade
              FROM {aivideoactivity_attempts}
             WHERE aivideoactivityid = :aivideoactivityid
               AND status = :status
               $userwhere
          GROUP BY userid";

    return $DB->get_records_sql($sql, $params);
}

/**
 * Get icon mapping for font-awesome.
 *
 * @return array
 */
function mod_aivideoactivity_get_fontawesome_icon_map() {
    return [
        'mod_aivideoactivity:icon' => 'fa-play-circle',
    ];
}

/**
 * Given a course_module object, this function returns any
 * "extra" information that may be needed when printing this activity.
 *
 * @param cm_info $coursemodule The course module info
 * @return cached_cm_info|null Cached course module info or null if not available
 */
function aivideoactivity_get_coursemodule_info($coursemodule) {
    global $DB;

    if (!$videoactivity = $DB->get_record('aivideoactivity', ['id' => $coursemodule->instance],
            'id, name, intro, introformat, completionallcorrect')) {
        return null;
    }

    $info = new cached_cm_info();
    $info->name = $videoactivity->name;

    if ($coursemodule->showdescription) {
        $info->content = format_module_intro('aivideoactivity', $videoactivity, $coursemodule->id, false);
    }

    // Populate custom completion rules.
    if ($videoactivity->completionallcorrect) {
        $info->customdata['customcompletionrules']['completionallcorrect'] = $videoactivity->completionallcorrect;
    }

    return $info;
}

/**
 * Mark the activity completed (if required) and trigger the course_module_viewed event.
 *
 * @param stdClass $videoactivity The video activity object
 * @param stdClass $course The course object
 * @param stdClass $cm The course module object
 * @param context_module $context The context object
 */
function aivideoactivity_view($videoactivity, $course, $cm, $context) {
    // Trigger the course_module_viewed event.
    $event = \mod_aivideoactivity\event\course_module_viewed::create([
        'objectid' => $videoactivity->id,
        'context' => $context,
    ]);
    $event->add_record_snapshot('course', $course);
    $event->add_record_snapshot('aivideoactivity', $videoactivity);
    $event->trigger();

    // Mark as viewed for completion.
    $completion = new completion_info($course);
    $completion->set_module_viewed($cm);
}

/**
 * Called when viewing course page.
 *
 * @param cm_info $cm Course-module object
 */
function aivideoactivity_cm_info_view(cm_info $cm) {
    // Nothing additional needed for display.
}

/**
 * Get the effective maximum attempts for a user (base + overrides).
 *
 * @param stdClass $videoactivity The video activity object
 * @param int $userid The user ID
 * @return int Effective max attempts (0 = unlimited)
 */
function aivideoactivity_effective_maxattempts($videoactivity, $userid) {
    global $DB;

    $base = (int)$videoactivity->maxattempts;
    if ($base === 0) {
        return 0; // Unlimited.
    }

    // Check for user override.
    $override = $DB->get_record('aivideoactivity_overrides', [
        'aivideoactivityid' => $videoactivity->id,
        'userid' => $userid,
    ]);

    $extra = $override ? max(0, (int)$override->extraattempts) : 0;
    return $base + $extra;
}

/**
 * Count completed attempts for a user.
 *
 * @param int $aivideoactivityid The video activity ID
 * @param int $userid The user ID
 * @return int Number of completed attempts
 */
function aivideoactivity_count_attempts($aivideoactivityid, $userid) {
    global $DB;

    return (int)$DB->count_records('aivideoactivity_attempts', [
        'aivideoactivityid' => $aivideoactivityid,
        'userid' => $userid,
        'status' => 1, // Completed.
    ]);
}

/**
 * Check if user can start a new attempt.
 *
 * @param stdClass $videoactivity The video activity object
 * @param int $userid The user ID
 * @return bool True if can attempt, false if limit reached
 */
function aivideoactivity_can_attempt($videoactivity, $userid) {
    $maxattempts = aivideoactivity_effective_maxattempts($videoactivity, $userid);
    if ($maxattempts === 0) {
        return true; // Unlimited.
    }

    $used = aivideoactivity_count_attempts($videoactivity->id, $userid);
    return $used < $maxattempts;
}

/**
 * Send notification when user reaches max attempts.
 * Includes throttling to prevent duplicate notifications.
 *
 * @param stdClass $videoactivity The video activity object
 * @param stdClass $course The course object
 * @param stdClass $cm The course module object
 * @param stdClass $user The user who used all attempts
 * @return bool True if notification was sent, false if throttled
 */
function aivideoactivity_send_attempts_notification($videoactivity, $course, $cm, $user) {
    global $DB, $CFG;

    // Check for throttling - only send notification once per user per activity per effective limit.
    $override = $DB->get_record('aivideoactivity_overrides', [
        'aivideoactivityid' => $videoactivity->id,
        'userid' => $user->id,
    ]);

    $maxattempts = aivideoactivity_effective_maxattempts($videoactivity, $user->id);
    $attemptsused = aivideoactivity_count_attempts($videoactivity->id, $user->id);

    // Only notify when exactly at the limit (not above due to override grants).
    if ($attemptsused != $maxattempts) {
        return false;
    }

    // Check if we already notified for this limit level.
    $notifykey = 'notify_' . $videoactivity->id . '_' . $user->id . '_' . $maxattempts;
    $lastnotified = get_config('mod_aivideoactivity', $notifykey);

    if ($lastnotified) {
        // Already notified for this limit level.
        return false;
    }

    // Mark as notified for this limit level.
    set_config($notifykey, time(), 'mod_aivideoactivity');

    $context = context_module::instance($cm->id);

    // Build message data.
    $a = new stdClass();
    $a->fullname = fullname($user);
    $a->activityname = format_string($videoactivity->name);
    $a->coursename = format_string($course->fullname);
    $a->limit = $maxattempts;
    $a->overrideurl = (new moodle_url('/mod/aivideoactivity/moreattempts.php', ['id' => $cm->id]))->out(false);

    $subject = get_string('allattemptsused_subject', 'mod_aivideoactivity', $a);
    $body = get_string('allattemptsused_body', 'mod_aivideoactivity', $a);

    // Get users with viewreports capability (teachers/managers).
    $teachers = get_users_by_capability($context, 'mod/aivideoactivity:viewreports', 'u.*', '', '', '', '', '', false);

    // Send notification to each teacher.
    $eventdata = new \core\message\message();
    $eventdata->courseid = $course->id;
    $eventdata->component = 'mod_aivideoactivity';
    $eventdata->name = 'allattemptsused';
    $eventdata->userfrom = core_user::get_noreply_user();
    $eventdata->subject = $subject;
    $eventdata->fullmessage = $body;
    $eventdata->fullmessageformat = FORMAT_PLAIN;
    $eventdata->fullmessagehtml = nl2br($body);
    $eventdata->smallmessage = $subject;
    $eventdata->notification = 1;
    $eventdata->contexturl = new moodle_url('/mod/aivideoactivity/report.php', ['id' => $cm->id, 'userid' => $user->id]);
    $eventdata->contexturlname = get_string('attemptsreport', 'mod_aivideoactivity');

    foreach ($teachers as $teacher) {
        $eventdata->userto = $teacher;
        message_send($eventdata);
    }

    // Send to CC email addresses if configured.
    if (!empty($videoactivity->ccemail)) {
        require_once($CFG->dirroot . '/lib/moodlelib.php');
        
        $emails = array_map('trim', explode(',', $videoactivity->ccemail));
        
        foreach ($emails as $email) {
            if (validate_email($email)) {
                $ccuser = new stdClass();
                $ccuser->email = $email;
                $ccuser->id = -1;
                $ccuser->auth = 'manual';
                $ccuser->deleted = 0;
                $ccuser->suspended = 0;
                $ccuser->mailformat = 1;
                $ccuser->emailstop = 0;
                $ccuser->firstnamephonetic = '';
                $ccuser->lastnamephonetic = '';
                $ccuser->middlename = '';
                $ccuser->alternatename = '';
                $ccuser->firstname = 'Admin';
                $ccuser->lastname = 'Notification';
                $ccuser->username = 'cc_notification';

                email_to_user(
                    $ccuser,
                    core_user::get_noreply_user(),
                    $subject,
                    $body,
                    nl2br($body)
                );
            }
        }
    }

    return true;
}

/**
 * Extends the settings navigation with the report links.
 *
 * @param settings_navigation $settingsnav The settings navigation object
 * @param navigation_node $navref The navigation node
 */
function aivideoactivity_extend_settings_navigation(settings_navigation $settingsnav, navigation_node $navref) {
    global $PAGE;

    $cm = $PAGE->cm;
    if (!$cm) {
        return;
    }

    $context = context_module::instance($cm->id);

    // Add Report link for users with viewreports capability.
    if (has_capability('mod/aivideoactivity:viewreports', $context)) {
        $reporturl = new moodle_url('/mod/aivideoactivity/report.php', ['id' => $cm->id]);
        $navref->add(
            get_string('attemptsreport', 'mod_aivideoactivity'),
            $reporturl,
            navigation_node::TYPE_SETTING
        );
    }

    // Add More Attempts link for users with manageoverrides capability.
    if (has_capability('mod/aivideoactivity:manageoverrides', $context)) {
        $moreattemptsurl = new moodle_url('/mod/aivideoactivity/moreattempts.php', ['id' => $cm->id]);
        $navref->add(
            get_string('moreattempts', 'mod_aivideoactivity'),
            $moreattemptsurl,
            navigation_node::TYPE_SETTING
        );
    }
}

/**
 * Returns the lists of all browsable file areas within the given module context.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param stdClass $context
 * @return array
 */
function aivideoactivity_get_file_areas($course, $cm, $context) {
    return [];
}

/**
 * Serves files stored in the module.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param stdClass $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool false if file not found
 */
function aivideoactivity_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }

    require_login($course, true, $cm);

    if ($filearea !== 'audiofile') {
        return false;
    }

    $itemid = array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'mod_aivideoactivity', $filearea, $itemid, $filepath, $filename);

    if (!$file || $file->is_directory()) {
        return false;
    }

    send_stored_file($file, 0, 0, false, $options);
}

/**
 * Callback function that returns the completion rule descriptions relative to $cm.
 *
 * @param cm_info|stdClass $cm course-module object
 * @return array $descriptions
 */
function mod_aivideoactivity_get_completion_active_rule_descriptions($cm) {
    global $DB;

    $descriptions = [];
    $videoactivity = $DB->get_record('aivideoactivity', ['id' => $cm->instance]);

    if ($videoactivity && !empty($videoactivity->completionallcorrect)) {
        $descriptions[] = get_string('completiondetail:completionallcorrect', 'mod_aivideoactivity');
    }

    return $descriptions;
}
