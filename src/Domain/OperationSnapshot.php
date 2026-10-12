<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Domain;

final readonly class OperationSnapshot
{
    /** @param array<string, mixed>|null $result */
    public function __construct(
        public string $id,
        public string $scope,
        public OperationStatus $status,
        public ?array $result,
        public ?string $reconciliationReason,
    ) {}
}
