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

namespace mod_aivideoactivity;

use mod_aivideoactivity\local\byok\engine;
use mod_aivideoactivity\local\byok\http_error;
use mod_aivideoactivity\local\byok\js_error;

/**
 * Bring your own AI: do the LMS Labs work on the site's own AI.
 *
 * With a Bring your own AI licence (AI Grader Central Config 1.1.0 or later) an administrator chooses, for text and
 * voice separately, whether LMS Labs credits or the site's own AI does the work. Question writing runs the service's
 * own logic (local\byok\engine) on the site's text provider; narration goes to the site's voice provider. These calls
 * go from this Moodle site to the provider, never through LMS Labs, and use no LMS Labs credits. Anything set to
 * credits still goes to LMS Labs as before.
 *
 * Every method answers in the shape the matching LMS Labs route answers, so the page handles both the same way.
 *
 * @package    mod_aivideoactivity
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class byok {
    /** Component the licence and usage are recorded against. */
    const COMPONENT = 'mod_aivideoactivity';
    /** Seconds one text call may take: a whole-set rewrite of 20 questions is long. */
    const TEXT_TIMEOUT = 240;
    /** The service narrates a little slower than normal speech. */
    const SPEAKING_RATE = 0.92;
    /** Job ids this plugin gives own-AI generations. */
    const JOB_PREFIX = 'byok-';
    /** The plugin's Google voices, and the closest OpenAI voice for each. */
    const OPENAI_VOICES = ['Aoede' => 'nova', 'Kore' => 'shimmer', 'Leda' => 'coral', 'Zephyr' => 'sage',
        'Puck' => 'onyx', 'Charon' => 'echo', 'Fenrir' => 'ash', 'Orus' => 'ballad'];
    /** What the routes need in siteId and apiKey when nothing goes to LMS Labs. */
    const NO_CREDENTIALS = 'byok';

    /**
     * Whether a kind of work runs on the site's own AI now.
     *
     * @param string $kind text or voice.
     * @return bool
     */
    public static function uses(string $kind): bool {
        return class_exists('\local_aiconfig\byok\ai') && \local_aiconfig\byok\ai::uses_own_ai(self::COMPONENT, $kind);
    }

    /**
     * Which kinds of work run on the site's own AI, for the page.
     *
     * @return array text, voice => bool.
     */
    public static function state(): array {
        return ['text' => self::uses('text'), 'voice' => self::uses('voice')];
    }

    /**
     * Whether any of this plugin's work runs on the site's own AI.
     *
     * @return bool
     */
    public static function any(): bool {
        return in_array(true, self::state(), true);
    }

    /**
     * The LMS Labs address and credentials, as the plugin reads them: Central Config first, then its own settings.
     *
     * @return array [apibase, siteid, apikey]
     */
    public static function credentials(): array {
        global $CFG;
        $apibase = get_config('mod_aivideoactivity', 'apiurl');
        $apibase = rtrim(empty($apibase) ? 'https://lms-labs.com' : $apibase, '/');
        $lib = $CFG->dirroot . '/local/aiconfig/lib.php';
        if (file_exists($lib)) {
            require_once($lib);
        }
        $siteid = function_exists('local_aiconfig_get_siteid') ? trim(local_aiconfig_get_siteid() ?? '') : '';
        $apikey = function_exists('local_aiconfig_get_apikey') ? trim(local_aiconfig_get_apikey() ?? '') : '';
        if ($siteid === '') {
            $siteid = trim(get_config('mod_aivideoactivity', 'siteid') ?? '');
        }
        if ($apikey === '') {
            $apikey = trim(get_config('mod_aivideoactivity', 'apikey') ?? '');
        }
        return [$apibase, $siteid, $apikey];
    }

    /**
     * Whether LMS Labs credentials are set, for the work that still goes to LMS Labs.
     *
     * @return bool
     */
    public static function has_credentials(): bool {
        [, $siteid, $apikey] = self::credentials();
        return $siteid !== '' && $apikey !== '';
    }

    /**
     * Point the engine at the site's own AI.
     *
     * @param callable|null $progress Told how a generation is going.
     */
    protected static function hooks(?callable $progress = null): void {
        engine::$aicall = function (string $system, string $user, array $options): string {
            $opts = ['json' => !empty($options['json']), 'timeout' => self::TEXT_TIMEOUT];
            if ($options['maxtokens'] !== null) {
                $opts['maxtokens'] = (int) $options['maxtokens'];
            }
            if ($options['temperature'] !== null) {
                $opts['temperature'] = (float) $options['temperature'];
            }
            return \local_aiconfig\byok\ai::text(self::COMPONENT, $system, $user, $opts);
        };
        engine::$tts = function (string $text, array $voice): string {
            return self::speech($text, $voice);
        };
        engine::$random = null;
        engine::$progress = $progress;
        engine::$providererror = null;
    }

    /**
     * One narration clip in Ogg Opus, the format the activity plays.
     *
     * The service narrates with Google Chirp 3 HD voices. A Google key uses the same voice; an OpenAI key gets the
     * closest OpenAI voice of the same kind; ElevenLabs uses the voice the administrator chose.
     *
     * @param string $text
     * @param array $voice languageCode, name, id (from engine::voice_config()).
     * @return string Audio bytes.
     */
    protected static function speech(string $text, array $voice): string {
        $vendor = \local_aiconfig\byok\router::vendor(\local_aiconfig\byok\router::VOICE);
        if ($vendor === 'googletts') {
            $name = $voice['name'];
        } else if ($vendor === 'openai') {
            $name = self::OPENAI_VOICES[$voice['id']] ?? '';
        } else {
            $name = '';
        }
        return \local_aiconfig\byok\ai::speech(
            self::COMPONENT,
            $text,
            ['language' => $voice['languageCode'], 'voice' => $name, 'format' => 'ogg', 'rate' => self::SPEAKING_RATE]
        );
    }

    /**
     * Fill in the credentials the routes check for when the work does not go to LMS Labs.
     *
     * @param array $payload
     * @return array
     */
    protected static function with_credentials(array $payload): array {
        foreach (['siteId', 'apiKey'] as $field) {
            if (empty($payload[$field])) {
                $payload[$field] = self::NO_CREDENTIALS;
            }
        }
        return $payload;
    }

    /**
     * What to tell the teacher about an own-AI error.
     *
     * @param \Throwable $e
     * @return string
     */
    public static function message(\Throwable $e): string {
        if ($e instanceof \moodle_exception && $e->errorcode === 'byok_notavailable') {
            return get_string('error:byokunavailable', 'mod_aivideoactivity');
        }
        return get_string(
            'error:byokfailed',
            'mod_aivideoactivity',
            \core_text::substr(clean_param($e->getMessage(), PARAM_TEXT), 0, 400)
        );
    }

    /**
     * The route's failure answer for an own-AI error.
     *
     * @param \Throwable $e
     * @return array
     */
    protected static function failure(\Throwable $e): array {
        if ($e instanceof http_error) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        return ['ok' => false, 'error' => self::message($e)];
    }

    // Generation: a job, run by a task, polled like the service's.

    /**
     * The cache that holds own-AI generation jobs.
     *
     * @return \cache
     */
    protected static function jobs(): \cache {
        return \cache::make('mod_aivideoactivity', 'byokjobs');
    }

    /**
     * Cache key for a job id.
     *
     * @param string $jobid
     * @return string
     */
    protected static function key(string $jobid): string {
        return 'j' . sha1($jobid);
    }

    /**
     * Whether a job id is one of this plugin's own-AI jobs.
     *
     * @param string $jobid
     * @return bool
     */
    public static function is_job(string $jobid): bool {
        return strpos($jobid, self::JOB_PREFIX) === 0;
    }

    /**
     * Start a generation on the site's own AI: check the request as the service does, then queue the job.
     *
     * @param array $payload The generate request the plugin would send to LMS Labs.
     * @param \context $context The activity.
     * @return array The generate route's answer: ok, jobId, creditsRequired, totalQuestions, wordCount.
     */
    public static function start_generation(array $payload, \context $context): array {
        global $USER;
        try {
            $request = engine::generate_request(self::with_credentials($payload));
        } catch (http_error $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $jobid = self::JOB_PREFIX . bin2hex(random_bytes(16));
        self::jobs()->set(self::key($jobid), [
            'contextid' => $context->id,
            'status' => 'pending',
            'progress' => 0,
            'progressMessage' => 'Queued for processing',
            'questions' => null,
            'error' => null,
        ]);
        $task = new task\byok_generate();
        $task->set_custom_data(['jobid' => $jobid, 'contextid' => $context->id, 'mode' => 'generate',
            'params' => $request['params']]);
        $task->set_userid($USER->id);
        \core\task\manager::queue_adhoc_task($task);
        $response = $request['response'];
        $response['jobId'] = $jobid;
        $response['ownAi'] = true;
        return $response;
    }

    /**
     * Remember that a generation LMS Labs is writing (without voiceover) is to be narrated on the site's own voice.
     *
     * @param string $jobid The service's job id.
     * @param \context $context
     * @param string $language
     * @param string $voiceid
     */
    public static function narrate_later(string $jobid, \context $context, string $language, string $voiceid): void {
        self::jobs()->set(self::key('narrate:' . $jobid), ['contextid' => $context->id, 'language' => $language,
            'voiceId' => $voiceid]);
    }

    /**
     * The status answer for a job this plugin is handling, in the service's shape, or null when it is not one.
     *
     * Also takes over a finished LMS Labs job that is waiting to be narrated on the site's own voice.
     *
     * @param string $jobid
     * @param \context $context
     * @param array|null $servicestatus The service's status answer, when it was polled.
     * @return array|null
     */
    public static function status(string $jobid, \context $context, ?array $servicestatus = null): ?array {
        global $USER;
        $jobs = self::jobs();
        $job = $jobs->get(self::key($jobid));
        if ($job === false && $servicestatus !== null) {
            $narrate = $jobs->get(self::key('narrate:' . $jobid));
            if ($narrate === false || (int) $narrate['contextid'] !== (int) $context->id) {
                return null;
            }
            if (($servicestatus['status'] ?? '') !== 'completed' || !is_array($servicestatus['questions'] ?? null)) {
                return null;
            }
            $job = ['contextid' => $context->id, 'status' => 'processing', 'progress' => 70,
                'progressMessage' => get_string('byok:narrating', 'mod_aivideoactivity'), 'questions' => null,
                'error' => null];
            $jobs->set(self::key($jobid), $job);
            $jobs->delete(self::key('narrate:' . $jobid));
            $task = new task\byok_generate();
            $task->set_custom_data(['jobid' => $jobid, 'contextid' => $context->id, 'mode' => 'narrate',
                'questions' => $servicestatus['questions'], 'language' => $narrate['language'],
                'voiceId' => $narrate['voiceId']]);
            $task->set_userid($USER->id);
            \core\task\manager::queue_adhoc_task($task);
        }
        if ($job === false) {
            return self::is_job($jobid) ? ['ok' => false, 'error' => 'Job not found'] : null;
        }
        if ((int) $job['contextid'] !== (int) $context->id) {
            return ['ok' => false, 'error' => 'Job not found'];
        }
        $doc = ['ok' => true, 'status' => $job['status'], 'progress' => $job['progress'],
            'progressMessage' => $job['progressMessage']];
        if ($job['status'] === 'completed') {
            $doc['questions'] = $job['questions'];
        }
        if ($job['error'] !== null) {
            $doc['error'] = $job['error'];
        }
        return $doc;
    }

    /**
     * Run a queued job (from the task).
     *
     * @param array $data Task data: jobid, contextid, mode (generate or narrate) and its inputs.
     */
    public static function run_job(array $data): void {
        $jobs = self::jobs();
        $key = self::key($data['jobid']);
        $job = $jobs->get($key);
        if ($job === false) {
            return;
        }
        $context = \context::instance_by_id((int) $data['contextid'], IGNORE_MISSING);
        $save = function (array $changes) use ($jobs, $key, &$job) {
            $job = $changes + $job;
            $jobs->set($key, $job);
        };
        self::hooks(function (?int $progress, string $message) use ($save) {
            $changes = ['status' => 'processing'];
            if ($progress !== null) {
                $changes['progress'] = $progress;
            }
            if ($message !== '') {
                $changes['progressMessage'] = $message;
            }
            $save($changes);
        });
        \core_php_time_limit::raise();
        try {
            if ($data['mode'] === 'narrate') {
                $questions = engine::narrate($data['questions'], $data['language'], $data['voiceId']);
            } else {
                $params = $data['params'];
                $servicevoice = $params['voiceoverEnabled'] && !self::uses('voice');
                if ($servicevoice) {
                    $params['voiceoverEnabled'] = false;
                }
                $questions = engine::process($params);
                if ($servicevoice && $context) {
                    $save(['progress' => 80, 'progressMessage' => get_string('byok:narrating', 'mod_aivideoactivity')]);
                    $questions = self::narrate_on_credits($questions, $params['voiceLanguage'], $params['voiceId'], $context);
                }
            }
            $save(['status' => 'completed', 'progress' => 100,
                'progressMessage' => 'Video activity questions generated!', 'questions' => $questions]);
        } catch (\Throwable $e) {
            $error = $e instanceof js_error ? $e->getMessage() : self::message($e);
            $save(['status' => 'failed', 'error' => $error !== '' ? $error : 'Generation failed']);
        } finally {
            engine::$progress = null;
        }
    }

    /**
     * Narrate questions on LMS Labs credits (text on the site's own AI, voice on credits).
     *
     * When LMS Labs cannot narrate them (no credentials, no credits), the questions are kept without audio.
     *
     * @param array $questions
     * @param string $language
     * @param string $voiceid
     * @param \context $context
     * @return array
     */
    protected static function narrate_on_credits(array $questions, string $language, string $voiceid, \context $context): array {
        global $USER;
        [$apibase, $siteid, $apikey] = self::credentials();
        if ($siteid === '' || $apikey === '' || !$questions) {
            return $questions;
        }
        $curl = new \curl();
        $raw = $curl->post($apibase . '/api/videoactivity-regenerate-audio', json_encode(['siteId' => $siteid,
            'apiKey' => $apikey, 'questions' => $questions, 'voiceLanguage' => $language, 'voiceId' => $voiceid]), [
            'CURLOPT_TIMEOUT' => 240,
            'CURLOPT_CONNECTTIMEOUT' => 10,
            'CURLOPT_HTTPHEADER' => ['Content-Type: application/json'],
        ]);
        $httpcode = (int) ($curl->get_info()['http_code'] ?? 0);
        if ($curl->error || $httpcode !== 200 || !is_string($raw)) {
            return $questions;
        }
        $doc = json_decode($raw, true);
        if (method_exists('\\local_clientportal\\api', 'record_lmslabs_call')) {
            \local_clientportal\api::record_lmslabs_call(
                (int) $USER->id,
                (int) $context->id,
                self::COMPONENT,
                $doc,
                0,
                'Voiceover regenerated',
                ''
            );
        }
        $narrated = is_array($doc) ? ($doc['questions'] ?? null) : null;
        if (!is_array($narrated) || count($narrated) !== count($questions)) {
            return $questions;
        }
        foreach ($questions as $i => $q) {
            // The route can upgrade a card question (one explanation and clip per card), so take it whole.
            if (is_array($q) && is_array($narrated[$i] ?? null) && array_key_exists('audioData', $narrated[$i])) {
                $questions[$i] = $narrated[$i];
            }
        }
        return $questions;
    }

    // The other routes answer straight away.

    /**
     * Rewrite a question set on the site's own AI: the regenerate-settings or regenerate-instructions route.
     *
     * @param string $route settings or instructions.
     * @param array $payload The request the plugin would send to LMS Labs.
     * @param \context $context
     * @return array The route's answer.
     */
    public static function regenerate(string $route, array $payload, \context $context): array {
        self::hooks();
        \core_php_time_limit::raise(self::TEXT_TIMEOUT + 120);
        $payload = self::with_credentials($payload);
        $servicevoice = !empty($payload['voiceoverEnabled']) && !self::uses('voice');
        if ($servicevoice) {
            $payload['voiceoverEnabled'] = false;
        }
        try {
            $out = $route === 'instructions' ? engine::regenerate_instructions($payload) :
                engine::regenerate_settings($payload);
        } catch (http_error $e) {
            // The service's message for a failed rewrite is fixed; say what the provider said when it said something.
            $said = engine::$providererror;
            return ['ok' => false, 'error' => $e->status === 500 && $said !== null
                ? get_string('error:byokfailed', 'mod_aivideoactivity', $said) : $e->getMessage()];
        } catch (\Throwable $e) {
            return self::failure($e);
        }
        if ($servicevoice && is_array($out['questions'] ?? null)) {
            $out['questions'] = self::narrate_on_credits(
                $out['questions'],
                (string) ($payload['voiceLanguage'] ?? 'en-AU'),
                (string) ($payload['voiceId'] ?? 'Aoede'),
                $context
            );
        }
        $out['ownAi'] = true;
        return $out;
    }

    /**
     * Narrate questions LMS Labs wrote without voiceover, on the site's own voice (text on credits, voice own).
     *
     * @param array $result The service's answer.
     * @param string $language
     * @param string $voiceid
     * @return array The answer with audio.
     */
    public static function narrate_result(array $result, string $language, string $voiceid): array {
        if (empty($result['ok']) || !is_array($result['questions'] ?? null) || !$result['questions']) {
            return $result;
        }
        self::hooks();
        \core_php_time_limit::raise(600);
        try {
            $result['questions'] = engine::narrate($result['questions'], $language, $voiceid);
        } catch (\Throwable $e) {
            return self::failure($e);
        }
        return $result;
    }

    /**
     * Narrate questions on the site's own voice: the regenerate-audio route.
     *
     * @param array $payload
     * @return array The route's answer.
     */
    public static function audio(array $payload): array {
        self::hooks();
        \core_php_time_limit::raise(600);
        try {
            return engine::regenerate_audio(self::with_credentials($payload));
        } catch (\Throwable $e) {
            return self::failure($e);
        }
    }

    /**
     * One clip on the site's own voice: the tts-single route.
     *
     * @param array $payload
     * @return array The route's answer.
     */
    public static function tts_single(array $payload): array {
        self::hooks();
        \core_php_time_limit::raise(120);
        try {
            return engine::tts_single(self::with_credentials($payload));
        } catch (\Throwable $e) {
            return self::failure($e);
        }
    }
}
