<?php
if (! defined('ABSPATH')) {
    exit;
}
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This template is included from PrintFriendly_WordPress::config_page(); its top-level variables are function-scoped, not global.
$pf_urlInfo = wp_parse_url(get_option('siteurl'));
$domain = ( isset($pf_urlInfo['host']) ? $pf_urlInfo['host'] : '' );

// don't throw a PHP notice if license_date is not defined
$pf_license_date = $this->getVal('license_date', '');

if (! empty($pf_license_date)) {
    $pf_license_date = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), (int) ($pf_license_date / 1000 + get_option('gmt_offset') * HOUR_IN_SECONDS));
}

?>
                    <div class="pf-bu-block pf-bu-card">
                        <div class="pf-bu-card-content">

          <div id="pf-pro" class="pf-notice pf-pro">
            <h3 style="margin: 1rem 0 0 0">Pro - <span id="pf-pro-status-header"></span></h3>
            <p class="pf-alert-success">PrintFriendly is <strong>GDPR Compliant</strong> <a href="https://www.printfriendly.com/privacy" target="_blank"><?php esc_html_e('Learn more', 'printfriendly'); ?></a>.

            </p>
            <div class="pf-col-1 ">
                <?php
                if (! ( empty($this->getVal('pro_email')) && in_array($this->getVal('license_status'), array( 'pro' ), true) )) {
                    $pf_email = $this->getVal('pro_email');
                    if (empty($pf_email)) {
                        $pf_email = get_option('admin_email');
                    }
                    ?>
              <label class="pf-no-margin">
                <strong><?php esc_html_e('Email', 'printfriendly'); ?></strong><span class="description">(<?php esc_html_e('To send account details', 'printfriendly'); ?>)</span><br>
                <input id="pf-pro-email" type="email" class="regular-text" maxlength="80" name="<?php echo esc_attr($this->option_name); ?>[pro_email]" value="<?php echo esc_attr($pf_email); ?>" placeholder="hello@my-website.com" />
                <br>
                <span id="pf-pro-email-error" class="pf-error"><?php esc_html_e('Email is invalid', 'printfriendly'); ?></span>
              </label>
                <?php } ?>

              <label>
                <strong><?php esc_html_e('Website Domain', 'printfriendly'); ?></strong><br/>
                <input id="pf-pro-domain" type="text" class="regular-text" readonly name="<?php echo esc_attr($this->option_name); ?>[pro_domain]" value="<?php echo esc_attr($domain); ?>" />
                <br />
                <span id="pf-pro-domain-error" class="pf-error"><?php esc_html_e('Domain is invalid', 'printfriendly'); ?></span>
              </label>

              <p id="pf-pro-activate" class="pf-hidden">
                <button id="pf-pro-activate-btn" type="button" class="button-primary"><?php esc_html_e('Activate', 'printfriendly'); ?></button>
                <span class="pf-btn-message"><?php esc_html_e('Free 30 days trial, no credit card required.', 'printfriendly'); ?></span>
              </p>

              <p id="pf-pro-buy" class="pf-hidden">
                <a id="pf-pro-buy-btn" class="button-primary"><?php esc_html_e('Buy Now', 'printfriendly'); ?></a>
                <span id="pf-pro-status-message" class="pf-btn-message"></span>
              </p>

              <p id="pf-pro-details" class="pf-hidden">
                <span class="pf-btn-message"><?php esc_html_e('Last Checked', 'printfriendly'); ?>: <?php echo esc_html($pf_license_date); ?></span>
                <input type="hidden" name="<?php echo esc_attr($this->option_name); ?>[license_status]" id="license_status">
                <input type="hidden" name="<?php echo esc_attr($this->option_name); ?>[license_date]" id="license_date">
              </p>

              <p id="pf-pro-loading" class="pf-hidden">
                <b class="pf-text-success"><span id="pf-pro-loading-message"></span><?php esc_html_e('Please wait', 'printfriendly'); ?>...</b>
              </p>

              <p id="pf-pro-communication-error" class="pf-hidden">
                <b class="pf-text-error"><span id="pf-pro-communication-error-message"></span></b>
              </p>
            </div>

            <div class="pf-col-2 pf-pro-features">
              <strong><?php esc_html_e('Pro Features', 'printfriendly'); ?></strong>
              <ol>
                <li><?php esc_html_e('Faster, better experience for end-user', 'printfriendly'); ?></li>
                <li><?php esc_html_e('Cache-free, so any updates are instantly included', 'printfriendly'); ?></li>
                <li><?php esc_html_e('Ad-Free for companies and organizations', 'printfriendly'); ?></li>
                <li><?php esc_html_e('Works on all sites (Password protected, Angular/React/Ember)', 'printfriendly'); ?></li>
                <li><?php esc_html_e('Email support', 'printfriendly'); ?></li>
              </ol>
              <p><?php esc_html_e('Have a development/staging domain?', 'printfriendly'); ?> <a href="https://support.printfriendly.com/pro/staging-development-domain/" target="_blank"><?php esc_html_e('Add Free Development Domain', 'printfriendly'); ?></a>.</p>
            </div>
            <br />
          </div>

        </div>
    </div>
