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
 * List all AI Video Activity instances in a course.
 *
 * @package    mod_aivideoactivity
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);

$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);

require_login($course);

$PAGE->set_url('/mod/aivideoactivity/index.php', ['id' => $id]);
$PAGE->set_pagelayout('incourse');

$strname = get_string('modulenameplural', 'mod_aivideoactivity');
$PAGE->set_title($strname);
$PAGE->set_heading($course->fullname);
$PAGE->navbar->add($strname);

echo $OUTPUT->header();
echo $OUTPUT->heading($strname);

$aivideoactivities = get_all_instances_in_course('aivideoactivity', $course);

if (empty($aivideoactivities)) {
    notice(get_string('novideoactivities', 'mod_aivideoactivity'), new moodle_url('/course/view.php', ['id' => $course->id]));
}

$table = new html_table();
$table->attributes['class'] = 'generaltable mod_index';
$table->head = [
    get_string('name'),
    get_string('description'),
];
$table->align = ['left', 'left'];

foreach ($aivideoactivities as $aivideoactivity) {
    $url = new moodle_url('/mod/aivideoactivity/view.php', ['id' => $aivideoactivity->coursemodule]);
    $link = html_writer::link($url, format_string($aivideoactivity->name));
    $description = format_text($aivideoactivity->intro, $aivideoactivity->introformat);
    
    $table->data[] = [$link, $description];
}

echo html_writer::table($table);

echo $OUTPUT->footer();
