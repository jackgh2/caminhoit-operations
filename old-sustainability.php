<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';

$page_title = $translations['sustainability_page_title'];
?>
<!DOCTYPE html>
<html lang="<?= $lang; ?>">
<head>
    <meta charset="UTF-8">
    <title><?= $page_title; ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= $translations['sustainability_page_description']; ?>">

    <link rel="preload" href="/assets/logo.png" as="image">
    <link rel="stylesheet" href="/assets/styles.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            --border-radius: 14px;
            --card-shadow: 0 10px 25px rgba(0,0,0,0.1);
            --card-shadow-hover: 0 20px 35px rgba(0,0,0,0.15);
        }
        .hero {
            background: var(--primary-gradient);
            color: #fff;
            text-align: center;
            padding: 7rem 2rem;
        }
        .hero h1 {font-size:3.5rem;font-weight:800;}
        .hero p {max-width:800px;margin:1.5rem auto 2rem;font-size:1.25rem;}
        .hero-btn {
            background:#fff;color:#11998e;padding:0.875rem 2rem;border-radius:50px;
            font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:.5rem;
            box-shadow:0 4px 12px rgba(0,0,0,0.2);
        }
        .hero-btn:hover {transform:translateY(-2px);box-shadow:0 8px 25px rgba(0,0,0,0.3);}
        .section {padding:5rem 0;}
        .section h2 {font-size:2.6rem;font-weight:700;text-align:center;margin-bottom:1rem;}
        .section p.lead {text-align:center;max-width:800px;margin:1rem auto 3rem;color:#555;}
        .focus-card {
            background:#fff;padding:2rem;border-radius:var(--border-radius);
            box-shadow:var(--card-shadow);transition:all .3s ease;height:100%;
        }
        .focus-card:hover {transform:translateY(-5px);box-shadow:var(--card-shadow-hover);}
        .cta {background:var(--primary-gradient);color:#fff;text-align:center;padding:5rem 2rem;}
        .cta a {
            background:#fff;color:#11998e;padding:0.875rem 2rem;border-radius:50px;
            text-decoration:none;font-weight:600;margin:0.5rem;display:inline-block;
        }
        .fade-in{opacity:0;transform:translateY(30px);transition:all .6s ease;}
        .fade-in.visible{opacity:1;transform:translateY(0);}
    </style>
</head>
<body>
<?php include $_SERVER['DOCUMENT_ROOT'].'/includes/nav.php'; ?>

<!-- HERO -->
<section class="hero">
  <div class="container">
    <h1><i class="bi bi-globe-europe-africa me-2"></i><?= $translations['sustainability_hero_title']; ?></h1>
    <p><?= $translations['sustainability_hero_paragraph']; ?></p>
    <a href="#why" class="hero-btn"><i class="bi bi-arrow-down-circle"></i> <?= $translations['sustainability_hero_button']; ?></a>
  </div>
</section>

<!-- WHY SUSTAINABILITY -->
<section class="section fade-in" id="why">
  <div class="container">
    <h2><?= $translations['sustainability_why_title']; ?></h2>
    <p class="lead"><?= $translations['sustainability_why_lead']; ?></p>
    <p><?= $translations['sustainability_why_p1']; ?></p>
    <p><?= $translations['sustainability_why_p2']; ?></p>
  </div>
</section>

<!-- INTRODUCING VERDAIC -->
<section class="section bg-light fade-in" id="verdaic">
  <div class="container">
    <h2><?= $translations['sustainability_intro_title']; ?></h2>
    <p class="lead"><?= $translations['sustainability_intro_lead']; ?></p>
    <p><?= $translations['sustainability_intro_p1']; ?></p>
    <p><?= $translations['sustainability_intro_p2']; ?></p>
  </div>
</section>

<!-- THE CHALLENGE & SOLUTION -->
<section class="section fade-in">
  <div class="container">
    <h2><?= $translations['sustainability_problem_title']; ?></h2>
    <p class="lead"><?= $translations['sustainability_problem_lead']; ?></p>
    <p><?= $translations['sustainability_problem_p1']; ?></p>
    <h2 class="mt-5"><?= $translations['sustainability_solution_title']; ?></h2>
    <p><?= $translations['sustainability_solution_p1']; ?></p>
  </div>
</section>

<!-- CORE FOCUS AREAS -->
<section class="section bg-light fade-in">
  <div class="container">
    <h2><?= $translations['sustainability_focus_title']; ?></h2>
    <p class="lead"><?= $translations['sustainability_focus_lead']; ?></p>
    <div class="row g-4 mt-4">
      <div class="col-md-6 col-lg-3">
        <div class="focus-card">
          <h5><?= $translations['sustainability_focus1_title']; ?></h5>
          <p><?= $translations['sustainability_focus1_text']; ?></p>
        </div>
      </div>
      <div class="col-md-6 col-lg-3">
        <div class="focus-card">
          <h5><?= $translations['sustainability_focus2_title']; ?></h5>
          <p><?= $translations['sustainability_focus2_text']; ?></p>
        </div>
      </div>
      <div class="col-md-6 col-lg-3">
        <div class="focus-card">
          <h5><?= $translations['sustainability_focus3_title']; ?></h5>
          <p><?= $translations['sustainability_focus3_text']; ?></p>
        </div>
      </div>
      <div class="col-md-6 col-lg-3">
        <div class="focus-card">
          <h5><?= $translations['sustainability_focus4_title']; ?></h5>
          <p><?= $translations['sustainability_focus4_text']; ?></p>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- DASHBOARD -->
<section class="section fade-in">
  <div class="container">
    <h2><?= $translations['sustainability_dashboard_title']; ?></h2>
    <p class="lead"><?= $translations['sustainability_dashboard_lead']; ?></p>
    <div class="row g-4">
      <div class="col-md-3"><div class="focus-card"><strong><?= $translations['sustainability_dashboard1_title']; ?></strong><p><?= $translations['sustainability_dashboard1_text']; ?></p></div></div>
      <div class="col-md-3"><div class="focus-card"><strong><?= $translations['sustainability_dashboard2_title']; ?></strong><p><?= $translations['sustainability_dashboard2_text']; ?></p></div></div>
      <div class="col-md-3"><div class="focus-card"><strong><?= $translations['sustainability_dashboard3_title']; ?></strong><p><?= $translations['sustainability_dashboard3_text']; ?></p></div></div>
      <div class="col-md-3"><div class="focus-card"><strong><?= $translations['sustainability_dashboard4_title']; ?></strong><p><?= $translations['sustainability_dashboard4_text']; ?></p></div></div>
    </div>
  </div>
</section>

<!-- BUSINESS BENEFITS -->
<section class="section bg-light fade-in">
  <div class="container">
    <h2><?= $translations['sustainability_benefits_title']; ?></h2>
    <p class="lead"><?= $translations['sustainability_benefits_lead']; ?></p>
    <p><?= $translations['sustainability_benefits_p1']; ?></p>
    <ul>
      <li><strong><?= $translations['sustainability_benefit1']; ?></strong></li>
      <li><strong><?= $translations['sustainability_benefit2']; ?></strong></li>
      <li><strong><?= $translations['sustainability_benefit3']; ?></strong></li>
      <li><strong><?= $translations['sustainability_benefit4']; ?></strong></li>
    </ul>
  </div>
</section>

<!-- CTA -->
<section class="cta fade-in">
  <div class="container">
    <h3><?= $translations['sustainability_cta_title']; ?></h3>
    <p class="mb-4"><?= $translations['sustainability_cta_paragraph']; ?></p>
    <a href="/contact" class="btn"><i class="bi bi-chat-dots"></i> <?= $translations['sustainability_cta_btn1']; ?></a>
    <a href="/demo" class="btn"><i class="bi bi-laptop"></i> <?= $translations['sustainability_cta_btn2']; ?></a>
  </div>
</section>

<script>
document.addEventListener('DOMContentLoaded',()=>{
  const obs=new IntersectionObserver(e=>{e.forEach(x=>{if(x.isIntersecting)x.target.classList.add('visible');});},{threshold:.1});
  document.querySelectorAll('.fade-in').forEach(el=>obs.observe(el));
});
</script>

<?php include $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
</body>
</html>
