<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * 全部のサンプルで共通の HTML の外枠。$body は描画済みの HTML で、エスケープしない。
 */
function example_layout(string $title, string $body): string
{
    $escaped = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return <<<HTML
        <!doctype html>
        <html lang="en">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{$escaped}</title>
        <style>
        body { font: 16px/1.6 system-ui, sans-serif; max-width: 40rem; margin: 2rem auto; padding: 0 1rem; }
        nav { margin-bottom: 1rem; }
        td, th { padding: .25rem .75rem .25rem 0; text-align: left; }
        </style>
        </head>
        <body>
        <nav><a href="/">&larr; All examples</a></nav>
        <h1>{$escaped}</h1>
        {$body}
        </body>
        </html>
        HTML;
}
