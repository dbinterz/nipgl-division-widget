/* NIPGL Scorecard JS - v5.3 */
(function(){
  'use strict';

  var ajaxUrl  = (typeof nipglSubmit !== 'undefined') ? nipglSubmit.ajaxUrl  : '/wp-admin/admin-ajax.php';
  var nonce    = (typeof nipglSubmit !== 'undefined') ? nipglSubmit.nonce    : '';
  var authClub = (typeof nipglSubmit !== 'undefined') ? nipglSubmit.authClub : '';

  // ── Utility ───────────────────────────────────────────────────────────────────
  function qs(sel, ctx)  { return (ctx||document).querySelector(sel); }
  function qsa(sel, ctx) { return (ctx||document).querySelectorAll(sel); }

  function showStatus(el, msg, type) {
    if (!el) return;
    el.textContent   = msg;
    el.className     = 'nipgl-notice nipgl-notice-' + (type||'info');
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
    xhr.onerror = function(){ cb({success:false, data:'Network error'}); };
    xhr.send(fd);
  }

  // ── Club login ────────────────────────────────────────────────────────────────
  var pinSubmit = qs('#nipgl-pin-submit');
  var pinInput  = qs('#nipgl-pin-input');
  var clubSel   = qs('#nipgl-club-select');

  function attemptLogin() {
    var club = clubSel ? clubSel.value : '';
    var pin  = pinInput ? pinInput.value.trim() : '';
    if (!club) { showStatus(qs('#nipgl-pin-error'), 'Please select your club', 'error'); return; }
    if (!pin)  { showStatus(qs('#nipgl-pin-error'), 'Please enter your PIN',   'error'); return; }
    if (pinSubmit) setLoading(pinSubmit, 'Login', true);

    post('nipgl_check_pin', {club: club, pin: pin}, function(res){
      if (pinSubmit) setLoading(pinSubmit, 'Login', false);
      if (res.success) {
        authClub = res.data.club;
        qs('#nipgl-pin-gate').style.display   = 'none';
        qs('#nipgl-submit-form').style.display = '';
        var nameEl = qs('#nipgl-club-name');
        if (nameEl) nameEl.textContent = authClub;
        showPending(res.data.pending || []);
      } else {
        showStatus(qs('#nipgl-pin-error'), res.data || 'Incorrect club or PIN', 'error');
      }
    });
  }

  if (pinSubmit) pinSubmit.addEventListener('click', attemptLogin);
  if (pinInput)  pinInput.addEventListener('keydown', function(e){ if(e.key==='Enter') attemptLogin(); });

  // ── Logout ────────────────────────────────────────────────────────────────────
  var logoutBtn = qs('#nipgl-logout');
  if (logoutBtn) {
    logoutBtn.addEventListener('click', function(){
      post('nipgl_logout', {}, function(){
        authClub = '';
        qs('#nipgl-pin-gate').style.display    = '';
        qs('#nipgl-submit-form').style.display = 'none';
        if (clubSel)   clubSel.value   = '';
        if (pinInput)  pinInput.value  = '';
        qs('#nipgl-pending-wrap').style.display = 'none';
      });
    });
  }

  // ── Pending confirmations ─────────────────────────────────────────────────────
  function showPending(pending) {
    var wrap = qs('#nipgl-pending-wrap');
    var list = qs('#nipgl-pending-list');
    if (!wrap || !list) return;
    if (!pending || !pending.length) { wrap.style.display = 'none'; return; }
    wrap.style.display = '';
    list.innerHTML = '';
    pending.forEach(function(p){
      var div = document.createElement('div');
      div.className = 'nipgl-pending-item';
      div.innerHTML =
        '<div class="nipgl-pending-match">'
        + '<strong>' + esc(p.home_team) + '</strong> v <strong>' + esc(p.away_team) + '</strong>'
        + '<span class="nipgl-pending-date">' + esc(p.date) + '</span>'
        + '</div>'
        + '<div class="nipgl-pending-by">Submitted by: ' + esc(p.submitted_by) + '</div>'
        + '<div class="nipgl-pending-actions">'
        + '<button class="nipgl-btn nipgl-btn-sm nipgl-btn-view" data-id="'+p.id+'">View Scorecard</button>'
        + '</div>'
        + '<div class="nipgl-pending-detail" id="nipgl-detail-'+p.id+'" style="display:none"></div>';
      list.appendChild(div);
    });

    // Bind view buttons
    list.querySelectorAll('.nipgl-btn-view').forEach(function(btn){
      btn.addEventListener('click', function(){
        var id     = btn.getAttribute('data-id');
        var detail = qs('#nipgl-detail-' + id);
        if (!detail) return;
        if (detail.style.display !== 'none') { detail.style.display = 'none'; btn.textContent = 'View Scorecard'; return; }
        btn.textContent = '⏳ Loading…';
        var xhr = new XMLHttpRequest();
        xhr.open('GET', ajaxUrl + '?action=nipgl_get_scorecard_by_id&id=' + id + '&_=' + Date.now());
        xhr.onload = function(){
          var res = JSON.parse(xhr.responseText || '{}');
          btn.textContent = 'Hide Scorecard';
          if (res.success) {
            detail.style.display = '';
            detail.innerHTML = renderScorecardReview(res.data, id);
            bindReviewActions(detail, id, res.data);
          } else {
            detail.style.display = '';
            detail.innerHTML = '<p class="nipgl-notice nipgl-notice-error">Could not load scorecard.</p>';
          }
        };
        xhr.send();
      });
    });
  }

  // ── Scorecard review (confirm / amend) ────────────────────────────────────────
  function renderScorecardReview(sc, id) {
    var h = '<div class="nipgl-sc-review">';
    h += nipglRenderScorecardHtml(sc);
    h += '<div class="nipgl-review-actions">';
    h += '<button class="nipgl-btn nipgl-btn-primary" data-action="confirm" data-id="'+id+'">✅ Confirm — scores are correct</button>';
    h += '<button class="nipgl-btn nipgl-btn-secondary" data-action="amend"  data-id="'+id+'">✏️ Amend — I have different scores</button>';
    h += '</div>';
    h += '<p class="nipgl-review-status" style="display:none"></p>';
    h += '</div>';
    return h;
  }

  function bindReviewActions(container, id, sc) {
    var confirmBtn = container.querySelector('[data-action="confirm"]');
    var amendBtn   = container.querySelector('[data-action="amend"]');
    var statusEl   = container.querySelector('.nipgl-review-status');

    if (confirmBtn) {
      confirmBtn.addEventListener('click', function(){
        setLoading(confirmBtn, '✅ Confirm — scores are correct', true);
        post('nipgl_confirm_scorecard', {id: id}, function(res){
          setLoading(confirmBtn, '✅ Confirm — scores are correct', false);
          if (res.success) {
            showStatus(statusEl, res.data.message, 'ok');
            confirmBtn.style.display = 'none';
            amendBtn.style.display   = 'none';
            // Remove from pending list
            var item = container.closest('.nipgl-pending-item');
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
        var form = qs('#nipgl-scorecard-form');
        if (form) form.scrollIntoView({behavior:'smooth', block:'start'});
      });
    }
  }

  // ── Submission method tabs ────────────────────────────────────────────────────
  qsa('.nipgl-stab').forEach(function(btn){
    btn.addEventListener('click', function(){
      qsa('.nipgl-stab').forEach(function(b){ b.classList.remove('active'); });
      qsa('.nipgl-stab-panel').forEach(function(p){ p.classList.remove('active'); });
      btn.classList.add('active');
      var panel = qs('.nipgl-stab-panel[data-panel="'+btn.getAttribute('data-tab')+'"]');
      if (panel) panel.classList.add('active');
    });
  });

  // ── Photo upload ──────────────────────────────────────────────────────────────
  var photoInput    = qs('#nipgl-photo-input');
  var photoTrigger  = qs('#nipgl-photo-trigger');
  var photoDrop     = qs('#nipgl-photo-drop');
  var photoPreview  = qs('#nipgl-photo-preview');
  var parsePhotoBtn = qs('#nipgl-parse-photo');
  var photoStatus   = qs('#nipgl-parse-photo-status');
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
      fd.append('action', 'nipgl_parse_photo');
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
  var excelInput    = qs('#nipgl-excel-input');
  var parseExcelBtn = qs('#nipgl-parse-excel');
  var excelStatus   = qs('#nipgl-parse-excel-status');

  if (excelInput) excelInput.addEventListener('change', function(){ if(excelInput.files[0] && parseExcelBtn) parseExcelBtn.style.display = ''; });
  if (parseExcelBtn) {
    parseExcelBtn.addEventListener('click', function(){
      if (!excelInput || !excelInput.files[0]) return;
      var lbl = 'Read Spreadsheet';
      setLoading(parseExcelBtn, lbl, true);
      showStatus(excelStatus, 'Reading spreadsheet…', 'info');
      var fd = new FormData();
      fd.append('action', 'nipgl_parse_excel');
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
    setVal('sc-date',        sc.date       || '');
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
      var hs = qs('.nipgl-score-home[data-rink="'+r+'"]');
      var as = qs('.nipgl-score-away[data-rink="'+r+'"]');
      if(hs) hs.value = rk.home_score !== null ? rk.home_score : '';
      if(as) as.value = rk.away_score !== null ? rk.away_score : '';
    });
    var form = qs('#nipgl-scorecard-form');
    if(form) form.scrollIntoView({behavior:'smooth', block:'start'});
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
        home_score: pn((qs('.nipgl-score-home[data-rink="'+r+'"]')||{}).value),
        away_score: pn((qs('.nipgl-score-away[data-rink="'+r+'"]')||{}).value),
      });
    }
    return sc;
  }
  function pn(v){ if(v===''||v==null) return null; var n=parseFloat(v); return isNaN(n)?null:n; }

  // ── Save scorecard ────────────────────────────────────────────────────────────
  var saveBtn    = qs('#nipgl-save-scorecard');
  var saveStatus = qs('#nipgl-save-status');
  var clearBtn   = qs('#nipgl-clear-form');

  if (saveBtn) {
    saveBtn.addEventListener('click', function(){
      var sc = collectForm();
      if (!sc.home_team || !sc.away_team) {
        showStatus(saveStatus, '❌ Please enter both home and away team names', 'error'); return;
      }
      setLoading(saveBtn, 'Save Scorecard', true);
      showStatus(saveStatus, 'Saving…', 'info');
      post('nipgl_save_scorecard', {scorecard: JSON.stringify(sc)}, function(res){
        setLoading(saveBtn, 'Save Scorecard', false);
        if (res.success) {
          var type = res.data.status === 'confirmed' ? 'ok' : res.data.status === 'disputed' ? 'error' : 'ok';
          showStatus(saveStatus, res.data.message, type);
        } else {
          showStatus(saveStatus, '❌ ' + (res.data || 'Save failed'), 'error');
        }
      });
    });
  }

  if (clearBtn) {
    clearBtn.addEventListener('click', function(){
      qsa('input[type=text], input[type=number]', qs('#nipgl-scorecard-form')).forEach(function(el){ el.value=''; });
      showStatus(saveStatus, '', '');
    });
  }

  // ── Scorecard display (called from nipgl-widget.js) ───────────────────────────
  window.nipglFetchScorecard = function(home, away, date, containerEl) {
    containerEl.innerHTML = '<p class="nipgl-sc-loading">Loading scorecard…</p>';
    var xhr = new XMLHttpRequest();
    xhr.open('GET', ajaxUrl+'?action=nipgl_get_scorecard'
      +'&home='+encodeURIComponent(home)
      +'&away='+encodeURIComponent(away)
      +'&date='+encodeURIComponent(date)+'&_='+Date.now());
    xhr.onload = function(){
      var res = JSON.parse(xhr.responseText || '{}');
      if (res.success && res.data) {
        containerEl.innerHTML = nipglRenderScorecardHtml(res.data);
      } else {
        containerEl.innerHTML = '<p class="nipgl-sc-none">No scorecard submitted yet.</p>';
      }
    };
    xhr.onerror = function(){ containerEl.innerHTML = ''; };
    xhr.send();
  };

  // ── Scorecard HTML renderer (shared by modal display and review) ──────────────
  function nipglRenderScorecardHtml(sc) {
    var status = sc['_status'] || 'pending';
    var h = '<div class="nipgl-sc-full">';

    // Status badge
    var badges = {
      pending:   '<span class="nipgl-sc-badge nipgl-sc-badge-pending">⏳ Awaiting confirmation</span>',
      confirmed: '<span class="nipgl-sc-badge nipgl-sc-badge-confirmed">✅ Confirmed by both clubs</span>',
      disputed:  '<span class="nipgl-sc-badge nipgl-sc-badge-disputed">⚠️ Result under review</span>',
    };
    h += (badges[status] || '');

    // Meta
    h += '<div class="nipgl-sc-meta">';
    if (sc.division) h += '<span class="nipgl-sc-div">'+esc(sc.division)+'</span>';
    if (sc.venue)    h += '<span class="nipgl-sc-venue">'+esc(sc.venue)+'</span>';
    if (sc.date)     h += '<span class="nipgl-sc-date">'+esc(sc.date)+'</span>';
    h += '</div>';

    // Rinks
    (sc.rinks || []).forEach(function(rk){
      h += '<div class="nipgl-sc-rink">';
      h += '<div class="nipgl-sc-rink-hdr">Rink '+rk.rink+'</div>';
      h += '<div class="nipgl-sc-rink-body">';
      h += '<div class="nipgl-sc-players nipgl-sc-players-home">';
      (rk.home_players||[]).forEach(function(p){ if(p) h+='<div class="nipgl-sc-player">'+esc(p)+'</div>'; });
      h += '</div>';
      h += '<div class="nipgl-sc-scores">';
      h += '<span class="nipgl-sc-score'+(rk.home_score>rk.away_score?' nipgl-sc-win':'')+'">'+
           (rk.home_score!==null?rk.home_score:'–')+'</span>';
      h += '<span class="nipgl-sc-sep">–</span>';
      h += '<span class="nipgl-sc-score'+(rk.away_score>rk.home_score?' nipgl-sc-win':'')+'">'+
           (rk.away_score!==null?rk.away_score:'–')+'</span>';
      h += '</div>';
      h += '<div class="nipgl-sc-players nipgl-sc-players-away">';
      (rk.away_players||[]).forEach(function(p){ if(p) h+='<div class="nipgl-sc-player">'+esc(p)+'</div>'; });
      h += '</div>';
      h += '</div></div>';
    });

    // Totals
    if (sc.home_total !== null || sc.away_total !== null) {
      h += '<div class="nipgl-sc-totals">';
      h += '<div class="nipgl-sc-total-row">';
      h += '<span class="nipgl-sc-total-lbl">Total Shots</span>';
      h += '<span class="nipgl-sc-total-val'+(sc.home_total>sc.away_total?' nipgl-sc-win':'')+'">'+
           (sc.home_total!==null?sc.home_total:'–')+'</span>';
      h += '<span class="nipgl-sc-sep">–</span>';
      h += '<span class="nipgl-sc-total-val'+(sc.away_total>sc.home_total?' nipgl-sc-win':'')+'">'+
           (sc.away_total!==null?sc.away_total:'–')+'</span>';
      h += '</div>';
      if (sc.home_points !== null || sc.away_points !== null) {
        h += '<div class="nipgl-sc-total-row nipgl-sc-points-row">';
        h += '<span class="nipgl-sc-total-lbl">Points</span>';
        h += '<span class="nipgl-sc-total-val nipgl-sc-pts'+(sc.home_points>sc.away_points?' nipgl-sc-win':'')+'">'+
             (sc.home_points!==null?sc.home_points:'–')+'</span>';
        h += '<span class="nipgl-sc-sep">–</span>';
        h += '<span class="nipgl-sc-total-val nipgl-sc-pts'+(sc.away_points>sc.home_points?' nipgl-sc-win':'')+'">'+
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
