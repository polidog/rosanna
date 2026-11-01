# Rosanna 実装仕様 01 — ランタイムの描画

Status: Draft (2026-11-01、usePHP v0.10.0 `e073bff` / Relayer main `9566b0c` で照合。2026-11-02 に状態層を `polidog/rosanna-state` に分離)

計画: [docs/design/rosanna-split-and-rename.md](../design/rosanna-split-and-rename.md)

| 仕様 | 範囲 |
|---|---|
| 01 (この文書) | `Element`、`H`、`Renderer`、`RenderExtension`、`RenderContext`、`RenderScope`、`RequestLifecycle`、PSX の読み込み、`Rosanna` の描画 API、`vendor/bin/rosanna` |
| [03-compiler.md](03-compiler.md) | PSX、コンパイラ (`src/Psx/`) |
| [`polidog/rosanna-state` の 01](https://github.com/polidog/rosanna-state/blob/main/docs/spec/01-runtime.md) | RT-4〜8、RT-9.4、RT-9.5、RT-10、`RosannaState` の API (RT-12)、`publish` (RT-13) |
| [`polidog/rosanna-state` の 02](https://github.com/polidog/rosanna-state/blob/main/docs/spec/02-state-and-actions.md) | Storage、Snapshot、Action、署名、CSRF、バージョン照合、defer、`rosanna.js`、`actions()->handle()`、`signer()` |

要件 ID は分離の前の番号のままで、節の番号も変えない。状態層に移した項目は、番号を残して移した先を書く。この文書で「状態層の 01」「02」は `polidog/rosanna-state` の文書を指す。

根拠の `usePHP/...` は `../usePHP` のパスと行番号で、仕様の参考資料である。コードはコピーしない。

## 0. 共通の前提

- **RT-0.1** PHP `>=8.5`。`polidog/rosanna` の必須依存は `ext-mbstring` と `nikic/php-parser` で、どちらもコンパイラのためのもの (03 の CP-1)。描画では php-parser を読み込まない (03 の CP-2)。ルーター、イベント、DI コンテナ、PSR-7 を持たない。
- **RT-0.2** 生成コードと Relayer が参照する次の場所を変えない。usePHP の `Polidog\UsePhp` からの移行は、描画だけなら `Polidog\Rosanna` への置換で済むが、状態層を使うアプリはこの表の 2 段目に移す。`new` と `use` の置換だけでは済まない (`polidog/rosanna-state` の README の移行手順)。

| パッケージ | 種類 | 完全修飾名 |
|---|---|---|
| `polidog/rosanna` | クラス | `Polidog\Rosanna\Html\H`、`Polidog\Rosanna\Runtime\Element`、`Polidog\Rosanna\Runtime\Renderable` |
| `polidog/rosanna` | 関数 | `Polidog\Rosanna\Html\createElement` |
| `polidog/rosanna-state` | クラス | `Polidog\Rosanna\State\Action`、`Polidog\Rosanna\State\FunctionComponent`、`Polidog\Rosanna\State\Component\BaseComponent`、`Component`、`ComponentInterface`、`Defer`、`Polidog\Rosanna\State\Installer\AssetInstaller` |
| `polidog/rosanna-state` | enum | `Polidog\Rosanna\State\Storage\StorageType`、`SnapshotPersist` |
| `polidog/rosanna-state` | 関数 | `Polidog\Rosanna\State\useState`、`useFormAction`、`useEffect`、`fc`、`component` |

- **RT-0.3** usePHP の `Runtime\useRouter` は作らない。`Polidog\Rosanna\Rosanna` が描画の入口で、usePHP の `UsePHP` の状態層を含む役割は `Polidog\Rosanna\State\RosannaState` にあたる。
- **RT-0.4** `H` は Composer の `autoload.files` で読み込む (`usePHP/composer.json`)。hooks の関数は `polidog/rosanna-state` が同じ形で読み込む。
- **RT-0.5** 例外の使い分けは次のとおり。

| 例外 | 使う場面 |
|---|---|
| `InvalidArgumentException` | `H`、`createElement` に渡した引数の誤り。状態層では `fc`、`useFormAction`、`#[Component]` も同じ |
| `TypeError` | 型宣言のある `H` のメソッド (`H::button(onClick: 'x')`) に合わない型を渡したとき。PHP が投げるので関数は実行されない (RT-2.6) |
| `RuntimeException` | Renderer に届いた不正な Element、PSX の読み込み失敗 |
| `LogicException` | 登録や設定の不足、スコープ外の呼び出し。状態層では未登録のコンポーネント、秘密鍵なしの Snapshot も同じ |

## 1. Element

**目的:** 描画前の木を表す値。

```php
namespace Polidog\Rosanna\Runtime;

final readonly class Element
{
    public function __construct(public string $type, public array $props = [], public array $children = []);
    public function withProps(array $props): self;
    public function withChildren(array $children): self;
}

interface Renderable
{
    public function render(): Element;
}
```

**振る舞い** (`usePHP/src/Runtime/Element.php:10`、`Renderable.php:23`)

- **RT-1.1** `withProps` と `withChildren` は新しいインスタンスを返す。
- **RT-1.2** `type` の予約値は次の 2 つ。いずれもタグとして出力しない。

| type | 意味 | 処理 |
|---|---|---|
| `Fragment` | タグなしで子だけを出す | RT-3.2 |
| `__defer__` | defer のプレースホルダー | `RenderExtension::renderDeferred()` (RT-3.15)。なければ例外 (RT-3.16)。プレースホルダーの中身は 02 |

  `State\component()` は予約の type を使わず、本体の `ResolvesItself` を実装した値を返す (状態層の RT-6.7)。

- **RT-1.3** `createElement` と `H::__callStatic` は `__defer__` を `InvalidArgumentException` にする。props はプレースホルダーの内容で、値から作らせないため。`H::defer()` は `createElement` を通らずに作る。
  `Fragment` は生成コードが使うので残す。

**テスト:** 不変であること。予約値のそれぞれの処理。予約の type を `createElement` と `H::{$type}()` に渡すと例外になること。

## 2. H と createElement

**目的:** 要素を作る API。PSX の生成コードは `createElement` を完全修飾名で呼ぶ
(`usePHP/README.ja.md:817`)。

```php
namespace Polidog\Rosanna\Html;

final class H
{
    // タグごとの静的メソッド
    public static function div(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        EventBinding|\Closure|null $onClick = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element;

    public static function Fragment(array $children): Element;
    /** 振る舞いは 02 の 8 節。引数名は生成コードとの契約 (03 の CP-62) */
    public static function defer(
        string $name,
        array $params = [],
        ?Element $fallback = null,
        bool $localCache = false,
        bool $reloadable = false,
        int $localCacheTtl = 0,
    ): Element;
    public static function __callStatic(string $name, array $arguments): Element;
}

function createElement(string $type, array $params): Element;
function resolveRenderables(array $children): array;
```

**振る舞い**

- **RT-2.1** タグのメソッドは次の 96 個で、usePHP と同じ名前にする。`varElement` だけは `var` 要素を作る
  (PHP の予約語を避けるため。`usePHP/src/Html/Elements.php:350`)。名前付き引数は `usePHP/src/Html/Elements.php:28-1250` と同じにする。

  `div span p h1 h2 h3 h4 h5 h6 a strong em small mark del ins sub sup code pre blockquote q abbr cite time kbd samp var br wbr hr
  ul ol li dl dt dd table thead tbody tfoot tr th td caption colgroup col form input textarea button select option optgroup label
  fieldset legend datalist output progress meter header footer nav main article section aside figure figcaption details summary
  dialog address img audio video source track picture canvas svg iframe embed object param map area link meta title base script
  noscript template slot`

- **RT-2.2** 次の 16 個は `children` を持たない: `br wbr hr col input progress meter img source track embed param area link meta base`。
- **RT-2.3** どのメソッドも `mixed ...$attrs` で任意の属性を受ける。PHP で書けない名前はスプレッドで渡す
  (`H::button(...['aria-label' => 'Close'], children: 'x')`)。文字列キーでない `$attrs` は `InvalidArgumentException`
  (`Elements.php:1356`)。`H::__callStatic` の位置引数も同じく `InvalidArgumentException` にする。
- **RT-2.4** **#118 への対応。** `children` の型は Renderer が描画できる型と揃え、`Element|Renderable|Stringable|string|int|float|bool|null`
  とその配列にする。usePHP は `bool`、`Renderable`、`Stringable` を受けず、`strict_types` で `TypeError` になる
  (`Elements.php:40`)。`bool` は何も描画しない。
- **RT-2.5** `createElement` の処理は次の順 (`Elements.php:1393`)。

| 入力 | 処理 |
|---|---|
| `type === 'textarea'` で `value` がスカラー | `value` を文字列にして `children` に移す |
| 値が `null` の param | 無視する。`onFocus` などの未対応イベントでも例外にしない |
| `onFocus`、`onBlur`、`onLoad`、`onError` | `InvalidArgumentException` |
| `children` | `resolveRenderables()` を通す。配列でなければ 1 要素の配列にする |
| `onClick`、`onChange`、`onInput`、`onSubmit` | RT-2.6 |
| その他 | そのまま `props` に入れる |

- **RT-2.6** イベントの値は `EventResolver::resolve($event, $value)` に渡す。戻り値が `EventBinding` なら `wire:click`、`wire:change`、`wire:input`、`wire:submit` の prop にし、`null` なら prop を付けない。
  - **既定 (状態層なし):** `null` でない値をクロージャを実行せずに `InvalidArgumentException` にし、`polidog/rosanna-state` を案内する。
  - **状態層が差し込んだ実装:** `Action` (本体の空のインターフェース `EventBinding` を実装する) か、必須引数のないクロージャだけを受ける。クロージャはその場で実行し、戻り値が `Action` ならそれを返し、`Action` 以外なら `null` を返す (`fn() => $cond ? $set(1) : null` のため)。文字列、配列、呼び出し可能オブジェクトは実行せずに `InvalidArgumentException` にする。関数名を実行させないためで、セキュリティ要件である (`Elements.php:1436-1461`)。実装は `State\resolveEventAction()` で、`autoload.files` が `EventResolver::register()` で登録する。
- **RT-2.7** `H::Fragment($children)` は `Element('Fragment', [], resolveRenderables($children))` を返す。
- **RT-2.8** `resolveRenderables` はネストした配列を文書順に平坦化し、`ResolvesItself` を `resolve()` で、それ以外の `Renderable` を `render()` で展開する
  (`Elements.php:1491`)。`ResolvesItself` はラッパーなどの付いた最終形を返す値で、状態層の `BaseComponent` (RT-5.4) と `State\component()` (RT-6.7) が実装する。本体はこれらを名指ししない。

**エラー:** RT-2.3、RT-2.5、RT-2.6 のとおり。状態層の実装は、必須引数を持つクロージャに「`useFormAction()` を使う」と案内する。

**テスト**

- 名前付きの属性、スプレッドの属性、位置引数の拒否 (`tests/Html/ElementAttributesTest.php`)。
- `textarea` の `value`、数値の属性 (`tests/Html/ElementAttributeTypesTest.php`)。
- `strict_types` で `children` に int、float、bool、`Stringable`、`Renderable` を渡せること (#118)。
- 状態層なしで、イベントの prop がクロージャを実行せずに例外になること。値が `null` のときの無視 (`tests/Runtime/WithoutStateLayerTest.php`)。
- 状態層の実装 (関数名の文字列、配列 callable、呼び出し可能オブジェクトを実行せず拒否すること、未対応イベントの拒否) は `polidog/rosanna-state` の `tests/Security/SecurityHardeningTest.php` の `testRT26*` と `tests/Runtime/ElementEventsTest.php`。

## 3. Renderer と出力の安全性

**目的:** Element の木を HTML にする。計画の「作り直しても引き継ぐもの」のうち、タグ名と属性名の検証、
インラインの `on*` の拒否、`javascript:` の遮断、不正な UTF-8 の置換、`script` と `style` の出力、描画できない子の拒否を満たす。

```php
namespace Polidog\Rosanna\Runtime;

final class Renderer
{
    public const TAG_NAME_PATTERN = '/^[A-Za-z][A-Za-z0-9\-]*$/';
    public const ATTRIBUTE_NAME_PATTERN = '/^[A-Za-z_:][A-Za-z0-9:_.\-]*$/';

    public function __construct(?RenderExtension $extension = null);
    public function renderElement(Element|string $element): string;
}

/** `Renderer` の差し替え口。描画ごとの可変状態を持てるよう、描画ごとに作る */
interface RenderExtension
{
    public function beginRender(): void;
    public function finishRender(): string;
    public function endRender(): void;
    public function renderTag(Element $element, string $lowerTag, bool $isVoid, \Closure $attributes, \Closure $content): string;
    public function renderDeferred(Element $element, \Closure $render): string;
}
```

`new Renderer()` は引数なしで使える。ラッパーと snapshot の hidden を付ける `render()` と `renderPartial()` は状態層の `ComponentRenderer` にある (状態層の RT-9.5)。

### エスケープ

- **RT-3.1** テキストと属性値は `htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` で出力する。不正な UTF-8 で出力全体が
  空になるのを防ぐため、`ENT_SUBSTITUTE` は必須とする (`usePHP/src/Runtime/Renderer.php:774`)。

### 子要素

- **RT-3.2** 子の種類ごとの出力は次のとおり (`Renderer.php:932`)。判定はこの順で行う。

| 子 | 出力 |
|---|---|
| `Element` | 再帰的に描画する |
| 配列 | 平坦化する |
| `Renderable` | `resolveRenderables` で展開して描画する |
| `null`、`bool` | 何も出さない |
| `string`、`Stringable` | エスケープして出す |
| `int`、`float` | 文字列にして出す |
| その他 | `RuntimeException`。型名を含める |

- **RT-3.3** 直接の子が文字列のとき (`renderElement('x')`) もエスケープする。

### タグ

- **RT-3.4** タグ名は補間の前に `TAG_NAME_PATTERN` で検証し、違反は `RuntimeException` にする。要素を黙って落とさない
  (`Renderer.php:784`)。
- **RT-3.5** `area base br col embed hr img input link meta param source track wbr` は void 要素として `<tag ... />` で出力し、子を出さない。判定はタグ名を小文字にして行う。
- **RT-3.6** 廃止 (2026-11-02)。`State\component()` は `Element` を返さず、`resolveRenderables` が展開する値を返すので、未解決の要素が Renderer に届くことはない。

### 属性

- **RT-3.7** 属性名は JSX 名を変換してから検証する。対応表は `Renderer.php:118-272` と同じ内容を持つ
  (`className`→`class`、`htmlFor`→`for`、`tabIndex`→`tabindex`、`strokeWidth`→`stroke-width`、`xlinkHref`→`xlink:href` など)。
  表にない名前はそのまま使う。
- **RT-3.8** 変換後の名前が `ATTRIBUTE_NAME_PATTERN` に合わなければ `RuntimeException`。`/^on[a-z]/i` に合えば、値の型を問わず
  `RuntimeException` (`Renderer.php:801`)。
- **RT-3.9** 値の出力は次のとおり (`Renderer.php:695`)。

| 値 | 出力 |
|---|---|
| bool で、属性が `aria-*`、`contenteditable`、`draggable`、`spellcheck` | `name="true"` または `name="false"` |
| その他の bool | `true` は値なしの属性、`false` は出さない |
| スカラーでない値 | 出さずに `E_USER_NOTICE`。属性名と型名を含める |
| スカラー | エスケープして `name="value"` |

- **RT-3.10** 値のある `srcdoc` は `RuntimeException`。中身が同じオリジンの HTML になるため。空文字は許す (`Renderer.php:735`)。
- **RT-3.11** `href src action formaction data poster background xlink:href` (大文字小文字を区別しない) の値は、次の手順で検査する。
  スキームが `javascript`、`vbscript`、`data` なら、例外にせず属性だけを落とす (`Renderer.php:828`)。
  1. TAB、LF、CR を全体から除く。
  2. 先頭の `\x00`〜`\x20` を除く。
  3. 最初の `:` の前を小文字にし、`/^[a-z][a-z0-9+\-.]*$/` に合うものだけをスキームとして扱う。

  検査を通った値は加工せず、元の値を出力する。

  `animate` と `set` (大文字小文字を区別しない) で `attributeName` が `href` を指すときは、`to`、`values`、`from`、`by` の値にも同じ検査をして属性を落とす。Chromium 155 は `<set attributeName="href" to="javascript:...">` を実行するため。
  `attributeName` は大文字小文字と前後の空白を無視し、接頭辞は問わない (`xlink:href`、`x:href`)。接頭辞の意味は宣言で決まるので見ない。`values` は `;` で区切った値ごとに検査する。後ろの値も時間が来れば適用される。
  Firefox と WebKit では確かめていない。

### raw text と foreign content

- **RT-3.12** `script` と `style` (大文字小文字を区別しない) の子はエスケープせずに連結する。HTML パーサーがデコードしないため
  (`Renderer.php:856-923`)。連結後の文字列に `</script`、`</style` (自身の終了タグ。大文字小文字を区別しない) があれば
  `RuntimeException`。子に分割しても通らないよう、連結後に検査する。
  祖先に `noscript`、`textarea`、`title`、`xmp`、`iframe`、`noembed`、`noframes` があれば、その祖先の `</tag` (大文字小文字を区別しない) も同じく `RuntimeException`。
  これらの中では `style` が要素にならず、エスケープしない中身の終了タグが祖先を閉じるため。通常のテキストの子と属性はエスケープされるので検査しない。
- **RT-3.13** raw text の中の子は文字列、数値、`Stringable`、`null`、bool、それらの配列だけを許す。`Element` などは `RuntimeException`。
- **RT-3.14** `svg` と `math` の中では `script` と `style` を raw text として扱わず、通常どおりエスケープする。
  foreign content ではパーサーが中身をマークアップとして読むので、エスケープしないと `<img onerror>` が実行される。
  深さを数えて入れ子に対応し、外に出たら raw text に戻す。

### 差し替え口

- **RT-3.15** `RenderExtension` が覆うのは、最も外側の描画の開始と終了 (`beginRender`、`finishRender`、`endRender`)、タグの描画 (`renderTag`。`data-rosanna` のスコープ、要素の束縛)、予約の type `__defer__` (`renderDeferred`) である。`endRender` は例外のときも呼ぶ。タグ名の検証は `renderTag` より前に本体が行い、属性の検査と子の描画は渡されたクロージャで本体に任せる。`Rosanna` は差し込まれた factory で描画ごとに作る。
- **RT-3.16** `RenderExtension` なしの `Renderer` では、`wire:` で始まる prop (大文字小文字を区別しない) と `__defer__` を `RuntimeException` にし、`polidog/rosanna-state` を案内する。属性として出力しない。

**テスト**

- `tests/Runtime/RendererOutputTest.php` と `tests/Security/SecurityHardeningTest.php` の `testRenderer*` にある項目。次を必ず含める。
  - `\tjavascript:`、`java\tscript:`、`JAVASCRIPT:` の遮断。`HREF` のような大文字の属性名。タブを含む無害な URL が変わらないこと。
  - 不正な UTF-8 のテキスト、属性、state の値で出力が空にならないこと。
  - `<svg><style>` のエスケープと、その後の `<style>` が raw text に戻ること。
  - `</script` を子に分割しても拒否すること。
- 描画できない子 (`object`、`resource`、`Closure`) の拒否。
- RT-3.16: 状態層なしの `wire:*`、`H::defer()` の例外 (`tests/Runtime/WithoutStateLayerTest.php`)。セッションが始まらず `data-rosanna` が出力されないこと (`tests/Runtime/RenderingWithoutStateTest.php`、`tests/Runtime/StateLayerBoundaryTest.php`)。

## 4〜8. 状態層に移した節

次の節は [`polidog/rosanna-state` の 01](https://github.com/polidog/rosanna-state/blob/main/docs/spec/01-runtime.md) に同じ番号である。

| 節 | 内容 |
|---|---|
| 4 (RT-4) | Action の束縛とフォームの配置 |
| 5 (RT-5) | クラスコンポーネント |
| 6 (RT-6) | `fc()`、`State\component()` と関数コンポーネント |
| 7 (RT-7) | hooks |
| 8 (RT-8) | インスタンス ID と描画パス |

状態のないコンポーネントは、`Element` を返す関数か、`Renderable` を実装して `children` に置くクラスで書く。

## 9. 描画スコープ

**目的:** `RenderContext::getApp()` をなくす (計画の決定)。生成コードが呼ぶ `RenderContext::scope()->psx` のために描画中の ambient は残すが、型を必要な機能に絞る。

```php
namespace Polidog\Rosanna\Runtime;

final class RenderScope
{
    public function __construct(
        public readonly PsxRenderer $psx,     // 生成コードが呼ぶ
        public readonly ?object $state = null, // 状態層の値を置く slot。本体は中身を読まない
    );
}

final class RenderContext
{
    /** enter と leave を例外時も含めて保証し、$fn の戻り値を返す。入れ子にできる */
    public static function run(RenderScope $scope, callable $fn): mixed;
    /** @throws \LogicException スコープの外 */
    public static function scope(): RenderScope;
    public static function currentScope(): ?RenderScope;
}

final class RequestLifecycle
{
    /** reset() で一緒に消すものを登録する。同じ名前なら置き換える */
    public static function onReset(string $name, \Closure $callback): void;
    public static function reset(): void;
}
```

**振る舞い**

- **RT-9.1** `RenderContext` が持つスコープの型は `RenderScope` だけとする。`Rosanna` 型を返す API を作らない。
- **RT-9.2** `run` の入れ子では、抜けるときに外側のスコープに戻す (`tests/Runtime/AppContextRestoreTest.php`)。
- **RT-9.3** ルート情報を持たない。分割設計の `RenderScope::$route` と `RouteInfo` は作らない。
- **RT-9.4** 状態層に移した (`StateScope::isDeferredEndpointTarget()`。[状態層の 01](https://github.com/polidog/rosanna-state/blob/main/docs/spec/01-runtime.md) の 9 節)。
- **RT-9.5** 状態層に移した (`RendererFactory`)。署名、CSRF、serializer、defer の prefix、`rootId` はすべて状態層のもので、本体の `RenderScope` は `$state` の slot で持つだけである。
- **RT-9.6** `RequestLifecycle::reset()` はスコープを消し、`onReset()` で登録された処理をすべて呼ぶ。Relayer の `endRequest()` はこれに置き換える
  (`relayer/src/Relayer.php:142-144`)。worker モードで別の訪問者に状態が漏れないようにするため。
  呼ぶのは `reset()` だけにする。層ごとに分けると呼び忘れで漏れる。状態層は `ComponentState` のインスタンス、Storage、描画パス、セッションの消去を `autoload.files` で登録する ([状態層の 01](https://github.com/polidog/rosanna-state/blob/main/docs/spec/01-runtime.md) の RT-9.6)。本体だけのときに消すものはスコープだけで、セッションには触らない。

**エラー:** スコープの外で `RenderContext::scope()` を呼んだら `LogicException`。生成コードはスコープの中で動くことを前提にする。

**テスト**

- `run` の入れ子と、例外時の復元。
- スコープの外での `scope()` の例外。`currentScope()` の `null`。
- `reset()` の後にスコープが残らないこと。`onReset()` の処理が呼ばれ、同じ名前の登録が置き換わること。

## 10. ComponentRegistry

状態層に移した ([状態層の 01](https://github.com/polidog/rosanna-state/blob/main/docs/spec/01-runtime.md) の 10 節)。

## 11. PSX の読み込み (ランタイム側)

**目的:** 生成コードから呼ばれる入口を、コンパイラを読み込まずに持つ。`src/Psx/` の外で `Polidog\Rosanna\Psx\CompileCommand` を参照するのは `bin/rosanna` だけとする (03 の CP-2)。

```php
namespace Polidog\Rosanna\Runtime;

final class PsxRenderer
{
    public function render(string $fqcn, array $props = []): Element;
}

interface PsxArgumentResolver
{
    /** @return list<mixed> 第 2 引数以降 */
    public function resolve(string $fqcn, array $metadata, array $props): array;
}
```

`PsxRenderer` は定数 `MANIFEST_FILENAME` (`manifest.php`)、`DEFERRED_MANIFEST_FILENAME` (`deferred-manifest.php`)、`BUILD_ID_KEY` (`#build-id`) を持つ。`CompileCommand` はこれを参照する (03 の CP-137、CP-144)。

**振る舞い**

- **RT-11.1** 生成コードの PSX コンポーネントの呼び出しは `RenderContext::scope()->psx->render('Fqcn', $props)` とする (分割設計)。
  生成コードの形は 03 が定める。`H::defer` と `createElement` の呼び出しは変えない。
- **RT-11.2** `render` は次の順で動く (`usePHP/src/UsePHP.php:538`)。読み込んだ callable はインスタンスに保持する。
  1. 読み込み済みならそれを使う。
  2. manifest になければ `RuntimeException`。
  3. ファイルがない、読めない、`ParseError`、callable を返さないときは `RuntimeException`。
     `vendor/bin/rosanna compile` を案内し、`ParseError` は `previous` に入れる。
- **RT-11.3** `PsxArgumentResolver` は DI を持たずに host が引数を渡すための口で、第 2 引数以降を解決する。自分で引数を解決するコンポーネントは本体の `AcceptsArgumentResolver` を実装する。状態層の `FunctionComponent` がこれにあたり、`fc()` の `inner` にも適用される (分割設計)。
  Relayer はここにコンテナを使う実装を渡し、`PsxComponentRegistrar` の再登録を消す。これで `wrapFunctionComponent` が `persist` を落としている問題 (`relayer/src/Psx/PsxComponentRegistrar.php:249`) もなくなる。
- **RT-11.4** 既定の実装は default 値と nullable だけを解決する。manifest の `parameters` の形式は 03。
- **RT-11.5** `loadComponentManifest($path)` は manifest を読む。`#build-id` の項目は読み飛ばす (03 の CP-144)。ファイルがない、配列を返さない、項目の形が違うときは `RuntimeException` (`usePHP/src/UsePHP.php:255-305`)。本体は deferred manifest を読まない。読んで build ID を照合するのは `RosannaState::loadComponentManifest()` である (02 の DF-13)。
- **RT-11.6** `StackTraceRewriter` はランタイム側 (`src/Psx/` の外) に置く (03 の 10 節)。

**テスト:** 未登録、ファイルなし、`ParseError`、callable でない戻り値のそれぞれの例外。resolver が `fc()` の内側にも効くこと。

## 12. Rosanna の描画 API と host への埋め込み

**目的:** Element の木を host のページに埋め込む。URL からページを選ぶのは host の役割で、`run()` と `handle()` は作らない。状態層の入口 `RosannaState` は [状態層の 01](https://github.com/polidog/rosanna-state/blob/main/docs/spec/01-runtime.md) の 12 節にある。

```php
namespace Polidog\Rosanna;

final class Rosanna
{
    /**
     * 引数は状態層のための差し替え口で、描画だけなら何も渡さない。
     *
     * @param (\Closure(RenderScope): RenderExtension)|null $extensionFactory renderElement() のたびに呼ぶ
     * @param (\Closure(PsxRenderer, ?RenderScope): RenderScope)|null $scopeFactory 新しいスコープを作る。第 2 引数は外のスコープ (あれば)
     */
    public function __construct(?\Closure $extensionFactory = null, ?\Closure $scopeFactory = null);

    // 登録
    public function registerComponent(string $fqcn, callable $component): self;
    public function loadComponentManifest(string $path): self;
    public function setArgumentResolver(PsxArgumentResolver $resolver): self;

    // 描画
    public function scope(): RenderScope;
    public function renderElement(Element $element): string;

    /** @internal 状態層のため */
    public function psx(): PsxRenderer;
    /** @internal 状態層のため */
    public function inScope(callable $fn): mixed;
}
```

**振る舞い**

- **RT-12.1、RT-12.2、RT-12.4、RT-12.5、RT-12.6、RT-12.7** 状態層に移した。`register()`、`render()`、`createElement()` はクラス名で action の送信先を引くための登録で、`renderClientScript()` は `rosanna.js` のものである。
- **RT-12.3** `renderElement` は自分のスコープで実行し、外側のスコープを `finally` で戻す。`Renderer` は呼び出しごとに新しく作る。インスタンス ID と描画パスは本体にない (状態層の RT-8)。
- **RT-12.8** `.psx` のコンポーネントを host が直接呼ぶ場合は、`RenderContext::run($app->scope(), $fn)` の中で Element を作り、`renderElement` で描画する。本体の `scope()` は `Request` を取らない。署名のスコープがないので HTTP を知る必要がない。
- **RT-12.9** `renderElement` は、外側のスコープが同じ `PsxRenderer` を持てばそれを使い、それ以外は scope factory で新しく作る。既定の factory は外側の `$state` を引き継ぐ。

**テスト**

- 入れ子の `renderElement` で外側のスコープが戻ること (`AppContextRestoreTest.php`)。
- `H` と PSX の描画で状態層のコードが動かず、セッションが始まらず、`data-rosanna` が出力されないこと。
- README の例を実際に動かすこと。

## 13. bin/rosanna

**目的:** PSX をコンパイルする。`publish`、`AssetInstaller`、`public/rosanna.js` は [状態層の 01](https://github.com/polidog/rosanna-state/blob/main/docs/spec/01-runtime.md) の 13 節に移した。

```
vendor/bin/rosanna compile [...]     Polidog\Rosanna\Psx\CompileCommand を呼ぶ (03)
vendor/bin/rosanna help              ヘルプを表示する
```

**振る舞い** (`usePHP/bin/usephp`)

- **RT-13.1** `bin/rosanna` は `../vendor/autoload.php` と `../../../autoload.php` の順で読み込む。
- **RT-13.2〜RT-13.6、RT-13.8** 状態層に移した (`publish`、`AssetInstaller`、配布物の `rosanna.js`)。
- **RT-13.7** `compile` は `Polidog\Rosanna\Psx\CompileCommand` を直接呼ぶ。コンパイラは同じパッケージにあるので、存在の確認も案内もしない (2026-11-01 に変更。03 の CP-140)。
- **RT-13.9** 未知のコマンドはヘルプを出して終了コード 1 にする。`publish` もここでは未知のコマンドである。

**テスト:** 依存として入った配置 (`vendor/polidog/rosanna`) から `compile` が動くこと。未知のコマンドの終了コード。

## 14. Relayer が使っている箇所の置き換え

実際の呼び出しは `relayer/src` の検索で確かめた。action まわりは 02。Relayer はまだ `polidog/use-php` に依存していて、この対応は分離の Issue の範囲に含めない。Rosanna へ移るときに、状態層を任意の依存にする (`LayoutRenderer.php:11`、`AppRouter.php` の `dispatchStateAction`)。下の表の「状態層」の行は `polidog/rosanna-state` を入れたときだけ要る。

| Relayer の箇所 | 現在 | 使う層 | Rosanna |
|---|---|---|---|
| `Router/AppRouter.php:461`、`:562` | `RenderContext::setApp()`、`clearApp()` | 本体 | `RenderContext::run($app->scope(), ...)`。状態層では `$app->scope($request)` |
| `Router/Layout/LayoutRenderer.php:86` | `new Renderer(...)` | 本体 | `new Renderer()` (引数なし)。状態層では `StateScope::of(RenderContext::scope())->renderers->create(...)` |
| `Router/Layout/LayoutRenderer.php` の `containsStateAction` | CSRF を読むかの判定 | 状態層 | 不要 (RT-4.6)。本体だけのフォームの CSRF は Relayer の `CsrfToken` が担う |
| `Router/AppRouter.php:1475`、`:1498` | `RenderContext::beginRender()` | 状態層 | `RenderPass::beginRender()` (RT-8.5) |
| `Router/AppRouter.php:1477-1478` | `ComponentState::getInstance()`、`reset()` | 状態層 | `State\ComponentState` のまま。`@internal` で残す |
| `Psx/PsxComponentRegistrar.php:188`、`:249` | `registerComponent()` と `new FunctionComponent` | 本体 | `setArgumentResolver()` (RT-11.3) |
| `Relayer.php:142-144` | 3 つの static の消去 | 本体 | `RequestLifecycle::reset()` (RT-9.6)。状態層の分は登録済みなので呼び分けない |
| `Router/AppRouter.php:1574` | `data-usephp` の手書き | 状態層 | `data-rosanna` |
| `Router/AppRouter.php:1593`、`:1616-1620` | `_usephp_action` と `_usephp_action_sig` を読んで検証 | 状態層 | `State\Protocol\ActionEnvelope::decode()` の `$actionJson` と `$signature` を渡す (02 の AC-20) |
| `Scaffold/Scaffold.php:100` | `AssetInstaller::publish` | 状態層 | `State\Installer\AssetInstaller::publish`。本体だけなら消す。サーバーアクションのフォームが JS に頼っていないかは、移るときに確かめる (未確認) |
| hooks の `useRouter` | `Runtime\useRouter` | Relayer | Relayer が提供する (計画) |

## 15. usePHP の未解決 Issue との対応

| Issue | この文書での扱い |
|---|---|
| #118 | RT-2.4 で解決する。`H` の `children` の型を Renderer と揃える |
| #120 | 状態層の RT-7.4。実装は 02 |
| #119 | 03 |
| #80 | 02 |
| #87 | 状態層の RT-4.7〜RT-4.9 で解決する。差分は 02 の 3.1 節 |

## 決定の記録

2026-11-01 に、いずれも (推奨) の案に決めた。推奨のない 6 は決定済み。8 は手順 4 で確かめた結果を書いた。状態層の分離 (2026-11-02) の前の記録で、2、3、4、5、6、7、10 の対象は今は `polidog/rosanna-state` にある。名前は分離の前のものである。

1. **`PsxArgumentResolver` の名前空間。** 分割設計は `Polidog\UsePhp\Psx` に置くが、計画では `Polidog\Rosanna\Psx` はコンパイラのパッケージの名前空間である。
   - A: `Polidog\Rosanna\Runtime` に置く。(推奨。この文書の案。パッケージと名前空間が対応し、ランタイムだけで型が解決できる)
   - B: `Polidog\Rosanna\Psx` にランタイムのパッケージからも置く。名前空間が 2 パッケージにまたがる。
   - 追記 (2026-11-01): 1 パッケージに統合した ([計画書](../design/rosanna-split-and-rename.md)) が、A のままとする。描画でコンパイラの名前空間を読み込まないため (03 の CP-2)。
2. **コンポーネントの外の hooks。** usePHP では `useState` が初期値と位置 0 の setter を返し、`useEffect` がそのまま実行する (`Hooks.php:23`、`:127`)。位置 0 の setter はどのコンポーネントを指すか決まらない。
   - A: `LogicException` にする。(推奨。誤りをすぐ出せる。Relayer のページは state を設定しているので影響しない)
   - B: usePHP と同じにする。
3. **host のページ自体の state。** Relayer はページの `useState` のために `ComponentState::getInstance()` と `reset()` を直接呼び、`BaseComponent::setComponentState()` も呼ぶ (`relayer/src/Router/AppRouter.php:1477-1482`)。
   - A: v0.0.1 では `ComponentState` の静的メソッドを `@internal` のまま残す。(推奨。作る API が増えない)
   - B: `RenderContext` に、state を設定してコールバックを実行する API を足す。
4. **`children` の `BaseComponent`。** `new Counter()` を置くと、ラッパーを出さず、`#[Component]` の保存先も見ず Session で動く (`Elements.php:1502-1517`)。Snapshot を指定しても効かない。
   - A: `#[Component]` を読み、`fc()` と同じラッパーを出す。(推奨。入口による差がなくなる。ただし HTML が変わる)
   - B: usePHP と同じにし、制約として書く。
5. **クロージャの名前の衝突。** `FC@<basename>:<行>` は、別のディレクトリの同名ファイルの同じ行で同じ名前になり、`fc()` に key がなければ連番で区別される。順番が変わると state が入れ替わる。
   - A: 形式を変えず、ドキュメントで key を勧める。(推奨。パスを含めると、配置の違うサーバー間で ID と署名がずれる)
   - B: プロジェクトからの相対パスのハッシュを足す。プロジェクトルートの決め方が要る。
6. **#87 (一覧の HTML が行数の 2 乗で増える)。** 決定済み。v0.0.1 で解決する (RT-4.7〜RT-4.9、02 の 3.1 節)。
7. **`renderClientScript` の CSP nonce。** 分割設計の署名は `($src, ?$nonce)` だが、実装は `($src, ?$deferPrefix)` で nonce がない (`UsePHP.php:78`)。インラインの script があるので、厳しい CSP では動かない。
   - A: `?string $nonce = null` を第 3 引数に足す。(推奨)
   - B: 足さない。
8. **SVG のアニメーションの URL。** RT-3.11 の属性の一覧に、`<animate>` や `<set>` の `to`、`values` はない。これらで `href` を `javascript:` に変えられるかは確かめていない。
   - A: 手順 4 でブラウザで確かめ、必要なら遮断する。(推奨)
   - B: タグ名や属性名に信頼できない値を渡さない、というドキュメントの注意にとどめる。

   **結果: A。** 修正前の Renderer の出力で、`<set attributeName="href" to="javascript:...">` は Chromium 155 でクリックすると実行された。Renderer で遮断し、RT-3.11 に要件を足した (`tests/browser/svg.spec.ts`)。Firefox と WebKit は確かめていない。
9. **小さな不整合。** いずれも usePHP の実際の動作で、テストはない。
   - void 要素の判定は大文字小文字を区別する (`<BR>` は `<BR></BR>` になる、`Renderer.php:452`)。raw text の判定は区別しない。推奨: 小文字にして比べる。
   - `H::__callStatic` は位置引数を黙って捨てる (`Elements.php:1379`)。タグのメソッドは例外にする。推奨: 同じく `InvalidArgumentException`。
   - `bin/usephp` は未知のコマンドでヘルプを出して終了コード 0 になる。推奨: 終了コード 1。
10. **`Component::$requireKey`。** 引数はあるが `src` のどこからも読まれていない (`Component.php:32`)。この文書の案では作らない。
    - A: 作らない。(推奨。移行時に指定していれば名前付き引数のエラーになるので、リリースノートに書く)
    - B: 受け取って無視する。
