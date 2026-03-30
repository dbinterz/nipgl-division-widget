/* LGW Scorecard JS - v5.17.10 */
(function(){
  'use strict';

  var ajaxUrl  = (typeof lgwSubmit !== 'undefined') ? lgwSubmit.ajaxUrl  : '/wp-admin/admin-ajax.php';
  var nonce    = (typeof lgwSubmit !== 'undefined') ? lgwSubmit.nonce    : '';
  var authClub = (typeof lgwSubmit !== 'undefined') ? lgwSubmit.authClub : '';

  // ── Utility ───────────────────────────────────────────────────────────────────
  function qs(sel, ctx)  { return (ctx||document).querySelector(sel); }
  function qsa(sel, ctx) { return (ctx||document).querySelectorAll(sel); }

  function showStatus(el, msg, type) {
    if (!el) return;
    el.textContent   = msg;
    el.className     = 'lgw-notice lgw-notice-' + (type||'info');
    el.style.display = msg ? '' : 'none';
  }

  function setLoading(btn, lbl, loading) {
    btn.disabled    = loading;
    btn.textContent = loading ? '⏳ ' + lbl + '…' : lbl;
  }

  function post(action, data, cb) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('nonce',  nonce);
    for (var k in data) fd.append(k, data[k]);
    var xhr = new XMLHttpRequest();
    xhr.open('POST', ajaxUrl);
    xhr.onload  = function(){ cb(JSON.parse(xhr.responseText || '{}')); };
    xhr.onerror = function(){ cb({success:false, data:'Network error — please check your connection and try again.'}); };
    xhr.send(fd);
  }

  // ── Club login ────────────────────────────────────────────────────────────────
  var pinSubmit = qs('#lgw-pin-submit');
  var pinInput  = qs('#lgw-pin-input');
  var clubSel   = qs('#lgw-club-select');

  function attemptLogin() {
    var club = clubSel ? clubSel.value : '';
    var pin  = pinInput ? pinInput.value.trim() : '';
    if (!club) { showStatus(qs('#lgw-pin-error'), 'Please select your club', 'error'); return; }
    if (!pin)  { showStatus(qs('#lgw-pin-error'), 'Please enter your PIN',   'error'); return; }
    if (pinSubmit) setLoading(pinSubmit, 'Login', true);

    post('lgw_check_pin', {club: club, pin: pin}, function(res){
      if (pinSubmit) setLoading(pinSubmit, 'Login', false);
      if (res.success) {
        authClub = res.data.club;
        qs('#lgw-pin-gate').style.display   = 'none';
        qs('#lgw-submit-form').style.display = '';
        var nameEl = qs('#lgw-club-name');
        if (nameEl) nameEl.textContent = authClub;
        showPending(res.data.pending || []);
      } else {
        showStatus(qs('#lgw-pin-error'), res.data || 'Incorrect club or PIN', 'error');
      }
    });
  }

  if (pinSubmit) pinSubmit.addEventListener('click', attemptLogin);
  if (pinInput)  pinInput.addEventListener('keydown', function(e){ if(e.key==='Enter') attemptLogin(); });

  // ── Logout ────────────────────────────────────────────────────────────────────
  var logoutBtn = qs('#lgw-logout');
  if (logoutBtn) {
    logoutBtn.addEventListener('click', function(){
      post('lgw_logout', {}, function(){
        authClub = '';
        qs('#lgw-pin-gate').style.display    = '';
        qs('#lgw-submit-form').style.display = 'none';
        if (clubSel)   clubSel.value   = '';
        if (pinInput)  pinInput.value  = '';
        qs('#lgw-pending-wrap').style.display = 'none';
      });
    });
  }

  // ── Pending confirmations ─────────────────────────────────────────────────────
  function showPending(pending) {
    var wrap = qs('#lgw-pending-wrap');
    var list = qs('#lgw-pending-list');
    if (!wrap || !list) return;
    if (!pending || !pending.length) { wrap.style.display = 'none'; return; }
    wrap.style.display = '';
    list.innerHTML = '';
    pending.forEach(function(p){
      var div = document.createElement('div');
      div.className = 'lgw-pending-item';
      div.innerHTML =
        '<div class="lgw-pending-match">'
        + '<strong>' + esc(p.home_team) + '</strong> v <strong>' + esc(p.away_team) + '</strong>'
        + '<span class="lgw-pending-date">' + esc(p.date) + '</span>'
        + '</div>'
        + '<div class="lgw-pending-by">Submitted by: ' + esc(p.submitted_by) + '</div>'
        + '<div class="lgw-pending-actions">'
        + '<button class="lgw-btn lgw-btn-sm lgw-btn-view" data-id="'+p.id+'">View Scorecard</button>'
        + '</div>'
        + '<div class="lgw-pending-detail" id="lgw-detail-'+p.id+'" style="display:none"></div>';
      list.appendChild(div);
    });

    // Bind view buttons
    list.querySelectorAll('.lgw-btn-view').forEach(function(btn){
      btn.addEventListener('click', function(){
        var id     = btn.getAttribute('data-id');
        var detail = qs('#lgw-detail-' + id);
        if (!detail) return;
        if (detail.style.display !== 'none') { detail.style.display = 'none'; btn.textContent = 'View Scorecard'; return; }
        btn.textContent = '⏳ Loading…';
        var xhr = new XMLHttpRequest();
        xhr.open('GET', ajaxUrl + '?action=lgw_get_scorecard_by_id&id=' + id + '&_=' + Date.now());
        xhr.onload = function(){
          var res = JSON.parse(xhr.responseText || '{}');
          btn.textContent = 'Hide Scorecard';
          if (res.success) {
            detail.style.display = '';
            detail.innerHTML = renderScorecardReview(res.data, id);
            bindReviewActions(detail, id, res.data);
          } else {
            detail.style.display = '';
            detail.innerHTML = '<p class="lgw-notice lgw-notice-error">Could not load scorecard.</p>';
          }
        };
        xhr.send();
      });
    });
  }

  // ── Scorecard review (confirm / amend) ────────────────────────────────────────
  function renderScorecardReview(sc, id) {
    var h = '<div class="lgw-sc-review">';
    h += lgwRenderScorecardHtml(sc);
    h += '<div class="lgw-review-actions">';
    h += '<button class="lgw-btn lgw-btn-primary" data-action="confirm" data-id="'+id+'">✅ Confirm — scores are correct</button>';
    h += '<button class="lgw-btn lgw-btn-secondary" data-action="amend"  data-id="'+id+'">✏️ Amend — I have different scores</button>';
    h += '</div>';
    h += '<p class="lgw-review-status" style="display:none"></p>';
    h += '</div>';
    return h;
  }

  function bindReviewActions(container, id, sc) {
    var confirmBtn = container.querySelector('[data-action="confirm"]');
    var amendBtn   = container.querySelector('[data-action="amend"]');
    var statusEl   = container.querySelector('.lgw-review-status');

    if (confirmBtn) {
      confirmBtn.addEventListener('click', function(){
        setLoading(confirmBtn, '✅ Confirm — scores are correct', true);
        post('lgw_confirm_scorecard', {id: id}, function(res){
          setLoading(confirmBtn, '✅ Confirm — scores are correct', false);
          if (res.success) {
            showStatus(statusEl, res.data.message, 'ok');
            confirmBtn.style.display = 'none';
            amendBtn.style.display   = 'none';
            // Remove from pending list
            var item = container.closest('.lgw-pending-item');
            if (item) setTimeout(function(){ item.style.opacity='0.5'; }, 300);
          } else {
            showStatus(statusEl, res.data || 'Error', 'error');
          }
        });
      });
    }

    if (amendBtn) {
      amendBtn.addEventListener('click', function(){
        // Pre-fill the main submission form with the existing scorecard data
        // so they can make corrections and resubmit
        populateForm(sc);
        confirmBtn.style.display = 'none';
        amendBtn.style.display   = 'none';
        showStatus(statusEl,
          'The scorecard has been loaded into the form below. Make your corrections and click Save Scorecard.',
          'info');
        var form = qs('#lgw-scorecard-form');
        if (form) form.scrollIntoView({behavior:'smooth', block:'start'});
      });
    }
  }

  // ── Submission method tabs ────────────────────────────────────────────────────
  qsa('.lgw-stab').forEach(function(btn){
    btn.addEventListener('click', function(){
      qsa('.lgw-stab').forEach(function(b){ b.classList.remove('active'); });
      qsa('.lgw-stab-panel').forEach(function(p){ p.classList.remove('active'); });
      btn.classList.add('active');
      var panel = qs('.lgw-stab-panel[data-panel="'+btn.getAttribute('data-tab')+'"]');
      if (panel) panel.classList.add('active');
    });
  });

  // ── Photo upload ──────────────────────────────────────────────────────────────
  var photoInput    = qs('#lgw-photo-input');
  var photoTrigger  = qs('#lgw-photo-trigger');
  var photoDrop     = qs('#lgw-photo-drop');
  var photoPreview  = qs('#lgw-photo-preview');
  var parsePhotoBtn = qs('#lgw-parse-photo');
  var photoStatus   = qs('#lgw-parse-photo-status');
  var photoFile     = null;

  if (photoTrigger) photoTrigger.addEventListener('click', function(){ if(photoInput) photoInput.click(); });
  if (photoDrop) {
    photoDrop.addEventListener('dragover',  function(e){ e.preventDefault(); photoDrop.classList.add('drag-over'); });
    photoDrop.addEventListener('dragleave', function(){  photoDrop.classList.remove('drag-over'); });
    photoDrop.addEventListener('drop', function(e){
      e.preventDefault(); photoDrop.classList.remove('drag-over');
      var f = e.dataTransfer.files[0];
      if (f && f.type.startsWith('image/')) handlePhotoFile(f);
    });
  }
  if (photoInput) photoInput.addEventListener('change', function(){ if(photoInput.files[0]) handlePhotoFile(photoInput.files[0]); });

  function handlePhotoFile(file) {
    photoFile = file;
    var reader = new FileReader();
    reader.onload = function(e){
      photoPreview.src = e.target.result;
      photoPreview.style.display = '';
      if (parsePhotoBtn) parsePhotoBtn.style.display = '';
    };
    reader.readAsDataURL(file);
  }

  if (parsePhotoBtn) {
    parsePhotoBtn.addEventListener('click', function(){
      if (!photoFile) return;
      var lbl = 'Read Scorecard with AI';
      setLoading(parsePhotoBtn, lbl, true);
      showStatus(photoStatus, 'Sending to AI — this takes a few seconds…', 'info');
      var fd = new FormData();
      fd.append('action', 'lgw_parse_photo');
      fd.append('nonce',  nonce);
      fd.append('photo',  photoFile);
      var xhr = new XMLHttpRequest();
      xhr.open('POST', ajaxUrl);
      xhr.onload = function(){
        setLoading(parsePhotoBtn, lbl, false);
        var res = JSON.parse(xhr.responseText || '{}');
        if (res.success) { populateForm(res.data); showStatus(photoStatus, '✅ Scorecard read — please check all fields below.', 'ok'); }
        else showStatus(photoStatus, '❌ ' + (res.data || 'Could not read scorecard'), 'error');
      };
      xhr.onerror = function(){ setLoading(parsePhotoBtn, lbl, false); showStatus(photoStatus, '❌ Network error', 'error'); };
      xhr.send(fd);
    });
  }

  // ── Excel upload ──────────────────────────────────────────────────────────────
  var excelInput    = qs('#lgw-excel-input');
  var parseExcelBtn = qs('#lgw-parse-excel');
  var excelStatus   = qs('#lgw-parse-excel-status');

  if (excelInput) excelInput.addEventListener('change', function(){ if(excelInput.files[0] && parseExcelBtn) parseExcelBtn.style.display = ''; });
  if (parseExcelBtn) {
    parseExcelBtn.addEventListener('click', function(){
      if (!excelInput || !excelInput.files[0]) return;
      var lbl = 'Read Spreadsheet';
      setLoading(parseExcelBtn, lbl, true);
      showStatus(excelStatus, 'Reading spreadsheet…', 'info');
      var fd = new FormData();
      fd.append('action', 'lgw_parse_excel');
      fd.append('nonce',  nonce);
      fd.append('excel',  excelInput.files[0]);
      var xhr = new XMLHttpRequest();
      xhr.open('POST', ajaxUrl);
      xhr.onload = function(){
        setLoading(parseExcelBtn, lbl, false);
        var res = JSON.parse(xhr.responseText || '{}');
        if (res.success) { populateForm(res.data); showStatus(excelStatus, '✅ Spreadsheet read — please check all fields below.', 'ok'); }
        else showStatus(excelStatus, '❌ ' + (res.data || 'Could not read file'), 'error');
      };
      xhr.onerror = function(){ setLoading(parseExcelBtn, lbl, false); showStatus(excelStatus, '❌ Network error', 'error'); };
      xhr.send(fd);
    });
  }

  // ── Populate form ─────────────────────────────────────────────────────────────
  function setVal(id, val) { var el = qs('#'+id); if(el) el.value = (val !== null && val !== undefined) ? val : ''; }

  function populateForm(sc) {
    setVal('sc-division',    sc.division   || '');
    setVal('sc-venue',       sc.venue      || '');
    var normDate = normaliseDate(sc.date || '');
    setVal('sc-date',        normDate || sc.date || '');
    setVal('sc-home-team',   sc.home_team  || '');
    setVal('sc-away-team',   sc.away_team  || '');
    setVal('sc-home-total',  sc.home_total  !== null ? sc.home_total  : '');
    setVal('sc-away-total',  sc.away_total  !== null ? sc.away_total  : '');
    setVal('sc-home-points', sc.home_points !== null ? sc.home_points : '');
    setVal('sc-away-points', sc.away_points !== null ? sc.away_points : '');
    (sc.rinks || []).forEach(function(rk){
      var r = rk.rink;
      (rk.home_players || []).forEach(function(name,i){
        var el = qs('[data-rink="'+r+'"][data-side="home"][data-player="'+(i+1)+'"]');
        if(el) el.value = name || '';
      });
      (rk.away_players || []).forEach(function(name,i){
        var el = qs('[data-rink="'+r+'"][data-side="away"][data-player="'+(i+1)+'"]');
        if(el) el.value = name || '';
      });
      var hs = qs('.lgw-score-home[data-rink="'+r+'"]');
      var as = qs('.lgw-score-away[data-rink="'+r+'"]');
      if(hs) hs.value = rk.home_score !== null ? rk.home_score : '';
      if(as) as.value = rk.away_score !== null ? rk.away_score : '';
    });
    var form = qs('#lgw-scorecard-form');
    if(form) form.scrollIntoView({behavior:'smooth', block:'start'});
    // Validate team names against division if we have one
    var div = (qs('#sc-division') || {}).value || '';
    if (div) {
      fetchDivisionTeams(div, function(teams) {
        if (teams) validateBothTeams();
      });
    }
  }

  // ── Team name validation against division ─────────────────────────────────────
  var divisionTeams    = null;
  var divisionFixtures = null;   // array of {home, away}
  var lastFetchedDiv   = '';

  function fetchDivisionTeams(division, cb) {
    if (!division) { divisionTeams = null; divisionFixtures = null; lastFetchedDiv = ''; cb(null); return; }
    if (division === lastFetchedDiv && divisionTeams !== null) { cb(divisionTeams); return; }
    var fd = new FormData();
    fd.append('action',   'lgw_get_division_teams');
    fd.append('nonce',    nonce);
    fd.append('division', division);
    var xhr = new XMLHttpRequest();
    xhr.open('POST', ajaxUrl);
    xhr.onload = function() {
      var res = JSON.parse(xhr.responseText || '{}');
      if (res.success && res.data.teams && res.data.teams.length) {
        divisionTeams    = res.data.teams;
        divisionFixtures = res.data.fixtures || [];
        lastFetchedDiv   = division;
        cb(divisionTeams);
      } else {
        divisionTeams    = null;
        divisionFixtures = null;
        lastFetchedDiv   = division;
        cb(null);
      }
    };
    xhr.onerror = function() { cb(null); };
    xhr.send(fd);
  }

  /**
   * Find the best matching fixture for a home+away pair.
   * Does exact match first, then prefix match (e.g. "Belmont" matches "Belmont A").
   *
   * Returns an object:
   *   { status: 'found' | 'found_corrected' | 'reversed' | 'reversed_corrected' | 'not_found',
   *     canonical: { home, away } }  — canonical names from the fixture list
   */
  function checkFixture(home, away) {
    if (!divisionFixtures || !home || !away) return { status: 'unknown', canonical: null };
    var h = home.trim().toUpperCase();
    var a = away.trim().toUpperCase();

    // Helper: does a typed value match a fixture team name (exact or prefix)?
    function teamMatches(typed, fixture) {
      var f = fixture.trim().toUpperCase();
      if (f === typed) return true;
      // fixture starts with typed and next char is a space (e.g. "BELMONT A" starts with "BELMONT ")
      return f.indexOf(typed) === 0 && (f.length === typed.length || f[typed.length] === ' ');
    }

    for (var i = 0; i < divisionFixtures.length; i++) {
      var fx = divisionFixtures[i];
      var fh = fx.home.trim().toUpperCase();
      var fa = fx.away.trim().toUpperCase();

      if (fh === h && fa === a) {
        return { status: 'found', canonical: { home: fx.home, away: fx.away } };
      }
    }
    // Exact reversed
    for (var i = 0; i < divisionFixtures.length; i++) {
      var fx = divisionFixtures[i];
      var fh = fx.home.trim().toUpperCase();
      var fa = fx.away.trim().toUpperCase();
      if (fh === a && fa === h) {
        return { status: 'reversed', canonical: { home: fx.home, away: fx.away } };
      }
    }
    // Prefix match (e.g. "Belmont" → "Belmont A")
    for (var i = 0; i < divisionFixtures.length; i++) {
      var fx = divisionFixtures[i];
      var fh = fx.home.trim().toUpperCase();
      var fa = fx.away.trim().toUpperCase();
      if (teamMatches(h, fx.home) && teamMatches(a, fx.away)) {
        var corrected = (fh !== h || fa !== a);
        return { status: corrected ? 'found_corrected' : 'found', canonical: { home: fx.home, away: fx.away } };
      }
    }
    // Prefix match reversed
    for (var i = 0; i < divisionFixtures.length; i++) {
      var fx = divisionFixtures[i];
      if (teamMatches(a, fx.home) && teamMatches(h, fx.away)) {
        return { status: 'reversed_corrected', canonical: { home: fx.home, away: fx.away } };
      }
    }
    return { status: 'not_found', canonical: null };
  }

  /**
   * Match a typed value against the known team list.
   * Returns: { exact: bool, matches: [teamName, ...] }
   * - exact: true if value matches one team name exactly (case-insensitive)
   * - matches: all team names that start with the typed value (club-prefix match)
   */
  function matchTeams(value, teams) {
    if (!teams || !value) return { exact: false, matches: [] };
    var v = value.trim().toUpperCase();
    var exact = false;
    var matches = [];
    teams.forEach(function(t) {
      var tu = t.trim().toUpperCase();
      if (tu === v) { exact = true; matches = [t]; return; }
    });
    if (exact) return { exact: true, matches: matches };
    // Partial / club-prefix matches
    teams.forEach(function(t) {
      var tu = t.trim().toUpperCase();
      if (tu.indexOf(v) === 0 && (tu.length === v.length || tu[v.length] === ' ')) {
        matches.push(t);
      }
    });
    return { exact: false, matches: matches };
  }

  /**
   * Show team validation hint below an input.
   * fieldId: 'sc-home-team' or 'sc-away-team'
   * otherFieldId: the opposite field (used to exclude already-selected team when only 1 team per club)
   */
  function validateTeamField(fieldId, otherFieldId) {
    var input     = qs('#' + fieldId);
    var hintId    = fieldId + '-hint';
    var existing  = qs('#' + hintId);
    if (existing) existing.parentNode.removeChild(existing);
    if (!input || !divisionTeams) return;

    var value = input.value.trim();
    if (!value) return;

    var result = matchTeams(value, divisionTeams);

    if (result.exact) {
      // Exact match — show a quiet confirmation tick and clear any border
      input.style.borderColor = '';
      var hint = document.createElement('div');
      hint.id = hintId;
      hint.style.cssText = 'font-size:11px;color:#2a7a2a;margin-top:2px';
      hint.textContent = '✓ Matched in division';
      input.parentNode.insertBefore(hint, input.nextSibling);
      return;
    }

    // Exclude the team already chosen in the other field
    var otherVal = (qs('#' + otherFieldId) || {}).value || '';
    var filtered = result.matches.filter(function(t) {
      return t.trim().toUpperCase() !== otherVal.trim().toUpperCase();
    });

    if (filtered.length === 0 && result.matches.length === 0) {
      // No match at all
      input.style.borderColor = '#dc3545';
      var hint = document.createElement('div');
      hint.id = hintId;
      hint.style.cssText = 'font-size:11px;color:#c0202a;margin-top:2px';
      hint.textContent = '⚠ "' + value + '" not found in this division — check the team name.';
      input.parentNode.insertBefore(hint, input.nextSibling);
      return;
    }

    var candidates = filtered.length > 0 ? filtered : result.matches;

    if (candidates.length === 1) {
      // One candidate — prompt to confirm or auto-fill if it's clearly a club-only entry
      input.style.borderColor = '#856404';
      var hint = document.createElement('div');
      hint.id = hintId;
      hint.style.cssText = 'font-size:12px;color:#7a5a00;background:#fff8e0;border:1px solid #e8d080;border-radius:4px;padding:5px 8px;margin-top:3px;display:flex;align-items:center;gap:8px;flex-wrap:wrap';
      hint.innerHTML = 'Did you mean <strong>' + escHtml(candidates[0]) + '</strong>?'
        + ' <button type="button" style="font-size:11px;padding:1px 8px;cursor:pointer" class="button button-small lgw-team-confirm" data-field="' + fieldId + '" data-team="' + escHtml(candidates[0]) + '">Use this</button>'
        + ' <span style="font-size:11px;color:#999">or edit the field above</span>';
      input.parentNode.insertBefore(hint, input.nextSibling);
    } else {
      // Multiple candidates (e.g. club has two teams in division) — show a select
      input.style.borderColor = '#856404';
      var hint = document.createElement('div');
      hint.id = hintId;
      hint.style.cssText = 'font-size:12px;color:#7a5a00;background:#fff8e0;border:1px solid #e8d080;border-radius:4px;padding:5px 8px;margin-top:3px';
      var opts = candidates.map(function(t) {
        return '<option value="' + escHtml(t) + '">' + escHtml(t) + '</option>';
      }).join('');
      hint.innerHTML = 'Multiple teams found — please select:'
        + ' <select class="lgw-team-select" data-field="' + fieldId + '" style="font-size:12px;margin-left:4px">'
        + '<option value="">— choose —</option>' + opts + '</select>';
      input.parentNode.insertBefore(hint, input.nextSibling);
    }
  }

  function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  // Delegate clicks on "Use this" buttons and changes on team selects
  document.addEventListener('click', function(e) {
    if (e.target && e.target.classList.contains('lgw-team-confirm')) {
      var fieldId = e.target.getAttribute('data-field');
      var team    = e.target.getAttribute('data-team');
      var input   = qs('#' + fieldId);
      if (input) { input.value = team; }
      validateTeamField(fieldId, fieldId === 'sc-home-team' ? 'sc-away-team' : 'sc-home-team');
      validateFixturePairing();
    }
  });
  document.addEventListener('change', function(e) {
    if (e.target && e.target.classList.contains('lgw-team-select')) {
      var fieldId = e.target.getAttribute('data-field');
      var team    = e.target.value;
      if (!team) return;
      var input   = qs('#' + fieldId);
      if (input) { input.value = team; }
      validateTeamField(fieldId, fieldId === 'sc-home-team' ? 'sc-away-team' : 'sc-home-team');
      validateFixturePairing();
    }
  });

  function validateBothTeams() {
    validateTeamField('sc-home-team', 'sc-away-team');
    validateTeamField('sc-away-team', 'sc-home-team');
    validateFixturePairing();
  }

  function validateFixturePairing() {
    var pairingHintId = 'sc-fixture-pairing-hint';
    var existing = qs('#' + pairingHintId);
    if (existing) existing.parentNode.removeChild(existing);

    if (!divisionFixtures || !divisionFixtures.length) return;

    var homeVal = (qs('#sc-home-team') || {}).value || '';
    var awayVal = (qs('#sc-away-team') || {}).value || '';
    if (!homeVal || !awayVal) return;

    // Skip if either field still has an unresolved multi-candidate prompt
    var homeHint = qs('#sc-home-team-hint');
    var awayHint = qs('#sc-away-team-hint');
    if ((homeHint && homeHint.querySelector('.lgw-team-select')) ||
        (awayHint && awayHint.querySelector('.lgw-team-select'))) return;

    var match = checkFixture(homeVal, awayVal);

    if (match.status === 'found') return; // exact match, all good

    var hint = document.createElement('div');
    hint.id = pairingHintId;

    if (match.status === 'found_corrected') {
      // Right pairing, wrong suffix — offer to correct both names
      hint.style.cssText = 'font-size:12px;color:#7a5a00;background:#fff8e0;border:1px solid #e8d080;border-radius:4px;padding:6px 10px;margin-top:6px;display:flex;align-items:center;gap:8px;flex-wrap:wrap';
      hint.innerHTML = '⚠ Did you mean <strong>' + escHtml(match.canonical.home) + '</strong> v <strong>' + escHtml(match.canonical.away) + '</strong>?'
        + ' <button type="button" class="button button-small lgw-apply-fixture"'
        + '  data-home="' + escHtml(match.canonical.home) + '" data-away="' + escHtml(match.canonical.away) + '">'
        + 'Correct both names</button>';

    } else if (match.status === 'reversed') {
      // Home and away swapped, names exact
      hint.style.cssText = 'font-size:12px;color:#7a5a00;background:#fff8e0;border:1px solid #e8d080;border-radius:4px;padding:6px 10px;margin-top:6px;display:flex;align-items:center;gap:8px;flex-wrap:wrap';
      hint.innerHTML = '⚠ This fixture is listed as <strong>' + escHtml(match.canonical.home) + '</strong> (home) v <strong>' + escHtml(match.canonical.away) + '</strong> (away) in the schedule.'
        + ' <button type="button" class="button button-small lgw-apply-fixture"'
        + '  data-home="' + escHtml(match.canonical.home) + '" data-away="' + escHtml(match.canonical.away) + '">'
        + 'Swap home/away</button>';

    } else if (match.status === 'reversed_corrected') {
      // Both swapped AND names need correcting
      hint.style.cssText = 'font-size:12px;color:#7a5a00;background:#fff8e0;border:1px solid #e8d080;border-radius:4px;padding:6px 10px;margin-top:6px;display:flex;align-items:center;gap:8px;flex-wrap:wrap';
      hint.innerHTML = '⚠ Did you mean <strong>' + escHtml(match.canonical.home) + '</strong> (home) v <strong>' + escHtml(match.canonical.away) + '</strong> (away)?'
        + ' <button type="button" class="button button-small lgw-apply-fixture"'
        + '  data-home="' + escHtml(match.canonical.home) + '" data-away="' + escHtml(match.canonical.away) + '">'
        + 'Correct &amp; swap</button>';

    } else {
      // not_found
      hint.style.cssText = 'font-size:12px;color:#c0202a;background:#fff0f0;border:1px solid #f5b2b2;border-radius:4px;padding:6px 10px;margin-top:6px';
      hint.textContent = '✗ No fixture found for ' + homeVal + ' v ' + awayVal + ' in this division — please check the team names.';
    }

    var awayFormRow = (qs('#sc-away-team') && qs('#sc-away-team').closest('.lgw-form-row-2')) || (qs('#sc-away-team') && qs('#sc-away-team').parentNode);
    if (awayFormRow) awayFormRow.parentNode.insertBefore(hint, awayFormRow.nextSibling);
  }

  document.addEventListener('click', function(e) {
    if (e.target && e.target.classList.contains('lgw-apply-fixture')) {
      var homeInput = qs('#sc-home-team');
      var awayInput = qs('#sc-away-team');
      if (homeInput) homeInput.value = e.target.getAttribute('data-home');
      if (awayInput) awayInput.value = e.target.getAttribute('data-away');
      validateBothTeams();
    }
  });

  // Wire up blur events on team fields
  var homeTeamInput = qs('#sc-home-team');
  var awayTeamInput = qs('#sc-away-team');
  if (homeTeamInput) homeTeamInput.addEventListener('blur', function() {
    validateTeamField('sc-home-team', 'sc-away-team');
    validateFixturePairing();
  });
  if (awayTeamInput) awayTeamInput.addEventListener('blur', function() {
    validateTeamField('sc-away-team', 'sc-home-team');
    validateFixturePairing();
  });

  // Wire up division field — fetch teams when division changes
  var divisionInput = qs('#sc-division');
  if (divisionInput) {
    divisionInput.addEventListener('blur', function() {
      var div = divisionInput.value.trim();
      if (!div) return;
      fetchDivisionTeams(div, function(teams) {
        if (teams) validateBothTeams();
      });
    });
  }

  // Wire up cup match selector — pre-fill home/away and skip division team fetch
  var cupMatchSel = qs('#sc-cup-match');
  if (cupMatchSel) {
    cupMatchSel.addEventListener('change', function () {
      var val = cupMatchSel.value;
      if (!val) return;
      try {
        var m = JSON.parse(val);
        var homeEl = qs('#sc-home-team');
        var awayEl = qs('#sc-away-team');
        if (homeEl) { homeEl.value = m.home || ''; }
        if (awayEl) { awayEl.value = m.away || ''; }
        // Disable validation against division teams — cup matches are pre-known
        divisionTeams    = null;
        divisionFixtures = null;
        // Clear any validation hints
        var hints = document.querySelectorAll('#sc-home-team-hint, #sc-away-team-hint, #sc-fixture-pairing-hint');
        hints.forEach(function (h) { h.textContent = ''; });
      } catch (e) {}
    });
    // If auth club is set, pre-select the first match involving their club
    if (authClub) {
      var opts = cupMatchSel.options;
      for (var oi = 1; oi < opts.length; oi++) {
        try {
          var m2 = JSON.parse(opts[oi].value);
          var clubU = authClub.toUpperCase();
          if ((m2.home || '').toUpperCase().indexOf(clubU.split(' ')[0]) === 0 ||
              (m2.away || '').toUpperCase().indexOf(clubU.split(' ')[0]) === 0) {
            cupMatchSel.selectedIndex = oi;
            cupMatchSel.dispatchEvent(new Event('change'));
            break;
          }
        } catch (e) {}
      }
    }
  }

  // ── Collect form ──────────────────────────────────────────────────────────────
  function collectForm() {
    var sc = {
      division:    (qs('#sc-division')    ||{}).value || '',
      venue:       (qs('#sc-venue')       ||{}).value || '',
      date:        (qs('#sc-date')        ||{}).value || '',
      home_team:   (qs('#sc-home-team')   ||{}).value || '',
      away_team:   (qs('#sc-away-team')   ||{}).value || '',
      home_total:  pn((qs('#sc-home-total')  ||{}).value),
      away_total:  pn((qs('#sc-away-total')  ||{}).value),
      home_points: pn((qs('#sc-home-points') ||{}).value),
      away_points: pn((qs('#sc-away-points') ||{}).value),
      rinks: [],
    };
    for (var r=1; r<=4; r++) {
      var hp=[], ap=[];
      for (var p=1; p<=4; p++) {
        var h = qs('[data-rink="'+r+'"][data-side="home"][data-player="'+p+'"]');
        var a = qs('[data-rink="'+r+'"][data-side="away"][data-player="'+p+'"]');
        hp.push(h ? h.value.trim() : '');
        ap.push(a ? a.value.trim() : '');
      }
      sc.rinks.push({
        rink: r,
        home_players: hp, away_players: ap,
        home_score: pn((qs('.lgw-score-home[data-rink="'+r+'"]')||{}).value),
        away_score: pn((qs('.lgw-score-away[data-rink="'+r+'"]')||{}).value),
      });
    }
    return sc;
  }
  function pn(v){ if(v===''||v==null) return null; var n=parseFloat(v); return isNaN(n)?null:n; }

  // ── Date normalisation ────────────────────────────────────────────────────────
  /**
   * Try to parse a freeform date string and return dd/mm/yyyy.
   * Handles: dd/mm/yyyy, dd-mm-yyyy, dd/mm/yy, d/m/yyyy,
   *          "10th May 2025", "10 May 2025", "10 May", "Sat 10-May-2025" etc.
   * Returns null if it can't be parsed confidently.
   */
  function normaliseDate(raw) {
    if (!raw) return null;
    var s = raw.trim();

    // Already dd/mm/yyyy or d/m/yyyy
    var m = s.match(/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{2,4})$/);
    if (m) {
      var d = parseInt(m[1], 10), mo = parseInt(m[2], 10), y = parseInt(m[3], 10);
      if (y < 100) y += 2000;
      if (d >= 1 && d <= 31 && mo >= 1 && mo <= 12)
        return pad2(d) + '/' + pad2(mo) + '/' + y;
    }

    var months = {jan:1,feb:2,mar:3,apr:4,may:5,jun:6,jul:7,aug:8,sep:9,oct:10,nov:11,dec:12};

    // "Sat 10-May-2025" or "Sat 10 May 2025" (from sheet format)
    m = s.match(/^(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun)\s+(\d{1,2})[\-\s]([A-Za-z]{3,})[\-\s](\d{4})$/i);
    if (m) {
      var mo = months[m[2].toLowerCase().slice(0,3)];
      if (mo) return pad2(parseInt(m[1],10)) + '/' + pad2(mo) + '/' + m[3];
    }

    // "10th May 2025", "10 May 2025", "10th May", "10 May"
    m = s.match(/^(\d{1,2})(?:st|nd|rd|th)?\s+([A-Za-z]{3,})(?:\s+(\d{4}))?$/i);
    if (m) {
      var mo = months[m[2].toLowerCase().slice(0,3)];
      var y  = m[3] ? parseInt(m[3], 10) : new Date().getFullYear();
      if (mo) return pad2(parseInt(m[1],10)) + '/' + pad2(mo) + '/' + y;
    }

    // "May 10 2025" / "May 10th 2025"
    m = s.match(/^([A-Za-z]{3,})\s+(\d{1,2})(?:st|nd|rd|th)?(?:\s+(\d{4}))?$/i);
    if (m) {
      var mo = months[m[1].toLowerCase().slice(0,3)];
      var y  = m[3] ? parseInt(m[3], 10) : new Date().getFullYear();
      if (mo) return pad2(parseInt(m[2],10)) + '/' + pad2(mo) + '/' + y;
    }

    return null;
  }

  function pad2(n) { return n < 10 ? '0' + n : '' + n; }

  // Normalise date field on blur
  var dateInput = qs('#sc-date');
  if (dateInput) {
    dateInput.addEventListener('blur', function() {
      var normalised = normaliseDate(dateInput.value);
      if (normalised && normalised !== dateInput.value) {
        dateInput.value = normalised;
        dateInput.style.borderColor = '#b2dfb2';
      }
    });
  }

  // ── Save scorecard ────────────────────────────────────────────────────────────
  var saveBtn    = qs('#lgw-save-scorecard');
  var saveStatus = qs('#lgw-save-status');
  var clearBtn   = qs('#lgw-clear-form');

  if (saveBtn) {
    saveBtn.addEventListener('click', function(){
      var sc = collectForm();
      if (!sc.home_team || !sc.away_team) {
        showStatus(saveStatus, '❌ Please enter both home and away team names', 'error'); return;
      }

      // If we have division teams loaded, validate names and fixture pairing now
      if (divisionTeams) {
        var homeResult = matchTeams(sc.home_team, divisionTeams);
        var awayResult = matchTeams(sc.away_team, divisionTeams);

        if (!homeResult.exact) {
          // Run the visual validation so the user sees the hints
          validateBothTeams();
          var msg = homeResult.matches.length
            ? '❌ "' + sc.home_team + '" is not an exact team name — please confirm the correct team from the suggestion below.'
            : '❌ "' + sc.home_team + '" was not found in this division. Please check the home team name.';
          showStatus(saveStatus, msg, 'error'); return;
        }
        if (!awayResult.exact) {
          validateBothTeams();
          var msg = awayResult.matches.length
            ? '❌ "' + sc.away_team + '" is not an exact team name — please confirm the correct team from the suggestion below.'
            : '❌ "' + sc.away_team + '" was not found in this division. Please check the away team name.';
          showStatus(saveStatus, msg, 'error'); return;
        }

        // Both names are exact — check the fixture pairing
        if (divisionFixtures && divisionFixtures.length) {
          var match = checkFixture(sc.home_team, sc.away_team);
          if (match.status === 'not_found') {
            validateFixturePairing();
            showStatus(saveStatus, '❌ No fixture found for ' + sc.home_team + ' v ' + sc.away_team + ' in this division. Please check the team names.', 'error'); return;
          }
          if (match.status === 'found_corrected' || match.status === 'reversed' || match.status === 'reversed_corrected') {
            validateFixturePairing();
            showStatus(saveStatus, '❌ Please apply the suggested team name correction shown below before submitting.', 'error'); return;
          }
        }
      }
      setLoading(saveBtn, 'Save Scorecard', true);
      showStatus(saveStatus, 'Saving…', 'info');
      post('lgw_save_scorecard', {scorecard: JSON.stringify(sc)}, function(res){
        setLoading(saveBtn, 'Save Scorecard', false);
        if (res.success) {
          var type = res.data.status === 'confirmed' ? 'ok' : res.data.status === 'disputed' ? 'error' : 'ok';
          var msg  = res.data.message;
          if (res.data.division_unresolved) {
            msg += ' ⚠️ Note: the division name wasn\'t recognised — the league admin will need to correct it before the result is written to the sheet.';
            type = 'warn';
          }
          showStatus(saveStatus, msg, type);
        } else {
          var errMsg = res.data || '';
          if (!errMsg || errMsg === 'Save failed') {
            errMsg = 'The scorecard could not be saved. Please check your connection and try again.';
          } else if (errMsg === 'Not authorised') {
            errMsg = 'Your session has expired. Please log out and log back in.';
          }
          showStatus(saveStatus, '❌ ' + errMsg, 'error');
        }
      });
    });
  }

  if (saveBtn) {
    saveBtn.addEventListener('error', function(){
      setLoading(saveBtn, 'Save Scorecard', false);
      showStatus(saveStatus, '❌ Network error — please check your connection and try again.', 'error');
    });
  }

  if (clearBtn) {
    clearBtn.addEventListener('click', function(){
      qsa('input[type=text], input[type=number]', qs('#lgw-scorecard-form')).forEach(function(el){ el.value=''; });
      showStatus(saveStatus, '', '');
    });
  }

  // ── Scorecard display (called from lgw-widget.js) ───────────────────────────
  window.lgwFetchScorecard = function(home, away, date, containerEl) {
    containerEl.innerHTML = '<p class="lgw-sc-loading">Loading scorecard…</p>';
    var xhr = new XMLHttpRequest();
    xhr.open('GET', ajaxUrl+'?action=lgw_get_scorecard'
      +'&home='+encodeURIComponent(home)
      +'&away='+encodeURIComponent(away)
      +'&date='+encodeURIComponent(date)+'&_='+Date.now());
    xhr.onload = function(){
      var res = JSON.parse(xhr.responseText || '{}');
      if (res.success && res.data) {
        containerEl.innerHTML = lgwRenderScorecardHtml(res.data);
      } else {
        containerEl.innerHTML = '<p class="lgw-sc-none">No scorecard submitted yet.</p>';
      }
    };
    xhr.onerror = function(){ containerEl.innerHTML = ''; };
    xhr.send();
  };

  // ── Scorecard HTML renderer (shared by modal display and review) ──────────────
  function lgwRenderScorecardHtml(sc) {
    var status = sc['_status'] || 'pending';
    var h = '<div class="lgw-sc-full">';

    // Status badge
    var badges = {
      pending:   '<span class="lgw-sc-badge lgw-sc-badge-pending">⏳ Awaiting confirmation</span>',
      confirmed: '<span class="lgw-sc-badge lgw-sc-badge-confirmed">✅ Confirmed by both clubs</span>',
      disputed:  '<span class="lgw-sc-badge lgw-sc-badge-disputed">⚠️ Result under review</span>',
    };
    h += (badges[status] || '');

    // Meta
    h += '<div class="lgw-sc-meta">';
    if (sc.division) h += '<span class="lgw-sc-div">'+esc(sc.division)+'</span>';
    if (sc.venue)    h += '<span class="lgw-sc-venue">'+esc(sc.venue)+'</span>';
    if (sc.date)     h += '<span class="lgw-sc-date">'+esc(sc.date)+'</span>';
    h += '</div>';

    // Rinks
    (sc.rinks || []).forEach(function(rk){
      h += '<div class="lgw-sc-rink">';
      h += '<div class="lgw-sc-rink-hdr">Rink '+rk.rink+'</div>';
      h += '<div class="lgw-sc-rink-body">';
      h += '<div class="lgw-sc-players lgw-sc-players-home">';
      (rk.home_players||[]).forEach(function(p){ if(p) h+='<div class="lgw-sc-player">'+esc(p)+'</div>'; });
      h += '</div>';
      h += '<div class="lgw-sc-scores">';
      h += '<span class="lgw-sc-score'+(rk.home_score>rk.away_score?' lgw-sc-win':'')+'">'+
           (rk.home_score!==null?rk.home_score:'–')+'</span>';
      h += '<span class="lgw-sc-sep">–</span>';
      h += '<span class="lgw-sc-score'+(rk.away_score>rk.home_score?' lgw-sc-win':'')+'">'+
           (rk.away_score!==null?rk.away_score:'–')+'</span>';
      h += '</div>';
      h += '<div class="lgw-sc-players lgw-sc-players-away">';
      (rk.away_players||[]).forEach(function(p){ if(p) h+='<div class="lgw-sc-player">'+esc(p)+'</div>'; });
      h += '</div>';
      h += '</div></div>';
    });

    // Totals
    if (sc.home_total !== null || sc.away_total !== null) {
      h += '<div class="lgw-sc-totals">';
      h += '<div class="lgw-sc-total-row">';
      h += '<span class="lgw-sc-total-lbl">Total Shots</span>';
      h += '<span class="lgw-sc-total-val'+(sc.home_total>sc.away_total?' lgw-sc-win':'')+'">'+
           (sc.home_total!==null?sc.home_total:'–')+'</span>';
      h += '<span class="lgw-sc-sep">–</span>';
      h += '<span class="lgw-sc-total-val'+(sc.away_total>sc.home_total?' lgw-sc-win':'')+'">'+
           (sc.away_total!==null?sc.away_total:'–')+'</span>';
      h += '</div>';
      if (sc.home_points !== null || sc.away_points !== null) {
        h += '<div class="lgw-sc-total-row lgw-sc-points-row">';
        h += '<span class="lgw-sc-total-lbl">Points</span>';
        h += '<span class="lgw-sc-total-val lgw-sc-pts'+(sc.home_points>sc.away_points?' lgw-sc-win':'')+'">'+
             (sc.home_points!==null?sc.home_points:'–')+'</span>';
        h += '<span class="lgw-sc-sep">–</span>';
        h += '<span class="lgw-sc-total-val lgw-sc-pts'+(sc.away_points>sc.home_points?' lgw-sc-win':'')+'">'+
             (sc.away_points!==null?sc.away_points:'–')+'</span>';
        h += '</div>';
      }
      h += '</div>';
    }
    h += '</div>';
    return h;
  }

  function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

})();
