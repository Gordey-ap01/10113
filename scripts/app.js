(function () {
  const pageStateEl = document.getElementById("page-state");
  const pageState = pageStateEl ? JSON.parse(pageStateEl.textContent) : { page: "home", root: "." };
  const root = pageState.root || ".";
  const app = document.getElementById("app");
  const formConfigUrl = `${root}/data/site-config.json`;
  const defaultFormRecipient = "shineteatr@gmail.com";
  const selectedServices = new Map();
  let formRecipient = defaultFormRecipient;
  let records = [];
  let currentServices = [];
  let currentDevice = null;
  const defaultRepairStats = {
    baselineDate: "2026-08-03",
    timezone: "Asia/Vladivostok",
    total: { value: 8545, dailyAverage: 3.344 },
    phonesAndTablets: { value: 6527, dailyAverage: 2.555 },
    computersAndLaptops: { value: 1185, dailyAverage: 1.481 },
    consoles: { value: 202, dailyAverage: 0.253 },
  };
  let repairStats = defaultRepairStats;
  const serviceMapOpenUrl = "https://yandex.ru/maps/?ll=137.026378%2C50.567777&z=12";
  let yandexMapsPromise = null;

  const branches = [
    {
      id: "vokzalnaya",
      title: "Вокзальная, 47",
      schedule: "Ежедневно 10:00-19:00",
      phone: "+7 (994) 076-01-01",
    },
    {
      id: "orehova",
      title: "Орехова, 54",
      schedule: "Ежедневно 10:00-19:00",
      phone: "+7 (994) 076-01-01",
    },
  ];

  const onsiteBranch = {
    id: "onsite",
    title: "Заказать выезд",
    schedule: "Мастер приедет к вам после согласования времени",
    phone: "+7 (994) 076-01-01",
  };

  const categoryCopy = {
    telefony: {
      title: "Телефоны",
      repairTitle: "Ремонт телефонов",
      subtitle: "Замена компонентов, стекла экрана, прошивка и извлечение данных.",
      intro: "Меняем экраны, аккумуляторы и другие компоненты, меняем стекло с сохранением оригинального дисплея, прошиваем и извлекаем данные.",
      icon: phoneIcon,
    },
    noutbuki: {
      title: "Ноутбуки",
      repairTitle: "Ремонт ноутбуков",
      subtitle: "Ремонт плат, чистка, настройка программ, апгрейд и модернизация.",
      intro: "Ремонтируем платы, перепаиваем процессоры и видеокарты, чистим, настраиваем программы, улучшаем и модернизируем ноутбуки.",
      icon: laptopIcon,
    },
    kompyutery: {
      title: "Компьютеры",
      repairTitle: "Ремонт компьютеров",
      subtitle: "Системные блоки, моноблоки, сборка, чистка, настройка и выездной ремонт.",
      intro: "Чистим и настраиваем программы, ремонтируем платы, улучшаем и модернизируем компьютеры для дома, офиса и игр.",
      icon: pcIcon,
    },
    pristavki: {
      title: "Приставки",
      repairTitle: "Ремонт игровых приставок",
      subtitle: "Пайка, прошивка, чиповка, чистка, обслуживание и ремонт джойстиков.",
      intro: "Ремонтируем и паяем приставки, прошиваем и чипуем, обслуживаем и чистим, восстанавливаем джойстики.",
      icon: consoleIcon,
    },
    videokarty: {
      title: "Видеокарты",
      repairTitle: "Ремонт видеокарт",
      subtitle: "Чистка, термопрокладки, пайка, BIOS и системы охлаждения.",
      intro: "Обслуживаем охлаждение, меняем термопрокладки, восстанавливаем питание, прошиваем BIOS и выполняем сложную пайку.",
      icon: gpuIcon,
    },
    gejmpady: {
      title: "Геймпады",
      repairTitle: "Ремонт геймпадов",
      subtitle: "Стики, кнопки, аккумуляторы, разъёмы и триггеры.",
      intro: "Ремонтируем стики, кнопки, аккумуляторы, разъёмы и триггеры контроллеров PlayStation, Xbox и Nintendo.",
      icon: gamepadIcon,
    },
  };

  const deviceInfoCopy = {
    telefony: {
      title: "Перед ремонтом телефона",
      text: "Если устройство включается, сохраните важные данные. Пароль блокировки можно сообщить мастеру при приёмке только для проверки выполненной работы.",
      items: ["Возьмите устройство без чехла", "После влаги не ставьте телефон на зарядку", "Для точной цены назовите полную модель"],
    },
    noutbuki: {
      title: "Что взять вместе с ноутбуком",
      text: "Принесите штатный блок питания, особенно если ноутбук не заряжается, выключается или работает нестабильно под нагрузкой.",
      items: ["Сохраните важные рабочие файлы", "Опишите, после чего появилась неисправность", "Для апгрейда расскажите, какие задачи стали медленными"],
    },
    kompyutery: {
      title: "Перед диагностикой компьютера",
      text: "Сообщите конфигурацию и симптомы: нет изображения, перезагрузки, шум, перегрев или медленная работа. Это поможет сразу назначить нужного мастера.",
      items: ["Периферию привозить не обязательно", "При проблемах с питанием возьмите кабель", "Для выезда укажите адрес и удобное время"],
    },
    pristavki: {
      title: "Перед ремонтом приставки",
      text: "Возьмите консоль и кабель питания. Если проблема возникает только в игре или с определённым геймпадом, принесите их вместе с приставкой.",
      items: ["Не прогревайте плату самостоятельно", "Сообщите об ошибках на экране", "После перегрева выключите консоль до диагностики"],
    },
    videokarty: {
      title: "Что сообщить мастеру по видеокарте",
      text: "Укажите модель блока питания и симптомы: артефакты, чёрный экран, вылеты драйвера, перегрев или шум вентиляторов.",
      items: ["Не используйте прогрев в духовке", "Сохраните фото или видео артефактов", "Упаковка должна защищать плату от изгиба"],
    },
    gejmpady: {
      title: "Перед ремонтом геймпада",
      text: "Опишите проблему со стиками, кнопками, триггерами, зарядкой или подключением. При нестабильной зарядке возьмите используемый кабель.",
      items: ["Не наносите смазку внутрь стиков", "Сообщите модель приставки", "После ремонта проверим контроллер на стенде"],
    },
  };

  document.addEventListener("DOMContentLoaded", () => {
    initFormDelivery();
    initRepairStatistics();
    initLightThemeMotion();
    initScrollTop();
    initGlobalBookingButtons();
    initServiceMaps();
    loadCatalog();
    if (new URLSearchParams(location.search).has("sent")) {
      setTimeout(() => openBookingModal("sent"), 400);
    }
  });

  async function loadCatalog() {
    if (!["category", "model", "onsite"].includes(pageState.page)) return;
    try {
      const catalog = await loadServiceCatalog();
      records = catalog.rows.map(normalizeRecord);
      document.documentElement.dataset.catalogSource = catalog.source;
      if (!app) return;
      if (pageState.page === "category") renderCategoryPage(pageState.category);
      if (pageState.page === "model") {
        renderModelPage(pageState.category, pageState.brand, pageState.model);
      }
      if (pageState.page === "onsite") renderOnsitePage();
      configureEmailForms(app);
      initServiceMaps();
    } catch (error) {
      if (app) {
        const message = location.protocol === "file:"
          ? "Каталог нельзя открыть напрямую с диска. Запустите локальный сервер по инструкции в README.md."
          : "Не удалось загрузить услуги и цены. Попробуйте обновить страницу или позвоните в сервис.";
        app.innerHTML = `<section class="section"><div class="container"><div class="error-box">${message}</div></div></section>`;
      }
      console.error(error);
    }
  }

  async function loadServiceCatalog() {
    const rows = await fetchServiceRows(`${root}/data/services.csv`, 8000);
    if (!hasValidServiceRows(rows)) {
      throw new Error("Local services catalog has an invalid structure");
    }
    return { rows, source: "local-csv" };
  }

  async function fetchServiceRows(url, timeoutMs) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), timeoutMs);
    try {
      const response = await fetch(url, {
        cache: "no-store",
        signal: controller.signal,
      });
      if (!response.ok) throw new Error(`Services ${response.status}`);
      return parseCSV(decodeServiceCSV(await response.arrayBuffer()));
    } finally {
      clearTimeout(timeout);
    }
  }

  function decodeServiceCSV(buffer) {
    const bytes = new Uint8Array(buffer);
    if (bytes[0] === 0xef && bytes[1] === 0xbb && bytes[2] === 0xbf) {
      return new TextDecoder("utf-8").decode(bytes.subarray(3));
    }
    if (bytes[0] === 0xff && bytes[1] === 0xfe) {
      return new TextDecoder("utf-16le").decode(bytes.subarray(2));
    }
    if (bytes[0] === 0xfe && bytes[1] === 0xff) {
      return new TextDecoder("utf-16be").decode(bytes.subarray(2));
    }

    try {
      return new TextDecoder("utf-8", { fatal: true }).decode(bytes);
    } catch {
      return new TextDecoder("windows-1251").decode(bytes);
    }
  }

  function hasValidServiceRows(rows) {
    return rows.some(
      (row) =>
        row.category_slug &&
        row.brand_slug &&
        row.model_slug &&
        Object.keys(row).length >= 10
    );
  }
  function normalizeRecord(row, index) {
    return {
      id: row.id || `service-${index}`,
      position: row["вид_работы"] || row["позиция"] || row.position || "",
      category: row["категория"] || row.category || "",
      cost: row["цена"] || row["стоимость"] || row.cost || "",
      averageCost: row["средняя_цена_с_деталью"] || row.averageCost || row["цена"] || "",
      description: row["описание"] || row.description || "",
      image: row["фото_1"] || row["ссылка_на_картинку"] || row.image || "",
      categorySlug: row.category_slug || "",
      categoryTitle: row.category_title || row["категория"] || "",
      brandSlug: row.brand_slug || "",
      brand: row["бренд"] || row.brand || "",
      modelSlug: row.model_slug || "",
      model: row["устройство"] || row.model || "",
      time: row["время_от"] || row.time || "",
      badge: row["пометка"] || row.badge || "",
      pageUrl: row.page_url || "",
    };
  }

  function parseCSV(text) {
    text = text.replace(/^\uFEFF/, "");
    let firstLine = text.split(/\r?\n/, 1)[0] || "";
    const excelSeparator = firstLine.match(/^sep=([;,\t])\s*$/i)?.[1];
    if (excelSeparator) {
      text = text.slice(firstLine.length).replace(/^\r?\n/, "");
      firstLine = text.split(/\r?\n/, 1)[0] || "";
    }
    const delimiter = excelSeparator || [";", ",", "\t"].reduce((best, candidate) => {
      const count = firstLine.split(candidate).length - 1;
      return count > best.count ? { value: candidate, count } : best;
    }, { value: ";", count: -1 }).value;
    const rows = [];
    let row = [];
    let field = "";
    let quoted = false;

    for (let i = 0; i < text.length; i += 1) {
      const char = text[i];
      const next = text[i + 1];
      if (quoted) {
        if (char === '"' && next === '"') {
          field += '"';
          i += 1;
        } else if (char === '"') {
          quoted = false;
        } else {
          field += char;
        }
      } else if (char === '"') {
        quoted = true;
      } else if (char === delimiter) {
        row.push(field);
        field = "";
      } else if (char === "\n") {
        row.push(field);
        rows.push(row);
        row = [];
        field = "";
      } else if (char !== "\r") {
        field += char;
      }
    }
    if (field || row.length) {
      row.push(field);
      rows.push(row);
    }

    const headers = (rows.shift() || []).map((header) => header.trim().replace(/^\uFEFF/, ""));
    return rows
      .filter((line) => line.some(Boolean))
      .map((line) =>
        headers.reduce((acc, header, idx) => {
          acc[header] = line[idx] || "";
          return acc;
        }, {})
      );
  }

  async function initFormDelivery() {
    configureEmailForms(document);
    document.addEventListener(
      "submit",
      (event) => {
        if (event.target instanceof HTMLFormElement && event.target.matches("[data-email-form]")) {
          configureEmailForm(event.target);
        }
      },
      true
    );

    try {
      const response = await fetch(formConfigUrl, { cache: "no-store" });
      if (!response.ok) throw new Error(`Form config ${response.status}`);
      const config = await response.json();
      if (!isEmail(config.formRecipient)) throw new Error("Invalid form recipient");
      formRecipient = config.formRecipient.trim();
      configureEmailForms(document);
    } catch (error) {
      console.warn("Form recipient config is unavailable; using the default address.", error);
    }
  }

  function configureEmailForms(scope) {
    if (!scope) return;
    scope.querySelectorAll("form[data-email-form]").forEach(configureEmailForm);
  }

  function configureEmailForm(form) {
    form.action = `https://formsubmit.co/${formRecipient}`;
    ensureHiddenFormField(form, "_template", "table");
    ensureHiddenFormField(form, "_captcha", "false");
  }

  function ensureHiddenFormField(form, name, value) {
    let input = form.querySelector(`input[type="hidden"][name="${name}"]`);
    if (!input) {
      input = document.createElement("input");
      input.type = "hidden";
      input.name = name;
      form.prepend(input);
    }
    input.value = value;
  }

  function isEmail(value) {
    return typeof value === "string" && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value.trim());
  }

  function renderCategoryPage(categorySlug) {
    const categoryRecords = records.filter((record) => record.categorySlug === categorySlug);
    if (!categoryRecords.length) {
      renderNotFound("Категория не найдена");
      return;
    }
    const active = pickDefaultDevice(categoryRecords);
    renderCatalog(active);
  }

  function renderModelPage(categorySlug, brandSlug, modelSlug) {
    const active =
      records.find(
        (record) =>
          record.categorySlug === categorySlug &&
          record.brandSlug === brandSlug &&
          record.modelSlug === modelSlug
      ) || null;
    if (!active) {
      renderNotFound("Устройство не найдено");
      return;
    }
    renderCatalog(active);
  }

  function renderOnsitePage() {
    const onsiteRecords = records.filter((record) =>
      ["noutbuki", "kompyutery"].includes(record.categorySlug)
    );
    const active = pickDefaultDevice(onsiteRecords);
    if (!active) {
      renderNotFound("Выездной ремонт пока не настроен");
      return;
    }
    renderCatalog(active, { onsite: true });
  }

  function renderCatalog(activeRecord, options = {}) {
    const category = categoryCopy[activeRecord.categorySlug] || {
      title: activeRecord.category,
      repairTitle: activeRecord.categoryTitle,
      subtitle: "",
      intro: "",
      icon: pcIcon,
    };
    const categoryRecords = records.filter((record) => record.categorySlug === activeRecord.categorySlug);
    const brands = uniqueBy(categoryRecords, "brandSlug");
    const activeBrandRecords = categoryRecords.filter(
      (record) => record.brandSlug === activeRecord.brandSlug
    );
    const models = uniqueBy(activeBrandRecords, "modelSlug");
    currentServices = getServices(activeRecord);
    currentDevice = {
      categorySlug: activeRecord.categorySlug,
      category: category.title,
      brand: activeRecord.brand,
      model: formatDeviceName(activeRecord.brand, activeRecord.model),
      image: activeRecord.image,
    };

    app.innerHTML = `
      <div class="page-shell">
        <section class="catalog-top">
          <div class="container">
            <div class="selection-shell">
              <div class="selection-shell__main">
                <div class="category-scroller" data-category-scroller>
                  <button class="category-scroller__button category-scroller__button--prev" type="button" aria-label="Предыдущие категории">‹</button>
                  <div class="catalog-tabs" data-category-row data-horizontal-scroll>
                    ${renderTopTabs(activeRecord.categorySlug)}
                  </div>
                  <button class="category-scroller__button category-scroller__button--next" type="button" aria-label="Следующие категории">›</button>
                </div>
                <div class="catalog-controls">
                  <div>
                    <p class="control-label">Бренд / тип устройства</p>
                    <div class="chip-row brand-chip-row" data-horizontal-scroll>
                      ${brands
                        .map((brand) => {
                          const firstModel = categoryRecords.find((record) => record.brandSlug === brand.brandSlug);
                          return `<a class="chip ${brand.brandSlug === activeRecord.brandSlug ? "active" : ""}" href="${modelHref(
                            firstModel
                          )}">${escapeHTML(brand.brand)}</a>`;
                        })
                        .join("")}
                    </div>
                  </div>
                  <div>
                    <p class="control-label">Модель</p>
                    <div class="model-scroller" data-model-scroller>
                      <button class="model-scroller__button model-scroller__button--prev" type="button" aria-label="Предыдущие модели">‹</button>
                      <div class="chip-row model-chip-row" data-model-row data-horizontal-scroll>
                        ${models
                          .map(
                            (model) =>
                              `<a class="chip ${model.modelSlug === activeRecord.modelSlug ? "active" : ""}" href="${modelHref(
                                model
                              )}">${escapeHTML(model.model)}</a>`
                          )
                          .join("")}
                      </div>
                      <button class="model-scroller__button model-scroller__button--next" type="button" aria-label="Следующие модели">›</button>
                    </div>
                  </div>
                </div>
              </div>
              ${renderCategoryCounter(activeRecord, options.onsite)}
            </div>
          </div>
        </section>

        <section class="section catalog-section">
          <div class="container">
            <div class="device-workspace">
              ${renderDeviceCard(activeRecord, category, options)}
              <div class="prices-column">
                ${renderPricePanel(activeRecord, category)}
                ${renderDeviceInfo(activeRecord.categorySlug)}
              </div>
            </div>
          </div>
        </section>
        ${renderContactSection()}
      </div>
    `;

    bindServiceButtons();
    bindModelScroller();
    bindHorizontalScroll();
    syncBookingBar();
    applyRepairStatistics();
  }

  function renderTopTabs(activeSlug) {
    const tabs = [
      { slug: "telefony", label: "Телефоны", href: `${root}/remont/telefony/index.html` },
      { slug: "noutbuki", label: "Ноутбуки", href: `${root}/remont/noutbuki/index.html` },
      { slug: "kompyutery", label: "Компьютеры", href: `${root}/remont/kompyutery/index.html` },
      { slug: "pristavki", label: "Приставки и консоли", href: `${root}/remont/pristavki/index.html` },
      { slug: "videokarty", label: "Видеокарты", href: `${root}/remont/videokarty/index.html` },
      { slug: "gejmpady", label: "Геймпады", href: `${root}/remont/gejmpady/index.html` },
    ];
    return tabs
      .map((tab) => {
        const active = tab.slug === activeSlug;
        const visualSlug = tab.slug;
        return `<a class="tab tab--${visualSlug} ${active ? "active" : ""}" href="${tab.href}">
          <span class="tab-icon tab-icon--${visualSlug}" aria-hidden="true">${tabIcon(visualSlug)}</span>
          <span>${tab.label}</span>
        </a>`;
      })
      .join("");
  }

  function tabIcon(slug) {
    const icons = {
      telefony: phoneIcon,
      noutbuki: laptopIcon,
      kompyutery: pcIcon,
      pristavki: consoleIcon,
      videokarty: gpuIcon,
      gejmpady: gamepadIcon,
    };
    return (icons[slug] || pcIcon)();
  }

  function renderDeviceCard(activeRecord, category, options = {}) {
    const deviceName = formatDeviceName(activeRecord.brand, activeRecord.model);
    const title = options.onsite
      ? `Выездной ремонт: ${deviceName}`
      : deviceName;
    return `
      <article class="device-card">
        <div class="device-card__media">
          ${
            activeRecord.image
              ? `<img src="${escapeAttr(activeRecord.image)}" alt="${escapeAttr(title)}" loading="eager">`
              : `<div class="device-card__placeholder" aria-hidden="true"></div>`
          }
        </div>
        <div class="device-card__body">
          <p class="device-kicker">${escapeHTML(category.title)}</p>
          <h2 class="device-title">${escapeHTML(title)}</h2>
          <p class="device-description">${escapeHTML(
            options.onsite
              ? "Мастер приедет домой, в офис или на производство. Возможен ремонт на месте или доставка устройства в сервис после диагностики."
              : category.intro
          )}</p>
          <div class="device-facts">
            <span><strong>от 30 мин</strong> типовой ремонт</span>
            <span><strong>до 12 мес</strong> гарантия</span>
          </div>
        </div>
      </article>
    `;
  }

  function renderPricePanel(activeRecord, category) {
    const services = getServices(activeRecord);
    const firstServices = services.slice(0, 7);
    const extraServices = services.slice(7);
    return `
      <article class="prices-panel">
        <div class="prices-panel__head">
          <div>
            <p class="eyebrow">Цена работы без детали</p>
            <p>${escapeHTML(category.subtitle)}</p>
          </div>
          <span class="warranty-pill">Гарантия до 12 мес</span>
        </div>
        <div class="price-list">
          <div class="price-list__head" aria-hidden="true">
            <span>Работа и срок</span>
            <span>Средняя цена с деталью</span>
            <span></span>
          </div>
          ${firstServices.map(renderServiceRow).join("")}
          ${
            extraServices.length
              ? `<div class="extra-services" hidden>${extraServices.map(renderServiceRow).join("")}</div>
                <button class="expand-services" type="button" data-expanded="false">Раскройте весь список услуг</button>`
              : ""
          }
        </div>
      </article>
    `;
  }

  function renderServiceRow(service) {
    const free = /бесплатно/i.test(service.cost);
    const selected = selectedServices.has(service.id);
    return `
      <div class="price-row ${selected ? "selected" : ""}" data-service-id="${escapeAttr(service.id)}">
        <div class="price-row__name">
          <div class="price-row__title">
            ${escapeHTML(service.position)}
            ${service.badge ? `<span class="badge">${escapeHTML(service.badge)}</span>` : ""}
          </div>
          <div class="price-row__time">${escapeHTML(service.time || "по согласованию")}</div>
          <div class="price-row__desc">${escapeHTML(service.description)}</div>
        </div>
        <div class="price-row__price ${free ? "free" : ""}">
          <span class="price-row__caption">С деталью</span>
          ${escapeHTML(service.averageCost)}
        </div>
        <button class="select-service" type="button">${selected ? "Выбрано" : "Выбрать"}</button>
      </div>
    `;
  }

  function renderDeviceInfo(categorySlug) {
    const info = deviceInfoCopy[categorySlug] || {
      title: "Перед визитом в сервис",
      text: "Расскажите мастеру, что произошло с устройством и после чего появилась неисправность.",
      items: ["Согласуем состав работ", "Назовём срок после диагностики", "Предоставим гарантию на ремонт"],
    };
    return `
      <aside class="device-info" aria-label="Полезная информация перед ремонтом">
        <div class="device-info__copy">
          <p class="eyebrow">Важно знать</p>
          <h3>${escapeHTML(info.title)}</h3>
          <p>${escapeHTML(info.text)}</p>
        </div>
        <ul>
          ${info.items.map((item) => `<li>${escapeHTML(item)}</li>`).join("")}
        </ul>
        <p class="device-info__price">Стоимость работы указана без детали. Полную цену с запчастью мастер подтвердит после диагностики или уточнения модели. Можно выбрать несколько услуг и отправить одну заявку — с вами сразу свяжется нужный мастер.</p>
      </aside>
    `;
  }

  function renderCategoryCounter(activeRecord, onsite) {
    const counter = getCategoryCounter(activeRecord, onsite);
    return `
      <div class="brand-counter" data-brand-counter>
        <span class="brand-counter__label">Отремонтировано</span>
        <strong data-brand-counter-value>${formatNumber(counter.value)}</strong>
        <span class="brand-counter__brand">${escapeHTML(counter.label)}</span>
      </div>
    `;
  }

  function getCategoryCounter(activeRecord, onsite) {
    if (onsite) return { value: estimateRepairCount("total"), label: "устройств всего" };
    return (
      {
        telefony: { value: estimateRepairCount("phonesAndTablets"), label: "телефонов и планшетов" },
        noutbuki: { value: estimateRepairCount("computersAndLaptops"), label: "компьютеров и ноутбуков" },
        kompyutery: { value: estimateRepairCount("computersAndLaptops"), label: "компьютеров и ноутбуков" },
        pristavki: { value: estimateRepairCount("consoles"), label: "приставок и консолей" },
      }[activeRecord.categorySlug] || { value: estimateRepairCount("total"), label: "устройств всего" }
    );
  }

  function renderContactSection() {
    return `
      <section id="contacts" class="section section-gray contact-section">
        <div class="container">
          <div class="contact-head">
            <div class="section-head">
              <p class="eyebrow">Контакты</p>
              <h2 class="section-title">Остались вопросы? Свяжитесь - бесплатная консультация!</h2>
            </div>
            <div class="contact-lines contact-lines--lead" aria-label="Телефон и электронная почта">
              <a href="tel:+79940760101">+7 (994) 076-01-01</a>
              <a href="mailto:101kms@mail.ru">101kms@mail.ru</a>
            </div>
            <p class="section-text contact-head__text">Филиалы работают ежедневно с 10:00 до 19:00 без перерывов и выходных. Можно приехать в сервис или оставить заявку на выезд мастера.</p>
          </div>
          <div class="contact-grid">
            <form class="contact-form" action="https://formsubmit.co/" method="POST" data-email-form>
              <input type="hidden" name="_subject" value="Заявка с сайта Сервис 101">
              <input type="hidden" name="_template" value="table">
              <input type="hidden" name="_captcha" value="false">
              <input class="input" type="text" name="Имя" placeholder="Ваше имя" required>
              <input class="input" type="tel" name="Телефон" placeholder="+7 (___) ___-__-__" required>
              <select class="input" name="Тип устройства" required>
                <option value="">Тип устройства</option>
                <option>Смартфон или планшет</option>
                <option>Ноутбук</option>
                <option>Компьютер</option>
                <option>Игровая приставка</option>
                <option>Геймпад</option>
                <option>Другое</option>
              </select>
              <textarea class="input" name="Описание" rows="5" placeholder="Опишите неисправность"></textarea>
              <select class="input" name="Филиал" required>
                <option value="">Выберите филиал</option>
                <option>ул. Вокзальная, 47 · ежедневно 10:00-19:00</option>
                <option>ул. Орехова, 54 · ежедневно 10:00-19:00</option>
                <option>Нужен выезд мастера</option>
              </select>
              <button class="btn btn-primary" type="submit">Отправить заявку</button>
            </form>
            <div class="contact-card">
              <div class="branch-list">
                <a class="branch-item" href="https://go.2gis.com/ooow7o" target="_blank" rel="noreferrer">
                  <strong>ул. Вокзальная, 47</strong>
                  <span>Ежедневно 10:00-19:00</span>
                </a>
                <a class="branch-item" href="https://go.2gis.com/8z19r" target="_blank" rel="noreferrer">
                  <strong>ул. Орехова, 54</strong>
                  <span>Ежедневно 10:00-19:00</span>
                </a>
              </div>
              ${renderServiceMap()}
            </div>
          </div>
        </div>
      </section>
    `;
  }

  function renderServiceMap() {
    return `<div class="map-frame service-map js-yandex-map" role="region" aria-label="Карта двух филиалов Сервиса 101">
      <div class="service-map__fallback">
        <strong>Два филиала Сервиса 101</strong>
        <span>Загружаем интерактивную Яндекс Карту...</span>
        <a href="${serviceMapOpenUrl}" target="_blank" rel="noreferrer">Открыть в Яндекс Картах</a>
      </div>
    </div>`;
  }

  function initServiceMaps() {
    const mapElements = [...document.querySelectorAll(".js-yandex-map:not([data-map-loading])")];
    if (!mapElements.length) return;

    mapElements.forEach((element) => {
      element.dataset.mapLoading = "true";
    });

    loadYandexMaps()
      .then(() => {
        let ready = false;
        const timeout = setTimeout(() => {
          if (!ready) mapElements.forEach(markServiceMapError);
        }, 6000);
        window.ymaps.ready(() => {
          ready = true;
          clearTimeout(timeout);
          mapElements.forEach(createServiceMap);
        });
      })
      .catch(() => mapElements.forEach(markServiceMapError));
  }

  function markServiceMapError(element) {
    element.dataset.mapError = "true";
    element.querySelector(".service-map__fallback span").textContent =
      "Карта временно недоступна. Откройте адреса в Яндекс Картах.";
  }

  function loadYandexMaps() {
    if (window.ymaps) return Promise.resolve(window.ymaps);
    if (yandexMapsPromise) return yandexMapsPromise;

    yandexMapsPromise = new Promise((resolve, reject) => {
      const script = document.createElement("script");
      const apiKey = document.querySelector('meta[name="yandex-maps-api-key"]')?.content.trim();
      const params = new URLSearchParams({ lang: "ru_RU" });
      const timeout = setTimeout(() => reject(new Error("Yandex Maps API timed out")), 6000);
      if (apiKey) params.set("apikey", apiKey);
      script.src = `https://api-maps.yandex.ru/2.1/?${params.toString()}`;
      script.async = true;
      script.onload = () => {
        clearTimeout(timeout);
        if (window.ymaps) resolve(window.ymaps);
        else reject(new Error("Yandex Maps API unavailable"));
      };
      script.onerror = (error) => {
        clearTimeout(timeout);
        reject(error);
      };
      document.head.append(script);
    });

    return yandexMapsPromise;
  }

  function createServiceMap(element) {
    if (element.dataset.mapInitialized === "true") return;

    const map = new window.ymaps.Map(element, {
      center: [50.566031, 137.03],
      zoom: 12,
      controls: ["zoomControl", "fullscreenControl"],
    });
    const placemarks = [
      new window.ymaps.Placemark(
        [50.546031, 136.98823],
        {
          hintContent: "Сервис 101 · Вокзальная, 47",
          balloonContent: "<strong>Сервис 101</strong><br>ул. Вокзальная, 47<br>Ежедневно 10:00-19:00",
        },
        { preset: "islands#darkBlueDotIcon" }
      ),
      new window.ymaps.Placemark(
        [50.589552, 137.064586],
        {
          hintContent: "Сервис 101 · Орехова, 54",
          balloonContent: "<strong>Сервис 101</strong><br>ул. Орехова, 54<br>Ежедневно 10:00-19:00",
        },
        { preset: "islands#darkBlueDotIcon" }
      ),
    ];

    placemarks.forEach((placemark) => map.geoObjects.add(placemark));
    map.behaviors.disable("scrollZoom");
    element.dataset.mapInitialized = "true";
    element.dataset.markerCount = String(map.geoObjects.getLength());
    element.service101Map = map;
  }

  function getServices(activeRecord) {
    return records.filter(
      (record) =>
        record.categorySlug === activeRecord.categorySlug &&
        record.brandSlug === activeRecord.brandSlug &&
        record.modelSlug === activeRecord.modelSlug
    );
  }

  function pickDefaultDevice(sourceRecords) {
    return sourceRecords[0];
  }

  function uniqueBy(sourceRecords, key) {
    const seen = new Set();
    return sourceRecords.filter((record) => {
      if (seen.has(record[key])) return false;
      seen.add(record[key]);
      return true;
    });
  }

  function modelHref(record) {
    return `${root}/remont/${record.categorySlug}/${record.brandSlug}/${record.modelSlug}/index.html`;
  }

  function formatDeviceName(brand, model) {
    const brandText = String(brand || "").trim();
    const modelText = String(model || "").trim();
    if (!brandText) return modelText;
    if (!modelText) return brandText;
    const brandWords = brandText.split(/\s+/);
    const modelWords = modelText.split(/\s+/);
    const lastBrand = brandWords[brandWords.length - 1]?.toLowerCase();
    const firstModel = modelWords[0]?.toLowerCase();
    if (lastBrand && firstModel && lastBrand === firstModel) {
      const rest = modelWords.slice(1).join(" ");
      return rest ? `${brandText} ${rest}` : brandText;
    }
    return `${brandText} ${modelText}`;
  }

  function renderNotFound(message) {
    app.innerHTML = `
      <section class="section">
        <div class="container">
          <div class="error-box">${escapeHTML(message)}. Вернитесь на <a href="${root}/index.html">главную</a>.</div>
        </div>
      </section>
    `;
  }

  function bindModelScroller() {
    document.querySelectorAll("[data-model-scroller], [data-category-scroller]").forEach((scroller) => {
      const isCategoryScroller = scroller.hasAttribute("data-category-scroller");
      const row = scroller.querySelector(isCategoryScroller ? "[data-category-row]" : "[data-model-row]");
      const previousButton = scroller.querySelector(
        isCategoryScroller ? ".category-scroller__button--prev" : ".model-scroller__button--prev"
      );
      const nextButton = scroller.querySelector(
        isCategoryScroller ? ".category-scroller__button--next" : ".model-scroller__button--next"
      );
      if (!row || !previousButton || !nextButton) return;

      const update = () => {
        const maxScroll = Math.max(0, row.scrollWidth - row.clientWidth);
        scroller.classList.toggle("has-overflow", maxScroll > 2);
        previousButton.disabled = row.scrollLeft <= 2;
        nextButton.disabled = row.scrollLeft >= maxScroll - 2;
      };

      const scrollByPage = (direction) => {
        row.scrollBy({
          left: direction * Math.max(240, row.clientWidth * 0.72),
          behavior: "smooth",
        });
      };

      previousButton.addEventListener("click", () => scrollByPage(-1));
      nextButton.addEventListener("click", () => scrollByPage(1));
      row.addEventListener("scroll", update, { passive: true });
      row.addEventListener(
        "wheel",
        (event) => {
          if (row.scrollWidth <= row.clientWidth || Math.abs(event.deltaY) <= Math.abs(event.deltaX)) return;
          event.preventDefault();
          row.scrollLeft += event.deltaY;
        },
        { passive: false }
      );
      window.addEventListener("resize", update);

      requestAnimationFrame(() => {
        row.querySelector(isCategoryScroller ? ".tab.active" : ".chip.active")?.scrollIntoView({
          behavior: "auto",
          block: "nearest",
          inline: "center",
        });
        update();
      });
    });
  }

  function bindHorizontalScroll() {
    document.querySelectorAll("[data-horizontal-scroll]").forEach((row) => {
      let startX = 0;
      let startScroll = 0;
      let pointerId = null;
      let dragging = false;
      let suppressClick = false;

      row.addEventListener("pointerdown", (event) => {
        if (event.pointerType !== "mouse" || event.button !== 0) return;
        pointerId = event.pointerId;
        startX = event.clientX;
        startScroll = row.scrollLeft;
        dragging = false;
        suppressClick = false;
      });

      row.addEventListener("pointermove", (event) => {
        if (pointerId !== event.pointerId) return;
        const distance = event.clientX - startX;
        if (!dragging && Math.abs(distance) > 6) {
          dragging = true;
          row.classList.add("is-dragging");
          row.setPointerCapture?.(event.pointerId);
        }
        if (!dragging) return;
        event.preventDefault();
        row.scrollLeft = startScroll - distance;
      });

      const stop = (event) => {
        if (pointerId !== event.pointerId) return;
        suppressClick = dragging;
        row.classList.remove("is-dragging");
        if (row.hasPointerCapture?.(event.pointerId)) row.releasePointerCapture(event.pointerId);
        pointerId = null;
        dragging = false;
        setTimeout(() => {
          suppressClick = false;
        }, 0);
      };

      row.addEventListener("pointerup", stop);
      row.addEventListener("pointercancel", stop);
      row.addEventListener(
        "click",
        (event) => {
          if (!suppressClick) return;
          event.preventDefault();
          event.stopPropagation();
          suppressClick = false;
        },
        true
      );
    });
  }

  function bindServiceButtons() {
    document.querySelectorAll(".expand-services").forEach((button) => {
      button.addEventListener("click", () => {
        const extra = button.previousElementSibling;
        const expanded = button.dataset.expanded === "true";
        if (!extra) return;
        extra.hidden = expanded;
        button.dataset.expanded = expanded ? "false" : "true";
        button.textContent = expanded ? "Раскройте весь список услуг" : "Скрыть список услуг";
      });
    });

    document.querySelectorAll(".price-row").forEach((row) => {
      const id = row.dataset.serviceId;
      const service = currentServices.find((item) => item.id === id);
      row.querySelector(".select-service").addEventListener("click", () => {
        if (!service) return;
        if (selectedServices.has(id)) {
          selectedServices.delete(id);
        } else {
          selectedServices.set(id, service);
        }
        row.classList.toggle("selected", selectedServices.has(id));
        row.querySelector(".select-service").textContent = selectedServices.has(id) ? "Выбрано" : "Выбрать";
        syncBookingBar();
      });
    });
  }

  function syncBookingBar() {
    let bar = document.querySelector(".booking-bar");
    if (!bar) {
      bar = document.createElement("div");
      bar.className = "booking-bar";
      bar.innerHTML = `
        <div class="booking-bar__text"></div>
        <button class="btn btn-primary" type="button">Связаться</button>
      `;
      document.body.appendChild(bar);
      bar.querySelector("button").addEventListener("click", () => openBookingModal());
    }
    const count = selectedServices.size;
    bar.classList.toggle("visible", count > 0);
    bar.querySelector(".booking-bar__text").innerHTML =
      count > 0
        ? `<strong>${count}</strong> ${plural(count, "услуга выбрана", "услуги выбраны", "услуг выбрано")}`
        : "";
  }

  function initGlobalBookingButtons() {
    document.addEventListener("click", (event) => {
      const trigger = event.target.closest("[data-open-booking]");
      if (!trigger) return;
      event.preventDefault();
      openBookingModal();
    });
  }

  function openBookingModal(mode) {
    const modal = ensureModal();
    document.body.classList.add("modal-open");
    modal.classList.add("visible");
    if (mode === "sent") {
      modal.querySelector(".modal__head p").textContent =
        "Заявка отправлена. Мастер свяжется с вами, чтобы уточнить устройство, филиал и время.";
    }
    renderModalServices();
    renderBranchCards();
    const deviceType = modal.querySelector('[name="Тип устройства"]');
    if (deviceType && currentDevice) deviceType.value = currentDevice.categorySlug;
    updateFormHiddenFields();
  }

  function closeBookingModal() {
    const modal = document.querySelector(".modal");
    if (!modal) return;
    modal.classList.remove("visible");
    document.body.classList.remove("modal-open");
  }

  function ensureModal() {
    let modal = document.querySelector(".modal");
    if (modal) return modal;
    modal = document.createElement("div");
    modal.className = "modal";
    modal.innerHTML = `
      <div class="modal__dialog" role="dialog" aria-modal="true" aria-label="Запись на ремонт">
        <div class="modal__head">
          <div>
            <h3>Запись на ремонт</h3>
            <p>Проверьте услуги, выберите филиал и оставьте контакты.</p>
          </div>
          <button class="modal__close" type="button" aria-label="Закрыть">×</button>
        </div>
        <form class="booking-form" action="https://formsubmit.co/" method="POST" data-email-form>
          <input type="hidden" name="_subject" value="Новая заявка с сайта Сервис 101">
          <input type="hidden" name="_template" value="table">
          <input type="hidden" name="_captcha" value="false">
          <input type="hidden" name="_next" value="">
          <input type="hidden" name="Устройство" value="">
          <input type="hidden" name="Услуги" value="">
          <input type="hidden" name="Филиал" value="">

          <div class="field-grid">
            <label class="form-field form-field--wide">
              <span>Тип устройства</span>
              <select class="input" name="Тип устройства" required>
                <option value="">Выберите устройство</option>
                <option value="telefony">Телефон или планшет</option>
                <option value="noutbuki">Ноутбук</option>
                <option value="kompyutery">Компьютер</option>
                <option value="pristavki">Игровая приставка</option>
                <option value="gejmpady">Геймпад</option>
                <option value="videokarty">Видеокарта</option>
                <option value="other">Другое устройство</option>
              </select>
            </label>
            <label class="form-field">
              <span>Имя</span>
              <input class="input" type="text" name="Имя" autocomplete="name" placeholder="Как к вам обращаться" required>
            </label>
            <label class="form-field">
              <span>Телефон</span>
              <input class="input" type="tel" name="Телефон" autocomplete="tel" inputmode="tel" placeholder="+7 (___) ___-__-__" required>
            </label>
            <label class="form-field form-field--wide">
              <span>Описание неисправности</span>
              <textarea class="input" name="Описание неисправности" rows="3" placeholder="Что случилось с устройством"></textarea>
            </label>
          </div>

          <div class="form-section">
            <p>Услуги</p>
            <div class="selected-list"></div>
          </div>

          <div class="form-section">
            <p>Филиал</p>
            <div class="branch-grid"></div>
          </div>

          <button class="btn btn-dark" type="submit">Отправить заявку</button>
          <p class="form-footnote">Нажимая кнопку, вы отправляете заявку в Сервис 101. Мастер перезвонит и уточнит детали ремонта.</p>
        </form>
      </div>
    `;
    document.body.appendChild(modal);
    configureEmailForms(modal);
    modal.addEventListener("click", (event) => {
      if (event.target === modal) closeBookingModal();
    });
    modal.querySelector(".modal__close").addEventListener("click", closeBookingModal);
    modal.querySelector("form").addEventListener("submit", () => {
      updateFormHiddenFields();
    });
    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape") closeBookingModal();
    });
    return modal;
  }

  function renderModalServices() {
    const list = document.querySelector(".selected-list");
    if (!list) return;
    const source = currentServices.length ? currentServices : Array.from(selectedServices.values());
    if (!source.length) {
      list.innerHTML = `<div class="service-check">Услуги можно уточнить после звонка мастера</div>`;
      return;
    }
    list.innerHTML = source
      .map(
        (service) => `
          <label class="service-check">
            <input type="checkbox" value="${escapeAttr(service.id)}" ${
              selectedServices.has(service.id) ? "checked" : ""
            }>
            <span>${escapeHTML(service.position)} · ${escapeHTML(service.cost)}</span>
          </label>
        `
      )
      .join("");
    list.querySelectorAll("input").forEach((input) => {
      input.addEventListener("change", () => {
        const service = source.find((item) => item.id === input.value);
        if (!service) return;
        if (input.checked) selectedServices.set(service.id, service);
        else selectedServices.delete(service.id);
        refreshRenderedServiceRows();
        syncBookingBar();
        updateFormHiddenFields();
      });
    });
  }

  function renderBranchCards() {
    const grid = document.querySelector(".branch-grid");
    if (!grid) return;
    const needsOnsite = shouldShowOnsite();
    const list = needsOnsite ? [...branches, onsiteBranch] : branches;
    grid.innerHTML = list
      .map(
        (branch, index) => `
          <label class="branch-card">
            <input type="radio" name="branch_choice" value="${escapeAttr(branch.id)}" ${index === 0 ? "checked" : ""}>
            <strong>${escapeHTML(branch.title)}</strong>
            <span>${escapeHTML(branch.schedule)}</span>
            <span>${escapeHTML(branch.phone)}</span>
          </label>
        `
      )
      .join("");
    grid.querySelectorAll("input").forEach((input) =>
      input.addEventListener("change", updateFormHiddenFields)
    );
  }

  function shouldShowOnsite() {
    if (currentDevice && ["noutbuki", "kompyutery"].includes(currentDevice.categorySlug)) return true;
    return Array.from(selectedServices.values()).some((service) =>
      ["noutbuki", "kompyutery"].includes(service.categorySlug)
    );
  }

  function refreshRenderedServiceRows() {
    document.querySelectorAll(".price-row").forEach((row) => {
      const selected = selectedServices.has(row.dataset.serviceId);
      row.classList.toggle("selected", selected);
      const button = row.querySelector(".select-service");
      if (button) button.textContent = selected ? "Выбрано" : "Выбрать";
    });
  }

  function updateFormHiddenFields() {
    const form = document.querySelector(".booking-form");
    if (!form) return;
    const selected = Array.from(selectedServices.values());
    const checkedBranch = form.querySelector('input[name="branch_choice"]:checked');
    const branch = [...branches, onsiteBranch].find((item) => item.id === checkedBranch?.value) || branches[0];
    form.elements["Устройство"].value = currentDevice
      ? `${currentDevice.category}: ${currentDevice.brand} ${currentDevice.model}`
      : "Не выбрано";
    form.elements["Услуги"].value = selected.length
      ? selected.map((service) => `${service.position} (${service.cost})`).join("; ")
      : "Уточнить по телефону";
    form.elements["Филиал"].value = `${branch.title}; ${branch.schedule}; ${branch.phone}`;
    form.elements["_next"].value = `${location.origin}${location.pathname}?sent=1`;
  }

  function initRepairStatistics() {
    applyRepairStatistics();
    fetch(`${root}/data/repair-stats.json`, { cache: "no-store" })
      .then((response) => {
        if (!response.ok) throw new Error(`Repair statistics ${response.status}`);
        return response.json();
      })
      .then((stats) => {
        if (!isValidRepairStats(stats)) return;
        repairStats = stats;
        applyRepairStatistics();
      })
      .catch(() => {
        // The built-in baseline keeps the counters available if the data file cannot be loaded.
      });
  }

  function isValidRepairStats(stats) {
    return (
      typeof stats?.baselineDate === "string" &&
      ["total", "phonesAndTablets", "computersAndLaptops", "consoles"].every(
        (key) => Number.isFinite(stats[key]?.value) && Number.isFinite(stats[key]?.dailyAverage)
      )
    );
  }

  function applyRepairStatistics() {
    const total = estimateRepairCount("total");
    writeHeaderCounter(total);
    document.querySelectorAll("[data-revival-counter]").forEach((counter) => {
      counter.textContent = formatNumber(total);
    });

    const categorySlug = currentDevice?.categorySlug || pageState.category;
    const valueEl = document.querySelector("[data-brand-counter-value]");
    const labelEl = document.querySelector(".brand-counter__brand");
    if (!categorySlug || !valueEl || !labelEl) return;
    const categoryCounter = getCategoryCounter({ categorySlug }, pageState.page === "onsite");
    valueEl.textContent = formatNumber(categoryCounter.value);
    labelEl.textContent = categoryCounter.label;
  }

  function estimateRepairCount(key) {
    const stat = repairStats[key] || defaultRepairStats[key] || defaultRepairStats.total;
    const elapsedDays = getElapsedRepairDays();
    return Math.round(stat.value + stat.dailyAverage * elapsedDays);
  }

  function getElapsedRepairDays() {
    const timezone = repairStats.timezone || defaultRepairStats.timezone;
    const todayParts = Object.fromEntries(
      new Intl.DateTimeFormat("en-US", {
        timeZone: timezone,
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
      })
        .formatToParts(new Date())
        .filter((part) => part.type !== "literal")
        .map((part) => [part.type, Number(part.value)])
    );
    const baseline = repairStats.baselineDate || defaultRepairStats.baselineDate;
    const [year, month, day] = baseline.split("-").map(Number);
    const todayUtc = Date.UTC(todayParts.year, todayParts.month - 1, todayParts.day);
    const baselineUtc = Date.UTC(year, month - 1, day);
    return Math.max(0, Math.floor((todayUtc - baselineUtc) / 86400000));
  }

  function initLightThemeMotion() {
    if (!document.body.classList.contains("home-page--light")) return;
    const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    const stage = document.querySelector(".repair-stage");

    if (stage && !reducedMotion) {
      stage.addEventListener("pointermove", (event) => {
        const bounds = stage.getBoundingClientRect();
        const x = (event.clientX - bounds.left) / bounds.width - 0.5;
        const y = (event.clientY - bounds.top) / bounds.height - 0.5;
        stage.style.setProperty("--stage-x", `${(x * 4).toFixed(2)}deg`);
        stage.style.setProperty("--stage-y", `${(y * -4).toFixed(2)}deg`);
      });
      stage.addEventListener("pointerleave", () => {
        stage.style.setProperty("--stage-x", "0deg");
        stage.style.setProperty("--stage-y", "0deg");
      });
    }

    const revealItems = document.querySelectorAll(
      ".cat-card, .service-row, .feature, .platform-rating, .review-card, .contact-form, .contact-card, .onsite-media, .onsite-panel"
    );
    revealItems.forEach((item) => item.classList.add("reveal-item"));
    if (reducedMotion || !("IntersectionObserver" in window)) {
      revealItems.forEach((item) => item.classList.add("is-visible"));
      return;
    }

    const observer = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (!entry.isIntersecting) return;
          entry.target.classList.add("is-visible");
          observer.unobserve(entry.target);
        });
      },
      { rootMargin: "0px 0px -8% 0px", threshold: 0.08 }
    );
    revealItems.forEach((item) => observer.observe(item));
  }

  function initScrollTop() {
    const button = document.querySelector("[data-scroll-top]");
    if (!button) return;
    const update = () => {
      const visible = window.scrollY > Math.max(520, window.innerHeight * 0.65);
      button.classList.toggle("is-visible", visible);
      button.setAttribute("aria-hidden", visible ? "false" : "true");
      button.tabIndex = visible ? 0 : -1;
    };
    button.addEventListener("click", () => {
      window.scrollTo({
        top: 0,
        behavior: window.matchMedia("(prefers-reduced-motion: reduce)").matches ? "auto" : "smooth",
      });
    });
    window.addEventListener("scroll", update, { passive: true });
    window.addEventListener("resize", update);
    update();
  }  function writeHeaderCounter(value) {
    document.querySelectorAll("[data-header-counter]").forEach((counter) => {
      counter.textContent = formatNumber(value);
    });
  }

  function formatNumber(value) {
    return new Intl.NumberFormat("ru-RU").format(value);
  }

  function plural(count, one, few, many) {
    const mod10 = count % 10;
    const mod100 = count % 100;
    if (mod10 === 1 && mod100 !== 11) return one;
    if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) return few;
    return many;
  }

  function escapeHTML(value) {
    return String(value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function escapeAttr(value) {
    return escapeHTML(value);
  }

  function phoneIcon() {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="7" y="2.5" width="10" height="19" rx="2.4"></rect><path d="M10 6.2h4"></path><path d="M11 18h2"></path></svg>';
  }

  function laptopIcon() {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="5" width="16" height="10" rx="1.8"></rect><path d="M2.5 18.5h19"></path><path d="M7 18.5h10"></path></svg>';
  }

  function consoleIcon() {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 8.5c0-1.4 1.1-2.5 2.5-2.5h9c1.4 0 2.5 1.1 2.5 2.5V15c0 1.4-1.1 2.5-2.5 2.5h-9C6.1 17.5 5 16.4 5 15V8.5Z"></path><path d="M8 10.5h3"></path><path d="M9.5 9v3"></path><circle cx="15.6" cy="12" r="0.75"></circle><circle cx="17.7" cy="12" r="0.75"></circle></svg>';
  }

  function pcIcon() {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3.5" y="5" width="17" height="11" rx="1.8"></rect><path d="M8 19h8"></path><path d="M10 16h4"></path></svg>';
  }

  function gpuIcon() {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3.5" y="7" width="13" height="8" rx="1.8"></rect><circle cx="10" cy="11" r="2.1"></circle><path d="M18 9.5h2.5v5H18"></path><path d="M6 15v3"></path><path d="M9 15v3"></path></svg>';
  }

  function gamepadIcon() {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6.5 9.5c1.3-1.6 3.4-2.6 5.5-2.6s4.2 1 5.5 2.6c1.5 1.8 2.6 4.5 1.1 6.3-1 1.2-2.9 1.3-4.1.3l-1.1-.9c-.8-.7-1.9-.7-2.7 0l-1.1.9c-1.2 1-3.1.9-4.1-.3-1.5-1.8-.4-4.5 1.1-6.3Z"></path><path d="M8.5 11.2h2"></path><path d="M9.5 10.2v2"></path><circle cx="15.6" cy="11.2" r="0.55"></circle><circle cx="16.9" cy="12.5" r="0.55"></circle></svg>';
  }
})();
