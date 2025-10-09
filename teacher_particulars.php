<?php
session_start();
require_once 'includes/db_connect.php';
include 'includes/header.php';
?>

<!-- Strong local styles to ensure the particulars layout renders as requested -->
<style>
    /* Ensure highest precedence for these rules on this page */
        .tp-wrapper{font-family:Arial,Helvetica,sans-serif !important; font-size:12px !important; text-align:left !important}
        .tp-wrapper .tp-title{font-size:15px !important; text-align:left !important}
        /* section border: thinner grey */
        .tp-section{border:1px solid #9a9a9a !important; padding:3px !important; margin-bottom:4px !important}
        .tp-section-header{background:#0b74a6 !important; color:#fff !important; padding:3px 4px !important; font-weight:700 !important; text-align:left !important}
            .tp-table{width:100% !important; border-collapse:collapse !important; table-layout:fixed !important}
            /* Use 16% label / 17% value split per pair (three pairs per row) => (16+17)*3 = 99% */
            .tp-table th{width:16% !important; box-sizing:border-box !important; padding:2px 3px !important; border:1px solid #9a9a9a !important; text-align:left !important; vertical-align:top !important}
            .tp-table td{width:17% !important; box-sizing:border-box !important; padding:2px 3px !important; border:1px solid #9a9a9a !important; text-align:left !important; vertical-align:top !important; word-break:break-word}
            .tp-col{width:100% !important; padding:2px !important}
            /* Allow labels to wrap instead of truncating */
            .tp-table th{white-space:normal; overflow:visible; text-overflow:clip}
    /* Print rules: hide header images (logo) when printing */
        @media print {
            /* hide the logo image block in the header */
            .tp-header-wrap img { display: none !important; }
            /* reduce extra spacing in print */
            .tp-header-wrap { margin-bottom: 2px !important; }
            .tp-wrapper { font-size:12px !important; margin-top:-6px !important }
            .tp-section{ margin-bottom:6px !important; padding:6px !important }
        }
    /* Shared action button styles (match teacher_view.php) */
    .tp-actions{display:flex;gap:8px;justify-content:flex-end;margin-bottom:6px}
        .tp-actions .action-btn{padding:10px 14px;border-radius:6px;border:none;color:#fff;background:#007bff;font-weight:700}
        .tp-actions .action-btn.secondary{background:#28a745}
</style>

<?php
?>

<?php
// Mobile masking helper: first 2 and last 2 visible, stars in between. Admins (session flag) see full.
function mask_mobile_local($m) {
    $m = trim((string)$m);
    if ($m === '') return '&ndash;';
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (!empty($_SESSION['admin_loggedin'])) return htmlspecialchars($m);
    $len = strlen($m);
    if ($len <= 4) return htmlspecialchars(str_repeat('*', $len));
    $start = substr($m, 0, 2);
    $end = substr($m, -2);
    $mid = str_repeat('*', max(0, $len - 4));
    return htmlspecialchars($start . $mid . $end);
}

?>

<div class="container main-body">
    <?php include 'includes/left_sidebar.php'; ?>

    <main class="main-content">
        <?php
        // If a treasury code was provided via GET or POST, determine the header title
        $headerTitle = 'Teacher Particulars';
        $pageTreasury = null;
        if (isset($_GET['treasury_code']) && trim($_GET['treasury_code']) !== '') $pageTreasury = trim($_GET['treasury_code']);
        if (isset($_POST['treasury_code']) && trim($_POST['treasury_code']) !== '') $pageTreasury = trim($_POST['treasury_code']);
        if ($pageTreasury) {
            // attempt to fetch surname and fullname for title
            $hstmt = $conn->prepare("SELECT TchSurName, TchFullName FROM teacherdata WHERE TreasuryCode = ? LIMIT 1");
            if ($hstmt) {
                $hstmt->bind_param('s', $pageTreasury);
                $hstmt->execute();
                $hres = $hstmt->get_result();
                if ($hres && $hres->num_rows > 0) {
                    $hrow = $hres->fetch_assoc();
                    $headerTitle = 'Particulars of ' . htmlspecialchars($pageTreasury) . ' ' . htmlspecialchars(trim(($hrow['TchSurName'] ?? '') . ' ' . ($hrow['TchFullName'] ?? '')));
                }
                $hstmt->close();
            }
        }
        ?>
        <?php
        // show logo above the header and scale it to 90% of its original size
        $logoPathHeader = 'assets/images/logo.png';
        echo '<div class="tp-header-wrap" style="text-align:center; margin-bottom:14px;">';
        if (file_exists(__DIR__ . DIRECTORY_SEPARATOR . $logoPathHeader)) {
            // display logo centered in its own block and scale to 80%
            echo '<div style="display:block; margin:0 auto 6px auto;">';
            echo '<img src="' . htmlspecialchars($logoPathHeader) . '" alt="Logo" style="display:inline-block; transform:scale(.8); transform-origin:center center; max-height:140px; height:auto; width:auto;"/>';
            echo '</div>';
        }
        // header text smaller by ~4px and red; reduce spacing underneath
        echo '<h2 style="margin:4px 0 6px 0; padding:2px 0; font-size:18px; color:red;">' . $headerTitle . '</h2>';
        echo '</div>';
        ?>

        <!-- Top action buttons -->
        <div class="tp-actions">
            <?php if ($pageTreasury) : ?>
                <button class="action-btn" onclick="printTeacherDetails('<?php echo htmlspecialchars(addslashes($pageTreasury)); ?>')">Print</button>
                <?php
                    // Show Edit if verified or admin
                    $topSessionKey = 'verified_teacher_' . $pageTreasury;
                    $topIsVerified = (isset($_SESSION[$topSessionKey]) && $_SESSION[$topSessionKey] === true);
                    if (!empty($_SESSION['admin_loggedin']) || $topIsVerified) {
                        echo '<button class="action-btn secondary" onclick="window.location.href=\'teacher_edit.php?treasury_code=' . rawurlencode($pageTreasury) . '\'">Edit</button>';
                    }
                ?>
            <?php else: ?>
                <button class="action-btn" onclick="alert('Please select a Treasury Code to print.');">Print</button>
            <?php endif; ?>
        </div>

        <?php
        // helper renders details for a given treasury code or email
        function render_particulars($conn, $treasury_code = null) {
            $treasury_code = trim((string)$treasury_code);

            // load label mapping
            $configPath = __DIR__ . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'label_map.json';
            $labelMap = [];
            if (file_exists($configPath) && is_readable($configPath)) {
                $json = file_get_contents($configPath);
                $decoded = json_decode($json, true);
                if (is_array($decoded)) {
                    $labelMap = $decoded;
                }
            }
            if (empty($labelMap)) {
                $labelMap = [
                    'TreasuryCode' => 'Teacher Employee ID',
                    'TchSurName' => 'Teacher Sur Name',
                    'TchFullName' => 'Teacher Full Name',
                    'FatherName' => 'Father Name',
                    'Designation' => 'Designation',
                    'dob' => 'Date of Birth',
                    'dort' => 'Date of Retirement',
                    'gender' => 'Gender'
                ];
            }

            // Prefer TreasuryCode lookup, fallback to email if provided in GET
            if ($treasury_code !== '') {
                $stmt = $conn->prepare("SELECT * FROM teacherdata WHERE TreasuryCode = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('s', $treasury_code);
                    $stmt->execute();
                    $result = $stmt->get_result();
                } else {
                    echo '<div class="notice">Query preparation failed.</div>';
                    return;
                }
            } else {
                // nothing to render
                return;
            }

            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();

                // Load mapping (try Book1.csv then teacher_structure.csv or JSON) - reuse logic from teacher_view.php
                $csvPaths = [__DIR__ . '/Book1.csv', __DIR__ . '/teacher_structure.csv'];
                $rawMapping = [];
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
                // JSON fallback (config/label_map.json)
                if (empty($rawMapping) && is_readable(__DIR__ . '/config/label_map.json')) {
                    $jm = json_decode(file_get_contents(__DIR__ . '/config/label_map.json'), true);
                    if (is_array($jm)) {
                        $order = 1;
                        foreach ($jm as $k => $v) {
                            if (is_array($v)) {
                                $rawMapping[] = [
                                    'column_key_order' => $v['column_key_order'] ?? $order++,
                                    'column_key_name' => $v['column_key_name'] ?? $k,
                                    'column_key_label' => $v['column_key_label'] ?? ($v['label'] ?? $k),
                                    'section_name_for_column_key' => $v['section_name_for_column_key'] ?? ($v['section'] ?? 'Miscellaneous'),
                                ];
                            } else {
                                $rawMapping[] = [
                                    'column_key_order' => $order++,
                                    'column_key_name' => $k,
                                    'column_key_label' => $v,
                                    'section_name_for_column_key' => 'Miscellaneous'
                                ];
                            }
                        }
                    }
                }

                // Normalize mapping into sections
                $sections = [];
                foreach ($rawMapping as $m) {
                    $cn = trim($m['column_key_name'] ?? '');
                    if ($cn === '') continue;
                    $lbl = trim($m['column_key_label'] ?? $cn);
                    $sec = trim($m['section_name_for_column_key'] ?? 'Miscellaneous');
                    $ord = isset($m['column_key_order']) ? (int)$m['column_key_order'] : 0;
                    if (!isset($sections[$sec])) $sections[$sec] = [];
                    $sections[$sec][] = ['name' => $cn, 'label' => $lbl, 'order' => $ord];
                }
                foreach ($sections as $s => &$flds) {
                    usort($flds, function ($a, $b) { return $a['order'] <=> $b['order']; });
                }
                unset($flds);

                // Render sections with 3-column grid
                echo '<div class="tp-wrapper" style="margin-top:0;padding-top:0">';

                foreach ($sections as $secName => $flds) {
                    if (empty($flds)) continue;
                    echo '<div class="tp-section" style="margin-bottom:8px;padding-top:6px;">';
                    echo '<div class="tp-section-header" style="padding:6px 8px;">' . htmlspecialchars($secName) . '</div>';
                    echo '<div style="padding:6px 8px;">';
                    // Grid container
                    echo '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px">';
                    $cnt = count($flds);
                    foreach ($flds as $f) {
                        $col = $f['name'];
                        $label = $f['label'];
                        $val = array_key_exists($col, $row) ? $row[$col] : '';
                        // Mask mobile for non-admins
                        if (strcasecmp($col, 'mobile') === 0) {
                            $display = mask_mobile_local($val);
                        } else {
                            $display = nl2br(htmlspecialchars($val));
                        }
                        echo '<div class="tp-col">';
                        echo '<strong>' . htmlspecialchars($label) . ':</strong> ' . $display;
                        echo '</div>';
                    }
                    // pad if not multiple of 3
                    $rem = $cnt % 3;
                    if ($rem !== 0) {
                        $pad = 3 - $rem;
                        for ($p = 0; $p < $pad; $p++) echo '<div class="tp-col" style="visibility:hidden">&nbsp;</div>';
                    }
                    echo '</div>'; // grid
                    echo '</div>'; // padding
                    echo '</div>'; // section
                }

                // no export link: printing is preferred and data is protected behind verification
                echo '</div>'; // tp-wrapper
            } else {
                echo '<div class="notice">No teacher found for the provided identifier.</div>';
            }

            if (isset($stmt) && is_object($stmt)) $stmt->close();
        }

        // New: Require verification (TreasuryCode + Mobile + DOB) before rendering particulars
        // Determine requested treasury code from POST or GET (search form posts treasury_code)
        $requestedTreasury = null;
        if (isset($_POST['search_teacher'])) {
            $requestedTreasury = isset($_POST['treasury_code']) ? trim($_POST['treasury_code']) : null;
        } elseif (isset($_GET['treasury_code']) && trim($_GET['treasury_code']) !== '') {
            $requestedTreasury = trim($_GET['treasury_code']);
        } elseif (isset($_GET['email']) && trim($_GET['email']) !== '') {
            // opening by email is not supported without verification to protect privacy
            echo '<div class="notice">Please search by Treasury Code and verify mobile + DOB to view particulars.</div>';
            $requestedTreasury = null;
        }

        if ($requestedTreasury) {
            $sessionKey = 'verified_teacher_' . $requestedTreasury;
            $isVerified = isset($_SESSION[$sessionKey]) && $_SESSION[$sessionKey] === true;
            $verifyError = '';

            // If verification values provided (from POST or GET), attempt to validate
            $providedMobile = $_REQUEST['mobile'] ?? null;
            $providedDob = $_REQUEST['dob'] ?? null; // expect YYYY-MM-DD from date input
            if (!$isVerified && $providedMobile && $providedDob) {
                $vstmt = $conn->prepare("SELECT TreasuryCode FROM teacherdata WHERE TreasuryCode = ? AND mobile = ? AND dob = ? LIMIT 1");
                if ($vstmt) {
                    $vstmt->bind_param('sss', $requestedTreasury, $providedMobile, $providedDob);
                    $vstmt->execute();
                    $vres = $vstmt->get_result();
                    if ($vres && $vres->num_rows > 0) {
                        $_SESSION[$sessionKey] = true;
                        $isVerified = true;
                    } else {
                        $verifyError = 'Verification failed. Please check Treasury Code, Mobile and Date of Birth.';
                    }
                    $vstmt->close();
                } else {
                    $verifyError = 'Verification query could not be prepared.';
                }
            }

            if ($isVerified) {
                // Render particulars after successful verification
                render_particulars($conn, $requestedTreasury);
            } else {
                // Show verification form (do not disclose any teacher info yet)
                ?>
                <div class="tp-wrapper">
                    <div class="tp-section">
                        <div class="tp-section-header">Verification required to view particulars</div>
                        <div style="padding:8px;">
                            <?php if ($verifyError) { echo '<div style="color:#a00;margin-bottom:8px;">' . htmlspecialchars($verifyError) . '</div>'; } ?>
                            <form method="post" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                                <div style="margin-bottom:6px;">
                                    <label>Treasury Code</label><br />
                                    <input name="treasury_code" value="<?php echo htmlspecialchars($requestedTreasury); ?>" readonly style="padding:6px;width:260px;" />
                                </div>
                                <div style="margin-bottom:6px;">
                                    <label>Mobile (full)</label><br />
                                    <input name="mobile" type="text" placeholder="10-digit mobile" required style="padding:6px;width:260px;" />
                                </div>
                                <div style="margin-bottom:6px;">
                                    <label>Date of Birth</label><br />
                                    <input name="dob" type="date" required style="padding:6px;width:180px;" />
                                </div>
                                <div>
                                    <button type="submit" name="verify_submit" class="print-btn" style="background:#28a745;border:none;color:#fff;padding:6px 10px;border-radius:3px;">Verify & View Particulars</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php
            }
        }
        // Bottom action buttons (repeat)
        echo '<div class="tp-actions" style="margin-top:12px;">';
        if ($pageTreasury) {
            echo '<button class="action-btn" onclick="printTeacherDetails(\'' . htmlspecialchars(addslashes($pageTreasury)) . '\')">Print</button>';
            $bottomSessionKey = 'verified_teacher_' . $pageTreasury;
            $bottomIsVerified = (isset($_SESSION[$bottomSessionKey]) && $_SESSION[$bottomSessionKey] === true);
            if (!empty($_SESSION['admin_loggedin']) || $bottomIsVerified) {
                echo '<button class="action-btn secondary" onclick="window.location.href=\'teacher_edit.php?treasury_code=' . rawurlencode($pageTreasury) . '\'">Edit</button>';
            }
        } else {
            echo '<button class="action-btn" onclick="alert(\'Please select a Treasury Code to print.\')">Print</button>';
        }
        echo '</div>';
        ?>
    </main>

</div>

<?php include 'includes/footer.php'; ?>

<script>
// PrintTeacherDetails: load teacher_view.php into a hidden iframe and print that iframe
function printTeacherDetails(treasury) {
    if (!treasury) { alert('Missing treasury code'); return; }
    // create or reuse hidden iframe
    var id = 'hiddenTeacherPrintFrame';
    var iframe = document.getElementById(id);
    if (!iframe) {
        iframe = document.createElement('iframe');
        iframe.style.position = 'fixed';
        iframe.style.right = '0';
        iframe.style.bottom = '0';
        iframe.style.width = '1px';
        iframe.style.height = '1px';
        iframe.style.opacity = '0';
        iframe.style.pointerEvents = 'none';
        iframe.id = id;
        document.body.appendChild(iframe);
    }
    // compose URL for teacher_view; use treasury_code param to match teacher_particulars logic
    var url = 'teacher_view.php?by=TreasuryCode&id=' + encodeURIComponent(treasury);
    // attach onload handler to trigger print once fully loaded
    var printed = false;
    var onload = function(){
        try {
            var win = iframe.contentWindow || iframe;
            if (win) {
                // small delay to let fonts & images settle
                setTimeout(function(){
                    try { win.focus(); win.print(); } catch(e) { console.error(e); alert('Printing failed: ' + (e && e.message ? e.message : e)); }
                }, 350);
            }
        } catch (ex) {
            console.error('Could not print iframe content', ex);
            alert('Unable to print the teacher particulars.');
        }
        // remove handler after first use
        iframe.removeEventListener('load', onload);
        printed = true;
    };
    iframe.addEventListener('load', onload);
    // set src last to start navigation
    iframe.src = url;
}
</script>
