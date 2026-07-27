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
        public readonly ?string $appleTitle = null,
        public readonly ?string $appleDetail = null,
        public readonly ?string $appleSourcePointer = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function operatorDiagnostic(): string
    {
        $parts = ['Apple Game Center submission failed'];
        if ($this->appleCode !== null) {
            $parts[0] .= ' (' . $this->appleCode . ')';
        }
        $parts[0] .= '.';
        if ($this->appleTitle !== null) {
            $parts[] = 'Title: ' . rtrim($this->appleTitle, ". \t\n\r\0\x0B") . '.';
        }
        if ($this->appleDetail !== null) {
            $parts[] = 'Detail: ' . rtrim($this->appleDetail, ". \t\n\r\0\x0B") . '.';
        }
        if ($this->appleSourcePointer !== null) {
            $parts[] = 'Source: ' . $this->appleSourcePointer . '.';
        }
        return mb_strcut(implode(' ', $parts), 0, 500, 'UTF-8');
    }
}
