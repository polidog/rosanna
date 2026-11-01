# Rosanna examples

[日本語](README.ja.md)

Rendering without state: no session, no JavaScript, and no `data-rosanna` in the output. For hooks and actions, see the examples in [polidog/rosanna-state](https://github.com/polidog/rosanna-state).

## Running

Run from the repository root. PHP 8.5+ is required.

```bash
composer install
bin/rosanna compile examples/psx/components --cache=examples/psx/var/cache/psx   # only needed for psx
php -S localhost:8000 examples/router.php
```

Open http://localhost:8000 for the list.

## Examples

| Example | What it shows | Main APIs |
|---|---|---|
| [h](h/index.php) | Building a page in PHP, with a class as a child and a query string read by the host | `H`, `Renderable`, `new Rosanna()`, `renderElement` |
| [psx](psx/) | Tags and nested components | `.psx`, `rosanna compile`, `loadComponentManifest`, `scope` |

[router.php](router.php) only exists to list the samples. In your application a host router or one PHP file per page does this job.

## Tests

`tests/browser/examples.spec.ts` opens both samples in a real browser.

```bash
npm ci && npx playwright install chromium
npx playwright test tests/browser/examples.spec.ts
```
