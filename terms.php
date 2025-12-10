<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';

$page_title = $translations['terms_page_title'];
?>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2.php'; ?>

<header class="hero">
    <div class="container">
        <div class="hero-content text-center">
            <h1 class="display-3 fw-bold text-white mb-3">
                <i class="bi bi-file-earmark-text-fill me-3"></i><?= $translations['terms_hero_title']; ?>
            </h1>
            <p class="lead text-white opacity-90">
                <?= $translations['terms_hero_subtitle']; ?>
            </p>
        </div>
    </div>
</header>

<section class="section fade-in">
    <div class="container">
        <div class="feature-card">
            <h2 class="fw-bold mb-4 text-center text-primary"><?= $translations['terms_main_heading']; ?></h2>
            <p class="text-center mb-5"><?= $translations['terms_intro']; ?></p>

            <h4><?= $translations['terms_1_title']; ?></h4>
            <p><?= $translations['terms_1_text']; ?></p>

            <h4><?= $translations['terms_2_title']; ?></h4>
            <p><?= $translations['terms_2_text']; ?></p>

            <h4><?= $translations['terms_3_title']; ?></h4>
            <p><?= $translations['terms_3_text']; ?></p>

            <h4><?= $translations['terms_4_title']; ?></h4>
            <p><?= $translations['terms_4_text']; ?></p>

            <h4><?= $translations['terms_5_title']; ?></h4>
            <p><?= $translations['terms_5_text']; ?></p>

            <h4><?= $translations['terms_6_title']; ?></h4>
            <p><?= $translations['terms_6_text']; ?></p>

            <h4><?= $translations['terms_7_title']; ?></h4>
            <p><?= $translations['terms_7_text']; ?></p>
            <ul>
                <li><?= $translations['terms_7_li1']; ?></li>
                <li><?= $translations['terms_7_li2']; ?></li>
                <li><?= $translations['terms_7_li3']; ?></li>
                <li><?= $translations['terms_7_li4']; ?></li>
            </ul>

            <h4><?= $translations['terms_8_title']; ?></h4>
            <p><?= $translations['terms_8_text']; ?></p>

            <h4><?= $translations['terms_9_title']; ?></h4>
            <p><?= $translations['terms_9_text']; ?></p>

            <h4><?= $translations['terms_10_title']; ?></h4>
            <p><?= $translations['terms_10_text']; ?></p>

            <h4><?= $translations['terms_11_title']; ?></h4>
            <p><?= $translations['terms_11_text']; ?></p>

            <h4><?= $translations['terms_12_title']; ?></h4>
            <p><?= $translations['terms_12_text']; ?></p>

            <h4><?= $translations['terms_13_title']; ?></h4>
            <p><?= $translations['terms_13_text']; ?></p>

            <hr class="my-5">
            <p class="text-center text-muted fst-italic"><?= $translations['terms_footer_note']; ?></p>
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
