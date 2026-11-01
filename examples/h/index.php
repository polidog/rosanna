<?php

declare(strict_types=1);

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Rosanna;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\Renderable;

require_once __DIR__ . '/../layout.php';

/** 状態を持たないクラス。Renderable を実装して children に置く */
final readonly class SongRow implements Renderable
{
    public function __construct(
        private string $title,
        private int $year,
    ) {}

    public function render(): Element
    {
        return H::tr(children: [
            H::td(children: $this->title),
            H::td(children: (string) $this->year),
        ]);
    }
}

$songs = [
    ['title' => 'Rosanna', 'year' => 1982],
    ['title' => 'Africa', 'year' => 1982],
    ['title' => 'Georgy Porgy', 'year' => 1978],
    ['title' => '<script>window.__pwned = true</script>', 'year' => 2000],
];

// 入力を読むのは host の役割。Rosanna は値を受け取って HTML の文字列を返すだけ
$query = is_string($_GET['q'] ?? null) ? $_GET['q'] : '';
$matches = array_filter($songs, static fn(array $song): bool => stripos($song['title'], $query) !== false);

$page = H::div(children: [
    H::form(method: 'get', children: [
        H::label(children: ['Search ', H::input(name: 'q', type: 'search', value: $query)]),
        ' ',
        H::button(type: 'submit', children: 'Go'),
    ]),
    H::table(children: H::tbody(children: array_map(
        static fn(array $song): SongRow => new SongRow($song['title'], $song['year']),
        array_values($matches),
    ))),
    H::p(id: 'count', children: count($matches) . ' found'),
]);

echo example_layout('H', new Rosanna()->renderElement($page));
