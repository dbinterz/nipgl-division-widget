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

## Changelog

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
