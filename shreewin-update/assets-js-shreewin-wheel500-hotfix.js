(function () {
    'use strict';

    var rewardText = 'Get ₹500';

    function applyWheelReward() {
        var labels = document.querySelectorAll('#app .turntable-text');
        labels.forEach(function (label) {
            if (label.textContent.trim() !== rewardText) {
                label.textContent = rewardText;
            }
            label.setAttribute('aria-label', rewardText);
        });
    }

    // v5: add the reference game's ✕ close button to the Add-to-Desktop pill.
    // The DownloadPWA component in this skin ships no close control, so one is
    // injected here; clicking it hides the pill for the current session only.
    function addPillClose() {
        var pills = document.querySelectorAll('#app .pwa-btn');
        pills.forEach(function (pill) {
            var closed = false;
            try { closed = sessionStorage.getItem('sw-pwa-closed') === '1'; } catch (e) {}
            if (closed) { pill.style.setProperty('display', 'none', 'important'); return; }
            if (pill.querySelector('.sw-close')) return;
            var x = document.createElement('span');
            x.className = 'sw-close';
            x.setAttribute('role', 'button');
            x.setAttribute('aria-label', 'Close');
            x.textContent = '\u00d7';
            x.addEventListener('click', function (ev) {
                ev.stopPropagation();
                ev.preventDefault();
                pill.style.setProperty('display', 'none', 'important');
                try { sessionStorage.setItem('sw-pwa-closed', '1'); } catch (e) {}
            });
            pill.appendChild(x);
        });
    }

    function applyAll() {
        applyWheelReward();
        addPillClose();
    }

    function start() {
        applyAll();
        var observer = new MutationObserver(applyAll);
        observer.observe(document.documentElement, {
            childList: true,
            subtree: true,
            characterData: true
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start, { once: true });
    } else {
        start();
    }
}());
