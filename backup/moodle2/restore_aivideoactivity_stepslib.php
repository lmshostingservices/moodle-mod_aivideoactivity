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
 * Restore steps for mod_aivideoactivity.
 *
 * @package    mod_aivideoactivity
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Structure step to restore one aivideoactivity activity.
 */
class restore_aivideoactivity_activity_structure_step extends restore_activity_structure_step {

    protected function define_structure() {
        $paths = [];
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('aivideoactivity', '/activity/aivideoactivity');
        $paths[] = new restore_path_element('aivideoactivity_question', '/activity/aivideoactivity/questions/question');

        if ($userinfo) {
            $paths[] = new restore_path_element('aivideoactivity_attempt', '/activity/aivideoactivity/attempts/attempt');
            $paths[] = new restore_path_element('aivideoactivity_override', '/activity/aivideoactivity/overrides/override');
        }

        return $this->prepare_activity_structure($paths);
    }

    protected function process_aivideoactivity($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->course = $this->get_courseid();
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        if (!isset($data->youtubeurl)) {
            $data->youtubeurl = '';
        }
        if (!isset($data->transcripttext)) {
            $data->transcripttext = '';
        }
        if (!isset($data->watchmode)) {
            $data->watchmode = 'none';
        }
        if (!isset($data->watchseconds)) {
            $data->watchseconds = 0;
        }
        if (!isset($data->maxattempts)) {
            $data->maxattempts = 0;
        }
        if (!isset($data->questioncount)) {
            $data->questioncount = 0;
        }
        if (!isset($data->passinggrade)) {
            $data->passinggrade = 0;
        }
        if (!isset($data->completionallcorrect)) {
            $data->completionallcorrect = 0;
        }
        if (!isset($data->completionpassgrade)) {
            $data->completionpassgrade = 0;
        }
        if (!isset($data->ccemail)) {
            $data->ccemail = '';
        }

        $newitemid = $DB->insert_record('aivideoactivity', $data);
        $this->apply_activity_instance($newitemid);
    }

    protected function process_aivideoactivity_question($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->aivideoactivityid = $this->get_new_parentid('aivideoactivity');

        $newitemid = $DB->insert_record('aivideoactivity_questions', $data);
        $this->set_mapping('aivideoactivity_question', $oldid, $newitemid);
    }

    protected function process_aivideoactivity_attempt($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->aivideoactivityid = $this->get_new_parentid('aivideoactivity');
        $data->userid = $this->get_mappingid('user', $data->userid);

        if (!empty($data->answers)) {
            $answers = json_decode($data->answers, true);
            if (is_array($answers)) {
                $newanswers = [];
                foreach ($answers as $oldqid => $answerdata) {
                    $newqid = $this->get_mappingid('aivideoactivity_question', $oldqid);
                    if ($newqid) {
                        $newanswers[$newqid] = $answerdata;
                    } else {
                        $newanswers[$oldqid] = $answerdata;
                    }
                }
                $data->answers = json_encode($newanswers);
            }
        }

        if (!empty($data->timecreated)) {
            $data->timecreated = $this->apply_date_offset($data->timecreated);
        }
        if (!empty($data->timemodified)) {
            $data->timemodified = $this->apply_date_offset($data->timemodified);
        }
        if (!empty($data->timestarted)) {
            $data->timestarted = $this->apply_date_offset($data->timestarted);
        }
        if (!empty($data->timeended)) {
            $data->timeended = $this->apply_date_offset($data->timeended);
        }

        $newitemid = $DB->insert_record('aivideoactivity_attempts', $data);
        $this->set_mapping('aivideoactivity_attempt', $oldid, $newitemid);
    }

    protected function process_aivideoactivity_override($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->aivideoactivityid = $this->get_new_parentid('aivideoactivity');
        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        $newitemid = $DB->insert_record('aivideoactivity_overrides', $data);
        $this->set_mapping('aivideoactivity_override', $oldid, $newitemid);
    }

    protected function after_execute() {
        $this->add_related_files('mod_aivideoactivity', 'intro', null);
    }
}
