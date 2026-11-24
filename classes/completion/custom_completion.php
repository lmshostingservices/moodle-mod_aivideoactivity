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

namespace mod_aivideoactivity\completion;

use core_completion\activity_custom_completion;

/**
 * Custom completion rules for AI Video Activity.
 *
 * @package    mod_aivideoactivity
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class custom_completion extends activity_custom_completion {
    /**
     * Fetches the completion state for a given completion rule.
     *
     * completionallcorrect is met by one finished attempt that answered every question in the
     * activity correctly.
     *
     * @param string $rule The completion rule.
     * @return int The completion state.
     */
    public function get_state(string $rule): int {
        global $DB;

        $this->validate_rule($rule);

        $questions = $DB->count_records('aivideoactivity_questions', ['aivideoactivityid' => $this->cm->instance]);
        $needed = max(1, $questions);
        $sql = "SELECT 1
                  FROM {aivideoactivity_attempts}
                 WHERE aivideoactivityid = :instance
                   AND userid = :userid
                   AND status = 1
                   AND correctcount >= :needed
                   AND correctcount >= totalcount";
        $done = $DB->record_exists_sql($sql, [
            'instance' => $this->cm->instance,
            'userid' => $this->userid,
            'needed' => $needed,
        ]);

        return $done ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
    }

    /**
     * Fetch the list of custom completion rules that this module defines.
     *
     * @return array
     */
    public static function get_defined_custom_rules(): array {
        return ['completionallcorrect'];
    }

    /**
     * Returns an associative array of the descriptions of custom completion rules.
     *
     * @return array
     */
    public function get_custom_rule_descriptions(): array {
        return [
            'completionallcorrect' => get_string('completiondetail:completionallcorrect', 'aivideoactivity'),
        ];
    }

    /**
     * Returns an array of all completion rules, in the order they should be displayed to users.
     *
     * @return array
     */
    public function get_sort_order(): array {
        return [
            'completionview',
            'completionallcorrect',
            'completionusegrade',
            'completionpassgrade',
        ];
    }
}
