<?php

namespace App\Services\Warmup\Exceptions;

use RuntimeException;
use Throwable;

final class WarmupGenerationException extends RuntimeException
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public readonly int $quarter,
        public readonly array $context,
        Throwable $previous,
    ) {
        parent::__construct("CF Manufacturing warm-up failed in Quarter {$quarter}: {$previous->getMessage()}", 0, $previous);
    }
}
