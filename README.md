# Rosanna

Named after the TOTO song "Rosanna", famous for Jeff Porcaro's shuffle.

[日本語](README.ja.md)

Rosanna is a library for writing HTML from PHP in the shape of React elements, with `H` or with PSX. Rosanna relates to PSX the way React relates to JSX: PSX is an optional syntax, and its compiler ships in the same package.

Rendering does not start a session, does not need JavaScript and adds nothing to your HTML. It takes values and returns a string.

Rosanna has no router, no DI and no events, and it knows nothing about HTTP. Choosing a page from a URL and reading input belong to the host (a framework, or one PHP file per page).

**For hooks, buttons and forms that work through the server (`fc()`, `useState`, `onClick`), add [`polidog/rosanna-state`](https://github.com/polidog/rosanna-state). For deferred fragments (`<defer>`) alone, [`polidog/rosanna-defer`](https://github.com/polidog/rosanna-defer) is enough.** See [Adding state](#adding-state).

- PHP 8.5+ with the `mbstring` extension. `polidog/rosanna` requires `nikic/php-parser` for the PSX compiler. Rendering does not load it.
- The current version is v1.0.0.

## Installation

```bash
composer require polidog/rosanna
```

## Minimal example

```php
<?php
// public/index.php
declare(strict_types=1);

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Rosanna;

require __DIR__ . '/../vendor/autoload.php';

$songs = ['Rosanna', 'Africa'];

$page = H::main(children: [
    H::h1(children: 'Toto IV'),
    H::ul(children: array_map(fn(string $song) => H::li(children: $song), $songs)),
]);

echo '<!doctype html><html><head><meta charset="utf-8"></head><body>';
echo new Rosanna()->renderElement($page);
echo '</body></html>';
```

```bash
php -S localhost:8000 -t public
```

Runnable samples are in [examples/](examples/README.md).

## H and attributes

```php
<?php
declare(strict_types=1);

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Rosanna;

require __DIR__ . '/vendor/autoload.php';

$items = ['one', 'two'];

echo new Rosanna()->renderElement(H::div(className: 'card', children: [
    H::h2(children: 'Title'),
    $items !== [] ? H::ul(children: array_map(fn(string $item) => H::li(children: $item), $items)) : null,
    H::button(...['aria-label' => 'Close', 'data-id' => '1'], children: 'x'),
    H::textarea(name: 'body', value: 'text'),
]));
```

- `children` takes `Element`, strings, numbers, `Stringable`, `Renderable`, `null`, bool and arrays of those. `null` and bool output nothing. Anything else throws.
- Text and attribute values are escaped. Pass attribute names PHP cannot write with a spread.
- `textarea` and `select` take a `value`. `H::select(name: 'plan', value: 'b', children: [...])` outputs no `value` attribute and marks the matching `option` `selected`, including inside `optgroup`. Values are compared as strings, and an `option` without a `value` is matched by its text, as in HTML. With `multiple`, pass an array. If nothing matches, nothing is marked.
- An `option`'s own `selected` prop decides for that option, whatever the `select`'s `value` is. If several options in a single select end up `selected`, the browser picks the last one.
- `new Renderer()` takes no arguments, if you want to render an `Element` without `Rosanna`.

## Components without state

A component is a function that returns an `Element`, or a class that implements `Renderable` and sits in `children`.

```php
<?php
declare(strict_types=1);

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Rosanna;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\Renderable;

require __DIR__ . '/vendor/autoload.php';

final readonly class SongRow implements Renderable
{
    public function __construct(private string $title) {}

    public function render(): Element
    {
        return H::li(children: $this->title);
    }
}

$Heading = fn(array $props): Element => H::h1(children: $props['title']);

echo new Rosanna()->renderElement(H::main(children: [
    $Heading(['title' => 'Toto IV']),
    H::ul(children: [new SongRow('Rosanna'), new SongRow('Africa')]),
]));
```

## PSX

A `.psx` file is PHP in which you can write tags like HTML. [docs/PSX.md](docs/PSX.md) has the details of the syntax, the manifest and errors.

```php
<?php
// components/SongList.psx
declare(strict_types=1);

namespace App;

return fn(array $props) => (
    <section>
        <h2>{$props['title']}</h2>
        <ul>
            {array_map(fn(string $song) => <li>{$song}</li>, $props['songs'])}
        </ul>
    </section>
);
```

```php
<?php
// public/index.php
declare(strict_types=1);

use Polidog\Rosanna\Rosanna;
use Polidog\Rosanna\Runtime\PsxRenderer;
use Polidog\Rosanna\Runtime\RenderContext;

require __DIR__ . '/../vendor/autoload.php';

$app = new Rosanna();
$app->loadComponentManifest(__DIR__ . '/../var/cache/psx/' . PsxRenderer::MANIFEST_FILENAME);

echo RenderContext::run($app->scope(), fn(): string => $app->renderElement(
    RenderContext::scope()->psx->render('App\\SongList', ['title' => 'Toto IV', 'songs' => ['Rosanna', 'Africa']]),
));
```

```bash
vendor/bin/rosanna compile components
```

- A `.psx` whose file name starts with an uppercase letter is a component, and its FQCN is `namespace\FileName`. Other `.psx` files call it as `<SongList title="..." songs={$songs} />`.
- Output goes to `var/cache/psx/`, never into the source tree. Add it to `.gitignore`.
- **Build and run at the same path.** The manifest holds absolute paths to the compiled files.
- Make the cache directory writable by the build role only, not by HTTP requests. Never pass a request-derived path to `loadComponentManifest()`.

The compiler is part of this package, so `vendor/bin/rosanna compile` also works after `composer install --no-dev`, in a build step or on the production server. Rendering compiled files does not load the compiler or `nikic/php-parser`.

## Embedding in a host

- `Rosanna` keeps no per-request information, so an instance can be reused across requests.
- `renderElement()` opens a scope by itself. When `.psx` components are called from your code, build the `Element` inside `RenderContext::run($app->scope(), ...)` as in [PSX](#psx). Generated code calls `RenderContext::scope()->psx->render`.
- A host that serves several requests from one process, such as FrankenPHP or RoadRunner, calls `RequestLifecycle::reset()` between requests.

## Adding state

```bash
composer require polidog/rosanna-state
```

Hooks, signed actions, CSRF, Snapshot, Storage and `rosanna.js` live in [`polidog/rosanna-state`](https://github.com/polidog/rosanna-state). It requires this package; this package does not know about it. It has its own entry point, `RosannaState`, and its README lists [what the host has to do](https://github.com/polidog/rosanna-state#what-the-host-still-does) and [how an app moves over](https://github.com/polidog/rosanna-state#migrating-an-app-that-used-state-on-polidogrosanna). Replacing `new` and `use` is not enough.

Without it, these throw an exception that points to `polidog/rosanna-state`. They never output something that looks like it works.

- `onClick`, `onChange`, `onInput`, `onSubmit`, with any value that is not `null`. A closure is not run.
- `wire:*` attributes.
- `H::defer()` and `<defer>`, when rendered.

`vendor/bin/rosanna compile` writes the build ID into `manifest.php` and removes a leftover deferred manifest (`--check` reports it as stale). An app using `polidog/rosanna-state` compiles with `vendor/bin/rosanna-state compile` instead.

## CLI

```bash
vendor/bin/rosanna compile [paths...]      # compile .psx (default: ./components)
vendor/bin/rosanna compile --check         # write nothing, exit 1 if stale; for CI
vendor/bin/rosanna compile --watch         # recompile on change
vendor/bin/rosanna compile --clean         # empty the output directory
vendor/bin/rosanna compile --cache=PATH    # output directory (default: var/cache/psx)
vendor/bin/rosanna help
```

A missing path, an unknown option or an unknown command exits 1.

## Security notes

- **The content of `<style>` and `<script>` is not escaped.** Keep user input out. Content containing its own end tag throws. Inside `noscript`, `textarea`, `title`, `xmp`, `iframe`, `noembed` or `noframes`, content containing that ancestor's end tag throws too. Inside `<svg>` and `<math>` it is escaped.
- **Do not pass untrusted values as tag or attribute names.** Invalid names and inline `on*` throw. `javascript:`, `vbscript:` and `data:` in `href`, `src`, `action`, `formaction`, `data`, `poster`, `background` and `xlink:href` drop the attribute. The same check drops `to`, `values`, `from` and `by` of SVG `<animate>` and `<set>` that target `href`. A `srcdoc` with a value throws.
- Rosanna has no CSRF mechanism. Forms you write with `H` go to your host, and validating them is the host's job.

## Known limitations

- PSX output depends on absolute paths ([PSX](#psx)).
- Attribute spread (`<div {...$a} />`) and namespaced attribute names are not available in PSX.

## Migrating from usePHP

This applies to a site that uses only `H`, `Element`, `Renderer` and PSX. A site that uses hooks or event props follows [the steps in `polidog/rosanna-state`](https://github.com/polidog/rosanna-state#migrating-from-usephp).

```bash
composer remove polidog/use-php
composer require polidog/rosanna
```

1. Replace `Polidog\UsePhp` with `Polidog\Rosanna` and `UsePHP` with `Rosanna`.
2. Change `new Renderer('...')` to `new Renderer()`. The argument was where actions were sent, which belongs to the state layer.
3. Change `vendor/bin/usephp` to `vendor/bin/rosanna`, and recompile PSX.
4. `AssetInstaller` and `usephp.js` have no counterpart here. Remove them from the `scripts` of `composer.json` and from your HTML, after checking that nothing on the site depends on the script.
5. Classes without state implement `Renderable` and go in `children`. `register()`, `render()` and `createElement()` of `Rosanna` are gone: they exist to find where an action is sent.

## Development

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse --no-progress
vendor/bin/psalm --no-progress
vendor/bin/php-cs-fixer fix --dry-run --diff
npm ci && npx playwright install chromium && npx playwright test
```

## License

MIT. See [LICENSE](LICENSE).
