(function () {
    'use strict';

    var doc = document;
    var csrfMeta = doc.querySelector('meta[name="csrf-token"]');
    var body = doc.body;

    function csrf() {
        return csrfMeta ? csrfMeta.getAttribute('content') : '';
    }

    var fab = doc.querySelector('[data-fab]');
    var feedback = doc.querySelector('[data-feedback]');
    var chat = doc.getElementById('elv-chat');
    var openFeedback = null;
    var openChat = null;
    var closeChat = null;

    if (feedback) {
        var resetFeedback = function () {
            var form = feedback.querySelector('form');
            if (!form) {
                return;
            }
            Array.prototype.forEach.call(feedback.querySelectorAll('.alert'), function (box) {
                box.remove();
            });
            form.classList.remove('is-hidden');
        };

        openFeedback = function () {
            resetFeedback();
            if (typeof feedback.showModal === 'function') {
                if (!feedback.open) {
                    feedback.showModal();
                }
            }
            else {
                feedback.setAttribute('open', 'open');
            }

            var first = feedback.querySelector('input[name="name"]');
            if (first) {
                first.focus();
            }
        };

        var closeFeedback = function () {
            if (typeof feedback.close === 'function') {
                feedback.close();
            }
            else {
                feedback.removeAttribute('open');
            }
        };

        Array.prototype.forEach.call(doc.querySelectorAll('[data-feedback-open]'), function (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();
                if (typeof closeChat === 'function') {
                    closeChat();
                }
                openFeedback();
            });
        });

        Array.prototype.forEach.call(feedback.querySelectorAll('[data-feedback-close]'), function (button) {
            button.addEventListener('click', closeFeedback);
        });

        feedback.addEventListener('click', function (event) {
            if (event.target === feedback) {
                closeFeedback();
            }
        });

        feedback.addEventListener('close', resetFeedback);

        feedback.addEventListener('elv:form-sent', function () {
            window.setTimeout(function () {
                if (feedback.open) {
                    closeFeedback();
                }
            }, 2200);
        });
    }

    if (chat) {
        var chatLog = doc.getElementById('elv-chat-log');
        var chatForm = chat.querySelector('[data-chat-form]');
        var chatInput = doc.getElementById('elv-chat-input');
        var chatStatus = chat.querySelector('[data-chat-status]');
        var chatSuggest = chat.querySelector('[data-chat-suggest]');
        var chatError = chat.querySelector('[data-chat-error]');
        var chatSend = chat.querySelector('.elv-chat__send');
        var chatExpand = chat.querySelector('[data-chat-expand]');
        var chatOpeners = doc.querySelectorAll('[data-chat-open]');
        var chatPrompt = doc.querySelector('[data-chat-prompt]');
        var promptKey = 'elvChatPrompt';
        var busy = false;
        var greeted = false;

        var setOpeners = function (open) {
            Array.prototype.forEach.call(chatOpeners, function (button) {
                button.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        };

        var bubble = function (who, text) {
            var row = doc.createElement('div');
            row.className = 'elv-chat__msg elv-chat__msg--' + who;
            var box = doc.createElement('div');
            box.className = 'elv-chat__bubble';
            box.textContent = text;
            row.appendChild(box);
            chatLog.appendChild(row);
            chatLog.scrollTop = chatLog.scrollHeight;

            return { row: row, box: box };
        };

        var addLink = function (link) {
            if (!link || !link.url || !link.label) {
                return;
            }

            var anchor = doc.createElement('a');
            anchor.className = 'elv-chat__link';
            anchor.href = link.url;
            anchor.textContent = link.label;
            chatLog.appendChild(anchor);
            chatLog.scrollTop = chatLog.scrollHeight;
        };

        var setChips = function (chips) {
            if (!chatSuggest || !chips || !chips.length) {
                return;
            }

            chatSuggest.textContent = '';

            chips.forEach(function (text) {
                var chip = doc.createElement('button');
                chip.className = 'elv-chat__chip';
                chip.type = 'button';
                chip.textContent = text;
                chip.addEventListener('click', function () {
                    ask(text);
                });
                chatSuggest.appendChild(chip);
            });
        };

        var showError = function (text) {
            if (!chatError) {
                return;
            }

            chatError.textContent = text;
            chatError.hidden = !text;
        };

        var setBusy = function (state) {
            busy = state;
            if (chatSend) {
                chatSend.disabled = state;
            }
            if (chatStatus) {
                chatStatus.textContent = state ? 'Печатает…' : 'Отвечает по базе знаний сайта';
            }
        };

        var ask = function (text) {
            var message = String(text || '').trim();

            if (!message || busy) {
                return;
            }

            if (message.length > 500) {
                message = message.slice(0, 500);
            }

            showError('');
            var user = bubble('user', message);

            if (chatInput) {
                chatInput.value = '';
            }

            var typing = bubble('bot', '');
            typing.box.className = 'elv-chat__bubble elv-chat__typing';
            typing.box.innerHTML = '<i></i><i></i><i></i>';
            setBusy(true);

            fetch('/chat', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrf()
                },
                body: JSON.stringify({ message: message })
            })
                .then(function (response) {
                    return response.json().catch(function () {
                        return { ok: false };
                    }).then(function (data) {
                        return { status: response.status, data: data };
                    });
                })
                .then(function (result) {
                    typing.row.remove();

                    if (result.status === 200 && result.data && result.data.ok) {
                        if (result.data.hide_user) user.row.remove();
                        bubble('bot', result.data.answer || '');

                        if (result.data.sources) result.data.sources.forEach(addLink);
                        addLink(result.data.link);

                        if (result.data.open === 'feedback' && typeof openFeedback === 'function') {
                            var button = doc.createElement('button');
                            button.className = 'elv-chat__link';
                            button.type = 'button';
                            button.textContent = 'Открыть форму обратной связи';
                            button.addEventListener('click', function () {
                                closeChat();
                                openFeedback();
                            });
                            chatLog.appendChild(button);
                            chatLog.scrollTop = chatLog.scrollHeight;
                        }

                        setChips(result.data.chips);
                    }
                    else if (result.status === 429) {
                        showError('Слишком много сообщений подряд. Подождите минуту.');
                    }
                    else if (result.status === 422) {
                        showError('Напишите вопрос подробнее — от двух символов.');
                    }
                    else {
                        showError('Консультант недоступен. Напишите нам через форму обратной связи.');
                    }
                })
                .catch(function () {
                    typing.row.remove();
                    showError('Сеть недоступна. Попробуйте ещё раз.');
                })
                .then(function () {
                    setBusy(false);

                    if (chatInput) {
                        chatInput.focus();
                    }
                });
        };

        openChat = function () {
            if (!chat.hidden) {
                if (chatInput) {
                    chatInput.focus();
                }
                return;
            }

            chat.hidden = false;
            body.classList.add('is-chat-open');
            setOpeners(true);

            if (!greeted) {
                greeted = true;
                bubble('bot', chatLog.getAttribute('data-greeting') || '');
            }

            if (chatPrompt) {
                chatPrompt.hidden = true;
            }

            if (chatInput) {
                chatInput.focus();
            }
        };

        closeChat = function () {
            if (chat.hidden) {
                return;
            }

            chat.hidden = true;
            body.classList.remove('is-chat-open');
            setOpeners(false);
        };

        Array.prototype.forEach.call(chatOpeners, function (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();
                openChat();
            });
        });

        Array.prototype.forEach.call(chat.querySelectorAll('[data-chat-close]'), function (button) {
            button.addEventListener('click', closeChat);
        });

        if (chatForm) {
            chatForm.addEventListener('submit', function (event) {
                event.preventDefault();
                ask(chatInput ? chatInput.value : '');
            });
        }

        if (chatSuggest) {
            Array.prototype.forEach.call(chatSuggest.querySelectorAll('[data-chat-q]'), function (chip) {
                chip.addEventListener('click', function () {
                    ask(chip.getAttribute('data-chat-q'));
                });
            });
        }

        if (chatExpand) {
            chatExpand.addEventListener('click', function () {
                var expanded = chat.classList.toggle('is-expanded');
                chatExpand.setAttribute('aria-pressed', expanded ? 'true' : 'false');
            });
        }

        doc.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !chat.hidden) {
                closeChat();
            }
        });

        if (chatPrompt) {
            var seen = false;

            try {
                seen = window.sessionStorage.getItem(promptKey) === '1';
            }
            catch (error) {
                seen = true;
            }

            if (!seen) {
                window.setTimeout(function () {
                    if (chat.hidden) {
                        chatPrompt.hidden = false;
                    }
                }, 8000);
            }

            var hidePrompt = function () {
                chatPrompt.hidden = true;
                try {
                    window.sessionStorage.setItem(promptKey, '1');
                }
                catch (error) {
                    return;
                }
            };

            var promptClose = chatPrompt.querySelector('[data-chat-prompt-close]');
            if (promptClose) {
                promptClose.addEventListener('click', hidePrompt);
            }
        }
    }

    if (fab) {
        var fabToggle = fab.querySelector('[data-fab-toggle]');
        var fabLinks = Number(fabToggle ? fabToggle.getAttribute('data-links') : 0) || 0;

        var setFab = function (open) {
            fab.classList.toggle('is-open', open);
            if (fabToggle) {
                fabToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            }
        };

        if (fabToggle) {
            fabToggle.addEventListener('click', function (event) {
                event.stopPropagation();

                if (fabLinks === 0) {
                    setFab(false);

                    if (typeof openChat === 'function' && chat) {
                        openChat();
                    }
                    else if (typeof openFeedback === 'function') {
                        openFeedback();
                    }

                    return;
                }

                setFab(!fab.classList.contains('is-open'));
            });
        }

        Array.prototype.forEach.call(fab.querySelectorAll('.fab__item'), function (item) {
            item.addEventListener('click', function () {
                setFab(false);
            });
        });

        doc.addEventListener('click', function (event) {
            if (!fab.contains(event.target)) {
                setFab(false);
            }
        });

        doc.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                setFab(false);
            }
        });
    }

    var toTop = doc.querySelector('[data-to-top]');

    if (toTop) {
        var onScroll = function () {
            toTop.classList.toggle('is-visible', window.pageYOffset > 600);
        };

        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();

        toTop.addEventListener('click', function () {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }
})();
