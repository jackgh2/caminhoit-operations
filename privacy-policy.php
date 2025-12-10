<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';

$page_title = $translations['privacy_page_title'];
?>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2.php'; ?>

<header class="hero">
    <div class="container">
        <div class="hero-content text-center">
            <h1 class="display-3 fw-bold text-white mb-3">
                <i class="bi bi-shield-lock-fill me-3"></i><?= $translations['privacy_hero_title']; ?>
            </h1>
            <p class="lead text-white opacity-90">
                <?= $translations['privacy_hero_subtitle']; ?>
            </p>
        </div>
    </div>
</header>

<section class="section fade-in">
    <div class="container">
        <div class="feature-card">
            <h2 class="fw-bold mb-4 text-center text-primary"><?= $translations['privacy_main_heading']; ?></h2>
            <p class="text-center mb-5"><?= $translations['privacy_intro']; ?></p>

            <h4><?= $translations['privacy_1_title']; ?></h4>
            <p><?= $translations['privacy_1_text']; ?></p>

            <h4><?= $translations['privacy_2_title']; ?></h4>
            <ul>
                <li><?= $translations['privacy_2_li1']; ?></li>
                <li><?= $translations['privacy_2_li2']; ?></li>
                <li><?= $translations['privacy_2_li3']; ?></li>
                <li><?= $translations['privacy_2_li4']; ?></li>
            </ul>

            <h4><?= $translations['privacy_3_title']; ?></h4>
            <p><?= $translations['privacy_3_text']; ?></p>
            <ul>
                <li><?= $translations['privacy_3_li1']; ?></li>
                <li><?= $translations['privacy_3_li2']; ?></li>
                <li><?= $translations['privacy_3_li3']; ?></li>
                <li><?= $translations['privacy_3_li4']; ?></li>
            </ul>

            <h4><?= $translations['privacy_4_title']; ?></h4>
            <ul>
                <li><?= $translations['privacy_4_li1']; ?></li>
                <li><?= $translations['privacy_4_li2']; ?></li>
                <li><?= $translations['privacy_4_li3']; ?></li>
            </ul>

            <h4><?= $translations['privacy_5_title']; ?></h4>
            <p><?= $translations['privacy_5_text']; ?></p>

            <h4><?= $translations['privacy_6_title']; ?></h4>
            <p><?= $translations['privacy_6_text']; ?></p>

            <h4><?= $translations['privacy_7_title']; ?></h4>
            <ul>
                <li><?= $translations['privacy_7_li1']; ?></li>
                <li><?= $translations['privacy_7_li2']; ?></li>
                <li><?= $translations['privacy_7_li3']; ?></li>
                <li><?= $translations['privacy_7_li4']; ?></li>
            </ul>

            <h4><?= $translations['privacy_8_title']; ?></h4>
            <p><?= $translations['privacy_8_text']; ?></p>

            <h4><?= $translations['privacy_9_title']; ?></h4>
            <p><?= $translations['privacy_9_text']; ?></p>

            <h4><?= $translations['privacy_10_title']; ?></h4>
            <p><?= $translations['privacy_10_text']; ?></p>

            <h4><?= $translations['privacy_11_title']; ?></h4>
            <ul>
                <li><?= $translations['privacy_11_li1']; ?></li>
                <li><?= $translations['privacy_11_li2']; ?></li>
                <li><?= $translations['privacy_11_li3']; ?></li>
                <li><?= $translations['privacy_11_li4']; ?></li>
            </ul>

            <h4><?= $translations['privacy_12_title']; ?></h4>
            <p><?= $translations['privacy_12_text']; ?></p>

            <h4><?= $translations['privacy_13_title']; ?></h4>
            <p><?= $translations['privacy_13_text']; ?></p>

            <h4><?= $translations['privacy_14_title']; ?></h4>
            <p><?= $translations['privacy_14_text']; ?></p>

            <h4><?= $translations['privacy_15_title']; ?></h4>
            <p><?= $translations['privacy_15_text']; ?></p>
            <p><em><?= $translations['privacy_last_updated']; ?></em></p>

            <hr class="my-4">
            <p class="text-center text-muted fst-italic mb-0"><?= $translations['privacy_footer_note']; ?></p>
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
