/** v2.4 Coach desktop screens, responsive regressions and existing schedule editor contracts. */
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
const pw = await import(process.env.PLAYWRIGHT_MODULE || "playwright");
const base = process.env.QA_BASE_URL || "http://localhost:8000";
assert.ok(
    ["http://localhost:8000", "https://training.ahmaddalao.com"].includes(base),
);
const out = process.env.QA_OUTPUT_DIR,
    engine = process.env.QA_BROWSER || "chromium";
const fixture = JSON.parse(
    fs.readFileSync(path.join(out, "desktop-fixtures.json")),
)[engine];
const accounts = JSON.parse(fs.readFileSync(process.env.QA_ACCOUNTS_FILE));
const account = accounts[fixture.coach];
assert.match(account.email, /^qa-v200-/);
const browser = await pw[engine].launch(),
    context = await browser.newContext({ serviceWorkers: "block" }),
    page = await context.newPage();
page.setDefaultTimeout(20000);
const checks = [],
    errors = [];
page.on("pageerror", (e) => errors.push(`${page.url()}: ${e.message}`));
const pass = (name) => {
    checks.push(name);
    console.log("PASS", name);
};
const prefix = "/coach/trainees/" + fixture.trainee_id;
const button = (name) =>
    page.getByRole("button", { name, exact: true }).locator("visible=true");
async function matrix(name, key = false) {
    for (const [width, height] of [
        [1280, 800],
        [1366, 768],
        [1440, 900],
        [1920, 1080],
        ...(key ? [[2560, 1440]] : []),
        [320, 480],
        [360, 800],
        [375, 812],
        [390, 844],
        [430, 900],
        [768, 1024],
    ]) {
        await page.setViewportSize({ width, height });
        await page.evaluate(
            () =>
                new Promise((r) =>
                    requestAnimationFrame(() => requestAnimationFrame(r)),
                ),
        );
        assert.ok(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth + 1,
            ),
            name + " page overflow " + width,
        );
        assert.equal(
            await page.locator("a button, button button, label button").count(),
            0,
            name + " nested controls",
        );
        if (width >= 1280) {
            const main = await page.locator("main").boundingBox(),
                header = (await page.locator(".trainee-context").count())
                    ? await page.locator(".trainee-context").boundingBox()
                    : null;
            assert.ok(
                main.width >= width * 0.73,
                name + " useful desktop width",
            );
            if (header)
                assert.ok(
                    header.width >= main.width * 0.8,
                    name + " trainee header width",
                );
        }
        if ([1280, 1440, 1920, 2560].includes(width)) {
            await page.evaluate(() => window.scrollTo(0, 0));
            await page.screenshot({
                path: path.join(out, `${engine}-desktop-${name}-${width}.png`),
                fullPage: true,
            });
        }
        if (width === 320 && key)
            await page.screenshot({
                path: path.join(out, `${engine}-desktop-${name}-${width}.png`),
                fullPage: true,
            });
        pass(
            `${name}: ${width}x${height}, readable page width and no overflow`,
        );
    }
    await page.setViewportSize({ width: 1440, height: 900 });
}
async function go(segment) {
    await page.waitForLoadState("networkidle");
    await page.goto(base + segment);
    await page.locator("h1").waitFor();
    assert.equal(await page.locator(".coach-workspace").count(), 1);
}
async function previewImage() {
    const trigger = page
        .getByRole("button", { name: /View full image/ })
        .first();
    await trigger.click();
    const dialog = page.getByRole("dialog").last();
    await dialog
        .locator(".plan-image-preview img")
        .evaluate((img) => img.decode());
    assert.equal(
        await dialog.getByRole("link", { name: /Open original/ }).count(),
        1,
    );
    await page.keyboard.press("Escape");
    await dialog.waitFor({ state: "detached" });
    await page.waitForFunction(
        (el) => document.activeElement === el,
        await trigger.elementHandle(),
    );
    pass("Full image opens, original link works and Escape returns focus");
}
try {
    await page.goto(base + "/login");
    await page.getByLabel("Email address").fill(account.email);
    await page.getByLabel("Password", { exact: true }).fill(account.password);
    await button("Log in").click();
    await page.waitForURL(/\/(coach|dashboard)$/);
    await go("/coach");
    await matrix("overview", true);
    const upcoming = page
        .locator("section")
        .filter({
            has: page.getByRole("heading", {
                name: "Upcoming · Next 7 days",
                exact: true,
            }),
        })
        .first();
    const links = await upcoming
        .locator(".coach-session-copy > a")
        .evaluateAll((xs) => xs.map((x) => x.getAttribute("href")));
    assert.equal(new Set(links).size, links.length);
    pass("Upcoming has one row per trainee");
    await go("/coach/trainees");
    await matrix("trainees", true);
    await page.getByLabel("Search connected trainees").fill("Amira");
    await page.waitForResponse(
        (r) => r.url().includes("/coach/trainees?") && r.status() === 200,
    );
    assert.equal(await page.locator(".trainee-table tbody tr").count(), 1);
    pass("Live trainee search needs no submit button");
    await go(prefix);
    await matrix("trainee-overview", true);
    assert.equal(
        await page
            .getByRole("heading", { name: "Current Training", exact: true })
            .count(),
        1,
    );
    await previewImage();
    await go(prefix + "/training-plans");
    await matrix("training", true);
    assert.equal(await page.locator(".coach-current-training").count(), 1);
    await go("/coach/training-plans");
    await matrix("programs", true);
    await previewImage();
    await go("/coach/training-plans/" + fixture.template_id);
    await matrix("program-detail");
    await go("/coach/training-plans/" + fixture.template_id + "/edit");
    await matrix("program-builder", true);
    await go(prefix + "/setup?assign=1");
    await matrix("choose-training");
    await go(prefix + "/setup?assign=1&template=" + fixture.template_id);
    await page
        .getByRole("heading", { name: "Schedule Training", exact: true })
        .waitFor();
    await matrix("assignment-schedule", true);
    await button("Continue to Review").click();
    await page
        .getByRole("heading", { name: "Review Assignment", exact: true })
        .waitFor();
    await matrix("assignment-review", true);
    await go(prefix + "/program-runs/" + fixture.run_id + "?schedule=1");
    await matrix("edit-schedule", true);
    const build = page.locator(".coach-schedule-definition details").filter({
        has: page.locator("summary").filter({ hasText: /Build · Weeks/ }),
    });
    await build.locator("summary").click();
    await build
        .getByRole("button", { name: "Change", exact: true })
        .first()
        .click();
    const edit = page.getByRole("dialog").last();
    const timeInput = edit.getByLabel("Workout time (optional)");
    await timeInput.fill(
        (await timeInput.inputValue()) === "20:00" ? "19:30" : "20:00",
    );
    await edit
        .getByRole("button", { name: "Save weekly schedule", exact: true })
        .click();
    await edit.waitFor({ state: "detached" });
    await button("Review changes").click();
    const review = page.getByRole("dialog").last();
    await review.waitFor();
    assert.ok(
        (await review.locator(".coach-session").count()) > 0,
        "Changed weekly time produces actual updated dates",
    );
    assert.equal(
        await review.locator("details.schedule-advanced[open]").count(),
        0,
    );
    assert.ok((await review.innerText()).includes("protected, untouched"));
    await review
        .getByRole("button", { name: "Confirm changes", exact: true })
        .click();
    await review.waitFor({ state: "detached" });
    pass(
        "Future weekly time edit uses signed preview and confirmation; protected sessions stay separate",
    );
    await go(prefix + "/calendar");
    await matrix("calendar", true);
    await button("Add Workout").click();
    await page.waitForURL(/\/schedule-entries\/create/);
    await page.getByLabel("Program block", { exact: true }).waitFor();
    assert.equal(
        await page
            .getByLabel("Workout", { exact: true })
            .locator("option")
            .count(),
        6,
    );
    const labels = await page
        .getByLabel("Workout", { exact: true })
        .locator("option")
        .allTextContents();
    assert.equal(new Set(labels).size, 6);
    pass("12-week program shows five authored workout choices per block");
    await page.keyboard.press("Escape");
    await go(prefix + "/schedule-entries/create");
    await matrix("add-workout", true);
    await page
        .getByLabel("Workout", { exact: true })
        .selectOption({ index: 1 });
    await button("Save to calendar").click();
    await page.waitForURL(/\/schedule-entries\/\d+$/);
    pass(
        "Add Workout creates one manual session through the existing endpoint",
    );
    await go(prefix + "/training-history");
    await matrix("history");
    await go(prefix + "/progress");
    await matrix("progress", true);
    assert.equal(await page.locator(".coach-current-training").count(), 1);
    assert.equal(await page.locator(".program-run-summary").count(), 0);
    await previewImage();
    pass(
        "Progress has one authoritative Current Training and separate program-history navigation",
    );
    await go("/coach/settings");
    assert.equal(await page.locator(".personal-workspace").count(), 0);
    pass("Profile & Settings stays in Coach Workspace");
    assert.deepEqual(errors, []);
    pass("No browser JavaScript errors");
    fs.writeFileSync(
        path.join(out, `coach-desktop-${engine}.json`),
        JSON.stringify(
            { engine, checks, count: checks.length, errors },
            null,
            2,
        ),
    );
} catch (e) {
    await page.screenshot({
        path: path.join(out, `coach-desktop-${engine}-failure.png`),
        fullPage: true,
    });
    throw e;
} finally {
    await browser.close();
}
