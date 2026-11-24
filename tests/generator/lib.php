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
 * Data generator for mod_aivideoactivity.
 *
 * @package    mod_aivideoactivity
 * @category   test
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_aivideoactivity_generator extends testing_module_generator {
    /**
     * Creates an instance of the module for testing purposes.
     *
     * @param array|stdClass|null $record Data for the module instance.
     * @param array|null $options General options for the course module.
     * @return stdClass The record from the aivideoactivity table, with cmid.
     */
    public function create_instance($record = null, ?array $options = null) {
        $record = (object) (array) $record;
        $defaults = [
            'mediatype' => 'video',
            'youtubeurl' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'watchmode' => 'none',
            'watchseconds' => 0,
            'grade' => 100,
            'maxattempts' => 0,
            'passinggrade' => 0,
            'completionallcorrect' => 0,
        ];
        foreach ($defaults as $name => $value) {
            if (!isset($record->{$name})) {
                $record->{$name} = $value;
            }
        }
        return parent::create_instance($record, (array) $options);
    }

    /**
     * Adds a multiple choice question to an activity.
     *
     * @param array|stdClass $record Must contain aivideoactivityid.
     * @return stdClass The question record.
     */
    public function create_question($record): stdClass {
        global $DB;
        $record = (object) (array) $record;
        if (empty($record->aivideoactivityid)) {
            throw new coding_exception('create_question needs aivideoactivityid');
        }
        $count = $DB->count_records('aivideoactivity_questions', ['aivideoactivityid' => $record->aivideoactivityid]);
        $defaults = [
            'questionnumber' => $count + 1,
            'questiontext' => 'Question ' . ($count + 1),
            'answer1' => 'Answer A',
            'answer2' => 'Answer B',
            'answer3' => 'Answer C',
            'answer4' => 'Answer D',
            'correctanswer' => 0,
            'questiontype' => 'mcq',
        ];
        foreach ($defaults as $name => $value) {
            if (!isset($record->{$name})) {
                $record->{$name} = $value;
            }
        }
        $record->id = $DB->insert_record('aivideoactivity_questions', $record);
        $DB->set_field('aivideoactivity', 'questioncount', $count + 1, ['id' => $record->aivideoactivityid]);
        return $record;
    }

    /**
     * Creates an attempt for a learner. Defaults to a finished attempt.
     *
     * @param array|stdClass $record Must contain aivideoactivityid and userid.
     * @return stdClass The attempt record.
     */
    public function create_attempt($record): stdClass {
        global $DB;
        $record = (object) (array) $record;
        if (empty($record->aivideoactivityid) || empty($record->userid)) {
            throw new coding_exception('create_attempt needs aivideoactivityid and userid');
        }
        $now = time();
        $defaults = [
            'currentquestion' => 0,
            'answers' => '{}',
            'correctcount' => 0,
            'totalcount' => 0,
            'status' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        foreach ($defaults as $name => $value) {
            if (!isset($record->{$name})) {
                $record->{$name} = $value;
            }
        }
        $record->id = $DB->insert_record('aivideoactivity_attempts', $record);
        return $record;
    }
}
