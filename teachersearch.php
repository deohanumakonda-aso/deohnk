
<?php
session_start(); // ensure session is started for admin detection
require_once 'includes/db_connect.php';
include 'includes/header.php';
?>

<?php
// Populate select lists with distinct values (used by tabs and initial page load)
$division = [];
$schmandal = [];
$school = [];
try {
    $q = $conn->query("SELECT DISTINCT COALESCE(NULLIF(division,''),'Unknown') AS division FROM teacherdata ORDER BY division");
    if ($q) { while ($r = $q->fetch_assoc()) $division[] = $r['division']; }
    $q2 = $conn->query("SELECT DISTINCT COALESCE(NULLIF(SchMandal,''),'Unknown') AS schmandal FROM teacherdata ORDER BY schmandal");
    if ($q2) { while ($r = $q2->fetch_assoc()) $schmandal[] = $r['schmandal']; }
    $q3 = $conn->query("SELECT DISTINCT COALESCE(NULLIF(SchName,''),'Unknown') AS school FROM teacherdata ORDER BY school");
    if ($q3) { while ($r = $q3->fetch_assoc()) $school[] = $r['school']; }
} catch (Exception $e) {
    // on error fallback to empty arrays
    $division = $division ?? [];
    $schmandal = $schmandal ?? [];
    $school = $school ?? [];
}
?>

<div class="container main-body">
    <?php include 'includes/left_sidebar.php'; ?>

    <main class="main-content">

        <div class="tabs" style="margin-bottom:14px;">
            <button class="tablink" data-tab="treasury" onclick="openTab(event,'byTreasury')">By Treasury</button>
            <button class="tablink" data-tab="designation" onclick="openTab(event,'byDesignation')">By Designation</button>
            <button class="tablink" data-tab="district" onclick="openTab(event,'byDistrict')">By District</button>
            <button class="tablink" data-tab="mandal" onclick="openTab(event,'byMandal')">By Mandal</button>
            <button class="tablink" data-tab="school" onclick="openTab(event,'bySchool')">By School</button>
        </div>

        <div id="byTreasury" class="tabcontent">
            <form method="get" action="teachersearch.php">
                <input type="hidden" name="tab" value="treasury">
                <label>Treasury Code</label>
                <input type="text" name="treasury_code" value="<?php echo isset($_GET['treasury_code']) ? htmlspecialchars($_GET['treasury_code']) : ''; ?>">
                <button type="submit">Search</button>
            </form>
        </div>

        <div id="byDesignation" class="tabcontent" style="display:none">
            <form method="get" action="teachersearch.php">
                <input type="hidden" name="tab" value="designation">
                <label>Designation</label>
                <select name="designation">
                    <option value="">-- Select Designation --</option>
                    <?php foreach ($designation as $d) echo '<option value="'.htmlspecialchars($d).'">'.htmlspecialchars($d).'</option>'; ?>
                </select>
                <button type="submit">Search</button>
            </form>
        </div>

        <div id="byDistrict" class="tabcontent" style="display:none">
            <form method="get" action="teachersearch.php">
                <input type="hidden" name="tab" value="district">
                <label>Division</label>
                <select name="division">
                    <option value="">-- Select Division --</option>
                    <?php foreach ($division as $d) echo '<option value="'.htmlspecialchars($d).'">'.htmlspecialchars($d).'</option>'; ?>
                </select>
                <button type="submit">Search</button>
            </form>
        </div>

        <div id="byMandal" class="tabcontent" style="display:none">
            <form method="get" action="teachersearch.php">
                <input type="hidden" name="tab" value="mandal">
                <label>Division</label>
                <select id="division" name="division">
                    <option value="">-- Select Division --</option>
                    <?php foreach ($division as $d) echo '<option value="'.htmlspecialchars($d).'">'.htmlspecialchars($d).'</option>'; ?>
                </select>

                <label>SchMandal</label>
                <select id="schmandal" name="schmandal">
                    <option value="">-- Select Mandal --</option>
                    <?php foreach ($schmandal as $s) echo '<option value="'.htmlspecialchars($s).'">'.htmlspecialchars($s).'</option>'; ?>
                </select>
                <button type="submit">Search</button>
            </form>
        </div>

        <div id="bySchool" class="tabcontent" style="display:none">
            <form method="get" action="teachersearch.php">
                <input type="hidden" name="tab" value="school">
                <label>Division</label>
                <select id="division_s" name="division">
                    <option value="">-- Select Division --</option>
                    <?php foreach ($division as $d) echo '<option value="'.htmlspecialchars($d).'">'.htmlspecialchars($d).'</option>'; ?>
                </select>

                <label>SchMandal</label>
                <select id="schmandal_s" name="schmandal">
                    <option value="">-- Select SchMandal --</option>
                    <?php foreach ($schmandal as $s) echo '<option value="'.htmlspecialchars($s).'">'.htmlspecialchars($s).'</option>'; ?>
                </select>

                <label>School (SchMandal values)</label>
                <select id="school" name="school">
                    <option value="">-- Select School --</option>
                    <?php foreach ($school as $s) echo '<option value="'.htmlspecialchars($s).'">'.htmlspecialchars($s).'</option>'; ?>
                </select>

                <label>School Code</label>
                <select id="schcode" name="schcode">
                    <option value="">-- Select School Code --</option>
                </select>
                <button type="submit">Search</button>
            </form>
        </div>

    <div id="search-results">
    <?php
        // Handle search queries and build result set
        $where = [];
        $params = [];
        if (isset($_GET['tab'])) {
            $tab = $_GET['tab'];
            if ($tab === 'treasury' && !empty($_GET['treasury_code'])) {
                $where[] = 'TreasuryCode = ?'; $params[] = $_GET['treasury_code'];
            } elseif ($tab === 'designation' && !empty($_GET['designation'])) {
                // exact match from dropdown
                $where[] = 'Designation = ?'; $params[] = $_GET['designation'];
            } elseif ($tab === 'district') {
                if (!empty($_GET['division'])) { $where[] = 'division = ?'; $params[] = $_GET['division']; }
            } elseif ($tab === 'mandal') {
                if (!empty($_GET['division'])) { $where[] = 'division = ?'; $params[] = $_GET['division']; }
                if (!empty($_GET['schmandal'])) { $where[] = 'SchMandal = ?'; $params[] = $_GET['schmandal']; }
            } elseif ($tab === 'school') {
                if (!empty($_GET['division'])) { $where[] = 'division = ?'; $params[] = $_GET['division']; }
                // school tab uses SchMandal values in the school dropdown per request
                if (!empty($_GET['schmandal'])) { $where[] = 'SchMandal = ?'; $params[] = $_GET['schmandal']; }
                // Prefer SchCode when user selects a code — otherwise filter by SchName if provided
                if (!empty($_GET['schcode'])) { $where[] = 'SchCode = ?'; $params[] = $_GET['schcode']; }
                elseif (!empty($_GET['school'])) { $where[] = 'SchName = ?'; $params[] = $_GET['school']; }
            }
        }

        $results = [];
        // build query only if there are filter criteria to avoid loading all rows on page load
        if (!empty($where)) {
            $sql = 'SELECT TreasuryCode, TchSurName, TchFullName, Designation, SchName, SchMandal, SchCode, division, mobile AS MobileNo FROM teacherdata';
            $sql .= ' WHERE ' . implode(' AND ', $where);
            $sql .= ' ORDER BY TchFullName ASC';

            // pagination
            $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
            $perPage = 50;
            $offset = ($page - 1) * $perPage;
            $sql .= ' LIMIT ? OFFSET ?';

            if ($stmt = $conn->prepare($sql)) {
            // Always bind parameters: the user filter params (if any) + LIMIT and OFFSET (integers)
            $bindParams = [];
            $types = '';
            if (!empty($params)) {
                $types = str_repeat('s', count($params));
                $bindParams = $params;
            }
            // add integer types for limit/offset
            $types .= 'ii';
            $bindParams[] = $perPage;
            $bindParams[] = $offset;

            // Prepare arguments for bind_param as references
            $bindArgs = [];
            // first arg must be the types string
            $bindArgs[] = $types;
            foreach ($bindParams as $k => $v) {
                // create variable to ensure reference stability
                $bindArgs[] = &$bindParams[$k];
            }
            // call bind_param with the assembled args
            call_user_func_array([$stmt, 'bind_param'], $bindArgs);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res) {
                while ($r = $res->fetch_assoc()) $results[] = $r;
            }
            $stmt->close();
            }
        }

        // If nothing searched, show a friendly message
        if (empty($results) && empty($where)) {
            echo '<div class="notice">Please select search criteria and click Search to view results.</div>';
        }

        // Display results
        if (!empty($results)) {
            // Build a contextual header for the results
            $resultsTitle = 'LIST OF TEACHERS';
            $ctx = '';
            if (isset($tab)) {
                if ($tab === 'treasury' && !empty($_GET['treasury_code'])) {
                    $ctx = 'Treasury Code: ' . htmlspecialchars($_GET['treasury_code']);
                } elseif ($tab === 'designation' && !empty($_GET['designation'])) {
                    $ctx = 'Designation: ' . htmlspecialchars($_GET['designation']);
                } elseif ($tab === 'district' && !empty($_GET['division'])) {
                    $ctx = 'Division: ' . htmlspecialchars($_GET['division']);
                } elseif ($tab === 'mandal') {
                    $m = !empty($_GET['schmandal']) ? htmlspecialchars($_GET['schmandal']) : '';
                    $d = !empty($_GET['division']) ? htmlspecialchars($_GET['division']) : '';
                    $ctx = trim('Mandal: ' . $m . ($d ? ' of ' . $d : ''));
                } elseif ($tab === 'school') {
                    if (!empty($_GET['schcode'])) {
                        $ctx = 'School Code: ' . htmlspecialchars($_GET['schcode']);
                    } elseif (!empty($_GET['school'])) {
                        $ctx = 'School: ' . htmlspecialchars($_GET['school']) . ' (' . htmlspecialchars($_GET['schmandal'] ?? '') . ', ' . htmlspecialchars($_GET['division'] ?? '') . ')';
                    }
                }
            }
            if ($ctx) $resultsTitle .= ' - ' . $ctx;
            echo '<h3>' . $resultsTitle . '</h3>';
            $isAdmin = (isset($_SESSION['admin_loggedin']) && $_SESSION['admin_loggedin'] === true);
            // build header depending on admin privileges (hide sensitive columns for public)
            $header = '<tr><th>Sl No.</th>';
            if ($isAdmin) $header .= '<th>TreasuryCode</th>';
            $header .= '<th>TeacherName</th><th>Designation</th><th>SchName</th>';
            if ($isAdmin) $header .= '<th>SchCode</th>';
            $header .= '<th>SchMandal</th><th>Division</th>';
            if ($isAdmin) $header .= '<th>MobileNo</th>';
            $header .= '<th>Action</th></tr>';

            echo '<table class="teacher-table"><thead>' . $header . '</thead><tbody>';
            $sn = 1;
            foreach ($results as $r) {
                $name = trim(($r['TchSurName'] ?? '') . ' ' . ($r['TchFullName'] ?? ''));
                echo '<tr>';
                echo '<td>' . $sn++ . '</td>';
                if ($isAdmin) {
                    echo '<td>' . htmlspecialchars($r['TreasuryCode']) . '</td>';
                }
                echo '<td>' . htmlspecialchars($name) . '</td>';
                echo '<td>' . htmlspecialchars($r['Designation']) . '</td>';
                echo '<td>' . htmlspecialchars($r['SchName'] ?? '') . '</td>';
                if ($isAdmin) {
                    echo '<td>' . htmlspecialchars($r['SchCode'] ?? '') . '</td>';
                }
                echo '<td>' . htmlspecialchars($r['SchMandal'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($r['division'] ?? '') . '</td>';
                if ($isAdmin) {
                    // mask mobile for admin: show first 2 and last 2 digits
                    $rawMob = isset($r['MobileNo']) ? $r['MobileNo'] : '';
                    $digits = preg_replace('/\D+/', '', $rawMob);
                    $len = strlen($digits);
                    if ($len <= 4) {
                        $displayMob = $digits;
                    } else {
                        $first = substr($digits, 0, 2);
                        $last = substr($digits, -2);
                        $middleStars = str_repeat('*', max(0, $len - 4));
                        $displayMob = $first . $middleStars . $last;
                    }
                    echo '<td>' . htmlspecialchars($displayMob) . '</td>';
                }
                echo '<td><a href="#" class="view-link" data-treasury="' . htmlspecialchars($r['TreasuryCode']) . '">View</a></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            // simple pagination links (previous/next)
            echo '<div style="margin-top:10px;">';
            if ($page > 1) echo '<a href="' . htmlspecialchars(preg_replace('/([&?]page=)[0-9]+/', '', $_SERVER['REQUEST_URI'])) . '&page=' . ($page - 1) . '">&laquo; Prev</a> ';
            if (count($results) === $perPage) echo '<a href="' . htmlspecialchars(preg_replace('/([&?]page=)[0-9]+/', '', $_SERVER['REQUEST_URI'])) . '&page=' . ($page + 1) . '">Next &raquo;</a>';
            echo '</div>';
        }
        ?>
    </div>

    </main>

    <?php include 'includes/right_sidebar.php'; ?>
</div>

<?php include 'includes/footer.php'; ?>

<style>
/* improved styles for tabs */
.tabs { margin-bottom:12px; display:flex; gap:8px; flex-wrap:wrap; }
.tablink {
    padding:10px 14px;
    background:#f2f2f2;
    border:1px solid #ccc;
    border-bottom:3px solid #ccc;
    cursor:pointer;
    font-weight:600;
    color:#333;
    border-radius:4px 4px 0 0;
}
.tablink.active, .tablink:hover {
    background:linear-gradient(180deg,#0b74a6,#07719a);
    color:#fff;
    border-color:#07719a;
    box-shadow:0 2px 4px rgba(0,0,0,0.08);
}
.tabcontent { margin:0 0 18px 0; padding:12px; border:1px solid #ddd; background:#fff; border-radius:0 4px 4px 4px }
label { display:block; margin-top:8px; font-weight:600 }
input[type=text], select { padding:6px 8px; margin-top:4px; width:260px; }
button { padding:8px 12px; margin-top:8px; }
/* increase results table font slightly for readability */
.teacher-table { font-size: calc(14px + 2px); }
</style>

<script>
var pageJustLoaded = true;
function openTab(evt, name) {
    var i, tabcontent, tablinks;
    tabcontent = document.getElementsByClassName('tabcontent');
    for (i = 0; i < tabcontent.length; i++) tabcontent[i].style.display = 'none';
    tablinks = document.getElementsByClassName('tablink');
    for (i = 0; i < tablinks.length; i++) tablinks[i].className = tablinks[i].className.replace(' active','');
    // before hiding tabs, save current tab form state
    try { saveCurrentTabState(); } catch(e){}
    document.getElementById(name).style.display = 'block';
    evt.currentTarget.className += ' active';
    // clear any prior search results when switching tabs, but not during initial page auto-open
    if (!pageJustLoaded) {
        var resDiv = document.getElementById('search-results');
        if (resDiv) resDiv.innerHTML = '';
    }
}
// open default tab if provided
document.addEventListener('DOMContentLoaded', function(){
    var requested = '<?php echo isset($_GET['tab']) ? htmlspecialchars($_GET['tab']) : ''; ?>';
    var clicked = false;
    if (requested) {
        var tabs = document.querySelectorAll('.tablink');
        tabs.forEach(function(tb){ if (tb.getAttribute('data-tab') === requested) { tb.click(); clicked = true; } });
    }
    if (!clicked) {
        // open first tab by default
        var first = document.querySelector('.tablink'); if (first) first.click();
    }
    // page initialization complete; subsequent tab clicks are manual
    pageJustLoaded = false;

    // restore state for the active tab if any
    try { restoreTabState(); } catch(e){}

    // populate designation dropdown via AJAX if empty
    var desel = document.querySelector('#byDesignation select[name="designation"]');
    if (desel && desel.options.length <= 1) {
        desel.innerHTML = '<option>Loading...</option>';
        var x = new XMLHttpRequest();
        x.open('GET', 'ajax_get_designations.php', true);
        x.onload = function(){ if (x.status===200) { try { var d = JSON.parse(x.responseText); desel.innerHTML = '<option value="">-- Select Designation --</option>'; (d.designations||[]).forEach(function(v){ var o=document.createElement('option'); o.value=v; o.textContent=v; desel.appendChild(o); }); } catch(e){ console.error(e); } } };
        x.send();
    }
});

// cascading selects using AJAX
function fetchMandalsAndschool(division, mandal, targetMandalId, targetSchoolId) {
    var xhr = new XMLHttpRequest();
    // endpoint returns { schmandals: [...], schools: [...] }
    xhr.open('GET', 'ajax_get_mandals_schools.php?division=' + encodeURIComponent(division) + (mandal ? '&mandal=' + encodeURIComponent(mandal) : ''), true);
    xhr.onload = function() {
        if (xhr.status === 200) {
            try {
                var data = JSON.parse(xhr.responseText);
                var mandalSelect = document.getElementById(targetMandalId);
                var schoolelect = document.getElementById(targetSchoolId);
                if (mandalSelect) {
                    mandalSelect.innerHTML = '<option value="">-- Select SchMandal --</option>';
                    var list = (data.schmandals || []);
                    if (list.length === 0) {
                        var o = document.createElement('option'); o.value = ''; o.textContent = '-- No mandals found --'; mandalSelect.appendChild(o);
                    } else {
                        list.forEach(function(m){ var o = document.createElement('option'); o.value = m; o.textContent = m; mandalSelect.appendChild(o);});
                    }
                }
                if (schoolelect) {
                    schoolelect.innerHTML = '<option value="">-- Select School --</option>';
                    var sl = (data.schools || []);
                    if (sl.length === 0) {
                        var o = document.createElement('option'); o.value = ''; o.textContent = '-- No schools found --'; schoolelect.appendChild(o);
                    } else {
                        sl.forEach(function(s){ var o = document.createElement('option'); o.value = s; o.textContent = s; schoolelect.appendChild(o);});
                    }
                }
                // after populating selects, try to restore any saved values for the school tab
                try {
                    var saved = sessionStorage.getItem('teachersearch_tab_school');
                    if (saved) {
                        var obj = JSON.parse(saved);
                        if (obj.schmandal && document.getElementById('schmandal_s')) document.getElementById('schmandal_s').value = obj.schmandal;
                        if (obj.school && document.getElementById('school')) document.getElementById('school').value = obj.school;
                        if (obj.schcode && document.getElementById('schcode')) document.getElementById('schcode').value = obj.schcode;
                    }
                } catch(e) { /* ignore restore errors */ }
                // populate schcodes if provided
                if (data.schcodes && data.schcodes.length) {
                    var sc = document.getElementById('schcode');
                    if (sc) {
                        sc.innerHTML = '<option value="">-- Select School Code --</option>';
                        // ensure uniqueness (some DB rows may return duplicate codes for same school)
                        var unique = Array.from(new Set(data.schcodes));
                        unique.forEach(function(c){ var o = document.createElement('option'); o.value = c; o.textContent = c; sc.appendChild(o); });
                    }
                } else {
                    var sc = document.getElementById('schcode'); if (sc) sc.innerHTML = '<option value="">-- Select School Code --</option>';
                }
            } catch (e) { console.error(e); }
        }
    };
    xhr.send();
}

document.addEventListener('DOMContentLoaded', function(){
    var d1 = document.getElementById('division');
    var m1 = document.getElementById('schmandal');
    var d2 = document.getElementById('division_s');
    var m2 = document.getElementById('schmandal_s');
    var school = document.getElementById('school');
    if (d1) d1.addEventListener('change', function(){
        var val = this.value;
        var ms = document.getElementById('schmandal');
        if (!val) { if (ms) ms.innerHTML = '<option value="">-- Select Mandal --</option>'; return; }
        // show loading
        if (ms) ms.innerHTML = '<option>Loading...</option>';
        fetchMandalsAndschool(val, '', 'schmandal', '');
    });
    if (d2) d2.addEventListener('change', function(){ fetchMandalsAndschool(this.value, '', 'schmandal_s', 'school'); });
    if (m2) m2.addEventListener('change', function(){
        // when selecting a mandal, do NOT repopulate the mandal select itself (that would overwrite the selection)
        fetchMandalsAndschool(d2.value, this.value, '', 'school');
    });
    // when a school is selected in the school tab, fetch schcodes
    var schoolSelect = document.getElementById('school');
    if (schoolSelect) schoolSelect.addEventListener('change', function(){
        var div = document.getElementById('division_s').value || '';
        var mand = document.getElementById('schmandal_s').value || '';
        var sch = this.value || '';
        var sc = document.getElementById('schcode'); if (sc) sc.innerHTML = '<option>Loading...</option>';
        if (!div || !sch) { if (sc) sc.innerHTML = '<option value="">-- Select School Code --</option>'; return; }
        // request schcodes
        var xhr2 = new XMLHttpRequest();
        xhr2.open('GET', 'ajax_get_mandals_schools.php?division=' + encodeURIComponent(div) + (mand?('&mandal='+encodeURIComponent(mand)): '') + '&school=' + encodeURIComponent(sch), true);
        xhr2.onload = function(){ if (xhr2.status === 200) {
                try {
                    var d = JSON.parse(xhr2.responseText);
                    var sc = document.getElementById('schcode');
                    if (sc) {
                        sc.innerHTML = '<option value="">-- Select School Code --</option>';
                        var codes = Array.from(new Set(d.schcodes || []));
                        codes.forEach(function(c){ var o = document.createElement('option'); o.value=c; o.textContent=c; sc.appendChild(o); });
                        // attempt to restore saved schcode from sessionStorage if present
                        try {
                            var saved = sessionStorage.getItem('teachersearch_tab_school');
                            if (saved) {
                                var obj = JSON.parse(saved);
                                if (obj.schcode) sc.value = obj.schcode;
                            }
                        } catch(e) { /* ignore */ }
                    }
                } catch(e){ console.error(e); }
        }};
        xhr2.send();
    });
        function saveCurrentTabState(){
            var active = document.querySelector('.tablink.active');
            if (!active) return;
            var tab = active.getAttribute('data-tab');
            var containerId = 'by' + (tab.charAt(0).toUpperCase() + tab.slice(1));
            var form = document.querySelector('#' + containerId + ' form');
            if (!form) return;
            var data = {};
            Array.from(form.elements).forEach(function(el){ if (el.name) data[el.name]=el.value; });
            sessionStorage.setItem('teachersearch_tab_' + tab, JSON.stringify(data));
        }

        // helper: restore form inputs for the requested tab (or active tab)
        function restoreTabState(requestedTab){
            var tab = requestedTab || (document.querySelector('.tablink.active')||{}).getAttribute('data-tab');
            if (!tab) return;
            var raw = sessionStorage.getItem('teachersearch_tab_' + tab);
            if (!raw) return;
            var data = JSON.parse(raw);
            var containerId = 'by' + (tab.charAt(0).toUpperCase() + tab.slice(1));
            var form = document.querySelector('#' + containerId + ' form');
            if (!form) return;
            Array.from(form.elements).forEach(function(el){ if (el.name && data.hasOwnProperty(el.name)) el.value = data[el.name]; });
        }

        // restore state when a tab is clicked
        document.addEventListener('click', function(e){
            var tb = e.target.closest('.tablink');
            if (tb) {
                // small timeout to let openTab run first then restore the saved values for that tab
                setTimeout(function(){ restoreTabState(tb.getAttribute('data-tab')); }, 10);
            }
        });

});
</script>

<!-- Inline verification form (hidden) -->
<div id="verify-box" style="display:none; position:fixed; left:50%; top:30%; transform:translate(-50%,-30%); background:#fff; border:1px solid #ccc; padding:16px; z-index:9999; box-shadow:0 6px 18px rgba(0,0,0,0.12);">
    <h4>Verify teacher</h4>
    <div id="verify-msg" style="color:#b00; margin-bottom:8px;"></div>
    <div style="margin-bottom:8px;"><label>TreasuryCode</label><input type="text" id="v_treasury" readonly style="width:200px;" /></div>
    <div style="margin-bottom:8px;"><label>Teacher Name</label><input type="text" id="v_name" readonly style="width:300px;" /></div>
    <div style="margin-bottom:8px;"><label>Date of Birth (DD/MM/YYYY)</label><input type="text" id="v_dob" placeholder="DD/MM/YYYY" style="width:200px;" /></div>
    <div style="margin-bottom:8px;"><label>Mobile No (full)</label><input type="text" id="v_mobile" placeholder="10-digit mobile" style="width:200px;" /></div>
    <div style="text-align:right; margin-top:8px;"><button id="v_cancel">Cancel</button> <button id="v_submit">Verify & Open</button></div>
    <div style="margin-top:8px; font-size:12px; color:#666;">Please enter full DOB (DD/MM/YYYY) and full mobile number to confirm identity before viewing particulars.</div>
</div>

<script>
// delegate view link clicks
document.addEventListener('click', function(e){
    var el = e.target;
    if (el && el.classList && el.classList.contains('view-link')) {
        e.preventDefault();
        var treasury = el.getAttribute('data-treasury');
        // fill treasury and attempt to fetch name from the row
        var row = el.closest('tr');
        var name = '';
        if (row) {
            var tds = row.getElementsByTagName('td');
            if (tds.length >= 3) name = tds[2].textContent.trim();
        }
        document.getElementById('v_treasury').value = treasury;
        document.getElementById('v_name').value = name;
        document.getElementById('v_dob').value = '';
        document.getElementById('v_mobile').value = '';
        document.getElementById('verify-msg').textContent = '';
        document.getElementById('verify-box').style.display = 'block';
    }
});

document.getElementById('v_cancel').addEventListener('click', function(){ document.getElementById('verify-box').style.display = 'none'; });
document.getElementById('v_submit').addEventListener('click', function(){
    var treasury = document.getElementById('v_treasury').value;
    var dob = document.getElementById('v_dob').value;
    var mobile = document.getElementById('v_mobile').value;
    if (!dob || !mobile) { document.getElementById('verify-msg').textContent = 'Please enter both DOB and Mobile'; return; }
    // POST to ajax_validate_teacher.php
    var f = new FormData(); f.append('treasury_code', treasury); f.append('dob', dob); f.append('mobile', mobile);
    fetch('ajax_validate_teacher.php', { method: 'POST', body: f }).then(function(resp){ return resp.json(); }).then(function(j){
        if (j.ok) {
            // redirect to particulars
            window.location.href = 'teacher_particulars.php?treasury_code=' + encodeURIComponent(treasury);
        } else {
            document.getElementById('verify-msg').textContent = j.msg || 'Verification failed';
        }
    }).catch(function(err){ document.getElementById('verify-msg').textContent = 'Server error'; console.error(err); });
});
</script>
