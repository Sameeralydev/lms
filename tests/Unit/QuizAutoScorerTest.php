<?php

namespace Tests\Unit;

use App\Domain\Assessments\QuizAutoScorer;
use App\Models\QuizQuestionVersion;
use Tests\TestCase;

class QuizAutoScorerTest extends TestCase
{
    public function test_objective_answers_are_scored_with_integer_cents(): void
    {
        $question = new QuizQuestionVersion;
        $question->setAttribute('question_type', 'true_false');
        $question->answer_key = ['value' => false];
        $question->scoring_rules = [];

        $this->assertSame(125, (new QuizAutoScorer)->score($question, ['value' => false], '1.25'));
        $this->assertSame(0, (new QuizAutoScorer)->score($question, ['value' => true], '1.25'));
    }

    public function test_open_response_requires_instructor_review_and_partial_credit_is_bounded(): void
    {
        $question = new QuizQuestionVersion;
        $question->setAttribute('question_type', 'long_response');
        $question->answer_key = null;
        $question->scoring_rules = [];
        $this->assertNull((new QuizAutoScorer)->score($question, ['text' => 'answer'], '3.00'));

        $partial = new QuizQuestionVersion;
        $partial->setAttribute('question_type', 'ordering');
        $partial->answer_key = ['order' => ['a', 'b']];
        $partial->scoring_rules = ['partial_credit' => true];
        $this->assertSame(50, (new QuizAutoScorer)->score($partial, ['order' => ['a', 'x']], '1.00'));
    }

    public function test_short_text_accepted_answers_are_autograded_using_the_case_policy(): void
    {
        $question = new QuizQuestionVersion;
        $question->setAttribute('question_type', 'short_text');
        $question->answer_key = ['accepted_answers' => ['Cairo', 'Al-Qahira']];
        $question->scoring_rules = ['case_sensitive' => false];

        $this->assertSame(200, (new QuizAutoScorer)->score($question, ['text' => 'CAIRO'], '2.00'));
        $this->assertSame(0, (new QuizAutoScorer)->score($question, ['text' => 'Alexandria'], '2.00'));
        $question->scoring_rules = ['case_sensitive' => true];
        $this->assertSame(0, (new QuizAutoScorer)->score($question, ['text' => 'CAIRO'], '2.00'));
    }

    public function test_numeric_answers_support_negative_values_and_non_negative_tolerance(): void
    {
        $question = new QuizQuestionVersion;
        $question->setAttribute('question_type', 'numeric');
        $question->answer_key = ['value' => '-1.5'];
        $question->scoring_rules = ['tolerance' => '0.1'];

        $this->assertSame(250, (new QuizAutoScorer)->score($question, ['value' => '-1.45'], '2.50'));
        $this->assertSame(0, (new QuizAutoScorer)->score($question, ['value' => '1.45'], '2.50'));
    }

    public function test_every_objective_question_format_scores_a_correct_response(): void
    {
        $fixtures = [
            ['single_choice', ['correct_option_ids' => ['a']], ['selected_ids' => ['a']]],
            ['multiple_choice', ['correct_option_ids' => ['a', 'b']], ['selected_ids' => ['a', 'b']]],
            ['true_false', ['value' => true], ['value' => true]],
            ['matching', ['matches' => ['one' => 'two', 'two' => 'one']], ['matches' => ['one' => 'two', 'two' => 'one']]],
            ['ordering', ['order' => ['two', 'one']], ['order' => ['two', 'one']]],
            ['fill_blank', ['values' => ['answer' => ['accepted']]], ['values' => ['answer' => 'accepted']]],
            ['multiple_blanks', ['values' => ['first' => ['one'], 'second' => ['two']]], ['values' => ['first' => 'one', 'second' => 'two']]],
            ['numeric', ['value' => '-1.5'], ['value' => '-1.5']],
            ['short_text', ['accepted_answers' => ['accepted']], ['text' => 'ACCEPTED']],
            ['image_based', ['correct_option_ids' => ['a']], ['selected_ids' => ['a']]],
        ];

        foreach ($fixtures as [$type, $answerKey, $response]) {
            $question = new QuizQuestionVersion;
            $question->setAttribute('question_type', $type);
            $question->answer_key = $answerKey;
            $question->scoring_rules = ['tolerance' => '0', 'case_sensitive' => false];

            self::assertSame(125, (new QuizAutoScorer)->score($question, $response, '1.25'), $type);
        }
    }
}
