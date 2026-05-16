/**
 * Teacher Edit Form - Main JavaScript
 * Refactored: 2025-12-20
 * 
 * This file manages the interaction, validation, and saving of the Teacher Edit Form.
 */

(function () {
    // --- State Management ---
    function getTreasuryCode() {
        return document.querySelector('input[name="treasury_code"]')?.value || '';
    }

    function getStorageKey() {
        return 'saved_groups_' + getTreasuryCode();
    }

    function getSavedGroups() {
        try {
            return JSON.parse(sessionStorage.getItem(getStorageKey()) || '[]');
        } catch (e) { return []; }
    }

    function setSavedGroup(groupKey) {
        if (!groupKey) return;
        const saved = getSavedGroups();
        if (!saved.includes(groupKey)) {
            saved.push(groupKey);
            sessionStorage.setItem(getStorageKey(), JSON.stringify(saved));
        }
        updateProgressUI();
    }

    // Expose helpers globally
    window.getSavedGroups = getSavedGroups;
    window.setSavedGroups = function (groups) {
        sessionStorage.setItem(getStorageKey(), JSON.stringify(groups));
        updateProgressUI();
    };

    // --- UI Updates ---
    function updateProgressUI() {
        // Derive groups directly from DOM to ensure accuracy
        const groupElements = Array.from(document.querySelectorAll('.group-section[data-group]'));
        const allGroups = groupElements.map(el => el.getAttribute('data-group')).filter(g => g);

        // Fallback to global var if DOM is empty (rare edge case)
        if (allGroups.length === 0 && window.__ALL_GROUPS) {
            const global = window.__ALL_GROUPS;
            if (Array.isArray(global)) {
                allGroups.push(...global);
            } else if (typeof global === 'object') {
                allGroups.push(...Object.keys(global));
            }
        }

        const savedGroups = getSavedGroups();

        const savedCount = savedGroups.filter(g => allGroups.includes(g)).length;
        const total = allGroups.length;

        const countEl = document.getElementById('sections-saved-count');
        const totalEl = document.getElementById('sections-total-count');
        if (countEl) countEl.textContent = savedCount;
        if (totalEl) totalEl.textContent = total;

        // Update Group Indicators
        groupElements.forEach(groupSec => {
            const grp = groupSec.getAttribute('data-group');
            if (!grp) return;

            const header = groupSec.querySelector('.accordion-header');
            const statusSpan = header ? header.querySelector('.group-status') : null;
            const btn = groupSec.querySelector('.section-save-btn');

            if (savedGroups.includes(grp)) {
                if (statusSpan) {
                    statusSpan.textContent = '✓ Saved';
                    statusSpan.style.opacity = '1';
                    statusSpan.style.color = '#a7f3d0';
                }
                if (btn) {
                    btn.innerHTML = '<i class="fas fa-check" style="margin-right:6px;"></i> Saved';
                    btn.classList.add('saved');
                    // Force style update
                    btn.style.background = 'linear-gradient(to bottom, #10b981 0%, #059669 100%)';
                    btn.style.borderColor = '#059669';
                    btn.style.color = '#fff';
                }
            } else {
                if (statusSpan) statusSpan.style.opacity = '0';
                // Reset button text if not saved (optional, usually keeps default)
            }
        });

        // Update Global Save Button
        const saveAllBtn = document.getElementById('save-all-btn');
        const warningDiv = document.getElementById('save-all-warning');

        if (saveAllBtn) {
            // Enable only if ALL sections are saved
            if (savedCount >= total && total > 0) {
                saveAllBtn.disabled = false;
                saveAllBtn.style.opacity = '1';
                saveAllBtn.style.cursor = 'pointer';
                saveAllBtn.style.background = 'linear-gradient(135deg, #be2f53, #9d1c40)';
                saveAllBtn.title = 'Ready to submit';
                if (warningDiv) warningDiv.style.display = 'none';
            } else {
                saveAllBtn.disabled = true;
                saveAllBtn.style.opacity = '0.6';
                saveAllBtn.style.cursor = 'not-allowed';
                saveAllBtn.title = 'Please save all sections (' + savedCount + '/' + total + ')';
                if (warningDiv) warningDiv.style.display = 'block';
            }
        }
    }

    window.updateProgressIndicator = updateProgressUI;

    // --- AJAX Save Logic ---
    async function handleSectionSave(btn) {
        const groupKey = btn.getAttribute('data-group-key');
        const groupSection = btn.closest('.group-section');

        if (!groupKey || !groupSection) return;

        // Visual Feedback
        const originalContent = btn.innerHTML;
        const msgSpan = groupSection.querySelector('.section-save-msg');

        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        btn.disabled = true;
        if (msgSpan) msgSpan.textContent = '';

        // Collect Data
        const formData = new FormData();
        formData.append('treasury_code', getTreasuryCode());
        const csrf = document.querySelector('input[name="csrf_token"]');
        if (csrf) formData.append('csrf_token', csrf.value);

        formData.append('ajax', '1');
        formData.append('save_section', groupKey);

        const inputs = groupSection.querySelectorAll('input, select, textarea');
        inputs.forEach(input => {
            if (!input.name) return;
            if ((input.type === 'radio' || input.type === 'checkbox') && !input.checked) return;
            // Handle file inputs specially
            if (input.type === 'file') {
                if (input.files && input.files.length > 0) {
                    formData.append(input.name, input.files[0]);
                }
            } else {
                formData.append(input.name, input.value);
            }
        });

        try {
            const response = await fetch('teacher_edit.php', { method: 'POST', body: formData });

            // Check content type
            const contentType = response.headers.get('content-type') || '';
            const rawText = await response.text();

            // Try to parse as JSON first (our server-side shutdown handler converts HTML errors to JSON)
            let result = null;
            try {
                result = JSON.parse(rawText);
            } catch (e) {
                // Not valid JSON — server returned raw HTML
                // Strip HTML tags to get a readable message
                const tmp = document.createElement('div');
                tmp.innerHTML = rawText;
                const stripped = (tmp.textContent || tmp.innerText || '').replace(/\s+/g, ' ').trim();
                const errMsg = stripped.substring(0, 300) || 'Server returned unexpected HTML';
                console.error('Server returned HTML error (first 1000 chars):', rawText.substring(0, 1000));
                throw new Error(errMsg);
            }

            if (result && result.success) {
                setSavedGroup(groupKey);
                if (msgSpan) {
                    msgSpan.textContent = 'Saved!';
                    msgSpan.style.color = '#10b981';
                    setTimeout(() => { if (msgSpan) msgSpan.textContent = ''; }, 3000);
                }
            } else {
                // Server returned JSON with success=false — show the actual message
                const errMsg = (result && result.message) ? result.message : 'Save failed';
                console.error('Save failed with message:', errMsg, result);
                throw new Error(errMsg);
            }

        } catch (error) {
            console.error('Save Error:', error);
            if (msgSpan) {
                msgSpan.textContent = 'Acc: ' + error.message;
                msgSpan.style.color = '#ef4444';
            }
            btn.innerHTML = originalContent; // Revert on failure
        } finally {
            btn.disabled = false;
            // If saved, updateProgressUI sets the checkmark style
            if (getSavedGroups().includes(groupKey)) {
                updateProgressUI();
            }
        }
    }


    // --- Initialization ---
    document.addEventListener('DOMContentLoaded', function () {
        updateProgressUI();

        // Bind Save Buttons
        document.querySelectorAll('.section-save-btn').forEach(btn => {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                handleSectionSave(this);
            });
        });

        // Tab Logic (Simplified but retaining Service visibility logic)
        const tabs = Array.from(document.querySelectorAll('.tp-tab'));
        const sections = Array.from(document.querySelectorAll('.tp-section'));

        function showTabByIndex(index) {
            tabs.forEach((t, i) => t.classList.toggle('tp-tab-active', i === index));
            const activeTab = tabs[index];
            const activeLabel = activeTab ? (activeTab.getAttribute('data-tab-label') || '').toLowerCase().replace(/[^a-z]/g, '') : '';

            sections.forEach(sec => {
                const secTab = (sec.getAttribute('data-tab') || '').toLowerCase().replace(/[^a-z]/g, '');
                const secLetter = sec.getAttribute('data-letter');
                const isService = ['R', 'S', 'T'].includes(secLetter);

                let show = false;
                if (activeLabel) {
                    if (secTab === activeLabel) show = true;
                    if (isService && activeLabel !== 'service') show = false;
                } else {
                    show = true;
                    if (isService && activeLabel !== 'service') show = false;
                }
                sec.style.display = show ? 'block' : 'none';
            });

            if (activeLabel === 'service') {
                if (typeof window.handleDesignationChange === 'function') window.handleDesignationChange();
                if (typeof window.handleSGTRenderedSelection === 'function') {
                    const s = document.querySelector('select[name="sgtrendered"]');
                    if (s) window.handleSGTRenderedSelection(s);
                }
            }
        }

        tabs.forEach((t, i) => {
            t.addEventListener('click', () => showTabByIndex(i));
        });
        if (tabs.length > 0) showTabByIndex(0);

        // Accordion Toggle - Auto-collapse others
        const allHeaders = document.querySelectorAll('.accordion-header');
        allHeaders.forEach(header => {
            // Remove existing by cloning
            const newHeader = header.cloneNode(true);
            header.parentNode.replaceChild(newHeader, header);

            newHeader.addEventListener('click', function (e) {
                if (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT' || e.target.closest('.section-save-btn')) {
                    return;
                }

                const isCurrentlyExpanded = this.classList.contains('accordion-expanded');

                // If we are opening this section (it was closed), close all others first
                if (!isCurrentlyExpanded) {
                    document.querySelectorAll('.accordion-header').forEach(other => {
                        if (other !== this && other.classList.contains('accordion-expanded')) {
                            other.classList.remove('accordion-expanded');
                            const otherContent = other.nextElementSibling;
                            if (otherContent) otherContent.style.display = 'none';
                            const otherIcon = other.querySelector('.accordion-icon');
                            if (otherIcon) otherIcon.style.transform = 'rotate(0deg)';
                        }
                    });
                }

                // Toggle clicked section
                this.classList.toggle('accordion-expanded');
                const content = this.nextElementSibling;
                if (content) {
                    const isExp = this.classList.contains('accordion-expanded');
                    content.style.display = isExp ? 'block' : 'none';
                    const icon = this.querySelector('.accordion-icon');
                    if (icon) icon.style.transform = isExp ? 'rotate(180deg)' : 'rotate(0deg)';
                }
            });
        });

        // Bind Save All Form Submit (Validation)
        const form = document.querySelector('form[action="teacher_edit.php"]') || document.forms[0];
        if (form) {
            form.addEventListener('submit', function (e) {
                // Only validate if we are submitting the main form (Save All)
                // Note: Section saves use AJAX and don't trigger this submit unless we mistakenly bound them

                if (typeof validateRequiredFields === 'function') {
                    const missing = validateRequiredFields();
                    if (missing.length > 0) {
                        e.preventDefault();
                        if (typeof showValidationMessage === 'function') {
                            showValidationMessage(missing);
                        } else {
                            alert('Please fill all required fields:\n' + missing.join('\n'));
                        }
                        return false;
                    }
                }
            });
        }

        // Run Init Handlers
        setTimeout(() => {
            try {
                if (typeof window.handleDesignationChange === 'function') window.handleDesignationChange();
                if (typeof window.handleSGTAppTypeSelection === 'function') window.handleSGTAppTypeSelection();
                if (typeof window.handleSAAppTypeSelection === 'function') window.handleSAAppTypeSelection();
            } catch (e) { console.log('Init Warn:', e); }
        }, 300);
    });

})();
// Field validation for required fields
function validateRequiredFields() {
    var requiredFields = [
        { name: 'TreasuryCode', label: 'Treasury Code' },
        { name: 'TchSurName', label: 'Teacher Sur Name' },
        { name: 'TchFullName', label: 'Teacher Full Name' },
        { name: 'FatherName', label: 'Father Name' },
        { name: 'Designation', label: 'Designation' },
        { name: 'dob', label: 'Date of Birth' },
        { name: 'dort', label: 'Date of Retirement' },
        { name: 'gender', label: 'Gender' },
        { name: 'caste', label: 'Caste' },
        { name: 'subcaste', label: 'Sub Caste' },
        { name: 'adhaar', label: 'Adhaar' },
        { name: 'mobile', label: 'Mobile' },
        { name: 'AccountNo', label: 'Bank Account Number' },
        { name: 'IfscCode', label: 'IFSC Code' },
        { name: 'branch', label: 'Bank Branch' },
        { name: 'bank', label: 'Bank Name' },
        { name: 'acstate', label: 'Account State' },
        { name: 'MaritalStatus', label: 'Marital Status' },
        { name: 'PhcYN', label: 'PHC (Physically Handicapped)' },
        // WORKING SCHOOL PARTICULARS
        { name: 'division', label: 'School District' },
        { name: 'SchMandal', label: 'School Mandal' },
        { name: 'SchName', label: 'School Name' },
        { name: 'SchCode', label: 'School Code' },
        { name: 'school_type', label: 'School Type' },
        { name: 'SchJoinDate', label: 'School Join Date' },
        // ADDRESS INFORMATION
        { name: 'ResHno', label: 'Residential H No' },
        { name: 'ResStreet', label: 'Residential Street' },
        { name: 'ResMandal', label: 'Residential Mandal' },
        { name: 'ResDist', label: 'Residential District' },
        { name: 'NatHno', label: 'Native H No' },
        { name: 'NatStreet', label: 'Native Street' },
        { name: 'NatMandal', label: 'Native Mandal' },
        { name: 'NatDist', label: 'Native District' },
        // EPIC INFORMATION
        { name: 'EpicNo', label: 'Electoral Photo Id Card No' },
        { name: 'PartNo', label: 'EPIC Part No' },
        { name: 'SerialNo', label: 'EPIC Serial No' },
        { name: 'AssmblyConstDist', label: 'Assembly Constituency District' },
        { name: 'AssmblyConstName', label: 'Assembly Constituency Name' },
        { name: 'RsConstName', label: 'Residnl. Constituency Name' },
        { name: 'NativeConstName', label: 'Native Constituency Name' },
        { name: 'WorkConstName', label: 'Working Constituency Name' },
        // ACADEMIC QUALIFICATIONS - SSC/X CLASS
        { name: 'SscType', label: 'SSC Type of Study' },
        { name: 'SscYear', label: 'SSC Passed Year' },
        { name: 'SscMed', label: 'SSC Passed Medium' },
        { name: 'SscLang1', label: 'SSC 1st Language' },
        { name: 'SscLang2', label: 'SSC 2nd Language' },
        // ACADEMIC QUALIFICATIONS - INTERMEDIATE
        { name: 'InterType', label: 'Intermediate Type of Study' },
        { name: 'InterYear', label: 'Intermediate Passed Year' },
        { name: 'InterCourse', label: 'Intermediate Course Studied' },
        { name: 'InterMed', label: 'Intermediate Medium of Study' },
        { name: 'InterLang1', label: 'Intermediate 1st Language' },
        { name: 'InterLang2', label: 'Intermediate 2nd Language' }
    ];

    var missing = [];
    var form = document.querySelector('form[action="teacher_edit.php"]') || document.forms[0];

    // Check basic required fields
    requiredFields.forEach(function (field) {
        var input = form.querySelector('[name="' + field.name + '"]');
        var value = input ? (input.value || '').trim() : '';
        if (!input || value === '' || value === '-- Select --' || value === 'null') {
            missing.push(field.label);
        }
    });

    // Special validation for bank information section
    var bankFields = [
        { name: 'AccountNo', label: 'Bank Account Number' },
        { name: 'IfscCode', label: 'IFSC Code' },
        { name: 'branch', label: 'Bank Branch' },
        { name: 'bank', label: 'Bank Name' },
        { name: 'acstate', label: 'Account State' }
    ];

    var missingBankFields = [];
    bankFields.forEach(function (field) {
        var input = form.querySelector('[name="' + field.name + '"]');
        var value = input ? (input.value || '').trim() : '';
        if (!input || value === '' || value === 'null') {
            missingBankFields.push(field.label);
        }
    });

    if (missingBankFields.length > 0) {
        // Remove individual bank field errors from main list to avoid duplication
        missing = missing.filter(function (item) {
            return !['Bank Account Number', 'IFSC Code', 'Bank Branch', 'Bank Name', 'Account State'].includes(item);
        });

        // Add comprehensive bank validation message
        if (missingBankFields.includes('IFSC Code') || missingBankFields.includes('Bank Branch') || missingBankFields.includes('Bank Name') || missingBankFields.includes('Account State')) {
            missing.push('🏦 BANK INFORMATION INCOMPLETE: ' + missingBankFields.join(', ') +
                (missingBankFields.includes('IFSC Code') ? ' (Enter valid IFSC code to auto-fill bank details)' : ''));
        } else {
            missing.push('🏦 BANK INFORMATION: ' + missingBankFields.join(', '));
        }
    }

    // Check caste certificate requirement for SC/ST
    var casteInput = form.querySelector('[name="caste"]');
    if (casteInput && casteInput.value && /(SC|ST)/i.test(casteInput.value)) {
        // Check if caste certificate exists or is being uploaded
        var casteCertWrapper = document.getElementById('castecert-wrapper');
        var hasExistingCert = casteCertWrapper && casteCertWrapper.dataset && casteCertWrapper.dataset.existingFile;
        var fileInput = document.getElementById('CasteCert_file');
        var hasNewUpload = fileInput && fileInput.files && fileInput.files.length > 0;

        // Also check if there's a visible certificate link (in case the data attribute isn't set)
        var certLink = document.getElementById('castecert-link');
        var hasVisibleCert = certLink && certLink.style.display !== 'none';

        if (!hasExistingCert && !hasNewUpload && !hasVisibleCert) {
            missing.push('Caste Certificate (required for SC/ST)');
        }
    }

    // Check PHC fields if PhcYN is YES
    var phcInput = form.querySelector('[name="PhcYN"]');
    if (phcInput && phcInput.value && phcInput.value.toUpperCase() === 'YES') {
        var phcRequiredFields = [
            { name: 'PhcType', label: 'PHC Type' },
            { name: 'PhcPercent', label: 'PHC Percentage' },
            { name: 'PhcAuth', label: 'PHC Authority' },
            { name: 'PhcCertNo', label: 'PHC Certificate Number' },
            { name: 'PhcCertDate', label: 'PHC Certificate Date' },
            { name: 'PhcCertValidity', label: 'PHC Certificate Validity' },
            { name: 'PhcCertReassess', label: 'PHC Certificate Reassessment' }
        ];

        phcRequiredFields.forEach(function (field) {
            var input = form.querySelector('[name="' + field.name + '"]');
            var value = input ? (input.value || '').trim() : '';
            if (!input || value === '' || value === '-- Select --' || value === 'null') {
                missing.push(field.label + ' (required when PHC = YES)');
            }
        });

        // Check PHC certificate - multiple ways to detect existing or new certificate
        var phcCertWrapper = document.getElementById('phcupload-wrapper');
        var hasExistingPhcCert = phcCertWrapper && phcCertWrapper.dataset && phcCertWrapper.dataset.existingFile;

        var phcFileInput = document.getElementById('Phcupload_file');
        var hasNewPhcUpload = phcFileInput && phcFileInput.files && phcFileInput.files.length > 0;

        // Check if PHC certificate link is visible (server rendered when certificate exists and PHC=YES)
        var phcCertLink = document.getElementById('phcupload-link');
        var hasVisiblePhcCert = phcCertLink && phcCertLink.style.display !== 'none' && phcCertLink.offsetParent !== null;

        // Check for PHC anchor link specifically - most reliable indicator of existing certificate
        var phcAnchor = document.getElementById('phcupload-anchor');
        var hasPhcAnchor = phcAnchor && phcAnchor.offsetParent !== null;

        // Check if PHC anchor link has valid href (indicates existing certificate)
        var hasValidPhcLink = phcAnchor && phcAnchor.href && phcAnchor.href.includes('phc_certificates');

        // Also check if the file input has a value (some browsers show filename in value)
        var fileInputHasValue = phcFileInput && phcFileInput.value && phcFileInput.value.trim() !== '';

        // Check if the file name display area shows a selected file
        var phcFileNameDiv = document.getElementById('phcupload-filename');
        var fileNameDisplayed = phcFileNameDiv && phcFileNameDiv.textContent &&
            !phcFileNameDiv.textContent.includes('No file chosen') &&
            !phcFileNameDiv.textContent.includes('Allowed:');

        // Check if wrapper has data attribute (server-side indicator of existing file)
        var wrapperHasFile = phcCertWrapper && phcCertWrapper.getAttribute('data-existing-file');

        // Alternative check: look for any visible link in the PHC upload area that contains 'phc_certificates'
        var phcLinks = phcCertWrapper ? phcCertWrapper.querySelectorAll('a[href*="phc_certificates"]') : [];
        var hasVisiblePhcLink = phcLinks.length > 0 && Array.from(phcLinks).some(link => link.offsetParent !== null);

        // Accept if ANY of these conditions indicate a certificate is available
        var hasPhcCertificate = hasExistingPhcCert || hasNewPhcUpload || hasVisiblePhcCert || hasPhcAnchor ||
            fileInputHasValue || fileNameDisplayed || wrapperHasFile || hasValidPhcLink || hasVisiblePhcLink;



        // Final validation decision - be lenient on client side, let server validate definitively
        if (!hasPhcCertificate) {
            // Only enforce client-side PHC validation if we're confident there's no existing certificate
            // Check if wrapper exists but has no existing file indication - then it's likely a new form
            var isNewForm = phcCertWrapper && !phcCertWrapper.getAttribute('data-existing-file') &&
                !phcCertWrapper.querySelector('a[href*="phc_certificates"]');



            // Only require upload for genuinely new forms without existing certificates
            if (isNewForm) {
                missing.push('PHC Certificate (required when PHC = YES)');
            }
            // For forms with potential existing certificates, let server-side validation handle it
        }
    }

    // Check spouse information requirements
    var maritalStatusEl = form.querySelector('[name="MaritalStatus"]');
    if (maritalStatusEl && maritalStatusEl.value && maritalStatusEl.value.toUpperCase() === 'MARRIED') {
        // SpGovtEmpYN is required when married
        var spGovtEmpYNEl = form.querySelector('[name="SpGovtEmpYN"]');
        if (!spGovtEmpYNEl || !spGovtEmpYNEl.value || spGovtEmpYNEl.value === '-- Select --') {
            missing.push('Spouse Government Employee Status (required when married)');
        }

        // If spouse is government employee, all spouse details are required
        if (spGovtEmpYNEl && spGovtEmpYNEl.value && spGovtEmpYNEl.value.toUpperCase() === 'YES') {
            var spouseRequiredFields = [
                { name: 'SpTreasuryCode', label: 'Spouse Treasury Code' },
                { name: 'SpDesign', label: 'Spouse Designation' },
                { name: 'SpName', label: 'Spouse Name' },
                { name: 'SpDeptName', label: 'Spouse Department' },
                { name: 'SpOfficeName', label: 'Spouse Office Name' },
                { name: 'SpWorkArea', label: 'Spouse Work Area' },
                { name: 'SpDeptType', label: 'Spouse Department Type' },
                { name: 'PostType', label: 'Post Type' }
            ];

            spouseRequiredFields.forEach(function (field) {
                var input = form.querySelector('[name="' + field.name + '"]');
                var value = input ? (input.value || '').trim() : '';
                if (!input || value === '' || value === '-- Select --' || value === 'null') {
                    missing.push(field.label + ' (required when spouse is government employee)');
                }
            });
        }
    }

    // Check degree requirements based on degrees_acquired dropdown
    var degreesAcquiredEl = form.querySelector('[name="degrees_acquired"]');
    if (degreesAcquiredEl && degreesAcquiredEl.value) {
        var degreesCount = parseInt(degreesAcquiredEl.value);

        if (degreesCount >= 1) {
            // D1 (Main degree) fields are required
            var d1RequiredFields = [
                { name: 'Deg1Type', label: 'D1: Type of Study' },
                { name: 'Deg1Course', label: 'D1: Studied Course' },
                { name: 'Deg1Med', label: 'D1: Medium' },
                { name: 'Deg1Opt1', label: 'D1: Optional -1' },
                { name: 'Deg1Univ', label: 'D1: University' },
                { name: 'Deg1PassYr', label: 'D1: Passed Year' },
                { name: 'Deg1Percent', label: 'D1: Pass Percent' }
            ];

            d1RequiredFields.forEach(function (field) {
                var input = form.querySelector('[name="' + field.name + '"]');
                var value = input ? (input.value || '').trim() : '';
                if (!input || value === '' || value === '-- Select --' || value === 'null') {
                    missing.push(field.label);
                }
            });
        }

        if (degreesCount >= 2) {
            // D2 (Additional degree) fields are required
            var d2RequiredFields = [
                { name: 'Deg2Type', label: 'D2: Type of Study' },
                { name: 'Deg2Course', label: 'D2: Studied Course' },
                { name: 'Deg2Med', label: 'D2: Medium' },
                { name: 'Deg2Opt1', label: 'D2: Optional -1' },
                { name: 'Deg2Univ', label: 'D2: University' },
                { name: 'Deg2PassYr', label: 'D2: Passed Year' },
                { name: 'Deg2Percent', label: 'D2: Pass Percent' }
            ];

            d2RequiredFields.forEach(function (field) {
                var input = form.querySelector('[name="' + field.name + '"]');
                var value = input ? (input.value || '').trim() : '';
                if (!input || value === '' || value === '-- Select --' || value === 'null') {
                    missing.push(field.label);
                }
            });
        }
    }

    // Check PG degree requirements based on pg_degrees_acquired dropdown
    var pgDegreesAcquiredEl = form.querySelector('[name="pg_degrees_acquired"]');
    if (pgDegreesAcquiredEl && pgDegreesAcquiredEl.value) {
        var pgDegreesCount = parseInt(pgDegreesAcquiredEl.value);

        if (pgDegreesCount >= 1) {
            // PG1 fields are required
            var pg1RequiredFields = [
                { name: 'Pg1Course', label: 'PG-1: Course' },
                { name: 'Pg1Subject', label: 'PG-1: Subject' },
                { name: 'Pg1Univ', label: 'PG-1: University' },
                { name: 'Pg1PassYr', label: 'PG-1: Passed Year' },
                { name: 'Pg1Percent', label: 'PG-1: Pass Percent' }
            ];

            pg1RequiredFields.forEach(function (field) {
                var input = form.querySelector('[name="' + field.name + '"]');
                var value = input ? (input.value || '').trim() : '';
                if (!input || value === '' || value === '-- Select --' || value === 'null') {
                    missing.push(field.label);
                }
            });
        }

        if (pgDegreesCount >= 2) {
            // PG2 fields are required
            var pg2RequiredFields = [
                { name: 'Pg2Course', label: 'PG-2: Course' },
                { name: 'Pg2Subject', label: 'PG-2: Subject' },
                { name: 'Pg2Univ', label: 'PG-2: University' },
                { name: 'Pg2PassYr', label: 'PG-2: Passed Year' },
                { name: 'Pg2Percent', label: 'PG-2: Pass Percent' }
            ];

            pg2RequiredFields.forEach(function (field) {
                var input = form.querySelector('[name="' + field.name + '"]');
                var value = input ? (input.value || '').trim() : '';
                if (!input || value === '' || value === '-- Select --' || value === 'null') {
                    missing.push(field.label);
                }
            });
        }
    }

    // Check DEd/TTC training requirements only when a training record is selected
    var dedTrainingsAcquiredEl = form.querySelector('[name="ded_trainings_acquired"]');
    if (dedTrainingsAcquiredEl && dedTrainingsAcquiredEl.value) {
        var dedTrainingsCount = parseInt(dedTrainingsAcquiredEl.value, 10);
        if (dedTrainingsCount >= 1) {
            var dedRequiredFields = [
                { name: 'UgTrngCourse', label: 'Training Course' },
                { name: 'UgTrngMedium', label: 'Medium' },
                { name: 'UgTrngBoardUniv', label: 'Board/ University' },
                { name: 'UgTrngPassYr', label: 'Passed Year' },
                { name: 'UgTrngPercent', label: 'Pass Percent' }
            ];

            dedRequiredFields.forEach(function (field) {
                var input = form.querySelector('[name="' + field.name + '"]');
                var fieldContainer = input ? input.closest('.ded-field') : null;
                var isVisible = fieldContainer && fieldContainer.classList.contains('show');
                if (isVisible) {
                    var value = input ? (input.value || '').trim() : '';
                    if (!input || value === '' || value === '-- Select --' || value === 'null') {
                        missing.push('L. DEd/TTC: ' + field.label);
                    }
                }
            });
        }
    }

    // Check PT training requirements based on pt_trainings_acquired dropdown
    var ptTrainingsAcquiredEl = form.querySelector('[name="pt_trainings_acquired"]');
    if (ptTrainingsAcquiredEl && ptTrainingsAcquiredEl.value) {
        var ptTrainingsCount = parseInt(ptTrainingsAcquiredEl.value);

        if (ptTrainingsCount >= 1) {
            // PT1 fields are required only if visible
            var pt1RequiredFields = [
                { name: 'Grad1TrngCourse', label: 'PT-1: Training Course' },
                { name: 'Grad1TrngMed', label: 'PT-1: Medium' },
                { name: 'Grad1TrngMthd1', label: 'PT-1: Method-1' },
                { name: 'Grad1TrngMthd2', label: 'PT-1: Method-2' },
                { name: 'Grad1TrngUniv', label: 'PT-1: Training Board/ Univ.' },
                { name: 'Grad1TrngPassYr', label: 'PT-1: Trainging Passed Year' },
                { name: 'Grad1TrngPercent', label: 'PT-1: Trainging Pass Percent' }
            ];

            pt1RequiredFields.forEach(function (field) {
                var input = form.querySelector('[name="' + field.name + '"]');
                // Only validate if the field's parent container is visible
                var fieldContainer = input ? input.closest('.pt1-field') : null;
                var isVisible = fieldContainer && fieldContainer.classList.contains('show');

                if (isVisible) {
                    var value = input ? (input.value || '').trim() : '';
                    if (!input || value === '' || value === '-- Select --' || value === 'null') {
                        missing.push('M. BEd: ' + field.label);
                    }
                }
            });
        }

        if (ptTrainingsCount >= 2) {
            // PT2 fields are required only if visible
            var pt2RequiredFields = [
                { name: 'Grad2TrngCourse', label: 'PT-2: Training Course' },
                { name: 'Grad2TrngMed', label: 'PT-2: Medium' },
                { name: 'Grad2TrngMthd1', label: 'PT-2: Method-1' },
                { name: 'Grad2TrngMthd2', label: 'PT-2: Method-2' },
                { name: 'Grad2TrngUniv', label: 'PT-2: Training Board/ Univ.' },
                { name: 'Grad2TrngPassYr', label: 'PT-2: Trainging Passed Year' },
                { name: 'Grad2TrngPercent', label: 'PT-2: Trainging Pass Percent' }
            ];

            pt2RequiredFields.forEach(function (field) {
                var input = form.querySelector('[name="' + field.name + '"]');
                // Only validate if the field's parent container is visible
                var fieldContainer = input ? input.closest('.pt2-field') : null;
                var isVisible = fieldContainer && fieldContainer.classList.contains('show');

                if (isVisible) {
                    var value = input ? (input.value || '').trim() : '';
                    if (!input || value === '' || value === '-- Select --' || value === 'null') {
                        missing.push('M. BEd: ' + field.label);
                    }
                }
            });
        }
    }

    // Check PT PG training requirements based on pt_pg_trainings_acquired dropdown
    var ptPgTrainingsAcquiredEl = form.querySelector('[name="pt_pg_trainings_acquired"]');
    if (ptPgTrainingsAcquiredEl && ptPgTrainingsAcquiredEl.value) {
        var ptPgTrainingsCount = parseInt(ptPgTrainingsAcquiredEl.value);

        if (ptPgTrainingsCount >= 1) {
            // PT PG fields are required only if visible
            var ptpgRequiredFields = [
                { name: 'MedCourse', label: 'Training Course' },
                { name: 'MedUniv', label: 'Training University' },
                { name: 'MedPassYr', label: 'Training Passed Year' },
                { name: 'MedPercent', label: 'Training Pass Percent' }
            ];

            ptpgRequiredFields.forEach(function (field) {
                var input = form.querySelector('[name="' + field.name + '"]');
                // Only validate if the field's parent container is visible
                var fieldContainer = input ? input.closest('.ptpg-field') : null;
                var isVisible = fieldContainer && fieldContainer.classList.contains('show');

                if (isVisible) {
                    var value = input ? (input.value || '').trim() : '';
                    if (!input || value === '' || value === '-- Select --' || value === 'null') {
                        missing.push('N. MEd: ' + field.label);
                    }
                }
            });
        }
    }

    // Check test-dependent field requirements based on Y/N dropdown values
    var testMappings = [
        { dropdown: 'EotYN', dependentClass: '.eot-dependent', fieldNames: [{ name: 'EotPass', label: 'EOT Passed Year' }], testName: 'EOT' },
        { dropdown: 'GotYN', dependentClass: '.got-dependent', fieldNames: [{ name: 'GotPass', label: 'GOT Passed Year' }], testName: 'GOT' },
        { dropdown: 'LttYN', dependentClass: '.ltt-dependent', fieldNames: [{ name: 'LttPass', label: 'Telugu Test Passed Year' }], testName: 'Telugu Language Test' },
        { dropdown: 'HttYN', dependentClass: '.htt-dependent', fieldNames: [{ name: 'HttPass', label: 'Hindi Test Passed Year' }], testName: 'Hindi Language Test' },
        { dropdown: 'OtherTestYN', dependentClass: '.other-dependent', fieldNames: [{ name: 'OtherTestPassYear', label: 'Other Dept Test Passed Year' }], testName: 'Other Departmental Test' },
        { dropdown: 'tet1passed', dependentClass: '.tet1-dependent', fieldNames: [{ name: 'TetP1Htno', label: 'TET Paper-1 Hall Ticket No' }, { name: 'TetP1PassYr', label: 'TET Paper-1 Passed Year' }], testName: 'TET Paper-1' },
        { dropdown: 'tet2passed', dependentClass: '.tet2-dependent', fieldNames: [{ name: 'TetP2Htno', label: 'TET Paper-2 Hall Ticket No' }, { name: 'TetP2PassYr', label: 'TET Paper-2 Passed Year' }], testName: 'TET Paper-2' }
    ];

    testMappings.forEach(function (test) {
        var dropdownEl = form.querySelector('[name="' + test.dropdown + '"]');
        if (dropdownEl && dropdownEl.value === 'YES') {
            // Test is passed, dependent fields are required
            test.fieldNames.forEach(function (field) {
                var input = form.querySelector('[name="' + field.name + '"]');
                // Only validate if the field's parent container is visible
                var fieldContainer = input ? input.closest(test.dependentClass) : null;
                var isVisible = fieldContainer && fieldContainer.classList.contains('show');

                if (isVisible) {
                    var value = input ? (input.value || '').trim() : '';
                    if (!input || value === '' || value === '-- Select --' || value === 'null') {
                        missing.push(test.testName + ': ' + field.label);
                    }
                }
            });
        }
    });

    return missing;
}

// Validate only required fields that belong to the Service tab (R/S/T sections)
function validateServiceRequiredFields() {
    var missing = [];
    var form = document.querySelector('form[action="teacher_edit.php"]') || document.forms[0];
    if (!form) return missing;

    // select inputs inside service tab sections OR service-specific classes
    var selectors = [
        '.tp-section[data-tab="Service"] input[name], .tp-section[data-tab="Service"] select[name], .tp-section[data-tab="Service"] textarea[name]',
        '.tp-section.sgt-section input[name], .tp-section.sa-section input[name], .tp-section.gaz-section input[name]',
        '.tp-section.sgt-section select[name], .tp-section.sa-section select[name], .tp-section.gaz-section select[name]',
        '.tp-section.sgt-section textarea[name], .tp-section.sa-section textarea[name], .tp-section.gaz-section textarea[name]'
    ];

    var elems = [];
    selectors.forEach(function (sel) { Array.prototype.push.apply(elems, Array.from(document.querySelectorAll(sel))); });

    // Deduplicate by name
    var seen = {};
    elems.forEach(function (el) {
        if (!el.name) return; if (seen[el.name]) return; seen[el.name] = true;
        // Skip fields that are explicitly hidden by hide-manual
        var col = el.closest('.tp-col');
        if (col && col.classList.contains('hide-manual')) return;
        // Skip fields that are not visible (display: none)
        try {
            var cs = window.getComputedStyle(col || el);
            if (cs && (cs.display === 'none' || cs.visibility === 'hidden')) return;
        } catch (e) { }
        // Consider only elements that are required (set via required attribute) OR marked required in the label (server prints a red *)
        var isRequiredAttr = (el.hasAttribute && el.hasAttribute('required'));
        var labEl = col ? col.querySelector('.field-label') : null;
        var labelHasStar = false;
        try { if (labEl && (labEl.innerHTML.indexOf('*') !== -1 || labEl.textContent.indexOf('*') !== -1)) labelHasStar = true; } catch (e) { }
        if (!isRequiredAttr && !labelHasStar) return;
        var val = (el.type === 'checkbox' || el.type === 'radio') ? (el.checked ? '1' : '') : (el.value || '').trim();
        if (val === '' || val === '-- Select --' || val === 'null') {
            // find the label text
            var label = '';
            var labEl = col ? col.querySelector('.field-label') : null;
            if (!labEl) labEl = el.closest('label');
            if (labEl) label = labEl.textContent || labEl.innerText || '';
            label = (label || el.name).toString().replace(/\s*\*/, '').trim();
            missing.push(label);
        }
    });

    return missing;
}

function showValidationMessage(missingFields) {
    if (missingFields.length === 0) return true;
    var hasBankError = missingFields.some(function (field) { return field.includes('🏦 BANK INFORMATION'); });

    var message = '';
    if (hasBankError) {
        message += '🏦 BANK INFORMATION SECTION:\nAll bank details are mandatory. Enter a valid IFSC to auto-fill bank details.\n\n';
    }
    message += 'The following fields must be filled before saving:\n\n' + missingFields.map(function (f) { return '• ' + f; }).join('\n');
    message += '\n\n✅ Please complete required fields (marked with *).';

    // If main.js exposes showValidationModal, delegate to it so modal behavior lives in main.js
    try {
        if (window && typeof window.showValidationModal === 'function') {
            // Choose a sensible focus selector: first required input in Service
            var focusSel = '.tp-section[data-tab="Service"] .edit-input[required], .tp-section.sgt-section .edit-input[required], .tp-section.sa-section .edit-input[required]';
            var ok = window.showValidationModal(message, focusSel);
            if (ok) return false;
        }
    } catch (e) { }

    // Fallback to alert
    alert(message);
    return false;
}

// Override form submission for validation
document.addEventListener('DOMContentLoaded', function () {
    // Intercept the "Save All Changes" button
    var saveAllBtn = document.querySelector('button[name="save_all"]');
    if (saveAllBtn) {

        // Ensure SERVICE subsections (R,S,T) are assigned to the Service tab
        try {
            var svcLetters = ['R', 'S', 'T'];
            svcLetters.forEach(function (letter) {
                // find any section with that letter and reassign it to Service
                var all = Array.from(document.querySelectorAll('.tp-section[data-letter="' + letter + '"]'));
                if (!all.length) return;
                // choose the best candidate to keep: prefer the one that contains a known field
                var keep = null;
                if (letter === 'R') keep = all.find(function (n) { return n.querySelector('[name="sgtrendered"], [name="SgtAppType"], [name="SgtCadreJoiningDate" ]'); });
                if (letter === 'S') keep = all.find(function (n) { return n.querySelector('[name="SaAppType"], [name="SaDscHtno"]'); });
                if (letter === 'T') keep = all.find(function (n) { return n.querySelector('[name="GHMGrIIDesign"],[name="GHMGrIIDOJ"]'); });
                // fallback to first
                if (!keep) keep = all[0];
                // assign keep to Service and add classes
                keep.setAttribute('data-tab', 'Service');
                if (letter === 'R') keep.classList.add('sgt-section');
                if (letter === 'S') keep.classList.add('sa-section');
                if (letter === 'T') keep.classList.add('gaz-section');
                // remove other duplicates
                all.forEach(function (n) { if (n !== keep) { try { n.parentNode && n.parentNode.removeChild(n); } catch (e) { } } });
            });
            // refresh local sections array after mutation
            sections = Array.from(document.querySelectorAll('.tp-section'));
        } catch (e) { console && console.warn && console.warn('ensureServiceSectionsAssigned failed', e); }
        saveAllBtn.addEventListener('click', function (e) {
            var missing = validateRequiredFields();
            if (missing.length > 0) {
                e.preventDefault();
                showValidationMessage(missing);
                return false;
            }

            // If active tab is Service, also validate service-only required fields
            var active = tabs.findIndex(function (t) { return t.classList.contains('tp-tab-active'); });
            var label = tabs[active] ? tabs[active].getAttribute('data-tab-label') : '';
            if (label === 'Service') {
                var svcMissing = validateServiceRequiredFields();
                if (svcMissing.length > 0) {
                    e.preventDefault();
                    showValidationMessage(svcMissing);
                    return false;
                }
            }
        });
    }

    // Intercept the form submission
    var form = document.querySelector('form[action="teacher_edit.php"]') || document.forms[0];
    if (form) {
        form.addEventListener('submit', function (e) {
            var missing = validateRequiredFields();
            if (missing.length > 0) {
                e.preventDefault();
                showValidationMessage(missing);
                return false;
            }
        });
    }
    // If server set a message during the last save, surface it using modal or alert
    try {
        if (window && window.__SERVER_MSG && window.__SERVER_MSG.text) {
            var m = window.__SERVER_MSG.text;
            var isErr = !!window.__SERVER_MSG.isError;
            if (window.showValidationModal && typeof window.showValidationModal === 'function') {
                // show modal and focus first required field when OK
                window.showValidationModal(m, isErr ? '.tp-section[data-tab="Service"] .edit-input[required], .tp-section.sgt-section .edit-input[required]' : null);
            } else {
                alert(m);
            }
            // clear it so it doesn't re-show on navigation
            try { delete window.__SERVER_MSG; } catch (e) { }
        }
    } catch (e) { }
});


/* ========================================================================
   Script Block 6
   ======================================================================== */

// Convert common date text inputs to HTML5 date inputs to show date pickers
document.addEventListener('DOMContentLoaded', function () {
    try {
        var dateNames = ['dob', 'dort', 'SchJoinDate', 'SgtJoinDate', 'SgtJoindate', 'Sgtjoindate', 'PrsntCdrSenDate', 'SgtJoinDate', 'FirstApptmntDate', 'FeederCadreDate', 'PresentCadreDate', 'SgtRegDate', 'SgtAbsrpDate', 'SaRegDate', 'GHMGrIIDOJ'];
        dateNames.forEach(function (n) {
            var el = document.querySelector('[name="' + n + '"]');
            if (!el) return;
            // If it's an input and not already type=date, and value matches YYYY-MM-DD, convert
            if (el.tagName.toLowerCase() === 'input') {
                var curType = el.getAttribute('type') || 'text';
                if (curType !== 'date') {
                    var v = el.value || '';
                    if (/^\d{4}-\d{2}-\d{2}$/.test(v) || v === '') {
                        try { el.type = 'date'; } catch (e) { }
                    }
                }
            }
        });
    } catch (e) { console && console.warn && console.warn('date-picker conversion failed', e); }
});


/* ========================================================================
   Script Block 7
   ======================================================================== */

// Caste certificate upload visibility and filename preview
(function () {
    function findCasteInput() {
        // try common field name variations for caste
        var f = document.querySelector('[name="caste"]') || document.querySelector('[name="Caste"]') || document.querySelector('[name="caste_ofthe_teacher"]');
        return f;
    }
    var casteInput = findCasteInput();
    var uploadArea = document.getElementById('caste-upload-area');
    var fileNameDiv = document.getElementById('caste-file-name');
    var fileInput = document.getElementById('CasteCert_file');

    function updateVisibility() {
        if (!uploadArea) return;
        var val = (casteInput && casteInput.value) ? casteInput.value : '';
        // allow any value containing SC or ST (case-insensitive) similar to SQL LIKE '%SC%' or '%ST%'
        var allowed = /(SC|ST)/i.test(val);
        uploadArea.style.display = allowed ? '' : 'none';
        // Update display based on caste selection
        var wrapper = document.getElementById('castecert-wrapper');
        var linkDiv = document.getElementById('castecert-link');
        var noneDiv = document.getElementById('castecert-none');

        if (!allowed) {
            // Hide upload area and update file input message
            if (fileNameDiv) fileNameDiv.textContent = 'No file chosen. Upload allowed only for SC/ST.';

            // Show appropriate message based on whether certificate exists
            if (wrapper && wrapper.dataset && wrapper.dataset.existingFile) {
                // Certificate exists but caste is non-SC/ST
                if (linkDiv) {
                    linkDiv.style.display = 'none';
                }
                if (noneDiv) {
                    noneDiv.textContent = '📎 Certificate available (shown only for SC/ST caste).';
                    noneDiv.style.display = '';
                }
            }
        } else {
            // SC/ST selected - show certificate if it exists
            if (wrapper && wrapper.dataset && wrapper.dataset.existingFile) {
                if (linkDiv) {
                    linkDiv.style.display = '';
                } else {
                    // Create the link element if it doesn't exist
                    var newLinkDiv = document.createElement('div');
                    newLinkDiv.id = 'castecert-link';
                    newLinkDiv.style.marginBottom = '6px';
                    newLinkDiv.innerHTML = '<a href="/uploads/caste_certificates/' + encodeURIComponent(wrapper.dataset.existingFile) + '" target="_blank" style="color:#007bff;text-decoration:underline">📄 ' + wrapper.dataset.existingFile + '</a>';
                    wrapper.insertBefore(newLinkDiv, wrapper.firstChild);
                }
                if (noneDiv) {
                    noneDiv.style.display = 'none';
                }
            } else {
                // No certificate exists
                if (noneDiv) {
                    noneDiv.textContent = 'No caste certificate uploaded.';
                    noneDiv.style.display = '';
                }
            }
        }
    }

    if (casteInput) {
        casteInput.addEventListener('change', updateVisibility);
        casteInput.addEventListener('input', updateVisibility);
        // Run on page load to set initial visibility
        updateVisibility();
    }
    if (fileInput && fileNameDiv) {
        fileInput.addEventListener('change', function (e) {
            var f = fileInput.files && fileInput.files[0];
            if (!f) { fileNameDiv.textContent = 'No file chosen.'; return; }
            // enforce client-side size and type checks
            var allowedTypes = ['image/jpeg', 'image/png'];
            if (allowedTypes.indexOf(f.type) === -1) {
                fileNameDiv.textContent = 'Invalid file type. Only JPG/JPEG or PNG allowed.';
                fileInput.value = '';
                return;
            }
            fileNameDiv.textContent = f.name + ' (' + Math.round(f.size / 1024) + ' KB) - will be compressed when uploaded.';
        });
    }
    // initial run
    setTimeout(updateVisibility, 50);
})();


/* ========================================================================
   Script Block 8
   ======================================================================== */

// PHC section client-side enforcement: when PhcYN = NO disable and clear PHC inputs; when YES mark required
(function () {
    function findPhcYn() {
        return document.querySelector('[name="PhcYN"]') || document.querySelector('[name="phcyn"]') || document.querySelector('[name="PhcYn"]');
    }
    var phcYn = findPhcYn();
    if (!phcYn) return;
    // list of PHC field names to control (these are rendered as inputs elsewhere in the form)
    var phcFields = ['PhcType', 'PhcPercent', 'PhcAuth', 'PhcCertNo', 'PhcCertDate', 'PhcCertValidity', 'PhcCertReassess'];

    // Store original values to preserve when toggling
    var originalValues = {};
    phcFields.forEach(function (n) {
        var el = document.querySelector('[name="' + n + '"]') || document.querySelector('[name="' + n.toLowerCase() + '"]');
        if (el) {
            originalValues[n] = el.value || '';
        }
    });

    function setPhcState() {
        var val = (phcYn.value || '').toString().toUpperCase();
        var isYes = (val === 'YES');

        phcFields.forEach(function (n) {
            var el = document.querySelector('[name="' + n + '"]') || document.querySelector('[name="' + n.toLowerCase() + '"]');
            if (!el) return;
            if (!isYes) {
                // Store current value before disabling
                if (el.value) {
                    originalValues[n] = el.value;
                }
                el.setAttribute('disabled', 'disabled');
                el.removeAttribute('required');
                el.style.backgroundColor = '#f5f5f5';
            } else {
                // Restore original value and enable
                if (originalValues[n]) {
                    el.value = originalValues[n];
                }
                el.removeAttribute('disabled');
                el.setAttribute('required', 'required');
                el.style.backgroundColor = '';
            }
        });

        // Handle PHC certificate visibility and file upload
        updatePhcCertificateVisibility(isYes);

        // Apply consistent field styling after state change
        if (typeof applyFieldStyling === 'function') {
            setTimeout(applyFieldStyling, 50);
        }
    }

    function updatePhcCertificateVisibility(isYes) {
        var wrapper = document.getElementById('phcupload-wrapper');
        var linkDiv = document.getElementById('phcupload-link');
        var noneDiv = document.getElementById('phcupload-none');
        var uploadArea = document.getElementById('phc-upload-area');
        var fileInput = document.getElementById('Phcupload_file');

        if (!wrapper) return;

        if (!isYes) {
            // PHC = NO: hide certificate link and file upload area
            if (uploadArea) {
                uploadArea.style.display = 'none';
            }
            if (fileInput) {
                fileInput.removeAttribute('required');
                fileInput.setAttribute('disabled', 'disabled');
            }

            // Show appropriate message based on whether certificate exists
            if (wrapper && wrapper.dataset && wrapper.dataset.existingFile) {
                // Certificate exists but PHC is NO - hide link, show message
                if (linkDiv) {
                    linkDiv.style.display = 'none';
                }
                if (noneDiv) {
                    noneDiv.textContent = '📎 PHC Certificate available (shown only when PHC = YES).';
                    noneDiv.style.display = '';
                }
            } else {
                // No certificate exists
                if (linkDiv) {
                    linkDiv.style.display = 'none';
                }
                if (noneDiv) {
                    noneDiv.textContent = 'No PHC certificate uploaded.';
                    noneDiv.style.display = '';
                }
            }
        } else {
            // PHC = YES: show certificate if it exists and show upload area
            if (uploadArea) {
                uploadArea.style.display = '';
            }
            if (fileInput) {
                fileInput.removeAttribute('disabled');
                // File input is optional if certificate already exists, required if no certificate
                var hasExistingFile = wrapper && wrapper.dataset && wrapper.dataset.existingFile;
                if (hasExistingFile) {
                    fileInput.removeAttribute('required'); // Optional when file exists
                } else {
                    fileInput.setAttribute('required', 'required'); // Required when no file exists
                }
            }

            if (wrapper && wrapper.dataset && wrapper.dataset.existingFile) {
                // Show downloadable link when certificate exists and PHC = YES
                if (linkDiv) {
                    linkDiv.style.display = '';
                } else {
                    // Create the link element if it doesn't exist
                    var newLinkDiv = document.createElement('div');
                    newLinkDiv.id = 'phcupload-link';
                    newLinkDiv.style.marginBottom = '6px';
                    newLinkDiv.innerHTML = '<a id="phcupload-anchor" href="/uploads/phc_certificates/' + encodeURIComponent(wrapper.dataset.existingFile) + '" target="_blank" style="color:#007bff;text-decoration:underline">📄 ' + wrapper.dataset.existingFile + '</a>';
                    wrapper.insertBefore(newLinkDiv, wrapper.firstChild);
                }
                if (noneDiv) {
                    noneDiv.style.display = 'none';
                }
            } else {
                // No certificate exists
                if (linkDiv) {
                    linkDiv.style.display = 'none';
                }
                if (noneDiv) {
                    noneDiv.textContent = 'No PHC certificate uploaded.';
                    noneDiv.style.display = '';
                }
            }
        }
    }
    // Add PHC file upload handler
    var phcFileInput = document.getElementById('Phcupload_file');
    var phcFileNameDiv = document.getElementById('phcupload-filename');

    if (phcFileInput && phcFileNameDiv) {
        phcFileInput.addEventListener('change', function (e) {
            var f = phcFileInput.files && phcFileInput.files[0];
            if (!f) {
                phcFileNameDiv.textContent = 'No file chosen. (JPG, PNG or PDF. File will be compressed on save if needed.)';
                return;
            }
            // enforce client-side size and type checks
            var allowedTypes = ['image/jpeg', 'image/png', 'application/pdf'];
            if (allowedTypes.indexOf(f.type) === -1) {
                phcFileNameDiv.textContent = 'Invalid file type. Only JPG/JPEG, PNG or PDF allowed.';
                phcFileInput.value = '';
                return;
            }
            phcFileNameDiv.textContent = f.name + ' (' + Math.round(f.size / 1024) + ' KB) - will be compressed on save if needed.';
        });
    }

    // Set up event listeners
    phcYn.addEventListener('change', setPhcState);
    phcYn.addEventListener('input', setPhcState);

    // Also listen for changes in PHC fields to preserve values
    phcFields.forEach(function (n) {
        var el = document.querySelector('[name="' + n + '"]') || document.querySelector('[name="' + n.toLowerCase() + '"]');
        if (el) {
            el.addEventListener('change', function () {
                if (!el.disabled) {
                    originalValues[n] = el.value;
                }
            });
            el.addEventListener('input', function () {
                if (!el.disabled) {
                    originalValues[n] = el.value;
                }
            });
        }
    });

    // run initially to set proper state
    setTimeout(setPhcState, 100);
})();


/* ========================================================================
   Script Block 9
   ======================================================================== */

// Spouse Information Conditional Logic
(function () {
    var maritalStatusSel = document.querySelector('[name="MaritalStatus"]');
    var spGovtEmpYNSel = document.querySelector('[name="SpGovtEmpYN"]');

    // Define spouse fields that should be conditionally visible/required
    var spouseFields = ['SpTreasuryCode', 'SpDesign', 'SpName', 'SpDeptName', 'SpOfficeName', 'SpWorkArea', 'SpDeptType', 'PostType'];
    var originalSpouseValues = {};
    var isUpdatingSpouseFields = false; // Flag to prevent recursive calls

    function setSpouseFieldsState() {
        if (isUpdatingSpouseFields) return; // Prevent recursive calls
        isUpdatingSpouseFields = true;
        var maritalStatus = (maritalStatusSel ? maritalStatusSel.value : '').toString().toUpperCase();
        var spGovtEmpYN = (spGovtEmpYNSel ? spGovtEmpYNSel.value : '').toString().toUpperCase();

        var isMarried = (maritalStatus === 'MARRIED');
        var isSpGovtEmp = (spGovtEmpYN === 'YES');
        var showSpouseDetails = isMarried && isSpGovtEmp;

        // Handle SpGovtEmpYN visibility and requirement
        if (spGovtEmpYNSel) {
            if (!isMarried) {
                // Store current value before disabling
                if (spGovtEmpYNSel.value) {
                    originalSpouseValues['SpGovtEmpYN'] = spGovtEmpYNSel.value;
                }
                spGovtEmpYNSel.setAttribute('disabled', 'disabled');
                spGovtEmpYNSel.removeAttribute('required');
                spGovtEmpYNSel.style.backgroundColor = '#f5f5f5';
                spGovtEmpYNSel.value = '';
            } else {
                // Restore and enable when married
                spGovtEmpYNSel.removeAttribute('disabled');
                spGovtEmpYNSel.removeAttribute('readonly');
                spGovtEmpYNSel.removeAttribute('tabindex');
                spGovtEmpYNSel.setAttribute('required', 'required');
                spGovtEmpYNSel.style.backgroundColor = '';
                spGovtEmpYNSel.style.pointerEvents = '';
                spGovtEmpYNSel.style.opacity = '';
                // Ensure the field is fully interactive
                spGovtEmpYNSel.disabled = false;
                // Clear any stored value to ensure fresh selection
                delete originalSpouseValues['SpGovtEmpYN'];
            }
        }

        // Handle spouse detail fields
        spouseFields.forEach(function (fieldName) {
            var el = document.querySelector('[name="' + fieldName + '"]') || document.querySelector('[name="' + fieldName.toLowerCase() + '"]');
            if (!el) return;

            if (!showSpouseDetails) {
                // Store current value before disabling
                if (el.value) {
                    originalSpouseValues[fieldName] = el.value;
                }
                el.setAttribute('disabled', 'disabled');
                el.removeAttribute('required');
                el.style.backgroundColor = '#f5f5f5';
                if (!isMarried) {
                    el.value = ''; // Clear if not married at all
                }
            } else {
                // Restore original value and enable
                if (originalSpouseValues[fieldName]) {
                    el.value = originalSpouseValues[fieldName];
                }
                el.removeAttribute('disabled');
                el.setAttribute('required', 'required');
                el.style.backgroundColor = '';
            }
        });

        // Reset the flag to allow future calls
        isUpdatingSpouseFields = false;

        // Apply consistent field styling after state change
        if (typeof applyFieldStyling === 'function') {
            setTimeout(applyFieldStyling, 50);
        }
    }

    // Set up event listeners
    if (maritalStatusSel) {
        maritalStatusSel.addEventListener('change', setSpouseFieldsState);
        maritalStatusSel.addEventListener('input', setSpouseFieldsState);
    }

    if (spGovtEmpYNSel) {
        spGovtEmpYNSel.addEventListener('change', setSpouseFieldsState);
        spGovtEmpYNSel.addEventListener('input', setSpouseFieldsState);
    }

    // Also listen for changes in spouse fields to preserve values
    spouseFields.forEach(function (fieldName) {
        var el = document.querySelector('[name="' + fieldName + '"]') || document.querySelector('[name="' + fieldName.toLowerCase() + '"]');
        if (el) {
            el.addEventListener('change', function () {
                if (!el.disabled) {
                    originalSpouseValues[fieldName] = el.value;
                }
            });
            el.addEventListener('input', function () {
                if (!el.disabled) {
                    originalSpouseValues[fieldName] = el.value;
                }
            });
        }
    });

    // Run initially to set proper state
    setTimeout(setSpouseFieldsState, 100);
})();


/* ========================================================================
   Script Block 10
   ======================================================================== */

// Assembly Constituency Cascaded Dropdown functionality
(function () {
    // Function to populate constituency dropdown via AJAX
    function populateConstituencyDropdown(dropdownName, selectedDistrict, defaultText) {
        var dropdown = document.querySelector('[name="' + dropdownName + '"]');
        if (!dropdown) return;

        // Preserve the current selected value
        var currentValue = dropdown.value;

        if (selectedDistrict) {
            // Make AJAX request to get constituencies for the district
            var xhr = new XMLHttpRequest();
            xhr.open('GET', 'ajax_get_constituencies.php?action=get_constituencies&district=' + encodeURIComponent(selectedDistrict), true);
            xhr.onreadystatechange = function () {
                if (xhr.readyState === 4 && xhr.status === 200) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        dropdown.innerHTML = '<option value="">' + defaultText + '</option>';
                        if (response.constituencies) {
                            response.constituencies.forEach(function (constituency) {
                                var option = document.createElement('option');
                                option.value = constituency;
                                option.textContent = constituency;
                                // Restore the previously selected value if it matches
                                if (constituency === currentValue) {
                                    option.selected = true;
                                }
                                dropdown.appendChild(option);
                            });
                        }
                    } catch (e) {
                        console.error('Error parsing constituencies response:', e);
                        dropdown.innerHTML = '<option value="">' + defaultText + '</option>';
                    }
                }
            };
            xhr.send();
        } else {
            dropdown.innerHTML = '<option value="">' + defaultText + '</option>';
        }
    }

    // Function to populate all constituencies (for dropdowns not dependent on district)
    function populateAllConstituencies(dropdownName, defaultText) {
        var dropdown = document.querySelector('[name="' + dropdownName + '"]');
        if (!dropdown) return;

        // Preserve the current selected value
        var currentValue = dropdown.value;

        var xhr = new XMLHttpRequest();
        xhr.open('GET', 'ajax_get_constituencies.php?action=get_all_constituencies', true);
        xhr.onreadystatechange = function () {
            if (xhr.readyState === 4 && xhr.status === 200) {
                try {
                    var response = JSON.parse(xhr.responseText);
                    dropdown.innerHTML = '<option value="">' + defaultText + '</option>';
                    if (response.constituencies) {
                        response.constituencies.forEach(function (constituency) {
                            var option = document.createElement('option');
                            option.value = constituency;
                            option.textContent = constituency;
                            // Restore the previously selected value if it matches
                            if (constituency === currentValue) {
                                option.selected = true;
                            }
                            dropdown.appendChild(option);
                        });
                    }
                } catch (e) {
                    console.error('Error parsing all constituencies response:', e);
                    dropdown.innerHTML = '<option value="">' + defaultText + '</option>';
                }
            }
        };
        xhr.send();
    }

    // Set up cascaded dropdown for Assembly Constituency
    var districtSelect = document.querySelector('[name="AssmblyConstDist"]');
    if (districtSelect) {
        districtSelect.addEventListener('change', function () {
            var selectedDistrict = this.value;
            populateConstituencyDropdown('AssmblyConstName', selectedDistrict, '-- Select --');
        });
    }

    // Initialize all constituency dropdowns on page load
    setTimeout(function () {
        populateAllConstituencies('RsConstName', '-- Select --');
        populateAllConstituencies('NativeConstName', '-- Select --');
        populateAllConstituencies('WorkConstName', '-- Select --');

        // Also initialize AssmblyConstName if district is already selected
        if (districtSelect && districtSelect.value) {
            populateConstituencyDropdown('AssmblyConstName', districtSelect.value, '-- Select --');
        } else {
            // If no district is selected, populate with all constituencies to preserve any existing value
            populateAllConstituencies('AssmblyConstName', '-- Select --');
        }
    }, 100);
})();


/* ========================================================================
   Script Block 11
   ======================================================================== */

// IFSC Code lookup functionality
(function () {
    var ifscInput = document.getElementById('ifsc-input');
    var statusDiv = document.getElementById('ifsc-status');
    var branchInput = document.getElementById('bank-branch');
    var bankInput = document.getElementById('bank-bank');
    var acstateInput = document.getElementById('bank-acstate');

    if (!ifscInput) return;

    var lookupTimeout;

    function lookupIFSC(ifscCode) {
        if (!ifscCode || ifscCode.length < 4) {
            clearBankFields();
            return;
        }

        // Show loading
        if (statusDiv) {
            statusDiv.textContent = 'Looking up IFSC code...';
            statusDiv.style.color = '#666';
        }

        // Make AJAX request
        fetch('ajax_get_ifsc_details.php?ifsc=' + encodeURIComponent(ifscCode))
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Populate bank details
                    if (branchInput) branchInput.value = data.branch || '';
                    if (bankInput) bankInput.value = data.bank || '';
                    if (acstateInput) acstateInput.value = data.acstate || '';

                    if (statusDiv) {
                        statusDiv.textContent = '✓ Bank details loaded successfully';
                        statusDiv.style.color = '#28a745';
                    }
                } else {
                    clearBankFields();
                    if (statusDiv) {
                        statusDiv.textContent = '⚠ ' + (data.error || 'IFSC code not found') + ' - Bank details are required';
                        statusDiv.style.color = '#dc3545';
                    }
                }
            })
            .catch(error => {
                clearBankFields();
                if (statusDiv) {
                    statusDiv.textContent = '⚠ Error looking up IFSC code';
                    statusDiv.style.color = '#dc3545';
                }
                console.error('IFSC lookup error:', error);
            });
    }

    function clearBankFields() {
        if (branchInput) branchInput.value = '';
        if (bankInput) bankInput.value = '';
        if (acstateInput) acstateInput.value = '';
    }

    // Event listeners for IFSC input
    ifscInput.addEventListener('input', function (e) {
        // Convert to uppercase
        e.target.value = e.target.value.toUpperCase();

        // Clear previous timeout
        clearTimeout(lookupTimeout);

        var ifscCode = e.target.value.trim();

        if (ifscCode.length >= 11) {
            // Lookup immediately for complete IFSC
            lookupTimeout = setTimeout(() => lookupIFSC(ifscCode), 300);
        } else if (ifscCode.length >= 4) {
            // Delay lookup for partial IFSC
            lookupTimeout = setTimeout(() => lookupIFSC(ifscCode), 800);
        } else {
            clearBankFields();
            if (statusDiv) {
                statusDiv.textContent = 'Enter IFSC code to auto-fill bank details - All bank fields are required';
                statusDiv.style.color = '#666';
            }
        }
    });

    ifscInput.addEventListener('blur', function (e) {
        var ifscCode = e.target.value.trim();
        if (ifscCode.length >= 11) {
            lookupIFSC(ifscCode);
        }
    });

    // Initial lookup if IFSC already has value
    setTimeout(function () {
        var existingIFSC = ifscInput.value.trim();
        if (existingIFSC && existingIFSC.length >= 11) {
            lookupIFSC(existingIFSC);
        } else if (statusDiv) {
            statusDiv.textContent = 'Enter IFSC code to auto-fill bank details - All bank fields are required';
            statusDiv.style.color = '#666';
        }
    }, 500);
})();


/* ========================================================================
   Script Block 12
   ======================================================================== */

// Degree Selection Functionality
function handleDegreeSelection(dropdown, skipClear) {
    var selectedValue = dropdown.value;
    var d1Fields = document.querySelectorAll('.d1-field');
    var d2Fields = document.querySelectorAll('.d2-field');

    if (selectedValue === '0') {
        // Hide all degree fields and clear them only if not initialization
        d1Fields.forEach(function (field) {
            field.classList.remove('show');
        });
        d2Fields.forEach(function (field) {
            field.classList.remove('show');
        });

        // Clear fields only if user is actively switching (not initialization)
        if (!skipClear) {
            clearDegreeFields('all');
            showDegreeMessage('📚 All degree fields are hidden. No degree information will be recorded.', 'info');
        }

    } else if (selectedValue === '1') {
        // Show only D1 fields, hide D2 fields
        d1Fields.forEach(function (field) {
            field.classList.add('show');
        });
        d2Fields.forEach(function (field) {
            field.classList.remove('show');
        });

        // Clear only D2 fields if user is actively switching
        if (!skipClear) {
            clearDegreeFields('d2');
            showDegreeMessage('🎓 Main degree fields are visible. Additional degree fields are hidden.', 'success');
        }

    } else if (selectedValue === '2') {
        // Show both D1 and D2 fields
        d1Fields.forEach(function (field) {
            field.classList.add('show');
        });
        d2Fields.forEach(function (field) {
            field.classList.add('show');
        });

        if (!skipClear) {
            showDegreeMessage('🏆 Both main degree and additional degree fields are visible.', 'success');
        }
    }

    // Apply consistent field styling after state change
    if (typeof applyFieldStyling === 'function') {
        setTimeout(applyFieldStyling, 50);
    }
}

function clearDegreeFields(scope) {
    // DATA PRESERVATION: Don't clear fields automatically when toggling dropdowns
    // This preserves user data when switching between visibility states
    // Fields will only be cleared if explicitly requested by user action
    console.log('clearDegreeFields called with scope:', scope, '- Data preserved, fields not cleared');
}

function showDegreeMessage(message, type) {
    // Create message element
    var msgDiv = document.createElement('div');
    msgDiv.textContent = message;
    msgDiv.style.cssText = 'position: fixed; top: 20px; right: 20px; padding: 12px 20px; border-radius: 6px; font-weight: bold; z-index: 9999; box-shadow: 0 4px 12px rgba(0,0,0,0.15); transition: all 0.3s ease; max-width: 350px;';

    // Set colors based on type
    if (type === 'success') {
        msgDiv.style.backgroundColor = '#d4edda';
        msgDiv.style.color = '#155724';
        msgDiv.style.border = '1px solid #c3e6cb';
    } else if (type === 'info') {
        msgDiv.style.backgroundColor = '#d1ecf1';
        msgDiv.style.color = '#0c5460';
        msgDiv.style.border = '1px solid #bee5eb';
    }

    // Add to page
    document.body.appendChild(msgDiv);

    // Animate in
    setTimeout(function () {
        msgDiv.style.transform = 'translateX(-10px)';
    }, 100);

    // Remove after 3 seconds
    setTimeout(function () {
        msgDiv.style.opacity = '0';
        msgDiv.style.transform = 'translateY(-20px)';
        setTimeout(function () {
            if (msgDiv.parentNode) {
                msgDiv.parentNode.removeChild(msgDiv);
            }
        }, 300);
    }, 3000);
}

// Helper function to check if fields have data
function checkFieldsHaveData(selector) {
    var fields = document.querySelectorAll(selector);
    var hasData = false;

    fields.forEach(function (field) {
        var inputs = field.querySelectorAll('input, select, textarea');
        inputs.forEach(function (input) {
            if (input.value && input.value.trim() !== '' && input.value !== '-- Select --') {
                hasData = true;
            }
        });
    });

    return hasData;
}

// Post Graduation Degree Selection Functionality
function handlePGDegreeSelection(dropdown, skipClear) {
    var selectedValue = dropdown.value;
    var pg1Fields = document.querySelectorAll('.pg1-field');
    var pg2Fields = document.querySelectorAll('.pg2-field');

    if (selectedValue === '0') {
        // Hide all PG degree fields
        pg1Fields.forEach(function (field) {
            field.classList.remove('show');
        });
        pg2Fields.forEach(function (field) {
            field.classList.remove('show');
        });

        // Clear fields only if user is actively switching (not initialization)
        if (!skipClear) {
            clearPGFields('all');
            showPGMessage('📚 All Post Graduation fields are hidden. No PG degree information will be recorded.', 'info');
        }

    } else if (selectedValue === '1') {
        // Show only PG1 fields, hide PG2 fields
        pg1Fields.forEach(function (field) {
            field.classList.add('show');
        });
        pg2Fields.forEach(function (field) {
            field.classList.remove('show');
        });

        // Clear only PG2 fields if user is actively switching
        if (!skipClear) {
            clearPGFields('pg2');
            showPGMessage('🎓 Post Graduation degree - 1 fields are visible. Degree - 2 fields are hidden.', 'success');
        }

    } else if (selectedValue === '2') {
        // Show both PG1 and PG2 fields
        pg1Fields.forEach(function (field) {
            field.classList.add('show');
        });
        pg2Fields.forEach(function (field) {
            field.classList.add('show');
        });

        if (!skipClear) {
            showPGMessage('🏆 Both Post Graduation degree - 1 and degree - 2 fields are visible.', 'success');
        }
    }

    // Apply consistent field styling after state change
    if (typeof applyFieldStyling === 'function') {
        setTimeout(applyFieldStyling, 50);
    }
}

function clearPGFields(scope) {
    // DATA PRESERVATION: Don't clear fields automatically when toggling dropdowns
    // This preserves user data when switching between visibility states
    console.log('clearPGFields called with scope:', scope, '- Data preserved, fields not cleared');
}

function showPGMessage(message, type) {
    // Create message element
    var msgDiv = document.createElement('div');
    msgDiv.textContent = message;
    msgDiv.style.cssText = 'position: fixed; top: 20px; left: 20px; padding: 12px 20px; border-radius: 6px; font-weight: bold; z-index: 9999; box-shadow: 0 4px 12px rgba(0,0,0,0.15); transition: all 0.3s ease; max-width: 350px;';

    // Set colors based on type
    if (type === 'success') {
        msgDiv.style.backgroundColor = '#d4edda';
        msgDiv.style.color = '#155724';
        msgDiv.style.border = '1px solid #c3e6cb';
    } else if (type === 'info') {
        msgDiv.style.backgroundColor = '#d1ecf1';
        msgDiv.style.color = '#0c5460';
        msgDiv.style.border = '1px solid #bee5eb';
    }

    // Add to page
    document.body.appendChild(msgDiv);

    // Animate in
    setTimeout(function () {
        msgDiv.style.transform = 'translateX(10px)';
    }, 100);

    // Remove after 3 seconds
    setTimeout(function () {
        msgDiv.style.opacity = '0';
        msgDiv.style.transform = 'translateY(-20px)';
        setTimeout(function () {
            if (msgDiv.parentNode) {
                msgDiv.parentNode.removeChild(msgDiv);
            }
        }, 300);
    }, 3000);
}

// Professional Training Selection Functionality
function handlePTTrainingSelection(dropdown, skipClear) {
    var selectedValue = dropdown.value;
    var pt1Fields = document.querySelectorAll('.pt1-field');
    var pt2Fields = document.querySelectorAll('.pt2-field');

    if (selectedValue === '0') {
        // Hide all PT fields and clear them only if not initialization
        pt1Fields.forEach(function (field) {
            field.classList.remove('show');
        });
        pt2Fields.forEach(function (field) {
            field.classList.remove('show');
        });

        // Clear fields only if user is actively switching (not initialization)
        if (!skipClear) {
            clearPTFields('all');
            showPTMessage('📚 All Professional Training fields are hidden. No training information will be recorded.', 'info');
        }

    } else if (selectedValue === '1') {
        // Show only PT1 fields, hide PT2 fields
        pt1Fields.forEach(function (field) {
            field.classList.add('show');
        });
        pt2Fields.forEach(function (field) {
            field.classList.remove('show');
        });

        // Clear only PT2 fields if user is actively switching
        if (!skipClear) {
            clearPTFields('pt2');
            showPTMessage('🎓 BEd/Spl BEd/BPEd/TPT/HPT/UPT details are visible. Additional training fields are hidden.', 'success');
        }

    } else if (selectedValue === '2') {
        // Show both PT1 and PT2 fields
        pt1Fields.forEach(function (field) {
            field.classList.add('show');
        });
        pt2Fields.forEach(function (field) {
            field.classList.add('show');
        });

        if (!skipClear) {
            showPTMessage('🏆 Both BEd/Spl BEd/BPEd/TPT/HPT/UPT details and Additional training fields are visible.', 'success');
        }
    }

    // Apply consistent field styling after state change
    if (typeof applyFieldStyling === 'function') {
        setTimeout(applyFieldStyling, 50);
    }
}

function handleDEDTrainingSelection(dropdown, skipClear) {
    var selectedValue = dropdown.value;
    var dedFields = document.querySelectorAll('.ded-field');

    if (selectedValue === '0') {
        dedFields.forEach(function (field) {
            field.classList.remove('show');
        });

        if (!skipClear) {
            showPTMessage('📚 DEd/TTC Professional Training fields are hidden. No training information will be recorded.', 'info');
        }
    } else if (selectedValue === '1') {
        dedFields.forEach(function (field) {
            field.classList.add('show');
        });

        if (!skipClear) {
            showPTMessage('🎓 DEd/TTC Professional Training details are visible.', 'success');
        }
    }

    if (typeof applyFieldStyling === 'function') {
        setTimeout(applyFieldStyling, 50);
    }
}

function clearPTFields(scope) {
    // DATA PRESERVATION: Don't clear fields automatically when toggling dropdowns
    // This preserves user data when switching between visibility states
    console.log('clearPTFields called with scope:', scope, '- Data preserved, fields not cleared');
}

function showPTMessage(message, type) {
    // Create message element
    var msgDiv = document.createElement('div');
    msgDiv.textContent = message;
    msgDiv.style.cssText = 'position: fixed; top: 80px; right: 20px; padding: 12px 20px; border-radius: 6px; font-weight: bold; z-index: 9999; box-shadow: 0 4px 12px rgba(0,0,0,0.15); transition: all 0.3s ease; max-width: 350px;';

    // Set colors based on type
    if (type === 'success') {
        msgDiv.style.backgroundColor = '#d4edda';
        msgDiv.style.color = '#155724';
        msgDiv.style.border = '1px solid #c3e6cb';
    } else if (type === 'info') {
        msgDiv.style.backgroundColor = '#d1ecf1';
        msgDiv.style.color = '#0c5460';
        msgDiv.style.border = '1px solid #bee5eb';
    }

    // Add to page
    document.body.appendChild(msgDiv);

    // Animate in
    setTimeout(function () {
        msgDiv.style.transform = 'translateX(-10px)';
    }, 100);

    // Remove after 3 seconds
    setTimeout(function () {
        msgDiv.style.opacity = '0';
        msgDiv.style.transform = 'translateY(-20px)';
        setTimeout(function () {
            if (msgDiv.parentNode) {
                msgDiv.parentNode.removeChild(msgDiv);
            }
        }, 300);
    }, 3000);
}

// Professional Training PG Selection Functionality
function handlePTPGTrainingSelection(dropdown, skipClear) {
    var selectedValue = dropdown.value;
    var ptpgFields = document.querySelectorAll('.ptpg-field');

    if (selectedValue === '0') {
        // Hide all PTPG fields and clear them only if not initialization
        ptpgFields.forEach(function (field) {
            field.classList.remove('show');
        });

        // Clear fields only if user is actively switching (not initialization)
        if (!skipClear) {
            clearPTPGFields();
            showPTPGMessage('📚 All Professional Training PG fields are hidden. No MEd/MPEd information will be recorded.', 'info');
        }

    } else if (selectedValue === '1') {
        // Show PTPG fields
        ptpgFields.forEach(function (field) {
            field.classList.add('show');
        });

        if (!skipClear) {
            showPTPGMessage('🎓 Professional Training PG (MEd/MPEd) fields are visible.', 'success');
        }
    }

    // Apply consistent field styling after state change
    if (typeof applyFieldStyling === 'function') {
        setTimeout(applyFieldStyling, 50);
    }
}

function clearPTPGFields() {
    // DATA PRESERVATION: Don't clear fields automatically when toggling dropdowns
    // This preserves user data when switching between visibility states
    console.log('clearPTPGFields called - Data preserved, fields not cleared');
}



function showPTPGMessage(message, type) {
    // Create message element
    var msgDiv = document.createElement('div');
    msgDiv.textContent = message;
    msgDiv.style.cssText = 'position: fixed; top: 140px; right: 20px; padding: 12px 20px; border-radius: 6px; font-weight: bold; z-index: 9999; box-shadow: 0 4px 12px rgba(0,0,0,0.15); transition: all 0.3s ease; max-width: 350px;';

    // Set colors based on type
    if (type === 'success') {
        msgDiv.style.backgroundColor = '#d4edda';
        msgDiv.style.color = '#155724';
        msgDiv.style.border = '1px solid #c3e6cb';
    } else if (type === 'info') {
        msgDiv.style.backgroundColor = '#d1ecf1';
        msgDiv.style.color = '#0c5460';
        msgDiv.style.border = '1px solid #bee5eb';
    }

    // Add to page
    document.body.appendChild(msgDiv);

    // Animate in
    setTimeout(function () {
        msgDiv.style.transform = 'translateX(-10px)';
    }, 100);

    // Remove after 3 seconds
    setTimeout(function () {
        msgDiv.style.opacity = '0';
        msgDiv.style.transform = 'translateY(-20px)';
        setTimeout(function () {
            if (msgDiv.parentNode) {
                msgDiv.parentNode.removeChild(msgDiv);
            }
        }, 300);
    }, 3000);
}

function handleTestDropdownChange(dropdown) {
    var fieldName = dropdown.name.toLowerCase();
    var selectedValue = dropdown.value;
    var dependentClass = '';
    var testName = '';

    // Map field names to their dependent classes
    if (fieldName === 'eotyn') {
        dependentClass = '.eot-dependent';
        testName = 'EOT';
    } else if (fieldName === 'gotyn') {
        dependentClass = '.got-dependent';
        testName = 'GOT';
    } else if (fieldName === 'lttyn') {
        dependentClass = '.ltt-dependent';
        testName = 'Telugu Language Test';
    } else if (fieldName === 'httyn') {
        dependentClass = '.htt-dependent';
        testName = 'Hindi Language Test';
    } else if (fieldName === 'othertestyn') {
        dependentClass = '.other-dependent';
        testName = 'Other Departmental Test';
    } else if (fieldName === 'tet1passed') {
        dependentClass = '.tet1-dependent';
        testName = 'TET Paper-1';
    } else if (fieldName === 'tet2passed') {
        dependentClass = '.tet2-dependent';
        testName = 'TET Paper-2';
    }

    var dependentFields = document.querySelectorAll(dependentClass);

    if (selectedValue === 'YES') {
        // Show dependent fields
        dependentFields.forEach(function (field) {
            field.classList.add('show');
        });
    } else if (selectedValue === 'NO' || selectedValue === '') {
        // Hide dependent fields
        dependentFields.forEach(function (field) {
            field.classList.remove('show');
        });
    }

    // Apply consistent field styling after state change
    if (typeof applyFieldStyling === 'function') {
        setTimeout(applyFieldStyling, 50);
    }
}

function showTestMessage(message, type) {
    // Create message element
    var msgDiv = document.createElement('div');
    msgDiv.textContent = message;
    msgDiv.style.cssText = 'position: fixed; top: 220px; right: 20px; padding: 12px 20px; border-radius: 6px; font-weight: bold; z-index: 9999; box-shadow: 0 4px 12px rgba(0,0,0,0.15); transition: all 0.3s ease; max-width: 350px;';

    // Set colors based on type
    if (type === 'success') {
        msgDiv.style.backgroundColor = '#d4edda';
        msgDiv.style.color = '#155724';
        msgDiv.style.border = '1px solid #c3e6cb';
    } else if (type === 'info') {
        msgDiv.style.backgroundColor = '#d1ecf1';
        msgDiv.style.color = '#0c5460';
        msgDiv.style.border = '1px solid #bee5eb';
    }

    // Add to page
    document.body.appendChild(msgDiv);

    // Animate in
    setTimeout(function () {
        msgDiv.style.transform = 'translateX(-10px)';
    }, 100);

    // Remove after 3 seconds
    setTimeout(function () {
        msgDiv.style.opacity = '0';
        msgDiv.style.transform = 'translateY(-20px)';
        setTimeout(function () {
            if (msgDiv.parentNode) {
                msgDiv.parentNode.removeChild(msgDiv);
            }
        }, 300);
    }, 3000);
}


/* ========================================================================
   Script Block 13
   ======================================================================== */

// SGT, SA, and GAZ Service Particulars Functions

function handleSGTRenderedSelection(dropdown) {
    var selectedValue = dropdown.value;
    var sgtSection = document.querySelector('.tp-section.sgt-section');
    // fields that come after sgtrendered and should be toggled as a group
    var sgtFollowing = document.querySelectorAll('.sgt-following');

    // Ensure the SGT section container remains visible so the sgtrendered controller is always shown
    if (sgtSection) sgtSection.style.display = 'block';

    if (selectedValue === 'YES') {
        // Show SGT dependent fields
        sgtFollowing.forEach(function (field) {
            field.classList.add('show');
        });
    } else {
        // Hide SGT dependent fields (but keep section header and controller visible)
        sgtFollowing.forEach(function (field) {
            field.classList.remove('show');
        });
    }

    // Handle conditional SGT fields based on SgtAppType
    handleSGTAppTypeSelection();

    // Also explicitly toggle specific SGT field wrappers requested by user
    var sgtFieldNames = [
        'SgtAppType', 'SgtDscYr', 'SgtCadreDesign', 'SgtDscHtno', 'SgtDscList', 'SgtDscRank', 'SgtDscMarks', 'SgtMgmnt', 'SgtJoinDate', 'SgtRegDate', 'SgtAbsrpDate'
    ];
    sgtFieldNames.forEach(function (n) {
        var el = document.querySelector('[name="' + n + '"]');
        if (!el) return;
        var wrapper = el.closest('.tp-col');
        if (!wrapper) return;
        if (selectedValue === 'YES') {
            wrapper.classList.add('show');
            wrapper.classList.remove('hide-manual');
        } else {
            wrapper.classList.remove('show');
            wrapper.classList.add('hide-manual');
        }
    });

    // Apply consistent field styling after state change
    if (typeof applyFieldStyling === 'function') {
        setTimeout(applyFieldStyling, 50);
    }
}

function handleSGTAppTypeSelection() {
    var sgtAppTypeField = document.querySelector('select[name="SgtAppType"]');
    var sgtRegFields = document.querySelectorAll('.sgt-reg-dependent');
    var sgtAbsorpFields = document.querySelectorAll('.sgt-absorp-dependent');
    var sgtDscFields = document.querySelectorAll('.sgt-dsc-dependent');

    if (!sgtAppTypeField) return;

    var selectedValue = sgtAppTypeField.value;

    // If COMPASSIONATE appointment, hide DSC related fields and registration/absorption dates
    var sgtRenderedDropdown = document.querySelector('select[name="sgtrendered"]');
    var sgtRenderedYes = sgtRenderedDropdown && sgtRenderedDropdown.value === 'YES';
    if (selectedValue === 'COMPASSIONATE') {
        // hide the DSC group
        sgtDscFields.forEach(function (field) { field.classList.remove('show'); field.classList.add('hide-manual'); });
        // also hide reg/absorp regardless
        sgtRegFields.forEach(function (field) { field.classList.remove('show'); field.classList.add('hide-manual'); });
        sgtAbsorpFields.forEach(function (field) { field.classList.remove('show'); field.classList.add('hide-manual'); });
        // remove required attribute from inputs within these groups
        sgtDscFields.forEach(function (field) { var inp = field.querySelector('input, select, textarea'); if (inp) inp.removeAttribute('required'); });
        sgtRegFields.forEach(function (field) { var inp = field.querySelector('input, select, textarea'); if (inp) inp.removeAttribute('required'); });
        sgtAbsorpFields.forEach(function (field) { var inp = field.querySelector('input, select, textarea'); if (inp) inp.removeAttribute('required'); });
        return;
    }
    // Show/hide SgtRegDate based on SgtAppType
    // Only UNTRAINED/ Spl VV should show registration date (and only when rendered=YES)
    if (selectedValue === 'UNTRAINED/ Spl VV') {
        sgtRegFields.forEach(function (field) {
            if (sgtRenderedYes) {
                field.classList.add('show');
                field.classList.remove('hide-manual');
                var inp = field.querySelector('input, select, textarea'); if (inp) inp.setAttribute('required', 'required');
            } else {
                field.classList.remove('show');
                field.classList.add('hide-manual');
                var inp = field.querySelector('input, select, textarea'); if (inp) inp.removeAttribute('required');
            }
        });
    } else {
        // keep registration fields hidden for all other appointment types unless future rules say otherwise
        sgtRegFields.forEach(function (field) {
            field.classList.remove('show');
            field.classList.add('hide-manual');
            var inp = field.querySelector('input, select, textarea'); if (inp) inp.removeAttribute('required');
        });
    }

    // Show/hide SgtAbsrpDate based on SgtAppType
    // Only Spl DSC (398) should show absorption date (and only when rendered=YES)
    if (selectedValue === 'Spl DSC (398)') {
        sgtAbsorpFields.forEach(function (field) {
            if (sgtRenderedYes) {
                field.classList.add('show');
                field.classList.remove('hide-manual');
                var inp = field.querySelector('input, select, textarea'); if (inp) inp.setAttribute('required', 'required');
            } else {
                field.classList.remove('show');
                field.classList.add('hide-manual');
                var inp = field.querySelector('input, select, textarea'); if (inp) inp.removeAttribute('required');
            }
        });
    } else {
        // keep absorption fields hidden for all other appointment types
        sgtAbsorpFields.forEach(function (field) {
            field.classList.remove('show');
            field.classList.add('hide-manual');
            var inp = field.querySelector('input, select, textarea'); if (inp) inp.removeAttribute('required');
        });
    }
    // For non-COMPASSIONATE appointments, show DSC fields only if sgtrendered=YES
    // For non-COMPASSIONATE appointments, show DSC fields only for specific appointment types and when sgtrendered=YES
    var allowedDscAppTypes = ['DSC/ TRT', 'Spl DSC (398)', 'UNTRAINED/ Spl VV', 'DSC (CONTRACTUAL)'];
    var sgtAppAllowsDsc = allowedDscAppTypes.indexOf(selectedValue) !== -1;
    if (sgtAppAllowsDsc && sgtRenderedYes) {
        sgtDscFields.forEach(function (field) {
            field.classList.add('show');
            field.classList.remove('hide-manual');
            // ensure inputs inside become editable
            var inp = field.querySelector('input, select, textarea'); if (inp) inp.removeAttribute('disabled');
        });
    } else {
        sgtDscFields.forEach(function (field) {
            field.classList.remove('show');
            field.classList.add('hide-manual');
            var inp = field.querySelector('input, select, textarea'); if (inp) inp.removeAttribute('required');
        });
    }

    // Apply consistent field styling after state change
    if (typeof applyFieldStyling === 'function') {
        setTimeout(applyFieldStyling, 50);
    }
}


function handleDesignationChange() {
    // support both lowercase and capitalised name attributes
    var designationField = document.querySelector('select[name="designation"], select[name="Designation"]');
    if (!designationField) return;

    var selectedDesignation = (designationField.value || '').trim().toUpperCase();
    var excludedDesignations = ['LP HINDI', 'LP TELUGU', 'LP URDU', 'PET', 'VOC', 'CI', 'DM', 'MUSIC', 'SGT', 'SGT UM', 'SGT SPL_EDN'];

    // Check if we're on Service tab before manipulating Section S and T
    var activeTab = document.querySelector('.tp-tab.tp-tab-active');
    var activeTabLabel = activeTab ? (activeTab.getAttribute('data-tab-label') || activeTab.textContent || '') : '';
    var isServiceTab = activeTabLabel.toLowerCase().replace(/[^a-z0-9]/g, '') === 'service';

    // Only manipulate SA/GAZ sections if we're on the Service tab
    if (!isServiceTab) return;

    // Handle SA Section visibility
    var saSection = document.querySelector('.tp-section.sa-section');
    var saFields = document.querySelectorAll('.sa-section:not(.tp-section)');

    if (excludedDesignations.includes(selectedDesignation)) {
        // Keep SA section container visible (so header stays shown) but hide its inner fields
        if (saSection) {
            saSection.style.display = 'block';
        }
        saFields.forEach(function (field) {
            field.classList.remove('show');
            field.classList.add('hide-manual');
        });
        // Also clear and hide entire Section T (GAZ) and Section S (SA) fields to avoid carrying over saved values
        // Clear inputs/selects/textareas inside these sections
        try {
            var sSectionEl = document.querySelector('.tp-section.sa-section');
            var tSectionEl = document.querySelector('.tp-section.gaz-section');
            [sSectionEl, tSectionEl].forEach(function (sec) {
                if (!sec) return;
                var controls = sec.querySelectorAll('input[name], select[name], textarea[name]');
                controls.forEach(function (c) {
                    if (c.tagName.toLowerCase() === 'select') { c.selectedIndex = 0; }
                    else if (c.type === 'checkbox' || c.type === 'radio') { c.checked = false; }
                    else { c.value = ''; }
                    // remove required and disabled to be safe
                    c.removeAttribute('required');
                    c.removeAttribute('disabled');
                });
                // Keep the section container visible (header) but hide/clear its inner controls
                sec.style.display = 'block';
                // hide column controls and any dependent wrappers inside the section
                var secFields = sec.querySelectorAll('.tp-col, :scope > div [class*="tp-col"], .sa-dsc-dependent, .sa-reg-dependent, .gaz-section');
                secFields.forEach(function (f) {
                    try { f.classList.remove('show'); } catch (e) { }
                    try { f.classList.add('hide-manual'); } catch (e) { }
                });
            });
        } catch (e) { console && console.warn && console.warn('clear SA/GAZ on designation failed', e); }
    } else {
        // Show SA section
        if (saSection) {
            saSection.style.display = 'block';
        }
        saFields.forEach(function (field) {
            field.classList.add('show');
        });
        handleSAAppTypeSelection();
    }

    // Handle GAZ Section visibility
    var gazSection = document.querySelector('.tp-section.gaz-section');
    var gazFields = document.querySelectorAll('.gaz-section:not(.tp-section)');

    if (selectedDesignation === 'GHM-GR.II') {
        // Show GAZ section content
        if (gazSection) {
            gazSection.style.display = 'block';
        }
        gazFields.forEach(function (field) {
            field.classList.add('show');
            field.classList.remove('hide-manual');
        });
    } else {
        // Keep GAZ section header visible but hide its inner fields
        if (gazSection) {
            gazSection.style.display = 'block';
        }
        gazFields.forEach(function (field) {
            field.classList.remove('show');
            field.classList.add('hide-manual');
        });
    }

    // Apply consistent field styling after state change
    if (typeof applyFieldStyling === 'function') {
        setTimeout(applyFieldStyling, 50);
    }
}

// IDT/MT mutual transfer toggles
function handleIdtMutualSelection(dropdown) {
    if (!dropdown) return;
    var val = dropdown.value;
    var cdr = document.querySelector('select[name="IdtMutualCdr"]');
    var dist = document.querySelector('input[name="IdtMutualDistFrom"], select[name="IdtMutualDistFrom"]');
    var date = document.querySelector('input[name="IdtMutualDate"]');

    if (val === 'NO' || val === '') {
        if (cdr) { cdr.closest('.tp-col') && cdr.closest('.tp-col').classList.remove('show'); cdr.closest('.tp-col') && cdr.closest('.tp-col').classList.add('hide-manual'); cdr.removeAttribute('required'); }
        if (dist) { dist.closest('.tp-col') && dist.closest('.tp-col').classList.remove('show'); dist.closest('.tp-col') && dist.closest('.tp-col').classList.add('hide-manual'); dist.removeAttribute('required'); }
        if (date) { date.closest('.tp-col') && date.closest('.tp-col').classList.remove('show'); date.closest('.tp-col') && date.closest('.tp-col').classList.add('hide-manual'); date.removeAttribute('required'); }
    } else {
        if (cdr) { cdr.closest('.tp-col') && cdr.closest('.tp-col').classList.add('show'); cdr.closest('.tp-col') && cdr.closest('.tp-col').classList.remove('hide-manual'); cdr.setAttribute('required', 'required'); }
        if (dist) { dist.closest('.tp-col') && dist.closest('.tp-col').classList.add('show'); dist.closest('.tp-col') && dist.closest('.tp-col').classList.remove('hide-manual'); dist.setAttribute('required', 'required'); }
        if (date) { date.closest('.tp-col') && date.closest('.tp-col').classList.add('show'); date.closest('.tp-col') && date.closest('.tp-col').classList.remove('hide-manual'); date.setAttribute('required', 'required'); }
    }

    // Apply consistent field styling after state change
    if (typeof applyFieldStyling === 'function') {
        setTimeout(applyFieldStyling, 50);
    }
}

// Compute Cadre Seniority fields in Section W and set them readonly & not required
function computeCadreSeniority() {
    try {
        // Helper to safely get field value
        function val(name) { var el = document.querySelector('[name="' + name + '"]'); return el ? (el.value || '').trim() : ''; }
        function setVal(name, v) {
            var el = document.querySelector('[name="' + name + '"]'); if (!el) return; try {
                // ensure W-section inputs are plain text and readonly (no date-picker)
                if (el.tagName.toLowerCase() === 'input') {
                    try { el.type = 'text'; } catch (e) { }
                }
                // display dates as dd-mm-yyyy when possible
                var out = v || '';
                if (out && /^(\d{4})-(\d{2})-(\d{2})$/.test(out)) {
                    var m = out.match(/^(\d{4})-(\d{2})-(\d{2})$/);
                    out = m[3] + '-' + m[2] + '-' + m[1];
                }
                el.value = out;
                el.setAttribute('readonly', 'readonly'); el.removeAttribute('required'); el.closest && el.closest('.tp-col') && el.closest('.tp-col').classList.add('readonly-field');
            } catch (e) { }
        }
        function clearVal(name) { setVal(name, ''); }

        var designation = val('designation') || val('Designation');
        var sgtApp = val('SgtAppType') || val('sgtapptype') || '';
        var saApp = val('SaAppType') || val('saapptype') || '';

        // date sources
        var sgtJoin = val('SgtJoinDate') || val('sgtjoindate') || val('sgtjoindate');
        var sgtReg = val('SgtRegDate') || val('sgtregdate');
        var sgtAbs = val('SgtAbsrpDate') || val('sgtabsrpdate');
        var saJoin = val('SaJoinDate') || val('sajoindate') || val('sajoindate');
        var saReg = val('SaRegDate') || val('saregdate');
        var ghmDoj = val('GHMGrIIDOJ') || val('ghmgriidoj');

        // target fields in W section
        var dofappName = 'dofapp';
        var dofdrName = 'dofdr';
        var dojpcName = 'dojpc';

        // Make W fields readonly and not required always
        ['dofapp', 'dofdr', 'dojpc', 'dofapp_date', 'dofdr_date', 'dojpc_date'].forEach(function (n) {
            var e = document.querySelector('[name="' + n + '"]');
            if (e) { try { e.setAttribute('readonly', 'readonly'); e.removeAttribute('required'); e.closest && e.closest('.tp-col') && e.closest('.tp-col').classList.add('readonly-field'); } catch (e) { } }
        });

        // Utility to check membership (case insensitive)
        function inList(name, list) { if (!name) return false; var n = name.toString().toUpperCase().trim(); return list.some(function (it) { return it.toUpperCase().trim() === n; }); }

        var sgtDesignations = ['LP HINDI', 'LP TELUGU', 'LP URDU', 'PET', 'VOC', 'CI', 'DM', 'MUSIC', 'SGT', 'SGT SPL_EDN'];
        var saDesignations = ['SA BIO SCI', 'SA ENGLISH', 'SA HINDI', 'SA MATHS', 'SA PD', 'SA PHY SCI', 'SA SOCIAL', 'SA SPL_EDN', 'SA TELUGU', 'SA URDU', 'PSHM'];

        // normalize values for checks
        var isSgtDesign = inList(designation, sgtDesignations);
        var isSaDesign = inList(designation, saDesignations);
        var isGhm = (designation && designation.toUpperCase().replace(/\s+/g, '') === 'GHMGR.II'.replace(/\s+/g, '')) || (designation && designation.toUpperCase().indexOf('GHM') !== -1 && designation.toUpperCase().indexOf('GR') !== -1);

        // Default clear
        var computedDofapp = '';
        var computedDofdr = '';
        var computedDojpc = '';

        // CASES 1-3: SGT-like designations
        if (isSgtDesign) {
            computedDofdr = 'NULL';
            var sgtAppUpper = (sgtApp || '').toUpperCase();
            if (sgtAppUpper === 'DSC/ TRT' || sgtAppUpper === 'COMPASSIONATE' || sgtAppUpper.indexOf('DSC') !== -1 && sgtAppUpper.indexOf('398') !== -1) {
                // CASE 1 (and partly 3 condition for DSC variants uses Absorp)
                computedDofapp = sgtJoin || '';
                computedDojpc = sgtJoin || '';
                // If app type signals absorption, choose abs date when applicable
                if (sgtAppUpper.indexOf('CONTRACTUAL') !== -1 || sgtAppUpper.indexOf('398') !== -1) {
                    computedDojpc = sgtAbs || computedDojpc;
                }
            } else if (sgtAppUpper === 'UNTRAINED/ SPL VV') {
                // CASE 2
                computedDofapp = sgtJoin || '';
                computedDojpc = sgtReg || '';
            } else if (sgtAppUpper.indexOf('DSC') !== -1 || sgtAppUpper.indexOf('CONTRACTUAL') !== -1) {
                // fallback: treat as CASE 3
                computedDofapp = sgtJoin || '';
                computedDojpc = sgtAbs || '';
            } else {
                // default fallback
                computedDofapp = sgtJoin || '';
                computedDojpc = sgtJoin || '';
            }
        }

        // CASES 4-6: SA designations
        if (isSaDesign) {
            var saAppUpper = (saApp || '').toUpperCase();
            if (saAppUpper === 'DSC/ TRT' || saAppUpper === 'COMPASSIONATE') {
                // CASE 4
                computedDofdr = 'NULL';
                computedDofapp = saJoin || '';
                computedDojpc = saJoin || '';
            } else if (saAppUpper === 'UNTRAINED/ SPL VV') {
                // CASE 5
                computedDofdr = 'NULL';
                computedDofapp = saJoin || '';
                computedDojpc = saReg || '';
            } else if (saAppUpper === 'PROMOTION') {
                // CASE 6
                computedDofapp = sgtJoin || '';
                computedDojpc = saJoin || '';
                // dofdr depends on SgtAppType
                var sgtAppUpper2 = (sgtApp || '').toUpperCase();
                if (sgtAppUpper2 === 'DSC/ TRT' || sgtAppUpper2 === 'COMPASSIONATE') {
                    computedDofdr = sgtJoin || '';
                } else if (sgtAppUpper2 === 'UNTRAINED/ SPL VV') {
                    computedDofdr = sgtReg || '';
                } else if (sgtAppUpper2.indexOf('DSC') !== -1 || sgtAppUpper2.indexOf('CONTRACTUAL') !== -1) {
                    computedDofdr = sgtAbs || '';
                }
            }
        }

        // CASES 7-9: GHM Gr.II
        if (isGhm) {
            computedDojpc = ghmDoj || computedDojpc || '';
            var saAppUpper = (saApp || '').toUpperCase();
            if (saAppUpper === 'DSC/ TRT' || saAppUpper === 'COMPASSIONATE') {
                computedDofdr = saJoin || '';
                computedDofapp = saJoin || '';
            } else if (saAppUpper === 'UNTRAINED/ SPL VV') {
                computedDofdr = saReg || '';
                computedDofapp = saJoin || '';
            } else if (saAppUpper === 'PROMOTION') {
                computedDofdr = saJoin || '';
                computedDofapp = sgtJoin || '';
            }
        }

        // Final set values
        // treat explicit 'NULL' string as textual 'NULL'
        if (computedDofdr === 'NULL') setVal(dofdrName, 'NULL'); else setVal(dofdrName, computedDofdr || '');
        setVal(dofappName, computedDofapp || '');
        setVal(dojpcName, computedDojpc || '');

    } catch (e) { console && console.warn && console.warn('computeCadreSeniority error', e); }
}

// Bind computeCadreSeniority to changes in relevant fields
document.addEventListener('DOMContentLoaded', function () {
    try {
        var watch = ['designation', 'Designation', 'SgtAppType', 'sgtapptype', 'SaAppType', 'saapptype', 'SgtJoinDate', 'SgtRegDate', 'SgtAbsrpDate', 'SaJoinDate', 'SaRegDate', 'GHMGrIIDOJ'];
        watch.forEach(function (n) {
            var el = document.querySelector('[name="' + n + '"]');
            if (el) { el.addEventListener('change', function () { try { computeCadreSeniority(); } catch (e) { } }); }
        });
    } catch (e) { console && console.warn && console.warn('computeCadreSeniority binder failed', e); }
});

// Ensure conditional handlers run once on load to set initial visibility (fixes fields visible on pageload)
document.addEventListener('DOMContentLoaded', function () {
    try {
        if (typeof computeCadreSeniority === 'function') {
            try { computeCadreSeniority(); } catch (e) { }
        }
        try { if (typeof handleSGTAppTypeSelection === 'function') handleSGTAppTypeSelection(); } catch (e) { }
        try { if (typeof handleSAAppTypeSelection === 'function') handleSAAppTypeSelection(); } catch (e) { }
        try { if (typeof handleDesignationChange === 'function') handleDesignationChange(); } catch (e) { }
        // rerun shortly after to account for any other script mutations
        setTimeout(function () { try { if (typeof computeCadreSeniority === 'function') computeCadreSeniority(); } catch (e) { }; try { if (typeof handleSGTAppTypeSelection === 'function') handleSGTAppTypeSelection(); } catch (e) { } }, 450);
    } catch (e) { console && console.warn && console.warn('initial conditional handlers failed', e); }
});

function handleSAAppTypeSelection() {
    var saAppTypeField = document.querySelector('select[name="SaAppType"]');
    var saDscFields = document.querySelectorAll('.sa-dsc-dependent');
    var saRegFields = document.querySelectorAll('.sa-reg-dependent');

    if (!saAppTypeField) return;

    var selectedValue = saAppTypeField.value;

    // Hide DSC fields if PROMOTION, show otherwise
    if (selectedValue === 'PROMOTION') {
        saDscFields.forEach(function (field) {
            field.classList.remove('show');
        });
        // additionally hide specific SaDscHtno input if present
        var saDscHtno = document.querySelector('.tp-col.sa-dsc-dependent input[name="SaDscHtno"], .tp-col.sa-dsc-dependent input[name="SaDscHtno"]');
        if (saDscHtno) saDscHtno.closest('.tp-col').classList.remove('show');
    } else {
        saDscFields.forEach(function (field) {
            field.classList.add('show');
        });
        var saDscHtno = document.querySelector('.tp-col.sa-dsc-dependent input[name="SaDscHtno"], .tp-col.sa-dsc-dependent input[name="SaDscHtno"]');
        if (saDscHtno) saDscHtno.closest('.tp-col').classList.add('show');
    }

    // Show SaRegDate only for UNTRAINED/ Spl VV
    if (selectedValue === 'UNTRAINED/ Spl VV') {
        saRegFields.forEach(function (field) {
            field.classList.add('show');
        });
    } else {
        saRegFields.forEach(function (field) {
            field.classList.remove('show');
        });
    }

    // Apply consistent field styling after state change
    if (typeof applyFieldStyling === 'function') {
        setTimeout(applyFieldStyling, 50);
    }
}

function showServiceMessage(message, type) {
    // Create message element
    var msgDiv = document.createElement('div');
    msgDiv.textContent = message;
    msgDiv.style.cssText = 'position: fixed; top: 20px; right: 20px; padding: 12px 20px; border-radius: 6px; font-weight: bold; z-index: 9999; box-shadow: 0 4px 12px rgba(0,0,0,0.15); transition: all 0.3s ease; max-width: 350px;';

    // Set colors based on type
    if (type === 'success') {
        msgDiv.style.backgroundColor = '#d4edda';
        msgDiv.style.color = '#155724';
        msgDiv.style.border = '1px solid #c3e6cb';
    } else if (type === 'info') {
        msgDiv.style.backgroundColor = '#d1ecf1';
        msgDiv.style.color = '#0c5460';
        msgDiv.style.border = '1px solid #bee5eb';
    }

    // Add to page
    document.body.appendChild(msgDiv);

    // Animate in
    setTimeout(function () {
        msgDiv.style.transform = 'translateX(-10px)';
    }, 100);

    // Remove after 3 seconds
    setTimeout(function () {
        msgDiv.style.opacity = '0';
        msgDiv.style.transform = 'translateY(-20px)';
        setTimeout(function () {
            if (msgDiv.parentNode) {
                msgDiv.parentNode.removeChild(msgDiv);
            }
        }, 300);
    }, 3000);
}


/* ========================================================================
   Script Block 14
   ======================================================================== */

// Address Copy Functionality
function handleAddressCopy(radioInput) {
    if (radioInput.value === 'yes' && radioInput.checked) {
        copyResidentialToNative();
    } else if (radioInput.value === 'no' && radioInput.checked) {
        clearNativeAddressFields();
    }
}

function copyResidentialToNative() {
    // Mapping of residential to native address fields
    var addressMappings = [
        { residential: 'ResHno', native: 'NatHno' },
        { residential: 'ResStreet', native: 'NatStreet' },
        { residential: 'ResMandal', native: 'NatMandal' },
        { residential: 'ResDist', native: 'NatDist' }
    ];

    addressMappings.forEach(function (mapping) {
        var resField = document.querySelector('input[name="' + mapping.residential + '"], select[name="' + mapping.residential + '"], textarea[name="' + mapping.residential + '"]');
        var natField = document.querySelector('input[name="' + mapping.native + '"], select[name="' + mapping.native + '"], textarea[name="' + mapping.native + '"]');

        if (resField && natField) {
            natField.value = resField.value;

            // Add visual feedback (use lighter blue instead of green)
            natField.style.backgroundColor = '#eaf6ff';
            natField.style.border = '2px solid #2196f3';

            // Remove feedback after a moment
            setTimeout(function () {
                natField.style.backgroundColor = '';
                natField.style.border = '';
            }, 2000);
        }
    });

    // Show success message
    showCopyMessage('✅ Residential address copied to Native address fields!', 'success');
}

function clearNativeAddressFields() {
    var nativeFields = ['NatHno', 'NatStreet', 'NatMandal', 'NatDist'];

    nativeFields.forEach(function (fieldName) {
        var field = document.querySelector('input[name="' + fieldName + '"], select[name="' + fieldName + '"], textarea[name="' + fieldName + '"]');
        if (field) {
            field.value = '';

            // Add visual feedback
            field.style.backgroundColor = '#fff3cd';
            field.style.border = '2px solid #ffc107';

            // Remove feedback after a moment
            setTimeout(function () {
                field.style.backgroundColor = '';
                field.style.border = '';
            }, 2000);
        }
    });

    // Show info message
    showCopyMessage('ℹ️ Native address fields cleared for manual entry.', 'info');
}

function showCopyMessage(message, type) {
    // Create message element
    var msgDiv = document.createElement('div');
    msgDiv.textContent = message;
    msgDiv.style.cssText = 'position: fixed; top: 20px; right: 20px; padding: 12px 20px; border-radius: 6px; font-weight: bold; z-index: 9999; box-shadow: 0 4px 12px rgba(0,0,0,0.15); transition: all 0.3s ease;';

    // Set colors based on type
    if (type === 'success') {
        msgDiv.style.backgroundColor = '#d4edda';
        msgDiv.style.color = '#155724';
        msgDiv.style.border = '1px solid #c3e6cb';
    } else if (type === 'info') {
        msgDiv.style.backgroundColor = '#d1ecf1';
        msgDiv.style.color = '#0c5460';
        msgDiv.style.border = '1px solid #bee5eb';
    }

    // Add to page
    document.body.appendChild(msgDiv);

    // Animate in
    setTimeout(function () {
        msgDiv.style.transform = 'translateX(-10px)';
    }, 100);

    // Remove after 3 seconds
    setTimeout(function () {
        msgDiv.style.opacity = '0';
        msgDiv.style.transform = 'translateX(20px)';
        setTimeout(function () {
            if (msgDiv.parentNode) {
                msgDiv.parentNode.removeChild(msgDiv);
            }
        }, 300);
    }, 3000);
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function () {
    // Delay initialization to ensure form fields are populated with server data
    setTimeout(function () {
        // Initialize degree field visibility based on saved dropdown values
        var degreeDropdown = document.querySelector('select[name="degrees_acquired"]');
        if (degreeDropdown) {
            // Dropdown value is already set from database, just trigger visibility handler
            handleDegreeSelection(degreeDropdown, true); // Skip clearing during initialization
        }

        // Initialize PG degree field visibility based on saved dropdown values
        var pgDegreeDropdown = document.querySelector('select[name="pg_degrees_acquired"]');
        if (pgDegreeDropdown) {
            // Dropdown value is already set from database, just trigger visibility handler
            handlePGDegreeSelection(pgDegreeDropdown, true); // Skip clearing during initialization
        }

        // Initialize PT training field visibility based on saved dropdown values
        var ptDropdown = document.querySelector('select[name="pt_trainings_acquired"]');
        if (ptDropdown) {
            // Dropdown value is already set from database, just trigger visibility handler
            handlePTTrainingSelection(ptDropdown, true); // Skip clearing during initialization
        }

        // Initialize PT PG training field visibility based on saved dropdown values
        var ptpgDropdown = document.querySelector('select[name="pt_pg_trainings_acquired"]');
        if (ptpgDropdown) {
            // Dropdown value is already set from database, just trigger visibility handler
            handlePTPGTrainingSelection(ptpgDropdown, true); // Skip clearing during initialization
        }

        // Initialize DEd/TTC training field visibility based on saved dropdown values
        var dedDropdown = document.querySelector('select[name="ded_trainings_acquired"]');
        if (dedDropdown) {
            handleDEDTrainingSelection(dedDropdown, true); // Skip clearing during initialization
        }

        // Initialize test dropdown field visibility based on saved values
        var testDropdowns = ['EotYN', 'GotYN', 'LttYN', 'HttYN', 'OtherTestYN', 'tet1passed', 'tet2passed'];
        testDropdowns.forEach(function (fieldName) {
            var dropdown = document.querySelector('select[name="' + fieldName + '"]');
            if (dropdown && dropdown.value) {
                handleTestDropdownChange(dropdown);
            }
        });

        // Service sections will be handled by tab switching and conditional logic

        // Initialize SGT service particulars based on saved values
        if (sgtRenderedDropdown) {
            handleSGTRenderedSelection(sgtRenderedDropdown);
        }

        // Initialize designation-based section visibility
        var designationDropdown = document.querySelector('select[name="designation"]');
        if (designationDropdown) {
            handleDesignationChange();
        }

        // Add event listeners for new dropdowns
        if (sgtRenderedDropdown) {
            sgtRenderedDropdown.addEventListener('change', function () {
                handleSGTRenderedSelection(this);
            });
        }

        if (designationDropdown) {
            designationDropdown.addEventListener('change', function () {
                handleDesignationChange();
            });
        }

        var sgtAppTypeDropdown = document.querySelector('select[name="SgtAppType"]');
        if (sgtAppTypeDropdown) {
            sgtAppTypeDropdown.addEventListener('change', function () {
                handleSGTAppTypeSelection();
            });
        }

        var saAppTypeDropdown = document.querySelector('select[name="SaAppType"]');
        if (saAppTypeDropdown) {
            saAppTypeDropdown.addEventListener('change', function () {
                handleSAAppTypeSelection();
            });
        }

        // IDT/MT mutual transfer handler
        var idtMutualDropdown = document.querySelector('select[name="IdtMutualYN"]');
        if (idtMutualDropdown) {
            idtMutualDropdown.addEventListener('change', function () { handleIdtMutualSelection(this); });
            // initialize visibility based on saved value
            handleIdtMutualSelection(idtMutualDropdown);
        }

    }, 500); // Wait 500ms for form to be fully populated

    // Extra enforcement pass after a short delay to guard against race conditions
    setTimeout(function () {
        try {
            var sgtRenderedDropdown = document.querySelector('select[name="sgtrendered"]');
            if (sgtRenderedDropdown) handleSGTRenderedSelection(sgtRenderedDropdown);
            var sgtAppTypeDropdown = document.querySelector('select[name="SgtAppType"]');
            if (sgtAppTypeDropdown) handleSGTAppTypeSelection();
        } catch (e) {
            console && console.warn && console.warn('SGT enforcement pass failed', e);
        }
    }, 1200);

    // Listen for changes to residential address fields to auto-update native if "Yes" is selected
    var residentialFields = ['ResHno', 'ResStreet', 'ResMandal', 'ResDist'];

    residentialFields.forEach(function (fieldName) {
        var field = document.querySelector('input[name="' + fieldName + '"], select[name="' + fieldName + '"], textarea[name="' + fieldName + '"]');
        if (field) {
            field.addEventListener('input', function () {
                var yesRadio = document.querySelector('input[name="native_same_as_residential"][value="yes"]');
                if (yesRadio && yesRadio.checked) {
                    // Auto-copy when residential address changes if "Yes" is selected
                    setTimeout(copyResidentialToNative, 100);
                }
            });
        }
    });

    // Apply consistent background color styling to ALL sections (A through W)
    // This ensures active fields get light green and disabled fields get grey consistently
    function applyFieldStyling() {
        // Select all input, select, and textarea elements with edit-input class across ALL sections
        var allFields = document.querySelectorAll('input.edit-input, select.edit-input, textarea.edit-input');

        allFields.forEach(function (field) {
            if (field.disabled || field.readOnly) {
                // Disabled or readonly: grey background
                field.style.backgroundColor = '#f5f5f5';
            } else {
                // Active field (whether required or not): light blue background
                field.style.backgroundColor = '#eaf6ff';
            }
        });
    }

    // Call the styling function after all handlers have run
    setTimeout(applyFieldStyling, 600);
    setTimeout(applyFieldStyling, 1300); // Second pass to catch any delayed updates

    // Also apply styling whenever any dropdown changes that might affect field states
    var allDropdowns = document.querySelectorAll('select.edit-input');
    allDropdowns.forEach(function (dropdown) {
        dropdown.addEventListener('change', function () {
            setTimeout(applyFieldStyling, 100);
        });
    });

    // Re-apply styling when tabs change (sections might toggle visibility)
    var allTabs = document.querySelectorAll('.tp-tab');
    allTabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            setTimeout(applyFieldStyling, 200);
        });
    });
});

console.log('TP: teacher_edit_main.js finished loading!');


/* ========================================================================
   Script Block 15 (New)
   ======================================================================== */

// School Cascading Logic for Service Section
(function () {
    function initSchoolCascade() {
        // Elements
        const divisionSelect = document.querySelector('select[name="division"]');
        const mandalSelect = document.querySelector('select[name="SchMandal"]');
        const schoolNameSelect = document.querySelector('select[name="SchName"]');

        // Output fields
        const schCodeSelect = document.querySelector('select[name="SchCode"]') || document.querySelector('input[name="SchCode"]');
        const catInput = document.querySelector('input[name="category_ofthe_school"]') || document.querySelector('input[name="category"]');
        const mgtInput = document.querySelector('input[name="management"]');
        const medInput = document.querySelector('input[name="medium_ofthe_school"]') || document.querySelector('input[name="medium"]');
        const hraInput = document.querySelector('input[name="hra"]');

        if (!divisionSelect || !mandalSelect || !schoolNameSelect) return;

        function populateSelectWithCurrent(selectEl, items, currentValue, placeholder) {
            placeholder = placeholder || '-- Select --';
            if (!Array.isArray(items)) items = [];
            if (currentValue && currentValue !== '' && items.indexOf(currentValue) === -1) {
                items = [currentValue].concat(items);
            }
            let html = `<option value="">${placeholder}</option>`;
            items.forEach(function(item) {
                const safeItem = item.toString().replace(/"/g, '&quot;');
                const sel = (item === currentValue) ? ' selected' : '';
                html += `<option value="${safeItem}"${sel}>${item}</option>`;
            });
            selectEl.innerHTML = html;
        }

        function sortOptions(items) {
            if (!Array.isArray(items)) return [];
            return items.slice().sort(function(a, b) {
                const aStr = a.toString();
                const bStr = b.toString();
                return aStr.localeCompare(bStr, undefined, { numeric: true, sensitivity: 'base' });
            });
        }

        // Check if we are allowed to edit service fields
        const canEdit = (typeof window.__CAN_EDIT_SERVICE !== 'undefined') ? window.__CAN_EDIT_SERVICE : true;
        const originalDisabledState = {
            division: divisionSelect.disabled,
            mandal: mandalSelect.disabled,
            schoolName: schoolNameSelect.disabled,
            schoolCode: schCodeSelect ? schCodeSelect.disabled : false
        };

        // 1. Fetch Mandals based on Division
        function fetchMandals(divValue, selectedMandal, selectedSname, selectedScode) {
            if (!divValue) {
                mandalSelect.innerHTML = '<option value="">-- Select --</option>';
                schoolNameSelect.innerHTML = '<option value="">-- Select --</option>';
                return;
            }

            // Disable while loading
            mandalSelect.disabled = true;
            if (canEdit) mandalSelect.innerHTML = '<option value="">Loading...</option>';

            fetch('ajax_get_school_cascade.php?division=' + encodeURIComponent(divValue))
                .then(r => r.json())
                .then(data => {
                    mandalSelect.disabled = originalDisabledState.mandal;
                    const mandals = Array.isArray(data.mandals) ? data.mandals : [];
                    populateSelectWithCurrent(mandalSelect, mandals, selectedMandal, '-- Select --');

                    // If we have a selected mandal (either from saved data or user choice), 
                    // trigger school fetch
                    if (selectedMandal && mandalSelect.value === selectedMandal) {
                        const curSname = schoolNameSelect.getAttribute('data-current') || '';
                        const curScode = schCodeSelect ? (schCodeSelect.getAttribute('data-current') || '') : '';
                        fetchSchools(divValue, selectedMandal, curSname, curScode);
                        // Clear data-current after use to avoid re-selecting old value on manual changes
                        schoolNameSelect.removeAttribute('data-current');
                        if (schCodeSelect && schCodeSelect.tagName === 'SELECT') {
                            schCodeSelect.removeAttribute('data-current');
                        }
                    } else {
                        // Reset downstream
                        schoolNameSelect.innerHTML = '<option value="">-- Select --</option>';
                        if (schCodeSelect && schCodeSelect.tagName === 'SELECT') {
                            schCodeSelect.innerHTML = '<option value="">-- Select --</option>';
                        }
                    }
                })
                .catch(e => {
                    mandalSelect.disabled = originalDisabledState.mandal;
                    mandalSelect.innerHTML = '<option value="">Error</option>';
                    console.error('Error fetching mandals: ' + e.message);
                });
        }

        // 2. Fetch Schools based on Division + Mandal
        function fetchSchools(divValue, mandalValue, selectedSname, selectedScode) {
            if (!divValue || !mandalValue) {
                schoolNameSelect.innerHTML = '<option value="">-- Select --</option>';
                if (schCodeSelect && schCodeSelect.tagName === 'SELECT') {
                    schCodeSelect.innerHTML = '<option value="">-- Select --</option>';
                }
                return;
            }

            schoolNameSelect.disabled = true;
            if (canEdit) schoolNameSelect.innerHTML = '<option value="">Loading...</option>';

            fetch(`ajax_get_school_cascade.php?division=${encodeURIComponent(divValue)}&mandal=${encodeURIComponent(mandalValue)}`)
                .then(r => r.json())
                .then(data => {
                    schoolNameSelect.disabled = originalDisabledState.schoolName;
                    const snames = Array.isArray(data.snames) ? data.snames : [];
                    populateSelectWithCurrent(schoolNameSelect, snames, selectedSname, '-- Select --');

                    if (schCodeSelect && schCodeSelect.tagName === 'SELECT') {
                        const scodes = sortOptions(Array.isArray(data.scodes) ? data.scodes : []);
                        populateSelectWithCurrent(schCodeSelect, scodes, selectedScode, '-- Select --');
                    }

                    // IF we preserved a selection, trigger details lookup
                    if (selectedSname && schoolNameSelect.value === selectedSname) {
                        fetchSchoolDetails(divValue, mandalValue, selectedSname);
                    } else if (selectedScode && schCodeSelect && schCodeSelect.tagName === 'SELECT' && schCodeSelect.value === selectedScode) {
                        // if only code selected, use lookup to re-populate fields
                        fetchSchoolDetails(divValue, mandalValue, schoolNameSelect.value || selectedSname);
                    }
                })
                .catch(e => {
                    schoolNameSelect.disabled = originalDisabledState.schoolName;
                    schoolNameSelect.innerHTML = '<option value="">Error</option>';
                    console.error('Error fetching schools: ' + e.message);
                });
        }

        // 3. Fetch School Details (Scode, etc)
        function fetchSchoolDetails(divValue, mandalValue, snameValue) {
            if (!divValue || !mandalValue || !snameValue) {
                clearDetails();
                return;
            }

            // Get Scode first
            fetch(`ajax_get_school_cascade.php?division=${encodeURIComponent(divValue)}&mandal=${encodeURIComponent(mandalValue)}&sname=${encodeURIComponent(snameValue)}`)
                .then(r => r.json())
                .then(data => {
                    const scodes = sortOptions(Array.isArray(data.scodes) ? data.scodes : []);
                    const scode = (scodes && scodes.length > 0) ? scodes[0] : '';
                    if (schCodeSelect) {
                        if (schCodeSelect.tagName === 'SELECT') {
                            if (scode) {
                                const hasOption = Array.from(schCodeSelect.options).some(function(opt){ return opt.value === scode; });
                                if (!hasOption) {
                                    schCodeSelect.innerHTML += `<option value="${scode}">${scode}</option>`;
                                }
                            }
                            schCodeSelect.value = scode ? scode : '';
                        } else {
                            schCodeSelect.value = scode ? scode : '';
                        }
                        schCodeSelect.style.backgroundColor = '#fff3cd';
                        setTimeout(() => schCodeSelect.style.backgroundColor = '', 500);
                    }

                    if (scode) {
                        // Get details by Scode
                        fetch(`ajax_get_school_cascade.php?scode=${encodeURIComponent(scode)}`)
                            .then(r2 => r2.json())
                            .then(data2 => {
                                if (data2.lookup) {
                                    const l = data2.lookup;
                                    if (catInput) catInput.value = l.ps_ups || '';
                                    if (mgtInput) mgtInput.value = l.mgt || '';
                                    if (medInput) medInput.value = l.medium_sch || '';
                                    if (hraInput) hraInput.value = l.category || '';
                                }
                            });
                    } else {
                        clearDetails();
                    }
                })
                .catch(e => console.error('Error fetching details: ' + e.message));
        }

        function clearDetails() {
            if (schCodeSelect) {
                if (schCodeSelect.tagName === 'SELECT') {
                    schCodeSelect.value = '';
                } else {
                    schCodeSelect.value = '';
                }
            }
            if (catInput) catInput.value = '';
            if (mgtInput) mgtInput.value = '';
            if (medInput) medInput.value = '';
        }

        // Event Listeners
        divisionSelect.addEventListener('change', function () {
            // When division changes, clear mandal and school
            mandalSelect.value = '';
            schoolNameSelect.value = '';
            clearDetails();
            fetchMandals(this.value, '');
        });

        mandalSelect.addEventListener('change', function () {
            // When mandal changes, clear school
            schoolNameSelect.value = '';
            clearDetails();
            fetchSchools(divisionSelect.value, this.value, '');
        });

        schoolNameSelect.addEventListener('change', function () {
            fetchSchoolDetails(divisionSelect.value, mandalSelect.value, this.value);
        });

        if (schCodeSelect) {
            schCodeSelect.addEventListener('change', function () {
                const selectedScode = this.value;
                if (!selectedScode) {
                    clearDetails();
                    return;
                }
                fetch(`ajax_get_school_cascade.php?scode=${encodeURIComponent(selectedScode)}`)
                    .then(r => r.json())
                    .then(data => {
                        if (data.lookup) {
                            const l = data.lookup;
                            if (l.sname) {
                                const hasOption = Array.from(schoolNameSelect.options).some(function (opt) { return opt.value === l.sname; });
                                if (!hasOption) {
                                    schoolNameSelect.innerHTML += `<option value="${l.sname}">${l.sname}</option>`;
                                }
                                schoolNameSelect.value = l.sname;
                            }
                            if (catInput) catInput.value = l.ps_ups || '';
                            if (mgtInput) mgtInput.value = l.mgt || '';
                            if (medInput) medInput.value = l.medium_sch || '';
                            if (hraInput) hraInput.value = l.category || '';
                        } else {
                            clearDetails();
                        }
                    })
                    .catch(e => console.error('Error fetching school lookup by code: ' + e.message));
            });
        }

        // Initialize from existing values (important for Edit pages)
        setTimeout(function () {
            const curDiv = divisionSelect.value; // Server rendered
            const curMandal = mandalSelect.getAttribute('data-current') || '';
            const curSname = schoolNameSelect.getAttribute('data-current') || '';
            const curScode = (schCodeSelect && schCodeSelect.tagName === 'SELECT') ? (schCodeSelect.getAttribute('data-current') || '') : '';

            // If we have a division, start the chain
            if (curDiv) {
                // Pass curMandal so fetchMandals can select it and trigger next step
                fetchMandals(curDiv, curMandal, curSname, curScode);
            }
        }, 800); // 800ms
    }

    // Run on load
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSchoolCascade);
    } else {
        initSchoolCascade();
    }
})();

// Mark that the dedicated teacher edit cascade is active so main.js can skip its duplicate logic.
window.__TEACHER_EDIT_MAIN_JS_LOADED = true;
