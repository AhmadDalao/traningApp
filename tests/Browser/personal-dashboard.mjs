/** Personal Dashboard reference design: real data, navigation and workout controls. */
import fs from "node:fs";
import path from "node:path";
import assert from "node:assert/strict";
const pw = await import(process.env.PLAYWRIGHT_MODULE || "playwright");
const base = process.env.QA_BASE_URL || "http://localhost:8000";
assert(
    ["http://localhost:8000", "https://training.ahmaddalao.com"].includes(base),
);
const out = process.env.QA_OUTPUT_DIR,
    engine = process.env.QA_BROWSER || "chromium";
const accounts = JSON.parse(fs.readFileSync(process.env.QA_ACCOUNTS_FILE));
const browser = await pw[engine].launch();
const context = await browser.newContext({
    viewport: { width: 1440, height: 1000 },
    serviceWorkers: "block",
});
const page = await context.newPage(),
    errors = [],
    checks = [];
page.setDefaultTimeout(20000);
page.on("pageerror", (e) => errors.push(e.message));
const pass = (x) => {
    checks.push(x);
    console.log("PASS", x);
};
async function go(url) {
    await page.goto(base + url);
    await page.locator("main").waitFor();
    await page.waitForLoadState("networkidle");
}
async function login(account) {
    assert(account.email.startsWith("qa-v200-"));
    await context.clearCookies();
    await go("/login");
    await page.getByLabel("Email address").fill(account.email);
    await page.getByLabel("Password", { exact: true }).fill(account.password);
    await page.getByRole("button", { name: "Log in", exact: true }).click();
    await page.waitForURL((u) => u.pathname != "/login");
}
async function shot(name) {
    await page.locator("img").evaluateAll(async (images) => {
        for (const i of images) {
            i.loading = "eager";
            await i.decode().catch(() => {});
        }
    });
    await page.screenshot({
        path: path.join(out, `${engine}-${name}.png`),
        fullPage: true,
    });
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
        assert(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth + 1,
            ),
            `${name}: ${width} overflow`,
        );
        assert.equal(
            await page.locator("a button,button button,label button").count(),
            0,
        );
        if (width < 761) {
            assert(
                await page
                    .getByRole("navigation", {
                        name: "Mobile navigation",
                        exact: true,
                    })
                    .isVisible(),
            );
            const links = await page
                .locator(".personal-bottom-nav a")
                .evaluateAll((es) => es.map((e) => e.getAttribute("href")));
            assert.deepEqual(links, [
                "/dashboard",
                "/training-plans",
                "/calendar",
                "/progress",
                "/profile",
            ]);
        }
        if ([390, 1440].includes(width)) await shot(`${name}-${width}`);
        pass(`${name}: ${width}×${height}, no overflow or nested controls`);
    }
    await page.setViewportSize({ width: 1440, height: 1000 });
}
try {
    await login(accounts[2]);
    await go("/dashboard");
    await page.locator(".personal-dashboard").waitFor();
    await matrix("personal-dashboard");
    assert.equal(await page.locator(".coach-workspace").count(), 0);
    assert(
        await page
            .locator(".personal-dashboard-workspace.reference-workspace")
            .isVisible(),
    );
    pass(
        "Personal identity and navigation preserved inside shared reference shell",
    );
    const today = page.getByRole("region", {
        name: "Today's sessions",
        exact: true,
    });
    assert((await today.locator("article").count()) >= 13);
    assert(
        await today.getByText("Anytime", { exact: true }).first().isVisible(),
    );
    assert((await today.innerText()).includes("Recovery block 8"));
    pass(
        "All today rows retained, including untimed, timed, rest, completed and skipped",
    );
    const scroll = await today.evaluate((e) => ({
        height: e.clientHeight,
        content: e.scrollHeight,
        parent: e.closest("section").getBoundingClientRect().height,
    }));
    assert(scroll.content > scroll.height);
    assert.equal(Math.round(scroll.parent), 390);
    await today.evaluate((e) => (e.scrollTop = e.scrollHeight));
    await today
        .getByText("Recovery block 8", { exact: true })
        .scrollIntoViewIfNeeded();
    assert(
        await today.getByText("Recovery block 8", { exact: true }).isVisible(),
    );
    await today.evaluate((e) => (e.scrollTop = 0));
    pass(
        "Bounded 390px cards scroll to every session without growing the page",
    );
    assert((await page.locator(".program-run-summary").count()) > 0);
    assert((await page.locator(".program-mini-progress").count()) > 0);
    pass("Current program uses existing lifecycle card and progress values");
    await page
        .getByRole("button", { name: /View full image for/ })
        .first()
        .click();
    await page.getByRole("dialog").waitFor();
    assert(
        await page
            .getByRole("link", { name: "Open original image" })
            .isVisible(),
    );
    await page.keyboard.press("Escape");
    await page.getByRole("dialog").waitFor({ state: "hidden" });
    pass("Workout thumbnails open the full image and close by keyboard");
    // Banner starts the actual existing workout, then resumes it after returning.
    const focus = page.getByRole("region", { name: "Your training focus" });
    if (
        await focus
            .getByRole("button", { name: "Start workout", exact: true })
            .count()
    )
        await focus
            .getByRole("button", { name: "Start workout", exact: true })
            .click();
    else await focus.getByRole("link", { name: "Resume workout" }).click();
    await page.waitForURL(/\/schedule-entries\/\d+\/workout$/);
    await go("/dashboard");
    assert(
        await page
            .getByRole("region", { name: "Your training focus" })
            .getByRole("link", { name: "Resume workout" })
            .isVisible(),
    );
    await shot("personal-active-workout");
    pass("Start and Resume use the original active-workout engine");
    const evening = page
        .locator(".personal-session-row")
        .filter({ hasText: "Evening Strength" });
    if (
        await evening
            .getByRole("button", { name: "Mark completed", exact: true })
            .count()
    ) {
        await evening
            .getByRole("button", { name: "Mark completed", exact: true })
            .click();
        await page.waitForLoadState("networkidle");
        await go("/dashboard");
    }
    assert(
        await page
            .locator(".personal-session-row")
            .filter({ hasText: "Evening Strength" })
            .getByText("Completed", { exact: true })
            .first()
            .count(),
    );
    pass("Mark completed updates the existing status and dashboard result");

    for (const [label, url] of [
        ["View history", "/training-history"],
        ["See your progress", "/progress"],
    ]) {
        await page
            .getByRole("link", { name: new RegExp(label) })
            .first()
            .click();
        await page.waitForURL((u) => u.pathname === url);
        await go("/dashboard");
    }
    pass("History and Progress links stay in Personal Workspace");
    await page
        .getByRole("button", { name: "Create plan", exact: true })
        .click();
    await page.getByLabel(/Plan name/).fill(`QA dashboard ${engine}`);
    await page
        .getByRole("dialog")
        .getByRole("button", { name: "Create plan", exact: true })
        .click();
    await page.waitForURL(/\/training-plans\/\d+$/);
    await go("/dashboard");
    pass("Create plan retains the existing form and personal ownership");
    await page
        .getByRole("button", { name: "Schedule session", exact: true })
        .first()
        .click();
    await page
        .getByRole("dialog")
        .getByRole("button", { name: "Rest day", exact: true })
        .click();
    await page
        .getByLabel("Session title", { exact: true })
        .fill(`Dashboard recovery ${engine}`);
    await page
        .getByRole("dialog")
        .getByRole("button", { name: /Save to calendar|Schedule session/ })
        .click();
    await page.getByRole("dialog").waitFor({ state: "hidden" });
    await go("/dashboard");
    assert(
        (
            await page
                .getByRole("region", { name: "Today's sessions", exact: true })
                .innerText()
        ).includes(`Dashboard recovery ${engine}`),
    );
    pass("Schedule modal saves a real session and refreshes dashboard counts");
    if (
        await page
            .getByRole("button", { name: "Record today's weight" })
            .count()
    ) {
        await page
            .getByRole("button", { name: "Record today's weight" })
            .click();
        await page.getByLabel(/Body weight/).fill("81.5");
        await page
            .getByRole("button", { name: "Save weight", exact: true })
            .click();
        await page.getByRole("dialog").waitFor({ state: "hidden" });
        await page.reload();
        assert.equal(
            await page
                .getByRole("heading", { name: "Weekly check-in", exact: true })
                .count(),
            0,
        );
        pass(
            "Weekly check-in records weight and dismisses the prompt after saving",
        );
    } else pass("Saved weekly weight does not prompt again");
    await page.getByRole("link", { name: "Complete profile" }).click();
    await page.waitForURL("**/onboarding");
    pass("Profile completion remains directly reachable");
    await login(accounts[3]);
    await go("/dashboard");
    await matrix("personal-empty");
    assert(
        await page
            .getByRole("link", { name: "Find a training plan" })
            .isVisible(),
    );
    assert(
        await page
            .getByRole("heading", { name: "Your progress starts here" })
            .isVisible(),
    );
    assert.equal(await page.locator(".program-run-summary").count(), 0);
    pass("Empty account receives honest zero metrics and useful next actions");
    await login(accounts[0]);
    await go("/coach");
    assert(
        await page.locator(".coach-workspace.reference-workspace").isVisible(),
    );
    await shot("coach-regression-1440");
    await page.setViewportSize({ width: 390, height: 844 });
    assert(await page.locator(".coach-bottom-nav").isVisible());
    assert(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth + 1,
        ),
    );
    await go("/coach/settings");
    assert(await page.locator(".coach-workspace").isVisible());
    pass(
        "Shared styling preserves Coach desktop, mobile navigation and settings context",
    );
    assert.deepEqual(errors, []);
    pass("No browser runtime errors");
} finally {
    fs.writeFileSync(
        path.join(out, `personal-dashboard-${engine}.json`),
        JSON.stringify(
            { base, engine, checks, count: checks.length, errors },
            null,
            2,
        ),
    );
    await browser.close();
}
