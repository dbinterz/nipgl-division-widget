/* NIPGL Division Widget JS - loaded by WordPress via wp_enqueue_script */
(function(){
  'use strict';

  var badges = (typeof nipglData !== 'undefined' && nipglData.badges) ? nipglData.badges : {};
  var ajaxUrl = (typeof nipglData !== 'undefined') ? nipglData.ajaxUrl : '/wp-admin/admin-ajax.php';

  function badgeImg(team) {
    // Try exact match first, then case-insensitive
    if (badges[team]) {
      return '<img class="nipgl-badge" src="' + badges[team] + '" alt="' + team + '">';
    }
    var upper = team.toUpperCase();
    for (var key in badges) {
      if (key.toUpperCase() === upper) {
        return '<img class="nipgl-badge" src="' + badges[key] + '" alt="' + team + '">';
      }
    }
    return '';
  }

  function parseCSV(text){
    return text.split('\n').map(function(line){
      line = line.replace(/\r$/,'');
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

  function renderTable(rows, promote, relegate){
    promote  = promote  || 0;
    relegate = relegate || 0;

    var i=0;
    while(i<rows.length && rows[i].join('').indexOf('LEAGUE TABLE')===-1) i++;
    i++;
    while(i<rows.length && !nonEmpty(rows[i])) i++;
    while(i<rows.length && rows[i][0]!=='POS') i++;
    if(i>=rows.length) return '<div class="nipgl-status">Could not find league table in data.</div>';
    i++;

    // Collect all team rows first so we can do zone maths
    var teams=[];
    var j=i;
    while(j<rows.length && nonEmpty(rows[j])){
      var r=rows[j];
      var pos=r[0], team=r[1];
      if(pos && team && !isNaN(parseInt(pos,10))){
        teams.push({
          pos:  parseInt(pos,10),
          team: team,
          pl:   parseInt(r[5]||r[2]||'0',10),
          pts:  parseInt(r[7]||r[3]||'0',10),
          diff: r[8]||r[4]||'0',
          w:    r[9]||'0', l:r[10]||'0', d:r[11]||'0',
          f:    r[12]||'0', a:r[14]||r[13]||'0'
        });
      }
      j++;
    }

    var total = teams.length;
    var MAX_PTS = 7;

    // Work out games remaining per team from fixtures data
    // Count unplayed rows per team name
    var gamesLeft={};
    teams.forEach(function(t){ gamesLeft[t.team.toUpperCase()]=0; });

    // Scan fixtures section for unplayed matches
    var fi=0;
    while(fi<rows.length && rows[fi].join('').indexOf('FIXTURES')===-1) fi++;
    fi++;
    var colHTeam=2, colATeam=10, colHScore=7, colAScore=9;
    // Try to find header row for column positions
    for(var h=fi;h<Math.min(fi+5,rows.length);h++){
      if(rows[h].join('').indexOf('HPts')!==-1){
        for(var c=0;c<rows[h].length;c++){
          var hv=rows[h][c].trim();
          if(hv==='HTeam')  colHTeam=c;
          if(hv==='ATeam')  colATeam=c;
          if(hv==='HScore') colHScore=c;
          if(hv==='Ascore'||hv==='AScore') colAScore=c;
        }
        fi=h+1; break;
      }
    }
    var dateRe=/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun)\s+\d{1,2}-[A-Za-z]+-\d{4}$/;
    for(var fi2=fi;fi2<rows.length;fi2++){
      var fr=rows[fi2];
      var ht=(fr[colHTeam]||'').trim().toUpperCase();
      var at=(fr[colATeam]||'').trim().toUpperCase();
      var hs=(fr[colHScore]||'').trim();
      var as2=(fr[colAScore]||'').trim();
      if(ht && at && hs==='0' && as2==='0'){
        if(ht in gamesLeft) gamesLeft[ht]++;
        if(at in gamesLeft) gamesLeft[at]++;
      }
    }

    // Determine zone and clinched status for each position
    // promotion clinched: pts[n] - pts[n+1] > gamesLeft[n+1] * MAX_PTS
    // relegation clinched: pts[relegation_safe] - pts[n] > gamesLeft[relegation_safe] * MAX_PTS
    function getZone(idx){
      if(promote  > 0 && idx < promote)           return 'promote';
      if(relegate > 0 && idx >= total - relegate)  return 'relegate';
      return '';
    }

    function isClinched(idx){
      var zone = getZone(idx);
      if(!zone) return false;
      var myPts = teams[idx].pts;
      if(zone==='promote'){
        // Clinched if the team just outside promotion cannot catch us
        var challenger = teams[promote]; // 0-indexed, first team outside zone
        if(!challenger) return true; // only one team!
        var challLeft = gamesLeft[challenger.team.toUpperCase()] || 0;
        return (myPts - challenger.pts) > (challLeft * MAX_PTS);
      }
      if(zone==='relegate'){
        // Clinched if the team just above relegation cannot be caught by us
        var safeIdx = total - relegate - 1;
        var safe = teams[safeIdx];
        if(!safe) return true;
        var myLeft = gamesLeft[teams[idx].team.toUpperCase()] || 0;
        return (safe.pts - myPts) > (myLeft * MAX_PTS);
      }
      return false;
    }

    var h='<div class="tbl-wrap"><table class="lg"><thead><tr>'
      +'<th class="cp">Pos</th><th class="ct">Team</th>'
      +'<th>Pl</th><th>Pts</th><th>+/-</th><th>W</th><th>L</th><th>D</th><th>For</th><th>Agn</th>'
      +'</tr></thead><tbody>';

    teams.forEach(function(t, idx){
      var zone     = getZone(idx);
      var clinched = isClinched(idx);

      // Border line between zones
      var borderTop='';
      if(promote>0  && idx===promote)           borderTop=' zone-border-top';
      if(relegate>0 && idx===total-relegate)     borderTop=' zone-border-top';

      var rowClass = '';
      if(zone==='promote')  rowClass = clinched ? ' row-promoted'  : ' row-promote-zone';
      if(zone==='relegate') rowClass = clinched ? ' row-relegated' : ' row-relegate-zone';
      rowClass += borderTop;

      h+='<tr class="'+rowClass.trim()+'">'
        +'<td class="cp">'+t.pos+'</td>'
        +'<td class="ct">'+badgeImg(t.team)+t.team+'</td>'
        +'<td>'+t.pl+'</td><td class="ck">'+t.pts+'</td><td>'+t.diff+'</td>'
        +'<td>'+t.w+'</td><td>'+t.l+'</td><td>'+t.d+'</td>'
        +'<td>'+t.f+'</td><td>'+t.a+'</td>'
        +'</tr>';
    });

    h+='</tbody></table></div>';

    // Legend
    if(promote>0 || relegate>0){
      h+='<div class="lg-legend">';
      if(promote>0)  h+='<span class="lg-key lg-key-promote"></span>Promotion';
      if(relegate>0) h+='<span class="lg-key lg-key-relegate"></span>Relegation';
      h+='</div>';
    }

    return h;
  }

  function parseDate(str){
    try{var p=str.split(' ')[1].split('-');return new Date(p[1]+' '+p[0]+' '+p[2]);}catch(e){return null;}
  }

  function renderFixtures(rows, filter){
    var i=0;
    while(i<rows.length && rows[i].join('').indexOf('FIXTURES')===-1) i++;
    i++;
    if(i>=rows.length) return '<div class="nipgl-status">Could not find fixtures in data.</div>';

    // Read column map from header row (HPts, HTeam, HScore, AScore, ATeam, APts)
    var colPtsH=-1, colHTeam=-1, colHScore=-1, colAScore=-1, colATeam=-1, colPtsA=-1;
    // Scan for the header row
    var headerRow=null;
    for(var h=i;h<Math.min(i+5,rows.length);h++){
      if(rows[h].join('').indexOf('HPts')!==-1){
        headerRow=rows[h];
        i=h+1;
        break;
      }
    }
    if(headerRow){
      for(var c=0;c<headerRow.length;c++){
        var hv=headerRow[c].trim();
        if(hv==='HPts')   colPtsH=c;
        if(hv==='HTeam')  colHTeam=c;
        if(hv==='HScore') colHScore=c;
        if(hv==='Ascore'||hv==='AScore') colAScore=c;
        if(hv==='ATeam')  colATeam=c;
        if(hv==='APts')   colPtsA=c;
      }
    }
    // Fallback to known positions if header not found
    if(colPtsH===-1)  colPtsH=0;
    if(colHTeam===-1) colHTeam=2;
    if(colHScore===-1)colHScore=7;
    if(colAScore===-1)colAScore=9;
    if(colATeam===-1) colATeam=10;
    if(colPtsA===-1)  colPtsA=15;

    var dateRe=/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun)\s+\d{1,2}-[A-Za-z]+-\d{4}$/;
    var groups=[], cur=null;

    while(i<rows.length){
      var r=rows[i];
      var first=(r[0]||r[1]||'').trim();

      if(dateRe.test(first)){
        cur={date:first,matches:[]};
        groups.push(cur);
        i++;
        // Skip any sub-header rows (Points/Shots etc)
        while(i<rows.length && !nonEmpty(rows[i].slice(0,2)) && rows[i].join('').indexOf('Points')!==-1) i++;
        continue;
      }

      if(cur && nonEmpty(r)){
        var ptsHome  = (r[colPtsH]  ||'').trim();
        var homeTeam = (r[colHTeam] ||'').trim();
        var shotsHome= (r[colHScore]||'').trim();
        var shotsAway= (r[colAScore]||'').trim();
        var awayTeam = (r[colATeam] ||'').trim();
        var ptsAway  = (r[colPtsA]  ||'').trim();

        // Time note: look for HH:MM in cells after colATeam
        var timeNote='';
        for(var x=colATeam+1;x<Math.min(colPtsA,r.length);x++){
          if(/^\d{1,2}:\d{2}$/.test((r[x]||'').trim())) timeNote=r[x].trim();
        }

        if(homeTeam && awayTeam){
          var played=(shotsHome!=='0'||shotsAway!=='0'||ptsHome!=='0'||ptsAway!=='0');
          cur.matches.push({
            ptsHome:ptsHome, ptsAway:ptsAway,
            homeTeam:homeTeam, awayTeam:awayTeam,
            shotsHome:shotsHome, shotsAway:shotsAway,
            timeNote:timeNote, played:played
          });
        }
      }
      i++;
    }
    var now=new Date(), filtered=groups;
    if(filter==='results'){
      filtered=groups.map(function(g){
        return{date:g.date,matches:g.matches.filter(function(m){return m.played;})};
      }).filter(function(g){return g.matches.length;});
    } else if(filter==='upcoming'){
      filtered=groups.map(function(g){
        var d=parseDate(g.date);
        if(!d) return{date:g.date,matches:[]};
        // Only include unplayed matches on or after today
        var matches=g.matches.filter(function(m){return !m.played && d>=now;});
        return{date:g.date,matches:matches};
      }).filter(function(g){return g.matches.length;});
    }
    if(!filtered.length) return '<div class="nipgl-status">No fixtures to display.</div>';
    var h='';
    filtered.forEach(function(g){
      h+='<div class="date-group"><div class="date-hdr">'+g.date+'</div>';
      g.matches.forEach(function(m){
        var pc=m.played?' played':'';
        h+='<div class="fx-row'+pc+'">'
          +'<div class="fx-ph">'+(m.played?m.ptsHome:'')+'</div>'
          +'<div class="fx-h">'+badgeImg(m.homeTeam)+m.homeTeam+'</div>'
          +'<div class="fx-sc"><span class="fx-sb">'+m.shotsHome+'</span>'
          +'<span class="fx-sep">v</span>'
          +'<span class="fx-sb">'+m.shotsAway+'</span></div>'
          +'<div class="fx-a">'+badgeImg(m.awayTeam)+m.awayTeam+'</div>'
          +'<div class="fx-pa">'+(m.played?m.ptsAway:'')+'</div>'
          +(m.timeNote?'<div class="fx-time">&#9200; '+m.timeNote+'</div>':'')
          +'</div>';
      });
      h+='</div>';
    });
    return h;
  }

  function filterBar(activeFilter){
    var h='<div class="fix-filter">';
    ['all','results','upcoming'].forEach(function(f){
      var cap=f.charAt(0).toUpperCase()+f.slice(1);
      h+='<button data-f="'+f+'"'+(activeFilter===f?' class="active"':'')+'>'+cap+'</button>';
    });
    return h+'</div>';
  }

  function initWidget(widget){
    var csvUrl   = widget.getAttribute('data-csv');
    var promote  = parseInt(widget.getAttribute('data-promote')  || '0', 10);
    var relegate = parseInt(widget.getAttribute('data-relegate') || '0', 10);
    var activeFilter = 'all';
    var allRows = null;

    var panels = widget.querySelectorAll('.nipgl-panel');
    var tabs = widget.querySelectorAll('.nipgl-tab');

    // Tab clicks
    tabs.forEach(function(tab){
      tab.addEventListener('click', function(){
        tabs.forEach(function(t){t.classList.remove('active');});
        panels.forEach(function(p){p.classList.remove('active');});
        tab.classList.add('active');
        var name = tab.getAttribute('data-tab');
        for(var i=0;i<panels.length;i++){
          if(panels[i].getAttribute('data-panel')===name){
            panels[i].classList.add('active');
            break;
          }
        }
      });
    });

    function getFixPanel(){
      for(var i=0;i<panels.length;i++){
        if(panels[i].getAttribute('data-panel')==='fixtures') return panels[i];
      }
      return null;
    }

    function getTablePanel(){
      for(var i=0;i<panels.length;i++){
        if(panels[i].getAttribute('data-panel')==='table') return panels[i];
      }
      return null;
    }

    function bindFilterBtns(){
      var btns = widget.querySelectorAll('.fix-filter button');
      btns.forEach(function(b){
        b.addEventListener('click', function(){
          activeFilter = b.getAttribute('data-f');
          btns.forEach(function(x){x.classList.toggle('active', x.getAttribute('data-f')===activeFilter);});
          var fp = getFixPanel();
          if(fp) fp.innerHTML = filterBar(activeFilter) + renderFixtures(allRows, activeFilter);
          bindFilterBtns();
        });
      });
    }

    function showError(msg){
      var errHtml = '<div class="nipgl-status"><strong>Unable to load data.</strong><br><small>'+msg+'</small></div>';
      var tp=getTablePanel(), fp=getFixPanel();
      if(tp) tp.innerHTML=errHtml;
      if(fp) fp.innerHTML=errHtml;
    }

    // Build proxy URL via WordPress ajax
    var proxyUrl = ajaxUrl + '?action=nipgl_csv&url=' + encodeURIComponent(csvUrl);

    var xhr = new XMLHttpRequest();
    xhr.open('GET', proxyUrl);
    xhr.onload = function(){
      if(xhr.status === 200 && xhr.responseText && xhr.responseText.trim().length > 10){
        allRows = parseCSV(xhr.responseText);
        var tp=getTablePanel(), fp=getFixPanel();
        if(tp) tp.innerHTML = renderTable(allRows, promote, relegate);
        if(fp) fp.innerHTML = filterBar(activeFilter) + renderFixtures(allRows, activeFilter);
        bindFilterBtns();
      } else {
        showError('Server returned status ' + xhr.status);
      }
    };
    xhr.onerror = function(){ showError('Network error — could not reach proxy.'); };
    xhr.send();
  }

  // Init all widgets on the page when DOM is ready
  function init(){
    var widgets = document.querySelectorAll('.nipgl-w[data-csv]');
    widgets.forEach(function(w){ initWidget(w); });
  }

  if(document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();
