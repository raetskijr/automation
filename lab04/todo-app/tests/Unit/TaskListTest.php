<?php

declare(strict_types=1);

namespace Todo\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Todo\Priority;
use Todo\Task;
use Todo\TaskList;

// --- тесты: TaskList ---
final class TaskListTest extends TestCase
{
    private TaskList $list;

    // --- фикстура: 3 задачи с разным приоритетом ---
    protected function setUp(): void
    {
        $this->list = new TaskList();
        $this->list->add(new Task('полить цветы', Priority::Low));
        $this->list->add(new Task('купить хлеб'));
        $this->list->add(new Task('сдать лабораторную', Priority::High));
    }

    /** @param list<Task> $tasks */
    private static function titles(array $tasks): array
    {
        return array_map(fn (Task $t) => $t->title, $tasks);
    }

    public function testCount(): void
    {
        // --- проверка ---
        $this->assertCount(3, $this->list);
        $this->assertSame(3, $this->list->count());
    }

    public function testDuplicateTitleThrows(): void
    {
        // --- проверка ---
        $this->expectException(\DomainException::class);

        // --- действие ---
        $this->list->add(new Task('купить хлеб', Priority::High));
    }

    public function testCompleteMovesTaskToDone(): void
    {
        // --- действие ---
        $this->list->complete('купить хлеб');

        // --- проверка ---
        $this->assertSame(['купить хлеб'], self::titles($this->list->done()));
        $this->assertSame(['полить цветы', 'сдать лабораторную'], self::titles($this->list->pending()));
    }

    public function testCompleteUnknownThrows(): void
    {
        // --- проверка ---
        $this->expectException(\DomainException::class);

        // --- действие ---
        $this->list->complete('нет такой');
    }

    public function testByPriorityHighFirst(): void
    {
        // --- дано ---
        $this->list->add(new Task('ответить на почту'));

        // --- действие ---
        $sorted = $this->list->byPriority();

        // --- проверка ---
        $this->assertSame(
            ['сдать лабораторную', 'купить хлеб', 'ответить на почту', 'полить цветы'],
            self::titles($sorted),
        );
    }

    public function testPendingKeepsInsertionOrder(): void
    {
        // --- дано ---
        $this->list->add(new Task('вынести мусор', Priority::High));

        // --- действие ---
        $this->list->complete('полить цветы');

        // --- проверка ---
        $this->assertSame(
            ['купить хлеб', 'сдать лабораторную', 'вынести мусор'],
            self::titles($this->list->pending()),
        );
    }
}
