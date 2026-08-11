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
 * Attempts report page for AI Video Activity.
 *
 * @package    mod_aivideoactivity
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/aivideoactivity/lib.php');

$id = optional_param('id', 0, PARAM_INT);
$a  = optional_param('a', 0, PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);

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
        'To access the report, open the AI Video Activity from your course page first.');
}

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/aivideoactivity:viewreports', $context);

// If filtering by user, load user (ensure it exists).
$user = null;
if ($userid) {
    $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0],
        'id,firstname,lastname,alternatename,firstnamephonetic,lastnamephonetic,middlename,email',
        MUST_EXIST
    );
}

// Page setup.
$urlparams = ['id' => $id];
if ($userid) {
    $urlparams['userid'] = $userid;
}
$PAGE->set_url(new moodle_url('/mod/aivideoactivity/report.php', $urlparams));
$title = format_string($videoactivity->name) . ' - ' . get_string('attemptsreport', 'mod_aivideoactivity');
if ($user) {
    $title .= ' — ' . fullname($user, true);
}
$PAGE->set_title($title);
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');

// Add CSS.
$PAGE->requires->css('/mod/aivideoactivity/styles.css');

echo $OUTPUT->header();

// Breadcrumb link back to view.php.
$viewurl = new moodle_url('/mod/aivideoactivity/view.php', ['id' => $cm->id]);
echo html_writer::div(
    html_writer::link($viewurl, '&laquo; ' . format_string($videoactivity->name)),
    'mb-3'
);

echo $OUTPUT->heading(get_string('attemptsreport', 'mod_aivideoactivity'));

// Show user filter info.
if ($user) {
    echo html_writer::div(
        html_writer::span(fullname($user, true)) . ' ' .
        html_writer::span('·') . ' ' .
        html_writer::link(
            new moodle_url('/mod/aivideoactivity/report.php', ['id' => $id]),
            get_string('allparticipants')
        ),
        'mb-3'
    );
}

// Build user picker - include all name fields required by fullname().
$coursecontext = context_course::instance($course->id);
$namefields = 'u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename, u.email';
$enrolled = get_enrolled_users($coursecontext, '', 0, $namefields, 'u.lastname, u.firstname, u.id');

// Also include users who have attempts (in case they're not currently enrolled).
$attemptuserids = $DB->get_records_sql_menu(
    "SELECT DISTINCT u.id, u.id
       FROM {aivideoactivity_attempts} a
       JOIN {user} u ON u.id = a.userid
      WHERE a.aivideoactivityid = :vid AND u.deleted = 0",
    ['vid' => $videoactivity->id]
);

$picker = [];
foreach ($enrolled as $eu) {
    $picker[$eu->id] = $eu;
}
if (!empty($attemptuserids)) {
    list($insql, $inparams) = $DB->get_in_or_equal(array_keys($attemptuserids), SQL_PARAMS_NAMED);
    $extrausers = $DB->get_records_select('user', "id $insql AND deleted = 0",
        $inparams, 'lastname, firstname, id',
        'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename, email');
    foreach ($extrausers as $xu) {
        if (!isset($picker[$xu->id])) {
            $picker[$xu->id] = $xu;
        }
    }
}

// Sort picker list.
usort($picker, function ($a, $b) {
    $al = core_text::strtolower($a->lastname . ' ' . $a->firstname);
    $bl = core_text::strtolower($b->lastname . ' ' . $b->firstname);
    if ($al === $bl) {
        return $a->id <=> $b->id;
    }
    return $al <=> $bl;
});

// Prepare options for user picker.
$useroptions = [];
foreach ($picker as $pu) {
    $label = fullname($pu, true);
    $url = (new moodle_url('/mod/aivideoactivity/report.php', ['id' => $cm->id, 'userid' => $pu->id]))->out(false);
    $useroptions[] = ['id' => (int)$pu->id, 'label' => $label, 'url' => $url];
}

$currentlabel = ($userid && $user) ? fullname($user, true) : '';

// Render user picker.
echo html_writer::start_div('kc-userpicker mb-3');
echo html_writer::tag('label', get_string('user') . ':', ['for' => 'va-userinput', 'class' => 'mr-2']);
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'id' => 'va-userinput',
    'class' => 'form-control',
    'style' => 'max-width:520px; display:inline-block;',
    'list' => 'va-userdatalist',
    'placeholder' => '',
    'value' => $currentlabel,
    'autocomplete' => 'off',
]);
echo html_writer::start_tag('datalist', ['id' => 'va-userdatalist']);
foreach ($useroptions as $opt) {
    echo html_writer::empty_tag('option', ['value' => $opt['label']]);
}
echo html_writer::end_tag('datalist');
$allurl = new moodle_url('/mod/aivideoactivity/report.php', ['id' => $cm->id]);
echo ' ' . html_writer::link($allurl, get_string('allparticipants'), ['class' => 'btn btn-link p-1']);
echo html_writer::tag('script', json_encode($useroptions), ['type' => 'application/json', 'id' => 'va-user-map']);
echo html_writer::end_div();

// User picker JS.
$js = <<<JS
(function (){
  var input = document.getElementById('va-userinput');
  var dataEl = document.getElementById('va-user-map');
  if (!input || !dataEl) return;
  var map = [];
  try { map = JSON.parse(dataEl.textContent || '[]'); } catch(e){ map = []; }

  function gotoForValue(val){
    val = (val || '').trim();
    if (!val) { window.location = '{$allurl->out(false)}'; return true; }
    var lower = val.toLowerCase();

    // Exact match first.
    for (var i=0;i<map.length;i++){
      if ((map[i].label || '').toLowerCase() === lower) { window.location = map[i].url; return true; }
    }
    // Single partial match.
    var matches = map.filter(function (m){ return (m.label || '').toLowerCase().indexOf(lower) !== -1; });
    if (matches.length === 1) { window.location = matches[0].url; return true; }
    return false;
  }

  input.addEventListener('change', function (){ gotoForValue(input.value); });
  input.addEventListener('keydown', function (e){
    if (e.key === 'Enter') { if (gotoForValue(input.value)) { e.preventDefault(); } }
  });
})();
JS;
$PAGE->requires->js_init_code($js);

// Build WHERE clause with optional user filter.
$params = ['vid' => $videoactivity->id, 'status' => 1];
$where = ['a.aivideoactivityid = :vid', 'a.status = :status'];
if ($userid) {
    $where[] = 'a.userid = :userid';
    $params['userid'] = $userid;
}
$whereclause = implode(' AND ', $where);

// Get completed attempts.
$sql = "SELECT a.id AS attemptid, a.*, u.id AS uid, u.firstname, u.lastname,
               u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename, u.email
          FROM {aivideoactivity_attempts} a
          JOIN {user} u ON u.id = a.userid
         WHERE $whereclause
      ORDER BY u.lastname, u.firstname, a.id ASC";
$attempts = $DB->get_records_sql($sql, $params);

// Base max attempts for the activity.
$basemax = isset($videoactivity->maxattempts) ? (int)$videoactivity->maxattempts : 0;

// Load per-user overrides.
$extrabyuser = [];
if (!empty($attempts)) {
    $userids = array_values(array_unique(array_map(function ($a) { return (int)$a->userid; }, $attempts)));
    if (!empty($userids)) {
        list($insql, $inparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $ovrs = $DB->get_records_select('aivideoactivity_overrides',
            'aivideoactivityid = :vid AND userid ' . $insql,
            ['vid' => $videoactivity->id] + $inparams,
            '',
            'userid, extraattempts'
        );
        foreach ($ovrs as $o) {
            $extrabyuser[(int)$o->userid] = (int)$o->extraattempts;
        }
    }
}

if (!$attempts) {
    echo html_writer::div(get_string('noattempts', 'mod_aivideoactivity'));
} else {
    $table = new html_table();
    $table->head = [
        get_string('username', 'mod_aivideoactivity'),
        get_string('attemptno', 'mod_aivideoactivity'),
        get_string('score', 'mod_aivideoactivity'),
        '%',
        get_string('timestarted', 'mod_aivideoactivity'),
        get_string('timeended', 'mod_aivideoactivity'),
        get_string('timespent', 'mod_aivideoactivity'),
    ];

    $counters = [];

    foreach ($attempts as $a) {
        if (!isset($counters[$a->userid])) {
            $counters[$a->userid] = 0;
        }
        $counters[$a->userid]++;

        $fullname = fullname($a, true);

        // Score calculation.
        $score = '-';
        $percentage = '-';
        if (isset($a->correctcount) && isset($a->totalcount) && $a->totalcount > 0) {
            $score = $a->correctcount . '/' . $a->totalcount;
            $percentage = round(($a->correctcount / $a->totalcount) * 100, 1) . '%';
        }

        // Time started.
        $timestarted = '';
        $startts = 0;
        if (!empty($a->timestarted)) {
            $startts = (int)$a->timestarted;
            $timestarted = userdate($a->timestarted);
        } else if (!empty($a->timecreated)) {
            $startts = (int)$a->timecreated;
            $timestarted = userdate($a->timecreated);
        }

        // Time ended.
        $timeended = '';
        $endts = 0;
        if (!empty($a->timeended)) {
            $endts = (int)$a->timeended;
            $timeended = userdate($a->timeended);
        } else if (!empty($a->timemodified)) {
            $endts = (int)$a->timemodified;
            $timeended = userdate($a->timemodified);
        }

        // Time spent.
        $timespentstr = '-';
        if ($startts && $endts && $endts >= $startts) {
            $dur = $endts - $startts;
            $timespentstr = format_time($dur);
        }

        // Attempt X / Y where Y is per-user effective total.
        $extra = $extrabyuser[$a->userid] ?? 0;
        $effectivemax = ($basemax > 0) ? ($basemax + max(0, (int)$extra)) : 0;
        $attemptindex = $counters[$a->userid];
        $attemptno = $attemptindex . '/' . ($effectivemax > 0 ? $effectivemax : '∞');

        $table->data[] = new html_table_row([
            new html_table_cell($fullname),
            new html_table_cell($attemptno),
            new html_table_cell($score),
            new html_table_cell($percentage),
            new html_table_cell($timestarted),
            new html_table_cell($timeended),
            new html_table_cell($timespentstr),
        ]);
    }

    echo html_writer::table($table);
}

echo $OUTPUT->footer();
