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

namespace mod_aivideoactivity\local\byok;

/**
 * The LMS Labs AI Video Activity service, ported to run on the site's own AI.
 *
 * This is the service's own logic, in PHP: the same prompts, token budgets, parsing, checks, answer shuffling,
 * format mix, timestamp matching and narration. Prompt texts are generated from the service source, so they match
 * it character for character. The AI, narration and random-number calls go through the hooks below, which
 * mod_aivideoactivity\byok points at the site's own AI.
 *
 * Values from the AI's JSON are kept as the service sees them: a JSON object is a PHP array with string keys
 * (or an empty \stdClass), a JSON array is a PHP list.
 *
 * @package    mod_aivideoactivity
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class engine {
    /** JavaScript's whitespace, for trim() and \s. */
    const WS = '\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

    /** @var callable fn(string $system, string $user, array $options): string. Options: maxtokens, json, temperature. */
    public static $aicall = null;
    /** @var callable fn(string $text, array $voice): string audio bytes. Voice: languageCode, name, id. */
    public static $tts = null;
    /** @var callable fn(): float in [0, 1). */
    public static $random = null;
    /** @var callable|null fn(int $progress, string $message), told how a generation is going. */
    public static $progress = null;
    /** @var string|null The last provider error during a generation, to say why nothing came back. */
    public static $providererror = null;

    /** Question formats the service writes. */
    const FORMATS = ['mcq', 'truefalse', 'cardselect', 'matching', 'ordering', 'columnsort', 'categorysort', 'flashcards',
        'truefalseswipe', 'fillinblank'];
    /** Formats when the request names none. */
    const DEFAULT_FORMATS = ['mcq', 'cardselect', 'matching', 'ordering', 'columnsort', 'categorysort'];
    /** Tokens for a whole-set rewrite (the service leaves this to the model's own limit). */
    const REGEN_TOKENS = 16000;
    /** Tokens for one rewritten question. */
    const ONE_QUESTION_TOKENS = 2048;

    // The generate route.

    /**
     * Check a generate request and work out the job, as the service's generate route does.
     *
     * @param array $body Request body, decoded from JSON.
     * @return array Keys: response (the route's JSON answer), params (the job to run).
     * @throws http_error
     */
    public static function generate_request(array $body): array {
        $issues = [];
        self::z_string($body, 'siteId', $issues, ['required' => true]);
        self::z_string($body, 'apiKey', $issues, ['required' => true]);
        $d = [];
        $d['transcript'] = self::z_string($body, 'transcript', $issues, ['required' => true, 'min' => 50, 'max' => 50000]);
        $d['numQuestions'] = self::z_number($body, 'numQuestions', $issues, 5, 1, 20);
        $d['extraInstructions'] = self::z_string($body, 'extraInstructions', $issues, ['max' => 2000, 'default' => '']);
        $d['voiceoverEnabled'] = true;
        if (array_key_exists('voiceoverEnabled', $body)) {
            $v = $body['voiceoverEnabled'];
            if (is_bool($v) || is_int($v) || (is_float($v) && !is_nan($v))) {
                $d['voiceoverEnabled'] = $v;
            } else {
                $issues[] = [['voiceoverEnabled'], 'Invalid input'];
            }
        }
        $d['voiceLanguage'] = self::z_string($body, 'voiceLanguage', $issues, ['default' => 'en-AU']);
        $d['voiceGender'] = self::z_string($body, 'voiceGender', $issues, ['default' => 'female']);
        $d['voiceId'] = self::z_string($body, 'voiceId', $issues, ['default' => 'Aoede']);
        $d['questionType'] = self::z_enum($body, 'questionType', $issues, ['application', 'scenario', 'mixed'], 'application');
        $d['bloomLevel'] = self::z_number($body, 'bloomLevel', $issues, 3, 1, 6);
        $d['scenarioCountry'] = self::z_string($body, 'scenarioCountry', $issues, ['max' => 100, 'default' => '']);
        $d['scenarioIndustry'] = self::z_string($body, 'scenarioIndustry', $issues, ['max' => 200, 'default' => '']);
        $d['scenarioSubindustry'] = self::z_string($body, 'scenarioSubindustry', $issues, ['max' => 200, 'default' => '']);
        $d['selectedFormats'] = self::DEFAULT_FORMATS;
        if (array_key_exists('selectedFormats', $body)) {
            $v = $body['selectedFormats'];
            if (!self::is_array_js($v)) {
                $issues[] = [['selectedFormats'], self::z_expected('array', $v)];
            } else {
                $list = implode(' | ', array_map(function ($x) {
                    return "'" . $x . "'";
                }, self::FORMATS));
                foreach ($v as $i => $f) {
                    if (!is_string($f)) {
                        $issues[] = [['selectedFormats', $i], 'Expected ' . $list . ', received ' . self::z_type($f)];
                    } else if (!in_array($f, self::FORMATS, true)) {
                        $issues[] = [['selectedFormats', $i], 'Invalid enum value. Expected ' . $list . ", received '" . $f .
                            "'"];
                    }
                }
                $d['selectedFormats'] = $v;
            }
        }
        if ($issues) {
            throw new http_error(400, 'Validation error: ' . implode(', ', array_map(function ($issue) {
                return implode('.', $issue[0]) . ': ' . $issue[1];
            }, $issues)));
        }

        // Words, as the service counts them (timestamps stripped).
        $lt = '\n\r\x{2028}\x{2029}';
        $clean = preg_replace('/(?<=\A|[' . $lt . '])[' . self::WS . ']*[0-9]{1,2}:[0-9]{2}(?::[0-9]{2})?[' . self::WS
            . ']*(?=\z|[' . $lt . '])/u', '', $d['transcript']);
        $clean = preg_replace('/\n{2,}/', "\n", $clean);
        $words = preg_split('/[' . self::WS . ']+/u', self::jtrim($clean));
        $wordcount = count(array_filter($words, function ($w) {
            return $w !== '';
        }));

        $params = [
            'transcript' => $d['transcript'],
            'numQuestions' => $d['numQuestions'],
            'extraInstructions' => self::js_or($d['extraInstructions'], ''),
            'voiceoverEnabled' => self::js_truthy($d['voiceoverEnabled']),
            'voiceLanguage' => $d['voiceLanguage'],
            'voiceGender' => $d['voiceGender'],
            'voiceId' => $d['voiceId'],
            'questionType' => self::js_or($d['questionType'], 'application'),
            'bloomLevel' => self::js_or($d['bloomLevel'], 3),
            'scenarioCountry' => self::js_or($d['scenarioCountry'], ''),
            'scenarioIndustry' => self::js_or($d['scenarioIndustry'], ''),
            'scenarioSubindustry' => self::js_or($d['scenarioSubindustry'], ''),
            'selectedFormats' => self::js_or($d['selectedFormats'], self::DEFAULT_FORMATS),
        ];
        return [
            'response' => ['ok' => true, 'jobId' => '', 'creditsRequired' => 0, 'totalQuestions' => $d['numQuestions'],
                'wordCount' => $wordcount],
            'params' => $params,
        ];
    }

    // The job: processVideoActivity.

    /** Bloom's levels, as the service describes them. */
    const BLOOM = [
        1 => ['name' => 'Remember', 'verbs' => 'define, list, recall, identify, name, recognise, state',
            'description' => 'Recall facts and basic concepts. Questions should test whether the learner can retrieve '
                . 'relevant knowledge from memory.'],
        2 => ['name' => 'Understand', 'verbs' => 'describe, explain, summarise, paraphrase, classify, compare, interpret',
            'description' => 'Explain ideas or concepts. Questions should test whether the learner can construct meaning '
                . 'from the content.'],
        3 => ['name' => 'Apply', 'verbs' => 'use, implement, execute, solve, demonstrate, apply, illustrate',
            'description' => 'Use information in new situations. Questions should test whether the learner can carry out or '
                . 'use a procedure in a given situation.'],
        4 => ['name' => 'Analyse', 'verbs' => 'differentiate, organise, attribute, compare, deconstruct, examine, distinguish',
            'description' => 'Draw connections among ideas. Questions should test whether the learner can break material '
                . 'into constituent parts and determine how parts relate.'],
        5 => ['name' => 'Evaluate', 'verbs' => 'judge, justify, critique, defend, assess, appraise, argue, support',
            'description' => 'Justify a stand or decision. Questions should test whether the learner can make judgements '
                . 'based on criteria and standards.'],
        6 => ['name' => 'Create', 'verbs' => 'design, construct, plan, produce, devise, formulate, propose, hypothesise',
            'description' => 'Produce new or original work. Questions should test whether the learner can put elements '
                . 'together to form a coherent or functional whole, or reorganise elements into a new pattern.'],
    ];

    /**
     * Run a generation job, as the service's processVideoActivity does.
     *
     * @param array $params From generate_request().
     * @return array The questions.
     * @throws js_error When nothing could be generated; the message is the job's error.
     */
    public static function process(array $params): array {
        self::$providererror = null;
        self::progress(5, 'Generating questions from video transcript...');
        $voice = self::voice_config($params['voiceLanguage'], $params['voiceId']);
        self::progress(10, 'Generating ' . self::js_str($params['numQuestions']) . ' questions from transcript...');

        $isscenariomode = $params['questionType'] === 'scenario';
        $ismixedmode = $params['questionType'] === 'mixed';
        $isapplicationmode = $params['questionType'] === 'application' || (!$isscenariomode && !$ismixedmode);
        if ($isscenariomode) {
            $scenariocontext = self::scenario_context(
                $params['scenarioCountry'],
                $params['scenarioIndustry'],
                $params['scenarioSubindustry']
            );
            $questiontypeinstruction = ('

QUESTION TYPE: ALL SCENARIO-BASED
Generate ALL ' . self::js_str(self::js_get($params, 'numQuestions')) . ' questions as workplace scenario questions. Each ' .
            'question MUST present a realistic workplace situation or case study where a worker, manager, or team faces a ' .
                'specific ' .
            'challenge related to the video content. The learner must respond based on the scenario context.
' . self::js_str($scenariocontext));
        } else if ($ismixedmode) {
            $halfscenario = (int) ceil($params['numQuestions'] / 2);
            $halfapplication = $params['numQuestions'] - $halfscenario;
            $scenariocontext = self::scenario_context(
                $params['scenarioCountry'],
                $params['scenarioIndustry'],
                $params['scenarioSubindustry']
            );
            $questiontypeinstruction = ('

QUESTION TYPE: MIXED — STRICTLY ' . self::js_str($halfapplication) . ' Application + ' . self::js_str($halfscenario) . ' Sc' .
            'enario
You MUST generate two distinct categories of questions:

CATEGORY A — APPLICATION OF KNOWLEDGE (exactly ' . self::js_str($halfapplication) . ' questions):
- These questions test conceptual understanding, comparison, or analysis of IDEAS from the content.
- They must NOT contain workplace scenarios, case studies, or character names.
- They should ask the learner to explain, compare, differentiate, evaluate, or apply a CONCEPT — not respond to a ' .
            'situation.
- Example stems: "Which principle best explains why...", "What is the key difference between...", "How does X contribute ' .
            'to..."

CATEGORY B — WORKPLACE SCENARIO (exactly ' . self::js_str($halfscenario) . ' questions):
- These questions MUST present a detailed, realistic workplace situation with specific people, places, and circumstances.
- The learner must make a judgement call or decision based on the scenario context.
- Example stems: "You are a [role] at [specific place]. [Specific situation]. What should you do first?"
' . self::js_str($scenariocontext) . '

MIXING RULE: Alternate between Category A and Category B. Do NOT group all of one type together.
LABEL RULE: For each question, add a "category" field with value "application" or "scenario" so the output clearly shows the ' .
            'split.
ENFORCEMENT: If you generate ' . self::js_str(self::js_add($halfapplication, 1)) . ' or more scenario questions, you have ' .
            'FAILED the task. Count carefully.');
        } else {
            $questiontypeinstruction = ('

QUESTION TYPE: ALL APPLICATION OF KNOWLEDGE
Generate ALL ' . self::js_str(self::js_get($params, 'numQuestions')) . ' questions as application-of-knowledge questions. ' .
            'Each question should test the learner\'s grasp of concepts from the video content. Do NOT wrap questions in ' .
                'workplace ' .
            'scenarios or case studies — focus on conceptual understanding, comparison, analysis, and evaluation of the ' .
                'ideas ' .
            'presented in the content.');
        }

        $level = $params['bloomLevel'];
        $bloom = (is_int($level) || (is_float($level) && floor($level) === $level)) && isset(self::BLOOM[(int) $level])
            ? self::BLOOM[(int) $level] : self::BLOOM[3];
        if ($isscenariomode && $params['bloomLevel'] >= 3) {
            $scenarioenforcement = ('
SCENARIO ENFORCEMENT (MANDATORY at Bloom Level ' . self::js_str(self::js_get($params, 'bloomLevel')) . '):
Every scenario question MUST begin with a realistic, detailed workplace situation. The scenario must contain contextual ' .
            'details that require interpretation before answering. Do NOT generate any scenario question without a scenario ' .
                'preamble.');
        } else if ($ismixedmode && $params['bloomLevel'] >= 3) {
            $scenarioenforcement = ('
SCENARIO ENFORCEMENT FOR MIXED MODE (Bloom Level ' . self::js_str(self::js_get($params, 'bloomLevel')) . '):
- SCENARIO questions (Category B) MUST begin with a detailed workplace situation requiring interpretation.
- APPLICATION questions (Category A) must NOT have scenario preambles. They should directly ask about concepts, principles, ' .
            'or comparisons. Application questions can still be cognitively demanding without needing a scenario wrapper.');
        } else if ($isapplicationmode) {
            $scenarioenforcement = ('
Questions should require cognitive effort and reasoning but must NOT be wrapped in workplace scenarios. Focus on conceptual ' .
                'depth: comparison, analysis, evaluation of ideas from the content.');
        } else {
            $scenarioenforcement = ('
Questions may assess understanding or interpretation but must still avoid direct transcript copying or surface recall.');
        }
        $bloominstruction = ('

BLOOM\'S TAXONOMY LEVEL: ' . self::js_str(self::js_get($params, 'bloomLevel')) . ' - ' . self::js_str(self::js_get(
            $bloom,
            'name'
        )) . '
' . self::js_str(self::js_get($bloom, 'description')) . '
Target cognitive verbs: ' . self::js_str(self::js_get($bloom, 'verbs')) . '
All questions MUST be written at Bloom\'s Level ' . self::js_str(self::js_get($params, 'bloomLevel')) . ' (' .
        self::js_str(self::js_get($bloom, 'name')) .
        '). Do NOT write questions at lower or higher levels. Frame every question stem using the cognitive verbs listed ' .
            'above.' .
            self::js_str($scenarioenforcement));
        $enriched = self::js_str(self::js_or($params['extraInstructions'], '')) . $questiontypeinstruction . $bloominstruction;
        $country = self::js_or($params['scenarioCountry'], '');

        $questions = self::write_questions(
            $params['transcript'],
            $params['numQuestions'],
            $enriched,
            $country,
            $params['voiceLanguage'],
            $params['selectedFormats']
        );
        self::progress(60, 'Generated ' . count($questions) . ' questions');

        if ($params['voiceoverEnabled']) {
            self::progress(70, '');
            $n = count($questions);
            for ($i = 0; $i < $n; $i++) {
                self::progress(null, 'Generating voiceover ' . ($i + 1) . '/' . $n . '...');
                $q = self::upgrade_cardselect($questions[$i]);
                // The service narrates a copy when the explanation order had to be fixed, and keeps the
                // question it was given; that is reproduced here.
                $kept = $q;
                if (
                    self::card_type($q) && self::is_array_js(self::prop($q, 'explanations'))
                        && count($q['explanations']) > 0
                ) {
                    [$q, $changed] = self::fix_card_order($q);
                    if (!$changed) {
                        $kept = null;
                    }
                } else {
                    $kept = null;
                }
                $percard = self::is_per_card($q, false);
                if ($q['type'] === 'mcq' || $percard) {
                    $tovoice = self::list_of(self::js_or($q['explanations'] ?? null, []));
                } else {
                    $tovoice = [self::js_or(self::js_or($q['explanation'] ?? null, $q['question'] ?? null), '')];
                }
                $q['audioData'] = array_map(function ($e) use ($voice) {
                    return self::narrate_or_blank($e, $voice, true);
                }, $tovoice);
                $questions[$i] = $kept === null ? $q : $kept;
                self::progress(70 + (int) floor((($i + 1) / $n) * 20), '');
            }
        } else {
            self::progress(90, '');
        }

        try {
            $questions = self::timestamp_fallback($questions, $params['transcript']);
        } catch (js_error $e) {
            // The service logs this and carries on.
            unset($e);
        }
        $questions = self::dedupe($questions);
        if (!$questions) {
            $message = 'No questions could be generated. Please try again or provide a more detailed transcript.';
            if (self::$providererror !== null) {
                // Not in the service: on the site's own AI the usual cause is the provider refusing the call.
                $message .= ' Your AI provider said: ' . self::$providererror;
            }
            throw new js_error($message);
        }
        self::progress(90, 'Finalizing...');
        return $questions;
    }

    /**
     * Whether a value is a question of the cardselect type.
     *
     * @param mixed $q
     * @return bool
     */
    protected static function card_type($q): bool {
        return self::prop($q, 'type') === 'cardselect';
    }

    /**
     * Whether a cardselect question has one explanation per card (and so one clip per card).
     *
     * @param mixed $q
     * @param bool $four The regenerate routes check for exactly four explanations instead of one per card.
     * @return bool
     */
    protected static function is_per_card($q, bool $four): bool {
        if (!self::card_type($q) || !self::is_array_js(self::prop($q, 'explanations'))) {
            return false;
        }
        if ($four) {
            return count($q['explanations']) === 4;
        }
        return self::is_array_js(self::prop($q, 'cards')) && count($q['explanations']) === count($q['cards']);
    }

    /**
     * Report progress.
     *
     * @param int|null $progress Percent, or null to keep it.
     * @param string $message Empty to keep it.
     */
    protected static function progress(?int $progress, string $message): void {
        if (self::$progress) {
            (self::$progress)($progress, $message);
        }
    }

    /**
     * buildScenarioContext.
     *
     * @param mixed $country
     * @param mixed $industry
     * @param mixed $subindustry
     * @return string
     */
    protected static function scenario_context($country, $industry, $subindustry): string {
        $parts = [];
        if (self::js_truthy($country)) {
            $parts[] = 'Country: ' . self::js_str($country);
        }
        if (self::js_truthy($industry)) {
            $parts[] = 'Industry: ' . self::js_str($industry);
        }
        if (self::js_truthy($subindustry)) {
            $parts[] = 'Sub-Industry/Specialisation: ' . self::js_str($subindustry);
        }
        if (!$parts) {
            return '';
        }
        $industrylabel = self::js_or(self::js_or($subindustry, $industry), 'the specified industry');
        return ('SCENARIO WORKPLACE CONTEXT:
' . self::js_str(implode('
', array_map([self::class, "js_str"], $parts))) . '

AUTHENTIC SCENARIO GENERATION RULES (CRITICAL):
Scenario questions must feel like they come from a REAL ' . self::js_str($industrylabel) . ' workplace — not from a ' .
        'textbook with the word "' . self::js_str($industry) . '" inserted.

1. SET THE SCENE: Each scenario must name a specific workplace setting (e.g. "emergency department of a regional hospital", ' .
        '"aged care facility during night shift", "construction site during concrete pour"). Do NOT use generic phrases like ' .
        '"a healthcare workplace" or "an industry setting".
2. NAME REAL ROLES: Use actual job titles from ' . self::js_str($industrylabel) . ' (e.g. "enrolled nurse", "site ' .
        'supervisor", "process engineer") — not generic "a worker" or "an employee".
3. USE INDUSTRY EQUIPMENT & TERMINOLOGY: Reference specific tools, equipment, systems, forms, or procedures used in ' .
        self::js_str($industrylabel) . '. Generic terms like "safety equipment" or "workplace procedures" are NOT acceptable.
4. CREATE REALISTIC DILEMMAS: The scenario must present a situation where the learner must make a judgement call specific to ' .
        self::js_str($industrylabel) . ' practice — not a generic workplace question with the industry name attached.
5. REFERENCE REAL REGULATIONS: Where relevant, name actual standards, codes, legislation, or guidelines that apply in ' .
        self::js_str($industrylabel) . self::js_str((self::js_truthy($country) ? (' in ' . self::js_str($country)) : '')) . '.
6. AVOID INDUSTRY NAME-DROPPING: Do NOT simply add "' . self::js_str($industry) . '" or "' . self::js_str($industrylabel) .
        '" to an otherwise generic question. The scenario must be IMPOSSIBLE to understand without ' .
        self::js_str($industrylabel) . ' domain knowledge.

BAD EXAMPLE (industry name-dropped): "In a healthcare setting, what should a worker do when they identify a hazard?"
GOOD EXAMPLE (authentically healthcare): "During a morning medication round on a dementia ward, you notice a patient\'s ' .
        'insulin dose on their chart has been changed overnight but the handover notes don\'t mention it. The patient is due ' .
        'their dose in 10 minutes. What is your most appropriate first action?"');
    }

    /**
     * The cardselect upgrade both narration paths make: one explanation per card, from the single one.
     *
     * @param mixed $q
     * @return mixed
     */
    protected static function upgrade_cardselect($q) {
        $cards = self::prop($q, 'cards');
        if (
            !self::card_type($q) || !self::is_array_js($cards) || count($cards) === 0
            || (self::is_array_js(self::prop($q, 'explanations')) && count($q['explanations']) === count($cards))
        ) {
            return $q;
        }
        $ci = self::prop($q, 'correctIndex');
        $correct = $ci !== null ? self::parse_int(self::js_str($ci)) : 0;
        $explanation = self::prop($q, 'explanation');
        $original = self::js_truthy($explanation) && self::len(self::jtrim(self::js_str($explanation))) > 0
            ? self::jtrim(self::js_str($explanation)) : "That's the right answer.";
        $out = [];
        foreach ($cards as $idx => $card) {
            if (self::strict_eq($idx, $correct)) {
                $out[] = 'Correct. ' . $original;
                continue;
            }
            $label = self::js_truthy($card) && self::js_truthy(self::prop($card, 'label'))
                ? self::jtrim(self::js_str($card['label'])) : 'This option';
            $out[] = 'Incorrect. ' . $label . " isn't quite the right fit here. Have another look at the question and try a "
                . 'different option.';
        }
        $q['explanations'] = $out;
        return $q;
    }

    /**
     * fixCardSelectExplanationOrder: the "Correct." explanation (and its clip) goes with the correct card.
     *
     * @param mixed $question
     * @return array [question, whether a new question came back].
     */
    protected static function fix_card_order($question): array {
        if ($question === null) {
            throw js_error::type("Cannot destructure 'question' as it is null.");
        }
        $cards = self::prop($question, 'cards');
        $ci = self::prop($question, 'correctIndex');
        $explanations = self::prop($question, 'explanations');
        $audio = self::prop($question, 'audioData');
        if (!self::is_array_js($explanations) || !self::is_array_js($cards) || count($explanations) !== count($cards)) {
            return [$question, false];
        }
        $cidx = $ci !== null ? self::parse_int(self::js_str($ci)) : 0;
        if (is_float($cidx) && is_nan($cidx) || $cidx < 0 || $cidx >= count($explanations)) {
            return [$question, false];
        }
        $fixed = $explanations;
        $fixedaudio = self::is_array_js($audio) && count($audio) === count($cards) ? $audio : null;
        $starts = function ($e): bool {
            if ($e === null) {
                return false;
            }
            if (!is_string($e)) {
                throw js_error::type('e?.trim is not a function');
            }
            return strpos(self::lower(self::jtrim($e)), 'correct') === 0;
        };
        if (!$starts($fixed[$cidx])) {
            $found = -1;
            foreach ($fixed as $i => $e) {
                if ($starts($e)) {
                    $found = $i;
                    break;
                }
            }
            if ($found !== -1) {
                [$fixed[$cidx], $fixed[$found]] = [$fixed[$found], $fixed[$cidx]];
                if ($fixedaudio !== null) {
                    [$fixedaudio[$cidx], $fixedaudio[$found]] = [$fixedaudio[$found], $fixedaudio[$cidx]];
                }
            }
        }
        $n = count($explanations);
        for ($i = 0; $i < $n; $i++) {
            if ($i === $cidx) {
                continue;
            }
            $expl = self::js_or($fixed[$i], '');
            if (!is_string($expl)) {
                throw js_error::type('expl.trim is not a function');
            }
            if (strpos(self::lower(self::jtrim($expl)), 'correct') === 0) {
                $card = $cards[$i];
                $label = self::js_truthy($card) && self::js_truthy(self::prop($card, 'label'))
                    ? self::jtrim(self::js_str($card['label'])) : 'This option';
                $body = self::jtrim(preg_replace('/^correct[.:!]?[' . self::WS . ']*/iu', '', $expl, 1));
                $fixed[$i] = 'Incorrect. ' . self::js_str(self::js_or($body, $label . " isn't the right answer for this "
                    . 'question.'));
            }
        }
        $changed = false;
        foreach ($fixed as $i => $e) {
            if (!self::strict_eq($e, $explanations[$i])) {
                $changed = true;
            }
        }
        if ($fixedaudio !== null) {
            foreach ($fixedaudio as $i => $a) {
                if (!self::strict_eq($a, $audio[$i])) {
                    $changed = true;
                }
            }
        }
        if (!$changed) {
            return [$question, false];
        }
        $question['explanations'] = $fixed;
        if ($fixedaudio !== null) {
            $question['audioData'] = $fixedaudio;
        }
        return [$question, true];
    }

    // The question writer: generateVideoActivityQuestions.

    /**
     * Write the questions, in the formats asked for.
     *
     * @param string $text Transcript.
     * @param int|float $count
     * @param string $extrainstructions
     * @param mixed $country
     * @param string $language
     * @param array $selectedformats
     * @return array
     */
    protected static function write_questions(
        string $text,
        $count,
        string $extrainstructions,
        $country,
        string $language,
        array $selectedformats
    ): array {
        $formats = [];
        foreach ($selectedformats as $f) {
            $f = $f === 'truefalse' ? 'cardselect' : $f;
            if (!in_array($f, $formats, true)) {
                $formats[] = $f;
            }
        }
        $valid = ['mcq', 'cardselect', 'matching', 'ordering', 'columnsort', 'categorysort', 'flashcards', 'truefalseswipe',
            'fillinblank'];
        $selectedformats = array_values(array_filter($formats, function ($f) use ($valid) {
            return in_array($f, $valid, true);
        }));

        $voicecountries = ['en-AU' => 'Australia', 'en-GB' => 'United Kingdom', 'en-US' => 'United States',
            'en-SG' => 'Singapore', 'en-NZ' => 'New Zealand'];
        $effectivecountry = self::js_or(self::js_or($country, $voicecountries[$language] ?? null), '');
        $spellinginstructions = self::spelling($effectivecountry);
        $languagename = self::language_name($language);
        $isnonenglish = strpos($language, 'en-') !== 0;
        $languageinstruction = (self::js_truthy($isnonenglish) ? ('

IMPORTANT: Generate ALL content (questions, options, explanations, items, pairs, categories) in ' .
        self::js_str($languagename) . '. The entire response must be in ' . self::js_str($languagename) . ', not English.') :
            '');
        $australianlegislationguardrail = (self::js_truthy(self::js_or(self::js_or(
            ($effectivecountry === 'Australia'),
            self::js_includes($text, 'Australia')
        ), self::js_includes($text, 'AU'))) ? ('
NOTE: If (and ONLY if) the content naturally discusses workplace health and safety legislation, use current Australian ' .
            'terminology: "WHS Act" not "OH&S Act", "PCBU" not "Occupier", "WHS Regulations" not "OH&S Regulations". Do NOT ' .
            'introduce WHS/safety content unless it appears in the source material.
') : '');
        $extrainstructionssection = (self::js_truthy(self::js_and(
            $extrainstructions,
            self::jtrim(self::js_str($extrainstructions))
        )) ? ('=== MANDATORY TEACHER INSTRUCTIONS ===
The teacher has provided the following MANDATORY instructions that you MUST follow:

' . self::js_str(self::jtrim(self::js_str($extrainstructions))) . '
=== END MANDATORY INSTRUCTIONS ===

') : '');

        $formats = $selectedformats ? $selectedformats : ['mcq'];
        $weights = ['mcq' => 2, 'cardselect' => 1, 'matching' => 1, 'ordering' => 1, 'columnsort' => 1, 'categorysort' => 1,
            'flashcards' => 1, 'truefalseswipe' => 1, 'fillinblank' => 1];
        $totalweight = 0;
        foreach ($formats as $f) {
            $totalweight += $weights[$f];
        }
        $formatcounts = [];
        $assigned = 0;
        foreach ($formats as $f) {
            $c = (int) floor($count * $weights[$f] / $totalweight);
            $formatcounts[$f] = $c;
            $assigned += $c;
        }
        $remainder = $count - $assigned;
        if ($remainder > 0) {
            $sorted = array_map(function ($f) use ($count, $weights, $totalweight) {
                return ['f' => $f, 'frac' => fmod($count * $weights[$f] / $totalweight, 1), 'weight' => $weights[$f]];
            }, $formats);
            usort($sorted, function ($a, $b) {
                return ($b['frac'] <=> $a['frac']) ?: ($b['weight'] <=> $a['weight']);
            });
            for ($i = 0; $i < $remainder; $i++) {
                $formatcounts[$sorted[$i % count($sorted)]['f']]++;
            }
        }
        $mcqcount = $formatcounts['mcq'] ?? 0;
        $tfcount = $formatcounts['cardselect'] ?? 0;
        $matchcount = $formatcounts['matching'] ?? 0;
        $ordercount = $formatcounts['ordering'] ?? 0;
        $colsortcount = $formatcounts['columnsort'] ?? 0;
        $catsortcount = $formatcounts['categorysort'] ?? 0;
        $flashcardscount = $formatcounts['flashcards'] ?? 0;
        $truefalseswipecount = $formatcounts['truefalseswipe'] ?? 0;
        $fillinblankcount = $formatcounts['fillinblank'] ?? 0;

        $systemprompt = ('You are a senior learning architect who transforms technical content into assessment items that ' .
            'genuinely change how learners think and act. You have deep expertise in instructional design, cognitive ' .
            'psychology, and workplace performance.

YOUR PHILOSOPHY:
Every question you create must pass the "Monday Morning Test" — would answering this question change what the learner DOES ' .
            'at work on Monday? If not, the question is waste. You create questions that build mental models, reveal hidden ' .
            'consequences, and force learners to confront the difference between what seems right and what IS right.

CONTENT TRANSFORMATION PROCESS:
You do NOT quiz on content. You TRANSFORM content into thinking challenges. The source material is raw input — your job is ' .
            'to extract the deeper logic, the hidden decision points, the consequences that aren\'t obvious, and the ' .
            'competing approaches that create real dilemmas. Then you build questions around THOSE insights.

QUESTION STEM QUALITY RULES:
1. SPECIFICITY: Never write a question that could apply to any topic. "What is the most important factor in X?" is lazy. ' .
            '"When factor A and factor B are both present, which takes priority and why?" is specific.
2. CLARITY: Questions must be written in plain, everyday language. A student watching the video should immediately ' .
            'understand what is being asked. Avoid jargon, complex sentence structures, or academic phrasing.
3. SINGLE CORRECT ANSWER: Every MCQ must have exactly ONE answer that is clearly and unambiguously correct. The three ' .
            'distractors must be clearly wrong to anyone who watched the relevant segment. Never write questions where two ' .
            'options could both be argued as correct.
4. DIRECT AND ANSWERABLE: Questions should directly test whether the student paid attention to the video. The correct answer ' .
            'must be clearly stated or shown in the video content — not require inference, debate, or expert judgement.

WHAT MAKES EACH QUESTION TYPE EXCELLENT:

MCQ — The best MCQs ask a clear, direct question about something the video explains. The correct answer is unambiguously ' .
            'right according to the video content. The three distractors are clearly wrong but relate to the same topic — ' .
            'plausible to someone who hasn\'t watched, but obviously wrong to someone who has. NEVER write MCQs where two ' .
            'options could both be defended as correct.

CARD SELECT — The best card select questions present 4 COMPETING CONCEPTS where the learner must identify which concept is ' .
            'most relevant to a specific situation. Cards should represent genuinely different approaches, not obvious ' .
            'right/wrong.

MATCHING — The best matching questions pair items that REVEAL RELATIONSHIPS the learner hasn\'t explicitly seen. NEVER ' .
            'match terms to definitions — the question stem must name the relationship type (e.g. "Match each decision to ' .
            'its outcome"). Every right-side item must be UNAMBIGUOUS — it must only fit its one specific left-side item, ' .
            'not plausibly match any other. Match CAUSES to CONSEQUENCES, ACTIONS to their DOWNSTREAM EFFECTS, APPROACHES to ' .
            'their REAL-WORLD LIMITATIONS, or DECISIONS to their OUTCOMES. If any right-side item could swap with another ' .
            'and still make sense, the pairs fail — rewrite them.

ORDERING — The best ordering questions present steps where the ORDER MATTERS for a specific reason. Each step\'s position ' .
            'should be non-obvious — the learner must think about dependencies, prerequisites, and logical flow. If anyone ' .
            'could guess the order without knowing the content, it\'s too easy.

COLUMN SORT — The best column sorts present items where CLASSIFICATION REQUIRES JUDGEMENT. Some items should feel like ' .
            'they could go either way — forcing the learner to apply the underlying classification principle rather than ' .
            'relying on surface cues.

CATEGORY SORT — The best category sorts present items that are easily confused across categories. The learner must ' .
            'understand the DEFINING CHARACTERISTIC of each category, not just recognise obvious members. CRITICAL: Items ' .
            'MUST be distributed across ALL categories — every category must have at least 2 items assigned to it. Never ' .
            'cluster all items in one category.

FLASHCARDS — The best flashcards test recall of KEY CONCEPTS, DEFINITIONS, or PRINCIPLES. Each card has a front ' .
            '(question/prompt) and back (answer/explanation). Cards should cover diverse concepts from the content and ' .
            'progressively build understanding.

TRUE/FALSE SWIPE — The best true/false questions present STATEMENTS that sound plausible but require careful analysis. ' .
            'Each statement must be definitively true or false (not ambiguous), with an explanation that teaches the ' .
            'underlying principle. Include statements where the obvious answer is wrong.

FILL IN THE BLANK — The best fill-in-the-blank activities test whether learners can recall KEY TERMINOLOGY in context. The ' .
            'passage should contain blanks for critical terms, and the word bank should include plausible distractors that ' .
            'test understanding of similar concepts.

ANTI-PATTERNS (never do these):
- "All of the above" / "None of the above"
- Absolute qualifiers ("always", "never") as answer traps
- Negatively phrased stems ("Which is NOT…")
- Direct definition recall questions
- Copying phrasing from the content
- Obviously wrong distractors that no one would choose
- Generic comprehension questions that could apply to any topic
- Questions answerable by scanning for sentence matches

COGNITIVE DEPTH RULE:
All questions must require cognitive effort beyond surface recall. The student must interpret, apply judgement, or reason ' .
            'through the content.
If Bloom Level >= 3: Questions must be cognitively demanding. For SCENARIO-type questions, this means beginning with a ' .
            'realistic workplace situation with enough detail to require interpretation. For APPLICATION-type questions, ' .
            'this means requiring analysis, comparison, evaluation, or synthesis — not just identifying the "right answer" ' .
            'from obvious options.
If Bloom Level 1-2: Questions may assess understanding or interpretation but must still avoid direct content copying.

DISTRACTOR ENGINEERING (MCQs and Card Select):
Each incorrect option must:
- Represent a NAMED cognitive error (e.g., "confusing correlation with causation", "overgeneralising from a single case", ' .
            '"prioritising speed over safety")
- Be plausible enough that someone with partial knowledge would choose it
- Be similar in length, grammar, and specificity to the correct answer
- Be grounded in something mentioned or implied by the content

CONTENT USAGE RULE:
- Ground all ideas in the provided content — but go DEEPER than the content goes
- Extract the LOGIC and PRINCIPLES behind what the content says
- Avoid reusing more than 6 consecutive words from the content
- Explanations may enrich with relevant research, statistics, or evidence to deepen learning

SELF-VALIDATION (apply to every question before output):
1. Would answering this change what the learner does at work? If no → rewrite
2. Could someone answer this without understanding the underlying principle? If yes → rewrite
3. Does the explanation teach something the learner didn\'t already know? If no → rewrite
4. Could this question/explanation apply to ANY topic? If yes → too generic, rewrite
5. Is every distractor something a real person might believe? If no → rewrite
6. MATCHING ONLY: Could any right-side item plausibly match a different left-side item? If yes → the pairs are ambiguous ' .
            '— rewrite until each right-side item has only ONE correct match.
7. FILL-IN-BLANK ONLY: Do the numbered blanks (___1___, ___2___) match the order in the blanks array? Verify blank 1 = ' .
            'blanks[0].answer, blank 2 = blanks[1].answer, etc.
8. FILL-IN-BLANK ONLY: Does the passage end with a complete sentence — NOT "..." or a mid-sentence cut-off? If the passage ' .
            'ends with "...", REWRITE it with a proper ending before returning.
9. SPELLING: Does every item use the spelling and vocabulary specified in the LANGUAGE REQUIREMENTS above? If not, correct ' .
            'it before returning.
10. MCQ AND CARD SELECT ONLY: Read all 4 options/cards simultaneously. Could a student make a reasonable argument that more ' .
            'than ONE is correct? If yes — there is no clear winner. Rewrite the question stem and/or the options/cards ' .
            'until only ONE answer is unambiguously correct. This is the most common source of student frustration — never ' .
            'leave it ambiguous.

Return only valid JSON.');
        $truncatedtext = self::len($text) > 30000 ? self::slice($text, 0, 30000) . "\n\n[Content truncated...]" : $text;
        $prompt = (self::js_str($extrainstructionssection) . 'TASK: Generate exactly ' . self::js_str($count) . ' high-qualit' .
            'y assessment items that transform the provided content into genuine thinking challenges.

STEP 1 — DEEP CONTENT ANALYSIS + DISTRIBUTION MAPPING (Internal Only — Do Not Output):
Before generating questions, deeply analyse the content and do TWO things:

A) DIVIDE THE CONTENT: Mentally split the transcript/content into ' . self::js_str($count) . ' approximately equal segments ' .
        'from start to finish. Label them Segment 1 (opening), Segment 2, ... Segment ' . self::js_str($count) .
            ' (closing). ' .
        'Each question you generate MUST draw its core concept from a different segment. Questions must progress through the ' .
        'content — question 1 from Segment 1, question ' . self::js_str($count) . ' from Segment ' . self::js_str($count) .
            '. NEVER generate multiple questions from the opening segment while the closing segments go untested.

B) IDENTIFY KEY FACTS from each segment:
- KEY FACTS: What specific facts, numbers, names, or definitions does this segment state?
- KEY STEPS: What processes or sequences does this segment describe?
- KEY CONCEPTS: What is the main idea being explained in this segment?
- CLEAR ANSWERS: What questions could someone answer correctly ONLY if they watched this segment?
Use these clearly-stated facts and concepts as the basis for your questions. Each question must have one obvious correct ' .
            'answer that anyone who watched that segment would know.

STEP 2: Generate questions that test these key facts and concepts, one question per segment, progressing through the content ' .
            'from start to finish.

=== SOURCE CONTENT ===
' . self::js_str($truncatedtext) . '
=== END SOURCE CONTENT ===
' . self::js_str($spellinginstructions) . self::js_str($languageinstruction) . '
' . self::js_str($australianlegislationguardrail) . '

COGNITIVE ENFORCEMENT (apply to every question before outputting):
1. Does this question test a DECISION, TRADE-OFF, CONSEQUENCE, or PRINCIPLE — not just a fact?
2. Could someone answer this without understanding the underlying logic? If yes, rewrite with more depth.
3. Are all distractors things a real person with partial knowledge might believe?
4. Does the explanation teach something the learner didn\'t already know — a principle, evidence, consequence, or ' .
        'statistic? If it just summarises the exercise, it FAILS — rewrite it.
5. Is the explanation at least 2-3 sentences with substantive, topic-specific content?
6. Would answering this question change what the learner does at work? If no, the question is too academic — rewrite it.
7. FOR MATCHING: Read each right-side item in isolation. Could it plausibly match ANY other left-side item? If yes — the ' .
        'pair is ambiguous. Rewrite the right-side item to be unambiguously specific to its left-side partner.
If any check fails, regenerate the question internally before returning.

QUESTION TYPE DISTRIBUTION (generate exactly ' . self::js_str($count) . ' questions total):
' . self::js_str((self::js_truthy(($mcqcount > 0)) ? ('- ' . self::js_str($mcqcount) . ' MCQ (multiple choice with 4 options)
') : '')) . self::js_str((self::js_truthy(($tfcount > 0)) ? ('- ' . self::js_str($tfcount) . ' Card Select (4 visual cards, ' .
        'pick the correct one)
') : '')) . self::js_str((self::js_truthy(($matchcount > 0)) ? ('- ' . self::js_str($matchcount) . ' Matching (exactly 4 ' .
        'pairs each)
') : '')) . self::js_str((self::js_truthy(($ordercount > 0)) ? ('- ' . self::js_str($ordercount) . ' Ordering (exactly 4-6 ' .
        'items each)
') : '')) . self::js_str((self::js_truthy(($colsortcount > 0)) ? ('- ' . self::js_str($colsortcount) . ' Column Sort ' .
        '(contextual column labels with 4-6 items)
') : '')) . self::js_str((self::js_truthy(($catsortcount > 0)) ? ('- ' . self::js_str($catsortcount) . ' Category Sort (3 ' .
        'categories with 2-3 items each)
') : '')) . self::js_str((self::js_truthy(($flashcardscount > 0)) ? ('- ' . self::js_str($flashcardscount) . ' Flashcards ' .
        '(4-6 cards with front/back)
') : '')) . self::js_str((self::js_truthy(($truefalseswipecount > 0)) ? ('- ' . self::js_str($truefalseswipecount) . ' True/F' .
        'alse Swipe (4-6 statements)
') : '')) . self::js_str((self::js_truthy(($fillinblankcount > 0)) ? ('- ' . self::js_str($fillinblankcount) . ' Fill in the ' .
        'Blank (passage with 3-5 blanks + word bank)
') : '')) . '
' . self::js_str((self::js_truthy((self::js_len($formats) > 1)) ? 'Mix the question types throughout — do NOT group all of ' .
        'one type together.' : 'Generate ALL questions as the specified type.')) . '

QUESTION TYPE SPECIFICATIONS:
' . self::js_str((self::js_truthy(self::js_includes($formats, 'mcq')) ? ('
MCQ ("type": "mcq"):
   - 4 options, exactly 1 correct answer — CRITICAL: Exactly ONE option is unambiguously correct. Never write 2 or more ' .
        'options that could both be defended as right. Each wrong option must be clearly incorrect when the content is fully ' .
        'understood, even if it sounds plausible at first. If you catch yourself thinking "well, it depends", the question ' .
            'must ' .
        'be rewritten.
   - correctAnswer is the 0-based index of the correct option
   - explanations array has 4 entries matching the 4 options — correct starts with "Correct.", wrong ones start with ' .
        '"Incorrect."
   - Options must be homogeneous: same grammar, similar length
   - QUESTION STYLE: Write plain, simple questions a student can easily understand. Ask directly about something the video ' .
        'clearly explains — "What did the video say about X?", "According to the video, what is Y?", "What is the first ' .
            'step ' .
        'in Z?". Avoid abstract reasoning, edge cases, or trade-offs.
   - DISTRACTORS: The three wrong options must be clearly incorrect to anyone who watched the video, but plausible enough to ' .
        'guess without watching. Each distractor should be a factually wrong statement about the same topic — a different ' .
        'number, a reversed fact, a common misconception, or an incorrect alternative. NEVER write a distractor that is ' .
        'partially correct or could be defended as right in any context.
   - LANGUAGE: Keep all options short and parallel — same grammar, similar length, plain English. No jargon or complex ' .
        'phrasing in any option.
   - MUTUAL EXCLUSIVITY TEST (mandatory before returning): Read all 4 options together. Ask yourself: "Could a student ' .
        'reasonably argue that option B, C, or D is also correct?" If yes — rewrite the distractors until only ONE option ' .
            'is ' .
        'unambiguously the right answer. Students must NEVER feel like multiple answers could both be correct. If the ' .
            'correct ' .
        'answer is "all of the above" or depends on context, the question must be rewritten with a clearer, more specific ' .
            'stem.
') : '')) . self::js_str((self::js_truthy(self::js_includes($formats, 'cardselect')) ? ('
CARD SELECT ("type": "cardselect"):
   - Present a question where the learner picks the correct card from 4 visual cards
   - Each card has: "icon" (one of: shield, book, lightbulb, gear, heart, star, check, warning, clock, target, users, tool, ' .
        'flag, eye, lock), "label" (short 2-5 word title), "description" (1 sentence explaining the concept)
   - correctIndex is the 0-based index of the correct card (0-3)
   - explanations is an array of EXACTLY 4 strings, one per card, indexed positionally to match cards. The card at ' .
        'correctIndex MUST have its explanation start with "Correct." and explain why it\'s the right answer; the other 3 ' .
            'cards ' .
        'MUST have explanations starting with "Incorrect." and explain why each card is wrong while pointing the learner ' .
            'toward ' .
        'the correct concept (without stating the correct card\'s label outright in the wrong-card explanations — let the ' .
        'learner figure it out from the reasoning).
   - QUALITY: Cards must represent 4 GENUINELY COMPETING concepts — not "obviously right + 3 obviously wrong." The ' .
        'question should force the learner to think about which concept is MOST relevant, not just recognise the correct one.
   - DISTINCT CARDS: Each card\'s label and description must describe a clearly DIFFERENT concept from the other 3 cards. If ' .
        'two cards describe essentially the same idea at different levels of generality, rewrite them. Students who read all ' .
            '4 ' .
        'cards must never think "these two mean the same thing."
   - CARD MUTUAL EXCLUSIVITY TEST: After writing all 4 cards, ask: "Could a student argue that more than one card is the ' .
        'correct answer?" If yes, rewrite the question stem to be more specific, or rewrite the cards so only one card is ' .
            'the ' .
        'correct answer to this particular question.
   - Choose icons that visually represent each card\'s concept
') : '')) . self::js_str((self::js_truthy(self::js_includes($formats, 'matching')) ? ('
MATCHING ("type": "matching"):
   - Provide exactly 4 pairs of related items
   - Each pair has "left" and "right" fields (left: 3-8 words, right: 3-10 words)
   - The "question" field MUST name the relationship type — e.g. "Match each risk factor to its consequence", "Match each ' .
        'action to its downstream effect" — NEVER write "Match the terms with their definitions"
   - COHERENCE RULE (CRITICAL): Every right-side item must ONLY logically fit its ONE specific left-side item. Test this: ' .
        'could a learner reasonably put right item X with left item Y? If yes — the pairs are ambiguous — rewrite until ' .
            'each ' .
        'right item is unambiguous.
   - RIGHT-SIDE ITEMS must be SHORT (3-10 words), SPECIFIC and DISTINCTIVE from each other — similar enough that confusion ' .
        'is only possible on content knowledge, but each clearly belonging to only one left item.
   - QUALITY: NEVER match terms to textbook definitions. Match CAUSES to CONSEQUENCES, ACTIONS to their DOWNSTREAM EFFECTS, ' .
        'APPROACHES to their REAL-WORLD LIMITATIONS, or DECISIONS to their OUTCOMES. Every pair must reveal a relationship ' .
            'that ' .
        'requires understanding the content to get right.
') : '')) . self::js_str((self::js_truthy(self::js_includes($formats, 'ordering')) ? ('
ORDERING ("type": "ordering"):
   - Provide 4-6 items that have a logical, definitive order
   - Items array must be in the CORRECT order
   - QUALITY: The order must be NON-OBVIOUS. If someone could guess the correct order without knowing the content, it\'s too ' .
        'easy. Each step\'s position should depend on understanding WHY it comes before or after another step. Include at ' .
            'least ' .
        'one pair of steps where the order is counterintuitive or commonly reversed.
') : '')) . self::js_str((self::js_truthy(self::js_includes($formats, 'columnsort')) ? ('
COLUMN SORT ("type": "columnsort"):
   - Two columns with contextual labels (not just "Good / Bad" — use topic-specific labels like "Immediate Priority / ' .
        'Delayed Follow-up" or "Evidence-Based / Common Misconception")
   - 4-6 items, each assigned to column "A" or "B"
   - columnA and columnB are the column labels
   - QUALITY: At least 2 items should be AMBIGUOUS-SEEMING — they look like they could go either way, forcing the learner ' .
        'to apply the classification principle rather than relying on surface cues. Avoid items that are obviously in one ' .
            'column.
') : '')) . self::js_str((self::js_truthy(self::js_includes($formats, 'categorysort')) ? ('
CATEGORY SORT ("type": "categorysort"):
   - Exactly 3 categories with topic-specific names (not generic like "Type 1, Type 2, Type 3")
   - 6-9 items total; the "category" field on each item MUST be the exact string name of one of the categories (NOT a number)
   - CRITICAL DISTRIBUTION RULE: Items MUST be spread across ALL 3 categories. Every category MUST have at least 2 items. ' .
        'NEVER assign all or most items to the same category. A valid distribution example: 3 items in category 1, 3 items ' .
            'in ' .
        'category 2, 3 items in category 3. An INVALID distribution: 6 items in one category and 0 or 1 in others.
   - QUALITY: Items should be EASILY CONFUSED across categories. The learner must understand the DEFINING CHARACTERISTIC of ' .
        'each category to sort correctly. Include at least 2 items that naive learners would put in the wrong category.
') : '')) . self::js_str((self::js_truthy(self::js_includes($formats, 'flashcards')) ? ('
FLASHCARDS ("type": "flashcards"):
   - 4-6 flashcards, each with a "front" (a short recall question testing a key concept) and "back" (its answer/explanation)
   - Front MUST be a SHORT RECALL QUESTION — e.g. "What is a hazard?" or "What does PPE stand for?". Keep it concise ' .
        '(under 10 words).
   - Back MUST be the answer — clear and complete, explaining what it means and why it matters in practice (1-2 complete ' .
        'sentences, minimum 40 characters)
   - Cards should cover diverse KEY CONCEPTS from the content, not repeating the same concept twice
   - QUALITY: Each question should test a DIFFERENT concept. Focus on terminology and principles that matter for real-world ' .
        'application. Always phrase fronts as questions (starting with What, How, Why, When, or Who).
') : '')) . self::js_str((self::js_truthy(self::js_includes($formats, 'truefalseswipe')) ? ('
TRUE/FALSE SWIPE ("type": "truefalseswipe"):
   - 4-6 statements, each with "text" (the statement), "correct" (boolean true/false), and "explanation" (why it\'s true or ' .
        'false)
   - Statements must be definitively true or false — no ambiguity
   - Mix of true and false statements (roughly balanced)
   - QUALITY: Include statements where the obvious answer is WRONG. At least 2 statements should be counterintuitive or ' .
        'require careful reasoning.
   - SPELLING: Use the exact spelling and vocabulary required by the language/spelling instructions above. Apply those rules ' .
        'to every word in every statement and explanation.
') : '')) . self::js_str((self::js_truthy(self::js_includes($formats, 'fillinblank')) ? ('
FILL IN THE BLANK ("type": "fillinblank"):
   - A "text" field containing a passage with numbered placeholders (___1___, ___2___, etc.)
   - A "blanks" array where each entry has an "answer" field (the correct word/phrase)
   - A "distractors" array with 2-3 plausible but incorrect words
   - 3-5 blanks per passage
   - COMPLETE PASSAGE (CRITICAL): The passage in the "text" field MUST be a fully complete piece of writing. NEVER end a ' .
        'sentence mid-way. NEVER end the passage with "..." or any ellipsis. Every sentence must be grammatically complete. ' .
            'If ' .
        'you run out of blanks before the end of the passage, that is fine — just finish the sentence properly.
   - BLANK VISIBILITY: Each blank placeholder (___1___, ___2___, etc.) must appear naturally mid-sentence so the student can ' .
        'clearly see which term is missing from context.
   - QUALITY: Blanks should target KEY TERMINOLOGY that demonstrates understanding. Distractors should be similar enough to ' .
        'be tempting but clearly wrong in context.
') : '')) . '

EXPLANATION QUALITY RULES (CRITICAL — applies to ALL question types):
Every explanation must teach the learner something they didn\'t know before. The learner has already answered the question ' .
        '— they don\'t need a summary of what they just did. They need to understand WHY it matters.

REQUIRED EXPLANATION STRUCTURE (use 2-4 of these in each explanation):
1. THE UNDERLYING PRINCIPLE: Explain the core principle or theory behind the correct answer. What mental model should the ' .
        'learner walk away with?
2. THE "WHY IT WORKS" REASON: Give a psychological, scientific, or evidence-based reason why this approach is effective. ' .
        'E.g. "Research shows that..." or "This works because the human brain..."
3. THE REAL-WORLD CONSEQUENCE: What happens in practice when this is done right vs. wrong? Use concrete outcomes, not ' .
        'abstract statements.
4. THE SURPRISING INSIGHT: Share a counterintuitive fact, a common misconception, or a statistic that deepens understanding. ' .
        'E.g. "Studies show that teams with X are 40% more likely to..."
5. THE TRANSFERABLE TAKEAWAY: State a principle the learner can apply beyond this specific question. Frame it as actionable ' .
        'wisdom.

BANNED EXPLANATION PATTERNS (never use these):
- "This exercise helps you understand..." (meta-commentary about the exercise itself)
- "This sorting/matching activity clarifies..." (describing what the exercise does)
- "By categorising these items, you can see..." (narrating the learner\'s own actions back to them)
- "The correct answer is X because X is correct" (circular reasoning)
- Any explanation that could apply to ANY question on ANY topic (too generic)
- Any explanation shorter than 2 sentences

BAD EXPLANATION: "This sorting exercise clarifies the impact of different leadership behaviours on team morale and ' .
        'effectiveness."
GOOD EXPLANATION: "Recognising achievements and offering constructive feedback activate intrinsic motivation — research by ' .
        'Deci & Ryan shows that autonomy, competence, and relatedness are the three psychological needs that drive sustained ' .
        'engagement. In contrast, ignoring concerns and assigning blame trigger \'threat responses\' in the brain\'s ' .
            'amygdala, ' .
        'causing team members to disengage or become defensive. Leaders who consistently use positive reinforcement see up ' .
            'to ' .
        '31% higher productivity (Gallup, 2022)."

QUALITY REQUIREMENTS:
- Every question must be answerable using ONLY information in the content
- Cover diverse concepts from the content — avoid clustering around one idea
- Ensure increasing variation in cognitive demand across questions
- Explanations MUST teach — include principles, evidence, consequences, or statistics (see EXPLANATION QUALITY RULES above)
- No transcript echoing — abstract and rephrase, don\'t copy
- No generic comprehension questions — every question must require thought
- NEVER reference the source material ("According to the video", "The document states", etc.)
- NEVER write meta-commentary about the exercise itself in explanations

TIMESTAMP FIELD (REQUIRED — every question of every type, no exceptions): EVERY question object you return MUST include a ' .
        '"timestamp_seconds" field. This is non-negotiable — MCQ, Card Select, Matching, Ordering, Column Sort, Category ' .
            'Sort, ' .
        'Flashcards, True/False, Fill in the Blank — all of them. The schema example below shows "timestamp_seconds" ' .
            'inside ' .
        'every question type\'s object specifically so you do not forget any of them. If you omit this field on any ' .
            'question, ' .
        'the "Jump to X:XX" chapter link will silently break for that question on the student\'s screen.
Value rules: integer (seconds since video start) when the source contains YouTube-style timestamps (e.g. "0:08", "1:09", ' .
        '"2:36"); null only when the transcript contains no timestamps at all. Find the timestamp of the line in the ' .
            'transcript ' .
        'where the question\'s topic BEGINS to be discussed — this is the point a student should jump to in order to watch ' .
            'and ' .
        'find the answer. Convert to total seconds ("1:09" = 69, "2:36" = 156). Use the line that introduces the relevant ' .
        'concept, not a line before or after it. ACCURACY IS CRITICAL: the timestamp must correspond to the actual moment in ' .
            'the ' .
        'video where this topic starts — a student clicking "Jump to" must land in the correct section. SPREAD: Every ' .
            'question ' .
        'must reference a DIFFERENT part of the video — each question\'s topic comes from a different segment, so each ' .
        'timestamp should be distinct. Never assign the same timestamp to two different questions unless the transcript ' .
        'genuinely only covers a single narrow time range.

Return JSON in this exact format:
{
  "questions": [
' . self::js_str((self::js_truthy(self::js_includes($formats, 'mcq')) ? ('    {
      "type": "mcq",
      "question": "Question stem?",
      "options": ["Option A", "Option B", "Option C", "Option D"],
      "correctAnswer": 0,
      "timestamp_seconds": 69,
      "explanations": ["Correct. [Explain WHY this is right — what the video says about this topic and why this answer ' .
        'matters. 2 sentences.]", "Incorrect. [Explain simply why this option is wrong — what the video actually says ' .
            'instead. ' .
        '1-2 sentences.]", "Incorrect. [Explain simply why this option is wrong — what the video actually says instead. ' .
            '1-2 ' .
        'sentences.]", "Incorrect. [Explain simply why this option is wrong — what the video actually says instead. 1-2 ' .
        'sentences.]"]
    },') : '')) . '
' . self::js_str((self::js_truthy(self::js_includes($formats, 'cardselect')) ? ('    {
      "type": "cardselect",
      "question": "Which concept best describes...?",
      "cards": [
        {"icon": "shield", "label": "Card A Title", "description": "Brief description of concept A"},
        {"icon": "book", "label": "Card B Title", "description": "Brief description of concept B"},
        {"icon": "lightbulb", "label": "Card C Title", "description": "Brief description of concept C"},
        {"icon": "gear", "label": "Card D Title", "description": "Brief description of concept D"}
      ],
      "correctIndex": 0,
      "timestamp_seconds": 69,
      "explanations": ["Correct. [Explain why this card is the right answer — include the principle and one piece of ' .
        'evidence or consequence. 2 sentences.]", "Incorrect. [Explain why this specific card is wrong without naming the ' .
        'correct card. 1-2 sentences.]", "Incorrect. [Explain why this specific card is wrong without naming the correct ' .
            'card. ' .
        '1-2 sentences.]", "Incorrect. [Explain why this specific card is wrong without naming the correct card. 1-2 ' .
        'sentences.]"]
    },') : '')) . '
' . self::js_str((self::js_truthy(self::js_includes($formats, 'matching')) ? ('    {
      "type": "matching",
      "question": "Match each [cause/action/factor] to its [consequence/effect/outcome]",
      "pairs": [
        {"left": "Skipping the initial risk identification step", "right": "Uncontrolled exposure to a known hazard"},
        {"left": "Applying the approach without contextual adjustment", "right": "Outcome opposite to what was intended"},
        {"left": "Ignoring early warning indicators in the process", "right": "Escalation to a preventable systemic ' .
        'failure"},
        {"left": "Prioritising speed over established protocol", "right": "A critical vulnerability left permanently ' .
        'unaddressed"}
      ],
      "timestamp_seconds": 156,
      "explanation": "[Explain the cause-effect principle connecting these pairs — why these consequences follow from ' .
        'these actions, include a real-world outcome or research-backed insight. 2-3 sentences minimum. Do NOT say \'this ' .
        'matching exercise helps you understand...\'.]"
    },') : '')) . '
' . self::js_str((self::js_truthy(self::js_includes($formats, 'ordering')) ? ('    {
      "type": "ordering",
      "question": "Put these steps in the correct order",
      "items": ["Step 1", "Step 2", "Step 3", "Step 4"],
      "timestamp_seconds": 210,
      "explanation": "[Explain WHY this sequence matters — what principle drives this order, what goes wrong if steps are ' .
        'swapped, include evidence or a real-world consequence. 2-3 sentences minimum. Do NOT say \'this ordering exercise ' .
        'clarifies...\'.]"
    },') : '')) . '
' . self::js_str((self::js_truthy(self::js_includes($formats, 'columnsort')) ? ('    {
      "type": "columnsort",
      "question": "Sort these into the correct categories",
      "columnA": "Good Practice",
      "columnB": "Bad Practice",
      "items": [
        {"text": "Item 1", "column": "A"},
        {"text": "Item 2", "column": "B"},
        {"text": "Item 3", "column": "A"},
        {"text": "Item 4", "column": "B"}
      ],
      "timestamp_seconds": 245,
      "explanation": "[Explain the principle that separates these categories — why certain practices work and others ' .
        'don\'t, include research or real-world outcomes. 2-3 sentences minimum. Do NOT say \'this sorting exercise ' .
        'clarifies...\'.]"
    },') : '')) . '
' . self::js_str((self::js_truthy(self::js_includes($formats, 'categorysort')) ? ('    {
      "type": "categorysort",
      "question": "Sort these items into the correct categories",
      "categories": ["Category 1", "Category 2", "Category 3"],
      "items": [
        {"text": "Item 1", "category": "Category 1"},
        {"text": "Item 2", "category": "Category 2"},
        {"text": "Item 3", "category": "Category 3"},
        {"text": "Item 4", "category": "Category 1"}
      ],
      "timestamp_seconds": 280,
      "explanation": "[Explain the principle that defines these categories — why these distinctions matter, include ' .
        'evidence, a statistic, or a real-world consequence. 2-3 sentences minimum. Do NOT say \'this categorisation ' .
            'exercise ' .
        'helps you see...\'.]"
    },') : '')) . '
' . self::js_str((self::js_truthy(self::js_includes($formats, 'flashcards')) ? ('    {
      "type": "flashcards",
      "question": "Review these key concepts",
      "title": "Key Concepts Review",
      "cards": [
        {"front": "What is [Key Term A]?", "back": "The answer explaining what Term A means, why it matters, and how it ' .
        'applies in practice (1-2 sentences)."},
        {"front": "What does [Key Term B] involve?", "back": "The answer explaining what Term B involves — its key ' .
        'components and real-world significance (1-2 sentences)."},
        {"front": "Why is [Key Term C] important?", "back": "The answer explaining why Term C matters — the principle it ' .
        'represents and how it connects to practice (1-2 sentences)."},
        {"front": "How does [Key Term D] work?", "back": "The answer explaining how Term D works — including any critical ' .
        'nuance learners must understand (1-2 sentences)."}
      ],
      "timestamp_seconds": 320,
      "explanation": "[Brief overall explanation of why these concepts matter. 2-3 sentences.]"
    },') : '')) . '
' . self::js_str((self::js_truthy(self::js_includes($formats, 'truefalseswipe')) ? ('    {
      "type": "truefalseswipe",
      "question": "Determine whether each statement is true or false",
      "title": "True or False",
      "statements": [
        {"text": "Statement that sounds true but is actually false", "correct": false, "explanation": "This is false ' .
        'because... [teach the principle]"},
        {"text": "Statement that is genuinely true", "correct": true, "explanation": "This is true because... [deepen ' .
        'understanding]"},
        {"text": "Another statement to evaluate", "correct": true, "explanation": "Correct — this is true because..."},
        {"text": "Tricky false statement", "correct": false, "explanation": "This is a common misconception. In reality..."}
      ],
      "timestamp_seconds": 360,
      "explanation": "[Overall explanation connecting these concepts. 2-3 sentences.]"
    },') : '')) . '
' . self::js_str((self::js_truthy(self::js_includes($formats, 'fillinblank')) ? ('    {
      "type": "fillinblank",
      "question": "Complete the passage by filling in the missing terms",
      "title": "Fill in the Blank",
      "text": "In the context of [topic], ___1___ is essential for achieving ___2___. Without proper ___3___, organisations ' .
        'risk...",
      "blanks": [
        {"answer": "correct term 1"},
        {"answer": "correct term 2"},
        {"answer": "correct term 3"}
      ],
      "distractors": ["plausible wrong term 1", "plausible wrong term 2"],
      "timestamp_seconds": 405,
      "explanation": "[Explain why these terms are important in context. 2-3 sentences.]"
    },') : '')) . '
  ]
}');
        $budget = (int) max(8000, $count * 600);
        $parsed = self::safe_parse($systemprompt, $prompt, $budget, 'videoactivity-mixed (' . self::len($text) . ' chars)');
        if (!self::js_truthy($parsed) || !self::is_array_js(self::prop($parsed, 'questions'))) {
            throw new js_error("AI response missing 'questions' array");
        }

        $validquestions = [];
        foreach ($parsed['questions'] as $q) {
            $q = self::check_question($q, $ok);
            if ($ok) {
                $validquestions[] = $q;
            }
        }
        if (!$validquestions) {
            throw new js_error('No valid questions in AI response');
        }
        $processed = [];
        foreach ($validquestions as $q) {
            if ($q['type'] === 'mcq') {
                $q = self::shuffle(self::fix_explanation_order($q));
            } else if ($q['type'] === 'cardselect') {
                $q = self::fix_card_order($q)[0];
            }
            $processed[] = $q;
        }

        $seen = [];
        $enforced = [];
        $used = [];
        foreach ($processed as $idx => $q) {
            $desired = $formatcounts[$q['type']] ?? 0;
            $sofar = $seen[$q['type']] ?? 0;
            if ($sofar < $desired) {
                $enforced[] = $q;
                $used[$idx] = true;
                $seen[$q['type']] = $sofar + 1;
            }
        }
        if (count($enforced) < $count) {
            foreach ($processed as $idx => $q) {
                if (count($enforced) >= $count) {
                    break;
                }
                if (empty($used[$idx])) {
                    $enforced[] = $q;
                    $used[$idx] = true;
                }
            }
        }
        return array_slice($enforced, 0, max(0, (int) $count));
    }

    /**
     * The service's per-question check and tidy-up of one AI question (the filter in generateVideoActivityQuestions).
     *
     * @param mixed $q
     * @param bool|null $ok Set to whether the question is kept.
     * @return mixed The question, tidied.
     */
    protected static function check_question($q, &$ok) {
        $ok = false;
        $question = self::prop($q, 'question');
        if (!self::js_truthy($question) || !is_string($question)) {
            return $q;
        }
        $type = self::prop($q, 'type');
        if (!self::js_truthy($type) || !is_string($type)) {
            return $q;
        }
        $type = self::lower($type);
        $q['type'] = $type;
        switch ($type) {
            case 'mcq':
                $options = $q['options'] ?? null;
                if (!self::js_truthy($options) || !self::is_array_js($options) || count($options) < 4) {
                    return $q;
                }
                if (count($options) > 4) {
                    $ca = $q['correctAnswer'] ?? null;
                    $correct = (is_int($ca) || is_float($ca)) && $ca >= 0 && $ca < count($options) ? $ca : 0;
                    $kept = [$correct];
                    for ($k = 0; $k < count($options) && count($kept) < 4; $k++) {
                        if (!self::strict_eq($k, $correct)) {
                            $kept[] = $k;
                        }
                    }
                    usort($kept, function ($a, $b) {
                        return $a <=> $b;
                    });
                    $q['options'] = array_map(function ($k) use ($options) {
                        return self::index_get($options, $k);
                    }, $kept);
                    $exp = $q['explanations'] ?? null;
                    $q['explanations'] = self::is_array_js($exp) ? array_map(function ($k) use ($exp) {
                        return self::js_or(self::index_get($exp, $k), '');
                    }, $kept) : null;
                    $pos = -1;
                    foreach ($kept as $i => $k) {
                        if (self::strict_eq($k, $correct)) {
                            $pos = $i;
                            break;
                        }
                    }
                    $q['correctAnswer'] = $pos;
                }
                if (
                    !array_key_exists('correctAnswer', $q) || self::js_lt($q['correctAnswer'], 0)
                        || self::js_gt($q['correctAnswer'], 3)
                ) {
                    $q['correctAnswer'] = 0;
                }
                $exp = $q['explanations'] ?? null;
                if (!self::js_truthy($exp) || !self::is_array_js($exp) || count($exp) < 4) {
                    $exp = [];
                    foreach ($q['options'] as $i => $o) {
                        $exp[] = self::strict_eq($i, $q['correctAnswer']) ? 'Correct.' : 'Incorrect.';
                    }
                }
                $q['explanations'] = array_slice($exp, 0, 4);
                $ok = true;
                return $q;

            case 'cardselect':
                $cards = $q['cards'] ?? null;
                if (!self::js_truthy($cards) || !self::is_array_js($cards) || count($cards) < 2) {
                    return $q;
                }
                $q['cards'] = array_map(function ($c) {
                    return ['icon' => self::js_or(self::prop($c, 'icon'), 'star'),
                        'label' => self::js_or(self::prop($c, 'label'), 'Option'),
                        'description' => self::js_or(self::prop($c, 'description'), '')];
                }, array_slice($cards, 0, 4));
                if (
                    !array_key_exists('correctIndex', $q) || self::js_lt($q['correctIndex'], 0)
                        || self::js_ge($q['correctIndex'], count($q['cards']))
                ) {
                    $q['correctIndex'] = 0;
                }
                self::default_explanation($q);
                $ok = true;
                return $q;

            case 'matching':
                $pairs = $q['pairs'] ?? null;
                if (!self::js_truthy($pairs) || !self::is_array_js($pairs) || count($pairs) < 2) {
                    return $q;
                }
                $q['pairs'] = array_slice(array_values(array_filter($pairs, function ($p) {
                    return self::js_truthy(self::prop($p, 'left')) && self::js_truthy(self::prop($p, 'right'));
                })), 0, 4);
                if (count($q['pairs']) < 2) {
                    return $q;
                }
                self::default_explanation($q);
                $ok = true;
                return $q;

            case 'ordering':
                $items = $q['items'] ?? null;
                if (!self::js_truthy($items) || !self::is_array_js($items) || count($items) < 3) {
                    return $q;
                }
                $q['items'] = array_slice($items, 0, 6);
                self::default_explanation($q);
                $ok = true;
                return $q;

            case 'columnsort':
                $items = $q['items'] ?? null;
                if (!self::js_truthy($items) || !self::is_array_js($items) || count($items) < 3) {
                    return $q;
                }
                $q['items'] = array_slice(array_values(array_filter($items, function ($item) {
                    $column = self::prop($item, 'column');
                    return self::js_truthy(self::prop($item, 'text')) && ($column === 'A' || $column === 'B');
                })), 0, 6);
                if (count($q['items']) < 3) {
                    return $q;
                }
                if (!self::js_truthy($q['columnA'] ?? null)) {
                    $q['columnA'] = 'Column A';
                }
                if (!self::js_truthy($q['columnB'] ?? null)) {
                    $q['columnB'] = 'Column B';
                }
                self::default_explanation($q);
                $ok = true;
                return $q;

            case 'categorysort':
                $categories = $q['categories'] ?? null;
                if (!self::js_truthy($categories) || !self::is_array_js($categories) || count($categories) < 2) {
                    return $q;
                }
                $items = $q['items'] ?? null;
                if (!self::js_truthy($items) || !self::is_array_js($items) || count($items) < 3) {
                    return $q;
                }
                $kept = [];
                foreach ($items as $item) {
                    if (!self::js_truthy(self::prop($item, 'text'))) {
                        continue;
                    }
                    $cat = $item['category'] ?? null;
                    if (is_int($cat) || is_float($cat)) {
                        if ($cat >= 0 && $cat < count($categories)) {
                            $kept[] = $item;
                        }
                        continue;
                    }
                    if (is_string($cat)) {
                        if (self::js_list_includes($categories, $cat)) {
                            $kept[] = $item;
                            continue;
                        }
                        $num = self::parse_int($cat);
                        if (!(is_float($num) && is_nan($num)) && $num >= 0 && $num < count($categories)) {
                            $item['category'] = $num;
                            $kept[] = $item;
                        }
                    }
                }
                $q['items'] = $kept;
                if (count($kept) < 3) {
                    return $q;
                }
                $used = [];
                foreach ($kept as $item) {
                    $cat = $item['category'];
                    $idx = is_int($cat) || is_float($cat) ? $cat : self::js_index_of($categories, $cat);
                    $used[self::key_of($idx)] = true;
                }
                if (count($used) < 2) {
                    return $q;
                }
                self::default_explanation($q);
                $ok = true;
                return $q;

            case 'flashcards':
                $cards = $q['cards'] ?? null;
                if (!self::js_truthy($cards) || !self::is_array_js($cards) || count($cards) < 2) {
                    return $q;
                }
                $q['cards'] = array_values(array_filter(array_map(function ($c) {
                    return ['front' => self::js_or(self::prop($c, 'front'), ''), 'back' => self::js_or(
                        self::prop($c, 'back'),
                        ''
                    )];
                }, array_slice($cards, 0, 6)), function ($c) {
                    return self::js_truthy($c['front']) && self::js_truthy($c['back']);
                }));
                if (count($q['cards']) < 2) {
                    return $q;
                }
                self::default_explanation($q);
                $ok = true;
                return $q;

            case 'truefalseswipe':
                $statements = $q['statements'] ?? null;
                if (!self::js_truthy($statements) || !self::is_array_js($statements) || count($statements) < 2) {
                    return $q;
                }
                $q['statements'] = array_values(array_filter(array_map(function ($s) {
                    return ['text' => self::js_or(self::prop($s, 'text'), ''), 'correct' => self::js_truthy(self::prop(
                        $s,
                        'correct'
                    )), 'explanation' => self::js_or(self::prop($s, 'explanation'), '')];
                }, array_slice($statements, 0, 6)), function ($s) {
                    return self::js_truthy($s['text']);
                }));
                if (count($q['statements']) < 2) {
                    return $q;
                }
                self::default_explanation($q);
                $ok = true;
                return $q;

            case 'fillinblank':
                $text = $q['text'] ?? null;
                if (!self::js_truthy($text) || !is_string($text)) {
                    return $q;
                }
                $blanks = $q['blanks'] ?? null;
                if (!self::js_truthy($blanks) || !self::is_array_js($blanks) || count($blanks) < 1) {
                    return $q;
                }
                $q['blanks'] = array_values(array_filter(array_map(function ($b) {
                    return ['answer' => is_string($b) ? $b : self::js_or(self::prop($b, 'answer'), '')];
                }, $blanks), function ($b) {
                    return self::js_truthy($b['answer']);
                }));
                if (!self::js_truthy($q['distractors'] ?? null) || !self::is_array_js($q['distractors'])) {
                    $q['distractors'] = [];
                }
                self::default_explanation($q);
                $ok = true;
                return $q;
        }
        return $q;
    }

    /**
     * if (!q.explanation) q.explanation = "".
     *
     * @param array $q
     */
    protected static function default_explanation(array &$q): void {
        if (!self::js_truthy($q['explanation'] ?? null)) {
            $q['explanation'] = '';
        }
    }

    /**
     * list.includes(v) (SameValueZero).
     *
     * @param array $list
     * @param mixed $v
     * @return bool
     */
    protected static function js_list_includes(array $list, $v): bool {
        foreach ($list as $x) {
            if (self::strict_eq($x, $v)) {
                return true;
            }
        }
        return false;
    }

    /**
     * list.indexOf(v).
     *
     * @param array $list
     * @param mixed $v
     * @return int
     */
    protected static function js_index_of(array $list, $v): int {
        foreach ($list as $i => $x) {
            if (self::strict_eq($x, $v)) {
                return $i;
            }
        }
        return -1;
    }

    /**
     * a >= b.
     *
     * @param mixed $a
     * @param int|float $b
     * @return bool
     */
    protected static function js_ge($a, $b): bool {
        $a = self::tonum($a);
        return !is_nan((float) $a) && $a >= $b;
    }

    // Timestamps: applyTranscriptTimestampFallback.

    /**
     * Give a question without a timestamp the time of the transcript block that shares most words with it.
     *
     * @param array $questions
     * @param mixed $transcript
     * @return array
     */
    protected static function timestamp_fallback(array $questions, $transcript): array {
        if (!$questions || !is_string($transcript) || self::len($transcript) < 10) {
            return $questions;
        }
        $blocks = [];
        $lastidx = 0;
        $lastsec = null;
        preg_match_all('/(?:\[)?([0-9]{1,2}:[0-9]{2}(?::[0-9]{2})?)(?:\])?/', $transcript, $all, PREG_SET_ORDER
            | PREG_OFFSET_CAPTURE);
        foreach ($all as $m) {
            if ($lastsec !== null) {
                $text = self::jtrim(substr($transcript, $lastidx, $m[0][1] - $lastidx));
                if ($text !== '') {
                    $blocks[] = ['sec' => $lastsec, 'text' => $text];
                }
            }
            $parts = array_map('intval', explode(':', $m[1][0]));
            $lastsec = count($parts) === 3 ? $parts[0] * 3600 + $parts[1] * 60 + $parts[2] : $parts[0] * 60 + $parts[1];
            $lastidx = $m[0][1] + strlen($m[0][0]);
        }
        if ($lastsec !== null) {
            $tail = self::jtrim(substr($transcript, $lastidx));
            if ($tail !== '') {
                $blocks[] = ['sec' => $lastsec, 'text' => $tail];
            }
        }
        if (count($blocks) < 2) {
            return $questions;
        }
        $blockwords = array_map(function ($b) {
            return preg_split('/[^a-z0-9]+/', self::lower($b['text']));
        }, $blocks);
        foreach ($questions as $qi => $q) {
            $existing = self::prop($q, 'timestamp_seconds');
            if ($existing !== null && self::js_gt($existing, 0)) {
                continue;
            }
            $stem = self::lower(self::js_str(self::js_or(
                self::js_or(self::prop($q, 'question'), self::prop($q, 'stem')),
                ''
            )));
            if (self::len($stem) < 8) {
                continue;
            }
            $stemwords = [];
            foreach (preg_split('/[^a-z0-9]+/', $stem) as $w) {
                if (strlen($w) >= 4) {
                    $stemwords[$w] = true;
                }
            }
            if (!$stemwords) {
                continue;
            }
            $best = $blocks[0];
            $bestscore = 0;
            foreach ($blocks as $bi => $block) {
                $score = 0;
                foreach ($blockwords[$bi] as $w) {
                    if (isset($stemwords[$w])) {
                        $score++;
                    }
                }
                if ($score > $bestscore) {
                    $bestscore = $score;
                    $best = $block;
                }
            }
            if ($bestscore >= 1) {
                $q['timestamp_seconds'] = $best['sec'];
                $questions[$qi] = $q;
            }
        }
        return $questions;
    }

    // The other routes.

    /**
     * Check the credentials and question list the regenerate routes need.
     *
     * @param array $body
     * @param bool $cap Whether the route allows at most 50 questions.
     * @return array The questions.
     * @throws http_error
     */
    protected static function questions_from(array $body, bool $cap): array {
        if (!self::js_truthy($body['siteId'] ?? null) || !self::js_truthy($body['apiKey'] ?? null)) {
            throw new http_error(401, 'Missing credentials');
        }
        $questions = $body['questions'] ?? null;
        if (!self::js_truthy($questions) || !self::is_array_js($questions) || count($questions) === 0) {
            throw new http_error(400, 'Questions required');
        }
        if ($cap && count($questions) > 50) {
            throw new http_error(400, 'Maximum 50 questions');
        }
        return $questions;
    }

    /**
     * Narrate questions again: the regenerate-audio route.
     *
     * @param array $body
     * @return array The route's JSON answer.
     * @throws http_error
     */
    public static function regenerate_audio(array $body): array {
        $questions = self::questions_from($body, false);
        $voice = self::voice_config(
            self::js_or($body['voiceLanguage'] ?? null, 'en-AU'),
            self::js_or($body['voiceId'] ?? null, 'Aoede')
        );
        $out = [];
        try {
            foreach ($questions as $q) {
                if ($q === null) {
                    throw js_error::type("Cannot read properties of null (reading 'type')");
                }
                $q = self::upgrade_cardselect($q);
                if (self::card_type($q) && self::is_array_js(self::prop($q, 'explanations')) && count($q['explanations']) > 0) {
                    $q = self::fix_card_order($q)[0];
                }
                $percard = self::is_per_card($q, false);
                $type = self::prop($q, 'type');
                $nonmcq = self::js_truthy($type) && $type !== 'mcq' && !$percard;
                if ($percard) {
                    $audio = array_map(function ($e) use ($voice) {
                        return self::narrate_or_blank($e, $voice, false);
                    }, $q['explanations']);
                } else if ($nonmcq) {
                    $audio = self::narrate_single(self::prop($q, 'explanation'), $voice);
                } else {
                    $explanations = self::prop($q, 'explanations');
                    if (!self::js_truthy($explanations)) {
                        $options = self::prop($q, 'options');
                        $explanations = [];
                        for ($i = 0; $i < 4; $i++) {
                            $o = $options === null ? null : self::elem($options, $i);
                            $explanations[] = self::js_or($o === null ? null : self::prop($o, 'explanation'), '');
                        }
                    }
                    $audio = array_map(function ($e) use ($voice) {
                        return self::narrate_or_blank($e, $voice, false);
                    }, self::list_of($explanations));
                }
                $copy = self::is_object_js($q) && !self::is_array_js($q) ? (array) $q : [];
                unset($copy['audioData']);
                $copy['audioData'] = $audio;
                $out[] = $copy;
            }
        } catch (js_error $e) {
            throw new http_error(500, 'Failed to regenerate audio');
        }
        return ['ok' => true, 'questions' => $out];
    }

    /**
     * One clip for a question's single explanation: [clip] when there is text, [] when there is none.
     *
     * @param mixed $explanation
     * @param array $voice
     * @return array
     */
    protected static function narrate_single($explanation, array $voice): array {
        $text = self::js_or($explanation, '');
        if (!is_string($text)) {
            throw js_error::type('text.trim is not a function');
        }
        if (self::len(self::jtrim($text)) === 0) {
            return [];
        }
        return [self::narrate_or_blank($text, $voice, false)];
    }

    /**
     * Narrate rewritten questions, as both rewrite routes do.
     *
     * @param array $questions
     * @param mixed $langcode
     * @param mixed $voiceid
     * @return array
     */
    protected static function narrate_rewritten(array $questions, $langcode, $voiceid): array {
        $voice = self::voice_config($langcode, self::js_or($voiceid, 'Aoede'));
        foreach ($questions as $i => $q) {
            if ($q === null) {
                throw js_error::type("Cannot read properties of null (reading 'type')");
            }
            $percard = self::is_per_card($q, true);
            $type = self::prop($q, 'type');
            $nonmcq = self::js_truthy($type) && $type !== 'mcq' && !$percard;
            if ($percard) {
                $audio = array_map(function ($e) use ($voice) {
                    return self::narrate_or_blank($e, $voice, false);
                }, $q['explanations']);
            } else if ($nonmcq) {
                $audio = self::narrate_single(self::prop($q, 'explanation'), $voice);
            } else {
                $explanations = self::js_or(self::prop($q, 'explanations'), []);
                if (is_string($explanations)) {
                    $explanations = self::spread($explanations);
                } else if (!self::is_array_js($explanations)) {
                    throw js_error::type('q.explanations is not iterable');
                }
                $audio = array_map(function ($e) use ($voice) {
                    return self::narrate_or_blank($e, $voice, false);
                }, $explanations);
            }
            if (is_array($q) && !array_is_list($q) || $q instanceof \stdClass) {
                $q = (array) $q;
                $q['audioData'] = $audio;
                $questions[$i] = $q;
            }
        }
        return $questions;
    }

    /**
     * Rewrite questions in another language: the regenerate-settings route.
     *
     * @param array $body
     * @return array The route's JSON answer.
     * @throws http_error
     */
    public static function regenerate_settings(array $body): array {
        $questions = self::questions_from($body, true);
        $fail = 'Failed to regenerate with new settings';
        $langcode = self::js_or($body['voiceLanguage'] ?? null, 'en-AU');
        $languagename = self::language_name(self::key_string($langcode));
        if (!is_string($langcode)) {
            throw new http_error(500, $fail);
        }
        $explanationprefixinstruction = (self::js_truthy((strpos(self::js_str($langcode), 'en-') === 0)) ? ('- Explanations ' .
            'for the correct answer should start with "Correct!" and explanations for incorrect answers should start with ' .
            '"Incorrect."') : ('- Explanations for the correct answer should start with the ' . self::js_str($languagename) .
                ' equivalent of "Correct!" and explanations for incorrect answers should start with the ' .
                    self::js_str($languagename) . ' equivalent of "Incorrect."'));
        $questionsjson = self::json_pretty($questions);
        $prompt = ('You are an expert quiz question translator and editor. You are given existing quiz questions and must ' .
            'rewrite them in ' . self::js_str($languagename) . '.

IMPORTANT RULES:
- Translate/rewrite ALL question text, options, and explanations into ' . self::js_str($languagename) . '
- Use native ' . self::js_str($languagename) . ' spelling, grammar, vocabulary, and phrasing
- Keep the same meaning, difficulty level, and correct answer index
- Maintain exactly 4 answer options per question
- Each option must have an explanation
' . self::js_str($explanationprefixinstruction) . '
- Return valid JSON only

INPUT QUESTIONS:
' . self::js_str($questionsjson) . '

Return a JSON array with this exact structure:
[
  {
    "question": "translated question text",
    "options": ["option A", "option B", "option C", "option D"],
    "explanations": ["explanation for A", "explanation for B", "explanation for C", "explanation for D"],
    "correctAnswer": 0
  }
]

Return ONLY the JSON array, no markdown or other text.');
        try {
            $responsetext = self::js_or(self::call('', $prompt, self::REGEN_TOKENS, true, null), '');
        } catch (js_error $e) {
            throw new http_error(500, $fail);
        }
        $stripped = self::jtrim(preg_replace('/\x60{3}[' . self::WS . ']*/u', '', preg_replace('/\x60{3}json['
            . self::WS . ']*/iu', '', $responsetext)));
        $parsed = self::try_parse($stripped);
        if ($parsed === null && preg_match('/\[[\s\S]*\]/u', $stripped, $m)) {
            $parsed = self::try_parse($m[0]);
        }
        if ($parsed === null) {
            $s = strpos($responsetext, '[');
            $e = strrpos($responsetext, ']');
            if ($s !== false && $e !== false && $e > $s) {
                $parsed = self::try_parse(substr($responsetext, $s, $e - $s + 1));
            }
        }
        if ($parsed !== null && !self::is_array_js($parsed)) {
            $candidate = self::js_or(
                self::js_or(self::prop($parsed, 'questions'), self::prop($parsed, 'data')),
                self::first_array(self::object_values($parsed))
            );
            if (self::is_array_js($candidate)) {
                $parsed = $candidate;
            }
        }
        if (!self::is_array_js($parsed) || count($parsed) === 0) {
            throw new http_error(500, 'Failed to parse AI response');
        }
        $regenerated = array_slice($parsed, 0, count($questions));
        try {
            foreach ($regenerated as $i => $q) {
                if ($q === null) {
                    throw js_error::type("Cannot read properties of null (reading 'type')");
                }
                $type = self::prop($q, 'type');
                if ($type === 'mcq') {
                    $options = self::prop($q, 'options');
                    $explanations = self::prop($q, 'explanations');
                    $bad = !self::js_truthy(self::prop($q, 'question')) || !self::is_array_js($options) || count($options) !== 4
                        || !self::is_array_js($explanations) || count($explanations) !== 4;
                } else {
                    $bad = !self::js_truthy(self::prop($q, 'question'));
                }
                if ($bad) {
                    throw new http_error(500, 'AI returned malformed question ' . ($i + 1) . '. Please try again.');
                }
                $q = (array) $q;
                $original = $questions[$i] ?? null;
                $q['correctAnswer'] = $original === null ? 0 : (self::prop($original, 'correctAnswer') ?? 0);
                if (self::has($original, 'timestamp_seconds')) {
                    $q['timestamp_seconds'] = $original['timestamp_seconds'];
                }
                $q = self::fix_explanation_order($q);
                $options = self::prop($q, 'options');
                if (self::is_array_js($options) && count($options) === 4) {
                    $q = self::shuffle($q);
                }
                $regenerated[$i] = $q;
            }
            if (self::js_truthy($body['voiceoverEnabled'] ?? null)) {
                $regenerated = self::narrate_rewritten($regenerated, $langcode, $body['voiceId'] ?? null);
            }
        } catch (js_error $e) {
            throw new http_error(500, $fail);
        }
        return ['ok' => true, 'questions' => $regenerated];
    }

    /**
     * JSON.parse() that gives null on failure, as the service's parse helpers do.
     *
     * @param string $s
     * @return mixed
     */
    protected static function try_parse(string $s) {
        try {
            return self::parse($s);
        } catch (js_error $e) {
            return null;
        }
    }

    /** Per-type answer shapes for a one-question rewrite. */
    const RI_SCHEMAS = [
        'mcq' => ('Return a single JSON object with this EXACT shape:
{ "type": "mcq", "question": "...", "options": ["...","...","...","..."], "explanations": ["...","...","...","..."], ' .
            '"correctAnswer": 0|1|2|3, "timestamp_seconds": <int or null> }
- Reword the question stem
- Reword all 4 options (must be similar in length and grammatical structure; no "All of the above" / "None of the above")
- Reword all 4 explanations (correct one starts with "Correct.", incorrect ones start with "Incorrect.")
- Keep correctAnswer pointing at the SAME logical correct option after rewording
- timestamp_seconds: integer seconds since video start where this question\'s topic begins in the transcript; null only if ' .
            'the transcript contains no timestamps at all. ALWAYS include the field.'),
        'cardselect' => ('Return a single JSON object with this EXACT shape:
{ "type": "cardselect", "question": "...", "cards": [{"icon":"...","label":"...","description":"..."}, ...EXACTLY 4...], ' .
            '"correctIndex": 0|1|2|3, "explanations": ["...","...","...","..."], "timestamp_seconds": <int or null> }
- Reword the question stem
- Reword each of the 4 cards\' label and description (keep icons unchanged)
- Reword each of the 4 explanations (one per card, indexed positionally to match cards). The card at correctIndex MUST have ' .
            'its explanation start with "Correct."; the 3 other cards MUST have explanations starting with "Incorrect." and ' .
            'explain why that specific card is wrong without naming the correct card outright.
- Keep correctIndex pointing at the SAME logical correct card after rewording
- timestamp_seconds: integer seconds since video start where this question\'s topic begins in the transcript; null only if ' .
            'the transcript contains no timestamps at all. ALWAYS include the field.'),
        'matching' => ('Return a single JSON object with this EXACT shape:
{ "type": "matching", "question": "...", "pairs": [{"left":"...","right":"..."}, ...EXACTLY __PAIR_COUNT__ pairs...], ' .
            '"timestamp_seconds": <int or null> }
- Reword the question stem
- Reword each pair\'s left and right strings
- KEEP the same number of pairs and keep each left/right paired correctly
- timestamp_seconds: integer seconds since video start where this question\'s topic begins in the transcript; null only if ' .
            'the transcript contains no timestamps at all. ALWAYS include the field.'),
        'ordering' => ('Return a single JSON object with this EXACT shape:
{ "type": "ordering", "question": "...", "items": ["...","...",...], "explanation": "...", "timestamp_seconds": <int or ' .
            'null> }
- Reword the question stem
- Reword each item string
- KEEP the same number of items, in the SAME correct order
- timestamp_seconds: integer seconds since video start where this question\'s topic begins in the transcript; null only if ' .
            'the transcript contains no timestamps at all. ALWAYS include the field.'),
        'columnsort' => ('Return a single JSON object with this EXACT shape:
{ "type": "columnsort", "question": "...", "columnA": "...", "columnB": "...", "items": [{"text":"...","column":"A"|"B"}, ' .
            '...], "explanation": "...", "timestamp_seconds": <int or null> }
- Reword the question stem, both column labels, and each item.text
- Keep each item\'s "column" assignment ("A" or "B") unchanged
- KEEP the same number of items
- timestamp_seconds: integer seconds since video start where this question\'s topic begins in the transcript; null only if ' .
            'the transcript contains no timestamps at all. ALWAYS include the field.'),
        'categorysort' => ('Return a single JSON object with this EXACT shape:
{ "type": "categorysort", "question": "...", "categories": ["...","...","..."] (EXACTLY 3 strings), "items": ' .
            '[{"text":"...","category":"<one of the 3 category strings>"}, ...], "explanation": "...", "timestamp_seconds": ' .
            '<int or null> }
- Reword the question stem
- You MAY rename the 3 categories. If you do, EVERY item.category MUST be updated to exactly match one of the new category ' .
            'strings (string equality is required).
- Reword each item.text
- KEEP the same number of items and the same per-category distribution
- timestamp_seconds: integer seconds since video start where this question\'s topic begins in the transcript; null only if ' .
            'the transcript contains no timestamps at all. ALWAYS include the field.'),
        'flashcards' => ('Return a single JSON object with this EXACT shape:
{ "type": "flashcards", "cards": [{"front":"...","back":"..."}, ...], "timestamp_seconds": <int or null> }
- Reword each card\'s front (recall question/prompt) and back (answer)
- KEEP the same number of cards
- timestamp_seconds: integer seconds since video start where this question\'s topic begins in the transcript; null only if ' .
            'the transcript contains no timestamps at all. ALWAYS include the field.'),
        'truefalseswipe' => ('Return a single JSON object with this EXACT shape:
{ "type": "truefalseswipe", "question": "...", "statements": [{"text":"...","correct":true|false,"explanation":"..."}, ...], ' .
            '"explanation": "...", "timestamp_seconds": <int or null> }
- Reword the question stem, each statement.text, each statement.explanation, and the overall explanation
- Keep each statement\'s "correct" boolean unchanged
- KEEP the same number of statements
- timestamp_seconds: integer seconds since video start where this question\'s topic begins in the transcript; null only if ' .
            'the transcript contains no timestamps at all. ALWAYS include the field.'),
        'fillinblank' => ('Return a single JSON object with this EXACT shape:
{ "type": "fillinblank", "question": "...", "text": "...passage with ___1___ ___2___ ... numbered placeholders...", ' .
            '"blanks": [{"answer":"..."}, ...], "distractors": ["...","...",...], "explanation": "...", "timestamp_seconds": ' .
            '<int or null> }
- Reword the question stem, the passage text (keeping the SAME number of numbered ___N___ placeholders), each blank\'s ' .
            'answer, the distractor list, and the explanation
- KEEP the same number of blanks
- timestamp_seconds: integer seconds since video start where this question\'s topic begins in the transcript; null only if ' .
            'the transcript contains no timestamps at all. ALWAYS include the field.'),
    ];

    /** Examples for the anti-echo retry. */
    const RI_EXAMPLES = [
        'ordering' => ('Example: if the original item was "Identify the hazard", DO NOT return "Identify the hazard" — ' .
            'write something like "Spot any potential dangers in the area" or "Recognise risks present at the workplace". ' .
            'Use synonyms, expand abstract verbs into concrete descriptions, restructure the phrase.'),
        'matching' => ('Example: if an original pair was {"left":"PPE","right":"Personal protective equipment"}, DO NOT ' .
            'return identical strings — rephrase both sides, e.g. {"left":"Safety gear worn on the ' .
            'body","right":"Equipment that protects an individual worker from harm"}.'),
        'columnsort' => ('Example: if an item.text was "Wear gloves", DO NOT return "Wear gloves" — rephrase as "Put on ' .
            'protective gloves before starting" or similar.'),
        'categorysort' => ('Example: if an item.text was "Hard hat", DO NOT return "Hard hat" — rephrase as "Protective ' .
            'head covering" or "Construction safety helmet".'),
    ];

    /** Types whose short items the service checks for an echoed rewrite. */
    const ECHO_TYPES = ['ordering', 'matching', 'columnsort', 'categorysort'];

    /**
     * Rewrite questions to the teacher's new instructions: the regenerate-instructions route.
     *
     * @param array $body
     * @return array The route's JSON answer.
     * @throws http_error
     */
    public static function regenerate_instructions(array $body): array {
        $questions = self::questions_from($body, true);
        $fail = 'Failed to regenerate with updated instructions';
        $transcript = $body['transcript'] ?? null;
        $transcripttext = is_string($transcript)
            ? (self::len($transcript) > 200000 ? self::slice($transcript, 0, 200000) : $transcript) : '';
        $langcode = self::js_or($body['voiceLanguage'] ?? null, 'en-AU');
        $languagename = self::language_name(self::key_string($langcode));
        $extrainstructions = $body['extraInstructions'] ?? null;
        if (!is_string($langcode) || (self::js_truthy($extrainstructions) && !is_string($extrainstructions))) {
            throw new http_error(500, $fail);
        }
        $explanationprefixinstruction = (self::js_truthy((strpos(self::js_str($langcode), 'en-') === 0)) ? ('- Explanations ' .
            'for the correct answer should start with "Correct!" and explanations for incorrect answers should start with ' .
            '"Incorrect."') : ('- Explanations for the correct answer should start with the ' . self::js_str($languagename) .
                ' equivalent of "Correct!" and explanations for incorrect answers should start with the ' .
                    self::js_str($languagename) . ' equivalent of "Incorrect."'));
        $extrainstructionsblock = (self::js_truthy(self::js_and(
            $extrainstructions,
            self::jtrim(self::js_str($extrainstructions))
        )) ? ('

ADDITIONAL INSTRUCTOR INSTRUCTIONS (apply these when regenerating):
' . self::js_str(self::jtrim(self::js_str($extrainstructions)))) : '');
        $transcriptblock = (self::js_truthy((self::js_len(self::jtrim(self::js_str($transcripttext))) > 0)) ? ('

VIDEO TRANSCRIPT (AUTHORITATIVE SOURCE OF TRUTH — your regenerated question MUST be answerable from this transcript and ' .
            'MUST NOT introduce facts not present here):
"""
' . self::js_str(self::jtrim(self::js_str($transcripttext))) . '
"""
') : '');

        $total = count($questions);
        $regenerated = [];
        foreach ($questions as $i => $original) {
            $qtype = self::js_or($original === null ? null : self::prop($original, 'type'), 'mcq');
            try {
                $pass1 = self::rewrite_once(
                    $original,
                    $i,
                    $total,
                    false,
                    $languagename,
                    $explanationprefixinstruction,
                    $extrainstructionsblock,
                    $transcriptblock
                );
                if (!self::js_truthy($pass1) || !self::is_object_js($pass1)) {
                    $regenerated[] = $original;
                    continue;
                }
                $pass1 = self::set_type($pass1, $qtype);
                if (is_string($qtype) && in_array($qtype, self::ECHO_TYPES, true)) {
                    $origitems = self::short_items($original, $qtype);
                    $rate1 = self::echo_rate($origitems, self::short_items($pass1, $qtype));
                    if ($rate1 >= 0.5) {
                        try {
                            $pass2 = self::rewrite_once(
                                $original,
                                $i,
                                $total,
                                true,
                                $languagename,
                                $explanationprefixinstruction,
                                $extrainstructionsblock,
                                $transcriptblock
                            );
                            if (self::js_truthy($pass2) && self::is_object_js($pass2)) {
                                $pass2 = self::set_type($pass2, $qtype);
                                $rate2 = self::echo_rate($origitems, self::short_items($pass2, $qtype));
                                if ($rate2 < $rate1) {
                                    $regenerated[] = $pass2;
                                    continue;
                                }
                            }
                        } catch (js_error $e) {
                            // The service keeps the first pass.
                            unset($e);
                        }
                    }
                }
                $regenerated[] = $pass1;
            } catch (js_error $e) {
                $regenerated[] = $original;
            }
        }

        try {
            foreach ($regenerated as $i => $q) {
                $original = $questions[$i] ?? null;
                $type = self::js_or($original === null ? null : self::prop($original, 'type'), 'mcq');
                if (!self::check_rewrite($q, $original, $type)) {
                    $regenerated[$i] = self::js_truthy($original) ? $original : $q;
                    continue;
                }
                $q = (array) $q;
                if ($type === 'mcq') {
                    $ca = $q['correctAnswer'] ?? null;
                    if (!(is_int($ca) || is_float($ca)) || $ca < 0 || $ca > 3) {
                        $q['correctAnswer'] = self::oget($original, 'correctAnswer') ?? 0;
                    }
                    $q = self::fix_explanation_order($q);
                } else if ($type === 'cardselect') {
                    $ci = $q['correctIndex'] ?? null;
                    if (!(is_int($ci) || is_float($ci)) || $ci < 0 || $ci > 3) {
                        $q['correctIndex'] = self::oget($original, 'correctIndex') ?? 0;
                    }
                    $origcards = self::oget($original, 'cards');
                    if (self::js_truthy($origcards) && self::is_array_js($origcards)) {
                        $q['cards'] = array_map(function ($c, $idx) use ($origcards) {
                            $icon = self::oget($origcards[$idx] ?? null, 'icon');
                            $c = (array) $c;
                            if ($icon !== null) {
                                $c['icon'] = $icon;
                            }
                            return $c;
                        }, $q['cards'], array_keys($q['cards']));
                    }
                    if (self::is_array_js($q['explanations'] ?? null) && count($q['explanations']) > 0) {
                        $q = self::fix_card_order($q)[0];
                    }
                } else if ($type === 'columnsort') {
                    $origitems = self::oget($original, 'items');
                    if (self::js_truthy($origitems) && self::is_array_js($origitems)) {
                        $q['items'] = array_map(function ($it, $idx) use ($origitems) {
                            $column = self::oget($origitems[$idx] ?? null, 'column');
                            $it = (array) $it;
                            if ($column !== null) {
                                $it['column'] = $column;
                            }
                            return $it;
                        }, $q['items'], array_keys($q['items']));
                    }
                } else if ($type === 'truefalseswipe') {
                    $origstatements = self::oget($original, 'statements');
                    if (self::js_truthy($origstatements) && self::is_array_js($origstatements)) {
                        $q['statements'] = array_map(function ($s, $idx) use ($origstatements) {
                            $correct = self::oget($origstatements[$idx] ?? null, 'correct');
                            $s = (array) $s;
                            if (is_bool($correct)) {
                                $s['correct'] = $correct;
                            }
                            return $s;
                        }, $q['statements'], array_keys($q['statements']));
                    }
                }
                $q['type'] = $type;
                $origts = self::oget($original, 'timestamp_seconds');
                if ($origts !== null) {
                    $q['timestamp_seconds'] = $origts;
                } else if (!array_key_exists('timestamp_seconds', $q)) {
                    $q['timestamp_seconds'] = null;
                }
                $regenerated[$i] = $q;
            }
            if (self::js_truthy($body['voiceoverEnabled'] ?? null)) {
                $regenerated = self::narrate_rewritten($regenerated, $langcode, $body['voiceId'] ?? null);
            }
        } catch (js_error $e) {
            throw new http_error(500, $fail);
        }
        return ['ok' => true, 'questions' => $regenerated, 'isFree' => true, 'regenerationCount' => 1];
    }

    /**
     * o?.k: null for null, otherwise as prop().
     *
     * @param mixed $o
     * @param string $k
     * @return mixed
     */
    protected static function oget($o, string $k) {
        return $o === null ? null : self::prop($o, $k);
    }

    /**
     * Set a rewritten question's type back to the original's (a list has nowhere JSON would keep it).
     *
     * @param mixed $q
     * @param mixed $type
     * @return mixed
     */
    protected static function set_type($q, $type) {
        if (is_array($q) && !array_is_list($q)) {
            $q['type'] = $type;
        } else if ($q instanceof \stdClass) {
            $q = ['type' => $type];
        }
        return $q;
    }

    /**
     * One rewrite call and its parse (callRegenerateOnce with buildSingleQuestionPrompt and parseSingleQuestion).
     *
     * @param mixed $originalq
     * @param int $idx
     * @param int $total
     * @param bool $antiechomode
     * @param string $languagename
     * @param string $explanationprefixinstruction
     * @param string $extrainstructionsblock
     * @param string $transcriptblock
     * @return mixed The parsed question, or null.
     */
    protected static function rewrite_once(
        $originalq,
        int $idx,
        int $total,
        bool $antiechomode,
        string $languagename,
        string $explanationprefixinstruction,
        string $extrainstructionsblock,
        string $transcriptblock
    ) {
        $qtype = self::js_or($originalq === null ? null : self::prop($originalq, 'type'), 'mcq');
        $key = self::key_string($qtype);
        $schemadesc = self::RI_SCHEMAS[$key] ?? self::RI_SCHEMAS['mcq'];
        if ($qtype === 'matching') {
            $pairs = $originalq === null ? null : self::prop($originalq, 'pairs');
            $count = $pairs === null ? null : self::prop($pairs, 'length');
            $schemadesc = str_replace('__PAIR_COUNT__', self::js_str(self::js_or($count, 0)), $schemadesc);
        }
        if (!is_string($qtype)) {
            throw js_error::type('qType.toUpperCase is not a function');
        }
        if ($antiechomode) {
            $example = self::RI_EXAMPLES[$qtype] ?? ('Use synonyms and restructure phrases — do NOT return text identical ' .
                'to the original.');
            $antiechoblock = ('

CRITICAL — ANTI-ECHO INSTRUCTION (read carefully):
Your previous attempt at this question returned text that was character-for-character identical to the original. That is a ' .
                'failure. You MUST now rewrite EVERY string field with COMPLETELY different vocabulary while preserving the ' .
                'exact same meaning, count, and structure.
' . self::js_str($example) . '
Forbidden: returning any item text, pair text, option text, or statement text that is identical (after lowercasing and ' .
            'stripping punctuation) to the original at the same index. Every short-text field MUST use different words.
');
        } else {
            $antiechoblock = '';
        }
        $originaljson = self::json_pretty($originalq);
        $prompt = ('You are a quiz question editor. You are rewording ONE existing ' .
            self::js_str(mb_strtoupper(self::js_str($qtype))) . ' quiz question (Question ' . self::js_str(self::js_add(
                $idx,
                1
            )) . ' of ' . self::js_str($total) .
                    ') that was originally generated from the VIDEO TRANSCRIPT below. Regenerate/improve it while staying ' .
                        'tightly grounded in that same transcript.' .
                        self::js_str($antiechoblock) . '
' . self::js_str($transcriptblock) . '
CORE REQUIREMENT — PRESERVE TYPE AND STRUCTURE:
- The question type is "' . self::js_str($qtype) . '". You MUST return the SAME type — never change the type.
- Preserve all STRUCTURAL fields exactly: counts, indices, boolean flags, column/category assignments. Reword ONLY the ' .
        'natural-language text.

CORE REQUIREMENT — STAY GROUNDED IN THE TRANSCRIPT:
- The transcript above is the ONLY source of truth. Every regenerated field MUST be answerable from the transcript.
- Do NOT invent facts, statistics, names, processes, or examples that are not present in the transcript.
- Do NOT generalise the topic. If the transcript is about a specific procedure, country, regulation, product, or scenario, ' .
        'the regenerated question MUST stay specific to that.

CORE REQUIREMENT — STYLE MUST MATCH ORIGINAL:
- Match the same simple, clear, factual style as the original
- Do NOT create workplace scenario questions, narrative situations, or character-based scenarios
- Do NOT add fictional characters, workplace settings, or "imagine you are…" framing
- Keep the question factual, direct, and knowledge-based

CORE REQUIREMENT — ACTUALLY REWORD THE TEXT:
- The regenerated text MUST be meaningfully different wording from the original (different sentence structure, different ' .
        'phrasing) — do NOT return the original verbatim.
- The underlying knowledge being tested MUST stay the same.

LANGUAGE RULES:
- All content must be in ' . self::js_str($languagename) . '
- Use native ' . self::js_str($languagename) . ' spelling, grammar, vocabulary, and phrasing
' . self::js_str($explanationprefixinstruction) . '

PER-TYPE SCHEMA (this is the ONLY shape you may return):
' . self::js_str($schemadesc) . '
' . self::js_str($extrainstructionsblock) . '

ORIGINAL QUESTION (reword this — same type, same structure, fresh wording):
' . self::js_str($originaljson) . '

Return ONLY the single JSON object described above. No markdown. No commentary. No array wrapper. Just the object.');
        $text = self::js_or(self::call('', $prompt, self::ONE_QUESTION_TOKENS, true, null), '');
        return self::parse_one($text);
    }

    /**
     * parseSingleQuestion: an object, a list of one, or a wrapper around one.
     *
     * @param string $text
     * @return mixed
     */
    protected static function parse_one(string $text) {
        if ($text === '') {
            return null;
        }
        $stripped = self::jtrim(preg_replace('/\x60{3}[' . self::WS . ']*/u', '', preg_replace('/\x60{3}json['
            . self::WS . ']*/iu', '', $text)));
        $parsed = self::try_parse($stripped);
        if ($parsed === null && preg_match('/\{[\s\S]*\}/u', $stripped, $m)) {
            $parsed = self::try_parse($m[0]);
        }
        if ($parsed === null && preg_match('/\[[\s\S]*\]/u', $stripped, $m)) {
            $parsed = self::try_parse($m[0]);
        }
        if (self::is_array_js($parsed)) {
            $parsed = self::js_or($parsed[0] ?? null, null);
        }
        if (self::js_truthy($parsed) && self::is_object_js($parsed) && !self::is_array_js($parsed)) {
            $list = self::prop($parsed, 'questions');
            if (self::js_truthy($list) && self::is_array_js($list)) {
                return self::js_or($list[0] ?? null, null);
            }
            $question = self::prop($parsed, 'question');
            if (self::js_truthy($question) && self::is_object_js($question)) {
                return $question;
            }
        }
        return $parsed;
    }

    /**
     * extractShortItems: the short texts the echo check compares.
     *
     * @param mixed $q
     * @param string $type
     * @return array
     */
    protected static function short_items($q, string $type): array {
        if (!self::js_truthy($q)) {
            return [];
        }
        $str = function ($v): string {
            return self::js_str($v ?? '');
        };
        switch ($type) {
            case 'ordering':
                $items = self::prop($q, 'items');
                return self::is_array_js($items) ? array_map($str, $items) : [];
            case 'matching':
                $pairs = self::prop($q, 'pairs');
                if (!self::is_array_js($pairs)) {
                    return [];
                }
                $out = [];
                foreach ($pairs as $p) {
                    $out[] = $str($p === null ? null : self::prop($p, 'left'));
                    $out[] = $str($p === null ? null : self::prop($p, 'right'));
                }
                return $out;
            case 'columnsort':
            case 'categorysort':
                $items = self::prop($q, 'items');
                return self::is_array_js($items) ? array_map(function ($it) use ($str) {
                    return $str($it === null ? null : self::prop($it, 'text'));
                }, $items) : [];
        }
        return [];
    }

    /**
     * computeEchoRate: the share of items that came back unchanged.
     *
     * @param array $orig
     * @param array $new
     * @return float|int
     */
    protected static function echo_rate(array $orig, array $new) {
        $len = min(count($orig), count($new));
        if ($len === 0) {
            return 0;
        }
        $norm = function (string $s): string {
            return self::jtrim(preg_replace('/[^\p{L}\p{N}]+/u', '', self::lower($s)));
        };
        $echoed = 0;
        for ($i = 0; $i < $len; $i++) {
            if ($norm($orig[$i]) === $norm($new[$i])) {
                $echoed++;
            }
        }
        return $echoed / $len;
    }

    /**
     * validateRegenerated: whether a rewrite kept the original's shape.
     *
     * @param mixed $q
     * @param mixed $original
     * @param mixed $type
     * @return bool
     */
    protected static function check_rewrite(&$q, $original, $type): bool {
        if (!self::js_truthy($q) || !self::is_object_js($q)) {
            return false;
        }
        if (is_array($q) && !array_is_list($q)) {
            $t = $q['type'] ?? null;
            if (self::js_truthy($t) && !self::strict_eq($t, $type)) {
                $q['type'] = $type;
            }
        }
        $str = function ($v): bool {
            return self::js_truthy($v) && is_string($v);
        };
        $count = function ($field) use ($original) {
            $v = $original === null ? null : self::prop($original, $field);
            return self::js_truthy($v) ? self::prop($v, 'length') : false;
        };
        $samecount = function (array $list, string $field) use ($count): bool {
            $n = $count($field);
            return $n === false || self::strict_eq(count($list), $n);
        };
        $obj = function ($v): bool {
            return self::js_truthy($v) && (is_array($v) || $v instanceof \stdClass);
        };
        switch ($type) {
            case 'mcq':
                return $str(self::prop($q, 'question')) && self::is_array_js($options = self::prop($q, 'options'))
                    && count($options) === 4 && self::is_array_js($ex = self::prop($q, 'explanations')) && count($ex) === 4;
            case 'cardselect':
                if (
                    !$str(self::prop($q, 'question')) || !self::is_array_js($cards = self::prop($q, 'cards'))
                        || count($cards) !== 4
                ) {
                    return false;
                }
                foreach ($cards as $c) {
                    if (!$obj($c) || !is_string(self::prop($c, 'label')) || !is_string(self::prop($c, 'description'))) {
                        return false;
                    }
                }
                return true;
            case 'matching':
                if (
                    !$str(self::prop($q, 'question')) || !self::is_array_js($pairs = self::prop($q, 'pairs')) || !$pairs
                        || !$samecount($pairs, 'pairs')
                ) {
                    return false;
                }
                foreach ($pairs as $p) {
                    if (!$obj($p) || !is_string(self::prop($p, 'left')) || !is_string(self::prop($p, 'right'))) {
                        return false;
                    }
                }
                return true;
            case 'ordering':
                if (
                    !$str(self::prop($q, 'question')) || !self::is_array_js($items = self::prop($q, 'items')) || !$items
                        || !$samecount($items, 'items')
                ) {
                    return false;
                }
                foreach ($items as $it) {
                    if (!is_string($it) || $it === '') {
                        return false;
                    }
                }
                return true;
            case 'columnsort':
                if (
                    !$str(self::prop($q, 'question')) || !self::is_array_js($items = self::prop($q, 'items')) || !$items
                        || !$samecount($items, 'items')
                ) {
                    return false;
                }
                foreach ($items as $it) {
                    $column = $obj($it) ? self::prop($it, 'column') : null;
                    if (!$obj($it) || !is_string(self::prop($it, 'text')) || ($column !== 'A' && $column !== 'B')) {
                        return false;
                    }
                }
                return true;
            case 'categorysort':
                if (
                    !$str(self::prop($q, 'question')) || !self::is_array_js($cats = self::prop($q, 'categories'))
                        || count($cats) !== 3 || !self::is_array_js($items = self::prop($q, 'items')) || !$items
                        || !$samecount($items, 'items')
                ) {
                    return false;
                }
                foreach ($items as $it) {
                    if (
                        !$obj($it) || !is_string(self::prop($it, 'text'))
                            || !self::js_list_includes($cats, self::prop($it, 'category'))
                    ) {
                        return false;
                    }
                }
                return true;
            case 'flashcards':
                if (!self::is_array_js($cards = self::prop($q, 'cards')) || !$cards || !$samecount($cards, 'cards')) {
                    return false;
                }
                foreach ($cards as $c) {
                    if (!$obj($c) || !$str(self::prop($c, 'front')) || !$str(self::prop($c, 'back'))) {
                        return false;
                    }
                }
                return true;
            case 'truefalseswipe':
                if (!self::is_array_js($st = self::prop($q, 'statements')) || !$st || !$samecount($st, 'statements')) {
                    return false;
                }
                foreach ($st as $s) {
                    if (!$obj($s) || !is_string(self::prop($s, 'text')) || !is_bool(self::prop($s, 'correct'))) {
                        return false;
                    }
                }
                return true;
            case 'fillinblank':
                if (
                    !$str(self::prop($q, 'text')) || !self::is_array_js($blanks = self::prop($q, 'blanks')) || !$blanks
                        || !$samecount($blanks, 'blanks')
                ) {
                    return false;
                }
                foreach ($blanks as $b) {
                    if (!$obj($b) || !$str(self::prop($b, 'answer'))) {
                        return false;
                    }
                }
                return true;
        }
        return false;
    }

    /**
     * One clip on demand: the tts-single route.
     *
     * @param array $body
     * @return array The route's JSON answer.
     * @throws http_error
     */
    public static function tts_single(array $body): array {
        if (!self::js_truthy($body['siteId'] ?? null) || !self::js_truthy($body['apiKey'] ?? null)) {
            throw new http_error(401, 'Missing credentials');
        }
        $text = $body['text'] ?? null;
        if (!self::js_truthy($text) || !is_string($text) || self::len(self::jtrim($text)) === 0) {
            throw new http_error(400, 'Text required');
        }
        if (self::len($text) > 1000) {
            throw new http_error(400, 'Text too long (max 1000 chars)');
        }
        $voice = self::voice_config(
            self::js_or($body['voiceLanguage'] ?? null, 'en-AU'),
            self::js_or($body['voiceId'] ?? null, 'Aoede')
        );
        try {
            $audio = (self::$tts)(self::jtrim($text), $voice);
        } catch (\moodle_exception $e) {
            if ($e->errorcode === 'byok_notavailable') {
                throw $e;
            }
            throw new http_error(502, 'TTS generation failed');
        } catch (\Exception $e) {
            throw new http_error(502, 'TTS generation failed');
        }
        if ($audio === '') {
            throw new http_error(502, 'TTS returned empty audio');
        }
        return ['ok' => true, 'audioData' => base64_encode($audio)];
    }

    /**
     * Narrate questions the service wrote without voiceover, on the site's own voice: the regenerate-audio logic.
     *
     * @param array $questions
     * @param mixed $language
     * @param mixed $voiceid
     * @return array
     */
    public static function narrate(array $questions, $language, $voiceid): array {
        $out = self::regenerate_audio(['siteId' => 'byok', 'apiKey' => 'byok', 'questions' => $questions,
            'voiceLanguage' => $language, 'voiceId' => $voiceid]);
        return $out['questions'];
    }


    // Shared service helpers (the same code the Knowledge Check service uses).

    /**
     * One AI call through the hook. A provider failure is an error the service code catches like any other.
     *
     * @param string $system
     * @param string $user
     * @param int|null $maxtokens
     * @param bool $json
     * @param float|null $temperature
     * @return string
     */
    protected static function call(string $system, string $user, ?int $maxtokens, bool $json, ?float $temperature): string {
        try {
            return (string) (self::$aicall)($system, $user, ['maxtokens' => $maxtokens, 'json' => $json,
                'temperature' => $temperature]);
        } catch (js_error $e) {
            throw $e;
        } catch (\moodle_exception $e) {
            if ($e->errorcode === 'byok_notavailable') {
                throw $e;
            }
            self::$providererror = \core_text::substr(clean_param($e->getMessage(), PARAM_TEXT), 0, 300);
            throw new js_error(self::$providererror);
        }
    }

    /**
     * Call the AI and read JSON, with one retry: safeParseAIResponse.
     *
     * @param string $system
     * @param string $user
     * @param int $budget
     * @param string $label
     * @return mixed
     */
    protected static function safe_parse(string $system, string $user, int $budget, string $label) {
        $last = null;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $trimmed = self::jtrim(self::call($system, $user, $budget, true, 0.7));
                try {
                    return self::parse($trimmed);
                } catch (js_error $e) {
                    if (preg_match('/\{[\s\S]*\}/', $trimmed, $m)) {
                        return self::parse($m[0]);
                    }
                    throw $e;
                }
            } catch (js_error $e) {
                $last = $e;
            }
        }
        throw new js_error($label . ': Failed to get valid JSON after 2 attempts. Last error: '
            . ($last ? $last->getMessage() : 'undefined'));
    }

    /**
     * fixExplanationOrder: the "Correct." explanation goes with the correct option.
     *
     * @param mixed $question
     * @return mixed
     */
    protected static function fix_explanation_order($question) {
        $options = self::prop($question, 'options');
        $correct = self::prop($question, 'correctAnswer');
        $explanations = self::prop($question, 'explanations');
        if (
            !self::js_truthy($explanations) || !self::js_truthy($options) || self::prop($explanations, 'length') !== 4
                || self::prop($options, 'length') !== 4
        ) {
            return $question;
        }
        $fixed = self::spread($explanations);
        $starts = function ($e): bool {
            if ($e === null) {
                return false;
            }
            if (!is_string($e)) {
                throw js_error::type('e?.trim is not a function');
            }
            return strpos(self::lower(self::jtrim($e)), 'correct') === 0;
        };
        if (!$starts(self::index_get($fixed, $correct))) {
            $found = -1;
            foreach ($fixed as $i => $e) {
                if ($starts($e)) {
                    $found = $i;
                    break;
                }
            }
            if ($found !== -1) {
                $a = self::index_get($fixed, $correct);
                $b = $fixed[$found];
                self::index_set($fixed, $correct, $b);
                $fixed[$found] = $a;
            }
        }
        for ($i = 0; $i < 4; $i++) {
            if (self::strict_eq($i, $correct)) {
                continue;
            }
            $expl = self::js_or($fixed[$i] ?? null, '');
            if (!is_string($expl)) {
                throw js_error::type('expl.trim is not a function');
            }
            if (strpos(self::lower(self::jtrim($expl)), 'correct') === 0) {
                $body = self::jtrim(preg_replace('/^correct[.:!]?[' . self::WS . ']*/iu', '', $expl, 1));
                $fixed[$i] = 'Incorrect. ' . self::js_str(self::js_or($body, 'This is not the best answer for this question.'));
            }
        }
        $changed = false;
        foreach ($fixed as $i => $e) {
            if (!self::strict_eq($e, self::elem($explanations, $i))) {
                $changed = true;
            }
        }
        if (!$changed) {
            return $question;
        }
        $question['explanations'] = self::clean_list($fixed);
        return $question;
    }

    /**
     * shuffleQuestionAnswers: options and explanations move together; the correct index follows.
     *
     * @param mixed $question
     * @return array
     */
    protected static function shuffle($question): array {
        if (!is_array($question)) {
            throw js_error::type('Cannot destructure question');
        }
        $options = $question['options'] ?? null;
        $correct = $question['correctAnswer'] ?? null;
        $explanations = $question['explanations'] ?? null;
        $rest = $question;
        unset($rest['options'], $rest['correctAnswer'], $rest['explanations']);
        $indices = [0, 1, 2, 3];
        for ($i = 3; $i > 0; $i--) {
            $j = (int) floor(self::random() * ($i + 1));
            [$indices[$i], $indices[$j]] = [$indices[$j], $indices[$i]];
        }
        $newoptions = [];
        $newexplanations = [];
        foreach ($indices as $i) {
            $newoptions[] = self::elem($options, $i, true);
        }
        foreach ($indices as $i) {
            $newexplanations[] = self::elem($explanations, $i, true);
        }
        $newcorrect = -1;
        foreach ($indices as $pos => $i) {
            if (self::strict_eq($i, $correct)) {
                $newcorrect = $pos;
                break;
            }
        }
        return $rest + ['options' => self::clean_list($newoptions), 'correctAnswer' => $newcorrect,
            'explanations' => self::clean_list($newexplanations)];
    }

    /**
     * deduplicateQuestions: drop a question whose first 80 normalised characters repeat an earlier one.
     *
     * @param array $questions
     * @return array
     */
    protected static function dedupe(array $questions): array {
        if (count($questions) <= 1) {
            return $questions;
        }
        $seen = [];
        $out = [];
        foreach ($questions as $q) {
            $text = self::js_or(self::prop($q, 'question'), '');
            if (!is_string($text)) {
                throw js_error::type('toLowerCase is not a function');
            }
            $norm = preg_replace('/[^a-z0-9' . self::WS . ']/u', '', self::lower($text));
            $norm = self::jtrim(preg_replace('/[' . self::WS . ']+/u', ' ', $norm));
            $key = self::slice($norm, 0, 80);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $q;
        }
        return $out;
    }

    /**
     * One narration clip, base64, or '' when it cannot be made.
     *
     * @param mixed $explanation
     * @param array $voice
     * @param bool $lenient Whether a non-text explanation is just skipped (generation) or an error (regeneration).
     * @return string
     */
    protected static function narrate_or_blank($explanation, array $voice, bool $lenient): string {
        if (!is_string($explanation)) {
            if ($lenient || !self::js_truthy($explanation)) {
                return '';
            }
            throw js_error::type('explanation.trim is not a function');
        }
        $text = self::jtrim($explanation);
        if ($text === '') {
            return '';
        }
        try {
            $audio = (self::$tts)($text, $voice);
        } catch (\moodle_exception $e) {
            // A lapsed licence stops the job; anything else leaves this clip silent, as the service does.
            if ($e->errorcode === 'byok_notavailable') {
                throw $e;
            }
            return '';
        } catch (\Exception $e) {
            return '';
        }
        return $audio !== '' ? base64_encode($audio) : '';
    }

    // Languages, voices, spelling.

    /** Language names the question writers use. */
    const LANGUAGE_NAMES = [
        'en-AU' => 'English (Australian)',
        'en-GB' => 'English (British)',
        'en-IN' => 'English (Indian)',
        'en-US' => 'English (American)',
        'es-ES' => 'Spanish (Spain)',
        'es-US' => 'Spanish (US)',
        'fr-CA' => 'French (Canadian)',
        'fr-FR' => 'French (France)',
        'de-DE' => 'German',
        'pt-BR' => 'Portuguese (Brazilian)',
        'nl-BE' => 'Dutch (Belgian)',
        'nl-NL' => 'Dutch (Netherlands)',
        'da-DK' => 'Danish',
        'fi-FI' => 'Finnish',
        'nb-NO' => 'Norwegian',
        'sv-SE' => 'Swedish',
        'bg-BG' => 'Bulgarian',
        'cs-CZ' => 'Czech',
        'hr-HR' => 'Croatian',
        'hu-HU' => 'Hungarian',
        'pl-PL' => 'Polish',
        'ro-RO' => 'Romanian',
        'ru-RU' => 'Russian',
        'sk-SK' => 'Slovak',
        'sl-SI' => 'Slovenian',
        'sr-RS' => 'Serbian',
        'uk-UA' => 'Ukrainian',
        'et-EE' => 'Estonian',
        'lt-LT' => 'Lithuanian',
        'lv-LV' => 'Latvian',
        'el-GR' => 'Greek',
        'it-IT' => 'Italian',
        'cmn-CN' => 'Chinese (Mandarin)',
        'ja-JP' => 'Japanese',
        'ko-KR' => 'Korean',
        'id-ID' => 'Indonesian',
        'th-TH' => 'Thai',
        'vi-VN' => 'Vietnamese',
        'bn-IN' => 'Bengali',
        'gu-IN' => 'Gujarati',
        'hi-IN' => 'Hindi',
        'kn-IN' => 'Kannada',
        'ml-IN' => 'Malayalam',
        'mr-IN' => 'Marathi',
        'ta-IN' => 'Tamil',
        'te-IN' => 'Telugu',
        'ur-IN' => 'Urdu',
        'ar-XA' => 'Arabic',
        'he-IL' => 'Hebrew',
        'tr-TR' => 'Turkish',
        'sw-KE' => 'Swahili',
        'ms-MY' => 'Malay',
        'pa-IN' => 'Punjabi',
        'fil-PH' => 'Filipino',
        'cmn-TW' => 'Chinese (Traditional Mandarin)',
        'yue-HK' => 'Cantonese',
        'pt-PT' => 'Portuguese (Portugal)',
        'ca-ES' => 'Catalan',
        'is-IS' => 'Icelandic',
    ];

    /** Narration language codes with Chirp 3 HD voices. */
    const VOICE_LANGUAGES = [
        'en-AU' => 'en-AU',
        'en-GB' => 'en-GB',
        'en-IN' => 'en-IN',
        'en-US' => 'en-US',
        'es-ES' => 'es-ES',
        'es-US' => 'es-US',
        'fr-CA' => 'fr-CA',
        'fr-FR' => 'fr-FR',
        'de-DE' => 'de-DE',
        'pt-BR' => 'pt-BR',
        'nl-BE' => 'nl-BE',
        'nl-NL' => 'nl-NL',
        'da-DK' => 'da-DK',
        'fi-FI' => 'fi-FI',
        'nb-NO' => 'nb-NO',
        'sv-SE' => 'sv-SE',
        'bg-BG' => 'bg-BG',
        'cs-CZ' => 'cs-CZ',
        'hr-HR' => 'hr-HR',
        'hu-HU' => 'hu-HU',
        'pl-PL' => 'pl-PL',
        'ro-RO' => 'ro-RO',
        'ru-RU' => 'ru-RU',
        'sk-SK' => 'sk-SK',
        'sl-SI' => 'sl-SI',
        'sr-RS' => 'sr-RS',
        'uk-UA' => 'uk-UA',
        'et-EE' => 'et-EE',
        'lt-LT' => 'lt-LT',
        'lv-LV' => 'lv-LV',
        'el-GR' => 'el-GR',
        'it-IT' => 'it-IT',
        'cmn-CN' => 'cmn-CN',
        'ja-JP' => 'ja-JP',
        'ko-KR' => 'ko-KR',
        'id-ID' => 'id-ID',
        'th-TH' => 'th-TH',
        'vi-VN' => 'vi-VN',
        'bn-IN' => 'bn-IN',
        'gu-IN' => 'gu-IN',
        'hi-IN' => 'hi-IN',
        'kn-IN' => 'kn-IN',
        'ml-IN' => 'ml-IN',
        'mr-IN' => 'mr-IN',
        'ta-IN' => 'ta-IN',
        'te-IN' => 'te-IN',
        'ur-IN' => 'ur-IN',
        'ar-XA' => 'ar-XA',
        'he-IL' => 'he-IL',
        'tr-TR' => 'tr-TR',
        'sw-KE' => 'sw-KE',
    ];

    /**
     * getLanguageName.
     *
     * @param string $code
     * @return string
     */
    protected static function language_name(string $code): string {
        return self::LANGUAGE_NAMES[$code] ?? 'English (Australian)';
    }

    /**
     * getVoiceConfigWithId: the Google voice the service narrates with.
     *
     * @param mixed $language
     * @param mixed $voiceid
     * @return array languageCode, name, id (the voice's short name).
     */
    public static function voice_config($language, $voiceid): array {
        $valid = ['Aoede', 'Kore', 'Leda', 'Zephyr', 'Puck', 'Charon', 'Fenrir', 'Orus'];
        $voice = in_array($voiceid, $valid, true) ? $voiceid : 'Aoede';
        $fallbacks = [
            'ms-MY' => ['ms-MY', 'ms-MY-Standard-D'],
            'pa-IN' => ['hi-IN', 'hi-IN-Chirp3-HD-' . $voice],
            'fil-PH' => ['fil-PH', 'fil-PH-Standard-A'],
            'yue-HK' => ['yue-HK', 'yue-HK-Standard-D'],
            'cmn-TW' => ['cmn-CN', 'cmn-CN-Chirp3-HD-' . $voice],
            'pt-PT' => ['pt-BR', 'pt-BR-Chirp3-HD-' . $voice],
            'ca-ES' => ['es-ES', 'es-ES-Chirp3-HD-' . $voice],
            'is-IS' => ['is-IS', 'is-IS-Standard-A'],
        ];
        $key = self::key_string($language);
        if (isset($fallbacks[$key])) {
            return ['languageCode' => $fallbacks[$key][0], 'name' => $fallbacks[$key][1], 'id' => $voice];
        }
        $code = self::VOICE_LANGUAGES[$key] ?? 'en-AU';
        return ['languageCode' => $code, 'name' => $code . '-Chirp3-HD-' . $voice, 'id' => $voice];
    }

    /**
     * getSpellingInstructions.
     *
     * @param mixed $country
     * @return string
     */
    protected static function spelling($country): string {
        if (!self::js_truthy($country)) {
            return '';
        }
        $c = self::lower(self::js_str($country));
        if ($c === 'australia' || $c === 'au') {
            return ('

LANGUAGE REQUIREMENTS (CRITICAL - Australian English):
Use Australian English spelling and terminology throughout ALL content:
- organisation (not organization), behaviour (not behavior), colour (not color)
- centre (not center), licence (noun)/license (verb), defence (not defense)  '
                . "\n" . '- programme (not program for courses), labour (not labor), honour (not honor)
- realise (not realize), analyse (not analyze), recognise (not recognize)
- Use Australian terminology where relevant (e.g., "superannuation" for retirement savings)');
        } else if ($c === 'united kingdom' || $c === 'uk') {
            return ('

LANGUAGE REQUIREMENTS (British English):
Use British English spelling throughout all content:
- organisation, behaviour, colour, centre, defence, programme, labour
- -ise endings (organise, realise, recognise) not -ize
- Use British English terminology');
        } else if ($c === 'united states' || $c === 'us' || $c === 'usa') {
            return ('

LANGUAGE REQUIREMENTS (American English):
Use American English spelling throughout all content:
- organization, behavior, color, center, defense, program, labor
- -ize endings (organize, realize, recognize)');
        } else if ($c === 'canada' || $c === 'ca') {
            return ('

LANGUAGE REQUIREMENTS (Canadian English):
Use Canadian English spelling throughout all content:
- Mixed British/American: colour, centre, but organization/organize is acceptable
- -ize or -ise endings both acceptable (organization/organisation)');
        } else if ($c === 'new zealand' || $c === 'nz') {
            return ('

LANGUAGE REQUIREMENTS (New Zealand English):
Use New Zealand English spelling throughout all content (similar to British/Australian):
- organisation, behaviour, colour, centre, defence, programme
- -ise endings (organise, realise, recognise)');
        } else if ($c === 'singapore' || $c === 'sg') {
            return ('

LANGUAGE REQUIREMENTS (Singapore English):
Use British English spelling throughout all content:
- organisation, behaviour, colour, centre, programme');
        }
        return '';
    }

    // JavaScript semantics.

    /**
     * Next random number in [0, 1).
     *
     * @return float
     */
    protected static function random(): float {
        if (self::$random) {
            return (float) (self::$random)();
        }
        return random_int(0, PHP_INT_MAX - 1) / PHP_INT_MAX;
    }

    /**
     * JSON.parse(): objects become string-keyed arrays (an empty one a \stdClass), arrays become lists.
     *
     * @param string $s
     * @return mixed
     * @throws js_error
     */
    public static function parse(string $s) {
        $v = json_decode($s);
        if ($v === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new js_error('SyntaxError: ' . json_last_error_msg());
        }
        return self::tree($v);
    }

    /**
     * Turn decoded JSON objects into arrays.
     *
     * @param mixed $v
     * @return mixed
     */
    protected static function tree($v) {
        if ($v instanceof \stdClass) {
            $vars = get_object_vars($v);
            if (!$vars) {
                return new \stdClass();
            }
            $out = [];
            foreach ($vars as $k => $x) {
                $out[$k] = self::tree($x);
            }
            return $out;
        }
        if (is_array($v)) {
            return array_map([self::class, 'tree'], $v);
        }
        return $v;
    }

    /**
     * A JavaScript array.
     *
     * @param mixed $v
     * @return bool
     */
    protected static function is_array_js($v): bool {
        return is_array($v) && array_is_list($v);
    }

    /**
     * A value whose typeof is "object", other than null.
     *
     * @param mixed $v
     * @return bool
     */
    protected static function is_object_js($v): bool {
        return is_array($v) || $v instanceof \stdClass;
    }

    /**
     * Whether an object has its own property.
     *
     * @param mixed $o
     * @param string $k
     * @return bool
     */
    protected static function has($o, string $k): bool {
        return is_array($o) && !array_is_list($o) && array_key_exists($k, $o);
    }

    /**
     * o.k as JavaScript reads it; undefined is null. Reading from null or undefined is a TypeError.
     *
     * @param mixed $o
     * @param string $k
     * @return mixed
     */
    protected static function prop($o, string $k) {
        if ($o === null) {
            throw js_error::type("Cannot read properties of null (reading '{$k}')");
        }
        if ($k === 'length') {
            if (is_string($o)) {
                return self::len($o);
            }
            if (self::is_array_js($o)) {
                return count($o);
            }
        }
        if (is_array($o)) {
            if (array_is_list($o)) {
                return preg_match('/^(0|[1-9]\d*)$/', $k) ? ($o[(int) $k] ?? null) : null;
            }
            return $o[$k] ?? null;
        }
        return null;
    }

    /**
     * list[i] for a list or a string; reading an index of null or undefined is a TypeError.
     *
     * @param mixed $v
     * @param int|float $i
     * @param bool $strict Throw on null.
     * @return mixed
     */
    protected static function elem($v, $i, bool $strict = false) {
        if ($v === null) {
            if ($strict) {
                throw js_error::type("Cannot read properties of undefined (reading '{$i}')");
            }
            return null;
        }
        $i = (int) $i;
        if (is_array($v)) {
            return array_is_list($v) ? ($v[$i] ?? null) : ($v[$i] ?? null);
        }
        if (is_string($v)) {
            $c = self::slice($v, $i, $i + 1);
            return $c === '' ? null : $c;
        }
        return null;
    }

    /**
     * A property key as JavaScript writes it, for an array index.
     *
     * @param mixed $k
     * @return int|null The index, or null when the key is not an array index.
     */
    protected static function index_of_key($k): ?int {
        if (is_int($k) && $k >= 0) {
            return $k;
        }
        if (is_float($k) && floor($k) === $k && $k >= 0 && $k < 4294967295) {
            return (int) $k;
        }
        if (is_string($k) && preg_match('/^(0|[1-9]\d{0,9})$/', $k)) {
            return (int) $k;
        }
        return null;
    }

    /**
     * list[k] with a key of any type.
     *
     * @param array $list
     * @param mixed $k
     * @return mixed
     */
    protected static function index_get(array $list, $k) {
        $i = self::index_of_key($k);
        return $i === null ? null : ($list[$i] ?? null);
    }

    /**
     * list[k] = v with a key of any type; a key that is not an index sets nothing JSON would keep.
     *
     * @param array $list
     * @param mixed $k
     * @param mixed $v
     */
    protected static function index_set(array &$list, $k, $v): void {
        $i = self::index_of_key($k);
        if ($i !== null) {
            for ($j = count($list); $j < $i; $j++) {
                $list[$j] = null;
            }
            $list[$i] = $v;
        }
    }

    /**
     * [...v].
     *
     * @param mixed $v
     * @return array
     */
    protected static function spread($v): array {
        if (is_array($v)) {
            return array_values($v);
        }
        if (is_string($v)) {
            return preg_split('//u', $v, -1, PREG_SPLIT_NO_EMPTY);
        }
        throw js_error::type('object is not iterable');
    }

    /**
     * A list, for map().
     *
     * @param mixed $v
     * @return array
     */
    protected static function list_of($v): array {
        if (self::is_array_js($v)) {
            return $v;
        }
        throw js_error::type('map is not a function');
    }

    /**
     * Re-index a list.
     *
     * @param array $v
     * @return array
     */
    protected static function clean_list(array $v): array {
        ksort($v);
        return array_values($v);
    }

    /**
     * list.slice(a, b).
     *
     * @param array $list
     * @param int $a
     * @param mixed $b
     * @return array
     */
    protected static function slice_list(array $list, int $a, $b): array {
        $n = count($list);
        $b = self::tonum($b);
        if (is_nan((float) $b)) {
            $b = 0;
        }
        $b = $b < 0 ? max(0, $n + (int) $b) : min((int) $b, $n);
        return array_slice($list, $a, max(0, $b - $a));
    }

    /**
     * a === b, with JavaScript's single number type.
     *
     * @param mixed $a
     * @param mixed $b
     * @return bool
     */
    protected static function strict_eq($a, $b): bool {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return $a == $b;
        }
        return $a === $b;
    }

    /**
     * Number(v) for a relational comparison.
     *
     * @param mixed $v
     * @return int|float
     */
    protected static function tonum($v) {
        if (is_int($v) || is_float($v)) {
            return $v;
        }
        if ($v === null) {
            return 0;
        }
        if (is_bool($v)) {
            return $v ? 1 : 0;
        }
        if (is_array($v) && array_is_list($v)) {
            return self::tonum(implode(',', array_map(function ($x) {
                return $x === null ? '' : self::js_str($x);
            }, $v)));
        }
        if (is_string($v)) {
            $t = self::jtrim($v);
            if ($t === '') {
                return 0;
            }
            if (
                preg_match('/^[+-]?(Infinity|\d+\.?\d*(e[+-]?\d+)?|\.\d+(e[+-]?\d+)?)$/i', $t) && stripos(
                    $t,
                    'infinity'
                ) === false
            ) {
                return $t + 0;
            }
            if (preg_match('/^([+-]?)Infinity$/', $t, $m)) {
                return $m[1] === '-' ? -INF : INF;
            }
            if (preg_match('/^0x[0-9a-f]+$/i', $t)) {
                return hexdec($t);
            }
            return NAN;
        }
        return NAN;
    }

    /**
     * a < b.
     *
     * @param mixed $a
     * @param int|float $b
     * @return bool
     */
    protected static function js_lt($a, $b): bool {
        $a = self::tonum($a);
        return !is_nan((float) $a) && $a < $b;
    }

    /**
     * a > b.
     *
     * @param mixed $a
     * @param int|float $b
     * @return bool
     */
    protected static function js_gt($a, $b): bool {
        $a = self::tonum($a);
        return !is_nan((float) $a) && $a > $b;
    }

    /**
     * a * b.
     *
     * @param mixed $a
     * @param int|float $b
     * @return int|float
     */
    protected static function js_mul($a, $b) {
        return self::tonum($a) * $b;
    }

    /**
     * Math.round().
     *
     * @param float $x
     * @return int
     */
    protected static function js_round(float $x): int {
        return (int) floor($x + 0.5);
    }

    /**
     * parseInt(s, 10).
     *
     * @param string $s
     * @return int|float
     */
    protected static function parse_int(string $s) {
        if (preg_match('/^[' . self::WS . ']*([+-]?\d+)/u', $s, $m)) {
            return (int) $m[1];
        }
        return NAN;
    }

    /**
     * A Set key that keeps "69" and 69 apart, as SameValueZero does.
     *
     * @param mixed $v
     * @return string
     */
    protected static function key_of($v): string {
        if (is_int($v) || is_float($v)) {
            return 'n:' . self::js_str($v);
        }
        return gettype($v) . ':' . (is_scalar($v) ? (string) $v : self::json($v));
    }

    /**
     * A value used as an object key, as String() writes it.
     *
     * @param mixed $v
     * @return string
     */
    protected static function key_string($v): string {
        return self::js_str($v);
    }

    /**
     * Object.values().
     *
     * @param mixed $v
     * @return array
     */
    protected static function object_values($v): array {
        if (is_array($v)) {
            return array_values($v);
        }
        if (is_string($v)) {
            return preg_split('//u', $v, -1, PREG_SPLIT_NO_EMPTY);
        }
        return [];
    }

    /**
     * The first value that is an array, or null.
     *
     * @param array $values
     * @return mixed
     */
    protected static function first_array(array $values) {
        foreach ($values as $v) {
            if (self::is_array_js($v)) {
                return $v;
            }
        }
        return null;
    }

    /**
     * Length in UTF-16 code units.
     *
     * @param string $s
     * @return int
     */
    public static function len(string $s): int {
        return intdiv(strlen(mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')), 2);
    }

    /**
     * s.substring(a, b) in UTF-16 code units.
     *
     * @param string $s
     * @param int $a
     * @param int $b
     * @return string
     */
    public static function slice(string $s, int $a, int $b): string {
        $u = mb_convert_encoding($s, 'UTF-16LE', 'UTF-8');
        $n = intdiv(strlen($u), 2);
        $a = max(0, min($a, $n));
        $b = max(0, min($b, $n));
        return mb_convert_encoding(substr($u, $a * 2, max(0, $b - $a) * 2), 'UTF-8', 'UTF-16LE');
    }

    /**
     * String.prototype.trim().
     *
     * @param string $s
     * @return string
     */
    public static function jtrim(string $s): string {
        return preg_replace('/^[' . self::WS . ']+|[' . self::WS . ']+$/u', '', $s);
    }

    /**
     * String.prototype.toLowerCase().
     *
     * @param string $s
     * @return string
     */
    protected static function lower(string $s): string {
        return mb_strtolower($s, 'UTF-8');
    }

    /**
     * JSON.stringify(v).
     *
     * @param mixed $v
     * @return string
     */
    public static function json($v): string {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
    }

    /**
     * JSON.stringify(v, null, 2).
     *
     * @param mixed $v
     * @param string $indent
     * @return string
     */
    public static function json_pretty($v, string $indent = ''): string {
        $inner = $indent . '  ';
        if ($v instanceof \stdClass) {
            $v = get_object_vars($v);
            if (!$v) {
                return '{}';
            }
        } else if (is_array($v) && array_is_list($v)) {
            if (!$v) {
                return '[]';
            }
            $items = array_map(function ($x) use ($inner) {
                return $inner . self::json_pretty($x, $inner);
            }, $v);
            return "[\n" . implode(",\n", $items) . "\n" . $indent . ']';
        }
        if (is_array($v)) {
            $items = [];
            foreach ($v as $k => $x) {
                $items[] = $inner . self::json((string) $k) . ': ' . self::json_pretty($x, $inner);
            }
            return "{\n" . implode(",\n", $items) . "\n" . $indent . '}';
        }
        return self::json($v);
    }

    // Request checks, as the service's zod schema makes them.

    /**
     * zod's name for a value's type.
     *
     * @param mixed $v
     * @return string
     */
    protected static function z_type($v): string {
        if ($v === null) {
            return 'null';
        }
        if (is_string($v)) {
            return 'string';
        }
        if (is_bool($v)) {
            return 'boolean';
        }
        if (is_int($v) || is_float($v)) {
            return is_nan((float) $v) ? 'nan' : 'number';
        }
        if (self::is_array_js($v)) {
            return 'array';
        }
        return 'object';
    }

    /**
     * zod's invalid-type message.
     *
     * @param string $expected
     * @param mixed $v
     * @return string
     */
    protected static function z_expected(string $expected, $v): string {
        return 'Expected ' . $expected . ', received ' . self::z_type($v);
    }

    /**
     * A string field.
     *
     * @param array $o
     * @param string $key
     * @param array $issues
     * @param array $rules required, default, min, max, trim.
     * @param array $path Path of the object.
     * @return mixed
     */
    protected static function z_string(array $o, string $key, array &$issues, array $rules, array $path = []) {
        $p = array_merge($path, [$key]);
        if (!array_key_exists($key, $o)) {
            if (!empty($rules['required'])) {
                $issues[] = [$p, 'Required'];
            }
            return $rules['default'] ?? null;
        }
        $v = $o[$key];
        if (!is_string($v)) {
            $issues[] = [$p, self::z_expected('string', $v)];
            return null;
        }
        if (!empty($rules['trim'])) {
            $v = self::jtrim($v);
        }
        if (isset($rules['min']) && self::len($v) < $rules['min']) {
            $issues[] = [$p, 'String must contain at least ' . $rules['min'] . ' character(s)'];
        }
        if (isset($rules['max']) && self::len($v) > $rules['max']) {
            $issues[] = [$p, 'String must contain at most ' . $rules['max'] . ' character(s)'];
        }
        return $v;
    }

    /**
     * A number field with a default and a range.
     *
     * @param array $o
     * @param string $key
     * @param array $issues
     * @param int|float $default
     * @param int $min
     * @param int $max
     * @param array $path
     * @return mixed
     */
    protected static function z_number(array $o, string $key, array &$issues, $default, int $min, int $max, array $path = []) {
        $p = array_merge($path, [$key]);
        if (!array_key_exists($key, $o)) {
            return $default;
        }
        $v = $o[$key];
        if (!is_int($v) && !is_float($v)) {
            $issues[] = [$p, self::z_expected('number', $v)];
            return null;
        }
        if ($v < $min) {
            $issues[] = [$p, 'Number must be greater than or equal to ' . $min];
        }
        if ($v > $max) {
            $issues[] = [$p, 'Number must be less than or equal to ' . $max];
        }
        return $v;
    }

    /**
     * A boolean field with a default.
     *
     * @param array $o
     * @param string $key
     * @param array $issues
     * @param bool $default
     * @return mixed
     */
    protected static function z_bool(array $o, string $key, array &$issues, bool $default) {
        if (!array_key_exists($key, $o)) {
            return $default;
        }
        if (!is_bool($o[$key])) {
            $issues[] = [[$key], self::z_expected('boolean', $o[$key])];
            return null;
        }
        return $o[$key];
    }

    /**
     * An enum field with a default.
     *
     * @param array $o
     * @param string $key
     * @param array $issues
     * @param array $options
     * @param string $default
     * @return mixed
     */
    protected static function z_enum(array $o, string $key, array &$issues, array $options, string $default) {
        if (!array_key_exists($key, $o)) {
            return $default;
        }
        $v = $o[$key];
        $list = implode(' | ', array_map(function ($x) {
            return "'" . $x . "'";
        }, $options));
        if (!is_string($v)) {
            $issues[] = [[$key], 'Expected ' . $list . ', received ' . self::z_type($v)];
            return null;
        }
        if (!in_array($v, $options, true)) {
            $issues[] = [[$key], 'Invalid enum value. Expected ' . $list . ", received '" . $v . "'"];
            return null;
        }
        return $v;
    }

    /**
     * An optional array field (default empty) whose items are checked by a callback.
     *
     * @param array $o
     * @param string $key
     * @param array $issues
     * @param callable $item fn($value, array $path)
     * @return array
     */
    protected static function z_array(array $o, string $key, array &$issues, callable $item): array {
        if (!array_key_exists($key, $o)) {
            return [];
        }
        $v = $o[$key];
        if (!self::is_array_js($v)) {
            $issues[] = [[$key], self::z_expected('array', $v)];
            return [];
        }
        $out = [];
        foreach ($v as $i => $x) {
            $out[] = $item($x, [$key, $i]);
        }
        return $out;
    }


    // JavaScript semantics used by the ported prompt templates (generated with the port).

    /**
     * Js truthy.
     *
     * @param mixed $v
     * @return bool JavaScript truthiness.
     */
    private static function js_truthy($v): bool {
        if (is_array($v) || is_object($v)) {
            return true;
        }
        if (is_float($v) && is_nan($v)) {
            return false;
        }
        return $v !== null && $v !== false && $v !== '' && $v !== 0 && $v !== 0.0;
    }

    /**
     * Js or.
     *
     * @param mixed $a
     * @param mixed $b
     * @return mixed a || b
     */
    private static function js_or($a, $b) {
        return self::js_truthy($a) ? $a : $b;
    }

    /**
     * Js and.
     *
     * @param mixed $a
     * @param mixed $b
     * @return mixed a && b
     */
    private static function js_and($a, $b) {
        return self::js_truthy($a) ? $b : $a;
    }

    /**
     * Js add.
     *
     * @param mixed $a
     * @param mixed $b
     * @return mixed a + b
     */
    private static function js_add($a, $b) {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return $a + $b;
        }
        return self::js_str($a) . self::js_str($b);
    }

    /**
     * Js str.
     *
     * @param mixed $v
     * @return string String(v)
     */
    private static function js_str($v): string {
        if ($v === null) {
            return 'null';
        }
        if ($v === true) {
            return 'true';
        }
        if ($v === false) {
            return 'false';
        }
        if (is_float($v)) {
            if (floor($v) == $v && abs($v) < 1e15) {
                return (string) (int) $v;
            }
            return rtrim(rtrim(sprintf('%.15F', $v), '0'), '.');
        }
        if ($v instanceof \stdClass) {
            return '[object Object]';
        }
        if (is_array($v)) {
            if ($v === [] || array_keys($v) === range(0, count($v) - 1)) {
                return implode(',', array_map(function ($x) {
                    return $x === null ? '' : self::js_str($x);
                }, $v));
            }
            return '[object Object]';
        }
        return (string) $v;
    }

    /**
     * Js num.
     *
     * @param mixed $v
     * @return float Number(v)
     */
    private static function js_num($v) {
        if (is_int($v) || is_float($v)) {
            return $v;
        }
        if ($v === null || $v === false || (is_string($v) && trim($v) === '')) {
            return 0;
        }
        if ($v === true) {
            return 1;
        }
        return is_numeric(trim((string) $v)) ? trim((string) $v) + 0 : NAN;
    }

    /**
     * Js get.
     *
     * @param mixed $o
     * @param mixed $k
     * @return mixed o[k] or null
     */
    private static function js_get($o, $k) {
        if (is_array($o)) {
            return $o[$k] ?? null;
        }
        if (is_string($o) && is_int($k)) {
            return mb_substr($o, $k, 1);
        }
        return null;
    }

    /**
     * Js len.
     *
     * @param mixed $v
     * @return int .length
     */
    private static function js_len($v): int {
        if (is_array($v)) {
            return count($v);
        }
        return strlen(mb_convert_encoding(self::js_str($v), 'UTF-16LE', 'UTF-8')) / 2;
    }

    /**
     * Js includes.
     *
     * @param mixed $h
     * @param mixed $n
     * @return bool .includes()
     */
    private static function js_includes($h, $n): bool {
        if (is_array($h)) {
            return in_array($n, $h, true);
        }
        return strpos(self::js_str($h), self::js_str($n)) !== false;
    }

    /**
     * Js slice.
     *
     * @param mixed $v
     * @param int $a
     * @param int|null $b
     * @return mixed .slice() on strings (UTF-16) and arrays
     */
    private static function js_slice($v, $a = 0, $b = null) {
        if (is_array($v)) {
            $n = count($v);
            $a = $a < 0 ? max(0, $n + $a) : min($a, $n);
            $b = $b === null ? $n : ($b < 0 ? max(0, $n + $b) : min($b, $n));
            return array_slice($v, $a, max(0, $b - $a));
        }
        $u = mb_convert_encoding(self::js_str($v), 'UTF-16LE', 'UTF-8');
        $n = strlen($u) / 2;
        $a = $a < 0 ? max(0, $n + $a) : min($a, $n);
        $b = $b === null ? $n : ($b < 0 ? max(0, $n + $b) : min($b, $n));
        return mb_convert_encoding(substr($u, $a * 2, max(0, $b - $a) * 2), 'UTF-8', 'UTF-16LE');
    }

    /**
     * Js map.
     *
     * @param mixed $v
     * @param callable $f
     * @return array .map()
     */
    private static function js_map($v, callable $f): array {
        $out = [];
        foreach (array_values((array) $v) as $i => $x) {
            $out[] = $f($x, $i);
        }
        return $out;
    }

    /**
     * Js filter.
     *
     * @param mixed $v
     * @param callable $f
     * @return array .filter()
     */
    private static function js_filter($v, callable $f): array {
        $out = [];
        foreach (array_values((array) $v) as $i => $x) {
            if (self::js_truthy($f($x, $i))) {
                $out[] = $x;
            }
        }
        return $out;
    }

    /**
     * Js typeof.
     *
     * @param mixed $v
     * @return string typeof
     */
    private static function js_typeof($v): string {
        if (is_string($v)) {
            return 'string';
        }
        if (is_int($v) || is_float($v)) {
            return 'number';
        }
        if (is_bool($v)) {
            return 'boolean';
        }
        return $v === null ? 'undefined' : 'object';
    }

    /**
     * Js replace first.
     *
     * @param mixed $s
     * @param string $a
     * @param string $b
     * @return string .replace(string, string)
     */
    private static function js_replace_first($s, $a, $b): string {
        $s = self::js_str($s);
        $p = strpos($s, $a);
        return $p === false ? $s : substr_replace($s, $b, $p, strlen($a));
    }
}
