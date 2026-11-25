/* Калькулятор Elv Kit: мастер из 4 шагов, смета и печать. */
(function () {
    'use strict';

    var root = document.querySelector('[data-calculator]');

    if (!root) {
        return;
    }

    var steps = Array.prototype.slice.call(root.querySelectorAll('[data-calc-step]'));
    var progress = Array.prototype.slice.call(root.querySelectorAll('[data-calc-go]'));
    var planInputs = Array.prototype.slice.call(root.querySelectorAll('input[name="plan"]'));
    var moduleInputs = Array.prototype.slice.call(root.querySelectorAll('input[name="modules"]'));
    var result = document.getElementById('calculator-result');
    var requestForm = document.querySelector('[data-calc-request]');

    if (!steps.length || !planInputs.length) {
        return;
    }

    var symbol = root.getAttribute('data-symbol') || '$';
    var quoteUrl = root.getAttribute('data-quote-url') || '/calculator/quote.pdf';
    var recommendations = {};
    var recommendText = root.querySelector('[data-recommend-text]');
    var applyRecommendButton = root.querySelector('[data-calc-apply-recommend]');
    var pdfLink = document.querySelector('[data-calc-pdf]');
    var current = 0;
    var touched = false;

    try {
        recommendations = JSON.parse(root.getAttribute('data-recommendations') || '{}');
    } catch (error) {
        recommendations = {};
    }

    function money(cents) {
        var value = Number(cents || 0) / 100;
        var text = value.toLocaleString('ru-RU', {
            minimumFractionDigits: Number.isInteger(value) ? 0 : 2,
            maximumFractionDigits: 2
        });

        return symbol === '$' ? '$' + text : text + ' ' + symbol;
    }

    function checkedPlan() {
        for (var i = 0; i < planInputs.length; i += 1) {
            if (planInputs[i].checked) {
                return planInputs[i];
            }
        }

        return planInputs[0];
    }

    function selectedModules() {
        return moduleInputs.filter(function (input) {
            return input.checked;
        }).map(function (input) {
            return {
                slug: input.value,
                label: input.getAttribute('data-label') || input.value,
                price: parseInt(input.getAttribute('data-price'), 10) || 0
            };
        });
    }

    function checkedValue(name, fallback) {
        var input = root.querySelector('input[name="' + name + '"]:checked');

        return input ? input.value : fallback;
    }

    function currentRecommendation() {
        return recommendations[checkedValue('task', 'landing')] || null;
    }

    function planLabel(key) {
        for (var i = 0; i < planInputs.length; i += 1) {
            if (planInputs[i].value === key) {
                return planInputs[i].getAttribute('data-label') || key;
            }
        }

        return key;
    }

    function moduleLabel(slug) {
        for (var i = 0; i < moduleInputs.length; i += 1) {
            if (moduleInputs[i].value === slug) {
                return moduleInputs[i].getAttribute('data-label') || slug;
            }
        }

        return slug;
    }

    function renderRecommendation() {
        var recommendation = currentRecommendation();

        if (!recommendText) {
            return;
        }

        if (!recommendation) {
            recommendText.textContent = 'Для этой задачи рекомендация появится позже.';
            if (applyRecommendButton) {
                applyRecommendButton.disabled = true;
            }
            return;
        }

        var labels = (recommendation.modules || []).map(moduleLabel);
        var composition = planLabel(recommendation.plan) + (labels.length ? ' + ' + labels.join(', ') : ' без платных модулей');

        recommendText.textContent = composition + '. ' + (recommendation.reason || '');

        if (applyRecommendButton) {
            applyRecommendButton.disabled = false;
        }
    }

    function applyRecommendation() {
        var recommendation = currentRecommendation();

        if (!recommendation) {
            return;
        }

        planInputs.forEach(function (input) {
            input.checked = input.value === recommendation.plan;
        });

        if (!planInputs.some(function (input) {
            return input.checked;
        })) {
            planInputs[0].checked = true;
        }

        moduleInputs.forEach(function (input) {
            input.checked = (recommendation.modules || []).indexOf(input.value) !== -1;
        });

        touched = true;
        update();
        track('calculator_recommend', {
            task: checkedValue('task', 'landing'),
            plan: recommendation.plan,
            modules: (recommendation.modules || []).join(',')
        });
    }

    function syncPdfLink() {
        if (!pdfLink) {
            return;
        }

        var plan = checkedPlan();

        if (!plan) {
            return;
        }

        var params = new URLSearchParams(window.location.search);
        params.set('plan', plan.value);
        params.set('modules', selectedModules().map(function (module) {
            return module.slug;
        }).join(','));
        params.set('task', checkedValue('task', 'landing'));
        params.set('hosting', checkedValue('hosting', 'own'));
        pdfLink.href = quoteUrl + '?' + params.toString();
    }

    function downloadPdf() {
        var totals = update();
        var url = pdfLink ? pdfLink.href : quoteUrl;

        track('calculator_pdf', { total: totals.launch });

        if (!window.fetch) {
            window.print();
            return;
        }

        fetch(url, { headers: { Accept: 'application/pdf' } })
            .then(function (response) {
                var type = response.headers.get('Content-Type') || '';

                if (!response.ok || type.indexOf('application/pdf') === -1) {
                    throw new Error('PDF is not available');
                }

                return response.blob();
            })
            .then(function (blob) {
                var objectUrl = URL.createObjectURL(blob);
                var link = document.createElement('a');

                link.href = objectUrl;
                link.download = 'elv-quote.pdf';
                document.body.appendChild(link);
                link.click();
                link.remove();
                setTimeout(function () {
                    URL.revokeObjectURL(objectUrl);
                }, 1000);
            })
            .catch(function () {
                window.print();
            });
    }

    function applyUrlState() {
        var params = new URLSearchParams(window.location.search);
        var planKey = params.get('plan');
        var task = params.get('task');
        var hosting = params.get('hosting');

        if (planKey) {
            planInputs.forEach(function (input) {
                input.checked = input.value === planKey;
            });

            if (!planInputs.some(function (input) {
                return input.checked;
            })) {
                planInputs[0].checked = true;
            }
        }

        if (params.has('modules')) {
            var wanted = params.get('modules').split(',').filter(Boolean);

            moduleInputs.forEach(function (input) {
                input.checked = wanted.indexOf(input.value) !== -1;
            });
        }

        if (task) {
            Array.prototype.slice.call(root.querySelectorAll('input[name="task"]')).forEach(function (input) {
                if (input.value === task) {
                    input.checked = true;
                }
            });
        }

        if (hosting) {
            Array.prototype.slice.call(root.querySelectorAll('input[name="hosting"]')).forEach(function (input) {
                if (input.value === hosting) {
                    input.checked = true;
                }
            });
        }
    }

    function syncUrl() {
        if (!window.history || typeof window.history.replaceState !== 'function') {
            return;
        }

        var plan = checkedPlan();

        if (!plan) {
            return;
        }

        var params = new URLSearchParams(window.location.search);
        params.set('plan', plan.value);
        params.set('modules', selectedModules().map(function (module) {
            return module.slug;
        }).join(','));
        params.set('task', checkedValue('task', 'landing'));
        params.set('hosting', checkedValue('hosting', 'own'));
        window.history.replaceState(null, '', window.location.pathname + '?' + params.toString() + window.location.hash);
    }

    function setText(selector, value) {
        Array.prototype.slice.call(document.querySelectorAll(selector)).forEach(function (node) {
            node.textContent = value;
        });
    }

    function breakdownRows(plan, modules) {
        var rows = [{
            label: plan.getAttribute('data-label') || 'Elv Kit',
            price: parseInt(plan.getAttribute('data-price'), 10) || 0
        }];

        modules.forEach(function (module) {
            rows.push({ label: module.label, price: module.price });
        });

        return rows;
    }

    function renderRows(plan, modules) {
        var list = document.querySelector('[data-breakdown]');

        if (list) {
            list.innerHTML = '';
            breakdownRows(plan, modules).forEach(function (row) {
                var tr = document.createElement('tr');
                var name = document.createElement('td');
                var price = document.createElement('td');
                name.textContent = row.label;
                price.textContent = money(row.price);
                tr.appendChild(name);
                tr.appendChild(price);
                list.appendChild(tr);
            });
        }

        var printList = document.querySelector('[data-print-breakdown]');

        if (printList) {
            printList.innerHTML = '';
            breakdownRows(plan, modules).forEach(function (row) {
                var tr = document.createElement('tr');
                var name = document.createElement('td');
                var price = document.createElement('td');
                name.textContent = row.label;
                price.textContent = money(row.price);
                tr.appendChild(name);
                tr.appendChild(price);
                printList.appendChild(tr);
            });
        }
    }

    function buildMessage(plan, modules, totals) {
        var taskLabels = {
            landing: 'Лендинг',
            services: 'Сайт услуг',
            shop: 'Магазин',
            booking: 'Онлайн-запись'
        };
        var hostingLabels = {
            own: 'свой хостинг',
            need: 'нужен хостинг',
            help: 'нужна помощь с выбором'
        };
        var task = checkedValue('task', 'landing');
        var hosting = checkedValue('hosting', 'own');
        var params = new URLSearchParams(window.location.search);
        var utm = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'ref']
            .map(function (key) {
                var value = params.get(key);

                return value ? key + '=' + value.slice(0, 120) : '';
            })
            .filter(Boolean)
            .join('&');

        var lines = [
            'Смета Elv Kit',
            'Тариф: ' + (plan.getAttribute('data-label') || '') + ' — ' + money(plan.getAttribute('data-price')),
            'Сайтов: ' + (plan.getAttribute('data-sites') || ''),
            'Тип сайта: ' + (taskLabels[task] || task),
            'Модули: ' + (modules.length ? modules.map(function (module) {
                return module.label + ' — ' + money(module.price);
            }).join('; ') : 'не выбраны'),
            'Хостинг: ' + (hostingLabels[hosting] || hosting),
            'Запуск: ' + money(totals.launch),
            'Хостинг и лицензии, в год: 0 (у студии; хостинг и домен — провайдеру)',
            'Поддержка, в месяц: 0 (включена на ' + (plan.getAttribute('data-support') || '') + ')',
            'Первый год, студии: ' + money(totals.year),
            'Дальше в год, студии: 0',
            'Цены: ' + (root.getAttribute('data-updated') || '') + '. Не оферта.'
        ];

        if (utm) {
            lines.push('UTM: ' + utm);
        }

        return lines.join('\n').slice(0, 4900);
    }

    function update() {
        var plan = checkedPlan();
        var modules = selectedModules();
        var launch = parseInt(plan.getAttribute('data-price'), 10) || 0;

        modules.forEach(function (module) {
            launch += module.price;
        });

        var year = launch;
        var planLabel = plan.getAttribute('data-label') || '';
        var planSites = plan.getAttribute('data-sites') || '';
        var planSupport = plan.getAttribute('data-support') || '';
        var planUpdates = plan.getAttribute('data-updates') || '';
        var moduleNames = modules.length ? modules.map(function (module) {
            return module.label;
        }).join(', ') : 'не выбраны';

        setText('[data-total-launch]', money(launch));
        setText('[data-total-annual]', money(0));
        setText('[data-total-monthly]', money(0));
        setText('[data-total-next]', money(0));
        setText('[data-total-year]', money(year));
        setText('[data-total-support]', planSupport);

        setText('[data-result-launch]', money(launch));
        setText('[data-result-annual]', money(0));
        setText('[data-result-monthly]', money(0));
        setText('[data-result-year]', money(year));

        setText('[data-review-plan]', planLabel);
        setText('[data-review-sites]', planSites);
        setText('[data-review-support]', planSupport);
        setText('[data-review-updates]', planUpdates);
        setText('[data-review-modules]', moduleNames);

        setText('[data-print-plan]', planLabel);
        setText('[data-print-modules]', moduleNames);
        setText('[data-print-launch]', money(launch));
        setText('[data-print-annual]', money(0));
        setText('[data-print-monthly]', money(0));
        setText('[data-print-year]', money(year));
        setText('[data-print-next]', money(0));

        renderRows(plan, modules);

        if (requestForm) {
            var message = requestForm.querySelector('#calc-message');

            if (message) {
                message.value = buildMessage(plan, modules, { launch: launch, year: year });
            }
        }

        if (touched) {
            syncUrl();
        }

        syncPdfLink();

        return { plan: plan, modules: modules, launch: launch, year: year };
    }

    function go(step) {
        current = Math.max(0, Math.min(steps.length - 1, step));

        steps.forEach(function (node, index) {
            node.classList.toggle('is-active', index === current);
        });

        progress.forEach(function (node, index) {
            node.classList.toggle('is-active', index === current);
            node.setAttribute('aria-current', index === current ? 'step' : 'false');
        });

        track('calculator_step', { step: current + 1 });
    }

    function showResult() {
        var totals = update();

        if (result) {
            result.hidden = false;
            result.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        track('calculator_result', { total: totals.launch });
    }

    function track(name, data) {
        var payload = Object.assign({ event: name }, data || {});

        try {
            window.dispatchEvent(new CustomEvent('elv:calculator', { detail: payload }));
        } catch (error) {
            /* CustomEvent может быть недоступен в старом браузере. */
        }

        if (typeof window.gtag === 'function') {
            window.gtag('event', name, data || {});
        }

        if (Array.isArray(window.dataLayer)) {
            window.dataLayer.push(payload);
        }
    }

    root.addEventListener('change', function (event) {
        var target = event.target;

        if (['plan', 'modules', 'task', 'hosting'].indexOf(target.name) === -1) {
            return;
        }

        touched = true;

        if (target.name === 'plan') {
            update();
            track('calculator_plan', { plan: target.value, price: target.getAttribute('data-price') });
        }

        if (target.name === 'modules') {
            update();
            track('calculator_module', { module: target.value, selected: target.checked });
        }

        if (target.name !== 'plan' && target.name !== 'modules') {
            update();
        }

        renderRecommendation();
    });

    root.addEventListener('click', function (event) {
        var next = event.target.closest('[data-calc-next]');
        var back = event.target.closest('[data-calc-back]');
        var jump = event.target.closest('[data-calc-go]');
        var resultButton = event.target.closest('[data-calc-show-result]');
        var recommendButton = event.target.closest('[data-calc-apply-recommend]');

        if (next) {
            go(current + 1);
        }

        if (back) {
            go(current - 1);
        }

        if (jump) {
            go(parseInt(jump.getAttribute('data-calc-go'), 10) || 0);
        }

        if (resultButton) {
            showResult();
        }

        if (recommendButton) {
            applyRecommendation();
        }
    });

    if (pdfLink) {
        pdfLink.addEventListener('click', function (event) {
            event.preventDefault();
            downloadPdf();
        });
    }

    if (requestForm) {
        requestForm.addEventListener('submit', function () {
            var totals = update();
            track('calculator_request', { total: totals.launch });
        }, true);
    }

    applyUrlState();
    update();
    renderRecommendation();
    go(0);
    track('calculator_open', {});
})();
