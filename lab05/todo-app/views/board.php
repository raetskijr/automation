<?php

declare(strict_types=1);

// --- шаблон страницы: доска задач ---
// вход: $list (Todo\TaskList), всё выводится через $e()

use Todo\Priority;

/** @var \Todo\TaskList $list */

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

// --- подписи приоритетов ---
$labels = [
    Priority::Low->value => 'низкий',
    Priority::Normal->value => 'обычный',
    Priority::High->value => 'высокий',
];

// --- колонка "в работе": порядок byPriority(), без готовых ---
$inWork = array_values(array_filter($list->byPriority(), fn ($t) => !$t->isDone()));
$done = $list->done();
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>todo-app</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<header class="bar">
    <h1>todo-app</h1>
    <p class="counters">всего <?= count($list) ?> · готово <?= count($done) ?></p>
</header>

<main>
    <!-- [ форма: 5 строк ] -->
    <form method="post" class="form">
        <?php for ($i = 0; $i < 5; $i++): ?>
            <div class="row">
                <input type="text" name="rows[<?= $i ?>][title]" placeholder="задача <?= $i + 1 ?>">
                <select name="rows[<?= $i ?>][priority]">
                    <?php foreach ($labels as $value => $label): ?>
                        <option value="<?= $value ?>"<?= $value === Priority::Normal->value ? ' selected' : '' ?>><?= $e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <label><input type="checkbox" name="rows[<?= $i ?>][done]" value="1"> готово</label>
            </div>
        <?php endfor; ?>
        <button type="submit">показать доску</button>
    </form>

    <!-- [ доска: две колонки ] -->
    <section class="board">
        <div class="column">
            <h2>в работе</h2>
            <ul>
                <?php foreach ($inWork as $task): ?>
                    <li>
                        <span class="badge p<?= $task->priority->value ?>"><?= $e($labels[$task->priority->value]) ?></span>
                        <?= $e($task->title) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="column">
            <h2>готово</h2>
            <ul>
                <?php foreach ($done as $task): ?>
                    <li class="done"><?= $e($task->title) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </section>
</main>
</body>
</html>
