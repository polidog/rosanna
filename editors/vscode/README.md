# VS Code の PSX 拡張

`.psx` の TextMate 文法です。PHP の部分は VS Code 組み込みの PHP の文法を使います。

Marketplace には公開していません。ローカルにインストールします。

## インストール

拡張のディレクトリにリンクを作り、VS Code を再起動します。`polidog/rosanna` を clone した場所で実行します。

```sh
ln -s "$PWD/editors/vscode" ~/.vscode/extensions/rosanna-psx
```

VSIX にしたいときは次のとおりです。

```sh
cd editors/vscode
npx @vscode/vsce package --allow-missing-repository
code --install-extension rosanna-psx-0.1.0.vsix
```

## 確かめる

[`../sample.psx`](../sample.psx) を開き、右下の言語が `PSX` になっていることを見ます。スコープはコマンドパレットの
`Developer: Inspect Editor Tokens and Scopes` で確かめられます。

| 対象 | スコープ |
|---|---|
| 組み込みのタグ | `entity.name.tag.psx` |
| コンポーネント | `support.class.component.psx` |
| Fragment | `punctuation.definition.tag.fragment.psx` |
| 属性の名前 | `entity.other.attribute-name.psx` |
| `{}` の中 | `meta.embedded.expression.psx source.php` |
| `style`、`script` の中 | `meta.raw.psx source.css`、`meta.raw.psx source.js` |
