<?php

declare(strict_types=1);

namespace Todo\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Todo\Priority;
use Todo\Task;

// --- тесты: Task ---
final class TaskTest extends TestCase
{
    public function testNewTaskIsNotDone(): void
    {
        // --- дано ---
        $task = new Task('купить молоко');

        // --- проверка ---
        $this->assertSame(false, $task->isDone());
    }

    public function testDefaultPriorityIsNormal(): void
    {
        // --- дано ---
        $task = new Task('позвонить');

        // --- проверка ---
        $this->assertSame(Priority::Normal, $task->priority);
    }

    public function testMarkDone(): void
    {
        // --- дано ---
        $task = new Task('сдать лабораторную', Priority::High);

        // --- действие ---
        $task->markDone();

        // --- проверка ---
        $this->assertSame(true, $task->isDone());
    }

    public function testEmptyTitleThrows(): void
    {
        // --- проверка ---
        $this->expectException(\InvalidArgumentException::class);

        // --- действие ---
        new Task('   ');
    }
}
