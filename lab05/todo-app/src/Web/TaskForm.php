<?php

declare(strict_types=1);

namespace Todo\Web;

use Todo\Priority;
use Todo\Task;
use Todo\TaskList;

// --- строки формы -> список задач ---
final class TaskForm
{
    /**
     * @param array<int, array{title?: string, priority?: string, done?: string}> $rows
     *        строка: title, priority ('1' | '2' | '3'), done ('1', если отмечено)
     */
    public static function fromRows(array $rows): TaskList
    {
        $list = new TaskList();

        foreach ($rows as $row) {
            // --- пустые и битые строки формы пропускаются ---
            if (!is_array($row)) {
                continue;
            }
            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            // --- приоритет: неизвестное значение -> Normal ---
            $priority = Priority::tryFrom((int) ($row['priority'] ?? 0)) ?? Priority::Normal;

            // --- дубль названия: TaskList бросает DomainException -> строка пропускается ---
            try {
                $list->add(new Task($title, $priority));
            } catch (\DomainException) {
                continue;
            }
            if (($row['done'] ?? '') === '1') {
                $list->complete($title);
            }
        }

        return $list;
    }
}
