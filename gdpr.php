<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';

$page_title = $translations['gdpr_page_title'];
?>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2.php'; ?>

<header class="hero">
    <div class="container">
        <div class="hero-content text-center">
            <h1 class="display-3 fw-bold text-white mb-3">
                <i class="bi bi-shield-check me-3"></i><?= $translations['gdpr_hero_title']; ?>
            </h1>
            <p class="lead text-white opacity-90">
                <?= $translations['gdpr_hero_subtitle']; ?>
            </p>
        </div>
    </div>
</header>

<section class="section fade-in">
    <div class="container">
        <div class="feature-card">
            <h2 class="fw-bold mb-4 text-center text-primary"><?= $translations['gdpr_main_heading']; ?></h2>
            <p class="text-center mb-5"><?= $translations['gdpr_intro']; ?></p>

            <h4><?= $translations['gdpr_1_title']; ?></h4>
            <p><?= $translations['gdpr_1_text']; ?></p>

            <h4><?= $translations['gdpr_2_title']; ?></h4>
            <p><?= $translations['gdpr_2_text']; ?></p>

            <h4><?= $translations['gdpr_3_title']; ?></h4>
            <p><?= $translations['gdpr_3_text']; ?></p>

            <h4><?= $translations['gdpr_4_title']; ?></h4>
            <p><?= $translations['gdpr_4_text']; ?></p>

            <h4><?= $translations['gdpr_5_title']; ?></h4>
            <p><?= $translations['gdpr_5_text']; ?></p>

            <h4><?= $translations['gdpr_6_title']; ?></h4>
            <p><?= $translations['gdpr_6_text']; ?></p>

            <h4><?= $translations['gdpr_7_title']; ?></h4>
            <p><?= $translations['gdpr_7_intro']; ?></p>
            <ul>
                <li><?= $translations['gdpr_7_li1']; ?></li>
                <li><?= $translations['gdpr_7_li2']; ?></li>
                <li><?= $translations['gdpr_7_li3']; ?></li>
                <li><?= $translations['gdpr_7_li4']; ?></li>
                <li><?= $translations['gdpr_7_li5']; ?></li>
                <li><?= $translations['gdpr_7_li6']; ?></li>
            </ul>

            <h4><?= $translations['gdpr_8_title']; ?></h4>
            <p><?= $translations['gdpr_8_intro']; ?></p>
            <ul>
                <li><?= $translations['gdpr_8_li1']; ?></li>
                <li><?= $translations['gdpr_8_li2']; ?></li>
            </ul>

            <h4><?= $translations['gdpr_9_title']; ?></h4>
            <p><?= $translations['gdpr_9_text']; ?></p>

            <h4><?= $translations['gdpr_10_title']; ?></h4>
            <p><?= $translations['gdpr_10_text']; ?></p>

            <h4><?= $translations['gdpr_11_title']; ?></h4>
            <p><?= $translations['gdpr_11_text']; ?></p>

            <h4><?= $translations['gdpr_12_title']; ?></h4>
            <p><?= $translations['gdpr_12_text']; ?></p>

            <h4><?= $translations['gdpr_13_title']; ?></h4>
            <p><?= $translations['gdpr_13_text']; ?></p>

            <h4><?= $translations['gdpr_14_title']; ?></h4>
            <p><?= $translations['gdpr_14_text']; ?></p>

            <hr class="my-5">
            <p class="text-center text-muted fst-italic mb-0"><?= $translations['gdpr_footer_note']; ?></p>
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const observer = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (entry.isIntersecting) entry.target.classList.add('visible');
        });
    }, { threshold: 0.1 });
    document.querySelectorAll('.fade-in').forEach(el => observer.observe(el));
});
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-v2.php'; ?>
