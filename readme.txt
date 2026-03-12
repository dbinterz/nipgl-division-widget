=== NIPGL Division Widget ===
Contributors: dbinterz
Tags: bowls, sports, league table, fixtures, google sheets
Requires at least: 5.0
Tested up to: 6.5
Stable tag: 5.18.2
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
`[nipgl_division csv="URL" title="Division 1"]`

All parameters:
* `csv` — required. Published Google Sheets CSV URL
* `title` — division name shown above the widget
* `promote` — number of promotion places to highlight (default 0)
* `relegate` — number of relegation places to highlight (default 0)
* `sponsor_img` — override primary sponsor image URL for this division
* `sponsor_url` — override primary sponsor link URL for this division
* `sponsor_name` — override primary sponsor alt text for this division

Scorecard submission form:
`[nipgl_submit]`

== Installation ==

1. Upload the plugin zip via Plugins > Add New > Upload Plugin
2. Activate the plugin
3. Go to Settings > NIPGL Widget to configure club badges and cache settings
4. Add the shortcode to each division page

== Changelog ==

= 5.18.2 =
* Import Passphrases tool — upload the club passphrases xlsx directly from wp-admin (NIPGL → Import Passphrases) to set all club passphrases in one go. Tool removes itself from the menu when dismissed.

= 5.18.1 =
* PIN authentication replaced with passphrase authentication — clubs now log in with a three-word passphrase instead of a numeric PIN
* what3words address for the clubhouse recommended as a default passphrase (e.g. filled.count.ripen)
* Passphrase input is case-insensitive and whitespace-tolerant — filled.count.ripen and Filled.Count.Ripen both work
* Admin settings updated with passphrase column, format hint, and what3words tip
* Login form updated with plain-text input, format hint, and autocapitalise disabled for mobile

= 5.17.10 =
* Fixed "headers already sent" error on theme reset — handler moved from nipgl_settings_page() to admin_init hook so redirect runs before any output

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
* Fixed missing nipgl_safe_filename() function causing Drive upload fatal after admin edit
* Drive folders now use full team name (e.g. "Dunbarton A") not stripped club prefix
* Drive API errors now surfaced in Drive log rather than silently failing
* Added OAuth 2.0 support for Drive uploads — works with personal Gmail accounts
* Service account JWT retained as fallback for Sheets writeback
* Admin edit handler wrapped in try/catch — Drive/Sheets errors no longer return HTTP 500
* Fixed variable name collision in nipgl_ajax_get_division_teams

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
* Scorecard submission feature — new [nipgl_submit] shortcode
* PIN-gated score entry form (no WordPress login needed)
* AI photo reading via Anthropic API — upload a photo, form pre-fills automatically
* Excel upload parsing — reads NIPGL scorecard template directly
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
