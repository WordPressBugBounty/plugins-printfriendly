/**
 * Makes the Pro notice's X button stick. WordPress removes the notice from the
 * page on click; this tells the plugin, so it stays gone on the next load.
 * "No thanks" does the same as a plain link and needs none of this.
 */
(function () {
    document.addEventListener('click', function (event) {
        var target = event.target;
        if (! target || ! target.closest || ! window.fetch) {
            return;
        }
        var button = target.closest('#pf-pro-notice .notice-dismiss');
        if (! button) {
            return;
        }
        var notice = button.closest('#pf-pro-notice');
        var url = notice ? notice.getAttribute('data-dismiss-url') : '';
        if (url) {
            window.fetch(url, { credentials: 'same-origin' });
        }
    });
}());
