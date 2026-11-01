# PSX

PSX は、PHP のファイルの中に HTML のようにタグを書ける構文です。Rosanna と PSX の関係は React と JSX の関係と同じで、
`polidog/rosanna` のコンパイラが `.psx` を PHP に変換します。変換後のコードの描画では、コンパイラも `nikic/php-parser` も読み込みません。

この文書は v0.0.1 の PSX を説明します。

コンパイラは本体の `polidog/rosanna` にあります。`fc()`、`useState`、`onClick`、`<defer>` は [`polidog/rosanna-state`](https://github.com/polidog/rosanna-state) のものです。状態のないコンポーネントは `return fn (array $props) => <div />;` で書けます。
```php
<?php
// components/Counter.psx
declare(strict_types=1);

namespace App;

use function Polidog\Rosanna\State\fc;
use function Polidog\Rosanna\State\useState;

return fc(function (array $props) {
    [$count, $setCount] = useState($props['initial'] ?? 0);

    return (
        <div className="counter">
            <span>Count: {$count}</span>
            <button onClick={fn () => $setCount($count + 1)}>+</button>
        </div>
    );
});
```

## 導入

コンパイラは `polidog/rosanna` に入っています。`composer install --no-dev` の後でも、ビルド工程でも本番サーバーの上でもコンパイルできます。

```bash
composer require polidog/rosanna          # 描画だけ
composer require polidog/rosanna-state    # 状態も使う (`polidog/rosanna` も入る)
```

## コンパイル

```bash
vendor/bin/rosanna compile [--check] [--clean] [--watch] [--cache=PATH] [paths...]        # 描画だけ
vendor/bin/rosanna-state compile [--check] [--clean] [--watch] [--cache=PATH] [paths...]  # 状態も使う
```

`RosannaState` を使うアプリは `vendor/bin/rosanna-state compile` を使います。`RosannaState::loadComponentManifest()` は、deferred manifest がないか build ID が合わないと例外を投げます。`vendor/bin/rosanna compile` は残っている deferred manifest を消し、`--check` では古いと報告します。

| オプション | 意味 | 既定 |
|---|---|---|
| `--cache=PATH` | 成果物と manifest の出力先 | `var/cache/psx` |
| `--check` | 何も書き込まず、古いものがあれば exit 1。CI 向け | — |
| `--clean` | 出力先の中身を消す。`--check`、`--watch` より優先し、パスを確かめない | — |
| `--watch` | `.psx` の変更と一覧の変化を 500ms ごとに確かめて再コンパイルする。失敗しても終了せず、次の変更まで待つ | — |

- パスを省略すると `components` を対象にします。ディレクトリは再帰的に走査し、シンボリックリンクは追いません。
- 存在しないパス、未知のオプション (`--chek` など)、`--check` と `--watch` の組み合わせは exit 1 です。
- `.psx` が 1 つもないディレクトリは exit 0 です。
- 変更のないファイルは再コンパイルしません。ただし参照先のコンポーネントが消えていれば検出します。
- エラーは最初の 1 つで止まり、`.psx` のパスを付けて表示します。

### 成果物

ソースツリーには何も書きません。

```
var/cache/psx/
├── <sha1(ソースの絶対パス)>.php        コンパイル結果
├── <sha1(ソースの絶対パス)>.php.meta   ハッシュ、参照、ソースのパス (JSON)
├── manifest.php                        FQCN から成果物を引く表と build ID
└── deferred-manifest.php               defer の表と同じ build ID。`rosanna-state compile` だけが書く
```

成果物と manifest は一時ファイルと `rename` で置き換えるので、実行中のリクエストが書きかけのファイルを読むことはありません。

### 配置の制約

**manifest の `file` と成果物のファイル名は絶対パスに依存します。ビルドした場所と実行する場所のパスを同じにしてください。**

- `manifest.php` の `file` は成果物の絶対パスです。
- 成果物のファイル名は、ソースの絶対パスの SHA-1 です。

たとえばコンテナのビルドでは、実行時と同じパス (`/app` など) でコンパイルしてください。別のパスでビルドした
`var/cache/psx` をコピーすると、`file` の指す先がなくなり、読み込みのときに `RuntimeException` になります。

### コンパイル時に実行されるコード

**`vendor/bin/rosanna-state compile` では、`Defer` という文字列を含むコンポーネントのファイルが、コンパイルのときに `require` されます。そのファイルのトップレベルの
コードが実行されます。** `vendor/bin/rosanna compile` は何も実行しません。

deferred manifest を作るには、返り値の `fc(..., defer: new Defer(...))` を実際に見る必要があるからです。文字列の判定は
コメントや文字列の中でも当たります。したがって、次の点に気を付けてください。

- トップレベルに、データベースへの接続など、ビルド環境で動かない処理や副作用のある処理を書かないでください。
  例外が起きると、コンパイルは exit 1 になります。トップレベルの出力は捨てられます。
- `--watch` では同じファイルを繰り返し読み込みます。トップレベルに `function` を宣言すると再宣言の Fatal error になるので、
  `Defer` を使うファイルではクロージャを使ってください。
- ファイル名が小文字で始まるファイルと、`Defer` の文字列を含まないファイルは実行されません。

`--check` は、最新の成果物だけを読み込みます。成果物が古ければ、読み込まずに exit 1 にします。

## ファイルと名前

ファイルは `.psx` で終わる PHP のファイルで、コンポーネントを `return` します。

- **ファイル名が大文字で始まるファイル**はコンポーネントです。FQCN は `namespace\ファイル名` になり、manifest に載ります。
  ディレクトリは FQCN に使いません。同じ FQCN が 2 つあるとコンパイルエラーです。
- **小文字で始まるファイル** (`page.psx`、`layout.psx`) はコンパイルしますが manifest に載りません。パスで直接読み込むためのもので、
  同名のファイルが複数あっても構いません。

`declare(strict_types=1)` は、書いたとおりに残ります。コンパイラが足したり消したりしません。

### コンポーネントの引数

`return` するのはクロージャ、アロー関数、または `polidog/rosanna-state` の `fc()` でそれを包んだものです。パラメータは次の順に判定され、manifest に記録されます。

| 順 | 条件 | 意味 |
|---|---|---|
| 1 | 最初のパラメータで、型が `array` か型なし | props |
| 2 | クラス型、`?Foo`、`Foo\|null` | サービス。host が `PsxArgumentResolver` で解決する |
| 3 | 既定値がある | 既定値 |
| 4 | null を許す | `null` |
| 5 | 上のどれでもない | コンパイルエラー |

```php
return fc(fn (array $props, UserRepository $users, int $limit = 10, ?string $title = null) => <ul />);
```

コンパイラは DI を知りません。パラメータの情報を書くだけで、解決はランタイムの `PsxArgumentResolver` に host が渡す実装が行います。

## 構文

### タグ

| 書き方 | 意味 |
|---|---|
| `<div>...</div>`、`<my-tag />` | 組み込みタグ。先頭が大文字でないもの |
| `<Counter />` | コンポーネント。先頭が大文字のもの |
| `<>...</>` | Fragment。囲みのタグを出さない。閉じは `</>` だけ |
| `<br />` | 自己終了。子のない要素と同じ |

タグ名に使える文字は英数字、`_`、`-` です。閉じタグは大文字小文字まで一致させます (`</div >` のように名前の後の空白は構いません)。
`var`、`switch`、`use` のような PHP のキーワードと同じ名前も使えます。

タグを書けるのは、PHP が式を期待している位置だけです。

```php
return <a />;                    // return の後
$x = <a />;                      // 代入
f(<a />, [<b />]);               // 引数、配列
$y ? <a /> : <b />;              // 三項演算子
$y ?? <a />;
match ($y) { 1 => <a /> };
fn () => <a />;
```

`1 < 2`、`$a < $b`、文字列、コメント、ヒアドキュメントの中の `<` は PHP のままです。

### 属性

```php
<input type="text" value='x' disabled data-id={$id} />
```

| 書き方 | 値 |
|---|---|
| `a="..."`、`a='...'` | 文字列。`\` に続く引用符と `\` だけを解除し、その後でエンティティをデコードする |
| `a={式}` | 単独の PHP の式。中にタグも書ける。空の `{}`、`{1, 2}` はエラー |
| `a` | `true` |

属性はソースの順に配列へ入り、同名の属性は後の値が勝ちます。`<label for="a">` の `for` は `htmlFor` になります。

属性名とインラインの `on*` の検証、値のエスケープはランタイムが行います。

### 子

```php
<div>
    テキスト &amp; エンティティ
    <b>要素</b>
    {$式}
    {array_map(fn ($x) => <li>{$x}</li>, $xs)}
    {/* コメント。子を作らない */}
    {...$list}
</div>
```

**テキスト**

- 改行を含むテキストは、先頭と末尾の改行を含む空白を捨て、残りの連続する空白を 1 つにまとめます。空白だけなら子を作りません。
- 改行を含まないテキストは、連続する空白を 1 つにまとめ、前後の空白 1 つは残します。
- テキストの中の `'`、`#`、`//`、`}` はただの文字です。`{` と `<` はそのままでは書けないので、`{'{'}`、`&lt;` と書きます。
- エンティティは HTML5 の規則でコンパイル時にデコードされ、出力のときにランタイムがエスケープします。`&nbsp;` と `&#32;` の空白は残ります。

**式**

- 組み込みタグと Fragment の子が `{式}` 1 つだけなら、式の値がそのまま `children` になります。リストを返す `{array_map(...)}` が入れ子になりません。
- コンポーネントの子が 1 つだけなら、配列にせずその値を渡します。`$props['children']` は要素か文字列です。複数なら配列です。
- `{...式}` は配列を展開します。単独なら `children` は常に配列です。ほかの子と並べても構いません。

### `style` と `script`

小文字の `style` と `script` の中身は 1 つの文字列です。`{}` を展開せず、エンティティをデコードせず、空白も変えません。
閉じタグは小文字で書きます (`</SCRIPT>` はエラーです)。

```php
<style>
    a > b { color: red; }
</style>
```

### コンポーネント

属性は props の配列に入り、子は `children` に入ります。

名前は `use` のエイリアス、現在の名前空間、名前そのままの順に解決します。`use function` と `use const` は使いません。
解決先は同じコンパイルの manifest か、`@psx-runtime` の宣言でなければなりません。

実行時に登録するコンポーネントを参照するときは、コメントで宣言します。宣言はファイルをまたいで有効です。

```php
// @psx-runtime App\Registered
# @psx-runtime App\Registered
/* @psx-runtime App\Registered */
```

宣言は PSX の領域の外に書きます。`{/* @psx-runtime App\Registered */}` のように領域の中に書いても読みません。

### `defer`

`<defer>` は、子がなく、`name` があり、属性が `name`、`params`、`fallback`、`localCache`、`reloadable`、`localCacheTtl` の範囲に
収まるときだけ defer のプレースホルダになります。それ以外は普通の要素です。
描画には `polidog/rosanna-state` が要ります。状態層なしで描画すると、それを案内する例外になります。

```php
<defer name="user-header" params={['id' => 1]} fallback={<p>...</p>} localCache={true} localCacheTtl={60} />
```

`localCache`、`reloadable`、`localCacheTtl` に文字列は書けません。`{true}`、`{60}` と書きます。`localCache="true"` はコンパイルエラーです。

### `&&` と `||`

右辺にタグを含む `&&`、`and` は、左が真なら右を、偽なら `false` を返します。右辺にタグを含む `||`、`or` は、左が真なら左を、
偽なら右を返します。

```php
{$show && <b>x</b>}        // $show が真なら <b>x</b>
{$name || <i>名無し</i>}   // $name が真なら $name
```

JSX と違い、左が偽のときに左の値は返しません。`{0 && <b />}` は何も描画しません。右辺にタグを含まない演算子は PHP の意味のままです。

## 対応していない構文

次はコンパイルエラーです。

| 構文 | 例 | 代わりの書き方 |
|---|---|---|
| 属性のスプレッド | `<div {...$a} />` | `createElement('div', $a)` を `{}` に書く |
| 名前空間付きの属性名 | `<svg xlink:href="a" />` | `href` |
| ドットや名前空間付きのタグ | `<Foo.Bar />`、`<Foo\Bar />` | `use` で名前を付ける |
| `<` の後の空白 | `< div />` | `<div />` |
| 式の開始と判定されない位置のタグ | `(string) <a/>`、`$x == <b/>` | 変数に入れるか `{}` の外で組み立てる |

## エラーと行番号

`{}` の中は単独の式か 1 つのスプレッドです。`{1, 2}` や `{...$a, ...$b}` はコンパイルエラーです。エラーの列は文字単位で数え、`^` は全角を幅 2 として揃えます。

コンパイル結果の行は `.psx` の行と一致します。実行時の例外の `getLine()` は `.psx` の行です。

```
PSX parse error at line 9, column 4: Mismatched closing tag: expected </p>, got </q>
    </q>;
       ^
```

```
PSX compile error at line 4: generated PHP is invalid: Syntax error, unexpected ']'
      {1 +}
```

例外のファイル名は成果物の `<sha1>.php` になります。`.psx` のパスで読むには、ランタイムの `StackTraceRewriter::formatException()` を使います。

コンパイラの更新で生成コードの形が変わったときは、`.meta` のハッシュが変わるので再コンパイルされます。
