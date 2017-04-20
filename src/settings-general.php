<?php

if (!defined('SUCURISCAN_INIT') || SUCURISCAN_INIT !== true) {
    if (!headers_sent()) {
        /* Report invalid access if possible. */
        header('HTTP/1.1 403 Forbidden');
    }
    exit(1);
}

/**
 * Renders a page with information about the reset options feature.
 *
 * @param bool $nonce True if the CSRF protection worked.
 * @return string Page with information about the reset options.
 */
function sucuriscan_settings_general_resetoptions($nonce)
{
    // Reset all the plugin's options.
    if ($nonce && SucuriScanRequest::post(':reset_options') !== false) {
        $process = SucuriScanRequest::post(':process_form');

        if (intval($process) === 1) {
            $message = 'Local security logs, hardening and settings were deleted';

            sucuriscan_deactivate(); /* simulate plugin deactivation */

            SucuriScanEvent::reportCriticalEvent($message);
            SucuriScanEvent::notifyEvent('plugin_change', $message);
            SucuriScanInterface::info($message);
        } else {
            SucuriScanInterface::error('You need to confirm that you understand the risk of this operation.');
        }
    }

    return SucuriScanTemplate::getSection('settings-general-resetoptions');
}

/**
 * Renders a page with information about the API key feature.
 *
 * @param bool $nonce True if the CSRF protection worked.
 * @return string Page with information about the API key.
 */
function sucuriscan_settings_general_apikey($nonce)
{
    $params = array();
    $invalid_domain = false;
    $api_recovery_modal = '';
    $api_registered_modal = '';

    // Whether the form to manually add the API key should be shown or not.
    $display_manual_key_form = (bool) (SucuriScanRequest::post(':recover_key') !== false);

    if ($nonce) {
        if (!empty($_POST)) {
            $fpath = SucuriScanOption::optionsFilePath();

            if (!is_writable($fpath)) {
                SucuriScanInterface::error('Storage is not writable: <code>' . $fpath . '</code>');
            }
        }

        // Remove API key from the local storage.
        if (SucuriScanRequest::post(':remove_api_key') !== false) {
            SucuriScanAPI::setPluginKey('');
            wp_clear_scheduled_hook('sucuriscan_scheduled_scan');
            SucuriScanEvent::reportCriticalEvent('Sucuri API key was deleted.');
            SucuriScanEvent::notifyEvent('plugin_change', 'Sucuri API key removed');
        }

        // Save API key after it was recovered by the administrator.
        if ($api_key = SucuriScanRequest::post(':manual_api_key')) {
            SucuriScanAPI::setPluginKey($api_key, true);
            SucuriScanEvent::scheduleTask();
            SucuriScanEvent::reportInfoEvent('Sucuri API key was added manually.');
        }

        // Generate new API key from the API service.
        if (SucuriScanRequest::post(':plugin_api_key') !== false) {
            $user_id = (int) SucuriScanRequest::post(':setup_user');
            $user_obj = SucuriScan::getUserByID($user_id);

            if ($user_obj && user_can($user_obj, 'administrator')) {
                // Send request to generate new API key or display form to set manually.
                if (SucuriScanAPI::registerSite($user_obj->user_email)) {
                    $api_registered_modal = SucuriScanTemplate::getModal('settings-apiregistered', array(
                        'Title' => 'Site registered successfully',
                    ));
                } else {
                    $display_manual_key_form = true;
                }
            }
        }

        // Recover API key through the email registered previously.
        if (SucuriScanRequest::post(':recover_key') !== false) {
            if (SucuriScanAPI::recoverKey()) {
                $_GET['recover'] = 'true'; /* display modal window */
                SucuriScanEvent::reportInfoEvent('API key recovery (email sent)');
            } else {
                SucuriScanEvent::reportInfoEvent('API key recovery (failure)');
            }
        }
    }

    $api_key = SucuriScanAPI::getPluginKey();

    if (SucuriScanRequest::get('recover') !== false) {
        $api_recovery_modal = SucuriScanTemplate::getModal('settings-apirecovery', array(
            'Title' => 'Plugin API Key Recovery',
        ));
    }

    // Check whether the domain name is valid or not.
    if (!$api_key) {
        $clean_domain = SucuriScan::getTopLevelDomain();
        $domain_address = @gethostbyname($clean_domain);
        $invalid_domain = (bool) ($domain_address === $clean_domain);
    }

    $params['APIKey'] = (!$api_key ? '(not set)' : $api_key);
    $params['APIKey.RecoverVisibility'] = SucuriScanTemplate::visibility(!$api_key);
    $params['APIKey.ManualKeyFormVisibility'] = SucuriScanTemplate::visibility($display_manual_key_form);
    $params['APIKey.RemoveVisibility'] = SucuriScanTemplate::visibility((bool) $api_key);
    $params['InvalidDomainVisibility'] = SucuriScanTemplate::visibility($invalid_domain);
    $params['ModalWhenAPIRegistered'] = $api_registered_modal;
    $params['ModalForApiKeyRecovery'] = $api_recovery_modal;

    return SucuriScanTemplate::getSection('settings-general-apikey', $params);
}

/**
 * Renders a page with information about the data storage feature.
 *
 * @param bool $nonce True if the CSRF protection worked.
 * @return string Page with information about the data storage.
 */
function sucuriscan_settings_general_datastorage()
{
    $params = array();
    $files = array(
        '', /* <root> */
        'auditqueue',
        'blockedusers',
        'failedlogins',
        'ignorescanning',
        'integrity',
        'lastlogins',
        'oldfailedlogins',
        'plugindata',
        'settings',
        'sitecheck',
        'trustip',
    );

    $params['Storage.Files'] = '';
    $params['Storage.Path'] = SucuriScan::dataStorePath();

    if (SucuriScanInterface::checkNonce()) {
        if ($filenames = SucuriScanRequest::post(':filename', '_array')) {
            $deleted = 0;

            foreach ($filenames as $filename) {
                $short = substr($filename, 7); /* drop directroy path */
                $short = substr($short, 0, -4); /* drop file extension */

                if (!$short || empty($short) || !in_array($short, $files)) {
                    continue; /* prevent path traversal */
                }

                $filepath = SucuriScan::dataStorePath($filename);

                if (!file_exists($filepath) || is_dir($filepath)) {
                    continue; /* there is nothing to reset */
                }

                /* ignore write permissions */
                if (@unlink($filepath)) {
                    $deleted++;
                }
            }

            SucuriScanInterface::info(sprintf('%d out of %d files were deleted', $deleted, count($filenames)));
        }
    }

    foreach ($files as $name) {
        $fsize = 0;
        $fname = ($name ? sprintf('sucuri-%s.php', $name) : '');
        $fpath = SucuriScan::dataStorePath($fname);
        $disabled = 'disabled="disabled"';
        $iswritable = 'No Writable';
        $exists = 'Does Not Exists';
        $labelExistence = 'danger';
        $labelWritability = 'default';

        if (file_exists($fpath)) {
            $fsize = @filesize($fpath);
            $exists = 'Exists';
            $labelExistence = 'success';
            $labelWritability = 'danger';

            if (is_writable($fpath)) {
                $disabled = ''; /* Allow file deletion */
                $iswritable = 'Writable';
                $labelWritability = 'success';
            }
        }

        $params['Storage.Filename'] = $fname;
        $params['Storage.Filepath'] = str_replace(ABSPATH, '', $fpath);
        $params['Storage.Filesize'] = SucuriScan::humanFileSize($fsize);
        $params['Storage.Exists'] = $exists;
        $params['Storage.IsWritable'] = $iswritable;
        $params['Storage.DisabledInput'] = $disabled;
        $params['Storage.Existence'] = $labelExistence;
        $params['Storage.Writability'] = $labelWritability;

        if (is_dir($fpath)) {
            $params['Storage.DisabledInput'] = 'disabled="disabled"';
            $params['Storage.Filesize'] = '' /* empty */;
        }

        $params['Storage.Files'] .= SucuriScanTemplate::getSnippet('settings-general-datastorage', $params);
    }

    return SucuriScanTemplate::getSection('settings-general-datastorage', $params);
}

function sucuriscan_selfhosting_fpath()
{
    $monitor = SucuriScanOption::getOption(':selfhosting_monitor');
    $monitor_fpath = SucuriScanOption::getOption(':selfhosting_fpath');
    $folder = dirname($monitor_fpath);

    if ($monitor === 'enabled'
        && !empty($monitor_fpath)
        && is_writable($folder)
    ) {
        return $monitor_fpath;
    }

    return false;
}

/**
 * Renders a page with information about the self-hosting feature.
 *
 * @param bool $nonce True if the CSRF protection worked.
 * @return string Page with information about the self-hosting.
 */
function sucuriscan_settings_general_selfhosting($nonce)
{
    $params = array();

    $params['SelfHosting.DisabledVisibility'] = 'visible';
    $params['SelfHosting.Status'] = 'Enabled';
    $params['SelfHosting.SwitchText'] = 'Disable';
    $params['SelfHosting.SwitchValue'] = 'disable';
    $params['SelfHosting.FpathVisibility'] = 'hidden';
    $params['SelfHosting.Fpath'] = '';

    if ($nonce) {
        // Set a file path for the self-hosted event monitor.
        $monitor_fpath = SucuriScanRequest::post(':selfhosting_fpath');

        if ($monitor_fpath !== false) {
            if (empty($monitor_fpath)) {
                $message = 'Log exporter was disabled.';

                SucuriScanEvent::reportInfoEvent($message);
                SucuriScanOption::deleteOption(':selfhosting_fpath');
                SucuriScanOption::updateOption(':selfhosting_monitor', 'disabled');
                SucuriScanEvent::notifyEvent('plugin_change', $message);
                SucuriScanInterface::info($message);
            } elseif (strpos($monitor_fpath, $_SERVER['DOCUMENT_ROOT']) !== false) {
                SucuriScanInterface::error('File should not be publicly accessible.');
            } elseif (file_exists($monitor_fpath)) {
                SucuriScanInterface::error('File already exists and will not be overwritten.');
            } elseif (!is_writable(dirname($monitor_fpath))) {
                SucuriScanInterface::error('File parent directory is not writable.');
            } else {
                @file_put_contents($monitor_fpath, '', LOCK_EX);

                $message = 'Log exporter file path was set correctly.';

                SucuriScanEvent::reportInfoEvent($message);
                SucuriScanOption::updateOption(':selfhosting_monitor', 'enabled');
                SucuriScanOption::updateOption(':selfhosting_fpath', $monitor_fpath);
                SucuriScanEvent::notifyEvent('plugin_change', $message);
                SucuriScanInterface::info($message);
            }
        }
    }

    $monitor = SucuriScanOption::getOption(':selfhosting_monitor');
    $monitor_fpath = SucuriScanOption::getOption(':selfhosting_fpath');

    if ($monitor === 'disabled') {
        $params['SelfHosting.Status'] = 'Disabled';
        $params['SelfHosting.SwitchText'] = 'Enable';
        $params['SelfHosting.SwitchValue'] = 'enable';
    }

    if ($monitor === 'enabled' && $monitor_fpath) {
        $params['SelfHosting.DisabledVisibility'] = 'hidden';
        $params['SelfHosting.FpathVisibility'] = 'visible';
        $params['SelfHosting.Fpath'] = SucuriScan::escape($monitor_fpath);
    }

    return SucuriScanTemplate::getSection('settings-general-selfhosting', $params);
}

/**
 * Renders a page with information about the cronjobs feature.
 *
 * @param bool $nonce True if the CSRF protection worked.
 * @return string Page with information about the cronjobs.
 */
function sucuriscan_settings_general_cronjobs()
{
    global $sucuriscan_schedule_allowed;

    $params = array(
        'Cronjobs.List' => '',
        'Cronjobs.Total' => 0,
        'Cronjob.Schedules' => '',
    );

    if (SucuriScanInterface::checkNonce()) {
        // Modify the scheduled tasks (run now, remove, re-schedule).
        $available = ($sucuriscan_schedule_allowed === null)
            ? SucuriScanEvent::availableSchedules()
            : $sucuriscan_schedule_allowed;
        $allowed_actions = array_keys($available);
        $allowed_actions[] = 'runnow';
        $allowed_actions[] = 'remove';
        $allowed_actions = sprintf('(%s)', implode('|', $allowed_actions));

        if ($cronjob_action = SucuriScanRequest::post(':cronjob_action', $allowed_actions)) {
            $cronjobs = SucuriScanRequest::post(':cronjobs', '_array');

            if (!empty($cronjobs)) {
                $total_tasks = count($cronjobs);

                if ($cronjob_action == 'runnow') {
                    /* Force execution of the selected scheduled tasks. */
                    SucuriScanInterface::info($total_tasks . ' tasks were scheduled to run in the next ten seconds.');
                    SucuriScanEvent::reportNoticeEvent(sprintf(
                        'Force execution of scheduled tasks: (multiple entries): %s',
                        @implode(',', $cronjobs)
                    ));

                    foreach ($cronjobs as $task_name) {
                        wp_schedule_single_event(time() + 10, $task_name);
                    }
                } elseif ($cronjob_action == 'remove' || $cronjob_action == '_oneoff') {
                    /* Force deletion of the selected scheduled tasks. */
                    SucuriScanInterface::info($total_tasks . ' scheduled tasks were removed.');
                    SucuriScanEvent::reportNoticeEvent(sprintf(
                        'Delete scheduled tasks: (multiple entries): %s',
                        @implode(',', $cronjobs)
                    ));

                    foreach ($cronjobs as $task_name) {
                        wp_clear_scheduled_hook($task_name);
                    }
                } else {
                    SucuriScanInterface::info($total_tasks . ' tasks were re-scheduled to run <code>' . $cronjob_action . '</code>.');
                    SucuriScanEvent::reportNoticeEvent(sprintf(
                        'Re-configure scheduled tasks %s: (multiple entries): %s',
                        $cronjob_action,
                        @implode(',', $cronjobs)
                    ));

                    foreach ($cronjobs as $task_name) {
                        $next_due = wp_next_scheduled($task_name);
                        wp_schedule_event($next_due, $cronjob_action, $task_name);
                    }
                }
            } else {
                SucuriScanInterface::error('No scheduled tasks were selected from the list.');
            }
        }
    }

    $cronjobs = _get_cron_array();
    $available = ($sucuriscan_schedule_allowed === null)
        ? SucuriScanEvent::availableSchedules()
        : $sucuriscan_schedule_allowed;

    /* Hardcode the first one to allow the immediate execution of the cronjob(s) */
    $params['Cronjob.Schedules'] .= '<option value="runnow">Execute Now (in +10 seconds)</option>';

    foreach ($available as $freq => $name) {
        $params['Cronjob.Schedules'] .= sprintf('<option value="%s">%s</option>', $freq, $name);
    }

    foreach ($cronjobs as $timestamp => $cronhooks) {
        foreach ((array) $cronhooks as $hook => $events) {
            foreach ((array) $events as $key => $event) {
                if (empty($event['args'])) {
                    $event['args'] = array('[]');
                }

                $params['Cronjobs.Total'] += 1;
                $params['Cronjobs.List'] .=
                SucuriScanTemplate::getSnippet('settings-general-cronjobs', array(
                    'Cronjob.Hook' => $hook,
                    'Cronjob.Schedule' => $event['schedule'],
                    'Cronjob.NextTime' => SucuriScan::datetime($timestamp),
                    'Cronjob.Arguments' => SucuriScan::implode(', ', $event['args']),
                ));
            }
        }
    }

    return SucuriScanTemplate::getSection('settings-general-cronjobs', $params);
}

/**
 * Renders a page with information about the reverse proxy feature.
 *
 * @param bool $nonce True if the CSRF protection worked.
 * @return string Page with information about the reverse proxy.
 */
function sucuriscan_settings_general_reverseproxy($nonce)
{
    $params = array(
        'ReverseProxyStatus' => 'Enabled',
        'ReverseProxySwitchText' => 'Disable',
        'ReverseProxySwitchValue' => 'disable',
    );

    // Enable or disable the reverse proxy support.
    if ($nonce) {
        $revproxy = SucuriScanRequest::post(':revproxy', '(en|dis)able');

        if ($revproxy) {
            if ($revproxy === 'enable') {
                SucuriScanOption::setRevProxy('enable');
                SucuriScanOption::setAddrHeader('HTTP_X_SUCURI_CLIENTIP');
            } else {
                SucuriScanOption::setRevProxy('disable');
                SucuriScanOption::setAddrHeader('REMOTE_ADDR');
            }
        }
    }

    if (SucuriScanOption::isDisabled(':revproxy')) {
        $params['ReverseProxyStatus'] = 'Disabled';
        $params['ReverseProxySwitchText'] = 'Enable';
        $params['ReverseProxySwitchValue'] = 'enable';
    }

    return SucuriScanTemplate::getSection('settings-general-reverseproxy', $params);
}

/**
 * Renders a page with information about the IP discoverer feature.
 *
 * @param bool $nonce True if the CSRF protection worked.
 * @return string Page with information about the IP discoverer.
 */
function sucuriscan_settings_general_ipdiscoverer($nonce)
{
    $params = array(
        'TopLevelDomain' => 'Unknown',
        'WebsiteHostName' => 'Unknown',
        'WebsiteHostAddress' => 'Unknown',
        'IsUsingFirewall' => 'Unknown',
        'WebsiteURL' => 'Unknown',
        'RemoteAddress' => '127.0.0.1',
        'RemoteAddressHeader' => 'INVALID',
        'AddrHeaderOptions' => '',
        /* Switch form information. */
        'DnsLookupsStatus' => 'Enabled',
        'DnsLookupsSwitchText' => 'Disable',
        'DnsLookupsSwitchValue' => 'disable',
    );

    // Get main HTTP header for IP retrieval.
    $allowed_headers = SucuriScan::allowedHttpHeaders(true);

    // Configure the DNS lookups option for reverse proxy detection.
    if ($nonce) {
        $dns_lookups = SucuriScanRequest::post(':dns_lookups', '(en|dis)able');
        $addr_header = SucuriScanRequest::post(':addr_header');

        if ($dns_lookups) {
            $action_d = $dns_lookups . 'd';
            $message = 'DNS lookups for reverse proxy detection <code>' . $action_d . '</code>';

            SucuriScanOption::updateOption(':dns_lookups', $action_d);
            SucuriScanEvent::reportInfoEvent($message);
            SucuriScanEvent::notifyEvent('plugin_change', $message);
            SucuriScanInterface::info($message);
        }

        if ($addr_header) {
            if ($addr_header === 'REMOTE_ADDR') {
                SucuriScanOption::setAddrHeader('REMOTE_ADDR');
                SucuriScanOption::setRevProxy('disable');
            } else {
                SucuriScanOption::setAddrHeader($addr_header);
                SucuriScanOption::setRevProxy('enable');
            }
        }
    }

    if (SucuriScanOption::isDisabled(':dns_lookups')) {
        $params['DnsLookupsStatus'] = 'Disabled';
        $params['DnsLookupsSwitchText'] = 'Enable';
        $params['DnsLookupsSwitchValue'] = 'enable';
    }

    $proxy_info = SucuriScan::isBehindFirewall(true);
    $base_domain = SucuriScan::getDomain(true);

    $params['TopLevelDomain'] = $proxy_info['http_host'];
    $params['WebsiteHostName'] = $proxy_info['host_name'];
    $params['WebsiteHostAddress'] = $proxy_info['host_addr'];
    $params['IsUsingFirewall'] = ($proxy_info['status'] ? 'Active' : 'Not Active');
    $params['RemoteAddressHeader'] = SucuriScan::getRemoteAddrHeader();
    $params['RemoteAddress'] = SucuriScan::getRemoteAddr();
    $params['WebsiteURL'] = SucuriScan::getDomain();
    $params['AddrHeaderOptions'] = SucuriScanTemplate::selectOptions(
        $allowed_headers,
        SucuriScanOption::getOption(':addr_header')
    );

    if ($base_domain !== $proxy_info['http_host']) {
        $params['TopLevelDomain'] = sprintf('%s (%s)', $params['TopLevelDomain'], $base_domain);
    }

    return SucuriScanTemplate::getSection('settings-general-ipdiscoverer', $params);
}

/**
 * Renders a page with information about the comment monitor feature.
 *
 * @param bool $nonce True if the CSRF protection worked.
 * @return string Page with information about the comment monitor.
 */
function sucuriscan_settings_general_commentmonitor($nonce)
{
    $params = array(
        'CommentMonitorStatus' => 'Enabled',
        'CommentMonitorSwitchText' => 'Disable',
        'CommentMonitorSwitchValue' => 'disable',
    );

    // Configure the comment monitor option.
    if ($nonce) {
        $monitor = SucuriScanRequest::post(':comment_monitor', '(en|dis)able');

        if ($monitor) {
            $action_d = $monitor . 'd';
            $message = 'Comment monitor was <code>' . $action_d . '</code>';

            SucuriScanOption::updateOption(':comment_monitor', $action_d);
            SucuriScanEvent::reportInfoEvent($message);
            SucuriScanEvent::notifyEvent('plugin_change', $message);
            SucuriScanInterface::info($message);
        }
    }

    if (SucuriScanOption::isDisabled(':comment_monitor')) {
        $params['CommentMonitorStatus'] = 'Disabled';
        $params['CommentMonitorSwitchText'] = 'Enable';
        $params['CommentMonitorSwitchValue'] = 'enable';
    }

    return SucuriScanTemplate::getSection('settings-general-commentmonitor', $params);
}

/**
 * Renders a page with information about the auditlog stats feature.
 *
 * @param bool $nonce True if the CSRF protection worked.
 * @return string Page with information about the auditlog stats.
 */
function sucuriscan_settings_general_auditlogstats($nonce)
{
    $params = array();

    if ($nonce) {
        // Update the limit for audit logs report.
        if ($logs4report = SucuriScanRequest::post(':logs4report', '[0-9]{1,4}')) {
            $message = 'Audit log statistics limit set to <code>' . $logs4report . '</code>';

            SucuriScanOption::updateOption(':logs4report', $logs4report);
            SucuriScanEvent::reportInfoEvent($message);
            SucuriScanEvent::notifyEvent('plugin_change', $message);
            SucuriScanInterface::info($message);
        }
    }

    $logs4report = SucuriScanOption::getOption(':logs4report');
    $params['AuditLogStats.Limit'] = SucuriScan::escape($logs4report);

    return SucuriScanTemplate::getSection('settings-general-auditlogstats', $params);
}

/**
 * Renders a page with information about the import export feature.
 *
 * @param bool $nonce True if the CSRF protection worked.
 * @return string Page with information about the import export.
 */
function sucuriscan_settings_general_importexport($nonce)
{
    $settings = array();
    $params = array();
    $allowed = array(
        ':addr_header',
        ':api_handler',
        ':api_key',
        ':api_protocol',
        ':api_service',
        ':cloudproxy_apikey',
        ':comment_monitor',
        ':diff_utility',
        ':dns_lookups',
        ':email_subject',
        ':emails_per_hour',
        ':ignored_events',
        ':language',
        ':lastlogin_redirection',
        ':logs4report',
        ':maximum_failed_logins',
        ':notify_available_updates',
        ':notify_bruteforce_attack',
        ':notify_failed_login',
        ':notify_plugin_activated',
        ':notify_plugin_change',
        ':notify_plugin_deactivated',
        ':notify_plugin_deleted',
        ':notify_plugin_installed',
        ':notify_plugin_updated',
        ':notify_post_publication',
        ':notify_scan_checksums',
        ':notify_settings_updated',
        ':notify_success_login',
        ':notify_theme_activated',
        ':notify_theme_deleted',
        ':notify_theme_editor',
        ':notify_theme_installed',
        ':notify_theme_updated',
        ':notify_to',
        ':notify_user_registration',
        ':notify_website_updated',
        ':notify_widget_added',
        ':notify_widget_deleted',
        ':prettify_mails',
        ':request_timeout',
        ':revproxy',
        ':scan_frequency',
        ':selfhosting_fpath',
        ':selfhosting_monitor',
        ':use_wpmail',
    );

    if ($nonce && SucuriScanRequest::post(':import') !== false) {
        $process = SucuriScanRequest::post(':process_form');

        if (intval($process) === 1) {
            $json = SucuriScanRequest::post(':settings');
            $json = str_replace('\&quot;', '"', $json);
            $data = @json_decode($json, true);

            if ($data) {
                $count = 0;
                $total = count($data);

                /* minimum length for option name */
                $minLength = strlen(SUCURISCAN . '_');

                foreach ($data as $option => $value) {
                    if (strlen($option) <= $minLength) {
                        continue;
                    }

                    $option_name = ':' . substr($option, $minLength);

                    /* check if the option can be imported */
                    if (!in_array($option_name, $allowed)) {
                        continue;
                    }

                    SucuriScanOption::updateOption($option_name, $value);

                    $count++;
                }

                SucuriScanInterface::info($count . ' out of ' . $total . ' option were imported');
            } else {
                SucuriScanInterface::error('Data is incorrectly encoded');
            }
        } else {
            SucuriScanInterface::error('You need to confirm that you understand the risk of this operation.');
        }
    }

    foreach ($allowed as $option) {
        $option_name = SucuriScan::varPrefix($option);
        $settings[$option_name] = SucuriScanOption::getOption($option);
    }

    $params['Export'] = @json_encode($settings);

    return SucuriScanTemplate::getSection('settings-general-importexport', $params);
}
