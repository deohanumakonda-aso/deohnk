<?php
// export_pdf.php - removed
http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
echo "Export to PDF feature has been removed from this installation.\n";
exit;

?>
<?php
// export_pdf.php - disabled
// Server-side PDF export removed per project request.
http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
echo "Export to PDF feature has been removed from this installation.\n";
exit;


$employeeId = $_REQUEST['id'] ?? $_REQUEST['employee_id'] ?? $_REQUEST['TreasuryCode'] ?? null;
if (!$employeeId) {
    echo "<p>Missing teacher id.</p>"; exit;
}

// accept print_token as alternate authorization
$printToken = $_REQUEST['print_token'] ?? null;
$sessionKey = 'verified_teacher_' . $employeeId;
$isVerified = isset($_SESSION[$sessionKey]) && $_SESSION[$sessionKey] === true;
if (!$isVerified && $printToken) {
    if (!empty($_SESSION['print_tokens'][$printToken]) && is_array($_SESSION['print_tokens'][$printToken])) {
        $pt = &$_SESSION['print_tokens'][$printToken];
        if (isset($pt['expires']) && $pt['expires'] >= time() && isset($pt['treasury']) && $pt['treasury'] === (string)$employeeId) {
            $isVerified = true;
            unset($_SESSION['print_tokens'][$printToken]);
        } else {
            unset($_SESSION['print_tokens'][$printToken]);
        }
    }
}

if (!$isVerified) {
    echo "<p>Not authorized to export PDF. Please verify the teacher first.</p>";
    exit;
}

// fetch teacher record
$stmt = $conn->prepare("SELECT * FROM teacherdata WHERE TreasuryCode = ? LIMIT 1");
if (!$stmt) { echo "<p>DB error</p>"; exit; }
$stmt->bind_param('s', $employeeId);
$stmt->execute();
$res = $stmt->get_result();
if (!$res || $res->num_rows === 0) { echo "<p>No teacher found</p>"; exit; }
$teacher = $res->fetch_assoc();
$stmt->close();

// load mapping (Book1.csv or JSON fallback)
$mapping = [];
$csvFiles = [__DIR__ . '/Book1.csv', __DIR__ . '/teacher_structure.csv'];
foreach ($csvFiles as $p) {
    if (is_readable($p)) {
        $h = fopen($p, 'r');
        if ($h) {
            $hdr = fgetcsv($h);
            while (($r = fgetcsv($h)) !== false) {
                if (count($r) !== count($hdr)) continue;
                $mapping[] = array_combine($hdr, $r);
            }
            fclose($h);
        }
    }
    if (!empty($mapping)) break;
}
if (empty($mapping) && is_readable(__DIR__ . '/config/label_map.json')) {
    $jm = json_decode(file_get_contents(__DIR__ . '/config/label_map.json'), true);
    if (is_array($jm)) {
        $order = 1;
        foreach ($jm as $k => $v) {
            if (is_array($v)) {
                $mapping[] = ['column_key_order'=>$v['column_key_order'] ?? $order++, 'column_key_name'=>$v['column_key_name'] ?? $k, 'column_key_label'=>$v['column_key_label'] ?? ($v['label'] ?? $k), 'section_name_for_column_key'=>$v['section_name_for_column_key'] ?? ($v['section'] ?? 'Miscellaneous')];
            } else {
                $mapping[] = ['column_key_order'=>$order++, 'column_key_name'=>$k, 'column_key_label'=>$v, 'section_name_for_column_key'=>'Miscellaneous'];
            }
        }
    }
}
if (empty($mapping)) { echo "<p>No mapping available.</p>"; exit; }

// build sections
$sections = [];
foreach ($mapping as $row) {
    $col = trim($row['column_key_name'] ?? '');
    if ($col === '') continue;
    $label = trim($row['column_key_label'] ?? $row['column_key_name'] ?? $col);
    $section = trim($row['section_name_for_column_key'] ?? $row['section'] ?? 'Miscellaneous');
    $order = isset($row['column_key_order']) ? (int)$row['column_key_order'] : 0;
    if (!isset($sections[$section])) $sections[$section] = [];
    $sections[$section][] = ['name'=>$col,'label'=>$label,'order'=>$order];
}
foreach ($sections as $k=>&$flds) { usort($flds, function($a,$b){return $a['order']<=>$b['order'];}); }
unset($flds);

// Helper to mask mobile
function mask_mobile_local_pdf($m) { $m = trim((string)$m); if ($m==='') return '&ndash;'; if (!empty($_SESSION['admin_loggedin'])) return htmlspecialchars($m); $len = strlen($m); if ($len<=4) return htmlspecialchars(str_repeat('*',$len)); $start=substr($m,0,2); $end=substr($m,-2); $mid=str_repeat('*',max(0,$len-4)); return htmlspecialchars($start.$mid.$end); }

// Helper to safely escape values (avoid passing null to htmlspecialchars)
function esc($s) { return htmlspecialchars((string)$s); }

// Build HTML (use styles similar to teacher_view.php to match printed output)
$logoPath = realpath(__DIR__ . '/assets/images/logo.png');
// embed logo as base64 if available to avoid path issues
$logoData = '';
if ($logoPath && is_readable($logoPath)) {
    $img = file_get_contents($logoPath);
    $mime = mime_content_type($logoPath) ?: 'image/png';
    $logoData = 'data:' . $mime . ';base64,' . base64_encode($img);
}
$title = 'Teacher Particulars - ' . esc($teacher['TchFullName'] ?? $employeeId);
$html = '<!doctype html><html><head><meta charset="utf-8"><title>' . $title . '</title><style>';
$html .= '@page { margin: 18mm 12mm; }';
$html .= ':root{--section-bg:#f5f7fb;--label-color:#333;--value-color:#111}';
$html .= 'body{font-family:Arial,Helvetica,sans-serif;margin:0;color:var(--value-color);font-size:13px;line-height:1.25}';
$html .= '.header{display:flex;align-items:center;gap:16px;margin-bottom:16px}.logo{max-height:90px}';
$html .= '.section{border-radius:6px;padding:4px;margin-bottom:4px;background:#fff;box-shadow:0 0 0 1px rgba(0,0,0,0.03)}';
$html .= '.section-head{background:var(--section-bg);padding:6px;margin:-4px -4px 6px;border-radius:6px 6px 0 0;font-weight:700}';
$html .= '.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:6px}.label{font-weight:600;margin-bottom:4px}.value{display:block}';
$html .= '.cert-section{border:1px solid #cfcfcf;padding:10px;margin-top:12px}.cert-head{background:#0b74a6;color:#fff;padding:6px;font-weight:700;margin:-10px -10px 10px -10px}.sign-row{display:flex;justify-content:space-between;margin-top:28px;font-size:13px}.sign-row div{width:30%;text-align:left}';
$html .= '@media (max-width:900px){.grid{grid-template-columns:repeat(2,1fr);}}@media (max-width:600px){.grid{grid-template-columns:1fr;}}';
$html .= '</style></head><body>';
// header with embedded logo and title to match teacher_view.php
$html .= '<div class="header">';
if ($logoData) {
    $html .= '<img src="' . esc($logoData) . '" class="logo" alt="logo"/>'; 
} else {
    $html .= '<img src="assets/images/logo.png" class="logo" alt="logo"/>'; 
}
$html .= '';
$html .= '<div><h1>' . esc($teacher['TchFullName'] ?? $employeeId) . '</h1>';
$html .= '<div><h1>' . esc($teacher['TchFullName'] ?? $employeeId) . '</h1>';
$html .= '<div>Employee ID: ' . esc($teacher['TreasuryCode'] ?? $employeeId) . '</div></div></div>';
foreach ($sections as $sec=>$flds) {
    $html .= '<div class="section"><div class="section-head">' . esc($sec) . '</div><div class="grid">';
    foreach ($flds as $f) {
        $col = $f['name']; $lbl = $f['label']; $val = array_key_exists($col,$teacher) ? $teacher[$col] : '';
        if (strcasecmp($col,'mobile')===0) $val = mask_mobile_local_pdf($val); else $val = nl2br(esc($val));
        $html .= '<div><div class="label">' . esc($lbl) . ':</div><div>' . $val . '</div></div>';
    }
    $html .= '</div></div>';
}

// Append certification block similar to the on-page layout
$cert = '';
$cert .= '<div class="cert-section">';
$cert .= '<div class="cert-head">DECLARATION</div>';
$cert .= '<div><p>I declare that the above particulars submitted by me are true and correct and if any false information found, I will be personally held responsible as per CCA Rules</p><div style="text-align:right;margin-top:20px;">Signature of the Teacher</div></div></div>';
$cert .= '<div class="cert-section">';
$cert .= '<div class="cert-head">CERTIFICATE</div>';
$cert .= '<div><p>I certify that the above particulars submitted by the candidate are verified with the Original Certificates and the service register of the individual and found correct.</p><div class="sign-row"><div>Signature of the DDO</div><div>Signature of the Mandal Educational Officer</div></div></div></div>';
$cert .= '<div class="cert-section">';
$cert .= '<div class="cert-head">DECLARATION BY CLUSTER RESOURCE PERSON / COMPUTER OPERATOR / MIS COORDINATOR</div>';
$cert .= '<div><p>Certified that all the details submitted by the teacher in the signed hard copy are verified. The corrections provided by the teacher and the authorities in the hard copy are uploaded to website server.</p><div class="sign-row"><div>Signature of the CRP</div><div>Signature of the Computer operator</div><div>Signature of the MIS Coordinator</div></div></div></div>';
$html = $html . $cert . '</body></html>';

// Append certification block similar to the on-page layout
$cert = '';
$cert .= '<div class="section" style="margin-top:18px;padding:10px;">';
$cert .= '<div class="section-head" style="background:#0b74a6;color:#fff;padding:6px;">DECLARATION</div>';
$cert .= '<div style="padding:10px;"><p>I declare that the above particulars submitted by me are true and correct and if any false information found, I will be personally held responsible as per CCA Rules</p><div style="text-align:right;margin-top:20px;">Signature of the Teacher</div></div></div>';
$cert .= '<div class="section" style="margin-top:6px;padding:10px;">';
$cert .= '<div class="section-head" style="background:#0b74a6;color:#fff;padding:6px;">CERTIFICATE</div>';
$cert .= '<div style="padding:10px;"><p>I certify that the above particulars submitted by the candidate are verified with the Original Certificates and the service register of the individual and found correct.</p><div style="display:flex;justify-content:space-between;margin-top:28px;"><div>Signature of the DDO</div><div>Signature of the Mandal Educational Officer</div></div></div></div>';
$cert .= '<div class="section" style="margin-top:6px;padding:10px;">';
$cert .= '<div class="section-head" style="background:#0b74a6;color:#fff;padding:6px;">DECLARATION BY CLUSTER RESOURCE PERSON / COMPUTER OPERATOR / MIS COORDINATOR</div>';
$cert .= '<div style="padding:10px;"><p>Certified that all the details submitted by the teacher in the signed hard copy are verified. The corrections provided by the teacher and the authorities in the hard copy are uploaded to website server.</p><div style="display:flex;justify-content:space-between;margin-top:28px;"><div>Signature of the CRP</div><div>Signature of the Computer operator</div><div>Signature of the MIS Coordinator</div></div></div></div>';
$html = str_replace('</body></html>', $cert . '</body></html>', $html);

// Attempt to load Composer autoloader (if present)
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Try Dompdf first
if (class_exists('\Dompdf\Dompdf')) {
    $dompdf = new \Dompdf\Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4','portrait');
    // Add page number/footer via canvas after render
    $dompdf->render();
    $canvas = $dompdf->getCanvas();
    $w = $canvas->get_width();
    $h = $canvas->get_height();
    $font = $dompdf->getFontMetrics()->get_font('Helvetica', 'normal');
    $footerTextLeft = 'Printed: ' . date('Y-m-d H:i');
    $footerTextRight = 'Page {PAGE_NUM} of {PAGE_COUNT}';
    // draw footer on each page using canvas methods
    $pageCount = $dompdf->get_canvas()->get_page_count();
    for ($p = 0; $p < $pageCount; $p++) {
        $canvas->page_text(20, $h - 24, $footerTextLeft, $font, 10, array(0,0,0), $p);
        $canvas->page_text($w - 150, $h - 24, $footerTextRight, $font, 10, array(0,0,0), $p);
    }
    $filename = 'particulars_' . preg_replace('/[^a-z0-9_-]/i','_', $employeeId) . '.pdf';
    // Stream inline so PDF renders in browser rather than forcing download
    $dompdf->stream($filename, ['Attachment' => false]);
    exit;
}

// Dompdf not available — try wkhtmltopdf binary as fallback
function find_wkhtmltopdf() {
    $env = getenv('WKHTMLTOPDF_BINARY');
    if ($env && is_executable($env)) return $env;
    $candidates = [
        'C:\\Program Files\\wkhtmltopdf\\bin\\wkhtmltopdf.exe',
        'C:\\Program Files (x86)\\wkhtmltopdf\\bin\\wkhtmltopdf.exe'
    ];
    foreach ($candidates as $p) if (is_executable($p)) return $p;
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $out = @shell_exec('where wkhtmltopdf 2>&1');
        if ($out) {
            $lines = preg_split('/\r?\n/', trim($out));
            if (!empty($lines[0]) && is_executable(trim($lines[0]))) return trim($lines[0]);
        }
    } else {
        $out = @shell_exec('which wkhtmltopdf 2>&1');
        if ($out) { $p = trim($out); if ($p && is_executable($p)) return $p; }
    }
    return null;
}

$wk = find_wkhtmltopdf();
if ($wk) {
    $tmpHtml = tempnam(sys_get_temp_dir(), 'tp_html_') . '.html';
    $tmpPdf = tempnam(sys_get_temp_dir(), 'tp_pdf_') . '.pdf';
    file_put_contents($tmpHtml, $html);
    // Add footer options for wkhtmltopdf: left=printed date, right=page numbers
    $footerDate = date('Y-m-d H:i');
    $cmd = escapeshellarg($wk) . ' --enable-local-file-access --footer-left ' . escapeshellarg('Printed: ' . $footerDate) . ' --footer-right ' . escapeshellarg('[page]/[toPage]') . ' ' . escapeshellarg($tmpHtml) . ' ' . escapeshellarg($tmpPdf) . ' 2>&1';
    $out = null; $rc = null;
    exec($cmd, $out, $rc);
    if ($rc === 0 && file_exists($tmpPdf)) {
        header('Content-Type: application/pdf');
        $filename = 'particulars_' . preg_replace('/[^a-z0-9_-]/i','_', $employeeId) . '.pdf';
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($tmpPdf));
        readfile($tmpPdf);
        @unlink($tmpHtml); @unlink($tmpPdf);
        exit;
    } else {
        @unlink($tmpHtml); @unlink($tmpPdf);
        echo '<h3>PDF export not available</h3>';
        echo '<p>wkhtmltopdf was found but failed to generate the PDF. Output:</p>';
        echo '<pre>' . htmlspecialchars(implode("\n", (array)$out)) . '</pre>';
        echo '<p>Check wkhtmltopdf installation and permissions.</p>';
        exit;
    }
}

// Neither Dompdf nor wkhtmltopdf available — show helpful instructions
echo '<h3>PDF export not available</h3>';
echo '<p>The server-side PDF generator (Dompdf) is not installed. To enable server-side PDF export, install Dompdf with Composer:</p>';
echo '<pre>composer require dompdf/dompdf</pre>';
echo '<p>After installing, ensure your project includes the Composer autoloader (vendor/autoload.php).</p>';
echo '<p>Alternatively, install wkhtmltopdf on the server (add to PATH) or set the WKHTMLTOPDF_BINARY environment variable to its location.</p>';
exit;

?>
