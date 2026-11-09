import fs from "node:fs";
import path from "node:path";
import assert from "node:assert/strict";
const pw = await import(process.env.PLAYWRIGHT_MODULE || "playwright");
const base = process.env.QA_BASE_URL || "http://localhost:8000",
    out = process.env.QA_OUTPUT_DIR,
    engine = process.env.QA_BROWSER || "chromium";
assert(
    ["http://localhost:8000", "https://training.ahmaddalao.com"].includes(base),
);
const f = JSON.parse(fs.readFileSync(path.join(out, "simple-fixtures.json")))[
        engine
    ],
    a = JSON.parse(fs.readFileSync(process.env.QA_ACCOUNTS_FILE))[f.coach];
assert.match(a.email, /^qa-v200-/);
const browser = await pw[engine].launch(),
    page = await browser.newPage({ viewport: { width: 1440, height: 900 } }),
    errors = [],
    checks = [];
page.on("pageerror", (e) => errors.push(e.message));
const pass = (s) => {
    checks.push(s);
    console.log("PASS", s);
};
try {
    await page.goto(base + "/login");
    await page.getByLabel("Email address").fill(a.email);
    await page.getByLabel("Password", { exact: true }).fill(a.password);
    await page.getByRole("button", { name: "Log in", exact: true }).click();
    await page.waitForURL(/\/coach$/);
    const prefix = `/coach/trainees/${f.trainee_id}`;
    await page.goto(
        base + prefix + `/training-plans/${f.plan_id}/schedule-preview`,
    );
    await page
        .getByRole("heading", { name: "Weekly schedule", exact: true })
        .waitFor();
    assert.equal(
        await page.locator("details.schedule-advanced[open]").count(),
        0,
    );
    pass("Simple plan shows weekly pattern before collapsed technical details");
    await page
        .getByRole("button", { name: "Change", exact: true })
        .first()
        .click();
    const edit = page.getByRole("dialog");
    const time = edit.getByLabel("Workout time (optional)");
    await time.fill((await time.inputValue()) === "20:30" ? "19:30" : "20:30");
    await edit
        .getByRole("button", { name: "Save weekly schedule", exact: true })
        .click();
    await edit.waitFor({ state: "detached" });
    await page
        .getByRole("button", { name: "Review changes", exact: true })
        .click();
    const review = page.getByRole("dialog");
    await review
        .getByLabel("Also remove obsolete eligible generated sessions")
        .check();
    assert(
        await review
            .getByRole("heading", { name: "Dates to add", exact: true })
            .isVisible(),
    );
    assert(
        await review
            .getByRole("heading", { name: "Dates to remove", exact: true })
            .isVisible(),
    );
    pass("Time correction explicitly previews both old and new occurrences");
    await review.getByRole("button", { name: /Confirm/ }).click();
    await review.waitFor({ state: "detached" });
    assert.equal(
        await page
            .getByRole("button", { name: "Review changes", exact: true })
            .isDisabled(),
        true,
    );
    pass("Confirmed simple plan regeneration leaves no pending diff");
    await page.goto(base + prefix + "/schedule-entries/create");
    const plan = page.getByLabel("Training", { exact: true });
    assert.equal(await plan.inputValue(), "");
    assert((await plan.locator("option").count()) > 2);
    pass("Multiple assignments require explicit plan selection");
    await plan.selectOption(String(f.plan_id));
    assert.equal(
        await page
            .getByLabel("Workout", { exact: true })
            .locator("option")
            .count(),
        2,
    );
    pass("One authored workout appears once");
    assert.deepEqual(errors, []);
    pass("No browser runtime errors");
} finally {
    fs.writeFileSync(
        path.join(out, `weekly-schedule-${engine}.json`),
        JSON.stringify({ checks, errors }, null, 2),
    );
    await browser.close();
}
