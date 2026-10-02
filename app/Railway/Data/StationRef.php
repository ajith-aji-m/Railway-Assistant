<?php

namespace App\Railway\Data;

final readonly class StationRef
{
    public function __construct(
        public string $code,
        public string $name,
    ) {}
}
