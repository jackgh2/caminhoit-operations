<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';

$page_title = $translations['sustainability_page_title'];
?>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2.php'; ?>

<!-- HERO -->
<header class="hero sustainability-hero">
    <div class="sustainability-gradient"></div>
    <div class="container position-relative text-center">
        <h1 class="hero-title">
            <i class="bi bi-globe-europe-africa me-3"></i><?= $translations['sustainability_hero_title']; ?>
        </h1>
        <p class="hero-subtitle mx-auto">
            <?= $translations['sustainability_hero_paragraph']; ?>
        </p>
        <div class="hero-cta">
            <a href="#why" class="btn c-btn-primary">
                <i class="bi bi-arrow-down-circle me-1"></i> <?= $translations['sustainability_hero_button']; ?>
            </a>
        </div>
    </div>
</header>

<main>

<!-- WHY SUSTAINABILITY -->
<section class="section section-soft fade-in" id="why">
    <div class="container">
        <div class="section-header text-center">
            <h2 class="section-title"><?= $translations['sustainability_why_title']; ?></h2>
            <p class="section-subtitle mx-auto"><?= $translations['sustainability_why_lead']; ?></p>
        </div>
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <p class="mb-3"><?= $translations['sustainability_why_p1']; ?></p>
                <p class="mb-3"><?= $translations['sustainability_why_p2']; ?></p>
            </div>
        </div>
    </div>
</section>

<!-- INTRODUCING VERDAIC -->
<section class="section fade-in" id="verdaic">
    <div class="container">
        <div class="section-header text-center">
            <div class="section-eyebrow">VerdaiC Platform</div>
            <h2 class="section-title"><?= $translations['sustainability_intro_title']; ?></h2>
            <p class="section-subtitle mx-auto"><?= $translations['sustainability_intro_lead']; ?></p>
        </div>
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <p class="mb-3"><?= $translations['sustainability_intro_p1']; ?></p>
                <p class="mb-3"><?= $translations['sustainability_intro_p2']; ?></p>
            </div>
        </div>
    </div>
</section>

<!-- THE CHALLENGE & SOLUTION -->
<section class="section section-soft fade-in">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="section-header text-center">
                    <h2 class="section-title"><?= $translations['sustainability_problem_title']; ?></h2>
                    <p class="section-subtitle mx-auto"><?= $translations['sustainability_problem_lead']; ?></p>
                </div>
                <p class="mb-5"><?= $translations['sustainability_problem_p1']; ?></p>

                <div class="section-header text-center mt-5">
                    <h2 class="section-title"><?= $translations['sustainability_solution_title']; ?></h2>
                </div>
                <p><?= $translations['sustainability_solution_p1']; ?></p>
            </div>
        </div>
    </div>
</section>

<!-- CORE FOCUS AREAS -->
<section class="section fade-in">
    <div class="container">
        <div class="section-header text-center">
            <div class="section-eyebrow">Core Focus</div>
            <h2 class="section-title"><?= $translations['sustainability_focus_title']; ?></h2>
            <p class="section-subtitle mx-auto"><?= $translations['sustainability_focus_lead']; ?></p>
        </div>

        <div class="row g-4">
            <div class="col-md-6 col-lg-3">
                <article class="pill-card">
                    <h3 class="pill-title"><?= $translations['sustainability_focus1_title']; ?></h3>
                    <p class="pill-text"><?= $translations['sustainability_focus1_text']; ?></p>
                </article>
            </div>
            <div class="col-md-6 col-lg-3">
                <article class="pill-card">
                    <h3 class="pill-title"><?= $translations['sustainability_focus2_title']; ?></h3>
                    <p class="pill-text"><?= $translations['sustainability_focus2_text']; ?></p>
                </article>
            </div>
            <div class="col-md-6 col-lg-3">
                <article class="pill-card">
                    <h3 class="pill-title"><?= $translations['sustainability_focus3_title']; ?></h3>
                    <p class="pill-text"><?= $translations['sustainability_focus3_text']; ?></p>
                </article>
            </div>
            <div class="col-md-6 col-lg-3">
                <article class="pill-card">
                    <h3 class="pill-title"><?= $translations['sustainability_focus4_title']; ?></h3>
                    <p class="pill-text"><?= $translations['sustainability_focus4_text']; ?></p>
                </article>
            </div>
        </div>
    </div>
</section>

<!-- DASHBOARD -->
<section class="section section-soft fade-in">
    <div class="container">
        <div class="section-header text-center">
            <div class="section-eyebrow">Dashboard Features</div>
            <h2 class="section-title"><?= $translations['sustainability_dashboard_title']; ?></h2>
            <p class="section-subtitle mx-auto"><?= $translations['sustainability_dashboard_lead']; ?></p>
        </div>

        <div class="row g-4">
            <div class="col-md-6 col-lg-3">
                <article class="feature-card">
                    <div class="feature-content">
                        <h3 class="feature-title"><?= $translations['sustainability_dashboard1_title']; ?></h3>
                        <p class="feature-text"><?= $translations['sustainability_dashboard1_text']; ?></p>
                    </div>
                </article>
            </div>
            <div class="col-md-6 col-lg-3">
                <article class="feature-card">
                    <div class="feature-content">
                        <h3 class="feature-title"><?= $translations['sustainability_dashboard2_title']; ?></h3>
                        <p class="feature-text"><?= $translations['sustainability_dashboard2_text']; ?></p>
                    </div>
                </article>
            </div>
            <div class="col-md-6 col-lg-3">
                <article class="feature-card">
                    <div class="feature-content">
                        <h3 class="feature-title"><?= $translations['sustainability_dashboard3_title']; ?></h3>
                        <p class="feature-text"><?= $translations['sustainability_dashboard3_text']; ?></p>
                    </div>
                </article>
            </div>
            <div class="col-md-6 col-lg-3">
                <article class="feature-card">
                    <div class="feature-content">
                        <h3 class="feature-title"><?= $translations['sustainability_dashboard4_title']; ?></h3>
                        <p class="feature-text"><?= $translations['sustainability_dashboard4_text']; ?></p>
                    </div>
                </article>
            </div>
        </div>
    </div>
</section>

<!-- BUSINESS BENEFITS -->
<section class="section fade-in">
    <div class="container">
        <div class="section-header text-center">
            <div class="section-eyebrow">Business Value</div>
            <h2 class="section-title"><?= $translations['sustainability_benefits_title']; ?></h2>
            <p class="section-subtitle mx-auto"><?= $translations['sustainability_benefits_lead']; ?></p>
        </div>
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <p class="mb-4"><?= $translations['sustainability_benefits_p1']; ?></p>
                <ul class="why-list">
                    <li>
                        <i class="bi bi-check2-circle"></i>
                        <span><?= $translations['sustainability_benefit1']; ?></span>
                    </li>
                    <li>
                        <i class="bi bi-check2-circle"></i>
                        <span><?= $translations['sustainability_benefit2']; ?></span>
                    </li>
                    <li>
                        <i class="bi bi-check2-circle"></i>
                        <span><?= $translations['sustainability_benefit3']; ?></span>
                    </li>
                    <li>
                        <i class="bi bi-check2-circle"></i>
                        <span><?= $translations['sustainability_benefit4']; ?></span>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</section>

<!-- CTA -->
<section class="section sustainability-cta fade-in">
    <div class="container text-center">
        <h2 class="section-title text-white mb-3"><?= $translations['sustainability_cta_title']; ?></h2>
        <p class="section-subtitle mx-auto text-white mb-4">
            <?= $translations['sustainability_cta_paragraph']; ?>
        </p>
        <div class="d-flex justify-content-center gap-3">
            <a href="/contact.php" class="btn c-btn-primary">
                <i class="bi bi-chat-dots me-1"></i> <?= $translations['sustainability_cta_btn1']; ?>
            </a>
            <a href="/book.php" class="btn c-btn-outline-light">
                <i class="bi bi-laptop me-1"></i> <?= $translations['sustainability_cta_btn2']; ?>
            </a>
        </div>
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
});
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-v2.php'; ?>
