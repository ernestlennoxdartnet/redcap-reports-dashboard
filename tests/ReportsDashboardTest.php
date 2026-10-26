<?php

namespace DARTNet\ReportsDashboard;

// tests/ -> module directory -> modules/ -> REDCap root.
require_once __DIR__ . '/../../../redcap_connect.php';

class ReportsDashboardTest extends \ExternalModules\ModuleBaseTest
{
    const PID = 12345;

    private static function entries(array $reportIds, array $labels = [])
    {
        // The row shape getSubSettings() returns for the dashboard-report-config setting.
        // (getSubSettings() itself reads the database, not PHPUnit's in-memory settings.)
        $rows = [];
        foreach ($reportIds as $i => $reportId) {
            $rows[] = ['dashboard-reports' => $reportId, 'dashboard-report-label' => $labels[$i] ?? null];
        }
        return $rows;
    }

    public function testConfiguredReportIdsAreUniquePositiveIntegers()
    {
        $reports = $this->module->buildConfiguredReports(self::entries(['12', 'not-an-id', '12', '0', '-3', null, '34']));
        $this->assertSame([12, 34], array_column($reports, 'report_id'));
    }

    public function testNoReportsWhenNothingIsConfigured()
    {
        $this->assertSame([], $this->module->buildConfiguredReports([]));
        $this->assertSame([], $this->module->buildConfiguredReports(null));
        $this->assertSame([], $this->module->getConfiguredReportIds(67890));
    }

    public function testLabelsAreOptionalAndFirstLabelWins()
    {
        $this->assertSame([
            ['report_id' => 12, 'label' => 'Enrolled'],
            ['report_id' => 34, 'label' => null],
        ], $this->module->buildConfiguredReports(self::entries(['12', '34', '12'], ['  Enrolled  ', '', 'Ignored duplicate'])));
    }

    /** The settings shape the configuration dialog passes to redcap_module_configuration_settings(). */
    private static function dialogSettings(array $choices)
    {
        return [
            ['key' => 'allowed-roles', 'type' => 'user-role-list', 'choices' => [['value' => '9', 'name' => 'Not a report']]],
            ['key' => 'dashboard-report-config', 'type' => 'sub_settings', 'sub_settings' => [
                ['key' => 'dashboard-reports', 'type' => 'report-list', 'choices' => $choices],
                ['key' => 'dashboard-report-label', 'type' => 'text'],
            ]],
        ];
    }

    public function testCatalogComesFromTheReportListChoicesAsPlainText()
    {
        $catalog = $this->module->buildCatalog(self::dialogSettings([
            ['value' => '1228', 'name' => 'Visits &amp; &quot;labs&quot;'],
            ['value' => 'x', 'name' => 'ignored'],
            ['value' => '1227', 'name' => '  Enrollment  '],
        ]));
        $this->assertSame([1228 => 'Visits & "labs"', 1227 => 'Enrollment'], $catalog);
    }

    public function testCatalogIsNullWhenTheChoicesAreMissing()
    {
        $settings = self::dialogSettings([]);
        unset($settings[1]['sub_settings'][0]['choices']);
        $this->assertNull($this->module->buildCatalog($settings));
        $this->assertNull($this->module->buildCatalog([]));
        $this->assertSame([], $this->module->buildCatalog(self::dialogSettings([])));
    }

    public function testConfigurationHookSavesTheCatalogAndReturnsSettingsUnchanged()
    {
        $settings = self::dialogSettings([['value' => '5', 'name' => 'Five'], ['value' => '3', 'name' => 'Three']]);
        $this->assertSame($settings, $this->module->redcap_module_configuration_settings(self::PID, $settings));
        $catalog = $this->module->getReportCatalog(self::PID);
        $this->assertSame([5 => 'Five', 3 => 'Three'], $catalog['reports']);
        $this->assertGreaterThan(0, $catalog['updated_at']);
        $this->assertSame('Three', $this->module->getReportTitle(3, self::PID));
        $this->assertNull($this->module->getReportTitle(4, self::PID));
    }

    public function testShowEveryReportUsesTheCatalogInReportOrder()
    {
        $this->module->redcap_module_configuration_settings(self::PID, self::dialogSettings([
            ['value' => '5', 'name' => 'Five'], ['value' => '3', 'name' => 'Three'],
        ]));
        $this->assertFalse($this->module->includesAllReports(self::PID));
        $this->module->setProjectSetting('include-all-reports', true, self::PID);
        $this->assertTrue($this->module->includesAllReports(self::PID));
        $this->assertSame([
            ['report_id' => 3, 'label' => null],
            ['report_id' => 5, 'label' => null],
        ], $this->module->getConfiguredReports(self::PID));
        $this->assertTrue($this->module->isConfiguredReport(5, self::PID));
        $this->assertFalse($this->module->isConfiguredReport(4, self::PID));
    }

    public function testPreviewIsOnByDefaultAndCanBeTurnedOff()
    {
        $this->assertTrue($this->module->isDataPreviewEnabled(self::PID));
        $this->module->setProjectSetting('hide-data-preview', true, self::PID);
        $this->assertFalse($this->module->isDataPreviewEnabled(self::PID));
        $this->module->setProjectSetting('hide-data-preview', false, self::PID);
        $this->assertTrue($this->module->isDataPreviewEnabled(self::PID));
    }

    public function testMaxPreviewRowsIsClampedAndDefaults()
    {
        $this->assertSame(5, $this->module->getMaxPreviewRows(self::PID));
        foreach (['0' => 5, '-2' => 5, 'abc' => 5, '7' => 7, '500' => 100] as $stored => $expected) {
            $this->module->setProjectSetting('max-preview-rows', (string)$stored, self::PID);
            $this->assertSame($expected, $this->module->getMaxPreviewRows(self::PID), "stored '$stored'");
        }
    }

    public function testCountsAreAutomaticUnlessManualIsChecked()
    {
        $this->assertFalse($this->module->countsOnlyWhenAsked(self::PID));
        $this->module->setProjectSetting('manual-counts', true, self::PID);
        $this->assertTrue($this->module->countsOnlyWhenAsked(self::PID));
    }

    public function testStoredCountsKeepOnlyValidUnexpiredConfiguredEntries()
    {
        $now = 1000000;
        $stored = [
            '12' => [7, $now - 50, 'Enrollment'],
            '34' => [0, $now - 5000, null],      // expires with a one-hour lifetime
            '56' => [3, $now - 10, 'Not on the dashboard'],
            '78' => ['x', $now, 'bad count'],
            '90' => [-1, $now, 'negative'],
            'no' => [1, $now, 'bad id'],
            '91' => 'not an array',
        ];
        $this->assertSame([
            12 => ['count' => 7, 'counted_at' => $now - 50, 'title' => 'Enrollment'],
            34 => ['count' => 0, 'counted_at' => $now - 5000, 'title' => null],
        ], $this->module->filterCounts($stored, [12, 34, 78, 90, 91], null, $now));
        $this->assertSame([12], array_keys($this->module->filterCounts($stored, [12, 34], 3600, $now)));
        $this->assertSame([], $this->module->filterCounts(null, [12], null, $now));
    }

    public function testCountLifetimeIsBlankOrPositiveHours()
    {
        $this->assertNull($this->module->getCountLifetimeSeconds(self::PID));
        foreach (['' => null, '0' => null, '-4' => null, 'abc' => null, '24' => 86400, '0.5' => 1800] as $stored => $expected) {
            $this->module->setProjectSetting('count-cache-hours', (string)$stored, self::PID);
            $this->assertSame($expected, $this->module->getCountLifetimeSeconds(self::PID), "stored '$stored'");
        }
    }

    public function testReportLinksAreBuiltFromTheDocumentedGetUrlForm()
    {
        $cases = [
            // getUrl() as documented, absolute (host dropped)
            'https://example.com/redcap/redcap_v17.5.6/ExternalModules/?prefix=x&page=pages%2Fdashboard&pid=45'
                => '/redcap/redcap_v17.5.6/DataExport/index.php?pid=45&report_id=1228',
            // REDCap at the web root, and an explicit index.php
            'http://localhost:8080/redcap_v17.5.6/ExternalModules/index.php?prefix=x&pid=45'
                => '/redcap_v17.5.6/DataExport/index.php?pid=45&report_id=1228',
            // already relative
            '/redcap_v14.6.4/ExternalModules/?prefix=x'
                => '/redcap_v14.6.4/DataExport/index.php?pid=45&report_id=1228',
            // shapes that are not recognised get no link
            'https://example.com/redcap/external_modules/?prefix=x&pid=45' => null,
            'https://example.com/redcap/api/?type=module&prefix=x&page=pages%2Fdashboard' => null,
            'https://example.com/redcap/redcap_v17.5.6/ExternalModules/manager/project.php' => null,
            'https://example.com/redcap/redcap_vX/ExternalModules/?prefix=x' => null,
            'not a url' => null,
            '' => null,
        ];
        foreach ($cases as $moduleUrl => $expected) {
            $this->assertSame($expected, $this->module->buildReportPageUrl($moduleUrl, 45, 1228), $moduleUrl);
        }
    }

    public function testReportLinksNeedPositiveIds()
    {
        $url = '/redcap_v17.5.6/ExternalModules/?prefix=x';
        $this->assertNull($this->module->buildReportPageUrl($url, 0, 1228));
        $this->assertNull($this->module->buildReportPageUrl($url, 45, -1));
        $this->assertNull($this->module->buildReportPageUrl($url, '45 OR 1', 1228));
        $this->assertSame('/redcap_v17.5.6/DataExport/index.php?pid=45&report_id=7', $this->module->buildReportPageUrl($url, '45', '7'));
    }

    public function testReportsThatAreNotConfiguredAreRefusedWithoutRunningThem()
    {
        // Nothing is configured for this project, so report 99 must be refused.
        $this->module->setProjectId(self::PID);
        $result = $this->module->getReportData(99, true);
        $this->assertSame(ReportsDashboard::ERROR_NOT_CONFIGURED, $result['error']);
        $this->assertSame([], $result['data']);
    }
}
