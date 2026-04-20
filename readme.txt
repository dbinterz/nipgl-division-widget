=== League Game Widget ===
Contributors: dbinterz
Tags: bowls, sports, league table, fixtures, google sheets
Requires at least: 5.0
Tested up to: 6.5
Stable tag: 7.1.85
License: GPLv2 or later

Mobile-friendly league tables, fixtures, and scorecard submission for bowls leagues. Powered by Google Sheets CSV.

== Description ==

A full league management widget for bowls clubs and leagues. Displays live league tables and fixtures fetched from a published Google Sheet, and includes an optional scorecard submission system with two-party verification and player tracking.

= League Table & Fixtures =

* Mobile-responsive tabbed widget with sticky team name column
* Club badge support via WordPress Media Library
* Promotion and relegation zone highlighting with clinched-position shading
* All / Results / Upcoming fixture filter tabs
* Sponsor logo display with per-division override
* Server-side caching to minimise Google Sheets requests
* Dark mode toggle and print view

= Scorecard Submission =

* Per-club passphrase authentication — each club gets a private passphrase to submit scores (what3words address recommended)
* Two-party verification — both home and away clubs must confirm before a scorecard is marked confirmed
* Dispute resolution — admin can view side-by-side versions and accept either
* Score entry via typed input, Excel file upload, or photo (AI-parsed via Claude)
* Submitted scorecards visible inline when clicking a played fixture

= Player Tracking =

* Appearances automatically logged from confirmed scorecards
* Grouped by club, showing which teams each player has appeared for
* Season date range filtering
* Merge tool for duplicate player names
* Export to Excel — one sheet per club

= Shortcodes =

League table and fixtures:
`[lgw_division csv="URL" title="Division 1"]`

All parameters:
* `csv` — required. Published Google Sheets CSV URL
* `title` — division name shown above the widget
* `promote` — number of promotion places to highlight (default 0)
* `relegate` — number of relegation places to highlight (default 0)
* `sponsor_img` — override primary sponsor image URL for this division
* `sponsor_url` — override primary sponsor link URL for this division
* `sponsor_name` — override primary sponsor alt text for this division

Scorecard submission form:
`[lgw_submit]`

Cup bracket:
`[lgw_cup id="senior-cup-2025" title="Senior Cup 2025"]`

Parameters:
* `id` — required. The cup ID set in LGW → Cups admin page
* `title` — optional override for the cup title displayed in the widget header



1. Upload the plugin zip via Plugins > Add New > Upload Plugin
2. Activate the plugin
3. Go to Settings > LGW Widget to configure club badges and cache settings
4. Add the shortcode to each division page

== Changelog ==

= 7.1.85 =
* Fix: Clear override button now removes the override entirely instead of saving a 0-0 score — inputs are blanked and empty strings sent so the server-side clearing path (unset) is triggered correctly

= 7.1.84 =
* Feature: Championship — Rename Entry tool on the edit page lets you correct spelling mistakes in entries after a draw has been done, without resetting the draw or any scores

= 7.1.82 =
* Fix: Live points hint in scorecard modal used parseInt — half-point values (e.g. 2.5+4.5) showed total as 6 instead of 7; fixed to parseFloat with tolerance comparison

= 7.1.81 =
* Fix: Rink score inputs (modal and standalone form) now have step="0.5" so browsers accept half-scores without rounding
* Fix: Auto-sum of rink scores rounds to 1 decimal to prevent float accumulation noise
* Fix: Scorecard admin page stripped half-points — all scores, totals and points now use floatval; admin number inputs gain step="0.5"
* Fix: Points validation uses parseFloat and tolerance comparison throughout

= 7.1.80 =
* Fix: Drive upload now respects submitted_for — PDF saved to that team's folder only when submitting for one team
* Fix: Resubmitting a scorecard replaces existing PDF in Drive rather than creating a duplicate; admin edits still produce versioned copies

= 7.1.78 =
* Feature: Cup and Championship bracket draws on mobile now support horizontal swipe scrolling — all rounds are visible side-by-side with scroll-snap for clean swiping between them
* Feature: Tapping a round header in the bracket scrolls forward to the next round (wraps to first), and the tab bar stays in sync as you swipe via IntersectionObserver

= 7.1.77 =
* Feature: Championship bracket draws now show potential opponents in TBD slots — displays the last player's surname and abbreviated club name (e.g. "Hinds, Sha/Maxwell, Nor") matching the cup bracket style

= 7.1.76 =
* Feature: Championship draws now enforce strict same-club separation using a multi-pass algorithm — players from the same club are guaranteed not to be drawn against each other in the first round wherever mathematically possible (graceful fallback only when all entries are from a single club)
* Feature: Admin draw editor — after a championship section is drawn, an "✏️ Edit Draw" button appears on the admin edit page; clicking it reveals a bracket table where any first-round match participant can be swapped via dropdown; saving an edit clears that match's score and cascades resets through all downstream rounds, and unseeds the Final Stage if applicable so it can be redrawn once corrected results are entered

= 7.1.75 =
* Fix: scorecard photo camera option on Chromium browsers (Chrome, Brave etc) now uses the browser's native camera API (getUserMedia) instead of a capture="environment" file input — which Chromium locks to camera-only with no way to switch to gallery/files; both options now work correctly across all browsers

= 7.1.73 =
* Scorecard photo upload on mobile now prompts the user to choose between "📷 Take a photo" (camera) or "🖼️ Choose from gallery / files" instead of immediately launching the camera — desktop behaviour (file picker) unchanged

= 7.1.72 =
* Settings: Merged "Clubs & Passphrases" and "Club Badges" into a single "Clubs & Badges" table — passphrase and badge fields now on one row per club

= 7.1.70 =
* Feature: Archived seasons now support start/end date fields — set via the Seasons admin edit form or when adding a historical season
* Feature: Each archived season row in Seasons admin now has a "👥 Players" link (opens Player Tracking filtered to that season) and a "🔄 Backfill Players" button (re-runs appearance logging for all confirmed scorecards tagged to that season)
* Feature: Player Tracking admin now accepts a ?season=ID URL param — loads that season's date range for all appearance counts, the export, and the Season Settings tab summary
* Feature: Season switcher bar added above the tabs in Player Tracking — pill buttons for every season; active season marked with ●
* Feature: Page title reflects the archived season being viewed (e.g. "Player Tracking — 2025 Season")
* Feature: Export to Excel respects the currently viewed season and passes the season ID through so the downloaded file matches what is on screen

= 7.1.69 =
* Feature: Season start/end dates moved from Player Tracking admin to Seasons admin — one place to manage season label, dates, and divisions
* Change: lgw_get_season() in lgw-players.php now reads label/start/end from the active season in lgw_seasons; falls back to legacy lgw_season option for existing installs
* Change: Player Tracking "Season Settings" tab replaced with a read-only summary and a link to Seasons admin

= 7.1.68 =
* Fix: Sheets writeback now finds the fixture row even when the match was played on a different date to scheduled — tries the fixture date first, then falls back to team-name-only search
* Fix: Same date-fallback applied to the override sync so both the spreadsheet write and the widget override use the correct row

= 7.1.68 =
* Fix: Override key now uses the fixture date read directly from the published CSV (by finding the home/away team pair row), not the played date stored on the scorecard — fixes cases where a match was played on a different date to scheduled (e.g. 12/05 played instead of 09/05 fixture)
* Fix: lgw_sync_get_fixture_date_from_csv() helper added — fetches the division CSV and returns the exact date string the widget will use as a key for that fixture row
* Fix: Confirmed scorecards now update the widget immediately — lgw_sync_override_from_scorecard() was silently bailing when the division had no csv_url in sheets_tabs; now falls back to the active season division config
* Fix: Override key now uses the fixture date (lgw_fixture_date post meta) rather than the played date, so it correctly matches the CSV fixture row even when a match was played on a different date to scheduled
* Fix: lgw_sync_override_from_scorecard() now logs success and failure to the per-scorecard sheets log, visible in the History panel
* Feature: "Force sync widget override" button added to the Sheets Writeback Log on every scorecard's History panel — allows admin to manually re-push any confirmed scorecard's score to the override table without re-saving
* Fix: Deleting or trashing a scorecard now removes all associated player appearance records and prunes orphaned player entries
* Fix: Player re-save (same club resubmitting a previously confirmed scorecard) now fires the sheets writeback action so Google Sheets is updated correctly
* Feature: Player names on the Player Tracking page are now clickable — opens a modal showing every game the player appeared in, with date, match, division, rink, team, score, and scorecard status
* Fix: lgw_sheets_find_row now normalises dates (strips leading zeros, lowercases) and trims/lowercases team names before comparing — fixes "row not found" caused by "05-Apr" vs "5-Apr" day padding or whitespace differences
* Fix: lgw_sheets_format_date now omits the leading zero from the day number to match the typical sheet format ("Sat 5-Apr-2025" not "Sat 05-Apr-2025")
* Fix: OAuth redirect URI was hardcoded to lgw-league-setup; introduced LGW_SETUP_PAGE constant so the redirect URI is always self-consistent and matches what Google Cloud Console expects
* Fix: Google auth token scope now includes spreadsheets — OAuth and service account JWTs were only requesting drive scope, causing auth_failed on all Sheets writeback and score override writes


= 7.1.52 =
* Fix: season switcher now matches archived divisions to the shortcode title even when the title includes a trailing year (e.g. "Division 1 2026" matches archived "Division 1" or "Division 1 2025") — year suffix is stripped from both sides before comparison
* Fix: Seasons admin — editing an existing archived season no longer triggers "season already exists" error; Edit form now correctly updates in place

= 7.1.50 =
* Cup admin: added "Download Draw (.xlsx)" export button on cup edit page — downloads the full bracket as an Excel spreadsheet matching the reference cup draw format (draw number, round columns, dates)
* Championship admin: added "Download Draw (.xlsx)" export button on championship edit page — downloads all drawn sections as separate sheets, plus a Final Stage sheet if drawn, matching the reference championship draw format
* New module: lgw-export.php handles all xlsx generation in pure PHP (ZipArchive), no server-side dependencies required

= 7.1.48 =
* Scorecard modal: Date Played field now displays in the same format as the fixture date (e.g. "Sat 9-May-2026") after blur, making it easier to confirm the correct day was entered
* Date is normalised back to dd/mm/yyyy internally on save so storage format remains consistent
* Code cleanup: consolidated duplicate lgwClubMatchesTeamStr into lgwClubMatchesTeam (null guard added); removed redundant typeof normaliseDate defensive check in populateModalForm

= 7.1.46 =
* Fix: points auto-suggest now updates correctly after every rink score change, not just the first — programmatic input events no longer incorrectly cleared the auto-fill flag
* Fix: same isTrusted guard applied to totals auto-sum to prevent similar edge cases
* Scorecard modal: Date Played field now normalises to dd/mm/yyyy format on blur, matching the fixture date display

= 7.1.45 =
* Scorecard submission: rink scores now auto-suggest home/away points as you type, based on configurable points-per-rink-win and overall-match-win values
* Points calculation: 1 per rink win, 3 overall win by default (0.5/1.5 for draws); totals to 7 for 4-rink, 6 for 3-rink matches
* League Setup: new Points System section to configure points-per-rink and overall-match points (live preview of max points per match)
* If user manually overrides auto-suggested points, a mismatch warning is shown but submission is not blocked
* Points auto-suggest also fires after photo AI parse and Excel import

= 7.1.44 =
* Scorecard submission: rink scores now auto-sum into the Home/Away Total Shots fields as you type
* Totals are updated silently when auto-filled; if the user manually enters a total that doesn't match the rink sum, an inline warning is shown (submission is not blocked)
* Auto-sum also fires after photo AI parse and Excel import so totals are always in sync with populated rink scores

= 7.1.43 =
* Fix: Cup scorecard modal now shows the round date (e.g. 01/05/2025) as the fixture date — passed from the bracket's dates[] array at card-click time
* Fix: Cup name (Senior Cup / Junior Cup / Midweek Cup etc.) now shown as the division label in the scorecard form instead of the generic "Cup"
* Fix: Cup scorecard modal header changed from red to navy to match the league scorecard style; modal body always renders in light mode regardless of device dark-mode setting

= 7.1.43 =
* Fix: Cup scorecard submission now fully works — login gate, submission form, and confirm/amend flow all appear correctly when clicking a cup bracket match
* Fix: Root cause was that lgw_get_scorecard matched on team names only, so a league scorecard for the same two clubs was found instead of returning "no scorecard yet"; fixed by adding a context field (league/cup) stored as lgw_sc_context post meta, passed through the full fetch/submit/amend chain
* Fix: Admin clicking a cup match now sees the submission form directly (no login gate) matching league behaviour
* Fix: Amend flow in cup now correctly skips points validation (maxPts: 0 preserved through amend path)

= 7.1.43 =
* Fix: maxPts: 0 (cup mode) was being overridden to 7 by a JS falsy fallback (0 || 7) in both lgwFetchScorecardOrSubmit and lgwOpenSubmitInModal — fixed with an explicit undefined/null check; cup scorecard login gate and submission form now appear correctly
* Fix: Admin on cup page now sees the submission form directly without a login gate, same as the league widget

= 7.1.43 =
* Fix: Cup page now loads lgw-scorecard.js (and its CSS) as a dependency — previously lgw-cup.js had no dependency on it, so lgwFetchScorecardOrSubmit and lgwOpenSubmitInModal were undefined on cup-only pages, causing the scorecard modal to always fall through to the quick-view fallback with no login gate and no submission form
* Fix: lgwSubmit (clubs list, nonce, authClub) now localised on cup pages so the login gate can populate the club dropdown and authenticate correctly
* Fix: Admin on a cup-only page now goes directly to the scorecard form without a login gate (isAdmin from lgwCupData flows correctly into lgwOpenSubmitInModal)

= 7.1.43 =
* New: Cup scorecards now fully submittable via the bracket — clicking a match with both teams opens the same modal as the league (login gate, rink scores, player names, submission and confirmation flow); points fields are hidden for cup matches (not applicable) and points validation is skipped
* Fix: Totals row in scorecard form switches to a 2-column layout when points fields are hidden

= 7.1.43 =
* Fix: Cup bracket card routing rebuilt — clicking any match with both teams known now opens the full scorecard modal (with login gate and submission) rather than the score-entry popover; the popover is now accessible via an ✏️ Score button in the modal header (admin only)
* Fix: Matches with only one team set (TBD slot) continue to open the quick score popover directly as before

= 7.1.43 =
* Fix: Club users logged in via passphrase no longer see the scorecard submission form for fixtures that don't involve their club — those fixtures now show "No scorecard submitted yet" as a read-only visitor would see
* Fix: Cup bracket full scorecard now accessible via a "Full Scorecard" button inside the score-entry popover — previously the scorecard viewer was unreachable when a draw passphrase was set (the editable card path always won the routing decision)

= 7.1.43 =
* Fix: lgw-sheets.php syntax error — invalid PHP template block inside echo-mode function (lgw_render_sheets_log) replaced with echo statements
* Fix: Scorecard submission modal — JSON.parse now guarded with try/catch so PHP notices/warnings prepended to AJAX response no longer silently kill the flow
* Fix: Login gate condition broadened from `mode === open` to exclude disabled/admin_only — future-proof and handles edge cases
* Fix: Sub-container ID collision in lgwFetchScorecardOrSubmit replaced with class selector
* Fix: Orphaned player records (misspelled names after admin corrections) now pruned after each admin scorecard save
* New: Cup bracket scorecard viewer now uses full lgwFetchScorecardOrSubmit modal (with submission + login gate) when lgw-scorecard.js is loaded; falls back to cup quick-view
* New: submissionMode and authClub added to lgwCupData so cup page has correct submission context
* New: Admin/visitor view toggle button in widget tab bar — admins can preview the widget as a regular visitor without logging out

= 7.1.27 =
* New: Season management — new 📅 Seasons admin page to manage the active season, archive past seasons, and backload historical seasons (CSV URLs per division)
* New: Season switcher on the front-end widget — add seasons="2025,2024" or seasons="all" to any [lgw_division] shortcode to show a pill bar above the tabs; clicking a past season loads that season's data read-only
* New: Scorecard season tagging — new scorecards stamped with lgw_sc_season post meta; archiving a season back-fills all untagged scorecards
* New file: lgw-seasons.php

= 7.1.31 =
* Photo and Excel parse handlers now allow WP admins without a passphrase session — previously only passphrase-authenticated club users could trigger the parse; admins using the modal form were getting a silent "Not authorised" error
* Improved auth error message: "Not authorised — please log in with your club passphrase first." shown instead of bare "Not authorised" when a non-admin, non-authenticated user attempts to parse

= 7.1.30 =
* Modal submission form now includes Photo, Excel and Manual entry tabs — same three input methods as the standalone [lgw_submit] form; photo and Excel parse results populate the pre-filled modal form
* "Submitting on behalf of" radio text made smaller (12px, no bold team names) to reduce visual weight
* Season tagging confirmed working: both normal and admin-both submission paths call lgw_get_active_season_id() and stamp lgw_sc_season on every new scorecard post; backfill fires when a season is archived

= 7.1.29 =
* Played fixtures with no scorecard now also show the submission form — clicking any played fixture checks for an existing scorecard first; if none, the submit form is offered inline (respects submission mode setting)
* New shortcode attribute max_points="7" (default) on [lgw_division] — set to 6 for the 12-player division; points validation enforces home + away = max_points
* Submission form: Date Played field added (optional, with hint text "enter only if different to fixture date"); when blank, the fixture date is used as the match date
* Submission form: Submitted by field added (submitter's name, stored on the scorecard and displayed in the public scorecard view)
* Points validation: live running total shown as you type (green when correct, red when off); save blocked if points do not sum to the division max

= 7.1.28 =
* Scorecard submission mode toggle in Settings: Disabled / Admin only / Open — lets admin test the workflow before releasing it to clubs
* Fixture modal now opens for unplayed fixtures when submission is enabled — click any upcoming fixture to submit a scorecard; form is pre-filled with division, date, home team and away team
* Admin submission now includes a "Submitting on behalf of" radio: Home team, Away team, or Both teams — selecting "Both" skips the two-party flow and immediately confirms the scorecard
* Club list exposed in modal login gate — clubs with passphrases are shown in a select dropdown when logging in from the fixture modal

= 7.1.27 =
* Finals Week: fix home end scores left-aligning in ends table — stray CSS class was overriding right-align; home scores and running totals now correctly right-align toward the centre End column

= 7.1.25 =
* Finals Week: ends table defaults to collapsed; click header to expand
* Finals Week: ends table now shows 5 columns — end score | running total | end number | running total | end score — so scores and totals are centred near the end number rather than pushed to the outer edges

= 7.1.24 =
* Finals Week: player name and club name now displayed separately in the match card — player name(s) bold on top, club name smaller and muted below; badge aligns to the full name block

= 7.1.23 =
* Finals Week: fix score display showing "undefined–undefined" after adding an end (undefined homeScore/awayScore now treated as null, showing live running total instead)
* Finals Week: rink number added to match display alongside date/time; admin can set/clear it in the date & time popover; stored as finals_rink on the match object; polled live for public viewers

= 7.1.21 =
* Finals Week: colour scheme fixed — widget now uses forced light theme like other widgets; live match state uses a subtle warm amber tint instead of dark red; dark mode only activates on explicit manual toggle
* Finals Week: Complete game button added — shown in the ends panel, pre-filled with the running total from ends entered, lets admin confirm or adjust before saving; validates that scores are not equal (no draws in bowls)
* Finals Week: final score edit button (✏️) remains accessible during live end-by-end scoring, not just after completion
* Finals Week: ends table is now collapsible (click the Ends header to toggle) and scrollable up to a fixed height, so viewers can focus on the score without scrolling through a long ends list

= 7.1.20 =
* New shortcode [lgw_finals season="2026"] — Finals Week schedule page showing all championship SF+Final matches across a season; displays date/time, team names with badges, live end-by-end scoring, and final scores
* Admin can set date/time per match via a popover (📅 button), enter end-by-end scores live (+ Add end / Remove last end), and save the final aggregate score
* Public viewers see live scores updated automatically every 30 seconds without page refresh
* 1-section competitions surface the last 2 rounds (SF + Final) of the section bracket; 2/4-section competitions use the Final Stage bracket
* Winner propagation and cascade reset work the same as the main bracket
* New files: lgw-finals.php, lgw-finals.js, lgw-finals.css

= 7.1.19 =
* New shortcode [lgw_finalists season="2025"] displays all finalists/semi-finalists across every championship in a given season on a single page — 1-section competitions show 4 semi-finalists, 2-section shows both finalists per section, 4-section shows each section winner; pending draws shown with a placeholder
* Season field added to championship admin — set on the edit page, visible in the championships list, used by [lgw_finalists] to filter by season
* Score reset now cascades through all subsequent rounds (not just one step forward) for both cup and championship brackets; resetting a section result also unsets the auto-seeded final stage so it re-seeds correctly when results are corrected
* Championship score save/reset now updates the final stage panel live in the browser without requiring a page refresh

= 7.1.18 =
* Dark/light mode fix extended to modals: fixture modal, cup score entry popover, cup/champ draw login box, and cup/champ scorecard modal all had hardcoded white backgrounds — replaced with CSS vars so they render correctly in dark mode
* Calendar widget now remembers the selected month across page refreshes, persisted per calendar instance in localStorage

= 7.1.17 =
* Calendar widget now defaults to grid (table) view instead of list view; user preference still saved on toggle
* Dark/light mode fix: league table, fixtures and widget panels now explicitly declare text and background colours so WordPress theme styles can no longer bleed in and cause text to blend into the background
* Colour customisation extended to cup and championship widgets: [lgw_cup] and [lgw_champ] shortcodes now accept color_primary, color_secondary, and color_bg attributes; site-wide theme colours from League Setup also apply
* Cup and championship CSS overhauled: all hardcoded hex colours replaced with CSS variables, making every tint — headers, round labels, draw login, score popover, scorecard modal — respond to colour overrides
* Championship header colour now follows --lgw-navy (was hardcoded teal #1a4e6e)

= 7.1.16 =
* Added calendar widget: [lgw_calendar xlsx="..."] renders a monthly event calendar from a Google Sheets xlsx export; supports list and grid views, month navigation, colour-coded event categories, and a colour legend

= 7.1.15 =
* Plugin modules now loaded with file-existence checks and try/catch — a missing or broken module file no longer brings the whole site down; admin notice shown instead
* Fixed: workflow was missing lgw-draw.php and lgw-logo.svg from release zip

= 7.1.14 =
* Test release — verified auto-update download via GitHub API asset URL

= 7.1.13 =
* Fixed: plugins_api (info popup / View Details) was still using direct download URL for download_link, causing 404 on update; now uses GitHub API asset URL to match the update checker

= 7.1.12 =
* Auto-update download fix: switched to GitHub API asset URL to avoid auth header being stripped on CDN redirect
* Auth injection filter restricted to api.github.com and github.com only
* Accept: application/octet-stream header added for asset downloads
* Test Download URL diagnostic added to Settings page

= 7.1.11 =
* Added Test Download URL diagnostic button to Settings page — tests HEAD request to release zip with and without auth, follows redirect and reports HTTP status at each step to diagnose auto-update download failures

= 7.1.10 =
* Cup bracket: TBD slots in future rounds now show abbreviated predecessor team names (e.g. "Sal A/B'mena B") instead of plain TBD
* Abbreviation format: first 3 chars of main name + suffix (A/B/etc), e.g. "Ballymena B" -> "B'mena B", "Salisbury A" -> "Sal A"
* Placeholder text styled in muted italic to distinguish from confirmed teams

= 7.1.9 =
* Fixed: GitHub release transient was caching stale release data (e.g. v7.1.1) even after newer versions were installed, preventing the auto-updater from offering updates
* Version-aware cache bust: if the cached release tag is <= the installed version, the transient is cleared automatically on next WP update check
* Cache TTL reduced from 6 hours to 1 hour so stale data clears faster
* upgrader_process_complete hook now also busts the transient after any plugin update
* Force Update Check confirmation notice added to Settings page

= 7.1.8 =
* Fixed: Green Usage table date sort was sorting lexicographically (12/5 before 28/4); dates now parsed from DD/MM/YY or DD/MM/YYYY format before comparison

= 7.1.7 =
* Green Usage table: sort by Date or Club via link controls
* Rows merged by primary sort key (date or club) using rowspan so each group appears once
* Championship titles merged into a single cell per club/date group when multiple competitions share the same date
* Full indicator shown in red when a club's green is at capacity

= 7.1.6 =
* Green bookings backfill: lgw_rebuild_green_bookings() scans all existing drawn brackets and rebuilds the cross-championship green bookings register from scratch
* Auto-backfill runs on init if lgw_green_bookings has never been built — upgrades from pre-7.1.5 are handled silently
* Manual "Recalculate from All Drawn Brackets" button added to Championship Management page for resyncing if bookings get out of step

= 7.1.5 =
* Cross-championship green capacity: when multiple championships share the same round date, home green slots are shared and allocated by priority
* Draw priority order — drag-to-reorder list on Championship Management page; manual order takes precedence, unordered championships fall back to draw timestamp order
* Hard-block enforcement: lower-priority championships cannot exceed remaining green slots on a date already partially booked by a higher-priority championship
* Green usage table on Championship Management page shows home slots used per date and club across all championships
* Capacity warning shown on edit page before drawing a section if another higher-priority championship has reduced available slots on the same dates
* Draw timestamp stamped on first draw for tiebreaking
* Reset draw now releases green bookings for that championship

= 7.1.4 =
* Added League Game Widget logo — hexagon badge in brand colours (#072a82 blue, #138211 green)
* Logo registered as WordPress admin menu icon replacing dashicons-clipboard
* Logo header added to all admin pages (Scorecards, Settings, League Setup, Cups, Championships, Import Passphrases)
* lgw-logo.svg added to plugin files

= 7.1.3 =
* GitHub Personal Access Token and Plugin Updates diagnostic moved from League Setup to Settings page
* League Setup form no longer redirects to Settings on save
* Force Update Check button now correctly stays on Settings page

= 7.1.2 =
* Fixed: GitHub PAT auth header was dropped when WordPress followed GitHub's CDN redirect during plugin zip download, causing "Not Found" on update; filter now also matches objects.githubusercontent.com and codeload.github.com

= 7.1.1 =
* Fixed: editing round dates after a draw now correctly updates the displayed dates on the live bracket page (bracket dates were previously frozen at draw time)
* Fix applies to both cup and championship section/final stage brackets

= 7.1.0 =
* League Setup page restructured into clear sections: Data Source, Photo Analysis, Google Integration, Plugin
* Data source selector added (Google Sheets active; Upload and WordPress DB placeholders for future)
* Photo analysis provider selector added (Claude/Anthropic active; OpenAI and Gemini placeholders for future)
* Google OAuth credentials consolidated into a single Google Integration section covering both Drive and Sheets
* Plugin Updates and Clear Cache moved from Settings page to League Setup
* Settings page now focused on appearance and branding only

= 7.0.0 =
* Rebranded: all internal references renamed from nipgl_ to lgw_ prefix
* Plugin display name updated to League Game Widget
* One-time DB migration on upgrade: all nipgl_* options and post meta automatically renamed to lgw_*
* Shortcodes (lgw_division, lgw_cup, lgw_champ, lgw_submit) and plugin slug unchanged for drop-in compatibility

= 6.4.51 =
* Merged Quick Score Entry into the Scorecards admin page — removed separate Scores submenu
* Both sections are collapsible with state remembered in sessionStorage; scorecards expanded by default, score entry collapsed
* Section headers show live badge counts (overrides active, pending/disputed scorecards)


= 6.4.51 =
* Simplified auto-updater to construct release asset URL directly from tag name rather than parsing API assets array — more reliable

= 6.4.34 =
* Fixed auto-updater to use GitHub release asset zip (correct folder structure) instead of raw source zipball
* Check for Updates button now forces an immediate WP update check rather than waiting for next scheduled check

= 6.4.33 =
* Fixed sponsor_img shortcode override on Cup pages (lost in v6.4.32 merge)
* Restored pre-draw entry list on Cup pages (lost in v6.4.32 merge)
* Added score update audit log — records time, match, teams, score, updated-by and IP; visible in Cups admin

= 6.4.32 =
* Added passphrase-gated score entry for Cup brackets — non-admin users can enter scores after authenticating with the draw passphrase; token held in memory for the session

= 6.4.31 =
* Fixed pre-draw entry list badge lookup — added exact club-badge match and bidirectional prefix matching to cover cases where badge key is more specific than entry club name

= 6.4.30 =
* Show entry list (badge + name) before draw is performed on Cup and Championship pages

= 6.4.29 =
* Fixed sponsor bar dark background on Cup and Championship pages — moved primary bar inside scoped CSS variable context

= 6.4.28 =
* Fixed sponsor_img shortcode attribute not overriding global sponsor for Cup and Championship widgets

= 6.4.27 =
* Added sponsor branding to Cup and Championship widgets (primary bar above bracket, rotating secondary below status bar)

= 6.4.26 =
* Added emoji icons to admin submenu items (Scorecards, Players, Cups, League Setup, Settings)

= 6.4.25 =
* Final stage always has 4 entries: 4 sections contribute 1 qualifier each (section winner), 2 sections contribute 2 each (both finalists, seeded once SFs complete), 1 section contributes all 4 semi-finalists (seeded once QFs complete)
* Section bracket winner/qualifier label changed from "Champion" to "Qualifier" (with ✅ icon) since section winners simply progress to the Final Stage; Final Stage winner still shows "Champion" with 🏆

= 6.4.24 =
* Fixed score of 0 not displaying on match cards — escHtml used s||'' which treated 0 as falsy; changed to explicit null/undefined check
* Fixed final stage bracket showing "Preliminary Round / Final" instead of "Semi-Final / Final" — final draw now passes lgw_draw_default_rounds as stored_rounds so round names reflect the full bracket size

= 6.4.23 =
* Fixed 500 error when entering the last result in a championship section — lgw_champ_try_seed_final called undefined function lgw_champ_make_skeleton_bracket; replaced with lgw_champ_perform_final_draw which performs the full final stage draw automatically

= 6.4.22 =
* Fixed championship section tabs not switching — clicking a section tab now correctly shows that section's pane (the DOM switching was dropped when the inline script was removed in v6.4.20; initSectionTabs was only saving to sessionStorage, not updating active classes)

= 6.4.21 =
* Code quality: shared draw library extracted to lgw-draw.php — bracket geometry, animation pairs, and skeleton-round assembly now live in one place (lgw_draw_build_bracket, lgw_draw_default_rounds, lgw_draw_cup_club); cup and champ draw functions refactored to thin wrappers supplying their own club/home-limit callbacks

= 6.4.20 =
* Robustness: bracket size check added at draw time — rejects writes exceeding 800KB to prevent option corruption
* Code quality: inline admin JS moved to lgw-admin.js (cup draw, cup sync, champ draw buttons)
* Code quality: redundant inline tab-switching script removed from champ shortcode (handled by lgw-champ.js)
* Build: GitHub Actions version check extended to validate LGW_VERSION constant and readme.txt stable tag

= 6.3.0 =
* Fixed empty print/PDF — replaced body > * visibility approach with visibility:hidden on all + visibility:visible on cup wrap, which works at any nesting depth; all rounds forced visible before print dialog opens

= 6.2.9 =
* Print Draw button appears in the bracket header after the draw is complete — prints a clean draw sheet hiding UI chrome
* Clicking a completed match card shows the submitted scorecard in a modal (rink-by-rink with scores, players, winner highlighted, confirmation status)
* Cup scorecards already feed into player appearance records automatically via the existing [lgw_submit cup="..."] confirmation flow — no extra config needed

= 6.2.8 =
* Fixed draw stuck at "N-1 / N drawn" — round header entries in pairs_for_anim were included in the total count but never triggered an advance_cursor call; cursor never reached total so complete was never set; headers now advance the cursor on the draw master side (and on skip-to-end)

= 6.2.7 =
* Fixed viewer draw completion — server now returns a dedicated "complete" flag with the bracket whenever the draw is fully done; viewer polls on this flag rather than inferring from in_progress+bracket which had a race condition
* Viewer overlay now shows running total (X / Y drawn) and estimated time remaining, matching the draw master screen

= 6.2.6 =
* Fixed viewer draw not completing — waitForAnim interval could wait forever if the animating flag was still true when the poll returned the final bracket; now times out after 6s and force-clears the animating state

= 6.2.5 =
* Fixed initCupWidget not defined error — function was accidentally removed during the Python-based rewrite of startDrawPoll in 6.2.3; restored

= 6.2.4 =
* Fixed login button broken by 6.2.3 — drawMasterActive variable was declared after initAdminDraw causing a ReferenceError that broke the entire script; moved declaration to module scope alongside drawToken

= 6.2.3 =
* Fixed draw master seeing two overlays — viewer poll overlay suppressed when draw master animation is active on same page
* Fixed viewer overlay missing "View Bracket" close button
* Polling uses exponential backoff: 1s during active draw, backing off to 2s/4s/8s when idle — reduces mobile network requests

= 6.2.2 =
* Draw overlay now shows "✅ The draw is complete!" with a "View Bracket" button when the draw finishes — applies to draw master, skip-to-end, and live viewers

= 6.2.1 =
= 6.2.1 =
* Fixed draw animation replaying on page refresh after draw is complete — polling is now suppressed if a complete bracket is already present on page load; draw_in_progress is also auto-cleared server-side when cursor reaches the total pair count

= 6.2.0 =
* Live draw is now fully synchronised for all viewers — the draw master's animation drives a server-side cursor that advances match by match; viewers poll at 1s intervals and see each team revealed in lockstep; viewers who join mid-draw pick up from the current position immediately

= 6.1.9 =
* Removed passphrase hint text and format placeholder from the scorecard login form on division pages — input now shows generic "Enter passphrase" placeholder only

= 6.1.8 =
* Removed passphrase format hint and placeholder from the public draw login modal — no information about the passphrase format is shown to users

= 6.1.7 =
* Fixed "unexpected response" on mobile passphrase entry — check_ajax_referer replaced with wp_verify_nonce in the draw auth and perform draw handlers so nonce failures return proper JSON errors instead of plain -1; stale nonces (from page caching) now show a "session expired, please refresh" message

= 6.1.6 =
* Fixed "unexpected token" error on mobile after passphrase entry — ajaxUrl now always sourced from lgwCupData; post() helper parses response as text first so non-JSON server responses produce a readable error

= 6.1.5 =
* Fixed draw animation showing next match teams before the reveal — text is now set inside the timeout, not before it
* Draw animation speed configurable in LGW > Cups (0.5× fast to 2× slow); default 1× = 2.6s per match
* Server-side guard against double-draw from concurrent authenticated users

= 6.1.4 =
* Draw animation is now fully automatic — teams reveal on a timed sequence; Skip to End fast-forwards all remaining matches instantly
* "No draw performed" message hidden after draw completes
* Bracket columns flex to fill available width on wider screens
* Header bar changed to red; round name/date labels have yellow background; Final round header is navy with gold text

= 6.1.3 =
* Login to Draw and Perform Draw buttons are now hidden from the public page after the draw completes — both when the draw is triggered by the current user and when a watching visitor sees it via polling
* Draw reset remains wp-admin only (Cups edit page)

= 6.1.2 =
* Draw passphrase setting moved from Settings > LGW Widget to the Cups admin page (LGW > Cups)

= 6.1.1 =
* Draw passphrase gate now applies to everyone on the public page including WP admins — the 🔑 Login to Draw button is shown to all visitors; the wp-admin inline draw button retains direct access for admins

= 6.1.0 =
* Draw passphrase gate — a global draw passphrase can be set in Settings > LGW Widget; when set, the public cup page shows a "Login to Draw" button instead of the draw button; the user enters the passphrase in a modal and on success the draw is unlocked for their browser session; WP admins bypass the gate entirely

= 6.0.10 =
* Winner row: lighter green background (#e6f4e6) with dark green text (#1a5c1a)
* Loser row: light red background (#fdf0f0) with dark red text (#8b1a1a)
* Score popover team names hardcoded to #1a1a1a for reliable contrast regardless of page theme

= 6.0.9 =
* Fixed score input contrast — explicit white background and dark text on score popover inputs
* Draw numbers hidden when a score is present to avoid overlap with the score value
* Cup scorecard support — [lgw_submit cup="cup-id"] pre-fills the division with the cup name and shows a match selector from the drawn bracket

= 6.0.8 =
* Fixed undefined variable $drawn warning on line 104 — $drawn was used in the shortcode header before being defined

= 6.0.7 =
* Fixed blank vs TBD match in 17-team draw — round names had erroneous array_reverse causing an extra skeleton round
* Round names now correct for prelim-format cups: Preliminary Round, Round of 16, Quarter Final, Semi-Final, Final
* Edit button removed from public cup page
* Cup widget now sets explicit light-mode CSS variables for standalone use
* Score entry: admins can click any match card to enter scores via a popover; winner is automatically advanced to the next round on save

= 6.0.6 =
* "Perform Draw" button is now hidden on the public page once the draw has been completed — both server-side (PHP) and immediately in the browser after the draw animation finishes

= 6.0.5 =
* Draw animation now includes the full Round 2 draw for prelim-format cups — after the prelim matches are revealed, a section header separates them and all Round 2 pairings (including "Prelim Winner" placeholders) are drawn live in sequence

= 6.0.4 =
* Fixed byes logic — prelim round now contains only the overflow matches (n minus half), with remaining teams going straight to the main round; 17 teams gives 1 prelim then 8 main-round matches

= 6.0.3 =
* Fixed "headers already sent" warning when saving cup — POST handler and draw reset/delete actions moved to admin_init hook so redirects fire before any page output

= 6.0.2 =
* Draw now enforces club home-conflict rule — teams from the same club cannot both be the home team in Round 1 on the same date; home/away assignment is adjusted automatically after the random draw, with a same-club match (the one unavoidable exception) left in drawn order

= 6.0.1 =
* Cup bracket widget — new [lgw_cup] shortcode renders a single-elimination knockout bracket with mobile-friendly round tabs and team badges
* Live animated draw — admin triggers the draw from wp-admin or the public page; visitors watching at the time see an animated team-reveal sequence in real time via polling
* Cup management — LGW → Cups admin page to create and configure cups: name, entries, round names, dates, optional Google Sheets CSV URL for result sync
* Results from Google Sheets — cup results can be synced from a published CSV matching the existing bracket spreadsheet format
* Draw reset — admin can clear and redo the draw at any time before results are recorded
* Dark mode and theme CSS variable support inherited from division widget

= 5.18.3 =
* Import Passphrases tool — upload the club passphrases xlsx directly from wp-admin (LGW → Import Passphrases) to set all club passphrases in one go. Tool removes itself from the menu when dismissed.

= 5.18.1 =
* PIN authentication replaced with passphrase authentication — clubs now log in with a three-word passphrase instead of a numeric PIN
* what3words address for the clubhouse recommended as a default passphrase (e.g. filled.count.ripen)
* Passphrase input is case-insensitive and whitespace-tolerant — filled.count.ripen and Filled.Count.Ripen both work
* Admin settings updated with passphrase column, format hint, and what3words tip
* Login form updated with plain-text input, format hint, and autocapitalise disabled for mobile

= 5.17.10 =
* Fixed "headers already sent" error on theme reset — handler moved from lgw_settings_page() to admin_init hook so redirect runs before any output

= 5.17.9 =
* Fixed ReferenceError: widget is not defined in showTeamModal — widget element now passed as parameter through showTeamModal → openModal rather than assumed in scope

= 5.17.8 =
* Fixed ReferenceError: wEl is not defined — modal CSS variable propagation now correctly passes the widget element as a parameter to openModal rather than referencing an undeclared variable

= 5.17.7 =
* Fixed theme colour saves — colour picker sync JS was placed in the scorecard admin page instead of the settings page, so picking a colour never updated the hex field that gets submitted

= 5.17.6 =
* Fixed theme colours resetting on save — colour picker inputs had duplicate name attributes, causing the hex text field value to be overwritten. Name attribute removed from pickers; hex fields are the single submitted value.

= 5.17.5 =
* Fixed undefined array key warnings on theme colour inputs when no theme has been saved yet

= 5.17.4 =
* Customisable theme colours — primary, secondary (gold), and background colours can be set globally in widget settings and overridden per-shortcode via color_primary, color_secondary, color_bg attributes. Modal inherits widget theme.

= 5.17.3 =
* Sponsor bar width fix — moved max-width/margin constraints to outer wrapper so sponsor bar matches table width correctly

= 5.17.2 =
* League table column detection now reads header row dynamically — fixes half points (e.g. 76.5) being truncated to integers when sheet has variable empty columns between fields

= 5.17.1 =
* Sponsor bar now constrained to widget width via wrapper div — no longer stretches full page width

= 5.17.0 =
* Scorecard lookup now falls back to normalised team name matching when exact slug key doesn't match — fixes "No scorecard submitted yet" when CSV team name differs from submitted name (e.g. "U. Transport A" vs "Ulster Transport A")

= 5.16.0 =
* Fixed JS syntax error (missing closing brace) that broke tab switching and scorecard submission
* Team name validation now runs actively on submit — blocks club-name-only entries even without blurring fields
* Date field normalises freeform dates (e.g. "10th May 2025") to dd/mm/yyyy on blur and after AI parse
* AI photo parse prompt updated to request dd/mm/yyyy directly
* Fixed missing lgw_safe_filename() function causing Drive upload fatal after admin edit
* Drive folders now use full team name (e.g. "Dunbarton A") not stripped club prefix
* Drive API errors now surfaced in Drive log rather than silently failing
* Added OAuth 2.0 support for Drive uploads — works with personal Gmail accounts
* Service account JWT retained as fallback for Sheets writeback
* Admin edit handler wrapped in try/catch — Drive/Sheets errors no longer return HTTP 500
* Fixed variable name collision in lgw_ajax_get_division_teams

= 5.15.0 =
* Scorecard submission now allowed even when division name is unrecognised — admin can correct via wp-admin
* Admin scorecards list shows ⚠ Unresolved badge on affected scorecards
* Admin edit form highlights division field in red with known divisions listed when unresolved
* Clearing division to a valid value automatically retries Google Sheets writeback
* Meaningful save error messages — JSON parse detail, session expiry, network errors surfaced clearly
* Division → CSV URL mapping added to sheet tab settings (used for team name validation)
* Team name validation on scorecard form — checks each field against division team list from CSV
* Fixture pairing validation — detects unknown pairings, home/away swaps, and missing suffixes (e.g. "Belmont" → "Belmont A")
* Single-click correction offered for all fixable name issues

= 5.2 =
* Fixed photo parsing — model name corrected to claude-sonnet-4-5
* Added HTTP status check on API response — surfaces real error messages instead of generic failure
* Increased max_tokens to 2000 to avoid truncated responses
* Improved error messages include raw API response excerpt for easier diagnosis
* Increased API timeout to 40s

= 5.1 =
* Scorecard submission feature — new [lgw_submit] shortcode
* PIN-gated score entry form (no WordPress login needed)
* AI photo reading via Anthropic API — upload a photo, form pre-fills automatically
* Excel upload parsing — reads LGW scorecard template directly
* Manual entry form with 4 rinks, player names, scores, totals
* Scorecard storage as custom post type
* Played fixture rows clickable — shows full rink-by-rink scorecard in modal
* New Scorecards admin page for viewing and deleting submissions
* Score Entry PIN and Anthropic API key settings

= 5.0 =
* Club-level badge configuration — set a badge once for a club and it matches all teams with that prefix (e.g. MALONE covers MALONE A, B, C)
* Exact team badges still supported and always take priority over club prefix matches
* Longest matching prefix wins when multiple club entries could match
* Badge admin UI updated with Type column (Club prefix / Exact)

= 4.9 =
* Fixed modal header and buttons being clipped in Brave browser
* Replaced inset:0 shorthand with explicit top/right/bottom/left for cross-browser compatibility
* Switched modal from vertical centering to top-anchored with padding to prevent viewport clipping

= 4.8 =
* Fixed print speed — removed Google Fonts load, dialog now appears immediately
* Fixed modal print badge oversized
* Fixed modal print stats appearing vertically instead of horizontally

= 4.7 =
* Fixed modal window appearing transparent
* Fixed league table columns bleeding behind sticky team/pos columns on mobile scroll
* Fixed fixtures print preview not generating on mobile
* Dark mode now applied via :root CSS variables so all elements including modal inherit correctly

= 4.6 =
* Dark mode — auto follows device/OS, manual toggle button on widget, preference remembered per device
* Printer icon replaced with SVG (renders correctly on all mobile browsers)
* Team name added to modal header alongside badge
* Print layout fixed — logos constrained to sensible sizes
* Print button added to league table and fixtures tabs
* Accessibility — promotion/relegation zones now show ▲/▼ symbols alongside colour
* Modal results show W/D/L label alongside colour coding
* All colours refactored to CSS variables for consistency

= 4.5 =
* Team modal — click any team name in league table or fixtures to see their full record and fixture list
* Print button in modal header opens a clean print-friendly view

= 4.4 =
* Fixed Check for Updates Now button not appearing on settings page

= 4.3 =
* Added sponsor logos — primary sponsor above title, additional sponsors rotate randomly below league table
* Per-division sponsor override via shortcode parameters

= 4.2 =
* Version number now defined as single constant — only one place to update per release

= 4.1 =
* Added "Check for Updates Now" button to settings page

= 4.0 =
* Added GitHub auto-updater
* Font updated to Saira throughout

= 3.1 =
* Font updated to Saira
* Version tracking introduced

= 3.0 =
* Added promotion/relegation zones with clinched shading
* Added server-side caching with configurable duration and manual clear
* Added title shortcode parameter
* Added club badges via Media Library

= 2.0 =
* Moved to shortcode-based approach to avoid WordPress script stripping
* Added CSV proxy via WordPress ajax

= 1.0 =
* Initial release

= 7.1.32 =
* Preview confirmation popup before saving: clicking Save now shows a full scorecard preview; users can click "← Edit" to return to the form or "✅ Confirm & Save" to proceed
* New player highlighting in preview: players not yet in the database, or with no appearances this season, are shown in green with a NEW badge
* Ladies player highlighting: names entered with an asterisk (*) are shown in purple with a ♀ badge in the preview; the * is stripped before saving as before
* Player name boxes changed to auto-expanding textareas — they grow horizontally and vertically to show all names without clipping
* New AJAX endpoint lgw_check_new_players: checks a list of player names against the DB and season appearances before showing the preview

= 7.1.33 =
* Login dropdown in fixture modal now shows only the two clubs involved in that fixture — filters the full club list using the same prefix-matching logic as passphrase auth; falls back to showing all clubs if no match is found
* Team mismatch validation on save: if the submitted home/away team names don't match the fixture (in either order), save is blocked with a clear message — "This fixture is X v Y — the scorecard appears to be for a different game"
* Mismatch check tolerates case differences and club prefix variations (e.g. "U. Transport A" vs "Ulster Transport A") before rejecting

= 7.1.34 =
* Drive and Sheets writeback now skip silently for scorecards from archived seasons — logs a clear ℹ️ "Skipped — scorecard belongs to archived season X" info entry instead of OAuth/tab errors
* Sheets Retry button hidden when all log entries are informational (e.g. archived season skip); still shown for genuine warn/error entries that may be actionable
* Drive log renderer now correctly styles info (grey), warn (amber) and success (green) entries; previously only error/success were styled
* lgw_scorecard_is_active_season() helper added to lgw-seasons.php — returns true when the scorecard's lgw_sc_season tag matches the active season, or when no season system is in use

= 7.1.35 =
* Fixture modal now shows confirm/amend actions inline when a pending scorecard exists and the logged-in club is the second club (i.e. not the submitter)
* Confirm: marks the scorecard as confirmed immediately, updates the badge in place
* Amend: replaces the scorecard view with the submission form pre-filled with the existing scores — submitting different scores marks the result as disputed for admin review
* lgw_get_scorecard AJAX response now includes _id (post ID) so the confirm action can reference the correct record without a second lookup
* lgwClubMatchesTeamStr helper added (module-level) — mirrors PHP lgw_club_matches_team for submitted_by comparisons in JS

= 7.1.36 =
* Duplicate player name detection: if the same name appears more than once on the same team across any rinks, save is blocked with a message asking the user to use Sr/Jr suffix or enter the full name to distinguish the two individuals
* Live duplicate warning shown as names are typed — amber notice appears below the rink table without blocking input, so the user can see the issue as it develops
* Preview popup shows a DUP badge (amber) on any duplicated name and includes it in the legend
* Duplicate check is case-insensitive and strips asterisks before comparing, so "J Smith*" and "J Smith" are treated as the same name
