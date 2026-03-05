=== NIPGL Division Widget ===
Contributors: dbinterz
Tags: bowls, sports, league table, fixtures, google sheets
Requires at least: 5.0
Tested up to: 6.5
Stable tag: 5.2
License: GPLv2 or later

Mobile-friendly league table and fixtures widget for NIPGL, powered by Google Sheets CSV.

== Description ==

Renders a tabbed widget showing a league table and fixtures/results for each NIPGL division, fetched live from a published Google Sheet. Features include:

* Mobile-responsive with sticky team column
* Club badge support via WordPress Media Library
* Promotion and relegation zone highlighting
* Clinched promotion/relegation shading
* Server-side caching to speed up page loads
* All/Results/Upcoming fixture filters

Use the shortcode on any page:
`[nipgl_division csv="YOUR_CSV_URL" title="Division 1" promote="2" relegate="2"]`

== Installation ==

1. Upload the plugin zip via Plugins > Add New > Upload Plugin
2. Activate the plugin
3. Go to Settings > NIPGL Widget to configure club badges and cache settings
4. Add the shortcode to each division page

== Changelog ==

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
