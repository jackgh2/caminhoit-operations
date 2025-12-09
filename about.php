<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';

$page_title = $translations['about_page_title'];
?>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2.php'; ?>

<!-- Enhanced Hero Section -->
<header class="hero about-hero">
    <div class="hero-gradient"></div>
    <div class="container position-relative">
        <div class="hero-content-enhanced text-center">
            <h1 class="hero-title text-white">
                <i class="bi bi-building-fill me-3"></i>
                <?= $translations['about_hero_title']; ?>
            </h1>
            <p class="hero-subtitle mx-auto text-white">
                <?= $translations['about_hero_subtitle']; ?>
            </p>
            <div class="hero-cta">
                <a href="#story" class="btn c-btn-primary">
                    <i class="bi bi-arrow-down me-1"></i>
                    <?= $translations['learn_our_story']; ?>
                </a>
            </div>
        </div>
    </div>
</header>

<main>

    <!-- Story Section -->
    <section class="section section-soft fade-in" id="story">
        <div class="container">
            <div class="section-header text-center">
                <h2 class="section-title"><?= $translations['who_we_are']; ?></h2>
            </div>

            <div class="row">
                <div class="col-lg-10 mx-auto">
                    <div class="panel panel-gradient mb-4">
                        <div class="d-flex align-items-center gap-3">
                            <div class="fs-1">🤝</div>
                            <div class="panel-text">
                                <?= $translations['strategic_partner_quote']; ?>
                            </div>
                        </div>
                    </div>

                    <p class="mb-3">
                        <?= $translations['company_journey_p1']; ?>
                    </p>
                    <p class="mb-3">
                        <?= $translations['company_journey_p2']; ?>
                    </p>
                    <p class="mb-3">
                        <?= $translations['company_journey_p3']; ?>
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- Values Section -->
    <section class="section fade-in">
        <div class="container">
            <div class="section-header text-center">
                <div class="section-eyebrow">Our Values</div>
                <h2 class="section-title"><?= $translations['what_we_stand_for']; ?></h2>
                <p class="section-subtitle mx-auto"><?= $translations['values_subtitle']; ?></p>
            </div>

            <div class="row g-4">
                <div class="col-md-6 col-lg-3">
                    <article class="pill-card text-center">
                        <div class="fs-2 mb-3">✅</div>
                        <h3 class="pill-title"><?= $translations['value_clarity']; ?></h3>
                        <p class="pill-text"><?= $translations['value_clarity_desc']; ?></p>
                    </article>
                </div>
                <div class="col-md-6 col-lg-3">
                    <article class="pill-card text-center">
                        <div class="fs-2 mb-3">🚀</div>
                        <h3 class="pill-title"><?= $translations['value_innovation']; ?></h3>
                        <p class="pill-text"><?= $translations['value_innovation_desc']; ?></p>
                    </article>
                </div>
                <div class="col-md-6 col-lg-3">
                    <article class="pill-card text-center">
                        <div class="fs-2 mb-3">🤝</div>
                        <h3 class="pill-title"><?= $translations['value_partnership']; ?></h3>
                        <p class="pill-text"><?= $translations['value_partnership_desc']; ?></p>
                    </article>
                </div>
                <div class="col-md-6 col-lg-3">
                    <article class="pill-card text-center">
                        <div class="fs-2 mb-3">🌍</div>
                        <h3 class="pill-title"><?= $translations['value_agility']; ?></h3>
                        <p class="pill-text"><?= $translations['value_agility_desc']; ?></p>
                    </article>
                </div>
            </div>
        </div>
    </section>

    <!-- Why Partner Section -->
    <section class="section section-soft fade-in">
        <div class="container">
            <div class="section-header text-center">
                <div class="section-eyebrow">Why Choose Us</div>
                <h2 class="section-title"><?= $translations['why_partner_title']; ?></h2>
            </div>

            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <ul class="why-list">
                        <li>
                            <i class="bi bi-check2-circle"></i>
                            <span>🇬🇧🇵🇹 <?= $translations['strong_uk_portugal_presence']; ?></span>
                        </li>
                        <li>
                            <i class="bi bi-check2-circle"></i>
                            <span>⚡ <?= $translations['agile_team_ready']; ?></span>
                        </li>
                        <li>
                            <i class="bi bi-check2-circle"></i>
                            <span>🔐 <?= $translations['trusted_microsoft_reseller']; ?></span>
                        </li>
                        <li>
                            <i class="bi bi-check2-circle"></i>
                            <span>🛠️ <?= $translations['seamless_it_services']; ?></span>
                        </li>
                        <li>
                            <i class="bi bi-check2-circle"></i>
                            <span>🌱 <?= $translations['sustainable_growth_commitment']; ?></span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <!-- Industries Section -->
    <section class="section fade-in">
        <div class="container">
            <div class="section-header text-center">
                <div class="section-eyebrow">Industries</div>
                <h2 class="section-title"><?= $translations['industries_we_support']; ?></h2>
                <p class="section-subtitle mx-auto"><?= $translations['industries_subtitle']; ?></p>
            </div>

            <div class="row g-3">
                <!-- Row 1 -->
                <div class="col-md-3 col-sm-6">
                    <article class="pill-card text-center">
                        <div class="fs-3 mb-2">🏦</div>
                        <h5 class="pill-title"><?= $translations['financial_services']; ?></h5>
                        <p class="pill-text"><?= $translations['financial_services_desc']; ?></p>
                    </article>
                </div>
                <div class="col-md-3 col-sm-6">
                    <article class="pill-card text-center">
                        <div class="fs-3 mb-2">🚀</div>
                        <h5 class="pill-title"><?= $translations['startups']; ?></h5>
                        <p class="pill-text"><?= $translations['startups_desc']; ?></p>
                    </article>
                </div>
                <div class="col-md-3 col-sm-6">
                    <article class="pill-card text-center">
                        <div class="fs-3 mb-2">🏢</div>
                        <h5 class="pill-title"><?= $translations['smes_corporates']; ?></h5>
                        <p class="pill-text"><?= $translations['smes_corporates_desc']; ?></p>
                    </article>
                </div>
                <div class="col-md-3 col-sm-6">
                    <article class="pill-card text-center">
                        <div class="fs-3 mb-2">🎓</div>
                        <h5 class="pill-title"><?= $translations['education_training']; ?></h5>
                        <p class="pill-text"><?= $translations['education_training_desc']; ?></p>
                    </article>
                </div>

                <!-- Row 2 -->
                <div class="col-md-3 col-sm-6">
                    <article class="pill-card text-center">
                        <div class="fs-3 mb-2">🏥</div>
                        <h5 class="pill-title"><?= $translations['healthcare_clinics']; ?></h5>
                        <p class="pill-text"><?= $translations['healthcare_clinics_desc']; ?></p>
                    </article>
                </div>
                <div class="col-md-3 col-sm-6">
                    <article class="pill-card text-center">
                        <div class="fs-3 mb-2">⚖️</div>
                        <h5 class="pill-title"><?= $translations['legal_consultancy']; ?></h5>
                        <p class="pill-text"><?= $translations['legal_consultancy_desc']; ?></p>
                    </article>
                </div>
                <div class="col-md-3 col-sm-6">
                    <article class="pill-card text-center">
                        <div class="fs-3 mb-2">🎨</div>
                        <h5 class="pill-title"><?= $translations['creative_media']; ?></h5>
                        <p class="pill-text"><?= $translations['creative_media_desc']; ?></p>
                    </article>
                </div>
                <div class="col-md-3 col-sm-6">
                    <article class="pill-card text-center">
                        <div class="fs-3 mb-2">🛍️</div>
                        <h5 class="pill-title"><?= $translations['ecommerce_retail']; ?></h5>
                        <p class="pill-text"><?= $translations['ecommerce_retail_desc']; ?></p>
                    </article>
                </div>

                <!-- Row 3 -->
                <div class="col-md-3 col-sm-6">
                    <article class="pill-card text-center">
                        <div class="fs-3 mb-2">🏗️</div>
                        <h5 class="pill-title"><?= $translations['construction_trades']; ?></h5>
                        <p class="pill-text"><?= $translations['construction_trades_desc']; ?></p>
                    </article>
                </div>
                <div class="col-md-3 col-sm-6">
                    <article class="pill-card text-center">
                        <div class="fs-3 mb-2">📢</div>
                        <h5 class="pill-title"><?= $translations['marketing_advertising']; ?></h5>
                        <p class="pill-text"><?= $translations['marketing_advertising_desc']; ?></p>
                    </article>
                </div>
                <div class="col-md-3 col-sm-6">
                    <article class="pill-card text-center">
                        <div class="fs-3 mb-2">🌍</div>
                        <h5 class="pill-title"><?= $translations['charities_nonprofits']; ?></h5>
                        <p class="pill-text"><?= $translations['charities_nonprofits_desc']; ?></p>
                    </article>
                </div>
                <div class="col-md-3 col-sm-6">
                    <article class="pill-card text-center">
                        <div class="fs-3 mb-2">🏘️</div>
                        <h5 class="pill-title"><?= $translations['property_housing']; ?></h5>
                        <p class="pill-text"><?= $translations['property_housing_desc']; ?></p>
                    </article>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="section section-soft fade-in text-center">
        <div class="container">
            <h2 class="section-title mb-4"><?= $translations['about_cta']; ?></h2>
            <a href="/index.php#it" class="btn c-btn-primary">
                <i class="bi bi-grid-3x3-gap me-1"></i>
                <?= $translations['discover_our_services']; ?>
            </a>
        </div>
    </section>

</main>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const observer = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('visible');
            }
        });
    }, {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    });

    document.querySelectorAll('.fade-in').forEach(el => observer.observe(el));

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
