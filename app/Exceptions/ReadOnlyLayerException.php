<?php

namespace App\Exceptions;

/**
 * Raised when something tries to add, edit or delete features on a layer
 * that is backed by a SQL view (metadata.sql_view = true). Those layers are
 * computed from other data, so they are read-only.
 *
 * Extends SchemaValidationException so every existing feature-write handler
 * (and the global renderer) answers with a 422 and the message below.
 */
class ReadOnlyLayerException extends SchemaValidationException
{
    public const MESSAGE = 'هذه الطبقة للعرض فقط ولا يمكن تعديل معالمها.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE, ['layer' => self::MESSAGE]);
    }
}
