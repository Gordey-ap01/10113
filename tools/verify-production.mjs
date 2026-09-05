import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { spawn } from "node:child_process";

const chrome = "C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe";
const debugPort = 9334;
const baseUrl = process.argv[2] || "http://127.0.0.1:8096";
const screenshotDir = path.resolve(".qa-production");
const userDataDir = path.join(os.tmpdir(), `service-101-production-${Date.now()}`);
const failures = [];
const consoleErrors = [];

const browser = spawn(chrome, [
  "--headless=new",
  "--no-sandbox",
  "--disable-gpu",
  "--no-first-run",
  "--no-default-browser-check",
  `--remote-debugging-port=${debugPort}`,
  `--user-data-dir=${userDataDir}`,
  "about:blank",
]);

try {
  const wsUrl = await waitForWebSocketUrl();
  const cdp = await connect(wsUrl);
  await cdp.send("Page.enable");
  await cdp.send("Runtime.enable");
  cdp.on("Runtime.exceptionThrown", (event) => {
    consoleErrors.push(event.exceptionDetails?.text || "Runtime exception");
  });
  cdp.on("Runtime.consoleAPICalled", (event) => {
    if (event.type === "error") {
      consoleErrors.push(event.args?.map((arg) => arg.value || arg.description).join(" ") || "console.error");
    }
  });

  await setViewport(cdp, 1440, 1000, false);
  await navigate(cdp, `${baseUrl}/`);
  await expect(cdp, "home rendered", "document.body.classList.contains('home-page')");
  await expect(cdp, "no repair links", "document.querySelector('a[href*=\"/remont/\"]') === null");
  await expect(cdp, "six request categories", "document.querySelectorAll('.cat-card[data-request-device]').length === 6");
  await expect(cdp, "category requests use contact anchor", "[...document.querySelectorAll('.cat-card')].every((link) => link.getAttribute('href') === '#contacts')");
  await expect(cdp, "forms use local endpoint", "[...document.querySelectorAll('form[data-email-form]')].every((form) => form.action.startsWith(location.origin + '/') && form.action.endsWith('/api/send-request.php'))");
  await expect(cdp, "forms have spam protection", "[...document.querySelectorAll('form[data-email-form]')].every((form) => form.querySelector('[name=\"website\"]') && form.querySelector('[data-form-status]'))");
  await expect(cdp, "forms require customer email", "[...document.querySelectorAll('form[data-email-form]')].every((form) => form.querySelector('input[type=\"email\"][name=\"Email\"][required]'))");
  await capture(cdp, "home-desktop.png");
  await clickCenter(cdp, '.cat-card[data-request-device="Ноутбук"]');
  await delay(300);
  await expect(cdp, "category opens contact form", "location.hash === '#contacts'");
  await expect(cdp, "category prefills device", "document.querySelector('#contacts select[name=\"Тип устройства\"]')?.value === 'Ноутбук'");
  await expect(cdp, "desktop has no horizontal overflow", "document.documentElement.scrollWidth <= innerWidth + 2");
  await capture(cdp, "contacts-desktop.png");

  await setViewport(cdp, 390, 900, true);
  await navigate(cdp, `${baseUrl}/`);
  await expect(cdp, "mobile has no horizontal overflow", "document.documentElement.scrollWidth <= innerWidth + 2");
  await expect(cdp, "mobile call action", "document.querySelector('.header-call')?.getAttribute('href') === 'tel:+79940760101'");
  await expect(cdp, "mobile repair cards open form", "[...document.querySelectorAll('.cat-card')].every((link) => link.getAttribute('href') === '#contacts')");
  await capture(cdp, "home-mobile.png");

  await setViewport(cdp, 1280, 900, false);
  await navigate(cdp, `${baseUrl}/b2b/`);
  await expect(cdp, "b2b rendered", "document.body.classList.contains('b2b-page')");
  await expect(cdp, "b2b form uses local endpoint", "document.querySelector('.b2b-form')?.action.startsWith(location.origin + '/') && document.querySelector('.b2b-form')?.action.endsWith('/api/send-request.php')");
  await expect(cdp, "b2b forms require customer email", "[...document.querySelectorAll('form[data-email-form]')].every((form) => form.querySelector('input[type=\"email\"][name=\"Email\"][required]'))");
  await expect(cdp, "b2b has no repair links", "document.querySelector('a[href*=\"/remont/\"]') === null");
  await expect(cdp, "b2b has no horizontal overflow", "document.documentElement.scrollWidth <= innerWidth + 2");
  await capture(cdp, "b2b-desktop.png");

  if (consoleErrors.length) {
    failures.push(`console errors: ${consoleErrors.join("; ")}`);
  }
} finally {
  browser.kill();
  await delay(500);
  fs.rmSync(userDataDir, { recursive: true, force: true, maxRetries: 3, retryDelay: 200 });
}

if (failures.length) {
  console.error(failures.map((failure) => `FAIL ${failure}`).join("\n"));
  process.exit(1);
}

console.log(`Production browser verification passed. Screenshots: ${screenshotDir}`);

async function waitForWebSocketUrl() {
  const deadline = Date.now() + 10000;
  while (Date.now() < deadline) {
    try {
      const response = await fetch(`http://127.0.0.1:${debugPort}/json/list`);
      const targets = await response.json();
      const page = targets.find((target) => target.type === "page" && target.webSocketDebuggerUrl);
      if (page) return page.webSocketDebuggerUrl;
    } catch {
      await delay(150);
    }
  }
  throw new Error("Chrome DevTools endpoint did not start");
}

function connect(wsUrl) {
  return new Promise((resolve, reject) => {
    const ws = new WebSocket(wsUrl);
    let id = 0;
    const pending = new Map();
    const handlers = new Map();
    ws.addEventListener("open", () => {
      resolve({
        send(method, params = {}) {
          const callId = ++id;
          ws.send(JSON.stringify({ id: callId, method, params }));
          return new Promise((ok, fail) => pending.set(callId, { ok, fail }));
        },
        eval(expression) {
          return this.send("Runtime.evaluate", { expression, awaitPromise: true, returnByValue: true });
        },
        on(method, handler) {
          handlers.set(method, handler);
        },
      });
    });
    ws.addEventListener("message", (message) => {
      const data = JSON.parse(message.data);
      if (data.id && pending.has(data.id)) {
        const item = pending.get(data.id);
        pending.delete(data.id);
        if (data.error) item.fail(new Error(data.error.message));
        else item.ok(data.result);
      } else if (data.method && handlers.has(data.method)) {
        handlers.get(data.method)(data.params);
      }
    });
    ws.addEventListener("error", reject);
  });
}

async function setViewport(cdp, width, height, mobile) {
  await cdp.send("Emulation.setDeviceMetricsOverride", { width, height, deviceScaleFactor: 1, mobile });
}

async function navigate(cdp, url) {
  await cdp.send("Page.navigate", { url });
  await delay(1400);
}

async function expect(cdp, label, expression) {
  const result = await cdp.eval(`Boolean(${expression})`);
  if (!result.result.value) failures.push(label);
}

async function clickCenter(cdp, selector) {
  const result = await cdp.eval(`(() => {
    const element = document.querySelector(${JSON.stringify(selector)});
    if (!element) return false;
    element.scrollIntoView({ block: 'center' });
    element.click();
    return true;
  })()`);
  if (!result.result.value) {
    failures.push(`click target missing: ${selector}`);
  }
}

async function capture(cdp, filename) {
  fs.mkdirSync(screenshotDir, { recursive: true });
  const result = await cdp.send("Page.captureScreenshot", { format: "png", fromSurface: true });
  fs.writeFileSync(path.join(screenshotDir, filename), Buffer.from(result.data, "base64"));
}

function delay(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}
