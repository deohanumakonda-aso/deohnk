<?php
// Login widget include (Tailwind + JS). Redesigned per user request.
?>
<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
<div id="tds-login" class="bg-white rounded-lg shadow-lg p-4" aria-live="polite">
    <div class="mb-3">
        <h3 class="text-lg font-semibold">District Education Login</h3>
    </div>

    <!-- Role tabs: Admin, DEO, MEO, HM (Teacher removed) -->
    <div role="tablist" aria-label="Login roles" class="flex gap-1 mb-3" id="roleTabs">
        <button role="tab" aria-selected="true" id="tab-ADMIN" class="px-2 py-1 rounded text-sm border bg-blue-600 text-white" onclick="switchRole('ADMIN')">Admin</button>
        <button role="tab" aria-selected="false" id="tab-DEO" class="px-2 py-1 rounded text-sm border" onclick="switchRole('DEO')">DEO</button>
        <button role="tab" aria-selected="false" id="tab-MEO" class="px-2 py-1 rounded text-sm border" onclick="switchRole('MEO')">MEO</button>
        <button role="tab" aria-selected="false" id="tab-HM" class="px-2 py-1 rounded text-sm border" onclick="switchRole('HM')">HM</button>
    </div>

    <form id="tdsLoginForm" class="space-y-3" onsubmit="return validateAndLogin(event)">
        <input type="hidden" name="loginRole" id="loginRole" value="ADMIN">

        <!-- Role-specific container (fields shown/hidden per role) -->
        <div id="roleFields">
            <!-- Admin fields -->
            <div class="role-block" data-role="ADMIN">
                <label class="block text-sm font-medium">Email Id</label>
                <input id="adminEmail" name="adminEmail" type="email" placeholder="name@example.com" class="mt-1 block w-full border rounded px-2 py-1 text-sm" />

                <label class="block text-sm font-medium mt-2">Mobile No</label>
                <input id="adminMobile" name="adminMobile" type="tel" pattern="[0-9]{10}" placeholder="10-digit Mobile No" class="mt-1 block w-full border rounded px-2 py-1 text-sm" />

                <label class="block text-sm font-medium mt-2">Password</label>
                <input id="adminPassword" name="adminPassword" type="password" minlength="8" placeholder="Your prefixed security password" class="mt-1 block w-full border rounded px-2 py-1 text-sm" />

                <label class="block text-sm font-medium mt-2">Sms text (OTP)</label>
                <input id="adminSmsOtp" name="adminSmsOtp" type="text" minlength="6" maxlength="6" placeholder="Enter 6-digit OTP" class="mt-1 block w-full border rounded px-2 py-1 text-sm" />
            </div>

            <!-- DEO fields -->
            <div class="role-block hidden" data-role="DEO">
                <label class="block text-sm font-medium">District</label>
                <select id="deoDistrict" name="deoDistrict" class="mt-1 block w-full border rounded px-2 py-1 text-sm"></select>

                <label class="block text-sm font-medium mt-2">Email Id</label>
                <input id="deoEmail" name="deoEmail" type="email" placeholder="name@example.com" class="mt-1 block w-full border rounded px-2 py-1 text-sm" />

                <label class="block text-sm font-medium mt-2">Mobile No</label>
                <input id="deoMobile" name="deoMobile" type="tel" pattern="[0-9]{10}" placeholder="10-digit Mobile No" class="mt-1 block w-full border rounded px-2 py-1 text-sm" />

                <label class="block text-sm font-medium mt-2">Password</label>
                <input id="deoPassword" name="deoPassword" type="password" minlength="8" placeholder="Your prefixed security password" class="mt-1 block w-full border rounded px-2 py-1 text-sm" />

                <label class="block text-sm font-medium mt-2">Sms text (OTP)</label>
                <input id="deoSmsOtp" name="deoSmsOtp" type="text" minlength="6" maxlength="6" placeholder="Enter 6-digit OTP" class="mt-1 block w-full border rounded px-2 py-1 text-sm" />
            </div>

            <!-- MEO fields -->
            <div class="role-block hidden" data-role="MEO">
                <label class="block text-sm font-medium">District</label>
                <select id="meoDistrict" name="meoDistrict" class="mt-1 block w-full border rounded px-2 py-1 text-sm"></select>

                <label class="block text-sm font-medium mt-2">Mandal</label>
                <select id="meoMandal" name="meoMandal" class="mt-1 block w-full border rounded px-2 py-1 text-sm"></select>

                <label class="block text-sm font-medium mt-2">Mobile No</label>
                <input id="meoMobile" name="meoMobile" type="tel" pattern="[0-9]{10}" placeholder="10-digit Mobile No" class="mt-1 block w-full border rounded px-2 py-1 text-sm" />

                <label class="block text-sm font-medium mt-2">Password</label>
                <input id="meoPassword" name="meoPassword" type="password" minlength="8" placeholder="Your prefixed security password" class="mt-1 block w-full border rounded px-2 py-1 text-sm" />

                <label class="block text-sm font-medium mt-2">Sms text (OTP)</label>
                <input id="meoSmsOtp" name="meoSmsOtp" type="text" minlength="6" maxlength="6" placeholder="Enter 6-digit OTP" class="mt-1 block w-full border rounded px-2 py-1 text-sm" />
            </div>

            <!-- HM fields -->
            <div class="role-block hidden" data-role="HM">
                <label class="block text-sm font-medium">District</label>
                <select id="hmDistrict" name="hmDistrict" class="mt-1 block w-full border rounded px-2 py-1 text-sm"></select>

                <label class="block text-sm font-medium mt-2">Mandal</label>
                <select id="hmMandal" name="hmMandal" class="mt-1 block w-full border rounded px-2 py-1 text-sm"></select>

                <label class="block text-sm font-medium mt-2">School (Code - Name)</label>
                <select id="hmSchool" name="hmSchool" class="mt-1 block w-full border rounded px-2 py-1 text-sm"></select>

                <label class="block text-sm font-medium mt-2">Mobile No</label>
                <input id="hmMobile" name="hmMobile" type="tel" pattern="[0-9]{10}" placeholder="10-digit Mobile No" class="mt-1 block w-full border rounded px-2 py-1 text-sm" />

                <label class="block text-sm font-medium mt-2">Password</label>
                <input id="hmPassword" name="hmPassword" type="password" minlength="8" placeholder="Your prefixed security password" class="mt-1 block w-full border rounded px-2 py-1 text-sm" />

                <label class="block text-sm font-medium mt-2">Sms text (OTP)</label>
                <input id="hmSmsOtp" name="hmSmsOtp" type="text" minlength="6" maxlength="6" placeholder="Enter 6-digit OTP" class="mt-1 block w-full border rounded px-2 py-1 text-sm" />
            </div>
        </div>

        <!-- Captcha shared across roles -->
        <div class="flex items-center gap-2">
            <div class="flex-1">
                <label class="block text-sm font-medium">Captcha Input</label>
                <input id="captchaInput" name="captchaInput" type="text" required placeholder="Enter the security code shown below" class="mt-1 block w-full border rounded px-2 py-1 text-sm" />
            </div>
            <div class="w-36 text-center">
                <div id="captchaDisplay" class="bg-gray-100 border rounded px-2 py-2 text-lg font-mono tracking-wider select-all">ABC123</div>
                <button type="button" onclick="generateCaptcha()" title="Refresh Captcha" class="mt-1 text-xs text-blue-600">Refresh</button>
            </div>
        </div>

        <div class="flex items-center justify-between mt-2">
            <a href="/forgot-password.html" class="text-xs text-gray-600">Forgot Password?</a>
            <button type="submit" class="bg-blue-600 text-white px-3 py-1 rounded text-sm">Login</button>
        </div>
    </form>
</div>

<script>
// Role switching and UI management
function switchRole(newRole){
    var roles = ['ADMIN','DEO','MEO','HM'];
    roles.forEach(function(r){
        var btn = document.getElementById('tab-'+r);
        var blocks = document.querySelectorAll('.role-block[data-role="'+r+'"]');
        if (btn) {
            if (r === newRole){ btn.classList.add('bg-blue-600','text-white'); btn.setAttribute('aria-selected','true'); } else { btn.classList.remove('bg-blue-600','text-white'); btn.setAttribute('aria-selected','false'); }
        }
        blocks.forEach(function(b){ if (r === newRole) b.classList.remove('hidden'); else b.classList.add('hidden'); });
    });
    var hf = document.getElementById('loginRole'); if (hf) hf.value = newRole;
}

// Simple captcha generator (client-side only)
function generateCaptcha(){
    var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789abcdefghjkmnpqrstuvwxyz';
    var s = '';
    for (var i=0;i<6;i++){ s += chars.charAt(Math.floor(Math.random()*chars.length)); }
    var disp = document.getElementById('captchaDisplay'); if (disp) disp.textContent = s; window._tds_current_captcha = s;
}

// Fill district selects from server (distinct division values)
function populateDistrictSelects(){
    var url = 'ajax_get_schools.php';
    fetch(url)
    .then(function(r){ return r.json(); })
    .then(function(data){
        var sets = ['deoDistrict','meoDistrict','hmDistrict'];
        var list = (data.divisions || []);
        sets.forEach(function(id){ var sel = document.getElementById(id); if (!sel) return; sel.innerHTML = '<option value="">--Select District--</option>'; list.forEach(function(d){ var o = document.createElement('option'); o.value = d; o.textContent = d; sel.appendChild(o); }); });
    }).catch(function(){ console.warn('Could not fetch districts for selects.'); });
}

// When a district changes, populate mandals via existing ajax endpoint
function onDistrictChange(role){
    var districtSel = document.getElementById(role.toLowerCase()+'District');
    var mandalSel = document.getElementById(role.toLowerCase()+'Mandal');
    if (!districtSel || !mandalSel) return;
    var district = districtSel.value;
    mandalSel.innerHTML = '<option value="">Loading...</option>';
    fetch('ajax_get_schools.php?division='+encodeURIComponent(district))
        .then(function(r){ return r.json(); })
        .then(function(data){
            mandalSel.innerHTML = '<option value="">--Select Mandal--</option>';
            (data.mandals||[]).forEach(function(m){ var o = document.createElement('option'); o.value = m; o.textContent = m; mandalSel.appendChild(o); });
        }).catch(function(){ mandalSel.innerHTML = '<option value="">--Error--</option>'; });
}

// When a mandal changes for HM, populate schools
function onMandalChangeForHM(){
    var district = document.getElementById('hmDistrict').value;
    var mandal = document.getElementById('hmMandal').value;
    var schoolSel = document.getElementById('hmSchool');
    if (!schoolSel) return;
    schoolSel.innerHTML = '<option value="">Loading...</option>';
    var url = 'ajax_get_schools.php?division='+encodeURIComponent(district)+'&mandal='+encodeURIComponent(mandal);
    fetch(url)
        .then(function(r){ return r.json(); })
        .then(function(data){
            schoolSel.innerHTML = '<option value="">--Select School--</option>';
            (data.schools||[]).forEach(function(sc){ var o = document.createElement('option'); o.value = sc.scode || sc.sname; o.textContent = (sc.scode? (sc.scode + ' - ') : '') + sc.sname; schoolSel.appendChild(o); });
        }).catch(function(){ schoolSel.innerHTML = '<option value="">--Error--</option>'; });
}

// Validate form fields per role and ensure captcha matches. Backend integration required for OTP verification.
function validateAndLogin(e){
    e.preventDefault();
    var role = document.getElementById('loginRole').value;
    var cap = document.getElementById('captchaInput').value.trim();
    var expected = window._tds_current_captcha || document.getElementById('captchaDisplay').textContent;
    if (cap !== expected){ alert('Captcha does not match.'); generateCaptcha(); return false; }

    var payload = { role: role };
    // Role-specific validation
    if (role === 'ADMIN'){
        var email = document.getElementById('adminEmail').value.trim();
        var mobile = document.getElementById('adminMobile').value.trim();
        var pwd = document.getElementById('adminPassword').value;
        var otp = document.getElementById('adminSmsOtp').value.trim();
        if (!email || !mobile || !pwd || !otp){ alert('Please fill all Admin fields.'); return false; }
        if (!/^[0-9]{10}$/.test(mobile)){ alert('Enter valid 10-digit mobile.'); return false; }
        payload.email = email; payload.mobile = mobile; payload.password = pwd; payload.otp = otp;
    } else if (role === 'DEO'){
        var district = document.getElementById('deoDistrict').value;
        var email = document.getElementById('deoEmail').value.trim();
        var mobile = document.getElementById('deoMobile').value.trim();
        var pwd = document.getElementById('deoPassword').value;
        var otp = document.getElementById('deoSmsOtp').value.trim();
        if (!district || !email || !mobile || !pwd || !otp){ alert('Please fill all DEO fields.'); return false; }
        if (!/^[0-9]{10}$/.test(mobile)){ alert('Enter valid 10-digit mobile.'); return false; }
        payload.district = district; payload.email = email; payload.mobile = mobile; payload.password = pwd; payload.otp = otp;
    } else if (role === 'MEO'){
        var district = document.getElementById('meoDistrict').value;
        var mandal = document.getElementById('meoMandal').value;
        var mobile = document.getElementById('meoMobile').value.trim();
        var pwd = document.getElementById('meoPassword').value;
        var otp = document.getElementById('meoSmsOtp').value.trim();
        if (!district || !mandal || !mobile || !pwd || !otp){ alert('Please fill all MEO fields.'); return false; }
        if (!/^[0-9]{10}$/.test(mobile)){ alert('Enter valid 10-digit mobile.'); return false; }
        payload.district = district; payload.mandal = mandal; payload.mobile = mobile; payload.password = pwd; payload.otp = otp;
    } else if (role === 'HM'){
        var district = document.getElementById('hmDistrict').value;
        var mandal = document.getElementById('hmMandal').value;
        var school = document.getElementById('hmSchool').value;
        var mobile = document.getElementById('hmMobile').value.trim();
        var pwd = document.getElementById('hmPassword').value;
        var otp = document.getElementById('hmSmsOtp').value.trim();
        if (!district || !mandal || !school || !mobile || !pwd || !otp){ alert('Please fill all HM fields.'); return false; }
        if (!/^[0-9]{10}$/.test(mobile)){ alert('Enter valid 10-digit mobile.'); return false; }
        payload.district = district; payload.mandal = mandal; payload.school = school; payload.mobile = mobile; payload.password = pwd; payload.otp = otp;
    }

    // At this point payload is ready to POST to server for authentication.
    // The server must:
    //  - Validate the supplied identifier (email/mobile) exists and belongs to role + selected district/mandal/school
    //  - Validate the prefixed password according to server-side rules
    //  - Generate and send OTP to the mobile number stored in DB (don't rely on client-supplied mobile)
    //  - On OTP verification, establish a secure session cookie

    // For now, show a friendly message (replace with real AJAX call to login API)
    alert('Client-side validation passed for role ' + role + '. Ready to submit to server.');
    return false;
}

// Wire up cascaded select event handlers
document.addEventListener('DOMContentLoaded', function(){
    generateCaptcha();
    switchRole('ADMIN');


    // populate district selects with a small AJAX call to get distinct divisions from school_list
    fetch('ajax_get_schools.php')
        .then(function(r){ return r.json(); })
        .then(function(data){
            var list = (data.divisions || []);
            ['deoDistrict','meoDistrict','hmDistrict'].forEach(function(id){
                var sel = document.getElementById(id); if (!sel) return;
                sel.innerHTML = '<option value="">--Select District--</option>';
                list.forEach(function(d){ var o = document.createElement('option'); o.value = d; o.textContent = d; sel.appendChild(o); });
            });
        }).catch(function(){ console.warn('Could not fetch district list.'); });

    // Attach change handlers
    var deoD = document.getElementById('deoDistrict'); if (deoD) deoD.addEventListener('change', function(){ onDistrictChange('DEO'); });
    var meoD = document.getElementById('meoDistrict'); if (meoD) meoD.addEventListener('change', function(){ onDistrictChange('MEO'); });
    var hmD = document.getElementById('hmDistrict'); if (hmD) hmD.addEventListener('change', function(){ onDistrictChange('HM'); });
    var meoM = document.getElementById('meoMandal'); if (meoM) meoM.addEventListener('change', function(){ /* no-op for MEO beyond mandal */ });
    var hmM = document.getElementById('hmMandal'); if (hmM) hmM.addEventListener('change', onMandalChangeForHM);
});
</script>
