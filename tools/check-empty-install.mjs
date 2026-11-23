/**
 * check-empty-install — витрина на чистой установке (без демо-данных).
 *
 * Запуск вручную против свежей установки:
 *   node tools/check-empty-install.mjs --base=http://127.0.0.1:8093
 *
 * Что проверяется: страницы отвечают 200 (кроме адресов, которых на пустой
 * базе быть не должно), пустые состояния объясняют отсутствие данных, нет
 * выдуманных цен и дат, нет сырого Twig и следов PHP-ошибок. Гейт не требует
 * пустых данных: на заполненном сайте он проверяет те же инварианты, но
 * «пустые» утверждения пропускает — поэтому в общий `gate.mjs` не входит.
 */

const args = process.argv.slice(2);
const baseArg = args.find((arg) => arg.startsWith('--base='));
const base = (baseArg ? baseArg.slice('--base='.length) : 'http://127.0.0.1:8090').replace(/\/$/, '');

const problems = [];
const notes = [];

const get = async (path) => {
  const response = await fetch(base + path, { redirect: 'manual' });
  return { status: response.status, html: await response.text() };
};

const mainOf = (html) => (/<main[\s\S]*?<\/main>/i.exec(html) || [''])[0];

const pages = ['/', '/kit/', '/modules/', '/pricing/', '/calculator/', '/blog/', '/blog/tech/', '/booking/', '/contacts/', '/docs/'];

const bodies = {};

for (const page of pages) {
  try {
    const { status, html } = await get(page);
    bodies[page] = mainOf(html);

    if (status !== 200) {
      problems.push(page + ' — статус ' + status + ', ожидался 200');
    }

    if (/\{\{[^}]*\}\}/.test(html)) {
      problems.push(page + ' — в разметке остался сырой Twig');
    }

    if (/Whoops|SQLSTATE|Fatal error|Undefined variable/i.test(html)) {
      problems.push(page + ' — в разметке следы PHP-ошибки');
    }
  } catch (error) {
    problems.push(page + ' — запрос не выполнен: ' + error.message);
  }
}

const pricing = bodies['/pricing/'] || '';
const pricingCards = (pricing.match(/class="price-card__value"/g) || []).length;

if (pricingCards === 0) {
  if (!/Прайс пока пуст/.test(pricing)) {
    problems.push('/pricing/ — тарифов нет, но пустого состояния «Прайс пока пуст» тоже нет');
  }

  if (/Цены обновлены/.test(pricing)) {
    problems.push('/pricing/ — тарифов нет, а страница всё равно печатает «Цены обновлены»');
  }

  if (/\/order/.test(pricing)) {
    problems.push('/pricing/ — тарифов нет, но страница ведёт на /order/ (там нечего заказывать)');
  }

  notes.push('/pricing/: тарифов 0 → пустое состояние, без даты и без ссылки на заказ');
} else {
  notes.push('/pricing/: тарифов ' + pricingCards + ' → дата «' + ((/Цены обновлены\s*(\d{2}\.\d{2}\.\d{4})/.exec(pricing) || [])[1] || 'нет') + '»');

  if (/Цены обновлены\s*[.<]/.test(pricing)) {
    problems.push('/pricing/ — «Цены обновлены» без даты (пустая подстановка)');
  }
}

const order = await get('/order/');
const orderMain = mainOf(order.html);
const hasForm = /id="order-form"/.test(orderMain);
const hasEmpty = /Оформление пока закрыто/.test(orderMain);

if (order.status !== 200) {
  problems.push('/order/ — статус ' + order.status + ', ожидался 200: страница должна объяснять, а не отдавать 404');
} else if (hasForm && hasEmpty) {
  problems.push('/order/ — одновременно форма заказа и пустое состояние');
} else if (!hasForm && !hasEmpty) {
  problems.push('/order/ — ни формы, ни объяснения, почему заказ закрыт');
} else {
  notes.push('/order/: ' + (hasForm ? 'форма заказа' : 'пустое состояние «Оформление пока закрыто»'));
}

const bogus = await get('/order/?product=net-takogo-tovara');

if (bogus.status !== 404) {
  problems.push('/order/?product=<несуществующий> — статус ' + bogus.status + ', ожидался 404');
}

/*
 * Страница модуля без товара в базе: на чистой установке модулей нет, и
 * выдумывать карточку нельзя. Ответ — честный 404 с noindex, а не пустая
 * страница с «Подключить раздел».
 */
const bogusModule = await get('/modules/net-takogo-modulya/');

if (bogusModule.status !== 404) {
  problems.push('/modules/<несуществующий>/ — статус ' + bogusModule.status + ', ожидался 404');
} else if (!/noindex/i.test(bogusModule.html)) {
  problems.push('/modules/<несуществующий>/ — 404 без noindex: страница попадёт в индекс');
} else if (/\{\{[^}]*\}\}/.test(bogusModule.html)) {
  problems.push('/modules/<несуществующий>/ — в 404 остался сырой Twig');
} else {
  notes.push('/modules/<несуществующий>/: 404 с noindex');
}

const blog = bodies['/blog/'] || '';

if ((blog.match(/post-card/g) || []).length === 0 && !/Ничего не нашлось|Пока нет статей|Записей нет/i.test(blog)) {
  problems.push('/blog/ — статей нет, но пустого состояния нет');
}

notes.forEach((note) => console.log('· ' + note));

if (problems.length) {
  problems.forEach((problem) => console.error('FAIL: ' + problem));
  process.exit(1);
}

console.log('OK: ' + base + ' — ' + pages.length + ' страниц, пустые состояния и цены без выдумок');
