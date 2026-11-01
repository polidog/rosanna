# Vim、Neovim の PSX 構文

`*.psx` を `filetype=psx` にして色分けします。Vim script だけで書いてあり、外部の依存はありません。PHP の部分は標準の
`syntax/php.vim` を使います。

## インストール

`editors/nvim` を `runtimepath` に足します。

### lazy.nvim

```lua
{
  'polidog/rosanna',
  ft = 'psx',
  init = function()
    vim.filetype.add({ extension = { psx = 'psx' } })
  end,
  config = function(plugin)
    vim.opt.runtimepath:append(plugin.dir .. '/editors/nvim')
    -- FileType はこの時点で発火済みなので、開いているバッファに当て直す
    vim.cmd('runtime! syntax/psx.vim')
    vim.cmd('runtime! ftplugin/psx.vim')
  end,
}
```

### packer.nvim

```lua
use({ 'polidog/rosanna', rtp = 'editors/nvim' })
```

### 手で入れる

```sh
git clone https://github.com/polidog/rosanna.git ~/src/rosanna
```

```vim
" init.vim か .vimrc
set runtimepath+=~/src/rosanna/editors/nvim
```

## 確かめる

[`../sample.psx`](../sample.psx) を開いて `:set filetype?` が `filetype=psx` であることを見ます。

| 対象 | ハイライトグループ |
|---|---|
| 組み込みのタグ | `psxTag` |
| コンポーネント | `psxComponent` |
| Fragment | `psxFragmentTag` |
| 属性の名前 | `psxAttrName` |
| `{}` の括弧 | `psxBraces` |
| `style`、`script` の中 | `psxRawStyle`、`psxRawScript` |

## 制約

子のテキストに `/>` をそのまま書くと、そこでタグが閉じたものとして色が付きます。`&gt;` と書いてください。
