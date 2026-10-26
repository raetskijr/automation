<?php

declare(strict_types=1);

namespace Todo;

// --- список задач ---
final class TaskList implements \Countable
{
    /** @var array<string, Task> название -> задача, в порядке добавления */
    private array $tasks = [];

    // --- изменение ---

    public function add(Task $task): void
    {
        if (isset($this->tasks[$task->title])) {
            throw new \DomainException("задача уже есть: {$task->title}");
        }
        $this->tasks[$task->title] = $task;
    }

    public function complete(string $title): void
    {
        if (!isset($this->tasks[$title])) {
            throw new \DomainException("нет такой задачи: {$title}");
        }
        $this->tasks[$title]->markDone();
    }

    // --- выборки ---

    /** @return list<Task> */
    public function pending(): array
    {
        return array_values(array_filter($this->tasks, fn (Task $t) => !$t->isDone()));
    }

    /** @return list<Task> */
    public function done(): array
    {
        return array_values(array_filter($this->tasks, fn (Task $t) => $t->isDone()));
    }

    /** @return list<Task> high -> low, внутри приоритета порядок добавления */
    public function byPriority(): array
    {
        $tasks = array_values($this->tasks);
        // usort стабилен (php 8+)
        usort($tasks, fn (Task $a, Task $b) => $b->priority->value <=> $a->priority->value);
        return $tasks;
    }

    // --- countable ---

    public function count(): int
    {
        return count($this->tasks);
    }
}
