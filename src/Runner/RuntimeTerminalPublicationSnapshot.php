<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Runner;

use Apntalk\EslCore\Vocabulary\TerminalPublication;

final class RuntimeTerminalPublicationSnapshot
{
    public function __construct(
        public readonly TerminalPublication $publication,
        public readonly ?string $operationId = null,
        public readonly ?string $subjectId = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'publication' => $this->publication->toArray(),
            'operation_id' => $this->operationId,
            'subject_id' => $this->subjectId,
        ];
    }
}
