/**
 * Pro card v2 (views/pro-card.php). Every action is a plain nonced link that
 * the server handles, so this file only adds polish: a busy state on the
 * buttons that leave for printfriendly.com, and the stub-state switcher.
 */
(function ($) {
    'use strict';

    $(function () {
        var $card = $('#pf-pro-card');
        if (!$card.length) {
            return;
        }

        $card.on('click', 'a.pf-card-btn[data-busy]', function () {
            var $btn = $(this);
            if ($btn.hasClass('is-busy')) {
                return false;
            }
            $btn.addClass('is-busy').attr('aria-busy', 'true').text($btn.data('busy'));
            return true;
        });

        $card.on('change', '#pf-stub-state', function () {
            var base = $(this).data('url');
            window.location.href = base + (base.indexOf('?') === -1 ? '?' : '&') + 'pf_stub_state=' + encodeURIComponent(this.value);
        });
    });
})(jQuery);
