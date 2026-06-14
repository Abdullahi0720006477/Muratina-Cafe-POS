/* Muratina Café POS — shared front-end behaviour */
(function () {
    'use strict';

    /* ---------- Theme toggle (persisted) ---------- */
    const html = document.documentElement;
    const saved = localStorage.getItem('pos-theme');
    if (saved) html.setAttribute('data-bs-theme', saved);
    syncThemeIcon();

    const themeBtn = document.getElementById('themeToggle');
    if (themeBtn) {
        themeBtn.addEventListener('click', () => {
            const next = html.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
            html.setAttribute('data-bs-theme', next);
            localStorage.setItem('pos-theme', next);
            syncThemeIcon();
        });
    }
    function syncThemeIcon() {
        const btn = document.getElementById('themeToggle');
        if (!btn) return;
        const dark = html.getAttribute('data-bs-theme') === 'dark';
        btn.innerHTML = dark ? '<i class="fa-solid fa-sun"></i>' : '<i class="fa-solid fa-moon"></i>';
    }

    /* ---------- Sidebar (mobile) ---------- */
    const sidebar = document.getElementById('sidebar');
    const toggle = document.getElementById('sidebarToggle');
    if (toggle && sidebar) {
        let backdrop = document.querySelector('.sidebar-backdrop');
        if (!backdrop) {
            backdrop = document.createElement('div');
            backdrop.className = 'sidebar-backdrop';
            document.body.appendChild(backdrop);
        }
        const close = () => { sidebar.classList.remove('open'); backdrop.classList.remove('show'); };
        toggle.addEventListener('click', () => {
            sidebar.classList.toggle('open');
            backdrop.classList.toggle('show');
        });
        backdrop.addEventListener('click', close);
    }
})();

/* ---------- Helpers exposed globally ---------- */
function fmtMoney(n) {
    return (window.CURRENCY || 'KSh') + ' ' + Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function postJSON(url, data) {
    return fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF || '' },
        body: JSON.stringify(data)
    }).then(r => r.json());
}
