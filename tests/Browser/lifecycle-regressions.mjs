/** Focused v2.1.1 UI regressions against private local program fixtures. */
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
const pw = await import(process.env.PLAYWRIGHT_MODULE || "playwright");
const engine = process.env.QA_BROWSER || "chromium",
    output = process.env.QA_OUTPUT_DIR;
const fixture = JSON.parse(
    fs.readFileSync(path.join(output, "regression-fixtures.json")),
)[engine];
const account = JSON.parse(fs.readFileSync(process.env.QA_ACCOUNTS_FILE))[2];
assert.match(account.email, /^qa-v200-/);
const browser = await pw[engine].launch();
const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } });
const p = await ctx.newPage();
p.setDefaultTimeout(8000);
const checks = [],
    issues = [];
const pass = (label) => {
    checks.push(label);
    console.log("PASS", label);
};
p.on("pageerror", (e) => issues.push(e.message));
const button = (scope, name) =>
    scope.getByRole("button", { name, exact: true });
const go = async (url) => p.goto("http://localhost:8000" + url);
async function matrix(label) {
    for (const width of [320, 360, 375, 390, 430, 768, 1280]) {
        await p.setViewportSize({ width, height: 850 });
        await p.evaluate(() => new Promise((r) => requestAnimationFrame(r)));
        assert.ok(
            await p.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth + 1,
            ),
        );
        pass(`${label}: ${width}px`);
    }
    await p.setViewportSize({ width: 390, height: 844 });
    await p.screenshot({
        path: path.join(output, `${engine}-regression-${label}.png`),
    });
}
try {
    await go("/login");
    await p.getByLabel("Email address").fill(account.email);
    await p.getByLabel("Password", { exact: true }).fill(account.password);
    await button(p, "Log in").click();
    await p.waitForURL("**/dashboard");
    await go("/program-runs/" + fixture.run);
    await p.getByText("PROGRAM PAUSED", { exact: true }).waitFor();
    await button(p, "Start workout").waitFor();
    await matrix("future-pause");
    pass(
        "Future pause keeps the earlier unresolved workout available on its run page",
    );
    await button(p, "End program").click();
    let d = p.getByRole("dialog");
    await button(d, "Preview changes").click();
    await button(d, "End program early").click();
    await d.waitFor({ state: "hidden" });
    for (const route of ["schedule-entries", "training-history"]) {
        await go("/" + route + "/" + fixture.past);
        await p
            .getByText("This program is paused or ended.", { exact: false })
            .waitFor();
        assert.equal(await button(p, "Start workout").count(), 0);
        assert.equal(await button(p, "Mark completed").count(), 0);
        assert.equal(
            await p.getByRole("link", { name: "Edit", exact: true }).count(),
            0,
        );
        pass(`${route} hides forbidden actions on retained unresolved history`);
    }
    await matrix("ended-history");
    await go("/training-history/" + fixture.saved);
    await p.getByLabel("Change session status").selectOption("planned");
    await p
        .getByText("This program is paused or ended.", { exact: false })
        .waitFor();
    assert.equal(await button(p, "Start workout").count(), 0);
    assert.equal(
        await p
            .getByRole("link", { name: "Resume workout", exact: true })
            .count(),
        0,
    );
    assert.ok((await p.locator(".logged-set").count()) > 0);
    await p.getByLabel("Change session status").selectOption("completed");
    await p
        .getByText("Session completed. Keep showing up.", { exact: true })
        .waitFor();
    pass(
        "Saved history allows status corrections while preserving actuals and hiding held workout actions",
    );
    await go("/training-history");
    assert.equal(await p.getByLabel("Items per page").inputValue(), "10");
    pass("History defaults to 10");
    for (const n of ["25", "50", "100", "all", "10"]) {
        await p.getByLabel("Items per page").selectOption(n);
        await p.waitForURL((u) => u.searchParams.get("per_page") === n);
        await p.waitForFunction(
            (n) =>
                !document.querySelector(".history-toolbar select:disabled") &&
                document.querySelectorAll(".history-table tbody tr").length <=
                    (n === "all" ? Infinity : Number(n)),
            n,
        );
        assert.equal(await p.getByLabel("Items per page").inputValue(), n);
        pass(`History page size ${n}`);
    }
    for (const period of [
        "today",
        "yesterday",
        "last_7_days",
        "last_30_days",
        "this_month",
    ]) {
        await p.getByLabel("Period").selectOption(period);
        await p.waitForURL((u) => u.searchParams.get("period") === period);
        await p.getByLabel("Period").waitFor({ state: "visible" });
        await p.waitForFunction(
            () => !document.querySelector("select:disabled"),
        );
        const dates = await p
            .locator(".history-table tbody a[aria-label]")
            .evaluateAll((a) =>
                a.map((x) => x.getAttribute("aria-label").slice(-10)),
            );
        const today = new Date().toLocaleDateString("en-CA", {
            timeZone: "Asia/Riyadh",
        });
        for (const date of dates) {
            assert.ok(date <= today);
            if (period === "today") assert.equal(date, today);
            if (period === "yesterday") assert.equal(date, fixture.saved_date);
        }
        pass(`History ${period} applies before displaying rows`);
    }
    await p.getByLabel("Period").selectOption("custom");
    await p.getByLabel("From", { exact: true }).fill(fixture.past_date);
    await p.getByLabel("To", { exact: true }).fill(fixture.saved_date);
    await p.getByLabel("Session type").selectOption("training");
    await button(p, "Apply filters").click();
    await p.waitForURL(
        (u) =>
            u.searchParams.get("period") === "custom" &&
            u.searchParams.get("type") === "training",
    );
    await p.waitForFunction(() => !document.querySelector("select:disabled"));
    const labels = await p
        .locator(".history-table tbody a[aria-label]")
        .evaluateAll((a) =>
            a.map((x) => x.getAttribute("aria-label").slice(-10)),
        );
    assert.ok(labels.length > 0);
    assert.ok(
        labels.every((v) => v >= fixture.past_date && v <= fixture.saved_date),
    );
    await matrix("history-custom-filter");
    pass("Custom date range and session type combine correctly");
    assert.deepEqual(issues, []);
    fs.writeFileSync(
        path.join(output, `lifecycle-regressions-${engine}.json`),
        JSON.stringify({ checks, count: checks.length, issues }, null, 2),
    );
    console.log("COMPLETE", checks.length);
} catch (e) {
    await p.screenshot({
        path: path.join(output, `lifecycle-regressions-failure-${engine}.png`),
    });
    throw e;
} finally {
    await browser.close();
}
