# Inspection Test Checklist

Run these checks in a non-production REDCap instance before submitting this
module to the REDCap Repo.

## Automated checks

1. Run `php -l` on `ReportsDashboard.php` and both files in `pages/`.
2. Run the PHPUnit tests in `tests/` from the module directory, for example
   `php <redcap-root>/redcap_v<version>/UnitTests/vendor/bin/phpunit tests/ReportsDashboardTest.php`.
3. Run **Control Center → External Modules → Manage → Module Security
   Scanning** and resolve every finding.

## Manual checks

1. Enable the module and confirm the configuration shows **Reports shown on the
   dashboard** with a Report and a Card label in each entry.
2. Add two reports, one with a label, then confirm only those two cards appear:
   one shows the label straight away, the other shows "Report" and then the
   report's title once counted. No card shows a report number.
3. Check **Show every report in this project**: confirm there is one card per
   report and the "as of" date is shown. Add a report in REDCap, confirm it is
   missing, open and close the module configuration, and confirm it appears.
   Uncheck it and confirm the manual list is used again.
4. Confirm an unselected report ID returns an error from `report_data.php`, and
   that its title is not in any response.
5. Confirm a role not selected in **Roles allowed** cannot load the menu page
   or the endpoint, and a selected role can load both.
6. Confirm a user with Project Design & Setup rights can load both.
7. As a dashboard user who cannot open one of the selected reports in REDCap,
   confirm that card says "You do not have access to this report in REDCap",
   keeps the generic "Report" heading (its title never appears, including in the
   page source and network responses), and the other cards still load.
8. Compare a card's count with the "total number of results" on REDCap's report
   page for a user with full export rights.
9. Check **Turn off data previews**: confirm the preview button disappears and
   the endpoint refuses `action=preview`.
10. Check **Count reports only when asked**: confirm cards show a Count button and
   nothing is counted until it is clicked.
11. Confirm each count and preview adds a data-export entry to the project's
    logging.
12. Reload the dashboard: confirm stored counts and their "Counted" times
    appear without new data-export log entries, **Refresh** on one card
    recounts only that card, and **Refresh all** recounts every card. Log in as
    a second user and confirm they do not see the first user's counts. Set
    **Keep each user's counts for (hours)** to a small value (for example
    0.01), wait, reload, and confirm the expired counts are redone.
13. Confirm a Data Access Group user sees the count REDCap returns for that
    user.
14. After a card is counted, confirm it shows **Open report**, that the link
    opens that report's REDCap page in a new tab, and that cards the viewer
    cannot open (or has not counted yet) have no link.
15. Confirm drag-and-drop order persists only for the user and project that
    saved it, and that a failed save puts the cards back.
