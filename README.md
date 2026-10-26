# Reports Dashboard

Reports Dashboard is a REDCap External Module that shows project reports chosen
by project designers, or every report in the project, as cards. Each card shows the report's row count and, when
enabled, a small preview of its first rows, both produced by REDCap for the
current user. Users can drag the cards into their own order.

## Requirements

- REDCap 17.0.1 or later (External Module Framework version 17)
- PHP 7.4 or later

## Configuration

In the project's External Module configuration (users with Project Design &
Setup rights):

1. Either check **Show every report in this project**, or under **Reports shown
   on the dashboard** add one entry per report. Each entry has a **Report** and
   an optional **Card label**.
2. Optionally choose **Roles allowed to see the Reports Dashboard link**.
   Administrators and users with Project Design & Setup rights always have
   access.
3. Optionally check **Count reports only when asked** or **Turn off data
   previews**, set **Keep each user's counts for (hours)**, or set **Maximum
   preview rows** (1–100, default 5).

### The report list and titles

The External Module Framework has no documented way for a module to list a
project's reports or read their titles. It does fill the report-list setting
with every report and its title when someone opens this module's configuration,
and it passes that to the documented `redcap_module_configuration_settings`
hook. The module saves that list (report numbers and titles) each time the
configuration is opened. So:

- **Show every report** includes the reports that existed when the
  configuration was last opened. After adding, renaming or deleting reports, a
  user with Project Design & Setup rights should open and close the
  configuration. The dashboard says when the list was last loaded.
- Titles come from the same list. A report that is newer than the list shows
  as "Report" until the configuration is opened again.

## How counts and previews work

### Stored counts

Each user's counts are stored for that user on that project, and the dashboard
shows them again the next time they open it, with the time each was counted.
Only cards the user has never counted are counted on opening (or none, with
**Count reports only when asked**). **Refresh** on a card recounts that report,
and **Refresh all** recounts every card one at a time.

Counts are never shared between users, because REDCap counts with each viewer's
own rights: two users can rightly get different numbers for the same report.
A stored count is a snapshot. It does not change when records or the report
change, or when the user's access changes, until it is refreshed. Set **Keep
each user's counts for (hours)** to have counts older than that redone
automatically.

Each count and each preview calls REDCap's documented report export,
`REDCap::getReport()`, as the user viewing the dashboard. That has some
consequences worth knowing:

- **REDCap applies the viewer's own rights.** Report access, data export rights,
  de-identification and Data Access Group restrictions all apply. A card only
  works for users who can open that report in REDCap; anyone else sees "You do
  not have access to this report in REDCap."
- **The count is what the viewer may export.** It is the number of rows (one per
  record, event and repeating instance) in the viewer's export of the report.
  For users whose export rights exclude some instruments, it can differ from the
  count on REDCap's own report page.
- **Every count and preview is logged as a data export** in the project's
  logging, exactly as if the user had exported the report. Opening the dashboard
  therefore adds one logging entry per card. On busy or large projects, check
  **Count reports only when asked** so cards are counted only when a user clicks
  **Count**.
- **Counts run one card at a time**, and each one runs the whole report. On very
  large reports a count or preview can take as long as opening the report in
  REDCap.

Previews show the first five columns of the first rows (up to the configured
maximum) and are refused by the server when previews are turned off.

## Access and privacy

The menu link, the page and its data endpoint all apply the same access rule:
administrators, users with Project Design & Setup rights, and users whose role
is selected in the configuration. The endpoint accepts only report IDs that are
selected in the configuration.

Report numbers are not shown on cards. A card shows its report's **title only
after REDCap has run the report for the viewer**: the title travels with the
count (or preview) response, and REDCap ends that request before it is sent if
the viewer cannot open the report. Until then, and for viewers without access,
the card just says "Report". With **Count reports only when asked**, titles
appear as each card is counted. **Card labels are different: they are shown to
every dashboard user straight away**, so don't put anything in a label that
some of them should not see.

### Report links

Once REDCap has counted a report for the viewer, its card also gets an **Open
report** link that opens REDCap's own report page in a new tab. Like titles,
links are only sent to viewers REDCap let run the report.

The External Module Framework has no method that returns the address of a
core REDCap page, so the module builds the link from its own `getUrl()`
address. `getUrl()` is documented to return
`.../redcap_vX.X.X/ExternalModules/?prefix=...`, and the report page is in the
same versioned directory: `.../redcap_vX.X.X/DataExport/index.php?pid=…&report_id=…`.
Only the path is used, so links work behind a reverse proxy. If `getUrl()`
returns any other shape (for example on servers that serve modules from
`/external_modules/`), cards simply have no link.

## Stored data

The module stores its project configuration, the project's report list as last
seen by the configuration dialog (a project setting named `report-catalog`:
report numbers, titles and the time it was saved), and two user settings, which REDCap keeps in that user's own interface state
for the project: the card order (`report-card-order`) and the user's stored
counts (`my-report-counts`: report number, count, time counted, and the title
of a report REDCap has already run for that user). Like any project setting,
`report-catalog` can be read and changed by users with Project Design & Setup
rights; it holds nothing they cannot already see. It makes no outbound network calls and
does not write REDCap record data.

## Installation

Install **Reports Dashboard** from the REDCap Repo (Control Center → External Modules →
Manage → View modules available in the REDCap Repo), enable it in the Control Center and then
on each project that should have the dashboard, and configure it as described above.

## Testing

PHPUnit tests are in `tests/`; the manual checklist is in `TESTING.md`.

## License

MIT. See [LICENSE](LICENSE).
