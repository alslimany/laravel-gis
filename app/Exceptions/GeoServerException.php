<?php

namespace App\Exceptions;

use Exception;

class GeoServerException extends Exception
{
    /**
     * Create a new GeoServer exception instance.
     *
     * @param string $message
     * @param int $code
     * @param \Throwable|null $previous
     */
    public function __construct(string $message = "", int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Create a workspace creation exception.
     *
     * @param string $workspace
     * @param \Throwable|null $previous
     * @return static
     */
    public static function workspaceCreationFailed(string $workspace, ?\Throwable $previous = null): static
    {
        return new static("Failed to create workspace: {$workspace}", 0, $previous);
    }

    /**
     * Create a datastore creation exception.
     *
     * @param string $datastore
     * @param \Throwable|null $previous
     * @return static
     */
    public static function datastoreCreationFailed(string $datastore, ?\Throwable $previous = null): static
    {
        return new static("Failed to create datastore: {$datastore}", 0, $previous);
    }

    /**
     * Create a layer publishing exception.
     *
     * @param string $layer
     * @param \Throwable|null $previous
     * @return static
     */
    public static function layerPublishFailed(string $layer, ?\Throwable $previous = null): static
    {
        return new static("Failed to publish layer: {$layer}", 0, $previous);
    }

    /**
     * Create a layer deletion exception.
     *
     * @param string $layer
     * @param \Throwable|null $previous
     * @return static
     */
    public static function layerDeletionFailed(string $layer, ?\Throwable $previous = null): static
    {
        return new static("Failed to delete layer: {$layer}", 0, $previous);
    }

    /**
     * Create a style update exception.
     *
     * @param string $style
     * @param \Throwable|null $previous
     * @return static
     */
    public static function styleUpdateFailed(string $style, ?\Throwable $previous = null): static
    {
        return new static("Failed to update style: {$style}", 0, $previous);
    }

    /**
     * Create a connection exception.
     *
     * @param \Throwable|null $previous
     * @return static
     */
    public static function connectionFailed(?\Throwable $previous = null): static
    {
        return new static("Failed to connect to GeoServer", 0, $previous);
    }
}
