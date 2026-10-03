import { test, expect, signIn, signUp } from './support.js';

test('a visitor browses the public site and the pricing', async ({ page }) => {
  await page.goto('/');
  await expect(page).toHaveTitle(/BuildPusher/);
  await page.goto('/pricing');
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
  await page.goto('/roadmap');
  await expect(page.getByRole('heading', { name: 'What we’re building' })).toBeVisible();
  await page.goto('/changelog');
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
});

test('someone signs up, verifies their email and creates a project', async ({ page }) => {
  await signUp(page);
  await page.goto('/projects/create');
  const form = page.getByRole('main');
  await form.getByRole('textbox', { name: 'Project name' }).fill('Marketing site');
  await form.getByRole('button', { name: 'Create project' }).click();
  await expect(page.getByRole('heading', { name: 'Setup guide', level: 1 })).toBeVisible();
  await expect(page.getByText('Project created.')).toBeVisible();
  await page.goto('/dashboard');
  await expect(page.getByRole('main').getByText('Marketing site').first()).toBeVisible();
});

test('someone signs out and back in', async ({ page }) => {
  const person = await signUp(page);
  await page.goto('/dashboard');
  await page.context().clearCookies();
  await page.goto('/dashboard');
  await expect(page).toHaveURL(/login/);
  await signIn(page, person.email, person.password);
  await expect(page).toHaveURL(/dashboard/);
});

test('a wrong password is refused', async ({ page }) => {
  await page.goto('/login');
  await signIn(page, 'nobody@example.test', 'not-the-password');
  await expect(page).toHaveURL(/login/);
  await expect(page.getByText(/credentials|incorrect|match/i).first()).toBeVisible();
});
