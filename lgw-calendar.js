/* LGW Calendar Widget JS - v7.1.16 */
(function () {
  'use strict';

  var ajaxUrl = (typeof lgwCalendarData !== 'undefined') ? lgwCalendarData.ajaxUrl : '/wp-admin/admin-ajax.php';

  var MONTHS       = ['January','February','March','April','May','June',
                      'July','August','September','October','November','December'];
  var SHORT_MONTHS = ['Jan','Feb','Mar','Apr','May','Jun',
                      'Jul','Aug','Sep','Oct','Nov','Dec'];
  var DAY_NAMES    = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];

  // ── Colour coding ─────────────────────────────────────────────────────────
  var COLOUR_RULES = [
    { cls: 'lgw-cal-c-red',        label: 'League / Inter-Association',
      test: function(t){ return /league division|division 4|inter.assoc|inter-assoc/i.test(t); } },
    { cls: 'lgw-cal-c-magenta',    label: 'Cup',
      test: function(t){ return /cup/i.test(t); } },
    { cls: 'lgw-cal-c-green',      label: 'IBA Cups',
      test: function(t){ return /\biba\b/i.test(t); } },
    { cls: 'lgw-cal-c-yellow',     label: 'Championships Singles',
      test: function(t){ return /championships singles/i.test(t); } },
    { cls: 'lgw-cal-c-lime',       label: 'Championships Pairs',
      test: function(t){ return /championships pairs/i.test(t) && !/junior|senior|over|u25/i.test(t); } },
    { cls: 'lgw-cal-c-blue',       label: 'Championships Fours / U25',
      test: function(t){ return /championships fours|u25 singles/i.test(t); } },
    { cls: 'lgw-cal-c-purple',     label: 'Championships Triples',
      test: function(t){ return /championships triples/i.test(t); } },
    { cls: 'lgw-cal-c-amber',      label: 'Championships Senior Fours',
      test: function(t){ return /championships senior fours/i.test(t); } },
    { cls: 'lgw-cal-c-salmon',     label: 'Championships Over-55',
      test: function(t){ return /over.55/i.test(t); } },
    { cls: 'lgw-cal-c-lightblue',  label: 'Championships Junior Fours',
      test: function(t){ return /championships junior fours/i.test(t); } },
    { cls: 'lgw-cal-c-lightgreen', label: 'Championships Junior Pairs',
      test: function(t){ return /championships junior pairs/i.test(t); } },
    { cls: 'lgw-cal-c-cyan',       label: 'Midweek League',
      test: function(t){ return /midweek league/i.test(t); } },
    { cls: 'lgw-cal-c-darkblue',   label: 'IBF / International',
      test: function(t){ return /\bibf\b|international|commonwealth|atlantic|jpl\b/i.test(t); } },
    { cls: 'lgw-cal-c-teal',       label: 'U18 Singles',
      test: function(t){ return /u18 singles/i.test(t); } },
    { cls: 'lgw-cal-c-grey',       label: 'Inter-Association / Trials',
      test: function(t){ return /inter.assoc|home nations|minnis|pgl|trial|\bjunior i/i.test(t); } },
    { cls: 'lgw-cal-c-navy',       label: 'Association Event',
      test: function(t){ return /flag unfurling|unfurl/i.test(t); } },
  ];

  function eventColourClass(text) {
    for (var i = 0; i < COLOUR_RULES.length; i++) {
      if (COLOUR_RULES[i].test(text)) return COLOUR_RULES[i].cls;
    }
    return '';
  }

  function buildLegend(events) {
    var seen = {}, items = [];
    events.forEach(function(ev) {
      var cls = eventColourClass(ev.event);
      if (!cls || seen[cls]) return;
      seen[cls] = true;
      for (var i = 0; i < COLOUR_RULES.length; i++) {
        if (COLOUR_RULES[i].cls === cls) { items.push({ cls: cls, label: COLOUR_RULES[i].label }); break; }
      }
    });
    if (!items.length) return '';
    var html = '<div class="lgw-cal-legend">';
    items.forEach(function(it) {
      html += '<span class="lgw-cal-legend-item"><span class="lgw-cal-legend-swatch ' + it.cls + '"></span>' + escHtml(it.label) + '</span>';
    });
    return html + '</div>';
  }

  // ── Month name helpers ────────────────────────────────────────────────────
  var MONTH_NAMES_FULL = ['january','february','march','april','may','june',
                          'july','august','september','october','november','december'];

  function monthIndexFromName(str) {
    var s = str.trim().toLowerCase();
    var fi = MONTH_NAMES_FULL.indexOf(s);
    if (fi !== -1) return fi;
    return SHORT_MONTHS.map(function(m){ return m.toLowerCase(); }).indexOf(s.slice(0,3));
  }

  // ── Detect a date-number row ──────────────────────────────────────────────
  function isDateRow(rowCells) {
    var numCount = 0, nonNumNonEmpty = 0;
    for (var c = 1; c <= 7; c++) {
      var v = (rowCells[c] && rowCells[c].v) ? String(rowCells[c].v).trim() : '';
      if (!v) continue;
      var n = parseFloat(v);
      var isInt = !isNaN(n) && n >= 1 && n <= 31 && (Math.floor(n) === n || v.match(/\.0+$/));
      if (isInt) { numCount++; } else { nonNumNonEmpty++; }
    }
    return numCount >= 3 && nonNumNonEmpty === 0;
  }

  // ── Parse the xlsx cell/merge data into a flat event list ─────────────────
  // ── Parse xlsx data into flat event list ──────────────────────────────────
  // Structure: fixed 2 event rows per week band, always after the date row.
  // Rule: every event row belongs to the most recently seen date row.
  function parseEvents(data) {
    var cells  = data.cells  || {};
    var merges = data.merges || {};
    var events = [];

    var rowNums = Object.keys(cells).map(Number).sort(function(a,b){ return a-b; });

    var ctxYear      = new Date().getFullYear();
    var ctxMonth     = -1;
    var ctxFirstWeek = false;
    var weekDates    = null;
    var weekMonths   = null;
    var weekYears    = null;

    for (var ri = 0; ri < rowNums.length; ri++) {
      var rowNum   = rowNums[ri];
      var rowCells = cells[rowNum] || {};

      // ── Title row: extract year ─────────────────────────────────────────
      var allText = '';
      for (var c = 1; c <= 7; c++) allText += (rowCells[c] && rowCells[c].v) ? rowCells[c].v : '';
      var ymMatch = allText.match(/20[0-9][0-9]/);
      if (ymMatch && allText.toUpperCase().indexOf('CALENDAR') !== -1) {
        ctxYear = parseInt(ymMatch[0], 10); continue;
      }

      // ── Month header ────────────────────────────────────────────────────
      var nonEmpty = [];
      for (var c = 1; c <= 7; c++) {
        if (rowCells[c] && rowCells[c].v && rowCells[c].v.trim()) nonEmpty.push(rowCells[c].v.trim());
      }
      if (nonEmpty.length === 1) {
        var mi = monthIndexFromName(nonEmpty[0]);
        if (mi !== -1) { ctxMonth = mi; ctxFirstWeek = true; continue; }
      }

      // ── Day-of-week header: skip ────────────────────────────────────────
      var fv = (rowCells[1] && rowCells[1].v) ? rowCells[1].v.trim().toUpperCase() : '';
      if (fv === 'MONDAY' || fv === 'MON') continue;

      // ── Date-number row ─────────────────────────────────────────────────
      if (isDateRow(rowCells)) {
        if (ctxMonth === -1) continue;
        weekDates = {}; weekMonths = {}; weekYears = {};
        var rawDays = {};
        for (var c = 1; c <= 7; c++) {
          var v = (rowCells[c] && rowCells[c].v) ? String(rowCells[c].v).trim() : '';
          rawDays[c] = v ? Math.floor(parseFloat(v)) : null;
        }
        // Find month-boundary reset (e.g. 30→1)
        var resetCol = -1;
        for (var c = 2; c <= 7; c++) {
          if (rawDays[c] !== null && rawDays[c-1] !== null &&
              rawDays[c] < rawDays[c-1] && rawDays[c-1] > 20 && rawDays[c] <= 7) {
            resetCol = c; break;
          }
        }
        var prevMonth = (ctxMonth - 1 + 12) % 12;
        var nextMonth = (ctxMonth + 1)       % 12;
        var prevYear  = (ctxMonth === 0)  ? ctxYear - 1 : ctxYear;
        var nextYear  = (ctxMonth === 11) ? ctxYear + 1 : ctxYear;

        for (var c = 1; c <= 7; c++) {
          weekDates[c] = rawDays[c];
          if (resetCol === -1) {
            weekMonths[c] = ctxMonth; weekYears[c] = ctxYear;
          } else if (ctxFirstWeek) {
            // First week of section: cols before reset = prev month tail
            weekMonths[c] = (c < resetCol) ? prevMonth : ctxMonth;
            weekYears[c]  = (c < resetCol) ? prevYear  : ctxYear;
          } else {
            // Later weeks: cols from reset = next month start
            weekMonths[c] = (c < resetCol) ? ctxMonth  : nextMonth;
            weekYears[c]  = (c < resetCol) ? ctxYear   : nextYear;
          }
        }
        ctxFirstWeek = false;
        continue;
      }

      // ── Event row: belongs to the current (last-seen) date row ──────────
      if (!weekDates || ctxMonth === -1) continue;

      var c = 1;
      while (c <= 7) {
        var cellData = rowCells[c];
        var text     = (cellData && cellData.v) ? cellData.v.trim() : '';
        if (!text || !weekDates[c]) { c++; continue; }

        // Determine span from merge map
        var mergeKey  = rowNum + ':' + c;
        var mergeSpan = (merges[mergeKey] && merges[mergeKey] > 1) ? merges[mergeKey] : 1;
        var startCol  = c;
        var endCol    = c;

        if (mergeSpan > 1) {
          var spanEnd = c + mergeSpan - 1;
          for (var mc = c + 1; mc <= spanEnd && mc <= 7; mc++) {
            if (weekDates[mc] !== null && weekDates[mc] !== undefined) endCol = mc;
          }
        }

        var startDay   = weekDates[startCol];
        var endDay     = weekDates[endCol];
        var startMonth = weekMonths[startCol] !== undefined ? weekMonths[startCol] : ctxMonth;
        var endMonth   = weekMonths[endCol]   !== undefined ? weekMonths[endCol]   : ctxMonth;
        var startYr    = weekYears[startCol]  !== undefined ? weekYears[startCol]  : ctxYear;

        var dStart  = new Date(startYr, startMonth, startDay);
        var dateStr;
        if (endDay && (endDay !== startDay || endMonth !== startMonth)) {
          dateStr = startMonth === endMonth
            ? (startDay + '\u2013' + endDay + ' ' + SHORT_MONTHS[startMonth])
            : (startDay + ' ' + SHORT_MONTHS[startMonth] + '\u2013' + endDay + ' ' + SHORT_MONTHS[endMonth]);
        } else {
          dateStr = DAY_NAMES[dStart.getDay()] + ' ' + startDay + ' ' + SHORT_MONTHS[startMonth];
        }

        events.push({ date: dStart, dateStr: dateStr, event: text,
                      year: startYr, month: startMonth, day: startDay });

        c += mergeSpan;
      }
    }

    console.log('[LGW Calendar] Parsed ' + events.length + ' events');
    events.sort(function(a, b){ return a.date - b.date; });
    return events;
  }



  // ── Group / find initial month ────────────────────────────────────────────
  function groupByMonth(events) {
    var map = {}, keys = [];
    events.forEach(function(ev) {
      var k = ev.year + '-' + ev.month;
      if (!map[k]) { map[k] = { year: ev.year, month: ev.month, events: [] }; keys.push(k); }
      map[k].events.push(ev);
    });
    return keys.sort().map(function(k){ return map[k]; });
  }

  function findInitialMonth(mgs) {
    var now = new Date(), ny = now.getFullYear(), nm = now.getMonth();
    for (var i = 0; i < mgs.length; i++) if (mgs[i].year === ny && mgs[i].month === nm) return i;
    for (var i = 0; i < mgs.length; i++) if (mgs[i].year > ny || (mgs[i].year === ny && mgs[i].month > nm)) return i;
    return mgs.length - 1;
  }

  function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function scrollActiveTab(wrap) {
    var a = wrap.querySelector('.lgw-cal-tab.active');
    if (a && a.scrollIntoView) a.scrollIntoView({ inline:'center', block:'nearest', behavior:'smooth' });
  }

  // ── View preference (list / table) ───────────────────────────────────────
  var VIEW_KEY = 'lgw_cal_view';
  function getViewPref() {
    try { var v = localStorage.getItem(VIEW_KEY); if (v === 'table' || v === 'list') return v; } catch(e) {}
    return 'list';
  }
  function setViewPref(v) { try { localStorage.setItem(VIEW_KEY, v); } catch(e) {} }

  // ── Render list view ──────────────────────────────────────────────────────
  function renderList(mg) {
    var groupKeys = [], byDateStr = {};
    mg.events.forEach(function(ev) {
      var k = ev.dateStr;
      if (!byDateStr[k]) { byDateStr[k] = []; groupKeys.push(k); }
      byDateStr[k].push(ev);
    });
    var html = '<div class="lgw-cal-list">';
    if (!groupKeys.length) {
      html += '<div class="lgw-cal-empty">No events this month.</div>';
    } else {
      groupKeys.forEach(function(key) {
        html += '<div class="lgw-cal-day-group"><div class="lgw-cal-day-hdr">' + escHtml(key) + '</div>';
        byDateStr[key].forEach(function(ev) {
          var cc = eventColourClass(ev.event);
          html += '<div class="lgw-cal-event' + (cc?' '+cc:'') + '">'
                + '<div class="lgw-cal-event-title">' + escHtml(ev.event) + '</div>'
                + '</div>';
        });
        html += '</div>';
      });
    }
    return html + '</div>';
  }

  // ── Render table (grid) view ──────────────────────────────────────────────
  function renderTable(mg) {
    var DAY_HDRS = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];

    // Build a map: day-of-month -> array of events
    // For multi-day events, add to every day in the span using the actual end date
    var byDay = {};
    mg.events.forEach(function(ev) {
      // Parse the end date from dateStr so we know exactly when to stop
      // dateStr formats: "Mon 3 Aug" (single) or "3-8 Aug" or "30 Jul-2 Aug" (range)
      var endDate = ev.date; // default: single day
      var dash = ev.dateStr.indexOf('–');
      if (dash !== -1) {
        // Multi-day: parse end portion
        var endPart = ev.dateStr.slice(dash + 1).trim(); // e.g. "8 Aug" or "2 Aug"
        var ep = endPart.match(/^(\d+)\s+([A-Za-z]+)/);
        if (ep) {
          var endDay   = parseInt(ep[1], 10);
          var endMonStr = ep[2].charAt(0).toUpperCase() + ep[2].slice(1,3).toLowerCase();
          var endMon   = SHORT_MONTHS.indexOf(endMonStr);
          if (endMon === -1) endMon = ev.month; // fallback
          var endYr    = (endMon < ev.month) ? ev.year + 1 : ev.year;
          endDate = new Date(endYr, endMon, endDay);
        }
      }

      var d = new Date(ev.date.getTime());
      var safety = 0;
      while (safety++ < 35) {
        // Stop if past end date or left this month's display range
        if (d > endDate) break;
        if (d.getFullYear() === mg.year && d.getMonth() === mg.month) {
          var dn = d.getDate();
          if (!byDay[dn]) byDay[dn] = [];
          byDay[dn].push(ev);
        }
        d.setDate(d.getDate() + 1);
        // Also stop if we've left the month and the event doesn't span into next
        if (d.getMonth() !== mg.month && d > endDate) break;
      }
    });

    // Build calendar grid weeks
    // Find first day of month (Mon=0 ... Sun=6 in our grid)
    var firstDate = new Date(mg.year, mg.month, 1);
    var firstDow  = (firstDate.getDay() + 6) % 7; // convert Sun=0 to Mon=0
    var daysInMonth = new Date(mg.year, mg.month + 1, 0).getDate();

    // Build weeks: array of arrays of 7 cells, each cell = dayNum or null
    var weeks = [];
    var week  = [];
    for (var pad = 0; pad < firstDow; pad++) week.push(null);
    for (var day = 1; day <= daysInMonth; day++) {
      week.push(day);
      if (week.length === 7) { weeks.push(week); week = []; }
    }
    if (week.length) {
      while (week.length < 7) week.push(null);
      weeks.push(week);
    }

    var today = new Date();
    var todayDay = (today.getFullYear() === mg.year && today.getMonth() === mg.month) ? today.getDate() : -1;

    var html = '<div class="lgw-cal-table-wrap"><table class="lgw-cal-table"><thead><tr>';
    DAY_HDRS.forEach(function(d) { html += '<th>' + d + '</th>'; });
    html += '</tr></thead><tbody>';

    weeks.forEach(function(w) {
      html += '<tr>';
      w.forEach(function(dayNum) {
        if (!dayNum) {
          html += '<td class="lgw-cal-td-empty"></td>';
          return;
        }
        var isToday = (dayNum === todayDay);
        html += '<td class="lgw-cal-td' + (isToday ? ' lgw-cal-today' : '') + '">';
        html += '<div class="lgw-cal-td-num">' + dayNum + '</div>';
        var evs = byDay[dayNum] || [];
        evs.forEach(function(ev) {
          var cc = eventColourClass(ev.event);
          html += '<div class="lgw-cal-td-event' + (cc?' '+cc:'') + '">' + escHtml(ev.event) + '</div>';
        });
        html += '</td>';
      });
      html += '</tr>';
    });

    html += '</tbody></table></div>';
    return html;
  }

  // ── Render a month view (list or table) ───────────────────────────────────
  function renderMonth(wrap, mgs, idx, view) {
    view = view || getViewPref();
    var mg = mgs[idx];

    var nav = '<div class="lgw-cal-nav">'
      + '<button class="lgw-cal-nav-btn" data-dir="-1"' + (idx > 0 ? '' : ' disabled') + '>&#8249;</button>'
      + '<div class="lgw-cal-month-label">' + MONTHS[mg.month] + ' ' + mg.year + '</div>'
      + '<button class="lgw-cal-nav-btn" data-dir="1"'  + (idx < mgs.length-1 ? '' : ' disabled') + '>&#8250;</button>'
      + '</div>';

    // Month tab strip
    var tabs = '<div class="lgw-cal-tabs" role="tablist">';
    mgs.forEach(function(m, i) {
      tabs += '<button class="lgw-cal-tab' + (i===idx?' active':'') + '" data-idx="'+i+'" role="tab">'
            + SHORT_MONTHS[m.month] + ' ' + String(m.year).slice(2) + '</button>';
    });
    tabs += '</div>';

    // View toggle
    var toggle = '<div class="lgw-cal-view-toggle">'
      + '<button class="lgw-cal-view-btn' + (view==='list'?' active':'') + '" data-view="list" title="List view">'
      + '<svg viewBox="0 0 16 16" fill="currentColor"><rect x="0" y="1" width="16" height="2.5" rx="1"/><rect x="0" y="6" width="16" height="2.5" rx="1"/><rect x="0" y="11" width="16" height="2.5" rx="1"/></svg>'
      + '</button>'
      + '<button class="lgw-cal-view-btn' + (view==='table'?' active':'') + '" data-view="table" title="Calendar view">'
      + '<svg viewBox="0 0 16 16" fill="currentColor"><rect x="0" y="0" width="4.5" height="4.5" rx="0.5"/><rect x="5.75" y="0" width="4.5" height="4.5" rx="0.5"/><rect x="11.5" y="0" width="4.5" height="4.5" rx="0.5"/><rect x="0" y="5.75" width="4.5" height="4.5" rx="0.5"/><rect x="5.75" y="5.75" width="4.5" height="4.5" rx="0.5"/><rect x="11.5" y="5.75" width="4.5" height="4.5" rx="0.5"/><rect x="0" y="11.5" width="4.5" height="4.5" rx="0.5"/><rect x="5.75" y="11.5" width="4.5" height="4.5" rx="0.5"/><rect x="11.5" y="11.5" width="4.5" height="4.5" rx="0.5"/></svg>'
      + '</button>'
      + '</div>';

    var body = (view === 'table') ? renderTable(mg) : renderList(mg);

    wrap.innerHTML = nav + tabs + toggle + body + buildLegend(mg.events);

    // Nav buttons
    wrap.querySelectorAll('.lgw-cal-nav-btn').forEach(function(btn) {
      btn.addEventListener('click', function() {
        var ni = idx + parseInt(btn.getAttribute('data-dir'), 10);
        if (ni >= 0 && ni < mgs.length) { renderMonth(wrap, mgs, ni, getViewPref()); scrollActiveTab(wrap); }
      });
    });
    // Month tabs
    wrap.querySelectorAll('.lgw-cal-tab').forEach(function(tab) {
      tab.addEventListener('click', function() {
        renderMonth(wrap, mgs, parseInt(tab.getAttribute('data-idx'), 10), getViewPref());
        scrollActiveTab(wrap);
      });
    });
    // View toggle buttons
    wrap.querySelectorAll('.lgw-cal-view-btn').forEach(function(btn) {
      btn.addEventListener('click', function() {
        var v = btn.getAttribute('data-view');
        setViewPref(v);
        renderMonth(wrap, mgs, idx, v);
        scrollActiveTab(wrap);
      });
    });
  }

  // ── Init ──────────────────────────────────────────────────────────────────
  function initCalendar(wrap) {
    var xlsxUrl = wrap.getAttribute('data-xlsx');
    if (!xlsxUrl) {
      wrap.innerHTML = '<div class="lgw-cal-status lgw-cal-error">No xlsx URL configured.</div>';
      return;
    }
    var url = ajaxUrl + '?action=lgw_cal_xlsx&url=' + encodeURIComponent(xlsxUrl);
    var xhr = new XMLHttpRequest();
    xhr.open('GET', url);
    xhr.onload = function() {
      try {
        var data = JSON.parse(xhr.responseText);
        if (data && data.success === false) {
          wrap.innerHTML = '<div class="lgw-cal-status lgw-cal-error">&#9888; ' + escHtml(data.data || 'Error loading calendar') + '</div>';
          return;
        }
        var events = parseEvents(data);
        if (!events.length) { wrap.innerHTML = '<div class="lgw-cal-status">No events found in calendar.</div>'; return; }
        var mgs = groupByMonth(events);
        renderMonth(wrap, mgs, findInitialMonth(mgs));
        scrollActiveTab(wrap);
      } catch(e) {
        wrap.innerHTML = '<div class="lgw-cal-status lgw-cal-error">&#9888; Could not parse calendar data.</div>';
      }
    };
    xhr.onerror = function() {
      wrap.innerHTML = '<div class="lgw-cal-status lgw-cal-error">&#9888; Network error.</div>';
    };
    xhr.send();
  }

  function init() { document.querySelectorAll('.lgw-cal-wrap[data-xlsx]').forEach(function(w){ initCalendar(w); }); }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
