<?php

namespace App\Exceptions;

use Exception;

class GeoServerException extends Exception
{
    /**
     * Create a new GeoServer exception instance.
     */
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Create a workspace creation exception.
     */
    public static function workspaceCreationFailed(string $workspace, ?\Throwable $previous = null): static
    {
        return new static("Failed to create workspace: {$workspace}", 0, $previous);
    }

    /**
     * Create a datastore creation exception.
     */
    public static function datastoreCreationFailed(string $datastore, ?\Throwable $previous = null): static
    {
        return new static("Failed to create datastore: {$datastore}", 0, $previous);
    }

    /**
     * Create a layer publishing exception.
     */
    public static function layerPublishFailed(string $layer, ?\Throwable $previous = null): static
    {
        return new static("Failed to publish layer: {$layer}", 0, $previous);
    }

    /**
     * Create a layer deletion exception.
     */
    public static function layerDeletionFailed(string $layer, ?\Throwable $previous = null): static
    {
        return new static("Failed to delete layer: {$layer}", 0, $previous);
    }

    /**
     * Create a style update exception.
     */
    public static function styleUpdateFailed(string $style, ?\Throwable $previous = null): static
    {
        return new static("Failed to update style: {$style}", 0, $previous);
    }

    /**
     * Create a connection exception.
     */
    public static function connectionFailed(?\Throwable $previous = null): static
    {
        return new static('Failed to connect to GeoServer', 0, $previous);
    }
}
