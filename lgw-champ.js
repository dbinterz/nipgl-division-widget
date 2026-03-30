/* LGW Championships JS - v6.4.0 */
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

  function renderTeamRow(team, score, isWinner, isLoser, placeholder) {
    var cls = 'lgw-champ-team';
    if (isWinner) cls += ' lgw-champ-winner';
    if (isLoser)  cls += ' lgw-champ-loser';
    var badge = team ? champBadge(team) : '';
    var isTbd = !team;
    var nameCls = 'lgw-champ-team-name' + (isTbd ? ' tbd' : '');
    var nameStr = team ? escHtml(team) : escHtml(placeholder || 'TBD');
    var scoreStr = (score !== null && score !== undefined && score !== '') ? escHtml(score) : '';
    return '<div class="' + cls + '">'
      + badge
      + '<span class="' + nameCls + '">' + nameStr + '</span>'
      + (scoreStr !== '' ? '<span class="lgw-champ-score">' + scoreStr + '</span>' : '')
      + '</div>';
  }

  function renderMatch(match) {
    var home       = match.home  || '';
    var away       = match.away  || '';
    var hs         = match.home_score;
    var as         = match.away_score;
    var hasResult  = (hs !== null && hs !== undefined && hs !== '' &&
                      as !== null && as !== undefined && as !== '');
    var homeWin    = hasResult && parseFloat(hs) > parseFloat(as);
    var awayWin    = hasResult && parseFloat(as) > parseFloat(hs);

    // Placeholders for null slots — PHP stores which game feeds each slot
    var homePlaceholder = home ? null
      : (match.prev_game_home ? 'Winner of Game ' + match.prev_game_home : 'TBD');
    var awayPlaceholder = away ? null
      : (match.prev_game_away ? 'Winner of Game ' + match.prev_game_away : 'TBD');

    var cls = 'lgw-champ-match';
    if (match.bye)      cls += ' lgw-champ-bye';
    if (!home && !away) cls += ' lgw-champ-tbd';

    var gameNumHtml = match.game_num
      ? '<div class="lgw-champ-game-num">Game ' + match.game_num + '</div>'
      : '';

    return '<div class="' + cls + '">'
      + gameNumHtml
      + renderTeamRow(home, hasResult ? hs : null, homeWin, awayWin && home, homePlaceholder)
      + renderTeamRow(away, hasResult ? as : null, awayWin, homeWin && away, awayPlaceholder)
      + '</div>';
  }

  function renderBracket(wrap, data) {
    var rounds  = data.rounds  || [];
    var matches = data.matches || [];
    var dates   = data.dates   || [];

    // ── Mobile tabs
    var tabsEl = qs('.lgw-champ-tabs', wrap);
    var tabsInner = tabsEl ? qs('.lgw-champ-tabs-inner', tabsEl) : null;
    if (tabsInner) {
      tabsInner.innerHTML = '';
      rounds.forEach(function (name, i) {
        var tab = document.createElement('div');
        tab.className = 'lgw-champ-tab' + (i === 0 ? ' active' : '');
        tab.textContent = name;
        tab.dataset.round = i;
        tabsInner.appendChild(tab);
      });
    }

    // ── Bracket
    var bracketEl = qs('.lgw-champ-bracket', wrap);
    if (!bracketEl) return;
    bracketEl.innerHTML = '';

    // Game numbers and prev_game links are stored in the match data by PHP at draw time.
    // No JS pre-pass needed — just read match.game_num, match.prev_game_home, match.prev_game_away.

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
      roundEl.className = 'lgw-champ-round' + (isFinal ? ' lgw-champ-round-final' : '') + (ri === 0 ? ' mobile-active' : '');
      roundEl.dataset.round = ri;

      var dateStr = dates[ri] ? '<span class="lgw-champ-round-date">' + escHtml(dates[ri]) + '</span>' : '';
      roundEl.innerHTML = '<div class="lgw-champ-round-header">' + escHtml(roundName) + dateStr + '</div>'
        + '<div class="lgw-champ-round-slots"></div>';

      var slotsEl = qs('.lgw-champ-round-slots', roundEl);
      roundMatches.forEach(function (match, mi) {
        var matchEl = document.createElement('div');
        matchEl.innerHTML = renderMatch(match);
        var card = matchEl.firstElementChild;
        card.dataset.round = ri;
        card.dataset.match = mi;
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
    if (tabsInner) {
      tabsInner.addEventListener('click', function (e) {
        var tab = e.target.closest('.lgw-champ-tab');
        if (!tab) return;
        var ri2 = parseInt(tab.dataset.round);
        qsa('.lgw-champ-tab', tabsInner).forEach(function (t) { t.classList.toggle('active', t === tab); });
        qsa('.lgw-champ-round', bracketEl).forEach(function (r) {
          r.classList.toggle('mobile-active', parseInt(r.dataset.round) === ri2);
        });
      });
    }
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

  // ── Status bar ────────────────────────────────────────────────────────────────
  // ── Sponsor bar ───────────────────────────────────────────────────────────────
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
        if (pane) pane.classList.add('active');
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
  });

})();
