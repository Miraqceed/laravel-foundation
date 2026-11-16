<?php

namespace Miraqceed\Foundation\Tests\Unit\Support;

use InvalidArgumentException;
use Miraqceed\Foundation\Support\UnsignedInteger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stringable;

class UnsignedIntegerTest extends TestCase
{
    public function test_addition_normalizes_and_preserves_carries_beyond_native_integer_precision(): void
    {
        $this->assertSame('0', UnsignedInteger::add(0, '000'));
        $this->assertSame('42', UnsignedInteger::add('00012', 30));
        $this->assertSame((string) PHP_INT_MAX, UnsignedInteger::add(PHP_INT_MAX - 1, 1));
        $this->assertSame(PHP_INT_SIZE === 8 ? '9223372036854775808' : '2147483648', UnsignedInteger::add(PHP_INT_MAX, 1));
        $this->assertSame('18446744073709551616', UnsignedInteger::add('18446744073709551615', 1));
        $this->assertSame('1'.str_repeat('0', 1024), UnsignedInteger::add(str_repeat('9', 1024), 1));
    }

    public function test_multiplication_is_exact_for_zero_normalized_and_large_operands(): void
    {
        $this->assertSame('0', UnsignedInteger::multiply('000', PHP_INT_MAX));
        $this->assertSame('36', UnsignedInteger::multiply('00012', 3));
        $this->assertSame((string) PHP_INT_MAX, UnsignedInteger::multiply(PHP_INT_MAX, 1));
        $this->assertSame('1219326311370217952237463801111263526900', UnsignedInteger::multiply('12345678901234567890', '98765432109876543210'));
        $this->assertSame('8'.str_repeat('9', 511).'1', UnsignedInteger::multiply(str_repeat('9', 512), 9));
    }

    public function test_small_calculations_match_independent_native_integer_results(): void
    {
        foreach ([0, 1, 9, 10, 97, 999, 123456, 999999] as $left) {
            foreach ([0, 1, 7, 99, 10001, 999999] as $right) {
                $this->assertSame((string) ($left + $right), UnsignedInteger::add($left, $right));
                $this->assertSame((string) ($left * $right), UnsignedInteger::multiply($left, $right));
                $this->assertSame($left <=> $right, UnsignedInteger::compare($left, $right));
            }
        }
    }

    #[DataProvider('roundedValues')]
    public function test_ceiling_division_uses_decimal_places_without_constructing_the_divisor(mixed $value, int $scale, string $expected): void
    {
        $this->assertSame($expected, UnsignedInteger::ceilDivideByPowerOfTen($value, $scale));
    }

    public static function roundedValues(): array
    {
        return [
            [0, PHP_INT_MAX, '0'], ['000', 0, '0'], ['000123', 0, '123'],
            ['1200', 2, '12'], ['1201', 2, '13'], ['9999', 1, '1000'],
            ['1', 1, '1'], ['1000', 4, '1'], ['1000', 5, '1'],
            ['0001', PHP_INT_MAX, '1'],
            ['123456789012345678901234567890', 10, '12345678901234567891'],
        ];
    }

    public function test_comparison_does_not_convert_similarly_sized_digit_strings_to_numbers(): void
    {
        $this->assertSame(0, UnsignedInteger::compare('000123', 123));
        $this->assertSame(-1, UnsignedInteger::compare('999', '1000'));
        $this->assertSame(1, UnsignedInteger::compare('1000', '999'));
        $prefix = str_repeat('9', 100);
        $this->assertSame(-1, UnsignedInteger::compare($prefix.'1', $prefix.'2'));
        $this->assertSame(1, UnsignedInteger::compare($prefix.'2', $prefix.'1'));
        $this->assertSame(0, UnsignedInteger::compare($prefix, $prefix));
    }

    #[DataProvider('invalidValues')]
    public function test_invalid_operands_are_rejected_in_every_position_without_weak_scalar_coercion(mixed $value): void
    {
        foreach (['add', 'multiply', 'compare'] as $method) {
            $this->assertInvalid(fn () => UnsignedInteger::$method($value, 0));
            $this->assertInvalid(fn () => UnsignedInteger::$method(0, $value));
        }
        $this->assertInvalid(fn () => UnsignedInteger::ceilDivideByPowerOfTen($value, 0));
    }

    public static function invalidValues(): array
    {
        return array_map(fn ($value) => [$value], [
            -1, 1.0, -1.5, INF, NAN, true, false, null, [], new \stdClass,
            '', '-1', '+1', ' 1', '1 ', "1\n", "1\0", '1.0', '1e3', '１２', '١٢', 'private-input',
        ]);
    }

    #[DataProvider('invalidScales')]
    public function test_scale_requires_an_actual_nonnegative_int(mixed $scale): void
    {
        $this->assertInvalid(fn () => UnsignedInteger::ceilDivideByPowerOfTen(1, $scale));
    }

    public static function invalidScales(): array
    {
        return array_map(fn ($value) => [$value], [-1, 1.0, INF, NAN, true, false, null, '1', '0', [], new \stdClass]);
    }

    public function test_objects_are_not_stringified_and_errors_never_echo_inputs(): void
    {
        $object = new class implements Stringable {
            public function __toString(): string { throw new \LogicException('must not stringify private input'); }
        };
        $this->assertInvalid(fn () => UnsignedInteger::add($object, 1));
        $this->assertInvalid(fn () => UnsignedInteger::add('private-input-123', 1));
        $this->assertInvalid(fn () => UnsignedInteger::ceilDivideByPowerOfTen(1, 'private-scale-456'));
    }

    public function test_resources_are_rejected_without_casting(): void
    {
        $resource = fopen('php://memory', 'r+');
        try {
            foreach (['add', 'multiply', 'compare'] as $method) {
                $this->assertInvalid(fn () => UnsignedInteger::$method($resource, 0));
                $this->assertInvalid(fn () => UnsignedInteger::$method(0, $resource));
            }
            $this->assertInvalid(fn () => UnsignedInteger::ceilDivideByPowerOfTen($resource, 0));
            $this->assertInvalid(fn () => UnsignedInteger::ceilDivideByPowerOfTen(1, $resource));
        } finally {
            fclose($resource);
        }
    }

    private function assertInvalid(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $error) {
            $this->assertContains($error->getMessage(), ['Expected a nonnegative integer or ASCII digit string.', 'Scale must be a nonnegative integer.']);
        }
    }
}
