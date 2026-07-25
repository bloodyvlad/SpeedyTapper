<?php

declare(strict_types=1);

namespace SpeedyTapper;

final class GameCenterAppleApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable,
        public readonly ?int $httpStatus = null,
        public readonly ?string $appleCode = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
