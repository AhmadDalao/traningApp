/** Program lifecycle against disposable QA accounts only. Build assets before running. */
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
const playwright = await import(process.env.PLAYWRIGHT_MODULE || "playwright");
const accounts = JSON.parse(fs.readFileSync(process.env.QA_ACCOUNTS_FILE));
const account = accounts[Number(process.env.QA_TRAINEE_INDEX || 2)];
assert.match(account.email, /^qa-v200-/);
const base = process.env.QA_BASE_URL || "http://localhost:8000";
assert.ok(
    ["http://localhost:8000", "https://training.ahmaddalao.com"].includes(base),
);
const output = process.env.QA_OUTPUT_DIR || "storage/app/private/v200-qa";
fs.mkdirSync(output, { recursive: true });
const browser = await playwright[process.env.QA_BROWSER || "chromium"].launch();
const context = await browser.newContext({
    viewport: { width: 320, height: 850 },
});
const page = await context.newPage();
page.setDefaultTimeout(7000);
const problems = [],
    checks = [],
    urls = {};
page.on("pageerror", (e) => problems.push(e.message));
page.on("console", (m) => {
    if (["error", "warning"].includes(m.type())) problems.push(m.text());
});
page.on("response", (r) => {
    if (r.status() >= 400) problems.push(`${r.status()} ${r.url()}`);
});
const pass = (label) => {
    checks.push(label);
    console.log("PASS", label);
};
async function matrix(label, screenshot = false) {
    for (const width of [320, 360, 375, 390, 430, 768, 1280]) {
        await page.setViewportSize({ width, height: 850 });
        await page.evaluate(
            () =>
                new Promise((resolve) =>
                    requestAnimationFrame(() => requestAnimationFrame(resolve)),
                ),
        );
        const dims = await page.evaluate(() => ({
            width: innerWidth,
            scroll: document.documentElement.scrollWidth,
        }));
        assert.ok(
            dims.scroll <= dims.width + 1,
            `${label} ${width}: ${JSON.stringify(dims)}`,
        );
        pass(`${label}: ${width}px, no page overflow`);
    }
    await page.setViewportSize({ width: 320, height: 850 });
    if (screenshot)
        await page.screenshot({
            path: path.join(
                output,
                `${process.env.QA_BROWSER || "chromium"}-${label}.png`,
            ),
            fullPage: true,
        });
}
const date = (offset) => {
    const d = new Date();
    d.setDate(d.getDate() + offset);
    return d.toLocaleDateString("en-CA", { timeZone: "Asia/Riyadh" });
};
try {
    await page.goto(base + "/login");
    await page.getByLabel("Email address").fill(account.email);
    await page.getByLabel("Password", { exact: true }).fill(account.password);
    await page.getByRole("button", { name: "Log in", exact: true }).click();
    await page.waitForURL("**/dashboard");
    await page.goto(base + "/recommendations");
    await page.getByRole("button", { name: /^Filters/ }).click();
    await page.getByLabel("Type").selectOption("program");
    await page.getByRole("button", { name: "Show 4 plans" }).click();
    assert.equal(
        await page
            .locator('[aria-labelledby="all-plans-title"] .discovery-card')
            .count(),
        4,
    );
    await matrix("program-discovery", true);
    await page
        .getByRole("link", {
            name: "Preview 8-Week Beginner Strength",
            exact: true,
        })
        .first()
        .click();
    await page
        .getByRole("heading", { name: "8-Week Beginner Strength", exact: true })
        .waitFor();
    await matrix("catalog-preview", true);
    await page
        .getByRole("button", { name: "Use this program", exact: true })
        .first()
        .click();
    await page.waitForURL(/\/training-plans\/\d+$/);
    urls.catalogProgram = new URL(page.url()).pathname;
    assert.equal(
        await page
            .getByRole("heading", { name: "Program runs", exact: true })
            .count(),
        0,
    );
    pass("Catalog program creates an editable definition without starting it");

    await page.goto(base + "/training-plans");
    await page
        .getByRole("button", { name: "Create training plan", exact: true })
        .click();
    const dialog = page.getByRole("dialog");
    await dialog.getByLabel("Create").selectOption("program");
    const name = `QA mobile program ${Date.now()}`;
    await dialog.getByLabel("Plan name").fill(name);
    await dialog.getByLabel("Program duration (weeks)").fill("1");
    await matrix("create-program");
    await dialog
        .getByRole("button", { name: "Create plan", exact: true })
        .click();
    await page.waitForURL(/\/training-plans\/\d+$/);
    const definitionUrl = new URL(page.url()).pathname;
    urls.definition = definitionUrl;
    await page
        .getByRole("link", { name: "Add training day", exact: true })
        .click();
    await page.getByLabel("Title", { exact: false }).fill("Foundation workout");
    await page.getByRole("checkbox", { name: "Monday", exact: true }).check();
    await page.getByLabel("Usual time (optional)").fill("18:00");
    await page
        .getByRole("button", { name: "Add exercise", exact: true })
        .first()
        .click();
    await dialog.getByLabel("Find an exercise").fill("Barbell Bench Press");
    await dialog.locator(".library-card").first().click();
    await dialog.getByLabel("Sets", { exact: false }).fill("2");
    await dialog.getByLabel("Reps / duration").fill("8-10");
    await dialog.getByLabel("Planned Weight (Optional)").fill("40");
    await matrix("program-exercise-editor");
    await dialog
        .getByRole("button", { name: "Add exercise", exact: true })
        .click();
    await page
        .getByRole("button", { name: "Save training day", exact: true })
        .click();
    await page.waitForURL(/\/training-days\/\d+$/);
    urls.firstDay = new URL(page.url()).pathname;
    await page.goto(base + definitionUrl);
    await page
        .getByRole("button", { name: "Edit blocks", exact: true })
        .click();
    await dialog.getByLabel("Program duration (weeks)").fill("2");
    await dialog
        .getByRole("button", { name: "Duplicate block", exact: true })
        .click();
    const copied = dialog.locator("fieldset").nth(1);
    await copied.getByLabel("Block name").fill("Deload");
    await copied.getByLabel("Block type").selectOption("deload");
    await matrix("block-editor", true);
    await dialog
        .getByRole("button", { name: "Save blocks", exact: true })
        .click();
    await dialog.waitFor({ state: "hidden" });
    pass(
        "Mobile program builder duplicates the workout into an independent deload block",
    );
    const deloadCard = page.locator("section.program-card").filter({
        has: page.getByRole("heading", { name: "Deload", exact: true }),
    });
    await deloadCard.locator("summary").click();
    await deloadCard
        .getByRole("link", { name: "Edit day", exact: true })
        .click();
    await page.getByRole("button", { name: /^Edit .*Bench/ }).click();
    await dialog.getByLabel("Sets", { exact: false }).fill("1");
    await dialog.getByLabel("Planned Weight (Optional)").fill("20");
    await dialog
        .getByRole("button", { name: "Save exercise", exact: true })
        .click();
    await page
        .getByRole("button", { name: "Save training day", exact: true })
        .click();
    await page.waitForURL(/\/training-days\/\d+$/);
    urls.deloadDay = new URL(page.url()).pathname;
    await page.goto(base + definitionUrl);
    await matrix("program-definition", true);
    await page
        .getByRole("link", { name: "Start program", exact: true })
        .click();
    urls.start = new URL(page.url()).pathname;
    await page.getByLabel("Start date").fill(date(1));
    await page
        .getByRole("button", { name: "Preview program schedule", exact: true })
        .click();
    await page
        .getByRole("button", { name: "Confirm & start program", exact: true })
        .waitFor();
    await matrix("program-start-preview", true);
    assert.equal(await page.locator(".program-week").count(), 2);
    assert.equal(await page.locator(".program-week[open]").count(), 1);
    await page
        .getByRole("button", { name: "Confirm & start program", exact: true })
        .click();
    await page.waitForURL(/\/program-runs\/\d+$/);
    const runUrl = new URL(page.url()).pathname;
    urls.run = runUrl;
    await matrix("active-program", true);
    await page
        .getByRole("button", { name: "Start workout", exact: true })
        .click();
    await page.waitForURL(/\/schedule-entries\/\d+\/workout$/);
    urls.workout = new URL(page.url()).pathname;
    const rows = page.locator(".workout-set");
    // Match the existing set forms by their accessible group labels.
    const setRows = page.locator(".workout-set");
    assert.equal(await setRows.count(), 2);
    for (let i = 0; i < 2; i++) {
        const row = setRows.nth(i);
        await row.getByLabel("Weight (kg)").fill("40");
        await row.getByLabel("Reps", { exact: true }).fill("10");
        await row
            .getByRole("button", { name: `Complete set ${i + 1}`, exact: true })
            .click();
        await row
            .getByRole("button", { name: `Save set ${i + 1}`, exact: true })
            .waitFor();
    }
    await matrix("program-workout");
    await page
        .getByRole("button", { name: "Finish workout", exact: true })
        .first()
        .click();
    await page.waitForURL(/\/schedule-entries\/\d+$/);
    urls.completedSession = new URL(page.url()).pathname;
    await page.goto(base + runUrl);
    await page.getByText("WEEK 2 OF 2", { exact: true }).waitFor();
    pass("Structured workout completion advances from Foundation to Deload");
    await page
        .locator(".program-next")
        .getByRole("link", { name: "View session", exact: true })
        .click();
    await page
        .getByRole("button", { name: "Mark skipped", exact: true })
        .click();
    await page.getByLabel("Change session status").waitFor();
    await page.goto(base + runUrl);
    await page.getByText("PROGRAM COMPLETE 🎉", { exact: true }).waitFor();
    await matrix("program-completion", true);
    pass(
        "Skipped final workout resolves the run and displays a data-backed summary",
    );
    await page
        .getByRole("link", { name: "Repeat program", exact: true })
        .click();
    await page.getByLabel("Start date").fill(date(15));
    await page
        .getByRole("button", { name: "Preview program schedule", exact: true })
        .click();
    await page
        .getByRole("button", { name: "Confirm & start program", exact: true })
        .click();
    await page.waitForURL(/\/program-runs\/\d+$/);
    urls.repeat = new URL(page.url()).pathname;
    assert.notEqual(urls.repeat, runUrl);
    await page.goto(base + runUrl);
    await page.getByText("PROGRAM COMPLETE 🎉", { exact: true }).waitFor();
    pass("Repeat creates a different run while the completed run stays intact");
    await page.goto(base + urls.repeat);
    await page
        .getByRole("button", { name: "Review program changes", exact: true })
        .click();
    await dialog.waitFor();
    await matrix("future-change-review");
    await dialog.getByRole("button", { name: "Cancel", exact: true }).click();
    await page.goto(base + "/program-runs");
    await matrix("program-run-history");
    urls.list = "/program-runs";
    assert.deepEqual(problems, []);
    pass("No application console errors, React warnings or broken assets");
    fs.writeFileSync(
        path.join(
            output,
            `program-browser-${process.env.QA_BROWSER || "chromium"}.json`,
        ),
        JSON.stringify(
            { base, checks, count: checks.length, urls, problems },
            null,
            2,
        ),
    );
    console.log("COMPLETE", checks.length);
} catch (error) {
    await page.screenshot({
        path: path.join(output, "program-browser-failure.png"),
        fullPage: true,
    });
    fs.writeFileSync(
        path.join(output, "program-browser-failure.json"),
        JSON.stringify(
            { url: page.url(), checks, problems, error: error.message },
            null,
            2,
        ),
    );
    throw error;
} finally {
    await browser.close();
}
