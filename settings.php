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
 * AI Video Activity settings.
 *
 * Note: Site ID and API Key are managed via AI Grader Central Config (local_aiconfig).
 * These fallback settings are only used if Central Config is not installed.
 *
 * @package    mod_aivideoactivity
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig && isset($settings)) {
    $centralconfigurl = new moodle_url('/admin/settings.php', ['section' => 'local_aiconfig']);
    $centralconfiginstalled = file_exists($CFG->dirroot . '/local/aiconfig/version.php');

    if ($centralconfiginstalled) {
        $settings->add(new admin_setting_heading(
            'mod_aivideoactivity/centralconfig_notice',
            '',
            '<div style="padding: 12px; background: #ecfdf5; border: 1px solid #10b981; border-radius: 8px; margin-bottom: 16px;">' .
            '<strong style="color: #047857;">AI Grader Central Config is installed.</strong><br>' .
            'Site ID and API Key are managed centrally. ' .
            '<a href="' . $centralconfigurl->out() . '">Configure Central Settings</a>' .
            '</div>'
        ));
    } else {
        $settings->add(new admin_setting_heading(
            'mod_aivideoactivity/centralconfig_notice',
            '',
            '<div style="padding: 12px; background: #fef3c7; border: 1px solid #f59e0b; border-radius: 8px; margin-bottom: 16px;">' .
            '<strong style="color: #b45309;">Recommended: Install AI Grader Central Config</strong><br>' .
            'Configure Site ID and API Key once for all AI Grader plugins.' .
            '</div>'
        ));
    }

    $settings->add(new admin_setting_configtext(
        'mod_aivideoactivity/apiurl',
        get_string('apiurl', 'mod_aivideoactivity'),
        get_string('apiurl_desc', 'mod_aivideoactivity'),
        'https://lms-labs.com',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'mod_aivideoactivity/siteid',
        get_string('siteid', 'mod_aivideoactivity'),
        get_string('siteid_desc', 'mod_aivideoactivity') . ($centralconfiginstalled ? ' (Fallback - Central Config takes priority)' : ''),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'mod_aivideoactivity/apikey',
        get_string('apikey', 'mod_aivideoactivity'),
        get_string('apikey_desc', 'mod_aivideoactivity') . ($centralconfiginstalled ? ' (Fallback - Central Config takes priority)' : ''),
        ''
    ));

    $settings->add(new admin_setting_heading(
        'mod_aivideoactivity/credits_heading',
        get_string('credits_heading', 'mod_aivideoactivity'),
        get_string('credits_info', 'mod_aivideoactivity')
    ));

    // FEAT-VA-BULK-CARDSELECT-AUDIO-UPGRADE (v1.0.114): Admin-only link to the
    // bulk Card Select audio upgrade page. The page itself enforces is_siteadmin()
    // — this section just surfaces the link so mum can find it.
    $bulkregenurl = new moodle_url('/mod/aivideoactivity/admin/bulkregen_cardselect.php');
    $settings->add(new admin_setting_heading(
        'mod_aivideoactivity/bulkregen_heading',
        get_string('bulkregen_heading', 'mod_aivideoactivity'),
        '<div style="padding: 12px; background: #f0f9ff; border: 1px solid #0ea5e9; border-radius: 8px; margin: 8px 0;">' .
        '<p style="margin: 0 0 12px 0;">' . get_string('bulkregen_info', 'mod_aivideoactivity') . '</p>' .
        '<a href="' . $bulkregenurl->out(false) . '" class="btn btn-primary">' .
        get_string('bulkregen_link', 'mod_aivideoactivity') . '</a>' .
        '</div>'
    ));
}
