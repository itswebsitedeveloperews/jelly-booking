(() => {
  'use strict';
  const form = document.querySelector('#jdb-form'); if (!form) return;
  const $ = id => document.querySelector('#jdb-' + id), f = form.elements;
  let step=0, location=null, services=[], users=[], start='', slotLabel='', requestId='', busy=false, session={}, revision=0;
  const error = $('error'), status = $('status');
  const selectedService = () => services.find(s => s.id === f.service.value);
  const selectedUser = () => users.find(u => u.id === f.clinician.value);
  const price = s => s?.cost == null ? 'Price to be confirmed by the clinic' : 'Listed fee: ' + new Intl.NumberFormat('en-GB',{style:'currency',currency:JDB.currency||'GBP'}).format(Number(s.cost));
  function text(tag, value) { const el=document.createElement(tag); el.textContent=value; return el; }
  function summary(target='summary') {
    const box=$(target); box.replaceChildren(); const service=selectedService(), user=selectedUser();
    [location?.name,service?.name,service?`${service.duration} minutes · ${price(service)}`:'',user?.name,f.date.value?`${f.date.value}${slotLabel?' at '+slotLabel:''} (${JDB.timezone})`:''].filter(Boolean).forEach(v=>box.append(text('p',v)));
    if(!box.children.length) box.textContent='Your selection will appear here.';
  }
  function navigation() {
    $('back').hidden=step===0 || step===4; $('next').hidden=step>=3; $('submit').hidden=step!==3;
    $('next').disabled=busy || (step===0&&!location) || (step===1&&(!f.service.value||!f.clinician.value)) || (step===2&&!start);
    $('submit').disabled=busy; $('back').disabled=busy;
  }
  function show(n, focus=true) {
    step=n; error.textContent=''; status.textContent=''; $('retry').hidden=true;
    document.querySelectorAll('[data-step]').forEach(el=>{el.hidden=Number(el.dataset.step)!==n;});
    document.querySelectorAll('.jdb-steps li').forEach((el,i)=>{if(i===n)el.setAttribute('aria-current','step');else el.removeAttribute('aria-current');});
    if(n===3)summary('review-details'); summary(); navigation();
    if(focus){const h=document.querySelector(`[data-step="${n}"] h2`);h.tabIndex=-1;h.focus();}
  }
  function setBusy(value,message='') { busy=value; status.textContent=message; form.setAttribute('aria-busy',String(value)); navigation(); }
  async function refreshSession() {
    const response=await fetch(JDB.session,{credentials:'same-origin',cache:'no-store'});
    const result=await response.json(); if(!result.success)throw new Error('Could not start the booking session. Reload this page.'); session=result.data;
  }
  async function api(path, params={}, method='GET', retried=false) {
    const url=new URL(JDB.api+path);
    if(method==='GET')Object.entries(params).forEach(([k,v])=>url.searchParams.set(k,v));
    const response=await fetch(url,{method,credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-JDB-Nonce':session.nonce,'X-WP-Nonce':session.restNonce},...(method==='POST'?{body:JSON.stringify(params)}:{})});
    let result; try{result=await response.json();}catch{throw new Error('The booking server returned an unreadable response. Please contact the clinic.');}
    if(!response.ok){if(!retried&&method==='GET'&&['nonce','rest_cookie_invalid_nonce'].includes(result.code)){await refreshSession();return api(path,params,method,true);}throw new Error(result.message||'Could not complete this request. Please try again.');}
    return result;
  }
  function options(select, rows, label) { select.replaceChildren(new Option(label,'')); rows.forEach(r=>select.add(new Option(r.name,r.id))); }
  function fail(e,retry=false) { error.textContent=e.message; $('retry').hidden=!retry; }
  async function loadLocations() {
    setBusy(true,'Loading clinic locations from Jelly…'); error.textContent=''; $('retry').hidden=true;
    try{
      await refreshSession(); const rows=await api('locations'); $('locations').replaceChildren();
      if(!rows.length){status.textContent='No bookable locations were returned. Please contact the clinic.';return;}
      rows.forEach(row=>{
        const label=text('label',''); label.className='jdb-location';
        const input=document.createElement('input'); input.type='radio';input.name='location';input.value=row.id;
        input.addEventListener('change',()=>{location=row;f.service.value='';f.clinician.value='';services=[];users=[];start='';requestId='';summary();navigation();});
        const info=document.createElement('span'); info.append(text('strong',row.name));
        if(row.address)info.append(text('span',row.address));if(row.phone)info.append(text('span','Phone: '+row.phone));
        label.append(input,info); $('locations').append(label);
      }); status.textContent='';
    }catch(e){status.textContent='';fail(e,true);}finally{busy=false;form.setAttribute('aria-busy','false');navigation();}
  }
  async function loadServices() {
    setBusy(true,'Loading services from Jelly…');
    try{services=await api('services',{location:location.id});options(f.service,services,'Choose a service');options(f.clinician,[],'Choose a service first');$('service-info').textContent='';if(!services.length)error.textContent='No online services are available for this location.';}
    catch(e){fail(e);}finally{setBusy(false);}
  }
  f.service.addEventListener('change',async()=>{
    const rev=++revision; users=[];options(f.clinician,[],'Loading clinicians…');start='';slotLabel='';requestId='';error.textContent='';
    const service=selectedService();$('service-info').textContent=service?`${service.duration} minutes · ${price(service)}`:'';summary();
    if(!service){options(f.clinician,[],'Choose a service first');navigation();return;}
    setBusy(true,'Loading clinicians from Jelly…');
    try{const result=await api('clinicians',{location:location.id,service:service.id});if(rev!==revision)return;users=result;options(f.clinician,users,'Choose a clinician');if(!users.length)error.textContent='No clinicians are available for this service.';}
    catch(e){if(rev===revision){options(f.clinician,[],'Could not load clinicians');fail(e);}}
    finally{if(rev===revision)setBusy(false);}
  });
  f.clinician.addEventListener('change',()=>{start='';slotLabel='';requestId='';summary();navigation();});
  async function loadSlots() {
    const rev=++revision;start='';slotLabel='';requestId='';error.textContent='';summary();$('slots').replaceChildren();
    if(!f.date.value){$('slots').textContent='Choose a date to see available times.';navigation();return;}
    if(!f.date.reportValidity())return;
    setBusy(true,'Checking availability with Jelly…');
    try{const rows=await api('slots',{location:location.id,service:f.service.value,clinician:f.clinician.value,date:f.date.value});if(rev!==revision)return;
      if(!rows.length)$('slots').textContent='No times are available on this date. Please choose another date.';
      rows.forEach(row=>{const label=text('label','');label.className='jdb-slot';const input=document.createElement('input');input.type='radio';input.name='start';input.value=row.start;input.addEventListener('change',()=>{start=row.start;slotLabel=row.label;requestId='';summary();navigation();});label.append(input,document.createTextNode(row.label));$('slots').append(label);});
    }catch(e){if(rev===revision)fail(e);}finally{if(rev===revision)setBusy(false);}
  }
  f.date.addEventListener('change',loadSlots);
  $('next').addEventListener('click',async()=>{
    if(busy)return;
    if(step===0&&location){show(1);await loadServices();}
    else if(step===1&&f.service.reportValidity()&&f.clinician.reportValidity()){show(2);await loadSlots();}
    else if(step===2&&start)show(3);
  });
  $('back').addEventListener('click',()=>{if(!busy)show(step-1);});
  f.existing.addEventListener('change',()=>{$('portal').hidden=!f.existing.checked;f.portalPassword.required=f.existing.checked;if(!f.existing.checked)f.portalPassword.value='';requestId='';});
  $('retry').addEventListener('click',loadLocations);
  form.addEventListener('submit',async event=>{
    event.preventDefault();if(step!==3||busy)return;
    const required=[f.firstName,f.lastName,f.email,f.dob,f.consent,...(f.existing.checked?[f.portalPassword]:[])];
    if(!required.every(el=>el.reportValidity()))return;
    const body={location:location.id,service:f.service.value,clinician:f.clinician.value,date:f.date.value,start,firstName:f.firstName.value,lastName:f.lastName.value,email:f.email.value,mobile:f.mobile.value,dob:f.dob.value,existing:f.existing.checked,portalPassword:f.existing.checked?f.portalPassword.value:'',consent:f.consent.checked};
    requestId ||= crypto.randomUUID();body.requestId=requestId;
    setBusy(true,'Sending your booking to Jelly. Please keep this page open…');error.textContent='';
    try{const result=await api('bookings',body,'POST');if(result.status!=='confirmed')throw new Error('The appointment has not been confirmed. Please contact the clinic.');f.portalPassword.value='';$('reference').textContent='Booking reference: '+result.reference;$('finish-note').textContent=result.environment==='test'?'Saved in Jelly’s test system. This is not a real clinical appointment.':'Jelly has confirmed this appointment. No online payment was collected; contact the clinic about any outstanding fees.';show(4);}
    catch(e){fail(e);}finally{setBusy(false);}
  });
  $('again').addEventListener('click',()=>window.location.reload());
  show(0,false);loadLocations();
})();
