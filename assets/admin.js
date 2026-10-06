(function ($) {
    'use strict';

    var $selectAll    = $('#sia-select-all');
    var $bulkTrash    = $('#sia-bulk-trash');
    var $bulkDismiss  = $('#sia-bulk-dismiss');

    function getSelectedIds() {
        return $('.sia-check:checked').map(function () {
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
        data.nonce  = siaAdmin.nonce;

        $.post(siaAdmin.ajaxUrl, data, function (res) {
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
        $('.sia-check').prop('checked', this.checked);
        updateBulkButtons();
    });

    $(document).on('change', '.sia-check', updateBulkButtons);

    // Start scan
    $('#sia-start-scan').on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Starting…');

        ajaxAction('sia_start_scan', {}, function () {
            $btn.replaceWith('<span class="sia-status scanning">Scan in progress…</span>');
            pollScanStatus();
        });
    });

    function pollScanStatus() {
        setTimeout(function () {
            $.get(siaAdmin.ajaxUrl, {
                action: 'sia_scan_status',
                nonce: siaAdmin.nonce
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
    $(document).on('click', '.sia-trash-one', function () {
        var $btn = $(this);
        var id   = $btn.data('id');
        if (!confirm('Move this image to trash?')) return;

        $btn.prop('disabled', true);
        ajaxAction('sia_trash', { ids: [id] }, function () {
            $btn.closest('tr').fadeOut(300, function () { $(this).remove(); });
        });
    });

    // Single dismiss
    $(document).on('click', '.sia-dismiss-one', function () {
        var $btn = $(this);
        var id   = $btn.data('id');

        $btn.prop('disabled', true);
        ajaxAction('sia_dismiss', { ids: [id] }, function () {
            $btn.closest('tr').fadeOut(300, function () { $(this).remove(); });
        });
    });

    // Bulk trash
    $bulkTrash.on('click', function () {
        var ids = getSelectedIds();
        if (!ids.length) return;
        if (!confirm('Move ' + ids.length + ' image(s) to trash?')) return;

        $(this).prop('disabled', true);
        ajaxAction('sia_trash', { ids: ids }, function () {
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
        ajaxAction('sia_dismiss', { ids: ids }, function () {
            ids.forEach(function (id) {
                $('tr[data-id="' + id + '"]').fadeOut(300, function () { $(this).remove(); });
            });
            updateBulkButtons();
        });
    });

    // Single restore (Deleted tab)
    $(document).on('click', '.sia-restore-one', function () {
        var $btn = $(this);
        var id   = $btn.data('id');
        if (!confirm('Restore this image from backup?')) return;

        $btn.prop('disabled', true).text('Restoring…');
        ajaxAction('sia_restore', { ids: [id] }, function (data) {
            if (data.restored > 0) {
                $btn.closest('tr').fadeOut(300, function () { $(this).remove(); });
            } else {
                alert('Restore failed. The backup files may have been cleaned up.');
                $btn.prop('disabled', false).text('Restore');
            }
        });
    });

    // Auto-poll if scan is running on page load
    if ($('.sia-status.scanning').length) {
        pollScanStatus();
    }

})(jQuery);
