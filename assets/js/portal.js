/**
 * Customer portal enhancements. The form works without this script; it only keeps the summary live while the
 * customer chooses, enables the quantity field when an alternative is picked, and moves focus to status messages.
 */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    function fill(template, args) {
        return String(template || '').replace(/%(\d+)\$s/g, function (match, index) {
            var value = args[parseInt(index, 10) - 1];
            return value === undefined ? '' : String(value);
        });
    }

    function makeMoney(currency) {
        var decimals = parseInt(currency.decimals, 10);
        if (isNaN(decimals)) {
            decimals = 2;
        }
        return function (amount) {
            var fixed = Math.abs(amount).toFixed(decimals);
            var parts = fixed.split('.');
            var whole = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, currency.thousandSep || '');
            var number = parts.length > 1 ? whole + (currency.decimalSep || ',') + parts[1] : whole;
            return fill(currency.format || '%1$s%2$s', [currency.symbol || '', number]);
        };
    }

    ready(function () {
        var notice = document.querySelector('.lp-missing-portal [data-lp-focus]');
        if (notice && typeof notice.focus === 'function') {
            notice.focus();
        }

        var form = document.querySelector('.lp-missing-portal .lp-portal-form');
        if (!form) {
            return;
        }
        var config = {};
        try {
            config = JSON.parse(form.getAttribute('data-lp-config') || '{}');
        } catch (e) {
            return;
        }
        var i18n = config.i18n || {};
        var money = makeMoney(config.currency || {});
        var decimals = parseInt((config.currency || {}).decimals, 10) || 0;
        var threshold = 0.5 * Math.pow(10, -decimals);
        var summaryList = form.querySelector('.lp-portal-summary__lines');
        var summaryTotal = form.querySelector('.lp-portal-summary__total');

        function round(value) {
            var factor = Math.pow(10, decimals);
            return Math.round(value * factor) / factor;
        }

        // Mirrors LP_Missing_Pricing::store_covers_difference(); exact per-quantity answers come from the server.
        function storeCovers(option, qty, total) {
            var flags = option.getAttribute('data-covers') || '';
            if (qty >= 1 && qty <= flags.length) {
                return flags.charAt(qty - 1) === '1';
            }
            if (config.priceMode === 'store_covers') {
                return true;
            }
            return config.coversBelow > 0 && total <= config.coversBelow + 0.000001;
        }

        function describe(item) {
            var name = item.getAttribute('data-name') || '';
            var missing = parseInt(item.getAttribute('data-qty-missing'), 10) || 1;
            var checked = item.querySelector('.lp-option__input:checked');
            var qtyInput = item.querySelector('.lp-qty-input');
            var isAlt = !!(checked && checked.getAttribute('data-type') === 'alt');

            if (qtyInput && !qtyInput.hasAttribute('data-lp-readonly')) {
                qtyInput.disabled = !isAlt || checked.disabled;
                if (isAlt) {
                    qtyInput.max = checked.getAttribute('data-max-qty') || String(missing);
                }
            }
            if (!checked) {
                return { text: fill(i18n.none, [name]), charged: 0 };
            }
            var type = checked.getAttribute('data-type');
            if (type === 'decline' || type === 'delete') {
                return { text: fill(i18n[type], [name, missing]), charged: 0 };
            }
            var max = parseInt(checked.getAttribute('data-max-qty'), 10) || missing;
            var qty = qtyInput ? parseInt(qtyInput.value, 10) : missing;
            if (isNaN(qty) || qty < 1) {
                qty = 1;
            }
            qty = Math.min(qty, max);
            var delta = parseFloat(checked.getAttribute('data-delta-unit')) || 0;
            var total = round(delta * qty);
            var args = [name, qty, checked.getAttribute('data-label') || '', money(total)];
            var text;
            var charged = 0;
            if (delta >= threshold) {
                if (storeCovers(checked, qty, total)) {
                    text = fill(i18n.alt_up_covered, args);
                } else {
                    text = fill(i18n.alt_up_charged, args);
                    charged = total;
                }
            } else if (delta <= -threshold) {
                text = fill(i18n.alt_down, args);
            } else {
                text = fill(i18n.alt_same, args);
            }
            if (qty < missing) {
                text += ' ' + fill(i18n.partial, [missing - qty]);
            }
            return { text: text, charged: charged };
        }

        function renderedSignature() {
            var texts = [];
            var items = summaryList.querySelectorAll('li');
            for (var i = 0; i < items.length; i++) {
                texts.push(items[i].textContent.trim());
            }
            return texts.join('\n') + '|' + summaryTotal.textContent.trim();
        }

        function update() {
            if (!summaryList || !summaryTotal) {
                return;
            }
            var items = form.querySelectorAll('.lp-portal-item');
            var charged = 0;
            var lines = [];
            for (var i = 0; i < items.length; i++) {
                var line = describe(items[i]);
                charged += line.charged;
                lines.push(line.text);
            }
            var totalText = charged > 0 ? fill(i18n.total_charged, [money(charged)]) : (i18n.total_none || '');
            // Only rewrite when something changed, so screen readers are not told the same summary again.
            if (lines.join('\n') + '|' + totalText === renderedSignature()) {
                return;
            }
            while (summaryList.firstChild) {
                summaryList.removeChild(summaryList.firstChild);
            }
            for (var j = 0; j < lines.length; j++) {
                var li = document.createElement('li');
                li.textContent = lines[j];
                summaryList.appendChild(li);
            }
            var strong = document.createElement('strong');
            strong.textContent = totalText;
            while (summaryTotal.firstChild) {
                summaryTotal.removeChild(summaryTotal.firstChild);
            }
            summaryTotal.appendChild(strong);
        }

        // Quantity fields of a read-only preview stay disabled.
        var readonlyQty = form.querySelectorAll('.lp-missing-portal--readonly .lp-qty-input');
        for (var k = 0; k < readonlyQty.length; k++) {
            readonlyQty[k].setAttribute('data-lp-readonly', '1');
        }

        form.addEventListener('change', update);
        form.addEventListener('input', update);
        update();
    });
})();
