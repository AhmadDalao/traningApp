/** Read and mutate only disposable LOCAL lifecycle QA fixtures. */
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
const playwright = await import(process.env.PLAYWRIGHT_MODULE || "playwright");
const output = process.env.QA_OUTPUT_DIR || "storage/app/private/v201-qa";
const fixtures = JSON.parse(
    fs.readFileSync(path.join(output, "stress-fixtures.json")),
);
const accounts = JSON.parse(fs.readFileSync(process.env.QA_ACCOUNTS_FILE));
const engine = process.env.QA_BROWSER || "chromium";
const account = accounts[Number(process.env.QA_TRAINEE_INDEX || 2)];
assert.match(account.email, /^qa-v200-/);
const base = "http://localhost:8000";
const browser = await playwright[engine].launch();
const context = await browser.newContext({
    viewport: { width: 320, height: 850 },
});
const page = await context.newPage();
page.setDefaultTimeout(9000);
const checks = [],
    issues = [];
const pass = (label) => {
    checks.push(label);
    console.log("PASS", label);
};
page.on("pageerror", (e) => issues.push(e.message));
page.on("console", (m) => {
    if (["error", "warning"].includes(m.type())) issues.push(m.text());
});
page.on("response", (r) => {
    if (r.status() >= 400) issues.push(`${r.status()} ${r.url()}`);
});
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
            `${label}: ${JSON.stringify(dims)}`,
        );
        pass(`${label}: ${width}px`);
    }
    await page.setViewportSize({ width: 320, height: 850 });
    if (screenshot)
        await page.screenshot({
            path: path.join(output, `${engine}-stress-${label}.png`),
            fullPage: true,
        });
}
try {
    await page.goto(base + "/login");
    await page.getByLabel("Email address").fill(account.email);
    await page.getByLabel("Password", { exact: true }).fill(account.password);
    await page.getByRole("button", { name: "Log in", exact: true }).click();
    await page.waitForURL("**/dashboard");
    await matrix("many-active-programs", true);
    await page.goto(base + `/training-plans/${fixtures.plan}`);
    assert.equal(
        await page
            .locator("section.program-card")
            .filter({ has: page.locator("details.program-day") })
            .count(),
        4,
    );
    await matrix("twelve-week-long-definition", true);
    await page.goto(base + `/program-runs/${fixtures.run}`);
    await page.getByText("WEEK 1 OF 12", { exact: true }).waitFor();
    assert.equal(await page.locator(".program-week").count(), 12);
    await matrix("missed-and-out-of-order-run", true);
    const missed = fixtures.missed_entries[engine === "webkit" ? 1 : 0];
    await page.goto(base + `/schedule-entries/${missed}`);
    await page
        .getByRole("button", { name: "Change date", exact: true })
        .click();
    const dialog = page.getByRole("dialog");
    const date = new Date();
    date.setDate(date.getDate() + 7);
    await dialog
        .getByLabel("New date")
        .fill(date.toLocaleDateString("en-CA", { timeZone: "Asia/Riyadh" }));
    await dialog
        .getByRole("button", { name: "Preview changes", exact: true })
        .click();
    await dialog
        .getByRole("button", { name: "Apply changes", exact: true })
        .waitFor();
    await page.setViewportSize({ width: 320, height: 480 });
    await dialog
        .getByRole("button", { name: "Apply changes", exact: true })
        .click();
    await dialog.waitFor({ state: "hidden" });
    await page.goto(base + `/program-runs/${fixtures.run}`);
    await page.getByText("WEEK 1 OF 12", { exact: true }).waitFor();
    pass(
        "Missed workout moves through reviewed short-height dialog without advancing logical week",
    );
    const review = page.getByRole("button", {
        name: "Review program changes",
        exact: true,
    });
    await review.click();
    const protectedRows = dialog.locator("details").filter({
        has: page.locator("summary").filter({ hasText: /^PROTECTED/ }),
    });
    await protectedRows.locator("summary").click();
    assert.ok(
        (await protectedRows.innerText()).includes(
            "QA manually moved ORIGINAL",
        ),
    );
    assert.ok(
        !(await protectedRows.innerText()).includes("QA proposed replacement"),
    );
    await matrix("protected-preview", true);
    await page.setViewportSize({ width: 320, height: 480 });
    await dialog.getByRole("button", { name: "Cancel", exact: true }).click();
    await page.waitForFunction(() =>
        document.activeElement?.textContent.includes("Review program changes"),
    );
    pass(
        "Protected diff displays saved title; cancel returns keyboard focus at 320×480",
    );
    await page.goto(base + `/training-history/${fixtures.early_entry}`);
    await page.getByRole("heading", { level: 1 }).waitFor();
    assert.ok((await page.locator("body").innerText()).includes("Completed"));
    await matrix("early-completed-history", true);
    await page.goto(base + `/program-runs/${fixtures.completed_run}`);
    await page.getByText("PROGRAM COMPLETE 🎉", { exact: true }).waitFor();
    assert.ok(
        (await page.locator("body").innerText()).includes(
            "first to last weighted performance in this run",
        ),
    );
    assert.ok(
        !(await page.locator("body").innerText()).includes(
            "Push-ups LongExerciseLongExerciseLongExerciseLongExerciseLongExerciseLongExerciseLongExerciseLongExercise:",
        ),
    );
    await matrix("large-completion-summary", true);
    await page.goto(base + `/schedule-entries/${fixtures.completed_entry}`);
    await page.getByLabel("Change session status").selectOption("planned");
    await page
        .getByRole("button", { name: "Mark completed", exact: true })
        .waitFor();
    await page.goto(base + `/program-runs/${fixtures.completed_run}`);
    await page.getByText("WEEK 1 OF 12", { exact: true }).waitFor();
    assert.equal(
        await page
            .getByRole("heading", { name: "Your program summary", exact: true })
            .count(),
        0,
    );
    await page.goto(base + `/schedule-entries/${fixtures.completed_entry}`);
    await page
        .getByRole("button", { name: "Mark completed", exact: true })
        .click();
    await page.getByLabel("Change session status").waitFor();
    await page.goto(base + `/program-runs/${fixtures.completed_run}`);
    await page.getByText("PROGRAM COMPLETE 🎉", { exact: true }).waitFor();
    pass("Reopen and recomplete refresh the same run and its summary");
    await page.goto(base + "/program-runs");
    assert.equal(
        await page
            .getByRole("link", { name: "View program run", exact: true })
            .count(),
        12,
    );
    assert.equal(
        await page
            .locator(`a[href="/program-runs/${fixtures.foreign_run}"]`)
            .count(),
        0,
    );
    await matrix("paginated-run-history", true);
    await page.goto(base + "/program-runs?page=2");
    assert.ok(
        (await page
            .getByRole("link", { name: "View program run", exact: true })
            .count()) > 0,
    );
    pass("Run history paginates at 12 and excludes trainee B");
    for (const view of ["Month", "Week", "List"]) {
        await page.goto(base + "/calendar");
        await page.getByRole("button", { name: view, exact: true }).click();
        await page.locator('.calendar-panel[aria-busy="false"]').waitFor();
        await matrix("calendar-" + view.toLowerCase());
    }
    assert.deepEqual(issues, []);
    pass("No console errors, React warnings or failed resources");
    fs.writeFileSync(
        path.join(output, `program-stabilization-${engine}.json`),
        JSON.stringify({ checks, count: checks.length, issues }, null, 2),
    );
    console.log("COMPLETE", checks.length);
} catch (error) {
    await page.screenshot({
        path: path.join(output, `${engine}-stress-failure.png`),
        fullPage: true,
    });
    fs.writeFileSync(
        path.join(output, `${engine}-stress-failure.json`),
        JSON.stringify(
            { url: page.url(), checks, issues, error: error.message },
            null,
            2,
        ),
    );
    throw error;
} finally {
    await browser.close();
}
