<?php

declare(strict_types=1);

namespace DaemsModule\Members\Application\Backstage\GetApplicationDetail;

final class GetApplicationDetailOutput
{
    /**
     * @param 'member'|'supporter' $type
     * @param array<string, mixed> $application
     */
    public function __construct(
        public readonly string $type,
        public readonly array $application,
    ) {}

    /**
     * @return array{type: string, application: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'type'        => $this->type,
            'application' => $this->application,
        ];
    }
}
