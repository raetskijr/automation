<?php

declare(strict_types=1);

namespace Todo;

// --- приоритет задачи ---
enum Priority: int
{
    case Low = 1;
    case Normal = 2;
    case High = 3;
}
