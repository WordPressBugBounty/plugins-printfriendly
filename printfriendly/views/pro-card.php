<?php

/**
 * Pro card v2. Included from PrintFriendly_Pro_Card::render() with $card set
 * by PrintFriendly_Pro_Card::view_model(). Only rendered when the
 * PRINTFRIENDLY_PRO_CARD_V2 flag is on; views/pro.php is the default.
 *
 * Copy rules: no em dashes, never WordPress core's plugin verb for the trial
 * action, and the yellow button is always last in the action row so it sits
 * on the right.
 */

if (! defined('ABSPATH')) {
    exit;
}
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included from PrintFriendly_Pro_Card::render(); $card is method-scoped, not global.

$pf_state = $card['state'];
$pf_pills = array(
    'offer' => array('free', __('Free plan', 'printfriendly')),
    'free' => array('free', __('Free plan', 'printfriendly')),
    'free_connected' => array('free', __('Free plan', 'printfriendly')),
    'expired' => array('free', __('Free plan', 'printfriendly')),
    'expired_eligible' => array('free', __('Free plan', 'printfriendly')),
    'trial' => array('trial', __('Pro trial', 'printfriendly')),
    'pro' => array('pro', __('Pro', 'printfriendly')),
    'past_due' => array('pastdue', __('Payment failed', 'printfriendly')),
    'cancelled' => array('cancelled', __('Pro ending', 'printfriendly')),
);
$pf_pill = isset($pf_pills[$pf_state]) ? $pf_pills[$pf_state] : $pf_pills['free'];

$pf_notices = array(
    'connected' => array('success', __('Connected. Your plan status now comes straight from your PrintFriendly account.', 'printfriendly')),
    'pending' => array('info', __('The setup on printfriendly.com is not finished yet. The button below takes you back to it.', 'printfriendly')),
    'handoff_failed' => array('error', __('We could not finish connecting this site. Try again, or email support@printfriendly.com.', 'printfriendly')),
    'start_failed' => array('error', __('We could not reach PrintFriendly. Please try again in a minute.', 'printfriendly')),
    'refreshed' => array('success', __('Status updated.', 'printfriendly')),
);

/**
 * "We could not reach PrintFriendly" is only true when nothing answered. A
 * server that answered with a status is a different failure and points
 * somewhere else: a 404 in particular means the address is wrong, not that
 * PrintFriendly is down, and reading it as an outage has cost real debugging
 * time more than once. The status is on hand, so say it.
 */
if ($card['notice'] === 'start_failed' && ! empty($card['notice_status'])) {
    $pf_status = (int) $card['notice_status'];
    if ($pf_status === 404) {
        /* translators: %s: the API address the plugin is calling, for example https://www.printfriendly.com */
        $pf_start_failed = sprintf(__('PrintFriendly answered from %s but has no page there (HTTP 404). That usually means the API address is wrong rather than PrintFriendly being down.', 'printfriendly'), PrintFriendly_Pro_Card::api_base());
    } else {
        /* translators: 1: HTTP status code, for example 503, 2: the API address the plugin is calling */
        $pf_start_failed = sprintf(__('PrintFriendly returned HTTP %1$d from %2$s. Please try again in a minute.', 'printfriendly'), $pf_status, PrintFriendly_Pro_Card::api_base());
    }
    // Only the message: the severity stays owned by the array above, so
    // changing 'error' there is not silently undone here.
    $pf_notices['start_failed'][1] = $pf_start_failed;
}

/* translators: %s: the site's domain name */
$pf_live_on = sprintf(__('Live on %s', 'printfriendly'), $card['host']);
/* translators: %s: the email address of the connected PrintFriendly account */
$pf_connected_as = sprintf(__('Connected as %s', 'printfriendly'), $card['account_email']);
$pf_why_pro = __('Free works forever, but readers finish on printfriendly.com, where the ads are. Pro opens the preview on your own pages, ad-free.', 'printfriendly');
/* translators: 1: price line, for example "$8.25 a month, billed yearly", 2: the yearly charge, for example "$99" */
$pf_trial_terms = sprintf(__('30-day trial, card required, nothing charged today. Then %1$s at %2$s. Cancel anytime.', 'printfriendly'), $card['price_line'], $card['charge_amount']);
?>
<div id="pf-pro-card" class="pf-bu-block pf-card pf-card-state-<?php echo esc_attr($pf_state); ?>" data-state="<?php echo esc_attr($pf_state); ?>">

    <?php if ($card['stub']) { ?>
        <div class="pf-card-stub">
            <strong><?php esc_html_e('Stub mode', 'printfriendly'); ?></strong>
            <span><?php esc_html_e('PRINTFRIENDLY_API_STUB is on. No request leaves this site. Pick a state to preview it:', 'printfriendly'); ?></span>
            <label for="pf-stub-state" class="screen-reader-text"><?php esc_html_e('Stub state', 'printfriendly'); ?></label>
            <select id="pf-stub-state" data-url="<?php echo esc_url($card['stub_url']); ?>">
                <?php foreach ($card['stub_states'] as $pf_stub_state) { ?>
                    <option value="<?php echo esc_attr($pf_stub_state); ?>" <?php selected($card['stub_state'], $pf_stub_state); ?>><?php echo esc_html($pf_stub_state); ?></option>
                <?php } ?>
            </select>
        </div>
    <?php } ?>

    <?php if ($card['notice'] && isset($pf_notices[$card['notice']])) { ?>
        <div class="pf-card-notice pf-card-notice-<?php echo esc_attr($pf_notices[$card['notice']][0]); ?>" role="status">
            <?php echo esc_html($pf_notices[$card['notice']][1]); ?>
        </div>
    <?php } ?>

    <?php if ($card['unreachable']) { ?>
        <div class="pf-card-notice pf-card-notice-warning" role="status">
            <?php esc_html_e('We could not reach PrintFriendly to check your plan. Your button keeps working either way.', 'printfriendly'); ?>
            <a href="<?php echo esc_url($card['refresh_url']); ?>"><?php esc_html_e('Try again', 'printfriendly'); ?></a>
        </div>
    <?php } elseif ($card['stale']) { ?>
        <div class="pf-card-notice pf-card-notice-warning" role="status">
            <?php esc_html_e('We could not reach PrintFriendly just now, so this is the last status we saw.', 'printfriendly'); ?>
            <a href="<?php echo esc_url($card['refresh_url']); ?>"><?php esc_html_e('Try again', 'printfriendly'); ?></a>
        </div>
    <?php } ?>

    <?php if ($pf_state === 'offer') { ?>
        <div class="pf-card-offer">
            <div class="pf-card-offer-copy">
                <div class="pf-card-status">
                    <span class="pf-card-pill pf-card-pill-<?php echo esc_attr($pf_pill[0]); ?>"><?php echo esc_html($pf_pill[1]); ?></span>
                    <span class="pf-card-context"><?php echo esc_html($pf_live_on); ?></span>
                </div>
                <h2 class="pf-card-headline pf-card-headline-lg"><?php esc_html_e('Remove the ads. Keep users on your site.', 'printfriendly'); ?></h2>
                <p class="pf-card-line pf-card-line-lg"><?php echo esc_html($pf_why_pro); ?></p>
                <p class="pf-card-line pf-card-line-sm">
                    <?php
                    echo wp_kses(
                        sprintf(
                            /* translators: 1: the site's domain name, 2: the admin email address */
                            __('We will set this up for %1$s using %2$s. You can change either on the next screen.', 'printfriendly'),
                            '<strong>' . esc_html($card['host']) . '</strong>',
                            '<strong>' . esc_html($card['admin_email']) . '</strong>'
                        ),
                        array('strong' => array())
                    );
                    ?>
                </p>
                <div class="pf-card-actions">
                    <a class="pf-card-btn pf-card-btn-ghost" href="<?php echo esc_url($card['stay_free_url']); ?>"><?php esc_html_e('Keep the free plan', 'printfriendly'); ?></a>
                    <a class="pf-card-btn pf-card-btn-yellow pf-card-btn-lg" href="<?php echo esc_url($card['start_url']); ?>" data-busy="<?php esc_attr_e('Opening PrintFriendly', 'printfriendly'); ?>"><?php esc_html_e('Remove ads now, free for 30 days', 'printfriendly'); ?></a>
                </div>
                <p class="pf-card-fine"><?php echo esc_html($pf_trial_terms); ?></p>
            </div>

            <div class="pf-card-offer-visual" aria-hidden="true">
                <div class="pf-card-badge">
                    <span class="pf-card-badge-dot"><svg width="11" height="11" viewBox="0 0 16 16" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8.5 L6.5 12 L13 4.5"></path></svg></span>
                    <span class="pf-card-badge-text"><?php esc_html_e('Ad-free, on your site', 'printfriendly'); ?></span>
                </div>
                <div class="pf-card-mock">
                    <div class="pf-card-mock-bar">
                        <i></i><i></i><i></i>
                        <span class="pf-card-mock-url"><?php echo esc_html($card['host'] . $card['sample_path']); ?></span>
                    </div>
                    <div class="pf-card-mock-page">
                        <div class="pf-card-mock-skel" style="width:70%"></div>
                        <div class="pf-card-mock-skel"></div>
                        <div class="pf-card-mock-preview">
                            <div class="pf-card-mock-chips">
                                <span class="is-on"><?php esc_html_e('Print', 'printfriendly'); ?></span>
                                <span><?php esc_html_e('PDF', 'printfriendly'); ?></span>
                                <span><?php esc_html_e('Email', 'printfriendly'); ?></span>
                            </div>
                            <div class="pf-card-mock-rule"></div>
                            <div class="pf-card-mock-title"></div>
                            <div class="pf-card-mock-skel"></div>
                            <div class="pf-card-mock-skel"></div>
                            <div class="pf-card-mock-skel" style="width:76%"></div>
                            <div class="pf-card-mock-skel" style="width:90%"></div>
                        </div>
                    </div>
                </div>
                <p class="pf-card-mock-caption"><?php esc_html_e('What your readers see on Pro. Your address stays in the bar, nothing else on screen.', 'printfriendly'); ?></p>
            </div>
        </div>
    <?php } else { ?>
        <div class="pf-card-status">
            <span class="pf-card-pill pf-card-pill-<?php echo esc_attr($pf_pill[0]); ?>"><?php echo esc_html($pf_pill[1]); ?></span>
            <span class="pf-card-context">
                <?php
                switch ($pf_state) {
                    case 'free':
                        echo esc_html($pf_live_on);
                        break;
                    case 'expired':
                        if ($card['last_trial_date']) {
                            /* translators: %s: a date */
                            echo esc_html(sprintf(__('Your Pro trial ended on %s. Nothing broke and nothing was charged.', 'printfriendly'), $card['last_trial_date']));
                        } else {
                            esc_html_e('Your Pro trial has ended. Nothing broke and nothing was charged.', 'printfriendly');
                        }
                        break;
                    case 'expired_eligible':
                        if ($card['last_trial_date']) {
                            /* translators: %s: a date */
                            echo esc_html(sprintf(__('Your last trial ended on %s. Nothing was ever charged.', 'printfriendly'), $card['last_trial_date']));
                        } else {
                            esc_html_e('Your last trial has ended. Nothing was ever charged.', 'printfriendly');
                        }
                        break;
                    default:
                        echo esc_html($pf_connected_as);
                }
                ?>
            </span>
        </div>

        <?php if ($pf_state === 'free' || $pf_state === 'free_connected') { ?>
            <?php /* translators: %s: the site's domain name */ ?>
            <h2 class="pf-card-headline"><?php echo esc_html(sprintf(__('Your button is live on %s.', 'printfriendly'), $card['host'])); ?></h2>
            <p class="pf-card-line"><?php echo esc_html($pf_why_pro); ?></p>
            <div class="pf-card-actions">
                <?php if ($card['eligible']) { ?>
                    <a class="pf-card-btn pf-card-btn-yellow" href="<?php echo esc_url($card['start_url']); ?>" data-busy="<?php esc_attr_e('Opening PrintFriendly', 'printfriendly'); ?>"><?php esc_html_e('Remove ads now, free for 30 days', 'printfriendly'); ?></a>
                <?php } else { ?>
                    <a class="pf-card-btn pf-card-btn-yellow" href="<?php echo esc_url($card['buy_url']); ?>" data-busy="<?php esc_attr_e('Opening PrintFriendly', 'printfriendly'); ?>"><?php esc_html_e('Remove ads now', 'printfriendly'); ?></a>
                <?php } ?>
            </div>
            <p class="pf-card-fine">
                <?php
                if ($card['eligible']) {
                    echo esc_html($pf_trial_terms);
                } else {
                    /* translators: 1: price line, 2: the yearly charge, for example "$99" */
                    echo esc_html(sprintf(__('%1$s at %2$s. Cancel anytime.', 'printfriendly'), $card['price_line'], $card['charge_amount']));
                }
                ?>
            </p>

        <?php } elseif ($pf_state === 'trial') { ?>
            <h2 class="pf-card-headline">
                <?php
                if ($card['days_left'] === null) {
                    esc_html_e('Ads are off. Your Pro trial is running.', 'printfriendly');
                } elseif ($card['days_left'] === 0) {
                    esc_html_e('Ads are off. Your trial ends today.', 'printfriendly');
                } else {
                    /* translators: %d: number of days left in the trial */
                    echo esc_html(sprintf(_n('Ads are off. %d day left in your trial.', 'Ads are off. %d days left in your trial.', $card['days_left'], 'printfriendly'), $card['days_left']));
                }
                ?>
            </h2>
            <p class="pf-card-line">
                <?php esc_html_e('Readers now finish on your own pages, ad-free.', 'printfriendly'); ?>
                <?php if ($card['dev_domain'] !== '') { ?>
                    <?php /* translators: %s: a development domain name */ ?>
                    <?php echo esc_html(sprintf(__('Development domain %s is included.', 'printfriendly'), $card['dev_domain'])); ?>
                <?php } ?>
            </p>
            <div class="pf-card-actions">
                <a class="pf-card-btn pf-card-btn-secondary" href="<?php echo esc_url($card['account_url']); ?>" target="_blank" rel="noopener"><?php esc_html_e('View account', 'printfriendly'); ?></a>
            </div>
            <p class="pf-card-fine"><?php esc_html_e('Your button settings stay right here in the plugin.', 'printfriendly'); ?></p>

        <?php } elseif ($pf_state === 'expired') { ?>
            <h2 class="pf-card-headline"><?php esc_html_e('The ads are back on your button.', 'printfriendly'); ?></h2>
            <p class="pf-card-line"><?php esc_html_e('Readers finish on printfriendly.com again. Pro puts the preview back on your own pages, ad-free, and picks up right where you left off.', 'printfriendly'); ?></p>
            <div class="pf-card-actions">
                <a class="pf-card-btn pf-card-btn-ghost" href="<?php echo esc_url($card['stay_free_url']); ?>"><?php esc_html_e('Stay on Free', 'printfriendly'); ?></a>
                <a class="pf-card-btn pf-card-btn-yellow" href="<?php echo esc_url($card['buy_url']); ?>" data-busy="<?php esc_attr_e('Opening PrintFriendly', 'printfriendly'); ?>"><?php esc_html_e('Remove ads now', 'printfriendly'); ?></a>
            </div>
            <?php /* translators: 1: price line, 2: the yearly charge, for example "$99" */ ?>
            <p class="pf-card-fine"><?php echo esc_html(sprintf(__('%1$s at %2$s. Cancel anytime. Your card was never charged during the trial.', 'printfriendly'), $card['price_line'], $card['charge_amount'])); ?></p>

        <?php } elseif ($pf_state === 'expired_eligible') { ?>
            <h2 class="pf-card-headline"><?php esc_html_e('Try Pro again, free for 30 days.', 'printfriendly'); ?></h2>
            <p class="pf-card-line"><?php esc_html_e('You are eligible for another trial. Pro puts the preview back on your own pages, ad-free, and remembers every setting from last time.', 'printfriendly'); ?></p>
            <div class="pf-card-actions">
                <a class="pf-card-btn pf-card-btn-ghost" href="<?php echo esc_url($card['stay_free_url']); ?>"><?php esc_html_e('Stay on Free', 'printfriendly'); ?></a>
                <a class="pf-card-btn pf-card-btn-yellow" href="<?php echo esc_url($card['start_url']); ?>" data-busy="<?php esc_attr_e('Opening PrintFriendly', 'printfriendly'); ?>"><?php esc_html_e('Try Pro again, free', 'printfriendly'); ?></a>
            </div>
            <?php /* translators: 1: price line, 2: the yearly charge, for example "$99" */ ?>
            <p class="pf-card-fine"><?php echo esc_html(sprintf(__('Card required, nothing charged today. Then %1$s at %2$s. Cancel anytime.', 'printfriendly'), $card['price_line'], $card['charge_amount'])); ?></p>

        <?php } elseif ($pf_state === 'pro') { ?>
            <?php /* translators: %s: the site's domain name */ ?>
            <h2 class="pf-card-headline"><?php echo esc_html(sprintf(__('Pro is active on %s.', 'printfriendly'), $card['host'])); ?></h2>
            <p class="pf-card-line">
                <?php esc_html_e('Readers finish on your own pages, ad-free.', 'printfriendly'); ?>
                <?php if ($card['dev_domain'] !== '') { ?>
                    <?php /* translators: %s: a development domain name */ ?>
                    <?php echo esc_html(sprintf(__('Development domain %s is included.', 'printfriendly'), $card['dev_domain'])); ?>
                <?php } ?>
            </p>
            <div class="pf-card-actions">
                <a class="pf-card-btn pf-card-btn-secondary" href="<?php echo esc_url($card['account_url']); ?>" target="_blank" rel="noopener"><?php esc_html_e('View account', 'printfriendly'); ?></a>
            </div>
            <p class="pf-card-fine"><?php esc_html_e('Your button settings stay right here in the plugin.', 'printfriendly'); ?></p>

        <?php } elseif ($pf_state === 'past_due') { ?>
            <h2 class="pf-card-headline"><?php esc_html_e('We could not charge your card.', 'printfriendly'); ?></h2>
            <p class="pf-card-line">
                <strong>
                    <?php
                    if ($card['grace_date']) {
                        /* translators: %s: a date */
                        echo esc_html(sprintf(__('Pro stays on until %s.', 'printfriendly'), $card['grace_date']));
                    } else {
                        esc_html_e('Pro stays on for now.', 'printfriendly');
                    }
                    ?>
                </strong>
                <?php esc_html_e('Update your card by then to keep it. If we still cannot charge it, your button drops back to Free and the ads come back. Nothing else happens.', 'printfriendly'); ?>
            </p>
            <div class="pf-card-actions">
                <a class="pf-card-btn pf-card-btn-ghost" href="<?php echo esc_url($card['support_url']); ?>"><?php esc_html_e('Contact support', 'printfriendly'); ?></a>
                <a class="pf-card-btn pf-card-btn-yellow" href="<?php echo esc_url($card['update_card_url']); ?>" target="_blank" rel="noopener"><?php esc_html_e('Update card', 'printfriendly'); ?></a>
            </div>
            <p class="pf-card-fine">
                <?php
                if ($card['failed_date']) {
                    /* translators: 1: a date, 2: price line, for example "$8.25 a month, billed yearly" */
                    echo esc_html(sprintf(__('We tried %1$s and will retry twice more. We never charge more than %2$s.', 'printfriendly'), $card['failed_date'], $card['price_line']));
                } else {
                    /* translators: %s: price line, for example "$8.25 a month, billed yearly" */
                    echo esc_html(sprintf(__('We will retry twice more. We never charge more than %s.', 'printfriendly'), $card['price_line']));
                }
                ?>
            </p>

        <?php } elseif ($pf_state === 'cancelled') { ?>
            <h2 class="pf-card-headline">
                <?php
                if ($card['ends_date']) {
                    /* translators: %s: a date */
                    echo esc_html(sprintf(__('Pro ends on %s.', 'printfriendly'), $card['ends_date']));
                } else {
                    esc_html_e('Pro ends at the end of this billing period.', 'printfriendly');
                }
                ?>
            </h2>
            <p class="pf-card-line">
                <?php
                if ($card['ends_date']) {
                    /* translators: %s: a date */
                    echo esc_html(sprintf(__('You cancelled, so nothing else is charged. On %s your button drops back to Free: it keeps working, the ads come back, and readers finish on printfriendly.com again.', 'printfriendly'), $card['ends_date']));
                } else {
                    esc_html_e('You cancelled, so nothing else is charged. After that your button drops back to Free: it keeps working, the ads come back, and readers finish on printfriendly.com again.', 'printfriendly');
                }
                ?>
            </p>
            <div class="pf-card-actions">
                <a class="pf-card-btn pf-card-btn-ghost" href="<?php echo esc_url($card['account_url']); ?>" target="_blank" rel="noopener"><?php esc_html_e('View account', 'printfriendly'); ?></a>
                <a class="pf-card-btn pf-card-btn-yellow" href="<?php echo esc_url($card['resume_url']); ?>" target="_blank" rel="noopener"><?php esc_html_e('Keep Pro instead', 'printfriendly'); ?></a>
            </div>
            <p class="pf-card-fine"><?php esc_html_e('Changed your mind later? Pro picks up right where you left off.', 'printfriendly'); ?></p>
        <?php } ?>
    <?php } ?>

    <div class="pf-card-rule"></div>
    <div class="pf-card-footer">
        <span>
            <?php esc_html_e('PrintFriendly Pro is GDPR compliant.', 'printfriendly'); ?>
            <a href="<?php echo esc_url($card['privacy_url']); ?>" target="_blank" rel="noopener"><?php esc_html_e('Privacy policy', 'printfriendly'); ?></a>
        </span>
        <?php if (in_array($pf_state, array('offer', 'free'), true)) { ?>
            <span>
                <?php esc_html_e('Questions?', 'printfriendly'); ?>
                <a href="<?php echo esc_url($card['support_url']); ?>"><?php echo esc_html(PrintFriendly_Pro_Card::SUPPORT_EMAIL); ?></a>
            </span>
        <?php } else { ?>
            <span>
                <a href="<?php echo esc_url($card['refresh_url']); ?>"><?php esc_html_e('Refresh status', 'printfriendly'); ?></a>
            </span>
        <?php } ?>
    </div>
</div>
