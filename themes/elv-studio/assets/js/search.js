/* Страница поиска: клиентский индекс, чтобы поиск работал и в статической копии. */
(function () {
    'use strict';

    var indexNode = document.querySelector('[data-search-index]');
    var form = document.querySelector('[data-search-form]');
    var input = document.getElementById('site-search-input');
    var results = document.querySelector('[data-search-results]');
    var statusNode = document.querySelector('[data-search-status]');
    var emptyNode = document.querySelector('[data-search-empty]');
    var popularNode = document.querySelector('[data-search-popular]');
    var moreNode = document.querySelector('[data-search-more]');
    var filterNodes = document.querySelectorAll('[data-search-type]');

    if (!indexNode || !form || !input || !results || !statusNode) {
        return;
    }

    var items = [];
    var loaded = false;

    var state = { type: 'all', limit: 30 };
    var stop = {
        'и': 1, 'в': 1, 'во': 1, 'на': 1, 'для': 1, 'с': 1, 'со': 1, 'по': 1,
        'как': 1, 'что': 1, 'это': 1, 'или': 1, 'не': 1, 'из': 1, 'у': 1,
        'о': 1, 'об': 1, 'от': 1, 'до': 1, 'за': 1, 'под': 1, 'над': 1,
        'при': 1, 'про': 1, 'без': 1, 'же': 1, 'бы': 1, 'ли': 1, 'то': 1,
        'все': 1, 'мы': 1, 'вы': 1, 'он': 1, 'она': 1, 'они': 1, 'мне': 1, 'нам': 1
    };
    var synonymGroups = [
        ['оплата', 'оплаты', 'оплате', 'оплату', 'оплатой', 'оплатить', 'платеж', 'платежи', 'платить'],
        ['запись', 'записи', 'записью', 'записаться', 'бронирование', 'бронирования', 'бронь', 'брони'],
        ['цена', 'цены', 'цене', 'цену', 'ценой', 'стоимость', 'стоимости', 'стоимостью', 'тариф', 'тарифы', 'тарифам', 'тарифами'],
        ['сайт', 'сайты', 'сайта', 'сайту', 'сайте', 'сайтом', 'лендинг', 'лендинга'],
        ['установка', 'установки', 'установить', 'настройка', 'настройки', 'настроить'],
        ['поддержка', 'поддержки', 'поддержать', 'сопровождение', 'сопровождения'],
        ['аудит', 'аудита', 'проверка', 'проверки', 'проверить'],
        ['блог', 'блога', 'блоге', 'статья', 'статьи', 'статью', 'статей'],
        ['ключ', 'ключа', 'ключи', 'ключей', 'лицензия', 'лицензии']
    ];
    var endings = [
        'иями', 'ями', 'ами', 'ией', 'иям', 'ием', 'иях', 'ого', 'его', 'ому', 'ему', 'ыми', 'ими',
        'овать', 'евать',
        'ая', 'яя', 'ое', 'ее', 'ые', 'ие', 'ой', 'ей', 'ий', 'ый', 'ом', 'ем', 'ам', 'ям', 'ах', 'ях',
        'ов', 'ев', 'ью', 'ия', 'ию', 'ии', 'ую', 'юю', 'ить', 'ать', 'ять', 'еть',
        'а', 'я', 'о', 'е', 'ы', 'и', 'у', 'ю', 'ь'
    ];
    var timer = null;

    function normalize(value) {
        var text = String(value || '').toLowerCase().replace(/ё/g, 'е');

        return text.match(/[\p{L}\p{Nd}]+/gu) || [];
    }

    function phrase(value) {
        return normalize(value).join(' ');
    }

    function words(value) {
        var all = normalize(value);
        var seen = {};
        var list = [];

        for (var i = 0; i < all.length; i += 1) {
            var word = all[i];

            if (word.length < 2 || stop[word] || seen[word]) {
                continue;
            }

            seen[word] = true;
            list.push(word);
        }

        return list;
    }

    function stem(word) {
        if (word.length <= 3) {
            return word;
        }

        for (var i = 0; i < endings.length; i += 1) {
            var ending = endings[i];
            var value = word.slice(0, -ending.length);

            if (word.slice(-ending.length) === ending && value.length >= 3) {
                return value;
            }
        }

        return word;
    }

    function formsFor(word) {
        var list = [word];
        var value = stem(word);

        if (value !== word) {
            list.push(value);
        }

        for (var i = 0; i < synonymGroups.length; i += 1) {
            var group = synonymGroups[i];

            if (group.indexOf(word) === -1) {
                continue;
            }

            group.forEach(function (item) {
                list.push(item);

                var itemStem = stem(item);

                if (itemStem !== item) {
                    list.push(itemStem);
                }
            });

            break;
        }

        return list.filter(function (item, index, all) {
            return item.length >= 2 && all.indexOf(item) === index;
        });
    }

    function escapeRegExp(value) {
        return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function hasForm(text, value) {
        if (value.length < 4) {
            return new RegExp('(^|[^\\p{L}\\p{N}])' + escapeRegExp(value) + '($|[^\\p{L}\\p{N}])', 'u').test(text);
        }

        return text.indexOf(value) !== -1;
    }

    function hasAny(text, forms) {
        for (var i = 0; i < forms.length; i += 1) {
            if (hasForm(text, forms[i])) {
                return true;
            }
        }

        return false;
    }

    function score(item, query, parts) {
        var title = phrase(item.title);
        var description = phrase(item.description);
        var text = phrase(item.text);
        var keywords = phrase(item.keywords);
        var meta = phrase(item.meta);
        var total = title.indexOf(query) !== -1 ? 14 : 0;
        var hits = 0;
        var wordForms = parts.map(formsFor);

        parts.forEach(function (word, index) {
            var forms = wordForms[index];
            var hit = false;

            if (hasAny(title, forms)) {
                total += 7;
                hit = true;
            }

            if (hasAny(keywords, forms)) {
                total += 5;
                hit = true;
            }

            if (hasAny(description, forms)) {
                total += 3;
                hit = true;
            }

            if (hasAny(text, forms)) {
                total += 2;
                hit = true;
            }

            if (hasAny(meta, forms)) {
                total += 1;
                hit = true;
            }

            if (hit) {
                hits += 1;
            }
        });

        if (hits === 0) {
            return 0;
        }

        if (parts.length > 1) {
            total += hits * 2;
            total -= (parts.length - hits) * 2;
        }

        return total;
    }

    function plural(count, one, few, many) {
        var last = count % 10;
        var lastTwo = count % 100;

        if (last === 1 && lastTwo !== 11) {
            return one;
        }

        if (last >= 2 && last <= 4 && (lastTwo < 10 || lastTwo >= 20)) {
            return few;
        }

        return many;
    }

    function appendHighlighted(node, value, parts) {
        var source = String(value || '');
        var forms = [];

        parts.forEach(function (word) {
            forms = forms.concat(formsFor(word));
        });

        forms = forms.filter(function (form, index, all) {
            return form.length >= 4 && all.indexOf(form) === index;
        });

        if (!forms.length) {
            node.textContent = source;
            return;
        }

        forms.sort(function (a, b) {
            return b.length - a.length;
        });

        var pattern = new RegExp('(' + forms.map(escapeRegExp).join('|') + ')', 'giu');
        var last = 0;
        var match;

        while ((match = pattern.exec(source)) !== null) {
            if (match.index > last) {
                node.appendChild(document.createTextNode(source.slice(last, match.index)));
            }

            var mark = document.createElement('mark');
            mark.textContent = match[0];
            node.appendChild(mark);
            last = match.index + match[0].length;

            if (match[0] === '') {
                pattern.lastIndex += 1;
            }
        }

        if (last < source.length) {
            node.appendChild(document.createTextNode(source.slice(last)));
        }
    }

    function card(item, parts) {
        var link = document.createElement('a');
        link.className = 'search-result';
        link.href = item.url;

        var top = document.createElement('div');
        top.className = 'search-result__top';

        var type = document.createElement('span');
        type.className = 'search-result__type';
        type.textContent = item.type === 'blog' ? 'Статья' : 'Страница';
        top.appendChild(type);

        if (item.group) {
            var group = document.createElement('span');
            group.className = 'search-result__group';
            group.textContent = item.group;
            top.appendChild(group);
        }

        link.appendChild(top);

        var title = document.createElement('h2');
        appendHighlighted(title, item.title || '', parts);
        link.appendChild(title);

        var description = item.description || item.text || '';
        if (description) {
            var lead = document.createElement('p');
            appendHighlighted(lead, description, parts);
            link.appendChild(lead);
        }

        if (item.meta) {
            var meta = document.createElement('span');
            meta.className = 'search-result__meta';
            meta.textContent = item.meta;
            link.appendChild(meta);
        }

        return link;
    }

    function render() {
        if (!loaded) {
            statusNode.textContent = 'Загружаем поиск…';
            return;
        }

        var raw = input.value.trim();
        var query = phrase(raw);
        var parts = words(raw);

        results.textContent = '';
        results.setAttribute('aria-busy', 'false');
        emptyNode.hidden = true;

        if (!raw) {
            popularNode.hidden = false;

            if (moreNode) {
                moreNode.hidden = true;
            }

            statusNode.textContent = 'Введите запрос — покажем страницы и статьи.';
            return;
        }

        popularNode.hidden = true;
        results.setAttribute('aria-busy', 'true');

        if (!parts.length) {
            if (moreNode) {
                moreNode.hidden = true;
            }

            results.setAttribute('aria-busy', 'false');
            statusNode.textContent = 'Введите хотя бы два символа.';
            return;
        }

        var found = [];

        items.forEach(function (item) {
            if (state.type !== 'all' && item.type !== state.type) {
                return;
            }

            var value = score(item, query, parts);

            if (value > 0) {
                found.push({ item: item, score: value });
            }
        });

        found.sort(function (a, b) {
            if (a.score === b.score) {
                return String(a.item.title).localeCompare(String(b.item.title), 'ru');
            }

            return b.score - a.score;
        });

        if (!found.length) {
            if (moreNode) {
                moreNode.hidden = true;
            }

            results.setAttribute('aria-busy', 'false');
            emptyNode.hidden = false;
            statusNode.textContent = 'Ничего не нашлось по запросу «' + raw + '».';
            return;
        }

        var shown = found.slice(0, state.limit);
        var suffix = plural(found.length, 'результат', 'результата', 'результатов');
        statusNode.textContent = found.length > shown.length
            ? 'Нашли ' + found.length + ' ' + suffix + ', показываем первые ' + shown.length + '.'
            : 'Нашли ' + found.length + ' ' + suffix + '.';

        shown.forEach(function (entry) {
            results.appendChild(card(entry.item, parts));
        });

        if (moreNode) {
            moreNode.hidden = found.length <= shown.length;
        }

        results.setAttribute('aria-busy', 'false');
    }

    function syncUrl() {
        var value = input.value.trim();
        var base = window.location.pathname.replace(/index\.html$/, '');

        if (base.charAt(base.length - 1) !== '/') {
            base += '/';
        }

        try {
            window.history.replaceState(null, '', base + (value ? '?q=' + encodeURIComponent(value) : ''));
        } catch (error) {
            /* file:// не даёт менять адрес — поиск всё равно работает. */
        }
    }

    input.addEventListener('input', function () {
        state.limit = 30;
        window.clearTimeout(timer);
        timer = window.setTimeout(function () {
            syncUrl();
            render();
        }, 120);
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        state.limit = 30;
        syncUrl();
        render();
    });

    Array.prototype.forEach.call(filterNodes, function (button) {
        button.addEventListener('click', function () {
            state.type = button.getAttribute('data-search-type') || 'all';
            state.limit = 30;

            Array.prototype.forEach.call(filterNodes, function (node) {
                node.classList.toggle('chip--active', node === button);
            });

            render();
        });
    });

    if (moreNode) {
        moreNode.addEventListener('click', function () {
            state.limit += 30;
            render();
        });
    }

    statusNode.textContent = 'Загружаем поиск…';

    var initial = new URLSearchParams(window.location.search).get('q');

    if (initial && !input.value) {
        input.value = initial;
    }

    fetch(indexNode.getAttribute('data-search-index') || '/search-index.json', {
        headers: { Accept: 'application/json' }
    })
        .then(function (response) {
            if (!response.ok) {
                throw new Error('search index ' + response.status);
            }

            return response.json();
        })
        .then(function (data) {
            items = Array.isArray(data) ? data : [];
            loaded = true;

            if (input.value.trim()) {
                render();
                return;
            }

            statusNode.textContent = 'Введите запрос — покажем страницы и статьи.';
            popularNode.hidden = false;
        })
        .catch(function () {
            statusNode.textContent = 'Поиск временно недоступен. Откройте карту сайта.';
        });
})();
