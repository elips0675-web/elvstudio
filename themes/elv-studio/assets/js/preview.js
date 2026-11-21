(function () {
    'use strict';

    var blocks = document.querySelectorAll('[data-preview]');

    if (!blocks.length) {
        return;
    }

    var deviceOf = function (block) {
        return block.getAttribute('data-device') || 'desktop';
    };

    var setDevice = function (block, device) {
        block.setAttribute('data-device', device);

        var frame = block.querySelector('[data-preview-frame]');

        if (frame) {
            frame.className = 'preview__frame preview__frame--' + device;
        }

        var buttons = block.querySelectorAll('[data-preview-device]');

        for (var i = 0; i < buttons.length; i++) {
            var active = buttons[i].getAttribute('data-preview-device') === device;
            buttons[i].classList.toggle('is-active', active);
            buttons[i].setAttribute('aria-pressed', active ? 'true' : 'false');
        }

        if (block.getAttribute('data-preview-mode') === 'page' && window.history && window.history.replaceState) {
            var url = new URL(window.location.href);

            if (device === 'desktop') {
                url.searchParams.delete('device');
            } else {
                url.searchParams.set('device', device);
            }

            window.history.replaceState(null, '', url.toString());
        }
    };

    for (var b = 0; b < blocks.length; b++) {
        (function (block) {
            block.addEventListener('click', function (event) {
                var button = event.target.closest ? event.target.closest('[data-preview-device]') : null;

                if (button && block.contains(button)) {
                    setDevice(block, button.getAttribute('data-preview-device'));
                }
            });

            var select = block.querySelector('[data-preview-select]');

            if (!select) {
                return;
            }

            select.addEventListener('change', function () {
                var option = select.options[select.selectedIndex];

                if (!option) {
                    return;
                }

                if (block.getAttribute('data-preview-mode') === 'page') {
                    var url = option.value;
                    var device = deviceOf(block);

                    if (device !== 'desktop') {
                        url += (url.indexOf('?') === -1 ? '?' : '&') + 'device=' + device;
                    }

                    window.location.href = url;
                    return;
                }

                var frame = block.querySelector('[data-preview-frame]');
                var src = option.getAttribute('data-src');

                if (frame && src) {
                    frame.src = src;
                }

                var open = block.querySelector('[data-preview-open]');

                if (open && src) {
                    open.href = src;
                }

                var buy = block.querySelector('[data-preview-buy]');
                var order = option.getAttribute('data-order');

                if (buy && order) {
                    buy.href = order;
                }

                var full = block.querySelector('[data-preview-full]');
                var previewUrl = option.getAttribute('data-preview-url');

                if (full && previewUrl) {
                    full.href = previewUrl;
                }
            });
        })(blocks[b]);
    }
})();
