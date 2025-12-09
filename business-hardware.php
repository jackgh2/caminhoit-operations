<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';

$page_title = $translations['business_hardware_page_title'];
?>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2.php'; ?>

<!-- Hero Section -->
<header class="hero">
    <div class="hero-gradient"></div>
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-12 text-center">
                <span class="hero-eyebrow">
                    <i class="bi bi-laptop-fill"></i>
                    <?= $translations['business_hardware_page_title']; ?>
                </span>
                <h1 class="hero-title">
                    <?= $translations['business_hardware_hero_title']; ?>
                </h1>
                <p class="hero-subtitle mx-auto">
                    <?= $translations['business_hardware_hero_subtitle']; ?>
                </p>
                <div class="hero-cta">
                    <a href="#hardware" class="btn c-btn-primary">
                        <i class="bi bi-arrow-down"></i>
                        <?= $translations['view_hardware']; ?>
                    </a>
                </div>
            </div>
        </div>
    </div>
</header>

<!-- Introduction Section -->
<section class="section section-soft fade-in">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-6">
                <div class="section-header">
                    <h2 class="section-title"><?= $translations['hardware_when_you_need_it']; ?></h2>
                </div>
                <p class="section-subtitle"><?= $translations['hardware_intro_desc']; ?></p>
                <ul class="why-list">
                    <li><i class="bi bi-check-circle-fill"></i> <?= $translations['business_grade_laptops']; ?></li>
                    <li><i class="bi bi-check-circle-fill"></i> <?= $translations['av_equipment_video_calls']; ?></li>
                    <li><i class="bi bi-check-circle-fill"></i> <?= $translations['voip_telephony_devices']; ?></li>
                    <li><i class="bi bi-check-circle-fill"></i> <?= $translations['delivery_installation_included']; ?></li>
                </ul>
            </div>
            <div class="col-lg-6">
                <img src="assets/logos/it-hardware-illustration.png" alt="<?= $translations['it_hardware_illustration_alt']; ?>" class="img-fluid rounded">
            </div>
        </div>
    </div>
</section>

<!-- Hardware Section -->
<section class="section fade-in" id="hardware">
    <div class="container">
        <div class="section-header text-center">
            <h2 class="section-title"><?= $translations['available_hardware']; ?></h2>
            <p class="section-subtitle mx-auto"><?= $translations['hardware_section_subtitle']; ?></p>
        </div>

        <div class="row g-4">
            <!-- Laptops & Desktops -->
            <div class="col-md-6">
                <div class="pill-card">
                    <div class="pill-icon">
                        <i class="bi bi-laptop"></i>
                    </div>
                    <h4 class="pill-title"><?= $translations['laptops_desktops']; ?></h4>
                    <p class="pill-text"><?= $translations['laptops_desktops_desc']; ?></p>
                </div>
            </div>

            <!-- Tablets & Mobile Devices -->
            <div class="col-md-6">
                <div class="pill-card">
                    <div class="pill-icon">
                        <i class="bi bi-tablet"></i>
                    </div>
                    <h4 class="pill-title"><?= $translations['tablets_mobile_devices']; ?></h4>
                    <p class="pill-text"><?= $translations['tablets_mobile_desc']; ?></p>
                </div>
            </div>

            <!-- Accessories & Peripherals -->
            <div class="col-md-6">
                <div class="pill-card">
                    <div class="pill-icon">
                        <i class="bi bi-printer"></i>
                    </div>
                    <h4 class="pill-title"><?= $translations['accessories_peripherals']; ?></h4>
                    <p class="pill-text"><?= $translations['accessories_peripherals_desc']; ?></p>
                </div>
            </div>

            <!-- Cloud Telephony -->
            <div class="col-md-6">
                <div class="pill-card">
                    <div class="pill-icon">
                        <i class="bi bi-telephone"></i>
                    </div>
                    <h4 class="pill-title"><?= $translations['cloud_telephony']; ?></h4>
                    <p class="pill-text"><?= $translations['cloud_telephony_desc']; ?></p>
                </div>
            </div>

            <!-- AV Equipment -->
            <div class="col-md-6">
                <div class="pill-card">
                    <div class="pill-icon">
                        <i class="bi bi-camera-video"></i>
                    </div>
                    <h4 class="pill-title"><?= $translations['av_equipment']; ?></h4>
                    <p class="pill-text"><?= $translations['av_equipment_desc']; ?></p>
                </div>
            </div>

            <!-- Servers & Networking -->
            <div class="col-md-6">
                <div class="pill-card">
                    <div class="pill-icon">
                        <i class="bi bi-hdd-stack"></i>
                    </div>
                    <h4 class="pill-title"><?= $translations['servers_networking']; ?></h4>
                    <p class="pill-text"><?= $translations['servers_networking_desc']; ?></p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Leasing Section -->
<section class="section section-soft fade-in">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-6 order-lg-2">
                <div class="section-header">
                    <h2 class="section-title"><?= $translations['hardware_leasing_finance']; ?></h2>
                </div>
                <p class="section-subtitle"><?= $translations['leasing_intro_desc']; ?></p>
                <ul class="why-list">
                    <li><i class="bi bi-arrow-repeat"></i> <?= $translations['swap_old_tech']; ?></li>
                    <li><i class="bi bi-briefcase"></i> <?= $translations['save_money_bundled']; ?></li>
                    <li><i class="bi bi-calendar"></i> <?= $translations['flexible_monthly_plans']; ?></li>
                    <li><i class="bi bi-graph-up-arrow"></i> <?= $translations['perfect_scaling_businesses']; ?></li>
                </ul>
            </div>
            <div class="col-lg-6 order-lg-1">
                <img src="assets/logos/hardware-leasing.png" alt="<?= $translations['hardware_leasing_alt']; ?>" class="img-fluid rounded">
            </div>
        </div>
    </div>
</section>

<!-- CTA Section -->
<section class="section fade-in">
    <div class="container text-center">
        <h4 class="section-title"><?= $translations['hardware_cta']; ?></h4>
        <a href="contact.php" class="btn c-btn-primary mt-3">
            <i class="bi bi-chat-dots"></i>
            <?= $translations['speak_to_our_team']; ?>
        </a>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };

    const observer = new IntersectionObserver(function(entries) {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('visible');
            }
        });
    }, observerOptions);

    document.querySelectorAll('.fade-in').forEach(el => {
        observer.observe(el);
    });

    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });
});
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-v2.php'; ?>
