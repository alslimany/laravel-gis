<?php

namespace Tests\Unit;

use App\Services\ExpressionEvaluator;
use PHPUnit\Framework\TestCase;

class ExpressionEvaluatorTest extends TestCase
{
    public function test_evaluates_arithmetic_with_fields(): void
    {
        $evaluator = new ExpressionEvaluator();
        $result = $evaluator->evaluate('width * height', [
            'width' => 10,
            'height' => 5,
        ]);

        $this->assertSame(50, $result);
    }

    public function test_rejects_disallowed_tokens(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ExpressionEvaluator())->evaluate('exec("rm")', ['a' => 1]);
    }

    public function test_rejects_unknown_fields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ExpressionEvaluator())->evaluate('missing + 1', ['a' => 1]);
    }
}
