/** Local-only, real UI lifecycle audit. Credentials stay in the private cleanup ledger. */
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { randomBytes } from "node:crypto";
const pw = await import(process.env.PLAYWRIGHT_MODULE || "playwright");
const output = process.env.QA_OUTPUT_DIR;
const ledger = process.env.QA_ACCOUNTS_FILE;
assert.ok(
    output && ledger,
    "Private QA output and cleanup ledger are required",
);
const base = "http://localhost:8000";
const engine = process.env.QA_BROWSER || "chromium";
const browser = await pw[engine].launch();
const checks = [],
    issues = [],
    urls = {};
let current;
const pass = (label) => {
    checks.push(label);
    console.log("PASS", label);
};
const button = (page, name) => page.getByRole("button", { name, exact: true });
const link = (page, name) => page.getByRole("link", { name, exact: true });
const date = (offset = 0) => {
    const d = new Date();
    d.setDate(d.getDate() + offset);
    return d.toLocaleDateString("en-CA", { timeZone: "Asia/Riyadh" });
};
async function visit(p, url) {
    current = p;
    await p.goto(base + url);
    await p.locator("#app > *").first().waitFor({ state: "visible" });
}
async function matrix(p, label) {
    for (const width of [320, 360, 375, 390, 430, 768, 1280]) {
        await p.setViewportSize({ width, height: 850 });
        await p.evaluate(
            () =>
                new Promise((r) =>
                    requestAnimationFrame(() => requestAnimationFrame(r)),
                ),
        );
        const sizes = await p.evaluate(() => ({
            width: innerWidth,
            scroll: document.documentElement.scrollWidth,
            dialogs: [...document.querySelectorAll("dialog[open]")].map((d) => [
                d.clientWidth,
                d.scrollWidth,
            ]),
        }));
        assert.ok(
            sizes.scroll <= sizes.width + 1 &&
                sizes.dialogs.every(([a, b]) => b <= a + 1),
            label + JSON.stringify(sizes),
        );
    }
    await p.setViewportSize({ width: 320, height: 480 });
    if (await p.getByRole("dialog").count()) {
        const last = p.getByRole("dialog").getByRole("button").last();
        await last.scrollIntoViewIfNeeded();
        const b = await last.boundingBox();
        assert.ok(b.y >= 0 && b.y + b.height <= 481);
    }
    await p.setViewportSize({ width: 390, height: 844 });
    pass(`${label}: all 7 widths and short-screen actions`);
}
async function register(type) {
    const account = {
        name: `QA v2.0.0 UI ${type} ${engine} ${Date.now()}`,
        email: `qa-v200-${randomBytes(10).toString("hex")}@example.com`,
        password: randomBytes(24).toString("base64url"),
        type,
    };
    const accounts = JSON.parse(fs.readFileSync(ledger));
    accounts.push(account);
    fs.writeFileSync(ledger, JSON.stringify(accounts), { mode: 0o600 });
    const ctx = await browser.newContext({
        viewport: { width: 390, height: 844 },
    });
    const p = await ctx.newPage();
    current = p;
    p.setDefaultTimeout(9000);
    p.on("pageerror", (e) => issues.push(e.message));
    p.on("console", (m) => {
        if (m.type() === "warning") issues.push(m.text());
    });
    await visit(p, "/register");
    if (type === "coach") {
        await button(p, "I'm a coach").click();
        await p.getByLabel("Coach display name").fill(account.name);
    }
    await p.getByLabel("Your name").fill(account.name);
    await p.getByLabel("Email address").fill(account.email);
    await p.getByLabel("Password", { exact: true }).fill(account.password);
    await p.getByLabel("Confirm password").fill(account.password);
    await button(p, "Create account").click();
    await p.waitForURL(
        type === "coach" ? "**/coach" : /\/(onboarding|dashboard)$/,
    );
    pass(`${type} registers through shared account UI`);
    return { p, account };
}
async function profile(p) {
    await visit(p, "/onboarding");
    await p.getByLabel("Height (optional)").fill("178");
    await p.getByLabel("Current Body Weight (optional)").fill("82");
    await button(p, "Next").click();
    const goals = p.locator('input[name="training_goals"]');
    for (let i = 0; i < 3; i++) await goals.nth(i).check();
    await goals.nth(3).click();
    assert.equal(await goals.locator(":scope:checked").count(), 3);
    await p
        .getByText("You can choose up to 3 training goals.", { exact: true })
        .waitFor();
    await p
        .getByRole("button", { name: /Move up:/ })
        .nth(2)
        .click();
    await matrix(p, "Ordered goals");
    await button(p, "Next").click();
    await p.getByLabel("Experience level (optional)").selectOption("beginner");
    await p.getByLabel("Training days per week (optional)").selectOption("3");
    await p
        .getByLabel("Typical session duration (optional)")
        .selectOption("45");
    await p.getByRole("checkbox", { name: "Full gym", exact: true }).check();
    await button(p, "Save & see recommendations").click();
    await p.waitForURL("**/recommendations");
    pass(
        "Onboarding records body weight, enforces three ordered goals, and opens recommendations",
    );
    await p.getByLabel("Search plans").fill("8-Week Beginner Strength");
    await link(p, "Preview 8-Week Beginner Strength").first().click();
    await button(p, "Use this program").first().click();
    await p.waitForURL(/\/training-plans\/\d+$/);
    urls.discovery = new URL(p.url()).pathname;
    await visit(p, "/profile");
    await matrix(p, "Profile and saved preferences");
    await visit(p, "/progress");
    await matrix(p, "Body progress");
}
async function addExercise(p, name, mode = "reps", partner = false) {
    await button(p, "Add exercise").first().click();
    const d = p.getByRole("dialog");
    await d.getByLabel("Find an exercise").fill(name);
    await d.locator(".library-card").first().click();
    await d.getByLabel("Sets", { exact: false }).fill("1");
    await d
        .getByLabel("Reps / duration")
        .fill(mode === "duration" ? "30 seconds" : "10");
    await d.getByLabel("Track by").selectOption(mode);
    await d
        .getByLabel("Planned Weight (Optional)")
        .fill(mode === "reps" ? "40" : "");
    if (partner) {
        await button(d, "Choose partner from library").click();
        await d.getByLabel("Find an exercise").fill("Pushups");
        await d.locator(".library-card").first().click();
        await d.getByLabel("Sets", { exact: false }).fill("1");
        await d.getByLabel("Reps / duration").fill("15");
        await d.getByLabel("Planned Weight (Optional)").fill("");
        await button(d, "Use this partner").click();
    }
    await button(d, partner ? "Add linked exercises" : "Add exercise").click();
    await d.waitFor({ state: "hidden" });
}
async function makeProgram(p, prefix = "", rich = false) {
    await visit(p, prefix + "/training-plans");
    await button(p, "Create training plan").click();
    const d = p.getByRole("dialog");
    await d.getByLabel("Create").selectOption("program");
    await d
        .getByLabel("Plan name")
        .fill(`QA lifecycle ${rich ? "Personal" : "Coach"} ${engine}`);
    await d.getByLabel("Program duration (weeks)").fill("1");
    await button(d, "Create plan").click();
    await p.waitForURL(/\/training-plans\/\d+$/);
    const definition = new URL(p.url()).pathname;
    await link(p, "Add training day").click();
    await p.getByLabel("Title", { exact: false }).fill("Strength + مدة");
    await p.getByRole("checkbox", { name: "Monday", exact: true }).check();
    await p.getByLabel("Usual time (optional)").fill("18:00");
    await addExercise(p, "Barbell Bench Press", "reps", rich);
    if (rich) await addExercise(p, "Plank", "duration");
    await button(p, "Save training day").click();
    await p.waitForURL(/\/training-days\/\d+$/);
    await visit(p, definition);
    await button(p, "Edit blocks").click();
    await d.getByLabel("Program duration (weeks)").fill("2");
    await button(d, "Duplicate block").click();
    await d.locator("fieldset").nth(1).getByLabel("Block name").fill("Deload");
    await d
        .locator("fieldset")
        .nth(1)
        .getByLabel("Block type")
        .selectOption("deload");
    await button(d, "Save blocks").click();
    await d.waitFor({ state: "hidden" });
    pass(
        `${prefix ? "Coach template" : "Personal program"} created with independent foundation/deload blocks${rich ? ", library superset and duration exercise" : ""}`,
    );
    return definition;
}
async function start(p, definition, offset = 1) {
    await visit(p, definition);
    await link(p, "Start program").click();
    await p.getByLabel("Start date").fill(date(offset));
    await button(p, "Preview program schedule").click();
    await button(p, "Confirm & start program").waitFor();
    await matrix(p, "Finite schedule review");
    await button(p, "Confirm & start program").click();
    await p.waitForURL(/\/program-runs\/\d+$/);
    return new URL(p.url()).pathname;
}
async function control(p, action, offset = 7, known = false) {
    const labels = {
        pause: "Pause program",
        resume: "Resume program",
        shift: "Adjust remaining schedule",
        end: "End program",
    };
    await button(p, labels[action]).click();
    const d = p.getByRole("dialog");
    if (action === "pause" && known) {
        await d.getByLabel("Return date").selectOption("known");
        await d.getByLabel("Expected resume date").fill(date(offset));
    }
    if (["resume", "shift"].includes(action))
        await d
            .getByLabel(
                action === "resume"
                    ? "Resume on"
                    : "Start remaining workouts on",
            )
            .fill(date(offset));
    await button(d, "Preview changes").click();
    await d
        .getByRole("heading", { name: "Review changes", exact: true })
        .waitFor();
    await button(
        d,
        action === "end" ? "End program early" : labels[action],
    ).click();
    await d.waitFor({ state: "hidden" });
}
async function workout(p, rich = false) {
    await button(p, "Start workout").click();
    await p.waitForURL(/\/workout$/);
    const session = new URL(p.url()).pathname.replace("/workout", "");
    const first = p.locator(".workout-exercise").first();
    let row = first.locator(".workout-set").first();
    await row.getByLabel("Reps", { exact: true }).fill("10");
    await row.getByLabel("Weight (kg)").fill("42.5");
    await button(row, "Complete set 1").click();
    await button(row, "Save set 1").waitFor();
    if (rich) {
        await row.getByLabel("Reps", { exact: true }).fill("11");
        await button(row, "Save set 1").click();
        await p.locator(".unsaved-set").waitFor({ state: "hidden" });
        await button(first, "Add set").click();
        await first.locator(".workout-set").nth(1).waitFor();
        await button(first, "Remove set 2").click();
        await first.locator(".workout-set").nth(1).waitFor({ state: "hidden" });
        await button(row, "Mark set 1 incomplete").click();
        await button(row, "Complete set 1").waitFor();
        await button(row, "Complete set 1").click();
        await button(row, "Save set 1").waitFor();
        await button(first, "Skip exercise").click();
        await button(p.getByRole("dialog"), "Skip exercise").click();
        await button(first, "Undo skip").waitFor();
        await button(first, "Undo skip").click();
        await first.locator(".workout-set").waitFor();
        assert.ok((await p.locator(".workout-superset").count()) >= 2);
        const second = p
            .locator(".workout-exercise")
            .nth(1)
            .locator(".workout-set")
            .first();
        await second.getByLabel("Reps", { exact: true }).fill("20");
        await second.getByLabel("Weight (kg)").fill("0");
        await button(second, "Complete set 1").click();
        await button(second, "Save set 1").waitFor();
        const duration = p
            .locator(".workout-exercise")
            .nth(2)
            .locator(".workout-set")
            .first();
        await duration.getByLabel("Duration (mm:ss)").fill("00:45");
        await duration.getByLabel("Weight (kg)").fill("");
        await button(duration, "Complete set 1").click();
        await button(duration, "Save set 1").waitFor();
        await button(p, "90 sec").click();
        await button(p, "Start timer").click();
        await button(p, "Pause timer").click();
        await button(p, "Reset timer").click();
        assert.equal(await p.getByRole("timer").textContent(), "01:30");
        await matrix(p, "Full structured workout");
        await p.reload();
        await button(p.locator(".workout-set").first(), "Save set 1").waitFor();
        assert.equal(await p.locator(".workout-set.is-complete").count(), 3);
        pass(
            "Sets edit/add/remove/undo, skip/undo, zero weight, duration, superset, timer and refresh preserve actuals",
        );
    }
    await button(p, "Finish workout").first().click();
    if (await p.getByRole("dialog").count())
        await button(p.getByRole("dialog"), "Finish anyway").click();
    await p.waitForURL(/\/schedule-entries\/\d+$/);
    return session;
}
try {
    const { p: trainee, account } = await register("trainee");
    await profile(trainee);
    const definition = await makeProgram(trainee, "", true);
    urls.personalDefinition = definition;
    const run = await start(trainee, definition);
    urls.personalRun = run;
    const nextTop = await trainee.locator(".program-next").boundingBox();
    const endTop = await button(trainee, "End program").boundingBox();
    assert.ok(nextTop.y < endTop.y);
    assert.ok(
        !(await button(trainee, "End program").getAttribute("class")).includes(
            "btn-danger",
        ),
    );
    pass("Workout precedes secondary controls and End is visually secondary");
    const session = await workout(trainee, true);
    urls.personalSession = session;
    await visit(trainee, run);
    await trainee.getByText("WEEK 2 OF 2", { exact: true }).waitFor();
    await control(trainee, "pause", 7, true);
    await trainee.getByText("PROGRAM PAUSED", { exact: true }).waitFor();
    assert.match(
        await button(trainee, "Resume program").getAttribute("class"),
        /btn-primary/,
    );
    await matrix(trainee, "Paused program hierarchy");
    await control(trainee, "resume", 7);
    await control(trainee, "shift", 10);
    await control(trainee, "pause");
    await control(trainee, "resume", 11);
    await trainee.getByText("WEEK 2 OF 2", { exact: true }).waitFor();
    pass(
        "Repeated known/indefinite pause, resume and shift preserve logical week",
    );
    await link(trainee.locator(".program-next"), "View session").click();
    await button(trainee, "Mark completed").click();
    await trainee.getByLabel("Change session status").waitFor();
    await visit(trainee, run);
    await trainee.getByText("PROGRAM COMPLETE 🎉", { exact: true }).waitFor();
    await matrix(trainee, "Completed program and summary");
    await visit(
        trainee,
        session.replace("/schedule-entries/", "/training-history/"),
    );
    await trainee.getByLabel("Change session status").selectOption("planned");
    await button(trainee, "Start workout").waitFor();
    await visit(trainee, run);
    await trainee.getByText("WEEK 1 OF 2", { exact: true }).waitFor();
    await button(trainee, "Start workout").click();
    await trainee.waitForURL(/\/workout$/);
    assert.equal(await trainee.locator(".workout-set.is-complete").count(), 3);
    await button(trainee, "Finish workout").first().click();
    await trainee.waitForURL(/\/schedule-entries\/\d+$/);
    await visit(trainee, run);
    await trainee.getByText("PROGRAM COMPLETE 🎉", { exact: true }).waitFor();
    pass(
        "Reopening and completing again reuse saved sets and resolve the same run",
    );
    await link(trainee, "Repeat program").click();
    await trainee.getByLabel("Start date").fill(date(20));
    await button(trainee, "Preview program schedule").click();
    await button(trainee, "Confirm & start program").click();
    await trainee.waitForURL(/\/program-runs\/\d+$/);
    const repeat = new URL(trainee.url()).pathname;
    assert.notEqual(run, repeat);
    urls.repeat = repeat;
    const other = await trainee.context().newPage();
    await visit(other, repeat);
    await control(other, "pause");
    await button(trainee, "Start workout").click();
    await trainee
        .getByRole("alert")
        .filter({ hasText: "Resume the program" })
        .waitFor();
    await other.close();
    pass(
        "Stale Start displays the server rejection instead of silently doing nothing",
    );
    await control(trainee, "resume", 21);
    await control(trainee, "end");
    await trainee.getByText("PROGRAM ENDED EARLY", { exact: true }).waitFor();
    assert.equal(await button(trainee, "Resume program").count(), 0);
    await visit(trainee, run);
    await trainee.getByText("PROGRAM COMPLETE 🎉", { exact: true }).waitFor();
    pass(
        "Ending the repeated run leaves original completion and actuals intact",
    );
    await visit(
        trainee,
        session.replace("/schedule-entries/", "/training-history/"),
    );
    await matrix(trainee, "History detailed actuals");
    await link(trainee, "View Exercise Progress").first().click();
    await matrix(trainee, "Exercise progress and records");
    await visit(trainee, "/training-preferences");
    await button(trainee, "Next").click();
    assert.equal(
        await trainee.locator('input[name="training_goals"]:checked').count(),
        3,
    );
    await button(trainee, "Next").click();
    await button(trainee, "Save & see recommendations").click();
    await trainee.waitForURL("**/recommendations");
    pass(
        "Returning preferences retain ordered goals after completed and ended programs",
    );
    const { p: coach } = await register("coach");
    await visit(trainee, "/my-coaching");
    const code = await trainee.locator(".training-code").textContent();
    await button(trainee, "Show Coach QR").click();
    const qr = await trainee.getByLabel("Temporary coaching link").inputValue();
    assert.ok(await trainee.getByRole("dialog").locator("svg").count());
    await button(trainee.getByRole("dialog"), "Close dialog").click();
    await visit(coach, "/coach/trainees");
    await coach.getByLabel("Training Planner ID").fill(code);
    await button(coach, "Find member").click();
    await coach.locator(".coach-member").waitFor();
    assert.equal(await link(coach, "View trainee").count(), 0);
    await coach.goto(qr);
    await button(coach, "Send request").click();
    await coach.waitForURL("**/coach/trainees");
    await visit(trainee, "/my-coaching");
    await button(trainee, "Accept coach").click();
    await button(trainee, "Disconnect coach").waitFor();
    pass(
        "ID lookup, QR request, explicit consent and active relationship use real UI",
    );
    await visit(coach, "/coach/subscriptions");
    await button(coach, "Start subscription").click();
    const d = coach.getByRole("dialog");
    await d
        .getByLabel("Connected trainee")
        .selectOption({ label: account.name });
    await d.getByLabel("Custom package name").fill("QA lifecycle service");
    await d.getByLabel("Ends on").fill(date(180));
    await button(d, "Start subscription").click();
    await d.waitFor({ state: "hidden" });
    const template = await makeProgram(coach, "/coach");
    await visit(coach, "/coach/trainees");
    await coach
        .getByRole("link", { name: /View trainee/ })
        .first()
        .click();
    await coach.waitForURL(/\/coach\/trainees\/\d+$/);
    const prefix = new URL(coach.url()).pathname;
    await visit(coach, prefix + "/assign");
    await coach
        .getByLabel("Coach template")
        .selectOption(template.split("/").at(-1));
    await button(coach, "Confirm assignment").click();
    await coach.waitForURL(/\/training-plans\/\d+$/);
    const assigned = new URL(coach.url()).pathname;
    const coachedRun = await start(coach, assigned);
    urls.coachedRun = coachedRun;
    const ownerRun = "/program-runs/" + coachedRun.split("/").at(-1);
    assert.equal(await button(coach, "Start workout").count(), 0);
    await visit(trainee, ownerRun);
    await workout(trainee);
    await visit(coach, coachedRun);
    await coach.getByText("WEEK 2 OF 2", { exact: true }).waitFor();
    await matrix(coach, "Coach context and monitored progress");
    await control(coach, "pause");
    await control(coach, "resume", 15);
    await control(coach, "shift", 18);
    await visit(coach, "/coach/subscriptions");
    await button(coach, "Pause").click();
    await button(coach, "Resume").waitFor();
    await visit(coach, coachedRun);
    assert.equal(await button(coach, "End program").count(), 0);
    await coach.getByText("Read-only access.", { exact: false }).waitFor();
    await visit(coach, "/coach/subscriptions");
    await button(coach, "Resume").click();
    await button(coach, "Pause").waitFor();
    await visit(coach, coachedRun);
    await button(coach, "Pause program").waitFor();
    pass(
        "Subscription pause removes write controls; resume restores authorized management",
    );
    await visit(trainee, "/my-coaching");
    await button(trainee, "Disconnect coach").click();
    await button(trainee.getByRole("dialog"), "Disconnect coach").click();
    await trainee.getByRole("dialog").waitFor({ state: "hidden" });
    await visit(trainee, ownerRun);
    await button(trainee, "Start workout").waitFor();
    await control(trainee, "end");
    await trainee.getByText("PROGRAM ENDED EARLY", { exact: true }).waitFor();
    await visit(trainee, session);
    await trainee
        .getByText("Session completed. Keep showing up.", { exact: true })
        .waitFor();
    pass(
        "Disconnect preserves trainee-owned program controls and prior history",
    );
    assert.deepEqual(issues, []);
    pass("No React warnings or uncaught application errors");
    fs.writeFileSync(
        path.join(output, `full-lifecycle-${engine}.json`),
        JSON.stringify({ checks, count: checks.length, urls, issues }, null, 2),
    );
    console.log("COMPLETE", checks.length);
} catch (e) {
    if (current) {
        await current.screenshot({
            path: path.join(output, `full-lifecycle-failure-${engine}.png`),
            fullPage: true,
        });
        fs.writeFileSync(
            path.join(output, `full-lifecycle-failure-${engine}.json`),
            JSON.stringify(
                { error: e.message, url: current.url(), checks, issues },
                null,
                2,
            ),
        );
    }
    throw e;
} finally {
    await browser.close();
}
