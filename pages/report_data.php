<?php
/** @var \DARTNet\ReportsDashboard\ReportsDashboard $module */

// JSON endpoint for pages/dashboard.php. This page is not a link in
// config.json, so the framework does not run redcap_module_link_check_display()
// for it; the same access rule is applied here. POSTs are CSRF-checked by the
// framework before this page runs.
header('Content-Type: application/json; charset=utf-8');

$respond = function ($status, array $body) {
    http_response_code($status);
    echo json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};

if (!$module->userCanAccessDashboard($module->getProjectId())) {
    $respond(403, ['ok' => false, 'error' => 'You do not have access to the Reports Dashboard.']);
}

$action = isset($_REQUEST['action']) ? (string)$_REQUEST['action'] : '';
if (!in_array($action, ['count', 'preview', 'save_order'], true)) {
    $respond(400, ['ok' => false, 'error' => 'Invalid action.']);
}

if ($action === 'save_order') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        $respond(405, ['ok' => false, 'error' => 'POST required.']);
    }
    $reportIds = json_decode((string)($_POST['report_order'] ?? ''), true);
    if (!is_array($reportIds)) {
        $respond(400, ['ok' => false, 'error' => 'Invalid report order.']);
    }
    try {
        $respond(200, ['ok' => true, 'report_order' => $module->saveReportCardOrder($reportIds)]);
    } catch (\Throwable $e) {
        error_log('Reports Dashboard save order failed: ' . $e->getMessage());
        $respond(500, ['ok' => false, 'error' => 'The dashboard order could not be saved.']);
    }
}

$reportId = filter_input(INPUT_GET, 'report_id', FILTER_VALIDATE_INT);
if (!$reportId) {
    $respond(400, ['count' => 0, 'data' => [], 'error' => 'Invalid report ID.']);
}
if ($action === 'preview' && !$module->isDataPreviewEnabled()) {
    $respond(403, ['count' => 0, 'data' => [], 'error' => 'Data preview is turned off for this project.']);
}

// If the viewer cannot open this report, REDCap prints "ERROR" and ends the
// request inside getReportData(); the dashboard page recognises that reply.
$result = $module->getReportData($reportId, $action === 'preview');
if ($action === 'preview' && $result['data']) {
    $result['data'] = array_slice($result['data'], 0, $module->getMaxPreviewRows());
} else {
    $result['data'] = [];
}
// Reaching this line means REDCap ran the report for this viewer, so they may
// see its title. Viewers who cannot open it never get here.
$result['title'] = $result['error'] === null ? $module->getReportTitle($reportId) : null;
$result['report_url'] = $result['error'] === null ? $module->getReportPageUrl($reportId) : null;

// Keep this viewer's count so the next page load can show it without rerunning
// the report. A failure to save is not a failure to count.
if ($action === 'count' && $result['error'] === null) {
    try {
        $result['counted_at'] = $module->saveMyCount($reportId, $result['count'], $result['title'])['counted_at'];
    } catch (\Throwable $e) {
        error_log('Reports Dashboard could not store a count: ' . $e->getMessage());
        $result['counted_at'] = time();
    }
}
$respond($result['error'] === null ? 200 : 404, $result);
