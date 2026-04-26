# League Game Widget

A WordPress plugin that renders mobile-friendly league tables and fixtures for lawn bowls and other league sports, powered by published Google Sheets CSV data.

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

1. Download the latest release zip from [Releases](https://github.com/dbinterz/lgw-division-widget/releases)
2. In WordPress go to **Plugins → Add New → Upload Plugin**
3. Upload the zip and activate
4. Go to **Settings → LGW Widget** to configure badges and sponsors

---

## Usage

Add a shortcode block to any page:

```
[lgw_division csv="YOUR_CSV_URL" title="Division 1" promote="2" relegate="2"]
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

Go to **Settings → LGW Widget** to manage:

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

The `[lgw_submit]` shortcode adds a scorecard entry page for clubs.

### Setup

1. Go to **Settings → LGW Widget** and add each club with a passphrase under **Score Entry** — the [what3words](https://what3words.com) address for the clubhouse makes a good default (e.g. `filled.count.ripen`)
2. Add an Anthropic API key under **API Settings** if you want AI photo parsing
3. Create a page with `[lgw_submit]` — clubs visit this page to submit scorecards

### How it works

- Clubs log in with their club name and passphrase (no WordPress account needed) — passphrase entry is case-insensitive
- Three entry methods: **photo** (AI reads the scorecard image), **Excel** (upload the LGW template), or **manual**
- First submission sets status to **Pending** — awaiting confirmation from the other club
- Second club can **Confirm** (scores agree → Confirmed ✅) or **Amend** (scores differ → Disputed ⚠️)
- League admin resolves disputes via **wp-admin → Scorecards**
- Confirmed scorecards appear when clicking a played fixture row in the league table

### Excel template

The plugin parses the standard LGW scorecard Excel template. Cells with unresolved formulas (total shots) are handled automatically by summing rink scores as a fallback.

---

## Changelog

### v7.1.112
- **Fix:** Player stats popup now correctly resolves players with apostrophes in their names (e.g. `K O'Neill`) — WordPress magic-quotes were stripping the apostrophe before the DB lookup; fixed with `wp_unslash()` wrapping all relevant `$_POST` reads in `lgw_ajax_get_player_stats`, `add_player`, and `rename_player` handlers
- **Fix:** Stats lookup now passes the name through `lgw_clean_player_name()` to strip any trailing `*` female marker before querying, preventing lookup failures for female-flagged players

### v7.1.111
- **New:** Players admin screen: Club filter (defaults to All Clubs), cascading Team filter dropdown, and Name search — all live client-side with match count and Clear button

### v7.1.110
- **New:** Player stats popover is draggable — a grab-handle bar at the top lets users reposition it freely by mouse or touch; once moved, automatic positioning is suppressed until the popover is closed and reopened

### v7.1.109
- **New:** Player stats popover now includes a full games list for the current season — each row shows the match title, date, rink number, score, and a colour-coded W/D/L badge, ordered newest first; cup games tagged with a type pill
- **Fix:** Popover switched to `position:fixed` with viewport-aware placement — flips above the button when space below is insufficient; inner body is `overflow-y:auto` with a dynamically calculated `max-height` so it never goes off-screen

### v7.1.108
- **New:** Player name links in scorecard modal — clicking a player's name opens a stats popover showing their current-season W/D/L record, total games played, and which teams they have appeared for this season
- **New:** Public AJAX endpoint `lgw_get_player_stats` — returns current-season stats by player name and club, nonce-protected, no authentication required
- **CSS:** Player stats popover with club badge, colour-coded W/D/L tiles (green/amber/red), played total tile, teams-this-season chips; full dark-mode support

### v7.1.107
- **Fix:** Division name missing in scorecard modal after shortcode title change — `divisionTitle` now read from `data-division` attribute instead of `previousElementSibling` (ticker insertion had broken the sibling lookup)

### v7.1.106
- **New:** CSV reference row support — parser detects `homepts`/`home`/`home shots`/`away shots`/`away`/`awaypts`/`time` labels and maps columns directly; time read from explicit index, no scanning
- **Fix:** Legacy fallback (no reference row) breaks on first time match and uses narrowed serial range

### v7.1.103
- **Fix:** Results ticker now shows only scores for the current division and current season; hidden if no matching results
- **Fix:** Ticker positioned inside the widget wrap, below the sponsor banner, full-width and inline with the rest of the widget
- **New:** Added `data-division` attribute to widget element for division-scoped result filtering

### v7.1.101
- **Fix:** Scorecards admin season backfill now correctly reassigns cards tagged to the wrong season (not just untagged ones) — banner appears on all seasons that have date ranges configured, counts scorecards whose match date falls in the season but carry a different season tag, and 'Reassign to this season' button retags them all via date-range matching

### v7.1.100
- **Fix:** Scorecards admin season filter no longer shows previous-season cards in the active season view — the `NOT EXISTS` fallback was pulling in untagged cards from any/all seasons; removed from the main query; untagged card count now comes from a separate dedicated count query; warning banner and backfill button still appear on the active season view

### v7.1.99
- **New:** Scorecards admin page now splits by season — season switcher bar defaults to the active season; archived seasons accessible via pill buttons; list filtered by `lgw_sc_season` meta; active season also shows untagged cards so nothing is accidentally hidden; untagged card warning banner with one-click "Tag all to this season" backfill button; new `lgw_backfill_sc_seasons` AJAX handler uses dual-strategy (tag + date-range fallback)

### v7.1.98
- **New:** Player tracking auto-merges dotted-initial name variants — `lgw_normalise_player_name()` strips dots from single-letter initials (`D. Bintley` → `D Bintley`) before DB lookup; new scorecards never create duplicates; Merge Duplicates tab shows a detected-pairs preview table with a one-click Auto-merge button; keep rule: most appearances wins, ties prefer the already-normalised (no-dot) form

### v7.1.97
- **Fix:** "Skip Google writeback" checkbox now also suppresses Google Drive PDF upload — uses a `lgw_skip_google` post meta flag so Drive's anonymous action hooks are correctly bypassed; checkbox label updated to "Skip Google Drive & Sheets writeback"

### v7.1.96
- **Improvement:** Excel/xlsx parse errors now return actionable diagnostic messages — ZipArchive error codes, missing worksheet details, empty grid diagnostics (sheet name, size, shared string count), and rink-mapping failures include row samples and field detection summary

### v7.1.95
- **New:** Admin scorecard form now includes a "Skip Google Drive & Sheets writeback" checkbox (visible to admins only); use when backfilling historical scorecards to avoid overwriting the live sheet

### v7.1.94
- **Feature:** Player history modal — each appearance row now shows the scorecard ID as a `#NNN ↗` link directly to the WP admin edit screen (opens in new tab), making it easy to inspect, edit or trash test/duplicate scorecards

### v7.1.93
- **Fix:** Backfill missed scorecards tagged to a different/wrong season ID — date-range strategy now scans ALL scorecards (not just untagged ones); match date is the authority, season tag is supplementary

### v7.1.92
- **Fix:** Backfill not picking up untagged scorecards for previous seasons — added date-range fallback scanning scorecards with no `lgw_sc_season` meta against the season's start/end dates

### v7.1.91
- **Fix:** Player stats not recorded when re-saving a scorecard via admin edit — rink scores were stored as `0.0` (not `null`) for empty fields, causing false 0–0 draws; now stored as `null` when blank
- **Fix:** `lgw_log_appearances()` zero-guard — legacy scorecards where all rink scores were `0` (floatval artifact) are treated as score-absent; real 0-scores still honoured when match totals are non-zero
- **Fix:** `lgw_sc_context` (league/cup) now preserved correctly on admin scorecard edits; missing context defaulted to `league` rather than empty string

### v7.1.90
- **Feature:** Player statistics — Wins, Draws, Losses, Shots For and Shots Against now tracked per appearance (rink level) for both League and Cup games
- **Feature:** Admin player list table gains W/D/L, SF–SA, League W/D/L and Cup W/D/L columns per player for the current season view
- **Feature:** Player history modal upgraded — stats summary table (Total / League / Cup) at top; per-game rows show rink score, coloured W/D/L badge, Cup label badge, and full match score
- **Feature:** Excel export gains a new **Stats** sheet with full per-player breakdown; per-club matrix sheets gain W/D/L, SF and SA columns
- **Improvement:** DB migration auto-adds `shots_for`, `shots_against`, `result`, `game_type` columns to existing installations; `game_type` back-filled from scorecard context meta
- **Improvement:** `lgw_log_appearances()` now reads rink-level scores and `lgw_sc_context` meta to store all stats atomically with each appearance row

### v7.1.87
- **Fix:** Fixture time note (e.g. 5:30) now correctly displayed for all divisions; scan range extended past APts column and `HH:MM:SS` format normalised to `HH:MM`

### v7.1.86
- **Fix:** Player tracking — female status from confirmed scorecards (asterisk-marked players) now correctly saved to player record; new `lgw_ensure_female_flag()` upgrades `false→true` only, never resets manual edits
- **Fix:** Player tracking — toggling the female checkbox no longer incorrectly sets the starred flag; `update_flags` handler now reads actual submitted field values instead of `isset()` check
- **Feature:** Player tracking — new **Club Summary** tab with per-club player count, appearances, ladies, and admin-editable Players Paid field with balance (paid − played); exportable as XLS spreadsheet or print-ready PDF

### v7.1.84
- **Feature:** Championship — Rename Entry tool on the edit page lets you correct spelling mistakes in entries after a draw has been done, without resetting the draw or any scores

### v7.1.82
- **Fix:** Live points hint in scorecard modal used `parseInt` — half-point values (e.g. 2.5 + 4.5) showed total as 6 instead of 7; fixed to `parseFloat` with tolerance comparison

### v7.1.81
- **Fix:** Rink score inputs (modal and standalone form) now have `step="0.5"` so browsers accept half-scores without rounding
- **Fix:** Auto-sum of rink scores rounds to 1 decimal to prevent float accumulation noise
- **Fix:** Scorecard admin page stripped half-points — all scores, totals and points now use `floatval`; admin number inputs gain `step="0.5"`
- **Fix:** Points validation uses `parseFloat` and tolerance comparison throughout

### v7.1.80
- **Fix:** Drive upload now respects `submitted_for` — PDF saved to that team's folder only when submitting for one team
- **Fix:** Resubmitting a scorecard replaces the existing PDF in Drive rather than creating a duplicate; admin edits still produce versioned copies

### v7.1.79
- **Feature:** Sponsor logo now appears bottom-right in the print/PDF output for both cup and championship draws
- **Fix:** Cup print layout on desktop Chrome/Chromebook now uses the same spreadsheet-style layout as championship — R0/R1 as side-by-side columns, later rounds compact below — eliminating clipped or missing matches in Chrome print preview

### v7.1.78
- **Feature:** Cup and Championship bracket draws on mobile now support horizontal swipe scrolling — all rounds sit side-by-side with `scroll-snap` for smooth swiping between them
- **Feature:** Tapping a round header in the bracket scrolls forward to the next round (wraps to first); the tab bar stays in sync as you swipe via `IntersectionObserver`

### v7.1.77
- **Feature:** Championship bracket draws now show potential opponents in TBD slots — displays the last player's surname and abbreviated club name (e.g. `Hinds, Sha/Maxwell, Nor`) matching the cup bracket style

### v7.1.76
- **Feature:** Championship draws now enforce strict same-club separation — a multi-pass algorithm guarantees players from the same club are never drawn against each other in the first round (graceful fallback only when separation is mathematically impossible)
- **Feature:** Admin draw editor — after a section is drawn, an **✏️ Edit Draw** button on the admin edit page reveals a bracket table; any first-round match participant can be swapped via dropdown; saving clears the match score and cascades resets through all downstream rounds, and unseeds the Final Stage so it can be redrawn

### v7.1.73
- Scorecard photo upload on mobile now prompts the user to choose between 📷 Take a photo (camera) or 🖼️ Choose from gallery / files instead of immediately launching the camera — desktop behaviour (file picker) unchanged

### v7.1.72
- **Settings:** Merged "Clubs & Passphrases" and "Club Badges" into a single "Clubs & Badges" table — passphrase and badge fields now on one row per club

### v7.1.71
- **Fix:** Duplicate season switcher bar removed from Player Tracking admin

### v7.1.70
- **Feature:** Archived seasons now support start/end date fields — set via the Seasons admin edit or Add Historical Season forms
- **Feature:** Each archived season row has a **👥 Players** link (opens Player Tracking filtered to that season) and a **🔄 Backfill Players** button (re-logs appearances for all confirmed scorecards tagged to that season)
- **Feature:** Player Tracking admin accepts `?season=ID` URL param — all appearance counts, the export, and the Season Settings tab reflect that season's date range
- **Feature:** Season switcher bar added above the Players tabs — pill buttons for every season, active season marked with ●
- **Feature:** Page title shows the archived season name when viewing one (e.g. "Player Tracking — 2025 Season")
- **Feature:** Export to Excel passes the season ID through so the downloaded file matches what is on screen

### v7.1.69
- **Feature:** Season start/end dates moved to Seasons admin — label, dates, and divisions now all managed in one place
- **Change:** `lgw_get_season()` reads from the active season in `lgw_seasons`; legacy `lgw_season` option used as fallback for existing installs
- **Change:** Player Tracking "Season Settings" tab is now a read-only summary with a link to Seasons admin

### v7.1.68
- **Fix:** Sheets writeback (`lgw_sheets_write_result`) now finds the fixture row even when the match was played on a rescheduled date — tries the fixture date first, then falls back to matching by team names only; logs a note when the fallback is used
- **Fix:** Override key now uses the fixture date read directly from the published CSV (by matching the home/away team pair in the fixture list), not the played date on the scorecard — fixes cases where a match was rescheduled

### v7.1.66
- **Fix:** Confirmed scorecards now update the widget score immediately — `lgw_sync_override_from_scorecard()` was silently bailing when the division had no `csv_url` in `sheets_tabs`; now falls back to the active season division config
- **Fix:** `lgw_sync_override_from_scorecard()` now logs success/failure to the per-scorecard sheets log; visible in the History panel
- **Feature:** "Force sync widget override" button added to every scorecard's History panel
- **Fix:** Deleting or trashing a scorecard removes all associated player appearance records and prunes orphaned player entries
- **Feature:** Player names on the Player Tracking admin page are now clickable — opens a modal showing every game the player appeared in

### v7.1.52
- **Fix:** Season switcher now matches archived divisions to the shortcode `title` even when the title includes a trailing year
- **Fix:** Seasons admin — editing an existing archived season no longer triggers "season already exists" error
- **New:** Cup admin: "Download Draw (.xlsx)" export button on cup edit page
- **New:** Championship admin: "Download Draw (.xlsx)" export button on championship edit page
- **New:** `lgw-export.php` — pure-PHP xlsx generation via ZipArchive, no server dependencies

### v7.1.48
- **Fix/Cleanup:** Consolidated duplicate `lgwClubMatchesTeamStr` into `lgwClubMatchesTeam` — null guard added, all call sites updated
- **Fix:** Scorecard modal: Date Played field now displays in the same format as the fixture date after blur; normalised back to dd/mm/yyyy on save

### v7.1.27
- Multi-season archive and front-end season switcher
- New Seasons admin page: manage active season, archive, backload historical seasons
- `[lgw_division seasons="2025,2024"]` or `seasons="all"` dropdown to switch between seasons
- Scorecards stamped with `lgw_sc_season` post meta; archive back-fills untagged scorecards
- New file: `lgw-seasons.php`

### v7.0.0
- Plugin rebranded from `nipgl-division-widget` to `lgw-division-widget`
- All option/meta prefixes migrated from `nipgl_` to `lgw_`
- All shortcodes renamed to `[lgw_*]`
- One-time DB migration with rollback capability

### v6.0.0
- **Cup bracket widget** — new `[lgw_cup id="…"]` shortcode renders a full single-elimination knockout bracket
- **Live animated draw** — admin triggers the draw; visitors see an animated team-reveal sequence live via polling
- **Cup management admin** — LGW → Cups page to create cups, enter team lists, set round names/dates
- New files: `lgw-cup.php`, `lgw-cup.js`, `lgw-cup.css`

### v5.9
- Player tracking system — appearances auto-logged from confirmed scorecards
- Players grouped by club, showing which teams they've played for and appearance count
- Season date range configuration, admin merge tool, export to Excel

### v5.1
- Full scorecard submission system — `[lgw_submit]` shortcode
- PIN-gated entry (no WordPress login needed)
- AI photo reading (Anthropic API) pre-fills form from photo
- Excel upload parsing for LGW scorecard template

---

## License

GPLv2 or later
