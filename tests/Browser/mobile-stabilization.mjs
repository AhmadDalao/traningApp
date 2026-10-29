/**
 * Run against disposable local QA accounts after building frontend assets.
 * QA_ACCOUNTS_FILE: private JSON [{email,password,type}].
 * QA_TRAINEE_INDEX / QA_COACH_INDEX select the two accounts.
 * PLAYWRIGHT_MODULE may point to an existing Playwright installation.
 * QA_BROWSER=webkit also exercises Safari's dialog/button focus behavior.
 */
import assert from "node:assert/strict";
import fs from "node:fs";
const playwright = await import(process.env.PLAYWRIGHT_MODULE || "playwright");
const accounts = JSON.parse(fs.readFileSync(process.env.QA_ACCOUNTS_FILE));
const browser = await playwright[process.env.QA_BROWSER || "chromium"].launch();
let checks = 0;
function passed(label) {
    checks++;
    console.log("PASS", label);
}
async function login(index, width = 320) {
    const context = await browser.newContext({
        viewport: { width, height: 850 },
    });
    const page = await context.newPage();
    await page.goto("http://localhost:8000/login");
    const fonts = await page
        .locator("input:not([type=checkbox])")
        .evaluateAll((inputs) =>
            inputs.map((input) => getComputedStyle(input).fontSize),
        );
    assert.ok(fonts.every((size) => Number.parseFloat(size) >= 16));
    await page.getByLabel("Email address").fill(accounts[index].email);
    await page
        .getByLabel("Password", { exact: true })
        .fill(accounts[index].password);
    await page.getByRole("button", { name: "Log in", exact: true }).click();
    await page.waitForURL(/\/(dashboard|coach)$/);
    return { page, context };
}
async function noOverflow(page, label) {
    const dimensions = await page.evaluate(() => ({
        width: innerWidth,
        scroll: document.documentElement.scrollWidth,
    }));
    assert.ok(
        dimensions.scroll <= dimensions.width + 1,
        `${label}: ${JSON.stringify(dimensions)}`,
    );
    passed(label);
}
try {
    const { page } = await login(Number(process.env.QA_TRAINEE_INDEX || 0));
    passed("Login inputs remain at least 16px on mobile");
    await page.goto("http://localhost:8000/training-plans");
    const create = page.getByRole("button", {
        name: "Create training plan",
        exact: true,
    });
    await create.focus();
    await create.press("Enter");
    const dialog = page.getByRole("dialog");
    await dialog.waitFor();
    const target = await dialog
        .getByRole("button", { name: "Close dialog" })
        .boundingBox();
    assert.ok(target.width >= 44 && target.height >= 44);
    await dialog.getByRole("button", { name: "Close dialog" }).press("Escape");
    await dialog.waitFor({ state: "hidden" });
    await page.waitForFunction(() =>
        document.activeElement?.textContent.includes("Create training plan"),
    );
    passed("Dialog Escape returns focus to its opener; close target is 44px");
    await page.setViewportSize({ width: 320, height: 480 });
    await create.click();
    await dialog.getByRole("button", { name: "Cancel", exact: true }).click();
    await dialog.waitFor({ state: "hidden" });
    passed("Dialog actions reachable at 320×480");
    await page.getByRole("button", { name: "Toggle menu" }).click();
    await page.locator(".user-menu-toggle").click();
    await page.getByRole("link", { name: "Profile", exact: true }).click();
    await page.waitForURL("**/profile");
    assert.equal(await page.locator(".sidebar.open").count(), 0);
    passed("Short-height sidebar scrolls to Profile and closes on selection");
    const paths = ["/dashboard", "/training-history", "/profile", "/progress"];
    for (const path of paths) {
        await page.goto("http://localhost:8000" + path);
        for (const width of [320, 360, 375, 390, 430, 768, 1280]) {
            await page.setViewportSize({ width, height: 850 });
            await noOverflow(page, `${path} at ${width}px`);
        }
    }
    await page.goto("http://localhost:8000/training-plans");
    await page
        .locator(".plan-card")
        .filter({ hasText: "YOUR TRAINING PLAN" })
        .getByRole("link", { name: "View plan", exact: true })
        .first()
        .click();
    const edit = page.getByRole("button", { name: "Edit plan", exact: true });
    await edit.click();
    await page.getByLabel("Plan name").fill("UnbrokenName".repeat(12));
    for (const width of [320, 768]) {
        await page.setViewportSize({ width, height: 480 });
        await noOverflow(page, `Long plan edit dialog at ${width}px`);
    }
    await page.getByRole("button", { name: "Cancel", exact: true }).click();
    await page.setViewportSize({ width: 320, height: 850 });
    await page.getByLabel("More plan actions").click();
    await page
        .getByRole("button", { name: "Duplicate", exact: true })
        .first()
        .click();
    await page.getByRole("button", { name: "Cancel", exact: true }).click();
    await page.waitForFunction(
        () => document.activeElement?.tagName === "SUMMARY",
    );
    passed("Closing a copy dialog restores focus to the visible menu summary");
    await page
        .getByRole("link", { name: "Add training day", exact: true })
        .click();
    await page
        .getByRole("button", { name: "Add exercise", exact: true })
        .first()
        .click();
    await page.locator(".library-card").first().click();
    await page
        .getByRole("button", { name: "Choose partner from library" })
        .click();
    await page
        .getByRole("button", { name: "Back to exercise", exact: true })
        .click();
    await page.getByRole("button", { name: "Close dialog" }).press("Escape");
    await page.waitForFunction(
        () => document.activeElement?.textContent.trim() === "Add exercise",
    );
    passed(
        "Nested superset library preserves the original exercise-dialog opener",
    );
    const { page: coach } = await login(
        Number(process.env.QA_COACH_INDEX || 1),
    );
    await coach.setViewportSize({ width: 320, height: 480 });
    for (const name of [
        "Personal Workspace",
        "Personal Workspace",
        "Coach Workspace",
        "Coach Workspace",
    ]) {
        await coach.getByRole("button", { name: "Toggle menu" }).click();
        await coach.locator(".user-menu-toggle").click();
        await coach.getByRole("button", { name, exact: true }).click();
        await coach.locator(".sidebar.open").waitFor({ state: "hidden" });
        await coach.waitForURL(
            name === "Personal Workspace" ? "**/dashboard" : "**/coach",
        );
    }
    passed(
        "Workspace switch and selecting the same workspace close mobile navigation",
    );
    console.log(
        `${checks} mobile stabilization checks passed (${process.env.QA_BROWSER || "chromium"}).`,
    );
} finally {
    await browser.close();
}
