<?php
session_start(); // Start the session to check for admin login
require_once 'includes/db_connect.php';
include 'includes/header.php';
?>

<div class="container main-body">
    
    <?php include 'includes/left_sidebar.php'; ?>

    <main class="main-content">
        <section class="welcome-section">
            <h1>Welcome to the District Education Office</h1>
            <div class="location"><h2>Hanumakonda District, Telangana</h2></div>
        </section>

        <!-- Image and Login Row -->
        <section class="image-login-row" style="margin-bottom:20px;">
            <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start;">
                <div style="flex:1 1 320px; max-width:640px;">
                    <img src="assets/images/deohnk.jpg" alt="DEO Hanumakonda" style="width:100%;height:auto;border-radius:8px;object-fit:cover;" />
                </div>
                <div style="flex:0 0 320px; min-width:260px;">
                    <?php include 'includes/login_widget.php'; ?>
                </div>
            </div>
        </section>
        <section class="badge-section">
            <div class="badge teacher">
                <a href="teachersearch.php">
                    <div class="badge-header">
                        <div class="icon">👥</div>
                        <h3>Teacher Portal</h3>
                    </div>
                    <p>Access teacher database, transfers, and training information</p>
                    <button>Access Portal</button>
                </a>
            </div>
            <div class="badge programs">
                 <a href="programs.php">
                    <div class="badge-header">
                        <div class="icon">🎓</div>
                        <h3>Educational Programs</h3>
                    </div>
                    <p>View ongoing programs and initiatives in the district</p>
                    <button>Access Portal</button>
                </a>
            </div>
            <div class="badge announcements">
                 <a href="announcements.php">
                    <div class="badge-header">
                        <div class="icon">📢</div>
                        <h3>Announcements</h3>
                    </div>
                    <p>Latest announcements and notifications from district office</p>
                    <button>Access Portal</button>
                </a>
            </div>
            <div class="badge contact">
                 <a href="contact.php">
                    <div class="badge-header">
                        <div class="icon">📞</div>
                        <h3>Contact Us</h3>
                    </div>
                    <p>Get in touch with district education office officials</p>
                    <button>Access Portal</button>
                </a>
            </div>
        </section>

        <section class="services-section">
            <h2>Our Services</h2>
            <div class="service-grid">
                <div class="service-item">
                    <h4>Teacher Management</h4>
                    <p>Complete teacher database, transfers, recruitment, and training management</p>
                </div>
                <div class="service-item">
                    <h4>Educational Programs</h4>
                    <p>Implementation and monitoring of various educational initiatives</p>
                </div>
                <div class="service-item">
                    <h4>Student Services</h4>
                    <p>Scholarships, examinations, and student welfare programs</p>
                </div>
                <div class="service-item">
                    <h4>Infrastructure</h4>
                    <p>School building construction and maintenance oversight</p>
                </div>
                <div class="service-item">
                    <h4>Policy Implementation</h4>
                    <p>Ensuring adherence to state and central education policies</p>
                </div>
                <div class="service-item">
                    <h4>Quality Assurance</h4>
                    <p>Regular inspections and quality improvement initiatives</p>
                </div>
            </div>
        </section>

    </main>

    <?php include 'includes/right_sidebar.php'; ?>
    
</div>

<?php include 'includes/footer.php'; ?>