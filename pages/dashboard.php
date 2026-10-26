<?php
/** @var \DARTNet\ReportsDashboard\ReportsDashboard $module */

$projectId = (int)$module->getProjectId();
$reports = $module->getOrderedConfiguredReports($projectId);
$manualCounts = $module->countsOnlyWhenAsked($projectId);
$includeAll = $module->includesAllReports($projectId);
$catalogUpdatedAt = $includeAll ? $module->getReportCatalog($projectId)['updated_at'] : 0;
// This viewer's own stored counts. Titles are only included for reports
// REDCap has already run for this viewer, using the latest known title.
$myCounts = [];
foreach ($module->getMyCounts($projectId) as $reportId => $entry) {
    $myCounts[$reportId] = [
        'count' => $entry['count'],
        'counted_at' => $entry['counted_at'],
        'title' => $module->getReportTitle($reportId, $projectId) ?? $entry['title'],
        'report_url' => $module->getReportPageUrl($reportId, $projectId)
    ];
}
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE;
$jsConfig = [
    'reportDataUrl' => $module->getUrl('pages/report_data.php'),
    'previewEnabled' => $module->isDataPreviewEnabled($projectId),
    'manualCounts' => $manualCounts,
    'myCounts' => (object)$myCounts,
    'csrfToken' => $module->getCSRFToken()
];
?>
<style>
    .reports-dashboard { max-width: 1400px; margin: 0 auto; padding: 20px; }
    .reports-dashboard .rd-header { background: linear-gradient(135deg,#667eea,#764ba2); color:#fff; padding:30px; border-radius:10px; margin-bottom:30px; }
    .reports-dashboard .rd-header h2 { margin:0 0 10px; font-size:28px; }
    .reports-dashboard .rd-summary-cards, .reports-dashboard .rd-grid { display:grid; gap:20px; }
    .reports-dashboard .rd-summary-cards { grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); margin-bottom:30px; }
    .reports-dashboard .rd-grid { grid-template-columns:repeat(auto-fill,minmax(330px,1fr)); }
    .reports-dashboard .rd-summary-card, .reports-dashboard .rd-card { background:#fff; border-radius:10px; box-shadow:0 2px 10px rgba(0,0,0,.1); }
    .reports-dashboard .rd-summary-card { padding:20px; text-align:center; }
    .reports-dashboard .rd-number { font-size:36px; font-weight:bold; color:#667eea; }
    .reports-dashboard .rd-label, .reports-dashboard .rd-count-label { color:#666; font-size:14px; }
    .reports-dashboard .rd-card { overflow:hidden; }
    .reports-dashboard .rd-card-header { padding:15px 20px; background:#f8f9fa; border-bottom:1px solid #eee; display:flex; align-items:center; gap:10px; }
    .reports-dashboard .rd-card-header h4 { margin:0; font-size:14px; flex:1; overflow-wrap:anywhere; }
    .reports-dashboard .rd-drag-handle { color:#999; cursor:grab; }
    .reports-dashboard .rd-list-status { margin-bottom:20px; padding:10px 14px; background:#eef2ff; border-left:4px solid #667eea; color:#444; font-size:13px; }
    .reports-dashboard .rd-card-body { padding:20px; text-align:center; }
    .reports-dashboard .rd-count { font-size:48px; font-weight:bold; margin:10px 0; }
    .reports-dashboard .rd-has-data { color:#28a745; } .reports-dashboard .rd-no-data { color:#dc3545; }
    .reports-dashboard .rd-preview { margin-top:15px; max-height:150px; overflow:auto; font-size:11px; background:#f8f9fa; padding:10px; border-radius:5px; text-align:left; }
    .reports-dashboard .rd-preview table { width:100%; border-collapse:collapse; }
    .reports-dashboard .rd-preview th, .reports-dashboard .rd-preview td { padding:4px 8px; border:1px solid #ddd; text-align:left; }
    .reports-dashboard .rd-preview th { background:#e9ecef; position:sticky; top:0; }
    .reports-dashboard .rd-preview-more { margin:8px 0 0; color:#888; text-align:center; }
    .reports-dashboard .rd-toggle-preview, .reports-dashboard .rd-filter-btn, .reports-dashboard .rd-count-btn { cursor:pointer; }
    .reports-dashboard .rd-toggle-preview { margin-top:12px; border:0; background:none; color:#667eea; padding:0; }
    .reports-dashboard .rd-count-btn { margin-top:8px; border:1px solid #667eea; background:#667eea; color:#fff; padding:6px 14px; border-radius:5px; }
    .reports-dashboard .rd-count-btn:disabled, .reports-dashboard .rd-refresh-all:disabled { opacity:.6; cursor:wait; }
    .reports-dashboard .rd-open-report { display:inline-block; margin-top:8px; font-size:12px; color:#667eea; }
    .reports-dashboard .rd-counted-at { margin-top:6px; color:#888; font-size:11px; min-height:14px; }
    .reports-dashboard .rd-refresh-all { margin-left:auto; border:1px solid #667eea; background:#667eea; color:#fff; padding:8px 14px; border-radius:5px; cursor:pointer; }
    .reports-dashboard .rd-filters { display:flex; align-items:center; gap:15px; margin-bottom:20px; flex-wrap:wrap; }
    .reports-dashboard .rd-filter-btn { border:2px solid #667eea; background:#fff; color:#667eea; padding:8px 20px; border-radius:20px; }
    .reports-dashboard .rd-filter-btn.rd-active { background:#667eea; color:#fff; }
    .reports-dashboard .rd-layout-status { color:#777; font-size:12px; }
    .reports-dashboard .rd-error { color:#842029; background:#f8d7da; padding:10px; border-radius:5px; margin-top:12px; font-size:12px; text-align:left; }
    .reports-dashboard .rd-empty { padding:25px; background:#eef2ff; border-left:4px solid #667eea; }
    .reports-dashboard .rd-card-placeholder { min-height:180px; border:2px dashed #667eea; border-radius:10px; background:#eef2ff; }
</style>

<div class="reports-dashboard" id="reports-dashboard">
    <div class="rd-header">
        <h2>Reports Dashboard</h2>
        <p>Project ID: <?= $projectId ?> | Configured Reports: <?= count($reports) ?></p>
    </div>

    <div class="rd-summary-cards">
        <div class="rd-summary-card"><div class="rd-number"><?= count($reports) ?></div><div class="rd-label">Configured Reports</div></div>
        <div class="rd-summary-card"><div class="rd-number" id="rd-with-data">&mdash;</div><div class="rd-label">Reports With Data</div></div>
        <div class="rd-summary-card"><div class="rd-number" id="rd-without-data">&mdash;</div><div class="rd-label">Reports Without Data</div></div>
        <div class="rd-summary-card"><div class="rd-number" id="rd-total-rows">&mdash;</div><div class="rd-label">Total Rows</div></div>
    </div>

    <?php if ($includeAll && $catalogUpdatedAt): ?>
        <div class="rd-list-status">Showing every report in this project, as of <span id="rd-list-updated" data-time="<?= (int)$catalogUpdatedAt ?>"></span>. To pick up reports added, renamed or deleted since then, a user with Project Design &amp; Setup rights can open and close this module's configuration.</div>
    <?php endif; ?>

    <?php if (!$reports && $includeAll): ?>
        <div class="rd-empty">This dashboard is set to show every report, but it has not loaded the project's report list yet. A user with Project Design &amp; Setup rights can load it by opening and closing this module's configuration.</div>
    <?php elseif (!$reports): ?>
        <div class="rd-empty">No reports have been selected. A user with Project Design &amp; Setup rights can select dashboard reports in this module's project configuration.</div>
    <?php else: ?>
        <div class="rd-filters">
            <span>Filter:</span>
            <button type="button" class="rd-filter-btn rd-active" data-show="all">All</button>
            <button type="button" class="rd-filter-btn" data-show="with-data">With Data</button>
            <button type="button" class="rd-filter-btn" data-show="no-data">Without Data</button>
            <span class="rd-layout-status" id="rd-layout-status" role="status"></span>
            <button type="button" class="rd-refresh-all" id="rd-refresh-all">Refresh all</button>
        </div>
        <div class="rd-grid" id="rd-grid">
            <?php foreach ($reports as $report): ?>
                <div class="rd-card" data-report-id="<?= (int)$report['report_id'] ?>" data-filter="loading">
                    <div class="rd-card-header">
                        <span class="rd-drag-handle" title="Drag to reorder"><i class="fas fa-grip-vertical" aria-hidden="true"></i></span>
                        <?php // Titles arrive with each count, once REDCap has run the report for this viewer. ?>
                        <h4 class="rd-title"<?= $report['label'] === null ? ' data-awaiting-title="1"' : '' ?>><?= $report['label'] === null ? 'Report' : $module->escape($report['label']) ?></h4>
                    </div>
                    <div class="rd-card-body">
                        <div class="rd-count">&mdash;</div>
                        <div class="rd-count-label">Not counted yet</div>
                        <div class="rd-counted-at"></div>
                        <div class="rd-link-container"></div>
                        <button type="button" class="rd-count-btn">Count</button>
                        <div class="rd-preview-container"></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
(function ($, config) {
    'use strict';
    var $root = $('#reports-dashboard');
    var totals = { withData: 0, withoutData: 0, rows: 0, counted: {} };
    var NO_ACCESS = 'You do not have access to this report in REDCap, or it no longer exists.';

    function cards() { return $root.find('.rd-card'); }

    // Only cards without a designer label take the report's title.
    function showTitle($card, title) {
        var $title = $card.find('.rd-title[data-awaiting-title]');
        if ($title.length && title) $title.text(String(title)).removeAttr('data-awaiting-title');
    }

    // Links come with a successful count, so only viewers REDCap let run the
    // report get one. They are module-built paths on this server; anything else is ignored.
    function showLink($card, url) {
        var $container = $card.find('.rd-link-container');
        if (!url || typeof url !== 'string' || url.charAt(0) !== '/' || url.charAt(1) === '/' || $container.children().length) return;
        $container.append(
            $('<a class="rd-open-report" target="_blank" rel="noopener noreferrer">')
                .attr('href', url)
                .attr('title', 'Open this report in REDCap (new tab)')
                .append(document.createTextNode('Open report '), $('<i class="fas fa-external-link-alt" aria-hidden="true">'))
        );
    }

    var $listUpdated = $('#rd-list-updated');
    if ($listUpdated.length) $listUpdated.text(new Date(Number($listUpdated.data('time')) * 1000).toLocaleString());

    // REDCap ends the request with a bare "ERROR" (or "0" for AJAX) when the
    // viewer cannot open a report, so that reply is not JSON.
    function failureMessage(xhr, fallback) {
        var text = $.trim((xhr && xhr.responseText) || '');
        if (text === 'ERROR' || text === '0') return NO_ACCESS;
        if (xhr && xhr.responseJSON && xhr.responseJSON.error) return String(xhr.responseJSON.error);
        return fallback;
    }

    function updateTotals() {
        $('#rd-with-data').text(totals.withData);
        $('#rd-without-data').text(totals.withoutData);
        $('#rd-total-rows').text(totals.rows);
    }

    function showError($card, message) {
        $card.attr('data-filter', 'error');
        $card.find('.rd-count').removeClass('rd-has-data').addClass('rd-no-data').text('?');
        $card.find('.rd-count-label').text('Unavailable');
        $card.find('.rd-error').remove();
        $card.find('.rd-preview-container').before($('<div class="rd-error">').text(message));
    }

    function showCount($card, count) {
        var hasData = count > 0;
        var reportId = String($card.data('report-id'));
        $card.find('.rd-error').remove();
        $card.attr('data-filter', hasData ? 'with-data' : 'no-data');
        $card.find('.rd-count').toggleClass('rd-has-data', hasData).toggleClass('rd-no-data', !hasData).text(count);
        $card.find('.rd-count-label').text(count === 1 ? 'row' : 'rows');
        if (hasData && config.previewEnabled && !$card.find('.rd-toggle-preview').length) {
            $card.find('.rd-preview-container').append($('<button type="button" class="rd-toggle-preview">').text('Show Preview'));
        }
        // A recount replaces the card's earlier contribution to the totals.
        var previous = totals.counted[reportId];
        if (previous !== undefined) {
            totals[previous > 0 ? 'withData' : 'withoutData']--;
            totals.rows -= previous;
        }
        totals.counted[reportId] = count;
        totals[hasData ? 'withData' : 'withoutData']++;
        totals.rows += count;
        updateTotals();
    }

    function showCountedAt($card, countedAt) {
        $card.find('.rd-counted-at').text(countedAt ? 'Counted ' + new Date(countedAt * 1000).toLocaleString() : '');
        $card.find('.rd-count-btn').text(countedAt ? 'Refresh' : 'Count');
    }

    function countCard($card) {
        var $button = $card.find('.rd-count-btn').prop('disabled', true).text('Counting...');
        $card.find('.rd-count').html('&hellip;');
        $card.find('.rd-count-label').text('Counting');
        return $.ajax({ url: config.reportDataUrl, dataType: 'json', data: { action: 'count', report_id: $card.data('report-id') }, timeout: 120000 })
            .done(function (result) {
                if (result.error) { showError($card, result.error); return; }
                showTitle($card, result.title);
                showLink($card, result.report_url);
                showCount($card, Number(result.count) || 0);
                showCountedAt($card, Number(result.counted_at) || 0);
            })
            .fail(function (xhr) { showError($card, failureMessage(xhr, 'This report could not be loaded.')); })
            .always(function () {
                $button.prop('disabled', false);
                if ($button.text() === 'Counting...') $button.text($card.find('.rd-counted-at').text() ? 'Refresh' : 'Count');
            });
    }

    // Count one card at a time so the dashboard never occupies more than one
    // PHP worker per viewer.
    function countAll(allCards, done) {
        var index = 0;
        (function next() {
            if (index >= allCards.length) { if (done) done(); return; }
            countCard($(allCards[index++])).always(next);
        })();
    }

    // Show this viewer's stored counts straight away.
    var uncounted = [];
    cards().each(function () {
        var $card = $(this);
        var stored = config.myCounts[String($card.data('report-id'))];
        if (!stored) { uncounted.push(this); return; }
        showTitle($card, stored.title);
        showLink($card, stored.report_url);
        showCount($card, Number(stored.count) || 0);
        showCountedAt($card, Number(stored.counted_at) || 0);
    });

    function currentOrder() {
        return cards().map(function () { return Number($(this).data('report-id')); }).get();
    }

    function restoreOrder(order) {
        var $grid = $('#rd-grid');
        order.forEach(function (reportId) {
            $grid.append($grid.find('.rd-card[data-report-id="' + Number(reportId) + '"]'));
        });
    }

    if ($.fn.sortable) {
        var orderBeforeDrag = [];
        $('#rd-grid').sortable({
            items: '> .rd-card', handle: '.rd-drag-handle', placeholder: 'rd-card-placeholder',
            start: function () { orderBeforeDrag = currentOrder(); $('#rd-layout-status').text('Moving...'); },
            update: function () {
                $.ajax({
                    url: config.reportDataUrl, method: 'POST', dataType: 'json', timeout: 10000,
                    data: { action: 'save_order', report_order: JSON.stringify(currentOrder()), redcap_csrf_token: config.csrfToken }
                }).done(function () {
                    $('#rd-layout-status').text('Order saved');
                    window.setTimeout(function () { $('#rd-layout-status').text(''); }, 2000);
                }).fail(function (xhr) {
                    restoreOrder(orderBeforeDrag);
                    $('#rd-layout-status').text(failureMessage(xhr, 'The new order could not be saved.'));
                });
            }
        });
    }

    $root.on('click', '.rd-filter-btn', function () {
        var show = $(this).data('show');
        $root.find('.rd-filter-btn').removeClass('rd-active');
        $(this).addClass('rd-active');
        cards().each(function () {
            var $card = $(this);
            $card.toggle(show === 'all' || $card.attr('data-filter') === show);
        });
    });

    $root.on('click', '.rd-count-btn', function () {
        countCard($(this).closest('.rd-card'));
    });

    $('#rd-refresh-all').on('click', function () {
        var $button = $(this).prop('disabled', true).text('Refreshing...');
        countAll(cards().toArray(), function () { $button.prop('disabled', false).text('Refresh all'); });
    });

    $root.on('click', '.rd-toggle-preview', function () {
        var $button = $(this), $card = $button.closest('.rd-card');
        if ($button.data('loaded')) {
            var $preview = $button.next('.rd-preview').toggle();
            $button.text($preview.is(':visible') ? 'Hide Preview' : 'Show Preview');
            return;
        }
        $button.prop('disabled', true).text('Loading Preview...');
        $.ajax({ url: config.reportDataUrl, dataType: 'json', data: { action: 'preview', report_id: $card.data('report-id') }, timeout: 120000 })
            .done(function (result) {
                if (!result.error) { showTitle($card, result.title); showLink($card, result.report_url); }
                var rows = $.isArray(result.data) ? result.data : [];
                if (result.error || !rows.length) { $button.text(result.error ? 'Preview unavailable' : 'No rows to preview'); return; }
                var allColumns = Object.keys(rows[0]);
                var columns = allColumns.slice(0, 5);
                var $head = $('<tr>');
                columns.forEach(function (column) { $head.append($('<th>').text(column)); });
                if (allColumns.length > 5) $head.append($('<th>').text('...'));
                var $body = $('<tbody>');
                rows.forEach(function (row) {
                    var $row = $('<tr>');
                    columns.forEach(function (column) { $row.append($('<td>').text(String(row[column] == null ? '' : row[column]).substring(0, 30))); });
                    if (allColumns.length > 5) $row.append($('<td>').text('...'));
                    $body.append($row);
                });
                var $preview = $('<div class="rd-preview">').append($('<table>').append($('<thead>').append($head), $body));
                var total = Number(result.count) || rows.length;
                if (total > rows.length) $preview.append($('<p class="rd-preview-more">').text('... and ' + (total - rows.length) + ' more rows'));
                $button.after($preview);
                $button.text('Hide Preview').data('loaded', true);
            })
            .fail(function (xhr) { $button.text(failureMessage(xhr, 'Preview unavailable')); })
            .always(function () { $button.prop('disabled', false); });
    });

    // Count only what this viewer has never counted (or whose count expired).
    // Refresh all waits until this first pass finishes, so only one count runs at a time.
    if (!config.manualCounts && uncounted.length) {
        var $refreshAll = $('#rd-refresh-all').prop('disabled', true);
        countAll(uncounted, function () { $refreshAll.prop('disabled', false); });
    }
})(jQuery, <?= json_encode($jsConfig, $jsonFlags) ?>);
</script>
