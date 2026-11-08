/* Uses WooCommerce's existing totals refresh after the server-side quote is ready. */
(function ($) {
    'use strict';
    var timer = 0, busy = false, attempts = 0, started = 0, failures = 0, activeKey = '';
    function marker() { return document.querySelector('.notran-amadast-pending'); }
    function key(node) { return node ? node.dataset.notranKey || '' : ''; }
    function place() {
        document.querySelectorAll('.notran-amadast-status').forEach(function (node) {
            var row = node.closest('.woocommerce-shipping-totals'), heading = row && row.querySelector('th');
            if (heading && node.parentNode !== heading) heading.appendChild(node);
        });
    }
    function failed() {
        document.querySelectorAll('.notran-amadast-pending').forEach(function (node) {
            node.classList.add('is-error');
            node.querySelector('.notran-amadast-message').textContent = notranAmadast.error;
            if (!node.querySelector('button')) {
                var button = document.createElement('button'); button.type = 'button';
                button.className = 'notran-amadast-retry'; button.textContent = notranAmadast.retry;
                node.appendChild(button);
            }
        });
    }
    function schedule(delay) {
        var node = marker();
        if (!node || busy || timer || node.classList.contains('is-error')) return;
        if (activeKey !== key(node)) { activeKey = key(node); attempts = 0; started = 0; failures = 0; }
        if (attempts >= 30 || (started && Date.now() - started > 180000)) { failed(); return; }
        if (!started) started = Date.now();
        timer = window.setTimeout(check, delay || 100);
    }
    async function check() {
        timer = 0;
        if (!marker() || busy) return;
        busy = true; attempts++;
        var requestKey = key(marker()), abort = new AbortController(), timeout = window.setTimeout(function () { abort.abort(); }, 25000), delay = 0;
        try {
            var response = await fetch(notranAmadast.url, { method: 'POST', credentials: 'same-origin', signal: abort.signal,
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'nonce=' + encodeURIComponent(notranAmadast.nonce) });
            if (!response.ok) throw new Error('HTTP');
            var result = await response.json();
            if (!result.success) throw new Error('response');
            if (key(marker()) !== requestKey) { delay = 100; return; }
            failures = 0;
            if (result.data.refresh && marker()) $(document.body).trigger(notranAmadast.checkout ? 'update_checkout' : 'wc_update_cart');
            if (result.data.pending) delay = Math.max(1, Math.min(10, Number(result.data.delay) || 4)) * 1000;
            else { attempts = 0; started = 0; }
        } catch (_) {
            failures++;
            if (failures < 3) delay = 8000;
            else failed();
        } finally {
            window.clearTimeout(timeout); busy = false;
            if (delay) schedule(delay);
        }
    }
    $(document.body).on('updated_checkout updated_wc_div updated_cart_totals', function () {
        place();
        if (!marker()) { window.clearTimeout(timer); timer = 0; attempts = 0; started = 0; }
        else schedule();
    });
    document.addEventListener('click', function (event) {
        if (!event.target.closest('.notran-amadast-retry')) return;
        window.clearTimeout(timer); timer = 0; attempts = 0; failures = 0; started = 0;
        document.querySelectorAll('.notran-amadast-pending').forEach(function (node) {
            node.classList.remove('is-error'); node.querySelector('.notran-amadast-message').textContent = notranAmadast.loading;
            var button = node.querySelector('.notran-amadast-retry'); if (button) button.remove();
        });
        schedule(100);
    });
    $(function () { place(); schedule(); });
}(jQuery));
