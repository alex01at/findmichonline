document.querySelectorAll('.password-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var input = document.getElementById(btn.dataset.toggleFor);
        if (!input) return;
        var showing = input.type === 'text';
        input.type = showing ? 'password' : 'text';
        btn.textContent = showing ? '👁' : '🙈';
    });
});

document.querySelectorAll('[data-strength-for]').forEach(function (meter) {
    var input = document.getElementById(meter.dataset.strengthFor);
    var fill = meter.querySelector('.password-strength-fill');
    var label = meter.querySelector('.password-strength-label');
    if (!input || !fill || !label) return;

    input.addEventListener('input', function () {
        var pw = input.value;
        var score = 0;
        if (pw.length >= 8) score++;
        if (pw.length >= 12) score++;
        if (/[a-z]/.test(pw) && /[A-Z]/.test(pw)) score++;
        if (/[0-9]/.test(pw)) score++;
        if (/[^A-Za-z0-9]/.test(pw)) score++;
        score = Math.min(score, 4);

        if (pw.length === 0) {
            fill.style.width = '0%';
            label.textContent = '';
            return;
        }

        var color = '#d33b3b';
        var text = meter.dataset.labelWeak;
        if (score >= 4) {
            color = '#1f9d55';
            text = meter.dataset.labelStrong;
        } else if (score >= 2) {
            color = '#e08e0b';
            text = meter.dataset.labelMedium;
        }

        fill.style.width = Math.max(15, (score / 4) * 100) + '%';
        fill.style.background = color;
        label.textContent = text;
    });
});

document.querySelectorAll('.tab-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var scope = btn.closest('.card') || document;
        scope.querySelectorAll('.tab-btn').forEach(function (b) { b.classList.remove('active'); });
        scope.querySelectorAll('.tab-panel').forEach(function (p) { p.hidden = true; });
        btn.classList.add('active');
        var panel = scope.querySelector('[data-tab-panel="' + btn.dataset.tab + '"]');
        if (panel) panel.hidden = false;
    });
});

(function () {
    var btn = document.getElementById('lang-switcher-btn');
    var modal = document.getElementById('lang-modal');
    var closeBtn = document.getElementById('lang-modal-close');
    if (!btn || !modal) return;

    function open() { modal.hidden = false; }
    function close() { modal.hidden = true; }

    btn.addEventListener('click', open);
    if (closeBtn) closeBtn.addEventListener('click', close);
    modal.addEventListener('click', function (e) {
        if (e.target === modal) close();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hidden) close();
    });
})();

(function () {
    var toggle = document.getElementById('mobile-nav-toggle');
    var closeBtn = document.getElementById('mobile-nav-close');
    var overlay = document.getElementById('mobile-nav-overlay');
    if (!toggle || !overlay) return;

    function open() {
        overlay.hidden = false;
        toggle.setAttribute('aria-expanded', 'true');
        requestAnimationFrame(function () {
            overlay.classList.add('open');
        });
    }

    function close() {
        overlay.classList.remove('open');
        toggle.setAttribute('aria-expanded', 'false');
        setTimeout(function () {
            overlay.hidden = true;
        }, 250);
    }

    toggle.addEventListener('click', open);
    if (closeBtn) closeBtn.addEventListener('click', close);
    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) close();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !overlay.hidden) close();
    });
})();

(function () {
    var lightbox = document.getElementById('image-lightbox');
    var lightboxImg = document.getElementById('image-lightbox-img');
    if (!lightbox || !lightboxImg) return;

    document.querySelectorAll('[data-lightbox]').forEach(function (trigger) {
        trigger.addEventListener('click', function (e) {
            e.preventDefault();
            lightboxImg.src = trigger.getAttribute('href');
            lightbox.hidden = false;
        });
    });

    lightbox.addEventListener('click', function () {
        lightbox.hidden = true;
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !lightbox.hidden) lightbox.hidden = true;
    });
})();

(function () {
    var tabs = document.querySelectorAll('#design-showcase-tabs .design-tab-btn');
    var frame = document.getElementById('design-showcase-frame');
    if (!tabs.length || !frame) return;

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            tabs.forEach(function (t) { t.classList.remove('active'); });
            tab.classList.add('active');
            frame.src = '/demo-card/' + tab.dataset.design;
        });
    });
})();
