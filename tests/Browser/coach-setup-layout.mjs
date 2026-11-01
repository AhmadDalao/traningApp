/** Setup layout regression: disposable QA fixtures only, local or authorized production. */
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
const pw = await import(process.env.PLAYWRIGHT_MODULE || "playwright");
const output = process.env.QA_OUTPUT_DIR;
const engine = process.env.QA_BROWSER || "chromium";
const base = process.env.QA_BASE_URL || "http://localhost:8000";
assert.ok(
    ["http://localhost:8000", "https://training.ahmaddalao.com"].includes(base),
);
const fixture = JSON.parse(fs.readFileSync(path.join(output, "fixtures.json")))[
    engine
];
const accounts = JSON.parse(fs.readFileSync(process.env.QA_ACCOUNTS_FILE));
const account = accounts[fixture.coach];
assert.match(account.email, /^qa-v200-/);
const browser = await pw[engine].launch();
const context = await browser.newContext();
const page = await context.newPage();
page.setDefaultTimeout(15000);
const checks = [],
    errors = [];
page.on("pageerror", (e) => errors.push(e.message));
const pass = (s) => {
    checks.push(s);
    console.log("PASS", s);
};
const button = (name) =>
    page.getByRole("button", { name, exact: true }).locator("visible=true");
async function matrix(step) {
    for (const [width, height] of [
        [320, 480],
        [360, 800],
        [375, 812],
        [390, 844],
        [430, 900],
        [768, 1024],
        [1280, 900],
        [1920, 1080],
        [2560, 1440],
    ]) {
        await page.setViewportSize({ width, height });
        await page.evaluate(() => {
            document.activeElement?.blur();
            window.scrollTo({ top: 0, behavior: "instant" });
        });
        const bounds = await page.evaluate(() => {
            const rect = (e) => {
                const r = e.getBoundingClientRect();
                return { x: r.x, width: r.width };
            };
            return {
                header: rect(document.querySelector(".trainee-context")),
                panels: [
                    ...document.querySelectorAll(
                        ".setup-fields .panel, .setup-steps",
                    ),
                ].map(rect),
                overflow: document.documentElement.scrollWidth > innerWidth + 1,
                radios: [
                    ...document.querySelectorAll(
                        ".setup-training-select input",
                    ),
                ].map((e) => e.getBoundingClientRect().width),
            };
        });
        assert.ok(!bounds.overflow);
        for (const panel of bounds.panels) {
            assert.ok(Math.abs(panel.x - bounds.header.x) < 2);
            assert.ok(Math.abs(panel.width - bounds.header.width) < 2);
        }
        assert.ok(bounds.radios.every((width) => width === 18));
        pass(
            `${step}: header-aligned panels and no overflow at ${width}x${height}`,
        );
        if (width === 390 || width === 1920)
            await page.screenshot({
                path: path.join(output, `${engine}-${step}-${width}.png`),
                fullPage: true,
            });
    }
}
try {
    await page.goto(base + "/login");
    await page.getByLabel("Email address").fill(account.email);
    await page.getByLabel("Password", { exact: true }).fill(account.password);
    await button("Log in").click();
    await page.waitForURL(/\/(dashboard|coach)$/);
    const prefix = "/coach/trainees/" + fixture.trainee;
    await page.goto(base + prefix + "/setup");
    await page
        .getByRole("heading", { name: "1. Choose Training Program" })
        .waitFor();
    assert.equal(await page.locator(".setup-training-option").count(), 3);
    assert.equal(
        await page.getByText("Current assignment", { exact: true }).count(),
        1,
    );
    await page.locator(".setup-training-select").last().click();
    assert.equal(await page.locator("input[name=training]:checked").count(), 1);
    assert.ok(
        await page
            .locator(".setup-training-option")
            .last()
            .getByRole("radio")
            .isChecked(),
    );
    pass("Whole card content selects exactly one assigned copy");
    await page.locator("input[name=training]:checked").focus();
    await page.keyboard.press("ArrowUp");
    assert.ok(
        await page
            .locator(".setup-training-option")
            .nth(1)
            .getByRole("radio")
            .isChecked(),
    );
    pass("Native radio arrow-key selection remains available");
    const popupPromise = context.waitForEvent("page");
    await page
        .locator(".setup-training-option")
        .first()
        .getByRole("link", { name: "Preview training" })
        .click();
    const popup = await popupPromise;
    await popup.waitForLoadState();
    assert.ok(new URL(popup.url()).pathname.includes("/training-plans/"));
    await popup.close();
    assert.ok(
        await page
            .locator(".setup-training-option")
            .nth(1)
            .getByRole("radio")
            .isChecked(),
    );
    pass("Preview opens separately without changing selection");
    await matrix("training");
    await page.getByRole("button", { name: /My Plans/ }).click();
    await page.getByText("No simple plans yet", { exact: true }).waitFor();
    assert.ok(await button("Next: Dates & Schedule").isDisabled());
    pass("Empty category explains next action and clears old selection");
    await page.getByRole("button", { name: /My Programs/ }).click();
    assert.equal(await page.locator(".setup-training-option").count(), 2);
    await page
        .getByRole("radio", { name: "12-Week Powerbuilding", exact: true })
        .check();
    await button("Refresh training list").click();
    await page
        .getByRole("radio", { name: "12-Week Powerbuilding", exact: true })
        .waitFor();
    assert.ok(
        await page
            .getByRole("radio", { name: "12-Week Powerbuilding", exact: true })
            .isChecked(),
    );
    pass("Category changes and refresh preserve deliberate selection");
    await matrix("templates");
    await button("Next: Dates & Schedule").click();
    await page.getByRole("heading", { name: "2. Dates & Schedule" }).waitFor();
    await page.getByLabel("Friday", { exact: true }).uncheck();
    await page.getByLabel("Saturday", { exact: true }).check();
    await page.getByLabel("Time for Full body (optional)").fill("21:15");
    const weekly = page.getByRole("complementary", {
        name: "Weekly schedule preview",
    });
    assert.ok(
        (
            await weekly.locator("li").filter({ hasText: /^Sat/ }).innerText()
        ).includes("Full body"),
    );
    assert.ok(
        !(
            await weekly.locator("li").filter({ hasText: /^Fri/ }).innerText()
        ).includes("Full body"),
    );
    assert.equal(await weekly.getByText("21:15", { exact: true }).count(), 4);
    pass("Weekly preview reflects weekday and optional-time edits immediately");
    await matrix("dates");
    await button("Review subscription & calendar").click();
    await page.locator(".setup-review-rows li").first().waitFor();
    assert.equal(await page.locator(".setup-review-rows li").count(), 48);
    assert.equal(
        await page.locator(".setup-review-rows li:visible").count(),
        8,
    );
    await button("View all 48 sessions").click();
    assert.equal(
        await page.locator(".setup-review-rows li:visible").count(),
        48,
    );
    await button("Show fewer sessions").click();
    pass("Review expands all sessions without dropping any proposed dates");
    await matrix("review");
    await page.setViewportSize({ width: 320, height: 480 });
    await button("Complete Setup").scrollIntoViewIfNeeded();
    await button("Complete Setup").click();
    await page.waitForURL(base + prefix);
    pass(
        "Activation stays reachable at 320x480 and completes existing assignment",
    );
    assert.deepEqual(errors, []);
    pass("No browser runtime errors");
} finally {
    fs.writeFileSync(
        path.join(output, `layout-${engine}.json`),
        JSON.stringify({ checks, errors }, null, 2),
    );
    await browser.close();
}
