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
 * Admin: bulk Card Select audio upgrade.
 *
 * FEAT-VA-BULK-CARDSELECT-AUDIO-UPGRADE (v1.0.114): site-admin-only page that
 * walks every cardselect question across the whole site and upgrades each
 * legacy single-clip question to the v1.0.110+ per-card 4-clip format by
 * calling /api/videoactivity-regenerate-audio for each one. Processes one
 * question per HTTP call (via the bulkregenstep ajax action) so the page
 * cannot time out even on large sites. Live progress bar + count badges.
 *
 * @package    mod_aivideoactivity
 * @copyright  2026 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

$pageurl = new moodle_url('/mod/aivideoactivity/admin/bulkregen_cardselect.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('bulkregen_pagetitle', 'mod_aivideoactivity'));
$PAGE->set_heading(get_string('bulkregen_pagetitle', 'mod_aivideoactivity'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('bulkregen_heading', 'mod_aivideoactivity'));

$ajaxurl = new moodle_url('/mod/aivideoactivity/ajax.php');
$sesskey = sesskey();
?>

<div style="max-width: 720px;">
    <div style="padding: 16px; background: #f0f9ff; border: 1px solid #0ea5e9; border-radius: 8px; margin-bottom: 16px;">
        <p style="margin: 0;"><?php echo get_string('bulkregen_info', 'mod_aivideoactivity'); ?></p>
    </div>

    <div style="padding: 12px; background: #fef3c7; border: 1px solid #f59e0b; border-radius: 8px; margin-bottom: 16px;">
        <p style="margin: 0;"><strong>How this works:</strong> the page processes one question per HTTP call to avoid server timeouts. Click <em>Count</em> first to see how many legacy questions will be upgraded, then click <em>Run</em>. The progress bar fills as each question is processed. You can safely close the page mid-run and resume later — already-upgraded questions are skipped automatically.</p>
    </div>

    <div style="margin: 16px 0;">
        <button type="button" id="va-bulkregen-count-btn" class="btn btn-secondary" data-testid="button-bulkregen-count">
            <?php echo get_string('bulkregen_count_button', 'mod_aivideoactivity'); ?>
        </button>
        <button type="button" id="va-bulkregen-run-btn" class="btn btn-primary" data-testid="button-bulkregen-run" disabled>
            <?php echo get_string('bulkregen_run_button', 'mod_aivideoactivity'); ?>
        </button>
        <button type="button" id="va-bulkregen-stop-btn" class="btn btn-warning" data-testid="button-bulkregen-stop" style="display: none;">
            Stop
        </button>
    </div>

    <div id="va-bulkregen-status" style="padding: 12px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; min-height: 60px; margin-bottom: 12px; font-family: monospace; font-size: 13px;">
        Click "Count" to begin.
    </div>

    <div id="va-bulkregen-progress-wrap" style="display: none; margin-bottom: 12px;">
        <div style="background: #e2e8f0; border-radius: 6px; overflow: hidden; height: 24px; position: relative;">
            <div id="va-bulkregen-progress-bar" style="background: #0ea5e9; height: 100%; width: 0%; transition: width 0.3s;"></div>
            <div id="va-bulkregen-progress-label" style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; text-align: center; line-height: 24px; font-weight: 600; color: #0f172a;">0 / 0</div>
        </div>
    </div>

    <div id="va-bulkregen-log" style="padding: 12px; background: #0f172a; color: #e2e8f0; border-radius: 8px; max-height: 300px; overflow-y: auto; font-family: monospace; font-size: 12px; line-height: 1.5; display: none;"></div>
</div>

<script>
(function() {
    var ajaxUrl = '<?php echo $ajaxurl->out(false); ?>';
    var sesskey = '<?php echo $sesskey; ?>';
    var countBtn = document.getElementById('va-bulkregen-count-btn');
    var runBtn = document.getElementById('va-bulkregen-run-btn');
    var stopBtn = document.getElementById('va-bulkregen-stop-btn');
    var statusEl = document.getElementById('va-bulkregen-status');
    var progressWrap = document.getElementById('va-bulkregen-progress-wrap');
    var progressBar = document.getElementById('va-bulkregen-progress-bar');
    var progressLabel = document.getElementById('va-bulkregen-progress-label');
    var logEl = document.getElementById('va-bulkregen-log');
    var totalLegacy = 0;
    var processed = 0;
    var lastId = 0;
    var stopRequested = false;

    function logLine(msg) {
        logEl.style.display = 'block';
        var line = document.createElement('div');
        var ts = new Date().toLocaleTimeString();
        line.textContent = '[' + ts + '] ' + msg;
        logEl.appendChild(line);
        logEl.scrollTop = logEl.scrollHeight;
    }

    function updateProgress() {
        var pct = totalLegacy > 0 ? Math.min(100, Math.floor((processed / totalLegacy) * 100)) : 0;
        progressBar.style.width = pct + '%';
        progressLabel.textContent = processed + ' / ' + totalLegacy + ' (' + pct + '%)';
    }

    function postForm(action, extra) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('sesskey', sesskey);
        if (extra) {
            for (var k in extra) {
                if (Object.prototype.hasOwnProperty.call(extra, k)) {
                    fd.append(k, extra[k]);
                }
            }
        }
        return fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r) { return r.json(); });
    }

    countBtn.addEventListener('click', function() {
        countBtn.disabled = true;
        statusEl.textContent = 'Counting Card Select questions...';
        postForm('bulkregencount').then(function(resp) {
            countBtn.disabled = false;
            if (!resp.ok) {
                statusEl.textContent = 'Error: ' + (resp.error || 'unknown');
                return;
            }
            totalLegacy = parseInt(resp.legacy_cardselect, 10) || 0;
            var totalAll = parseInt(resp.total_cardselect, 10) || 0;
            statusEl.innerHTML =
                '<strong>Total Card Select questions on site:</strong> ' + totalAll + '<br>' +
                '<strong>Legacy (single-clip, need upgrade):</strong> ' + totalLegacy + '<br>' +
                '<strong>Already upgraded (per-card audio):</strong> ' + (totalAll - totalLegacy);
            if (totalLegacy === 0) {
                statusEl.innerHTML += '<br><br><em><?php echo get_string('bulkregen_no_legacy', 'mod_aivideoactivity'); ?></em>';
                runBtn.disabled = true;
            } else {
                runBtn.disabled = false;
                progressWrap.style.display = 'block';
                processed = 0;
                lastId = 0;
                updateProgress();
            }
        }).catch(function(err) {
            countBtn.disabled = false;
            statusEl.textContent = 'Network error: ' + err.message;
        });
    });

    function processNext() {
        if (stopRequested) {
            logLine('Stopped by user.');
            stopBtn.style.display = 'none';
            runBtn.disabled = false;
            countBtn.disabled = false;
            statusEl.innerHTML += '<br><strong>Stopped.</strong> ' + processed + ' question(s) upgraded so far.';
            return;
        }
        postForm('bulkregenstep', { lastid: lastId }).then(function(resp) {
            if (!resp.ok && !resp.done) {
                logLine('Error on id=' + (resp.lastid || '?') + ': ' + (resp.error || 'unknown') + ' — continuing.');
                if (resp.lastid) lastId = parseInt(resp.lastid, 10);
                processed++;
                updateProgress();
                setTimeout(processNext, 500);
                return;
            }
            if (resp.done) {
                logLine('All done. ' + processed + ' question(s) upgraded.');
                stopBtn.style.display = 'none';
                countBtn.disabled = false;
                statusEl.innerHTML += '<br><br><strong style="color: #047857;"><?php echo get_string('bulkregen_done', 'mod_aivideoactivity'); ?></strong> Upgraded ' + processed + ' question(s).';
                progressBar.style.width = '100%';
                progressLabel.textContent = processed + ' / ' + processed + ' (100%)';
                return;
            }
            lastId = parseInt(resp.lastid, 10) || lastId;
            processed++;
            updateProgress();
            logLine(resp.message || ('Upgraded id=' + resp.lastid));
            // Tiny pause to keep the browser UI responsive and avoid hammering the SaaS.
            setTimeout(processNext, 200);
        }).catch(function(err) {
            logLine('Network error: ' + err.message + ' — retrying in 5s.');
            setTimeout(processNext, 5000);
        });
    }

    runBtn.addEventListener('click', function() {
        if (totalLegacy === 0) return;
        runBtn.disabled = true;
        countBtn.disabled = true;
        stopBtn.style.display = 'inline-block';
        stopRequested = false;
        logEl.style.display = 'block';
        logEl.innerHTML = '';
        logLine('<?php echo get_string('bulkregen_running', 'mod_aivideoactivity'); ?> (' + totalLegacy + ' question(s) to process)');
        processNext();
    });

    stopBtn.addEventListener('click', function() {
        stopRequested = true;
        stopBtn.disabled = true;
        logLine('Stop requested...');
    });
})();
</script>

<?php
echo $OUTPUT->footer();
