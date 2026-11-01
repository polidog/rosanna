// examples/ のサンプルを、README に書いたとおり router.php で動かして確かめる
import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';
import { expect, test as base } from './support/fixtures';
import { startPhpServer, type PhpServer } from './support/php-server';

const ROOT = resolve(__dirname, '../..');

const test = base.extend<{}, { server: PhpServer }>({
  server: [
    async ({}, use) => {
      execFileSync('php', ['bin/rosanna', 'compile', 'examples/psx/components', '--cache=examples/psx/var/cache/psx'], { cwd: ROOT });
      const server = await startPhpServer(resolve(ROOT, 'examples/router.php'));
      await use(server);
      await server.stop();
    },
    { scope: 'worker' },
  ],
});

test('一覧からすべてのサンプルに行ける', async ({ page }) => {
  await page.goto('/');
  await expect(page.locator('li a')).toHaveCount(2);
  await page.getByRole('link', { name: 'psx' }).click();
  await expect(page.locator('h1')).toHaveText('PSX');
});

for (const path of ['/', '/h', '/psx']) {
  test(`${path}: 状態層の痕跡がない (Cookie、data-rosanna、スクリプト)`, async ({ page, context }) => {
    const response = await page.goto(path);
    expect(response?.status()).toBe(200);
    expect(response?.headers()['set-cookie']).toBeUndefined();
    expect(await context.cookies()).toEqual([]);

    const html = await response?.text();
    expect(html).not.toContain('data-rosanna');
    expect(html).not.toContain('_rosanna_action');
    await expect(page.locator('script')).toHaveCount(0);
  });
}

test('h: クラスを子に置いて描画し、クエリで絞り込める', async ({ page }) => {
  await page.goto('/h');
  await expect(page.locator('#count')).toHaveText('4 found');
  await expect(page.locator('tr').first()).toContainText('Rosanna');
  await expect(page.locator('tr').first()).toContainText('1982');

  await page.getByLabel('Search').fill('africa');
  await page.getByRole('button', { name: 'Go' }).click();
  await expect(page).toHaveURL(/\/h\?q=africa$/);
  await expect(page.locator('#count')).toHaveText('1 found');
  await expect(page.locator('tr')).toHaveCount(1);
  await expect(page.getByLabel('Search')).toHaveValue('africa');
});

test('psx: 入れ子のコンポーネントが描画される', async ({ page }) => {
  await page.goto('/psx');
  await expect(page.locator('h2')).toHaveText('Toto IV');
  await expect(page.locator('#count')).toHaveText('3 songs');
  await expect(page.locator('li').first()).toHaveText('Rosanna');
});

for (const path of ['/h', '/psx']) {
  test(`${path}: 値のなかのタグは文字として出て、実行されない`, async ({ page }) => {
    await page.goto(path);
    await expect(page.getByText('<script>window.__pwned = true</script>')).toBeVisible();
    expect(await page.evaluate(() => (window as unknown as { __pwned?: boolean }).__pwned ?? false)).toBe(false);
  });
}
