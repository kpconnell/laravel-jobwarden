<script>
(function () {
    'use strict';

    // wire:navigate re-evaluates body scripts on every navigation; all our
    // wiring is document-level and survives the body swap, so register once.
    if (window.__jwScriptsBooted) return;
    window.__jwScriptsBooted = true;

    // ---- timezone renderer -------------------------------------------------
    // Render every <time data-jw-epoch> in the viewer's own timezone. The epoch
    // is the true instant (computed in SQL), so this is correct regardless of
    // the app or DB timezone. Re-runs after each Livewire morph because
    // wire:poll replaces the nodes.
    var timeFmt = new Intl.DateTimeFormat([], { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });

    function ago(ms) {
        var s = Math.round((Date.now() - ms) / 1000), a = Math.abs(s), future = s < 0;
        function u(n, unit) { return n + ' ' + unit + (n === 1 ? '' : 's') + (future ? ' from now' : ' ago'); }
        if (a < 45) return future ? 'in a few seconds' : 'just now';
        if (a < 5400) return u(Math.max(1, Math.round(a / 60)), 'min');
        if (a < 129600) return u(Math.round(a / 3600), 'hour');
        return u(Math.round(a / 86400), 'day');
    }

    function renderTimes(root) {
        (root || document).querySelectorAll('time[data-jw-epoch]').forEach(function (el) {
            var ms = Number(el.getAttribute('data-jw-epoch'));
            if (!ms) return;
            var d = new Date(ms);
            el.title = d.toLocaleString();
            el.textContent = el.getAttribute('data-jw-time') === 'time' ? timeFmt.format(d) : ago(ms);
        });
    }

    // ---- theme / density (persisted client-side on <html>) -----------------
    function syncThemeUI() {
        var t = document.documentElement.getAttribute('data-theme') || 'dark';
        document.querySelectorAll('[data-jw-theme-label]').forEach(function (el) { el.textContent = t === 'dark' ? 'Dark' : 'Light'; });
        document.querySelectorAll('[data-jw-theme-icon]').forEach(function (el) { el.textContent = t === 'dark' ? '◐' : '◑'; });
    }

    window.jwToggleTheme = function () {
        var next = (document.documentElement.getAttribute('data-theme') || 'dark') === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', next);
        try { localStorage.setItem('jw-theme', next); } catch (e) {}
        syncThemeUI();
    };

    window.jwToggleDensity = function () {
        var next = (document.documentElement.getAttribute('data-density') || 'compact') === 'compact' ? 'comfortable' : 'compact';
        document.documentElement.setAttribute('data-density', next);
        try { localStorage.setItem('jw-density', next); } catch (e) {}
    };

    // ---- toast --------------------------------------------------------------
    var toastTimer = null;
    function showToast(detail) {
        var box = document.getElementById('jw-toast');
        if (!box) return;
        var d = detail || {};
        box.querySelector('[data-jw-toast-msg]').textContent = d.message || '';
        box.querySelector('[data-jw-toast-detail]').textContent = d.detail || '';
        box.querySelector('[data-jw-toast-dot]').className = 'sdot ' + (d.tone === 'error' ? 'h-red' : 'h-green');
        box.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { box.hidden = true; }, 4000);
    }

    // ---- topbar: search / refresh / updated-ago -----------------------------
    document.addEventListener('submit', function (e) {
        var f = e.target && e.target.closest ? e.target.closest('form[data-jw-search]') : null;
        if (!f) return;
        e.preventDefault();
        var q = (f.querySelector('input[name="q"]').value || '').trim();
        var url = f.getAttribute('action') + (q ? '?q=' + encodeURIComponent(q) : '');
        if (window.Livewire && window.Livewire.navigate) window.Livewire.navigate(url);
        else window.location.href = url;
    });

    document.addEventListener('click', function (e) {
        var t = e.target;
        if (!t || !t.closest) return;

        var refresh = t.closest('[data-jw-refresh]');
        if (refresh) {
            refresh.classList.add('spin');
            setTimeout(function () { refresh.classList.remove('spin'); }, 700);
            if (window.Livewire) window.Livewire.all().forEach(function (c) { if (c.$wire) c.$wire.$refresh(); });
            return;
        }

        // Clickable table rows: navigate unless an interactive child was hit.
        var row = t.closest('[data-jw-href]');
        if (row && !t.closest('a,button,input,select,label')) {
            if (window.Livewire && window.Livewire.navigate) window.Livewire.navigate(row.getAttribute('data-jw-href'));
            else window.location.href = row.getAttribute('data-jw-href');
        }
    });

    var updatedAt = Date.now();
    function tickUpdated() {
        var s = Math.round((Date.now() - updatedAt) / 1000);
        // Coarse buckets, and write only on change — the label lives in the
        // topbar flex row, so a churning textContent invalidates layout for
        // the whole header every tick.
        var label = s < 10 ? 'just now' : (s < 60 ? (Math.floor(s / 10) * 10) + 's ago' : Math.round(s / 60) + 'm ago');
        document.querySelectorAll('[data-jw-updated]').forEach(function (el) {
            if (el.textContent !== label) el.textContent = label;
        });
    }
    setInterval(tickUpdated, 5000);

    // ---- log tail autoscroll -------------------------------------------------
    function autoscroll(root) {
        var els = (root || document).querySelectorAll('[data-jw-autoscroll]');
        els.forEach(function (el) { el.scrollTop = el.scrollHeight; });
        if (root && root.matches && root.matches('[data-jw-autoscroll]')) root.scrollTop = root.scrollHeight;
    }

    // ---- boot ----------------------------------------------------------------
    function boot(root) { renderTimes(root); syncThemeUI(); }

    document.addEventListener('DOMContentLoaded', function () { boot(); autoscroll(); });
    document.addEventListener('livewire:navigated', function () { boot(); autoscroll(); tickUpdated(); });
    document.addEventListener('livewire:initialized', function () {
        boot();
        window.Livewire.on('jw-toast', function (payload) {
            // Livewire 3 passes named event params as a single object (or array-wrapped).
            showToast(Array.isArray(payload) ? payload[0] : payload);
        });
        window.Livewire.hook('morphed', function (payload) {
            var el = payload && payload.el;
            renderTimes(el);
            autoscroll(el);
        });
        window.Livewire.hook('commit', function (ctx) {
            ctx.succeed(function () { updatedAt = Date.now(); tickUpdated(); });
        });
    });
})();
</script>
