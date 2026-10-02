<?php

namespace App\Railway\Data;

final readonly class StationDetail
{
    /**
     * Nullable fields are unknown when the provider does not supply them.
     *
     * @param  list<string>|null  $facilities  Facility keys, e.g. "wifi", "food", "taxi".
     */
    public function __construct(
        public string $code,
        public string $name,
        public ?string $fullName,
        public ?string $city,
        public ?string $state,
        public ?float $lat,
        public ?float $lng,
        public ?int $platforms,
        public ?string $image,
        public ?array $facilities,
    ) {}
}
