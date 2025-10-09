<?php
session_start();
require_once 'includes/db_connect.php';
include 'includes/header.php';
?>

<div class="container main-body">
    
    <?php include 'includes/left_sidebar.php'; ?>

    <main class="main-content">
        <h2>Contact Us</h2>
        <div class="contact-info">
            <h3>District Education Office, Hanumakonda</h3>
            <p>
                <strong>Address:</strong><br>
                Subedari, Hanumakonda,<br>
                Telangana, 506001,<br>
                India.
            </p>
            <p>
                <strong>Phone:</strong><br>
                (Your Office Phone Number Here)
            </p>
            <p>
                <strong>Email:</strong><br>
                <a href="mailto:deo_wgld@telangana.gov.in">deo_wgld@telangana.gov.in</a>
            </p>
        </div>

        <div class="map-container">
            <h3>Our Location</h3>
            <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3786.721257449557!2d79.544778!3d18.005086!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3a3345b1574a7231%3A0x651cbe588a4e3734!2sDistrict%20Educational%20Office!5e0!3m2!1sen!2sin!4v1664890000000!5m2!1sen!2sin" width="100%" height="450" style="border:0;" allowfullscreen="" loading="lazy"></iframe>
        </div>
    </main>

    <?php include 'includes/right_sidebar.php'; ?>
    
</div>

<?php include 'includes/footer.php'; ?>