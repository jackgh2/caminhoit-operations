<?php
/**
 * SEO Example Page
 * This demonstrates how to implement all SEO features
 * Copy this approach to other pages
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/SEOHelper.php';

// Initialize SEO
$seo = new SEOHelper();
$seo->setTitle('SEO Example Page | CaminhoIT')
    ->setDescription('This is an example of how to implement SEO on CaminhoIT pages. Includes meta tags, structured data, and AI optimization.')
    ->setKeywords('SEO example, meta tags, structured data, search optimization')
    ->setType('website');

// Example breadcrumbs for this page
$breadcrumbs = [
    ['name' => 'Home', 'url' => 'https://caminhoit.com'],
    ['name' => 'SEO Example']
];

// Example FAQs - these will show in Google as rich snippets!
$faqs = [
    [
        'question' => 'What IT services does CaminhoIT provide?',
        'answer' => 'We provide comprehensive IT solutions including cloud services, cybersecurity, managed IT support, infrastructure management, 24/7 technical support, and IT consulting for businesses in Portugal and UK.'
    ],
    [
        'question' => 'Do you offer 24/7 support?',
        'answer' => 'Yes! We provide round-the-clock IT support to ensure your business operations run smoothly at all times. Our support team is available via phone, email, and our online portal.'
    ],
    [
        'question' => 'What are your service areas?',
        'answer' => 'We primarily serve businesses in Portugal and the United Kingdom, with a strong presence in Lisbon and London. We also provide remote IT support services globally.'
    ],
    [
        'question' => 'How can I get started with CaminhoIT?',
        'answer' => 'You can get started by contacting us through our contact form, calling +351 963 452 653, or emailing support@caminhoit.com. We offer free consultations to understand your IT needs.'
    ]
];

$page_title = 'SEO Example | CaminhoIT';
?>
<!DOCTYPE html>
<html lang="<?= $lang; ?>">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($page_title) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- ============================================ -->
    <!-- SEO META TAGS (OpenGraph, Twitter, etc) -->
    <!-- ============================================ -->
    <?= $seo->renderMetaTags() ?>

    <!-- ============================================ -->
    <!-- STRUCTURED DATA (JSON-LD) -->
    <!-- ============================================ -->

    <!-- Organization Schema (shows business info in Google) -->
    <?= $seo->renderOrganizationSchema() ?>

    <!-- Local Business Schema (helps local SEO) -->
    <?= $seo->renderLocalBusinessSchema() ?>

    <!-- Breadcrumb Schema (shows navigation path in search) -->
    <?= $seo->renderBreadcrumbSchema($breadcrumbs) ?>

    <!-- FAQ Schema (creates FAQ rich snippets in Google!) -->
    <?= $seo->renderFAQSchema($faqs) ?>

    <!-- CSS -->
    <link rel="stylesheet" href="/assets/styles.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body {
            background-color: #f8fafc;
        }
        .seo-box {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .seo-box h2 {
            color: #667eea;
            border-bottom: 2px solid #667eea;
            padding-bottom: 0.5rem;
            margin-bottom: 1.5rem;
        }
        .check-item {
            padding: 0.5rem 0;
        }
        .check-item i {
            color: #10b981;
            margin-right: 0.5rem;
        }
        code {
            background: #f3f4f6;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.9em;
        }
        .badge-seo {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            display: inline-block;
            margin: 0.25rem;
        }
    </style>
</head>
<body>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/nav.php'; ?>

<header class="hero-enhanced">
    <div class="container">
        <div class="hero-content-enhanced">
            <h1 class="hero-title-enhanced text-white">
                <i class="bi bi-search me-3"></i>
                SEO Implementation Example
            </h1>
            <p class="hero-subtitle-enhanced text-white">
                This page demonstrates all SEO features for better search rankings
            </p>
        </div>
    </div>
</header>

<div class="container py-5" style="margin-top: -60px; position: relative; z-index: 10;">
    <div class="row">
        <div class="col-12">

            <!-- What's Implemented -->
            <div class="seo-box">
                <h2><i class="bi bi-check-circle me-2"></i>SEO Features Implemented</h2>
                <div class="row">
                    <div class="col-md-6">
                        <div class="check-item">
                            <i class="bi bi-check-circle-fill"></i> Meta title & description
                        </div>
                        <div class="check-item">
                            <i class="bi bi-check-circle-fill"></i> Keywords optimization
                        </div>
                        <div class="check-item">
                            <i class="bi bi-check-circle-fill"></i> OpenGraph tags (Facebook/LinkedIn)
                        </div>
                        <div class="check-item">
                            <i class="bi bi-check-circle-fill"></i> Twitter Card tags
                        </div>
                        <div class="check-item">
                            <i class="bi bi-check-circle-fill"></i> Canonical URLs
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="check-item">
                            <i class="bi bi-check-circle-fill"></i> Organization Schema (JSON-LD)
                        </div>
                        <div class="check-item">
                            <i class="bi bi-check-circle-fill"></i> Local Business Schema
                        </div>
                        <div class="check-item">
                            <i class="bi bi-check-circle-fill"></i> Breadcrumb Schema
                        </div>
                        <div class="check-item">
                            <i class="bi bi-check-circle-fill"></i> FAQ Schema (rich snippets!)
                        </div>
                        <div class="check-item">
                            <i class="bi bi-check-circle-fill"></i> AI/ChatGPT optimization
                        </div>
                    </div>
                </div>
            </div>

            <!-- Search Engine Visibility -->
            <div class="seo-box">
                <h2><i class="bi bi-globe me-2"></i>Search Engine & AI Platform Visibility</h2>
                <p>This website is now optimized for:</p>
                <div class="text-center mb-3">
                    <span class="badge-seo"><i class="bi bi-google me-2"></i>Google</span>
                    <span class="badge-seo"><i class="bi bi-microsoft me-2"></i>Bing</span>
                    <span class="badge-seo"><i class="bi bi-robot me-2"></i>ChatGPT</span>
                    <span class="badge-seo"><i class="bi bi-stars me-2"></i>Claude AI</span>
                    <span class="badge-seo"><i class="bi bi-lightbulb me-2"></i>Google Bard/Gemini</span>
                    <span class="badge-seo"><i class="bi bi-facebook me-2"></i>Facebook</span>
                    <span class="badge-seo"><i class="bi bi-linkedin me-2"></i>LinkedIn</span>
                    <span class="badge-seo"><i class="bi bi-twitter me-2"></i>Twitter</span>
                </div>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>robots.txt</strong> is configured to allow all AI crawlers while protecting sensitive areas.
                </div>
            </div>

            <!-- FAQ Section (SEO Gold!) -->
            <div class="seo-box">
                <h2><i class="bi bi-question-circle me-2"></i>Frequently Asked Questions</h2>
                <p class="text-muted mb-4">These FAQs are marked up with Schema.org structured data, which means they can appear as rich snippets in Google search results!</p>

                <div class="accordion" id="faqAccordion">
                    <?php foreach ($faqs as $index => $faq): ?>
                        <div class="accordion-item">
                            <h3 class="accordion-header">
                                <button class="accordion-button <?= $index > 0 ? 'collapsed' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#faq<?= $index ?>">
                                    <?= htmlspecialchars($faq['question']) ?>
                                </button>
                            </h3>
                            <div id="faq<?= $index ?>" class="accordion-collapse collapse <?= $index === 0 ? 'show' : '' ?>" data-bs-parent="#faqAccordion">
                                <div class="accordion-body">
                                    <?= htmlspecialchars($faq['answer']) ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Technical Details -->
            <div class="seo-box">
                <h2><i class="bi bi-code-slash me-2"></i>Technical Implementation</h2>

                <h4 class="mt-4">1. SEO Helper Class</h4>
                <p>Located at: <code>/includes/SEOHelper.php</code></p>
                <pre class="bg-light p-3 rounded"><code>$seo = new SEOHelper();
$seo->setTitle('Your Page Title')
    ->setDescription('Your page description')
    ->setKeywords('keyword1, keyword2, keyword3');</code></pre>

                <h4 class="mt-4">2. XML Sitemap</h4>
                <p>
                    Dynamic sitemap available at: <code>https://caminhoit.com/sitemap.xml.php</code><br>
                    <a href="/sitemap.xml.php" target="_blank" class="btn btn-sm btn-outline-primary mt-2">
                        <i class="bi bi-box-arrow-up-right me-2"></i>View Sitemap
                    </a>
                </p>

                <h4 class="mt-4">3. Robots.txt</h4>
                <p>
                    Crawler instructions at: <code>https://caminhoit.com/robots.txt</code><br>
                    <a href="/robots.txt" target="_blank" class="btn btn-sm btn-outline-primary mt-2">
                        <i class="bi bi-box-arrow-up-right me-2"></i>View Robots.txt
                    </a>
                </p>

                <h4 class="mt-4">4. Implementation Guide</h4>
                <p>
                    Full documentation: <code>/SEO-IMPLEMENTATION-GUIDE.md</code><br>
                    Contains step-by-step instructions for all pages
                </p>
            </div>

            <!-- Next Steps -->
            <div class="seo-box">
                <h2><i class="bi bi-list-check me-2"></i>Next Steps for Maximum SEO Impact</h2>
                <ol class="list-group list-group-numbered">
                    <li class="list-group-item">
                        <strong>Submit Sitemap to Google Search Console</strong><br>
                        <small class="text-muted">Visit <a href="https://search.google.com/search-console" target="_blank">search.google.com/search-console</a></small>
                    </li>
                    <li class="list-group-item">
                        <strong>Submit Sitemap to Bing Webmaster Tools</strong><br>
                        <small class="text-muted">Visit <a href="https://www.bing.com/webmasters" target="_blank">bing.com/webmasters</a></small>
                    </li>
                    <li class="list-group-item">
                        <strong>Create Google Business Profile</strong><br>
                        <small class="text-muted">Essential for local SEO in Portugal & UK</small>
                    </li>
                    <li class="list-group-item">
                        <strong>Add FAQ sections to key pages</strong><br>
                        <small class="text-muted">Homepage, services, pricing pages</small>
                    </li>
                    <li class="list-group-item">
                        <strong>Optimize all images with alt text</strong><br>
                        <small class="text-muted">Improves accessibility and SEO</small>
                    </li>
                    <li class="list-group-item">
                        <strong>Start blogging</strong><br>
                        <small class="text-muted">2-4 articles per month about IT topics</small>
                    </li>
                    <li class="list-group-item">
                        <strong>Get quality backlinks</strong><br>
                        <small class="text-muted">Business directories, partnerships, guest posts</small>
                    </li>
                    <li class="list-group-item">
                        <strong>Monitor with Google Analytics</strong><br>
                        <small class="text-muted">Track traffic, rankings, conversions</small>
                    </li>
                </ol>
            </div>

            <!-- Contact CTA -->
            <div class="seo-box text-center" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                <h2 class="text-white border-0">Ready to Boost Your Rankings?</h2>
                <p class="lead">Contact CaminhoIT for expert IT solutions and support</p>
                <div class="mt-4">
                    <a href="/contact.php" class="btn btn-light btn-lg me-2">
                        <i class="bi bi-envelope me-2"></i>Contact Us
                    </a>
                    <a href="tel:+351963452653" class="btn btn-outline-light btn-lg">
                        <i class="bi bi-telephone me-2"></i>+351 963 452 653
                    </a>
                </div>
            </div>

        </div>
    </div>
</div>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
