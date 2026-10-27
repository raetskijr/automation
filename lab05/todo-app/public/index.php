<?php

declare(strict_types=1);

// --- todo-app: точка входа (только контроллер, разметка в views/) ---
// vendor/autoload.php создаёт composer dump-autoload на сервере (deploy/configure.yml)
require __DIR__ . '/../vendor/autoload.php';

use Todo\TaskList;
use Todo\Web\TaskForm;

// --- данные: POST -> список, GET -> пустой список ---
$list = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? TaskForm::fromRows((array) ($_POST['rows'] ?? []))
    : new TaskList();

// --- вывод ---
require __DIR__ . '/../views/board.php';
