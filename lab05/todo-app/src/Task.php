<?php

declare(strict_types=1);

namespace Todo;

// --- задача ---
final class Task
{
    private bool $done = false;

    public function __construct(
        public readonly string $title,
        public readonly Priority $priority = Priority::Normal,
    ) {
        // --- проверка ---
        if (trim($title) === '') {
            throw new \InvalidArgumentException('пустое название задачи');
        }
    }

    public function isDone(): bool
    {
        return $this->done;
    }

    public function markDone(): void
    {
        $this->done = true;
    }
}
