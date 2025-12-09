<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';

$page_title = $translations['security_page_title'];
?>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2.php'; ?>

<header class="hero">
    <div class="container">
        <div class="hero-content text-center">
            <h1 class="display-3 fw-bold text-white mb-3">
                <i class="bi bi-lock-fill me-3"></i><?= $translations['security_hero_title']; ?>
            </h1>
            <p class="lead text-white opacity-90">
                <?= $translations['security_hero_subtitle']; ?>
            </p>
        </div>
    </div>
</header>

<section class="section fade-in">
    <div class="container">
        <div class="feature-card">
            <h2 class="fw-bold mb-4 text-center text-primary"><?= $translations['security_main_heading']; ?></h2>
            <p class="text-center mb-5"><?= $translations['security_intro']; ?></p>

            <h4><?= $translations['security_1_title']; ?></h4>
            <p><?= $translations['security_1_text']; ?></p>

            <h4><?= $translations['security_2_title']; ?></h4>
            <p><?= $translations['security_2_intro']; ?></p>
            <ul>
                <li><?= $translations['security_2_li1']; ?></li>
                <li><?= $translations['security_2_li2']; ?></li>
                <li><?= $translations['security_2_li3']; ?></li>
                <li><?= $translations['security_2_li4']; ?></li>
                <li><?= $translations['security_2_li5']; ?></li>
            </ul>

            <h4><?= $translations['security_3_title']; ?></h4>
            <p><?= $translations['security_3_intro']; ?></p>
            <ul>
                <li><?= $translations['security_3_li1']; ?></li>
                <li><?= $translations['security_3_li2']; ?></li>
                <li><?= $translations['security_3_li3']; ?></li>
            </ul>

            <h4><?= $translations['security_4_title']; ?></h4>
            <p><?= $translations['security_4_intro']; ?></p>
            <ul>
                <li><?= $translations['security_4_li1']; ?></li>
                <li><?= $translations['security_4_li2']; ?></li>
                <li><?= $translations['security_4_li3']; ?></li>
                <li><?= $translations['security_4_li4']; ?></li>
                <li><?= $translations['security_4_li5']; ?></li>
            </ul>

            <h4><?= $translations['security_5_title']; ?></h4>
            <p><?= $translations['security_5_text']; ?></p>

            <h4><?= $translations['security_6_title']; ?></h4>
            <p><?= $translations['security_6_intro']; ?></p>
            <ul>
                <li><?= $translations['security_6_li1']; ?></li>
                <li><?= $translations['security_6_li2']; ?></li>
                <li><?= $translations['security_6_li3']; ?></li>
            </ul>

            <h4><?= $translations['security_7_title']; ?></h4>
            <p><?= $translations['security_7_text']; ?></p>

            <h4><?= $translations['security_8_title']; ?></h4>
            <p><?= $translations['security_8_intro']; ?></p>
            <ul>
                <li><?= $translations['security_8_li1']; ?></li>
                <li><?= $translations['security_8_li2']; ?></li>
                <li><?= $translations['security_8_li3']; ?></li>
            </ul>

            <h4><?= $translations['security_9_title']; ?></h4>
            <p><?= $translations['security_9_text']; ?></p>

            <h4><?= $translations['security_10_title']; ?></h4>
            <p><?= $translations['security_10_text']; ?></p>

            <hr class="my-5">
            <p class="text-center text-muted fst-italic mb-0"><?= $translations['security_footer_note']; ?></p>
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
