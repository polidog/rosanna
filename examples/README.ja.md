# Rosanna のサンプル

[English](README.md)

状態を持たない描画のサンプルです。セッションも JavaScript も使わず、出力に `data-rosanna` は付きません。hooks と action は [polidog/rosanna-state](https://github.com/polidog/rosanna-state) のサンプルを見てください。

## 動かし方

リポジトリのルートで実行します。PHP 8.5 以上が要ります。

```bash
composer install
bin/rosanna compile examples/psx/components --cache=examples/psx/var/cache/psx   # psx を動かすときだけ
php -S localhost:8000 examples/router.php
```

http://localhost:8000 を開くと一覧が出ます。

## サンプル

| サンプル | 何を示すか | 主な API |
|---|---|---|
| [h](h/index.php) | PHP でページを組み立てる。クラスを子に置き、クエリ文字列は host が読む | `H`、`Renderable`、`new Rosanna()`、`renderElement` |
| [psx](psx/) | タグで書く。コンポーネントを入れ子にする | `.psx`、`rosanna compile`、`loadComponentManifest`、`scope` |

[router.php](router.php) はサンプルを並べるためのものです。自分のアプリでは host のルーターかページごとの PHP ファイルが同じ役割を持ちます。

## テスト

`tests/browser/examples.spec.ts` が本物のブラウザで両方のサンプルを開きます。

```bash
npm ci && npx playwright install chromium
npx playwright test tests/browser/examples.spec.ts
```
