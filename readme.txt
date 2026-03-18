=== NIPGL Division Widget ===
Contributors: dbinterz
Tags: bowls, sports, league table, fixtures, google sheets
Requires at least: 5.0
Tested up to: 6.5
Stable tag: 6.4.29
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

Cup bracket:
`[nipgl_cup id="senior-cup-2025" title="Senior Cup 2025"]`

Parameters:
* `id` — required. The cup ID set in NIPGL → Cups admin page
* `title` — optional override for the cup title displayed in the widget header



1. Upload the plugin zip via Plugins > Add New > Upload Plugin
2. Activate the plugin
3. Go to Settings > NIPGL Widget to configure club badges and cache settings
4. Add the shortcode to each division page

== Changelog ==

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
* Fixed final stage bracket showing "Preliminary Round / Final" instead of "Semi-Final / Final" — final draw now passes nipgl_draw_default_rounds as stored_rounds so round names reflect the full bracket size

= 6.4.23 =
* Fixed 500 error when entering the last result in a championship section — nipgl_champ_try_seed_final called undefined function nipgl_champ_make_skeleton_bracket; replaced with nipgl_champ_perform_final_draw which performs the full final stage draw automatically

= 6.4.22 =
* Fixed championship section tabs not switching — clicking a section tab now correctly shows that section's pane (the DOM switching was dropped when the inline script was removed in v6.4.20; initSectionTabs was only saving to sessionStorage, not updating active classes)

= 6.4.21 =
* Code quality: shared draw library extracted to nipgl-draw.php — bracket geometry, animation pairs, and skeleton-round assembly now live in one place (nipgl_draw_build_bracket, nipgl_draw_default_rounds, nipgl_draw_cup_club); cup and champ draw functions refactored to thin wrappers supplying their own club/home-limit callbacks

= 6.4.20 =
* Robustness: bracket size check added at draw time — rejects writes exceeding 800KB to prevent option corruption
* Code quality: inline admin JS moved to nipgl-admin.js (cup draw, cup sync, champ draw buttons)
* Code quality: redundant inline tab-switching script removed from champ shortcode (handled by nipgl-champ.js)
* Build: GitHub Actions version check extended to validate NIPGL_VERSION constant and readme.txt stable tag

= 6.3.0 =
* Fixed empty print/PDF — replaced body > * visibility approach with visibility:hidden on all + visibility:visible on cup wrap, which works at any nesting depth; all rounds forced visible before print dialog opens

= 6.2.9 =
* Print Draw button appears in the bracket header after the draw is complete — prints a clean draw sheet hiding UI chrome
* Clicking a completed match card shows the submitted scorecard in a modal (rink-by-rink with scores, players, winner highlighted, confirmation status)
* Cup scorecards already feed into player appearance records automatically via the existing [nipgl_submit cup="..."] confirmation flow — no extra config needed

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
* Fixed "unexpected token" error on mobile after passphrase entry — ajaxUrl now always sourced from nipglCupData; post() helper parses response as text first so non-JSON server responses produce a readable error

= 6.1.5 =
* Fixed draw animation showing next match teams before the reveal — text is now set inside the timeout, not before it
* Draw animation speed configurable in NIPGL > Cups (0.5× fast to 2× slow); default 1× = 2.6s per match
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
* Draw passphrase setting moved from Settings > NIPGL Widget to the Cups admin page (NIPGL > Cups)

= 6.1.1 =
* Draw passphrase gate now applies to everyone on the public page including WP admins — the 🔑 Login to Draw button is shown to all visitors; the wp-admin inline draw button retains direct access for admins

= 6.1.0 =
* Draw passphrase gate — a global draw passphrase can be set in Settings > NIPGL Widget; when set, the public cup page shows a "Login to Draw" button instead of the draw button; the user enters the passphrase in a modal and on success the draw is unlocked for their browser session; WP admins bypass the gate entirely

= 6.0.10 =
* Winner row: lighter green background (#e6f4e6) with dark green text (#1a5c1a)
* Loser row: light red background (#fdf0f0) with dark red text (#8b1a1a)
* Score popover team names hardcoded to #1a1a1a for reliable contrast regardless of page theme

= 6.0.9 =
* Fixed score input contrast — explicit white background and dark text on score popover inputs
* Draw numbers hidden when a score is present to avoid overlap with the score value
* Cup scorecard support — [nipgl_submit cup="cup-id"] pre-fills the division with the cup name and shows a match selector from the drawn bracket

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
* Cup bracket widget — new [nipgl_cup] shortcode renders a single-elimination knockout bracket with mobile-friendly round tabs and team badges
* Live animated draw — admin triggers the draw from wp-admin or the public page; visitors watching at the time see an animated team-reveal sequence in real time via polling
* Cup management — NIPGL → Cups admin page to create and configure cups: name, entries, round names, dates, optional Google Sheets CSV URL for result sync
* Results from Google Sheets — cup results can be synced from a published CSV matching the existing bracket spreadsheet format
* Draw reset — admin can clear and redo the draw at any time before results are recorded
* Dark mode and theme CSS variable support inherited from division widget

= 5.18.3 =
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
