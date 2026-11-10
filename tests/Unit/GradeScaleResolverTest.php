<?php

namespace Tests\Unit;

use App\Domain\Grading\GradeScaleResolver;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GradeScaleResolverTest extends TestCase
{
    public function test_numeric_and_percentage_scales_are_decimal_safe(): void
    {
        $resolver = new GradeScaleResolver;

        self::assertSame(['scale_result' => '93.25%', 'grade_points' => null, 'passed' => null], $resolver->resolve(9325, 'percentage', []));
        self::assertSame(['scale_result' => '93.25', 'grade_points' => null, 'passed' => null], $resolver->resolve(9325, 'numeric', []));
    }

    public function test_letter_scale_uses_the_highest_institution_configured_threshold(): void
    {
        $bands = [
            ['label' => 'A', 'minimum_percentage_basis_points' => 9000, 'grade_points' => '4.000', 'passing' => null],
            ['label' => 'B', 'minimum_percentage_basis_points' => 8000, 'grade_points' => '3.000', 'passing' => null],
            ['label' => 'F', 'minimum_percentage_basis_points' => 0, 'grade_points' => '0.000', 'passing' => null],
        ];

        self::assertSame(['scale_result' => 'B', 'grade_points' => '3.000', 'passed' => null], (new GradeScaleResolver)->resolve(8999, 'letter', $bands));
    }

    public function test_pass_fail_scale_preserves_configured_boundary(): void
    {
        $bands = [
            ['label' => 'Pass', 'minimum_percentage_basis_points' => 6000, 'grade_points' => null, 'passing' => true],
            ['label' => 'Fail', 'minimum_percentage_basis_points' => 0, 'grade_points' => null, 'passing' => false],
        ];
        $resolver = new GradeScaleResolver;

        self::assertSame(['scale_result' => 'Pass', 'grade_points' => null, 'passed' => true], $resolver->resolve(6000, 'pass_fail', $bands));
        self::assertSame(['scale_result' => 'Fail', 'grade_points' => null, 'passed' => false], $resolver->resolve(5999, 'pass_fail', $bands));
    }

    /** @param list<array{label:string,minimum_percentage_basis_points:int,grade_points:?string,passing:?bool}> $bands */
    #[DataProvider('invalidBandSets')]
    public function test_incomplete_or_invalid_scale_definitions_are_rejected(string $type, array $bands): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new GradeScaleResolver)->assertValidBands($type, $bands);
    }

    /** @return iterable<string, array{string, list<array{label:string,minimum_percentage_basis_points:int,grade_points:?string,passing:?bool}>}> */
    public static function invalidBandSets(): iterable
    {
        yield 'missing zero floor' => ['letter', [['label' => 'A', 'minimum_percentage_basis_points' => 9000, 'grade_points' => '4.0', 'passing' => null], ['label' => 'F', 'minimum_percentage_basis_points' => 500, 'grade_points' => '0.0', 'passing' => null]]];
        yield 'duplicate threshold' => ['letter', [['label' => 'A', 'minimum_percentage_basis_points' => 9000, 'grade_points' => '4.0', 'passing' => null], ['label' => 'B', 'minimum_percentage_basis_points' => 9000, 'grade_points' => '3.0', 'passing' => null]]];
        yield 'pass fail missing outcome' => ['pass_fail', [['label' => 'Pass', 'minimum_percentage_basis_points' => 5000, 'grade_points' => null, 'passing' => true], ['label' => 'Also pass', 'minimum_percentage_basis_points' => 0, 'grade_points' => null, 'passing' => true]]];
    }
}
