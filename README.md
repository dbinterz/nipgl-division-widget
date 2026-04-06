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

### v7.1.13
- Fixed: `plugins_api` (info popup / View Details) was still using direct download URL for `download_link`, causing 404 on update; now uses GitHub API asset URL to match the update checker

### v7.1.12
- Auto-update download fix: switched to GitHub API asset URL (`api.github.com/.../releases/assets/{id}`) to avoid auth header being stripped on CDN redirect
- Auth injection filter restricted to `api.github.com` and `github.com` only — not CDN redirect targets
- `Accept: application/octet-stream` header added for asset downloads
- Test Download URL diagnostic added to Settings page

### v7.1.11
- Test Download URL button in Settings — shows HTTP status at each redirect step for diagnosing update failures

### v7.1.10
- Cup bracket TBD slots now show abbreviated predecessor team names (e.g. "Sal A/B'mena B") instead of plain TBD

### v7.1.9
- Auto-updater stale transient fix: version-aware cache bust; TTL reduced from 6h to 1h; cache busted on plugin update

### v7.1.8
- Green usage table date sort fix: DD/MM/YY dates now parsed as timestamps before sorting

### v7.1.7
- Green usage table: sortable by Date or Club; rowspan merging; championships merged per date/club cell

### v7.1.6
- Green bookings backfill: `lgw_rebuild_green_bookings()` scans all drawn brackets; auto-backfill on init if never built; manual Recalculate button on Championship Management page

### v7.1.5
- Cross-championship green capacity management: `lgw_green_bookings` tracks home slots per date/club across all championships; drag-to-reorder priority list; hard-block lower-priority championships from exceeding remaining slots; Green Usage table and capacity warning before drawing

### v7.1.4
- Added hexagon badge logo (`lgw-logo.svg`) in brand colours (#072a82 / #138211 / #fcfcfc); registered as WP admin menu icon; `lgw_page_header()` helper added to all admin pages

### v7.1.3
- GitHub PAT and Plugin Updates diagnostic moved from League Setup to Settings page

### v7.1.2
- Auto-update CDN auth fix: auth header filter extended to match GitHub CDN domains so it isn't stripped on redirect

### v7.1.1
- Cup/championship bracket date sync fix: editing round dates after draw now updates the live bracket immediately

### v7.1.0
- Settings page restructured: League Setup reorganised into Data Source, Photo Analysis, Google Integration, and Plugin sections
- Data source selector (Google Sheets active; Upload/WordPress DB as placeholders)
- Photo analysis provider selector (Claude active; OpenAI/Gemini as placeholders)
- GitHub PAT and Plugin Updates moved to Settings page

### v7.0.0
- **Full rebrand** — all internal identifiers renamed from `nipgl_` to `lgw_` prefix across all files
- Plugin display name changed to **League Game Widget**; main file/folder renamed to `lgw-division-widget`
- All shortcodes renamed: `[lgw_division]`, `[lgw_cup]`, `[lgw_champ]`, `[lgw_submit]`
- One-time DB migration copies `nipgl_*` options/post meta to `lgw_*`; originals retained for rollback

### v6.4.51
- Simplified auto-updater to construct release asset URL directly from tag name

### 6.4.34
- Fixed auto-updater to use GitHub release asset zip instead of raw source zipball
- Check for Updates button now forces an immediate WP update re-check

### 6.4.33
- Fixed sponsor_img shortcode override on Cup pages (lost in v6.4.32 merge)
- Restored pre-draw entry list on Cup pages (lost in v6.4.32 merge)
- Added score update audit log — records time, match, teams, score, updated-by and IP; visible in Cups admin

### 6.4.32
- Added passphrase-gated score entry for Cup brackets — non-admin users can enter scores after authenticating with the draw passphrase; token held in memory for the session

### 6.4.31
- Fixed pre-draw entry list badge lookup — added exact club-badge match and bidirectional prefix matching

### 6.4.30
- Show entry list (badge + name) before draw is performed on Cup and Championship pages

### 6.4.29
- Fixed sponsor bar dark background on Cup and Championship pages — moved primary bar inside scoped CSS variable context

### 6.4.28
- Fixed sponsor_img shortcode attribute not overriding global sponsor for Cup and Championship widgets

### 6.4.27
- Added sponsor branding to Cup and Championship widgets (primary bar above bracket, rotating secondary below status bar)

### 6.4.26
- Added emoji icons to admin submenu items (Scorecards, Players, Cups, League Setup, Settings)

### 6.4.25
- Final stage always has 4 entries: 4 sections → 1 qualifier each (winner); 2 sections → 2 each (both finalists, seeded once SFs resolve); 1 section → 4 (all semi-finalists, seeded once QFs resolve)
- Section bracket winner label changed from "Champion" (🏆) to "Qualifier" (✅); Final Stage winner retains "Champion" (🏆)



### v6.4.24
- Fixed score of 0 not displaying on match cards — `escHtml` used `s || ''` treating 0 as falsy
- Fixed final stage showing "Preliminary Round / Final" instead of "Semi-Final / Final"

### v6.4.23
- Fixed 500 error when entering the last result in a championship section — `lgw_champ_try_seed_final` called undefined function `lgw_champ_make_skeleton_bracket`; replaced with `lgw_champ_perform_final_draw`

### v6.4.22
- Fixed championship section tabs not switching — clicking a section tab now correctly shows that section's pane (`initSectionTabs` was only saving to `sessionStorage`, not updating active classes; the DOM switching was lost when the inline script was removed in v6.4.20)

### v6.4.21
- Code quality: shared draw library extracted to `lgw-draw.php` — bracket geometry, animation pairs, and skeleton-round assembly now live in one place (`lgw_draw_build_bracket`, `lgw_draw_default_rounds`, `lgw_draw_cup_club`); cup and champ draw functions refactored to thin wrappers

### v6.4.20
- Robustness: bracket size check at draw time — rejects writes exceeding 800KB
- Code quality: inline admin JS moved to lgw-admin.js (cup draw, sync, champ draw)
- Code quality: redundant tab-switching script removed from champ shortcode
- Build: GitHub Actions version check covers LGW_VERSION constant and readme.txt stable tag

### v6.3.0

- Fixed blank print/PDF — replaced `body > *` selector (fails when bracket is nested in page content) with `visibility: hidden` on all + `visibility: visible` on `.lgw-cup-wrap` and descendants — works at any DOM depth; all round columns forced to `display: flex` before print dialog opens so mobile-hidden rounds appear

### v6.2.9

- **Print Draw button** — appears in bracket header after draw completes; print styles hide UI chrome and overlay elements
- **Match scorecard viewer** — click any completed match card to see the submitted scorecard in a modal (rink-by-rink scores, player names, winner highlighted, confirmation status); shows "no scorecard submitted yet" if none found
- Cup scorecards automatically feed into player appearance records via the existing submission flow

### v6.2.8

- Fixed draw stuck at N-1/N — round header entries counted in `pairs_for_anim` total but never called `lgw_cup_advance_cursor`; cursor never reached total so `complete` was never set; headers now fire `advance_cursor` on the draw master side and during skip-to-end

### v6.2.7

- Fixed viewer draw completion — server now returns a `complete` flag alongside the bracket; viewer detects completion reliably on this flag rather than inferring from `in_progress + bracket` (which had a race condition on the same poll that delivered the last pairs)
- Viewer overlay now shows running match count (X / Y drawn) and estimated time remaining

### v6.2.6

- Fixed viewer draw not completing — `waitForAnim` could hang indefinitely if `animating` was still `true` when the completion poll fired; now times out after 6s and force-clears state before calling `showViewerComplete`

### v6.2.5

- Fixed `initCupWidget is not defined` ReferenceError — function was accidentally dropped during the `startDrawPoll` rewrite in 6.2.3; restored

### v6.2.4

- Fixed login button broken by 6.2.3 — `drawMasterActive` declared after `initAdminDraw` causing a ReferenceError in strict mode; moved to module scope

### v6.2.3

- Fixed draw master seeing a second viewer overlay — `drawMasterActive` flag suppresses the viewer poll overlay when the draw master's own animation is running
- Fixed viewer "View Bracket" button not appearing — complete state now reliably shown
- Polling uses exponential backoff: 1s during active draw, backs off to 2s → 4s → 8s when idle — significantly reduces mobile network requests

### v6.2.2

- Draw overlay shows "✅ The draw is complete!" with a "View Bracket" button when finished — covers draw master, skip-to-end, and live viewers; overlay stays open until the user explicitly dismisses it

### v6.2.1

- Fixed draw animation replaying on page refresh — polling suppressed on page load when a complete bracket already exists; `draw_in_progress` auto-cleared server-side when cursor reaches total (handles draw master disconnecting early)

### v6.2.0

- **Fully synchronised live draw** — the draw master's animation advances a server-side cursor match by match via `lgw_cup_advance_cursor`; viewers poll at 1s and see each team revealed in lockstep with the draw master; viewers who open the page mid-draw join at the current position; the overlay closes and bracket renders for all viewers simultaneously when the draw completes

### v6.1.9

- Removed passphrase hint text and `e.g. filled.count.ripen` placeholder from the scorecard login form on division pages

### v6.1.8

- Removed passphrase hint text and `word.word.word` placeholder from the public draw login modal

### v6.1.7

- Fixed "unexpected response" on mobile passphrase entry — `check_ajax_referer` replaced with `wp_verify_nonce` in both the draw auth and perform draw handlers; nonce failures (e.g. from page caching serving a stale nonce) now return a JSON error with a "session expired — please refresh" message instead of a plain `-1` that breaks JSON parsing

### v6.1.6

- Fixed "unexpected token" error on mobile after passphrase entry — `ajaxUrl` now always taken from `lgwCupData` (always present) rather than `lgwData` (only present when division widget is also on the page); AJAX response parsed as text first so non-JSON responses give a readable error message

### v6.1.5

- Fixed team names pre-loading before draw animation — text now set inside the reveal timeout
- Draw speed configurable under LGW → Cups: Fast (0.5×) through Very Slow (2×)
- Server-side double-draw guard — concurrent authenticated users cannot accidentally re-draw an already-drawn cup

### v6.1.4

- Draw animation is fully automatic — home/away teams reveal on a timer; "Skip to End" button fast-forwards all remaining matches instantly
- "No draw performed" empty state hidden after draw completes
- Bracket columns flex to fill available width on wider screens
- Header bar red; round name/date labels yellow background; Final round header navy with gold text

### v6.1.3

- Login/draw buttons hidden from public page once draw completes — covers all paths: direct trigger, poll animation, and bracket-only updates
- Draw reset remains wp-admin only

### v6.1.2

- Draw passphrase setting moved from the main LGW settings page to the Cups admin page

### v6.1.1

- Draw passphrase gate now applies to all visitors on the public page including WP admins — everyone must enter the passphrase; only the wp-admin inline draw button retains direct admin access

### v6.1.0

- **Draw passphrase gate** — set a global draw passphrase in Settings → LGW Widget; the public cup page shows a "🔑 Login to Draw" button; on correct passphrase entry a session token is issued and the draw proceeds; WP admins bypass the gate; passphrase is stored as SHA-256 hash

### v6.0.10

- Winner row: lighter green background with dark green text for better readability
- Loser row: light red background with dark red text
- Score popover team name colour hardcoded to `#1a1a1a` so it renders correctly on any page theme

### v6.0.9

- Fixed score input contrast — hardcoded white background and dark text so inputs are readable regardless of theme
- Draw numbers hidden when a score is present — avoids overlap between score value and draw number badge
- **Cup scorecard**: `[lgw_submit cup="cup-id"]` now supported — division is pre-filled and locked, a match selector lists all drawn bracket fixtures, selecting a match auto-populates home/away team fields; full hole-by-hole scorecard submission works as normal

### v6.0.8

- Fixed undefined `$drawn` variable warning — was referenced in shortcode header output before being assigned

### v6.0.7

- Fixed 17-team draw showing a blank vs TBD match — `lgw_cup_default_rounds()` had an erroneous `array_reverse` causing round names to be in wrong order, producing an extra skeleton round
- Round names now correct for prelim-format cups: Preliminary Round → Round of 16 → Quarter Final → Semi-Final → Final
- Edit button removed from public cup page
- Cup widget explicitly defines light-mode CSS variables for standalone use
- **Score entry**: admins can click any match card on the bracket to enter scores via a popover; winner is automatically advanced to the next round on save

### v6.0.6

- "Perform Draw" button hidden once draw is complete — suppressed server-side for subsequent page loads and removed from the DOM immediately after the draw animation closes

### v6.0.5

- Draw animation now covers all drawn rounds — after prelim matches are revealed, a labelled section break introduces the Round 2 draw; pairings involving a prelim winner show as "Prelim Winner" placeholder until that result is known

### v6.0.4

- Fixed byes logic — bracket now uses a proper prelim round containing only the overflow matches. 17 teams → 1 prelim match, winner joins 15 bye teams in Round 2 (8 matches). 20 teams → 4 prelims, 12 byes, 8 main-round matches. Prelim winners are spread evenly across the main-round bracket.

### v6.0.3

- Fixed "headers already sent" warning when saving a cup — POST save handler, draw reset, and delete actions all moved to `admin_init` so redirects run before any HTML output

### v6.0.2

- Draw enforces club home-conflict rule — teams from the same club (e.g. Belmont A and Belmont B) cannot both be the home team in Round 1 on the same date; home/away positions are swapped as needed after the random pairing. The unavoidable exception is when two same-club teams are drawn against each other — drawn order is kept since no swap resolves it.

### v6.0.1

- Fixed Cups admin page returning 404 — Cups submenu now registered inside `lgw_admin_menu()` alongside Players/Scorecards submenus, guaranteeing the parent menu exists before the submenu is added
- Fixed admin JS/CSS not loading on Cups pages — `admin_enqueue_scripts` hook now covers `lgw_page_lgw-cups`

### v6.0.0

- **Cup bracket widget** — new `[lgw_cup id="…"]` shortcode renders a full single-elimination knockout bracket with club badges, scores, winner highlighting, and champion display
- **Mobile-friendly** — round tabs on small screens; horizontal scroll bracket on desktop
- **Live animated draw** — admin triggers the draw; any visitor on the page at that moment sees an animated team-reveal sequence pulled live via polling (no page reload needed)
- **Cup management admin** — **LGW → Cups** page to create cups, enter the team list, set round names and dates, and link a Google Sheets CSV for result sync
- **Google Sheets result sync** — pulls team advancement from the published bracket sheet and updates the stored bracket automatically
- **Draw reset** — admin can clear and redo the draw before any results are recorded
- **Dark mode + theme colours** — inherits all CSS variables from the division widget; fully compatible with per-shortcode theme overrides
- New files: `lgw-cup.php`, `lgw-cup.js`, `lgw-cup.css`

### v5.18.3

- Import Passphrases admin tool — upload `lgw-club-passphrases.xlsx` directly from **LGW → 🔑 Import Passphrases** in wp-admin to set all club passphrases in one go
- Tool hides itself from the menu once dismissed, keeping the admin tidy

### v5.18.1

- Fixed 404 on login button — `lgwSubmit` (ajaxUrl/nonce) was not always localised to the scorecard script when the `[lgw_submit]` shortcode was on a page without `[lgw_division]`. `wp_localize_script` now called unconditionally after enqueue in both enqueue functions.

### v5.18.0

- PIN authentication replaced with **passphrase authentication** — clubs now log in with a three-word passphrase instead of a numeric PIN
- [what3words](https://what3words.com) address for the clubhouse recommended as a default (e.g. `filled.count.ripen`)
- Passphrase matching is case-insensitive and whitespace-tolerant
- Admin settings updated with passphrase column, format hint, and what3words tip
- Login form updated with plain-text input, format hint, and `autocapitalize` disabled for mobile

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
- Key stored in protected `wp-content/uploads/lgw-private/` with `.htaccess` blocking web access
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
- New files: `lgw-drive.php`, `lgw-pdf.php`

### v5.12.0
- Admin scorecard edit — any field editable by admin: teams, date, venue, division, competition, per-rink player names, scores, totals and points
- Scorecards amended by admin show an "Amended" badge in the scorecards list
- Player appearances automatically re-logged after any admin edit
- Audit trail — every submit, confirm, resolve and admin edit is logged with timestamp, username, and a field-level before/after diff
- Audit history accessible via new 📋 History button on each scorecard row
- View/Edit/History panels replace the old single toggle — each button opens its own panel, clicking again collapses it
- New file: `lgw-sc-admin.php`

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
- Fixed: Players menu was invisible — parent slug corrected from `lgw-settings` to `lgw-scorecards`
- Players now appears as a submenu under the Scorecards top-level menu item

### v5.10.4
- Fixed: Players page not appearing in wp-admin — menu registration moved into main `lgw_admin_menu()` to guarantee load order
- LGW top-level menu now shows Scorecards and Players as submenus

### v5.10.3
- Updated plugin description in PHP header, readme.txt, and settings page to reflect all current features
- Shortcode reference in settings page now uses a table and includes all parameters including sponsor overrides
- Added `[lgw_submit]` documentation alongside `[lgw_division]` in the settings page

### v5.10.2
- Fixed: rink label in team modal scorecard now shows light background / dark text in light mode (was inheriting dark navy from scorecard.css)

### v5.10.1
- Fixed: team modal fixture rows now show inline scorecard when clicked
- Added missing CSS for `modal-fx-row`, `modal-sc-row`, `modal-sc-inline`, `modal-sc-hint`
- Compact scorecard layout styled for display inside the modal fixture table

### v5.10.0
- Scorecard display in fixture modal — clicking a played fixture now loads the full rink-by-rink scorecard
- Fixed script load order — lgw-scorecard.js now correctly loads before lgw-widget.js on all pages with [lgw_division]
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
- Added `lgw-debug.html` diagnostic tool for testing Excel parser directly in browser

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
- Full scorecard submission system — `[lgw_submit]` shortcode
- PIN-gated entry (no WordPress login needed)
- AI photo reading (Anthropic API) pre-fills form from photo
- Excel upload parsing for LGW scorecard template
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
