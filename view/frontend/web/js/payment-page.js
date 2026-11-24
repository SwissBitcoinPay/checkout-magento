/**
 * The payment page's script, a file of the module (no inline script, nothing from another host):
 * counts down the time left, copies the invoice and the address, and asks the server for news of
 * the payment (the server checks at most every few seconds per invoice), reloading the page when
 * its phase changed. Without it, the page works the same with its buttons.
 */
define([], function () {
    'use strict';

    /**
     * @param {Object} config statusUrl, phase, secondsLeft, interval, minInterval
     * @param {HTMLElement} element the page's root
     */
    return function (config, element) {
        var deadline = Date.now() + (config.secondsLeft || 0) * 1000,
            countdown = element.querySelector('[data-role="countdown"]'),
            stopped = false,
            interval = Math.max(config.interval || 3, 1) * 1000;

        /**
         * @param {Number} seconds
         * @return {String}
         */
        function format(seconds) {
            var h = Math.floor(seconds / 3600),
                m = Math.floor(seconds % 3600 / 60),
                s = seconds % 60,
                pad = function (n) { return (n < 10 ? '0' : '') + n; };

            return h > 0 ? h + ':' + pad(m) + ':' + pad(s) : m + ':' + pad(s);
        }

        if (countdown && config.secondsLeft > 0) {
            window.setInterval(function () {
                countdown.textContent = format(Math.max(0, Math.round((deadline - Date.now()) / 1000)));
            }, 1000);
        }

        Array.prototype.forEach.call(element.querySelectorAll('[data-role="copy"]'), function (button) {
            var input = document.getElementById(button.getAttribute('data-target'));

            if (!input || !navigator.clipboard) {
                return;
            }
            button.hidden = false;
            button.addEventListener('click', function () {
                navigator.clipboard.writeText(input.value).then(function () {
                    button.classList.add('sattleme-payment__copied');
                    window.setTimeout(function () {
                        button.classList.remove('sattleme-payment__copied');
                    }, 1500);
                });
            });
        });

        /**
         * Asks the server, then again after the interval, unless the phase changed.
         */
        function poll() {
            if (stopped || !config.statusUrl || !window.fetch) {
                return;
            }
            window.fetch(config.statusUrl, {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {'Accept': 'application/json'}
            }).then(function (response) {
                return response.ok ? response.json() : null;
            }).then(function (data) {
                if (data && data.phase && data.phase !== config.phase) {
                    stopped = true;
                    window.location.reload();

                    return;
                }
                window.setTimeout(poll, interval);
            }).catch(function () {
                window.setTimeout(poll, Math.max(interval, config.minInterval * 1000 || 6000));
            });
        }

        window.setTimeout(poll, interval);
    };
});
