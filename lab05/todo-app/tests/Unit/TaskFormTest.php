<?php

declare(strict_types=1);

namespace Todo\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Todo\Priority;
use Todo\Web\TaskForm;

// --- тесты: TaskForm ---
final class TaskFormTest extends TestCase
{
    public function testEmptyRowsAreSkipped(): void
    {
        // --- дано ---
        $rows = [
            ['title' => '', 'priority' => '2'],
            ['title' => '   ', 'priority' => '3'],
            ['title' => 'купить хлеб', 'priority' => '2'],
        ];

        // --- действие ---
        $list = TaskForm::fromRows($rows);

        // --- проверка ---
        $this->assertCount(1, $list);
    }

    public function testDoneFlagAndPriorityMapped(): void
    {
        // --- дано ---
        $rows = [
            ['title' => 'отчёт', 'priority' => '3'],
            ['title' => 'почта', 'priority' => '1', 'done' => '1'],
            ['title' => 'звонок', 'priority' => '9'],
        ];

        // --- действие ---
        $list = TaskForm::fromRows($rows);

        // --- проверка ---
        $pending = $list->pending();
        $done = $list->done();
        $this->assertSame(Priority::High, $pending[0]->priority);
        $this->assertSame(Priority::Normal, $pending[1]->priority);
        $this->assertSame('почта', $done[0]->title);
        $this->assertSame(Priority::Low, $done[0]->priority);
    }
}
