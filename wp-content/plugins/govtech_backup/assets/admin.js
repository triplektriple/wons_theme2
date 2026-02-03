jQuery(document).ready(function($) {

    const adminNoticeContainer = $('#govtech-admin-notices');
    const backupListContainer = $('#backup-list-container');
    const backupStatusIndicator = $('#backup-status-indicator');
    const statusSpinner = backupStatusIndicator.find('.spinner');
    const statusText = backupStatusIndicator.find('.status-text');
    let buttonAdded = false;

    // Function to display admin notices
    function showAdminNotice(message, type = 'success') {
        const noticeClass = type === 'error' ? 'notice-error' : 'notice-success';
        const noticeHtml = `<div class="notice ${noticeClass} is-dismissible"><p>${message}</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button></div>`;
        adminNoticeContainer.html(noticeHtml); // Replace existing notice
        // Handle dismissal (optional, WordPress handles this mostly)
        adminNoticeContainer.find('.notice-dismiss').on('click', function(e) {
            e.preventDefault();
            $(this).closest('.notice').fadeOut('fast', function() { $(this).remove(); });
        });
    }

    // --- Backup Deletion ---
    backupListContainer.on('click', '.delete-backup-btn', function(e) {
        e.preventDefault();
        const $button = $(this);
        const $row = $button.closest('tr');
        const s3Key = $row.data('key');
        const $spinner = $button.siblings('.delete-spinner');

        if (!s3Key) {
            showAdminNotice('Error: Could not find backup key.', 'error');
            return;
        }

        if (confirm(govtechBackupAdmin.text.confirm_delete)) {
            $button.prop('disabled', true);
            $spinner.addClass('is-active');
            adminNoticeContainer.empty(); // Clear previous notices

            $.post(govtechBackupAdmin.ajax_url, {
                action: 'govtech_backup_delete_s3_backup',
                nonce: govtechBackupAdmin.delete_nonce,
                key: s3Key
            })
            .done(function(response) {
                if (response.success) {
                    showAdminNotice(response.data.message || govtechBackupAdmin.text.delete_success, 'success');
                    $row.fadeOut('slow', function() { $(this).remove(); });
                    // Optionally reload the list or check if empty
                } else {
                    showAdminNotice((response.data && response.data.message) || govtechBackupAdmin.text.delete_error, 'error');
                }
            })
            .fail(function(xhr) {
                 let errorMsg = govtechBackupAdmin.text.delete_error;
                 if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                     errorMsg += ' ' + xhr.responseJSON.data.message;
                 } else if (xhr.statusText) {
                     errorMsg += ` (Status: ${xhr.status} ${xhr.statusText})`;
                 }
                 showAdminNotice(errorMsg, 'error');
            })
            .always(function() {
                $button.prop('disabled', false);
                $spinner.removeClass('is-active');
            });
        }
    });

    // --- Backup Download ---
    backupListContainer.on('click', '.download-backup-btn', function(e) {
        e.preventDefault();
        const $button = $(this);
        const $row = $button.closest('tr');
        const s3Key = $row.data('key');
        const filename = $row.find('td:first').text(); // Get filename from table cell

        if (!s3Key) {
            showAdminNotice('Error: Could not find backup key for download.', 'error');
            return;
        }

        // Construct the download URL pointing to admin-ajax.php
        const downloadUrl = new URL(govtechBackupAdmin.ajax_url);
        downloadUrl.searchParams.set('action', 'govtech_backup_download_s3_backup');
        downloadUrl.searchParams.set('nonce', govtechBackupAdmin.download_nonce);
        downloadUrl.searchParams.set('key', s3Key);
        // Optional: Pass filename hint, though Content-Disposition should handle it
        // downloadUrl.searchParams.set('filename', filename);

        // Trigger download by navigating to the URL
        window.location.href = downloadUrl.toString();

        // Note: No easy way to show spinner/feedback for direct downloads like this.
        // The browser handles the download prompt.
    });

    function clearBackupStatus() {
        $.post(govtechBackupAdmin.ajax_url, {
            action: 'govtech_backup_clear_status',
            nonce: govtechBackupAdmin.backup_clear_status_nonce
        })
        .done(function(response) {
            if (response.success) {
                showAdminNotice(response.data.message || govtechBackupAdmin.text.clear_status_success, 'success');
                // Optionally reload the list or check if empty
                backupListContainer.empty(); // Clear the list for now
            }
            else {
                showAdminNotice((response.data && response.data.message) || govtechBackupAdmin.text.clear_error, 'clear_status_error');
            }
        })
        .fail(function(xhr) {
                let errorMsg = govtechBackupAdmin.text.clear_error;
                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    errorMsg += ' ' + xhr.responseJSON.data.message;
                } else if (xhr.statusText) {
                    errorMsg += ` (Status: ${xhr.status} ${xhr.statusText})`;
                }
                showAdminNotice(errorMsg, 'error');
            }
        );
    };
    // --- Manual License Check ---
    $('#manual-license-check-btn').on('click', function(e) {
        e.preventDefault();
        const $button = $(this);
        const originalText = $button.text();
        $button.prop('disabled', true).text(govtechBackupAdmin.text.checking_license + '...');
        adminNoticeContainer.empty();

        $.post(govtechBackupAdmin.ajax_url, {
            action: 'govtech_backup_manual_license_check',
            nonce: govtechBackupAdmin.license_check_nonce
        })
        .done(function(response) {
            if (response.success) {
                showAdminNotice(response.data.message || govtechBackupAdmin.text.license_check_success, 'success');
                // Reload the page to show updated status sections
                location.reload();
            } else {
                showAdminNotice((response.data && response.data.message) || govtechBackupAdmin.text.license_check_error, 'error');
            }
        })
        .fail(function(xhr) {
             let errorMsg = govtechBackupAdmin.text.license_check_error;
             if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                 errorMsg += ' ' + xhr.responseJSON.data.message;
             } else if (xhr.statusText) {
                 errorMsg += ` (Status: ${xhr.status} ${xhr.statusText})`;
             }
             showAdminNotice(errorMsg, 'error');
        })
        .always(function() {
            $button.prop('disabled', false).text(originalText);
        });
    });

    // --- Backup Status Check ---
    function checkBackupStatus() {
        
        // statusText.text(govtechBackupAdmin.text.checking_progress);
        
        $.post(govtechBackupAdmin.ajax_url, {
            action: 'govtech_backup_backup_in_progress',
            // nonce: govtechBackupAdmin.backup_progress_nonce // Add nonce if needed in PHP
        })
        .done(function(response) {
            
            if (response.success) {
                if (response.data.in_progress) {
                    statusSpinner.addClass('is-active');
                    statusText.text(govtechBackupAdmin.text.backup_running);
                    // insert button with id manual-clear-backup-btn if not already present
                    if (!buttonAdded) {
                        const clearButton = $('<button id="manual-clear-backup-btn" class="button button-secondary">' + 
                                             govtechBackupAdmin.text.clear_backup_flag + '</button>');
                        
                        // Add click handler
                        clearButton.on('click', function() {
                            if (confirm(govtechBackupAdmin.text.confirm_clear_backup)) {
                                clearBackupStatus();
                            }
                        });
                        
                        backupStatusIndicator.append(clearButton);
                        buttonAdded = true;
                    }
                    // Optionally, you can add a spinner or loading animation here
                    // Optionally schedule another check sooner if backup is running
                    setTimeout(checkBackupStatus, 5000); // Check again in 15 seconds
                } else {
                    statusSpinner.removeClass('is-active');
                    statusText.text(govtechBackupAdmin.text.backup_not_running);
                    //Remove the button with id manual-clear-backup-btn if not running
                   if (buttonAdded) {
                        $('#manual-clear-backup-btn').remove();
                        buttonAdded = false;
                    }
                     // Schedule next check at a normal interval if not running
                     setTimeout(checkBackupStatus, 5000); // Check again in 60 seconds
                }
            } else {
                 statusText.text(govtechBackupAdmin.text.backup_status_error);
                 // Schedule next check even on error, but maybe less frequently
                 setTimeout(checkBackupStatus, 120000); // Check again in 120 seconds
            }
        })
        .fail(function() {
            statusText.text(govtechBackupAdmin.text.backup_status_error);
            setTimeout(checkBackupStatus, 120000); // Check again in 120 seconds on failure
        })
        .always(function() {
             statusSpinner.removeClass('is-active');
        });
    }

    // Initial check on page load
    checkBackupStatus();
    // Note: Backup list loading is initially done via PHP render.
    // Could add a "Refresh List" button here that re-calls the PHP rendering via AJAX if needed.

});
