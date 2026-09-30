<?php

declare(strict_types=1);

namespace App\DTOs\HBX;

final class ContentImportResult
{
    public const IMPORTED = 'IMPORTED';

    public const UPDATED = 'UPDATED';

    public const UNCHANGED = 'UNCHANGED';

    /**
     * @param  list<string>  $conflicts
     * @param  list<string>  $unmatchedWildcards
     */
    public function __construct(
        public readonly string $status,
        public readonly int $hotelCode,
        public readonly string $language,
        public readonly int $rooms,
        public readonly int $images,
        public readonly int $facilities,
        public readonly int $snapshotBytes,
        public readonly string $hash,
        public readonly array $conflicts = [],
        public readonly array $unmatchedWildcards = [],
    ) {}
}
