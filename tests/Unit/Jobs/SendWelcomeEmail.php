<?php

declare(strict_types=1);

namespace Orch8\Tests\Unit\Jobs;

use Orch8\Jobs\Orch8Job;
use Orch8\Model\JobRetry;
use Orch8\Worker\TaskContext;

final class SendWelcomeEmail extends Orch8Job
{
    public function __construct(public readonly int $userId, public readonly string $locale = 'en')
    {
    }

    public function handle(TaskContext $task): array
    {
        return ['sent_to' => $this->userId, 'locale' => $this->locale, 'attempt' => $task->attempt];
    }

    public function retry(): ?JobRetry
    {
        return new JobRetry(3, 1000);
    }
}
