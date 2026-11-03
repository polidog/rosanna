# Rosanna 実装仕様 03 — コンパイラ (`src/Psx/`)

Status: Draft (2026-11-01、usePHP v0.10.0 `e073bff` / Relayer main 9566b0c で照合)

関連: [計画書](../design/rosanna-split-and-rename.md)、[01-runtime.md](01-runtime.md) (`H`、Renderer、描画スコープ、本体の描画 API)、
[`polidog/rosanna-state` の 01](https://github.com/polidog/rosanna-state/blob/main/docs/spec/01-runtime.md) (Component、hooks、`RosannaState`、publish)、[`polidog/rosanna-state` の 02](https://github.com/polidog/rosanna-state/blob/main/docs/spec/02-state-and-actions.md) (Storage、Snapshot、Action、署名、CSRF、defer、`rosanna.js`)。この文書で「02」は `polidog/rosanna-state` の文書を指す

## 0. この文書の読み方

- 根拠は `usePHP/src/...:行` で示す。usePHP のコードはコピーせず、ここに書いた振る舞いから作り直す。
- 「実測」は usePHP v0.10.0 の `Compiler` と `CompileCommand` を一時スクリプトで動かして確かめた結果である。
- 「usePHP との差分」は意図して変える点で、それ以外は usePHP と同じ振る舞いにする。
- 行番号つきの `tests/Psx/...:行` と `src/Psx/...:行` は usePHP の場所で、Rosanna の同名のパスとは別物である。
- 要件の ID は `CP-` で始まる。テストは `tests/Psx/` に新しく書き、ID で対応を取る。振り直さず、廃止したものは「廃止」と書いて残す。
- ランタイム側の名前は `01-runtime.md` と合わせる必要がある。一覧は §11.3 に置く。

## 1. パッケージ

### 目的

`.psx` を PHP に変換する道具を、同じパッケージの `src/Psx/` に置き、描画の経路から読み込まれないようにする。
2026-11-01 に別パッケージの計画をやめて 1 パッケージにした (計画書の「決定の変更」)。

### 公開 API

| 種類 | 名前 | 利用者 |
|---|---|---|
| クラス | `Polidog\Rosanna\Psx\Compiler` | Relayer の開発時自動コンパイル (`relayer/src/Router/AppRouter.php:1791`) |
| クラス | `Polidog\Rosanna\Psx\CompileCommand` | `bin/rosanna`、Relayer (`relayer/src/Scaffold/CompileCommand.php:91`、`relayer/src/Psx/PsxComponentRegistrar.php:137`) |

この 2 つ以外は `@internal` とし、互換を約束しない。状態に触らない処理 (PSX の始まりを探すトークンの走査、文字列リテラルとテキストの正規化、ソースへの編集の適用と位置のずらし、返すコンポーネントとパラメータの型の読み取り、生成した PHP の構文の検査、`compile` のパスの解決とソースの収集と更新時刻、`.meta` の読み取り、manifest の項目と build ID の組み立て) は
`src-functions/Psx` の名前空間 `Polidog\Rosanna\Internal\Functions\Psx` の関数にある。`@internal` で、公開 API ではない。rosanna シリーズ (polidog/rosanna-*) からは使ってよい。

### 振る舞い

- **CP-1** `composer.json` の `require` は `php: >=8.5`、`ext-mbstring: *`、`nikic/php-parser: ^5.7` とする。
  `ext-mbstring` は CP-106 の列と表示幅に使う。
- **CP-2** 依存の向きは `src/Psx/` からランタイムへの一方向とする。`src/` のうち `src/Psx/` の外のコードは、
  `Polidog\Rosanna\Psx` と `PhpParser` を参照しない。参照してよいのは `bin/rosanna` の `compile` (§9) だけとする。
  コンパイル済みの成果物と manifest があれば、この 2 つのクラスを読み込まずに描画できる。
- **CP-3** PSX の仕様書は `docs/PSX.md` に書く。この文書はその元になるが、利用者向けの説明は含まない。

usePHP との差分: `CompilerInterface`、`PsxParserFactoryInterface`、`PsxPreProcessorInterface` は作らない。差し替えの
利用者は usePHP のテスト (`tests/Psx/CompilerTest.php:383`, `:420`) だけで、Relayer は `new Compiler()` だけを使う。
`NamespaceContext::parse()` (`usePHP/src/Psx/NamespaceContext.php:124`) のような旧 API の互換も作らない。

### テストすべき項目

- `src/Psx/` の外の `src/` から `Polidog\Rosanna\Psx` と `PhpParser` への参照がないことを、テストで固定する。
- コンパイル済みの成果物と manifest で描画した子プロセスに、`PhpParser` と `Polidog\Rosanna\Psx` のクラスが読み込まれていない (CP-2)。

## 2. PSX の構文

### 目的

計画書の決定どおり、PSX の構文は usePHP と同じ使い方にする。この節がその定義である。

### 公開 API

なし。入力は `.psx` のソースで、出力の形は §6 に書く。

### 振る舞い

#### タグの種類

- **CP-10** タグ名に使える文字は英数字、`_`、`-` とする (`usePHP/src/Psx/PsxParser.php:282`)。
- **CP-11** 先頭が大文字ならコンポーネント、それ以外は組み込みタグとする (`PsxParser.php:496`)。
- **CP-12** `<>...</>` は Fragment とする。閉じは `</>` のみで、名前付きの閉じタグはエラーにする (`PsxParser.php:264`)。
- **CP-13** `<x />` は自己終了タグとする。子を持たない要素と同じ出力になる。
- **CP-14** 閉じタグの名前は開きタグと大文字小文字まで一致させる。`</div >` のように名前の後の空白は許す
  (`PsxParser.php:252`)。
- **CP-15** PHP のキーワードと同じ名前 (`var`、`switch`、`use`) もタグ名として受け付ける (`usePHP/src/Psx/PsxPreProcessor.php:127`)。

#### 属性

- **CP-20** 属性名に使える文字は英数字、`_`、`-` とする (`PsxParser.php:296`)。
- **CP-21** 値は `"..."`、`'...'`、`{式}` のいずれかとする。値を省略すると `true` になる (`PsxParser.php:115`)。
  `{式}` は単独の式でなければならない (CP-109)。
- **CP-22** 文字列の値では `\` に続く引用符と `\` だけを解除し、ほかの `\x` はそのまま残す。その後でエンティティを
  デコードする (`PsxParser.php:318`, `:128`)。
- **CP-23** `{}` の中が空か、コメントだけの属性はコンパイルエラーにする (`PsxParser.php:133`)。
- **CP-24** 属性はソースの順に配列へ入れる。同名の属性は PHP の配列のとおり後の値が勝つ (実測)。
- **CP-25** 組み込みタグ `label` の `for` は `htmlFor` として出力する (`PsxParser.php:519`)。
- **CP-26** 属性名の検証とインラインの `on*` の拒否はコンパイラでは行わず、ランタイムに任せる。テストはランタイムの
  結果まで含めて書く (`tests/Psx/CompiledOutputTest.php:127`, `:265`)。

#### 子要素

- **CP-30** 子はテキスト、要素、`{式}` の並びとする。`{式}` の中にも PSX を書ける (`PsxParser.php:634`)。
  `{式}` は単独の式か 1 つのスプレッドでなければならない (CP-109)。
- **CP-31** `{/* コメント */}` のように空白とコメントだけの `{}` は子を作らない (`PsxParser.php:184`)。
- **CP-32** `{式}` の末尾が行コメントのときは、後続の生成コードが飲み込まれないよう改行を補う (`PsxParser.php:639`)。
- **CP-33** `{}` の対応を探すときは、PHP の文字列と `//`、`#`、`/* */` のコメントの中の括弧を数えない。`#[` は
  コメントとして扱わない (`PsxParser.php:357`)。
- **CP-34** 子が `{...式}` の形でも受け付ける。単独の場合は §6 の CP-73 に従う (#119)。

#### テキスト

- **CP-40** 改行を含むテキストでは、先頭と末尾の改行を含む空白を捨て、残りの連続する空白を 1 つの空白にまとめる。
  空白だけなら子を作らない (`PsxParser.php:457`)。
- **CP-41** 改行を含まないテキストでは、連続する空白を 1 つにまとめ、前後の空白 1 つは残す (`PsxParser.php:466`)。
- **CP-42** テキストの `}` はそのまま文字として扱う (実測)。
- **CP-43** テキストの中のシングルクォート、`#`、`//` でコンパイルが壊れないようにする (`tests/Psx/CompilerTest.php:302`)。

#### エンティティ

- **CP-50** テキストと、文字列の属性値のエンティティは、コンパイル時に HTML5 の規則で UTF-8 へデコードする
  (`PsxParser.php:441`)。出力時のエスケープはランタイムが行うので、二重にならない。
- **CP-51** デコードは空白の正規化の後に行う。`&nbsp;` と `&#32;` の空白は残る (`PsxParser.php:245`)。
- **CP-52** エンコードした `javascript:` の URL は、デコードの後もランタイムで遮断される
  (`tests/Psx/CompiledOutputTest.php:127`)。

#### `style` と `script`

- **CP-55** 小文字の `style` と `script` の中身は、1 つの文字列の子として渡す。`{}` は展開せず、エンティティも
  デコードせず、空白も変えない (`PsxParser.php:211`)。
- **CP-56** 中身が空なら子を持たない。自己終了も受け付ける (実測)。
- **CP-57** 終了位置の検索は大文字小文字を区別しないが、閉じタグ自体は CP-14 に従うので `</SCRIPT>` はエラーにする
  (`tests/Psx/CompiledOutputTest.php:537`)。

#### コンポーネント

- **CP-60** 属性は props の配列に入れ、子は予約キー `children` に入れる。名前解決は §4 に従う。

#### `defer`

- **CP-62** `<defer>` は、子がなく、`name` 属性があり、属性が `name`、`params`、`fallback`、`localCache`、`reloadable`、
  `localCacheTtl` の範囲に収まるときだけ、defer のプレースホルダを作る (`PsxParser.php:32`, `:533`)。
- **CP-63** それ以外の `<defer>` は普通の要素として出力する (実測)。
- **CP-64** CP-62 の `<defer>` で、`localCache`、`reloadable`、`localCacheTtl` の値が文字列ならコンパイルエラーにする。`{true}`、`{60}` と書く。

#### 演算子

- **CP-65** 右辺にタグを含む `&&`、`and` は、左が真なら右、偽なら `false` を返す形に書き換える (`usePHP/src/Psx/TagOperandLowering.php:24`)。
- **CP-66** 右辺にタグを含む `||`、`or` は、左が真なら左、偽なら右を返す形に書き換える。
- **CP-67** 右辺にタグを含まない演算子は PHP の意味のままにする。`{0 && <b/>}` は JSX と違い何も出力しない
  (`tests/Psx/CompiledOutputTest.php:414`)。
- **CP-68** 書き換えはソースへの差し込みで行い、AST を印字しない。コメントと行番号をそのまま残すためである
  (`TagOperandLowering.php:31`)。

### 非対応

次はコンパイルエラーにする。いずれも実測で確かめた usePHP の振る舞いと同じで、エラーになることをテストで固定する。

| 構文 | 例 |
|---|---|
| 属性のスプレッド | `<div {...$a} />` |
| 名前空間付きの属性名 | `<svg xlink:href="a" />` |
| ドットと名前空間付きのタグ | `<Foo.Bar />`、`<Foo\Bar />` |
| `<` の後の空白 | `< div />` |
| 式の開始と判定されない位置のタグ | `(string) <a/>`、`$x == <b/>` (§3) |

### エラー

§5 の形式で `RuntimeException` を投げる。

| 場面 | メッセージの内容 |
|---|---|
| 閉じタグの不一致 | `Mismatched closing tag: expected </a>, got </b>` |
| Fragment の閉じの誤り | ``Expected fragment close `</>`, got `</b>` `` |
| 閉じていない `style`、`script` | `Unclosed <style>` |
| 閉じていない文字列、`{}` | `Unterminated attribute string literal`、`Unclosed brace expression` |
| 空の属性式 | `Attribute 'id' has an empty expression` |
| `=` の後に値がない | `Expected attribute value after '=' for 'id'` |

### テストすべき項目

- CP-10〜CP-68 をそれぞれ 1 件以上。生成コードの比較と、ランタイムで描画した HTML の比較の両方を使う。
- 演算子は `usePHP/tests/Psx/CompiledOutputTest.php:367` の組み合わせを同じ範囲で書く (連鎖、括弧、三項、`and`/`or`、ネスト、
  `array_map` の中、演算子の前のコメント、`return` の直後)。
- SVG の `<use>`、`<var>` のようなキーワードと同名のタグ。

## 3. PSX の領域の検出

### 目的

PHP のソースの中で、どの `<` が PSX の開始かを決める。

### 公開 API

内部クラス `PsxPreProcessor`。

### 振る舞い

- **CP-80** `token_get_all` で先頭から走査し、PHP が式を期待している位置の `<` だけをタグの開始候補にする。文字列、
  コメント、ヒアドキュメントの中は対象外になる。
- **CP-81** 候補は、次のトークンが `[A-Za-z_]` で始まるか、`/` か `>` のときに PSX とする。`<>` は式の開始位置なら
  Fragment とする (`PsxPreProcessor.php:98`, `:121`)。
- **CP-82** 式の開始になるトークンは usePHP と同じにする (`PsxPreProcessor.php:137`, `:172`)。
  - トークン: `return`、`echo`、`print`、`=>`、`->`、`?->`、開始タグ、`fn`、`&&`、`||`、`and`、`or`、`xor`、`??`、`??=`、`yield`、`yield from`
  - 文字: `=`、`,`、`(`、`[`、`{`、`;`、`?`、`:`、`!`、`&`、`|`、`^`、`+`、`-`、`*`、`/`、`%`、`.`
  - 空白とコメントでは状態を変えない。`)`、`]`、`}` とその他のトークンでは偽にする。
- **CP-83** 最初の領域を見つけたら PSX パーサで終端を決め、プレースホルダ関数の呼び出しに置き換えて、残りを
  トークン化し直す。PSX のテキストがトークナイザーを壊すので、最初の領域より前のトークンしか信用しない
  (`PsxPreProcessor.php:16`)。
- **CP-84** プレースホルダは `\Polidog\Rosanna\Psx\Internal\__psx_region_N__()` とする。

### エラー

検出の失敗はそれ自体エラーにしない。タグと判定されなかった `<` は PHP のコードとして残り、CP-105 の検証で
エラーになる (`tests/Psx/CompiledOutputTest.php:434`)。

### テストすべき項目

- `$a < $b`、`1 < 2`、`<<<EOT`、`"<div>"`、`// <div>` が PSX にならない。
- `return`、`=`、関数の引数、配列、`?:`、`??`、`match` の `=>`、`fn() =>` の後のタグが PSX になる。
- 奇数個のアポストロフィを含むテキストが、同じファイルの別の領域を壊さない。
- `style`、`script` の中身で素の PHP として壊れるソースでも名前解決ができる (`tests/Psx/CompileCommandTest.php:587`)。

## 4. 名前解決

### 目的

コンポーネントのタグと、パラメータの型を、ファイルの `namespace` と `use` で FQCN にする。

### 公開 API

内部クラス `NamespaceContext`。

### 振る舞い

- **CP-90** コンポーネントのタグは、`use` のエイリアス、現在の名前空間、名前そのままの順に解決する
  (`usePHP/src/Psx/NamespaceContext.php:160`)。
- **CP-91** `use function` と `use const` は解決に使わない (`NamespaceContext.php:74`)。
- **CP-92** プレースホルダへ置き換えた後のソースを php-parser で解析し、名前空間と `use` を取り出す
  (`usePHP/src/Psx/CompileCommand.php:597`)。
- **CP-93** タグの解決結果は参照として記録する。`{}` の中のネストしたタグも同じ記録に入れる。型の解決は記録しない
  (`NamespaceContext.php:177`)。
- **CP-94** `// @psx-runtime FQCN`、`# @psx-runtime FQCN`、`/* @psx-runtime FQCN */` を、実行時に登録されるコンポーネントの
  宣言として集める。先頭の `\` は除く (`NamespaceContext.php:100`)。PSX の領域の外に書いたものだけが有効である。領域の中の `{/* @psx-runtime X */}` は読まない。

### エラー

- `namespace` の宣言を取り出せないのに、ソースに `namespace` のキーワードがあるときはエラーにする
  (`NamespaceContext.php:89`)。誤った FQCN で登録しないためである。
- 未解決の参照は §7 の `CompileCommand` がエラーにする。`Compiler` 単体では判定しない。

### テストすべき項目

名前空間のみ、`use`、エイリアス、名前空間なし、`use function` の無視、`@psx-runtime` の 3 形式、完全修飾の型名。

## 5. Compiler

### 目的

1 つの `.psx` のソースを、行番号の揃った正しい PHP に変換する。

### 公開 API

```php
namespace Polidog\Rosanna\Psx;

final class Compiler
{
    /** @throws \RuntimeException 構文エラーか、生成した PHP が不正なとき */
    public function compile(string $source): string;

    /** @return list<string> 直前の compile() で解決したコンポーネントの FQCN */
    public function getLastReferences(): array;
}
```

### 振る舞い

- **CP-100** 処理は領域の検出、名前解決、演算子の書き換え、各領域の変換、PHP としての検証の順に行う
  (`usePHP/src/Psx/Compiler.php:13`)。PSX の外の PHP はそのまま写す。領域の差し込みは検出した位置で行う。プレースホルダと同じ文字列を含む文字列、コメント、関数呼び出しは PHP のまま残す。
- **CP-101** 出力の各行は、ソースの同じ行のコードに対応する。領域の中でも、タグと `{式}` の行を保つ
  (`usePHP/src/Psx/PsxParser.php:227`、`Compiler.php:148`)。領域が短くなった分は改行で埋める。
- **CP-102** 生成コードの改行数がソースより多くなることはない。これはコンパイラの不変条件で、`assert` で確かめる。
- **CP-103** PSX 領域のないソースはそのまま返す。ただし CP-105 の検証は行う。
- **CP-104** `declare(strict_types=1)` はソースのまま残し、コンパイラが足したり消したりしない。
- **CP-105** 出力を php-parser で解析し、不正ならコンパイルエラーにする (`Compiler.php:115`)。

### エラー

- **CP-106** 構文エラーの形式は次のとおりとする (`PsxParser.php:470`)。

  ```
  PSX parse error at line {行}, column {列}: {内容}
      {ソースの行}
      {列の位置を指す ^}
  ```

  列は文字単位で数える。`^` は `mb_strwidth` の表示幅に合わせる。全角は幅 2 で表示されるため。タブは 1 文字として数える。

- **CP-107** 検証エラーの形式は `PSX compile error at line {行}: generated PHP is invalid: {内容}` に続けてソースの行を
  付ける。元の例外を `previous` に渡す (`Compiler.php:122`)。
- **CP-108** 行番号と表示する行は、常に `.psx` の実際の行とする。
- **CP-109** CP-105 の後に、各 `{式}` の中身を単独で解析する。`<?php (…);` が 1 文にならないもの、`<?php […];` が 1 要素の配列 1 つにならないものは、`PSX compile error at line {`{式}` の先頭の行}: {...} must hold a single expression` にソースの行を付けてコンパイルエラーにする。スプレッドは `[…]` だけで確かめる。`{1, 2}` や `{1]); print(1); f([2}` は生成コードの中では正しい PHP になるので、CP-105 だけでは止まらない。

usePHP との差分 (CP-108): usePHP は領域の置き換えで行が詰まるので、2 つ目以降の領域の行番号がずれる。`{}` の中の
ネストしたタグでは、外側の領域からの相対の行になる。実測では、実際は 9 行目の不一致が `line 7`、5 行目のネストの
不一致が `line 2` と出る。

### テストすべき項目

- 複数行のマークアップ、`{/* */}`、`style`、演算子の書き換えの後で行番号が保たれる。PSX の中で実行時エラーを起こし、
  `Throwable::getLine()` がソースの行と一致することを確かめる。
- CP-108: 2 つ目の領域と、`{array_map(fn () => <p>...)}` の中の構文エラーで、行、列、表示する行が正しい。
- CP-105: `{1 +}`、`id={1 +}`、`{if ($x) { 1; }}`、PSX の外の不正な PHP。
- `getLastReferences()` にネストしたタグが入る。呼び出しごとにリセットされる。

## 6. 生成コード

### 目的

ランタイムとコンパイラの本当の境界である生成コードの形を決める。ここを変えると再コンパイルが必要になる (計画書)。

### 公開 API

生成コードが呼ぶランタイム側の名前は §11.1 に置く。

### 振る舞い

| PSX | 生成コード |
|---|---|
| `<div className="a">x</div>` | `\Polidog\Rosanna\Html\createElement('div', ['className' => 'a', 'children' => ['x']])` |
| `<br />` | `\Polidog\Rosanna\Html\createElement('br', [])` |
| `<>x</>` | `\Polidog\Rosanna\Html\createElement('Fragment', ['children' => ['x']])` |
| `<Counter initial={5} />` | `\Polidog\Rosanna\Runtime\RenderContext::scope()->psx->render('App\\Counter', ['initial' => 5])` |
| `<defer name="a" />` | `\Polidog\Rosanna\Html\H::defer(name: 'a')` |
| `{$x && <b/>}` | `(($x) ? (...) : false)` |
| `{$x \|\| <b/>}` | `(($x) ?: (...))` |

- **CP-70** 組み込みタグは `H::xxx()` を経由せず、`createElement()` を完全修飾で呼ぶ (`usePHP/src/Psx/PsxParser.php:13`)。
  利用者のファイルに `use` を要求しない。
- **CP-71** 組み込みタグの子は常に配列にする。ただし子が `{式}` 1 つだけのときは、式の値をそのまま `children` に渡す。
  `{array_map(...)}` が子のリストを返せるようにするためである (`PsxParser.php:602`)。
- **CP-72** コンポーネントの子が 1 つだけのときは配列にせず、その値をそのまま `children` に渡す。コンポーネントは
  要素か文字列を受け取る (`PsxParser.php:616`)。
- **CP-73** 子が `{...式}` 1 つだけのときは、組み込みタグ、Fragment、コンポーネントのどれでも `'children' => [...式]`
  にする (#119)。判定はトークンで行い、`...` の前の空白とコメントは無視する。
- **CP-74** コンポーネントの呼び出しに `getApp()` を使わない。分割設計の形 (`scope()->psx->render()`) で呼ぶ。
- **CP-75** タグ名、属性名、FQCN は CP-10、CP-20 の文字だけなので、PHP の文字列へそのまま埋め込める。文字列の値は
  `'` と `\` をエスケープする (`PsxParser.php:446`)。

### `strict_types` (#118)

- **CP-76** `declare(strict_types=1)` の PSX で、組み込みタグの `{0}`、`{42}`、`width="100"`、`step="0.5"`、
  `disabled="disabled"` が `TypeError` にならない。`createElement()` の引数は `array` なので、値の型に制約がない。
  usePHP の現在の実装で解決済みであり、この形を維持する (実測、`tests/Psx/CompiledOutputTest.php:135`)。
- **CP-77** #118 が報告した `H::p(children: 42)` の型は、手書きの `H` の問題として `01-runtime.md` で扱う。
- **CP-78** `<defer>` は名前付き引数で `H::defer()` を呼ぶので、型のある引数に文字列を渡すと `strict_types` の下で
  `TypeError` になる。`<defer name="a" localCache="true" />` で実測した。CP-64 でコンパイル時に止める。

### エラー

生成コードの不正は CP-105 で検出する。

usePHP との差分: CP-73 は usePHP ではコンパイルエラーになる (実測、`generated PHP is invalid: Syntax error, unexpected
T_ELLIPSIS`)。複数の子なら `['a', ...$xs]` として通るので、単独でも同じ意味にする。#119 の修正案のうち、配列として
受け付ける案を採る。`relayer-doc/var/i18n-ja/page-assets.md:32` が `<div>{...$this->getChildren()}</div>` を案内して
いることも理由である。

### テストすべき項目

- 表の各行の生成コード。`createElement()` の結果が、同じ意味の `H::xxx()` の結果と一致する
  (`tests/Psx/CompiledOutputTest.php:235` の範囲)。
- CP-71: `{array_map(...)}` の結果が入れ子にならない。テキストだけの子は配列になる。
- CP-72: コンポーネントが 1 つの子を配列でなく受け取る。
- CP-73: 組み込みタグ、Fragment、コンポーネントの 3 種類で描画まで確かめる。コメントつきの `{/* c */ ...$x}` も通す。
- CP-74: 生成コードに `getApp` の文字列が現れない。
- CP-76: `strict_types` つきのファイルで usePHP のテストと同じ値が描画できる。

## 7. CompileCommand

### 目的

ディレクトリの `.psx` をまとめてコンパイルし、manifest を作る。

### 公開 API

```php
namespace Polidog\Rosanna\Psx;

final class CompileCommand
{
    public const DEFAULT_CACHE_SUBDIR = 'var/cache/psx';
    public const CACHE_VERSION = 3;

    /** @param list<string> $argv `compile` の後の引数。オプションとパスの順は問わない */
    public function run(array $argv, string $cwd): int;

    public static function cachePathFor(string $cacheDir, string $sourcePath): string;
    public static function metaPathFor(string $cacheDir, string $sourcePath): string;
}
```

| オプション | 意味 | 既定 |
|---|---|---|
| `--cache=PATH` | 成果物と manifest の出力先 | `<cwd>/var/cache/psx` |
| `--check` | 書き込まず、古いものがあれば exit 1 | — |
| `--clean` | 出力先の中身を消す | — |
| `--watch` | 変更を 500ms ごとに確かめて再コンパイルする | — |

未知のオプションは exit 1 にする。`--chek` のような誤りで CI の検査が素通りするのを防ぐ。

### 振る舞い

入力:

- **CP-110** パスを省略すると `<cwd>/components` を対象にする (`usePHP/src/Psx/CompileCommand.php:61`)。
- **CP-111** 存在しないパスは出力先を作る前に exit 1 にし、パスを表示する。`--check` が誤ったパスで成功するのを
  防ぐためである (`CompileCommand.php:69`)。`--clean` では確かめない。
- **CP-112** `.psx` のないディレクトリは exit 0 で、その旨を表示する。
- **CP-113** ディレクトリは再帰的に走査し、シンボリックリンクは追わない。ファイルは `.psx` で終わるものだけを対象に
  する。結果はソートし、同じファイルの重複を除く。ディレクトリとその中のファイルを同時に渡しても重複 FQCN のエラーにしない (`CompileCommand.php:465`)。

出力先:

- **CP-114** 成果物は `<cache>/<sha1(ソースの絶対パス)>.php` とする。ソースツリーには書かない。
  `realpath` できないときは渡されたパスのまま計算する (`CompileCommand.php:95`)。
- **CP-115** 付随ファイルは `<成果物>.meta` とし、JSON で `hash`、`refs`、`source` (ソースの絶対パス) を持つ (`CompileCommand.php:115`)。`source` は §10 が読む。
- **CP-116** `hash` は `CACHE_VERSION` と NUL とソースをつないだ SHA-256 とする (`CompileCommand.php:122`)。

manifest の対象:

- **CP-117** ファイル名の先頭が大文字の `.psx` だけを manifest に載せる。FQCN は `namespace\ファイル名` とする。
  ディレクトリは FQCN に使わない (`CompileCommand.php:616`, `:620`)。
- **CP-118** 先頭が小文字のファイル (`page.psx`、`layout.psx`) はコンパイルするが manifest に載せない。パスで直接
  読み込まれるので、同名のファイルが複数あってもエラーにしない。

コンパイルと検査:

- **CP-119** 同じ FQCN が 2 つあれば exit 1 にし、両方のパスを表示する。
- **CP-120** 各ファイルの参照は、manifest の FQCN か `@psx-runtime` の宣言に解決できなければ exit 1 にする。
  `@psx-runtime` はファイルをまたいで有効とする (`CompileCommand.php:603`, `:637`)。
- **CP-121** `hash` が一致し、成果物があり、保存した `refs` がすべて解決できるなら、再コンパイルしない。ほかの
  ファイルの削除で参照が切れた場合を検出するため、`refs` の確認は省かない (`CompileCommand.php:646`)。
- **CP-122** 成果物と manifest は、内容が変わるときだけ書く (`CompileCommand.php:677`, `:706`)。書き込みは同じディレクトリの一時ファイルと `rename` で行い、別のリクエストが書きかけの PHP を読まないようにする。
- **CP-123** `--check` はファイルを書かない。成果物、manifest、deferred manifest のいずれかが古ければ、一覧を
  表示して exit 1 にする (`CompileCommand.php:748`)。
- **CP-124** `--clean` は `--check`、`--watch` より優先し、出力先の中身を消す。出力先がなければ exit 0 にする。
  パスを確かめない (`CompileCommand.php:65`)。
- **CP-125** `--watch` は `.psx` の mtime と一覧の変化を 500ms ごとに確かめる。コンパイルに失敗しても終了しない
  (`CompileCommand.php:526`)。失敗しても mtime の記録を更新し、次の変更まで同じエラーを繰り返さない。
- **CP-126** メッセージは標準出力に書く。Relayer は `ob_start()` で捕まえている
  (`relayer/src/Scaffold/CompileCommand.php:88`)。

usePHP との差分: `CACHE_VERSION` は 3 から始める。usePHP の現在の値は 2 で、同じ値を使うと、ソースが同じファイルで
usePHP の時の `.meta` と一致し、古い生成コードが残る。1 も usePHP の過去の値なので使わない (`9009fa4`、`a477600`)。

### エラー

CP-111、CP-119、CP-120、構文エラー、出力先や成果物の書き込みの失敗はすべて exit 1 とし、例外を外へ投げない。
コンパイルエラーのメッセージにはソースのパスを付ける (`CompileCommand.php:664`)。最初のエラーで止める。

### テストすべき項目

`usePHP/tests/Psx/CompileCommandTest.php:29` からの一覧と同じ範囲を新しく書く。

- 成果物、`.meta`、manifest の生成。`--cache`、`--check`、`--clean`。
- 未解決の参照、`@psx-runtime`、重複 FQCN、大文字と小文字のファイルの混在。
- 2 回目のキャッシュ、ソース変更、`CACHE_VERSION` の変更、参照先の削除の検出。
- 存在しないパスの exit 1 (既定の `components` と、ほかのパスが正しい場合を含む)。`--clean` では無視する。
- `Compiler` のエラーにファイル名が付く。`cachePathFor()` の結果が安定している。

## 8. manifest と deferred manifest

### 目的

ランタイムが、コンパイラを読み込まずにコンポーネントを読み込めるようにする。

### 公開 API

生成物の形式がランタイムとの契約である。manifest の読み込みは `01-runtime.md` の `PsxRenderer` と `loadComponentManifest()` が行い
(RT-11.2、RT-11.5)、deferred manifest の読み込み、build ID の照合、defer の登録は `polidog/rosanna-state` の `RosannaState::loadComponentManifest()` が行う ([02](https://github.com/polidog/rosanna-state/blob/main/docs/spec/02-state-and-actions.md) の DF-13)。

```php
// <cache>/manifest.php
return [
    '#build-id' => '<sha256>',
    'App\\Counter' => [
        'file' => '/abs/var/cache/psx/<sha1>.php',
        'parameters' => [
            ['kind' => 'props', 'name' => 'props'],
            ['kind' => 'service', 'name' => 'repo', 'service' => 'App\\Repo'],
            ['kind' => 'default', 'name' => 'n', 'value' => '1'],
            ['kind' => 'null', 'name' => 'x'],
        ],
    ],
];

// <cache>/deferred-manifest.php (`rosanna-state compile` だけが書く)
return [
    '#build-id' => '<sha256>',
    'user-header' => ['component' => 'App\\UserHeader', 'cacheControl' => 'private, no-store'],
];
```

### 振る舞い

- **CP-130** どちらも FQCN または名前でソートし、1 行目の次に自動生成であることを示すコメントを書く
  (`usePHP/src/Psx/CompileCommand.php:202`, `:374`)。
- **CP-131** `file` は絶対パスとする。実行時とビルド時で配置が同じことを前提にする。この制約を PSX の仕様書に書く。
- **CP-132** `parameters` は、コンパイル済みのファイルのトップレベルの `return` から求める。`fc()` か `deferred()` で包まれていれば
  `component` という名前の引数か、なければ位置の第 1 引数のクロージャを見る。関数名は `use function` の別名を解決し、最後の部分を大文字小文字を区別せずに比べる。
  包まれた引数がクロージャでもアロー関数でもなければエラーにする。包まれていない `return` がクロージャでもアロー関数でもなければ空にする (`CompileCommand.php:219`)。
- **CP-133** 各パラメータは次の順に判定する (`CompileCommand.php:252`)。

  | 順 | 条件 | `kind` |
  |---|---|---|
  | 1 | 最初のパラメータで型が `array` か型なし | `props` |
  | 2 | クラス型か、`?Foo`、`Foo\|null` | `service` (`service` に解決した FQCN) |
  | 3 | 既定値がある | `default` (`value` に PHP の式の文字列) |
  | 4 | null を許す | `null` |
  | 5 | 上のどれでもない | エラー |

- **CP-134** コンパイラは DI を知らない。`parameters` はメタデータとして書くだけで、引数の解決はランタイムの
  `PsxArgumentResolver` に host が渡す実装が行う。Relayer はこれでコンテナからサービスを引く。
- **CP-135** deferred manifest を書くのは `vendor/bin/rosanna-defer compile` と `vendor/bin/rosanna-state compile` で、`polidog/rosanna-defer` の `DeferredManifestCompiler` が行う。本体の `CompileCommand` は `FunctionComponent` を知らず、manifest の後に動く `\Closure` を受けるだけである (`new CompileCommand($afterManifest)`。インターフェースは作らない。`--check` と `--watch` も同じ経路を通る)。
  クロージャは manifest に載るファイルのうち成果物に `Defer` の文字列を含むものだけを `require` し、返り値が `FunctionComponent` で `defer` を持つものを集める (`CompileCommand.php:399`)。コンパイル時にそのファイルの
  トップレベルのコードが実行されることを、仕様書に明記する。本体の `rosanna compile` は何も `require` しない。`--check` で成果物が古ければ、クロージャを呼ばず読み込まない。
- **CP-136** `rosanna-state compile` は、項目が空でも deferred manifest を必ず書き、manifest と同じ build ID を持たせる (CP-144)。クロージャを渡さない `rosanna compile` は defer を調べないので、残っている deferred manifest は古い。消す (`--check` では消さずに古いと報告して exit 1)。
  `RosannaState::loadComponentManifest()` は、deferred manifest がない、または build ID が `manifest.php` と合わないとき、`rosanna-state compile` を案内する `RuntimeException` にする。ファイルの有無だけを見ると、`rosanna-state compile` の後に `rosanna compile` を実行したときに、新しい manifest と古い deferred manifest の組み合わせを受け入れてしまう (`polidog/rosanna-state` の DF-13)。
- **CP-137** ファイル名の定数 `MANIFEST_FILENAME` (`manifest.php`) と `DEFERRED_MANIFEST_FILENAME` (`deferred-manifest.php`) は
  ランタイム側の `Polidog\Rosanna\Runtime\PsxRenderer` に置き、`CompileCommand` はそれを参照する。
- **CP-144** `compile` は manifest を書くたびに、build ID を `manifest.php` のキー `#build-id` (`PsxRenderer::BUILD_ID_KEY`) に書く。値は manifest の項目と、ソースのパスと成果物の組の SHA-256 で、成果物のハッシュから決まる。同じ入力なら同じ値になり、`writeIfChanged` の判定を壊さない。時刻や乱数を入れない。
  `PsxRenderer::loadManifest()` はこのキーを項目として読まない。`PsxRenderer::readBuildId()` が読む。`#` で始まるので FQCN と衝突しない。

usePHP との差分:

- 文字列だけの項目 (`usePHP/src/UsePHP.php:264`) は作らない。移行では再コンパイルが必須なので、旧形式を読む必要がない。
- CP-137 で `MANIFEST_FILENAME` もランタイムに移す。計画書の表は `DEFERRED_MANIFEST_FILENAME` だけを挙げているが、利用者が
  本番でコンパイラを読み込まずに manifest のパスを組み立てるには、こちらも要る (`usePHP/docs/PSX.md:535`)。

### エラー

- CP-133 の 5: exit 1。メッセージは FQCN、パラメータ名、使える形を示す (`CompileCommand.php:290`)。型のない
  `fn($props)` は usePHP ではここに入るが (実測、`CompileCommandTest.php:446`)、Rosanna では `props` とする。Relayer のリフレクションの解釈と揃えるため。
- CP-132 の読めない引数: exit 1。メッセージは FQCN、書かれた関数名、使える形を示す。
- 成果物の `require` が例外を投げたら exit 1 にし、ファイルと内容を表示する。
- `defer` の名前の重複は exit 1 にし、両方の FQCN を表示する。

### テストすべき項目

- `parameters` の 4 種類と `Foo|null`、`fc()` で包んだ場合、包まない場合。CP-133 の 5 のエラー。
- `fc()` と `deferred()` の別名 (グループの `use` と大文字小文字を含む)。変数などの読めない引数のエラー。
- `#build-id` が同じ入力で同じ値になり、ソースが変わると変わること。`loadManifest()` が項目として読まないこと。
- `rosanna compile` が残っている deferred manifest を消し、`--check` が古いと報告すること。`rosanna-state compile` 側の項目が空でも書くこと、名前の重複は [`polidog/rosanna-state`](https://github.com/polidog/rosanna-state/blob/main/docs/spec/README.md) のテストで固定する。
- 同じキャッシュディレクトリで 2 つのコマンドを交互に実行する場合を、通常実行、`--check`、`--watch` で確かめる (`polidog/rosanna-state` のテスト)。
- `Defer` の文字列を含まないファイルが `require` されない (トップレベルで例外を投げるファイルで確かめる)。
- ランタイムの読み込みとの結合テスト: コンパイルから描画まで。`PsxArgumentResolver` の実装を渡して `service` の
  引数が解決され、`fc()` の内側にも効く。

## 9. `bin/rosanna compile`

### 目的

1 つのコマンドからコンパイラを呼ぶ。`bin/rosanna` 本体は `01-runtime.md` の範囲である。`bin/rosanna-state` の `compile` も同じ `CompileCommand` を、CP-135 のクロージャを渡して呼ぶ ([`polidog/rosanna-state` の 01](https://github.com/polidog/rosanna-state/blob/main/docs/spec/01-runtime.md) の RT-13.7)。

### 公開 API

```
vendor/bin/rosanna compile [--check] [--clean] [--watch] [--cache=PATH] [paths...]
```

### 振る舞い

- **CP-140** `compile` は `CompileCommand` の `run(残りの引数, getcwd())` を直接呼び、その返り値で終了する
  (`usePHP/bin/usephp:29`)。
- **CP-141** 廃止 (2026-11-01)。1 パッケージになり、`class_exists()` でクラスの存在を確かめる必要がなくなった。
- **CP-142** `bin/rosanna help` に `compile` のオプションを載せる (`tests/Psx/CompileCommandTest.php:573`)。

### エラー

- **CP-143** 廃止 (2026-11-01)。コンパイラは常に入っているので、案内しない。

### テストすべき項目

- 引数と exit code が渡る。`--check` の失敗で exit 1 になる。
- 依存として入った配置 (`vendor/polidog/rosanna/bin/rosanna`) から `compile` が動く。ヘルプが実装のオプションと一致する。

## 10. スタックトレースの書き換え

### 目的

コンパイル済みのファイルで起きた例外を、`.psx` のパスで読めるようにする。

### 公開 API

計画書の決定で `StackTraceRewriter` はランタイム側に置く。`installPsxErrorHandler` は分割設計で削除する。

```php
namespace Polidog\Rosanna\Runtime; // 名前は 01-runtime.md と合わせる

final class StackTraceRewriter
{
    public static function rewriteFile(string $file): string;
    public static function rewriteFrames(array $frames): array;
    public static function formatException(\Throwable $e): string;
}
```

### 振る舞い

- **CP-150** `formatException()` は先頭の `クラス: メッセージ at ファイル:行`、スタックの各行、`Caused by:` で続く
  `previous` を出力する (`usePHP/src/Psx/StackTraceRewriter.php:59`)。
- **CP-151** 行番号は変換しない。CP-101 により `.psx` の行と一致する。

### エラー

書き換えは例外を投げない。対応が分からないパスはそのまま返す。

### 振る舞い (追加)

- **CP-152** `rewriteFile()` は同じディレクトリの `<成果物>.meta` の `source` (CP-115) から元のパスを引く。成果物のファイル名だけでは
  `.psx` を指せず、行を足すと CP-101 が崩れるので、付随ファイルを使う。

usePHP の `StackTraceRewriter` は `.psx.php` で終わるパスだけを書き換える (`StackTraceRewriter.php:28`)。一方、成果物は
`.psx.php` ではなく `<sha1>.php` になったので (CP-114、2026-05-09 の変更)、実際のコンパイル結果には効いていない。
テストは手で作った `.psx.php` のファイルで通している (`usePHP/tests/Psx/RuntimeIntegrationTest.php:512`)。

### テストすべき項目

`CompileCommand` で実際に作った成果物で例外を起こし、`.psx` のパスと行が出ることを確かめる。

## 11. ランタイムとの境界

### 11.1 生成コードとコンパイラが使うランタイムの名前

| 名前 | 用途 | usePHP の場所 |
|---|---|---|
| `Polidog\Rosanna\Html\createElement(string $type, array $params): Element` | 組み込みタグと Fragment | `src/Html/Elements.php:1393` |
| `Polidog\Rosanna\Html\H::defer(...)` | `<defer>`。引数名は CP-62 の 6 つ | `src/Html/Elements.php:1326` |
| `Polidog\Rosanna\Runtime\RenderContext::scope()->psx->render(string $fqcn, array $props)` | コンポーネントタグ | `src/Psx/PsxParser.php:577` |
| `Polidog\Rosanna\Defer\DeclaresDefer` | deferred manifest の収集。`polidog/rosanna-defer` の `DeferredManifestCompiler` が見る。コンパイラは参照しない | `src/Psx/CompileCommand.php:434` |
| `Polidog\Rosanna\Defer\Defer` の `name`、`cacheControl` | 同上 | `src/Psx/CompileCommand.php:437` |
| `fc` の関数名 | `parameters` の収集 (CP-132)。名前空間によらず関数名で判定するので、`State\fc` のままで変更がない | `src/Psx/CompileCommand.php:238` |
| `MANIFEST_FILENAME`、`DEFERRED_MANIFEST_FILENAME` | CP-137 | `src/Psx/CompileCommand.php:38` |
| `BUILD_ID_KEY` | CP-144 | なし |

### 11.2 ランタイムに任せるもの

- `Fragment` の展開、`children` の平坦化、描画できない子の拒否。
- `createElement()` のイベント props の変換 (状態層なしでは拒否。01 の RT-2.6) と、`onFocus` などの拒否。
- `className`、`htmlFor` の属性名の変換、属性名とタグ名の検証、エスケープ。
- `.psx` のファイルが返す callable の遅延読み込みと呼び出し、`fc()` の hook のライフサイクル (状態層)。コンパイラは `return` の
  右辺を解釈しない。

### 11.3 `01-runtime.md`、`polidog/rosanna-state` の文書と合わせる名前

| 名前 | この文書での前提 | 相手 |
|---|---|---|
| `RenderContext::scope()->psx->render()` | 分割設計の形。`PsxRenderer` | 01 |
| `PsxRenderer`、`loadComponentManifest()`、manifest の読み込み | §8 の形式を読む | 01 |
| `PsxArgumentResolver` | `src/Psx/` の外に置く | 01 |
| `FunctionComponent`、`fc` | `Polidog\Rosanna\State\`。`.psx` の `use` をそのまま写すので、状態付きの `.psx` は再コンパイルが要る | `polidog/rosanna-state` の 01 |
| `H::defer()`、`createElement()` | `Polidog\Rosanna\Html` | 01 |
| `PsxRenderer::MANIFEST_FILENAME`、`DEFERRED_MANIFEST_FILENAME` | ランタイム側 | 01 |
| `StackTraceRewriter` | ランタイム側。§10 | 01 |
| `Defer` (`name`、`cacheControl`)、`registerDeferred()` | deferred manifest を読む | `polidog/rosanna-state` の 01、02 |
| `bin/rosanna` | `compile` で `CompileCommand` を呼ぶ | 01 |

`PsxArgumentResolver` は分割設計では `Polidog\UsePhp\Psx` にあるが (`usephp-decomposition.md:125`)、コンパイラを読み込まずに描画
するには、`src/Psx/` の外になければならない。`Polidog\Rosanna\Psx` はコンパイラの名前空間なので、別の名前空間に
置く。

### 11.4 Relayer が使う API

追従のときに失わないよう挙げる。

| 使い方 | 場所 |
|---|---|
| `new Compiler()` と `compile($source)` で 1 ファイルをその場でコンパイルする | `relayer/src/Router/AppRouter.php:1791` |
| `CompileCommand::cachePathFor()` で小文字のファイルの成果物を探す | `relayer/src/Router/AppRouter.php:2036` |
| `CompileCommand::CACHE_VERSION` と `.version` の付随ファイルで古いキャッシュを検出する | `relayer/src/Psx/PsxComponentRegistrar.php:84`、`AppRouter.php:1787` |
| `MANIFEST_FILENAME`、`DEFERRED_MANIFEST_FILENAME` | `relayer/src/Psx/PsxComponentRegistrar.php:49`, `:95` |
| `run(['--cache=...', ...$paths], $root)` と出力の捕捉 | `relayer/src/Scaffold/CompileCommand.php:91` |

`PsxComponentRegistrar` が manifest の読み込み後に全コンポーネントを再登録して DI を差し込んでいる処理は、`PsxArgumentResolver` の
実装に置き換える (分割設計の決定 10)。現在の実装は `FunctionComponent` を作り直すときに `persist` を渡していない
(`relayer/src/Psx/PsxComponentRegistrar.php:249`)。作り直しでは `persist` を含めて保たれることを Relayer 側のテストで確かめる。

## 12. エディタの構文ハイライト

### 目的

`.psx` の編集環境を usePHP から引き継ぐ。

### 公開 API

なし。

### 振る舞い

usePHP の `editors/` は VS Code 用の TextMate 文法 (`editors/vscode/`) と、Vim/Neovim 用の構文定義 (`editors/nvim/`) を持つ。
どちらも PSX の構文だけを扱い、PHP の名前空間には依存しない。`usePHP` の名前が出るのは説明文、`package.json` の
`displayName`、`keywords`、`repository`、インストール手順のリポジトリ名だけである。

- **CP-160** リポジトリのルートに `editors/vscode/` と `editors/nvim/` を置く。`src/Psx/` には置かない。
  Neovim の手順がプラグインマネージャーでリポジトリを指定するので、`polidog/rosanna` のルートにあるほうが案内が単純になる。
- **CP-161** 計画書の方針どおりファイルをコピーせず、新しく書く。対象は §2 の構文で、タグ、コンポーネント、属性、
  `{}` の中の PHP、Fragment を色分けする。`style`、`script` の中は PHP の式として扱わない。
- **CP-162** 配布物から外すため、`.gitattributes` に `/editors export-ignore` を追加する。usePHP では外れていない
  (`usePHP/.gitattributes`)。
- **CP-163** VS Code の拡張は Marketplace に公開しない。usePHP と同じくローカルインストールの手順だけを書く。

作業の順番では手順 3 の後に行う。v0.0.1 の公開に必須とはせず、手順 4 の照合で状態を記録する。

### エラー

なし。

### テストすべき項目

自動テストは書かない。§2 の構文を網羅したサンプルの `.psx` を `editors/` に置き、手で確かめる。

## 決定の記録

2026-11-01 に、いずれも (推奨) の案に決めた。本文は決定に合わせてある。

1. **`<defer>` の文字列の属性 (CP-78)。** `<defer name="a" localCache="true" />` は `strict_types` の下で `TypeError` になる。
   - A. コンパイル時に、`localCache`、`reloadable`、`localCacheTtl` の文字列の値をエラーにする (推奨)。`{true}`、`{60}` と書けば
     よく、実行時より早く分かる。
   - B. `createElement()` と同じく、`H` を通らない経路にして型を問わない。
   - C. usePHP のまま実行時の `TypeError` にする。
2. **`StackTraceRewriter` を残すか (§10)。** 今は実際の成果物に効いていない。
   - A. 残して直す (推奨)。`.meta` に `source` を足し、`rewriteFile()` は同じディレクトリの `<成果物>.meta` から元のパスを引く。
     成果物のファイル名だけでは `.psx` を指せず、行を足すと CP-101 が崩れるので、付随ファイルを使う。計画書の配置のとおり
     ランタイム側に置ける。
   - B. 作らない。Relayer も README も使っていないので、最初のリリースから外す。例外には `<sha1>.php` のパスが出るが、
     行番号は `.psx` と一致する。
3. **型のない第 1 引数 (CP-133)。** `fn($props) => ...` は usePHP の `CompileCommand` ではエラーになる一方、Relayer の
   リフレクションでの解決 (`relayer/src/Psx/PsxComponentRegistrar.php:355`) は `props` として扱う。
   - A. 最初の引数は型がなければ `props` とする (推奨)。コマンドと実行時の解釈が揃い、JSX から来た人の書き方も通る。
   - B. usePHP のままエラーにする。
4. **manifest の絶対パス (CP-131)。** ビルドと実行でパスが違う配置では、`file` が指す先がなくなる。`cachePathFor()` もソースの
   絶対パスに依存する。
   - A. v0.0.1 は絶対パスのままとし、制約を仕様書に書く (推奨)。移行の範囲を増やさず、Relayer の探し方とも揃う。
   - B. `file` を manifest からの相対パスにする。`cachePathFor()` の問題は別に残る。
5. **成果物の書き込みの原子性。** `CompileCommand` は `file_put_contents` で直接書く (`CompileCommand.php:680`) が、Relayer の開発時
   自動コンパイルは同時のリクエストから `run()` を呼ぶことがある (`relayer/src/Psx/PsxComponentRegistrar.php:51`)。
   Relayer 自身の単一ファイルのコンパイルは原子的に書いている (`relayer/src/Router/AppRouter.php:1800`)。
   - A. 一時ファイルと `rename` で書く (推奨)。別のリクエストが書きかけの PHP を読まなくなる。
   - B. usePHP のまま。本番はビルドでコンパイルするので、開発時だけの問題とする。
6. **CLI の細部。** usePHP の振る舞いを実測で確かめた。いずれも直すことを推奨する。
   - 未知のオプションが無視される。`--chek` は exit 0 で普通にコンパイルするので、CI の検査が素通りする。→ exit 1 にする。
   - ディレクトリとその中のファイルを同時に渡すと、同じファイルが 2 回入り、重複 FQCN のエラーになる。→ 重複を除く。
   - `--watch` は失敗すると mtime の記録を更新しないので (`CompileCommand.php:557`)、直すまで 500ms ごとに同じエラーを
     表示する。→ 記録を更新し、次の変更まで待つ。
