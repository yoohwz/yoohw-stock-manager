/* Keep the submitted form intact so nonce and operation identity survive retries. */
(function () {
    'use strict';
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form.querySelector('input[name="action"][value="yoohwsm_adjust"]')) {
            return;
        }
        var button = event.submitter;
        if (button) {
            button.setAttribute('aria-busy', 'true');
        }
    });
}());
