/* LGW Cup Bracket JS - v6.1.6 */
(function () {
  'use strict';

  var ajaxUrl    = (typeof lgwData     !== 'undefined' && lgwData.ajaxUrl)
                 ? lgwData.ajaxUrl
                 : (typeof lgwCupData  !== 'undefined' && lgwCupData.ajaxUrl)
                 ? lgwCupData.ajaxUrl
                 : '/wp-admin/admin-ajax.php';
  var badges     = (typeof lgwData !== 'undefined') ? lgwData.badges     : {};
  var clubBadges = (typeof lgwData !== 'undefined') ? lgwData.clubBadges : {};

  // ── Badge lookup (same logic as lgw-widget.js) ─────────────────────────────
  function badgeImg(team, cls) {
    if (!team) return '';
    cls = cls || 'lgw-cup-team-badge';
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
    return String(s || '')
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

  // Abbreviate a team name: e.g. "Ballymena B" -> "B'mena B", "Salisbury A" -> "Sal A"
  function abbrevTeam(name) {
    if (!name) return '?';
    var parts = name.trim().split(/\s+/);
    if (parts.length === 1) return parts[0].slice(0, 5);
    var suffix   = parts[parts.length - 1];
    var mainParts = parts.slice(0, -1);
    var main = mainParts.join(' ');
    var abbrev;
    if (main.length <= 4) {
      abbrev = main;
    } else {
      abbrev = mainParts[0].slice(0, 3);
      for (var i = 1; i < mainParts.length; i++) abbrev += mainParts[i].slice(0, 1);
    }
    return abbrev + ' ' + suffix;
  }

  // Build predecessor placeholder from a previous round match
  function buildPlaceholder(prevMatch) {
    if (!prevMatch || prevMatch.bye) return null;
    var h = prevMatch.home ? abbrevTeam(prevMatch.home) : '?';
    var a = prevMatch.away ? abbrevTeam(prevMatch.away) : '?';
    if (prevMatch.home && prevMatch.away) return h + '/' + a;
    return null;
  }

  function renderTeamRow(team, score, isWinner, isLoser, drawNum, placeholder) {
    var cls = 'lgw-cup-team';
    if (isWinner) cls += ' lgw-cup-winner';
    if (isLoser)  cls += ' lgw-cup-loser';
    var badge   = team ? badgeImg(team) : '';
    var nameCls = 'lgw-cup-team-name' + (team ? '' : ' tbd');
    var nameStr = team ? escHtml(team)
                : (placeholder ? '<span class="lgw-cup-placeholder">' + escHtml(placeholder) + '</span>'
                               : 'TBD');
    var scoreStr = (score !== null && score !== undefined && score !== '') ? escHtml(score) : '';
    var dnStr = (drawNum && scoreStr === '') ? '<span class="lgw-cup-draw-num">' + escHtml(drawNum) + '</span>' : '';
    return '<div class="' + cls + '">'
      + badge
      + '<span class="' + nameCls + '">' + nameStr + '</span>'
      + (scoreStr !== '' ? '<span class="lgw-cup-score">' + scoreStr + '</span>' : '')
      + dnStr
      + '</div>';
  }

  function renderMatch(match, homePlaceholder, awayPlaceholder) {
    var home       = match.home  || '';
    var away       = match.away  || '';
    var hs         = match.home_score;
    var as         = match.away_score;
    var hasResult  = (hs !== null && hs !== undefined && hs !== '' &&
                      as !== null && as !== undefined && as !== '');
    var homeWin    = hasResult && parseFloat(hs) > parseFloat(as);
    var awayWin    = hasResult && parseFloat(as) > parseFloat(hs);

    var cls = 'lgw-cup-match';
    if (match.bye)     cls += ' lgw-cup-bye';
    if (!home && !away) cls += ' lgw-cup-tbd';

    return '<div class="' + cls + '">'
      + renderTeamRow(home, hasResult ? hs : null, homeWin, awayWin && home, match.draw_num_home, home ? null : homePlaceholder)
      + renderTeamRow(away, hasResult ? as : null, awayWin, homeWin && away, match.draw_num_away, away ? null : awayPlaceholder)
      + '</div>';
  }

  function renderBracket(wrap, data) {
    var rounds  = data.rounds  || [];
    var matches = data.matches || [];
    var dates   = data.dates   || [];

    // ── Mobile tabs
    var tabsEl = qs('.lgw-cup-tabs', wrap);
    var tabsInner = tabsEl ? qs('.lgw-cup-tabs-inner', tabsEl) : null;
    if (tabsInner) {
      tabsInner.innerHTML = '';
      rounds.forEach(function (name, i) {
        var tab = document.createElement('div');
        tab.className = 'lgw-cup-tab' + (i === 0 ? ' active' : '');
        tab.textContent = name;
        tab.dataset.round = i;
        tabsInner.appendChild(tab);
      });
    }

    // ── Bracket
    var bracketEl = qs('.lgw-cup-bracket', wrap);
    if (!bracketEl) return;
    bracketEl.innerHTML = '';

    rounds.forEach(function (roundName, ri) {
      var roundMatches = matches[ri] || [];
      var isFinal = ri === rounds.length - 1;

      // Connector column before each round (except first)
      if (ri > 0) {
        var connCol = document.createElement('div');
        connCol.className = 'lgw-cup-connector-col';
        bracketEl.appendChild(connCol);
      }

      var roundEl = document.createElement('div');
      roundEl.className = 'lgw-cup-round' + (isFinal ? ' lgw-cup-round-final' : '') + (ri === 0 ? ' mobile-active' : '');
      roundEl.dataset.round = ri;

      var dateStr = dates[ri] ? '<span class="lgw-cup-round-date">' + escHtml(dates[ri]) + '</span>' : '';
      roundEl.innerHTML = '<div class="lgw-cup-round-header">' + escHtml(roundName) + dateStr + '</div>'
        + '<div class="lgw-cup-round-slots"></div>';

      var slotsEl = qs('.lgw-cup-round-slots', roundEl);
      roundMatches.forEach(function (match, mi) {
        // Build predecessor placeholders for TBD slots
        var homePlaceholder = null, awayPlaceholder = null;
        if (!match.home || !match.away) {
          var prevRound = matches[ri - 1] || [];
          // Standard mapping: match mi gets home from prevRound[mi*2], away from prevRound[mi*2+1]
          // This works for R2+ rounds. For the very first full round (ri===1 with prelims at ri===0),
          // some slots come from prelim winners — those are already populated as null with draw_num
          var prevHome = prevRound[mi * 2];
          var prevAway = prevRound[mi * 2 + 1];
          if (!match.home && prevHome) homePlaceholder = buildPlaceholder(prevHome);
          if (!match.away && prevAway) awayPlaceholder = buildPlaceholder(prevAway);
        }
        var matchEl = document.createElement('div');
        matchEl.innerHTML = renderMatch(match, homePlaceholder, awayPlaceholder);
        var card = matchEl.firstElementChild;
        card.dataset.round = ri;
        card.dataset.match = mi;
        var isAdmin = typeof lgwCupData !== 'undefined' && lgwCupData.isAdmin == 1;
        var scorePassphraseSet = typeof lgwCupData !== 'undefined' && lgwCupData.scorePassphraseSet == 1;
        var hasResult = match.home_score !== null && match.home_score !== undefined &&
                        match.away_score !== null && match.away_score !== undefined;
        if (match.home && match.away && !match.bye) {
          // Both teams known: open full scorecard modal (handles view, submission, login gate).
          // Admin score entry is accessible via a button inside that modal.
          card.classList.add(hasResult ? 'lgw-cup-has-scorecard' : 'lgw-cup-editable');
          card.style.cursor = 'pointer';
          // Capture round date and index for this closure
          (function(roundDate, roundIdx, matchIdx) {
            card.addEventListener('click', function () {
              openScorecardViewer(card, match, roundIdx, matchIdx, roundDate);
            });
          })(dates[ri] || '', ri, mi);
        } else if ((isAdmin || scorePassphraseSet) && !match.bye && (match.home || match.away)) {
          // One team TBD — only score entry makes sense (no scorecard possible yet)
          card.classList.add('lgw-cup-editable');
          card.addEventListener('click', function () {
            if (isAdmin || scoreToken) {
              openScoreEntry(wrap, card, match, ri, mi);
            } else {
              openScoreLoginModal(wrap, function () {
                openScoreEntry(wrap, card, match, ri, mi);
              });
            }
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
      connCol2.className = 'lgw-cup-connector-col';
      bracketEl.appendChild(connCol2);

      var champEl = document.createElement('div');
      champEl.className = 'lgw-cup-champion';
      champEl.innerHTML = '<div class="lgw-cup-trophy">🏆</div>'
        + '<div class="lgw-cup-champion-name">' + escHtml(champion) + '</div>'
        + '<div class="lgw-cup-champion-label">Champion</div>';
      bracketEl.appendChild(champEl);
    }

    // ── Mobile: scroll-based navigation
    if (tabsInner) {
      var bracketOuter = qs('.lgw-cup-bracket-outer', wrap);

      // Helper: scroll bracket to show a round column
      function scrollToRound(ri2) {
        var target = qs('.lgw-cup-round[data-round="' + ri2 + '"]', bracketEl);
        if (!target || !bracketOuter) return;
        // Activate tab
        qsa('.lgw-cup-tab', tabsInner).forEach(function (t) {
          t.classList.toggle('active', parseInt(t.dataset.round) === ri2);
        });
        // Highlight round header
        qsa('.lgw-cup-round', bracketEl).forEach(function (r) {
          r.classList.toggle('mobile-active', parseInt(r.dataset.round) === ri2);
        });
        // Scroll active tab into view in the tab bar
        var activeTab = qs('.lgw-cup-tab[data-round="' + ri2 + '"]', tabsInner);
        if (activeTab) activeTab.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
        // Scroll bracket to that round
        bracketOuter.scrollTo({ left: target.offsetLeft, behavior: 'smooth' });
      }

      // Tab click → scroll to round
      tabsInner.addEventListener('click', function (e) {
        var tab = e.target.closest('.lgw-cup-tab');
        if (!tab) return;
        scrollToRound(parseInt(tab.dataset.round));
      });

      // Round header click → scroll to that round
      qsa('.lgw-cup-round-header', bracketEl).forEach(function (hdr) {
        var roundEl2 = hdr.closest('.lgw-cup-round');
        if (!roundEl2) return;
        hdr.addEventListener('click', function () {
          var ri3 = parseInt(roundEl2.dataset.round);
          // Tap: advance to next round, or go to round 0 if already on last
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
              qsa('.lgw-cup-tab', tabsInner).forEach(function (t) {
                t.classList.toggle('active', parseInt(t.dataset.round) === ri4);
              });
              qsa('.lgw-cup-round', bracketEl).forEach(function (r) {
                r.classList.toggle('mobile-active', parseInt(r.dataset.round) === ri4);
              });
              var activeTab2 = qs('.lgw-cup-tab[data-round="' + ri4 + '"]', tabsInner);
              if (activeTab2) activeTab2.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
            }
          });
        }, { root: bracketOuter, threshold: 0.5 });
        qsa('.lgw-cup-round', bracketEl).forEach(function (r) { observer.observe(r); });
      }
    }
  }

  // ── Live draw animation ───────────────────────────────────────────────────────
  function runDrawAnimation(pairs, onComplete, wrap) {
    // pairs = [ {home, away, bye}, ... ] with optional {type:'header', label:'...'} entries
    var matchCount = pairs.filter(function (p) { return p.type !== 'header'; }).length;

    var overlay = document.createElement('div');
    overlay.className = 'lgw-cup-draw-overlay';
    overlay.innerHTML = [
      '<div class="lgw-cup-draw-title">🏆 Cup Draw</div>',
      '<div class="lgw-cup-draw-subtitle">The draw is being made…</div>',
      '<div class="lgw-cup-draw-reveal">',
        '<div class="lgw-cup-draw-slot-label" id="lgw-draw-slot-label">Match 1</div>',
        '<div class="lgw-cup-draw-team" id="lgw-draw-home"></div>',
        '<div class="lgw-cup-draw-vs">vs</div>',
        '<div class="lgw-cup-draw-team" id="lgw-draw-away"></div>',
      '</div>',
      '<div class="lgw-cup-draw-progress" id="lgw-draw-progress">0 / ' + matchCount + ' drawn</div>',
      '<div class="lgw-cup-draw-pairs" id="lgw-draw-pairs"></div>',
      '<button class="lgw-cup-draw-btn lgw-cup-draw-skip-btn" id="lgw-draw-skip">Skip to End</button>',
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
    var speed   = (typeof lgwCupData !== 'undefined' && lgwCupData.drawSpeed) ? parseFloat(lgwCupData.drawSpeed) : 1.0;
    var T_HOME  = Math.round(700  * speed);  // delay before showing home team
    var T_AWAY  = Math.round(1200 * speed);  // delay before showing away team
    var T_CHIP  = Math.round(1800 * speed);  // delay before adding chip
    var T_NEXT  = Math.round(2600 * speed);  // delay before advancing to next pair

    function addChip(pair) {
      var chip = document.createElement('div');
      chip.className = 'lgw-cup-draw-pair-chip' + (pair.bye ? ' lgw-cup-draw-bye-chip' : '');
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
        var subtitleEl = qs('.lgw-cup-draw-subtitle', overlay);
        var revealEl   = qs('.lgw-cup-draw-reveal',   overlay);
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
        divider.className = 'lgw-cup-draw-round-header';
        divider.textContent = pair.label;
        pairsEl.appendChild(divider);
        homeEl.classList.remove('show');
        awayEl.classList.remove('show');
        homeEl.textContent = '';
        awayEl.textContent = '';
        labelEl.textContent = pair.label;
        // Advance server cursor for header entry so total stays in sync
        var cupIdH = wrap ? wrap.dataset.cupId : '';
        var nonceH = (typeof lgwCupData !== 'undefined') ? lgwCupData.cupNonce : '';
        if (cupIdH && nonceH) {
          post('lgw_cup_advance_cursor', { cup_id: cupIdH, nonce: nonceH, draw_token: drawToken || '' }, function () {});
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
        var cupId = document.querySelector('.lgw-cup-wrap') ?
          document.querySelector('.lgw-cup-wrap').dataset.cupId : '';
        var nonce = (typeof lgwCupData !== 'undefined') ? lgwCupData.cupNonce : '';
        if (cupId && nonce) {
          post('lgw_cup_advance_cursor', {
            cup_id: cupId, nonce: nonce, draw_token: drawToken || ''
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
          divider.className = 'lgw-cup-draw-round-header';
          divider.textContent = pair.label;
          pairsEl.appendChild(divider);
          // Advance cursor for header too
          var cupIdS = wrap ? wrap.dataset.cupId : '';
          var nonceS = (typeof lgwCupData !== 'undefined') ? lgwCupData.cupNonce : '';
          if (cupIdS && nonceS) {
            post('lgw_cup_advance_cursor', { cup_id: cupIdS, nonce: nonceS, draw_token: drawToken || '' }, function () {});
          }
          continue;
        }
        matchIdx++;
        addChip(pair);
      }
      progressEl.textContent = matchCount + ' / ' + matchCount + ' drawn';
      var subtitleEl2 = qs('.lgw-cup-draw-subtitle', overlay);
      var revealEl2   = qs('.lgw-cup-draw-reveal',   overlay);
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
  // Uses lgwFetchScorecardOrSubmit (full modal with submission/login gate) when
  // lgw-scorecard.js is loaded; falls back to the cup-specific quick-view.
  // ri and mi are optional — only needed to wire up the admin "Enter Score" button
  function openScorecardViewer(card, match, ri, mi, roundDate) {
    var existing = qs('.lgw-cup-sc-modal');
    if (existing) existing.parentNode.removeChild(existing);

    var isAdmin        = typeof lgwCupData !== 'undefined' && lgwCupData.isAdmin == 1;
    var submissionMode = (typeof lgwCupData !== 'undefined' && lgwCupData.submissionMode) ? lgwCupData.submissionMode : 'open';
    var authClubVal    = (typeof lgwCupData !== 'undefined') ? (lgwCupData.authClub || '') : '';
    var canSubmit      = submissionMode !== 'disabled' && (submissionMode !== 'admin_only' || isAdmin);
    var nonce          = (typeof lgwCupData !== 'undefined') ? lgwCupData.cupNonce : '';

    // Admin gets an "Enter Score" button in the modal header for quick access to the score popover
    var adminScoreBtn = (isAdmin && ri !== undefined && mi !== undefined)
      ? '<button class="lgw-cup-sc-modal-score-btn" title="Enter quick score">✏️ Score</button>'
      : '';

    var modal = document.createElement('div');
    modal.className = 'lgw-cup-sc-modal';
    modal.innerHTML =
      '<div class="lgw-cup-sc-modal-box">' +
        '<div class="lgw-cup-sc-modal-header">' +
          '<span class="lgw-cup-sc-modal-title">' + escHtml(match.home) + ' v ' + escHtml(match.away) + '</span>' +
          adminScoreBtn +
          '<button class="lgw-cup-sc-modal-close" aria-label="Close">&times;</button>' +
        '</div>' +
        '<div class="lgw-cup-sc-modal-body"><p class="lgw-cup-sc-loading">Loading scorecard…</p></div>' +
      '</div>';
    document.body.appendChild(modal);

    qs('.lgw-cup-sc-modal-close', modal).addEventListener('click', function () {
      modal.parentNode.removeChild(modal);
    });
    modal.addEventListener('click', function (e) {
      if (e.target === modal) modal.parentNode.removeChild(modal);
    });

    // Wire up admin score button — closes modal, opens score popover
    var scoreBtn = qs('.lgw-cup-sc-modal-score-btn', modal);
    if (scoreBtn) {
      scoreBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        modal.parentNode.removeChild(modal);
        // Find the wrap element (ancestor of card)
        var wrap = card.closest('[data-cup-id]');
        if (wrap) openScoreEntry(wrap, card, match, ri, mi);
      });
    }

    var body = qs('.lgw-cup-sc-modal-body', modal);

    // Prefer the shared full scorecard modal (handles submission + login gate)
    if (typeof window.lgwFetchScorecardOrSubmit === 'function') {
      // Read cup title from bracket data for the division label
      var cupTitle = (function(){
        var wrap = card.closest('[data-cup-id]');
        if (!wrap) return 'Cup';
        try { var d = JSON.parse(wrap.dataset.bracket || '{}'); return d.title || 'Cup'; }
        catch(e) { return 'Cup'; }
      })();
      window.lgwFetchScorecardOrSubmit(match.home, match.away, roundDate || '', body, {
        canSubmit:      canSubmit,
        division:       cupTitle,
        maxPts:         0,  // Cup scorecards don't use points
        isAdmin:        isAdmin,
        submissionMode: submissionMode,
        authClub:       authClubVal,
        context:        'cup',
      });
      return;
    }

    // Fallback: cup quick-view (no submission, no player names beyond what cup stores)
    post('lgw_cup_get_scorecard', { home: match.home, away: match.away, nonce: nonce }, function (res) {
      if (!res.success) {
        body.innerHTML = '<p class="lgw-cup-sc-none">No scorecard has been submitted for this match yet.</p>';
        return;
      }
      var sc = res.data.sc;
      var conf = res.data.confirmed_by ? '✅ Confirmed by ' + escHtml(res.data.confirmed_by) : '⏳ Awaiting confirmation';
      var html = '<div class="lgw-cup-sc-summary">' +
        '<div class="lgw-cup-sc-meta">' +
          (sc.venue ? '<span>' + escHtml(sc.venue) + '</span>' : '') +
          (sc.date  ? '<span>' + escHtml(sc.date)  + '</span>' : '') +
        '</div>' +
        '<div class="lgw-cup-sc-conf">' + conf + '</div>' +
        '<table class="lgw-cup-sc-table">' +
          '<thead><tr><th>Rink</th><th colspan="2">Home</th><th>Score</th><th colspan="2">Away</th><th>Score</th></tr></thead>' +
          '<tbody>';
      (sc.rinks || []).forEach(function (rk) {
        var hs = rk.home_score !== null && rk.home_score !== undefined ? rk.home_score : '-';
        var as = rk.away_score !== null && rk.away_score !== undefined ? rk.away_score : '-';
        var homeWin = hs !== '-' && as !== '-' && parseFloat(hs) > parseFloat(as);
        var awayWin = hs !== '-' && as !== '-' && parseFloat(as) > parseFloat(hs);
        html += '<tr>' +
          '<td class="lgw-cup-sc-rink">Rink ' + escHtml(String(rk.rink)) + '</td>' +
          '<td class="lgw-cup-sc-players">' + escHtml((rk.home_players || []).join(', ')) + '</td>' +
          '<td class="lgw-cup-sc-score ' + (homeWin ? 'win' : '') + '">' + escHtml(String(hs)) + '</td>' +
          '<td class="lgw-cup-sc-vs">v</td>' +
          '<td class="lgw-cup-sc-score ' + (awayWin ? 'win' : '') + '">' + escHtml(String(as)) + '</td>' +
          '<td class="lgw-cup-sc-players">' + escHtml((rk.away_players || []).join(', ')) + '</td>' +
          '</tr>';
      });
      html += '</tbody>' +
        '<tfoot><tr>' +
          '<td colspan="2" class="lgw-cup-sc-total-lbl">Total</td>' +
          '<td class="lgw-cup-sc-score ' + (parseFloat(sc.home_total) > parseFloat(sc.away_total) ? 'win' : '') + '">' + escHtml(String(sc.home_total ?? '-')) + '</td>' +
          '<td></td>' +
          '<td class="lgw-cup-sc-score ' + (parseFloat(sc.away_total) > parseFloat(sc.home_total) ? 'win' : '') + '">' + escHtml(String(sc.away_total ?? '-')) + '</td>' +
          '<td class="lgw-cup-sc-total-lbl">Total</td>' +
        '</tr></tfoot>' +
        '</table></div>';
      body.innerHTML = html;
    });
  }

  // ── Hide draw/login buttons once draw is complete ─────────────────────────────
  function hideDrawButtons(wrap) {
    var actionsEl = qs('.lgw-cup-header-actions', wrap);
    if (actionsEl) actionsEl.parentNode.removeChild(actionsEl);
    // Add print button
    var headerEl = qs('.lgw-cup-header', wrap);
    if (headerEl && !qs('.lgw-cup-post-draw-actions', wrap)) {
      var printDiv = document.createElement('div');
      printDiv.className = 'lgw-cup-header-actions lgw-cup-post-draw-actions';
      printDiv.innerHTML = '<button class="lgw-cup-btn lgw-cup-btn-ghost lgw-cup-print-btn">\uD83D\uDDA8 Print Draw</button>';
      qs('.lgw-cup-print-btn', printDiv).addEventListener('click', function () {
        // Ensure all rounds visible (not hidden by mobile tabs) before printing
        var rounds = qsa('.lgw-cup-round', wrap);
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
    var emptyEl = qs('.lgw-cup-empty', wrap);
    if (emptyEl) emptyEl.style.display = 'none';
  }

  // ── Draw passphrase login modal ───────────────────────────────────────────────
  var drawToken        = null; // stored in memory for session duration
  var scoreToken       = null; // stored in memory for session duration
  var drawMasterActive = false; // true while draw master animation is running

  // ── Score entry login modal ───────────────────────────────────────────────────
  function openScoreLoginModal(wrap, onSuccess) {
    var existing = qs('.lgw-cup-draw-login-modal');
    if (existing) existing.parentNode.removeChild(existing);

    var nonce = (typeof lgwCupData !== 'undefined') ? lgwCupData.cupNonce : '';

    var modal = document.createElement('div');
    modal.className = 'lgw-cup-draw-login-modal';
    modal.innerHTML =
      '<div class="lgw-cup-draw-login-box">' +
        '<div class="lgw-cup-draw-login-title">🔑 Score Entry Login</div>' +
        '<input class="lgw-cup-draw-login-input" type="password" placeholder="Enter passphrase" ' +
               'autocomplete="off" autocapitalize="none" spellcheck="false">' +
        '<div class="lgw-cup-draw-login-actions">' +
          '<button class="lgw-cup-draw-login-submit">Unlock</button>' +
          '<button class="lgw-cup-draw-login-cancel">Cancel</button>' +
        '</div>' +
        '<div class="lgw-cup-draw-login-msg"></div>' +
      '</div>';
    document.body.appendChild(modal);

    var input     = qs('.lgw-cup-draw-login-input',  modal);
    var submitBtn = qs('.lgw-cup-draw-login-submit', modal);
    var msgEl     = qs('.lgw-cup-draw-login-msg',    modal);
    input.focus();

    function doAuth() {
      var passphrase = input.value.trim().toLowerCase();
      if (!passphrase) return;
      submitBtn.disabled = true;
      submitBtn.textContent = 'Checking…';
      post('lgw_cup_score_auth', { nonce: nonce, passphrase: passphrase }, function (res) {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Unlock';
        if (!res.success) {
          msgEl.textContent = res.data || 'Incorrect passphrase.';
          input.value = '';
          input.focus();
          return;
        }
        scoreToken = res.data.token;
        modal.parentNode.removeChild(modal);
        onSuccess();
      });
    }

    submitBtn.addEventListener('click', doAuth);
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter') doAuth(); });
    qs('.lgw-cup-draw-login-cancel', modal).addEventListener('click', function () {
      modal.parentNode.removeChild(modal);
    });
    modal.addEventListener('click', function (e) {
      if (e.target === modal) modal.parentNode.removeChild(modal);
    });
  }

  function openDrawLoginModal(wrap, onSuccess) {
    var existing = qs('.lgw-cup-draw-login-modal');
    if (existing) existing.parentNode.removeChild(existing);

    var nonce = (typeof lgwCupData !== 'undefined') ? lgwCupData.cupNonce : '';

    var modal = document.createElement('div');
    modal.className = 'lgw-cup-draw-login-modal';
    modal.innerHTML =
      '<div class="lgw-cup-draw-login-box">' +
        '<div class="lgw-cup-draw-login-title">🔑 Draw Authentication</div>' +
        '<input class="lgw-cup-draw-login-input" type="text" placeholder="Enter passphrase" ' +
               'autocomplete="off" autocapitalize="none" spellcheck="false">' +
        '<div class="lgw-cup-draw-login-actions">' +
          '<button class="lgw-cup-draw-login-submit">Unlock Draw</button>' +
          '<button class="lgw-cup-draw-login-cancel">Cancel</button>' +
        '</div>' +
        '<div class="lgw-cup-draw-login-msg"></div>' +
      '</div>';
    document.body.appendChild(modal);

    var input   = qs('.lgw-cup-draw-login-input',  modal);
    var submitBtn = qs('.lgw-cup-draw-login-submit', modal);
    var msgEl   = qs('.lgw-cup-draw-login-msg',    modal);
    input.focus();

    function doAuth() {
      var passphrase = input.value.trim().toLowerCase();
      if (!passphrase) return;
      submitBtn.disabled = true;
      submitBtn.textContent = 'Checking…';
      post('lgw_cup_draw_auth', { nonce: nonce, passphrase: passphrase }, function (res) {
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
    qs('.lgw-cup-draw-login-cancel', modal).addEventListener('click', function () {
      modal.parentNode.removeChild(modal);
    });
    // Close on backdrop click
    modal.addEventListener('click', function (e) {
      if (e.target === modal) modal.parentNode.removeChild(modal);
    });
  }

  // ── Admin/authenticated draw trigger ──────────────────────────────────────────
  function initAdminDraw(wrap) {
    var cupId   = wrap.dataset.cupId;
    var drawBtn = qs('.lgw-cup-admin-draw-btn', wrap);
    var loginBtn = qs('.lgw-cup-draw-login-btn', wrap);

    function performDraw() {
      if (!confirm('Perform the draw now? This will randomise the bracket and publish it live. This cannot be undone.')) return;
      var nonce = (drawBtn ? drawBtn.dataset.nonce : '') ||
                  (typeof lgwCupData !== 'undefined' ? lgwCupData.cupNonce : '');
      var activeBtn = drawBtn || loginBtn;
      if (activeBtn) { activeBtn.disabled = true; activeBtn.textContent = '⏳ Drawing…'; }

      post('lgw_cup_perform_draw', { cup_id: cupId, nonce: nonce, draw_token: drawToken || '' }, function (res) {
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
    var existing = qs('.lgw-cup-score-popover');
    if (existing) existing.parentNode.removeChild(existing);

    var cupId = wrap.dataset.cupId;
    var nonce = (typeof lgwCupData !== 'undefined') ? lgwCupData.scoreNonce : '';
    var homeName = match.home || 'TBD';
    var awayName = match.away || 'TBD';
    var hs = (match.home_score !== null && match.home_score !== undefined) ? match.home_score : '';
    var as = (match.away_score !== null && match.away_score !== undefined) ? match.away_score : '';

    var hasScore = (hs !== '' && as !== '');

    var pop = document.createElement('div');
    pop.className = 'lgw-cup-score-popover';
    pop.innerHTML =
      '<div class="lgw-cup-score-pop-title">Enter Score</div>' +
      '<div class="lgw-cup-score-pop-row">' +
        '<span class="lgw-cup-score-pop-name">' + escHtml(homeName) + '</span>' +
        '<input class="lgw-cup-score-pop-input" id="lgw-score-home" type="number" min="0" max="99" value="' + escHtml(String(hs)) + '" placeholder="–">' +
      '</div>' +
      '<div class="lgw-cup-score-pop-row">' +
        '<span class="lgw-cup-score-pop-name">' + escHtml(awayName) + '</span>' +
        '<input class="lgw-cup-score-pop-input" id="lgw-score-away" type="number" min="0" max="99" value="' + escHtml(String(as)) + '" placeholder="–">' +
      '</div>' +
      '<div class="lgw-cup-score-pop-actions">' +
        '<button class="lgw-cup-score-pop-save">Save</button>' +
        '<button class="lgw-cup-score-pop-cancel">Cancel</button>' +
        (hasScore ? '<button class="lgw-cup-score-pop-reset">Clear</button>' : '') +
        (match.home && match.away ? '<button class="lgw-cup-score-pop-sc">Full Scorecard</button>' : '') +
      '</div>' +
      '<div class="lgw-cup-score-pop-msg"></div>';

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
    var msgEl     = qs('.lgw-cup-score-pop-msg', pop);
    homeInput.focus();

    qs('.lgw-cup-score-pop-cancel', pop).addEventListener('click', function () {
      pop.parentNode.removeChild(pop);
    });

    var resetBtn = qs('.lgw-cup-score-pop-reset', pop);
    if (resetBtn) {
      resetBtn.addEventListener('click', function () {
        if (!confirm('Clear the score for this match? The next round slot will also be cleared.')) return;
        resetBtn.disabled = true;
        resetBtn.textContent = 'Clearing…';
        post('lgw_cup_save_score', {
          cup_id: cupId, nonce: nonce,
          round_idx: roundIdx, match_idx: matchIdx,
          home_score: '', away_score: '',
          score_token: scoreToken || '',
        }, function (res) {
          if (!res.success) {
            msgEl.textContent = 'Error: ' + (res.data || 'Unknown');
            resetBtn.disabled = false;
            resetBtn.textContent = 'Clear';
            return;
          }
          pop.parentNode.removeChild(pop);
          renderBracket(wrap, res.data.bracket);
        });
      });
    }

    qs('.lgw-cup-score-pop-save', pop).addEventListener('click', function () {
      var saveBtn = this;
      saveBtn.disabled = true;
      saveBtn.textContent = 'Saving…';
      post('lgw_cup_save_score', {
        cup_id: cupId, nonce: nonce,
        round_idx: roundIdx, match_idx: matchIdx,
        home_score: homeInput.value, away_score: awayInput.value,
        score_token: scoreToken || '',
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

    // Full Scorecard button — opens the shared scorecard/submission modal
    var scBtn = qs('.lgw-cup-score-pop-sc', pop);
    if (scBtn) {
      scBtn.addEventListener('click', function () {
        if (pop.parentNode) pop.parentNode.removeChild(pop);
        openScorecardViewer(card, match);
      });
    }

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
    var statusEl = qs('.lgw-cup-status', wrap);
    if (statusEl) statusEl.after(bar); else wrap.appendChild(bar);
  }

  // ── Status bar ────────────────────────────────────────────────────────────────
  function updateStatus(wrap, msg) {
    var statusEl = qs('.lgw-cup-status', wrap);
    if (!statusEl) return;
    var dot = qs('.lgw-cup-status-dot', statusEl);
    var txt = qs('.lgw-cup-status-text', statusEl);
    if (dot) dot.classList.remove('live');
    if (txt) txt.textContent = msg;
  }

  // ── Polling for live draw (visitors) ─────────────────────────────────────────
  function startDrawPoll(wrap, lastVersion) {
    var cupId = wrap.dataset.cupId;
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
        overlay.className = 'lgw-cup-draw-overlay';
        overlay.innerHTML = [
          '<div class="lgw-cup-draw-title">🏆 Cup Draw</div>',
          '<div class="lgw-cup-draw-subtitle">The draw is being made…</div>',
          '<div class="lgw-cup-draw-reveal" id="lgw-draw-reveal-v">',
            '<div class="lgw-cup-draw-slot-label" id="lgw-draw-slot-label-v">Match</div>',
            '<div class="lgw-cup-draw-team" id="lgw-draw-home-v"></div>',
            '<div class="lgw-cup-draw-vs">vs</div>',
            '<div class="lgw-cup-draw-team" id="lgw-draw-away-v"></div>',
          '</div>',
          '<div class="lgw-cup-draw-progress" id="lgw-draw-progress-v">Waiting for draw to begin…</div>',
          '<div class="lgw-cup-draw-eta" id="lgw-draw-eta-v"></div>',
          '<div class="lgw-cup-draw-pairs" id="lgw-draw-pairs-v"></div>',
        ].join('');
        document.body.appendChild(overlay);
      }

      var labelEl = qs('#lgw-draw-slot-label-v', overlay);
      var homeEl  = qs('#lgw-draw-home-v',       overlay);
      var awayEl  = qs('#lgw-draw-away-v',       overlay);
      var pairsEl = qs('#lgw-draw-pairs-v',      overlay);

      var speed  = (typeof lgwCupData !== 'undefined' && lgwCupData.drawSpeed)
                 ? parseFloat(lgwCupData.drawSpeed) : 1.0;
      var T_HOME = Math.round(700  * speed);
      var T_AWAY = Math.round(1200 * speed);
      var T_CHIP = Math.round(1800 * speed);
      var T_DONE = Math.round(2400 * speed);

      if (pair.type === 'header') {
        var divider = document.createElement('div');
        divider.className = 'lgw-cup-draw-round-header';
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
        chip.className = 'lgw-cup-draw-pair-chip' + (pair.bye ? ' lgw-cup-draw-bye-chip' : '');
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
        var subtitleEl = qs('.lgw-cup-draw-subtitle', overlay);
        var revealEl   = qs('#lgw-draw-reveal-v',     overlay);
        if (subtitleEl) subtitleEl.textContent = '\u2705 The draw is complete!';
        if (revealEl)   revealEl.style.display = 'none';
        // Remove any existing button first
        var existing = qs('.lgw-cup-draw-skip-btn', overlay);
        if (existing) existing.parentNode.removeChild(existing);
        var closeBtn = document.createElement('button');
        closeBtn.className = 'lgw-cup-draw-skip-btn';
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
        var spd = (typeof lgwCupData !== 'undefined' && lgwCupData.drawSpeed)
                ? parseFloat(lgwCupData.drawSpeed) : 1.0;
        var secs = Math.round(((tot - cur) * 2600 * spd) / 1000);
        eta.textContent = secs > 0 ? ('~' + secs + 's remaining') : '';
      } else if (eta) { eta.textContent = ''; }
    }

    function doPoll() {
      post('lgw_cup_poll', { cup_id: cupId, version: lastVersion, cursor: cursor }, function (res) {
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
  function initCupWidget(wrap) {
    var cupId = wrap.dataset.cupId;
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
      var emptyEl = qs('.lgw-cup-empty', wrap);
      if (emptyEl) emptyEl.style.display = '';
    }
    renderSponsorBar(wrap);
    var shouldPoll = drawVersion === '0' || (drawInProgress && !bracketData);
    if (shouldPoll) {
      startDrawPoll(wrap, drawVersion);
    }

    initAdminDraw(wrap);

    // Wire up server-rendered print button if present
    var printBtn = qs('.lgw-cup-print-btn', wrap);
    if (printBtn) {
      printBtn.addEventListener('click', function () {
        var rounds = qsa('.lgw-cup-round', wrap);
        rounds.forEach(function (r) { r.style.display = 'flex'; });
        window.print();
        setTimeout(function () {
          rounds.forEach(function (r) { r.style.display = ''; });
        }, 1000);
      });
    }
  }

  // Boot all cup widgets on page
  document.addEventListener('DOMContentLoaded', function () {
    qsa('.lgw-cup-wrap').forEach(initCupWidget);
  });

})();
