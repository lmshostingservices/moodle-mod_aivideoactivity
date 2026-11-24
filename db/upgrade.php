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
 * Upgrade script for mod_aivideoactivity.
 *
 * @package    mod_aivideoactivity
 * @copyright  2026 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrades the aivideoactivity database and data.
 *
 * @param int $oldversion The version being upgraded from.
 * @return bool
 */
function xmldb_aivideoactivity_upgrade($oldversion) {
    if ($oldversion < 2026072300) {
        upgrade_mod_savepoint(true, 2026072300, 'aivideoactivity');
    }

    if ($oldversion < 2026112400) {
        // The custom completion rule could never complete before this release. Re-check learners
        // who already meet it, in the background so large sites upgrade quickly.
        \core\task\manager::queue_adhoc_task(new \mod_aivideoactivity\task\recalculate_completion(), true);
        upgrade_mod_savepoint(true, 2026112400, 'aivideoactivity');
    }

    return true;
}
