/** Privacy and reactive UX on disposable fixtures; no real account writes. */
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
const pw = await import(process.env.PLAYWRIGHT_MODULE || "playwright");
const output = process.env.QA_OUTPUT_DIR,
    engine = process.env.QA_BROWSER || "chromium";
const base = process.env.QA_BASE_URL || "http://localhost:8000";
assert.ok(
    ["http://localhost:8000", "https://training.ahmaddalao.com"].includes(base),
);
const f = JSON.parse(
    fs.readFileSync(path.join(output, "polish-fixtures.json")),
)[engine];
const accounts = JSON.parse(fs.readFileSync(process.env.QA_ACCOUNTS_FILE));
const browser = await pw[engine].launch(),
    context = await browser.newContext({
        viewport: { width: 390, height: 850 },
        serviceWorkers: "block", // PWA is covered separately; keep delayed HTTP responses interceptable.
    }),
    page = await context.newPage();
const checks = [],
    errors = [];
page.setDefaultTimeout(30000);
page.on("pageerror", (e) => errors.push(e.message));
const pass = (s) => {
    checks.push(s);
    console.log("PASS", s);
};
const button = (name) =>
    page.getByRole("button", { name, exact: true }).locator("visible=true");
async function visit(p) {
    await page.goto(base + p);
    await page.locator("#app > *").first().waitFor();
}
async function login(index) {
    const a = accounts[index];
    assert.match(a.email, /^qa-v200-/);
    await visit("/login");
    await page.getByLabel("Email address").fill(a.email);
    await page.getByLabel("Password", { exact: true }).fill(a.password);
    await button("Log in").click();
    await page.waitForURL(/\/(dashboard|coach)$/);
}
async function matrix(name) {
    for (const [width, height] of [
        [320, 850],
        [360, 850],
        [375, 850],
        [390, 850],
        [430, 850],
        [768, 850],
        [1280, 850],
        [320, 480],
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
            `${name} ${width}x${height} overflow`,
        );
        pass(`${name} ${width}x${height}`);
        if (width === 1280)
            await page.screenshot({
                path: path.join(output, `${engine}-polish-${name}-desktop.png`),
                fullPage: true,
            });
    }
    await page.setViewportSize({ width: 390, height: 850 });
    await page.screenshot({
        path: path.join(output, `${engine}-polish-${name}.png`),
        fullPage: true,
    });
}
async function idle() {
    await page.waitForFunction(
        () => !document.querySelector('[aria-busy="true"]'),
    );
}
const prefix = `/coach/trainees/${f.trainee}`;
try {
    await login(f.coach);
    await visit("/coach");
    await matrix("overview");
    await visit("/coach/trainees");
    await page.getByLabel("Search connected trainees").fill(f.code);
    await page.waitForURL((u) => u.searchParams.get("search") === f.code);
    await idle();
    assert.ok(
        (await page.locator(".trainee-mobile-cards").innerText()).includes(
            "Polish",
        ),
    );
    await matrix("trainees");
    await visit(`/coach/training-plans/${f.own.template}/assign`);
    assert.ok(
        (await page.locator(".trainee-mobile-cards article").count()) > 0,
    );
    assert.equal(
        await page.getByRole("button", { name: "Search", exact: true }).count(),
        0,
    );
    pass("Assignment list appears before typing");
    const search = page.getByLabel("Search trainees");
    await search.fill("not-a-person");
    await search.fill(f.code.slice(0, 5));
    await search.fill(f.code);
    await page.waitForURL((u) => u.searchParams.get("search") === f.code);
    await idle();
    assert.equal(
        await page.locator(".trainee-mobile-cards article").count(),
        1,
    );
    await page
        .getByRole("link", { name: "View assignment", exact: false })
        .locator("visible=true")
        .waitFor();
    assert.equal(
        await page
            .getByRole("link", { name: "Assign", exact: true })
            .locator("visible=true")
            .count(),
        0,
    );
    pass("Rapid live search keeps final TP ID and existing assignment");
    const slowPath = "**/*";
    let releaseSlow;
    const slowStarted = new Promise((resolve) => {
        releaseSlow = resolve;
    });
    await page.route(slowPath, async (route) => {
        if (
            new URL(route.request().url()).searchParams.get("search") ===
            "delayed-old-query"
        ) {
            releaseSlow();
            await new Promise((resolve) => setTimeout(resolve, 900));
        }
        await route.continue().catch(() => {});
    });
    await search.fill("delayed-old-query");
    await Promise.race([
        slowStarted,
        new Promise((_, reject) =>
            setTimeout(
                () => reject(new Error("Delayed filter request did not start")),
                10000,
            ),
        ),
    ]);
    await search.fill(f.code);
    await page.waitForURL((u) => u.searchParams.get("search") === f.code);
    await idle();
    await new Promise((resolve) => setTimeout(resolve, 1000));
    assert.equal(await search.inputValue(), f.code);
    assert.equal(
        await page.locator(".trainee-mobile-cards article").count(),
        1,
    );
    await page.unroute(slowPath);
    pass("An older in-flight filter response cannot overwrite newer results");
    assert.equal(await search.inputValue(), f.code);
    await page.reload();
    assert.equal(await search.inputValue(), f.code);
    await matrix("assign");
    await page
        .getByRole("link", { name: "View assignment", exact: false })
        .locator("visible=true")
        .click();
    await page.waitForURL(new RegExp(`/training-plans/${f.own.plan}$`));
    await page.goBack();
    await search.waitFor();
    assert.equal(await search.inputValue(), f.code);
    pass("Assignment filter survives refresh and browser back");
    await visit(prefix);
    assert.ok(!(await page.locator("main").innerText()).includes("QA Private"));
    await matrix("trainee");
    for (const route of [
        "/training-plans",
        "/calendar",
        "/training-history",
        "/progress",
    ]) {
        await visit(prefix + route);
        const text = await page.locator("main").innerText();
        assert.ok(
            !text.includes("Private workout") &&
                !text.includes("Other workout") &&
                !text.includes("QA Private") &&
                !text.includes("QA Other"),
        );
        pass(`Coach scope ${route}`);
        await matrix(route.slice(1));
    }
    assert.equal(await button("Record weight").count(), 0);
    assert.equal(
        await page
            .getByRole("link", { name: "Edit goal", exact: true })
            .count(),
        0,
    );
    await page
        .getByRole("heading", { name: "Training consistency", exact: true })
        .waitFor();
    await page
        .getByRole("heading", { name: "Exercise progress", exact: true })
        .waitFor();
    await page.getByLabel("Progress period").selectOption("90");
    await page.waitForURL((u) => u.searchParams.get("range") === "90");
    await idle();
    pass(
        "Coach dashboard is useful, scoped and read-only; period updates live",
    );
    await visit(prefix + "/schedule-entries/create");
    await page.getByLabel(/^Training plan/).waitFor();
    const opts = await page
        .getByLabel(/^Training plan/)
        .locator("option")
        .allTextContents();
    assert.ok(opts.some((x) => x.includes("Own training")));
    assert.ok(!opts.some((x) => /Private|Other/.test(x)));
    assert.equal(
        await page.getByLabel(/^Training plan/).inputValue(),
        String(f.own.plan),
    );
    assert.equal(
        await page.getByLabel(/^Training day/).inputValue(),
        String(f.own.day),
    );
    await matrix("schedule");
    pass("Sole eligible plan and day preselected; unrelated training excluded");
    for (const kind of ["private", "other"]) {
        const r = await page.request.get(
            base + prefix + `/training-plans/${f[kind].plan}`,
        );
        assert.ok([403, 404].includes(r.status()));
        const h = await page.request.get(
            base + prefix + `/training-history/${f[kind].entry}`,
        );
        assert.equal(h.status(), 404);
    }
    pass("Direct private and other-coach URLs denied");
    await visit(prefix + "/training-history");
    await page.getByLabel(/^Period/).selectOption("today");
    await page.waitForURL((u) => u.searchParams.get("period") === "today");
    await idle();
    assert.ok(
        !(await page.locator("main").innerText()).includes("Own workout 1"),
    );
    await page.getByLabel(/^Period/).selectOption("all");
    await page.waitForURL((u) => u.searchParams.get("period") === "all");
    await idle();
    await page.getByLabel("Items per page").selectOption("25");
    await page.waitForURL((u) => u.searchParams.get("per_page") === "25");
    await idle();
    pass("History period and page-size filters apply without a button");
    await context.clearCookies();
    await login(f.index);
    await visit("/progress");
    await matrix("personal-progress");
    await button("Record weight").waitFor();
    await page.getByLabel("Progress period").selectOption("all");
    await page.waitForURL((u) => u.searchParams.get("range") === "all");
    await idle();
    const highlights = await page
        .locator(".exercise-highlight-grid")
        .innerText();
    await page.getByText("View weight history", { exact: true }).click();
    await page
        .locator(".weight-history")
        .getByRole("link", { name: "2", exact: true })
        .click();
    await page.waitForURL((u) => u.searchParams.get("page") === "2");
    assert.equal(
        await page.locator(".exercise-highlight-grid").innerText(),
        highlights,
    );
    pass("Weight-history pagination preserves training highlights");
    await visit("/recommendations");
    await page.getByLabel("Search plans").waitFor();
    const searchY = (await page.getByLabel("Search plans").boundingBox()).y;
    const recommendY = (
        await page
            .getByRole("heading", { name: "Recommended for you" })
            .boundingBox()
    ).y;
    assert.ok(searchY < recommendY);
    assert.equal(
        await page
            .locator('[aria-labelledby="recommended-title"] .discovery-card')
            .count(),
        3,
    );
    assert.equal(
        await page
            .locator('[aria-labelledby="recommended-title"] .badge.completed')
            .count(),
        3,
    );
    pass("Search first, exactly three labeled recommendations");
    await page.getByLabel("Search plans").fill("ZZZnotaplan");
    await page
        .getByRole("heading", { name: "No training plans match your search" })
        .waitFor();
    await page.waitForURL(
        (u) => u.searchParams.get("search") === "ZZZnotaplan",
    );
    await button("Clear filters").click();
    await page.getByRole("button", { name: /^Filters/ }).click();
    const dialog = page.getByRole("dialog");
    await dialog.getByLabel("Type").selectOption("program");
    assert.equal(
        await page
            .locator('[aria-labelledby="all-plans-title"] .discovery-card')
            .count(),
        4,
    );
    await dialog.getByRole("button", { name: "Done" }).click();
    await page.waitForURL((u) => u.searchParams.get("type") === "program");
    await page.reload();
    await page
        .locator('[aria-labelledby="all-plans-title"] .discovery-card')
        .first()
        .waitFor();
    assert.equal(
        await page
            .locator('[aria-labelledby="all-plans-title"] .discovery-card')
            .count(),
        4,
    );
    pass(
        "Discovery changes behind sheet; Done only closes; URL survives refresh",
    );
    await matrix("discovery");
    assert.deepEqual(errors, []);
    pass("No browser JavaScript errors");
} catch (e) {
    console.error(e);
    await page.screenshot({
        path: path.join(output, `${engine}-polish-failure.png`),
        fullPage: true,
    });
    fs.writeFileSync(
        path.join(output, `${engine}-polish-failure.html`),
        await page.content(),
    );
    throw e;
} finally {
    fs.writeFileSync(
        path.join(output, `${engine}-polish-results.json`),
        JSON.stringify({ checks, errors }, null, 2),
    );
    await browser.close();
}
