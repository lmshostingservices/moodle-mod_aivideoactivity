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
 * Backup steps for mod_aivideoactivity.
 *
 * @package    mod_aivideoactivity
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Define the complete aivideoactivity structure for backup.
 */
class backup_aivideoactivity_activity_structure_step extends backup_activity_structure_step {

    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');

        $videoactivity = new backup_nested_element('aivideoactivity', ['id'], [
            'name', 'intro', 'introformat', 'youtubeurl', 'transcripttext',
            'watchmode', 'watchseconds', 'maxattempts', 'questioncount',
            'passinggrade', 'completionallcorrect', 'completionpassgrade',
            'ccemail', 'timecreated', 'timemodified',
        ]);

        $questions = new backup_nested_element('questions');
        $question = new backup_nested_element('question', ['id'], [
            'questionnumber', 'questiontext',
            'answer1', 'answer2', 'answer3', 'answer4',
            'correctanswer',
            'feedback1', 'feedback2', 'feedback3', 'feedback4',
            'audiodata',
        ]);

        $attempts = new backup_nested_element('attempts');
        $attempt = new backup_nested_element('attempt', ['id'], [
            'userid', 'currentquestion', 'answers',
            'correctcount', 'totalcount', 'status',
            'timecreated', 'timemodified', 'timestarted', 'timeended',
        ]);

        $overrides = new backup_nested_element('overrides');
        $override = new backup_nested_element('override', ['id'], [
            'userid', 'extraattempts', 'timecreated', 'timemodified',
        ]);

        $videoactivity->add_child($questions);
        $questions->add_child($question);

        $videoactivity->add_child($attempts);
        $attempts->add_child($attempt);

        $videoactivity->add_child($overrides);
        $overrides->add_child($override);

        $videoactivity->set_source_table('aivideoactivity', ['id' => backup::VAR_ACTIVITYID]);

        $question->set_source_table('aivideoactivity_questions', ['aivideoactivityid' => backup::VAR_PARENTID], 'id ASC');

        if ($userinfo) {
            $attempt->set_source_table('aivideoactivity_attempts', ['aivideoactivityid' => backup::VAR_PARENTID], 'id ASC');
            $override->set_source_table('aivideoactivity_overrides', ['aivideoactivityid' => backup::VAR_PARENTID], 'id ASC');
        }

        $attempt->annotate_ids('user', 'userid');
        $override->annotate_ids('user', 'userid');

        return $this->prepare_activity_structure($videoactivity);
    }
}
