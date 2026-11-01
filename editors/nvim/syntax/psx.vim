" PSX の構文ハイライト。docs/spec/03-compiler.md の §2 が定義。
" PHP の部分は標準の syntax/php.vim に任せ、phpRegion の中にタグの規則を足す。

if exists('b:current_syntax')
  finish
endif

runtime! syntax/php.vim
unlet! b:current_syntax

syntax case match
syntax sync fromstart

syntax cluster psxMarkup contains=psxElement,psxFragment,psxRawStyle,psxRawScript
syntax cluster psxChildren contains=@psxMarkup,psxExpr,psxEntity

" タグ名の次の位置から `>` までが属性。子のテキストを属性として色付けしない
syntax region psxAttrs contained
      \ start=+\%(<[A-Za-z][A-Za-z0-9_-]*\)\@<=+
      \ end=+\ze/>+
      \ matchgroup=psxPunct end=+>+
      \ contains=psxAttrName,psxAttrEquals,psxAttrString,psxAttrStringSingle,psxExpr
syntax match psxAttrName contained /[A-Za-z_][A-Za-z0-9_-]*/
syntax match psxAttrEquals contained /=/
syntax region psxAttrString contained start=+"+ skip=+\\[\\"]+ end=+"+ contains=psxEntity
syntax region psxAttrStringSingle contained start=+'+ skip=+\\[\\']+ end=+'+ contains=psxEntity
syntax match psxEntity contained /&\%([A-Za-z][A-Za-z0-9]*\|#[0-9]\+\|#[xX][0-9A-Fa-f]\+\);/

" `$a < $b`、`$a<b` をタグにしない。`<` の直前と、タグ名の直後で見分ける
syntax region psxElement containedin=phpRegion keepend extend
      \ matchgroup=psxTag
      \ start=+[[:alnum:]_)\]$<]\@1<!<\z([a-z][A-Za-z0-9_-]*\)\%(\_s*/\?>\|\_s\+[A-Za-z_]\)\@=+
      \ end=+/>+
      \ end=+</\z1\s*>+
      \ contains=psxAttrs,@psxChildren
syntax region psxElement containedin=phpRegion keepend extend
      \ matchgroup=psxComponent
      \ start=+[[:alnum:]_)\]$<]\@1<!<\z([A-Z][A-Za-z0-9_-]*\)\%(\_s*/\?>\|\_s\+[A-Za-z_]\)\@=+
      \ end=+/>+
      \ end=+</\z1\s*>+
      \ contains=psxAttrs,@psxChildren

syntax region psxFragment containedin=phpRegion keepend extend
      \ matchgroup=psxFragmentTag start=+<>+ end=+</>+
      \ contains=@psxChildren

" {} の中の PHP。文字列とコメントの中の括弧は php.vim の規則が先に取る
syntax region psxExpr contained keepend extend
      \ matchgroup=psxBraces start=+{+ end=+}+
      \ contains=psxExprNest,@psxMarkup,@phpClTop
syntax region psxExprNest contained transparent extend
      \ start=+{+ end=+}+
      \ contains=psxExprNest,@psxMarkup,@phpClTop

" style と script の中身は式として扱わない
syntax region psxRawStyle containedin=phpRegion keepend extend
      \ matchgroup=psxTag
      \ start=+<style\%([A-Za-z0-9_-]\)\@!+
      \ end=+/>+
      \ end=+</style\s*>+
      \ contains=psxAttrs
syntax region psxRawScript containedin=phpRegion keepend extend
      \ matchgroup=psxTag
      \ start=+<script\%([A-Za-z0-9_-]\)\@!+
      \ end=+/>+
      \ end=+</script\s*>+
      \ contains=psxAttrs

highlight default link psxTag Statement
highlight default link psxComponent Type
highlight default link psxPunct Delimiter
highlight default link psxAttrs Delimiter
highlight default link psxFragmentTag Delimiter
highlight default link psxAttrName Identifier
highlight default link psxAttrEquals Operator
highlight default link psxAttrString String
highlight default link psxAttrStringSingle String
highlight default link psxEntity SpecialChar
highlight default link psxBraces Special
highlight default link psxRawStyle String
highlight default link psxRawScript String

let b:current_syntax = 'psx'
