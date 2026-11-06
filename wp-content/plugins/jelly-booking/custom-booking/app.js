(() => {
  'use strict';
  const siteRoot=new URL('../',document.currentScript.src);
  const JDB=window.JDB||{api:new URL('?rest_route=/jelly/v1/',siteRoot).href,session:new URL('wp-admin/admin-ajax.php?action=jdb_session',siteRoot).href};
  const form = document.querySelector('#jdb-form'); if (!form) return;
  const $ = id => document.querySelector('#jdb-' + id), f = form.elements;
  let step=0, location=null, services=[], users=[], start='', slotLabel='', requestId='', busy=false, session={}, revision=0, allLocations=[], nextDatesFrom=null, loadedDates=[], dateRangeEnd='', loadedDatePeriods={}, currentSlots=[];
  const error = $('error'), status = $('status');
  const serviceCache=new Map(), serviceCacheTTL=300000;
  let displayedServicesLocation=null, displayedServicesExpires=0;
  const availabilityCache=new Map(), slotCache=new Map(), availabilityTTL=20000;
  const clinicianPhotos=new Map();
  const selectedService = () => services.find(s => s.id === f.service.value);
  const selectedUser = () => users.find(u => u.id === f.clinician.value);
  const price = s => s?.cost == null ? 'Price to be confirmed by the clinic' : 'Listed fee: ' + new Intl.NumberFormat('en-GB',{style:'currency',currency:JDB.currency||'GBP'}).format(Number(s.cost));
  function text(tag, value) { const el=document.createElement(tag); el.textContent=value; return el; }
  function summary(target='summary') {
    renderClinicianCards();
    const box=$(target); box.replaceChildren(); const service=selectedService(), user=selectedUser();
    [location?.name,service?.name,service?`${service.duration} minutes · ${price(service)}`:'',user?.name,f.date.value?`${f.date.value}${slotLabel?' at '+slotLabel:''} (${JDB.timezone})`:''].filter(Boolean).forEach(v=>box.append(text('p',v)));
    if(!box.children.length) box.textContent='Your selection will appear here.';
  }
  function clinicianPortrait(user){
    const portrait=text('span',user.name.replace(/^(Dr|Mr|Mrs|Ms|Prof)\.?\s+/i,'').split(/\s+/).filter(Boolean).slice(0,2).map(n=>n[0]).join(''));portrait.className='jdb-clinician-portrait';portrait.setAttribute('aria-hidden','true');
    const image=clinicianPhotos.get(user.id)?.image;
    if(image){const img=document.createElement('img');img.src=image;img.alt='';img.loading='eager';img.decoding='async';img.referrerPolicy='no-referrer';img.addEventListener('error',()=>img.remove(),{once:true});portrait.append(img);}
    return portrait;
  }
  function renderClinicianCards(){
    const user=selectedUser();
    ['clinician-card-assessment','clinician-card-time'].forEach(id=>{
      const card=$(id);card.replaceChildren();card.hidden=!user;if(!user)return;
      const info=text('div','');info.append(text('strong',user.name));if(user.title)info.append(text('p',user.title));
      const button=text('button','View profile');button.type='button';button.className='jdb-profile-button';button.addEventListener('click',()=>openClinicianProfile(user));
      card.append(clinicianPortrait(user),info,button);
    });
    if(user?.profileUrl)preloadClinicianPhoto(user);
  }
  function preloadClinicianPhoto(user,params={location:location?.id,service:f.service.value}){
    if(!user.profileUrl)return Promise.resolve();
    const existing=clinicianPhotos.get(user.id);if(existing)return existing.promise||Promise.resolve();
    const key='jdb-photo-v1|'+user.profileUrl;let stored;
    try{stored=JSON.parse(localStorage.getItem(key));}catch{}
    const record={image:'',promise:null};clinicianPhotos.set(user.id,record);
    const source=stored?.expires>Date.now()&&/^https:\/\//.test(stored.image)?Promise.resolve({image:stored.image}):api('clinician-photo',{...params,clinician:user.id});
    record.promise=source.then(async result=>{
      const image=result.image||'';
      if(image){
        // Fetch the actual pixels before updating the cards, rather than only caching the URL.
        await new Promise(resolve=>{const img=new Image();img.referrerPolicy='no-referrer';img.onload=()=>{record.image=image;resolve();};img.onerror=resolve;img.src=image;});
        if(record.image)try{localStorage.setItem(key,JSON.stringify({image,expires:Date.now()+86400000}));}catch{}
      }
      if(selectedUser()?.id===user.id){
        renderClinicianCards();
        if($('profile-dialog').open&&$('profile-title').textContent===user.name)$('profile-content').querySelector('.jdb-clinician-portrait')?.replaceWith(clinicianPortrait(user));
      }
    }).catch(()=>{clinicianPhotos.delete(user.id);}).finally(()=>{record.promise=null;});
    return record.promise;
  }
  function preloadClinicianPhotos(rows){
    const queue=rows.filter(user=>user.profileUrl),params={location:location.id,service:f.service.value};
    async function worker(){while(queue.length)await preloadClinicianPhoto(queue.shift(),params);}
    // Limit background requests so booking data retains network priority.
    void worker();void worker();
  }

  function openClinicianProfile(user){
    $('profile-title').textContent=user.name;const content=$('profile-content');content.replaceChildren(clinicianPortrait(user));
    if(user.title)content.append(text('h3',user.title));
    content.append(text('p',user.biography||'A biography is not currently available for this clinician.'));
    if(user.profileUrl){const link=text('a','Read full profile ↗');link.href=user.profileUrl;link.target='_blank';link.rel='noopener noreferrer';content.append(link);}
    document.documentElement.classList.add('jdb-modal-open');$('profile-dialog').showModal();$('profile-close').focus();
  }
  $('profile-close').addEventListener('click',()=>$('profile-dialog').close());
  $('profile-dialog').addEventListener('close',()=>document.documentElement.classList.remove('jdb-modal-open'));
  let clinicianMatches=[],clinicianActive=-1;
  function closeClinicianSearch(){
    $('clinician-results').hidden=true;$('clinician-search').setAttribute('aria-expanded','false');$('clinician-search').removeAttribute('aria-activedescendant');clinicianActive=-1;
  }
  function syncClinicianSearch(){
    const input=$('clinician-search');input.value=selectedUser()?.name||'';input.placeholder=f.clinician.options[0]?.textContent||'Choose or search for a clinician';closeClinicianSearch();
  }
  function chooseClinician(user){
    f.clinician.value=user.id;$('clinician-search').value=user.name;closeClinicianSearch();f.clinician.dispatchEvent(new Event('change'));$('clinician-search').focus();
  }
  function highlightClinician(index){
    clinicianActive=index;const rows=[...$('clinician-results').querySelectorAll('[role=option]')];rows.forEach((row,i)=>row.setAttribute('aria-selected',String(i===index)));
    if(rows[index]){$('clinician-search').setAttribute('aria-activedescendant',rows[index].id);rows[index].scrollIntoView({block:'nearest'});}
  }
  function openClinicianSearch(){
    if(busy)return;const query=$('clinician-search').value.trim().toLowerCase(), selected=selectedUser();
    clinicianMatches=users.filter(user=>!query||query===selected?.name.toLowerCase()||user.name.toLowerCase().includes(query));
    const list=$('clinician-results');list.replaceChildren();clinicianActive=-1;$('clinician-search').removeAttribute('aria-activedescendant');
    clinicianMatches.forEach((user,i)=>{const row=text('div',user.name);row.id='jdb-clinician-option-'+i;row.setAttribute('role','option');row.setAttribute('aria-selected','false');row.addEventListener('mousedown',event=>event.preventDefault());row.addEventListener('click',()=>chooseClinician(user));list.append(row);});
    if(!clinicianMatches.length)list.append(text('p',users.length?'No matching clinicians.':'Choose an assessment to load clinicians.'));
    list.hidden=false;$('clinician-search').setAttribute('aria-expanded','true');$('clinician-search-status').textContent=clinicianMatches.length+' matching clinicians';
  }
  $('clinician-search').addEventListener('focus',openClinicianSearch);
  $('clinician-search').addEventListener('click',openClinicianSearch);
  $('clinician-search').addEventListener('input',()=>{if(f.clinician.value){f.clinician.value='';f.clinician.dispatchEvent(new Event('change'));}openClinicianSearch();});
  $('clinician-search').addEventListener('keydown',event=>{
    if(['ArrowDown','ArrowUp'].includes(event.key)){event.preventDefault();if($('clinician-results').hidden)openClinicianSearch();if(clinicianMatches.length)highlightClinician((clinicianActive+(event.key==='ArrowDown'?1:clinicianActive<0?0:-1)+clinicianMatches.length)%clinicianMatches.length);}
    else if(event.key==='Enter'&&!$('clinician-results').hidden){event.preventDefault();if(clinicianActive>=0)chooseClinician(clinicianMatches[clinicianActive]);}
    else if(event.key==='Escape'){event.preventDefault();closeClinicianSearch();}
    else if(event.key==='Tab')closeClinicianSearch();
  });
  document.addEventListener('click',event=>{if(!$('clinician-picker').contains(event.target))closeClinicianSearch();});
  $('clinician-picker').addEventListener('focusout',event=>{if(!$('clinician-picker').contains(event.relatedTarget))closeClinicianSearch();});
  function navigation() {
    $('back').hidden=step===0 || step===4; $('next').hidden=step>=3; $('submit').hidden=step!==3;
    $('next').disabled=busy || (step===0&&!location) || (step===1&&(!f.service.value||!f.clinician.value)) || (step===2&&!start);
    $('clinician-search').disabled=busy;if(busy)closeClinicianSearch();$('time-filter').disabled=busy;$('day-filter').disabled=busy; $('submit').disabled=busy; $('back').disabled=busy; f.date.disabled=busy; $('date-list').disabled=busy;
  }
  function show(n, focus=true) {
    step=n; error.textContent=''; status.textContent=''; $('retry').hidden=true;
    document.querySelectorAll('[data-step]').forEach(el=>{el.hidden=Number(el.dataset.step)!==n;});
    document.querySelectorAll('.jdb-steps li').forEach((el,i)=>{el.classList.toggle('is-complete',i<n);if(i===n)el.setAttribute('aria-current','step');else el.removeAttribute('aria-current');});
    if(n===3)summary('review-details'); summary(); navigation();
    if(focus){const h=document.querySelector(`[data-step="${n}"] h2`);h.tabIndex=-1;h.focus();}
  }
  function setBusy(value,message='') { busy=value; status.textContent=message; form.setAttribute('aria-busy',String(value)); navigation(); }
  async function refreshSession(includeCatalog=false) {
    const response=await fetch(JDB.session+(includeCatalog?'&catalog=1':''),{credentials:'same-origin',cache:'no-store'});
    const result=await response.json(); if(!result.success)throw new Error('Could not start the booking session. Reload this page.'); session=result.data;displayedServicesLocation=null;displayedServicesExpires=0;serviceCache.clear();availabilityCache.clear();slotCache.clear();
    Object.assign(JDB,{timezone:session.timezone,currency:session.currency,environment:session.environment,submissionsEnabled:session.submissionsEnabled});
    f.date.min=session.today;f.date.max=session.maxDate;f.dob.max=session.today;
    $('timezone').textContent=JDB.timezone;
    $('source').textContent=JDB.environment==='live'?'LIVE DATA · PREVIEW':'TEST DATA · PREVIEW';
    $('submit').textContent=JDB.submissionsEnabled?'Continue to secure payment':'Secure payment →';
    if(JDB.submissionsEnabled){
      $('source').textContent=JDB.environment==='live'?'LIVE BOOKING':'JELLY TEST SYSTEM';
      $('mode-notice').textContent='Appointments are submitted to the clinic through Jelly. No online payment is collected here; applicable charges remain payable to the clinic.';
      $('patient-notice').textContent='Enter your details to register or verify your patient account with the clinic.';
      $('consent-copy').textContent='I confirm these details and authorise sending them to the clinic through Jelly. I understand that applicable fees remain payable to the clinic.';
    }
  }
  async function api(path, params={}, method='GET', retried=false) {
    const url=new URL(JDB.api+path);
    if(method==='GET')Object.entries(params).forEach(([k,v])=>url.searchParams.set(k,v));
    const response=await fetch(url,{method,credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-JDB-Nonce':session.nonce,'X-WP-Nonce':session.restNonce},...(method==='POST'?{body:JSON.stringify(params)}:{})});
    let result; try{result=await response.json();}catch{throw new Error('The booking server returned an unreadable response. Please contact the clinic.');}
    if(!response.ok){if(!retried&&method==='GET'&&['nonce','rest_cookie_invalid_nonce'].includes(result.code)){await refreshSession();return api(path,params,method,true);}throw new Error(result.message||'Could not complete this request. Please try again.');}
    return result;
  }
  function options(select, rows, label) { select.replaceChildren(new Option(label,'')); const groups=new Map(); rows.forEach(r=>{const option=new Option(r.name,r.id); if(r.category){if(!groups.has(r.category)){const group=document.createElement('optgroup');group.label=r.category;groups.set(r.category,group);select.append(group);}groups.get(r.category).append(option);}else select.add(option);});if(select===f.clinician)syncClinicianSearch(); }
  function fail(e,retry=false) { error.textContent=e.message; $('retry').hidden=!retry; }
  async function loadLocations() {
    setBusy(true,'Loading clinic locations from Jelly…'); error.textContent=''; $('retry').hidden=true;
    try{
      await refreshSession(true); const rows=session.locations||await api('locations');allLocations=rows; populateClinicFilter(); $('locations').replaceChildren();
      if(!rows.length){status.textContent='No bookable locations were returned. Please contact the clinic.';return;}
      rows.forEach(row=>{
        const label=text('label',''); label.className='jdb-location';
        const input=document.createElement('input'); input.type='radio';input.name='location';input.value=row.id;
        input.addEventListener('change',()=>{location=row;displayedServicesLocation=null;displayedServicesExpires=0;f.service.value='';f.clinician.value='';services=[];users=[];start='';requestId='';summary();navigation();prefetchServices(row.id);});
        const info=document.createElement('span'); info.append(text('strong',row.name));
        if(row.address)info.append(text('span',row.address));if(row.phone)info.append(text('span','Phone: '+row.phone));
        label.append(input,info); $('locations').append(label);
      }); status.textContent='';filterLocations();
    }catch(e){status.textContent='';fail(e,true);}finally{busy=false;form.setAttribute('aria-busy','false');navigation();}
  }
  function locationTags(row){
    const name=row.name.toLowerCase(), address=(row.address||'').toLowerCase();
    const child=/\b(child|children|young person|young persons|under 18|17 or under)\b/.test(name);
    const adult=/\badult\b/.test(name);
    const online=/\b(online|virtual|zoom|remote)\b/.test(name+' '+address);
    const physical=/\b(in[ -]person|marylebone|belgravia|manchester|wimpole)\b/.test(name) || (!online && !!address.trim());
    return {age:child?'child':adult?'adult':'',format:online?'online':physical?'in-person':''};
  }
  function clinicTag(row){
    if(locationTags(row).format!=='in-person')return '';
    const details=`${row.name} ${row.address||''}`.toLowerCase();
    if(/\b(marylebone|wimpole)\b/.test(details))return 'marylebone';
    if(/\b(belgravia|eccleston)\b/.test(details))return 'belgravia';
    if(/\b(manchester|wilmslow)\b/.test(details))return 'manchester';
    return 'other';
  }
  function populateClinicFilter(){
    const select=$('clinic-filter'), previous=select.value;
    const present=new Set(allLocations.map(clinicTag).filter(Boolean));
    select.replaceChildren(new Option('All clinics',''));
    Object.entries({marylebone:'London · Marylebone',belgravia:'London · Belgravia',manchester:'Manchester',other:'Other clinics'}).forEach(([value,label])=>{if(present.has(value))select.add(new Option(label,value));});
    select.value=present.has(previous)?previous:'';
  }
  function filterLocations(){
    const query=$('location-search').value.trim().toLowerCase(), age=$('age-filter').value, format=$('format-filter').value;
    const showClinics=format!=='online';$('clinic-filter-wrap').hidden=!showClinics;$('clinic-filter').disabled=!showClinics;
    if(!showClinics)$('clinic-filter').value='';
    const clinic=$('clinic-filter').value;let visible=0,selectionHidden=false;
    [...$('locations').children].forEach((el,index)=>{
      const row=allLocations[index],tags=locationTags(row);
      el.hidden=!`${row.name} ${row.address}`.toLowerCase().includes(query) || (!!age && tags.age!==age) || (!!format && tags.format!==format) || (showClinics && !!clinic && clinicTag(row)!==clinic);
      if(!el.hidden)visible++;
      if(el.hidden && el.querySelector('input').checked){el.querySelector('input').checked=false;selectionHidden=true;}
    });
    if(selectionHidden){location=null;services=[];users=[];f.service.value='';f.clinician.value='';start='';slotLabel='';requestId='';summary();navigation();}
    $('location-count').textContent=`${visible} of ${allLocations.length} live locations shown`;
    $('no-locations').hidden=visible!==0;
  }
  $('location-search').addEventListener('input',filterLocations);
  $('age-filter').addEventListener('change',filterLocations);
  $('clinic-filter').addEventListener('change',filterLocations);
  $('format-filter').addEventListener('change',filterLocations);
  function setupChoiceButtons(selectId,groupId){
    const select=$(selectId),group=$(groupId);
    [...select.options].forEach(option=>{
      const button=text('button',option.textContent);button.type='button';button.dataset.value=option.value;button.setAttribute('aria-pressed',String(select.value===option.value));
      button.addEventListener('click',()=>{select.value=option.value;select.dispatchEvent(new Event('change'));});group.append(button);
    });
    select.addEventListener('change',()=>{group.querySelectorAll('button').forEach(button=>button.setAttribute('aria-pressed',String(button.dataset.value===select.value)));});
  }
  setupChoiceButtons('age-filter','age-choices');setupChoiceButtons('format-filter','format-choices');
  function renderServiceCards(){
    const group=$('service-cards');group.replaceChildren();
    services.forEach(service=>{
      const button=document.createElement('button');button.type='button';button.className='jdb-service-card';button.setAttribute('aria-pressed',String(service.id===f.service.value));
      const info=document.createElement('span');info.append(text('strong',service.name),text('small',`${service.duration} minutes${service.category?' · '+service.category:''}`));
      button.append(info,text('span',price(service).replace('Listed fee: ','')));
      button.addEventListener('click',()=>{if(busy)return;f.service.value=service.id;f.service.dispatchEvent(new Event('change'));renderServiceCards();});group.append(button);
    });
  }
  function matchesDay(date){
    const day=new Date(date+'T12:00:00Z').getUTCDay(), preference=$('day-filter').value;
    return !preference || (preference==='weekdays'?day>0&&day<6:day===0||day===6);
  }
  function timePeriod(label){const hour=Number(label.slice(0,2));return hour<12?'morning':hour<17?'afternoon':'evening';}
  function matchingDates(){return loadedDates.filter(date=>matchesDay(date)&&(!$('time-filter').value||(loadedDatePeriods[date]||[]).includes($('time-filter').value)));}
  function renderDateChoices(){
    const dates=matchingDates(),list=$('date-list');list.replaceChildren(new Option(dates.length?'Choose an available date':'No dates match these preferences',''));
    const formatter=new Intl.DateTimeFormat('en-GB',{weekday:'short',day:'numeric',month:'long',year:'numeric',timeZone:'UTC'});
    dates.forEach(date=>list.add(new Option(formatter.format(new Date(date+'T12:00:00Z')),date)));
    if(nextDatesFrom)list.add(new Option('More dates…','__more__'));list.value=f.date.value;
    $('availability-note').textContent=`${dates.length} matching dates (${loadedDates.length} total) loaded through ${dateRangeEnd}. `+(nextDatesFrom?'Use More dates to search later months, or choose a date directly.':'Reached the one-year search limit.');
    renderDatePopup();
  }
  function renderSlots(){
    const previous=start,preference=$('time-filter').value;
    const rows=currentSlots.filter(row=>matchesDay(row.date)&&(!preference||timePeriod(row.label)===preference));
    $('slots').replaceChildren();
    if(!rows.some(row=>row.start===previous)){start='';slotLabel='';requestId='';}
    if(!rows.length)$('slots').textContent=currentSlots.length?'No times match your preferences on this date. Change your preferences or choose another date.':'No times are available on this date. Please choose another date.';
    rows.forEach(row=>{const label=text('label','');label.className='jdb-slot';const input=document.createElement('input');input.type='radio';input.name='start';input.value=row.start;input.checked=row.start===start;input.addEventListener('change',()=>{start=row.start;slotLabel=row.label;requestId='';summary();navigation();});label.append(input,document.createTextNode(row.label));$('slots').append(label);});
    summary();navigation();
  }
  async function applyTimePreferences(){
    if(busy)return;
    const dates=matchingDates();
    if(f.date.value && dates.includes(f.date.value)){renderSlots();renderDateChoices();return;}
    start='';slotLabel='';requestId='';currentSlots=[];f.date.value=dates[0]||'';renderDateChoices();summary();navigation();
    if(f.date.value)await loadSlots();else $('slots').textContent='No loaded dates match your preferences. Try More dates or change your preferences.';
  }
  $('time-filter').addEventListener('change',applyTimePreferences);$('day-filter').addEventListener('change',applyTimePreferences);
  function positionDatePopup(){
    const panel=$('date-popup');if(panel.hidden)return;
    const box=$('date-toggle').getBoundingClientRect(),viewport=window.visualViewport;
    const top=viewport?.offsetTop||0,bottom=top+(viewport?.height||window.innerHeight);
    const below=bottom-box.bottom-12,above=box.top-top-12,up=below<180&&above>below;
    panel.style.top=up?'auto':'calc(100% + 4px)';panel.style.bottom=up?'calc(100% + 4px)':'auto';
    panel.style.maxHeight=Math.max(48,Math.min(320,up?above:below))+'px';
  }
  window.addEventListener('resize',positionDatePopup);
  window.visualViewport?.addEventListener('resize',positionDatePopup);
  document.addEventListener('scroll',positionDatePopup,true);
  function closeDatePopup(){
    $('date-popup').hidden=true;$('date-toggle').setAttribute('aria-expanded','false');
  }
  function renderDatePopup(){
    const panel=$('date-popup'), wasOpen=!panel.hidden, oldDates=[...panel.querySelectorAll('[data-date]')].map(el=>el.dataset.date);
    const hadFocus=panel.contains(document.activeElement), scroll=panel.scrollTop;
    panel.replaceChildren();
    const formatter=new Intl.DateTimeFormat('en-GB',{weekday:'short',day:'numeric',month:'long',year:'numeric',timeZone:'UTC'});
    matchingDates().forEach(date=>{
      const button=text('button',formatter.format(new Date(date+'T12:00:00Z')));button.type='button';button.dataset.date=date;
      button.setAttribute('aria-pressed',String(date===f.date.value));
      button.addEventListener('click',async()=>{if(busy)return;f.date.value=date;$('date-list').value=date;closeDatePopup();$('date-toggle').focus();renderDatePopup();await loadSlots();});
      panel.append(button);
    });
    if(!matchingDates().length)panel.append(text('p','No dates match your preferences. Try More dates or change the filters.'));
    if(nextDatesFrom){
      const more=text('button','More dates…');more.type='button';more.className='jdb-date-more';
      more.addEventListener('click',()=>{if(!busy)loadAvailableDates(true);});panel.append(more);
    }
    $('date-toggle').textContent=f.date.value?formatter.format(new Date(f.date.value+'T12:00:00Z')):'Choose an available date';
    positionDatePopup();panel.scrollTop=scroll;
    if(wasOpen && hadFocus){
      const added=[...panel.querySelectorAll('[data-date]')].find(el=>!oldDates.includes(el.dataset.date));
      const focus=added||panel.querySelector('.jdb-date-more')||panel.querySelector('[data-date]');
      if(focus){focus.focus({preventScroll:true});if(added)added.scrollIntoView({block:'nearest'});}
    }
  }
  $('date-toggle').addEventListener('click',()=>{if(busy)return;const open=$('date-popup').hidden;$('date-popup').hidden=!open;$('date-toggle').setAttribute('aria-expanded',String(open));positionDatePopup();});
  $('date-picker').addEventListener('keydown',event=>{
    if(event.key==='Escape'){event.preventDefault();closeDatePopup();$('date-toggle').focus();return;}
    if(!['ArrowDown','ArrowUp','Home','End'].includes(event.key))return;
    event.preventDefault();$('date-popup').hidden=false;$('date-toggle').setAttribute('aria-expanded','true');
    positionDatePopup();
    const buttons=[...$('date-popup').querySelectorAll('button')],index=buttons.indexOf(document.activeElement);
    let next=event.key==='Home'?0:event.key==='End'?buttons.length-1:event.key==='ArrowDown'?Math.min(index+1,buttons.length-1):Math.max(index-1,0);
    buttons[next]?.focus();
  });
  document.addEventListener('click',event=>{if(!$('date-picker').contains(event.target))closeDatePopup();});
  document.addEventListener('focusin',event=>{if(!$('date-picker').contains(event.target))closeDatePopup();});
  function selectionParams(){return {location:location.id,service:f.service.value,clinician:f.clinician.value};}
  function availabilityKey(params){return [JDB.environment,params.location,params.service,params.clinician,params.from||session.today].join('|');}
  function slotKey(params,date){return [JDB.environment,params.location,params.service,params.clinician,date].join('|');}
  function cachedAvailability(params){
    const record=availabilityCache.get(availabilityKey(params));
    return record && (record.promise||record.expires>Date.now())?record:null;
  }
  function requestAvailability(params){
    const key=availabilityKey(params),existing=cachedAvailability(params);
    if(existing)return existing.promise||Promise.resolve(existing.result);
    const record={result:null,expires:0,promise:null};
    record.promise=api('availability',params).then(result=>{
      record.result=result;record.expires=Date.now()+availabilityTTL;record.promise=null;
      const byDate=new Map();(result.slots||[]).forEach(slot=>{if(!byDate.has(slot.date))byDate.set(slot.date,[]);byDate.get(slot.date).push(slot);});
      byDate.forEach((rows,date)=>slotCache.set(slotKey(params,date),{rows,expires:record.expires}));
      return result;
    }).catch(error=>{if(availabilityCache.get(key)===record)availabilityCache.delete(key);throw error;});
    availabilityCache.set(key,record);return record.promise;
  }
  function prefetchAvailability(){
    if(location&&f.service.value&&f.clinician.value)requestAvailability(selectionParams()).catch(()=>{});
  }
  function resetAvailableDates(){
    loadedDates=[];loadedDatePeriods={};currentSlots=[];nextDatesFrom=null;dateRangeEnd='';start='';slotLabel='';f.date.value='';$('slots').replaceChildren();
  }
  function useAvailabilityResult(result){
    loadedDates=[...new Set([...loadedDates,...result.dates])].sort();Object.assign(loadedDatePeriods,result.periodsByDate||{});
    nextDatesFrom=result.nextFrom;dateRangeEnd=result.rangeEnd;renderDateChoices();
  }
  async function loadAvailableDates(more=false){
    if(more && (!nextDatesFrom || busy))return;
    if(!more){
      resetAvailableDates();const cached=cachedAvailability(selectionParams());
      if(cached?.result && cached.expires>Date.now()){
        useAvailabilityResult(cached.result);const dates=matchingDates();
        if(dates.length){f.date.value=dates[0];$('date-list').value=f.date.value;await loadSlots();}
        else $('slots').textContent='No dates match your preferences in this range. Try More dates or change your preferences.';
        renderDatePopup();navigation();return;
      }
    }
    setBusy(true,'Finding available dates in Jelly…');error.textContent='';
    $('date-list').disabled=true;$('date-popup').setAttribute('aria-busy','true');
    const moreButton=$('date-popup').querySelector('.jdb-date-more');if(moreButton){moreButton.textContent='Loading dates…';moreButton.setAttribute('aria-disabled','true');}
    try{
      const params={location:location.id,service:f.service.value,clinician:f.clinician.value};
      if(more)params.from=nextDatesFrom;
      let result=await requestAvailability(params);
      // An empty month does not mean that all later months are empty.
      const remember=result=>{loadedDates=[...new Set([...loadedDates,...result.dates])].sort();Object.assign(loadedDatePeriods,result.periodsByDate||{});};
      remember(result);
      const hasMatch=result=>result.dates.some(date=>matchesDay(date)&&(!$('time-filter').value||(result.periodsByDate?.[date]||[]).includes($('time-filter').value)));
      while(more && !hasMatch(result) && result.nextFrom){
        status.textContent='Checking later months for available dates…';
        params.from=result.nextFrom;result=await requestAvailability(params);remember(result);
      }
      loadedDates=[...new Set([...loadedDates,...result.dates])].sort();Object.assign(loadedDatePeriods,result.periodsByDate||{});
      nextDatesFrom=result.nextFrom;dateRangeEnd=result.rangeEnd;
      renderDateChoices();
      const dates=matchingDates();
      if(!more && dates.length){f.date.value=dates[0];$('date-list').value=f.date.value;await loadSlots();}
      else if(!more)$('slots').textContent='No dates match your preferences in this range. Try More dates or change your preferences.';
      else $('date-list').value=f.date.value;
    }catch(e){fail(e);}finally{
      setBusy(false);$('date-list').disabled=false;$('date-popup').setAttribute('aria-busy','false');renderDatePopup();
    }
  }
  $('date-list').addEventListener('change',async()=>{if(busy||!$('date-list').value)return;if($('date-list').value==='__more__'){$('date-list').value=f.date.value;await loadAvailableDates(true);$('date-list').focus();return;}f.date.value=$('date-list').value;await loadSlots();});
  function cachedServices(locationId){
    const key=JDB.environment+'|'+locationId,record=serviceCache.get(key);
    return record && (record.promise||record.expires>Date.now())?record:null;
  }
  function requestServices(locationId){
    const key=JDB.environment+'|'+locationId,existing=cachedServices(locationId);
    if(existing)return existing.promise||Promise.resolve(existing.rows);
    const record={rows:null,expires:0,promise:null};
    record.promise=api('services',{location:locationId}).then(rows=>{
      record.rows=rows;record.expires=Date.now()+serviceCacheTTL;record.promise=null;return rows;
    }).catch(error=>{if(serviceCache.get(key)===record)serviceCache.delete(key);throw error;});
    serviceCache.set(key,record);return record.promise;
  }
  function prefetchServices(locationId){
    // Read-only preload; failed requests are evicted and retried on Continue.
    requestServices(locationId).catch(()=>{});
  }
  async function displayServices(rows){
    const previous=f.service.value;
    displayedServicesLocation=location.id;displayedServicesExpires=Date.now()+serviceCacheTTL;
    services=rows;options(f.service,services,'Choose a service');renderServiceCards();options(f.clinician,[],'Choose a service first');$('service-info').textContent='';
    $('service-scope').textContent=services.some(s=>s.referencePreview)?'Services match the reference website catalogue for this location. Availability is checked separately; bookings remain disabled.':'Services supplied by Jelly for your selected location.';
    if(!services.length){error.textContent='No online services are available for this location.';return;}
    f.service.value=services.some(row=>row.id===previous)?previous:services[0].id;
    renderServiceCards();await loadClinicians();
  }
  async function loadServices() {
    // Back/Continue on the same location must preserve the assessment and clinician.
    if(displayedServicesLocation===location.id && displayedServicesExpires>Date.now()){navigation();return;}
    users=[];const locationId=location.id,record=cachedServices(locationId);
    // Render before yielding when preload is ready: no second network request.
    if(record?.rows && record.expires>Date.now()){await displayServices(record.rows);navigation();return;}
    options(f.service,[],'Loading services…');$('service-cards').replaceChildren(text('p','Loading assessment options…'));options(f.clinician,[],'Choose a service first');
    setBusy(true,'Loading services from Jelly…');
    try{const rows=await requestServices(locationId);if(location?.id===locationId&&step===1)await displayServices(rows);}
    catch(e){if(location?.id===locationId&&step===1){$('service-cards').replaceChildren();fail(e);}}
    finally{setBusy(false);}
  }
  f.service.addEventListener('change',loadClinicians);
  async function loadClinicians(){
    const rev=++revision; users=[];options(f.clinician,[],'Loading clinicians…');start='';slotLabel='';requestId='';error.textContent='';
    const service=selectedService();$('service-info').textContent=service?`${service.duration} minutes · ${price(service)}`:'';summary();
    if(!service){options(f.clinician,[],'Choose a service first');navigation();return;}
    setBusy(true,'Loading clinicians from Jelly…');
    try{const result=await api('clinicians',{location:location.id,service:service.id});if(rev!==revision)return;users=result;options(f.clinician,users,'Choose a clinician');preloadClinicianPhotos(users);if(!users.length)error.textContent='No clinicians are available for this service.';}
    catch(e){if(rev===revision){options(f.clinician,[],'Could not load clinicians');fail(e);}}
    finally{if(rev===revision)setBusy(false);}
  }
  f.clinician.addEventListener('change',()=>{start='';slotLabel='';requestId='';summary();navigation();prefetchAvailability();});
  async function loadSlots() {
    const rev=++revision;currentSlots=[];start='';slotLabel='';requestId='';error.textContent='';summary();$('slots').replaceChildren();
    if(!f.date.value){$('slots').textContent='Choose a date to see available times.';navigation();return;}
    if(!f.date.reportValidity())return;
    const cached=slotCache.get(slotKey(selectionParams(),f.date.value));
    if(cached && cached.expires>Date.now()){currentSlots=cached.rows;renderSlots();return;}
    setBusy(true,'Checking availability with Jelly…');
    try{const rows=await api('slots',{location:location.id,service:f.service.value,clinician:f.clinician.value,date:f.date.value});if(rev!==revision)return;
      currentSlots=rows;renderSlots();
    }catch(e){if(rev===revision)fail(e);}finally{if(rev===revision)setBusy(false);}
  }
  f.date.addEventListener('change',()=>{if(busy)return;$('date-list').value=f.date.value;renderDatePopup();loadSlots();});
  $('next').addEventListener('click',async()=>{
    if(busy)return;
    if(step===0&&location){show(1);await loadServices();}
    else if(step===1&&f.service.reportValidity()&&f.clinician.reportValidity()){show(2);await loadAvailableDates();}
    else if(step===2&&start)show(3);
  });
  $('back').addEventListener('click',()=>{if(!busy)show(step-1);});
  f.existing.addEventListener('change',()=>{$('portal').hidden=!f.existing.checked;f.portalPassword.required=f.existing.checked&&JDB.submissionsEnabled;f.portalPassword.disabled=!JDB.submissionsEnabled;if(!f.existing.checked)f.portalPassword.value='';requestId='';});
  let stripeClient=null,stripeElements=null,stripeToken=null,stripePayBusy=false;
  let embeddedStripe=null,stripeScriptPromise=null,stripeVerifying=false;
  function loadStripeScript(){
    if(window.Stripe)return Promise.resolve();
    if(!stripeScriptPromise)stripeScriptPromise=new Promise((resolve,reject)=>{const script=document.createElement('script');script.src='https://js.stripe.com/v3/';script.onload=resolve;script.onerror=()=>{stripeScriptPromise=null;reject(new Error('Stripe could not load. Check your connection and retry.'));};document.head.append(script);});
    return stripeScriptPromise;
  }
  function closeStripeCheckout(){if(embeddedStripe){embeddedStripe.destroy();embeddedStripe=null;}stripeClient=null;stripeElements=null;stripeToken=null;$('stripe-pay').disabled=true;$('stripe-panel').hidden=true;$('stripe-checkout').replaceChildren();$('submit').hidden=step!==3;}
  async function verifyEmbeddedPayment(token){
    if(stripeVerifying)return;stripeVerifying=true;setBusy(true,'Verifying Stripe test payment…');
    try{const result=await api('stripe-test-status',{token});if(!result.paid)throw new Error(result.message);
      closeStripeCheckout();$('finish-label').textContent='STRIPE TEST PAYMENT VERIFIED';$('finish-heading').textContent='Sandbox checkout complete';$('reference').textContent='£1 GBP test payment only — no appointment booked.';$('finish-note').textContent=result.message;show(4);
    }catch(e){$('stripe-message').textContent=e.message;}finally{stripeVerifying=false;setBusy(false);}
  }
  async function mountStripeCheckout(){
    if(embeddedStripe){$('stripe-panel').hidden=false;$('stripe-panel').scrollIntoView({block:'start',behavior:'smooth'});return;}
    await loadStripeScript();const result=await api('stripe-test-checkout',{},'POST');
    stripeClient=window.Stripe(result.publishableKey);stripeToken=result.token;
    stripeElements=stripeClient.elements({clientSecret:result.clientSecret,appearance:{theme:'stripe',variables:{colorPrimary:'#1f6b3a',colorText:'#17231b',colorBackground:'#ffffff',borderRadius:'8px',fontFamily:'Arial, sans-serif',spacingUnit:'4px'},rules:{'.Input':{border:'1px solid #d9e3dc',boxShadow:'none'},'.Label':{fontWeight:'500'}}}});
    embeddedStripe=stripeElements.create('payment',{layout:'tabs',wallets:{applePay:'never',googlePay:'never'}});
    $('stripe-panel').hidden=false;$('stripe-message').textContent='Loading secure card fields…';$('stripe-pay').disabled=true;
    embeddedStripe.on('ready',()=>{$('stripe-message').textContent='Use Stripe test cards only.';$('stripe-pay').disabled=false;});
    embeddedStripe.on('loaderror',()=>{$('stripe-message').textContent='Card fields could not load. Cancel and try again.';});
    embeddedStripe.mount('#jdb-stripe-checkout');$('stripe-panel').scrollIntoView({block:'start',behavior:'smooth'});
  }
  $('stripe-pay').addEventListener('click',async()=>{
    if(stripePayBusy||!stripeElements)return;stripePayBusy=true;$('stripe-pay').disabled=true;$('stripe-cancel').disabled=true;setBusy(true,'Processing £1 test payment…');
    try{const submitted=await stripeElements.submit();if(submitted.error)throw new Error(submitted.error.message);
      const url=new URL(window.location.href);url.search='';url.searchParams.set('stripe_test','return');url.searchParams.set('test_token',stripeToken);
      const result=await stripeClient.confirmPayment({elements:stripeElements,confirmParams:{return_url:url.href},redirect:'if_required'});
      if(result.error)throw new Error(result.error.message);await verifyEmbeddedPayment(stripeToken);
    }catch(e){$('stripe-message').textContent=e.message;}finally{stripePayBusy=false;$('stripe-pay').disabled=false;$('stripe-cancel').disabled=false;setBusy(false);}
  });
  $('stripe-cancel').addEventListener('click',()=>{if(stripeVerifying)return;closeStripeCheckout();$('submit').focus();status.textContent='Test checkout closed. No Jelly booking was created.';});
  $('back').addEventListener('click',()=>{if(!busy)closeStripeCheckout();});
  $('retry').addEventListener('click',loadLocations);
  form.addEventListener('submit',async event=>{
    event.preventDefault();if(step!==3||busy)return;
    const required=[f.firstName,f.lastName,f.email,f.dob,f.consent,...(f.existing.checked&&JDB.submissionsEnabled?[f.portalPassword]:[])];
    if(!required.every(el=>el.reportValidity()))return;
    if(!JDB.submissionsEnabled){
      setBusy(true,'Loading Stripe test card form — no real charge…');error.textContent='';
      try{await mountStripeCheckout();}
      catch(e){fail(e);}finally{setBusy(false);}return;
    }

    const body={location:location.id,service:f.service.value,clinician:f.clinician.value,date:f.date.value,start,firstName:f.firstName.value,lastName:f.lastName.value,email:f.email.value,mobile:f.mobile.value,dob:f.dob.value,existing:f.existing.checked,portalPassword:f.existing.checked?f.portalPassword.value:'',consent:f.consent.checked};
    requestId ||= crypto.randomUUID();body.requestId=requestId;
    $('finish-label').textContent='APPOINTMENT CONFIRMED';$('finish-heading').textContent='Your appointment is booked.';
    setBusy(true,'Sending your booking to Jelly. Please keep this page open…');error.textContent='';
    try{const result=await api('bookings',body,'POST');if(result.status!=='confirmed')throw new Error('The appointment has not been confirmed. Please contact the clinic.');f.portalPassword.value='';$('reference').textContent='Booking reference: '+result.reference;$('finish-note').textContent=result.environment==='test'?'Saved in Jelly’s test system. This is not a real clinical appointment.':'Jelly has confirmed this appointment. No online payment was collected; contact the clinic about any outstanding fees.';show(4);}
    catch(e){fail(e);}finally{setBusy(false);}
  });
  $('again').addEventListener('click',()=>window.location.reload());
  $('payment-success').addEventListener('click',()=>{
    $('payment-dialog').close();
    $('finish-label').textContent='PAYMENT PREVIEW COMPLETE';
    $('finish-heading').textContent='The checkout preview is complete.';
    $('reference').textContent='No real payment or booking reference was issued.';
    $('finish-note').textContent='Simulated success only. No card was charged, no patient details were sent and no appointment was created.';
    show(4);
  });
  $('payment-fail').addEventListener('click',()=>{$('payment-status').textContent='Simulated payment failure. No booking is confirmed. You can retry the simulation or cancel.';});
  $('payment-cancel').addEventListener('click',()=>{$('payment-dialog').close();error.textContent='Payment preview cancelled. No payment or booking was created.';$('submit').focus();});
  $('payment-dialog').addEventListener('close',()=>document.documentElement.classList.remove('jdb-modal-open'));
  $('payment-dialog').addEventListener('cancel',()=>{error.textContent='Payment preview cancelled. No payment or booking was created.';});
  async function initialiseBooking(){
    show(0,false);await loadLocations();const query=new URLSearchParams(window.location.search);
    if(query.get('stripe_test')==='cancel'){error.textContent='Stripe test checkout cancelled. No appointment was created.';return;}
    if(query.get('stripe_test')==='return'){
      setBusy(true,'Verifying Stripe sandbox payment…');
      try{const result=await api('stripe-test-status',{token:query.get('test_token')||''});if(!result.paid)throw new Error(result.message);
        $('finish-label').textContent='STRIPE TEST PAYMENT VERIFIED';$('finish-heading').textContent='Sandbox checkout complete';$('reference').textContent='£1 GBP test payment only — no appointment booked.';$('finish-note').textContent=result.message;show(4);
      }catch(e){fail(e);}finally{setBusy(false);window.history.replaceState({},'',window.location.pathname);}
    }
  }
  initialiseBooking();
})();
