/* LGW Scorecard JS - v5.18.0 */
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
    xhr.onload  = function(){ var r; try { r=JSON.parse(xhr.responseText||'{}'); } catch(e){ r={success:false,data:'Bad server response — please try again.'}; } cb(r); };
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

  // Detect mobile/touch — offer camera vs file choice
  var isMobile = ('ontouchstart' in window) || (navigator.maxTouchPoints > 0);

  // ── In-page camera (getUserMedia) ────────────────────────────────────────────
  // Uses the browser camera API directly — avoids the Chromium issue where
  // capture="environment" on a file input locks it to camera-only with no way
  // to switch to gallery/files.
  var cameraStream   = null;
  var cameraOverlay  = null;

  function openCameraOverlay() {
    if (cameraOverlay) return; // already open

    cameraOverlay = document.createElement('div');
    cameraOverlay.id = 'lgw-camera-overlay';
    cameraOverlay.style.cssText = [
      'position:fixed','inset:0','z-index:99999',
      'background:rgba(0,0,0,.92)',
      'display:flex','flex-direction:column',
      'align-items:center','justify-content:center',
      'gap:12px','padding:16px','box-sizing:border-box'
    ].join(';');

    var video = document.createElement('video');
    video.setAttribute('autoplay', '');
    video.setAttribute('playsinline', ''); // essential on iOS Safari
    video.setAttribute('muted', '');
    video.style.cssText = 'max-width:100%;max-height:60vh;border-radius:8px;background:#000';

    var statusMsg = document.createElement('p');
    statusMsg.style.cssText = 'color:#fff;font-size:14px;margin:0;text-align:center';
    statusMsg.textContent = 'Starting camera…';

    var btnRow = document.createElement('div');
    btnRow.style.cssText = 'display:flex;gap:12px;flex-wrap:wrap;justify-content:center';

    var snapBtn = document.createElement('button');
    snapBtn.type = 'button';
    snapBtn.className = 'lgw-btn lgw-btn-primary';
    snapBtn.textContent = '📸 Capture';
    snapBtn.disabled = true;

    var cancelBtn = document.createElement('button');
    cancelBtn.type = 'button';
    cancelBtn.className = 'lgw-btn lgw-btn-secondary';
    cancelBtn.textContent = '✕ Cancel';

    btnRow.appendChild(snapBtn);
    btnRow.appendChild(cancelBtn);
    cameraOverlay.appendChild(statusMsg);
    cameraOverlay.appendChild(video);
    cameraOverlay.appendChild(btnRow);
    document.body.appendChild(cameraOverlay);

    // Request rear camera on mobile, any camera on desktop
    var constraints = { video: { facingMode: isMobile ? 'environment' : 'user' }, audio: false };
    navigator.mediaDevices.getUserMedia(constraints).then(function(stream){
      cameraStream = stream;
      video.srcObject = stream;
      video.onloadedmetadata = function(){ video.play(); snapBtn.disabled = false; statusMsg.textContent = 'Position the scorecard and tap Capture.'; };
    }).catch(function(err){
      statusMsg.textContent = '⚠️ Camera unavailable: ' + (err.message || err) + '. Use "Choose file" instead.';
      snapBtn.style.display = 'none';
    });

    snapBtn.addEventListener('click', function(){
      var canvas = document.createElement('canvas');
      canvas.width  = video.videoWidth  || 1280;
      canvas.height = video.videoHeight || 720;
      canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
      canvas.toBlob(function(blob){
        closeCameraOverlay();
        // Wrap blob in a File so handlePhotoFile works normally
        var f = new File([blob], 'scorecard-photo.jpg', { type: 'image/jpeg' });
        handlePhotoFile(f);
      }, 'image/jpeg', 0.92);
    });

    cancelBtn.addEventListener('click', closeCameraOverlay);
  }

  function closeCameraOverlay() {
    if (cameraStream) { cameraStream.getTracks().forEach(function(t){ t.stop(); }); cameraStream = null; }
    if (cameraOverlay && cameraOverlay.parentNode) cameraOverlay.parentNode.removeChild(cameraOverlay);
    cameraOverlay = null;
  }

  // ── Choice popup (mobile) ────────────────────────────────────────────────────
  function showPhotoChoice() {
    var existing = qs('#lgw-photo-choice');
    if (existing) existing.parentNode.removeChild(existing);

    var popup = document.createElement('div');
    popup.id = 'lgw-photo-choice';
    popup.style.cssText = 'position:absolute;z-index:9999;background:#fff;border:1px solid #ddd;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.18);padding:10px;display:flex;flex-direction:column;gap:8px;min-width:220px';

    var btnCamera = document.createElement('button');
    btnCamera.type = 'button';
    btnCamera.className = 'lgw-btn lgw-btn-secondary';
    btnCamera.innerHTML = '📷 Take a photo';

    var btnFile = document.createElement('button');
    btnFile.type = 'button';
    btnFile.className = 'lgw-btn lgw-btn-secondary';
    btnFile.innerHTML = '🖼️ Choose from gallery / files';

    popup.appendChild(btnCamera);
    popup.appendChild(btnFile);

    var rect = photoTrigger.getBoundingClientRect();
    popup.style.top  = (rect.bottom + window.scrollY + 6) + 'px';
    popup.style.left = Math.max(8, rect.left + window.scrollX) + 'px';
    document.body.appendChild(popup);

    function dismiss() {
      if (popup.parentNode) popup.parentNode.removeChild(popup);
      document.removeEventListener('click', onOutside, true);
    }

    btnCamera.addEventListener('click', function(){ dismiss(); openCameraOverlay(); });
    btnFile.addEventListener('click',   function(){ dismiss(); if (photoInput) photoInput.click(); });

    function onOutside(e) {
      if (!popup.contains(e.target) && e.target !== photoTrigger) dismiss();
    }
    setTimeout(function(){ document.addEventListener('click', onOutside, true); }, 50);
  }

  if (photoTrigger) {
    photoTrigger.addEventListener('click', function(){
      if (isMobile) { showPhotoChoice(); } else { if (photoInput) photoInput.click(); }
    });
  }

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
    // Trigger rink-totals auto-sum after populate
    var firstRinkScore = qs('.lgw-score-home');
    if(firstRinkScore) firstRinkScore.dispatchEvent(new Event('input', {bubbles: true}));
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

  // Format a dd/mm/yyyy string as "Sat 9-May-2026" (fixture date display format)
  var _dayNames   = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
  var _monthNames = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  function formatDateLong(ddmmyyyy) {
    if (!ddmmyyyy) return null;
    var p = ddmmyyyy.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
    if (!p) return null;
    var d = parseInt(p[1],10), mo = parseInt(p[2],10)-1, y = parseInt(p[3],10);
    var dt = new Date(y, mo, d);
    if (isNaN(dt.getTime())) return null;
    return _dayNames[dt.getDay()] + ' ' + d + '-' + _monthNames[mo] + '-' + y;
  }

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

  // ── Rink score → totals auto-sum ──────────────────────────────────────────────
  // scopeEl: parent element to search within (document for standalone, modal el for modal)
  // homeId / awayId: IDs of the total fields
  // Returns a teardown function (not currently used but available)
  function bindRinkTotals(scopeEl, homeId, awayId) {
    var scope = scopeEl || document;

    function sumSide(side) {
      var total = 0;
      var anyFilled = false;
      scope.querySelectorAll('.lgw-score-' + side).forEach(function(inp){
        var v = inp.value === '' ? null : parseFloat(inp.value);
        if(v !== null && !isNaN(v)){ total += v; anyFilled = true; }
      });
      return anyFilled ? Math.round(total * 10) / 10 : null;
    }

    function updateTotals() {
      ['home', 'away'].forEach(function(side){
        var id      = side === 'home' ? homeId : awayId;
        var warnId  = id + '-sum-warn';
        var totalEl = scope.querySelector ? scope.querySelector('#' + id) : qs('#' + id);
        if(!totalEl) return;

        var sum = sumSide(side);
        if(sum === null) return; // no rink scores yet — leave total field alone

        var existing = totalEl.value === '' ? null : parseFloat(totalEl.value);

        // Remove previous warning
        var oldWarn = (scope.querySelector ? scope.querySelector('#' + warnId) : qs('#' + warnId));
        if(oldWarn) oldWarn.parentNode.removeChild(oldWarn);

        if(existing === null || totalEl.getAttribute('data-auto-sum') === '1'){
          // Field empty or was previously auto-set — silently update
          totalEl.value = sum;
          totalEl.setAttribute('data-auto-sum', '1');
        } else if(Math.abs(existing - sum) > 0.001){
          // Manual value that doesn't match — warn but don't overwrite
          var warn = document.createElement('p');
          warn.id = warnId;
          warn.className = 'lgw-notice lgw-notice-warn';
          warn.style.cssText = 'margin:2px 0 6px;font-size:11px';
          warn.textContent = '⚠ Rink scores add up to ' + sum + ', but total shows ' + existing + '. Update the total if this is incorrect.';
          totalEl.parentNode.insertBefore(warn, totalEl.nextSibling);
        }
        // If existing === sum, nothing to do
      });
    }

    // Clear auto-sum flag if user manually edits a total
    function clearAutoFlag(id) {
      var totalEl = scope.querySelector ? scope.querySelector('#' + id) : qs('#' + id);
      if(totalEl) totalEl.removeAttribute('data-auto-sum');
      var warnId  = id + '-sum-warn';
      var oldWarn = scope.querySelector ? scope.querySelector('#' + warnId) : qs('#' + warnId);
      if(oldWarn) oldWarn.parentNode.removeChild(oldWarn);
      // Re-run update so warning reappears if needed
      updateTotals();
    }

    // Bind to rink score inputs (use delegation on scope for modal-generated inputs)
    scope.addEventListener('input', function(e){
      if(e.target && (e.target.classList.contains('lgw-score-home') || e.target.classList.contains('lgw-score-away'))){
        updateTotals();
      }
    });

    // Bind manual-edit detection on total fields (only genuine user input)
    var hEl = scope.querySelector ? scope.querySelector('#' + homeId) : qs('#' + homeId);
    var aEl = scope.querySelector ? scope.querySelector('#' + awayId) : qs('#' + awayId);
    if(hEl) hEl.addEventListener('input', function(e){ if(e.isTrusted) clearAutoFlag(homeId); });
    if(aEl) aEl.addEventListener('input', function(e){ if(e.isTrusted) clearAutoFlag(awayId); });
  }

  // Wire up rink totals for the standalone submission page form
  var scForm = qs('#lgw-scorecard-form');
  if(scForm) bindRinkTotals(document, 'sc-home-total', 'sc-away-total');

  // ── Rink scores → points auto-suggest ────────────────────────────────────────
  // pointsPerRink: points for a rink win (half for draw)
  // pointsOverall: bonus points for winning overall shots (half for draw)
  var lgwPtsPerRink  = (typeof lgwSubmit !== 'undefined' && lgwSubmit.pointsPerRink  != null) ? parseFloat(lgwSubmit.pointsPerRink)  : 1;
  var lgwPtsOverall  = (typeof lgwSubmit !== 'undefined' && lgwSubmit.pointsOverall  != null) ? parseFloat(lgwSubmit.pointsOverall)  : 3;

  function calcPoints(rinks, homeTotal, awayTotal) {
    // Returns {home, away} points or null if not enough data
    var homeRinkPts = 0, awayRinkPts = 0;
    var hasAllScores = rinks.length > 0;
    rinks.forEach(function(rk){
      if(rk.home_score === null || rk.away_score === null){ hasAllScores = false; return; }
      if(rk.home_score > rk.away_score)       { homeRinkPts += lgwPtsPerRink; }
      else if(rk.away_score > rk.home_score)  { awayRinkPts += lgwPtsPerRink; }
      else                                     { homeRinkPts += lgwPtsPerRink / 2; awayRinkPts += lgwPtsPerRink / 2; }
    });
    if(!hasAllScores) return null;

    // Overall bonus
    var hTotal = homeTotal, aTotal = awayTotal;
    if(hTotal === null || aTotal === null){
      // Sum rinks as fallback for overall calculation
      hTotal = 0; aTotal = 0;
      rinks.forEach(function(rk){ hTotal += rk.home_score || 0; aTotal += rk.away_score || 0; });
      hTotal = Math.round(hTotal * 10) / 10;
      aTotal = Math.round(aTotal * 10) / 10;
    }
    if(hTotal > aTotal)       { homeRinkPts += lgwPtsOverall; }
    else if(aTotal > hTotal)  { awayRinkPts += lgwPtsOverall; }
    else                      { homeRinkPts += lgwPtsOverall / 2; awayRinkPts += lgwPtsOverall / 2; }

    // Round to 1 decimal to avoid float noise
    return {
      home: Math.round(homeRinkPts * 10) / 10,
      away: Math.round(awayRinkPts * 10) / 10,
    };
  }

  // bindPointsSuggest: wires rink score inputs in scopeEl to auto-suggest points
  // homePtsId / awayPtsId: IDs of the points inputs
  // getRinks: function() → [{home_score, away_score}]
  // getTotal: function(side) → number|null
  function bindPointsSuggest(scopeEl, homePtsId, awayPtsId, getRinks, getTotal) {
    var scope = scopeEl || document;

    function updatePoints() {
      var rinks = getRinks();
      var homeTotal = getTotal ? getTotal('home') : null;
      var awayTotal = getTotal ? getTotal('away') : null;
      var pts = calcPoints(rinks, homeTotal, awayTotal);
      if(pts === null) return;

      ['home', 'away'].forEach(function(side){
        var id      = side === 'home' ? homePtsId : awayPtsId;
        var warnId  = id + '-pts-warn';
        var ptsEl   = scope.querySelector ? scope.querySelector('#' + id) : qs('#' + id);
        if(!ptsEl) return;

        var suggested = side === 'home' ? pts.home : pts.away;
        var existing  = ptsEl.value === '' ? null : parseFloat(ptsEl.value);

        var oldWarn = scope.querySelector ? scope.querySelector('#' + warnId) : qs('#' + warnId);
        if(oldWarn) oldWarn.parentNode.removeChild(oldWarn);

        if(existing === null || ptsEl.getAttribute('data-auto-pts') === '1'){
          ptsEl.value = suggested;
          ptsEl.setAttribute('data-auto-pts', '1');
          // Also trigger the existing points hint update
          ptsEl.dispatchEvent(new Event('input'));
        } else if(existing !== suggested){
          var warn = document.createElement('p');
          warn.id = warnId;
          warn.className = 'lgw-notice lgw-notice-warn';
          warn.style.cssText = 'margin:2px 0 6px;font-size:11px';
          warn.textContent = '⚠ Calculated points: ' + suggested + ', but you\'ve entered ' + existing + '. Update if incorrect.';
          ptsEl.parentNode.insertBefore(warn, ptsEl.nextSibling);
        }
      });
    }

    // Clear auto-pts flag on manual edit (only for genuine user input, not programmatic)
    [homePtsId, awayPtsId].forEach(function(id){
      var el = scope.querySelector ? scope.querySelector('#' + id) : qs('#' + id);
      if(el) el.addEventListener('input', function(e){
        if(!e.isTrusted) return; // programmatic dispatch — ignore
        el.removeAttribute('data-auto-pts');
        var warnId = id + '-pts-warn';
        var oldWarn = scope.querySelector ? scope.querySelector('#' + warnId) : qs('#' + warnId);
        if(oldWarn) oldWarn.parentNode.removeChild(oldWarn);
        updatePoints();
      });
    });

    // Trigger on any rink score change (delegation)
    scope.addEventListener('input', function(e){
      if(e.target && (e.target.classList.contains('lgw-score-home') || e.target.classList.contains('lgw-score-away')
                   || e.target.id === (scopeEl ? 'lgw-modal-home-total' : 'sc-home-total')
                   || e.target.id === (scopeEl ? 'lgw-modal-away-total' : 'sc-away-total'))){
        updatePoints();
      }
    });

    return updatePoints; // return so callers can trigger manually
  }

  // Wire points suggest for standalone form
  if(scForm) {
    bindPointsSuggest(
      document,
      'sc-home-points', 'sc-away-points',
      function(){
        var rinks = [];
        for(var r=1;r<=4;r++){
          var hs = qs('.lgw-score-home[data-rink="'+r+'"]');
          var as = qs('.lgw-score-away[data-rink="'+r+'"]');
          var hv = hs && hs.value !== '' ? parseFloat(hs.value) : null;
          var av = as && as.value !== '' ? parseFloat(as.value) : null;
          if(hv !== null || av !== null) rinks.push({home_score:hv, away_score:av});
        }
        return rinks;
      },
      function(side){
        var id = side==='home' ? 'sc-home-total' : 'sc-away-total';
        var el = qs('#'+id);
        return el && el.value !== '' ? parseFloat(el.value) : null;
      }
    );
  }

  // ── Scorecard display (called from lgw-widget.js for played fixtures) ─────────
  window.lgwFetchScorecard = function(home, away, date, containerEl) {
    containerEl.innerHTML = '<p class="lgw-sc-loading">Loading scorecard…</p>';
    var xhr = new XMLHttpRequest();
    xhr.open('GET', ajaxUrl+'?action=lgw_get_scorecard'
      +'&home='+encodeURIComponent(home)
      +'&away='+encodeURIComponent(away)
      +'&date='+encodeURIComponent(date)
      +(opts.context ? '&context='+encodeURIComponent(opts.context) : '')
      +'&_='+Date.now());
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

  // ── Fetch scorecard; if none found and submission allowed, offer the form ──────
  // opts: { canSubmit, division, maxPts, isAdmin, submissionMode, authClub }
  window.lgwFetchScorecardOrSubmit = function(home, away, date, containerEl, opts) {
    opts = opts || {};
    containerEl.innerHTML = '<p class="lgw-sc-loading">Loading scorecard…</p>';
    var xhr = new XMLHttpRequest();
    xhr.open('GET', ajaxUrl+'?action=lgw_get_scorecard'
      +'&home='+encodeURIComponent(home)
      +'&away='+encodeURIComponent(away)
      +'&date='+encodeURIComponent(date)+'&_='+Date.now());
    xhr.onload = function(){
      var res;
      try { res = JSON.parse(xhr.responseText || '{}'); } catch(e) {
        containerEl.innerHTML = '<p class="lgw-sc-none">Could not load scorecard (bad response).</p>';
        return;
      }
      if (res.success && res.data) {
        // Scorecard exists — show it with inline confirm/amend if pending and second club
        var scData      = res.data;
        var resolvedClub = opts.authClub || authClub || '';
        containerEl.innerHTML = lgwRenderScorecardHtml(scData, {
          authClub:       resolvedClub,
          isAdmin:        opts.isAdmin,
          canSubmit:      opts.canSubmit,
          submissionMode: opts.submissionMode,
          context:        opts.context || '',
        });
        // Bind confirm/amend actions if rendered
        var reviewEl = containerEl.querySelector('.lgw-sc-review-inline');
        if (reviewEl) {
          lgwBindInlineReview(reviewEl, scData, containerEl, opts, home, away, date);
        }
        // If scorecard is pending and no club is authenticated, show a compact
        // login gate so the second club can confirm/amend from this modal.
        var scStatus = scData['_status'] || 'pending';
        var canConfirmMode = opts.submissionMode !== 'disabled'
          && (opts.submissionMode !== 'admin_only' || opts.isAdmin);
        if (scStatus === 'pending' && !resolvedClub && canConfirmMode) {
          lgwRenderModalLoginGate(containerEl, scData, opts, home, away, date);
        }
      } else if (opts.canSubmit) {
        // No scorecard yet — offer submission
        containerEl.innerHTML = '<p class="lgw-sc-none" style="margin-bottom:12px">No scorecard submitted yet.</p>'
          + '<div class="lgw-sc-submit-wrap"></div>';
        var sub = containerEl.querySelector('.lgw-sc-submit-wrap');
        if (sub && typeof window.lgwOpenSubmitInModal === 'function') {
          window.lgwOpenSubmitInModal(sub, {
            home: home,
            away: away,
            date: date,
            division: opts.division || '',
            maxPts: (opts.maxPts !== undefined && opts.maxPts !== null) ? opts.maxPts : 7,
            isAdmin: opts.isAdmin,
            submissionMode: opts.submissionMode,
            authClub: opts.authClub,
            context: opts.context || '',
          });
        }
      } else {
        containerEl.innerHTML = '<p class="lgw-sc-none">No scorecard submitted yet.</p>';
      }
    };
    xhr.onerror = function(){ containerEl.innerHTML = '<p class="lgw-sc-none">Could not load scorecard.</p>'; };
    xhr.send();
  };

  // ── Scorecard HTML renderer (shared by modal display and review) ──────────────
  // ctx: optional { authClub, isAdmin, canSubmit, submissionMode }
  function lgwRenderScorecardHtml(sc, ctx) {
    ctx = ctx || {};
    var status = sc['_status'] || 'pending';
    var submittedBy = sc['_submitted_by'] || '';
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
    if (sc.division)   h += '<span class="lgw-sc-div">'+esc(sc.division)+'</span>';
    if (sc.venue)      h += '<span class="lgw-sc-venue">'+esc(sc.venue)+'</span>';
    if (sc.date)       h += '<span class="lgw-sc-date">'+esc(sc.date)+'</span>';
    if (sc.submitter)  h += '<span class="lgw-sc-submitter">Submitted by: '+esc(sc.submitter)+'</span>';
    h += '</div>';

    // Rinks
    (sc.rinks || []).forEach(function(rk){
      h += '<div class="lgw-sc-rink">';
      h += '<div class="lgw-sc-rink-hdr">Rink '+rk.rink+'</div>';
      h += '<div class="lgw-sc-rink-body">';
      var homeClub = lgwResolveClub(sc.home_team || '');
      var awayClub = lgwResolveClub(sc.away_team || '');
      h += '<div class="lgw-sc-players lgw-sc-players-home">';
      (rk.home_players||[]).forEach(function(p){ if(p) h+='<div class="lgw-sc-player"><button type="button" class="lgw-player-link" data-player="'+esc(p)+'" data-club="'+esc(homeClub)+'">'+esc(p)+'</button></div>'; });
      h += '</div>';
      h += '<div class="lgw-sc-scores">';
      h += '<span class="lgw-sc-score'+(rk.home_score>rk.away_score?' lgw-sc-win':'')+'">'+
           (rk.home_score!==null?rk.home_score:'–')+'</span>';
      h += '<span class="lgw-sc-sep">–</span>';
      h += '<span class="lgw-sc-score'+(rk.away_score>rk.home_score?' lgw-sc-win':'')+'">'+
           (rk.away_score!==null?rk.away_score:'–')+'</span>';
      h += '</div>';
      h += '<div class="lgw-sc-players lgw-sc-players-away">';
      (rk.away_players||[]).forEach(function(p){ if(p) h+='<div class="lgw-sc-player"><button type="button" class="lgw-player-link" data-player="'+esc(p)+'" data-club="'+esc(awayClub)+'">'+esc(p)+'</button></div>'; });
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

    // ── Inline confirm/amend for pending scorecards ──
    // Show when: status is pending, submission is allowed, and the current
    // club is involved in the match but is NOT the one who submitted.
    var currentClubCtx = ctx.authClub || authClub || '';
    var canAct = (ctx.canSubmit || ctx.isAdmin)
      && status === 'pending'
      && currentClubCtx
      && !lgwClubMatchesTeam(currentClubCtx, submittedBy);

    // Also check the current club is actually involved in this match
    var homeTeam = sc.home_team || '';
    var awayTeam = sc.away_team || '';
    var clubInvolved = homeTeam && awayTeam && currentClubCtx && (
      lgwClubMatchesTeam(currentClubCtx, homeTeam) ||
      lgwClubMatchesTeam(currentClubCtx, awayTeam)
    );

    if (canAct && clubInvolved) {
      h += '<div class="lgw-sc-review-inline">'
        +'<p class="lgw-sc-review-prompt">This scorecard was submitted by <strong>'+esc(submittedBy)+'</strong>. Do the scores look correct?</p>'
        +'<div class="lgw-sc-review-actions">'
        +'<button class="lgw-btn lgw-btn-primary lgw-inline-confirm">✅ Confirm — scores are correct</button>'
        +'<button class="lgw-btn lgw-btn-secondary lgw-inline-amend">✏️ Amend — I have different scores</button>'
        +'</div>'
        +'<p class="lgw-inline-review-status lgw-notice" style="display:none"></p>'
        +'</div>';
    }

    h += '</div>';
    return h;
  }

  function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

  // ── In-modal submission form (called from lgw-widget.js) ──────────────────────
  // opts: { home, away, date, division, maxPts, isAdmin, submissionMode, authClub }
  window.lgwOpenSubmitInModal = function(containerEl, opts) {
    opts = opts || {};
    var home       = opts.home || '';
    var away       = opts.away || '';
    var date       = opts.date || '';
    var division   = opts.division || '';
    var maxPts     = (opts.maxPts !== undefined && opts.maxPts !== null) ? opts.maxPts : 7;
    var isAdm      = !!opts.isAdmin;
    var mode       = opts.submissionMode || 'open';
    var scContext  = opts.context || 'league';
    var currentClub = opts.authClub || authClub || '';

    // ── Helper: render the inline scorecard form (pre-filled, locked meta) ────
    function renderModalForm(club) {
      var rinkRows = '';
      for(var r = 1; r <= 4; r++){
        rinkRows +=
          '<tr class="lgw-modal-rink-row" data-rink="'+r+'">'
          +'<td class="lgw-modal-rink-num">'+r+'</td>'
          +'<td><textarea class="lgw-modal-players lgw-modal-players-home lgw-autoresize" placeholder="Player names, comma separated" data-rink="'+r+'" rows="1"></textarea></td>'
          +'<td style="text-align:center"><input type="number" class="lgw-score-home" data-rink="'+r+'" min="0" step="0.5" style="width:60px;text-align:center"></td>'
          +'<td style="text-align:center"><input type="number" class="lgw-score-away" data-rink="'+r+'" min="0" step="0.5" style="width:60px;text-align:center"></td>'
          +'<td><textarea class="lgw-modal-players lgw-modal-players-away lgw-autoresize" placeholder="Player names, comma separated" data-rink="'+r+'" rows="1"></textarea></td>'
          +'</tr>';
      }

      var adminForRow = '';
      if(isAdm){
        adminForRow =
          '<div class="lgw-modal-sc-field" style="margin-bottom:14px">'
          +'<div style="display:flex;align-items:baseline;gap:8px;flex-wrap:wrap;margin-bottom:4px">'
          +'<label class="lgw-modal-sc-label" style="margin:0">Submitting on behalf of</label>'
          +'</div>'
          +'<div style="display:flex;gap:16px;flex-wrap:wrap;font-size:12px">'
          +'<label style="display:flex;align-items:center;gap:4px"><input type="radio" name="lgw_modal_submitted_for" value="home" checked> '
            +esc(home)+' <span style="color:#888">(home)</span></label>'
          +'<label style="display:flex;align-items:center;gap:4px"><input type="radio" name="lgw_modal_submitted_for" value="away"> '
            +esc(away)+' <span style="color:#888">(away)</span></label>'
          +'<label style="display:flex;align-items:center;gap:4px"><input type="radio" name="lgw_modal_submitted_for" value="both"> '
            +'Both teams <span style="color:#0a5a0a">(auto-confirms)</span></label>'
          +'</div>'
          +'<div style="margin-top:10px">'
          +'<label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer">'
          +'<input type="checkbox" name="lgw_modal_skip_sheets" value="1"> '
          +'Skip Google Drive &amp; Sheets writeback <span style="color:#888">(use when backfilling historical scorecards)</span>'
          +'</label>'
          +'</div>'
          +'</div>';
      }

      return '<div class="lgw-modal-sc-form">'
        +(club && club !== 'Admin' ? '<div class="lgw-club-bar" style="margin-bottom:12px"><span>Submitting as <strong>'+esc(club)+'</strong></span>'
            +(mode==='open' ? ' <button class="lgw-btn lgw-btn-secondary lgw-btn-sm lgw-modal-logout">Log out</button>' : '')
            +'</div>' : '')
        // ── Entry method tabs ──
        +'<div class="lgw-submit-tabs" style="margin-bottom:12px">'
        +'<button class="lgw-stab lgw-modal-tab active" data-mtab="photo">📷 Photo</button>'
        +'<button class="lgw-stab lgw-modal-tab" data-mtab="excel">📊 Excel</button>'
        +'<button class="lgw-stab lgw-modal-tab" data-mtab="manual">✏️ Manual</button>'
        +'</div>'
        // Photo panel
        +'<div class="lgw-stab-panel active lgw-modal-tabpanel" data-mtab="photo">'
        +'<p class="lgw-hint">Upload a photo of the scorecard. AI will read it and pre-fill the form below.</p>'
        +'<div class="lgw-upload-area" id="lgw-modal-photo-drop">'
        +'<input type="file" id="lgw-modal-photo-input" accept="image/*" capture="environment" style="display:none">'
        +'<div class="lgw-upload-inner" id="lgw-modal-photo-trigger"><span class="lgw-upload-icon">📷</span><span>Tap to take a photo or choose an image</span></div>'
        +'<img id="lgw-modal-photo-preview" src="" alt="" style="display:none;max-width:100%;max-height:180px;border-radius:6px;margin-top:10px">'
        +'</div>'
        +'<button class="lgw-btn lgw-btn-primary" id="lgw-modal-parse-photo" style="margin-top:8px;display:none">Read Scorecard with AI</button>'
        +'<p id="lgw-modal-photo-status" class="lgw-notice" style="display:none"></p>'
        +'</div>'
        // Excel panel
        +'<div class="lgw-stab-panel lgw-modal-tabpanel" data-mtab="excel" style="display:none">'
        +'<p class="lgw-hint">Upload the LGW Excel scorecard template (.xlsx).</p>'
        +'<div class="lgw-upload-area">'
        +'<input type="file" id="lgw-modal-excel-input" accept=".xlsx,.xls">'
        +'<div class="lgw-upload-inner"><span class="lgw-upload-icon">📊</span><span>Choose Excel file (.xlsx)</span></div>'
        +'</div>'
        +'<button class="lgw-btn lgw-btn-primary" id="lgw-modal-parse-excel" style="margin-top:8px;display:none">Read Spreadsheet</button>'
        +'<p id="lgw-modal-excel-status" class="lgw-notice" style="display:none"></p>'
        +'</div>'
        // Manual panel
        +'<div class="lgw-stab-panel lgw-modal-tabpanel" data-mtab="manual" style="display:none">'
        +'<p class="lgw-hint">Fill in the scorecard details manually using the form below.</p>'
        +'</div>'
        // ── Match meta ──
        +'<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 14px;margin-bottom:14px">'
        +'<div class="lgw-modal-sc-field"><label class="lgw-modal-sc-label">Home Team</label>'
          +'<input type="text" class="lgw-modal-meta" id="lgw-modal-home" value="'+esc(home)+'" readonly></div>'
        +'<div class="lgw-modal-sc-field"><label class="lgw-modal-sc-label">Away Team</label>'
          +'<input type="text" class="lgw-modal-meta" id="lgw-modal-away" value="'+esc(away)+'" readonly></div>'
        +'<div class="lgw-modal-sc-field"><label class="lgw-modal-sc-label">Fixture Date</label>'
          +'<input type="text" class="lgw-modal-meta" id="lgw-modal-date" value="'+esc(date)+'" readonly></div>'
        +'<div class="lgw-modal-sc-field">'
          +'<label class="lgw-modal-sc-label">Date Played <span style="font-weight:400;text-transform:none;font-size:10px;color:#888">(only if different to fixture date)</span></label>'
          +'<input type="text" id="lgw-modal-date-played" placeholder="dd/mm/yyyy" style="width:100%;padding:7px 10px;border:1px solid #d0d5e8;border-radius:5px;font-size:13px;font-family:inherit"></div>'
        +'<div class="lgw-modal-sc-field"><label class="lgw-modal-sc-label">Division</label>'
          +'<input type="text" class="lgw-modal-meta" id="lgw-modal-division" value="'+esc(division)+'" readonly></div>'
        +'<div class="lgw-modal-sc-field"><label class="lgw-modal-sc-label">Venue</label>'
          +'<input type="text" id="lgw-modal-venue" placeholder="e.g. Home Club" style="width:100%;padding:7px 10px;border:1px solid #d0d5e8;border-radius:5px;font-size:13px;font-family:inherit"></div>'
        +'<div class="lgw-modal-sc-field" style="grid-column:1/-1"><label class="lgw-modal-sc-label">Submitted by <span style="font-weight:400;text-transform:none;font-size:10px;color:#888">(your name)</span></label>'
          +'<input type="text" id="lgw-modal-submitter" placeholder="e.g. John Smith" style="width:100%;padding:7px 10px;border:1px solid #d0d5e8;border-radius:5px;font-size:13px;font-family:inherit"></div>'
        +'</div>'
        +adminForRow
        // ── Rink table ──
        +'<div style="overflow-x:auto;margin-bottom:14px">'
        +'<table class="lgw-modal-rink-table">'
        +'<thead><tr><th style="width:36px">Rink</th>'
          +'<th style="text-align:left">'+esc(home)+' Players</th>'
          +'<th style="width:70px;text-align:center">Home</th>'
          +'<th style="width:70px;text-align:center">Away</th>'
          +'<th style="text-align:left">'+esc(away)+' Players</th>'
        +'</tr></thead>'
        +'<tbody>'+rinkRows+'</tbody>'
        +'</table></div>'
        // ── Totals ──
        +'<div style="display:grid;grid-template-columns:'+( maxPts > 0 ? '1fr 1fr 1fr 1fr' : '1fr 1fr' )+';gap:8px;margin-bottom:6px">'
        +'<div class="lgw-modal-sc-field"><label class="lgw-modal-sc-label">Home Total Shots</label>'
          +'<input type="number" id="lgw-modal-home-total" min="0" style="width:100%;padding:6px"></div>'
        +'<div class="lgw-modal-sc-field"><label class="lgw-modal-sc-label">Away Total Shots</label>'
          +'<input type="number" id="lgw-modal-away-total" min="0" style="width:100%;padding:6px"></div>'
        +(maxPts > 0
          ? '<div class="lgw-modal-sc-field"><label class="lgw-modal-sc-label">Home Points</label>'
            +'<input type="number" id="lgw-modal-home-pts" min="0" max="'+maxPts+'" style="width:100%;padding:6px" data-pts="home"></div>'
            +'<div class="lgw-modal-sc-field"><label class="lgw-modal-sc-label">Away Points</label>'
            +'<input type="number" id="lgw-modal-away-pts" min="0" max="'+maxPts+'" style="width:100%;padding:6px" data-pts="away"></div>'
          : '')
        +'</div>'
        +(maxPts > 0 ? '<p style="font-size:11px;color:#888;margin:0 0 12px">Points must total '+maxPts+' (max per match).</p>' : '')
        +'<p id="lgw-modal-sc-status" class="lgw-notice" style="display:none"></p>'
        +'<button class="lgw-btn lgw-btn-primary" id="lgw-modal-sc-save">💾 Save Scorecard</button>'
        +'</div>';
    }

    // ── Helper: populate modal form fields from parsed scorecard data ─────────
    function populateModalForm(el, sc) {
      // Venue — overwrite if AI/Excel gave us one; keep home/away/date/division locked
      if (sc.venue)      { var v = el.querySelector('#lgw-modal-venue');       if(v) v.value = sc.venue; }
      if (sc.date) {
        var normDate = normaliseDate(sc.date);
        // If date differs from fixture date, put it in the "date played" override field
        if (normDate && normDate !== date) {
          var dp = el.querySelector('#lgw-modal-date-played');
          if(dp) dp.value = normDate;
        }
      }
      // Totals
      function mset(id, val) {
        var fe = el.querySelector('#'+id);
        if(fe && val !== null && val !== undefined && val !== '') fe.value = val;
      }
      mset('lgw-modal-home-total',  sc.home_total);
      mset('lgw-modal-away-total',  sc.away_total);
      mset('lgw-modal-home-pts',    sc.home_points);
      mset('lgw-modal-away-pts',    sc.away_points);
      // Rinks
      (sc.rinks || []).forEach(function(rk){
        var r = rk.rink;
        var row = el.querySelector('.lgw-modal-rink-row[data-rink="'+r+'"]');
        if (!row) return;
        // Players — join array into comma-separated string
        var hpEl = row.querySelector('.lgw-modal-players-home');
        var apEl = row.querySelector('.lgw-modal-players-away');
        if (hpEl && rk.home_players && rk.home_players.length) hpEl.value = rk.home_players.join(', ');
        if (apEl && rk.away_players && rk.away_players.length) apEl.value = rk.away_players.join(', ');
        // Trigger auto-resize
        if(hpEl && hpEl.tagName === 'TEXTAREA') setTimeout(function(){ hpEl.style.height='auto'; hpEl.style.height=hpEl.scrollHeight+'px'; },0);
        if(apEl && apEl.tagName === 'TEXTAREA') setTimeout(function(){ apEl.style.height='auto'; apEl.style.height=apEl.scrollHeight+'px'; },0);
        var hs = row.querySelector('.lgw-score-home');
        var as = row.querySelector('.lgw-score-away');
        if (hs && rk.home_score !== null && rk.home_score !== undefined) hs.value = rk.home_score;
        if (as && rk.away_score !== null && rk.away_score !== undefined) as.value = rk.away_score;
      });
      // Trigger points hint update
      var hpEl2 = el.querySelector('#lgw-modal-home-pts');
      if (hpEl2) hpEl2.dispatchEvent(new Event('input'));
      // Trigger rink-totals auto-sum after populate
      var firstScore = el.querySelector('.lgw-score-home');
      if (firstScore) firstScore.dispatchEvent(new Event('input', {bubbles: true}));
      // Points auto-suggest will fire via the same event delegation above
    }
    function collectModalForm(el) {
      var rinks = [];
      el.querySelectorAll('.lgw-modal-rink-row').forEach(function(row){
        var r = row.getAttribute('data-rink');
        var hpInput = row.querySelector('.lgw-modal-players-home');
        var apInput = row.querySelector('.lgw-modal-players-away');
        var hpRaw = hpInput ? hpInput.value : '';
        var apRaw = apInput ? apInput.value : '';
        rinks.push({
          rink:         parseInt(r,10),
          home_players: hpRaw.split(',').map(function(s){return s.trim();}).filter(Boolean),
          away_players: apRaw.split(',').map(function(s){return s.trim();}).filter(Boolean),
          home_score:   (function(v){return v===''||v===null?null:parseFloat(v);})(
                          (row.querySelector('.lgw-score-home')||{}).value),
          away_score:   (function(v){return v===''||v===null?null:parseFloat(v);})(
                          (row.querySelector('.lgw-score-away')||{}).value),
        });
      });
      // Date played: use if filled, else fall back to fixture date
      // Normalise back to dd/mm/yyyy for consistent storage (field may show long format)
      var datePlayedRaw = ((el.querySelector('#lgw-modal-date-played')||{}).value||'').trim();
      var datePlayed = datePlayedRaw ? (normaliseDate(datePlayedRaw) || datePlayedRaw) : '';
      var fixtureDate = (el.querySelector('#lgw-modal-date')||{}).value || date;
      return {
        home_team:    (el.querySelector('#lgw-modal-home')||{}).value || home,
        away_team:    (el.querySelector('#lgw-modal-away')||{}).value || away,
        date:         datePlayed || fixtureDate,
        fixture_date: fixtureDate,
        division:     (el.querySelector('#lgw-modal-division')||{}).value || division,
        venue:        ((el.querySelector('#lgw-modal-venue')||{}).value||'').trim(),
        submitter:    ((el.querySelector('#lgw-modal-submitter')||{}).value||'').trim(),
        home_total:   (function(v){return v===''?null:parseFloat(v);})(
                        ((el.querySelector('#lgw-modal-home-total')||{}).value)),
        away_total:   (function(v){return v===''?null:parseFloat(v);})(
                        ((el.querySelector('#lgw-modal-away-total')||{}).value)),
        home_points:  (function(v){return v===''?null:parseFloat(v);})(
                        ((el.querySelector('#lgw-modal-home-pts')||{}).value)),
        away_points:  (function(v){return v===''?null:parseFloat(v);})(
                        ((el.querySelector('#lgw-modal-away-pts')||{}).value)),
        rinks: rinks,
        context: scContext,
      };
    }

    // ── Helper: validate points ───────────────────────────────────────────────
    function validatePoints(sc, statusEl) {
      // Cup mode (maxPts === 0): no points fields, always valid
      if (maxPts === 0) return true;
      var hp = sc.home_points, ap = sc.away_points;
      // Both blank — skip validation (player tracking only, no result needed)
      if (hp === null && ap === null) return true;
      // One filled, one blank
      if (hp === null || ap === null) {
        showStatus(statusEl, '❌ Please enter both home and away points, or leave both blank.', 'error');
        return false;
      }
      if (hp < 0 || ap < 0) {
        showStatus(statusEl, '❌ Points cannot be negative.', 'error');
        return false;
      }
      if (hp > maxPts || ap > maxPts) {
        showStatus(statusEl, '❌ Neither team can exceed '+maxPts+' points.', 'error');
        return false;
      }
      var total = hp + ap;
      if (Math.abs(total - maxPts) > 0.001) {
        showStatus(statusEl, '❌ Home and away points must total '+maxPts+' (currently '+total+'). Max per match is '+maxPts+'.', 'error');
        return false;
      }
      return true;
    }

    // ── Show login gate or form ───────────────────────────────────────────────
    if(mode !== 'disabled' && mode !== 'admin_only' && !currentClub && !isAdm){
      var allClubs = (typeof lgwSubmit !== 'undefined' && lgwSubmit.clubs) ? lgwSubmit.clubs : [];

      // Filter to only clubs involved in this fixture
      var fixtureClubs = allClubs.filter(function(c){ return lgwClubMatchesTeam(c, home) || lgwClubMatchesTeam(c, away); });
      // Fall back to all clubs if no match (e.g. club list not configured)
      var clubs = fixtureClubs.length ? fixtureClubs : allClubs;

      var clubOpts = '';
      if(clubs.length){
        clubs.forEach(function(c){
          clubOpts += '<option value="'+esc(c)+'">'+esc(c)+'</option>';
        });
      }

      containerEl.innerHTML =
        '<div class="lgw-submit-card" style="margin:0;box-shadow:none;border:none;padding:0">'
        +'<h3 style="margin:0 0 10px;color:#1a2e5a">Log in to submit scorecard</h3>'
        +'<p style="font-size:13px;margin-bottom:12px;color:#555">Select your club and enter your passphrase.</p>'
        +(clubs.length
          ? '<div class="lgw-form-row" style="margin-bottom:10px">'
            +'<select id="lgw-modal-club-select" style="width:100%;padding:9px;border:1px solid #d0d5e8;border-radius:6px;font-size:14px">'
            +'<option value="">— Select your club —</option>'+clubOpts+'</select></div>'
          : '<div class="lgw-form-row" style="margin-bottom:10px">'
            +'<input type="text" id="lgw-modal-club-text" placeholder="Your club name" style="width:100%;padding:9px;border:1px solid #d0d5e8;border-radius:6px;font-size:14px"></div>'
        )
        +'<div class="lgw-pin-row" style="margin-bottom:8px">'
        +'<input type="text" id="lgw-modal-pin-input" placeholder="Passphrase" maxlength="60" autocomplete="off" autocapitalize="none" spellcheck="false">'
        +'<button class="lgw-btn lgw-btn-primary" id="lgw-modal-pin-submit">Login</button>'
        +'</div>'
        +'<p id="lgw-modal-pin-error" class="lgw-notice lgw-notice-error" style="display:none"></p>'
        +'</div>';

      function doModalLogin(){
        var clubEl = containerEl.querySelector('#lgw-modal-club-select') || containerEl.querySelector('#lgw-modal-club-text');
        var pinEl  = containerEl.querySelector('#lgw-modal-pin-input');
        var errEl  = containerEl.querySelector('#lgw-modal-pin-error');
        var clubVal = clubEl ? clubEl.value.trim() : '';
        var pin    = pinEl  ? pinEl.value.trim()  : '';
        if(!clubVal){ showStatus(errEl,'Please select your club','error'); return; }
        if(!pin) { showStatus(errEl,'Please enter your passphrase','error'); return; }
        var btn = containerEl.querySelector('#lgw-modal-pin-submit');
        if(btn){ btn.disabled=true; btn.textContent='Logging in…'; }
        post('lgw_check_pin', {club: clubVal, pin: pin}, function(res){
          if(btn){ btn.disabled=false; btn.textContent='Login'; }
          if(res.success){
            currentClub = res.data.club;
            authClub = currentClub;
            containerEl.innerHTML = renderModalForm(currentClub);
            bindModalForm(containerEl);
          } else {
            showStatus(errEl, res.data || 'Incorrect club or passphrase', 'error');
          }
        });
      }

      var loginBtn = containerEl.querySelector('#lgw-modal-pin-submit');
      var pinEl2   = containerEl.querySelector('#lgw-modal-pin-input');
      if(loginBtn) loginBtn.addEventListener('click', doModalLogin);
      if(pinEl2)   pinEl2.addEventListener('keydown', function(e){ if(e.key==='Enter') doModalLogin(); });
      return;
    }

    // Already logged in or admin — but first check club is involved in THIS fixture
    // A club logged in on one page carries their session to all fixture modals;
    // if they click a fixture that doesn't involve their club, show read-only view.
    if(!isAdm && currentClub && home && away){
      var clubInFixture = lgwClubMatchesTeam(currentClub, home) || lgwClubMatchesTeam(currentClub, away);
      if(!clubInFixture){
        containerEl.innerHTML = '<p class="lgw-sc-none">No scorecard submitted yet.</p>';
        return;
      }
    }

    // Involved club or admin — show form directly
    containerEl.innerHTML = renderModalForm(isAdm ? 'Admin' : currentClub);
    bindModalForm(containerEl);
    // Pre-fill with existing scorecard data if provided (amend flow)
    if(opts.prefill) {
      setTimeout(function(){ populateModalForm(containerEl, opts.prefill); }, 50);
    }

    function bindModalForm(el) {
      // ── Tab switching ──────────────────────────────────────────────────────
      el.querySelectorAll('.lgw-modal-tab').forEach(function(tab){
        tab.addEventListener('click', function(){
          el.querySelectorAll('.lgw-modal-tab').forEach(function(t){ t.classList.remove('active'); });
          el.querySelectorAll('.lgw-modal-tabpanel').forEach(function(p){ p.style.display='none'; p.classList.remove('active'); });
          tab.classList.add('active');
          var panel = el.querySelector('.lgw-modal-tabpanel[data-mtab="'+tab.getAttribute('data-mtab')+'"]');
          if(panel){ panel.style.display=''; panel.classList.add('active'); }
        });
      });

      // ── Date Played — normalise on blur ───────────────────────────────────
      var datePlayedEl = el.querySelector('#lgw-modal-date-played');
      if(datePlayedEl){
        datePlayedEl.addEventListener('blur', function(){
          var normalised = normaliseDate(datePlayedEl.value);
          if(normalised){
            var long = formatDateLong(normalised);
            var display = long || normalised;
            if(display !== datePlayedEl.value){
              datePlayedEl.value = display;
              datePlayedEl.style.borderColor = '#b2dfb2';
            }
          }
        });
      }

      // ── Photo upload ───────────────────────────────────────────────────────
      var photoInput2   = el.querySelector('#lgw-modal-photo-input');
      var photoTrigger2 = el.querySelector('#lgw-modal-photo-trigger');
      var photoPreview2 = el.querySelector('#lgw-modal-photo-preview');
      var parseBtn2     = el.querySelector('#lgw-modal-parse-photo');
      var photoStat2    = el.querySelector('#lgw-modal-photo-status');
      var modalPhotoFile = null;

      if(photoTrigger2) photoTrigger2.addEventListener('click', function(){ if(photoInput2) photoInput2.click(); });
      if(photoInput2) photoInput2.addEventListener('change', function(){
        if(!photoInput2.files[0]) return;
        modalPhotoFile = photoInput2.files[0];
        var reader = new FileReader();
        reader.onload = function(e){
          photoPreview2.src = e.target.result;
          photoPreview2.style.display = '';
          if(parseBtn2) parseBtn2.style.display = '';
        };
        reader.readAsDataURL(modalPhotoFile);
      });
      if(parseBtn2){
        parseBtn2.addEventListener('click', function(){
          if(!modalPhotoFile) return;
          parseBtn2.disabled = true; parseBtn2.textContent = '⏳ Reading…';
          showStatus(photoStat2, 'Sending to AI — this takes a few seconds…', 'info');
          var fd = new FormData();
          fd.append('action', 'lgw_parse_photo');
          fd.append('nonce',  nonce);
          fd.append('photo',  modalPhotoFile);
          var xhr3 = new XMLHttpRequest();
          xhr3.open('POST', ajaxUrl);
          xhr3.onload = function(){
            parseBtn2.disabled = false; parseBtn2.textContent = 'Read Scorecard with AI';
            var res = JSON.parse(xhr3.responseText || '{}');
            if(res.success){ populateModalForm(el, res.data); showStatus(photoStat2, '✅ Scorecard read — please check all fields below.', 'ok'); }
            else showStatus(photoStat2, '❌ ' + (res.data || 'Could not read scorecard'), 'error');
          };
          xhr3.onerror = function(){ parseBtn2.disabled=false; parseBtn2.textContent='Read Scorecard with AI'; showStatus(photoStat2,'❌ Network error','error'); };
          xhr3.send(fd);
        });
      }

      // ── Excel upload ───────────────────────────────────────────────────────
      var excelInput2  = el.querySelector('#lgw-modal-excel-input');
      var parseExcel2  = el.querySelector('#lgw-modal-parse-excel');
      var excelStat2   = el.querySelector('#lgw-modal-excel-status');

      if(excelInput2) excelInput2.addEventListener('change', function(){
        if(excelInput2.files[0] && parseExcel2) parseExcel2.style.display = '';
      });
      if(parseExcel2){
        parseExcel2.addEventListener('click', function(){
          if(!excelInput2 || !excelInput2.files[0]) return;
          parseExcel2.disabled = true; parseExcel2.textContent = '⏳ Reading…';
          showStatus(excelStat2, 'Reading spreadsheet…', 'info');
          var fd = new FormData();
          fd.append('action', 'lgw_parse_excel');
          fd.append('nonce',  nonce);
          fd.append('excel',  excelInput2.files[0]);
          var xhr4 = new XMLHttpRequest();
          xhr4.open('POST', ajaxUrl);
          xhr4.onload = function(){
            parseExcel2.disabled = false; parseExcel2.textContent = 'Read Spreadsheet';
            var res = JSON.parse(xhr4.responseText || '{}');
            if(res.success){ populateModalForm(el, res.data); showStatus(excelStat2, '✅ Spreadsheet read — please check all fields below.', 'ok'); }
            else showStatus(excelStat2, '❌ ' + (res.data || 'Could not read file'), 'error');
          };
          xhr4.onerror = function(){ parseExcel2.disabled=false; parseExcel2.textContent='Read Spreadsheet'; showStatus(excelStat2,'❌ Network error','error'); };
          xhr4.send(fd);
        });
      }

      // Logout
      var logoutBtn = el.querySelector('.lgw-modal-logout');
      if(logoutBtn){
        logoutBtn.addEventListener('click', function(){
          post('lgw_logout', {}, function(){
            authClub = '';
            currentClub = '';
            window.lgwOpenSubmitInModal(containerEl, opts);
          });
        });
      }

      // Live points sum hint
      function updatePointsHint() {
        var hpEl = el.querySelector('#lgw-modal-home-pts');
        var apEl = el.querySelector('#lgw-modal-away-pts');
        var hint = el.querySelector('#lgw-modal-pts-hint');
        if(!hpEl||!apEl||!hint) return;
        var hp = hpEl.value === '' ? null : parseFloat(hpEl.value);
        var ap = apEl.value === '' ? null : parseFloat(apEl.value);
        if(hp === null && ap === null){ hint.textContent=''; hint.style.color=''; return; }
        var total = Math.round(((hp||0)+(ap||0)) * 10) / 10;
        if(Math.abs(total - maxPts) < 0.001){
          hint.textContent = '✓ '+total+'/'+maxPts;
          hint.style.color = '#2a7a2a';
        } else {
          hint.textContent = total+'/'+maxPts+' — must total '+maxPts;
          hint.style.color = '#c0202a';
        }
      }
      // Insert hint span after points row
      var ptsRow = el.querySelector('#lgw-modal-home-pts');
      if(ptsRow){
        var hintEl = document.createElement('p');
        hintEl.id = 'lgw-modal-pts-hint';
        hintEl.style.cssText = 'font-size:11px;margin:2px 0 10px;font-weight:600';
        ptsRow.closest('div').parentNode.insertAdjacentElement('afterend', hintEl);
        el.querySelector('#lgw-modal-home-pts').addEventListener('input', updatePointsHint);
        el.querySelector('#lgw-modal-away-pts').addEventListener('input', updatePointsHint);
      }

      // ── Auto-resize textareas ─────────────────────────────────────────────
      function autoResize(ta) {
        ta.style.height = 'auto';
        ta.style.height = ta.scrollHeight + 'px';
      }

      // ── Live duplicate player warning ─────────────────────────────────────
      // Returns array of {name, team, rinks[]} for any name appearing >1 on same team
      function findDuplicatePlayers(sc) {
        var counts = {}; // key: "side:normalised_name" → [{rink, raw}]
        sc.rinks.forEach(function(rk){
          ['home_players','away_players'].forEach(function(side){
            var sideKey = side === 'home_players' ? 'home' : 'away';
            (rk[side]||[]).forEach(function(raw){
              var n = raw.replace(/\*/g,'').trim();
              if(!n) return;
              var key = sideKey + ':' + n.toLowerCase();
              if(!counts[key]) counts[key] = {name: n, side: sideKey, rinks: []};
              counts[key].rinks.push(rk.rink);
            });
          });
        });
        var dups = [];
        Object.keys(counts).forEach(function(k){
          if(counts[k].rinks.length > 1) dups.push(counts[k]);
        });
        return dups;
      }

      function updateDupWarnings() {
        var sc = collectModalForm(el);
        var dups = findDuplicatePlayers(sc);
        var warn = el.querySelector('#lgw-modal-dup-warn');
        if(!warn){
          warn = document.createElement('p');
          warn.id = 'lgw-modal-dup-warn';
          warn.className = 'lgw-notice lgw-notice-warn';
          warn.style.display = 'none';
          var saveBtn2 = el.querySelector('#lgw-modal-sc-save');
          if(saveBtn2) saveBtn2.parentNode.insertBefore(warn, saveBtn2);
        }
        if(dups.length){
          var msgs = dups.map(function(d){
            return '"'+d.name+'" appears '+d.rinks.length+' times on the '+d.side+' team (rinks '+d.rinks.join(', ')+')';
          });
          warn.style.display = '';
          warn.innerHTML = '⚠️ Duplicate player names detected — if these are two different people, please distinguish them with a suffix or full name (e.g. J Smith Sr / J Smith Jr):<br><strong>'+msgs.join('<br>')+'</strong>';
        } else {
          warn.style.display = 'none';
          warn.textContent = '';
        }
      }

      el.querySelectorAll('.lgw-autoresize').forEach(function(ta){
        ta.addEventListener('input', function(){
          autoResize(ta);
          updateDupWarnings();
        });
        setTimeout(function(){ autoResize(ta); }, 0);
      });

      // ── Rink score → totals auto-sum (modal) ─────────────────────────────
      bindRinkTotals(el, 'lgw-modal-home-total', 'lgw-modal-away-total');

      // ── Rink scores → points auto-suggest (modal) ─────────────────────────
      if(maxPts > 0) {
        bindPointsSuggest(
          el,
          'lgw-modal-home-pts', 'lgw-modal-away-pts',
          function(){
            var rinks = [];
            el.querySelectorAll('.lgw-modal-rink-row').forEach(function(row){
              var hs = row.querySelector('.lgw-score-home');
              var as = row.querySelector('.lgw-score-away');
              var hv = hs && hs.value !== '' ? parseInt(hs.value,10) : null;
              var av = as && as.value !== '' ? parseInt(as.value,10) : null;
              if(hv !== null || av !== null) rinks.push({home_score:hv, away_score:av});
            });
            return rinks;
          },
          function(side){
            var id = side==='home' ? 'lgw-modal-home-total' : 'lgw-modal-away-total';
            var te = el.querySelector('#'+id);
            return te && te.value !== '' ? parseFloat(te.value) : null;
          }
        );
      }

      // ── Save — shows preview popup first ─────────────────────────────────
      var saveBtn   = el.querySelector('#lgw-modal-sc-save');
      var statusEl  = el.querySelector('#lgw-modal-sc-status');
      if(!saveBtn) return;

      saveBtn.addEventListener('click', function(){
        var sc = collectModalForm(el);
        if(!sc.home_team || !sc.away_team){
          showStatus(statusEl, '❌ Home and away team names are required', 'error'); return;
        }
        if(!validatePoints(sc, statusEl)) return;

        // ── Duplicate player check ───────────────────────────────────────────
        var dups = findDuplicatePlayers(sc);
        if(dups.length){
          var dupNames = dups.map(function(d){
            return '"'+d.name+'" ('+d.side+' team, rinks '+d.rinks.join(', ')+')';
          }).join('; ');
          showStatus(statusEl,
            '❌ Duplicate player names on the same team: '+dupNames
            +'. If these are two different people, please use a distinguishing suffix (e.g. J Smith Sr / J Smith Jr) or enter their full names.',
            'error');
          return;
        }

        var submittedFor = 'home';
        if(isAdm){
          var sfEl = el.querySelector('input[name="lgw_modal_submitted_for"]:checked');
          submittedFor = sfEl ? sfEl.value : 'home';
        }

        // ── Team mismatch check ──────────────────────────────────────────────
        // Ensure the submitted teams match the fixture teams (in either order)
        var submittedHome = sc.home_team.trim();
        var submittedAway = sc.away_team.trim();
        var fixtureHome   = home.trim();
        var fixtureAway   = away.trim();

        // Check if submitted pair matches fixture pair (allowing home/away swap)
        var straightMatch  = lgwTeamNamesMatch(submittedHome, fixtureHome) && lgwTeamNamesMatch(submittedAway, fixtureAway);
        var reversedMatch  = lgwTeamNamesMatch(submittedHome, fixtureAway) && lgwTeamNamesMatch(submittedAway, fixtureHome);

        if(!straightMatch && !reversedMatch){
          showStatus(statusEl,
            '❌ These teams don\'t match this fixture. This fixture is '+esc(fixtureHome)+' v '+esc(fixtureAway)
            +' — the scorecard appears to be for a different game. Please check you\'ve uploaded the correct scorecard.',
            'error');
          return;
        }
        var homeClub = lgwResolveClub(sc.home_team);
        var awayClub = lgwResolveClub(sc.away_team);
        var allPlayers = [];
        sc.rinks.forEach(function(rk){
          (rk.home_players||[]).forEach(function(n){ if(n) allPlayers.push({name:n, club:homeClub||sc.home_team}); });
          (rk.away_players||[]).forEach(function(n){ if(n) allPlayers.push({name:n, club:awayClub||sc.away_team}); });
        });

        saveBtn.disabled = true;
        saveBtn.textContent = '⏳ Checking…';
        showStatus(statusEl, '', '');

        // Check new players, then show preview
        var fd0 = new FormData();
        fd0.append('action',  'lgw_check_new_players');
        fd0.append('nonce',   nonce);
        fd0.append('players', JSON.stringify(allPlayers));
        var xhrN = new XMLHttpRequest();
        xhrN.open('POST', ajaxUrl);
        xhrN.onload = function(){
          saveBtn.disabled = false;
          saveBtn.textContent = '💾 Save Scorecard';
          var res = JSON.parse(xhrN.responseText || '{}');
          var newPlayers = (res.success && res.data) ? res.data : [];
          showPreview(sc, submittedFor, newPlayers);
        };
        xhrN.onerror = function(){
          saveBtn.disabled = false;
          saveBtn.textContent = '💾 Save Scorecard';
          // Still show preview even if check fails
          showPreview(sc, submittedFor, []);
        };
        xhrN.send(fd0);
      });

      // ── Build set of new player names for quick lookup ────────────────────
      function buildNewSet(newPlayers) {
        var s = {};
        newPlayers.forEach(function(p){ s[p.name.toLowerCase()] = p.reason; });
        return s;
      }

      // Build set of duplicate names per side: {side:name_lower → true}
      function buildDupSet(sc) {
        var counts = {};
        sc.rinks.forEach(function(rk){
          ['home_players','away_players'].forEach(function(side){
            var sideKey = side === 'home_players' ? 'home' : 'away';
            (rk[side]||[]).forEach(function(raw){
              var n = raw.replace(/\*/g,'').trim().toLowerCase();
              if(!n) return;
              var key = sideKey+':'+n;
              counts[key] = (counts[key]||0)+1;
            });
          });
        });
        var dups = {};
        Object.keys(counts).forEach(function(k){ if(counts[k]>1) dups[k]=true; });
        return dups;
      }

      // ── Render a single player name with highlights ───────────────────────
      function renderPlayerName(rawName, newSet, dupSet, side) {
        var isLady  = rawName.indexOf('*') !== -1;
        var cleaned = rawName.replace(/\*/g, '').trim();
        var lower   = cleaned.toLowerCase();
        var isNew   = newSet.hasOwnProperty(lower);
        var isDup   = dupSet && dupSet.hasOwnProperty((side||'home')+':'+lower);
        var reason  = isNew ? newSet[lower] : '';
        var cls = 'lgw-prev-player';
        if(isLady) cls += ' lgw-prev-lady';
        if(isNew)  cls += ' lgw-prev-new';
        if(isDup)  cls += ' lgw-prev-dup';

        var badges = '';
        if(isLady) badges += '<span class="lgw-prev-badge lgw-prev-badge-lady" title="Ladies player">♀</span>';
        if(isNew)  badges += '<span class="lgw-prev-badge lgw-prev-badge-new" title="First game this season">1st game</span>';
        if(isDup)  badges += '<span class="lgw-prev-badge lgw-prev-badge-dup" title="Same name appears more than once on this team">DUP</span>';

        return (isLady||isNew||isDup)
          ? '<span class="'+cls+'">'+esc(cleaned)+badges+'</span>'
          : esc(cleaned);
      }

      // ── Show preview popup ────────────────────────────────────────────────
      function showPreview(sc, submittedFor, newPlayers) {
        var newSet = buildNewSet(newPlayers);
        var dupSet = buildDupSet(sc);
        var hasNew  = newPlayers.length > 0;
        var hasLady = false;
        var hasDup  = Object.keys(dupSet).length > 0;

        // Check for lady markers
        sc.rinks.forEach(function(rk){
          (rk.home_players||[]).concat(rk.away_players||[]).forEach(function(n){
            if(n && n.indexOf('*') !== -1) hasLady = true;
          });
        });

        // Build rink rows HTML
        var rinkHtml = '';
        sc.rinks.forEach(function(rk){
          var hasScores = rk.home_score !== null || rk.away_score !== null;
          var hasPlayers = (rk.home_players&&rk.home_players.length) || (rk.away_players&&rk.away_players.length);
          if(!hasScores && !hasPlayers) return;
          rinkHtml += '<div class="lgw-prev-rink">';
          rinkHtml += '<div class="lgw-prev-rink-hdr">Rink '+rk.rink+'</div>';
          rinkHtml += '<div class="lgw-prev-rink-body">';
          rinkHtml += '<div class="lgw-prev-col lgw-prev-col-home">';
          (rk.home_players||[]).forEach(function(n){ if(n) rinkHtml += '<div class="lgw-prev-name">'+renderPlayerName(n, newSet, dupSet, 'home')+'</div>'; });
          rinkHtml += '</div>';
          rinkHtml += '<div class="lgw-prev-scores">'
            +'<span class="lgw-prev-score'+(rk.home_score>rk.away_score?' lgw-prev-win':'')+'">'+((rk.home_score!==null&&rk.home_score!=='')?rk.home_score:'–')+'</span>'
            +'<span class="lgw-prev-sep">–</span>'
            +'<span class="lgw-prev-score'+(rk.away_score>rk.home_score?' lgw-prev-win':'')+'">'+((rk.away_score!==null&&rk.away_score!=='')?rk.away_score:'–')+'</span>'
            +'</div>';
          rinkHtml += '<div class="lgw-prev-col lgw-prev-col-away">';
          (rk.away_players||[]).forEach(function(n){ if(n) rinkHtml += '<div class="lgw-prev-name">'+renderPlayerName(n, newSet, dupSet, 'away')+'</div>'; });
          rinkHtml += '</div>';
          rinkHtml += '</div></div>';
        });

        var legendHtml = '';
        if(hasNew || hasLady || hasDup){
          legendHtml = '<div class="lgw-prev-legend">';
          if(hasNew)  legendHtml += '<span class="lgw-prev-badge lgw-prev-badge-new">1st game</span> First game this season &nbsp;';
          if(hasLady) legendHtml += '<span class="lgw-prev-badge lgw-prev-badge-lady">♀</span> Ladies player (marked with *) &nbsp;';
          if(hasDup)  legendHtml += '<span class="lgw-prev-badge lgw-prev-badge-dup">DUP</span> Same name on same team — please distinguish if two different people';
          legendHtml += '</div>';
        }

        var totalHtml = '';
        if(sc.home_total !== null || sc.away_total !== null || sc.home_points !== null){
          totalHtml = '<div class="lgw-prev-totals">'
            +'<span>Total shots: <strong>'+((sc.home_total!==null)?sc.home_total:'–')+' – '+((sc.away_total!==null)?sc.away_total:'–')+'</strong></span>'
            +(sc.home_points!==null ? ' &nbsp; Points: <strong>'+sc.home_points+' – '+sc.away_points+'</strong>' : '')
            +'</div>';
        }

        var adminLabel = isAdm ? '<div style="margin-bottom:6px;font-size:12px;color:#555">Submitting on behalf of: <strong>'
          +(submittedFor==='both'?'Both teams (auto-confirms)':submittedFor==='away'?esc(sc.away_team)+' (away)':esc(sc.home_team)+' (home)')
          +'</strong></div>' : '';

        var overlay = document.createElement('div');
        overlay.className = 'lgw-prev-overlay';
        overlay.innerHTML =
          '<div class="lgw-prev-dialog">'
          +'<h3 class="lgw-prev-title">📋 Confirm Scorecard</h3>'
          +'<div class="lgw-prev-match">'+esc(sc.home_team)+' v '+esc(sc.away_team)+'<span class="lgw-prev-meta">'+(sc.date||'')+' '+(sc.venue?'· '+sc.venue:'')+'</span></div>'
          +adminLabel
          +legendHtml
          +'<div class="lgw-prev-rinks">'+rinkHtml+'</div>'
          +totalHtml
          +(sc.submitter ? '<div style="font-size:11px;color:#888;margin-top:6px">Submitted by: '+esc(sc.submitter)+'</div>' : '')
          +'<div class="lgw-prev-actions">'
          +'<button class="lgw-btn lgw-btn-secondary lgw-prev-cancel">← Edit</button>'
          +'<button class="lgw-btn lgw-btn-primary lgw-prev-confirm">✅ Confirm &amp; Save</button>'
          +'</div>'
          +'</div>';

        document.body.appendChild(overlay);

        overlay.querySelector('.lgw-prev-cancel').addEventListener('click', function(){
          document.body.removeChild(overlay);
        });

        overlay.querySelector('.lgw-prev-confirm').addEventListener('click', function(){
          document.body.removeChild(overlay);
          doSave(sc, submittedFor);
        });
      }

      // ── Perform the actual save ───────────────────────────────────────────
      function doSave(sc, submittedFor) {
        saveBtn.disabled = true;
        saveBtn.textContent = '⏳ Saving…';
        showStatus(statusEl, '', '');

        var fd = new FormData();
        fd.append('action',         'lgw_save_scorecard');
        fd.append('nonce',          nonce);
        fd.append('scorecard',      JSON.stringify(sc));
        fd.append('submitted_for',  submittedFor);
        if (isAdm) {
          var skipSheetsEl = el.querySelector('input[name="lgw_modal_skip_sheets"]');
          if (skipSheetsEl && skipSheetsEl.checked) fd.append('skip_sheets', '1');
        }
        var xhr2 = new XMLHttpRequest();
        xhr2.open('POST', ajaxUrl);
        xhr2.onload = function(){
          saveBtn.disabled = false;
          var res = JSON.parse(xhr2.responseText || '{}');
          if(res.success){
            var msg = res.data.message || 'Scorecard saved.';
            if(res.data.division_unresolved){
              msg += ' ⚠️ Division name wasn\'t recognised — an admin will need to correct it.';
            }
            showStatus(statusEl, msg, 'ok');
            saveBtn.textContent = '✅ Saved';
            saveBtn.style.background = '#0a5a0a';
          } else {
            saveBtn.textContent = '💾 Save Scorecard';
            showStatus(statusEl, '❌ ' + (res.data || 'Save failed'), 'error');
          }
        };
        xhr2.onerror = function(){
          saveBtn.disabled = false;
          saveBtn.textContent = '💾 Save Scorecard';
          showStatus(statusEl, '❌ Network error — please try again', 'error');
        };
        xhr2.send(fd);
      }
    }
  };

  // ── Bind confirm/amend actions on an inline review block ─────────────────────
  // sc: the full scorecard data object
  // containerEl: the outer container (to replace with submit form on amend)
  // opts, home, away, date: passed through for amend flow
  function lgwBindInlineReview(reviewEl, sc, containerEl, opts, home, away, date) {
    var confirmBtn = reviewEl.querySelector('.lgw-inline-confirm');
    var amendBtn   = reviewEl.querySelector('.lgw-inline-amend');
    var statusEl   = reviewEl.querySelector('.lgw-inline-review-status');

    if (confirmBtn) {
      confirmBtn.addEventListener('click', function(){
        confirmBtn.disabled = true;
        confirmBtn.textContent = '⏳ Confirming…';
        var fd = new FormData();
        fd.append('action', 'lgw_confirm_scorecard');
        fd.append('nonce',  nonce);
        fd.append('id',     sc._id || '');
        var xhr = new XMLHttpRequest();
        xhr.open('POST', ajaxUrl);
        xhr.onload = function(){
          var res = JSON.parse(xhr.responseText || '{}');
          confirmBtn.disabled = false;
          if(res.success){
            confirmBtn.textContent = '✅ Confirmed';
            confirmBtn.style.background = '#0a5a0a';
            if(amendBtn) amendBtn.style.display = 'none';
            showStatus(statusEl, res.data.message || 'Scorecard confirmed. Thank you!', 'ok');
            // Update status badge
            var badge = containerEl.querySelector('.lgw-sc-badge');
            if(badge){ badge.className='lgw-sc-badge lgw-sc-badge-confirmed'; badge.textContent='✅ Confirmed by both clubs'; }
          } else {
            confirmBtn.textContent = '✅ Confirm — scores are correct';
            showStatus(statusEl, '❌ ' + (res.data || 'Could not confirm'), 'error');
          }
        };
        xhr.onerror = function(){
          confirmBtn.disabled = false;
          confirmBtn.textContent = '✅ Confirm — scores are correct';
          showStatus(statusEl, '❌ Network error — please try again', 'error');
        };
        xhr.send(fd);
      });
    }

    if (amendBtn) {
      amendBtn.addEventListener('click', function(){
        // Replace the scorecard view with the submission form, pre-filled with existing data
        containerEl.innerHTML = '<p class="lgw-notice lgw-notice-info" style="margin-bottom:12px">'
          +'✏️ Submit your version of the scores. If they differ from the submitted version, the result will be flagged for admin review.'
          +'</p>'
          +'<div id="lgw-sc-amend-form"></div>';
        var amendContainer = containerEl.querySelector('#lgw-sc-amend-form');
        if(amendContainer && typeof window.lgwOpenSubmitInModal === 'function'){
          window.lgwOpenSubmitInModal(amendContainer, {
            home:           home,
            away:           away,
            date:           date,
            division:       sc.division || opts.division || '',
            maxPts:         (opts.maxPts !== undefined && opts.maxPts !== null) ? opts.maxPts : 7,
            isAdmin:        opts.isAdmin,
            submissionMode: opts.submissionMode,
            authClub:       opts.authClub || authClub,
            context:        opts.context || sc.context || 'league',
            prefill:        sc, // pre-populate form with existing scorecard
          });
        }
      });
    }
  }

  // ── Compact login gate for confirming a pending scorecard from the modal ────────
  // Appended below the scorecard when status=pending and no club is authenticated.
  // On successful login re-fetches the scorecard so confirm/amend buttons appear.
  function lgwRenderModalLoginGate(containerEl, scData, opts, home, away, date) {
    var allClubs = (typeof lgwSubmit !== 'undefined' && lgwSubmit.clubs) ? lgwSubmit.clubs : [];
    var homeTeam = scData.home_team || home;
    var awayTeam = scData.away_team || away;

    // Filter to clubs involved in this fixture (same logic as the main login gate)
    var fixtureClubs = allClubs.filter(function(c){
      return lgwClubMatchesTeam(c, homeTeam) || lgwClubMatchesTeam(c, awayTeam);
    });
    var clubs = fixtureClubs.length ? fixtureClubs : allClubs;

    var clubOpts = clubs.map(function(c){
      return '<option value="'+esc(c)+'">'+esc(c)+'</option>';
    }).join('');

    var gateId   = 'lgw-modal-confirm-gate';
    var gateHtml = '<div id="'+gateId+'" class="lgw-submit-card" style="margin-top:16px;border-top:2px solid #e0e4f0;padding-top:16px">'
      +'<p style="margin:0 0 10px;font-weight:600;color:#1a2e5a">Are you the opposing club? Log in to confirm or amend these scores.</p>'
      +(clubs.length
        ? '<div class="lgw-form-row" style="margin-bottom:8px">'
          +'<label style="font-size:13px;margin-bottom:4px;display:block">Your club</label>'
          +'<select id="lgw-modal-gate-club" style="width:100%;padding:8px 10px;border:1px solid #d0d5e8;border-radius:6px;font-size:14px">'
          +'<option value="">— Select your club —</option>'
          +clubOpts
          +'</select>'
          +'</div>'
        : '<div class="lgw-form-row" style="margin-bottom:8px">'
          +'<label style="font-size:13px;margin-bottom:4px;display:block">Your club</label>'
          +'<input type="text" id="lgw-modal-gate-club" placeholder="Club name" style="width:100%;padding:8px 10px;border:1px solid #d0d5e8;border-radius:6px;font-size:14px">'
          +'</div>'
      )
      +'<div class="lgw-form-row" style="margin-bottom:12px">'
      +'<label style="font-size:13px;margin-bottom:4px;display:block">Passphrase</label>'
      +'<input type="password" id="lgw-modal-gate-pass" placeholder="Enter your club passphrase" style="width:100%;padding:8px 10px;border:1px solid #d0d5e8;border-radius:6px;font-size:14px">'
      +'</div>'
      +'<button class="lgw-btn lgw-btn-primary" id="lgw-modal-gate-submit" style="width:100%">🔓 Log in to confirm</button>'
      +'<p id="lgw-modal-gate-status" class="lgw-notice" style="display:none;margin-top:8px"></p>'
      +'</div>';

    containerEl.insertAdjacentHTML('beforeend', gateHtml);

    var gateEl    = containerEl.querySelector('#'+gateId);
    var clubEl    = gateEl.querySelector('#lgw-modal-gate-club');
    var passEl    = gateEl.querySelector('#lgw-modal-gate-pass');
    var submitBtn = gateEl.querySelector('#lgw-modal-gate-submit');
    var statusEl  = gateEl.querySelector('#lgw-modal-gate-status');

    // Allow Enter key in passphrase field
    passEl.addEventListener('keydown', function(e){
      if (e.key === 'Enter') submitBtn.click();
    });

    submitBtn.addEventListener('click', function(){
      var club = clubEl.value.trim();
      var pass = passEl.value.trim();
      if (!club) { showStatus(statusEl, '❌ Please select your club.', 'error'); return; }
      if (!pass) { showStatus(statusEl, '❌ Please enter your passphrase.', 'error'); return; }

      submitBtn.disabled = true;
      submitBtn.textContent = '⏳ Logging in…';

      var fd = new FormData();
      fd.append('action', 'lgw_check_pin');
      fd.append('nonce',  nonce);
      fd.append('club',   club);
      fd.append('pin',    pass);
      var xhr = new XMLHttpRequest();
      xhr.open('POST', ajaxUrl);
      xhr.onload = function(){
        var res;
        try { res = JSON.parse(xhr.responseText || '{}'); } catch(e) { res = {}; }
        submitBtn.disabled = false;
        if (res.success) {
          // Session is now authenticated — update module-level authClub and re-fetch
          authClub = club;
          // Re-fetch the scorecard with the authenticated club so confirm/amend renders
          window.lgwFetchScorecardOrSubmit(home, away, date, containerEl, {
            canSubmit:      opts.canSubmit,
            division:       opts.division || scData.division || '',
            maxPts:         (opts.maxPts !== undefined && opts.maxPts !== null) ? opts.maxPts : 7,
            isAdmin:        opts.isAdmin,
            submissionMode: opts.submissionMode,
            authClub:       club,
            context:        opts.context || '',
          });
        } else {
          submitBtn.textContent = '🔓 Log in to confirm';
          showStatus(statusEl, '❌ ' + (res.data || 'Incorrect passphrase — please try again.'), 'error');
        }
      };
      xhr.onerror = function(){
        submitBtn.disabled = false;
        submitBtn.textContent = '🔓 Log in to confirm';
        showStatus(statusEl, '❌ Network error — please try again.', 'error');
      };
      xhr.send(fd);
    });
  }

  // ── Helper: resolve club name from team name using lgwSubmit.clubs if available ─
  function lgwResolveClub(teamName) {
    var clubs = (typeof lgwSubmit !== 'undefined' && lgwSubmit.clubs) ? lgwSubmit.clubs : [];
    var upper = teamName.toUpperCase();
    var best = '', bestLen = 0;
    clubs.forEach(function(c){
      var cu = c.toUpperCase();
      if(upper === cu || (upper.indexOf(cu) === 0 && (upper.length === cu.length || upper[cu.length] === ' '))){
        if(c.length > bestLen){ best = c; bestLen = c.length; }
      }
    });
    return best;
  }

  // ── Helper: does a club name prefix-match a team name? ────────────────────────
  // Mirrors lgw_club_matches_team in PHP
  function lgwClubMatchesTeam(club, team) {
    if (!club || !team) return false;
    var c = club.toUpperCase().trim();
    var t = team.toUpperCase().trim();
    if(c === t) return true;
    if(t.indexOf(c) === 0){
      var rest = t.slice(c.length);
      return rest === '' || rest[0] === ' ';
    }
    return false;
  }

  // ── Helper: loose team name match for mismatch detection ─────────────────────
  // Returns true if two team name strings refer to the same team.
  // Handles: exact match, case differences, and common normalizations
  // (e.g. "U. Transport A" vs "Ulster Transport A", "Salisbury" vs "SALISBURY")
  function lgwTeamNamesMatch(a, b) {
    if(!a || !b) return false;
    var au = a.trim().toUpperCase();
    var bu = b.trim().toUpperCase();
    if(au === bu) return true;
    // Check if one starts with the other (catches "Salisbury A" vs "Salisbury")
    if(au.indexOf(bu) === 0 || bu.indexOf(au) === 0) return true;
    // Club prefix match: if the clubs extracted from each team are the same
    var clubA = lgwResolveClub(a);
    var clubB = lgwResolveClub(b);
    if(clubA && clubB && clubA.toUpperCase() === clubB.toUpperCase()) return true;
    return false;
  }

  // ── Player stats popover ─────────────────────────────────────────────────────
  // Resolves the best nonce available (scorecard page or widget page)
  function lgwGetPublicNonce() {
    if (typeof lgwSubmit !== 'undefined' && lgwSubmit.nonce) return lgwSubmit.nonce;
    if (typeof lgwData   !== 'undefined' && lgwData.scNonce)  return lgwData.scNonce;
    return '';
  }
  function lgwGetAjaxUrl() {
    if (typeof lgwSubmit !== 'undefined' && lgwSubmit.ajaxUrl) return lgwSubmit.ajaxUrl;
    if (typeof lgwData   !== 'undefined' && lgwData.ajaxUrl)   return lgwData.ajaxUrl;
    return '/wp-admin/admin-ajax.php';
  }

  // Resolve club badge URL from clubBadges map (mirrors widget logic)
  function lgwGetClubBadgeUrl(club) {
    var cb = (typeof lgwData !== 'undefined' && lgwData.clubBadges) ? lgwData.clubBadges : {};
    if (!club) return '';
    var upper = club.toUpperCase();
    var bestKey = '', bestImg = '';
    for (var key in cb) {
      var ku = key.toUpperCase();
      if (upper === ku || upper.indexOf(ku) === 0) {
        var rest = club.slice(key.length);
        if (rest === '' || rest[0] === ' ') {
          if (key.length > bestKey.length) { bestKey = key; bestImg = cb[key]; }
        }
      }
    }
    return bestImg;
  }

  // Create the singleton popover element
  var lgwPlayerPopover = null;
  function lgwEnsurePopover() {
    if (lgwPlayerPopover) return lgwPlayerPopover;
    var el = document.createElement('div');
    el.id = 'lgw-player-popover';
    el.className = 'lgw-player-popover';
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-label', 'Player stats');
    el.innerHTML = '<button class="lgw-player-popover-close" aria-label="Close">&times;</button>'
      + '<div class="lgw-player-popover-body"></div>';
    document.body.appendChild(el);
    el.querySelector('.lgw-player-popover-close').addEventListener('click', lgwHidePopover);
    // Close on outside click
    document.addEventListener('click', function(e) {
      if (lgwPlayerPopover && lgwPlayerPopover.classList.contains('lgw-player-popover-visible')) {
        if (!lgwPlayerPopover.contains(e.target) && !e.target.classList.contains('lgw-player-link')) {
          lgwHidePopover();
        }
      }
    });
    // Close on Escape
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') lgwHidePopover();
    });
    lgwPlayerPopover = el;
    return el;
  }

  function lgwHidePopover() {
    if (lgwPlayerPopover) {
      lgwPlayerPopover.classList.remove('lgw-player-popover-visible');
    }
  }

  function lgwShowPlayerStats(btn, playerName, club) {
    var pop = lgwEnsurePopover();
    var body = pop.querySelector('.lgw-player-popover-body');

    // Position popover near the button
    var rect = btn.getBoundingClientRect();
    var scrollY = window.pageYOffset || document.documentElement.scrollTop;
    var scrollX = window.pageXOffset || document.documentElement.scrollLeft;
    pop.style.top  = (rect.bottom + scrollY + 6) + 'px';
    pop.style.left = Math.max(8, Math.min(rect.left + scrollX, window.innerWidth - 280)) + 'px';

    // Show loading state
    var badgeUrl = lgwGetClubBadgeUrl(club);
    var badgeHtml = badgeUrl
      ? '<img class="lgw-player-popover-badge" src="'+badgeUrl+'" alt="'+esc(club)+'">'
      : '';
    body.innerHTML = '<div class="lgw-player-popover-header">'
      + badgeHtml
      + '<div class="lgw-player-popover-name">'+esc(playerName)+'</div>'
      + '</div>'
      + '<div class="lgw-player-popover-loading">Loading stats…</div>';

    pop.classList.add('lgw-player-popover-visible');

    var n  = lgwGetPublicNonce();
    var fd = new FormData();
    fd.append('action',      'lgw_get_player_stats');
    fd.append('nonce',       n);
    fd.append('player_name', playerName);
    fd.append('club',        club || '');
    var xhr = new XMLHttpRequest();
    xhr.open('POST', lgwGetAjaxUrl());
    xhr.onload = function() {
      var r;
      try { r = JSON.parse(xhr.responseText || '{}'); } catch(e) { r = {success:false}; }
      if (!r.success) {
        body.querySelector('.lgw-player-popover-loading').innerHTML
          = '<p class="lgw-player-popover-none">No stats found for this player yet.</p>';
        return;
      }
      var d = r.data;
      var played = d.played || 0;
      var html = '<div class="lgw-player-popover-header">'
        + badgeHtml
        + '<div>'
          + '<div class="lgw-player-popover-name">'+esc(d.name)+'</div>'
          + (d.club ? '<div class="lgw-player-popover-club">'+esc(d.club)+'</div>' : '')
        + '</div>'
        + '</div>';

      if (played > 0) {
        html += '<div class="lgw-player-popover-stats">'
          + '<div class="lgw-player-popover-stat lgw-pps-w"><span class="lgw-pps-val">'+d.won+'</span><span class="lgw-pps-lbl">Won</span></div>'
          + '<div class="lgw-player-popover-stat lgw-pps-d"><span class="lgw-pps-val">'+d.drawn+'</span><span class="lgw-pps-lbl">Drawn</span></div>'
          + '<div class="lgw-player-popover-stat lgw-pps-l"><span class="lgw-pps-val">'+d.lost+'</span><span class="lgw-pps-lbl">Lost</span></div>'
          + '<div class="lgw-player-popover-stat lgw-pps-p"><span class="lgw-pps-val">'+played+'</span><span class="lgw-pps-lbl">Played</span></div>'
          + '</div>';
        if (d.teams && d.teams.length) {
          html += '<div class="lgw-player-popover-teams"><span class="lgw-ppt-label">Teams this season:</span>'
            + d.teams.map(function(t){ return '<span class="lgw-ppt-team">'+esc(t)+'</span>'; }).join('')
            + '</div>';
        }
      } else {
        html += '<p class="lgw-player-popover-none">No appearances recorded this season yet.</p>';
      }
      body.innerHTML = html;
    };
    xhr.onerror = function() {
      body.querySelector('.lgw-player-popover-loading').innerHTML
        = '<p class="lgw-player-popover-none">Could not load stats — check your connection.</p>';
    };
    xhr.send(fd);
  }

  // Event delegation — handle player link clicks anywhere in the document
  document.addEventListener('click', function(e) {
    var btn = e.target.closest ? e.target.closest('.lgw-player-link') : null;
    if (!btn) return;
    e.stopPropagation();
    var playerName = btn.getAttribute('data-player') || '';
    var club       = btn.getAttribute('data-club')   || '';
    // Toggle: clicking the same open player hides the popover
    if (lgwPlayerPopover && lgwPlayerPopover.classList.contains('lgw-player-popover-visible')) {
      var currentName = lgwPlayerPopover.getAttribute('data-current-player');
      if (currentName === playerName) { lgwHidePopover(); return; }
    }
    if (lgwPlayerPopover) lgwPlayerPopover.setAttribute('data-current-player', playerName);
    lgwShowPlayerStats(btn, playerName, club);
  });

})();
