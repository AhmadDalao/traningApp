/** v2.5.2: subscription dates, coverage filters, pagination and responsive management. */
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
    fs.readFileSync(path.join(out, "subscription-fixture.json")),
);
const account = JSON.parse(fs.readFileSync(process.env.QA_ACCOUNTS_FILE))[
    fixture.coach
];
assert.match(account.email, /^qa-v200-/);
const browser = await pw[engine].launch();
const context = await browser.newContext({ serviceWorkers: "block" });
const page = await context.newPage();
page.setDefaultTimeout(20000);
const errors = [],
    checks = [];
page.on("pageerror", (e) => errors.push(e.message));
const pass = (name) => {
    checks.push(name);
    console.log("PASS", name);
};
const rows = () => page.locator(".subscription-table tbody tr");
async function go(url) {
    await page.goto(base + url);
    await page.locator("h1").waitFor();
    await page.waitForLoadState("networkidle");
}
async function change(label, value) {
    const response = page.waitForResponse(
        (r) =>
            r.url().includes("/coach/subscriptions?") &&
            r.request().method() === "GET",
    );
    const [received] = await Promise.all([
        response,
        page.getByLabel(label).selectOption(value),
    ]);
    await received.finished();
    await page.waitForFunction(
        () =>
            document
                .querySelector(".subscription-filters")
                ?.getAttribute("aria-busy") === "false",
    );
}
async function clear() {
    await page
        .getByRole("button", { name: "Clear filters", exact: true })
        .click();
    await page.waitForLoadState("networkidle");
    await page.waitForFunction(() =>
        document
            .querySelector('.subscription-filter-actions [role="status"]')
            ?.textContent?.includes("14 subscriptions found"),
    );
}
async function matrix(name) {
    for (const [width, height] of [
        [320, 480],
        [360, 800],
        [375, 812],
        [390, 844],
        [430, 900],
        [768, 1024],
        [1024, 800],
        [1100, 800],
        [1280, 800],
        [1440, 900],
        [1920, 1080],
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
        assert.ok(
            await page
                .locator(".subscription-table-wrap")
                .evaluate((el) => el.scrollWidth <= el.clientWidth + 1),
            name + " table overflow " + width,
        );
        assert.equal(
            await page.locator("a button,button button,label button").count(),
            0,
        );
        assert.ok(
            await rows()
                .first()
                .locator('[data-label="Start date"]')
                .isVisible(),
        );
        assert.ok(
            await rows().first().locator('[data-label="End date"]').isVisible(),
        );
        if (width === 1440 || width === 390) {
            await page.locator("img").evaluateAll(async (imgs) => {
                for (const img of imgs) {
                    img.loading = "eager";
                    await img.decode().catch(() => {});
                }
            });
            await page.screenshot({
                path: path.join(
                    out,
                    engine + "-" + name + "-" + width + ".png",
                ),
                fullPage: true,
            });
        }
        pass(
            name +
                " " +
                width +
                "×" +
                height +
                ": dates visible, no page overflow",
        );
    }
    await page.setViewportSize({ width: 1440, height: 900 });
}
try {
    await page.goto(base + "/login");
    await page.getByLabel("Email address").fill(account.email);
    await page.getByLabel("Password", { exact: true }).fill(account.password);
    await page.getByRole("button", { name: "Log in", exact: true }).click();
    await page.waitForURL((u) => !u.pathname.endsWith("/login"));
    await go("/coach/subscriptions");
    assert.equal(await page.getByLabel("Items per page").inputValue(), "10");
    await page.waitForFunction(
        (n) =>
            document.querySelectorAll(".subscription-table tbody tr").length ===
            n,
        10,
    );
    assert.equal(await rows().count(), 10);
    assert.ok(
        await page
            .getByText("Showing 1–10 of 14 subscriptions", { exact: true })
            .isVisible(),
    );
    pass("Default ten rows and accurate result range");
    for (const heading of [
        "Start date",
        "End date",
        "Status",
        "Time remaining",
    ])
        assert.ok(
            await page
                .getByRole("columnheader", { name: heading, exact: true })
                .isVisible(),
        );
    assert.match(
        await rows().first().locator("time").first().innerText(),
        /\d{1,2} [A-Za-z]{3} 20\d{2}/,
    );
    pass("Separate dates include the year");
    await page
        .getByRole("navigation", { name: "Pagination" })
        .getByRole("link", { name: "2", exact: true })
        .click();
    await page.waitForLoadState("networkidle");
    await page.waitForFunction(
        (n) =>
            document.querySelectorAll(".subscription-table tbody tr").length ===
            n,
        4,
    );
    assert.equal(await rows().count(), 4);
    pass("Page two contains remaining four periods");
    await change("Coverage", "ending_7");
    await page.waitForFunction(
        (n) =>
            document.querySelectorAll(".subscription-table tbody tr").length ===
            n,
        2,
    );
    assert.equal(await rows().count(), 2);
    assert.ok(
        await page
            .locator('[data-label="Time remaining"]')
            .getByText("Ends today", { exact: true })
            .isVisible(),
    );
    assert.ok(
        await page.getByText("7 days until end", { exact: true }).isVisible(),
    );
    pass(
        "Coverage filter resets pagination and includes today and seven-day boundary",
    );
    await change("Sort by", "ending_first");
    assert.equal(
        await rows().first().locator('[data-label="Package"]').innerText(),
        "Ends today",
    );
    pass("Sort by end date works");
    await change("Coverage", "ending_30");
    await page.waitForFunction(
        (n) =>
            document.querySelectorAll(".subscription-table tbody tr").length ===
            n,
        4,
    );
    assert.equal(await rows().count(), 4);
    pass("Ending within 30 days excludes inactive periods");
    await change("Coverage", "ongoing");
    await page.waitForFunction(
        (n) =>
            document.querySelectorAll(".subscription-table tbody tr").length ===
            n,
        1,
    );
    assert.equal(await rows().count(), 1);
    assert.equal(
        await rows().first().locator('[data-label="End date"]').innerText(),
        "Ongoing",
    );
    assert.equal(
        await rows()
            .first()
            .locator('[data-label="Time remaining"]')
            .innerText(),
        "No end date",
    );
    pass("Ongoing coverage has no fabricated end date");
    await clear();
    await change("Trainee", String(fixture.other_trainee_id));
    await page.waitForFunction(
        (n) =>
            document.querySelectorAll(".subscription-table tbody tr").length ===
            n,
        1,
    );
    assert.equal(await rows().count(), 1);
    assert.match(await rows().first().innerText(), /James coaching/);
    pass("Trainee filter is independent per trainee");
    await clear();
    await page.getByLabel("Search subscriptions").fill("Weekly");
    await page.waitForFunction(() =>
        document
            .querySelector('.subscription-filter-actions [role="status"]')
            ?.textContent?.includes("1 subscriptions found"),
    );
    await page.waitForFunction(
        (n) =>
            document.querySelectorAll(".subscription-table tbody tr").length ===
            n,
        1,
    );
    assert.equal(await rows().count(), 1);
    pass("Live search finds package");
    await page.getByRole("button", { name: "Pause", exact: true }).click();
    await page.waitForLoadState("networkidle");
    await page.getByRole("button", { name: "Resume", exact: true }).waitFor();
    assert.match(
        await rows().first().locator('[data-label="Status"]').innerText(),
        /Paused/,
    );
    await page.getByRole("button", { name: "Resume", exact: true }).click();
    await page.waitForLoadState("networkidle");
    await page.getByRole("button", { name: "Pause", exact: true }).waitFor();
    assert.match(
        await rows().first().locator('[data-label="Status"]').innerText(),
        /Active/,
    );
    pass("Pause and Resume still act on only the chosen period");
    await clear();
    await page.getByRole("button", { name: /^Expired/ }).click();
    await page.waitForLoadState("networkidle");
    await page.waitForFunction(
        (n) =>
            document.querySelectorAll(".subscription-table tbody tr").length ===
            n,
        1,
    );
    assert.equal(await rows().count(), 1);
    assert.ok(
        await page.getByText("Ended 1 day ago", { exact: true }).isVisible(),
    );
    pass("Expired state and elapsed days are clear");
    await clear();
    await page.getByRole("button", { name: /^Upcoming/ }).click();
    await page.getByText("Starts in 2 days", { exact: true }).waitFor();
    pass("Upcoming coverage shows the correct start countdown");
    await clear();
    await change("Items per page", "25");
    await page.waitForFunction(
        (n) =>
            document.querySelectorAll(".subscription-table tbody tr").length ===
            n,
        14,
    );
    assert.equal(await rows().count(), 14);
    pass("Page size 25 shows all fourteen independent periods");
    await change("Items per page", "10");
    await matrix("subscriptions");
    await go("/coach/trainees/" + fixture.trainee_id + "/coaching");
    await matrix("trainee-subscriptions");
    assert.deepEqual(errors, []);
    pass("No JavaScript runtime errors");
    fs.writeFileSync(
        path.join(out, "subscription-directory-" + engine + ".json"),
        JSON.stringify({ checks, count: checks.length }, null, 2),
    );
} catch (error) {
    await page.screenshot({
        path: path.join(out, engine + "-subscription-failure.png"),
        fullPage: true,
    });
    console.error("Page", page.url(), "Runtime errors", errors);
    throw error;
} finally {
    await browser.close();
}
