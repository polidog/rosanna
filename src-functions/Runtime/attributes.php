<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Internal\Functions\Runtime;

/*
 * ここの関数は @internal で、公開 API ではない。rosanna シリーズ (polidog/rosanna-*) からは使ってよいが、
 * それ以外からは使わない。互換性はシリーズの中でだけ揃える。
 */

/**
 * JSX の属性名を HTML と SVG の属性名にする (RT-3.7)。表にない名前はそのまま返す
 *
 * @internal
 */
function attributeName(string $name): string
{
    return match ($name) {
        'acceptCharset' => 'accept-charset',
        'accessKey' => 'accesskey',
        'allowFullScreen' => 'allowfullscreen',
        'autoCapitalize' => 'autocapitalize',
        'autoComplete' => 'autocomplete',
        'autoCorrect' => 'autocorrect',
        'autoFocus' => 'autofocus',
        'autoPlay' => 'autoplay',
        'autoSave' => 'autosave',
        'cellPadding' => 'cellpadding',
        'cellSpacing' => 'cellspacing',
        'charSet' => 'charset',
        'classID' => 'classid',
        'className' => 'class',
        'colSpan' => 'colspan',
        'contentEditable' => 'contenteditable',
        'contextMenu' => 'contextmenu',
        'controlsList' => 'controlslist',
        'crossOrigin' => 'crossorigin',
        'dateTime' => 'datetime',
        'dirName' => 'dirname',
        'encType' => 'enctype',
        'enterKeyHint' => 'enterkeyhint',
        'fetchPriority' => 'fetchpriority',
        'formAction' => 'formaction',
        'formEncType' => 'formenctype',
        'formMethod' => 'formmethod',
        'formNoValidate' => 'formnovalidate',
        'formTarget' => 'formtarget',
        'frameBorder' => 'frameborder',
        'hrefLang' => 'hreflang',
        'htmlFor' => 'for',
        'httpEquiv' => 'http-equiv',
        'imageSizes' => 'imagesizes',
        'imageSrcSet' => 'imagesrcset',
        'inputMode' => 'inputmode',
        'isMap' => 'ismap',
        'itemID' => 'itemid',
        'itemProp' => 'itemprop',
        'itemRef' => 'itemref',
        'itemScope' => 'itemscope',
        'itemType' => 'itemtype',
        'keyParams' => 'keyparams',
        'keyType' => 'keytype',
        'marginHeight' => 'marginheight',
        'marginWidth' => 'marginwidth',
        'maxLength' => 'maxlength',
        'mediaGroup' => 'mediagroup',
        'minLength' => 'minlength',
        'noModule' => 'nomodule',
        'noValidate' => 'novalidate',
        'playsInline' => 'playsinline',
        'popoverTarget' => 'popovertarget',
        'popoverTargetAction' => 'popovertargetaction',
        'radioGroup' => 'radiogroup',
        'readOnly' => 'readonly',
        'referrerPolicy' => 'referrerpolicy',
        'rowSpan' => 'rowspan',
        'spellCheck' => 'spellcheck',
        'srcDoc' => 'srcdoc',
        'srcLang' => 'srclang',
        'srcSet' => 'srcset',
        'tabIndex' => 'tabindex',
        'useMap' => 'usemap',
        'accentHeight' => 'accent-height',
        'alignmentBaseline' => 'alignment-baseline',
        'arabicForm' => 'arabic-form',
        'baselineShift' => 'baseline-shift',
        'capHeight' => 'cap-height',
        'clipPath' => 'clip-path',
        'clipRule' => 'clip-rule',
        'colorInterpolation' => 'color-interpolation',
        'colorInterpolationFilters' => 'color-interpolation-filters',
        'colorProfile' => 'color-profile',
        'colorRendering' => 'color-rendering',
        'dominantBaseline' => 'dominant-baseline',
        'enableBackground' => 'enable-background',
        'fillOpacity' => 'fill-opacity',
        'fillRule' => 'fill-rule',
        'floodColor' => 'flood-color',
        'floodOpacity' => 'flood-opacity',
        'fontFamily' => 'font-family',
        'fontSize' => 'font-size',
        'fontSizeAdjust' => 'font-size-adjust',
        'fontStretch' => 'font-stretch',
        'fontStyle' => 'font-style',
        'fontVariant' => 'font-variant',
        'fontWeight' => 'font-weight',
        'glyphName' => 'glyph-name',
        'glyphOrientationHorizontal' => 'glyph-orientation-horizontal',
        'glyphOrientationVertical' => 'glyph-orientation-vertical',
        'horizAdvX' => 'horiz-adv-x',
        'horizOriginX' => 'horiz-origin-x',
        'imageRendering' => 'image-rendering',
        'letterSpacing' => 'letter-spacing',
        'lightingColor' => 'lighting-color',
        'markerEnd' => 'marker-end',
        'markerMid' => 'marker-mid',
        'markerStart' => 'marker-start',
        'overlinePosition' => 'overline-position',
        'overlineThickness' => 'overline-thickness',
        'paintOrder' => 'paint-order',
        'panose1' => 'panose-1',
        'pointerEvents' => 'pointer-events',
        'renderingIntent' => 'rendering-intent',
        'shapeRendering' => 'shape-rendering',
        'stopColor' => 'stop-color',
        'stopOpacity' => 'stop-opacity',
        'strikethroughPosition' => 'strikethrough-position',
        'strikethroughThickness' => 'strikethrough-thickness',
        'strokeDasharray' => 'stroke-dasharray',
        'strokeDashoffset' => 'stroke-dashoffset',
        'strokeLinecap' => 'stroke-linecap',
        'strokeLinejoin' => 'stroke-linejoin',
        'strokeMiterlimit' => 'stroke-miterlimit',
        'strokeOpacity' => 'stroke-opacity',
        'strokeWidth' => 'stroke-width',
        'textAnchor' => 'text-anchor',
        'textDecoration' => 'text-decoration',
        'textRendering' => 'text-rendering',
        'transformOrigin' => 'transform-origin',
        'underlinePosition' => 'underline-position',
        'underlineThickness' => 'underline-thickness',
        'unicodeBidi' => 'unicode-bidi',
        'unicodeRange' => 'unicode-range',
        'unitsPerEm' => 'units-per-em',
        'vAlphabetic' => 'v-alphabetic',
        'vHanging' => 'v-hanging',
        'vIdeographic' => 'v-ideographic',
        'vMathematical' => 'v-mathematical',
        'vectorEffect' => 'vector-effect',
        'vertAdvY' => 'vert-adv-y',
        'vertOriginX' => 'vert-origin-x',
        'vertOriginY' => 'vert-origin-y',
        'wordSpacing' => 'word-spacing',
        'writingMode' => 'writing-mode',
        'xHeight' => 'x-height',
        'xlinkActuate' => 'xlink:actuate',
        'xlinkArcrole' => 'xlink:arcrole',
        'xlinkHref' => 'xlink:href',
        'xlinkRole' => 'xlink:role',
        'xlinkShow' => 'xlink:show',
        'xlinkTitle' => 'xlink:title',
        'xlinkType' => 'xlink:type',
        'xmlBase' => 'xml:base',
        'xmlLang' => 'xml:lang',
        'xmlSpace' => 'xml:space',
        'xmlnsXlink' => 'xmlns:xlink',
        default => $name,
    };
}

/**
 * RT-3.8。`/D` がないと `$` が末尾の改行の前にも合う
 *
 * @internal 引数は attributeName() で変換した後の名前
 * @throws \RuntimeException
 */
function assertValidAttributeName(string $name): void
{
    $pattern = '/^[A-Za-z_:][A-Za-z0-9:_.\-]*$/D';
    if (preg_match($pattern, $name) !== 1) {
        throw new \RuntimeException(sprintf('Invalid HTML attribute name %s: attribute names must match %s.', quote($name), $pattern));
    }
    if (preg_match('/^on[a-z]/i', $name) === 1) {
        throw new \RuntimeException(sprintf(
            'Inline event handler attribute %s is not allowed. Event props (onClick, onChange, onInput, onSubmit) need polidog/rosanna-state.',
            quote($name),
        ));
    }
}

/** @internal 引数は小文字にした属性名 */
function isUrlAttribute(string $lowerName): bool
{
    return match ($lowerName) {
        'href', 'src', 'action', 'formaction', 'data', 'poster', 'background', 'xlink:href' => true,
        default => false,
    };
}

/** @internal 引数は小文字にした属性名。true / false を値として書く属性 */
function isBooleanStringAttribute(string $lowerName): bool
{
    return str_starts_with($lowerName, 'aria-') || match ($lowerName) {
        'contenteditable', 'draggable', 'spellcheck' => true,
        default => false,
    };
}

/** @internal 引数は小文字にした属性名。アニメーション要素が対象の属性に入れる値 */
function isAnimationValueAttribute(string $lowerName): bool
{
    return match ($lowerName) {
        'to', 'values', 'from', 'by' => true,
        default => false,
    };
}

/**
 * Chromium は <set attributeName="href" to="javascript:..."> を実行する (tests/browser/svg.spec.ts)。
 * attributeName と属性名の大文字小文字はパーサーが揃えるので区別せず、接頭辞は宣言で決まるので見ない。
 *
 * @internal
 * @param array<array-key, mixed> $props
 */
function targetsHref(array $props): bool
{
    foreach ($props as $key => $value) {
        if (strtolower(attributeName((string) $key)) !== 'attributename' || !is_scalar($value)) {
            continue;
        }
        $target = strtolower(trim((string) $value, "\x00..\x20"));
        $colon = strrpos($target, ':');
        if (($colon === false ? $target : substr($target, $colon + 1)) === 'href') {
            return true;
        }
    }

    return false;
}

/**
 * 大文字小文字を区別しない属性名で、パーサーと同じく最初の値を取る。null は属性にならないので飛ばす。
 *
 * @internal
 * @param array<array-key, mixed> $props
 */
function firstProp(array $props, string $name): mixed
{
    foreach ($props as $key => $value) {
        if ($value !== null && strcasecmp((string) $key, $name) === 0) {
            return $value;
        }
    }

    return null;
}

/**
 * select の value を、比べる文字列の集合にする (RT-3.17)。文字列にするのは属性の出力と同じ。配列は multiple 用で、中の値を読む。
 * null と bool は option の値にならないので何も選ばない。スカラーでない値は RT-3.9 と同じく E_USER_NOTICE で落とす。
 *
 * @internal
 * @return array<array-key, true> キーは文字列にした値
 */
function selectValues(mixed $value): array
{
    $values = [];
    foreach (is_array($value) ? $value : [$value] as $item) {
        if ($item === null || is_bool($item)) {
            continue;
        }
        if (!is_scalar($item)) {
            trigger_error(sprintf('Non-scalar value for attribute "value" (%s) was dropped.', get_debug_type($item)), E_USER_NOTICE);
            continue;
        }
        $values[(string) $item] = true;
    }

    return $values;
}
