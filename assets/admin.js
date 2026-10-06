(function ($) {
    'use strict';

    var $selectAll    = $('#wpmc-select-all');
    var $bulkTrash    = $('#wpmc-bulk-trash');
    var $bulkDismiss  = $('#wpmc-bulk-dismiss');

    function getSelectedIds() {
        return $('.wpmc-check:checked').map(function () {
            return $(this).val();
        }).get();
    }

    function updateBulkButtons() {
        var hasSelection = getSelectedIds().length > 0;
        $bulkTrash.prop('disabled', !hasSelection);
        $bulkDismiss.prop('disabled', !hasSelection);
    }

    function ajaxAction(action, data, onSuccess) {
        data.action = action;
        data.nonce  = wpmcAdmin.nonce;

        $.post(wpmcAdmin.ajaxUrl, data, function (res) {
            if (res.success) {
                onSuccess(res.data);
            } else {
                alert('Error: ' + (res.data || 'Unknown error'));
            }
        }).fail(function () {
            alert('Request failed. Please try again.');
        });
    }

    $selectAll.on('change', function () {
        $('.wpmc-check').prop('checked', this.checked);
        updateBulkButtons();
    });

    $(document).on('change', '.wpmc-check', updateBulkButtons);

    // Start scan
    $('#wpmc-start-scan').on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Starting…');

        ajaxAction('wpmc_start_scan', {}, function () {
            $btn.replaceWith('<span class="wpmc-status scanning">Scan in progress…</span>');
            pollScanStatus();
        });
    });

    function pollScanStatus() {
        setTimeout(function () {
            $.get(wpmcAdmin.ajaxUrl, {
                action: 'wpmc_scan_status',
                nonce: wpmcAdmin.nonce
            }, function (res) {
                if (res.success && (res.data.status === 'idle' || res.data.stale)) {
                    location.reload();
                } else {
                    pollScanStatus();
                }
            });
        }, 5000);
    }

    // Single trash
    $(document).on('click', '.wpmc-trash-one', function () {
        var $btn = $(this);
        var id   = $btn.data('id');
        if (!confirm('Move this image to trash?')) return;

        $btn.prop('disabled', true);
        ajaxAction('wpmc_trash', { ids: [id] }, function () {
            $btn.closest('tr').fadeOut(300, function () { $(this).remove(); });
        });
    });

    // Single dismiss
    $(document).on('click', '.wpmc-dismiss-one', function () {
        var $btn = $(this);
        var id   = $btn.data('id');

        $btn.prop('disabled', true);
        ajaxAction('wpmc_dismiss', { ids: [id] }, function () {
            $btn.closest('tr').fadeOut(300, function () { $(this).remove(); });
        });
    });

    // Bulk trash
    $bulkTrash.on('click', function () {
        var ids = getSelectedIds();
        if (!ids.length) return;
        if (!confirm('Move ' + ids.length + ' image(s) to trash?')) return;

        $(this).prop('disabled', true);
        ajaxAction('wpmc_trash', { ids: ids }, function () {
            ids.forEach(function (id) {
                $('tr[data-id="' + id + '"]').fadeOut(300, function () { $(this).remove(); });
            });
            updateBulkButtons();
        });
    });

    // Bulk dismiss
    $bulkDismiss.on('click', function () {
        var ids = getSelectedIds();
        if (!ids.length) return;

        $(this).prop('disabled', true);
        ajaxAction('wpmc_dismiss', { ids: ids }, function () {
            ids.forEach(function (id) {
                $('tr[data-id="' + id + '"]').fadeOut(300, function () { $(this).remove(); });
            });
            updateBulkButtons();
        });
    });

    // Single restore (Deleted tab)
    $(document).on('click', '.wpmc-restore-one', function () {
        var $btn = $(this);
        var id   = $btn.data('id');
        if (!confirm('Restore this image from backup?')) return;

        $btn.prop('disabled', true).text('Restoring…');
        ajaxAction('wpmc_restore', { ids: [id] }, function (data) {
            if (data.restored > 0) {
                $btn.closest('tr').fadeOut(300, function () { $(this).remove(); });
            } else {
                alert('Restore failed. The backup files may have been cleaned up.');
                $btn.prop('disabled', false).text('Restore');
            }
        });
    });

    // Auto-poll if scan is running on page load
    if ($('.wpmc-status.scanning').length) {
        pollScanStatus();
    }

})(jQuery);
