jQuery(document).ready(function($) {
    const smtpData = typeof lunexiaSMTPData !== 'undefined' ? lunexiaSMTPData : (typeof liteSMTPData !== 'undefined' ? liteSMTPData : null);

    // Show general settings by default on page load
    $('.lite-smtp-nav-item[data-section="general"]').click();

    // Sidebar navigation
    $('.lite-smtp-nav-item').on('click', function(e) {
        e.preventDefault();
        const section = $(this).data('section');
        
        if (!section) return;
        
        $('.lite-smtp-nav-item').removeClass('active');
        $(this).addClass('active');
        
        $('.lite-smtp-section').removeClass('active').hide();
        $('#' + section).addClass('active').fadeIn();
    });

    // Tab switching for connection settings
    $('.lite-smtp-tab').on('click', function() {
        const tabId = $(this).data('tab');
        
        $('.lite-smtp-tab').removeClass('active');
        $(this).addClass('active');
        
        $('.lite-smtp-tab-content').removeClass('active').hide();
        $('#lite-smtp-' + tabId + '-settings').addClass('active').fadeIn();
    });

    // Authenticate with Google
    $('#authenticate-google-btn').on('click', function() {
        if (!smtpData) return;

        const $btn = $(this);
        const originalText = $btn.text();
        const clientId = $('#gmail_client_id').val();
        const clientSecret = $('#gmail_client_secret').val();

        if (!clientId || !clientSecret) {
            alert('Please fill in Client ID and Client Secret first');
            return;
        }

        $btn.text('Connecting...').prop('disabled', true);

        // First save the settings
        $.post(smtpData.ajaxurl, {
            action: 'lunexia_smtp_save_settings',
            nonce: smtpData.nonce,
            tab: 'gmail',
            client_id: clientId,
            client_secret: clientSecret
        }, function(response) {
            if (response.success) {
                // Now generate auth URL and redirect
                $.post(smtpData.ajaxurl, {
                    action: 'lunexia_smtp_generate_auth_url',
                    nonce: smtpData.nonce
                }, function(authResponse) {
                    if (authResponse.success) {
                        window.location.href = authResponse.data.auth_url;
                    } else {
                        showNotification('Error: ' + (authResponse.data.message || 'Failed to generate auth URL'), 'error');
                        $btn.text(originalText).prop('disabled', false);
                    }
                }).fail(function() {
                    showNotification('Error: Failed to connect to Google', 'error');
                    $btn.text(originalText).prop('disabled', false);
                });
            } else {
                showNotification('Error: ' + (response.data || 'Failed to save settings'), 'error');
                $btn.text(originalText).prop('disabled', false);
            }
        }).fail(function() {
            showNotification('Error: Failed to save settings', 'error');
            $btn.text(originalText).prop('disabled', false);
        });
    });

    // Save SMTP Settings
    $('#save-smtp-btn').on('click', function() {
        if (!smtpData) return;

        const $btn = $(this);
        const originalText = $btn.text();

        $btn.text('Saving...').prop('disabled', true);

        $.post(smtpData.ajaxurl, {
            action: 'lunexia_smtp_save_settings',
            nonce: smtpData.nonce,
            tab: 'smtp',
            smtp_host: $('#smtp_host').val(),
            smtp_port: $('#smtp_port').val(),
            smtp_username: $('#smtp_username').val(),
            smtp_password: $('#smtp_password').val(),
            smtp_encryption: $('#smtp_encryption').val(),
            from_email: $('#from_email').val(),
            from_name: $('#from_name').val()
        }, function(response) {
            if (response.success) {
                showNotification('SMTP settings saved successfully!', 'success');
            } else {
                showNotification('Error: ' + (response.data || 'Failed to save settings'), 'error');
            }
        }).always(function() {
            $btn.text(originalText).prop('disabled', false);
        });
    });

    // Disconnect Google
    $('#disconnect-google-btn').on('click', function() {
        if (!smtpData) return;

        if (!confirm('Are you sure you want to disconnect from Google?')) {
            return;
        }

        const $btn = $(this);
        $btn.text('Disconnecting...').prop('disabled', true);

        $.post(smtpData.ajaxurl, {
            action: 'lunexia_smtp_disconnect',
            nonce: smtpData.nonce
        }, function(response) {
            if (response.success) {
                showNotification('Disconnected from Google', 'success');
                setTimeout(() => location.reload(), 1000);
            } else {
                showNotification('Error: ' + response.data, 'error');
            }
        }).always(function() {
            $btn.prop('disabled', false);
        });
    });

    // Test Email Logic
    $('#send-test-email-btn').on('click', function(e) {
        e.preventDefault();
        if (!smtpData) return;

        const $btn = $(this);
        const originalText = $btn.text();
        
        const email = $('#test-email-address').val();
        const subject = $('#test-email-subject').val() || 'Lunexia SMTP Test Notification';
        const message = $('#test-email-message').val() || 'Confirm that the SMTP configuration is working by receiving this notification.';

        if (!email) {
            alert('Please enter a test email address.');
            return;
        }

        $btn.text('Sending...').prop('disabled', true);

        $.post(smtpData.ajaxurl, {
            action: 'lunexia_smtp_test_email',
            email: email,
            subject: subject,
            message: message,
            nonce: smtpData.security
        }, function(response) {
            const $responsePanel = $('#test-email-response');
            $responsePanel.show();
            
            if (response.success) {
                $responsePanel.css({
                    'background': '#DCFCE7',
                    'color': '#166534',
                    'border': '1px solid #BBF7D0'
                }).text('Success! Test email sent to ' + email);
            } else {
                $responsePanel.css({
                    'background': '#FEE2E2',
                    'color': '#991B1B',
                    'border': '1px solid #FECACA'
                }).text('Error: ' + (response.data ? (response.data.message || response.data) : 'Failed to send email.'));
            }
        }).always(function() {
            $btn.text(originalText).prop('disabled', false);
        });
    });

    // Copy functionality
    $(document).on('click', '.copy-btn', function() {
        const textToCopy = $(this).prev('span').text();
        const $this = $(this);
        const originalText = $this.text();

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(textToCopy).then(() => {
                $this.text('Copied!');
                setTimeout(() => $this.text(originalText), 2000);
            }).catch(() => {
                fallbackCopyTextToClipboard(textToCopy, $this, originalText);
            });
        } else {
            fallbackCopyTextToClipboard(textToCopy, $this, originalText);
        }
    });

    function fallbackCopyTextToClipboard(text, $btn, originalText) {
        const textArea = document.createElement("textarea");
        textArea.value = text;
        textArea.style.position = "fixed";
        textArea.style.left = "-999999px";
        textArea.style.top = "-999999px";
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();

        try {
            const successful = document.execCommand('copy');
            if (successful) {
                $btn.text('Copied!');
                setTimeout(() => $btn.text(originalText), 2000);
            } else {
                alert('Failed to copy text');
            }
        } catch (err) {
            alert('Failed to copy text');
        }

        document.body.removeChild(textArea);
    }

    // Regenerate Keys
    $('#regenerate-keys-btn').on('click', function() {
        if (!smtpData) return;

        if (!confirm('Are you sure you want to regenerate your API keys? Old keys will stop working.')) {
            return;
        }

        const $btn = $(this);
        $btn.text('Regenerating...').prop('disabled', true);

        $.post(smtpData.ajaxurl, {
            action: 'lunexia_smtp_regenerate_keys',
            nonce: smtpData.nonce
        }, function(response) {
            if (response.success) {
                $('#site-key').text(response.data.site_key);
                $('#secret-key').text(response.data.secret_key);
                showNotification('Keys regenerated successfully!', 'success');
            } else {
                showNotification('Error: ' + response.data, 'error');
            }
        }).always(function() {
            $btn.text('Regenerate Keys').prop('disabled', false);
        });
    });

    // Load Email Logs
    if ($('#logs-tbody').length) {
        loadEmailLogs();
    }

    // Helper function to load logs
    function loadEmailLogs() {
        if (!smtpData) return;

        $.post(smtpData.ajaxurl, {
            action: 'lunexia_smtp_get_logs',
            nonce: smtpData.nonce
        }, function(response) {
            const $tbody = $('#logs-tbody');
            $tbody.empty();

            if (response.success && response.data && response.data.length > 0) {
                response.data.forEach(function(log) {
                    let statusColor = '#059669';
                    let statusBg = '#ECFDF5';
                    let statusText = log.status.charAt(0).toUpperCase() + log.status.slice(1);

                    if (log.status === 'failed' || log.status === 'error') {
                        statusColor = '#DC2626';
                        statusBg = '#FEF2F2';
                    } else if (log.status === 'pending') {
                        statusColor = '#F59E0B';
                        statusBg = '#FFFBEB';
                    }

                    const errorInfo = log.error_message ? ` - ${log.error_message}` : '';
                    
                    $tbody.append(`
                        <tr style="border-bottom: 1px solid var(--lite-smtp-border);">
                            <td style="padding: 12px;">${escapeHtml(log.recipient)}</td>
                            <td style="padding: 12px;">${escapeHtml(log.subject)}</td>
                            <td style="padding: 12px;">
                                <span style="background: ${statusBg}; color: ${statusColor}; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 500;">
                                    ${statusText}${escapeHtml(errorInfo)}
                                </span>
                            </td>
                            <td style="padding: 12px; font-size: 12px; color: #666;">${escapeHtml(log.timestamp)}</td>
                        </tr>
                    `);
                });
            } else {
                $tbody.append('<tr><td colspan="4" style="padding: 20px; text-align: center; color: #666;">No logs found</td></tr>');
            }
        }).fail(function() {
            const $tbody = $('#logs-tbody');
            $tbody.empty();
            $tbody.append('<tr><td colspan="4" style="padding: 20px; text-align: center; color: #666;">Failed to load logs</td></tr>');
        });
    }

    function escapeHtml(text) {
        if (!text) return '';
        return String(text)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    // Helper function to show notifications
    function showNotification(message, type) {
        const bgColor = type === 'success' ? '#DCFCE7' : '#FEE2E2';
        const textColor = type === 'success' ? '#166534' : '#991B1B';
        const borderColor = type === 'success' ? '#BBF7D0' : '#FECACA';

        const $notification = $(`
            <div style="position: fixed; top: 20px; right: 20px; padding: 16px 24px; 
                background: ${bgColor}; color: ${textColor}; border: 1px solid ${borderColor}; 
                border-radius: 8px; z-index: 9999; max-width: 400px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                ${escapeHtml(message)}
            </div>
        `);

        $('body').append($notification);
        setTimeout(() => $notification.fadeOut(() => $notification.remove()), 3000);
    }

    // Save Email Preferences
    $('#save-preferences-btn').on('click', function() {
        if (!smtpData) return;

        const $btn = $(this);
        const originalText = $btn.text();

        $btn.text('Saving...').prop('disabled', true);

        const preferences = {
            enable_lite_smtp: $('#enable_lite_smtp').is(':checked'),
            enable_email_logging: $('#enable_logging').is(':checked'),
            enable_delivery_notifications: $('#delivery_notifications').is(':checked')
        };

        $.post(smtpData.ajaxurl, {
            action: 'lunexia_smtp_save_preferences',
            nonce: smtpData.nonce,
            preferences: preferences
        }, function(response) {
            if (response.success) {
                showNotification('Email preferences saved successfully!', 'success');
            } else {
                showNotification('Error: ' + (response.data || 'Failed to save preferences'), 'error');
            }
        }).fail(function() {
            showNotification('Error: Failed to save preferences', 'error');
        }).always(function() {
            $btn.text(originalText).prop('disabled', false);
        });
    });

    // Export Logs as CSV
    $('#export-csv-btn').on('click', function() {
        if (!smtpData) return;

        const $btn = $(this);
        const originalText = $btn.text();

        $btn.text('Exporting...').prop('disabled', true);

        const $form = $('<form>', {
            method: 'POST',
            action: smtpData.ajaxurl,
            target: '_blank'
        });

        $form.append($('<input>', {
            type: 'hidden',
            name: 'action',
            value: 'lunexia_smtp_export_logs'
        }));

        $form.append($('<input>', {
            type: 'hidden',
            name: 'nonce',
            value: smtpData.nonce
        }));

        $form.append($('<input>', {
            type: 'hidden',
            name: 'format',
            value: 'csv'
        }));

        $('body').append($form);
        $form.submit();
        $form.remove();

        $btn.text(originalText).prop('disabled', false);
    });

    // Export Logs as Excel
    $('#export-excel-btn').on('click', function() {
        if (!smtpData) return;

        const $btn = $(this);
        const originalText = $btn.text();

        $btn.text('Exporting...').prop('disabled', true);

        const $form = $('<form>', {
            method: 'POST',
            action: smtpData.ajaxurl,
            target: '_blank'
        });

        $form.append($('<input>', {
            type: 'hidden',
            name: 'action',
            value: 'lunexia_smtp_export_logs'
        }));

        $form.append($('<input>', {
            type: 'hidden',
            name: 'nonce',
            value: smtpData.nonce
        }));

        $form.append($('<input>', {
            type: 'hidden',
            name: 'format',
            value: 'excel'
        }));

        $('body').append($form);
        $form.submit();
        $form.remove();

        $btn.text(originalText).prop('disabled', false);
    });

    // Clear Email Logs
    $('#clear-logs-btn').on('click', function() {
        if (!smtpData) return;

        if (!confirm('Are you sure you want to clear all email logs? This action cannot be undone.')) {
            return;
        }

        const $btn = $(this);
        const originalText = $btn.text();

        $btn.text('Clearing...').prop('disabled', true);

        $.post(smtpData.ajaxurl, {
            action: 'lunexia_smtp_clear_logs',
            nonce: smtpData.nonce
        }, function(response) {
            if (response.success) {
                showNotification('Email logs cleared successfully!', 'success');
                loadEmailLogs();
            } else {
                showNotification('Error: ' + (response.data || 'Failed to clear logs'), 'error');
            }
        }).fail(function() {
            showNotification('Error: Failed to clear logs', 'error');
        }).always(function() {
            $btn.text(originalText).prop('disabled', false);
        });
    });
});

// Admin Notice System
(function($) {
    $(document).ready(function() {
        function sendAjaxRequest(data) {
            if (typeof liteSMTPNoticeData !== 'undefined' && liteSMTPNoticeData.ajaxUrl) {
                $.post(liteSMTPNoticeData.ajaxUrl, data);
            }
        }

        $('.lite-smtp-notice-close').on('click', function(event) {
            event.preventDefault();

            var $notice = $(this).closest('.lite-smtp-notice-box');
            var noticeType = $notice.data('notice-type');

            if ( ! noticeType || typeof liteSMTPNoticeData === 'undefined' ) {
                return;
            }

            sendAjaxRequest({
                action: 'lunexia_smtp_dismiss_notice',
                nonce: liteSMTPNoticeData.noticeNonce,
                notice_type: noticeType,
            });

            $notice.slideUp(200);
        });

        $('.lite-smtp-notice-btn').on('click', function(event) {
            var $button = $(this);
            var noticeType = $button.closest('.lite-smtp-notice-box').data('notice-type');
            var actionType = $button.data('action');

            if ( ! noticeType || ! actionType || typeof liteSMTPNoticeData === 'undefined' ) {
                return;
            }

            if ( $button.is('button') ) {
                event.preventDefault();
            }

            sendAjaxRequest({
                action: 'lunexia_smtp_notice_action',
                nonce: liteSMTPNoticeData.noticeNonce,
                notice_type: noticeType,
                action_type: actionType,
            });

            $button.closest('.lite-smtp-notice-box').slideUp(200);
        });
    });
})(jQuery);
