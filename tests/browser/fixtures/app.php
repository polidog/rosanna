<?php

declare(strict_types=1);

// `php -S` のルータースクリプト。状態層なしの Rosanna でページを出す

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Rosanna;
use Polidog\Rosanna\Runtime\Element;

require __DIR__ . '/../../../vendor/autoload.php';

ini_set('display_errors', '0');

/** @return array<string, Element> 01 の決定の記録の 8 */
function svgCases(): array
{
    $js = 'javascript:window.__pwned=true';
    $link = static fn(Element $animation, array $svg = [], array $a = []): Element => H::svg(...[...$svg, 'width' => '300', 'height' => '60'], children: H::a(...$a, children: [
        H::text(...['id' => 'target', 'x' => '10', 'y' => '30', 'font-size' => '24', 'children' => 'click']),
        $animation,
    ]));
    $xlink = ['xmlns:xlink' => 'http://www.w3.org/1999/xlink'];

    return [
        'set-to' => $link(H::set(attributeName: 'href', to: $js)),
        'set-to-tab' => $link(H::set(attributeName: 'href', to: "java\tscript:window.__pwned=true")),
        'set-uppercase-tag' => $link(H::SET(attributeName: 'href', to: $js)),
        'set-lowercase-name' => $link(H::set(attributename: 'href', to: $js)),
        'set-xlink' => $link(H::set(attributeName: 'xlink:href', to: $js), $xlink),
        'set-over-safe-href' => $link(H::set(attributeName: 'href', to: $js), [], ['href' => '/other']),
        'animate-values' => $link(H::animate(attributeName: 'href', values: $js, dur: '100s')),
        'animate-second-value' => $link(H::animate(attributeName: 'href', values: '/other;' . $js, keyTimes: '0;0.0001', dur: '1s', calcMode: 'discrete', fill: 'freeze')),
        'animate-to' => $link(H::animate(attributeName: 'href', to: $js, dur: '100s', fill: 'freeze')),
        'animate-from' => $link(H::animate(attributeName: 'href', from: $js, to: '/other', dur: '1000s')),
        'animate-by' => $link(H::animate(attributeName: 'href', by: $js, dur: '100s', fill: 'freeze')),
        'animate-xlink' => $link(H::animate(attributeName: 'xlink:href', values: $js, dur: '100s'), $xlink),
        'set-targets-by-href' => H::svg(...['width' => '300', 'height' => '60'], children: [
            H::a(id: 'l', children: H::text(...['id' => 'target', 'x' => '10', 'y' => '30', 'font-size' => '24', 'children' => 'click'])),
            H::set(href: '#l', attributeName: 'href', to: $js),
        ]),
        // 対照: 無害な値のアニメーションは残り、クリックで移動する
        'safe' => $link(H::set(attributeName: 'href', to: '/other')),
    ];
}

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$segments = explode('/', ltrim($path, '/'));
$scene = $segments[0];
$arg = $segments[1] ?? '';

header('Content-Type: text/html; charset=utf-8');

if ($scene === 'other') {
    echo '<!doctype html><html><body><p id="other">other</p></body></html>';

    return;
}

if ($scene === 'svg') {
    if ($arg === 'control') {
        // 対照: DOM で href を書き換えると同じクリックでスクリプトが動く。観察の方法が正しい証拠
        echo '<!doctype html><html><body><svg width="300" height="60"><a id="l"><text id="target" x="10" y="30" font-size="24">click</text></a></svg>'
            . '<script>document.getElementById("l").setAttribute("href", "javascript:window.__pwned=true")</script></body></html>';

        return;
    }
    $cases = svgCases();
    if (isset($cases[$arg])) {
        echo '<!doctype html><html><body>', new Rosanna()->renderElement($cases[$arg]), '</body></html>';

        return;
    }
}

http_response_code(404);
echo 'no scene';
