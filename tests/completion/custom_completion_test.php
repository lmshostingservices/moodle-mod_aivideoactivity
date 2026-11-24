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

/**
 * Tests for the AI Video Activity "all answers correct" completion rule.
 *
 * @package    mod_aivideoactivity
 * @category   test
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_aivideoactivity\completion\custom_completion
 * @covers     \mod_aivideoactivity\task\recalculate_completion
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_aivideoactivity\completion\custom_completion::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_aivideoactivity\task\recalculate_completion::class)]
final class custom_completion_test extends \advanced_testcase {
    /**
     * Creates a course, a learner and an activity with three questions.
     *
     * @param int $rule completionallcorrect value.
     * @return array [course, cm_info, learner, instance, generator]
     */
    protected function setup_activity(int $rule = 1): array {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['enablecompletion' => 1]);
        $learner = $gen->create_and_enrol($course, 'student');
        $instance = $gen->create_module('aivideoactivity', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionallcorrect' => $rule,
        ]);
        $plugin = $gen->get_plugin_generator('mod_aivideoactivity');
        for ($i = 0; $i < 3; $i++) {
            $plugin->create_question(['aivideoactivityid' => $instance->id]);
        }
        $cm = get_fast_modinfo($course)->get_cm($instance->cmid);
        return [$course, $cm, $learner, $instance, $plugin];
    }

    /**
     * The rule is defined, described and read from the cached course module data.
     */
    public function test_rule_definition(): void {
        [, $cm, $learner] = $this->setup_activity();
        $this->assertSame(['completionallcorrect'], custom_completion::get_defined_custom_rules());
        $this->assertEquals(1, $cm->customdata['customcompletionrules']['completionallcorrect']);
        $cc = new custom_completion($cm, (int) $learner->id);
        $this->assertSame(['completionallcorrect'], $cc->get_available_custom_rules());
        $this->assertArrayHasKey('completionallcorrect', $cc->get_custom_rule_descriptions());
    }

    /**
     * Only a finished attempt with every question right completes the activity.
     */
    public function test_get_state(): void {
        [, $cm, $learner, $instance, $plugin] = $this->setup_activity();
        $cc = new custom_completion($cm, (int) $learner->id);
        $base = ['aivideoactivityid' => $instance->id, 'userid' => $learner->id];
        $this->assertSame(COMPLETION_INCOMPLETE, $cc->get_state('completionallcorrect'));

        // Two of three right.
        $plugin->create_attempt($base + ['correctcount' => 2, 'totalcount' => 3]);
        $this->assertSame(COMPLETION_INCOMPLETE, $cc->get_state('completionallcorrect'));

        // All answered right so far, but the attempt is still open.
        $plugin->create_attempt($base + ['correctcount' => 3, 'totalcount' => 3, 'status' => 0]);
        $this->assertSame(COMPLETION_INCOMPLETE, $cc->get_state('completionallcorrect'));

        // Finished, but skipped a question.
        $plugin->create_attempt($base + ['correctcount' => 2, 'totalcount' => 2]);
        $this->assertSame(COMPLETION_INCOMPLETE, $cc->get_state('completionallcorrect'));

        // Finished with all three right.
        $plugin->create_attempt($base + ['correctcount' => 3, 'totalcount' => 3]);
        $this->assertSame(COMPLETION_COMPLETE, $cc->get_state('completionallcorrect'));
    }

    /**
     * An activity with no questions yet cannot be completed by an empty attempt.
     */
    public function test_no_questions(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['enablecompletion' => 1]);
        $learner = $gen->create_and_enrol($course, 'student');
        $instance = $gen->create_module('aivideoactivity', ['course' => $course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionallcorrect' => 1]);
        $gen->get_plugin_generator('mod_aivideoactivity')->create_attempt(
            ['aivideoactivityid' => $instance->id, 'userid' => $learner->id]
        );
        $cm = get_fast_modinfo($course)->get_cm($instance->cmid);
        $this->assertSame(
            COMPLETION_INCOMPLETE,
            (new custom_completion($cm, (int) $learner->id))->get_state('completionallcorrect')
        );
    }

    /**
     * Moodle's own completion API marks the activity complete after a perfect attempt.
     */
    public function test_completion_info_end_to_end(): void {
        [$course, $cm, $learner, $instance, $plugin] = $this->setup_activity();
        $completion = new \completion_info($course);
        $this->assertEquals(COMPLETION_INCOMPLETE, $completion->get_data($cm, false, $learner->id)->completionstate);
        $plugin->create_attempt(['aivideoactivityid' => $instance->id, 'userid' => $learner->id,
            'correctcount' => 3, 'totalcount' => 3]);
        $completion->update_state($cm, COMPLETION_UNKNOWN, $learner->id);
        $this->assertEquals(COMPLETION_COMPLETE, $completion->get_data($cm, false, $learner->id)->completionstate);
    }

    /**
     * With the rule off there is no custom rule to wait for.
     */
    public function test_rule_off(): void {
        [, $cm, $learner] = $this->setup_activity(0);
        $this->assertSame([], (new custom_completion($cm, (int) $learner->id))->get_available_custom_rules());
    }

    /**
     * The upgrade task completes the activity for learners who met the rule before it worked.
     */
    public function test_recalculate_completion_task(): void {
        [$course, $cm, $learner, $instance, $plugin] = $this->setup_activity();
        $pending = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $plugin->create_attempt(['aivideoactivityid' => $instance->id, 'userid' => $learner->id,
            'correctcount' => 3, 'totalcount' => 3]);
        $plugin->create_attempt(['aivideoactivityid' => $instance->id, 'userid' => $pending->id,
            'correctcount' => 1, 'totalcount' => 3]);
        $completion = new \completion_info($course);
        $this->assertEquals(COMPLETION_INCOMPLETE, $completion->get_data($cm, false, $learner->id)->completionstate);

        $this->expectOutputRegex('/re-checked completion for 1 learner/');
        (new \mod_aivideoactivity\task\recalculate_completion())->execute();

        $this->assertEquals(COMPLETION_COMPLETE, $completion->get_data($cm, false, $learner->id)->completionstate);
        $this->assertEquals(COMPLETION_INCOMPLETE, $completion->get_data($cm, false, $pending->id)->completionstate);
    }
}
