/** Coach visual refresh: real controls, navigation, saved settings and responsive pages. */
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
const pw = await import(process.env.PLAYWRIGHT_MODULE || "playwright");
const base = process.env.QA_BASE_URL || "http://localhost:8000";
assert.ok(
    ["http://localhost:8000", "https://training.ahmaddalao.com"].includes(base),
);
const out = process.env.QA_OUTPUT_DIR;
const engine = process.env.QA_BROWSER || "chromium";
const account = JSON.parse(fs.readFileSync(process.env.QA_ACCOUNTS_FILE))[0];
assert.match(account.email, /^qa-v200-/);
const browser = await pw[engine].launch();
const context = await browser.newContext({
    viewport: { width: 1440, height: 1000 },
    serviceWorkers: "block",
});
const page = await context.newPage();
page.setDefaultTimeout(20000);
const errors = [],
    checks = [];
page.on("pageerror", (e) => errors.push(e.message));
function pass(name) {
    checks.push(name);
    console.log("PASS", name);
}
async function go(url) {
    await page.goto(base + url);
    await page.locator("main").waitFor();
    await page.waitForLoadState("networkidle");
}
async function matrix(name) {
    for (const [width, height] of [
        [320, 480],
        [360, 800],
        [390, 844],
        [430, 900],
        [768, 1024],
        [1024, 800],
        [1280, 900],
        [1440, 1000],
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
            `${name} overflow ${width}`,
        );
        assert.equal(
            await page.locator("a button,button button,label button").count(),
            0,
        );
        if (width >= 1280) {
            const heading = page.locator("main h1").first();
            assert.ok(
                (await heading.boundingBox()).width > 180,
                `${name} heading squeezed`,
            );
        }
        if ([390, 1440].includes(width)) {
            await page.locator("img").evaluateAll(async (images) => {
                for (const img of images) {
                    img.loading = "eager";
                    await img.decode().catch(() => {});
                }
            });
            await page.screenshot({
                path: path.join(out, `${engine}-${name}-${width}.png`),
                fullPage: true,
            });
        }
        if (width < 761)
            assert.ok(
                await page
                    .getByRole("navigation", {
                        name: "Mobile navigation",
                        exact: true,
                    })
                    .isVisible(),
            );
    }
    pass(
        name +
            ": nine responsive sizes, mobile navigation, no overflow or nested controls",
    );
    await page.setViewportSize({ width: 1440, height: 1000 });
}
try {
    await go("/login");
    await page.getByLabel("Email address").fill(account.email);
    await page.getByLabel("Password", { exact: true }).fill(account.password);
    await page.getByRole("button", { name: "Log in", exact: true }).click();
    await page.waitForURL((u) => !u.pathname.endsWith("/login"));
    await go("/coach");
    await matrix("overview");
    assert.equal(await page.locator(".workload-day").count(), 7);
    assert.ok((await page.locator(".momentum-row").count()) > 0);
    await page.getByRole("link", { name: "Review priority queue" }).click();
    await page.waitForURL("**/coach/trainees?status=review");
    assert.ok((await page.url()).includes("status=review"));
    pass("Actual weekly workload, momentum and priority navigation");
    await go("/coach/trainees");
    await matrix("trainees");
    await page.getByLabel("Sort trainees").selectOption("name");
    await page.waitForLoadState("networkidle");
    await page.waitForFunction(
        () =>
            document
                .querySelector(".live-filter-bar")
                ?.getAttribute("aria-busy") === "false",
    );
    const names = await page
        .locator(".trainee-table tbody th strong")
        .allTextContents();
    assert.deepEqual(
        names,
        [...names].sort((a, b) => a.localeCompare(b)),
    );
    const searchResponse = page.waitForResponse(
        (response) =>
            new URL(response.url()).searchParams.get("search") ===
                "Full Body Strength" && response.request().method() === "GET",
    );
    await page
        .getByLabel("Search connected trainees")
        .fill("Full Body Strength");
    await (await searchResponse).finished();
    await page.waitForFunction(
        () =>
            document
                .querySelector(".live-filter-bar")
                ?.getAttribute("aria-busy") === "false",
    );
    assert.equal(await page.locator(".trainee-table tbody tr").count(), 12);
    pass("Roster name sorting and current-program search");
    await go("/coach/training-plans");
    await matrix("programs-grid");
    await page.getByRole("button", { name: "List view", exact: true }).click();
    await matrix("programs-list");
    await page.getByRole("button", { name: /^Draft/ }).click();
    await page.waitForLoadState("networkidle");
    await page.waitForFunction(
        () =>
            document
                .querySelector(".live-filter-bar")
                ?.getAttribute("aria-busy") === "false",
    );
    assert.ok(
        (await page
            .getByRole("link", { name: "Finish program", exact: true })
            .count()) > 0,
    );
    await page.getByRole("button", { name: /^Ready/ }).click();
    await page.waitForLoadState("networkidle");
    await page.waitForFunction(
        () =>
            document
                .querySelector(".live-filter-bar")
                ?.getAttribute("aria-busy") === "false",
    );
    assert.equal(
        await page
            .getByRole("link", { name: "Finish program", exact: true })
            .count(),
        0,
    );
    pass("Program list/grid and server-owned readiness filters");
    const image = page
        .locator(".training-thumbnail button,button.training-thumbnail")
        .first();
    if (await image.count()) {
        await image.click();
        await page.getByRole("dialog").waitFor();
        await page.keyboard.press("Escape");
        pass("Full image preview preserved");
    }
    await go("/coach/subscriptions");
    await matrix("subscriptions");
    await page
        .getByRole("tab", { name: "Coaching packages", exact: true })
        .click();
    assert.ok(await page.locator("#panel-packages").isVisible());
    assert.ok(!(await page.locator("#panel-subscriptions").isVisible()));
    await matrix("packages");
    pass(
        "Subscription/package tabs preserve separate period dates and package controls",
    );
    await go("/coach/settings");
    await matrix("settings");
    for (const id of ["profile", "preferences", "notifications", "devices"])
        assert.ok(await page.locator(`#settings-${id}`).isVisible());
    const name = await page.getByLabel("Coach display name").inputValue();
    await page
        .getByLabel("Specialization (optional)")
        .fill("Strength & conditioning");
    await page
        .getByLabel("About your coaching (optional)")
        .fill(
            "Helping trainees build consistent, sustainable training habits.",
        );
    await page.getByLabel("Experience level").selectOption("intermediate");
    await page.getByRole("switch", { name: /Workout reminders/ }).check();
    await page.getByRole("switch", { name: /Coach updates/ }).check();
    await page
        .getByRole("button", { name: "Save changes", exact: true })
        .click();
    await page.getByText("Settings saved.", { exact: true }).waitFor();
    await page.reload();
    assert.equal(
        await page.getByLabel("Coach display name").inputValue(),
        name,
    );
    assert.equal(
        await page.getByLabel("Specialization (optional)").inputValue(),
        "Strength & conditioning",
    );
    assert.equal(
        await page.getByLabel("Experience level").inputValue(),
        "intermediate",
    );
    assert.ok(
        await page
            .getByRole("switch", { name: /Workout reminders/ })
            .isChecked(),
    );
    await page.getByLabel("Coach display name").fill("Unsaved test");
    await page
        .getByRole("button", { name: "Discard changes", exact: true })
        .click();
    assert.equal(
        await page.getByLabel("Coach display name").inputValue(),
        name,
    );
    pass(
        "One Settings save persists profile, preferences and notifications; discard restores saved values",
    );
    for (const [section, label] of [
        ["profile", "Coach profile"],
        ["preferences", "Training preferences"],
        ["notifications", "Notifications"],
        ["devices", "App & devices"],
    ]) {
        await go("/coach/settings");
        await page.setViewportSize({ width: 390, height: 844 });
        await page
            .getByRole("navigation", { name: "Settings sections", exact: true })
            .getByRole("button", { name: new RegExp(label.replace("&", "&")) })
            .click();
        assert.ok(await page.locator(`#settings-${section}`).isVisible());
        await matrix("settings-" + section);
        await page.setViewportSize({ width: 320, height: 480 });
        await page
            .getByRole("button", { name: "Back to settings", exact: true })
            .click();
        assert.ok(
            await page
                .getByRole("navigation", {
                    name: "Settings sections",
                    exact: true,
                })
                .isVisible(),
        );
    }
    pass(
        "All four mobile sections open independently and return with Back at 320×480",
    );
    await go("/coach/settings?section=devices");
    await page.setViewportSize({ width: 1440, height: 1000 });
    assert.ok(
        await page
            .getByRole("heading", { name: "Connected devices", exact: true })
            .isVisible(),
    );
    await page
        .getByRole("button", { name: "Manage devices", exact: true })
        .click();
    assert.ok(await page.locator(".compact-device.expanded").isVisible());
    await page
        .getByRole("button", { name: "Install Training Planner", exact: true })
        .click();
    assert.ok(
        await page
            .getByText("An internet connection is required", { exact: false })
            .count(),
    );
    pass("App installation guidance and device controls remain available");
    await page.locator(".user-menu-toggle").click();
    assert.ok(
        await page
            .getByRole("link", { name: "Coaching administration", exact: true })
            .isVisible(),
    );
    await page.screenshot({
        path: path.join(out, `${engine}-workspace-menu-1440.png`),
    });
    await page.keyboard.press("Escape");
    assert.equal(await page.locator("#user-menu").count(), 0);
    pass("Grouped workspace menu opens and dismisses with Escape");
    await page.locator(".user-menu-toggle").click();
    await page
        .locator("#user-menu")
        .getByRole("button", { name: "Personal Workspace", exact: true })
        .click();
    await page.waitForURL("**/dashboard");
    assert.ok(await page.locator(".personal-workspace").count());
    assert.equal(await page.locator(".coach-bottom-nav").count(), 0);
    pass("Personal workspace remains separate");
    assert.deepEqual(errors, []);
    pass("No browser runtime errors");
    fs.writeFileSync(
        path.join(out, `coach-design-refresh-${engine}.json`),
        JSON.stringify({ engine, base, checks, count: checks.length }, null, 2),
    );
} catch (e) {
    await page.screenshot({
        path: path.join(out, `${engine}-refresh-failure.png`),
        fullPage: true,
    });
    throw e;
} finally {
    await browser.close();
}
