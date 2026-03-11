# NIPGL Division Widget

A WordPress plugin that renders mobile-friendly league tables and fixtures for NIPGL bowls divisions, powered by published Google Sheets CSV data.

---

## Features

- 📱 **Mobile responsive** — sticky Pos and Team columns, compact fixture layout on small screens
- 🏆 **Promotion & relegation zones** — colour coded with ▲/▼ symbols for accessibility
- ✅ **Clinched detection** — automatic shading when promotion/relegation is mathematically confirmed
- 🏅 **Club badges** — upload logos via WordPress Media Library, mapped to team names
- 🖱 **Team modal** — click any team name to see their full record and fixture list
- 🖨 **Print views** — print button on league table, fixtures, and team modal
- 🌙 **Dark mode** — auto-follows device/OS setting with manual toggle, preference remembered per device
- 💰 **Sponsor logos** — primary sponsor above title, additional sponsors rotate randomly below table
- ⚡ **Server-side caching** — configurable cache duration to speed up page loads
- 🔄 **GitHub auto-updates** — WordPress update notifications direct from GitHub releases
- 🔍 **Team name validation** — scorecard form checks team names and fixture pairings against the division CSV, with one-click correction for swapped home/away or missing suffixes (e.g. "Belmont" → "Belmont A")

---

## Installation

1. Download the latest release zip from [Releases](https://github.com/dbinterz/nipgl-division-widget/releases)
2. In WordPress go to **Plugins → Add New → Upload Plugin**
3. Upload the zip and activate
4. Go to **Settings → NIPGL Widget** to configure badges and sponsors

---

## Usage

Add a shortcode block to any page:

```
[nipgl_division csv="YOUR_CSV_URL" title="Division 1" promote="2" relegate="2"]
```

### Shortcode Parameters

| Parameter | Description | Required |
|-----------|-------------|----------|
| `csv` | Published Google Sheets CSV URL | ✅ Yes |
| `title` | Heading shown above the widget | No |
| `promote` | Number of promotion places | No |
| `relegate` | Number of relegation places | No |
| `sponsor_img` | Override primary sponsor image for this division | No |
| `sponsor_url` | Override primary sponsor link for this division | No |
| `sponsor_name` | Override primary sponsor alt text for this division | No |

### Getting the CSV URL

1. In Google Sheets go to **File → Share → Publish to web**
2. Select the sheet, choose **CSV** format, click **Publish**
3. Copy the URL and use it as the `csv` parameter

---

## Settings

Go to **Settings → NIPGL Widget** to manage:

- **Sponsors** — add logos with links; first sponsor appears above the title, others rotate below the table
- **Club Badges** — map team names (as they appear in the sheet) to badge images
- **Cache Settings** — configure how long data is cached (default 5 minutes)
- **Plugin Updates** — force an immediate check for updates from GitHub
- **Clear Cache** — force all divisions to fetch fresh data on next load

---

## Google Sheet Structure

The plugin expects two sections in the sheet:

### League Table
A section with a `LEAGUE TABLE` header row, followed by column headers starting with `POS`, then team data rows.

### Fixtures
A section with a `FIXTURES` header row, followed by a column header row containing `HPts`, `HTeam`, `HScore`, `AScore`, `ATeam`, `APts`, then date rows and fixture rows.

---

## Scorecard Submission

The `[nipgl_submit]` shortcode adds a scorecard entry page for clubs.

### Setup

1. Go to **Settings → NIPGL Widget** and add each club with a PIN under **Club PINs**
2. Add an Anthropic API key under **API Settings** if you want AI photo parsing
3. Create a page with `[nipgl_submit]` — clubs visit this page to submit scorecards

### How it works

- Clubs log in with their club name and PIN (no WordPress account needed)
- Three entry methods: **photo** (AI reads the scorecard image), **Excel** (upload the NIPGL template), or **manual**
- First submission sets status to **Pending** — awaiting confirmation from the other club
- Second club can **Confirm** (scores agree → Confirmed ✅) or **Amend** (scores differ → Disputed ⚠️)
- League admin resolves disputes via **wp-admin → Scorecards**
- Confirmed scorecards appear when clicking a played fixture row in the league table

### Excel template

The plugin parses the standard NIPGL scorecard Excel template. Cells with unresolved formulas (total shots) are handled automatically by summing rink scores as a fallback.

---

## Changelog

### v5.17.10

* Scorecard submission now allowed even when division name is unrecognised — scorecard saves as pending, admin flagged to correct
* Admin scorecards list shows ⚠ Unresolved badge on affected rows; edit form highlights division field in red with known divisions listed
* Clearing division to a valid value in admin automatically retries Google Sheets writeback
* Meaningful save error messages — JSON parse detail (with raw preview), session expiry, and network errors surfaced clearly to the user
* Division → CSV URL mapping added to sheet tab settings table (enables team name validation)
* Team name validation on scorecard form — each team field checked against the division's team list from CSV; partial matches (e.g. "Ards") prompt selection; multiple matches from same club show a dropdown
* Fixture pairing validation — detects pairings not in the schedule, home/away swaps, and missing suffixes (e.g. "Belmont" v "Salisbury" → "Belmont A" v "Salisbury A")
* Single-click correction offered for all fixable name issues — "Correct both names", "Swap home/away", "Correct & swap"

### v5.14.0
- Google Sheets writeback — confirmed scorecards automatically write scores and points into the matching fixture row
- Division → sheet tab mapping in settings (supports multi-division sheets)
- Sheets connection test button shows spreadsheet title and available tabs
- Manual retry button in scorecard History panel
- Sheets writeback log visible alongside Drive and audit logs in History panel

### v5.13.5
- Fix: OAuth scope changed from `drive.file` to `drive` — required to access folders shared with the service account
- Clean up diagnostic test output

### v5.13.1
- Drive settings: Service Account key can now be uploaded directly through WordPress admin — no SFTP needed
- Key is validated (must be a valid service_account JSON) before saving
- Service account email displayed after upload for confirmation
- Key stored in protected `wp-content/uploads/nipgl-private/` with `.htaccess` blocking web access
- Key path persists across settings saves via hidden field

### v5.13.0
- Google Drive integration — confirmed scorecards automatically saved as PDF to Google Drive
- Original photo (where uploaded) saved alongside PDF
- Files saved into both home and away club folders
- Folder structure: Year / Division / Club
- Subfolders created automatically
- Admin edits produce versioned files (e.g. `-v2.pdf`) rather than overwriting
- Drive activity logged per scorecard, visible in admin scorecards History panel
- Settings: service account key path (loaded from server file), root folder ID, test connection button
- OAuth2 JWT auth — pure PHP, no Composer dependency
- New files: `nipgl-drive.php`, `nipgl-pdf.php`

### v5.12.0
- Admin scorecard edit — any field editable by admin: teams, date, venue, division, competition, per-rink player names, scores, totals and points
- Scorecards amended by admin show an "Amended" badge in the scorecards list
- Player appearances automatically re-logged after any admin edit
- Audit trail — every submit, confirm, resolve and admin edit is logged with timestamp, username, and a field-level before/after diff
- Audit history accessible via new 📋 History button on each scorecard row
- View/Edit/History panels replace the old single toggle — each button opens its own panel, clicking again collapses it
- New file: `nipgl-sc-admin.php`

### v5.11.1
- Fixed: season date filter was comparing against `played_at` (log time) instead of `match_date` (actual match date), causing all players to vanish when a season range was set
- Season filter now uses `STR_TO_DATE` to correctly parse dd/mm/yyyy match dates for comparison

### v5.11.0
- Player records now have Starred (⭐) and Female (♀) flags, editable inline in the Players admin tab
- Auto-migration adds columns to existing installations on first load
- Export completely rebuilt — now generates a matrix format matching the existing tracking sheet
- Summary tab: club totals (teams, players listed, ladies, % of total, % ladies)
- Per-club tabs: player × match matrix with date/team/opposition/venue/comp header rows, T/A/B/MW totals, Starred and Female columns, colour-coded appearance cells

### v5.10.4
- Fixed: Players menu was invisible — parent slug corrected from `nipgl-settings` to `nipgl-scorecards`
- Players now appears as a submenu under the Scorecards top-level menu item

### v5.10.4
- Fixed: Players page not appearing in wp-admin — menu registration moved into main `nipgl_admin_menu()` to guarantee load order
- NIPGL top-level menu now shows Scorecards and Players as submenus

### v5.10.3
- Updated plugin description in PHP header, readme.txt, and settings page to reflect all current features
- Shortcode reference in settings page now uses a table and includes all parameters including sponsor overrides
- Added `[nipgl_submit]` documentation alongside `[nipgl_division]` in the settings page

### v5.10.2
- Fixed: rink label in team modal scorecard now shows light background / dark text in light mode (was inheriting dark navy from scorecard.css)

### v5.10.1
- Fixed: team modal fixture rows now show inline scorecard when clicked
- Added missing CSS for `modal-fx-row`, `modal-sc-row`, `modal-sc-inline`, `modal-sc-hint`
- Compact scorecard layout styled for display inside the modal fixture table

### v5.10.0
- Scorecard display in fixture modal — clicking a played fixture now loads the full rink-by-rink scorecard
- Fixed script load order — nipgl-scorecard.js now correctly loads before nipgl-widget.js on all pages with [nipgl_division]
- Switched to semantic versioning (MAJOR.MINOR.PATCH)

### v5.9
- Player tracking system — appearances auto-logged from confirmed/admin-resolved scorecards
- Players grouped by club, showing which teams they've played for and appearance count
- Season date range configuration — counts scoped to current season
- Admin merge tool for duplicate player names (freetext scorecard names)
- Manual add/rename/delete players
- Export to Excel — summary sheet plus one sheet per club with full appearance log

### v5.8
- Admin scorecards page rebuilt — status badges, inline scorecard view, side-by-side dispute comparison
- Admin can accept Version A or Version B to resolve a disputed result
- Disputed scorecard shows which club submitted each version

### v5.7
- Fixed fixture modal not finding submitted scorecards
- Match key now uses home + away team only — date formats differ between CSV and form input

### v5.6
- Fixed Excel parser returning empty grid on PHP 8.3 due to PCRE lookahead inconsistency
- Replaced regex cell matching with `explode('</c>', ...)` approach — robust across all PHP versions

### v5.5
- Added `nipgl-debug.html` diagnostic tool for testing Excel parser directly in browser

### v5.4
- Fixed Excel date serial numbers — now displayed as dd/mm/yyyy instead of raw number
- Fixed mapper not detecting Rink headers in column D (new template layout)
- Fixed away player names not resolving from shared string table

### v5.3
- Per-club PIN authentication — each club has its own PIN set in plugin settings
- Two-party verification — second club can confirm or amend a submitted scorecard
- Scorecard statuses: Pending / Confirmed / Disputed
- Pending scorecards shown to the other club on login
- Session-based club authentication

### v5.2
- Fixed AI photo parsing — corrected model name causing silent 400 error
- Improved API error surfacing — actual error message now shown on failure

### v5.1
- Full scorecard submission system — `[nipgl_submit]` shortcode
- PIN-gated entry (no WordPress login needed)
- AI photo reading (Anthropic API) pre-fills form from photo
- Excel upload parsing for NIPGL scorecard template
- Manual entry form with 4 rinks and player names
- Played fixtures clickable in modal — shows full rink-by-rink scorecard
- Scorecards admin page

### v5.0
- Club-level badge configuration — configure a badge once for a club, matches all teams with that prefix
- Exact team badge still supported, always takes priority over club prefix
- Badge admin UI updated with Type column (Club prefix / Exact)

### v4.9
- Fixed modal header and buttons clipped in Brave browser
- Replaced `inset:0` with explicit positioning for cross-browser compatibility
- Modal now top-anchored with padding rather than vertically centred, preventing clipping on non-standard viewports

### v4.8
- Fixed print speed — removed Google Fonts load from print window, dialog now appears immediately
- Fixed modal print badge too large — constrained with !important overrides
- Fixed modal print stats appearing vertically — switched from flex to inline-block for cross-browser print compatibility

### v4.7
- Fixed modal window appearing transparent
- Fixed league table columns bleeding behind sticky columns on mobile scroll
- Fixed fixtures print preview not generating on mobile
- Dark mode refactored to use `:root` CSS variables

### v4.6
- Dark mode — auto follows device/OS, manual toggle button, preference remembered per device
- Printer icon replaced with SVG (renders correctly on all mobile browsers)
- Team name added to modal header alongside badge
- Print layout fixed — logos constrained to sensible sizes
- Print button added to league table and fixtures tabs
- Accessibility — promotion/relegation zones show ▲/▼ symbols alongside colour
- Modal results show W/D/L label alongside colour coding

### v4.5
- Team modal — click any team name in league table or fixtures to see full record and fixture list
- Print button in modal opens clean print-friendly view

### v4.4
- Fixed Check for Updates Now button not appearing on settings page

### v4.3
- Sponsor logos — primary sponsor above title, additional sponsors rotate randomly below table
- Per-division sponsor override via shortcode parameters

### v4.2
- Version number defined as single constant — only one place to update per release

### v4.1
- Added Check for Updates Now button to settings page

### v4.0
- Added GitHub auto-updater

### v3.1
- Font updated to Saira throughout

### v3.0
- Promotion/relegation zones with clinched shading
- Server-side caching with configurable duration and manual clear
- Club badges via Media Library

---

## License

GPLv2 or later
