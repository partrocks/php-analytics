<?php

declare(strict_types=1);

namespace PartRocks\Analytics;

final class PartRocksError extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }
}
