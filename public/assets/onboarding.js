(function () {
    var toggle = document.getElementById('onboarding-preview-toggle');
    var col = document.getElementById('onboarding-preview-col');
    if (!toggle || !col) return;

    toggle.addEventListener('click', function () {
        var showing = col.style.display === 'block';
        col.style.display = showing ? 'none' : 'block';
        toggle.textContent = showing ? toggle.dataset.showLabel : toggle.dataset.hideLabel;
    });
})();

(function () {
    var wrap = document.getElementById('onboarding-preview-frame-wrap');
    var modeButtons = document.querySelectorAll('.onboarding-preview-mode-btn');
    if (!wrap || !modeButtons.length) return;

    modeButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            modeButtons.forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            wrap.classList.toggle('desktop-mode', btn.dataset.mode === 'desktop');
        });
    });
})();

(function () {
    var radios = document.querySelectorAll('input[name="design"]');
    var mainFrame = document.getElementById('onboarding-preview-frame');
    if (!radios.length) return;

    radios.forEach(function (radio) {
        radio.addEventListener('change', function () {
            if (radio.checked && mainFrame) {
                mainFrame.src = '/card/preview?design=' + radio.value;
            }
        });
    });

    // Belt-and-suspenders: explicitly select the radio on click instead
    // of relying solely on native label-click-forwarding, since the
    // label wraps an <iframe> (a separate browsing context that can
    // interfere with click handling in some browsers).
    document.querySelectorAll('.onboarding-design-option').forEach(function (option) {
        option.addEventListener('click', function () {
            var input = option.querySelector('input[type="radio"]');
            if (!input || input.disabled || input.checked) return;
            input.checked = true;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        });
    });
})();

(function () {
    // Only present on /team/branding: the design grid and the palette grid
    // each fix one axis and preview the other, so picking a palette must
    // refresh the (fixed-design) palette thumbnails, and picking a design
    // must refresh the (fixed-palette) design thumbnails - the design-radio
    // change listener above already exists for the plain onboarding wizard
    // and just no-ops here (#onboarding-preview-frame doesn't exist on this
    // page), so this runs independently rather than replacing it.
    var paletteRadios = document.querySelectorAll('input[name="color_preset"]');
    var previewFrames = document.querySelectorAll('.branding-preview-frame');
    if (!paletteRadios.length || !previewFrames.length) return;

    function currentValue(name, fallback) {
        var checked = document.querySelector('input[name="' + name + '"]:checked');
        return checked ? checked.value : fallback;
    }

    function refreshPreviews() {
        var design = currentValue('design', 'classic');
        var palette = currentValue('color_preset', '');
        previewFrames.forEach(function (frame) {
            var designOption = frame.closest('[data-design]');
            var paletteOption = frame.closest('[data-palette]');
            var d = designOption ? designOption.dataset.design : design;
            var p = paletteOption ? paletteOption.dataset.palette : palette;
            frame.src = '/card/preview?design=' + d + '&palette=' + p;
        });
    }

    document.querySelectorAll('input[name="design"]').forEach(function (radio) {
        radio.addEventListener('change', refreshPreviews);
    });
    paletteRadios.forEach(function (radio) {
        radio.addEventListener('change', refreshPreviews);
    });
})();

(function () {
    var input = document.getElementById('slug');
    var status = document.getElementById('onboarding-slug-status');
    if (!input || !status) return;

    var timer = null;
    input.addEventListener('input', function () {
        clearTimeout(timer);
        var value = input.value.trim().toLowerCase();
        if (!value) {
            status.textContent = '';
            status.className = 'onboarding-slug-status';
            return;
        }
        timer = setTimeout(function () {
            fetch('/onboarding/check-slug?slug=' + encodeURIComponent(value))
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.available) {
                        status.textContent = '✓ ' + status.dataset.availableText.replace('{slug}', value);
                        status.className = 'onboarding-slug-status available';
                    } else {
                        status.textContent = '✕ ' + status.dataset.takenText;
                        status.className = 'onboarding-slug-status taken';
                    }
                })
                .catch(function () {});
        }, 400);
    });
})();
