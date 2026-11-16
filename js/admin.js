/* Keep the submitted form intact so nonce and operation identity survive retries. */
(function () {
    'use strict';

    function revealAdjustment() {
        var panel = document.getElementById('ysm-adjust');
        if (!panel) {
            return;
        }
        var quantity = panel.querySelector('input[name="quantity"]');
        if (quantity) {
            quantity.setAttribute('aria-describedby', 'ysm-adjust-context');
            quantity.focus({ preventScroll: true });
        }
        panel.scrollIntoView({ block: 'start' });
    }
    function scheduleAdjustmentReveal() {
        window.requestAnimationFrame(revealAdjustment);
    }
    if (document.readyState === 'complete') {
        scheduleAdjustmentReveal();
    } else {
        window.addEventListener('load', scheduleAdjustmentReveal);
    }
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form.querySelector('input[name="action"][value="ysm_adjust"]')) {
            return;
        }
        var button = event.submitter;
        if (button) {
            button.setAttribute('aria-busy', 'true');
        }
    });
}());
