<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';

$page_title = $translations['consultancy_page_title'];
?>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2.php'; ?>

<!-- Hero Section -->
<header class="hero">
    <div class="hero-gradient"></div>
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-12 text-center">
                <span class="hero-eyebrow">
                    <i class="bi bi-lightbulb-fill"></i>
                    <?= $translations['consultancy_page_title']; ?>
                </span>
                <h1 class="hero-title">
                    <?= $translations['consultancy_hero_title']; ?>
                </h1>
                <p class="hero-subtitle mx-auto">
                    <?= $translations['consultancy_hero_subtitle']; ?>
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
                    <h2 class="section-title"><?= $translations['what_is_it_consultancy']; ?></h2>
                </div>
                <p class="section-subtitle"><?= $translations['consultancy_intro_desc']; ?></p>
                <ul class="why-list">
                    <li><i class="bi bi-check-circle-fill"></i> <?= $translations['strategic_it_planning']; ?></li>
                    <li><i class="bi bi-check-circle-fill"></i> <?= $translations['infrastructure_design_upgrades']; ?></li>
                    <li><i class="bi bi-check-circle-fill"></i> <?= $translations['cloud_adoption_transformation']; ?></li>
                    <li><i class="bi bi-check-circle-fill"></i> <?= $translations['vendor_neutral_advice']; ?></li>
                </ul>
            </div>
            <div class="col-lg-6">
                <img src="assets/logos/consultancy-graphic.png" alt="<?= $translations['consultancy_graphic_alt']; ?>" class="img-fluid rounded">
            </div>
        </div>
    </div>
</section>

<!-- Services Section -->
<section class="section fade-in" id="services">
    <div class="container">
        <div class="section-header text-center">
            <h2 class="section-title"><?= $translations['our_consultancy_services']; ?></h2>
            <p class="section-subtitle mx-auto"><?= $translations['consultancy_services_subtitle']; ?></p>
        </div>

        <div class="row g-4">
            <!-- IT Strategy & Audits -->
            <div class="col-md-6">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="bi bi-clipboard-check-fill"></i>
                    </div>
                    <div>
                        <h4 class="feature-title"><?= $translations['it_strategy_audits']; ?></h4>
                        <p class="feature-text"><?= $translations['it_strategy_audits_desc']; ?></p>
                    </div>
                </div>
            </div>

            <!-- Cloud Consultancy -->
            <div class="col-md-6">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="bi bi-cloud-check-fill"></i>
                    </div>
                    <div>
                        <h4 class="feature-title"><?= $translations['cloud_consultancy']; ?></h4>
                        <p class="feature-text"><?= $translations['cloud_consultancy_desc']; ?></p>
                    </div>
                </div>
            </div>

            <!-- Infrastructure Planning -->
            <div class="col-md-6">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="bi bi-diagram-3-fill"></i>
                    </div>
                    <div>
                        <h4 class="feature-title"><?= $translations['infrastructure_planning']; ?></h4>
                        <p class="feature-text"><?= $translations['infrastructure_planning_desc']; ?></p>
                    </div>
                </div>
            </div>

            <!-- Digital Transformation -->
            <div class="col-md-6">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="bi bi-laptop-fill"></i>
                    </div>
                    <div>
                        <h4 class="feature-title"><?= $translations['digital_transformation']; ?></h4>
                        <p class="feature-text"><?= $translations['digital_transformation_desc']; ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- CTA Section -->
<section class="section section-soft fade-in">
    <div class="container text-center">
        <h4 class="section-title"><?= $translations['consultancy_cta']; ?></h4>
        <a href="contact.php" class="btn c-btn-primary mt-3">
            <i class="bi bi-chat-dots"></i>
            <?= $translations['talk_to_consultants']; ?>
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
