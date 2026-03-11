/* NIPGL Division Widget JS - v5.1 */
(function(){
  'use strict';

  var badges     = (typeof nipglData !== 'undefined' && nipglData.badges)     ? nipglData.badges     : {};
  var clubBadges = (typeof nipglData !== 'undefined' && nipglData.clubBadges) ? nipglData.clubBadges : {};
  var ajaxUrl    = (typeof nipglData !== 'undefined') ? nipglData.ajaxUrl : '/wp-admin/admin-ajax.php';

  var PRINT_ICON = '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M19 8H5c-1.66 0-3 1.34-3 3v6h4v4h12v-4h4v-6c0-1.66-1.34-3-3-3zm-3 11H8v-5h8v5zm3-7c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm-1-9H6v4h12V3z"/></svg>';

  // ── Dark mode — stored on :root so modal (on body) inherits variables ─────────
  var DM_KEY = 'nipgl_darkmode';

  function getDarkPref(){
    try{
      var v = localStorage.getItem(DM_KEY);
      if(v==='dark'||v==='light') return v;
    }catch(e){}
    return 'auto';
  }

  function applyThemeToRoot(pref){
    if(pref==='dark')       document.documentElement.setAttribute('data-nipgl-theme','dark');
    else if(pref==='light') document.documentElement.setAttribute('data-nipgl-theme','light');
    else                    document.documentElement.removeAttribute('data-nipgl-theme');
  }

  function updateDMBtn(btn, pref){
    btn.textContent = pref==='dark' ? '☀ Light' : pref==='light' ? '⟳ Auto' : '☾ Dark';
    btn.title = pref==='dark' ? 'Switch to light mode' : pref==='light' ? 'Follow device setting' : 'Switch to dark mode';
  }

  // Apply on load
  applyThemeToRoot(getDarkPref());

  // ── Badge lookup: exact → case-insensitive exact → club prefix (longest wins) ─
  function badgeImg(team, cls){
    cls = cls||'nipgl-badge';
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

    var colPtsH=0,colHTeam=2,colHScore=7,colAScore=9,colATeam=10,colPtsA=15;
    for(var h=i;h<Math.min(i+5,rows.length);h++){
      if(rows[h].join('').indexOf('HPts')!==-1){
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
        for(var x=colATeam+1;x<Math.min(colPtsA,r.length);x++){
          if(/^\d{1,2}:\d{2}$/.test((r[x]||'').trim())) timeNote=r[x].trim();
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
    +'.nipgl-badge{width:20px;height:20px;max-width:20px;max-height:20px;vertical-align:middle;margin-right:4px}'
    +'.nipgl-sponsor-img{max-height:48px;max-width:160px}'
    +'.date-hdr{background:#c0202a;color:#fff;padding:5px 10px;font-size:12px;font-weight:700;letter-spacing:.06em;margin-top:8px}'
    +'.fx-tbl{width:100%;border-collapse:collapse;margin-bottom:4px}'
    +'.fx-tbl td{padding:5px 8px;border-bottom:1px solid #d0d5e8;font-size:12px}'
    +'.fx-tbl tr:nth-child(even) td{background:#f0f2f8}'
    +'.fx-home{text-align:right;font-weight:600;width:35%}'
    +'.fx-away{text-align:left;font-weight:600;width:35%}'
    +'.fx-score{text-align:center;font-weight:700;white-space:nowrap;width:30%}'
    +'.fx-pts{font-size:11px;color:#999}'
    +'@media print{body{padding:0}}';

  function printFixturesData(groups, title){
    var html='<h2>'+(title||'Fixtures &amp; Results')+'</h2>';
    groups.forEach(function(g){
      html+='<div class="date-hdr">'+g.date+'</div>';
      html+='<table class="fx-tbl"><tbody>';
      g.matches.forEach(function(m){
        var scoreStr = m.played ? m.shotsHome+' – '+m.shotsAway : 'v';
        var ptsStr   = m.played ? '<span class="fx-pts">('+m.ptsHome+' – '+m.ptsAway+')</span>' : '';
        html+='<tr>'
          +'<td class="fx-home">'+badgeImg(m.homeTeam)+m.homeTeam+'</td>'
          +'<td class="fx-score">'+scoreStr+' '+ptsStr+'</td>'
          +'<td class="fx-away">'+badgeImg(m.awayTeam)+m.awayTeam+'</td>'
          +'</tr>';
      });
      html+='</tbody></table>';
    });
    return html;
  }

  function openPrintWindow(title, bodyHtml){
    var win=window.open('','_blank','width=900,height=700');
    win.document.write(
      '<!DOCTYPE html><html><head><title>'+(title||'NIPGL')+'</title>'
      // No Google Fonts link — removes 5-10s delay waiting for font load before print dialog
      +'<style>'+PRINT_CSS+'</style></head><body>'
      +bodyHtml
      +'<script>window.print();window.onafterprint=function(){window.close();};<\/script>'
      +'</body></html>'
    );
    win.document.close();
  }

  // ── Modal ─────────────────────────────────────────────────────────────────────
  var modalEl=null;

  function ensureModal(){
    if(modalEl) return;
    modalEl=document.createElement('div');
    modalEl.className='nipgl-modal-overlay';
    modalEl.innerHTML=
      '<div class="nipgl-modal">'
      +'<div class="nipgl-modal-head">'
      +'<div class="nipgl-modal-title"></div>'
      +'<div class="nipgl-modal-actions">'
      +'<button class="nipgl-modal-print" title="Print">'+PRINT_ICON+'</button>'
      +'<button class="nipgl-modal-close" title="Close">&times;</button>'
      +'</div></div>'
      +'<div class="nipgl-modal-body"></div>'
      +'</div>';
    document.body.appendChild(modalEl);
    modalEl.addEventListener('click',function(e){if(e.target===modalEl)closeModal();});
    document.addEventListener('keydown',function(e){if(e.key==='Escape')closeModal();});
    modalEl.querySelector('.nipgl-modal-close').addEventListener('click',closeModal);
    modalEl.querySelector('.nipgl-modal-print').addEventListener('click',function(){
      var titleEl=modalEl.querySelector('.nipgl-modal-title');
      var bodyEl =modalEl.querySelector('.nipgl-modal-body');
      var teamName=titleEl.querySelector('h2')?titleEl.querySelector('h2').textContent:'';
      var modalPrintCss=PRINT_CSS
        +'.nipgl-modal-title{display:block;margin-bottom:16px;padding-bottom:12px;border-bottom:3px solid #1a2e5a;overflow:hidden}'
        +'.nipgl-modal-title h2{margin:0 0 4px;font-size:20px;color:#1a2e5a}'
        +'.nipgl-modal-badge{width:40px !important;height:40px !important;max-width:40px !important;max-height:40px !important;object-fit:contain !important;float:left;margin-right:10px}'
        +'.modal-stat-bar{margin-bottom:16px;line-height:2.2}'
        +'.modal-stat{display:inline-block;background:#f0f2f8;border-radius:4px;padding:4px 10px;text-align:center;min-width:52px;margin:2px 4px 2px 0;vertical-align:top}'
        +'.modal-stat-val{display:block;font-size:16px;font-weight:700;color:#1a2e5a}'
        +'.modal-stat-lbl{display:block;font-size:10px;color:#666;text-transform:uppercase}'
        +'.modal-fix-table{width:100%;border-collapse:collapse}'
        +'.modal-fix-table td{padding:6px 8px;border-bottom:1px solid #d0d5e8}'
        +'.modal-fix-table th{background:#1a2e5a;color:#fff;padding:6px 8px;text-align:left;font-size:11px}'
        +'.modal-fix-table .nipgl-badge{width:18px !important;height:18px !important;max-width:18px !important;max-height:18px !important;vertical-align:middle;margin-right:4px}'
        +'.modal-result-lbl{font-size:10px;font-weight:700;border-radius:3px;padding:1px 4px;margin-left:4px}'
        +'.res .modal-result-lbl{background:#2a7a2a;color:#fff}'
        +'.drew .modal-result-lbl{background:#888;color:#fff}'
        +'.lost .modal-result-lbl{background:#c0202a;color:#fff}'
        +'img{max-width:40px !important;max-height:40px !important}'  // safety net for any other images
        +'.modal-fix-table img{max-width:18px !important;max-height:18px !important}';
      var win=window.open('','_blank','width=900,height=700');
      win.document.write(
        '<!DOCTYPE html><html><head><title>'+teamName+'</title>'
        +'<style>'+modalPrintCss+'</style></head><body>'
        +'<div class="nipgl-modal-title">'+titleEl.innerHTML+'</div>'
        +bodyEl.innerHTML
        +'<script>window.print();window.onafterprint=function(){window.close();};<\/script>'
        +'</body></html>'
      );
      win.document.close();
    });
  }

  function openModal(titleHtml, bodyHtml, sourceWidget){
    ensureModal();
    modalEl.querySelector('.nipgl-modal-title').innerHTML=titleHtml;
    modalEl.querySelector('.nipgl-modal-body').innerHTML=bodyHtml;
    // Copy scoped CSS variables from widget wrapper onto modal (modal lives on body)
    var wrap=sourceWidget&&sourceWidget.parentElement;
    if(wrap){
      var cs=getComputedStyle(wrap);
      ['--nipgl-navy','--nipgl-navy-mid','--nipgl-gold','--nipgl-bg','--nipgl-bg-alt',
       '--nipgl-bg-hover','--nipgl-tab-bg','--nipgl-pts'].forEach(function(v){
        var val=cs.getPropertyValue(v).trim();
        if(val) modalEl.style.setProperty(v,val);
      });
    }
    modalEl.classList.add('active');
    document.body.classList.add('nipgl-modal-open');
  }

  function closeModal(){
    if(modalEl) modalEl.classList.remove('active');
    document.body.classList.remove('nipgl-modal-open');
  }

  function stat(val,lbl){
    return '<div class="modal-stat"><div class="modal-stat-val">'+val+'</div><div class="modal-stat-lbl">'+lbl+'</div></div>';
  }

  function showTeamModal(teamName, allRows, sourceWidget){
    var teams =parseTableRows(allRows);
    var groups=parseFixtureGroups(allRows);
    var teamData=null;
    for(var t=0;t<teams.length;t++){
      if(teams[t].team.toUpperCase()===teamName.toUpperCase()){teamData=teams[t];break;}
    }

    var bdg=badgeImg(teamName,'nipgl-modal-badge');
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
        fixtureRows+='<tr class="modal-fx-row'+(rowCls?' '+rowCls:'')+'"'+scAttrs+'>'
          +'<td>'+g.date+'</td>'
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
          if(typeof window.nipglFetchScorecard === 'function'){
            window.nipglFetchScorecard(
              row.getAttribute('data-home'),
              row.getAttribute('data-away'),
              '',
              container
            );
            container.dataset.loaded = '1';
          } else {
            container.innerHTML='<p class="nipgl-sc-none">Scorecard feature not available.</p>';
          }
        });
      });
    }
  }

  // ── Render table ──────────────────────────────────────────────────────────────
  function renderTable(rows, promote, relegate){
    promote=promote||0; relegate=relegate||0;
    var teams=parseTableRows(rows);
    if(!teams.length) return '<div class="nipgl-status">Could not find league table in data.</div>';

    var total=teams.length, MAX_PTS=7;
    var gamesLeft={};
    teams.forEach(function(t){gamesLeft[t.team.toUpperCase()]=0;});
    parseFixtureGroups(rows).forEach(function(g){
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
      h+='<tr class="'+(rc.trim())+' nipgl-team-row" data-team="'+t.team+'">'
        +'<td class="cp">'+t.pos+'</td>'
        +'<td class="ct"><span class="nipgl-team-link">'+badgeImg(t.team)+t.team+'</span></td>'
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

  function renderFixtures(rows, filter){
    var groups=parseFixtureGroups(rows);
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
    if(!filtered.length) return '<div class="nipgl-status">No fixtures to display.</div>';
    var h='';
    filtered.forEach(function(g){
      h+='<div class="date-group"><div class="date-hdr">'+g.date+'</div>';
      g.matches.forEach(function(m){
        var pc=m.played?' played':'';
        var fxAttrs=m.played?' data-home="'+m.homeTeam.replace(/"/g,"&quot;")+'" data-away="'+m.awayTeam.replace(/"/g,"&quot;")+'" data-date="'+g.date.replace(/"/g,"&quot;")+'" title="Click to view full scorecard"':'';
        h+='<div class="fx-row'+pc+'"'+fxAttrs+'>'
          +'<div class="fx-ph">'+(m.played?m.ptsHome:'')+'</div>'
          +'<div class="fx-h"><span class="nipgl-team-link" data-team="'+m.homeTeam+'">'+badgeImg(m.homeTeam)+m.homeTeam+'</span></div>'
          +'<div class="fx-sc"><span class="fx-sb">'+m.shotsHome+'</span><span class="fx-sep">v</span><span class="fx-sb">'+m.shotsAway+'</span></div>'
          +'<div class="fx-a"><span class="nipgl-team-link" data-team="'+m.awayTeam+'">'+badgeImg(m.awayTeam)+m.awayTeam+'</span></div>'
          +'<div class="fx-pa">'+(m.played?m.ptsAway:'')+'</div>'
          +(m.timeNote?'<div class="fx-time">&#9200; '+m.timeNote+'</div>':'')
          +'</div>';
      });
      h+='</div>';
    });
    return h;
  }

  function filterBar(af){
    var h='<div class="fix-filter">';
    ['all','results','upcoming'].forEach(function(f){
      var cap=f.charAt(0).toUpperCase()+f.slice(1);
      h+='<button data-f="'+f+'"'+(af===f?' class="active"':'')+'>'+cap+'</button>';
    });
    return h+'</div>';
  }

  // ── Init widget ───────────────────────────────────────────────────────────────
  function initWidget(widget){
    var csvUrl   =widget.getAttribute('data-csv');
    var promote  =parseInt(widget.getAttribute('data-promote')  ||'0',10);
    var relegate =parseInt(widget.getAttribute('data-relegate') ||'0',10);
    var extraSponsors=[];
    try{extraSponsors=JSON.parse(widget.getAttribute('data-sponsors')||'[]');}catch(e){}

    var prev=widget.previousElementSibling;
    var divisionTitle=prev&&prev.classList.contains('nipgl-title')?prev.textContent.trim():'';

    // Dark mode toggle — cycles auto→dark→light→auto, stored on :root
    var dmBtn=document.createElement('button');
    dmBtn.className='nipgl-darkmode-btn';
    updateDMBtn(dmBtn,getDarkPref());
    dmBtn.addEventListener('click',function(){
      var cur=getDarkPref();
      var next=cur==='auto'?'dark':cur==='dark'?'light':'auto';
      try{localStorage.setItem(DM_KEY,next);}catch(e){}
      applyThemeToRoot(next);
      updateDMBtn(dmBtn,next);
    });
    var tabBar=widget.querySelector('.nipgl-tabs');
    if(tabBar) tabBar.appendChild(dmBtn);

    function sponsorBar(){
      if(!extraSponsors.length) return '';
      var sp=extraSponsors[Math.floor(Math.random()*extraSponsors.length)];
      if(!sp||!sp.image) return '';
      var img='<img src="'+sp.image+'" alt="'+(sp.name||'Sponsor')+'" class="nipgl-sponsor-img">';
      var inner=sp.url?'<a href="'+sp.url+'" target="_blank" rel="noopener">'+img+'</a>':img;
      return '<div class="nipgl-sponsor-bar nipgl-sponsor-secondary">'+inner+'</div>';
    }

    var activeFilter='all', allRows=null;
    var panels=widget.querySelectorAll('.nipgl-panel');
    var tabs=widget.querySelectorAll('.nipgl-tab');

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
      btn.className='nipgl-print-btn';
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
          var groups=parseFixtureGroups(allRows);
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
      widget.querySelectorAll('.nipgl-team-link').forEach(function(el){
        el.addEventListener('click',function(e){
          e.stopPropagation();
          var team=el.getAttribute('data-team')||el.closest('[data-team]').getAttribute('data-team');
          if(team&&allRows) showTeamModal(team,allRows,widget);
        });
      });
      // Played fixture rows — click to show scorecard
      widget.querySelectorAll('.fx-row.played[data-home]').forEach(function(row){
        row.style.cursor='pointer';
        row.addEventListener('click',function(e){
          if(e.target.classList.contains('nipgl-team-link')||e.target.closest('.nipgl-team-link')) return;
          showFixtureModal(
            row.getAttribute('data-home'),
            row.getAttribute('data-away'),
            row.getAttribute('data-date')
          );
        });
      });
    }

    function showFixtureModal(home, away, date){
      var titleHtml='<h2>'+home+' v '+away+'</h2>';
      var bodyHtml='<p class="nipgl-sc-date" style="font-size:12px;color:#999;margin:0 0 12px">'+date+'</p>'
        +'<hr class="nipgl-sc-divider">'
        +'<div class="nipgl-sc-title">Full Scorecard</div>'
        +'<div id="nipgl-sc-container"></div>';
      openModal(titleHtml, bodyHtml, widget);
      // Load scorecard async after modal opens
      var container=document.getElementById('nipgl-sc-container');
      if(container && typeof window.nipglFetchScorecard === 'function'){
        window.nipglFetchScorecard(home, away, date, container);
      } else if(container){
        container.innerHTML='<p class="nipgl-sc-none">Scorecard feature not loaded.</p>';
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
          if(fp) fp.innerHTML=filterBar(activeFilter)+renderFixtures(allRows,activeFilter);
          bindFilterBtns(); bindTeamLinks();
        });
      });
    }

    function showError(msg){
      panels.forEach(function(p){
        p.innerHTML='<div class="nipgl-status"><strong>Unable to load data.</strong><small>'+msg+'</small></div>';
      });
    }

    var proxyUrl=ajaxUrl+'?action=nipgl_csv&url='+encodeURIComponent(csvUrl);
    var xhr=new XMLHttpRequest();
    xhr.open('GET',proxyUrl);
    xhr.onload=function(){
      if(xhr.status===200&&xhr.responseText&&xhr.responseText.trim().length>10){
        allRows=parseCSV(xhr.responseText);
        var tp=getPanel('table'), fp=getPanel('fixtures');
        if(tp){
          tp.innerHTML=renderTable(allRows,promote,relegate)+sponsorBar();
          tp.insertBefore(makePrintBtn('table'),tp.firstChild);
        }
        if(fp){
          fp.innerHTML=filterBar(activeFilter)+renderFixtures(allRows,activeFilter);
          fp.insertBefore(makePrintBtn('fixtures'),fp.firstChild);
        }
        bindFilterBtns(); bindTeamLinks();
      } else {
        showError('Server returned status '+xhr.status);
      }
    };
    xhr.onerror=function(){showError('Network error.');};
    xhr.send();
  }

  function init(){
    document.querySelectorAll('.nipgl-w[data-csv]').forEach(function(w){initWidget(w);});
  }
  if(document.readyState==='loading'){
    document.addEventListener('DOMContentLoaded',init);
  } else {
    init();
  }
})();
