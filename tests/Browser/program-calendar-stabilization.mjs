/** Confirmed drag/drop for a disposable local program occurrence. */
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
const playwright = await import(process.env.PLAYWRIGHT_MODULE || "playwright");
const output = process.env.QA_OUTPUT_DIR || "storage/app/private/v201-qa";
const fixture = JSON.parse(
    fs.readFileSync(path.join(output, "stress-fixtures.json")),
);
const accounts = JSON.parse(fs.readFileSync(process.env.QA_ACCOUNTS_FILE));
const account = accounts[Number(process.env.QA_TRAINEE_INDEX || 2)];
assert.match(account.email, /^qa-v200-/);
const engine = process.env.QA_BROWSER || "chromium";
const browser = await playwright[engine].launch();
const context = await browser.newContext({
    viewport: { width: 1280, height: 1000 },
});
const page = await context.newPage();
page.setDefaultTimeout(8000);
const base = "http://localhost:8000";
const checks = [],
    issues = [];
const pass = (label) => {
    checks.push(label);
    console.log("PASS", label);
};
let simulatingFailure = false;
page.on("pageerror", (e) => issues.push(e.message));
page.on("console", (m) => {
    if (["error", "warning"].includes(m.type()) && !simulatingFailure)
        issues.push(m.text());
});
page.on("response", (r) => {
    if (
        r.status() >= 400 &&
        !(
            simulatingFailure &&
            r.status() === 500 &&
            r.url().endsWith("/reschedule")
        )
    )
        issues.push(`${r.status()} ${r.url()}`);
});
const event = () =>
    page
        .locator(`a.fc-event[href$="/schedule-entries/${fixture.moved_entry}"]`)
        .first();
async function drag(to) {
    await event().scrollIntoViewIfNeeded();
    const a = await event().boundingBox(),
        cell = await page.locator(`[data-date="${to}"]`).boundingBox();
    assert.ok(a && cell);
    await page.mouse.move(a.x + a.width / 2, a.y + a.height / 2);
    await page.mouse.down();
    await page.mouse.move(a.x + a.width / 2 + 6, a.y + a.height / 2, {
        steps: 3,
    });
    await page.mouse.move(cell.x + cell.width / 2, cell.y + 35, { steps: 15 });
    await page.mouse.up();
    await page.getByRole("dialog").waitFor();
    assert.equal(await page.getByLabel("New date").inputValue(), to);
}
try {
    await page.goto(base + "/login");
    await page.getByLabel("Email address").fill(account.email);
    await page.getByLabel("Password", { exact: true }).fill(account.password);
    await page.getByRole("button", { name: "Log in", exact: true }).click();
    await page.waitForURL("**/dashboard");
    // The prior engine may already have moved it; inspect actual current data.
    await page.goto(base + `/schedule-entries/${fixture.moved_entry}`);
    const props = JSON.parse(
        await page.locator("#app").getAttribute("data-page"),
    ).props;
    const from = props.entry.scheduled_date.slice(0, 10),
        logicalWeek = props.entry.program_week;
    const d = new Date(from + "T12:00:00");
    d.setDate(d.getDate() + 1);
    const to = d.toLocaleDateString("en-CA");
    await page.goto(base + `/calendar?date=${from}`);
    assert.ok((await event().innerText()).includes("Week 4"));
    pass("Calendar shows saved program week and block on the moved event");
    await drag(to);
    await page.getByRole("button", { name: "Cancel", exact: true }).click();
    await page.getByRole("dialog").waitFor({ state: "hidden" });
    assert.equal(
        await page
            .locator(
                `[data-date="${from}"] a.fc-event[href$="/${fixture.moved_entry}"]`,
            )
            .count(),
        1,
    );
    pass("Cancelled drag restores original event position");
    await drag(to);
    await page
        .getByRole("button", { name: "Preview changes", exact: true })
        .click();
    simulatingFailure = true;
    await page.route("**/reschedule", (route) =>
        route.fulfill({
            status: 500,
            contentType: "application/json",
            body: JSON.stringify({ message: "QA simulated save failure" }),
        }),
    );
    await page
        .getByRole("button", { name: "Apply changes", exact: true })
        .click();
    await page
        .getByRole("alert")
        .filter({ hasText: "QA simulated save failure" })
        .waitFor();
    await page.getByRole("button", { name: "Cancel", exact: true }).click();
    await page.getByRole("dialog").waitFor({ state: "hidden" });
    await page.unroute("**/reschedule");
    await page.reload();
    simulatingFailure = false;
    assert.equal(
        await page
            .locator(
                `[data-date="${from}"] a.fc-event[href$="/${fixture.moved_entry}"]`,
            )
            .count(),
        1,
    );
    pass("Failed save shows an error and preserves persisted original date");
    await drag(to);
    assert.equal(await page.getByLabel("Apply to", { exact: true }).count(), 0);
    await page
        .getByRole("button", { name: "Preview changes", exact: true })
        .click();
    await page
        .getByRole("button", { name: "Apply changes", exact: true })
        .click();
    await page.getByRole("dialog").waitFor({ state: "hidden" });
    assert.equal(
        await page
            .locator(
                `[data-date="${to}"] a.fc-event[href$="/${fixture.moved_entry}"]`,
            )
            .count(),
        1,
    );
    pass("Confirmed program drag moves only this occurrence");
    await page.goto(base + `/schedule-entries/${fixture.moved_entry}`);
    const after = JSON.parse(
        await page.locator("#app").getAttribute("data-page"),
    ).props.entry;
    assert.equal(after.program_week, logicalWeek);
    assert.equal(after.plan_occurrence_key, props.entry.plan_occurrence_key);
    assert.equal(after.scheduled_date.slice(0, 10), to);
    assert.deepEqual(
        after.program_prescription,
        props.entry.program_prescription,
    );
    pass("Program identity, logical week and prescription remain unchanged");
    assert.deepEqual(issues, []);
    pass("No unexpected console errors or failed resources");
    fs.writeFileSync(
        path.join(output, `program-calendar-${engine}.json`),
        JSON.stringify({ checks, count: checks.length, issues }, null, 2),
    );
    console.log("COMPLETE", checks.length);
} finally {
    await browser.close();
}
