<?php

namespace App\Exceptions;

/**
 * Structured 422 for feature writes that violate the layer schema.
 *
 * Carries a field => message map so controllers can render per-field errors
 * instead of a generic 500.
 */
class SchemaValidationException extends \InvalidArgumentException
{
    /**
     * @param  array<string, string>  $errors
     */
    public function __construct(
        string $message,
        public array $errors = []
    ) {
        parent::__construct($message);
    }
}
