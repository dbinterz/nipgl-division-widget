/* LGW Division Widget JS - v5.3 */
(function(){
  'use strict';

  var badges     = (typeof lgwData !== 'undefined' && lgwData.badges)     ? lgwData.badges     : {};
  var clubBadges = (typeof lgwData !== 'undefined' && lgwData.clubBadges) ? lgwData.clubBadges : {};
  var ajaxUrl        = (typeof lgwData !== 'undefined') ? lgwData.ajaxUrl : '/wp-admin/admin-ajax.php';
  var scoreOverrides = (typeof lgwData !== 'undefined' && lgwData.scoreOverrides) ? lgwData.scoreOverrides : {};
  var playedDates    = (typeof lgwData !== 'undefined' && lgwData.playedDates)    ? lgwData.playedDates    : {};
  var recentResults  = (typeof lgwData !== 'undefined' && lgwData.recentResults)  ? lgwData.recentResults  : [];

  // ── Apply admin score overrides to parsed fixture groups ─────────────────────
  function applyScoreOverrides(groups, csvUrl){
    if(!csvUrl || !Object.keys(scoreOverrides).length) return groups;
    groups.forEach(function(g){
      g.matches.forEach(function(m){
        var key = csvUrl+'||'+g.date+'||'+m.homeTeam+'||'+m.awayTeam;
        var ov  = scoreOverrides[key];
        if(ov){
          if(ov.sh!=='') m.shotsHome = ov.sh;
          if(ov.sa!=='') m.shotsAway = ov.sa;
          if(ov.ph!=='') m.ptsHome   = ov.ph;
          if(ov.pa!=='') m.ptsAway   = ov.pa;
          if(ov.sh!==''||ov.sa!=='') m.played = true;
          m.overridden = true;
        }
      });
    });
    return groups;
  }

  var submissionMode = (typeof lgwData !== 'undefined' && lgwData.submissionMode) ? lgwData.submissionMode : 'open';
  var isAdmin        = (typeof lgwData !== 'undefined' && lgwData.isAdmin === '1');
  var widgetAuthClub = (typeof lgwData !== 'undefined' && lgwData.authClub) ? lgwData.authClub : '';

  var PRINT_ICON = '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M19 8H5c-1.66 0-3 1.34-3 3v6h4v4h12v-4h4v-6c0-1.66-1.34-3-3-3zm-3 11H8v-5h8v5zm3-7c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm-1-9H6v4h12V3z"/></svg>';

  // ── Dark mode — stored on :root so modal (on body) inherits variables ─────────
  var DM_KEY = 'lgw_darkmode';

  function getDarkPref(){
    try{
      var v = localStorage.getItem(DM_KEY);
      if(v==='dark'||v==='light') return v;
    }catch(e){}
    return 'auto';
  }

  function applyThemeToRoot(pref){
    if(pref==='dark')       document.documentElement.setAttribute('data-lgw-theme','dark');
    else if(pref==='light') document.documentElement.setAttribute('data-lgw-theme','light');
    else                    document.documentElement.removeAttribute('data-lgw-theme');
  }

  function updateDMBtn(btn, pref){
    btn.textContent = pref==='dark' ? '☀ Light' : pref==='light' ? '⟳ Auto' : '☾ Dark';
    btn.title = pref==='dark' ? 'Switch to light mode' : pref==='light' ? 'Follow device setting' : 'Switch to dark mode';
  }

  // Apply on load
  applyThemeToRoot(getDarkPref());

  // ── Badge lookup: exact → case-insensitive exact → club prefix (longest wins) ─
  function badgeImg(team, cls){
    cls = cls||'lgw-badge';
    // 1. Exact match
    if(badges[team]) return '<img class="'+cls+'" src="'+badges[team]+'" alt="'+team+'">';
    // 2. Case-insensitive exact match
    var upper = team.toUpperCase();
    for(var key in badges){
      if(key.toUpperCase() === upper) return '<img class="'+cls+'" src="'+badges[key]+'" alt="'+team+'">';
    }
    // 3. Club prefix match — case-insensitive, word-boundary aware, longest prefix wins
    var bestKey = '', bestImg = '';
    for(var club in clubBadges){
      var clubUpper = club.toUpperCase();
      if(upper === clubUpper || upper.indexOf(clubUpper) === 0){
        var rest = team.slice(club.length);
        if(rest === '' || rest[0] === ' '){
          if(club.length > bestKey.length){ bestKey = club; bestImg = clubBadges[club]; }
        }
      }
    }
    if(bestImg) return '<img class="'+cls+'" src="'+bestImg+'" alt="'+team+'">';
    return '';
  }

  // ── CSV parser ────────────────────────────────────────────────────────────────
  function parseCSV(text){
    return text.split('\n').map(function(line){
      line=line.replace(/\r$/,'');
      var cells=[], cur='', inQ=false;
      for(var i=0;i<line.length;i++){
        var c=line[i];
        if(c==='"'){inQ=!inQ;}
        else if(c===','&&!inQ){cells.push(cur.trim());cur='';}
        else{cur+=c;}
      }
      cells.push(cur.trim());
      return cells;
    });
  }

  function nonEmpty(row){ return row.some(function(c){return c!=='';}) }

  // ── Parse fixtures ────────────────────────────────────────────────────────────
  function parseFixtureGroups(rows){
    var i=0;
    while(i<rows.length && rows[i].join('').indexOf('FIXTURES')===-1) i++;
    i++;
    if(i>=rows.length) return [];

    var colPtsH=0,colHTeam=2,colHScore=7,colAScore=9,colATeam=10,colPtsA=15,colTime=-1;
    for(var h=i;h<Math.min(i+5,rows.length);h++){
      var rowJoined=rows[h].join('').toLowerCase();
      // New-style reference row: uses labels like 'homepts','home','home shots','awaypts','time'
      if(rowJoined.indexOf('homepts')!==-1){
        for(var c=0;c<rows[h].length;c++){
          var hv=rows[h][c].trim().toLowerCase();
          if(hv==='homepts')    colPtsH=c;
          if(hv==='home')       colHTeam=c;
          if(hv==='home shots') colHScore=c;
          if(hv==='away shots') colAScore=c;
          if(hv==='away')       colATeam=c;
          if(hv==='awaypts')    colPtsA=c;
          if(hv==='time')       colTime=c;
        }
        i=h+1; break;
      }
      // Legacy-style header row: HPts, HTeam, HScore, AScore, ATeam, APts
      if(rowJoined.indexOf('hpts')!==-1){
        for(var c=0;c<rows[h].length;c++){
          var hv=rows[h][c].trim();
          if(hv==='HPts')   colPtsH=c;
          if(hv==='HTeam')  colHTeam=c;
          if(hv==='HScore') colHScore=c;
          if(hv==='Ascore'||hv==='AScore') colAScore=c;
          if(hv==='ATeam')  colATeam=c;
          if(hv==='APts')   colPtsA=c;
        }
        i=h+1; break;
      }
    }

    var dateRe=/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun)\s+\d{1,2}-[A-Za-z]+-\d{4}$/;
    var groups=[], cur=null;
    while(i<rows.length){
      var r=rows[i];
      var first=(r[0]||r[1]||'').trim();
      if(dateRe.test(first)){
        cur={date:first,matches:[]};
        groups.push(cur);
        i++;
        while(i<rows.length && !nonEmpty(rows[i].slice(0,2)) && rows[i].join('').indexOf('Points')!==-1) i++;
        continue;
      }
      if(cur && nonEmpty(r)){
        var ptsHome  =(r[colPtsH]  ||'').trim();
        var homeTeam =(r[colHTeam] ||'').trim();
        var shotsHome=(r[colHScore]||'').trim();
        var shotsAway=(r[colAScore]||'').trim();
        var awayTeam =(r[colATeam] ||'').trim();
        var ptsAway  =(r[colPtsA]  ||'').trim();
        var timeNote='';
        if(colTime>=0){
          // Reference row provided a 'time' column — read directly, no scanning needed
          var tv=(r[colTime]||'').trim();
          if(/^\d{1,2}:\d{2}(:\d{2})?$/.test(tv)){
            timeNote=(tv.split(':').length>2)?tv.replace(/:\d{2}$/,''):tv;
          } else {
            var fv=parseFloat(tv);
            if(!isNaN(fv) && fv>0 && fv<1){
              var mins=Math.round(fv*1440);
              var hh=Math.floor(mins/60), mm=mins%60;
              timeNote=(hh<10?'0':'')+hh+':'+(mm<10?'0':'')+mm;
            }
          }
        } else {
          // No reference row — scan between awayTeam and awayPts (legacy fallback)
          for(var x=colATeam+1;x<colPtsA;x++){
            var tv=(r[x]||'').trim();
            if(/^\d{1,2}:\d{2}(:\d{2})?$/.test(tv)){
              timeNote=(tv.split(':').length>2)?tv.replace(/:\d{2}$/,''):tv;
              break;
            } else {
              var fv=parseFloat(tv);
              if(!isNaN(fv) && fv>=0.333 && fv<=0.938){
                var mins=Math.round(fv*1440);
                var hh=Math.floor(mins/60), mm=mins%60;
                timeNote=(hh<10?'0':'')+hh+':'+(mm<10?'0':'')+mm;
                break;
              }
            }
          }
        }
        if(homeTeam && awayTeam){
          var played=(shotsHome!=='0'||shotsAway!=='0'||ptsHome!=='0'||ptsAway!=='0');
          cur.matches.push({
            ptsHome:ptsHome,ptsAway:ptsAway,
            homeTeam:homeTeam,awayTeam:awayTeam,
            shotsHome:shotsHome,shotsAway:shotsAway,
            timeNote:timeNote,played:played
          });
        }
      }
      i++;
    }
    return groups;
  }

  // ── Parse league table ────────────────────────────────────────────────────────
  function parseTableRows(rows){
    var i=0;
    while(i<rows.length && rows[i].join('').indexOf('LEAGUE TABLE')===-1) i++;
    i++;
    while(i<rows.length && !nonEmpty(rows[i])) i++;
    while(i<rows.length && rows[i][0]!=='POS') i++;
    if(i>=rows.length) return [];

    // Detect column positions from header row
    var hdr=rows[i];
    var colPos=-1,colTeam=-1,colPl=-1,colPts=-1,colDiff=-1,colW=-1,colL=-1,colD=-1,colFor=-1,colAgn=-1;
    for(var c=0;c<hdr.length;c++){
      var v=(hdr[c]||'').trim().toUpperCase().replace(/\s+/g,'');
      if(v==='POS')                          colPos=c;
      else if(v==='TEAM')                    colTeam=c;
      else if(v==='PL'||v==='PLAYED')        colPl=c;
      else if(v==='PTS'||v==='POINTS')       colPts=c;
      else if(v==='+/-'||v==='DIFF')         colDiff=c;
      else if(v==='W'||v==='WON')            colW=c;
      else if(v==='L'||v==='LOST')           colL=c;
      else if(v==='D'||v==='DRAWN')          colD=c;
      else if(v==='FOR')                     colFor=c;
      else if(v==='AGAINST'||v==='AGN')      colAgn=c;
    }
    // Fallback to positional guesses if header detection fails
    if(colPos<0)  colPos=0;
    if(colTeam<0) colTeam=1;
    if(colPl<0)   colPl=5;
    if(colPts<0)  colPts=7;
    if(colDiff<0) colDiff=8;
    if(colW<0)    colW=9;
    if(colL<0)    colL=10;
    if(colD<0)    colD=11;
    if(colFor<0)  colFor=12;
    if(colAgn<0)  colAgn=14;

    i++;
    var teams=[];
    while(i<rows.length && nonEmpty(rows[i])){
      var r=rows[i];
      var pos=r[colPos], team=r[colTeam];
      if(pos && team && !isNaN(parseInt(pos,10))){
        teams.push({
          pos:parseInt(pos,10),  team:team,
          pl:parseInt(r[colPl]||'0',10),
          pts:parseFloat(r[colPts]||'0'),
          diff:r[colDiff]||'0',
          w:r[colW]||'0',  l:r[colL]||'0',  d:r[colD]||'0',
          f:r[colFor]||'0', a:r[colAgn]||'0'
        });
      }
      i++;
    }
    return teams;
  }

  // ── Print helpers ─────────────────────────────────────────────────────────────
  var PRINT_CSS =
    'body{font-family:Saira,Arial,sans-serif;padding:20px;color:#1a1a1a;font-size:13px}'
    +'h2{color:#1a2e5a;margin:0 0 12px}'
    +'table{width:100%;border-collapse:collapse}'
    +'th{background:#1a2e5a;color:#fff;padding:6px 8px;font-size:11px;text-align:center}'
    +'th.ct{text-align:left}td{padding:6px 8px;border-bottom:1px solid #d0d5e8;text-align:center}'
    +'td.ct{text-align:left;font-weight:600}td.cp{color:#999;width:36px}td.ck{font-weight:700;color:#8f1520}'
    +'tr:nth-child(even) td{background:#f0f2f8}'
    +'.row-promote-zone td:first-child,.row-promoted td:first-child{border-left:4px solid #2a7a2a}'
    +'.row-relegate-zone td:first-child,.row-relegated td:first-child{border-left:4px solid #c0202a}'
    +'.row-promote-zone td{background:#f0faf0}.row-promoted td{background:#c8edc8}'
    +'.row-relegate-zone td{background:#fff5f5}.row-relegated td{background:#f5c8c8}'
    +'.lg-legend{padding:6px 10px;font-size:11px;color:#666;border-top:1px solid #d0d5e8}'
    +'.lgw-badge{width:20px;height:20px;max-width:20px;max-height:20px;vertical-align:middle;margin-right:4px}'
    +'.lgw-sponsor-img{max-height:48px;max-width:160px}'
    +'.date-hdr{background:#c0202a;color:#fff;padding:5px 10px;font-size:12px;font-weight:700;letter-spacing:.06em;margin-top:8px}'
    +'.fx-tbl{width:100%;border-collapse:collapse;margin-bottom:4px}'
    +'.fx-tbl td{padding:5px 8px;border-bottom:1px solid #d0d5e8;font-size:12px}'
    +'.fx-tbl tr:nth-child(even) td{background:#f0f2f8}'
    +'.fx-home{text-align:right;font-weight:600;width:35%}'
    +'.fx-away{text-align:left;font-weight:600;width:35%}'
    +'.fx-score{text-align:center;font-weight:700;white-space:nowrap;width:30%}'
    +'.fx-pts{font-size:11px;color:#999}'
    +'@media print{body{padding:0}}'
    +'.fx-score-wrap{display:flex;flex-direction:column;align-items:center;gap:3px}'
    +'.fx-time-pill{display:inline-block;background:#1a2e5a;color:#e8b400;font-size:11px;font-weight:700;padding:2px 10px;border-radius:20px;letter-spacing:.04em}';

  function printFixturesData(groups, title){
    var html='<h2>'+(title||'Fixtures &amp; Results')+'</h2>';
    groups.forEach(function(g){
      html+='<div class="date-hdr">'+g.date+'</div>';
      html+='<table class="fx-tbl"><tbody>';
      g.matches.forEach(function(m){
        var scoreStr = m.played ? m.shotsHome+' – '+m.shotsAway : 'v';
        var ptsStr   = m.played ? '<span class="fx-pts">('+m.ptsHome+' – '+m.ptsAway+')</span>' : '';
        var printTimePill=m.timeNote?'<span class="fx-time-pill">&#9200; '+m.timeNote+'</span>':'';
        html+='<tr>'
          +'<td class="fx-home">'+badgeImg(m.homeTeam)+m.homeTeam+'</td>'
          +'<td class="fx-score"><div class="fx-score-wrap">'+scoreStr+' '+ptsStr+printTimePill+'</div></td>'
          +'<td class="fx-away">'+badgeImg(m.awayTeam)+m.awayTeam+'</td>'
          +'</tr>';
      });
      html+='</tbody></table>';
    });
    return html;
  }

  function openPrintWindow(title, bodyHtml, extraCss){
    var existing=document.getElementById('lgw-print-frame');
    if(existing) existing.parentNode.removeChild(existing);
    var iframe=document.createElement('iframe');
    iframe.id='lgw-print-frame';
    iframe.style.cssText='position:fixed;top:-9999px;left:-9999px;width:1px;height:1px;border:none;visibility:hidden';
    document.body.appendChild(iframe);
    var doc=iframe.contentDocument||iframe.contentWindow.document;
    doc.open();
    doc.write(
      '<!DOCTYPE html><html><head><title>'+(title||'LGW')+'</title>'
      +'<style>'+PRINT_CSS+(extraCss||'')+'</style></head><body>'
      +bodyHtml
      +'</body></html>'
    );
    doc.close();
    iframe.onload=function(){
      try{iframe.contentWindow.focus();iframe.contentWindow.print();}catch(e){window.print();}
    };
    try{iframe.contentWindow.focus();iframe.contentWindow.print();}catch(e){}
  }

  // ── Modal ─────────────────────────────────────────────────────────────────────
  var modalEl=null;

  function ensureModal(){
    if(modalEl) return;
    modalEl=document.createElement('div');
    modalEl.className='lgw-modal-overlay';
    modalEl.innerHTML=
      '<div class="lgw-modal">'
      +'<div class="lgw-modal-head">'
      +'<div class="lgw-modal-title"></div>'
      +'<div class="lgw-modal-actions">'
      +'<button class="lgw-modal-print" title="Print">'+PRINT_ICON+'</button>'
      +'<button class="lgw-modal-close" title="Close">&times;</button>'
      +'</div></div>'
      +'<div class="lgw-modal-body"></div>'
      +'</div>';
    document.body.appendChild(modalEl);
    modalEl.addEventListener('click',function(e){if(e.target===modalEl)closeModal();});
    document.addEventListener('keydown',function(e){if(e.key==='Escape')closeModal();});
    modalEl.querySelector('.lgw-modal-close').addEventListener('click',closeModal);
    modalEl.querySelector('.lgw-modal-print').addEventListener('click',function(){
      var titleEl=modalEl.querySelector('.lgw-modal-title');
      var bodyEl =modalEl.querySelector('.lgw-modal-body');
      var teamName=titleEl.querySelector('h2')?titleEl.querySelector('h2').textContent:'';
      var modalPrintCss=PRINT_CSS
        +'.lgw-modal-title{display:block;margin-bottom:16px;padding-bottom:12px;border-bottom:3px solid #1a2e5a;overflow:hidden}'
        +'.lgw-modal-title h2{margin:0 0 4px;font-size:20px;color:#1a2e5a}'
        +'.lgw-modal-badge{width:40px !important;height:40px !important;max-width:40px !important;max-height:40px !important;object-fit:contain !important;float:left;margin-right:10px}'
        +'.modal-stat-bar{margin-bottom:16px;line-height:2.2}'
        +'.modal-stat{display:inline-block;background:#f0f2f8;border-radius:4px;padding:4px 10px;text-align:center;min-width:52px;margin:2px 4px 2px 0;vertical-align:top}'
        +'.modal-stat-val{display:block;font-size:16px;font-weight:700;color:#1a2e5a}'
        +'.modal-stat-lbl{display:block;font-size:10px;color:#666;text-transform:uppercase}'
        +'.modal-fix-table{width:100%;border-collapse:collapse}'
        +'.modal-fix-table td{padding:6px 8px;border-bottom:1px solid #d0d5e8}'
        +'.modal-fix-table th{background:#1a2e5a;color:#fff;padding:6px 8px;text-align:left;font-size:11px}'
        +'.modal-fix-table .lgw-badge{width:18px !important;height:18px !important;max-width:18px !important;max-height:18px !important;vertical-align:middle;margin-right:4px}'
        +'.modal-result-lbl{font-size:10px;font-weight:700;border-radius:3px;padding:1px 4px;margin-left:4px}'
        +'.res .modal-result-lbl{background:#2a7a2a;color:#fff}'
        +'.drew .modal-result-lbl{background:#888;color:#fff}'
        +'.lost .modal-result-lbl{background:#c0202a;color:#fff}'
        +'img{max-width:40px !important;max-height:40px !important}'  // safety net for any other images
        +'.modal-fix-table img{max-width:18px !important;max-height:18px !important}';
      openPrintWindow(teamName,
        '<div class="lgw-modal-title">'+titleEl.innerHTML+'</div>'+bodyEl.innerHTML,
        modalPrintCss
      );
    });
  }

  function openModal(titleHtml, bodyHtml, sourceWidget){
    ensureModal();
    modalEl.querySelector('.lgw-modal-title').innerHTML=titleHtml;
    modalEl.querySelector('.lgw-modal-body').innerHTML=bodyHtml;
    // Copy scoped CSS variables from widget wrapper onto modal (modal lives on body)
    var wrap=sourceWidget&&sourceWidget.parentElement;
    if(wrap){
      var cs=getComputedStyle(wrap);
      ['--lgw-navy','--lgw-navy-mid','--lgw-gold','--lgw-bg','--lgw-bg-alt',
       '--lgw-bg-hover','--lgw-tab-bg','--lgw-pts'].forEach(function(v){
        var val=cs.getPropertyValue(v).trim();
        if(val) modalEl.style.setProperty(v,val);
      });
    }
    modalEl.classList.add('active');
    document.body.classList.add('lgw-modal-open');
  }

  function closeModal(){
    if(modalEl) modalEl.classList.remove('active');
    document.body.classList.remove('lgw-modal-open');
  }

  function stat(val,lbl){
    return '<div class="modal-stat"><div class="modal-stat-val">'+val+'</div><div class="modal-stat-lbl">'+lbl+'</div></div>';
  }

  function showTeamModal(teamName, allRows, sourceWidget, parseFn){
    parseFn = parseFn || parseFixtureGroups;
    var teams =parseTableRows(allRows);
    var groups=parseFn(allRows);
    var teamData=null;
    for(var t=0;t<teams.length;t++){
      if(teams[t].team.toUpperCase()===teamName.toUpperCase()){teamData=teams[t];break;}
    }

    var bdg=badgeImg(teamName,'lgw-modal-badge');
    var titleHtml=bdg+'<h2>'+teamName+'</h2>';

    var statsHtml='';
    if(teamData){
      statsHtml='<div class="modal-stat-bar">'
        +stat(teamData.pl,'Played')+stat(teamData.pts,'Points')
        +stat(teamData.w,'Won')+stat(teamData.d,'Drawn')+stat(teamData.l,'Lost')
        +stat(teamData.f,'For')+stat(teamData.a,'Against')+stat(teamData.diff,'+/-')
        +'</div>';
    }

    var fixtureRows='<table class="modal-fix-table"><thead><tr>'
      +'<th>Date</th><th>H/A</th><th>Opponent</th><th>Score</th><th>Pts</th><th></th>'
      +'</tr></thead><tbody>';
    var hasRows=false;

    groups.forEach(function(g){
      g.matches.forEach(function(m){
        var isHome=m.homeTeam.toUpperCase()===teamName.toUpperCase();
        var isAway=m.awayTeam.toUpperCase()===teamName.toUpperCase();
        if(!isHome&&!isAway) return;
        hasRows=true;
        var opponent=isHome?m.awayTeam:m.homeTeam;
        var ha=isHome?'H':'A';
        var myShots =isHome?m.shotsHome:m.shotsAway;
        var oppShots=isHome?m.shotsAway:m.shotsHome;
        var myPts   =isHome?m.ptsHome:m.ptsAway;
        var scoreStr=m.played?myShots+' - '+oppShots:'-';
        var rowCls='',resultLbl='';
        if(m.played){
          var p=parseInt(myPts,10);
          if(p>=4){rowCls='res';resultLbl='W';}
          else if(p===3){rowCls='drew';resultLbl='D';}
          else{rowCls='lost';resultLbl='L';}
        }
        var scRowId='sc-row-'+m.homeTeam.replace(/[^a-z0-9]/gi,'_')+'-'+m.awayTeam.replace(/[^a-z0-9]/gi,'_');
        var scAttrs=m.played
          ? ' data-home="'+m.homeTeam.replace(/"/g,'&quot;')+'" data-away="'+m.awayTeam.replace(/"/g,'&quot;')+'" data-scrowid="'+scRowId+'" title="Click to view scorecard"'
          : '';
        var timePill=(!m.played&&m.timeNote)?'<span class="modal-fx-time">&#9200; '+m.timeNote+'</span>':'';
        fixtureRows+='<tr class="modal-fx-row'+(rowCls?' '+rowCls:'')+'"'+scAttrs+'>'
          +'<td><div class="modal-fx-date">'+g.date+timePill+'</div></td>'
          +'<td style="text-align:center;font-weight:700;color:'+(isHome?'#1a2e5a':'#c0202a')+'">'+ha+'</td>'
          +'<td>'+badgeImg(opponent)+opponent+'</td>'
          +'<td style="text-align:center">'+scoreStr+'</td>'
          +'<td style="text-align:center;font-weight:700">'+(m.played?myPts:'')+'</td>'
          +'<td style="text-align:center">'+(rowCls?'<span class="modal-result-lbl">'+resultLbl+'</span>':'')
          +(m.played?' <span class="modal-sc-hint" title="View scorecard">&#x1F4CB;</span>':'')+'</td>'
          +'</tr>'
          +(m.played?'<tr class="modal-sc-row" id="'+scRowId+'" style="display:none"><td colspan="6"><div class="modal-sc-inline"></div></td></tr>':'');
      });
    });

    if(!hasRows) fixtureRows+='<tr><td colspan="6" style="text-align:center;color:#999">No fixtures found</td></tr>';
    fixtureRows+='</tbody></table>';
    openModal(titleHtml,statsHtml+fixtureRows,sourceWidget);

    // Bind scorecard click handlers on played rows in the team modal
    if(modalEl){
      modalEl.querySelectorAll('.modal-fx-row[data-home]').forEach(function(row){
        row.style.cursor='pointer';
        row.addEventListener('click', function(){
          var scRowId = row.getAttribute('data-scrowid');
          var scRow   = scRowId ? document.getElementById(scRowId) : null;
          if(!scRow) return;
          var isOpen  = scRow.style.display !== 'none';
          // Collapse any other open scorecard rows
          modalEl.querySelectorAll('.modal-sc-row').forEach(function(r){ r.style.display='none'; });
          if(isOpen){ return; } // toggle off if already open
          scRow.style.display='';
          var container = scRow.querySelector('.modal-sc-inline');
          if(!container) return;
          if(container.dataset.loaded){ return; } // already fetched
          if(typeof window.lgwFetchScorecard === 'function'){
            window.lgwFetchScorecard(
              row.getAttribute('data-home'),
              row.getAttribute('data-away'),
              '',
              container
            );
            container.dataset.loaded = '1';
          } else {
            container.innerHTML='<p class="lgw-sc-none">Scorecard feature not available.</p>';
          }
        });
      });
    }
  }

  // ── Render table ──────────────────────────────────────────────────────────────
  function renderTable(rows, promote, relegate, parseFn){
    parseFn = parseFn || parseFixtureGroups;
    promote=promote||0; relegate=relegate||0;
    var teams=parseTableRows(rows);
    if(!teams.length) return '<div class="lgw-status">Could not find league table in data.</div>';

    teams.sort(function(a,b){
      var pd=parseFloat(b.pts)-parseFloat(a.pts); if(pd!==0) return pd;
      return parseFloat(b.diff)-parseFloat(a.diff);
    });
    teams.forEach(function(t,i){t.pos=i+1;});

    var total=teams.length, MAX_PTS=7;
    var gamesLeft={};
    teams.forEach(function(t){gamesLeft[t.team.toUpperCase()]=0;});
    parseFn(rows).forEach(function(g){
      g.matches.forEach(function(m){
        if(!m.played){
          if(m.homeTeam.toUpperCase() in gamesLeft) gamesLeft[m.homeTeam.toUpperCase()]++;
          if(m.awayTeam.toUpperCase() in gamesLeft) gamesLeft[m.awayTeam.toUpperCase()]++;
        }
      });
    });

    function getZone(idx){
      if(promote>0 && idx<promote)          return 'promote';
      if(relegate>0 && idx>=total-relegate) return 'relegate';
      return '';
    }
    function isClinched(idx){
      var zone=getZone(idx); if(!zone) return false;
      var myPts=teams[idx].pts;
      if(zone==='promote'){
        var ch=teams[promote]; if(!ch) return true;
        return (myPts-ch.pts)>((gamesLeft[ch.team.toUpperCase()]||0)*MAX_PTS);
      }
      var safe=teams[total-relegate-1]; if(!safe) return true;
      return (safe.pts-myPts)>((gamesLeft[teams[idx].team.toUpperCase()]||0)*MAX_PTS);
    }

    var h='<div class="tbl-wrap"><table class="lg"><thead><tr>'
      +'<th class="cp">Pos</th><th class="ct">Team</th>'
      +'<th>Pl</th><th>Pts</th><th>+/-</th><th>W</th><th>L</th><th>D</th><th>For</th><th>Agn</th>'
      +'</tr></thead><tbody>';

    teams.forEach(function(t,idx){
      var zone=getZone(idx),clinched=isClinched(idx);
      var bt='';
      if(promote>0  && idx===promote)        bt=' zone-border-top';
      if(relegate>0 && idx===total-relegate) bt=' zone-border-top';
      var rc='';
      if(zone==='promote')  rc=clinched?' row-promoted':' row-promote-zone';
      if(zone==='relegate') rc=clinched?' row-relegated':' row-relegate-zone';
      rc+=bt;
      h+='<tr class="'+(rc.trim())+' lgw-team-row" data-team="'+t.team+'">'
        +'<td class="cp">'+t.pos+'</td>'
        +'<td class="ct"><span class="lgw-team-link">'+badgeImg(t.team)+t.team+'</span></td>'
        +'<td>'+t.pl+'</td><td class="ck">'+t.pts+'</td><td>'+t.diff+'</td>'
        +'<td>'+t.w+'</td><td>'+t.l+'</td><td>'+t.d+'</td>'
        +'<td>'+t.f+'</td><td>'+t.a+'</td></tr>';
    });

    h+='</tbody></table></div>';
    if(promote>0||relegate>0){
      h+='<div class="lg-legend">';
      if(promote>0)  h+='<span class="lg-key lg-key-promote"></span>▲ Promotion&nbsp;&nbsp;';
      if(relegate>0) h+='<span class="lg-key lg-key-relegate"></span>▼ Relegation';
      h+='</div>';
    }
    return h;
  }

  // ── Render fixtures ───────────────────────────────────────────────────────────
  function parseDate(str){
    try{var p=str.split(' ')[1].split('-');return new Date(p[1]+' '+p[0]+' '+p[2]);}catch(e){return null;}
  }

  function renderFixtures(rows, filter, parseFn){
    parseFn = parseFn || parseFixtureGroups;
    var groups=parseFn(rows);
    var now=new Date(), filtered=groups;
    if(filter==='results'){
      filtered=groups.map(function(g){
        return{date:g.date,matches:g.matches.filter(function(m){return m.played;})};
      }).filter(function(g){return g.matches.length;});
    } else if(filter==='upcoming'){
      filtered=groups.map(function(g){
        var d=parseDate(g.date); if(!d) return{date:g.date,matches:[]};
        return{date:g.date,matches:g.matches.filter(function(m){return !m.played&&d>=now;})};
      }).filter(function(g){return g.matches.length;});
    }
    if(!filtered.length) return '<div class="lgw-status">No fixtures to display.</div>';
    var h='';
    filtered.forEach(function(g){
      h+='<div class="date-group"><div class="date-hdr">'+g.date+'</div>';
      g.matches.forEach(function(m){
        var pc=m.played?' played':'';
        var fxAttrs=m.played
          ?' data-home="'+m.homeTeam.replace(/"/g,"&quot;")+'" data-away="'+m.awayTeam.replace(/"/g,"&quot;")+'" data-date="'+g.date.replace(/"/g,"&quot;")+'" title="Click to view full scorecard"'
          :' data-home="'+m.homeTeam.replace(/"/g,"&quot;")+'" data-away="'+m.awayTeam.replace(/"/g,"&quot;")+'" data-date="'+g.date.replace(/"/g,"&quot;")+'"';
        // Show date-played annotation if game was played on a different date
        var pdKey=(m.homeTeam+'||'+m.awayTeam+'||'+g.date).toLowerCase();
        var playedOn=playedDates[pdKey]||'';
        var playedNote=playedOn?'<div class="fx-played-date">📅 Played '+playedOn+'</div>':'';
        h+='<div class="fx-row'+pc+'"'+fxAttrs+'>'
          +'<div class="fx-ph">'+(m.played?m.ptsHome:'')+'</div>'
          +'<div class="fx-h"><span class="lgw-team-link" data-team="'+m.homeTeam+'">'+badgeImg(m.homeTeam)+m.homeTeam+'</span></div>'
          +'<div class="fx-sc"><span class="fx-sb">'+m.shotsHome+'</span><span class="fx-sep">v</span><span class="fx-sb">'+m.shotsAway+'</span></div>'
          +'<div class="fx-a"><span class="lgw-team-link" data-team="'+m.awayTeam+'">'+badgeImg(m.awayTeam)+m.awayTeam+'</span></div>'
          +'<div class="fx-pa">'+(m.played?m.ptsAway:'')+'</div>'
          +(m.timeNote?'<div class="fx-time"><span>&#9200; '+m.timeNote+'</span></div>':'')
          +playedNote
          +'</div>';
      });
      h+='</div>';
    });
    return h;
  }

  // ── Results ticker: horizontal scrolling strip of latest results ─────────────────────
  // Filters to results for a specific division (current season only — PHP already
  // constrains recentResults to the active season before passing to JS).
  function renderResultsTicker(divisionName) {
    if (!recentResults || !recentResults.length) return '';
    // Filter to the current division only (case-insensitive trim match)
    var divNorm = divisionName ? divisionName.trim().toLowerCase() : '';
    var filtered = divNorm
      ? recentResults.filter(function(r) {
          return r.division && r.division.trim().toLowerCase() === divNorm;
        })
      : recentResults;
    if (!filtered.length) return '';
    var items = filtered.map(function(r) {
      var ht  = (r.home_total  !== null && r.home_total  !== undefined) ? r.home_total  : '?';
      var at  = (r.away_total  !== null && r.away_total  !== undefined) ? r.away_total  : '?';
      var pts = (r.home_points !== null && r.home_points !== undefined &&
                 r.away_points !== null && r.away_points !== undefined)
        ? '<span class="lgw-ticker-pts">(' + r.home_points + '–' + r.away_points + ' pts)</span>'
        : '';
      var dt  = r.date ? '<span class="lgw-ticker-date">' + r.date + '</span>' : '';
      return '<span class="lgw-ticker-item">'
        + badgeImg(r.home_team, 'lgw-ticker-badge') + r.home_team
        + '<strong class="lgw-ticker-score"> ' + ht + ' – ' + at + ' </strong>'
        + badgeImg(r.away_team, 'lgw-ticker-badge') + r.away_team
        + pts + dt
        + '</span>';
    });
    // Duplicate content so CSS animation loops seamlessly
    var inner = items.join('<span class="lgw-ticker-sep">●</span>');
    inner = inner + '<span class="lgw-ticker-sep"> </span>' + inner;
    return '<div class="lgw-results-ticker" aria-label="Latest results" role="marquee">'
      + '<div class="lgw-ticker-label">Latest Results</div>'
      + '<div class="lgw-ticker-track">'
      + '<div class="lgw-ticker-scroll">' + inner + '</div>'
      + '</div>'
      + '</div>';
  }

  function filterBar(af){

    var h='<div class="fix-filter">';
    ['all','results','upcoming'].forEach(function(f){
      var cap=f.charAt(0).toUpperCase()+f.slice(1);
      h+='<button data-f="'+f+'"'+(af===f?' class="active"':'')+'>'+cap+'</button>';
    });
    return h+'</div>';
  }

  // ── Init widget ─────────────────────────────────────────────────────────────────
  function initWidget(widget){
    // -- Results ticker -- injected per widget, inside the wrap, below sponsor/title,
    //    above the lgw-w element. Filtered to this division's results only.
    var divisionName = widget.getAttribute('data-division') || '';
    var tickerHtml = renderResultsTicker(divisionName);
    if (tickerHtml) {
      var wrap = widget.closest('.lgw-widget-wrap') || widget.parentElement;
      if (wrap) {
        var tickerEl = document.createElement('div');
        tickerEl.innerHTML = tickerHtml;
        var ticker = tickerEl.firstChild;
        // Insert inside the wrap, immediately before the lgw-w widget div
        wrap.insertBefore(ticker, widget);
      }
    }
    var csvUrl   =widget.getAttribute('data-csv');
    var promote  =parseInt(widget.getAttribute('data-promote')  ||'0',10);
    var relegate =parseInt(widget.getAttribute('data-relegate') ||'0',10);
    var maxPts   =parseInt(widget.getAttribute('data-maxpts')   ||'7',10);
    var extraSponsors=[];
    try{extraSponsors=JSON.parse(widget.getAttribute('data-sponsors')||'[]');}catch(e){}

    // Season switcher — seasons data encoded on data-seasons attribute
    var seasonsData=[];
    try{
      var raw=widget.getAttribute('data-seasons');
      if(raw) seasonsData=JSON.parse(raw);
    }catch(e){}
    // activeCsvUrl tracks which CSV is currently loaded (may change on season switch)
    var activeCsvUrl=csvUrl;
    // currentSeasonEntry: the entry in seasonsData whose active:true flag is set (= live season)
    var currentSeasonEntry=null;
    for(var si=0;si<seasonsData.length;si++){
      if(seasonsData[si].active){currentSeasonEntry=seasonsData[si];break;}
    }
    var selectedSeasonId=currentSeasonEntry?currentSeasonEntry.id:'';

    // Read division title from data-division attr (set by PHP from shortcode title).
    // Previously used previousElementSibling but that broke when the ticker was
    // injected between the .lgw-title element and the widget.
    var divisionTitle = widget.getAttribute('data-division') || '';

    // Dark mode toggle — cycles auto→dark→light→auto, stored on :root
    var dmBtn=document.createElement('button');
    dmBtn.className='lgw-darkmode-btn';
    updateDMBtn(dmBtn,getDarkPref());
    dmBtn.addEventListener('click',function(){
      var cur=getDarkPref();
      var next=cur==='auto'?'dark':cur==='dark'?'light':'auto';
      try{localStorage.setItem(DM_KEY,next);}catch(e){}
      applyThemeToRoot(next);
      updateDMBtn(dmBtn,next);
    });
    var tabBar=widget.querySelector('.lgw-tabs');
    if(tabBar) tabBar.appendChild(dmBtn);

    // ── Admin view toggle ────────────────────────────────────────────────────
    // Lets admins preview the widget as a regular visitor (no submit controls)
    var viewAsAdmin = true; // starts in admin mode for admins
    if(isAdmin && tabBar){
      var viewToggleBtn = document.createElement('button');
      viewToggleBtn.className = 'lgw-darkmode-btn lgw-view-toggle-btn';
      viewToggleBtn.title = 'Switch to visitor view (hide admin controls)';
      viewToggleBtn.textContent = '\uD83D\uDC41 Admin view';
      viewToggleBtn.addEventListener('click', function(){
        viewAsAdmin = !viewAsAdmin;
        if(viewAsAdmin){
          widget.classList.remove('lgw-visitor-preview');
          viewToggleBtn.textContent = '\uD83D\uDC41 Admin view';
          viewToggleBtn.title = 'Switch to visitor view (hide admin controls)';
        } else {
          widget.classList.add('lgw-visitor-preview');
          viewToggleBtn.textContent = '\uD83D\uDC41 Visitor view';
          viewToggleBtn.title = 'Switch back to admin view';
        }
      });
      tabBar.appendChild(viewToggleBtn);
    }

    function sponsorBar(){
      if(!extraSponsors.length) return '';
      var sp=extraSponsors[Math.floor(Math.random()*extraSponsors.length)];
      if(!sp||!sp.image) return '';
      var img='<img src="'+sp.image+'" alt="'+(sp.name||'Sponsor')+'" class="lgw-sponsor-img">';
      var inner=sp.url?'<a href="'+sp.url+'" target="_blank" rel="noopener">'+img+'</a>':img;
      return '<div class="lgw-sponsor-bar lgw-sponsor-secondary">'+inner+'</div>';
    }

    var activeFilter='all', allRows=null;
    var parseFxGroups=parseFixtureGroups;
    var panels=widget.querySelectorAll('.lgw-panel');
    var tabs=widget.querySelectorAll('.lgw-tab');

    tabs.forEach(function(tab){
      tab.addEventListener('click',function(){
        tabs.forEach(function(t){t.classList.remove('active');});
        panels.forEach(function(p){p.classList.remove('active');});
        tab.classList.add('active');
        var name=tab.getAttribute('data-tab');
        for(var i=0;i<panels.length;i++){
          if(panels[i].getAttribute('data-panel')===name){panels[i].classList.add('active');break;}
        }
      });
    });

    function getPanel(name){
      for(var i=0;i<panels.length;i++){
        if(panels[i].getAttribute('data-panel')===name) return panels[i];
      }
      return null;
    }

    function makePrintBtn(tabName){
      var btn=document.createElement('button');
      btn.className='lgw-print-btn';
      btn.innerHTML=PRINT_ICON+'Print';
      btn.title='Print this view';
      btn.addEventListener('click',function(){
        if(tabName==='table'){
          var tp=getPanel('table');
          openPrintWindow(divisionTitle,
            '<h2>'+divisionTitle+'</h2>'+(tp?tp.innerHTML:'')
          );
        } else {
          // Fixtures: use dedicated renderer for reliable mobile print
          var groups=parseFxGroups(allRows);
          var now=new Date();
          if(activeFilter==='results'){
            groups=groups.map(function(g){
              return{date:g.date,matches:g.matches.filter(function(m){return m.played;})};
            }).filter(function(g){return g.matches.length;});
          } else if(activeFilter==='upcoming'){
            groups=groups.map(function(g){
              var d=parseDate(g.date); if(!d) return{date:g.date,matches:[]};
              return{date:g.date,matches:g.matches.filter(function(m){return !m.played&&d>=now;})};
            }).filter(function(g){return g.matches.length;});
          }
          openPrintWindow(divisionTitle, printFixturesData(groups, divisionTitle));
        }
      });
      return btn;
    }

    function bindTeamLinks(){
      widget.querySelectorAll('.lgw-team-link').forEach(function(el){
        el.addEventListener('click',function(e){
          e.stopPropagation();
          var team=el.getAttribute('data-team')||el.closest('[data-team]').getAttribute('data-team');
          if(team&&allRows) showTeamModal(team,allRows,widget,parseFxGroups);
        });
      });
      // Played fixture rows — click to show scorecard
      widget.querySelectorAll('.fx-row.played[data-home]').forEach(function(row){
        row.style.cursor='pointer';
        row.addEventListener('click',function(e){
          if(e.target.classList.contains('lgw-team-link')||e.target.closest('.lgw-team-link')) return;
          showFixtureModal(
            row.getAttribute('data-home'),
            row.getAttribute('data-away'),
            row.getAttribute('data-date')
          );
        });
      });
      // Unplayed fixture rows — always bind; canSubmit re-evaluated at click time
      // so the view toggle takes effect without rebinding
      var canShowUnplayed = submissionMode !== 'disabled' && (submissionMode !== 'admin_only' || isAdmin);
      if(canShowUnplayed){
        widget.querySelectorAll('.fx-row:not(.played)[data-home]').forEach(function(row){
          row.style.cursor='pointer';
          row.title='Submit scorecard for this fixture';
          row.addEventListener('click',function(e){
            if(e.target.classList.contains('lgw-team-link')||e.target.closest('.lgw-team-link')) return;
            // Respect view toggle: if admin is previewing as visitor, suppress submit
            var effectiveAdmin = isAdmin && viewAsAdmin;
            if(submissionMode === 'admin_only' && !effectiveAdmin) return;
            showUnplayedFixtureModal(
              row.getAttribute('data-home'),
              row.getAttribute('data-away'),
              row.getAttribute('data-date'),
              divisionTitle
            );
          });
        });
      }
    }

    function showFixtureModal(home, away, date){
      var effectiveAdmin = isAdmin && viewAsAdmin; // respects view toggle
      var titleHtml='<h2>'+home+' v '+away+'</h2>';
      var bodyHtml='<p class="lgw-sc-date" style="font-size:12px;color:#999;margin:0 0 12px">'+date+'</p>'
        +'<hr class="lgw-sc-divider">'
        +'<div class="lgw-sc-title">Full Scorecard</div>'
        +'<div id="lgw-sc-container"><p class="lgw-sc-loading">Loading…</p></div>';
      openModal(titleHtml, bodyHtml, widget);
      var container=document.getElementById('lgw-sc-container');
      if(!container) return;

      // Determine if submission can be offered
      var canSubmit = submissionMode !== 'disabled' && (submissionMode !== 'admin_only' || effectiveAdmin);

      if(typeof window.lgwFetchScorecardOrSubmit === 'function'){
        window.lgwFetchScorecardOrSubmit(home, away, date, container, {
          canSubmit: canSubmit,
          division: divisionTitle,
          maxPts: maxPts,
          isAdmin: effectiveAdmin,
          submissionMode: submissionMode,
          authClub: widgetAuthClub,
        });
      } else if(typeof window.lgwFetchScorecard === 'function'){
        window.lgwFetchScorecard(home, away, date, container);
      } else {
        container.innerHTML='<p class="lgw-sc-none">Scorecard feature not loaded.</p>';
      }
    }

    // ── Unplayed fixture modal — submission entry point ───────────────────────
    function showUnplayedFixtureModal(home, away, date, division){
      var effectiveAdmin = isAdmin && viewAsAdmin; // respects view toggle
      // Determine if submission should be offered
      var canSubmit = false;
      if(submissionMode === 'admin_only' && effectiveAdmin) canSubmit = true;
      if(submissionMode === 'open') canSubmit = true;

      var titleHtml = '<h2>'+home+' v '+away+'</h2>';

      if(!canSubmit){
        var bodyHtml = '<p class="lgw-sc-date" style="font-size:12px;color:#999;margin:0 0 8px">'+date+'</p>'
          + (division ? '<p style="font-size:12px;color:#999;margin:0 0 12px">'+division+'</p>' : '')
          + '<p class="lgw-sc-none">No scorecard submitted yet.</p>';
        openModal(titleHtml, bodyHtml, widget);
        return;
      }

      var bodyHtml = '<p class="lgw-sc-date" style="font-size:12px;color:#999;margin:0 0 8px">'+date+'</p>'
        + (division ? '<p style="font-size:12px;color:#999;margin:0 0 12px">'+division+'</p>' : '')
        + '<hr class="lgw-sc-divider">'
        + '<div id="lgw-sc-modal-submit"></div>';

      openModal(titleHtml, bodyHtml, widget);

      var container = document.getElementById('lgw-sc-modal-submit');
      if(!container) return;

      if(typeof window.lgwOpenSubmitInModal === 'function'){
        window.lgwOpenSubmitInModal(container, {
          home: home,
          away: away,
          date: date,
          division: division || '',
          maxPts: maxPts,
          isAdmin: effectiveAdmin,
          submissionMode: submissionMode,
          authClub: widgetAuthClub,
        });
      } else {
        container.innerHTML='<p class="lgw-sc-none">Scorecard submission not available.</p>';
      }
    }

    function bindFilterBtns(){
      widget.querySelectorAll('.fix-filter button').forEach(function(b){
        b.addEventListener('click',function(){
          activeFilter=b.getAttribute('data-f');
          widget.querySelectorAll('.fix-filter button').forEach(function(x){
            x.classList.toggle('active',x.getAttribute('data-f')===activeFilter);
          });
          var fp=getPanel('fixtures');
          if(fp) fp.innerHTML=filterBar(activeFilter)+renderFixtures(allRows,activeFilter,parseFxGroups);
          bindFilterBtns(); bindTeamLinks();
        });
      });
    }

    function showError(msg){
      panels.forEach(function(p){
        p.innerHTML='<div class="lgw-status lgw-status-error">⚠️ <strong>Unable to load data.</strong> <small>'+msg+'</small></div>';
      });
    }

    // ── Season switcher ───────────────────────────────────────────────────────
    if(seasonsData.length>1){
      var switcher=document.createElement('div');
      switcher.className='lgw-season-switcher';
      renderSwitcher();
      var tabBar2=widget.querySelector('.lgw-tabs');
      if(tabBar2) widget.insertBefore(switcher,tabBar2);
    }

    function renderSwitcher(){
      if(!switcher) return;
      var sel=document.createElement('select');
      sel.className='lgw-season-select';
      sel.setAttribute('aria-label','Select season');
      for(var i=0;i<seasonsData.length;i++){
        var s=seasonsData[i];
        var opt=document.createElement('option');
        opt.value=s.id;
        opt.textContent=s.label;
        opt.setAttribute('data-csv-url',s.csv_url);
        if(s.id===selectedSeasonId) opt.selected=true;
        sel.appendChild(opt);
      }
      sel.addEventListener('change',function(){
        var opt=sel.options[sel.selectedIndex];
        var sid=opt.value;
        var scsv=opt.getAttribute('data-csv-url');
        if(sid===selectedSeasonId) return;
        selectedSeasonId=sid;
        activeCsvUrl=scsv;
        var isLive=currentSeasonEntry&&(sid===currentSeasonEntry.id);
        loadSeason(scsv,isLive);
      });
      switcher.innerHTML='';
      switcher.appendChild(sel);
    }

    // ── loadSeason — fetch a CSV URL and re-render both panels ────────────────
    // isLive: true = apply score overrides + enable scorecard submission click.
    function loadSeason(url,isLive){
      panels.forEach(function(p){
        p.innerHTML='<div class="lgw-status">Loading&hellip;</div>';
      });
      activeFilter='all';
      var proxyUrl2=ajaxUrl+'?action=lgw_csv&url='+encodeURIComponent(url);
      var xhr2=new XMLHttpRequest();
      xhr2.open('GET',proxyUrl2);
      xhr2.onload=function(){
        if(xhr2.status===200&&xhr2.responseText&&xhr2.responseText.trim().length>10){
          allRows=parseCSV(xhr2.responseText);
          if(isLive){
            parseFxGroups=function(rows){ return applyScoreOverrides(parseFixtureGroups(rows), url); };
          } else {
            // Past season — no overrides, no submission
            parseFxGroups=function(rows){ return parseFixtureGroups(rows); };
          }
          var tp=getPanel('table'), fp=getPanel('fixtures');
          if(tp){
            tp.innerHTML=renderTable(allRows,promote,relegate,parseFxGroups)+sponsorBar();
            tp.insertBefore(makePrintBtn('table'),tp.firstChild);
            if(!isLive) addArchiveBanner(tp);
          }
          if(fp){
            fp.innerHTML=filterBar(activeFilter)+renderFixtures(allRows,activeFilter,parseFxGroups);
            fp.insertBefore(makePrintBtn('fixtures'),fp.firstChild);
            if(!isLive) addArchiveBanner(fp);
          }
          bindFilterBtns();
          // For past seasons: fixture click still opens scorecard modal (historical data)
          // For live season: full scorecard + submission behaviour
          bindTeamLinks();
          if(isLive){
            bindFixtureClicks();
          } else {
            bindFixtureClicksReadOnly();
          }
        } else {
          var msg='Could not load season data — please try refreshing.';
          try{ var j=JSON.parse(xhr2.responseText); if(j&&j.error) msg=j.error; }catch(e){}
          showError(msg);
        }
      };
      xhr2.onerror=function(){ showError('Network error — please check your connection and try again.'); };
      xhr2.send();
    }

    function addArchiveBanner(panel){
      var banner=document.createElement('div');
      banner.className='lgw-archive-banner';
      banner.textContent='📁 Archived season — read only';
      panel.insertBefore(banner,panel.firstChild);
    }

    // ── Fixture row click helpers — separated so season switching can swap ────
    function bindFixtureClicks(){
      // Alias for consistency — fixture row clicks are handled inside bindTeamLinks
      bindTeamLinks();
    }

    function bindFixtureClicksReadOnly(){
      // Past seasons: same modal behaviour — scorecards are looked up by home/away/date
      bindTeamLinks();
    }

    // ── Initial data load ─────────────────────────────────────────────────────
    var proxyUrl=ajaxUrl+'?action=lgw_csv&url='+encodeURIComponent(csvUrl);
    var xhr=new XMLHttpRequest();
    xhr.open('GET',proxyUrl);
    xhr.onload=function(){
      if(xhr.status===200&&xhr.responseText&&xhr.responseText.trim().length>10){
        allRows=parseCSV(xhr.responseText);
        parseFxGroups=function(rows){ return applyScoreOverrides(parseFixtureGroups(rows), csvUrl); };
        var tp=getPanel('table'), fp=getPanel('fixtures');
        if(tp){
          tp.innerHTML=renderTable(allRows,promote,relegate,parseFxGroups)+sponsorBar();
          tp.insertBefore(makePrintBtn('table'),tp.firstChild);
        }
        if(fp){
          fp.innerHTML=filterBar(activeFilter)+renderFixtures(allRows,activeFilter,parseFxGroups);
          fp.insertBefore(makePrintBtn('fixtures'),fp.firstChild);
        }
        bindFilterBtns(); bindTeamLinks(); bindFixtureClicks();
      } else {
        var msg = 'Could not load league data — please try refreshing the page.';
        try {
          var json = JSON.parse(xhr.responseText);
          if (json && json.error) msg = json.error;
        } catch(e) {}
        showError(msg);
      }
    };
    xhr.onerror=function(){ showError('Network error — please check your connection and try again.'); };
    xhr.send();
  }

  function init(){
    document.querySelectorAll('.lgw-w[data-csv]').forEach(function(w){initWidget(w);});
  }
  if(document.readyState==='loading'){
    document.addEventListener('DOMContentLoaded',init);
  } else {
    init();
  }
})();
