/* LGW Finals Week Widget JS - v7.1.26 */
(function () {
  'use strict';

  var ajaxUrl  = (typeof lgwFinalsData !== 'undefined') ? lgwFinalsData.ajaxUrl  : '/wp-admin/admin-ajax.php';
  var isAdmin  = (typeof lgwFinalsData !== 'undefined') && lgwFinalsData.isAdmin == 1;
  var nonce    = (typeof lgwFinalsData !== 'undefined') ? lgwFinalsData.nonce    : '';
  var matches  = (typeof lgwFinalsData !== 'undefined') ? (lgwFinalsData.matches || {}) : {};

  // ── Helpers ──────────────────────────────────────────────────────────────────
  function qs(sel, ctx)  { return (ctx || document).querySelector(sel); }
  function qsa(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
  function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

  function post(action, data, cb) {
    var fd = new FormData();
    fd.append('action', action);
    // Use payload nonce (for gchamp requests) or module-level nonce
    fd.append('nonce', data.nonce || nonce);
    Object.keys(data).forEach(function(k) { if (k !== 'nonce') fd.append(k, data[k]); });
    fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(cb)
      .catch(function(e) { console.error('LGW Finals:', e); });
  }

  function midParts(mid) {
    // mid = champId--bracketKey--roundIdx--matchIdx
    var parts = mid.split('--');
    return {
      champId:    parts[0],
      bracketKey: parts[1],
      roundIdx:   parts[2],
      matchIdx:   parts[3],
    };
  }

  // ── Ends table renderer (client-side mirror of PHP version) ──────────────────
  function renderEndsTable(mid, endsArr, home, away, collapsed) {
    if (!endsArr || !endsArr.length) {
      return '<div class="lgw-finals-ends-empty">'
           + (isAdmin ? '<button class="lgw-finals-add-end-btn" data-mid="' + esc(mid) + '">+ Start live scoring</button>' : '')
           + '</div>';
    }
    var ht = 0, at = 0;
    var rows = '';
    endsArr.forEach(function(e, i) {
      var he = parseInt(e[0], 10) || 0;
      var ae = parseInt(e[1], 10) || 0;
      ht += he; at += ae;
      rows += '<tr>'
            + '<td class="lgw-finals-ends-td lgw-finals-ends-td--end-score' + (he > ae ? ' win' : '') + '">' + he + '</td>'
            + '<td class="lgw-finals-ends-td lgw-finals-ends-td--running">' + ht + '</td>'
            + '<td class="lgw-finals-ends-td lgw-finals-ends-td--end">' + (i + 1) + '</td>'
            + '<td class="lgw-finals-ends-td lgw-finals-ends-td--running lgw-finals-ends-td--right">' + at + '</td>'
            + '<td class="lgw-finals-ends-td lgw-finals-ends-td--end-score lgw-finals-ends-td--right' + (ae > he ? ' win' : '') + '">' + ae + '</td>'
            + '</tr>';
    });
    var shortHome = shortName(home), shortAway = shortName(away);
    var isCollapsed = collapsed !== false; // default true
    var hdr = '<div class="lgw-finals-ends-hdr" data-ends-toggle="' + esc(mid) + '">'
            + '<span class="lgw-finals-ends-hdr-label">Ends (' + endsArr.length + ')</span>'
            + '<span class="lgw-finals-ends-hdr-toggle' + (isCollapsed ? ' collapsed' : '') + '">▼</span>'
            + '</div>';
    var table = '<table class="lgw-finals-ends-table">'
              + '<thead><tr>'
              + '<th class="lgw-finals-ends-th lgw-finals-ends-th--end-score">'  + esc(shortHome) + '</th>'
              + '<th class="lgw-finals-ends-th lgw-finals-ends-th--running">Tot</th>'
              + '<th class="lgw-finals-ends-th lgw-finals-ends-th--end">End</th>'
              + '<th class="lgw-finals-ends-th lgw-finals-ends-th--running lgw-finals-ends-th--right">Tot</th>'
              + '<th class="lgw-finals-ends-th lgw-finals-ends-th--end-score lgw-finals-ends-th--right">' + esc(shortAway) + '</th>'
              + '</tr></thead>'
              + '<tbody>' + rows + '</tbody>'
              + '<tfoot><tr>'
              + '<td class="lgw-finals-ends-td lgw-finals-ends-td--total' + (ht > at ? ' win' : '') + '">' + ht + '</td>'
              + '<td class="lgw-finals-ends-td lgw-finals-ends-td--end"></td>'
              + '<td class="lgw-finals-ends-td lgw-finals-ends-td--end">Total</td>'
              + '<td class="lgw-finals-ends-td lgw-finals-ends-td--end"></td>'
              + '<td class="lgw-finals-ends-td lgw-finals-ends-td--total lgw-finals-ends-td--right' + (at > ht ? ' win' : '') + '">' + at + '</td>'
              + '</tr></tfoot></table>';
    var actions = '';
    if (isAdmin) {
      actions = '<div class="lgw-finals-ends-actions">'
              + '<button class="lgw-finals-add-end-btn" data-mid="' + esc(mid) + '">+ Add end</button>'
              + '<button class="lgw-finals-del-end-btn" data-mid="' + esc(mid) + '">✕ Remove last end</button>'
              + '<button class="lgw-finals-complete-btn" data-mid="' + esc(mid) + '" data-home-total="' + ht + '" data-away-total="' + at + '">✓ Complete game</button>'
              + '</div>';
    }
    return hdr + '<div class="lgw-finals-ends-body' + (isCollapsed ? ' hidden' : '') + '">' + table + actions + '</div>';
  }

  function shortName(entry) {
    if (!entry) return '';
    var name = entry.split(',')[0].trim();
    return name.length > 22 ? name.slice(0, 20) + '…' : name;
  }

  // ── Update score block in DOM ────────────────────────────────────────────────
  function updateScoreBlock(mid, hs, as_score, ends) {
    var matchEl = qs('#lgw-fm-' + mid);
    if (!matchEl) return;
    var block = qs('.lgw-finals-score-block', matchEl);
    if (!block) return;

    var ht = 0, at = 0;
    (ends || []).forEach(function(e) { ht += parseInt(e[0],10)||0; at += parseInt(e[1],10)||0; });

    var editBtn = isAdmin ? '<button class="lgw-finals-edit-score" data-mid="' + esc(mid) + '" title="Enter score">✏️</button>' : '';

    if (hs !== null && as_score !== null) {
      block.innerHTML = '<span class="lgw-finals-score lgw-finals-score--home' + (hs > as_score ? ' lgw-finals-score--win' : '') + '">' + hs + '</span>'
                      + '<span class="lgw-finals-score-sep">–</span>'
                      + '<span class="lgw-finals-score lgw-finals-score--away' + (as_score > hs ? ' lgw-finals-score--win' : '') + '">' + as_score + '</span>'
                      + editBtn;
      matchEl.classList.remove('lgw-finals-match--upcoming', 'lgw-finals-match--live');
      matchEl.classList.add('lgw-finals-match--complete');
    } else if (ends && ends.length) {
      block.innerHTML = '<span class="lgw-finals-score lgw-finals-score--live">' + ht + '</span>'
                      + '<span class="lgw-finals-score-sep">–</span>'
                      + '<span class="lgw-finals-score lgw-finals-score--live">' + at + '</span>'
                      + '<span class="lgw-finals-live-badge">LIVE</span>'
                      + editBtn;
      matchEl.classList.remove('lgw-finals-match--upcoming', 'lgw-finals-match--complete');
      matchEl.classList.add('lgw-finals-match--live');
    } else {
      block.innerHTML = '<span class="lgw-finals-score-placeholder">v</span>' + editBtn;
      matchEl.classList.remove('lgw-finals-match--live', 'lgw-finals-match--complete');
      matchEl.classList.add('lgw-finals-match--upcoming');
    }
  }

  // ── Datetime edit popover ────────────────────────────────────────────────────
  function openDatetimeEditor(mid) {
    closePop();
    var p = midParts(mid);
    var m = matches[mid] || {};
    var current = m.datetime || '';
    var currentRink = m.rink || '';

    var pop = document.createElement('div');
    pop.className = 'lgw-finals-pop';
    pop.innerHTML =
      '<div class="lgw-finals-pop-title">Set date, time &amp; rink</div>'
    + '<div class="lgw-finals-pop-row">'
    + '<input class="lgw-finals-pop-input" type="datetime-local" id="lgw-finals-dt-input" value="' + esc(current.replace(' ', 'T')) + '">'
    + '</div>'
    + '<div class="lgw-finals-pop-row lgw-finals-pop-row--rink">'
    + '<label class="lgw-finals-pop-label" for="lgw-finals-rink-input">Rink</label>'
    + '<input class="lgw-finals-pop-input lgw-finals-pop-input--rink" type="text" id="lgw-finals-rink-input" maxlength="10" placeholder="e.g. 3" value="' + esc(currentRink) + '">'
    + '</div>'
    + '<div class="lgw-finals-pop-actions">'
    + '<button class="lgw-finals-pop-save">Save</button>'
    + '<button class="lgw-finals-pop-cancel">Cancel</button>'
    + (current || currentRink ? '<button class="lgw-finals-pop-clear">Clear</button>' : '')
    + '</div>'
    + '<div class="lgw-finals-pop-msg"></div>';

    positionPop(pop, mid);

    qs('#lgw-finals-dt-input', pop).focus();

    qs('.lgw-finals-pop-cancel', pop).addEventListener('click', closePop);

    var clearBtn = qs('.lgw-finals-pop-clear', pop);
    if (clearBtn) clearBtn.addEventListener('click', function() { saveDatetime(mid, '', '', pop); });

    qs('.lgw-finals-pop-save', pop).addEventListener('click', function() {
      var raw = qs('#lgw-finals-dt-input', pop).value; // "YYYY-MM-DDTHH:MM"
      var formatted = raw ? raw.replace('T', ' ') : '';
      var rink = qs('#lgw-finals-rink-input', pop).value.trim();
      saveDatetime(mid, formatted, rink, pop);
    });
  }

  function saveDatetime(mid, dt, rink, pop) {
    var p = midParts(mid);
    var msgEl = qs('.lgw-finals-pop-msg', pop);
    var m = matches[mid] || {};
    var action = m.isGchamp ? 'lgw_gchamp_finals_save_datetime' : 'lgw_finals_save_datetime';
    var payload = m.isGchamp
      ? { champ_id: m.champId, match_idx: m.matchIdx, nonce: m.nonce, datetime: dt, rink: rink }
      : { champ_id: p.champId, bracket_key: p.bracketKey, round_idx: p.roundIdx, match_idx: p.matchIdx, datetime: dt, rink: rink };
    post(action, payload, function(res) {
      if (!res.success) { msgEl.textContent = 'Error: ' + (res.data || 'Unknown'); return; }
      closePop();
      // Update datetime + rink display
      var matchEl = qs('#lgw-fm-' + mid);
      if (!matchEl) return;
      var dtEl = qs('.lgw-finals-datetime', matchEl);
      if (!dtEl) {
        dtEl = document.createElement('div');
        dtEl.className = 'lgw-finals-datetime';
        matchEl.insertBefore(dtEl, matchEl.firstChild);
      }
      if (dt || rink) {
        dtEl.className = 'lgw-finals-datetime';
        dtEl.innerHTML = (dt ? '<span class="lgw-finals-datetime-val">' + esc(res.data.formatted) + '</span>' : '')
                       + (rink ? '<span class="lgw-finals-rink-val">Rink ' + esc(rink) + '</span>' : '')
                       + '<button class="lgw-finals-edit-dt" data-mid="' + esc(mid) + '" title="Edit date/time &amp; rink">✏️</button>';
      } else {
        dtEl.className = 'lgw-finals-datetime lgw-finals-datetime--unset';
        dtEl.innerHTML = '<button class="lgw-finals-edit-dt" data-mid="' + esc(mid) + '">📅 Set date, time &amp; rink</button>';
      }
      bindMatchButtons(matchEl);
      if (matches[mid]) { matches[mid].datetime = dt; matches[mid].rink = rink; }
    });
  }

  // ── End entry popover ─────────────────────────────────────────────────────────
  function openEndEditor(mid) {
    closePop();
    var m = matches[mid] || {};

    var pop = document.createElement('div');
    pop.className = 'lgw-finals-pop';
    pop.innerHTML =
      '<div class="lgw-finals-pop-title">Add end</div>'
    + '<div class="lgw-finals-pop-row lgw-finals-pop-row--ends">'
    + '<div class="lgw-finals-pop-end-label">' + esc(shortName(m.home || 'Home')) + '</div>'
    + '<input class="lgw-finals-pop-input lgw-finals-pop-input--end" id="lgw-finals-he" type="number" min="0" max="30" placeholder="0">'
    + '<span class="lgw-finals-pop-vs">–</span>'
    + '<input class="lgw-finals-pop-input lgw-finals-pop-input--end" id="lgw-finals-ae" type="number" min="0" max="30" placeholder="0">'
    + '<div class="lgw-finals-pop-end-label lgw-finals-pop-end-label--right">' + esc(shortName(m.away || 'Away')) + '</div>'
    + '</div>'
    + '<div class="lgw-finals-pop-actions">'
    + '<button class="lgw-finals-pop-save">Add</button>'
    + '<button class="lgw-finals-pop-cancel">Cancel</button>'
    + '</div>'
    + '<div class="lgw-finals-pop-msg"></div>';

    positionPop(pop, mid);
    qs('#lgw-finals-he', pop).focus();

    qs('.lgw-finals-pop-cancel', pop).addEventListener('click', closePop);
    qs('.lgw-finals-pop-save', pop).addEventListener('click', function() {
      var he = qs('#lgw-finals-he', pop).value;
      var ae = qs('#lgw-finals-ae', pop).value;
      saveEnd(mid, 'add', he, ae, pop);
    });
    // Enter key submits
    pop.addEventListener('keydown', function(e) {
      if (e.key === 'Enter') { e.preventDefault(); qs('.lgw-finals-pop-save', pop).click(); }
    });
  }

  function saveEnd(mid, endAction, he, ae, pop) {
    var p = midParts(mid);
    var msgEl = pop ? qs('.lgw-finals-pop-msg', pop) : null;
    var m = matches[mid] || {};
    var ajaxAction = m.isGchamp ? 'lgw_gchamp_finals_save_end' : 'lgw_finals_save_end';
    var payload = m.isGchamp
      ? { champ_id: m.champId, match_idx: m.matchIdx, nonce: m.nonce, end_action: endAction, home_end: he||0, away_end: ae||0 }
      : { champ_id: p.champId, bracket_key: p.bracketKey, round_idx: p.roundIdx, match_idx: p.matchIdx, end_action: endAction, home_end: he||0, away_end: ae||0 };
    post(ajaxAction, payload, function(res) {
      if (!res.success) {
        if (msgEl) msgEl.textContent = 'Error: ' + (res.data || 'Unknown');
        return;
      }
      if (pop) closePop();
      var d = res.data;
      if (matches[mid]) { matches[mid].ends = d.ends; }
      var m = matches[mid] || {};
      var endsEl = qs('#lgw-ends-' + mid);
      if (endsEl) {
        endsEl.innerHTML = renderEndsTable(mid, d.ends, m.home, m.away);
        bindMatchButtons(endsEl.closest('.lgw-finals-match'));
      }
      updateScoreBlock(mid, m.homeScore, m.awayScore, d.ends);
    });
  }

  // ── Score edit popover ────────────────────────────────────────────────────────
  function openScoreEditor(mid) {
    closePop();
    var m = matches[mid] || {};
    var hs = m.homeScore !== null && m.homeScore !== undefined ? m.homeScore : '';
    var as = m.awayScore !== null && m.awayScore !== undefined ? m.awayScore : '';
    var hasScore = hs !== '' && as !== '';

    var pop = document.createElement('div');
    pop.className = 'lgw-finals-pop';
    pop.innerHTML =
      '<div class="lgw-finals-pop-title">Final Score</div>'
    + '<div class="lgw-finals-pop-row lgw-finals-pop-row--ends">'
    + '<div class="lgw-finals-pop-end-label">' + esc(shortName(m.home || 'Home')) + '</div>'
    + '<input class="lgw-finals-pop-input lgw-finals-pop-input--end" id="lgw-finals-hs" type="number" min="0" max="99" value="' + esc(String(hs)) + '" placeholder="–">'
    + '<span class="lgw-finals-pop-vs">–</span>'
    + '<input class="lgw-finals-pop-input lgw-finals-pop-input--end" id="lgw-finals-as" type="number" min="0" max="99" value="' + esc(String(as)) + '" placeholder="–">'
    + '<div class="lgw-finals-pop-end-label lgw-finals-pop-end-label--right">' + esc(shortName(m.away || 'Away')) + '</div>'
    + '</div>'
    + '<div class="lgw-finals-pop-actions">'
    + '<button class="lgw-finals-pop-save">Save</button>'
    + '<button class="lgw-finals-pop-cancel">Cancel</button>'
    + (hasScore ? '<button class="lgw-finals-pop-clear">Clear</button>' : '')
    + '</div>'
    + '<div class="lgw-finals-pop-msg"></div>';

    positionPop(pop, mid);
    qs('#lgw-finals-hs', pop).focus();

    qs('.lgw-finals-pop-cancel', pop).addEventListener('click', closePop);

    var clearBtn = qs('.lgw-finals-pop-clear', pop);
    if (clearBtn) clearBtn.addEventListener('click', function() {
      if (!confirm('Clear this score? The next round will also be cleared.')) return;
      saveScore(mid, '', '', pop);
    });

    qs('.lgw-finals-pop-save', pop).addEventListener('click', function() {
      saveScore(mid, qs('#lgw-finals-hs', pop).value, qs('#lgw-finals-as', pop).value, pop);
    });
  }

  function saveScore(mid, hs, as_score, pop) {
    var p = midParts(mid);
    var msgEl = pop ? qs('.lgw-finals-pop-msg', pop) : null;
    var m = matches[mid] || {};
    var ajaxAction = m.isGchamp ? 'lgw_gchamp_finals_save_score' : 'lgw_finals_save_score';
    var payload = m.isGchamp
      ? { champ_id: m.champId, match_idx: m.matchIdx, nonce: m.nonce, home_score: hs, away_score: as_score }
      : { champ_id: p.champId, bracket_key: p.bracketKey, round_idx: p.roundIdx, match_idx: p.matchIdx, home_score: hs, away_score: as_score };
    post(ajaxAction, payload, function(res) {
      if (!res.success) {
        if (msgEl) msgEl.textContent = 'Error: ' + (res.data || 'Unknown');
        return;
      }
      if (pop) closePop();
      var d = res.data;
      if (matches[mid]) { matches[mid].homeScore = d.homeScore; matches[mid].awayScore = d.awayScore; }
      var m = matches[mid] || {};
      updateScoreBlock(mid, d.homeScore, d.awayScore, m.ends || []);
    });
  }

  // ── Complete game popover — pre-filled from running totals ────────────────────
  function openCompleteGame(mid, homeTotal, awayTotal) {
    closePop();
    var m = matches[mid] || {};

    var pop = document.createElement('div');
    pop.className = 'lgw-finals-pop';
    pop.innerHTML =
      '<div class="lgw-finals-pop-title">Complete game</div>'
    + '<div class="lgw-finals-pop-subtitle">Confirm or adjust the final score</div>'
    + '<div class="lgw-finals-pop-row lgw-finals-pop-row--ends">'
    + '<div class="lgw-finals-pop-end-label">' + esc(shortName(m.home || 'Home')) + '</div>'
    + '<input class="lgw-finals-pop-input lgw-finals-pop-input--end" id="lgw-finals-chs" type="number" min="0" max="99" value="' + homeTotal + '">'
    + '<span class="lgw-finals-pop-vs">–</span>'
    + '<input class="lgw-finals-pop-input lgw-finals-pop-input--end" id="lgw-finals-cas" type="number" min="0" max="99" value="' + awayTotal + '">'
    + '<div class="lgw-finals-pop-end-label lgw-finals-pop-end-label--right">' + esc(shortName(m.away || 'Away')) + '</div>'
    + '</div>'
    + '<div class="lgw-finals-pop-actions">'
    + '<button class="lgw-finals-pop-save">✓ Confirm &amp; complete</button>'
    + '<button class="lgw-finals-pop-cancel">Cancel</button>'
    + '</div>'
    + '<div class="lgw-finals-pop-msg"></div>';

    positionPop(pop, mid);
    qs('#lgw-finals-chs', pop).focus();
    qs('#lgw-finals-chs', pop).select();

    qs('.lgw-finals-pop-cancel', pop).addEventListener('click', closePop);
    qs('.lgw-finals-pop-save', pop).addEventListener('click', function() {
      var hs = qs('#lgw-finals-chs', pop).value;
      var as = qs('#lgw-finals-cas', pop).value;
      if (hs === '' || as === '') {
        qs('.lgw-finals-pop-msg', pop).textContent = 'Please enter both scores.';
        return;
      }
      if (parseInt(hs, 10) === parseInt(as, 10)) {
        qs('.lgw-finals-pop-msg', pop).textContent = 'Scores cannot be equal — bowls cannot draw.';
        return;
      }
      saveScore(mid, hs, as, pop);
    });
  }

  // ── Popover positioning & close ───────────────────────────────────────────────
  function positionPop(pop, mid) {
    document.body.appendChild(pop);
    var trigger = qs('[data-mid="' + mid + '"]');
    if (trigger) {
      var rect = trigger.getBoundingClientRect();
      var top  = rect.bottom + window.scrollY + 6;
      var left = rect.left   + window.scrollX;
      if (left + 280 > window.innerWidth) left = window.innerWidth - 288;
      pop.style.top  = Math.max(8, top)  + 'px';
      pop.style.left = Math.max(8, left) + 'px';
    }
    // Close on outside click
    setTimeout(function() {
      document.addEventListener('click', outsideClickHandler);
    }, 50);
  }

  function outsideClickHandler(e) {
    var pop = qs('.lgw-finals-pop');
    if (pop && !pop.contains(e.target)) { closePop(); }
  }

  function closePop() {
    var pop = qs('.lgw-finals-pop');
    if (pop && pop.parentNode) pop.parentNode.removeChild(pop);
    document.removeEventListener('click', outsideClickHandler);
  }

  // ── Bind buttons on a match element ──────────────────────────────────────────
  function bindMatchButtons(matchEl) {
    if (!matchEl) return;
    qsa('.lgw-finals-edit-dt', matchEl).forEach(function(btn) {
      btn.addEventListener('click', function(e) { e.stopPropagation(); openDatetimeEditor(btn.dataset.mid); });
    });
    qsa('.lgw-finals-edit-score', matchEl).forEach(function(btn) {
      btn.addEventListener('click', function(e) { e.stopPropagation(); openScoreEditor(btn.dataset.mid); });
    });
    qsa('.lgw-finals-add-end-btn', matchEl).forEach(function(btn) {
      btn.addEventListener('click', function(e) { e.stopPropagation(); openEndEditor(btn.dataset.mid); });
    });
    qsa('.lgw-finals-del-end-btn', matchEl).forEach(function(btn) {
      btn.addEventListener('click', function(e) {
        e.stopPropagation();
        var mid = btn.dataset.mid;
        var m = matches[mid] || {};
        if (!m.ends || !m.ends.length) return;
        if (!confirm('Remove the last end?')) return;
        saveEnd(mid, 'delete_last', 0, 0, null);
      });
    });
    // Complete game — pre-fills score from running totals
    qsa('.lgw-finals-complete-btn', matchEl).forEach(function(btn) {
      btn.addEventListener('click', function(e) {
        e.stopPropagation();
        var mid = btn.dataset.mid;
        var ht  = parseInt(btn.dataset.homeTotal, 10) || 0;
        var at  = parseInt(btn.dataset.awayTotal, 10) || 0;
        openCompleteGame(mid, ht, at);
      });
    });
    // Ends toggle collapse/expand
    qsa('[data-ends-toggle]', matchEl).forEach(function(hdr) {
      hdr.addEventListener('click', function() {
        var mid   = hdr.dataset.endsToggle;
        var body  = hdr.nextElementSibling;
        var arrow = qs('.lgw-finals-ends-hdr-toggle', hdr);
        if (!body) return;
        var isHidden = body.classList.contains('hidden');
        body.classList.toggle('hidden', !isHidden);
        if (arrow) arrow.classList.toggle('collapsed', !isHidden);
      });
    });
  }

  // ── Live poll (non-admin: refresh data every 30s) ─────────────────────────────
  function startPoll(season) {
    if (isAdmin) return; // admin gets immediate updates via save responses
    setInterval(function() {
      fetch(ajaxUrl + '?action=lgw_finals_poll&season=' + encodeURIComponent(season), { credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(res) {
          if (!res.success) return;
          Object.keys(res.data).forEach(function(mid) {
            var d = res.data[mid];
            var local = matches[mid];
            if (!local) return;
            // Check if anything changed
            var changed = local.homeScore !== d.homeScore
                       || local.awayScore !== d.awayScore
                       || local.rink      !== d.rink
                       || JSON.stringify(local.ends) !== JSON.stringify(d.ends);
            if (!changed) return;
            local.homeScore = d.homeScore;
            local.awayScore = d.awayScore;
            local.ends      = d.ends;
            // Update rink display if changed
            if (local.rink !== d.rink) {
              local.rink = d.rink;
              var matchEl = qs('#lgw-fm-' + mid);
              if (matchEl) {
                var dtEl = qs('.lgw-finals-datetime', matchEl);
                if (dtEl) {
                  var dtVal = qs('.lgw-finals-datetime-val', dtEl);
                  var rkVal = qs('.lgw-finals-rink-val', dtEl);
                  if (rkVal) rkVal.parentNode.removeChild(rkVal);
                  if (d.rink) {
                    var newRk = document.createElement('span');
                    newRk.className = 'lgw-finals-rink-val';
                    newRk.textContent = 'Rink ' + d.rink;
                    if (dtVal && dtVal.nextSibling) dtEl.insertBefore(newRk, dtVal.nextSibling);
                    else dtEl.insertBefore(newRk, dtEl.firstChild);
                  }
                }
              }
            }
            // Update ends table
            var endsEl = qs('#lgw-ends-' + mid);
            if (endsEl) {
              // Preserve collapsed state
              var body = qs('.lgw-finals-ends-body', endsEl);
              var wasCollapsed = body && body.classList.contains('hidden');
              endsEl.innerHTML = renderEndsTable(mid, d.ends, local.home, local.away, wasCollapsed);
              bindMatchButtons(endsEl.closest('.lgw-finals-match'));
            }
            // If no ends table yet but now has ends, inject one
            if (d.ends && d.ends.length && !endsEl) {
              var matchEl = qs('#lgw-fm-' + mid);
              if (matchEl) {
                var newEndsEl = document.createElement('div');
                newEndsEl.className = 'lgw-finals-ends';
                newEndsEl.id = 'lgw-ends-' + mid;
                newEndsEl.innerHTML = renderEndsTable(mid, d.ends, local.home, local.away);
                matchEl.appendChild(newEndsEl);
                bindMatchButtons(matchEl);
              }
            }
            updateScoreBlock(mid, d.homeScore, d.awayScore, d.ends);
          });
        })
        .catch(function() {});
    }, 30000);
  }

  // ── Init ──────────────────────────────────────────────────────────────────────
  function init() {
    var wrap = qs('.lgw-finals-wrap');
    if (!wrap) return;

    // Bind all match buttons
    qsa('.lgw-finals-match', wrap).forEach(function(matchEl) {
      bindMatchButtons(matchEl);
    });

    // Start live poll for public viewers
    var season = wrap.dataset.season || '';
    if (season) startPoll(season);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
