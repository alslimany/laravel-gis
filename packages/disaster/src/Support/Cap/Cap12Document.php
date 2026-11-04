<?php

namespace Lumina\Disaster\Support\Cap;

/**
 * Immutable CAP 1.2 alert document parameters used by Cap12Builder.
 *
 * @phpstan-type InfoBlock array{
 *     language: string,
 *     category: string,
 *     event: string,
 *     urgency: string,
 *     severity: string,
 *     certainty: string,
 *     headline: string,
 *     description: string,
 *     expires?: string|null,
 *     area_desc?: string|null,
 *     polygon?: list<array{0: float, 1: float}>|null
 * }
 */
final class Cap12Document
{
    /**
     * @param  list<InfoBlock>  $infoBlocks
     */
    public function __construct(
        public string $identifier,
        public string $sender,
        public string $sent,
        public string $status,
        public string $msgType,
        public string $scope,
        public array $infoBlocks,
        public ?string $references = null,
    ) {}
}
