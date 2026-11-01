# Rosanna 実装仕様

Status: Draft (2026-11-01、usePHP v0.10.0 / Relayer main 9566b0c で照合)

[計画書](../design/rosanna-split-and-rename.md) の決定を、実装できる粒度に落としたもの。Rosanna は 0 から作り直すので、
usePHP のコードはコピーせず、ここに書いた振る舞いを新しく実装する。

| 文書 | 範囲 | 要件 ID |
|---|---|---|
| [01-runtime.md](01-runtime.md) | `H`、Renderer、Component、hooks、描画スコープ、本体の描画 API、publish | `RT-` |
| [02-state-and-actions.md](02-state-and-actions.md) | Storage、Snapshot、Action、署名、CSRF、バージョン照合、defer、`rosanna.js` | `RQ-` `ST-` `SN-` `AC-` `SG-` `CS-` `VR-` `HD-` `DF-` `JS-` |
| [03-compiler.md](03-compiler.md) | PSX、コンパイラ (`src/Psx/`) | `CP-` |

実装の順番は計画書の「作業の順番」に従う。

## 文書をまたぐ契約

| 契約 | 定義 |
|---|---|
| `RenderContext::scope()`、`RenderScope` (`$signer`、`$signingScope` を含む) | 01 の 9 節 |
| `Rosanna::scope(Request $request, ?string $deferName = null)` | 01 の 12 節 |
| `Http\Request`、`Http\Response` | 02 の 0 節 |
| `Action` (`withVersion()` を含む)、action の JSON の `version` | 02 の 3 節、VR-1 |
| `StateDiff`、`ActionEnvelope`、共有フォーム | 02 の 3.1、3.2 節、01 の RT-4.7 |
| `ActionSigner`、`actions()->handle()`、`CsrfGuard` | 02 の 4、5、7 節 |
| 生成コードが呼ぶ `scope()->psx->render()`、`createElement`、`H::defer` | 01 の 2、11 節、03 の生成コードの節 |
| `PsxRenderer::MANIFEST_FILENAME`、`DEFERRED_MANIFEST_FILENAME` | 01 の 11 節、CP-137 |
| manifest と deferred manifest の形式 | 03 の 8 節。読むのは RT-11.5、登録は DF-13 |
| `RequestLifecycle::reset()` | RT-9.6 |

## 決定事項

2026-11-01 に、すべて推奨どおりに決めた (02 U-7 は利用者の判断で案 A に決めた)。各文書の該当箇所は確定の書き方に直してある。検討の記録は各文書の「決定の記録」の節にある。

### 振る舞いが大きく変わるもの

| ID | 内容 | 決定 |
|---|---|---|
| 02 U-1 | `SnapshotBehavior` を残す範囲と指定の場所 | Isolated、Persistent、Session を残し、Shared は落とす。host が指定する |
| 02 U-2 | `persist` なしのリロードと `sessionStorage` の写しの矛盾 | `navigate` と `reload` では写しを消す。戻る操作と bfcache の復帰では残して照合する |
| 02 U-3 | 古い action を描き直すと `setFields` の入力が消える | `X-Rosanna-Stale` と `rosanna:stale` イベントで知らせ、欄を保つ |
| 01 の 4 | `children` の `BaseComponent` がラッパーを出さず保存先を無視する | `fc()` と揃える。HTML は変わる |
| 02 U-7 | 秘密鍵ありで最初の action の後に残るトークンのないフォームが 403 か 400 になる | 案 A。ST-2、SG-1、CS-3 を緩めず、`X-Rosanna-Session-Started` を受けて `rosanna.js` がページのフォームを取り直す。ST-2 と匿名のページのキャッシュを保つため |

### 細部

| ID | 内容 | 決定 |
|---|---|---|
| 01 の 1 | `PsxArgumentResolver` の名前空間 | `Polidog\Rosanna\Runtime` |
| 01 の 2 | コンポーネントの外の hooks | `LogicException` |
| 01 の 3 | Relayer のページ自体の state | `ComponentState` の静的メソッドを `@internal` で残す |
| 01 の 5 | `FC@basename:行` の名前の衝突 | 形式を変えず、key を勧める |
| 01 の 7 | `renderClientScript` の CSP nonce | 第 3 引数に足す |
| 01 の 8 | SVG `<animate>`、`<set>` の URL | 手順 4 でブラウザで確かめる |
| 01 の 9 | void 要素の判定、`__callStatic` の位置引数、未知のコマンドの終了コード | いずれも直す |
| 01 の 10 | `Component::$requireKey` | 作らない |
| 02 U-5 | セッションを開始できないときの書き込み | `LogicException` |
| 02 U-6 | `SessionInterface` | v0.0.1 はネイティブセッションだけ |
| 03 の 1 | `<defer>` の文字列の属性 | コンパイルエラー |
| 03 の 2 | `StackTraceRewriter` | `.meta` に `source` を足して直す |
| 03 の 3 | 型のない第 1 引数 `fn($props)` | `props` として扱う |
| 03 の 4 | manifest の絶対パス | v0.0.1 ではそのままにし、制約を明記する |
| 03 の 5 | 成果物の書き込み | 一時ファイルと `rename` |
| 03 の 6 | CLI の細部 | いずれも直す |
