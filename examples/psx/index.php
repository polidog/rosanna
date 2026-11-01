<?php

declare(strict_types=1);

use Polidog\Rosanna\Rosanna;
use Polidog\Rosanna\Runtime\PsxRenderer;
use Polidog\Rosanna\Runtime\RenderContext;

require_once __DIR__ . '/../layout.php';

$manifest = __DIR__ . '/var/cache/psx/' . PsxRenderer::MANIFEST_FILENAME;
if (!is_file($manifest)) {
    http_response_code(500);
    echo example_layout('PSX', '<p>Compile the components first:</p>'
        . '<pre>bin/rosanna compile examples/psx/components --cache=examples/psx/var/cache/psx</pre>');

    return;
}

$app = new Rosanna();
$app->loadComponentManifest($manifest);

$body = RenderContext::run($app->scope(), static fn(): string => $app->renderElement(
    RenderContext::scope()->psx->render('Examples\\Psx\\SongList', [
        'title' => 'Toto IV',
        'songs' => ['Rosanna', 'Africa', '<script>window.__pwned = true</script>'],
    ]),
));

echo example_layout('PSX', $body);
