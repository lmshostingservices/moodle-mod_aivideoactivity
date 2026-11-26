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

/**
 * Tests for writing and narrating video activity questions on the site's own AI.
 *
 * local\byok\engine was compared with the LMS Labs service code on 155 hand-written cases and 900 randomised AI
 * answers across every route (identical prompts, token budgets, parsing, repairs, answer shuffles, narration calls,
 * responses and job errors); these tests cover the wiring into the plugin.
 *
 * @package    mod_aivideoactivity
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(byok::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(engine::class)]
final class byok_test extends \advanced_testcase {
    /** @var string A transcript with timestamps, as teachers paste it. */
    private const TRANSCRIPT = "0:05\nWelcome to forklift safety training.\n0:30\nAlways check the brakes, horn and lights "
        . "before every shift.\n1:10\nNever carry passengers on the forks or pallets.\n2:05\nKeep the load low and tilted "
        . "back while travelling.\n3:40\nReport any hydraulic leaks to your supervisor and tag the forklift out of service.";

    /** @var array Requests the mocked providers received. */
    protected $requests = [];
    /** @var array Text answers the mocked text provider gives, in order. */
    protected $answers = [];
    /** @var \context_module */
    protected $context;
    /** @var \stdClass */
    protected $cm;

    /**
     * Licence the site for Bring your own AI on a test key, with text on an Anthropic key.
     */
    protected function setUp(): void {
        parent::setUp();
        if (!class_exists('\local_aiconfig\byok\ai')) {
            $this->markTestSkipped('AI Grader Central Config 1.1.0 or later is not installed.');
        }
        $this->resetAfterTest();
        $pair = sodium_crypto_sign_keypair();
        set_config('testpublickey', base64_encode(sodium_crypto_sign_publickey($pair)), 'local_aiconfig');
        $payload = json_encode(['v' => 1, 'id' => 'lic_t', 'site' => \local_aiconfig\byok\licence::site_host(),
            'plan' => 'pro', 'byok' => true, 'products' => ['*'], 'sub' => 'sub_t', 'exp' => time() + DAYSECS]);
        $b64 = function (string $s): string {
            return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        };
        set_config('licencekey', $b64($payload) . '.' .
            $b64(sodium_crypto_sign_detached($payload, sodium_crypto_sign_secretkey($pair))), 'local_aiconfig');
        set_config('src_text', 'ownkey', 'local_aiconfig');
        set_config('vendor_text', 'anthropic', 'local_aiconfig');
        set_config('key_anthropic', 'sk-test', 'local_aiconfig');
        set_config('key_gemini', 'g-test', 'local_aiconfig');
        set_config('key_openai', 'sk-open', 'local_aiconfig');
        // No LMS Labs credentials: everything on the site's own AI must work without them.
        set_config('siteid', '', 'mod_aivideoactivity');
        set_config('apikey', '', 'mod_aivideoactivity');
        $this->requests = [];
        $this->answers = [];
        $GLOBALS['local_aiconfig_byok_mock'] = function ($url, $headers, $body, $vendor, $raw = false) {
            $this->requests[] = ['url' => $url, 'body' => $body, 'vendor' => $vendor];
            if (strpos($url, 'texttospeech') !== false) {
                return ['audioContent' => base64_encode('OggS-' . $body['input']['text'])];
            }
            if (strpos($url, 'audio/speech') !== false) {
                return 'OggS-openai-' . $body['voice'];
            }
            $answer = array_shift($this->answers);
            if ($answer === null) {
                throw new \moodle_exception('byok_callfailed', 'local_aiconfig', '', (object) ['vendor' => $vendor,
                    'detail' => 'Overloaded']);
            }
            return ['content' => [['type' => 'text', 'text' => $answer]], 'stop_reason' => 'end_turn'];
        };
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $activity = $this->getDataGenerator()->get_plugin_generator('mod_aivideoactivity')
            ->create_instance(['course' => $course->id]);
        $this->cm = get_coursemodule_from_instance('aivideoactivity', $activity->id);
        $this->context = \context_module::instance($this->cm->id);
        $this->setUser($teacher);
    }

    /**
     * Clean up the provider mock.
     */
    protected function tearDown(): void {
        unset($GLOBALS['local_aiconfig_byok_mock']);
        engine::$aicall = engine::$tts = engine::$progress = null;
        parent::tearDown();
    }

    /**
     * A question set as the model writes it.
     *
     * @param string $topic
     * @return string
     */
    protected function questions(string $topic = 'forklift'): string {
        return json_encode(['questions' => [
            ['type' => 'mcq', 'question' => "Which check comes first before driving the {$topic}?",
                'options' => ['Brakes, horn and lights', 'Fuel level only', 'Seat height', 'Radio channel'],
                'correctAnswer' => 0, 'timestamp_seconds' => 30,
                'explanations' => ['Correct. The pre-start check covers brakes, horn and lights.',
                    'Incorrect. Fuel alone does not make the machine safe.',
                    'Incorrect. Seat height is comfort, not safety.',
                    'Incorrect. The radio is not part of the pre-start check.']],
            ['type' => 'ordering', 'question' => "Put the {$topic} pre-start steps in order",
                'items' => ['Walk around', 'Check brakes', 'Test horn', 'Sign the log'],
                'explanation' => 'The walk-around comes before any control checks.'],
        ]]);
    }

    /**
     * The generate request the page sends.
     *
     * @param array $over
     * @return array
     */
    protected function payload(array $over = []): array {
        return $over + ['siteId' => '', 'apiKey' => '', 'cmid' => $this->cm->id, 'videoactivityId' => $this->cm->instance,
            'transcript' => self::TRANSCRIPT, 'numQuestions' => 2, 'voiceoverEnabled' => false, 'voiceLanguage' => 'en-AU',
            'voiceGender' => 'female', 'voiceId' => 'Kore', 'extraInstructions' => '', 'questionType' => 'application',
            'bloomLevel' => 3, 'scenarioCountry' => 'Australia', 'scenarioIndustry' => 'Mining', 'scenarioSubindustry' => '',
            'scenarioJobLevel' => '', 'scenarioJobRoles' => '', 'selectedFormats' => ['mcq', 'ordering']];
    }

    public function test_generation_runs_on_own_text_without_credentials(): void {
        $this->answers = [$this->questions()];
        $started = byok::start_generation($this->payload(), $this->context);
        $this->assertTrue($started['ok']);
        $this->assertStringStartsWith('byok-', $started['jobId']);
        $this->assertSame(0, $started['creditsRequired']);
        $this->assertSame(2, $started['totalQuestions']);
        $this->assertSame('pending', byok::status($started['jobId'], $this->context)['status']);
        $this->assertCount(0, $this->requests);

        $this->runAdhocTasks('\mod_aivideoactivity\task\byok_generate');
        $done = byok::status($started['jobId'], $this->context);
        $this->assertSame('completed', $done['status'], $done['error'] ?? '');
        $this->assertSame(100, $done['progress']);
        $this->assertSame('Video activity questions generated!', $done['progressMessage']);
        $this->assertCount(2, $done['questions']);
        $mcq = $done['questions'][0]['type'] === 'mcq' ? $done['questions'][0] : $done['questions'][1];
        $this->assertStringStartsWith('Correct.', $mcq['explanations'][$mcq['correctAnswer']]);
        $this->assertSame('Brakes, horn and lights', $mcq['options'][$mcq['correctAnswer']]);
        foreach ($done['questions'] as $q) {
            $this->assertArrayNotHasKey('audioData', $q);
        }
        // The service's own prompt, sent to the site's Anthropic key.
        $this->assertCount(1, $this->requests);
        $this->assertSame('Anthropic', $this->requests[0]['vendor']);
        $prompt = json_encode($this->requests[0]['body']);
        $this->assertStringContainsString('BLOOM\'S TAXONOMY LEVEL: 3 - Apply', $prompt);
        $this->assertStringContainsString('forklift safety training', $prompt);
    }

    public function test_narration_on_own_google_voice(): void {
        set_config('src_voice', 'ownkey', 'local_aiconfig');
        set_config('vendor_voice', 'googletts', 'local_aiconfig');
        $this->answers = [$this->questions()];
        $started = byok::start_generation($this->payload(['voiceoverEnabled' => true]), $this->context);
        $this->runAdhocTasks('\mod_aivideoactivity\task\byok_generate');
        $done = byok::status($started['jobId'], $this->context);
        $this->assertSame('completed', $done['status'], $done['error'] ?? '');
        foreach ($done['questions'] as $q) {
            $this->assertNotEmpty($q['audioData']);
            $expected = $q['type'] === 'mcq' ? $q['explanations'] : [$q['explanation']];
            $this->assertCount(count($expected), $q['audioData']);
            $this->assertSame('OggS-' . $expected[0], base64_decode($q['audioData'][0]));
        }
        $tts = array_values(array_filter($this->requests, function ($r) {
            return strpos($r['url'], 'texttospeech') !== false;
        }));
        $this->assertCount(5, $tts);
        $this->assertSame('en-AU-Chirp3-HD-Kore', $tts[0]['body']['voice']['name']);
        $this->assertSame(0.92, $tts[0]['body']['audioConfig']['speakingRate']);
    }

    public function test_openai_voice_gets_the_matching_voice(): void {
        set_config('src_voice', 'ownkey', 'local_aiconfig');
        set_config('vendor_voice', 'openai', 'local_aiconfig');
        $out = byok::tts_single(['siteId' => '', 'apiKey' => '', 'text' => '  Check the brakes.  ', 'voiceLanguage' => 'en-AU',
            'voiceId' => 'Charon']);
        $this->assertTrue($out['ok'], $out['error'] ?? '');
        $this->assertSame('OggS-openai-echo', base64_decode($out['audioData']));
        $this->assertSame('Check the brakes.', end($this->requests)['body']['input']);
    }

    public function test_tts_single_keeps_the_service_checks(): void {
        set_config('src_voice', 'ownkey', 'local_aiconfig');
        set_config('vendor_voice', 'googletts', 'local_aiconfig');
        $this->assertSame(
            ['ok' => false, 'error' => 'Text too long (max 1000 chars)'],
            byok::tts_single(['text' => str_repeat('a', 1001)])
        );
        $this->assertCount(0, $this->requests);
    }

    public function test_failed_provider_fails_the_job_with_its_message(): void {
        $started = byok::start_generation($this->payload(), $this->context);
        $this->runAdhocTasks('\mod_aivideoactivity\task\byok_generate');
        $done = byok::status($started['jobId'], $this->context);
        $this->assertSame('failed', $done['status']);
        $this->assertStringContainsString('Overloaded', $done['error']);
    }

    public function test_job_belongs_to_its_activity(): void {
        $started = byok::start_generation($this->payload(), $this->context);
        $other = $this->getDataGenerator()->get_plugin_generator('mod_aivideoactivity')
            ->create_instance(['course' => $this->cm->course]);
        $othercontext = \context_module::instance(get_coursemodule_from_instance('aivideoactivity', $other->id)->id);
        $this->assertSame(['ok' => false, 'error' => 'Job not found'], byok::status($started['jobId'], $othercontext));
        $this->assertSame(['ok' => false, 'error' => 'Job not found'], byok::status('byok-missing', $this->context));
        // A service job id is not one of ours: the page asks LMS Labs.
        $this->assertNull(byok::status('3f2a8c4e-service', $this->context));
    }

    public function test_service_checks_apply_before_queueing(): void {
        $out = byok::start_generation($this->payload(['transcript' => 'Too short.']), $this->context);
        $this->assertFalse($out['ok']);
        $this->assertStringStartsWith('Validation error: transcript:', $out['error']);
        $this->assertEmpty(\core\task\manager::get_adhoc_tasks('\mod_aivideoactivity\task\byok_generate'));
    }

    public function test_regenerate_with_settings_on_own_text(): void {
        $questions = json_decode($this->questions(), true)['questions'];
        $this->answers = [json_encode([
            ['question' => 'Quelle vérification vient en premier ?', 'options' => ['Freins, klaxon et feux', 'Carburant',
                'Siège', 'Radio'], 'explanations' => ['Correct. a', 'Incorrect. b', 'Incorrect. c', 'Incorrect. d']],
            ['question' => 'Mettez les étapes dans l\'ordre', 'items' => ['Tour', 'Freins', 'Klaxon', 'Registre'],
                'explanation' => 'Le tour vient en premier.'],
        ])];
        $out = byok::regenerate(
            'settings',
            ['siteId' => '', 'apiKey' => '', 'questions' => $questions,
            'voiceLanguage' => 'fr-FR', 'voiceoverEnabled' => false, 'voiceGender' => 'female', 'voiceId' => 'Kore'],
            $this->context
        );
        $this->assertTrue($out['ok'], $out['error'] ?? '');
        $this->assertTrue($out['ownAi']);
        $this->assertCount(2, $out['questions']);
        $this->assertCount(1, $this->requests);
        $this->assertStringContainsString('French', json_encode($this->requests[0]['body']));
    }

    public function test_regenerate_failure_says_what_the_provider_said(): void {
        $questions = json_decode($this->questions(), true)['questions'];
        $out = byok::regenerate('settings', ['questions' => $questions, 'voiceLanguage' => 'fr-FR'], $this->context);
        $this->assertFalse($out['ok']);
        $this->assertStringContainsString('Overloaded', $out['error']);
        $this->assertStringContainsString('No LMS Labs credits were used', $out['error']);
    }

    public function test_regenerate_audio_on_own_voice(): void {
        set_config('src_voice', 'ownkey', 'local_aiconfig');
        set_config('vendor_voice', 'googletts', 'local_aiconfig');
        $questions = json_decode($this->questions(), true)['questions'];
        $out = byok::audio(['siteId' => '', 'apiKey' => '', 'questions' => $questions, 'voiceLanguage' => 'pa-IN',
            'voiceId' => 'Puck']);
        $this->assertTrue($out['ok'], $out['error'] ?? '');
        $this->assertCount(4, $out['questions'][0]['audioData']);
        $this->assertCount(1, $out['questions'][1]['audioData']);
        // The service's fallback for Punjabi: Hindi Chirp voices.
        $this->assertSame('hi-IN-Chirp3-HD-Puck', $this->requests[0]['body']['voice']['name']);
        $this->assertSame(
            ['ok' => false, 'error' => 'Questions required'],
            byok::audio(['questions' => []])
        );
    }

    public function test_service_job_is_narrated_on_own_voice(): void {
        set_config('src_text', 'credits', 'local_aiconfig');
        set_config('src_voice', 'ownkey', 'local_aiconfig');
        set_config('vendor_voice', 'googletts', 'local_aiconfig');
        byok::narrate_later('svc-123', $this->context, 'en-GB', 'Aoede');
        $service = ['ok' => true, 'status' => 'processing', 'progress' => 40, 'progressMessage' => 'Generating'];
        $this->assertNull(byok::status('svc-123', $this->context, $service));
        $service = ['ok' => true, 'status' => 'completed', 'progress' => 100,
            'progressMessage' => 'Video activity questions generated!',
            'questions' => json_decode($this->questions(), true)['questions']];
        $taken = byok::status('svc-123', $this->context, $service);
        $this->assertSame('processing', $taken['status']);
        $this->assertArrayNotHasKey('questions', $taken);
        $this->runAdhocTasks('\mod_aivideoactivity\task\byok_generate');
        $done = byok::status('svc-123', $this->context, $service);
        $this->assertSame('completed', $done['status'], $done['error'] ?? '');
        $this->assertCount(4, $done['questions'][0]['audioData']);
        $this->assertSame('en-GB-Chirp3-HD-Aoede', $this->requests[0]['body']['voice']['name']);
    }

    public function test_narrate_result_adds_own_voice_to_a_service_answer(): void {
        set_config('src_voice', 'ownkey', 'local_aiconfig');
        set_config('vendor_voice', 'googletts', 'local_aiconfig');
        $result = ['ok' => true, 'questions' => json_decode($this->questions(), true)['questions'], 'isFree' => true];
        $out = byok::narrate_result($result, 'en-AU', 'Kore');
        $this->assertTrue($out['isFree']);
        $this->assertCount(4, $out['questions'][0]['audioData']);
        $failed = ['ok' => false, 'error' => 'INSUFFICIENT_CREDITS'];
        $this->assertSame($failed, byok::narrate_result($failed, 'en-AU', 'Kore'));
    }

    public function test_without_licence_everything_stays_on_lms_labs(): void {
        set_config('licencekey', '', 'local_aiconfig');
        $this->assertSame(['text' => false, 'voice' => false], byok::state());
        $this->assertFalse(byok::any());
    }
}
