/*
 * Поддержка: лёгкий polling открытой вкладки обращения.
 *
 * Раз в 12 секунд страница спрашивает только новые сообщения (`?since=`),
 * поэтому трафик небольшой и уже прочитанное не перерисовывается. Узлы
 * строятся через DOM, текст вставляется как текст: HTML из ответа сервера не
 * исполняется.
 */
(function () {
    'use strict';

    var thread = document.querySelector('[data-ticket-thread]');
    var url = thread ? thread.getAttribute('data-poll-url') : '';

    if (!thread || !url) {
        return;
    }

    var lastId = Number(thread.getAttribute('data-last-id')) || 0;
    var closed = false;
    var timer = null;

    var el = function (tag, className, text) {
        var node = document.createElement(tag);

        if (className) {
            node.className = className;
        }

        if (text !== undefined && text !== null) {
            node.textContent = String(text);
        }

        return node;
    };

    var addMessage = function (message) {
        var article = el('article', 'ticket-message' + (message.is_support ? ' ticket-message--support' : ''));
        var header = el('header');

        header.appendChild(el('b', '', message.author_label));

        if (message.author_name) {
            header.appendChild(el('span', 'tiny muted', ' · ' + message.author_name));
        }

        header.appendChild(el('span', 'tiny muted', ' · ' + message.date));
        article.appendChild(header);
        article.appendChild(el('div', 'ticket-message__body', message.body));

        if (message.files && message.files.length) {
            var list = el('ul', 'ticket-files');

            message.files.forEach(function (file) {
                var item = el('li');
                var link = el('a', '', file.name);

                link.href = file.url;
                link.setAttribute('download', '');
                item.appendChild(link);
                item.appendChild(el('span', 'tiny muted', ' ' + file.size_label));
                list.appendChild(item);
            });

            article.appendChild(list);
        }

        thread.appendChild(article);
        lastId = Math.max(lastId, Number(message.id) || 0);
    };

    var stop = function () {
        if (timer) {
            window.clearInterval(timer);
            timer = null;
        }
    };

    var applyClosed = function (data) {
        closed = true;
        stop();

        var badge = document.querySelector('[data-ticket-status]');
        if (badge && data.status_label) {
            badge.textContent = data.status_label;
            badge.className = 'badge badge--line';
        }

        var form = document.getElementById('reply');
        if (form) {
            form.style.display = 'none';
        }

        var note = document.querySelector('[data-ticket-closed]');

        if (!note && thread.parentNode) {
            note = el('div', 'notice mt-24');
            note.setAttribute('data-ticket-closed', '');
            note.appendChild(el('p', '', 'Обращение закрыто. Новое сообщение откроет его снова.'));
            thread.parentNode.appendChild(note);
        }
        else if (note) {
            note.hidden = false;
        }
    };

    var poll = function () {
        if (closed || document.hidden) {
            return;
        }

        fetch(url + '?since=' + lastId, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin'
        })
            .then(function (response) {
                if (!response.ok) {
                    return null;
                }

                return response.json().catch(function () {
                    return null;
                });
            })
            .then(function (data) {
                if (!data || !data.ok) {
                    return;
                }

                (data.messages || []).forEach(addMessage);

                if (data.closed) {
                    applyClosed(data);
                }
            })
            .catch(function () {
                return null;
            });
    };

    timer = window.setInterval(poll, 12000);

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            poll();
        }
    });
})();
