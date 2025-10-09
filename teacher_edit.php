<?php
session_start();
require_once 'includes/db_connect.php';
include 'includes/header.php';

// preserve styles from teacher_particulars for consistent layout
?>
<style>
    /* reuse same local styles so edit form aligns with particulars */
    .tp-wrapper{font-family:Arial,Helvetica,sans-serif !important; font-size:12px !important; text-align:left !important}
    .tp-section{border:1px solid #9a9a9a !important; padding:3px !important; margin-bottom:4px !important}
    .tp-section-header{background:#0b74a6 !important; color:#fff !important; padding:3px 4px !important; font-weight:700 !important; text-align:left !important}
    .tp-col{padding:6px}
    label.field-label{display:block;font-weight:700;margin-bottom:4px}
    input.edit-input, select.edit-input, textarea.edit-input{width:100%;padding:6px;border:1px solid #ccc;border-radius:4px}
</style>
<?php

// Determine treasury code
$treasury = '';
if (isset($_GET['treasury_code'])) $treasury = trim($_GET['treasury_code']);
if (isset($_POST['treasury_code'])) $treasury = trim($_POST['treasury_code']);

if ($treasury === '') {
    echo '<div class="container main-body"><main class="main-content"><div class="notice">No Treasury Code provided.</div></main></div>'; include 'includes/footer.php'; exit;
}

$sessionKey = 'verified_teacher_' . $treasury;
$isVerified = (!empty($_SESSION['admin_loggedin']) || (isset($_SESSION[$sessionKey]) && $_SESSION[$sessionKey] === true));
if (!$isVerified) {
    echo '<div class="container main-body"><main class="main-content"><div class="notice">You are not authorized to edit this record. Please verify identity first.</div></main></div>'; include 'includes/footer.php'; exit;
}

// Load label mapping same as particulars
$configPath = __DIR__ . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'label_map.json';
$labelMap = [];
if (file_exists($configPath) && is_readable($configPath)) {
    $json = file_get_contents($configPath);
    $decoded = json_decode($json, true);
    if (is_array($decoded)) $labelMap = $decoded;
}
if (empty($labelMap)) {
    // minimal fallback
    $labelMap = ['TreasuryCode'=>'TreasuryCode','TchSurName'=>'TchSurName','TchFullName'=>'TchFullName','mobile'=>'mobile','dob'=>'dob'];
}

// Build an ordered mapping array similar to particulars renderer
$rawMapping = [];
// Try Book1.csv for column order if present
$csvPaths = [__DIR__ . '/Book1.csv', __DIR__ . '/teacher_structure.csv'];
foreach ($csvPaths as $p) {
    if (is_readable($p)) {
        $h = fopen($p, 'r');
        if ($h) {
            $hdr = fgetcsv($h);
            while (($r = fgetcsv($h)) !== false) {
                if (count($r) !== count($hdr)) continue;
                $rawMapping[] = array_combine($hdr, $r);
            }
            fclose($h);
        }
    }
    if (!empty($rawMapping)) break;
}
// fallback to label_map.json as simple mapping
if (empty($rawMapping) && is_array($labelMap)) {
    $order = 1;
    foreach ($labelMap as $k => $v) {
        if (is_array($v)) {
            $rawMapping[] = ['column_key_order'=>$v['column_key_order'] ?? $order++, 'column_key_name'=>$v['column_key_name'] ?? $k, 'column_key_label'=>$v['column_key_label'] ?? ($v['label'] ?? $k), 'section_name_for_column_key'=>$v['section_name_for_column_key'] ?? ($v['section'] ?? 'Miscellaneous')];
        } else {
            $rawMapping[] = ['column_key_order'=>$order++, 'column_key_name'=>$k, 'column_key_label'=>$v, 'section_name_for_column_key'=>'Miscellaneous'];
        }
    }
}

// Normalize mapping into sections
$sections = [];
foreach ($rawMapping as $m) {
    $cn = trim($m['column_key_name'] ?? ''); if ($cn === '') continue;
    $lbl = trim($m['column_key_label'] ?? $cn);
    $sec = trim($m['section_name_for_column_key'] ?? 'Miscellaneous');
    $ord = isset($m['column_key_order']) ? (int)$m['column_key_order'] : 0;
    if (!isset($sections[$sec])) $sections[$sec] = [];
    $sections[$sec][] = ['name'=>$cn,'label'=>$lbl,'order'=>$ord];
}
foreach ($sections as $s => &$flds) { usort($flds, function($a,$b){ return $a['order'] <=> $b['order']; }); }
unset($flds);

// Select/dropdown options (keys are lowercase column names)
$selectOptions = [
    'caste' => ['OC','BC A','BC B','BC C','BC D','BC E','SC GR.I','SC GR.II','SC GR.III','ST'],
    'designation' => [
        'GHM-Gr.II','SA BIO SCI','SA ENGLISH','SA HINDI','SA MATHS','SA PD','SA PHY SCI','SA SOCIAL','SA SPL_EDN',
        'SA TELUGU','SA URDU','LP HINDI','LP TELUGU','LP URDU','PET','VOC','CI','DM','MUSIC','PSHM','SGT','SGT SPL_EDN'
    ],
    'gender' => ['MALE','FEMALE'],
    'phcyn' => ['YES','NO'],
    'phctype' => ['VH','HH','OH','MD'],
    'phcauth' => ['SADAREM','MEDICAL BOARD','OTHER'],
    'phccertreassess' => ['YES','NO'],
    'maritalstatus' => ['MARRIED','UN-MARRIED','WIDOW','LEGALLY SEPERATED'],
    'spgovtempyn' => ['YES','NO'],
    'spworkarea' => ['DISTRICT','ZONAL','MULTI-ZONAL','STATE'],
    'spdepttype' => ['SCH. EDN. DEPT.(TG).','STATE GOVT.','CENTRAL GOVT.','AIDED','CORPORATION'],
    'posttype' => ['TRANSFERRABLE','NON-TRANSFERRABLE'],
    'ssctype' => ['REGULAR','OPEN (TOSS)','VOCATIONAL'],
    'sscmed' => ['TELUGU','ENGLISH','URDU','HINDI'],
    'ssclang1' => ['TELUGU','ENGLISH','URDU','HINDI','SANSKRIT'],
    'ssclang2' => ['TELUGU','ENGLISH','URDU','HINDI','SANSKRIT'],
    'intertype' => ['REGULAR','OPEN (TOSS)','VOCATIONAL'],
    'intermed' => ['TELUGU','ENGLISH','URDU','HINDI'],
    'interlang1' => ['TELUGU','ENGLISH','URDU','HINDI','SANSKRIT'],
    'interlang2' => ['TELUGU','ENGLISH','URDU','HINDI','SANSKRIT'],
    'deg1type' => ['REGULAR','OPEN']
];

// Additional dropdowns requested
$selectOptions['deg2type'] = ['REGULAR','OPEN'];
$selectOptions['deg1med'] = ['TELUGU','ENGLISH','URDU','HINDI'];
$selectOptions['deg2med'] = ['TELUGU','ENGLISH','URDU','HINDI'];
$selectOptions['ugtrngcourse'] = ['TTC','DEd','UGPEd','D.El.Ed.','Spl. DEd.'];
$selectOptions['ugtrngmedium'] = ['TELUGU','ENGLISH','URDU','HINDI'];
$selectOptions['grad1trngcourse'] = ['BEd','BPEd','Spl. BEd.','LPT','LPH','LPU'];
$selectOptions['grad1trngmed'] = ['TELUGU','ENGLISH','URDU','HINDI'];
$selectOptions['grad2trngcourse'] = ['BEd','BPEd','Spl. BEd.','LPT','LPH','LPU'];
$selectOptions['grad2trngmed'] = ['TELUGU','ENGLISH','URDU','HINDI'];
$selectOptions['medcourse'] = ['MEd','MPEd'];

// Sgt and SA related dropdowns
$selectOptions['sgtapptype'] = ['DSC/ TRT','Spl DSC (398)','UNTRAINED/ Spl VV','DSC (CONTRACTUAL)','COMPASSIONATE'];
$selectOptions['sgtcadredesign'] = ['SGT','SGT (Spl.Edn.)','PET','LPT','LPH','LPU','CI','DM','Voc. Ins.','MUSIC-Tr'];
$selectOptions['sgtmgmnt'] = ['GOVT','LB'];

$selectOptions['sacadredesign'] = ['SA BIO-SCI','SA ENGLISH','SA HINDI','SA MATHS','SA PD','SA PHY-SCI','SA SOCIAL','SA SPL_EDN','SA TELUGU','SA URDU'];
$selectOptions['saapptype'] = ['DSC/ TRT','PROMOTION','UNTRAINED/ Spl VV'];
$selectOptions['samgmnt'] = ['GOVT','LB'];

$selectOptions['ghmgriiapp'] = ['PROMOTION'];
$selectOptions['ghmgriidesign'] = ['GHM Gr.II'];

$selectOptions['idtmutualyn'] = ['YES','NO'];
$selectOptions['idtmutualcdr'] = ['SA BIO SCI','SA ENGLISH','SA HINDI','SA MATHS','SA PD','SA PHY SCI','SA SOCIAL','SA SPL_EDN','SA TELUGU','SA URDU','LP HINDI','LP TELUGU','LP URDU','PET','VOC','CI','DM','MUSIC','PSHM','SGT','SGT SPL_EDN'];

// eligible promotions
$promoOpts = ['GHM-Gr.II','SA BIO SCI','SA ENGLISH','SA HINDI','SA MATHS','SA PD','SA PHY SCI','SA SOCIAL','SA SPL_EDN','SA TELUGU','SA URDU','PSHM','JL/PGT'];
$selectOptions['eligible_promotion_1'] = $promoOpts;
$selectOptions['eligible_promotion_2'] = $promoOpts;
$selectOptions['eligible_promotion_3'] = $promoOpts;
$selectOptions['eligible_promotion_4'] = $promoOpts;

// Handle form submit: update all fields submitted (safe whitelist)
$msg = '';
// generate CSRF token for form if not present
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(16)); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_all'])) {
    // verify CSRF token
    $postedToken = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    if (!hash_equals($_SESSION['csrf_token'], $postedToken)) {
        $msg = 'CSRF token mismatch. Please reload the form and try again.';
    } else {
        // Determine allowed columns to update from mapping
        $allowed = [];
        foreach ($sections as $sec => $flds) foreach ($flds as $f) $allowed[] = $f['name'];
    // remove TreasuryCode from update list
    $allowed = array_filter(array_unique($allowed));
    $updates = [];
    $params = [];
    $types = '';
        // simple alias map: posted form field name => actual DB column name
        $aliasMap = [
            'scode' => 'SchCode', 'schcode' => 'SchCode', 'sch_name' => 'SchName', 'category' => 'category_ofthe_school',
            'mgt' => 'management', 'medium' => 'medium_ofthe_school', 'hra' => 'hra'
        ];

        // expand allowed to include alias values too
        foreach ($aliasMap as $posted => $real) { if (!in_array($real, $allowed)) $allowed[] = $real; }

        foreach ($allowed as $col) {
            if ($col === 'TreasuryCode') continue;
            // accept submitted value if present, else skip
            // check for alias names in POST as well
            $postKey = $col;
            // allow lowercase/alternative keys
            foreach ($aliasMap as $posted => $real) { if ($real === $col && isset($_POST[$posted])) { $postKey = $posted; break; } }
            if (array_key_exists($postKey, $_POST)) {
                $val = trim((string)$_POST[$postKey]);
            // mobile normalization
            if (strcasecmp($col,'mobile')===0) $val = preg_replace('/\D+/', '', $val);
            // normalize dob to Y-m-d when possible
            if (in_array(strtolower($col), ['dob','d_o_b','dateofbirth'])) {
                $ts = strtotime($val); if ($ts !== false) $val = date('Y-m-d', $ts);
            }
                $updates[] = '`' . str_replace('`','', $col) . '` = ?';
            $params[] = $val;
            $types .= 's';
        }
    }
    if (!empty($updates)) {
        $sql = 'UPDATE teacherdata SET ' . implode(', ', $updates) . ' WHERE TreasuryCode = ? LIMIT 1';
        $types .= 's'; $params[] = $treasury;
        if ($stmt = $conn->prepare($sql)) {
            // bind params by reference
            $bindArgs = [];
            $bindArgs[] = $types;
            foreach ($params as $k => $v) $bindArgs[] = &$params[$k];
            call_user_func_array([$stmt,'bind_param'], $bindArgs);
            if ($stmt->execute()) { $msg = 'Saved successfully.'; } else { $msg = 'Save failed.'; }
            $stmt->close();
            // audit log: record who saved, treasury, and changed columns
            $changed = implode(', ', array_map(function($u){ return trim($u); }, $updates));
            $auditLine = date('c') . "\t" . (isset($_SESSION['admin_loggedin']) && $_SESSION['admin_loggedin'] ? 'admin' : 'user') . "\t" . $treasury . "\t" . $changed . "\n";
            @file_put_contents(__DIR__ . '/edits.log', $auditLine, FILE_APPEND | LOCK_EX);
        } else { $msg = 'Server error preparing update.'; }
    } else { $msg = 'No editable fields submitted.'; }
    }
}

// Fetch current row
$row = [];
if ($stmt = $conn->prepare('SELECT * FROM teacherdata WHERE TreasuryCode = ? LIMIT 1')) {
    $stmt->bind_param('s', $treasury); $stmt->execute(); $res = $stmt->get_result(); if ($res && $res->num_rows) $row = $res->fetch_assoc(); $stmt->close();
}

echo '<div class="container main-body">';
include 'includes/left_sidebar.php';
echo '<main class="main-content">';
echo '<h2>Edit Particulars: ' . htmlspecialchars($treasury) . '</h2>';
if ($msg) echo '<div style="margin-bottom:8px;color:green;">' . htmlspecialchars($msg) . '</div>';

// Render form with sections and fields (inputs instead of plain text)
echo '<form method="post" action="teacher_edit.php">';
echo '<input type="hidden" name="treasury_code" value="' . htmlspecialchars($treasury) . '">';
echo '<input type="hidden" name="save_all" value="1">';
// CSRF token hidden field
echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token'] ?? '') . '">';
echo '<div class="tp-wrapper" style="margin-top:0;padding-top:0">';
// add alphabetical ordinals to section headers: A., B., C., ...
$sectionIndex = 0;
foreach ($sections as $secName => $flds) {
    $sectionIndex++;
    // map 1 -> A., 2 -> B., etc.
    $ordinal = chr(64 + ($sectionIndex <= 26 ? $sectionIndex : ($sectionIndex % 26 ?: 26))) . '. ';
    if (empty($flds)) continue;
    echo '<div class="tp-section" style="margin-bottom:8px;padding-top:6px;">';
    echo '<div class="tp-section-header" style="padding:6px 8px;">' . htmlspecialchars($ordinal . $secName) . '</div>';
    echo '<div style="padding:6px 8px;">';
    echo '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px">';

    // If this is the Working School Particulars section, inject the school-related selects/fields here
    if (stripos($secName, 'working') !== false && stripos($secName, 'school') !== false) {
        // Use exact teacherdata column names here to avoid mismatches and duplicates
        $curDiv = htmlspecialchars($row['division'] ?? '', ENT_QUOTES);
        echo '<div class="tp-col"><label class="field-label">School District</label>';
        echo '<select name="division" class="edit-input" data-current="' . $curDiv . '"><option value="">-- Select --</option></select></div>';

        $curMandal = htmlspecialchars($row['SchMandal'] ?? '', ENT_QUOTES);
        echo '<div class="tp-col"><label class="field-label">School Mandal</label>';
        echo '<select name="SchMandal" class="edit-input" data-current="' . $curMandal . '"><option value="">-- Select --</option></select></div>';

        $curSname = htmlspecialchars($row['SchName'] ?? '', ENT_QUOTES);
        echo '<div class="tp-col"><label class="field-label">School Name</label>';
        echo '<select name="SchName" class="edit-input" data-current="' . $curSname . '"><option value="">-- Select --</option></select></div>';

        $curScode = htmlspecialchars($row['SchCode'] ?? '', ENT_QUOTES);
        echo '<div class="tp-col"><label class="field-label">School Code</label>';
        echo '<select name="SchCode" class="edit-input" data-current="' . $curScode . '"><option value="">-- Select --</option></select></div>';

            // small visible debug area for school cascade troubleshooting (temporary)
            echo '<div id="school-debug" style="padding:8px;margin-top:6px;background:#f7f7f7;border:1px dashed #ddd;font-size:12px;color:#333;">School cascade status: <span id="school-debug-msg">idle</span></div>';

        // Category, Management, Medium, HRA (read-only display) - map to teacherdata columns
        echo '<div class="tp-col"><label class="field-label">Category of the school</label>';
        echo '<input type="text" name="category_ofthe_school" class="edit-input" readonly value="' . htmlspecialchars($row['category_ofthe_school'] ?? '') . '" /></div>';
        echo '<div class="tp-col"><label class="field-label">School Management</label>';
        echo '<input type="text" name="management" class="edit-input" readonly value="' . htmlspecialchars($row['management'] ?? '') . '" /></div>';
        echo '<div class="tp-col"><label class="field-label">School Medium</label>';
        echo '<input type="text" name="medium_ofthe_school" class="edit-input" readonly value="' . htmlspecialchars($row['medium_ofthe_school'] ?? '') . '" /></div>';
        echo '<div class="tp-col"><label class="field-label">School HRA</label>';
        echo '<input type="text" name="hra" class="edit-input" readonly value="' . htmlspecialchars($row['hra'] ?? '') . '" /></div>';

        // School Type select (default CO-ED)
        $curType = strtoupper($row['school_type'] ?? 'CO-ED');
        echo '<div class="tp-col"><label class="field-label">School Type</label>';
        echo '<select name="school_type" class="edit-input">';
        echo '<option value="CO-ED"' . ($curType==='CO-ED' ? ' selected' : '') . '>CO-ED</option>';
        echo '<option value="BOYS"' . ($curType==='BOYS' ? ' selected' : '') . '>BOYS</option>';
        echo '<option value="GIRLS"' . ($curType==='GIRLS' ? ' selected' : '') . '>GIRLS</option>';
        echo '</select></div>';

        // SchJoinDate (date input)
        $curJoin = htmlspecialchars($row['SchJoinDate'] ?? '', ENT_QUOTES);
        echo '<div class="tp-col"><label class="field-label">SchJoinDate</label>';
        echo '<input type="date" name="SchJoinDate" class="edit-input" value="' . $curJoin . '" /></div>';

        // mark these columns to be skipped in the generic renderer to avoid duplicates
        $school_section_cols = ['division','SchMandal','SchName','SchCode','category_ofthe_school','management','medium_ofthe_school','hra','school_type','SchJoinDate'];
    }
    foreach ($flds as $f) {
        $col = $f['name']; $label = $f['label'];
        // Skip school-section columns when we've already injected them above
        if (!empty($school_section_cols) && in_array(strtolower($col), array_map('strtolower', $school_section_cols))) continue;
        $val = array_key_exists($col, $row) ? $row[$col] : '';
        echo '<div class="tp-col">';
        echo '<label class="field-label">' . htmlspecialchars($label) . '</label>';
        // choose input type by column name heuristics
        $lc = strtolower($col);
        // render select if we have predefined options for this column
        if (isset($selectOptions[$lc]) && is_array($selectOptions[$lc])) {
            echo '<select class="edit-input" name="' . htmlspecialchars($col) . '">';
            echo '<option value="">-- Select --</option>';
            foreach ($selectOptions[$lc] as $opt) {
                $sel = ((string)$opt === (string)$val) ? ' selected' : '';
                echo '<option value="' . htmlspecialchars($opt) . '"' . $sel . '>' . htmlspecialchars($opt) . '</option>';
            }
            echo '</select>';
        } elseif ($lc === 'dob' || strpos($lc,'date')!==false) {
            echo '<input class="edit-input" type="date" name="' . htmlspecialchars($col) . '" value="' . htmlspecialchars($val) . '" />';
        } elseif ($lc === 'mobile' || strpos($lc,'phone')!==false) {
            echo '<input class="edit-input" type="text" name="' . htmlspecialchars($col) . '" value="' . htmlspecialchars($val) . '" />';
        } elseif (strpos($lc,'email')!==false) {
            echo '<input class="edit-input" type="email" name="' . htmlspecialchars($col) . '" value="' . htmlspecialchars($val) . '" />';
        } elseif (strlen($val) > 180) {
            echo '<textarea class="edit-input" name="' . htmlspecialchars($col) . '">' . htmlspecialchars($val) . '</textarea>';
        } else {
            $ro = ($col === 'TreasuryCode' || $col === 'TchSurName') ? ' readonly' : '';
            echo '<input class="edit-input" type="text" name="' . htmlspecialchars($col) . '" value="' . htmlspecialchars($val) . '"' . $ro . ' />';
        }
        echo '</div>';
    }
    echo '</div>'; // grid
    echo '</div>'; // padding
    echo '</div>'; // section
}
echo '</div>'; // tp-wrapper

echo '<div style="margin-top:10px;text-align:right">';
echo '<button type="submit" name="save_all" style="padding:10px 14px;background:#007bff;color:#fff;border:none;border-radius:6px;font-weight:700">Save All Changes</button> ';
echo '<a href="teacher_particulars.php?treasury_code=' . rawurlencode($treasury) . '" style="margin-left:12px;">Cancel</a>';
echo '</div>';

echo '</form>';

// Add JS for cascading school selects and loading dependent fields
?>
<script>
(function(){
    // helper to create option
    function makeOpt(v){ return '<option value="'+v.replace(/"/g,'&quot;')+'">'+v+'</option>'; }

    // small debug helper to write status into debug area if present
    function debug(msg){ try{ var el=document.getElementById('school-debug-msg'); if(el) el.textContent = (new Date()).toLocaleTimeString() + ' - ' + msg; }catch(e){}; console.debug(msg); }

    // Find or create DOM selects/fields in the form (by name attribute)
    var form = document.querySelector('form[action="teacher_edit.php"]');
    if (!form) return;

    // Locate the existing school fields that were rendered server-side in the Working School Particulars section
    var divisionSel = form.querySelector('[name="division"]');
    var mandalSel = form.querySelector('[name="SchMandal"]');
    var snameSel = form.querySelector('[name="SchName"]');
    var scodeSel = form.querySelector('[name="SchCode"]');

    // other dependent read-only text inputs
    function ensureText(name,label){
        var el = form.querySelector('[name="'+name+'"]');
        if (el) return el;
        var wrap = document.createElement('div'); wrap.className='tp-col';
        var lab = document.createElement('label'); lab.className='field-label'; lab.textContent = label;
        var inp = document.createElement('input'); inp.type='text'; inp.name = name; inp.className='edit-input'; inp.readOnly=true;
        wrap.appendChild(lab); wrap.appendChild(inp);
        var wrapper = form.querySelector('.tp-wrapper'); if (wrapper) wrapper.insertBefore(wrap, wrapper.firstChild.nextSibling);
        return inp;
    }

    var psUps = ensureText('category_ofthe_school','Category of the school:');
    var mgt = ensureText('management','Management:');
    var medium = ensureText('medium_ofthe_school','Medium of the school:');
    var hra = ensureText('hra','HRA Category:');

    // School Type select
    var schType = form.querySelector('[name="school_type"]');
    if (!schType){
        var wrap = document.createElement('div'); wrap.className='tp-col';
        var lab = document.createElement('label'); lab.className='field-label'; lab.textContent='School Type:';
        schType = document.createElement('select'); schType.name='school_type'; schType.className='edit-input';
        schType.innerHTML = '<option value="CO-Ed" selected>CO-Ed</option><option value="BOYS">BOYS</option><option value="GIRLS">GIRLS</option>';
        wrap.appendChild(lab); wrap.appendChild(schType);
        var wrapper = form.querySelector('.tp-wrapper'); if (wrapper) wrapper.insertBefore(wrap, wrapper.firstChild.nextSibling);
    }

    // load divisions
    function loadDivisions(){
        debug('loading divisions...');
        fetch('ajax_get_schools.php?_='+Date.now()).then(r=>r.json()).then(function(j){
            divisionSel.innerHTML = '<option value="">-- Select --</option>';
            (j.divisions||[]).forEach(function(d){ divisionSel.insertAdjacentHTML('beforeend', makeOpt(d)); });
            // try set current value from server-rendered field if present
            var cur = divisionSel.getAttribute('data-current') || '';
            if (cur) {
                divisionSel.value = cur;
                // populate mandals/schools for this division
                loadMandalsAndSchools();
            }
            debug('divisions loaded: '+ (j.divisions? j.divisions.length : 0));
        }).catch(function(e){ console.error(e); debug('error loading divisions'); });
    }

    // remember last selected division to detect changes and whether user changed it
    var lastDivision = divisionSel.getAttribute('data-current') || divisionSel.value || '';
    var userChangedDivision = false;
    var userChangedMandal = false;

    // load mandals and schools based on division/mandal
    function loadMandalsAndSchools(){
        var div = divisionSel.value || '';
        // prefer actual selected value, fallback to data-current for initial load
        var mandal = mandalSel.value || mandalSel.getAttribute('data-current') || '';
        debug('loading mandals/schools for '+div+' / '+mandal);
        fetch('ajax_get_schools.php?division='+encodeURIComponent(div)+'&mandal='+encodeURIComponent(mandal)+'&_='+Date.now()).then(r=>r.json()).then(function(j){
            debug('response received: schools='+ (j.schools? j.schools.length:0) + ' mandals=' + (j.mandals? j.mandals.length:0));
            // populate mandals (prefer j.mandals, otherwise derive unique mandals from schools)
            mandalSel.innerHTML = '<option value="">-- Select --</option>';
            var mandalList = (j.mandals && j.mandals.length) ? j.mandals.slice() : [];
            if (!mandalList.length && (j.schools||[]).length) {
                var seen = {};
                (j.schools||[]).forEach(function(s){ var mm = (s.mandal||'').toString().trim(); if (mm && !seen[mm]) { seen[mm]=true; mandalList.push(mm); } });
            }
            // trim and dedupe mandalList
            mandalList = mandalList.map(function(x){ return x.toString().trim(); }).filter(function(x,idx,arr){ return x!=='' && arr.indexOf(x)===idx; });
            console.debug('loadMandalsAndSchools: division=', div, 'mandals_count=', mandalList.length, 'schools_count=', (j.schools||[]).length);
            mandalList.forEach(function(m){ mandalSel.insertAdjacentHTML('beforeend', makeOpt(m)); });
            // try set current mandal
            var curM = mandalSel.getAttribute('data-current') || '';
            if (curM) mandalSel.value = curM;

                // populate school name select (use sname as the option value so data-current matches)
            snameSel.innerHTML = '<option value="">-- Select --</option>';
            (j.schools||[]).forEach(function(s){
                var sname = (s.sname||'').toString().trim();
                var scode = (s.scode||'').toString().trim();
                var mandalVal = (s.mandal||'').toString().trim();
                var label = s.label || ( scode + ' - ' + sname );
                    snameSel.insertAdjacentHTML('beforeend', '<option value="'+(sname.replace(/\"/g,'&quot;')||'')+'" data-mandal="'+(mandalVal||'')+'">'+label+'</option>');
            });

            // populate scode select (scode values, keep data-sname for lookup)
            scodeSel.innerHTML = '<option value="">-- Select --</option>';
            (j.schools||[]).forEach(function(s){
                var sname = (s.sname||'').toString().trim();
                var scode = (s.scode||'').toString().trim();
                var mandalVal = (s.mandal||'').toString().trim();
                scodeSel.insertAdjacentHTML('beforeend', '<option data-sname="'+(sname||'')+'" data-mandal="'+(mandalVal||'')+'" value="'+(scode||'')+'">'+(scode||'')+'</option>');
            });

            // restore current sname/scode from server-rendered attributes if present
            // but do NOT auto-restore if user just changed division or mandal (userChangedDivision/userChangedMandal)
            var curSname = snameSel.getAttribute('data-current') || '';
            var curScode = scodeSel.getAttribute('data-current') || '';
            if (!userChangedDivision && !userChangedMandal) {
                if (curSname) snameSel.value = curSname;
                if (curScode) scodeSel.value = curScode;
            }

            // if only sname was set, attempt to set scode to the matching option
            if (!scodeSel.value && curSname) {
                var match = Array.from(scodeSel.options).find(function(o){ return o.getAttribute('data-sname') === curSname; });
                if (match) {
                    scodeSel.value = match.value;
                    // set mandal based on matched option if possible
                    var m = match.getAttribute('data-mandal') || '';
                    if (m) {
                        // ensure option exists
                        if (!Array.from(mandalSel.options).some(function(o){ return o.value === m; })) {
                            mandalSel.insertAdjacentHTML('beforeend', makeOpt(m));
                        }
                        mandalSel.value = m;
                    }
                }
            }

            // trigger details load if we have a scode
            if (scodeSel.value) onScodeChange();
        }).catch(console.error);
    }

    // when scode changes, fetch school details
    // normalize helper for client-side matching
    function norm(x){ return (x||'').toString().trim().toLowerCase(); }

    function onScodeChange(){
        var sc = scodeSel.value || '';
        if (!sc){ psUps.value=''; mgt.value=''; medium.value=''; hra.value=''; setMatchedDebug(''); return; }
        fetch('ajax_get_school_by_scode.php?scode='+encodeURIComponent(sc)).then(r=>r.json()).then(function(j){
            if (j.found){ psUps.value = j.ps_ups || j.category || ''; mgt.value = j.mgt || ''; medium.value = j.medium_sch || ''; hra.value = j.category || ''; }
            // show matched debug info
            setMatchedDebug('Matched scode='+sc+' -> category='+(j.category||'')+', mgt='+(j.mgt||'')+', medium='+(j.medium_sch||''));
        }).catch(function(e){ console.error(e); setMatchedDebug('error fetching scode'); });
    }

    // wire events
    divisionSel.addEventListener('change', function(){
        // mark that the user actively changed division to prevent auto-restore of server defaults
        userChangedDivision = true;
        var newDiv = divisionSel.value || '';
        // if division actually changed, clear dependent fields and reset read-only fields
        if (newDiv !== lastDivision) {
            mandalSel.innerHTML = '<option value="">-- Select --</option>';
            snameSel.innerHTML = '<option value="">-- Select --</option>';
            scodeSel.innerHTML = '<option value="">-- Select --</option>';
            // clear dependent read-only fields
            psUps.value=''; mgt.value=''; medium.value=''; hra.value='';
            // reset SchJoinDate if present
            var sj = form.querySelector('[name="SchJoinDate"]'); if (sj) sj.value = '';
            // reset school_type to default CO-ED
            if (schType) schType.value = 'CO-ED';
            lastDivision = newDiv;
        }
        // load mandals and schools for the selected/new division
        loadMandalsAndSchools();
    });
    mandalSel.addEventListener('change', function(){
        // mark that user changed mandal and clear dependent fields until schools loaded
        userChangedMandal = true;
        // when mandal changes, clear school name/code and dependent read-only fields until schools loaded
        snameSel.innerHTML = '<option value="">-- Select --</option>';
        scodeSel.innerHTML = '<option value="">-- Select --</option>';
        psUps.value=''; mgt.value=''; medium.value=''; hra.value='';
        loadMandalsAndSchools();
    });
    // show matched scode/sname info in the UI for debugging
    function setMatchedDebug(txt){ try{ var d = document.getElementById('school-debug-msg'); if (d) d.textContent = (new Date()).toLocaleTimeString() + ' - ' + txt; console.info(txt); }catch(e){} }

    snameSel.addEventListener('change', function(){
        var selName = snameSel.value || '';
        if (!selName) { scodeSel.value = ''; onScodeChange(); setMatchedDebug(''); return; }
        // normalize and match ignoring case/whitespace
        var nSel = norm(selName);
        var match = Array.from(scodeSel.options).find(function(o){ return norm(o.getAttribute('data-sname')) === nSel; });
        if (match) {
            scodeSel.value = match.value;
            var m = match.getAttribute('data-mandal') || '';
            if (m) {
                // ensure option exists before setting (insert if missing)
                if (!Array.from(mandalSel.options).some(function(o){ return norm(o.value) === norm(m); })) {
                    mandalSel.insertAdjacentHTML('beforeend', makeOpt(m));
                }
                mandalSel.value = m;
            }
            onScodeChange();
            setMatchedDebug('Matched sname="'+selName+'" -> scode='+match.value+' mandal='+ (match.getAttribute('data-mandal')||'') );
            return;
        }
        // fallback: try match by scode string
        var byVal = Array.from(scodeSel.options).find(function(o){ return norm(o.value) === nSel; });
        if (byVal) { scodeSel.value = byVal.value; var m2 = byVal.getAttribute('data-mandal') || ''; if (m2) mandalSel.value = m2; onScodeChange(); setMatchedDebug('Matched by scode text -> scode='+byVal.value); }
    });
    scodeSel.addEventListener('change', onScodeChange);

    // initial load
    loadDivisions();
    // ensure school_type default
    if (schType && !schType.value) schType.value = 'CO-ED';

})();
</script>
<?php

include 'includes/footer.php';
exit;
