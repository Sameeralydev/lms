<?php

namespace Tests\Unit;

use App\Domain\Assessments\QuestionDefinition;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class QuestionDefinitionTest extends TestCase
{
    public function test_supported_question_types_are_explicit_and_complete(): void
    {
        $this->assertCount(14, QuestionDefinition::TYPES);
        $this->assertContains('file_response', QuestionDefinition::TYPES);
        $this->assertContains('case_passage', QuestionDefinition::TYPES);
    }

    public function test_single_choice_definition_validates_stable_option_identifiers(): void
    {
        $definition = QuestionDefinition::normalize('single_choice', [
            'prompt' => '<b>Pick one</b>',
            'options' => [['id' => 'a', 'text' => 'A'], ['id' => 'b', 'text' => 'B']],
        ], ['correct_option_ids' => ['b']]);

        $this->assertSame('Pick one', $definition['content']['prompt']);
        $this->assertSame(['b'], $definition['answer_key']['correct_option_ids']);
        $this->assertSame(['a', 'b'], array_column($definition['content']['options'], 'id'));
    }

    public function test_definition_rejects_unknown_answer_option_and_unsafe_identifier(): void
    {
        $this->expectException(ValidationException::class);
        QuestionDefinition::normalize('single_choice', [
            'prompt' => 'Choose',
            'options' => [['id' => '../a', 'text' => 'A'], ['id' => 'b', 'text' => 'B']],
        ], ['correct_option_ids' => ['missing']]);
    }

    public function test_numeric_definition_accepts_signed_expected_values_but_rejects_negative_tolerance(): void
    {
        $definition = QuestionDefinition::normalize(
            'numeric',
            ['prompt' => 'Calculate the signed result'],
            ['value' => '-12.5'],
            ['tolerance' => '0.25'],
        );

        $this->assertSame('-12.5', $definition['answer_key']['value']);
        $this->assertSame('0.25', $definition['scoring_rules']['tolerance']);

        $this->expectException(ValidationException::class);
        QuestionDefinition::normalize(
            'numeric',
            ['prompt' => 'Calculate the result'],
            ['value' => '12.5'],
            ['tolerance' => '-0.25'],
        );
    }

    public function test_all_supported_formats_have_validated_canonical_definitions(): void
    {
        $fixtures = [
            'single_choice' => [['prompt' => 'Choose one', 'options' => [['id' => 'a', 'text' => 'A'], ['id' => 'b', 'text' => 'B']]], ['correct_option_ids' => ['a']]],
            'multiple_choice' => [['prompt' => 'Choose all', 'options' => [['id' => 'a', 'text' => 'A'], ['id' => 'b', 'text' => 'B']]], ['correct_option_ids' => ['a', 'b']]],
            'true_false' => [['prompt' => 'Statement'], ['value' => true]],
            'matching' => [['prompt' => 'Match', 'pairs' => [['id' => 'one', 'left' => 'L1', 'right' => 'R1'], ['id' => 'two', 'left' => 'L2', 'right' => 'R2']]], ['matches' => ['one' => 'one', 'two' => 'two']]],
            'ordering' => [['prompt' => 'Order', 'items' => [['id' => 'one', 'text' => 'First'], ['id' => 'two', 'text' => 'Second']]], ['order' => ['one', 'two']]],
            'fill_blank' => [['prompt' => 'Complete', 'blank_keys' => ['answer']], ['values' => ['answer' => ['accepted']]]],
            'short_text' => [['prompt' => 'Short answer'], ['accepted_answers' => ['accepted']]],
            'long_response' => [['prompt' => 'Explain'], null],
            'numeric' => [['prompt' => 'Calculate'], ['value' => '-1.5']],
            'multiple_blanks' => [['prompt' => 'Complete all', 'blank_keys' => ['first', 'second']], ['values' => ['first' => ['one'], 'second' => ['two']]]],
            'case_passage' => [['prompt' => 'Respond', 'passage' => 'A case description.'], null],
            'image_based' => [['prompt' => 'Use the image', 'image_alt' => 'A described diagram', 'options' => [['id' => 'a', 'text' => 'A'], ['id' => 'b', 'text' => 'B']]], ['correct_option_ids' => ['a']]],
            'file_response' => [['prompt' => 'Upload a response'], null],
            'instructor_review' => [['prompt' => 'Write for review'], null],
        ];

        self::assertSame(QuestionDefinition::TYPES, array_keys($fixtures));

        foreach ($fixtures as $type => [$content, $answerKey]) {
            $normalized = QuestionDefinition::normalize($type, $content, $answerKey);
            self::assertNotEmpty($normalized['content']['prompt'], $type.' retains a prompt');
            self::assertSame($answerKey, $normalized['answer_key'], $type.' preserves its normalized answer key');
        }
    }
}
