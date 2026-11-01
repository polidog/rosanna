# Rosanna 実装仕様 01 — ランタイムの描画

Status: Draft (2026-11-01、usePHP v0.10.0 `e073bff` / Relayer main `9566b0c` で照合)

計画: [docs/design/rosanna-split-and-rename.md](../design/rosanna-split-and-rename.md)

| 仕様 | 範囲 |
|---|---|
| 01 (この文書) | `Element`、`H`、`Renderer`、コンポーネント、`fc()`、hooks、描画スコープ、`ComponentRegistry`、`Rosanna` の描画 API、host への埋め込み、`publish` |
| [02-state-and-actions.md](02-state-and-actions.md) | Storage、Snapshot、Action、署名、CSRF、バージョン照合、defer、`rosanna.js`、`actions()->handle()`、`signer()` |
| [03-compiler.md](03-compiler.md) | PSX、コンパイラ (`src/Psx/`) |

根拠の `usePHP/...` は `../usePHP` のパスと行番号で、仕様の参考資料である。コードはコピーしない。

## 0. 共通の前提

- **RT-0.1** PHP `>=8.5`。`polidog/rosanna` の必須依存は `ext-mbstring` と `nikic/php-parser` で、どちらもコンパイラのためのもの (03 の CP-1)。描画では php-parser を読み込まない (03 の CP-2)。ルーター、イベント、DI コンテナ、PSR-7 を持たない。
- **RT-0.2** usePHP の `Polidog\UsePhp` を `Polidog\Rosanna` に置換するだけで移行できるよう、次の場所を変えない。
  生成コードと Relayer もこれらを参照する。

| 種類 | 完全修飾名 |
|---|---|
| クラス | `Html\H`、`Runtime\Element`、`Runtime\Renderable`、`Runtime\Action`、`Runtime\FunctionComponent` |
| クラス | `Component\BaseComponent`、`Component\Component`、`Component\ComponentInterface`、`Component\Defer` |
| enum | `Storage\StorageType`、`Storage\SnapshotPersist` |
| 関数 | `Html\createElement`、`Runtime\useState`、`Runtime\useFormAction`、`Runtime\useEffect`、`Runtime\fc` |
| クラス | `Installer\AssetInstaller` |

- **RT-0.3** usePHP の `Runtime\useRouter` は作らない。`Polidog\Rosanna\Rosanna` が usePHP の `UsePHP` にあたる。
- **RT-0.4** `H` と hooks の関数は Composer の `autoload.files` で読み込む (`usePHP/composer.json`)。
- **RT-0.5** 例外の使い分けは次のとおり。

| 例外 | 使う場面 |
|---|---|
| `InvalidArgumentException` | `H`、`createElement`、`fc`、`useFormAction`、`#[Component]` に渡した引数の誤り |
| `TypeError` | 型宣言のある `H` のメソッド (`H::button(onClick: 'x')`) に合わない型を渡したとき。PHP が投げるので関数は実行されない (RT-2.6) |
| `RuntimeException` | Renderer に届いた不正な Element、PSX の読み込み失敗 |
| `LogicException` | 登録や設定の不足。未登録のコンポーネント、秘密鍵なしの Snapshot、スコープ外の呼び出し |

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
- **RT-1.2** `type` の予約値は次の 3 つ。いずれもタグとして出力しない。

| type | 意味 | 処理 |
|---|---|---|
| `Fragment` | タグなしで子だけを出す | RT-3.2 |
| `__defer__` | defer のプレースホルダー | 02 |
| `__function_component__` | `H::component()` の未解決要素 | RT-6.7 |

- **RT-1.3** `createElement` と `H::__callStatic` は `__defer__` と `__function_component__` を `InvalidArgumentException` にする。これらの props は実行する関数とプレースホルダーの内容で、
  `createElement('__function_component__', ['__component' => 'system', ...])` のように値から作らせないため。`H::component()` と `H::defer()` は `createElement` を通らずに作る。
  `Fragment` は生成コードが使うので残す。

**テスト:** 不変であること。予約値のそれぞれの処理。予約の type を `createElement` と `H::{$type}()` に渡すと例外で、関数が実行されないこと。

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
        Action|\Closure|null $onClick = null,
        array|Element|Renderable|\Stringable|string|int|float|bool|null $children = null,
        mixed ...$attrs,
    ): Element;

    public static function Fragment(array $children): Element;
    public static function component(callable $component, array $props = []): Element;
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
function getFunctionComponentName(callable $component): string;
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

- **RT-2.6** イベントの値は `Action` か、必須引数のないクロージャだけを受ける。クロージャはその場で実行し、戻り値が
  `Action` なら `wire:click`、`wire:change`、`wire:input`、`wire:submit` の prop にする。`Action` 以外を返したら prop を付けない
  (`fn() => $cond ? $set(1) : null` のため)。
  文字列、配列、呼び出し可能オブジェクトは実行せずに `InvalidArgumentException` にする。関数名を実行させないためで、
  セキュリティ要件である (`Elements.php:1436-1461`)。
- **RT-2.7** `H::Fragment($children)` は `Element('Fragment', [], resolveRenderables($children))` を返す。
- **RT-2.8** `resolveRenderables` はネストした配列を文書順に平坦化し、関数コンポーネントと `Renderable` を展開する
  (`Elements.php:1491`)。`BaseComponent` の処理は RT-5.4、`__function_component__` は RT-6.7。

**エラー:** RT-2.3、RT-2.5、RT-2.6 のとおり。必須引数を持つクロージャには「`useFormAction()` を使う」と案内する。

**テスト**

- 名前付きの属性、スプレッドの属性、位置引数の拒否 (`tests/Html/ElementAttributesTest.php`)。
- `textarea` の `value`、数値の属性 (`tests/Html/ElementAttributeTypesTest.php`)。
- `strict_types` で `children` に int、float、bool、`Stringable`、`Renderable` を渡せること (#118)。
- 関数名の文字列、配列 callable、呼び出し可能オブジェクトを実行せず拒否すること
  (`tests/Security/SecurityHardeningTest.php` の `testEventProp*`)。
- 未対応イベントの拒否と、値が `null` のときの無視 (`tests/Runtime/ElementEventsTest.php`)。

## 3. Renderer と出力の安全性

**目的:** Element の木を HTML にする。計画の「作り直しても引き継ぐもの」のうち、タグ名と属性名の検証、
インラインの `on*` の拒否、`javascript:` の遮断、不正な UTF-8 の置換、`script` と `style` の出力、描画できない子の拒否を満たす。

```php
namespace Polidog\Rosanna\Runtime;

final class Renderer
{
    public const TAG_NAME_PATTERN = '/^[A-Za-z][A-Za-z0-9\-]*$/';
    public const ATTRIBUTE_NAME_PATTERN = '/^[A-Za-z_:][A-Za-z0-9:_.\-]*$/';

    /** @param callable(): Element $component ラッパー付き */
    public function render(callable $component): string;
    /** @param callable(): Element $component ラッパーなし (部分更新) */
    public function renderPartial(callable $component): string;
    public function renderElement(Element|string $element): string;
}
```

`Renderer` は `new` せず、`RendererFactory` から得る (RT-9.5)。

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
- **RT-3.6** 未解決の `__function_component__` が Renderer に届いたら `RuntimeException` にする (`Renderer.php:437`)。

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

**テスト**

- `tests/Runtime/RendererOutputTest.php` と `tests/Security/SecurityHardeningTest.php` の `testRenderer*` にある項目。次を必ず含める。
  - `\tjavascript:`、`java\tscript:`、`JAVASCRIPT:` の遮断。`HREF` のような大文字の属性名。タブを含む無害な URL が変わらないこと。
  - 不正な UTF-8 のテキスト、属性、state の値で出力が空にならないこと。
  - `<svg><style>` のエスケープと、その後の `<style>` が raw text に戻ること。
  - `</script` を子に分割しても拒否すること。
- 描画できない子 (`object`、`resource`、`Closure`) の拒否。

## 4. Action の束縛とフォームの配置

**目的:** `wire:*` の prop を、JavaScript なしでも動く HTML にする。hidden フィールドの中身は 02 が定める。この節は配置だけを定める。

- **RT-4.1** 束縛は次の表のとおり (`Renderer.php:515`)。`wire:` で始まる prop の値が `Action` でなければ `RuntimeException`。

| イベント | 要素 | 出力 |
|---|---|---|
| `submit` | `form` | 要素自体を action のフォームにする。`method="post"` と `data-rosanna-form` を足し、hidden フィールドを先頭の子にする |
| `click` | `button` | `type="submit"`、`form="<id>"`、`name="_rosanna_action"`、`value="<エンベロープ>"` (02 の AC-20)。共有フォームを使う (RT-4.7)。利用者の `name` か `value` を持つ `button` は、これらを付けず個別フォームを使う |
| `click` | `input` で `type` が `submit`、`image` | `form="<id>"`。個別フォームを使う。`submit` は `value` がラベルになり、`image` は `value` が送られないため |
| `click`、`change`、`input` | その他 | `data-rosanna-on-<event>="<id>"` と `data-rosanna-action-<event>="<エンベロープ>"`。共有フォームを使う。`rosanna.js` が必要 |
| その他 | | `RuntimeException` |

  この節で読む属性の名前 (`action`、`method`、`name`、`value`、`type`、`multiple`) は大文字小文字を区別しない。Rosanna が書く `type`、`form`、`method` と同じ名前の利用者の属性は、大文字でも取り除く。パーサーは同じ属性の最初を読むため。
- **RT-4.2** `submit` を `form` 以外に付けたら `RuntimeException`。`action` を持つ `form`、`method` が `post` でない `form` も `RuntimeException`。
  JavaScript の有無で送信先が変わるのを防ぐため (`Renderer.php:593`)。
- **RT-4.3** `Action::SET_FIELDS` を `submit` 以外に付ける場合、次のいずれかなら `RuntimeException`。条件を満たせば
  `data-rosanna-with-value` を付ける (`Renderer.php:570`)。`submit` の場合も同じ属性を付ける。
  - イベントが `click`。
  - 要素が `input`、`select`、`textarea` でない。
  - `multiple` か `type="file"` を持つ。
  - フィールドのマップが、要素の `name` 1 つだけでない。

- **RT-4.4** 共有フォームと個別フォームは `<form method="post" data-rosanna-form id="rosanna-f-<16桁の16進>" hidden>` とする。
  ID はページ内で一意にするため `random_bytes(8)` から作る。インスタンスから決まる固定の ID にはしない。アプリの `form` の中のコンポーネントはフォームがラッパーの外に
  置かれ (RT-4.5)、部分更新の後も古いフォームが残る。ID が同じだと、新しいボタンが古いスナップショットのフォームを指す。
- **RT-4.5** 共有フォームと個別フォームは要素の隣に出さず、溜めておいて次の場所に出す。`form`、`p`、`tbody` の中に置いても
  マークアップが崩れないようにするため (`Renderer.php:296-305`、`:461-487`)。
  1. `data-rosanna` を持つ要素の中で、開いている `form` がなく、その要素自身も `form` でないとき: その要素の閉じタグの直前。その要素より外で溜まっていたものは持ち込まず、外に戻す。
     部分更新で親のフォームが消えないようにするため。`data-rosanna` を持つ `form` は入れ子を作らないよう 2 に従う。
  2. 最も外側の `form` の閉じタグの直後。
  3. 残りは最も外側の `renderElement()` の末尾。
- **RT-4.6** CSRF トークンはフォームを出力するときに読む。Action のないページでセッションを開始しない
  (`Renderer.php:669`、`tests/Runtime/ActionFormPlacementTest.php:191`)。これにより Relayer の `LayoutRenderer::containsStateAction()` は不要になる。
- **RT-4.7** **#87 への対応。** 共有フォームは、スコープと action の対象のインスタンスの組み合わせごとに 1 つ作る。スコープは最も内側の `data-rosanna` 要素で、なければ最も外側の
  `renderElement()` とする。中身は action を除く hidden フィールドで、1 回だけ書く。action はボタンの `value` か `data-rosanna-action-<event>` が持つ。
- **RT-4.8** 書き出した後も、同じスコープの間は共有フォームの ID を使い回す。`form` 属性は文書のどの位置のフォームも指せるので、行ごとにアプリの `form` があっても増えない。
  差分の基準 (02 の AC-18) が書き出し済みのフォームに足りないときだけ、新しい共有フォームを作る。
- **RT-4.9** 個別フォームは action ごとに作り、hidden フィールドをすべて持つ。`submit` の `form` は要素自体が個別フォームになる。

**テスト:** `tests/Runtime/ActionFormPlacementTest.php` と `ElementEventsTest.php` の項目。次を必ず含める。

- アプリの `form`、`p`、`table` の中のボタンで、入れ子のフォームや分断が起きないこと。
- 親のフォームが子コンポーネントのラッパーに入らないこと。
- JavaScript なしの送信で動く要素と、`data-rosanna-on-*` になる要素の区別。
- RT-4.1: 利用者の `name` か `value` を持つ `button` の値が上書きされず、個別フォームで動くこと。
- RT-4.4: アプリの `form` の中のコンポーネントを部分更新した後、新しいボタンが新しいフォームを指すこと。
- RT-4.7: 行のボタンが何個あっても共有フォームが 1 つで、対象のインスタンスが違えば分かれること。
- RT-4.7: 行数を 2 倍にしたとき、HTML のサイズがおよそ 2 倍に収まること (Session、Snapshot)。
- RT-4.8: 行ごとのアプリの `form` の中のボタンでも共有フォームが増えないこと。

## 5. クラスコンポーネント

**目的:** クラスでコンポーネントを書く入口。

```php
namespace Polidog\Rosanna\Component;

interface ComponentInterface
{
    public static function getComponentName(): string;
    public function render(): Element;
}

abstract class BaseComponent implements ComponentInterface
{
    /** @internal */ public function setComponentState(ComponentState $state): void;
    public function getComponentState(): ?ComponentState;
    protected function useState(mixed $initial): array;
    public static function getComponentName(): string;
}

#[\Attribute(\Attribute::TARGET_CLASS)]
final class Component
{
    public StorageType $storageType;
    public ?SnapshotPersist $persist;

    public function __construct(
        public ?string $name = null,
        StorageType|string $storage = StorageType::Session,
        SnapshotPersist|string|null $persist = null,
    );
}
```

**振る舞い** (`usePHP/src/Component/`)

- **RT-5.1** `getComponentName()` は `#[Component(name:)]`、なければクラス名を返す。
- **RT-5.2** `$storage` と `$persist` は enum か、その値の文字列を受ける。`persist` を Snapshot 以外に指定したら `InvalidArgumentException`。
- **RT-5.3** `BaseComponent::useState()` は RT-7.1 と同じだが、setter の Action に `storageType` を入れない。action の処理時にレジストリの設定で決める
  (`BaseComponent.php:55`、`UsePHP.php:2160`)。state が未設定なら `[$initial, setter]` を返す。
- **RT-5.4** `children` に `BaseComponent` のインスタンスを置いた場合 (`Elements.php:1502`):
  - state が未設定なら、スタックに積んで ID を作り、state を割り当てて `render()` し、`finally` で親の state とスタックを戻す。
  - 設定済みなら新しい番号を取らず、同じ state をスタックに積み、hook の位置を 0 に戻して `render()` し、`finally` で親の state とスタックを戻す。同じインスタンスを再利用しても同じ状態を読む。
  - どちらも `#[Component]` の `persist` を読み、`fc()` と同じラッパーを出す (RT-6.4、RT-6.5)。未設定のときは保存先も読む。usePHP はラッパーを出さず保存先も見ないので、HTML が変わる。

**テスト:** `tests/Component/ComponentAttributeTest.php`、`ComponentPersistTest.php`、`tests/Runtime/NestedRenderRestoreTest.php`。
子の後で親の hooks が親に戻ること。子が例外を投げても親と ID が戻ること。

## 6. fc() と関数コンポーネント

**目的:** クロージャでコンポーネントを書く入口。推奨の書き方で、PSX もこの形になる。

```php
namespace Polidog\Rosanna\Runtime;

function fc(
    callable $component,
    ?string $key = null,
    StorageType $storageType = StorageType::Session,
    ?Defer $defer = null,
    ?SnapshotPersist $persist = null,
): FunctionComponent;

final class FunctionComponent
{
    public function __construct(
        public readonly \Closure $inner,
        public readonly ?string $key = null,
        public readonly StorageType $storageType = StorageType::Session,
        public readonly ?Defer $defer = null,
        public readonly ?SnapshotPersist $persist = null,
    );
    public function __invoke(array $props = []): Element;
}
```

`$defer` は `CompileCommand` が読む (03)。

**振る舞い** (`usePHP/src/Runtime/FunctionComponent.php`)

- **RT-6.1** `$persist` を Snapshot 以外と組み合わせたら `InvalidArgumentException`。
- **RT-6.2** `__invoke` は `$defer` があり、現在のスコープが「このコンポーネントの defer エンドポイントの描画」でなければ、
  プレースホルダーを返す (`Defer::buildPlaceholder`、02)。入れ子の defer はプレースホルダーのままにする。
  スコープがない場合もプレースホルダーを返す。
- **RT-6.3** それ以外は次の順で描画する。例外でも `finally` でスタックと親の state を戻す。
  1. `key` prop を取り除き、インスタンス ID を決める (RT-8.2)。
  2. コンポーネントをスタックに積み、親の state を覚え、`ComponentState::getInstance($id, $storageType)` を現在にして hook の位置を 0 に戻す。
  3. `inner($props)` を実行する。
- **RT-6.4** 結果は `Element('div', ['data-rosanna' => $id], [$result])` で包む。
- **RT-6.5** Snapshot のとき、ラッパーに `data-rosanna-snapshot` を付ける。署名が得られれば、`persist` の有無によらず `data-rosanna-restore-sig` も付ける (02 の VR-7)。
  `persist` があれば `data-rosanna-persist` を付ける (値の形式は 02)。
- **RT-6.6** Snapshot でスコープがなければ `LogicException`。署名なしのスナップショットを出さないため (`FunctionComponent.php:93`)。
- **RT-6.7** `H::component($c, $props)` は `__function_component__` の Element を返す。`children` の中に置くと `resolveRenderables` が展開する。
  `fc()` と違ってラッパーを出さず、保存先は Session になる (`Elements.php:1534`)。`key` は props から取り除いて ID に使い、int は文字列にする。
  `__component` には `\Closure` だけを入れ、ID の名前 (RT-8.1) は元の callable から作って別に持つ。展開は `__component` が `\Closure` でなければ実行せず `RuntimeException` にする。手で組んだ `new Element('__function_component__', ...)` の props から文字列の関数名を実行させないため。
- **RT-6.8** 同じ ID で 2 つ描画しても検出せず、state を共有する (`FunctionComponent.php:121`)。

**テスト:** `tests/Runtime/FunctionComponentTest.php`。次を必ず含める。

- `$key` なしの自動採番、固定 key、props の key の組み合わせ。
- 親の hooks が子の後も親に束縛されること。例外時の復元。
- key に配列を渡したときの `InvalidArgumentException`。
- スコープなしの Snapshot の `LogicException`。

## 7. hooks

**目的:** React と同じ形の状態 API。値の保存先は 02。

```php
namespace Polidog\Rosanna\Runtime;

/** @return array{0: T, 1: callable(T): Action} */
#[\NoDiscard]
function useState(mixed $initial): array;

function useFormAction(array $fields): Action;

function useEffect(callable $callback, ?array $deps = null): void;
```

### useState

- **RT-7.1** 現在の `ComponentState` の次の hook 位置を取り、値を返す。setter は `Action::setState($index, $value, $componentId, $storageType)` を返すクロージャで、
  呼んでも状態は変わらない。値は描画時に確定する (`usePHP/src/Runtime/Hooks.php:19`)。setter は描画時の値と新しい値を比べ、使えるときは `Action::setStateDiff()` を返す
  (02 の AC-13)。利用者のコードは変わらない。
- **RT-7.2** hook は呼び出し順で識別する。条件分岐で順番を変えた場合は検出しない。
- **RT-7.3** `null` も値として保持する。初期値の再適用はしない (`tests/Runtime/HooksTest.php` の `testSetStateNullIsKeptInSessionStorage`)。
- **RT-7.4** **#120 への対応。** 読み取りだけでセッションを開始しない。保存側の要件は 02。
- **RT-7.4a** コンポーネントの外で `useState`、`useFormAction`、`useEffect` を呼ぶと `LogicException`。ページ自体の state を host が設定する場合は `ComponentState::setCurrent()` を `@internal` のまま使う。

### useFormAction

- **RT-7.5** `['field' => $setter]` を受け、`Action::setFields($map, $componentId, $storageType)` を返す (`Hooks.php:59`)。
  `$map` はフィールド名から hook 位置への対応で、署名の対象になる。
- **RT-7.6** 次の場合は `InvalidArgumentException`。
  - 配列が空。
  - フィールド名が `/^[A-Za-z_][A-Za-z0-9_\-]*$/` に合わない、または `_rosanna_` で始まる。`tags[]` や `a.b` は `$_POST` から読み戻せないため。
  - 値が `useState` の setter でない。`\Closure` に限り、`null` を渡して `setState` の Action が返るかで判定する。
  - setter が複数のコンポーネントにまたがる。
- **RT-7.7** 使える場所の制限は Renderer が検査する (RT-4.3)。値の検証は 02。

### useEffect

- **RT-7.8** 描画の中で同期的に実行する (`Hooks.php:123`)。実行の判定は次のとおり (`ComponentState.php:161`)。

| `$deps` | 実行するとき |
|---|---|
| `null` | 毎回 |
| `[]` | 前回の deps が配列でないとき (初回) だけ |
| 配列 | 前回の deps が配列でない、要素数が違う、いずれかが `!==` のとき |

- **RT-7.9** 実行の順番は、前回のクリーンアップ、callback、クリーンアップの記録、deps の保存とする。
- **RT-7.10** クリーンアップとして扱うのは callback が返した `\Closure` だけ。`fn() => 'system'` のような文字列を実行しないため、セキュリティ要件である。
- **RT-7.11** クリーンアップはリクエストのメモリにだけ置き、保存先に書かない。`$_SESSION` に Closure を書くと終了時に致命的エラーになるため
  (`ComponentState.php:29`)。次のリクエストでは実行しない。アンマウントはない。

**テスト:** `tests/Runtime/HooksTest.php` と `FormActionTest.php`。`useRouter` の項目は持ち込まない。次を必ず含める。

- deps が `null` から配列に変わるとき、値としての `null` を比べるとき。
- 多重実行しないこと、クリーンアップが保存先に書かれないこと、次のリクエストで実行されないこと。
- `useFormAction` の不正なフィールド名、setter でない値、複数コンポーネント。

## 8. インスタンス ID と描画パス

**目的:** 1 回の描画の中でコンポーネントのインスタンスを識別する。ID は state のキー、署名の対象、HTML の `data-rosanna` の値になる。

- **RT-8.1** ID の形式は `<名前>#<key>` とする。名前は次のとおり (`usePHP/src/Html/Elements.php:1563`)。

| コンポーネント | 名前 |
|---|---|
| クラス | `getComponentName()` |
| クロージャ | `FC@<ファイルの basename>:<開始行>` |
| 配列 callable | `<クラス>::<メソッド>` |
| 文字列 callable | その文字列 |

- **RT-8.2** key は次のとおり (`FunctionComponent.php:124`)。props の key は `string` か `int` だけを許し、それ以外は `InvalidArgumentException`。

| `fc()` の key | props の key | ID の key |
|---|---|---|
| なし | なし | 名前ごとの連番 (`0`、`1`、…) |
| `a` | なし | `a` |
| なし | `b` | `b` |
| `a` | `b` | `a/b` |

- **RT-8.3** 連番はパスごとに名前ごとに数える (`RenderContext.php:208`)。親の ID を含めない。
- **RT-8.4** usePHP の `ComponentId` の階層形式 (`Page@home/Counter@item-1`) は使われていないので作らない。`Runtime/ComponentId.php` の `parse`、`getPath`、`toString` 系は `src` からも Relayer からも呼ばれていない。
  ID の検証は 02。
- **RT-8.5** パスの操作は次のとおり。`resumePass` は名前ごとに大きいほうの連番を残し、入れ子のパスが使った番号を外側のパスが再利用しないようにする
  (`RenderContext.php:92`)。

```php
RenderContext::beginRender(): void;        // 新しいパスを始める
RenderContext::currentPass(): array;       // いまのパスを保存する
RenderContext::resumePass(array $p): void; // 保存したパスに戻す
```

**テスト:** `tests/Runtime/NestedRenderRestoreTest.php`、`RenderContextTest.php`。入れ子の描画の前後の兄弟で ID が重ならないこと、例外時にスタックは戻り、連番は戻さないこと (`resumePass` は大きいほうを残す。usePHP も同じ)。

## 9. 描画スコープ

**目的:** `RenderContext::getApp()` をなくす (計画の決定)。hooks がグローバル関数である以上、描画中の ambient は残るが、
型を必要な機能に絞る。分割設計の `RenderScope` を、ルーターなしに合わせて調整する。

```php
namespace Polidog\Rosanna\Runtime;

final class RenderScope
{
    public readonly RendererFactory $renderers;
    public readonly PsxRenderer $psx;                         // 生成コードが呼ぶ
    public readonly ?SnapshotSerializer $snapshotSerializer;  // 秘密鍵がなければ null
    public readonly ActionSigner $signer;                     // 02。鍵がなければ sign() が null を返す
    public readonly string $signingScope;                     // 02 の SG-5、SG-6。page:{パス} か defer:{名前}

    public function isDeferredEndpointTarget(FunctionComponent $component): bool; // 02
}

final class RenderContext
{
    /** enter と leave を例外時も含めて保証し、$fn の戻り値を返す。入れ子にできる */
    public static function run(RenderScope $scope, callable $fn): mixed;
    /** @throws \LogicException スコープの外 */
    public static function scope(): RenderScope;
    public static function currentScope(): ?RenderScope;
    // パスの操作は RT-8.5
}

final class RendererFactory
{
    public function create(
        string $rootId,
        ?StorageType $storage = null,
        ?SnapshotPersist $persist = null,
        ?string $deferQuery = null,
        ?string $deferName = null,
    ): Renderer;
}

final class RequestLifecycle
{
    public static function reset(): void;
}
```

**振る舞い**

- **RT-9.1** `RenderContext` が持つスコープの型は `RenderScope` だけとする。`Rosanna` 型を返す API を作らない。
- **RT-9.2** `run` の入れ子では、抜けるときに外側のスコープに戻す (`tests/Runtime/AppContextRestoreTest.php`)。
- **RT-9.3** ルート情報を持たない。分割設計の `RenderScope::$route` と `RouteInfo` は作らない。
- **RT-9.4** 分割設計の `renderingDeferredEndpoint: bool` では足りない。入れ子の defer をプレースホルダーにするには「対象のコンポーネントかどうか」の判定が要るので、メソッドにする
  (`usePHP/src/UsePHP.php:416`、`tests/Runtime/NestedDeferTest.php`)。
- **RT-9.5** `RendererFactory` は serializer、defer の prefix、CSRF トークン、署名を必ず揃えて `Renderer` を作る。Relayer の `LayoutRenderer` が `deferPrefix` を渡せない問題を解消する
  (分割設計「Relayer が現在…」)。`rootId` は action の `componentId` がないときの送信先になる (`Renderer.php:633`)。Renderer は描画時のバージョンを action の JSON に入れる (02 の VR-1)。
- **RT-9.6** `RequestLifecycle::reset()` は `ComponentState` のインスタンス、Storage、描画パス、スコープをすべて消す。Relayer の `endRequest()` はこれに置き換える
  (`relayer/src/Relayer.php:142-144`)。worker モードで別の訪問者に状態が漏れないようにするため。
  セッションも書き込んで閉じ、ID を手放す。PHP は閉じた後も ID を覚えていて次の `session_start()` で Cookie より優先するので、閉じるだけでは次の訪問者が前のセッションに入る。次の開始では `$_COOKIE` の ID を使う。host が `session_id()` で決めた ID は変えない。

**エラー:** スコープの外で `RenderContext::scope()` を呼んだら `LogicException`。生成コードはスコープの中で動くことを前提にする。

**テスト**

- `run` の入れ子と、例外時の復元。
- スコープの外での `scope()` の例外。`currentScope()` の `null`。
- `reset()` の後に state とスコープが残らないこと。

## 10. ComponentRegistry

```php
namespace Polidog\Rosanna\Component;

final class ComponentRegistry
{
    /** @param class-string<ComponentInterface> $className */
    public function register(string $className): self;
    public function has(string $name): bool;
    /** @return class-string<ComponentInterface>|null */
    public function get(string $name): ?string;
    public function create(string $name): ?ComponentInterface;
    public function getStorageType(string $name): StorageType;
    public function getPersist(string $name): ?SnapshotPersist;
    public function getDefer(string $name): ?Defer;
    /** @return array<string, class-string<ComponentInterface>> */
    public function all(): array;
}
```

**振る舞い** (`usePHP/src/Component/ComponentRegistry.php`)

- **RT-10.1** 名前は `getComponentName()` とし、同じ名前の再登録は上書きする。`#[Defer]` と `persist` も上書きで消す。古いクラスの設定を引き継がないため。
- **RT-10.2** `getStorageType` は未登録と `#[Component]` なしのとき `Session` を返す。
- **RT-10.3** `create` は未登録なら `null`、それ以外は引数なしで `new` する。DI はしない。
  コンストラクタに引数を要するクラスは登録せず、host が `Element` を作って埋め込む。

**テスト:** `tests/Component/ComponentRegistryTest.php`。再登録で設定が残らないこと。

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

`PsxRenderer` は定数 `MANIFEST_FILENAME` (`manifest.php`) と `DEFERRED_MANIFEST_FILENAME` (`deferred-manifest.php`) を持つ。`CompileCommand` はこれを参照する (03 の CP-137)。

**振る舞い**

- **RT-11.1** 生成コードの PSX コンポーネントの呼び出しは `RenderContext::scope()->psx->render('Fqcn', $props)` とする (分割設計)。
  生成コードの形は 03 が定める。`H::defer` と `createElement` の呼び出しは変えない。
- **RT-11.2** `render` は次の順で動く (`usePHP/src/UsePHP.php:538`)。読み込んだ callable はインスタンスに保持する。
  1. 読み込み済みならそれを使う。
  2. manifest になければ `RuntimeException`。
  3. ファイルがない、読めない、`ParseError`、callable を返さないときは `RuntimeException`。
     `vendor/bin/rosanna compile` を案内し、`ParseError` は `previous` に入れる。
- **RT-11.3** `PsxArgumentResolver` は DI を持たずに host が引数を渡すための口で、第 2 引数以降を解決する。`fc()` の `inner` にも適用する (分割設計)。
  Relayer はここにコンテナを使う実装を渡し、`PsxComponentRegistrar` の再登録を消す。これで `wrapFunctionComponent` が `persist` を落としている問題 (`relayer/src/Psx/PsxComponentRegistrar.php:249`) もなくなる。
- **RT-11.4** 既定の実装は default 値と nullable だけを解決する。manifest の `parameters` の形式は 03。
- **RT-11.5** `loadComponentManifest($path)` は manifest を読み、同じディレクトリに deferred manifest があれば、その項目を `registerDeferred()` に渡す (02 の DF-13)。ファイルがない、配列を返さない、項目の形が違うときは `RuntimeException` (`usePHP/src/UsePHP.php:255-305`)。
- **RT-11.6** `StackTraceRewriter` はランタイム側 (`src/Psx/` の外) に置く (03 の 10 節)。

**テスト:** 未登録、ファイルなし、`ParseError`、callable でない戻り値のそれぞれの例外。resolver が `fc()` の内側にも効くこと。

## 12. Rosanna の描画 API と host への埋め込み

**目的:** 1 つのコンポーネントや Element の木を、host のページに埋め込む。URL からページを選ぶのは host の役割で、`run()` と `handle()` は作らない。

```php
namespace Polidog\Rosanna;

final class Rosanna
{
    // 登録
    /** @param class-string<ComponentInterface> $className */
    public function register(string $className): self;
    public function registerComponent(string $fqcn, callable $component): self;
    public function loadComponentManifest(string $path): self;
    public function setArgumentResolver(PsxArgumentResolver $resolver): self;

    // 描画
    public function render(string $componentName, ?string $key = null): string;
    public function createElement(string $componentName, ?string $key = null): Element;
    public function renderElement(Element $element): string;
    /** $deferName があれば defer エンドポイントの描画とし、署名の scope を defer:{名前} にする */
    public function scope(Request $request, ?string $deferName = null): RenderScope;

    public static function renderClientScript(string $src = '/rosanna.js', ?string $deferPrefix = null, ?string $nonce = null): string;

    // 02: setSnapshotSecret、actions、signer、snapshotSerializer、csrf など
}
```

**振る舞い**

- **RT-12.1** `render` と `createElement` の `$componentName` は、登録名とクラス名のどちらでもよく、結果は同じインスタンス ID と state になる
  (`usePHP/src/UsePHP.php:578-596`、`tests/Runtime/ComponentNameResolutionTest.php`)。
- **RT-12.2** 登録されていないものは `LogicException`。未登録のクラスを副作用で登録しない。失敗してもスコープとパスを壊さない。
- **RT-12.3** 3 つの入口の違いは次のとおり (`UsePHP.php:2813-2930`)。いずれも自分のスコープで実行し、親の state、外側のスコープを `finally` で戻す。

| 入口 | 出力 | パス |
|---|---|---|
| `render` | ラッパーの `div` 付き HTML | 新しいパスを始め、`resumePass` で戻す |
| `createElement` | ラッパー付きの `Element` | 現在のパスを続ける |
| `renderElement` | HTML。ルート ID は `_root_` | 現在のパスを続ける |

- **RT-12.4** ラッパーの属性は RT-6.4、RT-6.5 と同じ。Snapshot で秘密鍵がなければ `LogicException` で、秘密鍵の設定を案内する (`Renderer.php:1079`)。
- **RT-12.5** `register` は `#[Defer]` があるクラスの defer エンドポイントも登録する (02)。
- **RT-12.6** `renderClientScript` は `<script src>` を `defer` 付きで出し、`$src` をエスケープする。`$nonce` があれば、出力するすべての `script` に `nonce` を付ける。defer のフォールバックと `$deferPrefix` の扱いは 02
  (`UsePHP.php:78`、`tests/Runtime/ClientScriptTest.php`)。Relayer は固定のパスで読み込むので使っていない (`relayer/src/Router/Document/HtmlDocument.php:11`)。
- **RT-12.7** host への埋め込みの形は次のとおり。action の入口は `actions()->handle()` (02) で、描画より前に呼ぶ。

```php
$app = new Rosanna();
$app->register(Counter::class);

$response = $app->actions()->handle($request, replay: fn (bool $partial) => /* 02 */);
// action の POST でなければ描画に進む
echo $app->render(Counter::class);
```

- **RT-12.8** `fc()` を host が直接呼ぶ場合、または host が自分のレイアウトと組み合わせる場合は、`RenderContext::run($app->scope($request), $fn)` の中で Element を作り、
  `RendererFactory` で描画する。
- **RT-12.9** `render`、`createElement`、`renderElement` は、外側のスコープがあればその署名の scope を使う。なければ `Request::fromGlobals()` からスコープを作る。パスを書き換える host は RT-12.8 の形で自分の `Request` を渡す。

**テスト**

- クラス名と登録名で ID と state が同じこと、解決できない名前の例外 (`ComponentNameResolutionTest.php`)。
- 入れ子の `render` で外側のスコープ、state、パスが戻ること (`AppContextRestoreTest.php`、`NestedRenderRestoreTest.php`)。
- README の埋め込みの例を実際に動かすこと (usePHP の `tests/Integration/ReadmeExamplesTest.php` に相当)。

## 13. bin/rosanna と AssetInstaller

**目的:** `rosanna.js` をプロジェクトの `public/` に配置する。Relayer の `relayer init` が書く Composer スクリプトもこれを呼ぶ
(`relayer/src/Scaffold/Scaffold.php:100`)。

```php
namespace Polidog\Rosanna\Installer;

final class AssetInstaller
{
    public static function publish(\Composer\Script\Event $event): void;
}
```

```
vendor/bin/rosanna publish           public/rosanna.js に配置する
vendor/bin/rosanna compile [...]     Polidog\Rosanna\Psx\CompileCommand を呼ぶ (03)
vendor/bin/rosanna help              ヘルプを表示する
```

**振る舞い** (`usePHP/bin/usephp`、`usePHP/src/Installer/AssetInstaller.php`)

- **RT-13.1** `bin/rosanna` は `../vendor/autoload.php` と `../../../autoload.php` の順で読み込む。
- **RT-13.2** `publish` の配置先はプロジェクトルートの `public/rosanna.js` とする。プロジェクトルートは、パッケージの 3 階層上の `vendor` の親とする。
  ディレクトリがなければ `0755` で作る。既存のファイルは上書きする。
- **RT-13.3** パッケージのルートで実行した場合 (3 階層上が `vendor` でない) は、案内を出して何もしない。
- **RT-13.4** `publish` の失敗 (元のファイルがない、ディレクトリを作れない、コピーできない) は標準エラーに出して終了コード 1 にする。
- **RT-13.5** `AssetInstaller::publish` は Composer の `vendor-dir` 設定から配置先を求める。失敗は警告にとどめ、例外を投げない。`composer install` を止めないため。
- **RT-13.6** `Composer\Script\Event` の型は Composer の中でしか解決されない。ランタイムの `require` に `composer/composer` を足さない。静的解析のためには `require-dev` に入れる。
- **RT-13.7** `compile` は `Polidog\Rosanna\Psx\CompileCommand` を直接呼ぶ。コンパイラは同じパッケージにあるので、存在の確認も案内もしない (2026-11-01 に変更。03 の CP-140)。
- **RT-13.8** `public/rosanna.js` を配布物に含める。`.gitattributes` の `export-ignore` に入れない。
- **RT-13.9** 未知のコマンドはヘルプを出して終了コード 1 にする。

**テスト:** 一時ディレクトリに `vendor/polidog/rosanna` の配置を作り、次を確かめる。

- 配置先の作成と上書き、パッケージのルートでの案内。
- コピー失敗で終了コードが 1 になること、`AssetInstaller` は例外を投げないこと。
- 依存として入った配置から `compile` が動くこと。

## 14. Relayer が使っている箇所の置き換え

実際の呼び出しは `relayer/src` の検索で確かめた。action まわりは 02。

| Relayer の箇所 | 現在 | Rosanna |
|---|---|---|
| `Router/AppRouter.php:461`、`:562` | `RenderContext::setApp()`、`clearApp()` | `RenderContext::run($app->scope($request), ...)` |
| `Router/Layout/LayoutRenderer.php:86` | `new Renderer(...)` | `RenderContext::scope()->renderers->create(...)` |
| `Router/Layout/LayoutRenderer.php` の `containsStateAction` | CSRF を読むかの判定 | 不要 (RT-4.6) |
| `Router/AppRouter.php:1475`、`:1498` | `RenderContext::beginRender()` | そのまま (RT-8.5) |
| `Router/AppRouter.php:1477-1478` | `ComponentState::getInstance()`、`reset()` | そのまま。`@internal` で残す |
| `Psx/PsxComponentRegistrar.php:188`、`:249` | `registerComponent()` と `new FunctionComponent` | `setArgumentResolver()` (RT-11.3) |
| `Relayer.php:142-144` | 3 つの static の消去 | `RequestLifecycle::reset()` (RT-9.6) |
| `Router/AppRouter.php:1574` | `data-usephp` の手書き | `data-rosanna` |
| `Router/AppRouter.php:1593`、`:1616-1620` | `_usephp_action` と `_usephp_action_sig` を読んで検証 | `ActionEnvelope::decode()` の `$actionJson` と `$signature` を渡す (02 の AC-20) |
| `Scaffold/Scaffold.php:100` | `AssetInstaller::publish` | 名前空間の置換 (RT-0.2) |
| hooks の `useRouter` | `Runtime\useRouter` | Relayer が提供する (計画) |

## 15. usePHP の未解決 Issue との対応

| Issue | この文書での扱い |
|---|---|
| #118 | RT-2.4 で解決する。`H` の `children` の型を Renderer と揃える |
| #120 | RT-7.4。実装は 02 |
| #119 | 03 |
| #80 | 02 |
| #87 | RT-4.7〜RT-4.9 で解決する。差分は 02 の 3.1 節 |

## 決定の記録

2026-11-01 に、いずれも (推奨) の案に決めた。推奨のない 6 は決定済み。8 は手順 4 で確かめた結果を書いた。

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
