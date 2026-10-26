// FB-CIVIL-01A-UI U4 — visual verification of the Staff Portal (LOCAL acceptance only).
//
// Drives a real headless Microsoft Edge through the Chrome DevTools Protocol
// against the acceptance stack (UI :3010, API :8010, famboook_u4_test): one
// isolated browser context per staff user, a full-page screenshot per route,
// and the rendered facts of each page (direction, language, navigation, gate
// notice, worker status, forbidden / empty / error / loading states).
// Screenshots and facts: %LOCALAPPDATA%/famboook-u4/visual/. SYNTHETIC data only.
//
//   node deploy/local-acceptance/u4-visual.mjs            # main pass
//   node deploy/local-acceptance/u4-visual.mjs --monitor-error   # only: overview while the monitor is unavailable
import { spawn } from "node:child_process";
import { mkdirSync, readFileSync, writeFileSync, rmSync } from "node:fs";
import { join } from "node:path";

const UI = "http://localhost:3010";
const API = "http://localhost:8010";
const STATE = (process.env.FAMBOOOK_U4_STATE ?? join(process.env.LOCALAPPDATA ?? "/tmp", "famboook-u4")).replaceAll("\\", "/");
const OUT = `${STATE}/visual`;
const EDGE = "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe";
const PORT = 9333;
const monitorErrorOnly = process.argv.includes("--monitor-error");

mkdirSync(OUT, { recursive: true });
const facts = [];
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// ---------------------------------------------------------------- browser and CDP
const profile = `${STATE}/edge-profile`;
rmSync(profile, { recursive: true, force: true });
const edge = spawn(EDGE, ["--headless=new", `--remote-debugging-port=${PORT}`, `--user-data-dir=${profile}`, "--no-first-run",
  "--no-default-browser-check", "--disable-extensions", "--window-size=1440,1000", "--lang=ar", "about:blank"], { stdio: "ignore" });

let version;
for (let i = 0; i < 60 && !version; i++) {
  try {
    version = await (await fetch(`http://127.0.0.1:${PORT}/json/version`)).json();
  } catch {
    await sleep(500);
  }
}
if (!version) throw new Error("EDGE_NOT_STARTED");

const ws = new WebSocket(version.webSocketDebuggerUrl);
await new Promise((resolve, reject) => { ws.onopen = resolve; ws.onerror = reject; });
let nextId = 1;
const pending = new Map();
ws.onmessage = (event) => {
  const msg = JSON.parse(event.data);
  if (msg.id && pending.has(msg.id)) {
    const { resolve, reject } = pending.get(msg.id);
    pending.delete(msg.id);
    msg.error ? reject(new Error(`${msg.error.message}`)) : resolve(msg.result);
  }
};
const send = (method, params = {}, sessionId) =>
  new Promise((resolve, reject) => {
    const id = nextId++;
    pending.set(id, { resolve, reject });
    ws.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }));
  });

async function persona() {
  const { browserContextId } = await send("Target.createBrowserContext", { disposeOnDetach: true });
  const { targetId } = await send("Target.createTarget", { url: "about:blank", browserContextId });
  const { sessionId } = await send("Target.attachToTarget", { targetId, flatten: true });
  for (const domain of ["Page", "Runtime", "Network"]) await send(`${domain}.enable`, {}, sessionId);
  await send("Emulation.setDeviceMetricsOverride", { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false }, sessionId);
  const evaluate = async (expression) =>
    (await send("Runtime.evaluate", { expression, awaitPromise: true, returnByValue: true }, sessionId)).result.value;
  const goto = async (path) => {
    await send("Page.navigate", { url: UI + path }, sessionId);
    // Settled: document loaded, no loading skeleton, a heading or a forbidden block rendered, for 1.5 s.
    let stable = 0;
    for (let i = 0; i < 400 && stable < 3; i++) {
      await sleep(500);
      const ready = await evaluate(`document.readyState === 'complete' && !document.querySelector('[data-loading],[aria-busy="true"]')
        && !!document.querySelector('h1, [data-forbidden]')`).catch(() => false);
      stable = ready ? stable + 1 : 0;
    }
  };
  return { sessionId, evaluate, goto };
}

async function shoot(p, name) {
  // Full page as a real window renders it: grow the viewport to the content height and
  // capture without a clip (a clip at document x=0 shifts RTL documents).
  const height = Math.min(Math.max(1000, Math.ceil(await p.evaluate("document.documentElement.scrollHeight"))), 4000);
  await send("Emulation.setDeviceMetricsOverride", { width: 1440, height, deviceScaleFactor: 1, mobile: false }, p.sessionId);
  await sleep(400);
  const { data } = await send("Page.captureScreenshot", { format: "png" }, p.sessionId);
  await send("Emulation.setDeviceMetricsOverride", { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false }, p.sessionId);
  writeFileSync(`${OUT}/${name}.png`, Buffer.from(data, "base64"));
}

const FACTS = `(() => {
  const text = (el) => (el?.innerText ?? '').replace(/\\s+/g, ' ').trim();
  const kpi = [...document.querySelectorAll('span')].find((s) => s.textContent.trim() === 'عامل الاستيراد');
  return {
    url: location.pathname,
    dir: document.documentElement.dir, lang: document.documentElement.lang,
    h1: text(document.querySelector('h1')),
    nav: [...document.querySelectorAll('[data-civil-nav] a')].map((a) => text(a)),
    navCurrent: text(document.querySelector('[data-civil-nav] a[aria-current="page"]')),
    sidebar: [...document.querySelectorAll('nav a')].map((a) => text(a)).filter((t) => t && t.length < 30).slice(0, 20),
    gate: document.querySelector('[data-publication-gate]')?.getAttribute('data-publication-gate') ?? null,
    gateText: text(document.querySelector('[data-publication-gate]')).slice(0, 160),
    worker: kpi ? text(kpi.parentElement).replace('عامل الاستيراد', '').trim() : null,
    forbidden: text(document.querySelector('[data-forbidden]')) || null,
    empty: [...document.querySelectorAll('[data-empty-state]')].map((e) => text(e)).join(' | ') || null,
    error: document.querySelector('[data-error]') ? text(document.querySelector('[data-error]')) : null,
    newImport: !!document.querySelector('[data-new-import]'),
    readOnlyNote: text(document.querySelector('[data-read-only-note]')).slice(0, 80) || null,
    buttons: [...document.querySelectorAll('main button, [role="main"] button, h1 ~ * button')].map((b) => text(b)).filter(Boolean).slice(0, 25),
    horizontalOverflow: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
  };
})()`;

async function visit(p, who, path, name) {
  await p.goto(path);
  const f = await p.evaluate(FACTS);
  facts.push({ who, name, ...f });
  await shoot(p, name);
  console.log(`${who.padEnd(9)} ${path.padEnd(48)} h1="${f.h1}" dir=${f.dir} ${f.forbidden ? "FORBIDDEN" : ""}${f.error ? " ERROR" : ""}${f.empty ? " EMPTY" : ""}`);
  return f;
}

async function passwordLogin(p, email, password) {
  await p.goto("/login");
  await sleep(1500);   // let the login page's own first API call settle the session
  // As lib/api/client.ts: on 419 (token of another session) refresh the CSRF cookie and retry.
  return p.evaluate(`(async () => {
    let status = 0;
    for (let attempt = 0; attempt < 3 && status !== 200; attempt++) {
      await fetch('${API}/sanctum/csrf-cookie', { credentials: 'include' });
      const xsrf = decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/) || [])[1] || '');
      const r = await fetch('${API}/api/v1/auth/login', { method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf },
        body: JSON.stringify({ email: ${JSON.stringify(email)}, password: ${JSON.stringify(password)} }) });
      status = r.status;
    }
    return status;
  })()`);
}

const me = (p) => p.evaluate(`fetch('${API}/api/v1/me', { credentials: 'include', headers: { Accept: 'application/json' } }).then((r) => r.status)`);

try {
  // ---------------------------------------------------------------- requester (dev, SUPER_ADMIN)
  const dev = await persona();
  await dev.goto("/dev-login");
  await sleep(2000);
  console.log(`dev session: /api/v1/me -> ${await me(dev)}`);

  if (monitorErrorOnly) {
    await visit(dev, "dev", "/administration/civil-registry", "e1-dev-overview-monitor-unavailable");
  } else {
    await visit(dev, "dev", "/administration/civil-registry", "01-dev-overview");
    await visit(dev, "dev", "/administration/civil-registry/imports/new", "02-dev-new-import");
    await visit(dev, "dev", "/administration/civil-registry/approvals", "03-dev-approvals");
    await visit(dev, "dev", "/administration/civil-registry/audit", "04-dev-audit");
    await visit(dev, "dev", "/administration/civil-registry/imports", "05-dev-imports");

    // Empty states: filters with no match.
    await dev.evaluate(`[...document.querySelectorAll('button')].find((b) => b.textContent.trim() === 'مرفوض')?.click()`);
    await sleep(2500);
    facts.push({ who: "dev", name: "06-dev-imports-empty", ...(await dev.evaluate(FACTS)) });
    await shoot(dev, "06-dev-imports-empty");
    await dev.goto("/administration/civil-registry/approvals");
    await dev.evaluate(`[...document.querySelectorAll('button')].find((b) => b.textContent.trim() === 'معتمد — بانتظار التنفيذ')?.click()`);
    await sleep(2500);
    facts.push({ who: "dev", name: "07-dev-approvals-empty", ...(await dev.evaluate(FACTS)) });
    await shoot(dev, "07-dev-approvals-empty");

    // One approval's details and one run's details.
    const firstPublication = await dev.evaluate(`fetch('${API}/api/v1/civil-registry/publication-requests', { credentials: 'include', headers: { Accept: 'application/json' } })
      .then((r) => r.json()).then((j) => j.data[0]?.id)`);
    if (firstPublication) await visit(dev, "dev", `/administration/civil-registry/approvals/${firstPublication}`, "08-dev-approval-detail");
    const firstRun = await dev.evaluate(`fetch('${API}/api/v1/civil-registry/imports', { credentials: 'include', headers: { Accept: 'application/json' } })
      .then((r) => r.json()).then((j) => j.data[0]?.id)`);
    if (firstRun) await visit(dev, "dev", `/administration/civil-registry/imports/${firstRun}`, "09-dev-run-detail");

    // Loading state: API responses delayed by 4 s.
    await send("Network.emulateNetworkConditions", { offline: false, latency: 4000, downloadThroughput: -1, uploadThroughput: -1 }, dev.sessionId);
    await send("Page.navigate", { url: `${UI}/administration/civil-registry/approvals` }, dev.sessionId);
    let sawLoading = false;
    for (let i = 0; i < 240 && !sawLoading; i++) {
      await sleep(250);
      sawLoading = await dev.evaluate(`!!document.querySelector('[data-loading]')`).catch(() => false);
    }
    facts.push({ who: "dev", name: "10-dev-loading", loadingRendered: sawLoading });
    if (sawLoading) await shoot(dev, "10-dev-loading");
    await send("Network.emulateNetworkConditions", { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1 }, dev.sessionId);
    console.log(`dev       loading skeleton rendered: ${sawLoading}`);

    // ---------------------------------------------------------------- other users
    const accounts = Object.fromEntries(readFileSync(`${STATE}/accounts.txt`, "utf8").split(/\r?\n/)
      .filter((l) => l && !l.startsWith("#")).map((l) => l.split("\t")).map(([email, , password]) => [email, password]));
    const viewer = await persona();
    console.log(`viewer login: ${await passwordLogin(viewer, "u4-viewer@famboook.test", accounts["u4-viewer@famboook.test"])}`);
    await visit(viewer, "viewer", "/administration/civil-registry", "11-viewer-overview");
    await visit(viewer, "viewer", "/administration/civil-registry/imports/new", "12-viewer-new-import");
    await visit(viewer, "viewer", "/administration/civil-registry/approvals", "13-viewer-approvals");
    await visit(viewer, "viewer", "/administration/civil-registry/audit", "14-viewer-audit");

    const outsider = await persona();
    console.log(`outsider login: ${await passwordLogin(outsider, "u4-outsider@famboook.test", accounts["u4-outsider@famboook.test"])}`);
    await visit(outsider, "outsider", "/administration/civil-registry", "15-outsider-overview");
    await visit(outsider, "outsider", "/administration/civil-registry/approvals", "16-outsider-approvals");

    const approver = await persona();
    console.log(`approver login: ${await passwordLogin(approver, "u4-approver@famboook.test", accounts["u4-approver@famboook.test"])}`);
    if (firstPublication) await visit(approver, "approver", `/administration/civil-registry/approvals/${firstPublication}`, "17-approver-approval-detail");
  }
} finally {
  writeFileSync(`${OUT}/${monitorErrorOnly ? "facts-monitor-error" : "facts"}.json`, JSON.stringify(facts, null, 2));
  try { await send("Browser.close"); } catch { /* already closed */ }
  edge.kill();
  await sleep(1500);
  try { rmSync(profile, { recursive: true, force: true, maxRetries: 5, retryDelay: 500 }); } catch { /* removed on the next run */ }
}
