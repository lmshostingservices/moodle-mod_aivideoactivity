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

namespace mod_aivideoactivity\task;

/**
 * One-off task that re-checks the "All answers must be correct" completion rule.
 *
 * Before this release the rule could never complete, so learners who already finished an attempt with every answer right were left
 * incomplete. The upgrade queues this task once to bring them up to date.
 *
 * @package    mod_aivideoactivity
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recalculate_completion extends \core\task\adhoc_task {
    /**
     * Re-evaluates completion for every learner who may already meet the rule.
     */
    public function execute() {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');

        $moduleid = $DB->get_field('modules', 'id', ['name' => 'aivideoactivity']);
        if (!$moduleid || empty($CFG->enablecompletion)) {
            return;
        }
        $sql = "SELECT DISTINCT cm.id AS cmid, cm.course, a.userid
                  FROM {aivideoactivity_attempts} a
                  JOIN {aivideoactivity} v ON v.id = a.aivideoactivityid
                  JOIN {course_modules} cm ON cm.instance = v.id AND cm.module = :moduleid
                 WHERE v.completionallcorrect = 1
                   AND cm.completion = :automatic
                   AND a.status = 1
                   AND a.correctcount > 0
                   AND a.correctcount >= a.totalcount
              ORDER BY cm.course, cm.id";
        $params = ['moduleid' => $moduleid, 'automatic' => COMPLETION_TRACKING_AUTOMATIC];
        $rows = $DB->get_recordset_sql($sql, $params);
        $updated = 0;
        $courseid = 0;
        $course = null;
        $completion = null;
        foreach ($rows as $row) {
            if ($row->course != $courseid) {
                $courseid = $row->course;
                $course = $DB->get_record('course', ['id' => $courseid]);
                $completion = $course ? new \completion_info($course) : null;
            }
            if (!$completion || !$completion->is_enabled()) {
                continue;
            }
            try {
                $cm = get_fast_modinfo($course, $row->userid)->get_cm($row->cmid);
            } catch (\moodle_exception $e) {
                continue;
            }
            $completion->update_state($cm, COMPLETION_UNKNOWN, $row->userid);
            $updated++;
        }
        $rows->close();
        mtrace("mod_aivideoactivity: re-checked completion for {$updated} learner(s).");
    }
}
