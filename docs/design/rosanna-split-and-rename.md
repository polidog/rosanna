# Rosanna — usePHP を残して新しいリポジトリで 0 から作り直す計画

Status: Draft (2026-11-01、usePHP v0.10.0 / Relayer main 9566b0c で照合)

usePHP のリポジトリは改名せずに残し、`polidog/rosanna` を新しく作る。Rosanna のコードは usePHP からコピーせず、
0 から作り直す。

関連: [UsePHP 分割設計](https://github.com/polidog/usePHP/blob/main/docs/design/usephp-decomposition.md) (本体の責務分離。以下「分割設計」)

## 目的

1. usePHP の設計上の問題を引きずらないよう、分割設計の最終形を最初から 0 から作る。
2. PSX コンパイラを同じパッケージの `src/Psx/` に置き、描画の経路では読み込まない (2026-11-01 に変更。[決定の変更](#決定の変更-2026-11-01))。
3. 新しい名前 Rosanna で出す。パッケージ名、名前空間、コマンド、本体クラスのすべてが対象。
4. usePHP は 0.10 系として動かし続け、既存の利用者に影響を与えない。

名前は TOTO の曲 "Rosanna" から取った。Relayer (Yes) と同じく曲目から取る方針で、Jeff Porcaro のシャッフルで
有名な曲でもある。README の冒頭に由来を一行書く。名前はこれで確定とする。Rosanna と PSX の関係は React と JSX の
関係と同じで、Rosanna がライブラリの名前、PSX が構文の名前である。Packagist の `polidog/rosanna` は
2026-11-01 時点で未使用。

## 決定事項

| 項目 | 現在 | 変更後 |
|---|---|---|
| パッケージ | `polidog/use-php` | `polidog/rosanna` (描画) と `polidog/rosanna-state` (状態層)。[決定の変更 (2026-11-02)](#決定の変更-2026-11-02) |
| コンパイラの場所 | 同居 (`src/Psx/`) | 同居 (`src/Psx/`) |
| 名前空間 | `Polidog\UsePhp` | `Polidog\Rosanna`、状態層は `Polidog\Rosanna\State` |
| PSX の名前空間 | `Polidog\UsePhp\Psx` | `Polidog\Rosanna\Psx` |
| 本体クラス | `UsePHP` | `Rosanna`、状態層の入口は `RosannaState` |
| コマンド | `vendor/bin/usephp` | `vendor/bin/rosanna`、状態層は `vendor/bin/rosanna-state` |
| GitHub リポジトリ | `polidog/usePHP` (残す) | `polidog/rosanna` (新規) |

- パッケージ名は `polidog/rosanna` とする。利用者が最初に試す名前を入口にする。
- コンパイラは同じパッケージに置く。中身の大半は PSX 専用なので、名前空間は `Polidog\Rosanna\Psx` とする。
- `useState` などの hooks の関数名は変えない。
- Relayer とは統合しない。Relayer は別リポジトリのまま。
- **コードは 0 から作り直す。** usePHP のコード、テスト、git の履歴は取り込まない。usePHP は読んで仕様を確かめる
  参考資料とし、ファイルはコピーしない。
- hooks、`H`、`Component`、PSX の構文は usePHP と同じ使い方にする。関数名、引数、属性の書き方は変えない。内部の構造は合わせない。例外はルーターに関わるものと、次の 2 つである。
  `useRouter` は Rosanna から外す。`H::component()` は `Polidog\Rosanna\State\component()` になる。
  移行は名前の置換と再コンパイルでは済まない。状態を使うアプリは [利用者の移行手順](#利用者の移行手順-リリースノートに書く) のとおり、パッケージの追加、入口の変更、`Polidog\Rosanna\State\` への `use` の変更、`rosanna-state compile` での再コンパイル、`AssetInstaller` の名前空間の変更が要る。描画だけのアプリは `polidog/rosanna` だけで済む。

### 決定の変更 (2026-11-01)

当初は `polidog/rosanna` と `polidog/rosanna-compiler` の 2 パッケージに分け、`packages/compiler/` を読み取り専用の
リポジトリに分割する計画で、途中までその形で作った。公開の前に、1 パッケージにすると決めた。`polidog/rosanna-compiler` は
作らない。

- 理由: PHP のライブラリとして自然で、本番でも compile が使える。
- 代償: `nikic/php-parser` が必須依存になる。ランタイムだけを入れることはできない。
- 残すもの: 描画に php-parser を読み込まない境界。`src/Psx/` の外から `Polidog\Rosanna\Psx` と `PhpParser` を参照しないことと、
  コンパイル済みの成果物で描画してもそれらのクラスが読み込まれないことをテストで固定する。
- なくなるもの: `compile` の `class_exists()` の分岐と案内、分割の CI、`path` リポジトリ、`self.version`、
  `COMPOSER_ROOT_VERSION` の指定。

### 決定の変更 (2026-11-02)

状態層を `polidog/rosanna-state` に切り出す (polidog/rosanna#7)。Relayer を使う `polidog/web` と `ptyhard/website-2026` は `H`、`Element`、`Renderer` だけを使っており、hooks とイベントの prop は 1 件も使っていないため。

- 依存の向きは `rosanna-state` から `rosanna` への一方向。`polidog/rosanna` は描画層だけで動く。
- 状態層の初期化は `composer require polidog/rosanna-state` と `new RosannaState()` だけで、bootstrap の呼び出しはない。POST を `actions()->handle()` に渡す、リクエストの scope の中で描画する、`renderClientScript()` と `rosanna-state publish` で JS を配信する、Snapshot なら `setSnapshotSecret()` を呼ぶ、はホスト側の仕事として残る。
- コンパイラは `polidog/rosanna` に同居したままとする (2026-11-01 の決定は変えない)。
- 分け方と境界は Issue と [パッケージの中の境界](#パッケージの中の境界) に書く。互換レイヤーと deprecation は作らない。

### 作り直しても引き継ぐもの

コピーしないので、usePHP のセキュリティ修正は自動では引き継がれない。次の振る舞いを Rosanna の要件とし、
それぞれのテストを新しく書く。公開前に全項目を確認する。

| 振る舞い | usePHP のコミット |
|---|---|
| スナップショットの HMAC、CSRF、open redirect と `javascript:` URL の遮断 | `434527d` |
| 壊れたスナップショットを検証失敗として扱う | `2ec7012` |
| アクションの署名を描画元のページに結びつける | `88399e4` |
| アクションの検証前に Cookie からセッションを再開する | `38631b0` |
| タグ名と属性名の検証、インラインの `on*` ハンドラの拒否 | `07b7777` |
| 不正な UTF-8 で出力を空にせず置換する | `fa3a8ef` |
| `script` と `style` の中身をエスケープせずに出力する | `34b1f1a` |
| 描画できない子要素を拒否する | `a5c5018` |
| 訪問者ごとの応答を共有キャッシュに載せない | `4621f0f` |
| クライアント JS の defer の fetch、localStorage の永続化、部分送信の失敗の扱い | `739714f` |
| タブと改行を含む危険な URL の遮断、属性名の大文字小文字 | `864ece5` |
| `srcdoc` の拒否 | `f92da1b` |
| action の署名と入力の検証 | `cb415f9` |
| イベントの props を `Action` とクロージャに限る | `1f5e847` |
| Session の Snapshot をセッション ID に束縛、URL からの取り込みの削除 | `dcf3759` |
| redirect 先を同一オリジンのパスに揃える | `750af5a` |
| `useEffect` の cleanup を `Closure` に限る | `3f712b5` |
| cleanup をリクエストのメモリにだけ置く | `bec8ff6` |
| 状態の型を JSON の往復で保つ | `481f02e` |
| 入れ子の defer をプレースホルダーのままにする | `f1ca983` |
| action の POST でも保護を実行する | `5c57302` |

この表はコミットメッセージの検索から作ったもので、網羅の保証はない。手順 4 で usePHP の履歴とテストを読み直して
補った (`864ece5` 以降の 11 件。`docs/design/usephp-conformance.md`)。

### HTML、JS、HTTP の識別子

やり取りされる識別子もすべて新しい名前にする。usePHP の名前は Rosanna に残さない。

| 現在 | 変更後 |
|---|---|
| `_usephp_*` (フォームのフィールド) | `_rosanna_*` |
| `data-usephp`、`data-usephp-*` | `data-rosanna`、`data-rosanna-*` |
| `X-UsePHP-Partial` | `X-Rosanna-Partial` |
| `window.usePHP` | `window.Rosanna` |
| `usephp.js` | `rosanna.js` |

新旧の両方を受け付ける期間は設けない。移行時に CDN に残った HTML や古い JS は動かなくなるので、利用者は
デプロイのときにキャッシュを消す。

## defer の分離 (2026-11-02)

当初は defer を状態層に置いた。`ActionHandler` と `rosanna.js` を共有していて、2 サイトでも使っていなかったためである。Relayer で useState を使わずに defer を使う要件が出たので、`polidog/rosanna-defer` に切り出した。

- 依存の向きは `rosanna-state` → `rosanna-defer` → `rosanna`。defer を state の上に載せると状態なしで使えない。
- 本体の差し替え口は `RenderExtension` と別の `DeferredRenderer` にした。`RenderExtension` を実装すると、状態なしの描画のタグの検査を複製することになるため。
- state に残るのは、断片の中のフォーム、署名の `defer:{name}`、replay の探索、部分更新とセッションの更新との接続である。

## パッケージの中の境界

`polidog/rosanna` の必須依存は `ext-mbstring` と `nikic/php-parser` で、どちらもコンパイラのためのものである。`polidog/rosanna-state` の必須依存は `polidog/rosanna` だけである。

| パッケージ | 場所 | 中身 | 使う依存 |
|---|---|---|---|
| `polidog/rosanna` | `src/` (`src/Psx/` の外) | `H`、`Element`、`Renderer`、`RenderContext`、`RenderScope`、`PsxRenderer`、`RequestLifecycle`、差し替え口 (`RenderExtension`、`EventBinding`、`EventResolver`、`ResolvesItself`、`AcceptsArgumentResolver`)、`Rosanna` | なし |
| `polidog/rosanna` | `src/Psx/` | Parser、Compiler、CompileCommand | `nikic/php-parser`、`ext-mbstring` |
| `polidog/rosanna-defer` | `src/` | `Defer`、`DeferredRegistry`、`DeferredEndpoint`、`PlaceholderRenderer`、`DeferredManifestCompiler`、`deferred()`、`RosannaDefer`、`public/rosanna-defer.js` | `polidog/rosanna` |
| `polidog/rosanna-state` | `src/` | hooks、`fc()`、`component()`、`Action`、`Component`、`Http`、`Protocol`、`Snapshot`、`Storage`、`public/rosanna.js`、`RosannaState` | `polidog/rosanna`、`polidog/rosanna-defer` |

`psr/http-message` には依存しない。分割設計の step 0 は `require` に加えるが、usePHP も Relayer も PSR-7 を使っておらず、
リクエストは `Polidog\Rosanna\State\Http\Request` (usePHP の `RequestContext` に相当) で受ける。これは状態層の値オブジェクトで、`polidog/rosanna` は HTTP を知らない。

依存の向きは `src/Psx/` からランタイムへ、`polidog/rosanna-state` から `polidog/rosanna` への一方向。本体は状態層の名前を参照しない。規模の目安は分離の前の数で、 usePHP のランタイム約 9,400 行、
PSX 約 2,400 行。

usePHP での依存の実態は次のとおりで、Rosanna の境界もこれを前提に決める。

- `nikic/php-parser` を使うのは `src/Psx/` だけ。
- usePHP の `CompileCommand` はコンパイル時に `FunctionComponent` を参照する (`src/Psx/CompileCommand.php:434`)。Rosanna の `CompileCommand` は参照せず、manifest の後に動く `\Closure` を受ける。`rosanna-state compile` がこれを渡して deferred manifest を書く。
- 生成コードは `RenderContext::getApp()->renderPsxComponent(...)` と `H::defer(...)` を呼ぶ
  (`src/Psx/PsxParser.php:551`, `:577`)。Rosanna の生成コードは `getApp()` を使わない形で作る。

hooks だけのパッケージは作らない。hooks は `ComponentState`、`RenderPass` に依存し、これらは Action、署名、Storage と一緒に `polidog/rosanna-state` に置く。

ランタイムとコンパイラの本当の境界は生成コードである。ランタイム側で呼び出しを変えると再コンパイルが必要になる。

### 最初から作らない参照

usePHP にはランタイムから PSX への参照が残っている。Rosanna では最初からこの向きの参照を作らない。

| usePHP の場所 | 参照 | Rosanna での形 |
|---|---|---|
| `src/UsePHP.php:293` | `CompileCommand::DEFERRED_MANIFEST_FILENAME` | `MANIFEST_FILENAME` とともにランタイム側の定数にし、コンパイラから参照する |
| `src/UsePHP.php:518` | `StackTraceRewriter::formatException()` | ランタイム側に置く。分割設計の `installPsxErrorHandler` 削除と合わせて要否を確認する |

### コマンド

`vendor/bin/rosanna` は `compile` と `help`、`vendor/bin/rosanna-state` は `publish`、`compile`、`help` を持つ。Composer で同じ bin 名は共存できない。`bin/rosanna` は `compile` で `CompileCommand` を直接呼ぶ。

```php
$compile = static function (array $arguments): int {
    return new \Polidog\Rosanna\Psx\CompileCommand()->run($arguments, getcwd() ?: '.');
};
```

`polidog/rosanna` の中で `src/Psx/` の外から PSX への参照はこの 1 か所だけである。`bin/rosanna-state` は `CompileCommand` に `DeferredManifestCompiler` のクロージャを渡す。

`rosanna compile` は manifest を書くたびに build ID を `manifest.php` に書き、残っている deferred manifest を消す (`--check` では古いと報告する)。`rosanna-state compile` は deferred manifest を空でも必ず書き、同じ build ID を持たせる。`RosannaState::loadComponentManifest()` は deferred manifest がない、または build ID が合わないとき、`rosanna-state compile` を案内する例外にする ([03](../spec/03-compiler.md) の CP-135、CP-136、CP-144)。

## アクション

Rosanna の役割は描画と PSX、`polidog/rosanna-state` の役割は状態の管理で、どちらも利用者のコードを動かす入口は持たない。この節は `polidog/rosanna-state` の話である。DI も、イベントのディスパッチも持たない。
Action は `setState`、`setFields`、`restore` の 3 種類で、どれも描画時に確定した値で状態を変えるか戻すだけとする。
`setState` の値は差分で運ぶことがあるが、適用の結果は描画時に確定している ([#87 への対応](#87-への対応))。
DI を含むアクション処理は Relayer が担う。

### ルーターを持たない

usePHP の `SimpleRouter`、ルート定義、`RouterInterface`、分割設計の Kernel は作らない。URL からページを選ぶのは host の
役割とする。Relayer も `usephp-bear-module` もフレームワークの上で動くので、組み込みのルーターは主な連携先では
要らない。素の PHP では、ページごとの PHP ファイルに埋め込み、action の入口を 1 行呼ぶ形にする。

usePHP でルーターに頼っていた次の処理は別の形で残す。

| usePHP | Rosanna |
|---|---|
| `createRedirectUrl()`、`extractSnapshot()` (Snapshot を URL で運ぶ) | Snapshot 側に移す。redirect 先は現在のリクエストの URL |
| `match()` (action の後の再描画) | `actions()->handle()` の `replay` callback |
| `useRouter()` の `currentUrl`、`params` | Rosanna には置かない。Relayer が同じ名前の hook を提供する |

### Relayer のサーバーアクションとの関係

Relayer の `$ctx->action()` は Relayer に残す。Rosanna は JSON でない `_rosanna_action` を自分のものとして扱わず
無視するので、Relayer のトークンと共存する。

### Relayer に公開する API

分割設計の「応答は Relayer が決める」を次のように改める。Relayer #116 で応答の判断を usePHP に委譲したので、
判断は Rosanna の 1 か所に置く。action の API は `polidog/rosanna-state` のもので、`$app` は `RosannaState` である。

```php
$response = $app->actions()->handle($request, replay: fn (bool $partial) => $this->renderPage(/* ... */));

$app->signer()->sign($componentId, $actionJson, $pagePath);
$app->signer()->verify($componentId, $actionJson, $signature);
$app->snapshotSerializer();   // ?SnapshotSerializer
```

再描画の callback は Relayer が渡すので、ページに注入したサービスがそのまま使える。部分更新と defer を含む再描画で
DI が効くことをテストで確かめる。`ActionReplayRouter` と exit handler は要らなくなる。

描画だけなら `polidog/rosanna` の API で足りる。Relayer はまだ `polidog/use-php` に依存しているので、Rosanna へ移るときに状態層を任意の依存にする (`LayoutRenderer.php:11`、`AppRouter.php` の `dispatchStateAction`)。

```php
// polidog/rosanna だけで使える
new Renderer();                                  // 引数なし
RenderContext::run($app->scope(), $fn);          // $app は Rosanna
$app->setArgumentResolver($resolver);            // PsxArgumentResolver
RequestLifecycle::reset();                       // 状態層を入れても入口はこれだけ

// polidog/rosanna-state を入れたときに使う
RenderContext::run($app->scope($request), $fn);  // $app は RosannaState
StateScope::of(RenderContext::scope())->renderers;
```

## リポジトリ構成

1 リポジトリ 1 パッケージとする。状態層は別リポジトリの `polidog/rosanna-state` である。monorepo の分割 CI、`self.version`、`path` リポジトリは持ち込まない。

```
polidog/rosanna
├── composer.json
├── src/
│   └── Psx/               コンパイラ
├── tests/
│   └── Psx/
└── docs/
    └── PSX.md             PSX の仕様書
```

- コンパイラのテストは `tests/Psx/` に新しく書く。ランタイムも参照してよい。PSX はランタイムに依存している。
- PSX の仕様書は `docs/PSX.md` に書く。`docs` は `export-ignore` なので配布物に入らず、README からは GitHub の URL で案内する。
- Relayer の CI で、Rosanna の main に対するテストも回す。

## リポジトリの方針

### `polidog/rosanna` (新規)

- 空のリポジトリから始める。usePHP の履歴もタグも持ち込まない。
- 開発は `main` に直接コミットして進める。作業用のブランチと PR は使わない。
- 最初のリリースは v0.0.1。0 から作り直した新しいパッケージなので、usePHP のバージョンを引き継がない。開発中はタグを
  打たないので、main を壊しながら進めてよい。
- Composer の `^0.0.1` は 0.0.1 だけに一致する。`polidog/rosanna-state` と Relayer は `^0.0.1` で固定し、
  `polidog/rosanna` を先にタグ付けして、リリースごとに `require` も上げる。3 つを一緒に上げる前提で進め、負担が見えたら `~0.0.1` を検討する。
- 改名、分割設計の最終形 (`getApp()` なし) を最初から含めて v0.0.1 として出す。利用者の移行と
  再コンパイルを 1 回で済ませるため。
- `replace` は宣言しない。名前空間が変わるので、`polidog/use-php` の代替にならない。

### `polidog/usePHP` (残す)

| 時期 | 状態 |
|---|---|
| Rosanna の公開まで | 新機能は入れない。緊急のセキュリティ修正だけ v0.10.x で出す |
| 手順 6 の公開時 | Packagist で abandoned にし、移行先に `polidog/rosanna` を指定する。未解決の Issue を移し、README の冒頭に移行先を書いて、リポジトリをアーカイブする |

abandoned にしてもアーカイブしても、既存のタグはインストールできる。最新のタグ v0.10.0 は main と同じコミットで
(2026-11-01 時点)、タグのない修正は残らない。

### usePHP の未解決 Issue

修正の二重管理はしない。次の Issue は Rosanna に移し、作り直しの設計の入力にする。

| Issue | 内容 |
|---|---|
| #120 | `useState` の読み取りで、匿名の訪問者にもセッションが始まる |
| #119 | `{...expr}` が単独の子要素だと不正な PHP を生成する |
| #118 | `strict_types` の PSX で、子要素 1 つの数値が `H` の呼び出しで TypeError になる |
| #87 | 一覧の HTML サイズが行数の 2 乗で増え、1000 行でメモリ上限を超える |
| #80 | 連打や複数タブで更新が失われる (値が描画時に固定されるため) |

### #80 への対応

値を描画時に確定する設計は変えない。代わりに、古い画面から送られた action を上書きとして適用せず、最新の状態で描き直す。
action の適用ごとにインスタンスのバージョンを上げ、描画時のバージョンを action と一緒に署名する。

| 保存先 | 確かめる場所 | 仕組み |
|---|---|---|
| Session | サーバー | セッションのロックの中でバージョンを比べ、違えば適用せずに描き直す |
| Snapshot | クライアント | `rosanna.js` が最新の署名済みスナップショットを `sessionStorage` に写す。古いフォームの送信は止め、`restore` で最新の状態を描き直す |

`sessionStorage` はタブごとなので、状態がページの中にある Snapshot と単位が揃う。別のタブの Snapshot は別の状態のままで、
互いに競合しない。

- 連打の 2 回目と、別のタブの Session の更新は、加算されずに描き直される。回数を数えるにはサーバーの最新値で計算する
  仕組みが要るが、action で利用者のコードを動かすことになるので入れない。
- Snapshot の確認はクライアントだけで行うので、JavaScript なしの送信や、保存済みの古いスナップショットを手で送る
  リクエストは止められない。これは競合の防止であって、セキュリティの境界ではない。1 回だけ使える権利のような値を
  Snapshot に置かないことをドキュメントに書く。
- `sessionStorage` が使えない環境では、ページ内のメモリで同じ確認を行う。リロードと戻る操作の後は確認できない。

### #87 への対応

O(n²) の原因は 2 つある。各ボタンの action に新しい一覧全体が入ることと、Snapshot では各フォームにスナップショット全体も
入ることである。片方だけ直しても O(n²) は残るので、両方を変える。利用者のコード (`$set(array_map(...))`) は変えない。

**値は差分で運ぶ。** Action の種類は増やさず、`setState` の payload に全体の値か差分のどちらかを入れる。

- setter が描画時の値と新しい値を比べて差分を作る。リストは共通の先頭と末尾を除いた splice にし、1 要素だけ変わる場合は
  その要素の中を再帰的に比べる。先頭行の削除で全行の添字がずれても、差分が膨らまない。
- 描画時に差分を適用してみて、新しい値と `===` で一致し、かつ全体より小さいときだけ差分を使う。それ以外は全体を送る。
  差分は圧縮として扱い、キーの順序などの細かな場合分けを一致の確認に任せる。
- 差分は描画時と同じ状態にしか適用できない。古い状態に適用すると、誰も描画していない混ざった状態ができる。そこで、描画時の
  フックの値のハッシュを `base` として action の JSON に入れ、一緒に署名する。`signer()` の引数は変わらない。サーバーは適用の
  前に基準の値のハッシュと比べ、違えば適用せずに描き直す。

| 保存先 | 基準の値 |
|---|---|
| Snapshot | 検証済みのスナップショットの state |
| Session、Memory | 保存値。なければフォームの `_rosanna_base[{index}]` |

- 初期値は保存しない (匿名の訪問者のセッションを始めないため) ので、一覧を初めて描画したときは基準がサーバーにない。そこで値を
  フォームに 1 回だけ載せる。署名しなくても、署名済みの `base` と比べるので改ざんは適用されない。最初の適用の後は保存値を使う。
- バージョンでは足りない。未保存の初期値はリクエストごとに変わることがあり、Snapshot は 2 つのタブが同じ版から分岐すると、番号が
  同じで中身の違う状態ができる。

この確認は差分と基準の組み合わせを守るもので、#80 の「Snapshot の確認はクライアントだけ」は変わらない。古い画面のフォームは
自分のスナップショットと一緒に送られるので基準が一致し、古い画面からの送信を止めるのは引き続き `rosanna.js` の役割である。

**フォームはコンポーネントに 1 つにする。** 送信ボタンの `name` と `value` はフォームと一緒に送られるので、JavaScript なしでも
動く。共通のフィールドは 1 回だけ書く。

```html
<button type="submit" form="rosanna-f-…" name="_rosanna_action" value='{"action":"…","sig":"署名"}'>○</button>
…
<form id="rosanna-f-…" method="post" data-rosanna-form hidden>
  component / csrf / snapshot を 1 回だけ
</form>
```

- ボタン 1 つの `value` に action と署名を両方入れるので、エンベロープの JSON にする。`_rosanna_action_sig` はなくなる。JSON の
  ままなので、「JSON でない `_rosanna_action` は無視する」という Relayer との取り決めは保たれる。
- フォームはラッパーと、action の対象のコンポーネントの組み合わせごとに 1 つ置く。子に渡した親の setter のボタンは、
  子のラッパーの中のフォームを使い、子の部分更新で一緒に置き換わる。
- フォームの ID はランダムのままにする。アプリの `form` の中のコンポーネントはフォームがラッパーの外に置かれ、部分更新の後も古い
  フォームが残るので、固定の ID だと新しいボタンが古いスナップショットのフォームを指す。
- `onClick` などを `rosanna.js` で処理する button 以外の要素は、属性に同じエンベロープを持たせる。もともと JavaScript が必要
  なので、フォームの共有で失うものはない。
- ラッパーの `data-rosanna-snapshot` は残す。action のないコンポーネントにはフォームがなく、`rosanna.js` が子孫の状態の収集や
  永続化で読む。スナップショットはインスタンスごとにラッパーとフォームの 2 か所になるが、行数の 2 乗にはならない。

次の 3 つは共有フォームにできないので、個別のフォームのまま残す。

| 対象 | 理由 |
|---|---|
| `onSubmit` のフォーム | アプリ側のフォームは入れ子にできず、1 つの `input` は複数のフォームに属せない |
| `input[type=submit\|image]` | `submit` は `value` がラベルになり、`image` は `value` が送られない |
| 利用者の `name` か `value` を持つボタン | 利用者の値を上書きできない |

**残る制約。** ドキュメントに書く。

- Snapshot で個別のフォームを行ごとに置く形は、スナップショットがフォームごとに入るので O(n²) のままである。
- 並べ替えのように全体が変わる操作は差分にならない。各行に置くと O(n²) のままである。
- Snapshot の行コンポーネントへの JavaScript なしの全ページ応答は、各フォームに他のインスタンスのスナップショットが入るので
  O(n²) のままである。
- onClick のクロージャは描画時に実行するので、CPU 時間は O(n²) のままである。

**採らない案。**

| 案 | 理由 |
|---|---|
| action をサーバーに保持して ID を渡す | Snapshot の「サーバーに状態を持たない」利点がなくなる。セッションが描画とタブのたびに増え、戻る操作で開いた古いページの ID が切れる |
| gzip に任せる | サーバーのメモリとブラウザのパース負荷が減らない |
| ID を渡してサーバーの関数で処理する (reducer) | 「利用者のコードを動かす入口を持たない」に反する。Relayer の `$ctx->action()` の役割である |

**計測 (2026-11-01、PHP 8.5.12)。** #87 の再現コードと同じ形の Todo で測った。現行は usePHP `e22f8ad` の `Renderer` の実測で、案は
同じ形の HTML を手で組み立てた試作なので、Rosanna の実装で測り直す。ブラウザでの送信は試していない。

| 保存先 | 行数 | 現行の HTML | 案の HTML | 現行のピークメモリ | 案のピークメモリ |
|---|---|---|---|---|---|
| Session (初回) | 200 | 3.9 MB | 155 KB | 16 MB | 2 MB |
| Session (初回) | 1000 | 94.5 MB | 775 KB | 254 MB | 4 MB |
| Session (保存後) | 1000 | 94.5 MB | 681 KB | 254 MB | 4 MB |
| Snapshot | 200 | 7.7 MB | 174 KB | 27 MB | 2 MB |
| Snapshot | 1000 | 188.7 MB | 870 KB | 478 MB | 4 MB |

案の 2000 行は 1.4〜1.7 MB で、サイズは行数に比例する。1000 行で 1 回の操作に載る差分はトグルで 44 B、削除で 18 B、先頭への追加で
53 B である (全体は約 54 KB)。試作の差分はランダムな値 20 万件で、JSON を経由しても復元結果がすべて一致した。差分の作成に
かかる時間は 1000 行で約 0.2 秒、2000 行で約 0.9 秒で、行数の 2 乗で増える。

要件とテストは [`polidog/rosanna-state` の実装仕様](https://github.com/polidog/rosanna-state/blob/main/docs/spec/02-state-and-actions.md) の 3.1、3.2 節と、[01](https://github.com/polidog/rosanna-state/blob/main/docs/spec/01-runtime.md) の RT-4.7〜RT-4.9 に書く。

## 作業の順番

名前は最初から Rosanna で書く。改名の工程はない。分割設計の移行順 (step 0〜4) は既存コードを段階的に変えるための
ものなので、そのままは使わず、最終形だけを目標にする。

### 1. リポジトリを作る (実行前に確認する)

- ローカルの `polidog/rosanna` には今この計画書だけがあり、git リポジトリにもなっていない。`git init` する。
- ルートの `composer.json`、静的解析とテストの設定、CI を最初のコミットで用意する。
- GitHub に `polidog/rosanna` を公開で作って push する。
- CI が通ることを確認する。

### 2. ランタイムを作る

分割設計の最終形を目標に、テストと一緒に次の順で作る。各段階で「作り直しても引き継ぐもの」の該当項目を満たす。

1. `H`、`Renderer`、`Component`
2. hooks、Storage、Snapshot
3. アクション、CSRF、defer (#80 のバージョン確認、#87 の差分と共有フォームを最初から入れる)
4. `Rosanna` 本体
5. `rosanna.js`、`AssetInstaller`、`bin/rosanna`

### 3. コンパイラを作る

- Parser、Compiler、`CompileCommand` を `src/Psx/` に作る。
- 生成コードは `Polidog\Rosanna` の名前空間を使い、`getApp()` を呼ばない。
- `bin/rosanna` から `CompileCommand` を呼ぶ。

### 4. usePHP と照合する

- usePHP の履歴とテストを読み直し、「作り直しても引き継ぐもの」の抜けを補う。
- README と PSX の仕様にある機能を項目ごとに確かめ、Rosanna で動くか、意図して落としたかを記録する。

### 5. Relayer の追従

Relayer は別ブランチで追従し、Rosanna の main に対してテストを回す。Relayer の main は v0.0.1 が出るまで
`polidog/use-php` のままにする。

`Polidog\UsePhp` の出現数 (`vendor` などを除く):

| リポジトリ | ファイル | 箇所 |
|---|---|---|
| Relayer | 100 | 292 |
| relayer-doc | 38 | 278 |
| usephp-bear-renderer-example | 10 | 25 |
| create-usephp-router | 6 | 12 |

このうち、ここで直すのは Relayer と relayer-doc だけ。

- `polidog/rosanna` を `require` にし、状態層 (`polidog/rosanna-state`) は任意の依存にする。`PsxComponentRegistrar` と `Scaffold\CompileCommand` が使う `CompileCommand` は
  `polidog/rosanna` に入っている。
- 分割設計の「Relayer 側の追従」を行う。ただし action まわりは「Relayer に公開する API」に従う。
- `useRouter` を Relayer に追加し、relayer-doc の案内を Relayer の名前空間に直す。
- HTML、JS、HTTP の識別子を新しい名前に合わせる (`AppRouter.php` の `data-usephp`、`_usephp_action`、
  `X-UsePHP-Partial` など)。Relayer 自身の `usephp-action:` トークンと環境変数 `USEPHP_SNAPSHOT_SECRET` も
  `rosanna-action:`、`ROSANNA_SNAPSHOT_SECRET` に変える。環境変数の変更はリリースノートに書く。
  識別子の出現は Relayer が 33 ファイル 210 箇所、relayer-doc が 23 ファイル 134 箇所 (2026-11-01 時点)。
- `AppRouter.php:1593`、`:1616-1620` の `_usephp_action` と `_usephp_action_sig` の読み取りを、#87 のエンベロープに合わせる。
- `vendor/bin/usephp` を案内している箇所を直す (`Scaffold.php:326`, `:928`、`AppRouter.php:1767` など)。
- **既存プロジェクトの移行を `relayer upgrade` に入れる。** `relayer init` は利用者の `composer.json` に
  `Polidog\UsePhp\Installer\AssetInstaller::publish` を書き込んでいる (`Scaffold.php:100`)。Rosanna に移ると
  `composer install` の post-install で失敗するので、`STRUCTURE_VERSION` を上げてこのスクリプトを書き換える。状態層を入れるなら `Polidog\Rosanna\State\Installer\AssetInstaller::publish` にし、入れないなら消す。
  本体だけに移ると `AssetInstaller` と JS がなくなるので、サーバーアクションのフォームが JS に頼っていないか、そのときに確かめる (未確認)。
- relayer-doc を更新する。

### 6. 公開 (実行前に確認する)

1. `polidog/rosanna` に v0.0.1 のタグを打って Packagist に登録し、`polidog/rosanna-state` の制約を `^0.0.1` にして同じようにタグを打って登録する。
2. Relayer の追従ブランチをマージして、新しいバージョンを出す。
3. `polidog/use-php` を Packagist で abandoned にし、移行先に `polidog/rosanna` を指定する。
4. usePHP の未解決 Issue を `gh issue transfer` で移し、README の冒頭に移行先を書いて、usePHP をアーカイブする。
   アーカイブすると読み取り専用になるので、この順番で行う。

## 利用者の移行手順 (リリースノートに書く)

移行は `new` と `use` の置換だけでは済まない。

### usePHP から、状態を使うアプリ

```bash
composer remove polidog/use-php
composer require polidog/rosanna-state
```

1. `new UsePHP()` を `new RosannaState()` にする。`setSnapshotSecret()`、`actions()`、`scope()`、`restoreSnapshots()`、`handleDeferred()`、`renderClientScript()` は `RosannaState` のものになる。
2. `.psx` と PHP の hooks と状態層のクラスの `use` を `Polidog\Rosanna\State\` に替える (`Runtime\*` は `State\*` に平らにする。`Protocol`、`Snapshot`、`Storage`、`Component`、`Http`、`Installer` は `State\` の下で同じ名前)。`Runtime\Snapshot` は `State\Snapshot\Snapshot` になる。`H`、`Element`、`RenderContext` など描画のものは `Polidog\Rosanna\` に替える。`H::component()` の呼び出しは `State\component()` に替える。`SimpleRouter` や `useRouter` を使っていれば、host のルーターかページごとの PHP ファイルに置き換える。Relayer の利用者は `useRouter` の `use` を Relayer のものに直す。
3. `vendor/bin/rosanna-state compile` で再コンパイルする。生成コードは `.psx` の `use` をそのまま写すので、状態付きの PSX は全部再コンパイルが要る。変わらないのは、コンパイラが生成するコンポーネント呼び出し `RenderContext::scope()->psx->render` の形だけである。`vendor/bin/usephp` を案内している箇所も直す。
4. `composer.json` の `scripts` にある `AssetInstaller::publish` の名前空間を `Polidog\Rosanna\State\Installer\` に替える。
5. `vendor/bin/rosanna-state publish` で `rosanna.js` を配置し、HTML の `usephp.js` の読み込みを直す。
6. 自前の JS やテンプレートが `_usephp_*`、`data-usephp-*`、`X-UsePHP-Partial`、`window.usePHP` を参照していれば
   新しい名前に直す。
7. デプロイのときに CDN のキャッシュを消す。

### usePHP から、描画だけのアプリ

```bash
composer remove polidog/use-php
composer require polidog/rosanna
```

1. `Polidog\UsePhp` を `Polidog\Rosanna` に、`UsePHP` を `Rosanna` に置換する。
2. `new Renderer('...')` を `new Renderer()` にする (`website-2026` の 4 か所など)。
3. `vendor/bin/usephp` を `vendor/bin/rosanna` に直し、PSX を再コンパイルする。
4. `AssetInstaller` と `usephp.js` を外す。サイトが JS に頼っていないことを先に確かめる。

### `polidog/rosanna` の状態を使っていたアプリ

polidog/rosanna#7 の「状態を使うアプリの移行」のとおり。

1. `composer require polidog/rosanna-state`
2. `new Rosanna()` を `new RosannaState()` にする。`setSnapshotSecret()`、`actions()`、`scope()`、`restoreSnapshots()`、`handleDeferred()`、`renderClientScript()` は `RosannaState` のものになる。
3. `.psx` と PHP の `use function Polidog\Rosanna\Runtime\useState;` などを `Polidog\Rosanna\State\` に替える。`H::component()` の呼び出しは `State\component()` に替える。
4. `vendor/bin/rosanna-state compile` で再コンパイルする。生成コードは `.psx` の `use` をそのまま写すので、状態付きの PSX は全部再コンパイルが要る。
5. `composer.json` の `scripts` にある `AssetInstaller::publish` の名前空間を `State\Installer\` に替える。

コンパイラは `polidog/rosanna` に入っているので、`--no-dev` で本番を動かす場合も、ビルド工程でも本番サーバー上でも
コンパイルできる。コンパイル済みのファイルの描画ではコンパイラも `nikic/php-parser` も読み込まない。

## 追従しないもの

`polidog/usephp-bear-module`、`create-usephp-router`、`usephp-bear-renderer-example` は Rosanna に追従しない。
いずれも旧バージョンのまま残す。

## 影響を受けないもの

`polidog/usePHP` のリポジトリ、`polidog/use-php` の既存のタグ、旧 URL はそのまま残る。バージョンを固定している
既存の連携は、何も変えずに動き続ける。

- `kojirock5260/usephp-laravel`: `polidog/use-php: ^0.8.0`
- `polidog/usephp-bear-module`: `polidog/use-php: >=0.6.0 <0.8.0`
- Relayer の既存のバージョン: `polidog/use-php: ^0.10.0`

Rosanna に引き継がれないのはスター (5 件)、git の履歴と blame、PR の履歴へのリンクで、これらは旧リポジトリに
残る。修正の経緯を調べるときは usePHP を見る。

## 未決事項・要確認

なし (2026-11-01 時点)。実装の粒度の決定は [実装仕様](../spec/README.md) にまとめる。
