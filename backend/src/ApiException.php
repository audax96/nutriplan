<?php
declare(strict_types=1);

namespace NutriPlan;

/** An error that is safe to show to the app user. */
final class ApiException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $extra = []
    ) {
        parent::__construct($message);
    }
}
