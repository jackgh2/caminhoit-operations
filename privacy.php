<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';

$page_title = $translations['privacy_page_title'];
?>
<!DOCTYPE html>
<html lang="<?= $lang; ?>">
<head>
    <meta charset="UTF-8">
    <title><?= $page_title; ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= $translations['privacy_page_description']; ?>">
    
    <!-- Styles -->
    <link rel="stylesheet" href="/assets/styles.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --card-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            --card-shadow-hover: 0 20px 40px rgba(0, 0, 0, 0.15);
            --border-radius: 12px;
            --transition: all 0.3s ease;
        }

        body { background: #f8f9fa; }

        .hero-enhanced {
            background: var(--primary-gradient);
            position: relative;
            overflow: hidden;
            min-height: 50vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }

        .hero-enhanced::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: radial-gradient(circle at 30% 50%, rgba(255,255,255,0.08) 0%, transparent 60%);
        }

        .hero-content-enhanced {
            text-align: center;
            z-index: 2;
            position: relative;
            padding: 4rem 1rem;
        }

        .hero-title-enhanced {
            font-size: 3rem;
            font-weight: 700;
            margin-bottom: 1rem;
            text-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }

        .hero-subtitle-enhanced {
            font-size: 1.25rem;
            opacity: 0.9;
            max-width: 600px;
            margin: 0 auto;
        }

        .policy-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 3rem;
            margin-top: -80px;
            position: relative;
            z-index: 10;
        }

        .policy-card:hover { box-shadow: var(--card-shadow-hover); }
        .policy-card h4 { color: #2c3e50; margin-top: 2rem; font-weight: 600; }
        .policy-card ul { margin-left: 1.25rem; color: #6c757d; }
        .policy-card p { color: #6c757d; line-height: 1.7; }

        .fade-in { opacity: 0; transform: translateY(30px); transition: all 0.6s ease; }
        .fade-in.visible { opacity: 1; transform: translateY(0); }

        @media (max-width: 768px) { .hero-title-enhanced { font-size: 2.2rem; } }
    </style>
</head>
<body>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/nav.php'; ?>

<header class="hero-enhanced">
  <div class="hero-content-enhanced">
    <h1 class="hero-title-enhanced"><i class="bi bi-shield-lock-fill me-2"></i><?= $translations['privacy_hero_title']; ?></h1>
    <p class="hero-subtitle-enhanced"><?= $translations['privacy_hero_subtitle']; ?></p>
  </div>
</header>

<section class="fade-in">
  <div class="container">
    <div class="policy-card">
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

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
</body>
</html>
