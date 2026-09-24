<?php

namespace App\Services;

/**
 * Safe arithmetic evaluator for layer field calculated expressions.
 * Allows only field names, numeric literals, parentheses, and + - * /.
 */
class ExpressionEvaluator
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function evaluate(string $expression, array $attributes): float|int
    {
        $expression = trim($expression);
        if ($expression === '') {
            throw new \InvalidArgumentException('Empty expression.');
        }

        if (! preg_match('/^[a-zA-Z0-9_\s\+\-\*\/\(\)\.]+$/', $expression)) {
            throw new \InvalidArgumentException('Expression contains disallowed characters.');
        }

        $tokens = preg_split('/(\s+|\+|\-|\*|\/|\(|\))/', $expression, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $tokens = array_values(array_filter(array_map('trim', $tokens), fn ($t) => $t !== ''));

        $rpn = $this->toRpn($tokens, $attributes);

        return $this->evalRpn($rpn);
    }

    /**
     * @param  list<string>  $tokens
     * @param  array<string, mixed>  $attributes
     * @return list<float|int|string>
     */
    protected function toRpn(array $tokens, array $attributes): array
    {
        $output = [];
        $ops = [];
        $precedence = ['+' => 1, '-' => 1, '*' => 2, '/' => 2];

        foreach ($tokens as $token) {
            if (is_numeric($token)) {
                $output[] = $token + 0;
                continue;
            }

            if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $token)) {
                if (! array_key_exists($token, $attributes) || ! is_numeric($attributes[$token])) {
                    throw new \InvalidArgumentException("Unknown or non-numeric field: {$token}");
                }
                $output[] = $attributes[$token] + 0;
                continue;
            }

            if ($token === '(') {
                $ops[] = $token;
                continue;
            }

            if ($token === ')') {
                while ($ops !== [] && end($ops) !== '(') {
                    $output[] = array_pop($ops);
                }
                if ($ops === [] || array_pop($ops) !== '(') {
                    throw new \InvalidArgumentException('Mismatched parentheses.');
                }
                continue;
            }

            if (isset($precedence[$token])) {
                while (
                    $ops !== []
                    && end($ops) !== '('
                    && ($precedence[end($ops)] ?? 0) >= $precedence[$token]
                ) {
                    $output[] = array_pop($ops);
                }
                $ops[] = $token;
                continue;
            }

            throw new \InvalidArgumentException("Disallowed token: {$token}");
        }

        while ($ops !== []) {
            $op = array_pop($ops);
            if ($op === '(' || $op === ')') {
                throw new \InvalidArgumentException('Mismatched parentheses.');
            }
            $output[] = $op;
        }

        return $output;
    }

    /**
     * @param  list<float|int|string>  $rpn
     */
    protected function evalRpn(array $rpn): float|int
    {
        $stack = [];
        foreach ($rpn as $token) {
            if (is_numeric($token)) {
                $stack[] = $token + 0;
                continue;
            }

            if (count($stack) < 2) {
                throw new \InvalidArgumentException('Invalid expression.');
            }
            $b = array_pop($stack);
            $a = array_pop($stack);
            $stack[] = match ($token) {
                '+' => $a + $b,
                '-' => $a - $b,
                '*' => $a * $b,
                '/' => $b == 0 ? throw new \InvalidArgumentException('Division by zero.') : $a / $b,
                default => throw new \InvalidArgumentException("Unknown operator: {$token}"),
            };
        }

        if (count($stack) !== 1) {
            throw new \InvalidArgumentException('Invalid expression.');
        }

        return $stack[0];
    }
}
