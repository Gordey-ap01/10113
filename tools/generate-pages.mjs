import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(__dirname, "..");
const servicesPath = path.join(projectRoot, "data", "services.csv");
const serviceRows = parseCSV(decodeServiceCSV(fs.readFileSync(servicesPath)));
const catalog = buildPageCatalog(serviceRows);

const yandexUrl = "https://yandex.ru/maps/org/101/27521144258/?ll=136.988230%2C50.546031&z=17";
const twoGisUrl = "https://2gis.ru/komsomolsk-on-amur/firm/70000001053887975";
const twoGisSecondUrl = "https://2gis.ru/komsomolsk-on-amur/firm/70000001048911527";
const googleUrl =
  "https://www.google.com/search?q=%D0%A1%D0%B5%D1%80%D0%B2%D0%B8%D1%81+101+%D0%A0%D0%B5%D0%BC%D0%BE%D0%BD%D1%82+%D0%BD%D0%BE%D1%83%D1%82%D0%B1%D1%83%D0%BA%D0%BE%D0%B2+%D0%B8%D0%B3%D1%80%D0%BE%D0%B2%D1%8B%D1%85+%D0%BF%D1%80%D0%B8%D1%81%D1%82%D0%B0%D0%B2%D0%BE%D0%BA+%D1%82%D0%B5%D0%BB%D0%B5%D1%84%D0%BE%D0%BD%D0%BE%D0%B2";
const statusWidgetUrl = "https://app.helloclient.by/check.html#126833";
const serviceMapOpenUrl = "https://yandex.ru/maps/?ll=137.026378%2C50.567777&z=12";

const categoryMeta = {
  telefony: {
    text: "Смартфоны Apple, Samsung, Xiaomi, Honor и Realme с быстрым подбором работ.",
    icon: phoneIcon(),
    image: "./assets/categories/telefony.png",
    gradient: "grad-phone",
  },
  noutbuki: {
    text: "Матрицы, клавиатуры, питание, чистка, SSD и установка Windows.",
    icon: laptopIcon(),
    image: "./assets/categories/noutbuki.png",
    gradient: "grad-laptop",
  },
  kompyutery: {
    text: "Сборка, обслуживание, блоки питания, платы, ОС и выездной ремонт.",
    icon: pcIcon(),
    image: "./assets/categories/kompyutery.png",
    gradient: "grad-pc",
  },
  pristavki: {
    text: "PlayStation, Xbox, Nintendo: HDMI, питание, чистка и ремонт плат.",
    icon: consoleIcon(),
    image: "./assets/categories/pristavki.png",
    gradient: "grad-console",
  },
  videokarty: {
    text: "NVIDIA и AMD: термопрокладки, пайка, BIOS и системы охлаждения.",
    icon: gpuIcon(),
    image: "./assets/categories/videokarty.png",
    gradient: "grad-gpu",
  },
  gejmpady: {
    text: "Стики, кнопки, аккумуляторы и разъёмы контроллеров.",
    icon: gamepadIcon(),
    image: "./assets/categories/gejmpady.png",
    gradient: "grad-gamepad",
  },
};

ensureDir(path.join(projectRoot, "data"));
ensureDir(path.join(projectRoot, "scripts"));

const homePath = path.join(projectRoot, "index.html");
if (!fs.existsSync(homePath)) {
  writeFile(
    homePath,
    layout({
      root: ".",
      page: "home",
      title: "Сервис 101 - ремонт техники в Комсомольске-на-Амуре",
      description:
        "Сервис 101: ремонт телефонов, ноутбуков, компьютеров, приставок, видеокарт и геймпадов в Комсомольске-на-Амуре.",
      body: homeBody(),
      state: { page: "home", root: "." },
    })
  );
}

for (const category of catalog.categories) {
  const categoryDir = path.join(projectRoot, "remont", category.id);
  ensureDir(categoryDir);
  writeFile(
    path.join(categoryDir, "index.html"),
    layout({
      root: "../..",
      page: "category",
      title: `${category.title} | Сервис 101`,
      description: `${category.title}: выберите бренд, модель и нужные услуги в Сервис 101.`,
      body: loaderBody(category.title),
      state: { page: "category", root: "../..", category: category.id },
    })
  );

  const brands = catalog.brands[category.id] || [];
  for (const brand of brands) {
    for (const model of brand.models) {
      const modelDir = path.join(projectRoot, "remont", category.id, brand.id, model.id);
      ensureDir(modelDir);
      writeFile(
        path.join(modelDir, "index.html"),
        layout({
          root: "../../../..",
          page: "model",
          title: `Ремонт ${formatDeviceName(brand.name, model.name)} | Сервис 101`,
          description: `Ремонт ${formatDeviceName(brand.name, model.name)}: цены работ без детали, выбор нескольких услуг и запись в Сервис 101.`,
          body: loaderBody(`Ремонт ${formatDeviceName(brand.name, model.name)}`),
          state: {
            page: "model",
            root: "../../../..",
            category: category.id,
            brand: brand.id,
            model: model.id,
          },
        })
      );
    }
  }
}

const onsiteDir = path.join(projectRoot, "remont", "vyezdnoj-remont");
ensureDir(onsiteDir);
writeFile(
  path.join(onsiteDir, "index.html"),
  layout({
    root: "../..",
    page: "onsite",
    title: "Выездной ремонт компьютеров и ноутбуков | Сервис 101",
    description: "Выездной ремонт ноутбуков и компьютеров: выберите услуги и закажите выезд мастера.",
    body: loaderBody("Выездной ремонт компьютеров и ноутбуков"),
    state: { page: "onsite", root: "../..", category: "vyezdnoj-remont" },
  })
);

const b2bDir = path.join(projectRoot, "b2b");
ensureDir(b2bDir);
const b2bPath = path.join(b2bDir, "index.html");
if (!fs.existsSync(b2bPath)) {
  writeFile(
    b2bPath,
    layout({
      root: "..",
      page: "b2b",
      title: "Ремонт техники для бизнеса | Сервис 101",
      description:
        "Обслуживание офисной и производственной техники для компаний Комсомольска-на-Амуре: выезд, диагностика, ремонт и отчётные документы.",
      body: b2bBody(),
      state: { page: "b2b", root: ".." },
    })
  );
}

console.log(`Generated static pages from ${serviceRows.length} services in ${servicesPath}`);

function layout({ root, page, title, description, body, state }) {
  const bodyClass =
    page === "home" ? "home-page home-page--light" : page === "b2b" ? "inner-page b2b-page" : "inner-page";
  const homeStyles = page === "home" ? `  <link rel="stylesheet" href="${root}/styles-10108.css">\n` : "";
  return `<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>${escapeHTML(title)}</title>
  <meta name="description" content="${escapeHTML(description)}">
  <link rel="icon" type="image/png" sizes="32x32" href="${root}/assets/branding/favicon-32.png">
  <link rel="icon" type="image/png" sizes="192x192" href="${root}/assets/branding/favicon-192.png">
  <link rel="apple-touch-icon" sizes="180x180" href="${root}/assets/branding/apple-touch-icon.png">
  <link rel="stylesheet" href="${root}/styles.css">
  <link rel="stylesheet" href="${root}/styles-10107.css">
${homeStyles}  <link rel="stylesheet" href="${root}/styles-10110.css">
</head>
<body class="${bodyClass}">
${header(root)}
<main id="app">
${body}
</main>
${footer(root)}
<button class="scroll-top" type="button" data-scroll-top aria-label="Наверх" aria-hidden="true" tabindex="-1" title="Наверх">&uarr;</button>
<script id="page-state" type="application/json">${JSON.stringify(state)}</script>
<script src="${root}/scripts/app.js" defer></script>
</body>
</html>
`;
}

function header(root) {
  return `<header class="site-header">
  <div class="container site-header__inner">
    <a class="brand" href="${root}/index.html" aria-label="Сервис 101">
      <span class="brand__identity">
        <img class="brand__logo" src="${root}/assets/branding/logo.svg" alt="Сервис 101" width="246" height="42">
      </span>
    </a>
    <div class="header-repair-counter" aria-label="Всего отремонтировано устройств">
      <span>Отремонтировано</span>
      <strong data-header-counter>8 545</strong>
      <small>устройств</small>
    </div>
    <div class="header-actions">
      <a class="header-b2b" href="${root}/b2b/index.html">Организациям</a>
      <a class="btn btn-primary btn-sm header-call" href="tel:+79940760101">Позвонить</a>
    </div>
  </div>
</header>
<a class="mobile-b2b-strip" href="${root}/b2b/index.html">
  <span>Организациям</span>
  <span aria-hidden="true">&#8594;</span>
</a>`;
}

function footer(root) {
  return `<footer class="footer">
  <div class="container footer__inner">
    <div>
      <strong>Сервис 101</strong><br>
      Вокзальная, 47 · Орехова, 54 · ежедневно 10:00-19:00
    </div>
    <div class="footer__contacts">
      <a href="tel:+79940760101">+7 (994) 076-01-01</a>
      <a href="mailto:101kms@mail.ru">101kms@mail.ru</a>
    </div>
    <div class="footer__actions">
      <a href="${root}/b2b/index.html">Организациям</a>
      <a class="btn btn-primary btn-sm" href="${root}/index.html#contacts">Записаться</a>
    </div>
  </div>
</footer>`;
}

function homeBody() {
  return `<section class="hero light-hero">
  <div class="light-hero__media">
    <img src="./assets/service-101-hero.webp" alt="Мастер Сервиса 101 ремонтирует смартфон" width="1672" height="941" fetchpriority="high">
  </div>
  <div class="container hero__inner">
    <div class="hero-copy">
      <div class="hero-heading">
        <p class="eyebrow">Сервисный центр · Комсомольск-на-Амуре</p>
        <h1>Сервис 101</h1>
      </div>
      <p class="hero__lead">Ремонт цифровой техники с гарантией до 12 месяцев. Диагностируем, согласовываем цену и возвращаем устройство в работу.</p>
    </div>
    <div class="repair-stage">
      <span class="repair-stage__pulse repair-stage__pulse--one" aria-hidden="true"></span>
      <span class="repair-stage__pulse repair-stage__pulse--two" aria-hidden="true"></span>
      <div class="repair-stage__counter">
        <span>Устройств отремонтировано</span>
        <strong class="revival-counter__number" data-revival-counter>8 545</strong>
        <small>и счёт продолжает расти</small>
      </div>
      <a class="repair-float repair-float--phone" href="#contacts" data-request-device="Смартфон или планшет">Смартфоны</a>
      <a class="repair-float repair-float--laptop" href="#contacts" data-request-device="Ноутбук">Ноутбуки</a>
      <a class="repair-float repair-float--console" href="#contacts" data-request-device="Игровая приставка">Консоли</a>
      <a class="repair-float repair-float--status" href="#repair-status">Статус заказа</a>
    </div>
  </div>
  <div class="hero-action-bar">
    <div class="container hero-action-bar__inner">
      <div class="hero__actions">
        <a class="btn hero-action-bar__primary" href="#specialization">Выбрать ремонт</a>
        <a class="btn hero-action-bar__secondary" href="#contacts">Записаться</a>
      </div>
      <div class="hero-trust" aria-label="Преимущества сервиса">
        <span><strong>2-3 часа</strong> типовой ремонт</span>
        <span><strong>до 12 мес</strong> гарантия</span>
        <span><strong>с 2016 г</strong> работаем для Вас</span>
      </div>
    </div>
  </div>
</section>

<section id="specialization" class="section section-white">
  <div class="container">
    <div class="section-head">
      <p class="eyebrow">Специализация сервиса</p>
      <h2 class="section-title">Выберите направление ремонта</h2>
      <p class="section-text">Выберите направление и оставьте заявку. Мастер уточнит модель, неисправность, срок и стоимость ремонта.</p>
    </div>
    <div class="cat-grid">
      ${catalog.categories.map(categoryCard).join("")}
    </div>
  </div>
</section>

<section class="section section-gray">
  <div class="container">
    <div class="section-head">
      <p class="eyebrow">Возможности сервиса</p>
      <h2 class="section-title">Ремонтируем, обслуживаем и модернизируем технику</h2>
      <p class="section-text">Сервис 101 берётся за типовые замены, программные работы, сложную пайку, чистку и восстановление устройств после влаги или скачков напряжения.</p>
    </div>
    <div class="service-list">
      <div class="service-row"><p class="service-name">Игровые приставки и консоли</p><p class="service-desc">Ремонтируем и паяем, прошиваем и чипуем, обслуживаем и чистим, ремонтируем джойстики.</p></div>
      <div class="service-row"><p class="service-name">Смартфоны и планшеты</p><p class="service-desc">Меняем экраны, аккумуляторы и другие компоненты, меняем стекло с сохранением оригинального дисплея, прошиваем и извлекаем данные.</p></div>
      <div class="service-row"><p class="service-name">Ноутбуки и компьютеры</p><p class="service-desc">Ремонтируем платы, перепаиваем процессоры и видеокарты, чистим, настраиваем программы, улучшаем и модернизируем устройства.</p></div>
      <div class="service-row"><p class="service-name">Выездной ремонт</p><p class="service-desc">Мастер приедет домой, в офис или на производство. Возможен ремонт на месте или доставка устройства в сервис.</p></div>
      <div class="service-row"><p class="service-name">Сложный ремонт плат</p><p class="service-desc">Восстанавливаем ноутбуки и приставки после скачков напряжения, замыкания и залития.</p></div>
      <div class="service-row"><p class="service-name">Извлечение информации</p><p class="service-desc">Копируем данные с повреждённых телефонов, компьютеров и носителей информации.</p></div>
    </div>
  </div>
</section>

<section class="section section-white">
  <div class="container">
    <div class="section-head">
      <p class="eyebrow">Почему выбирают нас</p>
      <h2 class="section-title">Ремонт с понятными сроками и гарантией</h2>
    </div>
    <div class="feature-grid">
      <div class="feature"><p class="feature-title">Гарантия качества</p><p class="feature-text">Предоставляем гарантию на все виды работ и запчасти до 12 месяцев.</p></div>
      <div class="feature"><p class="feature-title">Быстрый ремонт</p><p class="feature-text">Большинство ремонтов выполняем в течение 2-3 часов, некоторые работы делаем при вас.</p></div>
      <div class="feature"><p class="feature-title">Сложные работы</p><p class="feature-text">Берёмся за ремонт плат, пайку и восстановление техники после сложных неисправностей.</p></div>
      <div class="feature"><p class="feature-title">Бесплатная диагностика</p><p class="feature-text">Проводим диагностику бесплатно при последующем ремонте в сервисе.</p></div>
    </div>
  </div>
</section>

${statusBody()}

<section class="section section-white">
  <div class="container">
    <div class="cta-band">
      <h2>Выберите устройство и отправьте заявку за минуту</h2>
      <p>Если точной модели нет, оставьте заявку без выбора услуги. Мастер уточнит деталь, филиал, срок и итоговую цену.</p>
      <div class="cta-actions">
        <a class="btn btn-primary" href="#contacts" data-request-device="Смартфон или планшет">Оставить заявку</a>
        <a class="btn btn-ghost" href="#onsite-service">Заказать выезд</a>
      </div>
    </div>
  </div>
</section>

${reviewsBody()}

${contactBody(".")}`;
}

function categoryCard(category) {
  const meta = categoryMeta[category.id] || { text: category.title, icon: pcIcon(), gradient: "grad-pc" };
  const deviceType = {
    telefony: "Смартфон или планшет",
    noutbuki: "Ноутбук",
    kompyutery: "Компьютер",
    pristavki: "Игровая приставка",
    videokarty: "Видеокарта",
    gejmpady: "Геймпад",
  }[category.id] || "Другое";
  return `<a class="cat-card" href="#contacts" data-request-device="${escapeHTML(deviceType)}">
    <img class="cat-card__image" src="${meta.image || "./assets/categories/kompyutery.png"}" alt="" loading="lazy" aria-hidden="true">
    <span class="cat-card__head">
      <span class="cat-title">${escapeHTML(category.name)}</span>
      <span class="cat-icon ${meta.gradient}">${meta.icon}</span>
    </span>
    <span class="cat-card__content">
      <span class="cat-text">${escapeHTML(meta.text)}</span>
    </span>
    <span class="cat-open">Оставить заявку <span aria-hidden="true">→</span></span>
  </a>`;
}

function statusBody() {
  return `<section id="repair-status" class="section section-gray status-section">
  <div class="container status-layout">
    <div class="status-copy">
      <p class="eyebrow">Статус ремонта</p>
      <h2 class="section-title">Узнайте, что сейчас с вашим устройством</h2>
      <p class="section-text">Введите телефон, который оставили при оформлении заказа. Данные откроются в защищённом виджете сервисной системы.</p>
      <a class="status-link" href="${statusWidgetUrl}" target="_blank" rel="noreferrer">Открыть проверку в отдельном окне</a>
    </div>
    <div class="status-widget">
      <iframe title="Проверка статуса ремонта" src="${statusWidgetUrl}" loading="lazy"></iframe>
    </div>
  </div>
</section>`;
}

function reviewsBody() {
  return `<section id="reviews" class="section section-white reviews-section">
  <div class="container">
    <div class="section-head reviews-heading">
      <p class="eyebrow">Отзывы</p>
      <h2 class="section-title">Оценки клиентов на картах</h2>
      <p class="section-text">Показываем сводные показатели официальных карточек сервиса. Нажмите на площадку, чтобы открыть отзывы.</p>
    </div>
    <div class="platform-ratings">
      <a class="platform-rating platform-rating--yandex" href="${yandexUrl}" target="_blank" rel="noreferrer">
        <span class="platform-logo platform-logo--yandex"><span>Я</span> Яндекс Карты</span>
        <strong>4.9</strong>
        <span class="platform-rating__stars">★★★★★</span>
        <span class="platform-rating__count">26 отзывов · 32 оценки</span>
        <span class="platform-rating__open">Открыть отзывы</span>
      </a>
      <div class="platform-rating platform-rating--twogis">
        <span class="platform-logo platform-logo--twogis"><span>2</span> 2ГИС · оба филиала</span>
        <strong>4.8</strong>
        <span class="platform-rating__stars">★★★★★</span>
        <span class="platform-rating__count">239 отзывов · 275 оценок</span>
        <div class="platform-rating__branches">
          <a href="${twoGisUrl}/tab/reviews" target="_blank" rel="noreferrer">Вокзальная · 160</a>
          <a href="${twoGisSecondUrl}/tab/reviews" target="_blank" rel="noreferrer">Орехова · 79</a>
        </div>
      </div>
    </div>
    <div class="review-source-note">Данные площадок проверены 28.07.2026.</div>
    <div class="review-cards">
      <article class="review-card"><p>Отличный сервис, принес телефон: не включался. Быстро определили неисправность, заменили аккумулятор, всё работает как надо.</p><strong>Алексей Буркасов</strong><span>2ГИС</span></article>
      <article class="review-card"><p>Оперативно, не более чем за час, убрали сильный дрифт левого стика DualSense. Спасибо, буду обращаться ещё.</p><strong>Владимир Пастухов</strong><span>2ГИС</span></article>
      <article class="review-card"><p>Нужно было поменять аккумулятор на смартфоне. Цена устроила, через два часа забрал готовый телефон.</p><strong>Антон Андреев</strong><span>2ГИС</span></article>
    </div>
    <div class="all-review-links">
      <a href="${googleUrl}" target="_blank" rel="noreferrer">Также открыть карточку в Google</a>
    </div>
  </div>
</section>`;
}

function b2bBody() {
  return `<section class="b2b-hero">
  <div class="container b2b-hero__inner">
    <div>
      <p class="eyebrow">Сервис 101 для бизнеса</p>
      <h1>Ваш бизнес работает<br>с Сервисом 101</h1>
      <p>Обслуживаем компьютеры, ноутбуки, телефоны, кассовое и периферийное оборудование компаний Комсомольска-на-Амуре.</p>
      <div class="b2b-hero__actions">
        <a class="btn btn-primary" href="#b2b-request">Обсудить обслуживание</a>
        <a class="btn btn-ghost" href="tel:+79940760101">+7 (994) 076-01-01</a>
      </div>
    </div>
    <div class="b2b-sla" aria-label="Условия обслуживания">
      <span><strong>от 1 часа</strong> выезд специалиста</span>
      <span><strong>до 12 мес</strong> гарантия на работы</span>
      <span><strong>2 филиала</strong> и выезд по городу</span>
      <span><strong>с документами</strong> для бухгалтерии</span>
    </div>
  </div>
</section>

<section class="section section-white">
  <div class="container">
    <div class="section-head">
      <p class="eyebrow">Что берём на себя</p>
      <h2 class="section-title">Единая точка ремонта для техники компании</h2>
      <p class="section-text">Работаем с разовыми заявками и регулярным обслуживанием. Согласуем приоритет, сроки и бюджет до начала ремонта.</p>
    </div>
    <div class="b2b-services">
      <article><span>01</span><h3>Офисная техника</h3><p>Компьютеры, ноутбуки, моноблоки, телефоны, принтеры и периферия.</p></article>
      <article><span>02</span><h3>Выезд и доставка</h3><p>Диагностика на рабочем месте или бережная доставка устройства в сервис.</p></article>
      <article><span>03</span><h3>Срочный ремонт</h3><p>Приоритетная очередь для критичной техники и понятный статус каждой заявки.</p></article>
      <article><span>04</span><h3>Модернизация</h3><p>SSD, память, охлаждение, настройка программ и подготовка рабочих мест.</p></article>
    </div>
  </div>
</section>

<section class="section section-gray">
  <div class="container b2b-process">
    <div class="section-head">
      <p class="eyebrow">Как работаем</p>
      <h2 class="section-title">Без лишних согласований</h2>
    </div>
    <ol>
      <li><span>1</span><div><strong>Принимаем заявку</strong><p>Фиксируем технику, неисправность, адрес и приоритет.</p></div></li>
      <li><span>2</span><div><strong>Диагностируем</strong><p>Сообщаем срок, состав работ и итоговую стоимость.</p></div></li>
      <li><span>3</span><div><strong>Ремонтируем</strong><p>Назначаем профильного мастера и держим контакт с ответственным сотрудником.</p></div></li>
      <li><span>4</span><div><strong>Закрываем документами</strong><p>Возвращаем технику, гарантию и комплект отчётных документов.</p></div></li>
    </ol>
  </div>
</section>

<section id="b2b-request" class="section section-white">
  <div class="container b2b-request">
    <div>
      <p class="eyebrow">Заявка для бизнеса</p>
      <h2 class="section-title">Опишите парк техники и задачу</h2>
      <p class="section-text">Ответим в рабочее время, предложим формат обслуживания и сразу подключим нужного мастера.</p>
    </div>
    <form class="contact-form b2b-form" action="../api/send-request.php" method="POST" data-email-form data-form-type="b2b">
      <input type="hidden" name="_subject" value="B2B-заявка с сайта Сервис 101">
      <input type="hidden" name="_template" value="table">
      <input type="hidden" name="_captcha" value="false">
      <input class="input" type="text" name="Компания" placeholder="Название компании" required>
      <input class="input" type="text" name="Имя" placeholder="Контактное лицо" required>
      <input class="input" type="tel" name="Телефон" placeholder="+7 (___) ___-__-__" required>
      <input class="input" type="email" name="Email" autocomplete="email" inputmode="email" maxlength="254" placeholder="Рабочая почта" required>
      <select class="input" name="Формат" required>
        <option value="">Формат обслуживания</option>
        <option>Разовый ремонт</option>
        <option>Регулярное обслуживание</option>
        <option>Срочный выезд</option>
      </select>
      <textarea class="input" name="Задача" rows="5" placeholder="Какая техника и что произошло" required></textarea>
      <button class="btn btn-primary" type="submit">Отправить B2B-заявку</button>
    </form>
  </div>
</section>

${contactBody("..")}`;
}

function contactBody(root) {
  return `<section id="contacts" class="section section-gray contact-section">
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
      <p class="section-text contact-head__text">Два филиала в Комсомольске-на-Амуре работают ежедневно с 10:00 до 19:00 без перерывов и выходных.</p>
    </div>
    <div class="contact-grid">
      <form class="contact-form" action="${root}/api/send-request.php" method="POST" data-email-form data-form-type="contact">
        <input type="hidden" name="_subject" value="Заявка с сайта Сервис 101">
        <input type="hidden" name="_template" value="table">
        <input type="hidden" name="_captcha" value="false">
        <input class="input" type="text" name="Имя" placeholder="Ваше имя" required>
        <input class="input" type="tel" name="Телефон" placeholder="+7 (___) ___-__-__" required>
        <label class="form-email-field">
          <input class="input" type="email" name="Email" autocomplete="email" inputmode="email" maxlength="254" placeholder="Ваша электронная почта">
          <small class="form-email-note">Если вы организация, пожалуйста, пропишите электронную почту.</small>
        </label>
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
        ${serviceMapMarkup()}
      </div>
    </div>
  </div>
</section>`;
}

function serviceMapMarkup() {
  return `<div class="map-frame service-map js-yandex-map" role="region" aria-label="Карта двух филиалов Сервиса 101">
    <div class="service-map__fallback">
      <strong>Два филиала Сервиса 101</strong>
      <span>Загружаем интерактивную Яндекс Карту...</span>
      <a href="${serviceMapOpenUrl}" target="_blank" rel="noreferrer">Открыть в Яндекс Картах</a>
    </div>
  </div>`;
}

function loaderBody(title) {
  return `<section class="page-loader"><div class="container"><h1>${escapeHTML(title)}</h1><p>Загружаем услуги и цены...</p></div></section>`;
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

  for (let index = 0; index < text.length; index += 1) {
    const char = text[index];
    const next = text[index + 1];
    if (quoted) {
      if (char === '"' && next === '"') {
        field += '"';
        index += 1;
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
    .filter((line) => line.some((value) => value.trim()))
    .map((line) => headers.reduce((record, header, index) => {
      record[header] = line[index] || "";
      return record;
    }, {}));
}

function buildPageCatalog(rows) {
  const categories = [];
  const brands = {};
  const categoryMap = new Map();
  const brandMaps = new Map();

  for (const row of rows) {
    const categoryId = row.category_slug?.trim();
    const brandId = row.brand_slug?.trim();
    const modelId = row.model_slug?.trim();
    if (!categoryId || !brandId || !modelId) continue;

    let category = categoryMap.get(categoryId);
    if (!category) {
      category = {
        id: categoryId,
        name: row["категория"] || categoryId,
        title: row.category_title || row["категория"] || categoryId,
        icon: row["фото_1"] || "",
      };
      categoryMap.set(categoryId, category);
      brandMaps.set(categoryId, new Map());
      brands[categoryId] = [];
      categories.push(category);
    }

    const categoryBrands = brandMaps.get(categoryId);
    let brand = categoryBrands.get(brandId);
    if (!brand) {
      brand = { id: brandId, name: row["бренд"] || brandId, models: [] };
      categoryBrands.set(brandId, brand);
      brands[categoryId].push(brand);
    }
    if (!brand.models.some((model) => model.id === modelId)) {
      brand.models.push({ id: modelId, name: row["устройство"] || modelId });
    }
  }

  if (!categories.length) throw new Error("data/services.csv does not contain valid catalog rows");
  return { categories, brands };
}

function ensureDir(dir) {
  fs.mkdirSync(dir, { recursive: true });
}

function writeFile(file, text) {
  ensureDir(path.dirname(file));
  fs.writeFileSync(file, text, "utf8");
}

function slugify(value) {
  return String(value)
    .toLowerCase()
    .replace(/ё/g, "e")
    .replace(/[^a-zа-я0-9]+/gi, "-")
    .replace(/^-|-$/g, "")
    .slice(0, 44);
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

function escapeHTML(value) {
  return String(value)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}

function phoneIcon() {
  return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="7" y="2.5" width="10" height="19" rx="2.4"></rect><path d="M10 6.2h4"></path><path d="M11 18h2"></path></svg>';
}

function laptopIcon() {
  return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="4" y="5" width="16" height="10" rx="1.8"></rect><path d="M2.5 18.5h19"></path><path d="M7 18.5h10"></path></svg>';
}

function consoleIcon() {
  return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M5 8.5c0-1.4 1.1-2.5 2.5-2.5h9c1.4 0 2.5 1.1 2.5 2.5V15c0 1.4-1.1 2.5-2.5 2.5h-9C6.1 17.5 5 16.4 5 15V8.5Z"></path><path d="M8 10.5h3"></path><path d="M9.5 9v3"></path><circle cx="15.6" cy="12" r="0.75"></circle><circle cx="17.7" cy="12" r="0.75"></circle></svg>';
}

function pcIcon() {
  return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3.5" y="5" width="17" height="11" rx="1.8"></rect><path d="M8 19h8"></path><path d="M10 16h4"></path></svg>';
}

function gpuIcon() {
  return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3.5" y="7" width="13" height="8" rx="1.8"></rect><circle cx="10" cy="11" r="2.1"></circle><path d="M18 9.5h2.5v5H18"></path><path d="M6 15v3"></path><path d="M9 15v3"></path></svg>';
}

function gamepadIcon() {
  return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M6.5 9.5c1.3-1.6 3.4-2.6 5.5-2.6s4.2 1 5.5 2.6c1.5 1.8 2.6 4.5 1.1 6.3-1 1.2-2.9 1.3-4.1.3l-1.1-.9c-.8-.7-1.9-.7-2.7 0l-1.1.9c-1.2 1-3.1.9-4.1-.3-1.5-1.8-.4-4.5 1.1-6.3Z"></path><path d="M8.5 11.2h2"></path><path d="M9.5 10.2v2"></path><circle cx="15.6" cy="11.2" r="0.55"></circle><circle cx="16.9" cy="12.5" r="0.55"></circle></svg>';
}
