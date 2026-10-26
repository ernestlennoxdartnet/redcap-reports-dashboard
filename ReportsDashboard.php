<?php

namespace DARTNet\ReportsDashboard;

use ExternalModules\AbstractExternalModule;

/**
 * Reports Dashboard uses public External Module Framework methods and the
 * public REDCap report API. Project designers select the dashboard reports.
 */
class ReportsDashboard extends AbstractExternalModule
{
    const CARD_ORDER_KEY = 'report-card-order';
    // Each viewer's own counts (user setting: this user, this project only).
    // Counts depend on the viewer's rights, so they are never shared.
    const MY_COUNTS_KEY = 'my-report-counts';
    const COUNT_HOURS_KEY = 'count-cache-hours';
    const REPORT_CONFIG_KEY = 'dashboard-report-config';
    const REPORT_ID_KEY = 'dashboard-reports';
    const INCLUDE_ALL_KEY = 'include-all-reports';
    // Every report in the project, with its title, as the configuration
    // dialog last listed them. Written by redcap_module_configuration_settings().
    const CATALOG_KEY = 'report-catalog';
    const DEFAULT_PREVIEW_ROWS = 5;
    const MAX_PREVIEW_ROWS = 100;
    const MAX_TITLE_LENGTH = 200;

    const ERROR_NOT_CONFIGURED = 'This report is not available on the dashboard.';
    const ERROR_REPORT = 'This report could not be loaded.';

    /**
     * This hook controls both menu visibility and direct access to the
     * configured project page.
     */
    public function redcap_module_link_check_display($project_id, $link)
    {
        return $this->userCanAccessDashboard($project_id) ? $link : null;
    }

    /**
     * Called when someone who may configure the module opens its project
     * settings. By then the framework has filled the report-list setting with
     * every report in the project and its title, so this is the one documented
     * place to learn that list. It is saved for the dashboard to use and the
     * settings are returned unchanged.
     */
    public function redcap_module_configuration_settings($project_id, $settings)
    {
        try {
            if (!empty($project_id)) {
                $catalog = $this->buildCatalog($settings);
                if ($catalog !== null) {
                    $this->setProjectSetting(self::CATALOG_KEY, [
                        'updated_at' => time(),
                        'reports' => $catalog
                    ], (int)$project_id);
                }
            }
        } catch (\Throwable $e) {
            // Never let this break the configuration dialog.
            error_log('Reports Dashboard could not save the report list: ' . $e->getMessage());
        }
        return $settings;
    }

    /**
     * Pull [report_id => title] from the report-list choices in the settings
     * the configuration dialog is about to show. Returns null when the
     * choices are missing, so an unexpected shape never wipes a saved list.
     *
     * @return array<int,string>|null
     */
    public function buildCatalog($settings)
    {
        foreach ((array)$settings as $setting) {
            if (($setting['key'] ?? null) !== self::REPORT_CONFIG_KEY) continue;
            foreach ((array)($setting['sub_settings'] ?? []) as $subSetting) {
                if (($subSetting['key'] ?? null) !== self::REPORT_ID_KEY) continue;
                if (!isset($subSetting['choices']) || !is_array($subSetting['choices'])) return null;

                $catalog = [];
                foreach ($subSetting['choices'] as $choice) {
                    $reportId = filter_var($choice['value'] ?? null, FILTER_VALIDATE_INT);
                    if (!$reportId || $reportId < 1) continue;
                    // The framework HTML-escapes titles for the dialog; store plain text.
                    $title = trim(html_entity_decode((string)($choice['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    $catalog[(int)$reportId] = mb_substr($title, 0, self::MAX_TITLE_LENGTH);
                }
                return $catalog;
            }
        }
        return null;
    }

    /**
     * @return array{updated_at:int, reports:array<int,string>}
     */
    public function getReportCatalog($projectId = null)
    {
        if ($projectId === null) $projectId = $this->getProjectId();
        $stored = $this->getProjectSetting(self::CATALOG_KEY, (int)$projectId);
        $reports = [];
        foreach ((array)($stored['reports'] ?? []) as $reportId => $title) {
            $reportId = filter_var($reportId, FILTER_VALIDATE_INT);
            if ($reportId && $reportId > 0) $reports[(int)$reportId] = mb_substr((string)$title, 0, self::MAX_TITLE_LENGTH);
        }
        return ['updated_at' => (int)($stored['updated_at'] ?? 0), 'reports' => $reports];
    }

    /**
     * Link to REDCap's own page for a report, or null.
     *
     * Built from this module's getUrl() result, whose documented form is
     * .../redcap_vX.X.X/ExternalModules/?prefix=...: the report page lives in
     * the same versioned directory. Only the path is kept (getUrl() returns an
     * absolute URL, whose host may not be the one the browser uses). Never
     * call this before getReportData() has succeeded for the same user.
     */
    public function getReportPageUrl($reportId, $projectId = null)
    {
        if ($projectId === null) $projectId = $this->getProjectId();
        return $this->buildReportPageUrl($this->getUrl('pages/dashboard.php'), $projectId, $reportId);
    }

    /**
     * Returns null when the URL is not in the documented form (for example
     * when modules are served from /external_modules/, which has no version
     * directory), so the card simply shows no link rather than a wrong one.
     */
    public function buildReportPageUrl($moduleUrl, $projectId, $reportId)
    {
        $projectId = filter_var($projectId, FILTER_VALIDATE_INT);
        $reportId = filter_var($reportId, FILTER_VALIDATE_INT);
        if (!$projectId || $projectId < 1 || !$reportId || $reportId < 1) return null;

        $path = parse_url((string)$moduleUrl, PHP_URL_PATH);
        if (!is_string($path) || !preg_match('#^((?:/[^/?\#]+)*/redcap_v\d+\.\d+\.\d+/)ExternalModules/(?:index\.php)?$#', $path, $match)) {
            return null;
        }
        return $match[1] . 'DataExport/index.php?pid=' . (int)$projectId . '&report_id=' . (int)$reportId;
    }

    public function includesAllReports($projectId = null)
    {
        if ($projectId === null) $projectId = $this->getProjectId();
        return $this->isChecked($this->getProjectSetting(self::INCLUDE_ALL_KEY, (int)$projectId));
    }

    /**
     * The report's title, for a viewer REDCap has just let run it. Never
     * call this before getReportData() has succeeded for the same user.
     */
    public function getReportTitle($reportId, $projectId = null)
    {
        $title = $this->getReportCatalog($projectId)['reports'][(int)$reportId] ?? '';
        return $title !== '' ? $title : null;
    }

    public function userCanAccessDashboard($projectId)
    {
        $projectId = (int)$projectId;
        if ($projectId < 1 || !$this->isAuthenticated()) return false;

        try {
            // The module-level check respects "View project as user".
            if ($this->isSuperUser()) return true;

            $user = $this->getUser();
            if ($user->hasDesignRights($projectId)) return true;

            $rights = $user->getRights($projectId);
            $roleId = (string)($rights['role_id'] ?? '');
            return $roleId !== '' && in_array($roleId, $this->getAllowedRoles($projectId), true);
        } catch (\Throwable $e) {
            error_log('Reports Dashboard access check failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * @return string[]
     */
    public function getAllowedRoles($projectId)
    {
        $roles = $this->getProjectSetting('allowed-roles', (int)$projectId);
        if (!is_array($roles)) $roles = [$roles];

        $result = [];
        foreach ($roles as $roleId) {
            $roleId = (string)$roleId;
            if ($roleId !== '' && !in_array($roleId, $result, true)) $result[] = $roleId;
        }
        return $result;
    }

    /**
     * The dashboard's cards. With "Show every report" on, one card per report
     * in the saved report list (in report-number order); otherwise the reports
     * selected in the report-list sub-setting, in configured order.
     *
     * `label` is the designer's card label, shown to every dashboard user, or
     * null. Report titles are never included here: they are only sent to a
     * viewer after REDCap has run the report for them (see getReportTitle()).
     *
     * @return array<int, array{report_id:int,label:?string}>
     */
    public function getConfiguredReports($projectId = null)
    {
        if ($projectId === null) $projectId = $this->getProjectId();
        if ($this->includesAllReports($projectId)) {
            return $this->buildAllReports($this->getReportCatalog($projectId)['reports']);
        }
        return $this->buildConfiguredReports($this->getSubSettings(self::REPORT_CONFIG_KEY, (int)$projectId));
    }

    /**
     * @param array<int,string> $catalog report_id => title
     * @return array<int, array{report_id:int,label:?string}>
     */
    public function buildAllReports($catalog)
    {
        $reportIds = array_keys((array)$catalog);
        sort($reportIds, SORT_NUMERIC);
        return array_map(function ($reportId) {
            return ['report_id' => (int)$reportId, 'label' => null];
        }, $reportIds);
    }

    /**
     * Turn the rows returned by getSubSettings() into dashboard cards. A
     * report listed twice keeps its first label.
     *
     * @param array $entries [['dashboard-reports' => id, 'dashboard-report-label' => text], ...]
     * @return array<int, array{report_id:int,label:?string}>
     */
    public function buildConfiguredReports($entries)
    {
        $reports = [];
        foreach ((array)$entries as $entry) {
            if (!is_array($entry)) continue;
            $reportId = filter_var($entry[self::REPORT_ID_KEY] ?? null, FILTER_VALIDATE_INT);
            if (!$reportId || $reportId < 1 || isset($reports[$reportId])) continue;
            $label = trim((string)($entry['dashboard-report-label'] ?? ''));
            $reports[$reportId] = [
                'report_id' => (int)$reportId,
                'label' => $label !== '' ? mb_substr($label, 0, self::MAX_TITLE_LENGTH) : null
            ];
        }
        return array_values($reports);
    }

    /**
     * @return int[]
     */
    public function getConfiguredReportIds($projectId = null)
    {
        return array_column($this->getConfiguredReports($projectId), 'report_id');
    }

    public function isConfiguredReport($reportId, $projectId = null)
    {
        return in_array((int)$reportId, $this->getConfiguredReportIds($projectId), true);
    }

    /**
     * @return array<int, array{report_id:int,title:string}>
     */
    public function getOrderedConfiguredReports($projectId = null)
    {
        $reports = $this->getConfiguredReports($projectId);
        $byId = [];
        foreach ($reports as $report) $byId[$report['report_id']] = $report;

        $saved = (string)($this->getUserSetting(self::CARD_ORDER_KEY) ?? '');
        $ordered = [];
        foreach (array_filter(array_map('intval', explode(',', $saved))) as $reportId) {
            if (!isset($byId[$reportId])) continue;
            $ordered[] = $byId[$reportId];
            unset($byId[$reportId]);
        }
        // Newly configured reports appear at the end, in configured order.
        foreach ($reports as $report) {
            if (isset($byId[$report['report_id']])) $ordered[] = $report;
        }
        return $ordered;
    }

    /**
     * @return int[]
     */
    public function saveReportCardOrder($reportIds)
    {
        if (!is_array($reportIds)) $reportIds = [];
        $configured = array_flip($this->getConfiguredReportIds());
        $order = [];
        foreach ($reportIds as $reportId) {
            $reportId = filter_var($reportId, FILTER_VALIDATE_INT);
            if (!$reportId || !isset($configured[(int)$reportId]) || in_array((int)$reportId, $order, true)) continue;
            $order[] = (int)$reportId;
        }
        $this->setUserSetting(self::CARD_ORDER_KEY, implode(',', $order));
        return $order;
    }

    public function isDataPreviewEnabled($projectId = null)
    {
        if ($projectId === null) $projectId = $this->getProjectId();
        return !$this->isChecked($this->getProjectSetting('hide-data-preview', (int)$projectId));
    }

    public function countsOnlyWhenAsked($projectId = null)
    {
        if ($projectId === null) $projectId = $this->getProjectId();
        return $this->isChecked($this->getProjectSetting('manual-counts', (int)$projectId));
    }

    public function getMaxPreviewRows($projectId = null)
    {
        if ($projectId === null) $projectId = $this->getProjectId();
        $rows = filter_var($this->getProjectSetting('max-preview-rows', (int)$projectId), FILTER_VALIDATE_INT);
        if (!$rows || $rows < 1) return self::DEFAULT_PREVIEW_ROWS;
        return min((int)$rows, self::MAX_PREVIEW_ROWS);
    }

    /**
     * How long a viewer's stored counts are reused, in seconds; null means
     * until the viewer refreshes them.
     */
    public function getCountLifetimeSeconds($projectId = null)
    {
        if ($projectId === null) $projectId = $this->getProjectId();
        $raw = $this->getProjectSetting(self::COUNT_HOURS_KEY, (int)$projectId);
        if ($raw === null || trim((string)$raw) === '') return null;
        $hours = filter_var($raw, FILTER_VALIDATE_FLOAT);
        if ($hours === false || $hours <= 0) return null;
        return (int)round(min($hours, 24 * 365) * 3600);
    }

    /**
     * The current viewer's stored counts for the reports now on the
     * dashboard, without expired ones.
     *
     * @return array<int, array{count:int, counted_at:int, title:?string}>
     */
    public function getMyCounts($projectId = null)
    {
        if ($projectId === null) $projectId = $this->getProjectId();
        return $this->filterCounts(
            $this->getUserSetting(self::MY_COUNTS_KEY),
            $this->getConfiguredReportIds($projectId),
            $this->getCountLifetimeSeconds($projectId),
            time()
        );
    }

    /**
     * Keep well-formed entries for configured reports that have not expired.
     * Stored entries are compact: report_id => [count, counted_at, title].
     *
     * @return array<int, array{count:int, counted_at:int, title:?string}>
     */
    public function filterCounts($stored, array $reportIds, $lifetimeSeconds, $now)
    {
        $wanted = array_flip(array_map('intval', $reportIds));
        $counts = [];
        foreach ((array)$stored as $reportId => $entry) {
            $reportId = filter_var($reportId, FILTER_VALIDATE_INT);
            if (!$reportId || !isset($wanted[$reportId]) || !is_array($entry)) continue;
            $count = filter_var($entry[0] ?? null, FILTER_VALIDATE_INT);
            $countedAt = filter_var($entry[1] ?? null, FILTER_VALIDATE_INT);
            if ($count === false || $count < 0 || !$countedAt) continue;
            if ($lifetimeSeconds !== null && $countedAt <= $now - $lifetimeSeconds) continue;
            $title = isset($entry[2]) && $entry[2] !== '' ? mb_substr((string)$entry[2], 0, self::MAX_TITLE_LENGTH) : null;
            $counts[(int)$reportId] = ['count' => (int)$count, 'counted_at' => (int)$countedAt, 'title' => $title];
        }
        return $counts;
    }

    /**
     * Remember a count REDCap has just produced for the current viewer, and
     * drop entries for reports no longer on the dashboard or already expired.
     *
     * @return array{count:int, counted_at:int, title:?string}
     */
    public function saveMyCount($reportId, $count, $title, $projectId = null)
    {
        if ($projectId === null) $projectId = $this->getProjectId();
        $now = time();
        $kept = $this->filterCounts(
            $this->getUserSetting(self::MY_COUNTS_KEY),
            $this->getConfiguredReportIds($projectId),
            $this->getCountLifetimeSeconds($projectId),
            $now
        );
        $kept[(int)$reportId] = ['count' => (int)$count, 'counted_at' => $now, 'title' => $title];

        $stored = [];
        foreach ($kept as $id => $entry) $stored[$id] = [$entry['count'], $entry['counted_at'], $entry['title']];
        $this->setUserSetting(self::MY_COUNTS_KEY, $stored);
        return $kept[(int)$reportId];
    }

    private function isChecked($value)
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    /**
     * REDCap evaluates the report as the authenticated viewer, applying that
     * user's report, export, de-identification, and DAG rights, and logs it
     * as a data export.
     *
     * If the viewer cannot open the report, REDCap ends the request itself
     * (printing "ERROR") rather than throwing, so callers cannot catch that
     * case; the dashboard page recognises that response instead.
     *
     * @return array{count:int,data:array,error:?string}
     */
    public function getReportData($reportId, $includeData = true)
    {
        $reportId = filter_var($reportId, FILTER_VALIDATE_INT);
        if (!$reportId || !$this->isConfiguredReport($reportId)) {
            return ['count' => 0, 'data' => [], 'error' => self::ERROR_NOT_CONFIGURED];
        }

        try {
            // 'json' gives one flat row per record/event/instance, which is
            // what REDCap's report page counts. ('array' is keyed by record
            // and then event, so its count is records and its rows are nested.)
            $data = json_decode((string)\REDCap::getReport((int)$reportId, 'json'), true);
            if (!is_array($data)) $data = [];
            $data = array_values($data);
            return [
                'count' => count($data),
                'data' => $includeData ? $data : [],
                'error' => null
            ];
        } catch (\Throwable $e) {
            error_log(sprintf('Reports Dashboard report %d failed: %s', $reportId, $e->getMessage()));
            return ['count' => 0, 'data' => [], 'error' => self::ERROR_REPORT];
        }
    }
}
