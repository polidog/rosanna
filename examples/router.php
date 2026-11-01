<?php

declare(strict_types=1);

// 全部のサンプルを 1 つのサーバーで動かすための `php -S` のルータースクリプト。
// php -S localhost:8000 examples/router.php

require_once __DIR__ . '/layout.php';

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$first = explode('/', trim($path, '/'))[0];
$examples = ['h', 'psx'];

if ($first === '') {
    $links = array_map(
        static fn(string $name): string => '<li><a href="/' . $name . '">' . $name . '</a></li>',
        $examples,
    );
    echo example_layout('Rosanna examples', '<ul>' . implode('', $links) . '</ul>');

    return;
}

if (!in_array($first, $examples, true)) {
    http_response_code(404);
    echo example_layout('Not found', '<p>No such example.</p>');

    return;
}

require __DIR__ . '/' . $first . '/index.php';
