import { readFileSync } from 'node:fs';
import { test as base, expect } from '@playwright/test';

export { expect };

// Every journey also fails on any error a page logs to the browser console, so broken scripts can't slip through.
export const test = base.extend({
  page: async ({ page }, use) => {
    const errors = [];
    page.on('pageerror', (error) => errors.push(`${page.url()}: ${error.message}`));
    page.on('console', (message) => {
      if (message.type() === 'error') errors.push(`${page.url()}: ${message.text()}`);
    });
    await use(page);
    expect(errors, 'browser console errors').toEqual([]);
  },
});

// A fresh email address for each run, so journeys never collide.
export const uniqueEmail = (name) => `${name}-${Date.now()}-${Math.floor(Math.random() * 1e6)}@example.test`;

// The newest link in the e2e mail log (mail is logged, not sent) whose URL contains the given text.
export const mailedLink = (contains) => {
  const log = readFileSync(new URL('../storage/logs/e2e.log', import.meta.url), 'utf8')
    .replace(/=\r?\n/g, '')
    .replace(/=3D/g, '=');
  const links = [...log.matchAll(/https?:\/\/[^\s"'<>\]]+/g)].map((match) => match[0].replace(/&amp;/g, '&'));
  const link = links.reverse().find((url) => url.includes(contains));
  if (!link) throw new Error(`No mailed link containing ${contains}`);
  return link;
};

// Fill in and submit the sign-in form.
export const signIn = async (page, email, password) => {
  await page.getByRole('textbox', { name: 'Email address' }).fill(email);
  await page.getByRole('textbox', { name: 'Password', exact: true }).fill(password);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
};

// Sign up through the form and follow the emailed verification link.
export const signUp = async (page, { name = 'Erin Example', email = uniqueEmail('erin'), password = 'correct-horse-battery-9' } = {}) => {
  await page.goto('/register');
  await page.getByRole('textbox', { name: 'Your name' }).fill(name);
  await page.getByRole('textbox', { name: 'Work email' }).fill(email);
  await page.getByRole('textbox', { name: 'Password', exact: true }).fill(password);
  await page.getByRole('textbox', { name: 'Confirm password' }).fill(password);
  await page.getByRole('button', { name: 'Create account' }).click();
  await expect(page).toHaveURL(/email\/verify|dashboard/);
  await page.goto(mailedLink('/email/verify/'));
  await expect(page).toHaveURL(/dashboard|onboarding|projects/);
  return { name, email, password };
};
