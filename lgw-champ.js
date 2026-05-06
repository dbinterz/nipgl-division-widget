/* LGW Championships JS - v7.1.122 */
(function () {
  'use strict';

  var ajaxUrl    = (typeof lgwData     !== 'undefined' && lgwData.ajaxUrl)
                 ? lgwData.ajaxUrl
                 : (typeof lgwChampData  !== 'undefined' && lgwChampData.ajaxUrl)
                 ? lgwChampData.ajaxUrl
                 : '/wp-admin/admin-ajax.php';
  // Always prefer lgwChampData for badges (always set); fall back to lgwData
  // if the division widget happens to be on the same page.
  var badges     = (typeof lgwChampData !== 'undefined' && lgwChampData.badges)
                 ? lgwChampData.badges
                 : (typeof lgwData !== 'undefined' ? lgwData.badges     : {});
  var clubBadges = (typeof lgwChampData !== 'undefined' && lgwChampData.clubBadges)
                 ? lgwChampData.clubBadges
                 : (typeof lgwData !== 'undefined' ? lgwData.clubBadges : {});

  // ── Badge lookup (same logic as lgw-widget.js) ─────────────────────────────
  function badgeImg(team, cls) {
    if (!team) return '';
    cls = cls || 'lgw-champ-team-badge';
    if (badges[team]) return '<img class="' + cls + '" src="' + badges[team] + '" alt="">';
    var upper = team.toUpperCase();
    for (var key in badges) {
      if (key.toUpperCase() === upper) return '<img class="' + cls + '" src="' + badges[key] + '" alt="">';
    }
    var bestKey = '', bestImg = '';
    for (var club in clubBadges) {
      var cu = club.toUpperCase();
      if (upper === cu || upper.indexOf(cu) === 0) {
        var rest = team.slice(club.length);
        if (rest === '' || rest[0] === ' ') {
          if (club.length > bestKey.length) { bestKey = club; bestImg = clubBadges[club]; }
        }
      }
    }
    if (bestImg) return '<img class="' + cls + '" src="' + bestImg + '" alt="">';
    return '';
  }

  // ── Helpers ───────────────────────────────────────────────────────────────────
  function qs(sel, ctx) { return (ctx || document).querySelector(sel); }
  function qsa(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

  function escHtml(s) {
    return String(s !== null && s !== undefined ? s : '')
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;');
  }

  function post(action, data, cb) {
    var fd = new FormData();
    fd.append('action', action);
    for (var k in data) fd.append(k, data[k]);
    fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.text(); })
      .then(function (text) {
        try {
          cb(JSON.parse(text));
        } catch (e) {
          // Non-JSON response — surface the raw text for easier debugging
          cb({ success: false, data: 'Server returned unexpected response. Please try again.' });
          if (typeof console !== 'undefined') console.error('LGW cup AJAX non-JSON response:', text);
        }
      })
      .catch(function (e) { cb({ success: false, data: 'Network error: ' + e.message }); });
  }

  // ── Bracket rendering ─────────────────────────────────────────────────────────
  //
  // bracket data shape (stored in WP option, served via AJAX):
  // {
  //   title: "Senior Cup 2025",
  //   rounds: ["Round 1","Round 2","Quarter Final","Semi-Final","Final"],
  //   dates:  ["01/05/25", ...],   // optional, one per round
  //   matches: [                   // array of rounds; each round = array of match objects
  //     [ { home:"Ards", away:"Albert Foundry", home_score:null, away_score:null,
  //         draw_num_home:1, draw_num_away:2, bye:false }, ... ],
  //     ...
  //   ]
  // }

  // Extract club name from "Player Name(s), Club" format for badge lookup
  function entryClub(entry) {
    if (!entry) return '';
    var comma = entry.indexOf(',');
    return comma !== -1 ? entry.slice(comma + 1).trim() : '';
  }

  // Abbreviate a championship entry for use as a bracket placeholder.
  // Entry format: "F Surname/G Other, ClubName"  →  "Other, Clu"
  // Uses the last player listed (after the last '/') and the first 3 chars of the club.
  function abbrevChampEntry(entry) {
    if (!entry) return '?';
    var comma = entry.indexOf(',');
    var players = comma !== -1 ? entry.slice(0, comma).trim() : entry.trim();
    var club    = comma !== -1 ? entry.slice(comma + 1).trim() : '';
    // Last player is after the last '/'
    var slashIdx = players.lastIndexOf('/');
    var lastPlayer = slashIdx !== -1 ? players.slice(slashIdx + 1).trim() : players;
    // Extract surname (last word of last player)
    var nameParts = lastPlayer.trim().split(/\s+/);
    var surname = nameParts[nameParts.length - 1];
    var clubAbbrev = club ? club.slice(0, 3) : '';
    return clubAbbrev ? surname + ', ' + clubAbbrev : surname;
  }

  // Build a placeholder string for a TBD slot from the predecessor match.
  // Returns "Surname1, Clu/Surname2, Clu" or null if not enough info.
  function buildChampPlaceholder(prevMatch) {
    if (!prevMatch || prevMatch.bye) return null;
    var h = prevMatch.home ? abbrevChampEntry(prevMatch.home) : null;
    var a = prevMatch.away ? abbrevChampEntry(prevMatch.away) : null;
    if (h && a) return h + '/' + a;
    return null;
  }

  function champBadge(entry) {
    if (!entry) return '';
    // Try exact match on full entry string first (unlikely but safe)
    if (badges[entry]) return '<img class="lgw-champ-team-badge" src="' + badges[entry] + '" alt="">';
    // Look up by club name extracted from "Player, Club" format
    var club = entryClub(entry);
    if (!club) return '';
    var clubUpper = club.toUpperCase();
    // Exact club badge match
    for (var key in clubBadges) {
      if (key.toUpperCase() === clubUpper) {
        return '<img class="lgw-champ-team-badge" src="' + clubBadges[key] + '" alt="">';
      }
    }
    // Prefix match
    var bestKey = '', bestImg = '';
    for (var c in clubBadges) {
      var cu = c.toUpperCase();
      if (clubUpper === cu || clubUpper.indexOf(cu) === 0) {
        if (c.length > bestKey.length) { bestKey = c; bestImg = clubBadges[c]; }
      }
    }
    return bestImg ? '<img class="lgw-champ-team-badge" src="' + bestImg + '" alt="">' : '';
  }

  // Extract club from champ entry string "Player Name(s), ClubName"
  function entryClubFromStr(entry) {
    if (!entry) return '';
    var c = entry.lastIndexOf(',');
    return c !== -1 ? entry.slice(c + 1).trim() : '';
  }

  // Render a clickable player name button for stats popover
  // Only activates when statsEligible=true and the entry is not a TBD placeholder
  function renderEntryNameHtml(entry, statsEligible) {
    if (!entry || !statsEligible) return '<span class="lgw-champ-team-name">' + escHtml(entry || 'TBD') + '</span>';
    var club = entryClubFromStr(entry);
    // For multi-player entries (pairs etc.), split by '/' and render each as a link
    var comma = entry.lastIndexOf(',');
    var playersPart = comma !== -1 ? entry.slice(0, comma) : entry;
    var players = playersPart.split('/').map(function(p){ return p.trim(); }).filter(Boolean);
    var clubPart = comma !== -1 ? entry.slice(comma + 1).trim() : '';
    var links = players.map(function(p) {
      return '<button type="button" class="lgw-player-link lgw-champ-player-link"'
        + ' data-player="' + escHtml(p) + '"'
        + ' data-club="' + escHtml(club) + '">'
        + escHtml(p)
        + '</button>';
    }).join('<span class="lgw-champ-entry-sep"> / </span>');
    return '<span class="lgw-champ-team-name lgw-champ-name-links">'
      + links
      + (clubPart ? '<span class="lgw-champ-entry-club">, ' + escHtml(clubPart) + '</span>' : '')
      + '</span>';
  }

  function renderTeamRow(team, score, isWinner, isLoser, placeholder, statsEligible) {
    var cls = 'lgw-champ-team';
    if (isWinner) cls += ' lgw-champ-winner';
    if (isLoser)  cls += ' lgw-champ-loser';
    var badge = team ? champBadge(team) : '';
    var isTbd = !team;
    var nameHtml;
    if (isTbd) {
      nameHtml = '<span class="lgw-champ-team-name tbd">'
        + (placeholder ? '<span class="lgw-champ-placeholder">' + escHtml(placeholder) + '</span>' : 'TBD')
        + '</span>';
    } else {
      nameHtml = renderEntryNameHtml(team, statsEligible);
    }
    var scoreStr = (score !== null && score !== undefined && score !== '') ? escHtml(score) : '';
    return '<div class="' + cls + '">'
      + badge
      + nameHtml
      + (scoreStr !== '' ? '<span class="lgw-champ-score">' + scoreStr + '</span>' : '')
      + '</div>';
  }

  function renderMatch(match, homePlaceholder, awayPlaceholder, statsEligible) {
    var home       = match.home  || '';
    var away       = match.away  || '';
    var hs         = match.home_score;
    var as         = match.away_score;
    var hasResult  = (hs !== null && hs !== undefined && hs !== '' &&
                      as !== null && as !== undefined && as !== '');
    var homeWin    = hasResult && parseFloat(hs) > parseFloat(as);
    var awayWin    = hasResult && parseFloat(as) > parseFloat(hs);

    // Placeholders for null slots — prefer computed predecessor placeholders,
    // fall back to "Winner of Game N" if available, then generic TBD.
    var homePh = home ? null
      : (homePlaceholder || (match.prev_game_home ? 'Winner of Game ' + match.prev_game_home : null));
    var awayPh = away ? null
      : (awayPlaceholder || (match.prev_game_away ? 'Winner of Game ' + match.prev_game_away : null));

    var cls = 'lgw-champ-match';
    if (match.bye)      cls += ' lgw-champ-bye';
    if (!home && !away) cls += ' lgw-champ-tbd';

    var gameNumHtml = match.game_num
      ? '<div class="lgw-champ-game-num">Game ' + match.game_num + '</div>'
      : '';

    return '<div class="' + cls + '">'
      + gameNumHtml
      + renderTeamRow(home, hasResult ? hs : null, homeWin, awayWin && home, homePh, statsEligible)
      + renderTeamRow(away, hasResult ? as : null, awayWin, homeWin && away, awayPh, statsEligible)
      + '</div>';
  }

  // ── Current-round helpers ─────────────────────────────────────────────────────
  // Parse "d/m/yy" or "d/m/yyyy" → midnight timestamp (ms), or null.
  function parseChampDate(s) {
    if (!s) return null;
    var m = String(s).trim().match(/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})$/);
    if (!m) return null;
    var y = parseInt(m[3], 10); if (y < 100) y += 2000;
    return new Date(y, parseInt(m[2], 10) - 1, parseInt(m[1], 10)).getTime();
  }

  // Return the index of the "current" round:
  //   1. First round whose date >= today (upcoming/current round).
  //   2. If all dates are in the past → last round.
  //   3. If no dates → first round with any incomplete match.
  //   4. Fallback: 0.
  function findCurrentRound(rounds, matches, dates) {
    var today = new Date(); today.setHours(0,0,0,0); today = today.getTime();
    if (dates && dates.length) {
      var firstUpcoming = -1;
      for (var i = 0; i < dates.length; i++) {
        var ts = parseChampDate(dates[i]);
        if (ts !== null && ts >= today) { firstUpcoming = i; break; }
      }
      if (firstUpcoming >= 0) return firstUpcoming;
      for (var j = dates.length - 1; j >= 0; j--) {
        if (parseChampDate(dates[j]) !== null) return j;
      }
    }
    for (var r = 0; r < (matches || []).length; r++) {
      var roundMatches = matches[r] || [];
      var incomplete = roundMatches.some(function (m) {
        return !m.bye && (m.home_score === null || m.home_score === undefined ||
                          m.home_score === '' || m.away_score === null ||
                          m.away_score === undefined || m.away_score === '');
      });
      if (incomplete) return r;
    }
    return 0;
  }

  function renderBracket(wrap, data) {
    var rounds        = data.rounds  || [];
    var matches       = data.matches || [];
    var dates         = data.dates   || [];
    var statsEligible = wrap && wrap.dataset && wrap.dataset.statsEligible === '1';

    // Determine which round is "current" for highlight / auto-scroll
    var currentRound = findCurrentRound(rounds, matches, dates);

    // ── Mobile tabs
    var tabsEl = qs('.lgw-champ-tabs', wrap);
    var tabsInner = tabsEl ? qs('.lgw-champ-tabs-inner', tabsEl) : null;
    if (tabsInner) {
      tabsInner.innerHTML = '';
      rounds.forEach(function (name, i) {
        var tab = document.createElement('div');
        var isCurrent = (i === currentRound);
        tab.className = 'lgw-champ-tab' + (isCurrent ? ' active' : '') + (isCurrent ? ' lgw-champ-tab--current' : '');
        tab.textContent = name;
        tab.dataset.round = i;
        tabsInner.appendChild(tab);
      });
    }

    // ── Bracket
    var bracketEl = qs('.lgw-champ-bracket', wrap);
    if (!bracketEl) return;
    bracketEl.innerHTML = '';

    // Build a game_num → match index for predecessor placeholder lookups.
    var gameNumIndex = {};
    matches.forEach(function (roundMatches) {
      (roundMatches || []).forEach(function (m) {
        if (m && m.game_num) gameNumIndex[m.game_num] = m;
      });
    });

    rounds.forEach(function (roundName, ri) {
      var roundMatches = matches[ri] || [];
      var isFinal = ri === rounds.length - 1;

      // Connector column before each round (except first)
      if (ri > 0) {
        var connCol = document.createElement('div');
        connCol.className = 'lgw-champ-connector-col';
        bracketEl.appendChild(connCol);
      }

      var roundEl = document.createElement('div');
      var isCurrent2 = (ri === currentRound);
      roundEl.className = 'lgw-champ-round'
        + (isFinal    ? ' lgw-champ-round-final'   : '')
        + (isCurrent2 ? ' mobile-active lgw-champ-round--current' : '');
      roundEl.dataset.round = ri;

      var dateStr = dates[ri] ? '<span class="lgw-champ-round-date">' + escHtml(dates[ri]) + '</span>' : '';
      roundEl.innerHTML = '<div class="lgw-champ-round-header">' + escHtml(roundName) + dateStr + '</div>'
        + '<div class="lgw-champ-round-slots"></div>';

      var slotsEl = qs('.lgw-champ-round-slots', roundEl);
      roundMatches.forEach(function (match, mi) {
        // Build predecessor placeholders for TBD slots using game_num index
        var homePlaceholder = null, awayPlaceholder = null;
        if (!match.home || !match.away) {
          if (!match.home && match.prev_game_home) {
            var prevH = gameNumIndex[match.prev_game_home];
            if (prevH) homePlaceholder = buildChampPlaceholder(prevH);
          }
          if (!match.away && match.prev_game_away) {
            var prevA = gameNumIndex[match.prev_game_away];
            if (prevA) awayPlaceholder = buildChampPlaceholder(prevA);
          }
        }
        var matchEl = document.createElement('div');
        matchEl.innerHTML = renderMatch(match, homePlaceholder, awayPlaceholder, statsEligible);
        var card = matchEl.firstElementChild;
        card.dataset.round = ri;
        card.dataset.match = mi;
        if (match.game_num) card.dataset.gameNum = match.game_num;
        var isAdmin = typeof lgwChampData !== 'undefined' && lgwChampData.isAdmin == 1;
        var hasResult = match.home_score !== null && match.home_score !== undefined &&
                        match.away_score !== null && match.away_score !== undefined;
        if (isAdmin && !match.bye && (match.home || match.away)) {
          // Admin: click opens score entry
          card.classList.add('lgw-champ-editable');
          card.addEventListener('click', function () {
            openScoreEntry(wrap, card, match, ri, mi);
          });
        } else if (hasResult && match.home && match.away) {
          // Anyone: click opens scorecard viewer if result is set
          card.classList.add('lgw-champ-has-scorecard');
          card.addEventListener('click', function () {
            openScorecardViewer(card, match);
          });
        }
        slotsEl.appendChild(card);
      });
      bracketEl.appendChild(roundEl);
    });

    // Champion display after final
    var finalRound = matches[rounds.length - 1] || [];
    var finalMatch = finalRound[0] || null;
    var champion   = null;
    if (finalMatch) {
      var fhs = finalMatch.home_score, fas = finalMatch.away_score;
      if (fhs !== null && fhs !== undefined && fhs !== '' && fas !== null && fas !== undefined && fas !== '') {
        champion = parseFloat(fhs) > parseFloat(fas) ? finalMatch.home : finalMatch.away;
      }
    }

    if (champion !== null) {
      var connCol2 = document.createElement('div');
      connCol2.className = 'lgw-champ-connector-col';
      bracketEl.appendChild(connCol2);

      var isSection = wrap && wrap.dataset.section !== 'final';
      var champLabel = isSection ? 'Qualifier' : 'Champion';
      var champIcon  = isSection ? '✅' : '🏆';
      var champEl = document.createElement('div');
      champEl.className = 'lgw-champ-champion';
      champEl.innerHTML = '<div class="lgw-champ-trophy">' + champIcon + '</div>'
        + '<div class="lgw-champ-champion-name">' + escHtml(champion) + '</div>'
        + '<div class="lgw-champ-champion-label">' + champLabel + '</div>';
      bracketEl.appendChild(champEl);
    }

    // ── Mobile tab switching
    // ── Mobile: scroll-based navigation
    if (tabsInner) {
      var bracketOuter = qs('.lgw-champ-bracket-outer', wrap);

      // Helper: scroll bracket to show a round column
      function scrollToRound(ri2) {
        var target = qs('.lgw-champ-round[data-round="' + ri2 + '"]', bracketEl);
        if (!target || !bracketOuter) return;
        // Activate tab
        qsa('.lgw-champ-tab', tabsInner).forEach(function (t) {
          t.classList.toggle('active', parseInt(t.dataset.round) === ri2);
        });
        // Highlight round header
        qsa('.lgw-champ-round', bracketEl).forEach(function (r) {
          r.classList.toggle('mobile-active', parseInt(r.dataset.round) === ri2);
        });
        // Scroll active tab into view in the tab bar
        var activeTab = qs('.lgw-champ-tab[data-round="' + ri2 + '"]', tabsInner);
        if (activeTab) activeTab.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
        // Scroll bracket to that round
        bracketOuter.scrollTo({ left: target.offsetLeft, behavior: 'smooth' });
      }

      // Tab click → scroll to round
      tabsInner.addEventListener('click', function (e) {
        var tab = e.target.closest('.lgw-champ-tab');
        if (!tab) return;
        scrollToRound(parseInt(tab.dataset.round));
      });

      // Round header click → advance to next round (wrap to 0)
      qsa('.lgw-champ-round-header', bracketEl).forEach(function (hdr) {
        var roundEl2 = hdr.closest('.lgw-champ-round');
        if (!roundEl2) return;
        hdr.addEventListener('click', function () {
          var ri3 = parseInt(roundEl2.dataset.round);
          var totalRounds = rounds.length;
          var next = (ri3 + 1) < totalRounds ? ri3 + 1 : 0;
          scrollToRound(next);
        });
      });

      // IntersectionObserver: keep active tab in sync as user swipes
      if (bracketOuter && window.IntersectionObserver) {
        var observer = new IntersectionObserver(function (entries) {
          entries.forEach(function (entry) {
            if (entry.isIntersecting && entry.intersectionRatio >= 0.5) {
              var ri4 = parseInt(entry.target.dataset.round);
              qsa('.lgw-champ-tab', tabsInner).forEach(function (t) {
                t.classList.toggle('active', parseInt(t.dataset.round) === ri4);
              });
              qsa('.lgw-champ-round', bracketEl).forEach(function (r) {
                r.classList.toggle('mobile-active', parseInt(r.dataset.round) === ri4);
              });
              var activeTab2 = qs('.lgw-champ-tab[data-round="' + ri4 + '"]', tabsInner);
              if (activeTab2) activeTab2.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
            }
          });
        }, { root: bracketOuter, threshold: 0.5 });
        qsa('.lgw-champ-round', bracketEl).forEach(function (r) { observer.observe(r); });
      }
      // Auto-scroll to current round on load (deferred so layout is complete)
      if (currentRound > 0) {
        setTimeout(function () { scrollToRound(currentRound); }, 80);
      }
    }
    // Expose a method so external callers (section tabs) can re-trigger the scroll
    wrap._lgwScrollToCurrentRound = function () {
      setTimeout(function () { scrollToRound(currentRound); }, 80);
    };
  }

  // ── Live draw animation ───────────────────────────────────────────────────────
  function runDrawAnimation(pairs, onComplete, wrap) {
    // pairs = [ {home, away, bye}, ... ] with optional {type:'header', label:'...'} entries
    var matchCount = pairs.filter(function (p) { return p.type !== 'header'; }).length;

    var overlay = document.createElement('div');
    overlay.className = 'lgw-champ-draw-overlay';
    overlay.innerHTML = [
      '<div class="lgw-champ-draw-title">🏆 Championship Draw</div>',
      '<div class="lgw-champ-draw-subtitle">The draw is being made…</div>',
      '<div class="lgw-champ-draw-reveal">',
        '<div class="lgw-champ-draw-slot-label" id="lgw-draw-slot-label">Match 1</div>',
        '<div class="lgw-champ-draw-team" id="lgw-draw-home"></div>',
        '<div class="lgw-champ-draw-vs">vs</div>',
        '<div class="lgw-champ-draw-team" id="lgw-draw-away"></div>',
      '</div>',
      '<div class="lgw-champ-draw-progress" id="lgw-draw-progress">0 / ' + matchCount + ' drawn</div>',
      '<div class="lgw-champ-draw-pairs" id="lgw-draw-pairs"></div>',
      '<button class="lgw-champ-draw-btn lgw-champ-draw-skip-btn" id="lgw-draw-skip">Skip to End</button>',
    ].join('');
    document.body.appendChild(overlay);

    var labelEl    = qs('#lgw-draw-slot-label', overlay);
    var homeEl     = qs('#lgw-draw-home',      overlay);
    var awayEl     = qs('#lgw-draw-away',      overlay);
    var progressEl = qs('#lgw-draw-progress',  overlay);
    var pairsEl    = qs('#lgw-draw-pairs',      overlay);
    var skipBtn    = qs('#lgw-draw-skip',        overlay);

    var idx      = 0;
    var matchIdx = 0;
    var timer    = null;
    var skipped  = false;

    // Timings (ms)
    // Cadence: speed multiplier from cup settings (0.5 = fast, 1.0 = normal, 2.0 = slow)
    var speed   = (typeof lgwChampData !== 'undefined' && lgwChampData.drawSpeed) ? parseFloat(lgwChampData.drawSpeed) : 1.0;
    var T_HOME  = Math.round(700  * speed);  // delay before showing home team
    var T_AWAY  = Math.round(1200 * speed);  // delay before showing away team
    var T_CHIP  = Math.round(1800 * speed);  // delay before adding chip
    var T_NEXT  = Math.round(2600 * speed);  // delay before advancing to next pair

    function addChip(pair) {
      var chip = document.createElement('div');
      chip.className = 'lgw-champ-draw-pair-chip' + (pair.bye ? ' lgw-champ-draw-bye-chip' : '');
      chip.innerHTML = escHtml(pair.home)
        + '<span class="vs-sep">' + (pair.bye ? 'BYE' : 'v') + '</span>'
        + (pair.bye ? '' : escHtml(pair.away || 'TBD'));
      pairsEl.appendChild(chip);
      requestAnimationFrame(function () {
        requestAnimationFrame(function () { chip.classList.add('show'); });
      });
    }

    function advance() {
      if (idx < pairs.length) {
        showPair(idx);
        idx++;
      } else {
        // All pairs revealed — show completion state
        var subtitleEl = qs('.lgw-champ-draw-subtitle', overlay);
        var revealEl   = qs('.lgw-champ-draw-reveal',   overlay);
        if (subtitleEl) subtitleEl.textContent = '✅ The draw is complete!';
        if (revealEl)   revealEl.style.display = 'none';
        labelEl.textContent = '';
        homeEl.classList.remove('show'); homeEl.textContent = '';
        awayEl.classList.remove('show'); awayEl.textContent = '';
        skipBtn.textContent = 'View Bracket';
        skipBtn.onclick = function () {
          document.body.removeChild(overlay);
          if (onComplete) onComplete();
        };
      }
    }

    function showPair(i) {
      var pair = pairs[i];

      if (pair.type === 'header') {
        var divider = document.createElement('div');
        divider.className = 'lgw-champ-draw-round-header';
        divider.textContent = pair.label;
        pairsEl.appendChild(divider);
        homeEl.classList.remove('show');
        awayEl.classList.remove('show');
        homeEl.textContent = '';
        awayEl.textContent = '';
        labelEl.textContent = pair.label;
        // Advance server cursor for header entry so total stays in sync
        var cupIdH = wrap ? wrap.dataset.champId : '';
        var nonceH = (typeof lgwChampData !== 'undefined') ? lgwChampData.champNonce : '';
        if (cupIdH && nonceH) {
          post('lgw_champ_advance_cursor', { champ_id: cupIdH, nonce: nonceH, draw_token: drawToken || '', section: (wrap ? wrap.dataset.section || '0' : '0') }, function () {});
        }
        timer = setTimeout(advance, skipped ? 0 : 600);
        return;
      }

      matchIdx++;
      labelEl.textContent = 'Match ' + matchIdx;

      // Clear previous content first so next team names don't show before animation
      homeEl.classList.remove('show');
      awayEl.classList.remove('show');
      homeEl.textContent = '';
      awayEl.textContent = '';

      if (skipped) {
        // Instant reveal — no animation
        homeEl.textContent = pair.home;
        awayEl.textContent = pair.bye ? 'BYE' : (pair.away || 'TBD');
        homeEl.classList.add('show');
        awayEl.classList.add('show');
        addChip(pair);
        progressEl.textContent = matchIdx + ' / ' + matchCount + ' drawn';
        timer = setTimeout(advance, 0);
        return;
      }

      timer = setTimeout(function () {
        homeEl.textContent = pair.home;
        homeEl.classList.add('show');
      }, T_HOME);
      timer = setTimeout(function () {
        awayEl.textContent = pair.bye ? 'BYE' : (pair.away || 'TBD');
        awayEl.classList.add('show');
      }, T_AWAY);
      timer = setTimeout(function () {
        addChip(pair);
        progressEl.textContent = matchIdx + ' / ' + matchCount + ' drawn';
        // Advance server cursor so polling viewers receive this pair
        var cupId = document.querySelector('.lgw-champ-wrap') ?
          document.querySelector('.lgw-champ-wrap').dataset.champId : '';
        var nonce = (typeof lgwChampData !== 'undefined') ? lgwChampData.champNonce : '';
        if (cupId && nonce) {
          post('lgw_champ_advance_cursor', {
            champ_id: cupId, nonce: nonce, draw_token: drawToken || '',
            section: (document.querySelector('.lgw-champ-wrap') ? document.querySelector('.lgw-champ-wrap').dataset.section || '0' : '0')
          }, function () {}); // fire and forget
        }
      }, T_CHIP);
      timer = setTimeout(advance, T_NEXT);
    }

    skipBtn.addEventListener('click', function () {
      if (skipped) return; // already skipping, button becomes Close
      skipped = true;
      clearTimeout(timer);
      skipBtn.textContent = 'Skip to End';
      // Drain remaining pairs instantly
      while (idx < pairs.length) {
        var pair = pairs[idx];
        idx++;
        if (pair.type === 'header') {
          var divider = document.createElement('div');
          divider.className = 'lgw-champ-draw-round-header';
          divider.textContent = pair.label;
          pairsEl.appendChild(divider);
          // Advance cursor for header too
          var cupIdS = wrap ? wrap.dataset.champId : '';
          var nonceS = (typeof lgwChampData !== 'undefined') ? lgwChampData.champNonce : '';
          if (cupIdS && nonceS) {
            post('lgw_champ_advance_cursor', { champ_id: cupIdS, nonce: nonceS, draw_token: drawToken || '', section: (wrap ? wrap.dataset.section || '0' : '0') }, function () {});
          }
          continue;
        }
        matchIdx++;
        addChip(pair);
      }
      progressEl.textContent = matchCount + ' / ' + matchCount + ' drawn';
      var subtitleEl2 = qs('.lgw-champ-draw-subtitle', overlay);
      var revealEl2   = qs('.lgw-champ-draw-reveal',   overlay);
      if (subtitleEl2) subtitleEl2.textContent = '✅ The draw is complete!';
      if (revealEl2)   revealEl2.style.display = 'none';
      labelEl.textContent = '';
      homeEl.classList.remove('show'); homeEl.textContent = '';
      awayEl.classList.remove('show'); awayEl.textContent = '';
      skipBtn.textContent = 'View Bracket';
      skipBtn.onclick = function () {
        document.body.removeChild(overlay);
        if (onComplete) onComplete();
      };
    });

    // Kick off automatically
    advance();
  }

  // ── Cup match scorecard viewer ────────────────────────────────────────────────
  function openScorecardViewer(card, match) {
    var existing = qs('.lgw-champ-sc-modal');
    if (existing) existing.parentNode.removeChild(existing);

    var nonce  = (typeof lgwChampData !== 'undefined') ? lgwChampData.champNonce : '';
    var modal  = document.createElement('div');
    modal.className = 'lgw-champ-sc-modal';
    modal.innerHTML =
      '<div class="lgw-champ-sc-modal-box">' +
        '<div class="lgw-champ-sc-modal-header">' +
          '<span class="lgw-champ-sc-modal-title">' + escHtml(match.home) + ' v ' + escHtml(match.away) + '</span>' +
          '<button class="lgw-champ-sc-modal-close" aria-label="Close">&times;</button>' +
        '</div>' +
        '<div class="lgw-champ-sc-modal-body"><p class="lgw-champ-sc-loading">Loading scorecard…</p></div>' +
      '</div>';
    document.body.appendChild(modal);

    qs('.lgw-champ-sc-modal-close', modal).addEventListener('click', function () {
      modal.parentNode.removeChild(modal);
    });
    modal.addEventListener('click', function (e) {
      if (e.target === modal) modal.parentNode.removeChild(modal);
    });

    post('lgw_champ_get_scorecard', { home: match.home, away: match.away, nonce: nonce }, function (res) {
      var body = qs('.lgw-champ-sc-modal-body', modal);
      if (!res.success) {
        body.innerHTML = '<p class="lgw-champ-sc-none">No scorecard has been submitted for this match yet.</p>';
        return;
      }
      var sc = res.data.sc;
      var conf = res.data.confirmed_by ? '✅ Confirmed by ' + escHtml(res.data.confirmed_by) : '⏳ Awaiting confirmation';
      var html = '<div class="lgw-champ-sc-summary">' +
        '<div class="lgw-champ-sc-meta">' +
          (sc.venue ? '<span>' + escHtml(sc.venue) + '</span>' : '') +
          (sc.date  ? '<span>' + escHtml(sc.date)  + '</span>' : '') +
        '</div>' +
        '<div class="lgw-champ-sc-conf">' + conf + '</div>' +
        '<table class="lgw-champ-sc-table">' +
          '<thead><tr><th>Rink</th><th colspan="2">Home</th><th>Score</th><th colspan="2">Away</th><th>Score</th></tr></thead>' +
          '<tbody>';
      (sc.rinks || []).forEach(function (rk) {
        var hs = rk.home_score !== null && rk.home_score !== undefined ? rk.home_score : '-';
        var as = rk.away_score !== null && rk.away_score !== undefined ? rk.away_score : '-';
        var homeWin = hs !== '-' && as !== '-' && parseFloat(hs) > parseFloat(as);
        var awayWin = hs !== '-' && as !== '-' && parseFloat(as) > parseFloat(hs);
        html += '<tr>' +
          '<td class="lgw-champ-sc-rink">Rink ' + escHtml(String(rk.rink)) + '</td>' +
          '<td class="lgw-champ-sc-players">' + escHtml((rk.home_players || []).join(', ')) + '</td>' +
          '<td class="lgw-champ-sc-score ' + (homeWin ? 'win' : '') + '">' + escHtml(String(hs)) + '</td>' +
          '<td class="lgw-champ-sc-vs">v</td>' +
          '<td class="lgw-champ-sc-score ' + (awayWin ? 'win' : '') + '">' + escHtml(String(as)) + '</td>' +
          '<td class="lgw-champ-sc-players">' + escHtml((rk.away_players || []).join(', ')) + '</td>' +
          '</tr>';
      });
      html += '</tbody>' +
        '<tfoot><tr>' +
          '<td colspan="2" class="lgw-champ-sc-total-lbl">Total</td>' +
          '<td class="lgw-champ-sc-score ' + (parseFloat(sc.home_total) > parseFloat(sc.away_total) ? 'win' : '') + '">' + escHtml(String(sc.home_total ?? '-')) + '</td>' +
          '<td></td>' +
          '<td class="lgw-champ-sc-score ' + (parseFloat(sc.away_total) > parseFloat(sc.home_total) ? 'win' : '') + '">' + escHtml(String(sc.away_total ?? '-')) + '</td>' +
          '<td class="lgw-champ-sc-total-lbl">Total</td>' +
        '</tr></tfoot>' +
        '</table></div>';
      body.innerHTML = html;
    });
  }

  // ── Hide draw/login buttons once draw is complete ─────────────────────────────
  function hideDrawButtons(wrap) {
    var actionsEl = qs('.lgw-champ-header-actions', wrap);
    if (actionsEl) actionsEl.parentNode.removeChild(actionsEl);
    // Add print button
    var headerEl = qs('.lgw-champ-header', wrap);
    if (headerEl && !qs('.lgw-champ-post-draw-actions', wrap)) {
      var printDiv = document.createElement('div');
      printDiv.className = 'lgw-champ-header-actions lgw-champ-post-draw-actions';
      printDiv.innerHTML = '<button class="lgw-champ-btn lgw-champ-btn-ghost lgw-champ-print-btn">\uD83D\uDDA8 Print Draw</button>';
      qs('.lgw-champ-print-btn', printDiv).addEventListener('click', function () {
        // Ensure all rounds visible (not hidden by mobile tabs) before printing
        var rounds = qsa('.lgw-champ-round', wrap);
        rounds.forEach(function (r) { r.style.display = 'flex'; });
        window.print();
        // Restore after print dialog closes
        setTimeout(function () {
          rounds.forEach(function (r) { r.style.display = ''; });
        }, 1000);
      });
      headerEl.appendChild(printDiv);
    }
    // Also hide the "no draw yet" empty state
    var emptyEl = qs('.lgw-champ-empty', wrap);
    if (emptyEl) emptyEl.style.display = 'none';
  }

  // ── Draw passphrase login modal ───────────────────────────────────────────────
  var drawToken        = null; // stored in memory for session duration
  var drawMasterActive = false; // true while draw master animation is running

  function openDrawLoginModal(wrap, onSuccess) {
    var existing = qs('.lgw-champ-draw-login-modal');
    if (existing) existing.parentNode.removeChild(existing);

    var nonce = (typeof lgwChampData !== 'undefined') ? lgwChampData.champNonce : '';

    var modal = document.createElement('div');
    modal.className = 'lgw-champ-draw-login-modal';
    modal.innerHTML =
      '<div class="lgw-champ-draw-login-box">' +
        '<div class="lgw-champ-draw-login-title">🔑 Draw Authentication</div>' +
        '<input class="lgw-champ-draw-login-input" type="text" placeholder="Enter passphrase" ' +
               'autocomplete="off" autocapitalize="none" spellcheck="false">' +
        '<div class="lgw-champ-draw-login-actions">' +
          '<button class="lgw-champ-draw-login-submit">Unlock Draw</button>' +
          '<button class="lgw-champ-draw-login-cancel">Cancel</button>' +
        '</div>' +
        '<div class="lgw-champ-draw-login-msg"></div>' +
      '</div>';
    document.body.appendChild(modal);

    var input   = qs('.lgw-champ-draw-login-input',  modal);
    var submitBtn = qs('.lgw-champ-draw-login-submit', modal);
    var msgEl   = qs('.lgw-champ-draw-login-msg',    modal);
    input.focus();

    function doAuth() {
      var passphrase = input.value.trim().toLowerCase();
      if (!passphrase) return;
      submitBtn.disabled = true;
      submitBtn.textContent = 'Checking…';
      post('lgw_champ_draw_auth', { nonce: nonce, passphrase: passphrase }, function (res) {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Unlock Draw';
        if (!res.success) {
          msgEl.textContent = res.data || 'Incorrect passphrase.';
          input.value = '';
          input.focus();
          return;
        }
        drawToken = res.data.token;
        modal.parentNode.removeChild(modal);
        onSuccess();
      });
    }

    submitBtn.addEventListener('click', doAuth);
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter') doAuth(); });
    qs('.lgw-champ-draw-login-cancel', modal).addEventListener('click', function () {
      modal.parentNode.removeChild(modal);
    });
    // Close on backdrop click
    modal.addEventListener('click', function (e) {
      if (e.target === modal) modal.parentNode.removeChild(modal);
    });
  }

  // ── Admin/authenticated draw trigger ──────────────────────────────────────────
  function initAdminDraw(wrap) {
    var cupId   = wrap.dataset.champId;
    var drawBtn = qs('.lgw-champ-admin-draw-btn', wrap);
    var loginBtn = qs('.lgw-champ-draw-login-btn', wrap);

    function performDraw() {
      if (!confirm('Perform the draw now? This will randomise the bracket and publish it live. This cannot be undone.')) return;
      var nonce = (drawBtn ? drawBtn.dataset.nonce : '') ||
                  (typeof lgwChampData !== 'undefined' ? lgwChampData.champNonce : '');
      var activeBtn = drawBtn || loginBtn;
      if (activeBtn) { activeBtn.disabled = true; activeBtn.textContent = '⏳ Drawing…'; }

      var section = wrap.dataset.section || '0';
      post('lgw_champ_perform_draw', { champ_id: cupId, nonce: nonce, draw_token: drawToken || '', section: section }, function (res) {
        if (activeBtn) { activeBtn.disabled = false; }
        if (!res.success) {
          if (activeBtn) activeBtn.textContent = drawBtn ? '🎲 Perform Draw' : '🔑 Login to Draw';
          alert('Draw failed: ' + (res.data || 'Unknown error'));
          return;
        }
        var bracket = res.data.bracket;
        var pairs   = res.data.pairs;
        drawMasterActive = true;
        runDrawAnimation(pairs, function () {
          drawMasterActive = false;
          renderBracket(wrap, bracket);
          updateStatus(wrap, 'Draw complete — bracket published.');
          hideDrawButtons(wrap);
        }, wrap);
      });
    }

    // Admin draw button — direct
    if (drawBtn) {
      drawBtn.addEventListener('click', performDraw);
    }

    // Login button — open auth modal first, then draw on success
    if (loginBtn) {
      loginBtn.addEventListener('click', function () {
        if (drawToken) {
          // Already authenticated this session
          performDraw();
        } else {
          openDrawLoginModal(wrap, function () {
            // Swap login button to draw button appearance
            loginBtn.textContent = '🎲 Perform Draw';
            performDraw();
          });
        }
      });
    }
  }

  // ── Admin score entry popover ─────────────────────────────────────────────────
  function openScoreEntry(wrap, card, match, roundIdx, matchIdx) {
    // Remove any existing popover
    var existing = qs('.lgw-champ-score-popover');
    if (existing) existing.parentNode.removeChild(existing);

    var cupId = wrap.dataset.champId;
    var nonce = (typeof lgwChampData !== 'undefined') ? lgwChampData.scoreNonce : '';
    var homeName = match.home || 'TBD';
    var awayName = match.away || 'TBD';
    var hs = (match.home_score !== null && match.home_score !== undefined) ? match.home_score : '';
    var as = (match.away_score !== null && match.away_score !== undefined) ? match.away_score : '';

    var hasScore = (hs !== '' && as !== '');

    var pop = document.createElement('div');
    pop.className = 'lgw-champ-score-popover';
    pop.innerHTML =
      '<div class="lgw-champ-score-pop-title">Enter Score</div>' +
      '<div class="lgw-champ-score-pop-row">' +
        '<span class="lgw-champ-score-pop-name">' + escHtml(homeName) + '</span>' +
        '<input class="lgw-champ-score-pop-input" id="lgw-score-home" type="number" min="0" max="99" value="' + escHtml(String(hs)) + '" placeholder="–">' +
      '</div>' +
      '<div class="lgw-champ-score-pop-row">' +
        '<span class="lgw-champ-score-pop-name">' + escHtml(awayName) + '</span>' +
        '<input class="lgw-champ-score-pop-input" id="lgw-score-away" type="number" min="0" max="99" value="' + escHtml(String(as)) + '" placeholder="–">' +
      '</div>' +
      '<div class="lgw-champ-score-pop-actions">' +
        '<button class="lgw-champ-score-pop-save">Save</button>' +
        '<button class="lgw-champ-score-pop-cancel">Cancel</button>' +
        (hasScore ? '<button class="lgw-champ-score-pop-reset">Clear</button>' : '') +
      '</div>' +
      '<div class="lgw-champ-score-pop-msg"></div>';

    document.body.appendChild(pop);

    // Position near the card
    var rect = card.getBoundingClientRect();
    var top  = rect.top + window.scrollY + rect.height / 2 - 10;
    var left = rect.right + window.scrollX + 8;
    // Keep on screen
    if (left + 220 > window.innerWidth) left = rect.left + window.scrollX - 228;
    pop.style.top  = Math.max(8, top) + 'px';
    pop.style.left = Math.max(8, left) + 'px';

    var homeInput = qs('#lgw-score-home', pop);
    var awayInput = qs('#lgw-score-away', pop);
    var msgEl     = qs('.lgw-champ-score-pop-msg', pop);
    homeInput.focus();

    qs('.lgw-champ-score-pop-cancel', pop).addEventListener('click', function () {
      pop.parentNode.removeChild(pop);
    });

    var resetBtn = qs('.lgw-champ-score-pop-reset', pop);
    if (resetBtn) {
      resetBtn.addEventListener('click', function () {
        if (!confirm('Clear the score for this match? The next round slot will also be cleared.')) return;
        var scoreSection = wrap.dataset.section || '0';
        resetBtn.disabled = true;
        resetBtn.textContent = 'Clearing…';
        post('lgw_champ_save_score', {
          champ_id: cupId, nonce: nonce, section: scoreSection,
          round_idx: roundIdx, match_idx: matchIdx,
          home_score: '', away_score: '',
        }, function (res) {
          if (!res.success) {
            msgEl.textContent = 'Error: ' + (res.data || 'Unknown');
            resetBtn.disabled = false;
            resetBtn.textContent = 'Clear';
            return;
          }
          pop.parentNode.removeChild(pop);
          renderBracket(wrap, res.data.bracket);
          // Update final stage if section results changed
          if (wrap.dataset.section !== 'final') {
            updateFinalWrap(wrap, res.data.final_bracket || null);
          }
        });
      });
    }

    qs('.lgw-champ-score-pop-save', pop).addEventListener('click', function () {
      var saveBtn = this;
      saveBtn.disabled = true;
      saveBtn.textContent = 'Saving…';
      var scoreSection = wrap.dataset.section || '0';
      post('lgw_champ_save_score', {
        champ_id: cupId, nonce: nonce, section: scoreSection,
        round_idx: roundIdx, match_idx: matchIdx,
        home_score: homeInput.value, away_score: awayInput.value,
      }, function (res) {
        saveBtn.disabled = false;
        saveBtn.textContent = 'Save';
        if (!res.success) {
          msgEl.textContent = 'Error: ' + (res.data || 'Unknown');
          return;
        }
        pop.parentNode.removeChild(pop);
        renderBracket(wrap, res.data.bracket);
        // Update final stage if section results changed
        if (wrap.dataset.section !== 'final') {
          updateFinalWrap(wrap, res.data.final_bracket || null);
        }
      });
    });

    // Close on outside click
    setTimeout(function () {
      document.addEventListener('click', function handler(e) {
        if (!pop.contains(e.target) && e.target !== card) {
          if (pop.parentNode) pop.parentNode.removeChild(pop);
          document.removeEventListener('click', handler);
        }
      });
    }, 50);
  }

  // ── Update the final stage wrap when a section score changes ─────────────────
  function updateFinalWrap(sectionWrap, finalBracket) {
    // Find the outer tabs container, then the final pane wrap
    var outer = sectionWrap.closest
      ? sectionWrap.closest('.lgw-champ-tabs-outer')
      : (function() {
          var el = sectionWrap;
          while (el && !el.classList.contains('lgw-champ-tabs-outer')) el = el.parentNode;
          return el;
        })();
    if (!outer) return;
    var finalWrap = outer.querySelector('.lgw-champ-wrap[data-section="final"]');
    if (!finalWrap) return;
    if (finalBracket && finalBracket.rounds && finalBracket.rounds.length) {
      // Update data-bracket so page refresh picks up the new state
      finalWrap.dataset.bracket = JSON.stringify(finalBracket);
      renderBracket(finalWrap, finalBracket);
    } else {
      // Final was unseeded (reset) — clear the bracket display
      finalWrap.dataset.bracket = '';
      var bracketEl = finalWrap.querySelector('.lgw-champ-bracket');
      if (bracketEl) bracketEl.innerHTML = '';
      var emptyEl = finalWrap.querySelector('.lgw-champ-empty');
      if (emptyEl) emptyEl.style.display = '';
    }
  }
  function renderSponsorBar(wrap) {
    var sponsors = [];
    try { sponsors = JSON.parse(wrap.dataset.sponsors || '[]'); } catch (e) {}
    if (!sponsors.length) return;
    var sp = sponsors[Math.floor(Math.random() * sponsors.length)];
    if (!sp || !sp.image) return;
    var img   = '<img src="' + sp.image + '" alt="' + (sp.name || 'Sponsor') + '" class="lgw-sponsor-img">';
    var inner = sp.url ? '<a href="' + sp.url + '" target="_blank" rel="noopener">' + img + '</a>' : img;
    var bar   = document.createElement('div');
    bar.className = 'lgw-sponsor-bar lgw-sponsor-secondary';
    bar.innerHTML = inner;
    var statusEl = qs('.lgw-champ-status', wrap);
    if (statusEl) statusEl.after(bar); else wrap.appendChild(bar);
  }

  function updateStatus(wrap, msg) {
    var statusEl = qs('.lgw-champ-status', wrap);
    if (!statusEl) return;
    var dot = qs('.lgw-champ-status-dot', statusEl);
    var txt = qs('.lgw-champ-status-text', statusEl);
    if (dot) dot.classList.remove('live');
    if (txt) txt.textContent = msg;
  }

  // ── Polling for live draw (visitors) ─────────────────────────────────────────
  function startDrawPoll(wrap, lastVersion) {
    var cupId = wrap.dataset.champId;
    if (!cupId) return;

    var cursor      = 0;
    var animating   = false;
    var overlay     = null;
    var pairsQueue  = [];
    var pollTimeout;
    var pollDelay   = 1000;  // starts at 1s, backs off when idle
    var idleCount   = 0;

    function scheduleNextPoll() {
      clearTimeout(pollTimeout);
      pollTimeout = setTimeout(doPoll, pollDelay);
    }

    function revealNextPair() {
      if (animating || pairsQueue.length === 0) return;
      // Suppress viewer overlay if draw master animation is running on this page
      if (drawMasterActive) { pairsQueue = []; return; }
      animating = true;
      var pair = pairsQueue.shift();

      // Create overlay if not open yet
      if (!overlay || !document.body.contains(overlay)) {
        overlay = document.createElement('div');
        overlay.className = 'lgw-champ-draw-overlay';
        overlay.innerHTML = [
          '<div class="lgw-champ-draw-title">🏆 Championship Draw</div>',
          '<div class="lgw-champ-draw-subtitle">The draw is being made…</div>',
          '<div class="lgw-champ-draw-reveal" id="lgw-draw-reveal-v">',
            '<div class="lgw-champ-draw-slot-label" id="lgw-draw-slot-label-v">Match</div>',
            '<div class="lgw-champ-draw-team" id="lgw-draw-home-v"></div>',
            '<div class="lgw-champ-draw-vs">vs</div>',
            '<div class="lgw-champ-draw-team" id="lgw-draw-away-v"></div>',
          '</div>',
          '<div class="lgw-champ-draw-progress" id="lgw-draw-progress-v">Waiting for draw to begin…</div>',
          '<div class="lgw-champ-draw-eta" id="lgw-draw-eta-v"></div>',
          '<div class="lgw-champ-draw-pairs" id="lgw-draw-pairs-v"></div>',
        ].join('');
        document.body.appendChild(overlay);
      }

      var labelEl = qs('#lgw-draw-slot-label-v', overlay);
      var homeEl  = qs('#lgw-draw-home-v',       overlay);
      var awayEl  = qs('#lgw-draw-away-v',       overlay);
      var pairsEl = qs('#lgw-draw-pairs-v',      overlay);

      var speed  = (typeof lgwChampData !== 'undefined' && lgwChampData.drawSpeed)
                 ? parseFloat(lgwChampData.drawSpeed) : 1.0;
      var T_HOME = Math.round(700  * speed);
      var T_AWAY = Math.round(1200 * speed);
      var T_CHIP = Math.round(1800 * speed);
      var T_DONE = Math.round(2400 * speed);

      if (pair.type === 'header') {
        var divider = document.createElement('div');
        divider.className = 'lgw-champ-draw-round-header';
        divider.textContent = pair.label;
        pairsEl.appendChild(divider);
        homeEl.classList.remove('show'); homeEl.textContent = '';
        awayEl.classList.remove('show'); awayEl.textContent = '';
        if (labelEl) labelEl.textContent = pair.label;
        setTimeout(function () { animating = false; revealNextPair(); }, 600);
        return;
      }

      if (labelEl) labelEl.textContent = 'Match';
      homeEl.classList.remove('show'); homeEl.textContent = '';
      awayEl.classList.remove('show'); awayEl.textContent = '';

      setTimeout(function () { homeEl.textContent = pair.home; homeEl.classList.add('show'); }, T_HOME);
      setTimeout(function () {
        awayEl.textContent = pair.bye ? 'BYE' : (pair.away || 'TBD');
        awayEl.classList.add('show');
      }, T_AWAY);
      setTimeout(function () {
        var chip = document.createElement('div');
        chip.className = 'lgw-champ-draw-pair-chip' + (pair.bye ? ' lgw-champ-draw-bye-chip' : '');
        chip.innerHTML = escHtml(pair.home)
          + '<span class="vs-sep">' + (pair.bye ? 'BYE' : 'v') + '</span>'
          + (pair.bye ? '' : escHtml(pair.away || 'TBD'));
        pairsEl.appendChild(chip);
        requestAnimationFrame(function () {
          requestAnimationFrame(function () { chip.classList.add('show'); });
        });
      }, T_CHIP);
      setTimeout(function () {
        animating = false;
        if (pairsQueue.length > 0) revealNextPair();
      }, T_DONE);
    }

    function showViewerComplete(bracket) {
      if (drawMasterActive) {
        if (bracket) { renderBracket(wrap, bracket); hideDrawButtons(wrap); }
        return;
      }
      if (overlay && document.body.contains(overlay)) {
        var subtitleEl = qs('.lgw-champ-draw-subtitle', overlay);
        var revealEl   = qs('#lgw-draw-reveal-v',     overlay);
        if (subtitleEl) subtitleEl.textContent = '\u2705 The draw is complete!';
        if (revealEl)   revealEl.style.display = 'none';
        // Remove any existing button first
        var existing = qs('.lgw-champ-draw-skip-btn', overlay);
        if (existing) existing.parentNode.removeChild(existing);
        var closeBtn = document.createElement('button');
        closeBtn.className = 'lgw-champ-draw-skip-btn';
        closeBtn.textContent = 'View Bracket';
        closeBtn.addEventListener('click', function () {
          if (overlay && document.body.contains(overlay)) {
            document.body.removeChild(overlay);
            overlay = null;
          }
          if (bracket) {
            renderBracket(wrap, bracket);
            updateStatus(wrap, 'Draw complete.');
            hideDrawButtons(wrap);
          }
        });
        overlay.appendChild(closeBtn);
      } else {
        if (bracket) {
          renderBracket(wrap, bracket);
          updateStatus(wrap, 'Draw complete.');
          hideDrawButtons(wrap);
        }
      }
    }

    function updateProgress(cur, tot) {
      if (!overlay || !document.body.contains(overlay)) return;
      var prog = qs('#lgw-draw-progress-v', overlay);
      var eta  = qs('#lgw-draw-eta-v',      overlay);
      if (prog && tot > 0) prog.textContent = cur + ' / ' + tot + ' drawn';
      if (eta && tot > 0 && cur < tot) {
        var spd = (typeof lgwChampData !== 'undefined' && lgwChampData.drawSpeed)
                ? parseFloat(lgwChampData.drawSpeed) : 1.0;
        var secs = Math.round(((tot - cur) * 2600 * spd) / 1000);
        eta.textContent = secs > 0 ? ('~' + secs + 's remaining') : '';
      } else if (eta) { eta.textContent = ''; }
    }

    function doPoll() {
      var sectionId = wrap.dataset.section || '0';
      post('lgw_champ_poll', { champ_id: cupId, version: lastVersion, cursor: cursor, section: sectionId }, function (res) {
        if (!res.success) { scheduleNextPoll(); return; }
        var data = res.data;
        var gotNew = data.pairs && data.pairs.length > 0;

        if (gotNew) {
          pollDelay = 1000; idleCount = 0;
          data.pairs.forEach(function (p) { pairsQueue.push(p); });
          cursor = data.cursor;
          updateProgress(cursor, data.total);
          revealNextPair();
        } else {
          idleCount++;
          pollDelay = data.in_progress ? 1000 : Math.min(8000, 1000 * Math.pow(2, idleCount));
        }

        if (data.version !== lastVersion && data.version > 0) {
          lastVersion = data.version; cursor = data.cursor || 0;
          idleCount = 0; pollDelay = 1000;
        }

        if (data.complete && data.bracket) {
          clearTimeout(pollTimeout);
          var bracketToShow = data.bracket;
          var waitCount = 0;
          var waitForAnim = setInterval(function () {
            waitCount++;
            if ((!animating && pairsQueue.length === 0) || waitCount > 40) {
              clearInterval(waitForAnim);
              animating = false;
              pairsQueue = [];
              showViewerComplete(bracketToShow);
            }
          }, 200);
          return;
        }

        scheduleNextPoll();
      });
    }

    scheduleNextPoll();
  }

  // ── Navigate to a match card in the draw and highlight it ────────────────────
  /**
   * Close the search modal, switch to the correct section tab, scroll the bracket
   * to the target match card, and flash a highlight so the admin knows where to click.
   *
   * @param {string} champId     – data-champ-id value
   * @param {string|number} sectionIdx – numeric section index or 'final'
   * @param {number|string} gameNum    – match game_num
   */
  function navigateToMatch(champId, sectionIdx, gameNum) {
    // 1. Close any open search modal
    var modal = qs('.lgw-champ-search-modal');
    if (modal && modal.parentNode) modal.parentNode.removeChild(modal);

    // 2. Find the tabs-outer for this championship
    var outer = document.querySelector('.lgw-champ-tabs-outer[data-champ-id="' + champId + '"]');
    if (!outer) return;

    // 3. Switch to the correct section tab
    var sectionStr = String(sectionIdx);
    var tabBtn = outer.querySelector('.lgw-champ-section-tab[data-section="' + sectionStr + '"]');
    if (tabBtn) {
      outer.querySelectorAll('.lgw-champ-section-tab').forEach(function (b) { b.classList.remove('active'); });
      outer.querySelectorAll('.lgw-champ-section-pane').forEach(function (p) { p.classList.remove('active'); });
      tabBtn.classList.add('active');
      var pane = outer.querySelector('.lgw-champ-section-pane[data-section="' + sectionStr + '"]');
      if (pane) pane.classList.add('active');
      // Persist in sessionStorage so a page refresh remembers the tab
      try { sessionStorage.setItem('lgw_champ_section_' + champId, sectionStr); } catch (e) {}
    }

    // 4. Find the match card by game_num within the now-active pane
    if (!gameNum) return;
    var paneEl = outer.querySelector('.lgw-champ-section-pane[data-section="' + sectionStr + '"]');
    if (!paneEl) return;
    var card = paneEl.querySelector('.lgw-champ-match[data-game-num="' + gameNum + '"]');
    if (!card) return;

    // 5. Scroll the bracket outer container so the card is visible, then scroll the page
    var bracketOuter = paneEl.querySelector('.lgw-champ-bracket-outer');
    if (bracketOuter) {
      // Horizontal scroll within bracket to bring round into view
      var roundEl = card.closest('.lgw-champ-round');
      if (roundEl) {
        bracketOuter.scrollTo({ left: roundEl.offsetLeft, behavior: 'smooth' });
      }
    }
    // Small delay so the tab switch and horizontal scroll settle before vertical scroll
    setTimeout(function () {
      card.scrollIntoView({ behavior: 'smooth', block: 'center' });
      // 6. Flash highlight
      card.classList.add('lgw-champ-match-highlight');
      setTimeout(function () { card.classList.remove('lgw-champ-match-highlight'); }, 2200);
    }, 120);
  }

  // ── Championship Search Modal ─────────────────────────────────────────────────
  /**
   * Opens a search modal for a championship.
   * Allows searching by player name or club, with a toggle between
   * Fixtures (upcoming/undated) and Results (scored matches).
   * Future-dated fixtures that already have a result appear in both modes.
   */
  function openChampSearch(champId) {
    var existing = qs('.lgw-champ-search-modal');
    if (existing) existing.parentNode.removeChild(existing);

    var nonce = (typeof lgwChampData !== 'undefined') ? lgwChampData.searchNonce : '';

    var modal = document.createElement('div');
    modal.className = 'lgw-champ-search-modal';
    modal.innerHTML =
      '<div class="lgw-champ-search-box">' +
        '<div class="lgw-champ-search-header">' +
          '<span class="lgw-champ-search-title">🔍 Championship Search</span>' +
          '<button class="lgw-champ-search-close" aria-label="Close">&times;</button>' +
        '</div>' +
        '<div class="lgw-champ-search-hint">Search by player name or club name across all sections and rounds.</div>' +
        '<div class="lgw-champ-search-controls">' +
          '<input class="lgw-champ-search-input" type="text" placeholder="Enter name or club…" autocomplete="off" autocorrect="off">' +
          '<div class="lgw-champ-search-mode-tabs">' +
            '<button class="lgw-champ-search-mode-btn active" data-mode="fixtures">📅 Fixtures</button>' +
            '<button class="lgw-champ-search-mode-btn" data-mode="results">✅ Results</button>' +
          '</div>' +
          '<button class="lgw-champ-search-btn">Search</button>' +
        '</div>' +
        '<div class="lgw-champ-search-status"></div>' +
        '<div class="lgw-champ-search-results"></div>' +
        '<div class="lgw-champ-search-actions" style="display:none">' +
          '<button class="lgw-champ-search-copy-btn">📋 Copy as Text</button>' +
          '<button class="lgw-champ-search-pdf-btn">📄 Export PDF</button>' +
          '<button class="lgw-champ-search-csv-btn">📥 Export CSV</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(modal);

    var input       = qs('.lgw-champ-search-input',   modal);
    var searchBtn   = qs('.lgw-champ-search-btn',      modal);
    var statusEl    = qs('.lgw-champ-search-status',   modal);
    var resultsEl   = qs('.lgw-champ-search-results',  modal);
    var actionsEl   = qs('.lgw-champ-search-actions',  modal);
    var modeBtns    = qsa('.lgw-champ-search-mode-btn',modal);
    var currentMode = 'fixtures';
    var lastData    = null;

    // Close button
    qs('.lgw-champ-search-close', modal).addEventListener('click', function () {
      modal.parentNode.removeChild(modal);
    });
    modal.addEventListener('click', function (e) {
      if (e.target === modal) modal.parentNode.removeChild(modal);
    });

    // Mode tab switching
    modeBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        modeBtns.forEach(function (b) { b.classList.remove('active'); });
        btn.classList.add('active');
        currentMode = btn.dataset.mode;
        // Re-render last results in new mode if available
        if (lastData) renderResults(lastData, currentMode);
      });
    });

    // Focus input
    input.focus();

    function doSearch() {
      var q = input.value.trim();
      if (q.length < 2) {
        statusEl.textContent = 'Please enter at least 2 characters.';
        resultsEl.innerHTML  = '';
        actionsEl.style.display = 'none';
        return;
      }
      searchBtn.disabled = true;
      searchBtn.textContent = '⏳ Searching…';
      statusEl.textContent = '';
      resultsEl.innerHTML  = '';
      actionsEl.style.display = 'none';

      post('lgw_champ_search', {
        champ_id: champId,
        query:    q,
        mode:     'both',
        nonce:    nonce,
      }, function (res) {
        searchBtn.disabled = false;
        searchBtn.textContent = 'Search';
        if (!res.success) {
          statusEl.textContent = res.data || 'Search failed.';
          return;
        }
        lastData = res.data;
        renderResults(lastData, currentMode);
      });
    }

    searchBtn.addEventListener('click', doSearch);
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter') doSearch(); });

    function renderResults(data, mode) {
      var allMatches = data.matches || [];
      var query      = data.query   || '';
      var queryUpper = query.toUpperCase();

      // Filter to current mode
      var filtered = allMatches.filter(function (m) {
        return mode === 'fixtures' ? m.is_fixture : m.is_result;
      });

      if (filtered.length === 0) {
        var noun0 = mode === 'fixtures' ? 'upcoming fixtures' : 'results';
        statusEl.textContent = 'No ' + noun0 + ' found for "' + query + '".';
        resultsEl.innerHTML  = '';
        actionsEl.style.display = 'none';
        return;
      }

      var noun1  = mode === 'fixtures' ? 'fixture' : 'result';
      var nounPl = filtered.length === 1 ? noun1 : noun1 + 's';
      var isAdmin = typeof lgwChampData !== 'undefined' && lgwChampData.isAdmin == 1;
      statusEl.textContent = filtered.length + ' ' + nounPl + ' found for "' + query + '" in ' + escHtml(data.title) + '.';
      if (isAdmin) {
        var hint = document.createElement('span');
        hint.className = 'lgw-champ-sr-admin-hint';
        hint.textContent = ' Click a row to go to that match in the draw.';
        statusEl.appendChild(hint);
      }

      // Partition each match into home and away buckets relative to the query
      // A match can appear in both if the query matches both teams (e.g. club with two entries).
      var homeMatches = filtered.filter(function (m) {
        return m.home && m.home.toUpperCase().indexOf(queryUpper) !== -1;
      });
      var awayMatches = filtered.filter(function (m) {
        return m.away && m.away.toUpperCase().indexOf(queryUpper) !== -1;
      });

      // Helper: group an array of matches by date string
      function groupByDate(arr) {
        var groups = {}, order = [];
        arr.forEach(function (m) {
          var key = m.date || 'TBC';
          if (!groups[key]) { groups[key] = []; order.push(key); }
          groups[key].push(m);
        });
        return { groups: groups, order: order };
      }

      // Helper: render a table for one home/away group
      function renderTable(matches, isHome) {
        if (!matches.length) return '';
        var grouped = groupByDate(matches);
        var html = '<table class="lgw-champ-sr-table">';
        html += '<thead><tr>';
        html += '<th>Date</th>';
        html += '<th class="lgw-champ-sr-section-cell">Section</th>';
        html += '<th class="lgw-champ-sr-round">Round</th>';
        html += '<th>Match</th>';
        if (mode === 'results') html += '<th class="lgw-champ-sr-score-col">Score</th>';
        html += '</tr></thead><tbody>';

        grouped.order.forEach(function (dateKey) {
          var dayMatches = grouped.groups[dateKey];
          var dateLabel  = dateKey === 'TBC' ? '<span class="lgw-champ-sr-tbd">TBC</span>' : escHtml(dateKey);

          dayMatches.forEach(function (m, i) {
            var homeWin = m.has_result && parseFloat(m.home_score) > parseFloat(m.away_score);
            var awayWin = m.has_result && parseFloat(m.away_score) > parseFloat(m.home_score);
            var matchedIsHome = isHome;

            // Build the inline "A vs B" match cell
            var homeClass = 'lgw-champ-sr-vs-name' + (matchedIsHome ? ' lgw-champ-sr-highlight' : '');
            var awayClass = 'lgw-champ-sr-vs-name' + (!matchedIsHome ? ' lgw-champ-sr-highlight' : '');
            var matchCell =
              '<div class="lgw-champ-sr-vs-row">' +
                '<span class="' + homeClass + '">' + champBadge(m.home) + escHtml(m.home || 'TBD') + '</span>' +
                '<span class="lgw-champ-sr-vs-sep">vs</span>' +
                '<span class="' + awayClass + '">' + champBadge(m.away) + escHtml(m.away || 'TBD') + '</span>' +
              '</div>';

            // Score cell
            var scoreCell = '';
            if (mode === 'results') {
              if (m.has_result) {
                var hs = escHtml(String(m.home_score));
                var as = escHtml(String(m.away_score));
                scoreCell = '<span class="lgw-champ-sr-score-val' + (homeWin ? ' win-h' : '') + '">' + hs + '</span>'
                  + '<span class="lgw-champ-sr-score-dash">–</span>'
                  + '<span class="lgw-champ-sr-score-val' + (awayWin ? ' win-a' : '') + '">' + as + '</span>';
              } else {
                scoreCell = '<span class="lgw-champ-sr-score-pending">—</span>';
              }
            }

            var isAdmin = typeof lgwChampData !== 'undefined' && lgwChampData.isAdmin == 1;
            var navAttrs = (isAdmin && m.game_num != null && m.section_idx != null)
              ? ' data-champ-id="' + escHtml(String(champId)) + '"'
                + ' data-section-idx="' + escHtml(String(m.section_idx)) + '"'
                + ' data-game-num="' + escHtml(String(m.game_num)) + '"'
                + ' class="lgw-champ-sr-row lgw-champ-sr-row-nav"'
                + ' title="Click to go to this match in the draw"'
              : ' class="lgw-champ-sr-row"';

            html += '<tr' + navAttrs + '>';
            html += '<td class="lgw-champ-sr-date">' + (i === 0 ? dateLabel : '') + '</td>';
            html += '<td class="lgw-champ-sr-section-cell">' + escHtml(m.section) + '</td>';
            html += '<td class="lgw-champ-sr-round">' + escHtml(m.round) + '</td>';
            html += '<td class="lgw-champ-sr-match-cell">' + matchCell + '</td>';
            if (mode === 'results') {
              html += '<td class="lgw-champ-sr-score-cell">' + scoreCell + '</td>';
            }
            html += '</tr>';
          });
        });

        html += '</tbody></table>';
        return html;
      }

      var html = '';

      if (homeMatches.length) {
        html += '<div class="lgw-champ-sr-group">';
        html += '<div class="lgw-champ-sr-group-label lgw-champ-sr-home-label">🏠 Home Fixtures</div>';
        html += renderTable(homeMatches, true);
        html += '</div>';
      }

      if (awayMatches.length) {
        html += '<div class="lgw-champ-sr-group">';
        html += '<div class="lgw-champ-sr-group-label lgw-champ-sr-away-label">✈️ Away Fixtures</div>';
        html += renderTable(awayMatches, false);
        html += '</div>';
      }

      resultsEl.innerHTML = html;
      actionsEl.style.display = 'flex';

      // Delegated click: admin rows navigate to the match in the draw
      resultsEl.onclick = function (e) {
        var row = e.target.closest('.lgw-champ-sr-row-nav');
        if (!row) return;
        navigateToMatch(row.dataset.champId, row.dataset.sectionIdx, row.dataset.gameNum);
      };
    }

    // ── Copy as Text ─────────────────────────────────────────────────────────────
    qs('.lgw-champ-search-copy-btn', modal).addEventListener('click', function () {
      if (!lastData) return;
      var mode       = currentMode;
      var query      = lastData.query || '';
      var queryUpper = query.toUpperCase();
      var filtered   = (lastData.matches || []).filter(function (m) {
        return mode === 'fixtures' ? m.is_fixture : m.is_result;
      });

      var homeMatches = filtered.filter(function (m) {
        return m.home && m.home.toUpperCase().indexOf(queryUpper) !== -1;
      });
      var awayMatches = filtered.filter(function (m) {
        return m.away && m.away.toUpperCase().indexOf(queryUpper) !== -1;
      });

      var lines = [];
      var modeLabel = mode === 'fixtures' ? 'Fixtures' : 'Results';
      lines.push(escPlain(lastData.title) + ' — ' + modeLabel + ' for "' + escPlain(query) + '"');
      lines.push('');

      function formatGroup(matches, isHome) {
        if (!matches.length) return;
        lines.push(isHome ? '🏠 HOME FIXTURES' : '✈️ AWAY FIXTURES');
        lines.push('');
        var lastDate = null;
        matches.forEach(function (m) {
          var dateStr = m.date || 'TBC';
          if (dateStr !== lastDate) {
            if (lastDate !== null) lines.push('');
            lines.push('📅 ' + dateStr);
            lastDate = dateStr;
          }
          var opponent = isHome ? (m.away || 'TBD') : (m.home || 'TBD');
          var self_    = isHome ? (m.home || 'TBD') : (m.away || 'TBD');
          var line     = '  ' + escPlain(m.section) + ' · ' + escPlain(m.round) + ' · ' + escPlain(self_) + ' v ' + escPlain(opponent);
          if (mode === 'results' && m.has_result) {
            var s1 = isHome ? m.home_score : m.away_score;
            var s2 = isHome ? m.away_score : m.home_score;
            line += '  (' + s1 + '–' + s2 + ')';
          }
          lines.push(line);
        });
        lines.push('');
      }

      formatGroup(homeMatches, true);
      formatGroup(awayMatches, false);

      var text = lines.join('\n');

      var btn = this;
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () {
          btn.textContent = '✅ Copied!';
          setTimeout(function () { btn.textContent = '📋 Copy as Text'; }, 2000);
        }).catch(function () {
          fallbackCopy(text, btn);
        });
      } else {
        fallbackCopy(text, btn);
      }
    });

    function escPlain(s) { return String(s || ''); }

    function fallbackCopy(text, btn) {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.style.cssText = 'position:fixed;top:-9999px;left:-9999px;opacity:0';
      document.body.appendChild(ta);
      ta.select();
      try {
        document.execCommand('copy');
        btn.textContent = '✅ Copied!';
        setTimeout(function () { btn.textContent = '📋 Copy as Text'; }, 2000);
      } catch (e) {
        btn.textContent = '❌ Copy failed';
        setTimeout(function () { btn.textContent = '📋 Copy as Text'; }, 2000);
      }
      document.body.removeChild(ta);
    }

    // ── Export PDF ───────────────────────────────────────────────────────────────
    // Opens a print-ready popup containing the search results plus the sponsor
    // banner (if configured). The user saves as PDF from the browser print dialog.
    qs('.lgw-champ-search-pdf-btn', modal).addEventListener('click', function () {
      if (!lastData) return;

      // Collect sponsor image from the nearest lgw-champ-wrap's data-sponsors,
      // or from lgwData / lgwChampData globals.
      var sponsorHtml = '';
      var sponsorImg  = '';
      var sponsorName = '';
      var sponsorUrl  = '';

      // Try primary sponsor from the DOM (first lgw-sponsor-primary image on page)
      var primaryEl = document.querySelector('.lgw-sponsor-primary img');
      if (primaryEl) {
        sponsorImg  = primaryEl.src || '';
        sponsorName = primaryEl.alt || '';
        var parentA = primaryEl.parentElement;
        if (parentA && parentA.tagName === 'A') sponsorUrl = parentA.href || '';
      }

      if (sponsorImg) {
        var imgTag = '<img src="' + escHtml(sponsorImg) + '" alt="' + escHtml(sponsorName) + '" style="max-height:60px;max-width:200px;object-fit:contain">';
        sponsorHtml = '<div style="text-align:center;padding:16px 0 8px;border-top:1px solid #d0d5e8;margin-top:24px">'
          + '<div style="font-size:11px;color:#888;margin-bottom:6px;letter-spacing:.05em;text-transform:uppercase">Sponsored by</div>'
          + (sponsorUrl ? '<a href="' + escHtml(sponsorUrl) + '">' + imgTag + '</a>' : imgTag)
          + '</div>';
      }

      var mode   = currentMode;
      var query  = lastData.query || '';
      var status = qs('.lgw-champ-search-status', modal).textContent;

      // Inline the results HTML — strip badge <img> (they may be cross-origin),
      // keep structure for print styling
      var bodyHtml = qs('.lgw-champ-search-results', modal).innerHTML
        .replace(/<img[^>]*class="lgw-champ-team-badge"[^>]*>/gi, '');

      var modeLabel = mode === 'fixtures' ? 'Fixtures' : 'Results';
      var printTitle = escHtml(lastData.title) + ' — ' + modeLabel + ' for "' + escHtml(query) + '"';

      var printWin = window.open('', '_blank', 'width=860,height=700');
      if (!printWin) { alert('Pop-up blocked — please allow pop-ups for this site.'); return; }

      printWin.document.write(
        '<!DOCTYPE html><html><head><meta charset="utf-8">' +
        '<title>' + printTitle + '</title>' +
        '<style>' +
          '@page{margin:18mm 14mm}' +
          'body{font-family:Arial,sans-serif;font-size:12px;color:#111;margin:0;padding:0}' +
          'h1{font-size:16px;color:#1a2e5a;margin:0 0 4px}' +
          '.subtitle{font-size:12px;color:#555;margin-bottom:14px}' +
          '.lgw-champ-sr-group{margin-bottom:18px}' +
          '.lgw-champ-sr-group-label{font-size:13px;font-weight:700;padding:5px 8px;border-radius:3px 3px 0 0;margin-bottom:0}' +
          '.lgw-champ-sr-home-label{background:#1a2e5a;color:#fff}' +
          '.lgw-champ-sr-away-label{background:#1a4e6e;color:#fff}' +
          'table{width:100%;border-collapse:collapse;margin-bottom:4px}' +
          'th{background:#1a2e5a;color:#fff;padding:5px 7px;font-size:11px;text-align:left;font-weight:600}' +
          'td{padding:5px 7px;border-bottom:1px solid #eaedf6;font-size:11px;vertical-align:middle}' +
          'tr:nth-child(even) td{background:#f8f9fd}' +
          '.lgw-champ-sr-vs-row{display:flex;align-items:center;gap:5px;flex-wrap:nowrap}' +
          '.lgw-champ-sr-vs-name{display:inline-flex;align-items:center;gap:4px;white-space:nowrap}' +
          '.lgw-champ-sr-vs-sep{font-size:10px;color:#999;font-weight:600;padding:0 2px}' +
          '.lgw-champ-sr-highlight{font-weight:700;background:#fffbe6;border-radius:2px;padding:1px 3px}' +
          '.lgw-champ-sr-tbd{color:#999;font-style:italic}' +
          '.lgw-champ-sr-score-val{font-size:11px;font-weight:600;padding:0 1px}' +
          '.win-h,.win-a{font-weight:800;color:#0a3622}' +
          '.lgw-champ-sr-score-dash{color:#aaa;padding:0 1px}' +
          '.lgw-champ-sr-date{white-space:nowrap;font-weight:600;color:#1a2e5a;vertical-align:top;padding-top:7px}' +
          '.lgw-champ-sr-section-cell{font-size:10px;color:#666;white-space:nowrap}' +
          '.lgw-champ-sr-round{font-size:10px;color:#888;font-style:italic;white-space:nowrap}' +
          'img{display:none}' +  // badges hidden — cross-origin safe
        '</style>' +
        '</head><body>' +
        '<h1>' + printTitle + '</h1>' +
        '<div class="subtitle">' + escHtml(status) + '</div>' +
        bodyHtml +
        sponsorHtml +
        '</body></html>'
      );
      printWin.document.close();
      printWin.focus();
      setTimeout(function () { printWin.print(); }, 500);
    });

    // ── Export CSV ───────────────────────────────────────────────────────────────
    qs('.lgw-champ-search-csv-btn', modal).addEventListener('click', function () {
      if (!lastData) return;
      var mode       = currentMode;
      var query      = lastData.query || '';
      var queryUpper = query.toUpperCase();
      var filtered   = (lastData.matches || []).filter(function (m) {
        return mode === 'fixtures' ? m.is_fixture : m.is_result;
      });
      if (!filtered.length) return;

      var header = mode === 'results'
        ? ['H/A','Section','Round','Date','Matched Entry','Opponent','Home Score','Away Score']
        : ['H/A','Section','Round','Date','Matched Entry','Opponent'];

      var rows = [header];
      filtered.forEach(function (m) {
        var isHome  = m.home && m.home.toUpperCase().indexOf(queryUpper) !== -1;
        var matched = isHome ? (m.home || '') : (m.away || '');
        var opp     = isHome ? (m.away || '') : (m.home || '');
        var ha      = isHome ? 'H' : 'A';
        if (mode === 'results') {
          rows.push([ha, m.section, m.round, m.date || '', matched, opp, m.home_score !== null ? m.home_score : '', m.away_score !== null ? m.away_score : '']);
        } else {
          rows.push([ha, m.section, m.round, m.date || '', matched, opp]);
        }
      });

      var csv = rows.map(function (row) {
        return row.map(function (cell) {
          var s = String(cell);
          if (s.indexOf(',') !== -1 || s.indexOf('"') !== -1 || s.indexOf('\n') !== -1) {
            s = '"' + s.replace(/"/g, '""') + '"';
          }
          return s;
        }).join(',');
      }).join('\r\n');

      var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
      var url  = URL.createObjectURL(blob);
      var a    = document.createElement('a');
      a.href     = url;
      a.download = 'champ-' + query.replace(/[^a-z0-9]/gi, '_') + '-' + mode + '.csv';
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    });
  }

  // ── Init ──────────────────────────────────────────────────────────────────────
  function initChampWidget(wrap) {
    var cupId = wrap.dataset.champId;
    if (!cupId) return;

    var bracketData = null;
    try {
      var raw = wrap.dataset.bracket;
      if (raw) bracketData = JSON.parse(raw);
    } catch (e) {}

    var drawVersion    = wrap.dataset.drawVersion    || '0';
    var drawInProgress = wrap.dataset.drawInProgress === '1';

    if (bracketData && bracketData.rounds && bracketData.rounds.length) {
      renderBracket(wrap, bracketData);
    } else {
      var emptyEl = qs('.lgw-champ-empty', wrap);
      if (emptyEl) emptyEl.style.display = '';
    }
    renderSponsorBar(wrap);

    // Poll only if no complete bracket present on page load
    var shouldPoll = drawVersion === '0' || (drawInProgress && !bracketData);
    if (shouldPoll) {
      startDrawPoll(wrap, drawVersion);
    }

    initAdminDraw(wrap);

    // Wire up server-rendered print button if present
    var printBtn = qs('.lgw-champ-print-btn', wrap);
    if (printBtn) {
      printBtn.addEventListener('click', function () {
        var rounds = qsa('.lgw-champ-round', wrap);
        rounds.forEach(function (r) { r.style.display = 'flex'; });
        window.print();
        setTimeout(function () {
          rounds.forEach(function (r) { r.style.display = ''; });
        }, 1000);
      });
    }
  }

  // ── Sticky section tab (persists across page refreshes via sessionStorage) ────
  function initSectionTabs(champId) {
    var outer = document.querySelector('.lgw-champ-tabs-outer');
    if (!outer) return;
    var storageKey = 'lgw_champ_section_' + champId;

    // Restore saved tab
    var saved = sessionStorage.getItem(storageKey);
    if (saved !== null) {
      var btn = outer.querySelector('.lgw-champ-section-tab[data-section="' + saved + '"]');
      if (btn) {
        outer.querySelectorAll('.lgw-champ-section-tab').forEach(function(b) { b.classList.remove('active'); });
        outer.querySelectorAll('.lgw-champ-section-pane').forEach(function(p) { p.classList.remove('active'); });
        btn.classList.add('active');
        var pane = outer.querySelector('.lgw-champ-section-pane[data-section="' + saved + '"]');
        if (pane) pane.classList.add('active');
      }
    }

    // Switch tab and save on click
    outer.querySelectorAll('.lgw-champ-section-tab').forEach(function(btn) {
      btn.addEventListener('click', function() {
        outer.querySelectorAll('.lgw-champ-section-tab').forEach(function(b) { b.classList.remove('active'); });
        outer.querySelectorAll('.lgw-champ-section-pane').forEach(function(p) { p.classList.remove('active'); });
        btn.classList.add('active');
        var pane = outer.querySelector('.lgw-champ-section-pane[data-section="' + btn.dataset.section + '"]');
        if (pane) {
          pane.classList.add('active');
          // Reset mobile bracket to current round now that the pane is visible
          var wrap = pane.querySelector('.lgw-champ-wrap');
          if (wrap && typeof wrap._lgwScrollToCurrentRound === 'function') {
            wrap._lgwScrollToCurrentRound();
          }
        }
        sessionStorage.setItem(storageKey, btn.dataset.section);
      });
    });
  }

  // Boot all cup widgets on page
  document.addEventListener('DOMContentLoaded', function () {
    qsa('.lgw-champ-wrap').forEach(initChampWidget);
    // Sticky section tabs — run after wraps are initialised
    var firstWrap = document.querySelector('.lgw-champ-wrap');
    if (firstWrap && firstWrap.dataset.champId) {
      initSectionTabs(firstWrap.dataset.champId);
    }
    // Wire up search buttons
    qsa('.lgw-champ-search-tab').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        var champId = btn.dataset.champId;
        if (champId) openChampSearch(champId);
      });
    });
  });

})();
