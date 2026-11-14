/* Elv Studio — 3 КБ без зависимостей: меню, формы, выбор тарифа, копирование. */
(function () {
    'use strict';

    var header = document.getElementById('site-header');
    var toggle = document.querySelector('[data-nav-toggle]');

    function closeNav() {
        if (!header || !toggle) {
            return;
        }
        header.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
    }

    if (header && toggle) {
        toggle.addEventListener('click', function () {
            var open = header.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeNav();
            }
        });

        document.addEventListener('click', function (event) {
            if (!header.contains(event.target)) {
                closeNav();
            }
        });
    }

    function csrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function alertBox(kind, text) {
        var box = document.createElement('div');
        box.className = 'alert alert--' + kind;
        box.setAttribute('role', 'alert');
        box.textContent = text;
        return box;
    }

    function showErrors(form, errors) {
        var existing = form.querySelector('[data-form-errors]');
        if (existing) {
            existing.remove();
        }

        var messages = [];
        Object.keys(errors || {}).forEach(function (field) {
            var list = errors[field];
            if (Array.isArray(list)) {
                list.forEach(function (message) {
                    messages.push(message);
                });
            }
            var input = form.querySelector('[name="' + field + '"]');
            if (input) {
                input.setAttribute('aria-invalid', 'true');
            }
        });

        var box = alertBox('err', messages[0] || 'Проверьте поля формы.');
        box.setAttribute('data-form-errors', '');
        if (messages.length > 1) {
            var list = document.createElement('ul');
            messages.slice(1).forEach(function (message) {
                var item = document.createElement('li');
                item.textContent = message;
                list.appendChild(item);
            });
            box.appendChild(list);
        }
        form.insertBefore(box, form.firstChild);
        box.scrollIntoView({ block: 'center', behavior: 'smooth' });
    }

    function bindForm(form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();

            var button = form.querySelector('[type="submit"]');
            var label = button ? button.textContent : '';
            if (button) {
                button.disabled = true;
                button.textContent = 'Отправляем…';
            }

            fetch(form.getAttribute('action'), {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrf()
                },
                body: new FormData(form)
            })
                .then(function (response) {
                    return response.json().catch(function () {
                        return { ok: response.ok };
                    }).then(function (data) {
                        return { status: response.status, data: data };
                    });
                })
                .then(function (result) {
                    if (result.status === 201 || (result.data && result.data.ok && result.status < 300)) {
                        if (result.data && result.data.redirect) {
                            window.location.href = result.data.redirect;
                            return;
                        }
                        form.reset();
                        var success = alertBox('ok', form.getAttribute('data-success') || 'Готово. Мы свяжемся с вами.');
                        form.parentNode.insertBefore(success, form);
                        form.classList.add('is-hidden');
                        success.scrollIntoView({ block: 'center', behavior: 'smooth' });
                        form.dispatchEvent(new CustomEvent('elv:form-sent', { bubbles: true }));
                        return;
                    }

                    if (result.data && result.data.errors) {
                        showErrors(form, result.data.errors);
                    } else {
                        showErrors(form, { form: [result.data && result.data.message ? result.data.message : 'Не отправилось. Попробуйте ещё раз.'] });
                    }
                })
                .catch(function () {
                    showErrors(form, { form: ['Сеть недоступна. Попробуйте ещё раз.'] });
                })
                .then(function () {
                    if (button) {
                        button.disabled = false;
                        button.textContent = label;
                    }
                });
        });
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-elv-form]'), bindForm);

    function money(cents) {
        var value = cents / 100;
        var text = value === Math.floor(value)
            ? value.toLocaleString('ru-RU')
            : value.toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        return text;
    }

    var orderForm = document.querySelector('[data-order-form]');
    if (orderForm) {
        var totalNode = orderForm.querySelector('[data-total]');
        var currencyNode = orderForm.querySelector('[data-currency]');
        var bump = orderForm.querySelector('[name="bump"]');

        var recalc = function () {
            var plan = orderForm.querySelector('[name="plan"]:checked');
            var cents = plan ? parseInt(plan.getAttribute('data-price'), 10) : 0;
            if (bump && bump.checked) {
                cents += parseInt(bump.getAttribute('data-bump'), 10) || 0;
            }
            if (totalNode) {
                totalNode.textContent = money(cents);
            }
        };

        Array.prototype.forEach.call(orderForm.querySelectorAll('[name="plan"], [name="bump"]'), function (input) {
            input.addEventListener('change', recalc);
        });

        recalc();
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-copy]'), function (button) {
        button.addEventListener('click', function () {
            var value = button.getAttribute('data-copy');
            if (navigator.clipboard) {
                navigator.clipboard.writeText(value);
            }
            var previous = button.getAttribute('data-label') || button.textContent;
            button.setAttribute('data-label', previous);
            button.textContent = 'Скопировано';
            window.setTimeout(function () {
                button.textContent = previous;
            }, 1600);
        });
    });

})();
