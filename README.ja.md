# Rosanna

名前は TOTO の曲 "Rosanna" から取りました。Jeff Porcaro のシャッフルで有名な曲です。

[English](README.md)

Rosanna は、React の要素の形で PHP から HTML を書くライブラリです。`H` か PSX で書きます。Rosanna と PSX の関係は React と JSX の関係と同じで、PSX は任意の構文で、コンパイラは同じパッケージに入っています。

描画はセッションを始めず、JavaScript を必要とせず、HTML に何も足しません。値を受け取って文字列を返すだけです。

Rosanna はルーターも DI もイベントも持たず、HTTP を知りません。URL からページを選ぶことと入力を読むことは host (フレームワークか、ページごとの PHP ファイル) の役割です。

**hooks と、サーバーを通して動くボタンやフォーム (`fc()`、`useState`、`onClick`) を使うには [`polidog/rosanna-state`](https://github.com/polidog/rosanna-state) を足します。遅延読み込み (`<defer>`) だけなら [`polidog/rosanna-defer`](https://github.com/polidog/rosanna-defer) で足ります。** [状態を足す](#状態を足す)を見てください。

- PHP 8.5 以上と `mbstring` 拡張。`polidog/rosanna` は PSX のコンパイラのために `nikic/php-parser` を必要とします。描画では読み込みません。
- 現在の版は v1.0.0 です。

## インストール

```bash
composer require polidog/rosanna
```

## 最小の例

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

動かせるサンプルは [examples/](examples/README.ja.md) にあります。

## H と属性

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

- `children` には `Element`、文字列、数値、`Stringable`、`Renderable`、`null`、bool、それらの配列を渡せます。`null` と bool は何も出しません。それ以外は例外です。
- テキストと属性の値はエスケープします。PHP で書けない属性名はスプレッドで渡します。
- `textarea` と `select` には `value` を渡せます。`H::select(name: 'plan', value: 'b', children: [...])` は `value` 属性を出さず、`optgroup` の中も含めて値の合う `option` に `selected` を付けます。値は文字列として比べ、`value` のない `option` は HTML と同じくテキストで比べます。`multiple` なら配列を渡します。合うものがなければ何も付けません。
- `option` 自身の `selected` は、`select` の `value` によらずその `option` について優先します。単一選択で複数の `option` に `selected` が付くと、ブラウザは最後のものを選びます。
- `Rosanna` を通さずに `Element` を描画するなら、`new Renderer()` を引数なしで使えます。

## 状態のないコンポーネント

コンポーネントは `Element` を返す関数か、`Renderable` を実装して `children` に置くクラスです。

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

`.psx` は HTML のようにタグを書ける PHP のファイルです。構文、manifest、エラーの詳細は [docs/PSX.md](docs/PSX.md) にあります。

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

- ファイル名が大文字で始まる `.psx` がコンポーネントで、FQCN は `namespace\ファイル名` です。別の `.psx` から `<SongList title="..." songs={$songs} />` で呼べます。
- 成果物は `var/cache/psx/` に書き、ソースツリーには書きません。`.gitignore` に入れてください。
- **ビルドした場所と実行する場所のパスを同じにしてください。** manifest は成果物を絶対パスで持ちます。
- キャッシュのディレクトリはビルドのロールだけが書けるようにし、HTTP のリクエストから書けないようにします。`loadComponentManifest()` にリクエスト由来のパスを渡さないでください。

コンパイラはこのパッケージに入っているので、`composer install --no-dev` の後でも、ビルド工程でも本番サーバーの上でも `vendor/bin/rosanna compile` を使えます。コンパイル済みのファイルの描画では、コンパイラも `nikic/php-parser` も読み込みません。

## host への埋め込み

- `Rosanna` はリクエストごとの情報を持たないので、インスタンスをリクエストをまたいで使えます。
- `renderElement()` は自分でスコープを開きます。host のコードから `.psx` のコンポーネントを呼ぶときは、[PSX](#psx) の例のように `RenderContext::run($app->scope(), ...)` の中で `Element` を作ります。生成コードは `RenderContext::scope()->psx->render` を呼びます。
- FrankenPHP や RoadRunner のように 1 つのプロセスで複数のリクエストを扱う host は、リクエストの間で `RequestLifecycle::reset()` を呼びます。

## 状態を足す

```bash
composer require polidog/rosanna-state
```

hooks、署名付きの action、CSRF、Snapshot、Storage、`rosanna.js` は [`polidog/rosanna-state`](https://github.com/polidog/rosanna-state) にあります。依存の向きは `rosanna-state` からこのパッケージへの一方向です。入口は `RosannaState` で、[host に残る仕事](https://github.com/polidog/rosanna-state/blob/main/README.ja.md#host-に残る仕事)と[アプリの移行手順](https://github.com/polidog/rosanna-state/blob/main/README.ja.md#polidogrosanna-の状態を使っていたアプリの移行)はそちらの README にあります。`new` と `use` の置換だけでは済みません。

このパッケージだけで次のものを使うと、`polidog/rosanna-state` を案内する例外になります。動いているように見える出力はしません。

- `null` でない値の `onClick`、`onChange`、`onInput`、`onSubmit`。クロージャは実行しません。
- `wire:*` の属性。
- 描画された `H::defer()` と `<defer>`。

`vendor/bin/rosanna compile` は `manifest.php` に build ID を書き、残っている deferred manifest を消します (`--check` は古いと報告します)。`polidog/rosanna-state` を使うアプリは代わりに `vendor/bin/rosanna-state compile` でコンパイルします。

## CLI

```bash
vendor/bin/rosanna compile [paths...]      # .psx をコンパイルする (既定は ./components)
vendor/bin/rosanna compile --check         # 書き込まず、古ければ exit 1。CI 向け
vendor/bin/rosanna compile --watch         # 変更を見て再コンパイルする
vendor/bin/rosanna compile --clean         # 出力先の中身を消す
vendor/bin/rosanna compile --cache=PATH    # 出力先 (既定は var/cache/psx)
vendor/bin/rosanna help
```

存在しないパス、未知のオプション、未知のコマンドは exit 1 です。

## セキュリティの注意

- **`<style>` と `<script>` の中身はエスケープしません。** ユーザー入力を入れないでください。自身の終了タグを含む中身は例外です。`noscript`、`textarea`、`title`、`xmp`、`iframe`、`noembed`、`noframes` の中では、その祖先の終了タグを含む中身も例外です。`<svg>` と `<math>` の中ではエスケープします。
- **タグ名と属性名に信頼しない値を渡さないでください。** 不正な名前とインラインの `on*` は例外です。`href`、`src`、`action`、`formaction`、`data`、`poster`、`background`、`xlink:href` の `javascript:`、`vbscript:`、`data:` は属性を落とします。`href` を対象にする SVG の `<animate>`、`<set>` の `to`、`values`、`from`、`by` も同じ検査で属性を落とします。値のある `srcdoc` は例外です。
- Rosanna は CSRF の仕組みを持ちません。`H` で書いたフォームの送信先は host で、検証も host の役割です。

## 既知の制約

- PSX の成果物は絶対パスに依存します ([PSX](#psx))。
- 属性のスプレッド (`<div {...$a} />`) と名前空間付きの属性名は PSX で使えません。

## usePHP からの移行

`H`、`Element`、`Renderer`、PSX だけを使うサイトの手順です。hooks やイベントの prop を使うサイトは [`polidog/rosanna-state` の手順](https://github.com/polidog/rosanna-state/blob/main/README.ja.md#usephp-からの移行)に従います。

```bash
composer remove polidog/use-php
composer require polidog/rosanna
```

1. `Polidog\UsePhp` を `Polidog\Rosanna` に、`UsePHP` を `Rosanna` に置換します。
2. `new Renderer('...')` を `new Renderer()` にします。引数は action の送信先で、状態層のものです。
3. `vendor/bin/usephp` を `vendor/bin/rosanna` に直し、PSX を再コンパイルします。
4. `AssetInstaller` と `usephp.js` に当たるものはここにはありません。サイトがこのスクリプトに頼っていないことを確かめてから、`composer.json` の `scripts` と HTML から外します。
5. 状態のないクラスは `Renderable` を実装して `children` に置きます。`Rosanna` の `register()`、`render()`、`createElement()` はなくなりました。これらは action の送信先を引くためのものです。

## 開発

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse --no-progress
vendor/bin/psalm --no-progress
vendor/bin/php-cs-fixer fix --dry-run --diff
npm ci && npx playwright install chromium && npx playwright test
```

## ライセンス

MIT。[LICENSE](LICENSE) を参照してください。
