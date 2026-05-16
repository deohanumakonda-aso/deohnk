<?php $sidebarBasePath = (strpos($_SERVER['PHP_SELF'], '/admin/') !== false) ? '../' : ''; ?>
<aside class="left-sidebar">
    <h3>Quick Navigation</h3>
    <ul class="nav-menu">
        <li class="active">
            <a href="<?php echo $sidebarBasePath; ?>index.php">
                <span class="icon">🏠</span>
                <div class="text">
                    <div>Home</div>
                    <div class="desc">Main dashboard</div>
                </div>
            </a>
        </li>
        <li>
            <a href="<?php echo $sidebarBasePath; ?>about.php">
                <span class="icon">🏢</span>
                <div class="text">
                    <div>About Us</div>
                    <div class="desc">Office information</div>
                </div>
            </a>
        </li>
        <li>
            <a href="<?php echo $sidebarBasePath; ?>teachersearch.php">
                <span class="icon">👥</span>
                <div class="text">
                    <div>Teachers Portal</div>
                    <div class="desc">Teacher database</div>
                </div>
            </a>
        </li>
        <li>
            <a href="<?php echo $sidebarBasePath; ?>announcements.php">
                <span class="icon">📢</span>
                <div class="text">
                    <div>Announcements</div>
                    <div class="desc">Latest updates</div>
                </div>
            </a>
        </li>
        <li>
            <a href="<?php echo $sidebarBasePath; ?>blog.php">
                <span class="icon">📝</span>
                <div class="text">
                    <div>Blog</div>
                    <div class="desc">News & articles</div>
                </div>
            </a>
        </li>
        <li>
             <a href="<?php echo $sidebarBasePath; ?>ttc_landing.php">
                <span class="icon">📚</span>
                <div class="text">
                    <div>TTC 2026</div>
                    <div class="desc">Technical Teacher Certificate Portal</div>
                </div>
            </a>
        </li> 
       <!--   <li>
            <a href="<?php echo $sidebarBasePath; ?>ttc_facial_register.php">
                <span class="icon">📸</span>
                <div class="text">
                    <div>Facial Setup</div>
                    <div class="desc">TTC Biometric Setup</div>
                </div>
            </a>
        </li>   -->
        <?php if (!empty($_SESSION['admin_loggedin']) || in_array($_SESSION['user_type'] ?? '', ['ADMIN','DEO'])): ?>
        <li>
            <a href="<?php echo $sidebarBasePath; ?>ttc_attendance_register.php">
                <span class="icon">📋</span>
                <div class="text">
                    <div>Attendance Register</div>
                    <div class="desc">TTC day-wise register</div>
                </div>
            </a>
        </li>
        <?php endif; ?>

        <li>
            <a href="<?php echo $sidebarBasePath; ?>contact.php">
                <span class="icon">📞</span>
                <div class="text">
                    <div>Contact</div>
                    <div class="desc">Get in touch</div>
                </div>
            </a>
        </li>
        <li>
            <a href="<?php echo $sidebarBasePath; ?>downloads.php">
                <span class="icon">📥</span>
                <div class="text">
                    <div>Downloads</div>
                    <div class="desc">Forms & documents</div>
                </div>
            </a>
        </li>
        <li>
            <a href="<?php echo $sidebarBasePath; ?>results.php">
                <span class="icon">📊</span>
                <div class="text">
                    <div>Results</div>
                    <div class="desc">Exam results</div>
                </div>
            </a>
        </li>
        <li>
            <a href="<?php echo $sidebarBasePath; ?>verification.php">
                <span class="icon">✅</span>
                <div class="text">
                    <div>Verification</div>
                    <div class="desc">Document verification</div>
                </div>
            </a>
        </li>
    </ul>

    <div class="quick-stats">
        <h4>Quick Stats</h4>
        <div class="stat-item">
            <span class="stat-label">Total Schools</span>
            <span class="stat-value">1,247</span>
        </div>
        <div class="stat-item">
            <span class="stat-label">Teachers</span>
            <span class="stat-value">8,934</span>
        </div>
        <div class="stat-item">
            <span class="stat-label">Students</span>
            <span class="stat-value">2,45,678</span>
        </div>
    </div>
    <div class="designer-widget">
        <h5>Design and code by :</h5>
        <div class="designer-photo">
            <img src="<?php echo $sidebarBasePath; ?>assets/images/designer.JPG" alt="Designer Photograph"
                style="width: 80px; height: 96px; border-radius: 8px; object-fit: cover;">
        </div>
        <div class="designer-details">
            <p><strong>Putta Venkat Rajeshwar (PVR)</strong></p>
            <p>Asst. Statistical Officer (ASO)</p>
            <p>O/o the DEO Hanumakonda</p>
            <p>Hanumakonda district</p>
            <p>Email: asowglu@gmail.com</p>
            <p>Mobile: 9948083483</p>
            <p><img src="<?php echo $sidebarBasePath; ?>assets/images/PRAGNI.png" alt="PRAGNI.IN Logo"
                    style="width: 150px; height: auto; vertical-align: middle;"></p>
        </div>

</aside>