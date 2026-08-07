import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { spawn } from "node:child_process";
import { fileURLToPath } from "node:url";

const chrome = "C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe";
const debugPort = 9333;
const baseUrl = process.argv[2] || "http://127.0.0.1:8082";
const userDataDir = path.join(os.tmpdir(), `service-10113-chrome-${Date.now()}`);
const screenshotDir = process.env.QA_SCREENSHOTS ? path.resolve(process.env.QA_SCREENSHOTS) : null;
const failures = [];
const consoleErrors = [];
const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const csvBytes = fs.readFileSync(path.join(projectRoot, "data", "services.csv"));
if (!(csvBytes[0] === 0xef && csvBytes[1] === 0xbb && csvBytes[2] === 0xbf)) {
  failures.push("services.csv is UTF-8 with BOM for Excel");
}

const browser = spawn(chrome, [
  "--headless=new",
  "--no-sandbox",
  "--disable-software-rasterizer",
  "--disable-gpu-compositing",
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

  await setViewport(cdp, 1440, 1100, false);
  await navigate(cdp, `${baseUrl}/remont/telefony/apple/iphone-15/`);
  await waitFor(cdp, "document.querySelector('.brand-counter') && document.querySelector('.brand-chip-row') && document.querySelector('.model-chip-row')");
  await expect(
    cdp,
    "concise header repair counter",
    "document.querySelector('.site-header [data-header-counter]') !== null && document.querySelector('.header-repair-counter')?.textContent.includes('Отремонтировано')"
  );
  await expect(
    cdp,
    "header devices below large number",
    "Number.parseFloat(getComputedStyle(document.querySelector('.header-repair-counter strong')).fontSize) >= 30 && document.querySelector('.header-repair-counter small').getBoundingClientRect().top >= document.querySelector('.header-repair-counter strong').getBoundingClientRect().bottom - 1"
  );
  await expect(cdp, "header logo", "document.querySelector('.brand__logo')?.naturalWidth >= 246 && document.querySelector('.brand__logo').getBoundingClientRect().width >= 228");
  await expect(cdp, "header call action", "document.querySelector('.site-header .header-call')?.textContent.trim() === 'Позвонить' && document.querySelector('.site-header .header-call')?.getAttribute('href') === 'tel:+79940760101'");
  await expect(cdp, "desktop header phone removed", "document.querySelectorAll('.site-header .header-actions > a').length === 2 && [...document.querySelectorAll('.site-header .header-actions > a')].every((link) => !link.textContent.includes('+7 (994)'))");
  await expect(cdp, "desktop business action emphasized", "document.querySelector('.header-b2b').getBoundingClientRect().height >= 48 && Number.parseFloat(getComputedStyle(document.querySelector('.header-b2b')).fontSize) >= 16 && getComputedStyle(document.querySelector('.header-b2b')).backgroundColor !== 'rgba(255, 255, 255, 0.16)'");
  await expect(cdp, "service age removed from logo", "document.querySelector('.brand__since') === null && document.querySelector('.brand__city') === null");
  await expect(cdp, "site favicons", "document.querySelector('link[rel=\"icon\"][sizes=\"32x32\"]') !== null && document.querySelector('link[rel=\"apple-touch-icon\"]') !== null");
  await expect(
    cdp,
    "category repair counter",
    "document.querySelector('.brand-counter')?.textContent.includes('телефонов') && !document.querySelector('.brand-counter')?.textContent.includes('Apple')"
  );
  await clickCenter(cdp, '.brand-chip-row .chip[href*="/samsung/"]');
  await waitFor(cdp, "location.pathname.includes('/remont/telefony/samsung/')");
  await expect(cdp, "brand selection navigates", "location.pathname.includes('/remont/telefony/samsung/')");
  await navigate(cdp, `${baseUrl}/remont/telefony/apple/iphone-15/`);
  await waitFor(cdp, "document.querySelector('.model-chip-row')");
  await clickCenter(cdp, '.model-chip-row .chip[href*="/iphone-15-pro/"]');
  await waitFor(cdp, "location.pathname.includes('/remont/telefony/apple/iphone-15-pro/')");
  await expect(cdp, "model selection navigates", "location.pathname.includes('/remont/telefony/apple/iphone-15-pro/')");
  await navigate(cdp, `${baseUrl}/remont/telefony/apple/iphone-15/`);
  await waitFor(cdp, "document.querySelector('.catalog-tabs .tab--noutbuki')");
  await clickCenter(cdp, ".catalog-tabs .tab--noutbuki");
  await waitFor(cdp, "location.pathname.includes('/remont/noutbuki/')");
  await expect(cdp, "category selection navigates", "location.pathname.includes('/remont/noutbuki/')");
  await navigate(cdp, `${baseUrl}/remont/telefony/apple/iphone-15/`);
  await waitFor(cdp, "document.querySelectorAll('.price-row').length >= 10 && document.querySelector('.brand-counter')");
  await expect(cdp, "desktop price rows", "document.querySelectorAll('.price-row').length >= 10");
  await expect(cdp, "catalog uses local CSV", "document.documentElement.dataset.catalogSource === 'local-csv'");
  await expect(cdp, "average price with part column", "document.querySelector('.price-list__head')?.textContent.includes('Средняя цена с деталью')");
  await expect(cdp, "average price populated", "document.querySelector('.price-row__price')?.textContent.includes('После диагностики') && [...document.querySelectorAll('.price-row__price')].some((item) => item.textContent.includes('₽'))");
  await expect(cdp, "repair time grouped under service", "document.querySelector('.price-row__name .price-row__time') !== null && document.querySelector('.price-row > .price-row__time') === null");
  await expect(cdp, "price table contained", "document.querySelector('.price-list__head').getBoundingClientRect().right <= document.querySelector('.prices-panel').getBoundingClientRect().right + 1 && [...document.querySelectorAll('.price-row')].every((row) => row.getBoundingClientRect().right <= document.querySelector('.prices-panel').getBoundingClientRect().right + 1)");
  await expect(cdp, "model scroller controls", "document.querySelector('[data-model-scroller].has-overflow') !== null");
  const modelScrollBefore = await cdp.eval("document.querySelector('[data-model-row]')?.scrollLeft || 0");
  await cdp.eval("document.querySelector('.model-scroller__button--next')?.click()");
  await delay(500);
  const modelScrollAfter = await cdp.eval("document.querySelector('[data-model-row]')?.scrollLeft || 0");
  if (modelScrollAfter.result.value <= modelScrollBefore.result.value) failures.push("desktop model scroller moves");
  await expect(cdp, "category tabs have icons", "document.querySelectorAll('.catalog-tabs .tab-icon').length === 6");
  await expect(cdp, "video card and gamepad categories", "document.querySelector('.catalog-tabs .tab--videokarty .tab-icon svg') !== null && document.querySelector('.catalog-tabs .tab--gejmpady .tab-icon svg') !== null");
  await expect(cdp, "category scroller controls", "document.querySelector('[data-category-scroller].has-overflow') !== null");
  if (screenshotDir) await captureSection(cdp, ".catalog-top", "catalog-tabs-desktop.png");
  const categoryScrollBefore = await cdp.eval("document.querySelector('[data-category-row]')?.scrollLeft || 0");
  await cdp.eval("document.querySelector('.category-scroller__button--next')?.click()");
  await delay(500);
  const categoryScrollAfter = await cdp.eval("document.querySelector('[data-category-row]')?.scrollLeft || 0");
  if (categoryScrollAfter.result.value <= categoryScrollBefore.result.value) failures.push("desktop category scroller moves");
  await clickCenter(cdp, ".catalog-tabs .tab--videokarty");
  await waitFor(cdp, "location.pathname.includes('/remont/videokarty/') && document.querySelector('.catalog-tabs .tab--videokarty.active') !== null");
  await expect(cdp, "video card category navigates", "location.pathname.includes('/remont/videokarty/') && document.querySelector('.catalog-tabs .tab--videokarty.active') !== null");
  await expect(cdp, "console category replaces onsite tab", "document.querySelector('.catalog-tabs .tab--pristavki')?.textContent.includes('Приставки и консоли') && !document.querySelector('.catalog-tabs')?.textContent.includes('Выездной ремонт')");
  await expect(cdp, "active category icon present", "document.querySelector('.catalog-tabs .tab.active .tab-icon') !== null");
  await navigate(cdp, `${baseUrl}/remont/pristavki/`);
  await waitFor(cdp, "document.querySelector('.catalog-tabs .tab--pristavki.active .tab-icon svg') !== null");
  await expect(cdp, "active console icon colored", "document.querySelector('.catalog-tabs .tab--pristavki.active .tab-icon svg') !== null && getComputedStyle(document.querySelector('.catalog-tabs .tab--pristavki.active .tab-icon')).backgroundImage.includes('linear-gradient')");
  await expect(cdp, "console counter uses service baseline", "Number(document.querySelector('[data-brand-counter-value]').textContent.replace(/\\D/g, '')) >= 202 && document.querySelector('.brand-counter__brand').textContent.includes('приставок и консолей')");
  await navigate(cdp, `${baseUrl}/remont/telefony/apple/iphone-15/`);
  await waitFor(cdp, "document.querySelectorAll('.price-row').length >= 10");
  await expect(cdp, "device media tags removed", "document.querySelector('.device-media-tags') === null");
  await expect(cdp, "two device facts", "document.querySelectorAll('.device-facts span').length === 2");
  await expect(cdp, "no repeated page title", "document.querySelector('.page-title') === null");
  await expect(cdp, "no breadcrumbs", "document.querySelector('.breadcrumbs') === null");
  await expect(cdp, "no available works heading", "document.querySelector('.prices-panel__head h2') === null");
  await expect(cdp, "collapsed services button", "document.querySelector('.expand-services')?.textContent.includes('Раскройте')");
  await expect(cdp, "contact block present", "document.querySelector('.contact-section .contact-form') !== null && document.querySelector('.js-yandex-map') !== null");
  await waitFor(cdp, "['true'].includes(document.querySelector('.service-map')?.dataset.mapInitialized) || document.querySelector('.service-map')?.dataset.mapError === 'true'", 20000);
  await expect(cdp, "Yandex map has two standalone markers", `(document.querySelector('.service-map')?.dataset.markerCount === '2' || document.querySelector('.service-map')?.dataset.mapError === 'true') && document.querySelector('.service-map img[src*="static-maps.yandex.ru"]') === null`);
  if (screenshotDir) await captureSection(cdp, "#contacts", "contacts-map.png");
  await expect(cdp, "OpenStreetMap removed", "document.querySelector('link[href*=" + '"openstreetmap"' + "]') === null && document.querySelector('script[src*=" + '"leaflet"' + "]') === null && !document.documentElement.innerHTML.includes('tile.openstreetmap.org')");
  await expect(cdp, "brand counter present", "document.querySelector('[data-brand-counter-value]') !== null");
  await expect(cdp, "brand counter spans selector", "document.querySelector('.selection-shell > .brand-counter') !== null && document.querySelector('.selection-shell__main .catalog-controls') !== null");
  await expect(cdp, "brand counter blue", "getComputedStyle(document.querySelector('.selection-shell .brand-counter')).backgroundImage !== 'none' && getComputedStyle(document.querySelector('[data-brand-counter-value]')).color === 'rgb(255, 255, 255)'");
  const brandCounterBefore = await cdp.eval("document.querySelector('[data-brand-counter-value]')?.textContent");
  await delay(2300);
  const brandCounterAfter = await cdp.eval("document.querySelector('[data-brand-counter-value]')?.textContent");
  if (brandCounterBefore.result.value !== brandCounterAfter.result.value) failures.push("category counter remains stable during visit");
  await expect(cdp, "phone counter uses service baseline", "Number(document.querySelector('[data-brand-counter-value]').textContent.replace(/\\D/g, '')) >= 6527 && document.querySelector('.brand-counter__brand').textContent.includes('телефонов и планшетов')");
  await expect(cdp, "desktop no body overflow", "document.documentElement.scrollWidth <= window.innerWidth + 2");
  await expect(
    cdp,
    "service button label",
    "document.querySelector('.price-row .select-service')?.textContent.trim() === 'Выбрать'"
  );
  await cdp.eval("document.querySelectorAll('.price-row .select-service')[1].click()");
  await delay(250);
  await expect(cdp, "booking bar visible", "document.querySelector('.booking-bar.visible') !== null");
  await expect(cdp, "booking contact label", "document.querySelector('.booking-bar button')?.textContent.trim() === 'Связаться'");
  await expect(cdp, "booking contact enlarged", "document.querySelector('.booking-bar button').getBoundingClientRect().height >= 62");
  await cdp.eval("document.querySelector('.booking-bar button').click()");
  await delay(250);
  await expect(cdp, "modal visible", "document.querySelector('.modal.visible') !== null");
  await expect(cdp, "selected service checked", "document.querySelectorAll('.selected-list input:checked').length >= 1");
  await expect(cdp, "two branch cards for phone", "document.querySelectorAll('.branch-card').length === 2");
  await expect(cdp, "complete repair form", "document.querySelector('[name=\"Тип устройства\"]') !== null && document.querySelector('[name=\"Описание неисправности\"]') !== null");
  await expect(
    cdp,
    "email form action",
    "document.querySelector('.booking-form')?.action.includes('shineteatr@gmail.com')"
  );
  await expect(
    cdp,
    "contact form action",
    "document.querySelector('.contact-form')?.action.includes('shineteatr@gmail.com')"
  );
  await expect(cdp, "contact form branch", "document.querySelector('.contact-form [name=\"Филиал\"]') !== null");
  await expect(cdp, "phone info block", "document.querySelector('.device-info')?.textContent.includes('Перед ремонтом телефона')");
  await expect(cdp, "contact details promoted", "document.querySelector('.contact-head .contact-lines--lead') !== null && document.querySelector('.contact-head').compareDocumentPosition(document.querySelector('.contact-grid')) & Node.DOCUMENT_POSITION_FOLLOWING");

  await setViewport(cdp, 390, 1400, true);
  await navigate(cdp, `${baseUrl}/remont/noutbuki/apple/macbook-pro/`);
  await waitFor(cdp, "document.querySelectorAll('.price-row').length >= 5");
  await expect(cdp, "mobile content loaded", "document.querySelectorAll('.price-row').length >= 5");
  await expect(cdp, "mobile no body overflow", "document.documentElement.scrollWidth <= window.innerWidth + 2");
  await expect(
    cdp,
    "mobile device before prices",
    "document.querySelector('.device-card').getBoundingClientRect().top < document.querySelector('.prices-panel').getBoundingClientRect().top"
  );
  await expect(cdp, "mobile selectors preserve vertical scroll", "[...document.querySelectorAll('[data-horizontal-scroll]')].every((row) => { const touchAction = getComputedStyle(row).touchAction; return touchAction === 'auto' || touchAction.includes('pan-y'); })");
  await expect(cdp, "mobile model arrows contained", "getComputedStyle(document.querySelector('.model-scroller__button--next')).display === 'none' && document.querySelector('.model-scroller').getBoundingClientRect().right <= document.documentElement.clientWidth + 1");
  await expect(cdp, "mobile category arrows hidden", "getComputedStyle(document.querySelector('.category-scroller__button--next')).display === 'none' && document.querySelector('.category-scroller').getBoundingClientRect().right <= document.documentElement.clientWidth + 1");
  await expect(cdp, "laptop info block", "document.querySelector('.device-info')?.textContent.includes('Что взять вместе с ноутбуком')");
  await cdp.eval("document.querySelectorAll('.price-row .select-service')[1].click()");
  await delay(200);
  await cdp.eval("document.querySelector('.booking-bar button').click()");
  await delay(200);
  await expect(cdp, "onsite branch for laptop", "[...document.querySelectorAll('.branch-card strong')].some((item) => item.textContent.includes('Заказать выезд'))");
  await expect(cdp, "bright blurred modal", "getComputedStyle(document.querySelector('.modal')).backgroundColor !== 'rgba(2, 6, 23, 0.72)' && getComputedStyle(document.querySelector('.modal')).backdropFilter.includes('blur')");
  await expect(cdp, "booking scrollbar stays inside", "document.querySelector('.modal__dialog').scrollHeight <= document.querySelector('.modal__dialog').clientHeight + 1 && getComputedStyle(document.querySelector('.booking-form')).overflowY === 'auto'");

  await navigate(cdp, `${baseUrl}/`);
  await setViewport(cdp, 390, 900, true);
  await cdp.eval("window.scrollTo(0, 0)");
  await delay(300);
  await expect(cdp, "scroll top initially hidden", "document.querySelector('[data-scroll-top]') !== null && !document.querySelector('[data-scroll-top]').classList.contains('is-visible')");
  await expect(cdp, "home page class", "document.body.classList.contains('home-page')");
  await expect(cdp, "home light theme class", "document.body.classList.contains('home-page--light')");
  await expect(cdp, "blue header", "getComputedStyle(document.querySelector('.site-header')).backgroundImage.includes('linear-gradient')");
  await expect(cdp, "white call button", "getComputedStyle(document.querySelector('.site-header .header-call')).backgroundColor === 'rgb(255, 255, 255)'");
  await expect(cdp, "animated repair stage present", "document.querySelector('.repair-stage__pulse') !== null && document.querySelectorAll('.repair-float').length === 4");
  await expect(cdp, "repair stage links", "document.querySelector('.repair-float--phone')?.getAttribute('href') === './remont/telefony/index.html' && document.querySelector('.repair-float--laptop')?.getAttribute('href') === './remont/noutbuki/index.html' && document.querySelector('.repair-float--console')?.getAttribute('href') === './remont/pristavki/index.html' && document.querySelector('.repair-float--status')?.getAttribute('href') === '#repair-status'");
  await expect(cdp, "repair status link highlighted", "getComputedStyle(document.querySelector('.repair-float--status')).backgroundImage.includes('linear-gradient') && getComputedStyle(document.querySelector('.repair-float--status')).color === 'rgb(255, 255, 255)'");
  await expect(cdp, "mobile repair status remains prominent", "Number.parseFloat(getComputedStyle(document.querySelector('.repair-float--status')).fontSize) >= 14 && document.querySelector('.repair-float--status').getBoundingClientRect().height >= 46");
  await expect(cdp, "mobile repair links refined", "[...document.querySelectorAll('.repair-float:not(.repair-float--status)')].every((item) => { const style = getComputedStyle(item); return Number.parseFloat(style.fontSize) >= 13 && Number.parseFloat(style.fontSize) < 14 && Number.parseInt(style.fontWeight, 10) <= 500 && item.getBoundingClientRect().height >= 42; })");
  await expect(cdp, "revival counter present", "document.querySelector('[data-revival-counter]') !== null");
  await expect(cdp, "revival counter copy", "document.querySelector('.repair-stage__counter')?.textContent.includes('Устройств отремонтировано') && document.querySelector('.repair-stage__counter')?.textContent.includes('счёт продолжает расти')");
  await expect(cdp, "hero action strip", "document.querySelectorAll('.hero-action-bar .hero__actions .btn').length === 3 && document.querySelectorAll('.hero-action-bar .hero-trust span').length === 3");
  await expect(cdp, "hero trust copy", "[...document.querySelectorAll('.hero-trust span')].map((item) => item.textContent.replace(/\\s+/g, ' ').trim()).join('|').includes('2-3 часа типовой ремонт|до 12 мес гарантия|с 2016 г работаем для Вас')");
  await expect(cdp, "hero onsite action", "document.querySelector('.hero-action-bar__onsite')?.getAttribute('href') === '#onsite-service' && document.querySelector('.hero-action-bar__onsite')?.textContent.includes('Заказать выезд мастера')");
  await expect(cdp, "onsite follows specialization", "document.querySelector('#specialization').nextElementSibling?.id === 'onsite-service'");
  await expect(cdp, "reviews follow onsite", "document.querySelector('#onsite-service').nextElementSibling?.id === 'reviews'");
  await expect(cdp, "onsite section content", "document.querySelectorAll('.onsite-steps li').length === 3 && document.querySelectorAll('.onsite-scope span').length === 4");
  await expect(cdp, "onsite image source", "document.querySelector('.onsite-media img')?.getAttribute('src')?.endsWith('onsite-master.webp')");
  await expect(cdp, "onsite form", "document.querySelector('.onsite-form')?.action.includes('shineteatr@gmail.com') && document.querySelector('.onsite-form [name=\"Адрес\"]') !== null && document.querySelector('.onsite-form [name=\"Устройство\"]') !== null && document.querySelector('.onsite-form [name=\"_template\"]')?.value === 'table'");
  await expect(cdp, "all forms use configured table email", "[...document.querySelectorAll('form[data-email-form]')].every((form) => form.action.includes('shineteatr@gmail.com') && form.querySelector('[name=\"_template\"]')?.value === 'table')");
  await expect(cdp, "category background images", "document.querySelectorAll('.cat-card__image').length === 6 && [...document.querySelectorAll('.cat-card__image')].every((image) => image.getAttribute('src')?.endsWith('.png'))");
  await expect(cdp, "repair status widget", "document.querySelector('.status-widget iframe')?.src.includes('app.helloclient.by/check.html')");
  await expect(cdp, "review platform summaries", "document.querySelector('.platform-rating--yandex')?.textContent.includes('26 отзывов') && document.querySelector('.platform-rating--twogis')?.textContent.includes('239 отзывов')");
  await expect(cdp, "2gis addresses enlarged", "[...document.querySelectorAll('.platform-rating__branches a')].every((item) => Number.parseFloat(getComputedStyle(item).fontSize) >= 16)");
  await expect(cdp, "payment methods", "document.querySelectorAll('.payment-card').length === 4 && [...document.querySelectorAll('.payment-card img')].every((image) => image.getAttribute('src')?.endsWith('.webp'))");
  const revivalDuring = await cdp.eval("Number(document.querySelector('[data-revival-counter]')?.textContent.replace(/\\D/g, '') || 0)");
  if (revivalDuring.result.value < 8545) failures.push("revival counter uses service baseline");
  await expect(cdp, "hero and header counters agree", "document.querySelector('[data-revival-counter]').textContent === document.querySelector('[data-header-counter]').textContent");
  await delay(2600);
  const revivalReady = await cdp.eval("Number(document.querySelector('[data-revival-counter]')?.textContent.replace(/\\D/g, '') || 0)");
  if (revivalReady.result.value !== revivalDuring.result.value) failures.push("repair counter remains stable during visit");
  await waitFor(cdp, "document.querySelector('.light-hero__media img')?.naturalWidth > 1000");
  await expect(cdp, "home mobile no body overflow", "document.documentElement.scrollWidth <= window.innerWidth + 2");
  await expect(cdp, "mobile business shortcut", "getComputedStyle(document.querySelector('.site-header .header-b2b')).display === 'none' && getComputedStyle(document.querySelector('.mobile-b2b-strip')).display === 'flex' && getComputedStyle(document.querySelector('.mobile-b2b-strip')).position !== 'fixed' && document.querySelector('.site-header').nextElementSibling?.matches('.mobile-b2b-strip') && document.querySelector('.mobile-b2b-strip').getBoundingClientRect().width >= document.documentElement.clientWidth - 1");
  await expect(cdp, "mobile call action", "getComputedStyle(document.querySelector('.site-header .header-call')).display !== 'none' && document.querySelector('.site-header .header-call')?.getAttribute('href') === 'tel:+79940760101'");
  await expect(cdp, "mobile business shortcut label", "document.querySelector('.mobile-b2b-strip')?.textContent.includes('Организациям') && Number.parseFloat(getComputedStyle(document.querySelector('.mobile-b2b-strip')).fontSize) >= 14 && document.querySelector('.mobile-b2b-strip').getBoundingClientRect().height >= 42");
  await expect(cdp, "mobile business shortcut highlighted", "getComputedStyle(document.querySelector('.mobile-b2b-strip')).backgroundImage.includes('linear-gradient') && getComputedStyle(document.querySelector('.mobile-b2b-strip')).borderBottomColor === 'rgb(250, 204, 21)'");
  await expect(cdp, "mobile header counter does not overlap booking", "document.querySelector('.header-repair-counter').getBoundingClientRect().right <= document.querySelector('.site-header .btn-primary').getBoundingClientRect().left + 1");
  await expect(cdp, "mobile logo enlarged", "document.querySelector('.brand__logo').getBoundingClientRect().width >= 140");
  await expect(cdp, "mobile header names repaired count", "getComputedStyle(document.querySelector('.header-repair-counter > span')).display !== 'none' && document.querySelector('.header-repair-counter > span').textContent.includes('Отремонтировано')");
  await expect(cdp, "mobile contacts lead", "document.querySelector('.contact-lines--lead').getBoundingClientRect().top < document.querySelector('.contact-head__text').getBoundingClientRect().top && Number.parseFloat(getComputedStyle(document.querySelector('.contact-lines--lead a')).fontSize) >= 27");
  await expect(cdp, "light hero image remains visible", "getComputedStyle(document.querySelector('.light-hero__media img')).display !== 'none'");
  if (screenshotDir) await captureSection(cdp, ".light-hero", "hero-mobile.png");
  await expect(cdp, "mobile onsite action full row", "document.querySelector('.hero-action-bar__onsite').getBoundingClientRect().width > document.querySelector('.hero-action-bar__primary').getBoundingClientRect().width * 1.8");
  await expect(cdp, "mobile onsite order", "document.querySelector('.onsite-media').getBoundingClientRect().top < document.querySelector('.onsite-form').getBoundingClientRect().top");
  await expect(cdp, "mobile category card composition", "[...document.querySelectorAll('.cat-card')].every((card) => { const cardBox = card.getBoundingClientRect(); const title = card.querySelector('.cat-title').getBoundingClientRect(); const icon = card.querySelector('.cat-icon').getBoundingClientRect(); const content = card.querySelector('.cat-card__content').getBoundingClientRect(); const action = card.querySelector('.cat-open').getBoundingClientRect(); return Math.abs((title.left + title.width / 2) - (cardBox.left + cardBox.width / 2)) <= 2 && icon.left < content.left && content.left >= icon.right + 10 && content.top >= title.bottom + 8 && action.left >= content.left && action.top >= Math.min(content.bottom, icon.bottom); })");
  await expect(cdp, "mobile category images hidden for direct navigation", "[...document.querySelectorAll('.cat-card__image')].every((image) => getComputedStyle(image).display === 'none')");
  if (screenshotDir) await captureSection(cdp, "#specialization", "specialization-mobile.png");
  await expect(cdp, "mobile payment copy visible", "[...document.querySelectorAll('.payment-card')].every((card) => { const image = card.querySelector('img').getBoundingClientRect(); const copy = card.querySelector(':scope > div').getBoundingClientRect(); const title = card.querySelector('h3').getBoundingClientRect(); const text = card.querySelector('p').getBoundingClientRect(); return image.bottom <= copy.top + 1 && title.height > 0 && text.height > 0 && copy.bottom <= card.getBoundingClientRect().bottom + 1; })");
  await expect(cdp, "footer contacts use two rows", "getComputedStyle(document.querySelector('.footer__contacts')).display === 'grid' && document.querySelectorAll('.footer__contacts > a').length === 2 && document.querySelector('.footer__contacts a:last-child').getBoundingClientRect().top > document.querySelector('.footer__contacts a:first-child').getBoundingClientRect().top");
  if (screenshotDir) await captureSection(cdp, "#payments", "payments-mobile.png");
  await cdp.eval("window.scrollTo(0, 900)");
  await delay(250);
  await expect(cdp, "mobile business shortcut scrolls away", "document.querySelector('.mobile-b2b-strip').getBoundingClientRect().bottom < 0 && Math.abs(document.querySelector('.site-header').getBoundingClientRect().top) <= 1");
  await expect(cdp, "scroll top becomes visible", "document.querySelector('[data-scroll-top]').classList.contains('is-visible') && getComputedStyle(document.querySelector('[data-scroll-top]')).pointerEvents === 'auto'");
  await cdp.eval("document.querySelector('[data-scroll-top]').click()");
  await delay(1200);
  await expect(cdp, "scroll top returns to page start", "window.scrollY < 20");
  await cdp.eval("document.querySelector('.service-map').scrollIntoView({ block: 'start' })");
  await delay(300);
  await expect(cdp, "map stays behind fixed header", "document.elementFromPoint(Math.round(innerWidth / 2), 20)?.closest('.site-header') !== null && Number.parseInt(getComputedStyle(document.querySelector('.site-header')).zIndex, 10) > Number.parseInt(getComputedStyle(document.querySelector('.service-map')).zIndex || '0', 10)");
  if (screenshotDir) await captureSection(cdp, ".service-map", "contacts-map-mobile.png");

  await setViewport(cdp, 320, 900, true);
  await navigate(cdp, `${baseUrl}/`);
  await expect(cdp, "small mobile no body overflow", "document.documentElement.scrollWidth <= window.innerWidth + 2");
  await expect(cdp, "small mobile hero actions fit", "document.querySelector('.hero-action-bar .hero__actions').getBoundingClientRect().right <= document.documentElement.clientWidth + 1");
  await expect(cdp, "small mobile header fits", "[...document.querySelectorAll('.site-header__inner > *')].every((item) => item.getBoundingClientRect().right <= document.documentElement.clientWidth + 1)");

  await setViewport(cdp, 1280, 900, false);
  await navigate(cdp, `${baseUrl}/`);
  await expect(cdp, "desktop repair status remains prominent", "Number.parseFloat(getComputedStyle(document.querySelector('.repair-float--status')).fontSize) >= 22 && document.querySelector('.repair-float--status').getBoundingClientRect().height >= 62");
  await expect(cdp, "desktop repair links refined", "[...document.querySelectorAll('.repair-float:not(.repair-float--status)')].every((item) => { const style = getComputedStyle(item); return Number.parseFloat(style.fontSize) >= 18 && Number.parseFloat(style.fontSize) < 19 && Number.parseInt(style.fontWeight, 10) <= 500 && item.getBoundingClientRect().height >= 52; })");
  await expect(cdp, "desktop repair links clear the counter", "[...document.querySelectorAll('.repair-float:not(.repair-float--status)')].every((item) => { const itemBox = item.getBoundingClientRect(); const counterBox = document.querySelector('.repair-stage__counter').getBoundingClientRect(); return itemBox.right < counterBox.left || itemBox.left > counterBox.right || itemBox.bottom < counterBox.top || itemBox.top > counterBox.bottom; })");
  await expect(cdp, "desktop repair stage links fit viewport", "[...document.querySelectorAll('.repair-float')].every((item) => { const box = item.getBoundingClientRect(); return box.left >= 0 && box.right <= document.documentElement.clientWidth; })");
  if (screenshotDir) await captureSection(cdp, ".light-hero", "hero-desktop.png");
  await expect(
    cdp,
    "category images fill cards",
    "[...document.querySelectorAll('.cat-card')].every((card) => { const image = card.querySelector('.cat-card__image'); const a = card.getBoundingClientRect(); const b = image.getBoundingClientRect(); return b.width >= a.width - 2 && b.height >= a.height - 2 && getComputedStyle(image).objectFit === 'cover'; })"
  );
  await expect(cdp, "category titles centered with left icons", "[...document.querySelectorAll('.cat-card')].every((card) => { const cardBox = card.getBoundingClientRect(); const title = card.querySelector('.cat-title').getBoundingClientRect(); const icon = card.querySelector('.cat-icon').getBoundingClientRect(); const content = card.querySelector('.cat-card__content').getBoundingClientRect(); return Math.abs((title.left + title.width / 2) - (cardBox.left + cardBox.width / 2)) <= 2 && title.top - cardBox.top <= 24 && icon.width >= 66 && icon.left < content.left && content.left >= icon.right + 10; })");
  await expect(cdp, "specialization heading aligned with description", "Math.abs(document.querySelector('#specialization .section-title').getBoundingClientRect().left - document.querySelector('#specialization .section-text').getBoundingClientRect().left) <= 1");
  if (screenshotDir) await captureSection(cdp, ".payment-grid", "payments-desktop.png");
  await expect(cdp, "desktop payment cards are compact and contained", "getComputedStyle(document.querySelector('.payment-grid')).gridTemplateColumns.split(' ').length === 4 && Number.parseFloat(getComputedStyle(document.querySelector('.payment-grid')).columnGap) >= 10 && document.querySelector('.payment-grid').getBoundingClientRect().width <= document.querySelector('#payments .container').getBoundingClientRect().width + 1 && [...document.querySelectorAll('.payment-card')].every((card) => { const cardBox = card.getBoundingClientRect(); const image = card.querySelector('img').getBoundingClientRect(); const copy = card.querySelector(':scope > div').getBoundingClientRect(); return cardBox.width < innerWidth / 4 && cardBox.height <= innerHeight * 0.75 && cardBox.height >= 500 && image.height <= innerHeight * 0.75 && image.left >= cardBox.left && image.right <= cardBox.right + 1 && copy.bottom <= cardBox.bottom + 1; })");
  await expect(cdp, "desktop cta actions moved right", "document.querySelector('.cta-actions').getBoundingClientRect().left > document.querySelector('.cta-band').getBoundingClientRect().left + document.querySelector('.cta-band').getBoundingClientRect().width * 0.55 && [...document.querySelectorAll('.cta-actions .btn')].every((button) => button.getBoundingClientRect().height >= 50)");
  if (screenshotDir) await captureSection(cdp, ".cta-band", "cta-desktop.png");
  if (screenshotDir) await captureSection(cdp, "#specialization", "specialization-desktop.png");
  if (screenshotDir) await captureSection(cdp, "#payments", "payments-desktop.png");
  await expect(
    cdp,
    "review logos fill cards",
    "getComputedStyle(document.querySelector('.platform-rating--yandex'), '::before').content.includes('Яндекс') && getComputedStyle(document.querySelector('.platform-rating--twogis'), '::before').content.includes('2ГИС')"
  );
  await hoverCenter(cdp, ".platform-rating--yandex");
  const yandexHoverBackground = await cdp.eval("getComputedStyle(document.querySelector('.platform-rating--yandex')).backgroundImage");
  await hoverCenter(cdp, ".platform-rating--twogis");
  const twoGisHoverBackground = await cdp.eval("getComputedStyle(document.querySelector('.platform-rating--twogis')).backgroundImage");
  if (yandexHoverBackground.result.value !== twoGisHoverBackground.result.value) failures.push("review cards share one hover highlight");
  if (screenshotDir) await captureSection(cdp, "#reviews", "reviews-desktop.png");
  await expect(cdp, "desktop form and map aligned", "Math.abs(document.querySelector('.contact-form').getBoundingClientRect().height - document.querySelector('.contact-card').getBoundingClientRect().height) <= 2");
  await expect(cdp, "desktop contact details enlarged", "Number.parseFloat(getComputedStyle(document.querySelector('.contact-lines--lead a')).fontSize) >= 34 && Number.parseFloat(getComputedStyle(document.querySelector('.contact-lines--lead a:last-child')).fontSize) >= 24");
  if (screenshotDir) await captureSection(cdp, "#contacts", "contacts-desktop.png");
  await hoverCenter(cdp, ".header-b2b");
  await expect(cdp, "business hover contrast", "getComputedStyle(document.querySelector('.header-b2b')).backgroundColor === 'rgb(255, 255, 255)' && getComputedStyle(document.querySelector('.header-b2b')).color === 'rgb(3, 105, 161)'");
  await expect(cdp, "gray footer with white text", "getComputedStyle(document.querySelector('.footer')).backgroundColor === 'rgb(71, 85, 105)' && getComputedStyle(document.querySelector('.footer__inner')).color === 'rgb(255, 255, 255)'");
  await navigate(cdp, `${baseUrl}/b2b/`);
  await expect(cdp, "b2b page rendered", "document.body.classList.contains('b2b-page') && document.querySelectorAll('.b2b-services article').length === 4");
  await expect(cdp, "b2b slogan", "document.querySelector('.b2b-hero h1')?.textContent.includes('Ваш бизнес работает') && document.querySelector('.b2b-hero h1')?.textContent.includes('Сервисом 101')");
  await expect(cdp, "b2b form", "document.querySelector('.b2b-form')?.action.includes('shineteatr@gmail.com') && document.querySelector('.b2b-form [name=\"_template\"]')?.value === 'table'");
  await expect(cdp, "b2b no body overflow", "document.documentElement.scrollWidth <= window.innerWidth + 2");

  if (consoleErrors.length) {
    failures.push(`console errors: ${consoleErrors.join("; ")}`);
  }
} finally {
  browser.kill();
  await delay(500);
  try {
    fs.rmSync(userDataDir, { recursive: true, force: true, maxRetries: 3, retryDelay: 200 });
  } catch {
    // Chrome may keep a profile lock for a moment on Windows; it is safe to leave this temp folder.
  }
}

if (failures.length) {
  console.error(failures.map((item) => `FAIL ${item}`).join("\n"));
  process.exit(1);
}

console.log("Browser verification passed");

async function waitForWebSocketUrl() {
  const deadline = Date.now() + 10000;
  while (Date.now() < deadline) {
    try {
      const response = await fetch(`http://127.0.0.1:${debugPort}/json/list`);
      const data = await response.json();
      const page = data.find((target) => target.type === "page" && target.webSocketDebuggerUrl);
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
          return this.send("Runtime.evaluate", {
            expression,
            awaitPromise: true,
            returnByValue: true,
          });
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
  await cdp.send("Emulation.setDeviceMetricsOverride", {
    width,
    height,
    deviceScaleFactor: 1,
    mobile,
  });
}

async function navigate(cdp, url) {
  await cdp.send("Page.navigate", { url });
  await delay(1400);
}

async function expect(cdp, label, expression) {
  const result = await cdp.eval(`Boolean(${expression})`);
  if (!result.result.value) failures.push(label);
}

async function waitFor(cdp, expression, timeoutMs = 8000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const result = await cdp.eval(`Boolean(${expression})`);
    if (result.result.value) return true;
    await delay(200);
  }
  failures.push(`timed out waiting for: ${expression}`);
  return false;
}

async function clickCenter(cdp, selector) {
  const found = await cdp.eval(`(() => {
    const element = document.querySelector(${JSON.stringify(selector)});
    if (!element) return false;
    element.scrollIntoView({ block: "center", inline: "center" });
    return true;
  })()`);
  if (!found.result.value) {
    failures.push(`click target missing: ${selector}`);
    return;
  }
  await delay(400);
  const result = await cdp.eval(`(() => {
    const element = document.querySelector(${JSON.stringify(selector)});
    if (!element) return null;
    const rect = element.getBoundingClientRect();
    return { x: rect.left + rect.width / 2, y: rect.top + rect.height / 2 };
  })()`);
  const point = result.result.value;
  if (!point) {
    failures.push(`click target missing: ${selector}`);
    return;
  }
  await cdp.send("Input.dispatchMouseEvent", {
    type: "mousePressed",
    x: point.x,
    y: point.y,
    button: "left",
    clickCount: 1,
  });
  await cdp.send("Input.dispatchMouseEvent", {
    type: "mouseReleased",
    x: point.x,
    y: point.y,
    button: "left",
    clickCount: 1,
  });
}

async function captureSection(cdp, selector, filename) {
  fs.mkdirSync(screenshotDir, { recursive: true });
  await cdp.eval(`(() => {
    const element = document.querySelector(${JSON.stringify(selector)});
    if (!element) return false;
    document.documentElement.style.scrollBehavior = "auto";
    window.scrollTo(0, window.scrollY + element.getBoundingClientRect().top - 82);
    return true;
  })()`);
  await delay(500);
  const result = await cdp.send("Page.captureScreenshot", { format: "png", fromSurface: true });
  fs.writeFileSync(path.join(screenshotDir, filename), Buffer.from(result.data, "base64"));
}

async function hoverCenter(cdp, selector) {
  const result = await cdp.eval(`(() => {
    const element = document.querySelector(${JSON.stringify(selector)});
    if (!element) return null;
    const rect = element.getBoundingClientRect();
    return { x: rect.left + rect.width / 2, y: rect.top + rect.height / 2 };
  })()`);
  const point = result.result.value;
  if (!point) {
    failures.push(`hover target missing: ${selector}`);
    return;
  }
  await cdp.send("Input.dispatchMouseEvent", {
    type: "mouseMoved",
    x: point.x,
    y: point.y,
  });
  await delay(250);
}

function delay(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}
