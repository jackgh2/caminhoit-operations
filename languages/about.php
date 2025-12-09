<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';

$page_title = $translations['about_page_title'];
?>
<!DOCTYPE html>
<html lang="<?= $lang; ?>">
<head>
    <meta charset="UTF-8">
    <title><?= $page_title; ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= $translations['about_page_description']; ?>">
    
    <!-- Preload critical assets -->
    <link rel="preload" href="/assets/logo.png" as="image">
    
    <link rel="stylesheet" href="/assets/styles.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            --warning-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --info-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --dark-gradient: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
            --card-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            --card-shadow-hover: 0 20px 40px rgba(0, 0, 0, 0.15);
            --border-radius: 12px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Enhanced Hero Section */
        .hero-enhanced {
            background: var(--primary-gradient);
            position: relative;
            overflow: hidden;
            min-height: 60vh;
            display: flex;
            align-items: center;
        }

        .hero-enhanced::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 100" fill="white" opacity="0.1"><polygon points="0,100 1000,100 1000,20"/></svg>');
            background-size: cover;
            background-position: bottom;
        }

        .hero-enhanced::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: radial-gradient(circle at 30% 50%, rgba(255,255,255,0.1) 0%, transparent 50%);
        }

        .hero-content-enhanced {
            position: relative;
            z-index: 2;
            text-align: center;
            padding: 4rem 0;
        }

        .hero-title-enhanced {
            font-size: 3.5rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            text-shadow: 0 2px 10px rgba(0,0,0,0.2);
            line-height: 1.2;
        }

        .hero-subtitle-enhanced {
            font-size: 1.25rem;
            margin-bottom: 2rem;
            opacity: 0.9;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }

        .hero-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
            margin-top: 2rem;
        }

        .hero-btn {
            padding: 0.875rem 2rem;
            border-radius: 50px;
            font-weight: 600;
            text-decoration: none;
            transition: var(--transition);
            border: 2px solid transparent;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .hero-btn-primary {
            background: white;
            color: #667eea;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }

        .hero-btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.3);
            color: #667eea;
        }

        /* Enhanced Cards */
        .enhanced-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            border: none;
            overflow: hidden;
            position: relative;
            height: 100%;
        }

        .enhanced-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--card-shadow-hover);
        }

        .enhanced-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary-gradient);
        }

        /* Content Sections */
        .content-section {
            padding: 4rem 0;
        }

        .section-header {
            text-align: center;
            margin-bottom: 3rem;
        }

        .section-title {
            font-size: 2.5rem;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 1rem;
        }

        .section-subtitle {
            font-size: 1.125rem;
            color: #6c757d;
            max-width: 600px;
            margin: 0 auto;
        }

        /* Story Section */
        .story-section {
            background: #f8f9fa;
            padding: 4rem 0;
        }

        .highlight-quote {
            background: var(--primary-gradient);
            color: white;
            padding: 2rem;
            border-radius: var(--border-radius);
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            box-shadow: var(--card-shadow);
        }

        .quote-icon {
            font-size: 2rem;
            flex-shrink: 0;
        }

        .story-text {
            color: #6c757d;
            line-height: 1.7;
            margin-bottom: 1.5rem;
        }

        /* Values Cards */
        .value-card {
            padding: 2rem;
            text-align: center;
        }

        .value-card h5 {
            color: #2c3e50;
            font-weight: 600;
            margin-bottom: 1rem;
        }

        .value-card p {
            color: #6c757d;
            line-height: 1.6;
            margin-bottom: 0;
        }

        /* Industry Cards */
        .industry-card {
            padding: 1.5rem;
            text-align: center;
        }

        .industry-card h5 {
            color: #2c3e50;
            font-weight: 600;
            margin-bottom: 0.75rem;
            font-size: 1rem;
        }

        .industry-card p {
            color: #6c757d;
            line-height: 1.5;
            margin-bottom: 0;
            font-size: 0.9rem;
        }

        /* Why Partner List */
        .partner-list {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 2rem;
            max-width: 1000px;
            margin: 0 auto;
        }

        .partner-list .list-group-item {
            border: none;
            padding: 1rem 0;
            color: #6c757d;
            font-weight: 500;
            border-bottom: 1px solid #eee;
        }

        .partner-list .list-group-item:last-child {
            border-bottom: none;
        }

        /* CTA Section */
        .cta-section {
            padding: 4rem 0;
            text-align: center;
        }

        .cta-section h4 {
            font-size: 1.5rem;
            color: #2c3e50;
            margin-bottom: 2rem;
        }

        .btn-enhanced {
            border-radius: 50px;
            padding: 0.875rem 2rem;
            font-weight: 600;
            transition: var(--transition);
            border: none;
            position: relative;
            overflow: hidden;
            background: var(--primary-gradient);
            color: white;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-enhanced:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.3);
            color: white;
        }

        /* Scroll Animations */
        .fade-in {
            opacity: 0;
            transform: translateY(30px);
            transition: all 0.6s ease;
        }

        .fade-in.visible {
            opacity: 1;
            transform: translateY(0);
        }

        /* Responsive Enhancements */
        @media (max-width: 768px) {
            .hero-title-enhanced {
                font-size: 2.5rem;
            }
            
            .section-title {
                font-size: 2rem;
            }

            .highlight-quote {
                flex-direction: column;
                text-align: center;
            }
        }
    </style>
</head>
<body>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/nav.php'; ?>

<!-- Enhanced Hero Section -->
<header class="hero-enhanced">
    <div class="container">
        <div class="hero-content-enhanced">
            <h1 class="hero-title-enhanced text-white">
                <i class="bi bi-building-fill me-3"></i>
                <?= $translations['about_hero_title']; ?>
            </h1>
            <p class="hero-subtitle-enhanced text-white">
                <?= $translations['about_hero_subtitle']; ?>
            </p>
            <div class="hero-actions">
                <a href="#story" class="hero-btn hero-btn-primary">
                    <i class="bi bi-arrow-down"></i>
                    <?= $translations['learn_our_story']; ?>
                </a>
            </div>
        </div>
    </div>
</header>

<div style="margin-top: -80px; position: relative; z-index: 10;">
    
    <!-- Story Section -->
    <section class="story-section fade-in" id="story">
        <div class="container">
            <div class="section-header">
                <h2 class="section-title"><?= $translations['who_we_are']; ?></h2>
            </div>
            
            <div class="row">
                <div class="col-lg-11 mx-auto">
                    <div class="highlight-quote">
                        <div class="quote-icon">🤝</div>
                        <div>
                            <?= $translations['strategic_partner_quote']; ?>
                        </div>
                    </div>

                    <p class="story-text">
                        <?= $translations['company_journey_p1']; ?>
                    </p>
                    <p class="story-text">
                        <?= $translations['company_journey_p2']; ?>
                    </p>
                    <p class="story-text">
                        <?= $translations['company_journey_p3']; ?>
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- Values Section -->
    <section class="content-section fade-in">
        <div class="container">
            <div class="section-header">
                <h2 class="section-title"><?= $translations['what_we_stand_for']; ?></h2>
                <p class="section-subtitle"><?= $translations['values_subtitle']; ?></p>
            </div>
            
            <div class="row g-4">
                <div class="col-md-3">
                    <div class="enhanced-card value-card">
                        <h5>✅ <?= $translations['value_clarity']; ?></h5>
                        <p><?= $translations['value_clarity_desc']; ?></p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="enhanced-card value-card">
                        <h5>🚀 <?= $translations['value_innovation']; ?></h5>
                        <p><?= $translations['value_innovation_desc']; ?></p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="enhanced-card value-card">
                        <h5>🤝 <?= $translations['value_partnership']; ?></h5>
                        <p><?= $translations['value_partnership_desc']; ?></p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="enhanced-card value-card">
                        <h5>🌍 <?= $translations['value_agility']; ?></h5>
                        <p><?= $translations['value_agility_desc']; ?></p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Why Partner Section -->
    <section class="story-section fade-in">
        <div class="container">
            <div class="section-header">
                <h2 class="section-title"><?= $translations['why_partner_title']; ?></h2>
            </div>
            
            <div class="partner-list">
                <ul class="list-group list-group-flush">
                    <li class="list-group-item">🇬🇧🇵🇹 <?= $translations['strong_uk_portugal_presence']; ?></li>
                    <li class="list-group-item">⚡ <?= $translations['agile_team_ready']; ?></li>
                    <li class="list-group-item">🔐 <?= $translations['trusted_microsoft_reseller']; ?></li>
                    <li class="list-group-item">🛠️ <?= $translations['seamless_it_services']; ?></li>
                    <li class="list-group-item">🌱 <?= $translations['sustainable_growth_commitment']; ?></li>
                </ul>
            </div>
        </div>
    </section>

    <!-- Industries Section -->
    <section class="story-section fade-in">
        <div class="container">
            <div class="section-header">
                <h2 class="section-title"><?= $translations['industries_we_support']; ?></h2>
                <p class="section-subtitle"><?= $translations['industries_subtitle']; ?></p>
            </div>
            
            <div class="row g-3">
                <!-- Row 1 -->
                <div class="col-md-3 col-sm-6">
                    <div class="enhanced-card industry-card">
                        <h5>🏦 <?= $translations['financial_services']; ?></h5>
                        <p><?= $translations['financial_services_desc']; ?></p>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="enhanced-card industry-card">
                        <h5>🚀 <?= $translations['startups']; ?></h5>
                        <p><?= $translations['startups_desc']; ?></p>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="enhanced-card industry-card">
                        <h5>🏢 <?= $translations['smes_corporates']; ?></h5>
                        <p><?= $translations['smes_corporates_desc']; ?></p>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="enhanced-card industry-card">
                        <h5>🎓 <?= $translations['education_training']; ?></h5>
                        <p><?= $translations['education_training_desc']; ?></p>
                    </div>
                </div>

                <!-- Row 2 -->
                <div class="col-md-3 col-sm-6">
                    <div class="enhanced-card industry-card">
                        <h5>🏥 <?= $translations['healthcare_clinics']; ?></h5>
                        <p><?= $translations['healthcare_clinics_desc']; ?></p>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="enhanced-card industry-card">
                        <h5>⚖️ <?= $translations['legal_consultancy']; ?></h5>
                        <p><?= $translations['legal_consultancy_desc']; ?></p>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="enhanced-card industry-card">
                        <h5>🎨 <?= $translations['creative_media']; ?></h5>
                        <p><?= $translations['creative_media_desc']; ?></p>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="enhanced-card industry-card">
                        <h5>🛍️ <?= $translations['ecommerce_retail']; ?></h5>
                        <p><?= $translations['ecommerce_retail_desc']; ?></p>
                    </div>
                </div>

                <!-- Row 3 -->
                <div class="col-md-3 col-sm-6">
                    <div class="enhanced-card industry-card">
                        <h5>🏗️ <?= $translations['construction_trades']; ?></h5>
                        <p><?= $translations['construction_trades_desc']; ?></p>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="enhanced-card industry-card">
                        <h5>📢 <?= $translations['marketing_advertising']; ?></h5>
                        <p><?= $translations['marketing_advertising_desc']; ?></p>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="enhanced-card industry-card">
                        <h5>🌍 <?= $translations['charities_nonprofits']; ?></h5>
                        <p><?= $translations['charities_nonprofits_desc']; ?></p>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="enhanced-card industry-card">
                        <h5>🏘️ <?= $translations['property_housing']; ?></h5>
                        <p><?= $translations['property_housing_desc']; ?></p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="cta-section fade-in">
        <div class="container">
            <h4><?= $translations['about_cta']; ?></h4>
            <a href="services.php" class="btn-enhanced">
                <i class="bi bi-grid-3x3-gap"></i>
                <?= $translations['discover_our_services']; ?>
            </a>
        </div>
    </section>

</div>

<script>
// Enhanced scroll animations
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
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
</body>
</html>