<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';

$page_title = $translations['cloud_solutions_page_title'];
?>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2.php'; ?>

<!-- Hero Section -->
<header class="hero">
    <div class="hero-gradient"></div>
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-12 text-center">
                <span class="hero-eyebrow">
                    <i class="bi bi-cloud-fill"></i>
                    <?= $translations['cloud_solutions_page_title']; ?>
                </span>
                <h1 class="hero-title">
                    <?= $translations['cloud_solutions_hero_title']; ?>
                </h1>
                <p class="hero-subtitle mx-auto">
                    <?= $translations['cloud_solutions_hero_subtitle']; ?>
                </p>
                <div class="hero-cta">
                    <a href="#services" class="btn c-btn-primary">
                        <i class="bi bi-arrow-down"></i>
                        <?= $translations['explore_services']; ?>
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
                    <h2 class="section-title"><?= $translations['what_are_cloud_solutions']; ?></h2>
                </div>
                <p class="section-subtitle"><?= $translations['cloud_solutions_description']; ?></p>
                <ul class="why-list">
                    <li><i class="bi bi-check-circle-fill"></i> <?= $translations['flexible_cloud_infrastructure']; ?></li>
                    <li><i class="bi bi-check-circle-fill"></i> <?= $translations['secure_remote_access']; ?></li>
                    <li><i class="bi bi-check-circle-fill"></i> <?= $translations['reduced_hardware_costs']; ?></li>
                    <li><i class="bi bi-check-circle-fill"></i> <?= $translations['uk_portugal_support']; ?></li>
                </ul>
            </div>
            <div class="col-lg-6">
                <img src="assets/logos/cloud-solutions-graphic.png" alt="<?= $translations['cloud_solutions_alt']; ?>" class="img-fluid rounded">
            </div>
        </div>
    </div>
</section>

<!-- Services Section -->
<section class="section fade-in" id="services">
    <div class="container">
        <div class="section-header text-center">
            <h2 class="section-title"><?= $translations['our_cloud_services']; ?></h2>
            <p class="section-subtitle mx-auto"><?= $translations['cloud_services_section_subtitle']; ?></p>
        </div>

        <div class="row g-4">
            <!-- Cloud Migrations -->
            <div class="col-md-6">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="bi bi-cloud-arrow-up-fill"></i>
                    </div>
                    <div>
                        <h4 class="feature-title"><?= $translations['cloud_migrations']; ?></h4>
                        <p class="feature-text"><?= $translations['cloud_migrations_desc']; ?></p>
                    </div>
                </div>
            </div>

            <!-- Microsoft 365 & Google Workspace -->
            <div class="col-md-6">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="bi bi-microsoft"></i>
                    </div>
                    <div>
                        <h4 class="feature-title"><?= $translations['microsoft_365_google_workspace']; ?></h4>
                        <p class="feature-text"><?= $translations['microsoft_365_google_desc']; ?></p>
                    </div>
                </div>
            </div>

            <!-- Azure & Virtual Servers -->
            <div class="col-md-6">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="bi bi-hdd-network-fill"></i>
                    </div>
                    <div>
                        <h4 class="feature-title"><?= $translations['azure_virtual_servers']; ?></h4>
                        <p class="feature-text"><?= $translations['azure_virtual_desc']; ?></p>
                    </div>
                </div>
            </div>

            <!-- Web & Email Hosting -->
            <div class="col-md-6">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="bi bi-envelope-at-fill"></i>
                    </div>
                    <div>
                        <h4 class="feature-title"><?= $translations['web_email_hosting']; ?></h4>
                        <p class="feature-text"><?= $translations['web_email_desc']; ?></p>
                    </div>
                </div>
            </div>

            <!-- Cloud Security -->
            <div class="col-md-6">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="bi bi-shield-lock-fill"></i>
                    </div>
                    <div>
                        <h4 class="feature-title"><?= $translations['cloud_security']; ?></h4>
                        <p class="feature-text"><?= $translations['cloud_security_desc']; ?></p>
                    </div>
                </div>
            </div>

            <!-- Backup & Disaster Recovery -->
            <div class="col-md-6">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="bi bi-arrow-repeat"></i>
                    </div>
                    <div>
                        <h4 class="feature-title"><?= $translations['backup_disaster_recovery']; ?></h4>
                        <p class="feature-text"><?= $translations['backup_disaster_desc']; ?></p>
                    </div>
                </div>
            </div>

            <!-- Cloud Strategy & Consultancy -->
            <div class="col-md-6">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="bi bi-bar-chart-line-fill"></i>
                    </div>
                    <div>
                        <h4 class="feature-title"><?= $translations['cloud_strategy_consultancy']; ?></h4>
                        <p class="feature-text"><?= $translations['cloud_strategy_desc']; ?></p>
                    </div>
                </div>
            </div>

            <!-- Email Archiving & Journaling -->
            <div class="col-md-6">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="bi bi-archive-fill"></i>
                    </div>
                    <div>
                        <h4 class="feature-title"><?= $translations['email_archiving_journaling']; ?></h4>
                        <p class="feature-text"><?= $translations['email_archiving_desc']; ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- CTA Section -->
<section class="section section-soft fade-in">
    <div class="container text-center">
        <h4 class="section-title"><?= $translations['cloud_cta']; ?></h4>
        <a href="contact.php" class="btn c-btn-primary mt-3">
            <i class="bi bi-clouds"></i>
            <?= $translations['lets_talk_cloud']; ?>
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
