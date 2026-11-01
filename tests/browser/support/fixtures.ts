import { test as base, expect } from '@playwright/test';
import { startPhpServer, type PhpServer } from './php-server';

export { expect };

interface TestFixtures {
  noPageErrors: void;
  noPhpErrors: void;
}

export const test = base.extend<TestFixtures, { server: PhpServer }>({
  server: [
    async ({}, use) => {
      const server = await startPhpServer();
      await use(server);
      await server.stop();
    },
    { scope: 'worker' },
  ],

  baseURL: async ({ server }, use) => {
    await use(server.url);
  },

  noPageErrors: [
    async ({ context }, use) => {
      const errors: string[] = [];
      context.on('weberror', (error) => errors.push(error.error().message));
      await use();
      expect(errors).toEqual([]);
    },
    { auto: true },
  ],

  noPhpErrors: [
    async ({ server }, use) => {
      const before = server.log().length;
      await use();
      const problems = server.log().slice(before).split('\n')
        .filter((line) => /PHP (Fatal|Warning|Parse|Deprecated|Notice)/.test(line));
      expect(problems).toEqual([]);
    },
    { auto: true },
  ],
});
