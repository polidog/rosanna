# usePHP との照合の記録 (計画書の手順 4)

Status: 2026-11-01、usePHP `e073bff` (v0.10.0) の `README.ja.md`、`docs/PSX.md`、git 履歴 216 件、テストの一覧と照合した。

分類は次の 3 つとする。

- **動く**: Rosanna で使える。名前が変わったものを含む。テストの場所を書く。テストがなく手元で確かめたものは「手元」と書く。
- **落とした**: 計画書か仕様の決定で意図して作らないもの。
- **未対応**: 使えない、または動くことを確かめていないもの。

テストのパスはリポジトリのルートからである。

## 集計

| 分類 | 件数 |
|---|---|
| 動く | 82 |
| 落とした | 22 |
| 未対応 | 2 |

## セキュリティの照合

### 漏れの一覧

計画書の「作り直しても引き継ぐもの」と仕様のどちらにも入っていなかった振る舞いは次の 1 件で、実際に Rosanna で再現した。`Request::pagePath()` を足して直した (下の注)。

| # | 内容 | 再現 | usePHP |
|---|---|---|---|
| 1 | **末尾の `/` で host の保護を避けられる。** SG-5 の正規化は `/admin`、`/admin/`、`/admin//` を同じページとして扱う。host が `$request->path === '/admin'` で保護すると、`/admin` で描画したフォームを権限のない訪問者が `/admin/` に POST したとき、保護を通って署名の検証も通り、**action が適用される** | `/admin` は 401、`/admin/` は 303 で適用、`/other` は 400。`//admin` は `Request::fromGlobals()` が揃えるので 401 | 保護と署名の比較が同じルーターの正規化を使うので起きない (`88399e4`、`5c57302`、`ActionPageBindingTest::testATrailingSlashStillMeetsTheGuard`) |

攻撃に必要なのは、そのページで描画された有効なフォームである。権限を失った利用者が取っておいたフォーム、秘密鍵ありでセッションのない訪問者向けのフォームが該当する。

**対応。** SG-5 の正規化は保ち、署名と同じ正規化を返す `Request::pagePath()` を足した。README の保護の例はこれで比べ、`tests/Integration/ReadmeExamplesTest.php` の `testGuardCoversEveryPathTheSignatureAccepts` で実際の HTTP で確かめた。**host が生の `$request->path` で比べると、今も `/admin/` で避けられる。** Rosanna は host の比較を強制できないので、これは残るリスクで、README と SG-5 に書いた。

### 計画書の表にないコミット

次のコミットは計画書の表になかったので足した。いずれも仕様には入っていて、Rosanna で動くことを確かめた。

| コミット | 内容 | 要件 | 確かめた場所 |
|---|---|---|---|
| `864ece5` | タブと改行を含む危険な URL の遮断、属性名の大文字小文字 | RT-3.11 | `tests/Security/SecurityHardeningTest.php` の `testRT311*`、手元 |
| `f92da1b` | `srcdoc` の拒否 | RT-3.10 | `tests/Runtime/RendererOutputTest.php` の `testRT310*`、手元 (`srcDoc`、`SRCDOC`) |
| `cb415f9` | action の署名と入力の検証 | SG-1、AC-3、AC-5、AC-6、HD-3 | `tests/Protocol/ActionHandlerTest.php` の `testStep4*`、`testStep5*` |
| `1f5e847` | イベントの props を `Action` とクロージャに限る | RT-2.6 | `SecurityHardeningTest` の `testRT26*` |
| `dcf3759` | Session の Snapshot をセッション ID に束縛、URL からの取り込みの削除 | SN-6、SN-23、SN-24 | `tests/Integration/SnapshotBehaviorFlowTest.php` の `testSN23*`、`testSN24*` |
| `750af5a` | redirect 先を同一オリジンのパスに揃える | HD-13 | `ActionHandlerTest` の `testHD13*`、`SessionActionFlowTest` の `testHD13*` |
| `3f712b5` | `useEffect` の cleanup を `Closure` に限る | RT-7.10 | `tests/Runtime/HooksTest.php` の `testRT710*` |
| `bec8ff6` | cleanup をリクエストのメモリにだけ置く | RT-7.11、ST-6 | `HooksTest` の `testRT711*`、`SessionLifecycleTest` の `testST6*` |
| `481f02e` | 状態の型を JSON の往復で保つ | AC-4 | `tests/Runtime/ActionTest.php` の `testAC4*` |
| `f1ca983` | 入れ子の defer をプレースホルダーのままにする | DF-6 | `tests/Protocol/DeferredEndpointTest.php` の `testDF6*` |
| `5c57302` | action の POST でも保護を実行する | host の役割 (02 の 7 節) | 漏れの 1 を除き `ReadmeExamplesTest`、`SessionActionFlowTest::testSG6*` |

`97cc436`、`e1cf82c`、`9476066` (ルートのパラメーターのデコード、パターンの文字、HEAD) はルーターの修正で、ルーターごと落とした。URL の値の検証は host の役割になる。

### 計画書の表の確認

| 振る舞い | コミット | 確かめた場所 |
|---|---|---|
| スナップショットの HMAC | `434527d` | `tests/Snapshot/SnapshotSerializerTest.php` |
| CSRF | `434527d` | `tests/Protocol/CsrfGuardTest.php`、`SessionActionFlowTest` の `testCS*` |
| open redirect | `434527d` | `tests/Snapshot/SnapshotUrlTest.php` の `testSN20*`、`ActionHandlerTest` の `testHD13*`、`tests/browser/resend.spec.ts` (JS-4) |
| `javascript:` URL の遮断 | `434527d` | `SecurityHardeningTest` の `testRT311*`、`tests/Psx/Integration/CompiledOutputTest.php` の `testEncodedJavascriptUrlIsBlocked` |
| 壊れたスナップショット | `2ec7012` | `SnapshotSerializerTest::testSN5*`、`SnapshotBehaviorFlowTest::testSN27*` |
| 署名を描画元のページに結びつける | `88399e4` | `ActionHandlerTest::testSG5*`、`testSG7*`、`testAC8*`、`tests/Http/RequestTest.php` の `testSG5*`。host の比較は漏れの 1 |
| 検証前に Cookie からセッションを再開する | `38631b0` | `CsrfGuardTest::testCS1*`、`SessionActionFlowTest::testCS1*` |
| タグ名と属性名、`on*` | `07b7777` | `SecurityHardeningTest::testRT38*`、`RendererOutputTest::testRT34*` |
| 不正な UTF-8 | `fa3a8ef` | `RendererOutputTest::testRT31*`、`SnapshotSerializerTest::testSN2*` |
| `script` と `style` の出力 | `34b1f1a` | `SecurityHardeningTest::testRT312*`、`testRT314*` |
| 描画できない子 | `a5c5018` | `RendererOutputTest::testRT32*`、`testRT313*` |
| 共有キャッシュ | `4621f0f` | `tests/Integration/DeferEndpointFlowTest.php` の `testDF8*`、`testDF9*` |
| defer の fetch、localStorage、部分送信の失敗 | `739714f` | `tests/browser/defer.spec.ts`、`failure.spec.ts`、`tests/js/defer-cache.test.mjs` |

### 実際に試して問題がなかったもの

手元のスクリプトで、攻撃の形の入力を Rosanna に渡した。

| 対象 | 試したこと | 結果 |
|---|---|---|
| foreign content | `SVG`、`Svg`、`MATH` の大文字、Fragment の中の `style`、`script` | エスケープされる |
| 終了タグ | `</SCRIPT>`、`</STYLE >` | 例外 |
| URL 属性 | `HREF`、`xlinkHref`、`XLINK:HREF`、`formAction`、`FORMACTION` + 改行、先頭の制御文字、`VBScript:`、`DATA:`、`embed`、`object`、`background`、`poster` | 属性が落ちる |
| `java\0script:` | NUL を挟む | そのまま出る。HTML パーサーが U+FFFD に置き換えるのでスキームにならない |
| 名前 | `ONCLICK`、`x/onclick`、`a b`、末尾の改行、不正な UTF-8 | 例外 |
| コンパイラの文字列 | `'`、`\`、`' . system('id') . '` を属性、テキスト、`style` に | エスケープされる |
| manifest | ファイル名 `O'Brien.psx` | `var_export` で壊れない |
| セッションの束縛 | 未知のセッション Cookie で Cookie なしのフォームを送る、セッションのフォームを Cookie なしや別の Cookie で送る | 403、400、403 |
| defer | `HEAD`、`..%2f`、`<img>` の名前、`?key=victim` | host へ素通り (usePHP と同じ)、404、404、`key` は無視 |

**01 の決定の記録の 8 (SVG のアニメーション)。** この記録の初版には「ブラウザが属性を消すので実行されない」と書いたが、**誤りだった。** 修正前の Renderer の出力で、`<set attributeName="href" to="javascript:...">` は Chromium 155 でクリックすると実行された。Renderer で遮断し (usePHP にはない)、RT-3.11 に要件を足した。`href` を対象にする `animate` と `set` の `to`、`values` (`;` で区切って値ごと)、`from`、`by` を検査し、大文字小文字と空白を無視し、接頭辞は問わない。`tests/browser/svg.spec.ts` は遮断した後の 13 の形と、対照の 2 件を確かめる。**Firefox と WebKit は環境になく確かめていない。**

## 機能の照合

### インストールと全体

| 機能 | 分類 | 場所、理由 |
|---|---|---|
| `composer require` と `publish` | 動く | `tests/Installer/PublishTest.php` |
| JavaScript なしのボタンとフォーム | 動く | `tests/Integration/SessionActionFlowTest.php`、`tests/browser/shared-form.spec.ts` |
| 依存なしの JS による部分更新 | 動く | `tests/browser/morph.spec.ts`、`ActionHandlerTest::testRow1*`、`testRow3*` |
| `session_start()` を呼ばなくてよい | 動く | `tests/Storage/SessionLifecycleTest.php`、`ReadmeExamplesTest` |
| フレームワーク統合 (描画より前に action を処理) | 動く | `ReadmeExamplesTest::testClassComponentEmbeddingWorks`。`Response` を返す形に変わった |
| `examples/` のサンプルアプリ | 未対応 | 作っていない。README の例は `ReadmeExamplesTest` で動かす |

### ルーター

| 機能 | 分類 | 場所、理由 |
|---|---|---|
| `SimpleRouter`、`$app->run()`、`getRouter()` | 落とした | 計画書「ルーターを持たない」 |
| ルートパターン、グループ、パラメーターのデコード | 落とした | 同上 |
| `HEAD` の `GET` へのフォールバック | 落とした | 同上 |
| `useRouter()` | 落とした | RT-0.3。Relayer が提供する |
| ミドルウェア、`runRouteMiddleware()` | 落とした | host が `handle()` の前に確かめる (02 の 7 節)。比較は `Request::pagePath()` で行う (漏れの 1) |
| `disableRouter()`、`NullRouter` | 落とした | ルーターがない |
| `redirect()` の公開メソッド | 落とした | HD-15 |
| `RouterInterface::createRedirectUrl()`、`extractSnapshot()` | 動く | `SnapshotUrl`。`tests/Snapshot/SnapshotUrlTest.php` |
| ルートのキーで状態を分ける | 動く | props の `key`。`ReadmeExamplesTest::testKeysSeparateTheInstances`、`FunctionComponentTest::testRT82*` |
| `match()` による action の後の再描画 | 動く | `replay`。`ActionHandlerTest::testRow3*`、`testHD6*`、`testHD7*` |

### Snapshot の挙動

| 機能 | 分類 | 場所、理由 |
|---|---|---|
| Isolated | 動く | `ActionHandlerTest::testRow5*`、`SnapshotBehaviorFlowTest::testRow5*` |
| Persistent | 動く | `SnapshotBehaviorFlowTest::testPersistentCarriesTheStateInTheRedirect`、`ReadmeExamplesTest` |
| Session | 動く | `SnapshotBehaviorFlowTest::testSessionBehaviorWritesToTheSessionAndRestoresOnGet`、`SessionSnapshotsTest` |
| Shared | 落とした | U-1。ルートのグループの概念 |
| `isolatedSnapshot()` などの宣言 | 落とした | U-1。`handle()` と `restoreSnapshots()` で指定する |
| `setSnapshotParam()` | 落とした | U-1。`_rosanna_snapshot` に固定 |
| 古い単一スロットの値の読み取り | 落とした | usePHP の旧版からの移行用 |
| 入れ子の Snapshot、`_rosanna_snapshots[]` | 動く | `ActionHandlerTest::testSN13*`〜`testSN19*`、`tests/browser/state.spec.ts` |

### 部分更新

| 機能 | 分類 | 場所、理由 |
|---|---|---|
| 差分適用と欄の値の順 | 動く | `tests/browser/morph.spec.ts` |
| `id` による行の対応 | 動く | `morph.spec.ts` |
| 親族の順番と兄弟の独立 | 動く | `tests/browser/lock.spec.ts`。トークンのないフォームの Session の action だけは兄弟も待つ (JS-25) |
| 固定値の action を捨て、`useFormAction` を再送 | 動く | `lock.spec.ts`、`tests/browser/resend.spec.ts` |
| 失敗を再送しない、`submit-error` | 動く | `tests/browser/failure.spec.ts` |

### コンポーネントと hooks

| 機能 | 分類 | 場所、理由 |
|---|---|---|
| `fc()` | 動く | `tests/Runtime/FunctionComponentTest.php` |
| `fc()` の位置引数の key と保存先 | 動く | `FunctionComponentTest::testRT82KeyCombinations` |
| `H::component()` | 動く | `tests/Runtime/ElementTest.php` の `testRT67*` |
| 状態なしの素のクロージャ | 動く | `RT113ResolverWorksOnBareCallables` (`tests/Runtime/PsxRendererTest.php`) |
| key の合成、連番、`int` | 動く | `FunctionComponentTest::testRT82*`、`RenderContextTest::testRT83*` |
| 同じ ID の共有 | 動く | `FunctionComponentTest::testRT68SameIdSharesState` |
| Session、Memory、Snapshot | 動く | `ReadmeExamplesTest::testTheThreeStorageTypes`、`tests/Storage/StorageTest.php` |
| `persist` | 動く | `tests/Component/ComponentPersistTest.php`、`tests/browser/persist.spec.ts` |
| クラスコンポーネント、`#[Component]` | 動く | `tests/Component/ComponentAttributeTest.php`、`ComponentRegistryTest.php` |
| `#[Component(requireKey:)]` | 落とした | 01 の 10。usePHP でも読まれていない |
| `ComponentId` の階層形式 | 落とした | RT-8.4。使われていない |
| 登録名とクラス名で同じ状態 | 動く | `tests/Runtime/RosannaTest.php` の `testRT121*` |
| `useState`、`null` の保持、型の保持 | 動く | `HooksTest::testRT71*`、`testRT73*`、`ActionTest::testAC4*` |
| `useEffect` | 動く | `HooksTest::testRT78*`、`testRT79*`、`ReadmeExamplesTest::testArrayDepsStartASessionAndNullDepsDoNot` |
| `useFormAction` | 動く | `tests/Runtime/FormActionTest.php`、`ReadmeExamplesTest::testFormInputIsCopiedIntoTheHook` |
| コンポーネントの外の hooks | 動く | 振る舞いを変えた (`LogicException`)。`HooksTest::testRT74a*` |
| コンポーネントの合成 (親の setter を子へ) | 動く | `tests/Runtime/ActionFormPlacementTest.php` の `testRT47*`、`tests/browser/state.spec.ts` |
| `RenderContext::setApp()`、`getApp()` | 落とした | 計画書。`RenderContext::run()`、`scope()` にした |

### HTML と出力

| 機能 | 分類 | 場所、理由 |
|---|---|---|
| `H` のタグ、任意の属性、スプレッド | 動く | `tests/Html/ElementAttributesTest.php` |
| `children` の型 (#118) | 動く | `tests/Html/ElementAttributeTypesTest.php` |
| 条件付きの描画、配列の平坦化 | 動く | `RendererOutputTest::testRT32*` |
| `textarea` の `value` | 動く | `ElementAttributesTest::testRT25*`、`ReadmeExamplesTest::testElementsRenderAsDocumented` |
| 属性の bool、`aria-*` | 動く | `RendererOutputTest::testRT39*` |
| `<style>`、`<script>`、foreign content | 動く | `SecurityHardeningTest::testRT312*`、`testRT314*` |
| 4 つのイベントと未対応イベントの拒否 | 動く | `tests/Runtime/ElementEventsTest.php`、`tests/browser/events.spec.ts` |
| 生成される HTML の共有フォーム | 動く | 形を変えた (#87)。`ActionFormPlacementTest::testRT47*`、`tests/Runtime/HtmlSizeTest.php` |
| `_usephp_action_sig` のフィールド | 落とした | AC-20。エンベロープにまとめた |

### PSX

| 機能 | 分類 | 場所、理由 |
|---|---|---|
| HTML タグ、属性、Boolean 属性 | 動く | `tests/Psx/SyntaxTest.php`、`tests/Psx/Integration/CompiledOutputTest.php` |
| `data-*`、`aria-*`、属性名の変換 | 動く | `CompiledOutputTest::testRenderedHtml` |
| エンティティ | 動く | `CompiledOutputTest::testEntityDecodedAttributeValueIsEscapedOnOutput` |
| テキスト、補間、式の埋め込み | 動く | `tests/Psx/SyntaxTest.php`、`CompiledOutputTest::testLoneExpressionListIsNotNested` |
| `&&`、`\|\|` | 動く | `tests/Psx/OperatorLoweringTest.php` |
| コメント | 動く | `tests/Psx/SyntaxTest.php` |
| `<style>`、`<script>` | 動く | `CompiledOutputTest::testRawContentIsASingleChild`、`tests/Psx/SyntaxTest.php` の `testRawTextEscapesForPhp` |
| コンポーネントタグ、`children` | 動く | `tests/Psx/Integration/ComponentRenderingTest.php` |
| 名前解決、`use`、エイリアス | 動く | `tests/Psx/NamespaceContextTest.php` |
| Fragment、自己終了 | 動く | `tests/Psx/SyntaxTest.php` の `testGeneratedCode` |
| `<` の判定 | 動く | `tests/Psx/PreProcessorTest.php`。`docs/PSX.md` の「`< div>` もタグ」は実装と違う記述で、Rosanna でも usePHP の実測でもエラー |
| `{...式}` (#119) | 動く | `ComponentRenderingTest::testComponentSpreadChildren`、`tests/Psx/SyntaxTest.php` |
| private ヘルパー | 動く | 手元 (`{array_map($helper, $xs)}` と `use function ...\{fc, useState}`) |
| `registerComponent()`、`@psx-runtime` | 動く | `tests/Psx/CompileCommandTest.php` の `testPsxRuntime*`、`PsxRendererTest::testRT113*` |
| `renderPsxComponent()` の公開メソッド | 落とした | `RenderContext::scope()->psx->render()` にした (CP-74) |
| `<defer>` の文字列の属性 | 落とした | 03 の 1。コンパイルエラーにした |
| `CompilerInterface` などの差し替え | 落とした | 03 の 1 節。利用者がいない |
| `compile`、`--check`、`--clean`、`--watch`、`--cache` | 動く | `tests/Psx/CompileCommandTest.php`、`tests/Psx/WatchTest.php`、`tests/Installer/PublishTest.php` の `testCP14*` |
| 未解決と重複の検出 | 動く | `CompileCommandTest::testUnresolvedReferenceExitsOne`、`testDuplicateFqcnShowsBothSourcePaths` |
| 小文字のファイルを manifest に載せない | 動く | `CompileCommandTest::testLowercaseFilesAreCompiledButNotInTheManifest` |
| キャッシュの再利用 | 動く | `CompileCommandTest::testSecondRunSkipsUnchangedFiles` |
| manifest、`parameters` | 動く | `tests/Psx/ManifestTest.php` |
| 文字列だけの manifest の項目 | 落とした | 03 の 8 節。再コンパイルが必須 |
| `CompileCommand::MANIFEST_FILENAME` | 落とした | `PsxRenderer` に移した (CP-137)。`CompileCommand` に同じ名前は残していない |
| 行番号の保持 | 動く | `tests/Psx/CompilerTest.php` の `testThrowableLineMatchesTheSource` |
| `StackTraceRewriter` | 動く | `ComponentRenderingTest::testStackTraceRewriterReadsTheMeta` |
| `installPsxErrorHandler()` | 落とした | 分割設計で削除 |
| エディタの構文定義 (`editors/`) | 未対応 | ファイルはあり JSON は読める (CP-160〜163)。エディタで開いて確かめていない |
| JSON のソースマップ、PHPStan 拡張、複数の公開コンポーネント | 落とした | usePHP でも未実装 |

### defer

| 機能 | 分類 | 場所、理由 |
|---|---|---|
| `fc(defer:)`、`#[Defer]` | 動く | `tests/Component/DeferTest.php`、`ReadmeExamplesTest::testDeferredComponentsAreFetchedFromTheirEndpoints` |
| deferred manifest の自動登録 | 動く | `tests/Psx/Integration/DeferredManifestTest.php` |
| `handleDeferred()`、`setDeferPrefix()` | 動く | `tests/Protocol/DeferredEndpointTest.php` |
| コンポーネントごとの `Cache-Control` | 動く | `DeferredEndpointTest::testDF5*` |
| 共有キャッシュとセッション | 動く | `DeferEndpointFlowTest::testDF8*` |
| props のクエリへの変換 | 動く | `DeferTest::testBuildPlaceholderForwardsOptionsAndStripsFrameworkProps`、`DeferredEndpointTest::testDF4*` |
| 2 段のキャッシュ、`localCacheTtl` | 動く | `tests/js/defer-cache.test.mjs`、`tests/browser/defer.spec.ts` |
| `clearDeferCache()`、`DEFER_CACHE_VERSION` | 動く | `defer.spec.ts` |
| `reloadable`、`reloadDefer()`、`data-rosanna-reload-defer` | 動く | `defer.spec.ts` |
| 入れ子の defer | 動く | `DeferredEndpointTest::testDF6*`、`ActionHandlerTest::testHD9*` |
| インラインの fallback、nonce | 動く | `tests/Runtime/ClientScriptTest.php`、`defer.spec.ts` |

### セキュリティの機能と CLI

| 機能 | 分類 | 場所、理由 |
|---|---|---|
| `setSnapshotSecret()` | 動く | `RosannaTest::testSN4*`、`testSN9*` |
| `disableCsrfProtection()`、`getCsrfToken()` | 動く | `$app->csrf()->token()` に変えた。`CsrfGuardTest` |
| 署名付き action、`signAction()` | 動く | `$app->signer()` に変えた。`tests/Protocol/ActionSignerTest.php` |
| `trustProxyHeaders()` | 動く | `CsrfGuardTest::testCS7*` |
| 同一オリジンの redirect | 動く | `SnapshotUrlTest::testSN20*`、`ActionHandlerTest::testHD13*` |
| 遅延フェッチのガード | 動く | `defer.spec.ts`、`tests/js/urls.test.mjs` |
| URL 属性、タグ名、属性名の検証 | 動く | `SecurityHardeningTest` |
| `publish`、`help`、未知のコマンド | 動く | `PublishTest`。未知のコマンドは exit 1 に変えた |
| #80 (古い画面の送信) | 動く | `SessionActionFlowTest::testVR4*`、`tests/browser/version.spec.ts`。既知の制約から外れた |
| #87 (HTML のサイズ) | 動く | `HtmlSizeTest`。残る制約は README の既知の制約 |
| 秘密鍵ありの最初の action の後のフォーム | 動く | `SessionActionFlowTest::testHD16*`、`tests/browser/session-start.spec.ts`。usePHP は描画でセッションを始めていたので起きなかった。Rosanna は ST-2 を保つので、ページを取り直す (02 の U-7)。残る制約は README の既知の制約 |

## ブラウザのテストの状態

手元の Chromium 155 で全件を実行し、182 件すべてが通った (`ROSANNA_CHROMIUM_PATH=/bin/chromium npx playwright test`)。Firefox と WebKit では実行していない。
