// RT-3.17: Renderer の出力でブラウザが実際に選ぶ option
import { expect, test } from './support/fixtures';

const CASES: Array<[string, string[]]> = [
  ['value', ['b']],
  ['optgroup', ['c']],
  ['text', ['Plan B']],
  ['number', ['2']],
  ['multiple', ['a', 'c']],
  // 単一選択で合うものがなければ、ブラウザの既定で最初の option になる
  ['no-match', ['a']],
  ['placeholder-kept', ['']],
  ['placeholder-overridden', ['b']],
  ['explicit-false', ['a']],
];

for (const [name, expected] of CASES) {
  test(`RT-3.17: ${name}`, async ({ page }) => {
    await page.goto(`/select/${name}`);
    const selected = await page.locator('#pick').evaluate((el) => [...(el as HTMLSelectElement).selectedOptions].map((option) => option.value));
    expect(selected).toEqual(expected);
  });
}
