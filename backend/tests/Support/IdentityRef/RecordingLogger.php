<?php

namespace Tests\Support\IdentityRef;

use Psr\Log\AbstractLogger;
use Stringable;

/** Keeps every log record in memory so tests can prove what was (not) logged. */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    public function text(): string
    {
        return (string) json_encode($this->records, JSON_UNESCAPED_UNICODE);
    }

    /** @return list<string> */
    public function messages(): array
    {
        return array_column($this->records, 'message');
    }
}
