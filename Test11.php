<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>CaminhoIT – Smart, Sustainable IT & Web Solutions</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Google Font -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- CaminhoIT custom styles -->
    <!-- adjust path if you put the CSS somewhere else -->
    <link rel="stylesheet" href="Test11.css">
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar navbar-expand-lg c-nav sticky-top">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2" href="/">
            <img src="https://caminhoit.com/assets/logo.png" alt="CaminhoIT Logo" class="c-logo-img" style="height:32px;">
            <span class="c-brand-text">CAMINHOIT</span>
        </a>


        <button class="navbar-toggler border-0 text-white" type="button" data-bs-toggle="collapse"
                data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false"
                aria-label="Toggle navigation">
            <i class="bi bi-list fs-1"></i>
        </button>

        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav ms-auto mb-2 mb-lg-0 align-items-lg-center c-nav-links">
                <li class="nav-item">
                    <a class="nav-link active" href="#it">IT Solutions</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#web">Web Solutions</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#why">Why CaminhoIT</a>
                </li>
                <li class="nav-item ms-lg-3 mt-2 mt-lg-0">
                    <a class="btn c-btn-nav" href="/book.php">
                        <i class="bi bi-calendar-check me-1"></i>
                        Book Consultation
                    </a>
                </li>
                
                <button id="themeToggle" class="btn c-btn-nav ms-2">
                    <i class="bi bi-moon-stars" id="themeIcon"></i>
                </button>
            </ul>
        </div>
    </div>
</nav>

<!-- HERO -->
<header class="hero">
    <div class="hero-gradient"></div>

    <div class="container position-relative">
        <div class="row align-items-center">
            <div class="col-lg-7">
                <div class="hero-eyebrow">
                    <i class="bi bi-rocket-takeoff-fill"></i>
                    Smart, sustainable managed IT
                </div>

                <h1 class="hero-title">
                    <span class="hero-title-line">
                        Empowering Your
                    </span>
                    <span class="hero-title-line hero-title-highlight">
                        Business Through Smart,
                    </span>
                    <span class="hero-title-line hero-title-highlight">
                        Sustainable IT &amp; Web
                        <span class="hero-title-highlight-tail"></span>
                    </span>
                    <span class="hero-title-line">
                        Solutions
                    </span>
                </h1>

                <p class="hero-subtitle">
                    CaminhoIT blends modern cloud platforms, automation, and ESG-aware thinking to keep your
                    business secure, resilient, and ready for what’s next.
                </p>

                <div class="hero-cta d-flex flex-wrap align-items-center gap-2">
                    <a href="/book.php" class="btn c-btn-primary">
                        <i class="bi bi-calendar-week me-1"></i>
                        Get Started with a Consultation
                    </a>
                    <a href="#it" class="btn c-btn-ghost">
                        Explore Services
                    </a>
                </div>

                <div class="hero-meta">
                    <span><i class="bi bi-patch-check-fill"></i> Managed IT, Cloud &amp; Web</span>
                    <span><i class="bi bi-geo-alt"></i> Built around UK &amp; PT businesses</span>
                </div>
            </div>

            <!-- Snapshot card -->
            <div class="col-lg-5 mt-5 mt-lg-0 d-none d-lg-block">
                <div class="snapshot-card">
                    <!---<div class="snapshot-notch"></div>--->
                    <div class="snapshot-header">
                        <span class="snapshot-label">Snapshot of what we do</span>
                       <!--- <span class="snapshot-pill">
                            <i class="bi bi-heart-pulse-fill me-1"></i>
                            5-minute IT health check
                        </span>--->
                    </div>

                    <div class="snapshot-body">
                        <div class="snapshot-metric">
                            <span class="snapshot-metric-main">24/7</span>
                            <span class="snapshot-metric-sub">proactive monitoring</span>
                        </div>

                        <ul class="snapshot-list">
                            <li>
                                <i class="bi bi-shield-check"></i>
                                Cyber hygiene, audits &amp; realistic security controls.
                            </li>
                            <li>
                                <i class="bi bi-cloud-check"></i>
                                Cloud-first setups across Microsoft 365 &amp; Azure.
                            </li>
                            <li>
                                <i class="bi bi-diagram-3"></i>
                                Integrations that remove manual admin, not add more.
                            </li>
                        </ul>

                        <a href="/health-check.php" class="snapshot-cta">
                            Start 5-minute IT health check
                            <i class="bi bi-arrow-right-short"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>

<main>

    <!-- IT SOLUTIONS -->
    <section id="it" class="section section-soft">
        <div class="container">
            <div class="section-header">
                <div class="section-eyebrow">IT Solutions</div>
                <h2 class="section-title">
                    Comprehensive, proactive IT for growing teams
                </h2>
                <p class="section-subtitle">
                    From day-to-day support to long-term strategy, we keep your tech fast, secure, and sustainable.
                </p>
            </div>

            <div class="row g-4 align-items-stretch">
                <div class="col-lg-7">
                    <!-- Managed IT -->
                    <article class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-laptop"></i>
                        </div>
                        <div class="feature-content">
                            <h3 class="feature-title">Managed IT Support</h3>
                            <p class="feature-text">
                                Always-on monitoring, helpdesk, and endpoint management so your team can just get work done.
                            </p>
                            <div class="feature-meta">
                                <span class="feature-tag">
                                    <i class="bi bi-lightning-charge-fill"></i>
                                    SLA-backed support
                                </span>
                                Remote &amp; on-site options
                            </div>
                        </div>
                    </article>

                    <!-- Cloud -->
                    <article class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-cloud-arrow-up"></i>
                        </div>
                        <div class="feature-content">
                            <h3 class="feature-title">Cloud Services</h3>
                            <p class="feature-text">
                                Microsoft 365, Azure, backups, and identity done properly – secure, governed, and documented.
                            </p>
                            <div class="feature-meta">
                                <span class="feature-tag">
                                    <i class="bi bi-cloud-check"></i>
                                    Cloud-first by design
                                </span>
                                Migrations &amp; optimisation
                            </div>
                        </div>
                    </article>

                    <!-- Cyber -->
                    <article class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-shield-lock"></i>
                        </div>
                        <div class="feature-content">
                            <h3 class="feature-title">Cybersecurity</h3>
                            <p class="feature-text">
                                Practical cyber hygiene, audits, and tooling to reduce risk without slowing people down.
                            </p>
                            <div class="feature-meta">
                                <span class="feature-tag">
                                    <i class="bi bi-bug-fill"></i>
                                    Real-world security
                                </span>
                                Policies, training &amp; tools
                            </div>
                        </div>
                    </article>

                    <!-- Integrations -->
                    <article class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-diagram-3"></i>
                        </div>
                        <div class="feature-content">
                            <h3 class="feature-title">Business Systems &amp; Integrations</h3>
                            <p class="feature-text">
                                Connect your tools, automate workflows, and reduce manual admin with smart integrations.
                            </p>
                            <div class="feature-meta">
                                <span class="feature-tag">
                                    <i class="bi bi-gear-wide-connected"></i>
                                    Automation-first
                                </span>
                                Line-of-business &amp; ERP
                            </div>
                        </div>
                    </article>
                </div>

                <!-- Sustainability card -->
                <div class="col-lg-5">
                    <aside class="panel panel-soft">
                        <div class="panel-chip">
                            <i class="bi bi-tree"></i>
                            Sustainability intelligence
                        </div>
                        <h3 class="panel-title">
                            IT that’s efficient for your team and the planet
                        </h3>
                        <p class="panel-text">
                            We help you make decisions on hardware, cloud, and software that balance performance, cost,
                            and environmental impact — without getting dogmatic or impractical.
                        </p>
                        <ul class="panel-list">
                            <li>
                                <i class="bi bi-check2-circle"></i>
                                Guidance on device lifecycles &amp; cloud usage.
                            </li>
                            <li>
                                <i class="bi bi-check2-circle"></i>
                                Support for ESG reporting and audits.
                            </li>
                            <li>
                                <i class="bi bi-check2-circle"></i>
                                Pragmatic recommendations, not guilt trips.
                            </li>
                        </ul>
                        <a href="/book.php" class="btn c-btn-secondary w-100 mt-3">
                            <i class="bi bi-calendar-event me-1"></i>
                            Talk about your environment
                        </a>
                    </aside>
                </div>
            </div>
        </div>
    </section>

    <!-- WEB SOLUTIONS -->
    <section id="web" class="section">
        <div class="container">
            <div class="section-header">
                <div class="section-eyebrow">Web Solutions</div>
                <h2 class="section-title">
                    Hosting, sites, and systems that feel fast, not fragile
                </h2>
                <p class="section-subtitle">
                    From domains and SSL to full custom portals, we design and host with performance and security baked in.
                </p>
            </div>

            <div class="row g-4">
                <div class="col-md-6 col-lg-3">
                    <article class="pill-card">
                        <div class="pill-icon"><i class="bi bi-globe2"></i></div>
                        <h3 class="pill-title">Domains &amp; DNS</h3>
                        <p class="pill-text">
                            Clean domain setups, DNS management, and naming that makes sense.
                        </p>
                        <span class="pill-meta">Brand-aligned domains</span>
                    </article>
                </div>

                <div class="col-md-6 col-lg-3">
                    <article class="pill-card">
                        <div class="pill-icon"><i class="bi bi-hdd-network"></i></div>
                        <h3 class="pill-title">Business Hosting</h3>
                        <p class="pill-text">
                            Optimised hosting with backups, monitoring, and security tuned for real businesses.
                        </p>
                        <span class="pill-meta">Shared, VPS &amp; cloud</span>
                    </article>
                </div>

                <div class="col-md-6 col-lg-3">
                    <article class="pill-card">
                        <div class="pill-icon"><i class="bi bi-columns-gap"></i></div>
                        <h3 class="pill-title">Websites &amp; Portals</h3>
                        <p class="pill-text">
                            Modern, responsive sites and portals for customers, staff, and partners.
                        </p>
                        <span class="pill-meta">From brochure to SaaS</span>
                    </article>
                </div>

                <div class="col-md-6 col-lg-3">
                    <article class="pill-card">
                        <div class="pill-icon"><i class="bi bi-shield-check"></i></div>
                        <h3 class="pill-title">SSL &amp; Security</h3>
                        <p class="pill-text">
                            TLS/SSL, hardening, and monitoring so your site doesn't become a weekend project.
                        </p>
                        <span class="pill-meta">HTTPS everywhere</span>
                    </article>
                </div>
            </div>
        </div>
    </section>

    <!-- HEALTH CHECK + WHY -->
    <section id="why" class="section section-soft section-bottom-pad">
        <div class="container">
            <div class="row g-4 align-items-start">
                <div class="col-lg-6">
                    <section class="panel panel-gradient">
                        <div class="panel-chip panel-chip-light">
                            <i class="bi bi-heart-pulse-fill"></i>
                            5-minute IT Health Check
                        </div>
                        <h3 class="panel-title">Not sure where to start?</h3>
                        <p class="panel-text">
                            Run a quick, no-signup IT health check. Get a simple, prioritised view of risk, resilience,
                            and next steps — then decide if you want a follow-up call.
                        </p>
                        <ul class="panel-list panel-list-light">
                            <li>
                                <i class="bi bi-check2-circle"></i>
                                Questions on devices, backups, security &amp; cloud.
                            </li>
                            <li>
                                <i class="bi bi-check2-circle"></i>
                                Instant on-screen results, no email required.
                            </li>
                            <li>
                                <i class="bi bi-check2-circle"></i>
                                Optional contact form after you see your results.
                            </li>
                        </ul>

                        <div class="d-grid d-sm-flex gap-2 mt-3">
                            <a href="/health-check.php" class="btn c-btn-dark flex-fill">
                                <i class="bi bi-heart-pulse me-1"></i>
                                Start IT Health Check
                            </a>
                            <a href="/book.php" class="btn c-btn-outline-light flex-fill">
                                Book follow-up
                            </a>
                        </div>
                        <p class="panel-footnote">
                            Takes around 5 minutes. No sales calls unless you actually request one.
                        </p>
                    </section>
                </div>

                <div class="col-lg-6">
                    <header class="section-header mb-3">
                        <div class="section-eyebrow">Why CaminhoIT</div>
                        <h2 class="section-title">
                            Modern MSP thinking, without the drama
                        </h2>
                    </header>
                    <p class="section-subtitle mb-3">
                        We operate like a product team, not a break/fix helpdesk. Clear roadmaps, honest advice, and a
                        pragmatic view on budgets, sustainability, and risk.
                    </p>

                    <ul class="why-list">
                        <li>
                            <i class="bi bi-check2-circle"></i>
                            <span>Root-cause thinking, not just ticket closure.</span>
                        </li>
                        <li>
                            <i class="bi bi-check2-circle"></i>
                            <span>ESG-aware guidance for hardware, cloud, and software.</span>
                        </li>
                        <li>
                            <i class="bi bi-check2-circle"></i>
                            <span>UK &amp; Portugal footprint for flexible, remote-first support.</span>
                        </li>
                        <li>
                            <i class="bi bi-check2-circle"></i>
                            <span>Clear communication — no buzzword bingo, just straight answers.</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

</main>

<!-- FOOTER -->
<footer class="c-footer">
    <div class="footer-gradient"></div>
    <div class="container position-relative">
        <div class="row gy-4 align-items-start">
            <div class="col-md-4">
                <div class="footer-logo">CaminhoIT</div>
                <p class="footer-text">
                    Smart, sustainable IT &amp; web solutions for modern organisations across the UK and Portugal.
                </p>
                <small class="footer-meta">
                    © 2025 CaminhoIT. All rights reserved.
                </small>
            </div>

            <div class="col-6 col-md-4">
                <div class="footer-heading">Legal</div>
                <ul class="footer-links">
                    <li><a href="/privacy-policy.php">Privacy Policy</a></li>
                    <li><a href="/terms.php">Terms of Service</a></li>
                    <li><a href="/security.php">Security &amp; Compliance</a></li>
                    <li><a href="/gdpr.php">GDPR</a></li>
                </ul>
            </div>

            <div class="col-6 col-md-4">
                <div class="footer-heading">Contact</div>
                <ul class="footer-links">
                    <li>
                        <a href="mailto:support@caminhoit.com">
                            <i class="bi bi-envelope me-1"></i>
                            support@caminhoit.com
                        </a>
                    </li>
                    <li>
                        <a href="https://wa.me/351963452653" target="_blank" rel="noopener">
                            <i class="bi bi-whatsapp me-1"></i>
                            WhatsApp Support
                        </a>
                    </li>
                </ul>
                <small class="footer-meta">
                    Available 24/7 for contracted clients.
                </small>
            </div>
        </div>
    </div>
</footer>

<!-- Floating Health Check CTA – desktop only -->
<div class="health-floating-cta d-none d-lg-block">
    <a href="/health-check.php" class="health-floating-pill">
        <i class="bi bi-heart-pulse-fill"></i>
        <span>
            Start IT Health Check
            <span class="health-floating-sub">No signup • ~5 mins</span>
        </span>
    </a>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener("scroll", function() {
    const nav = document.querySelector('.c-nav');
    if (window.scrollY > 20) {
        nav.classList.add('scrolled');
    } else {
        nav.classList.remove('scrolled');
    }
});
</script>

<script>
document.addEventListener("DOMContentLoaded", () => {
    const toggle = document.getElementById("themeToggle");
    const icon = document.getElementById("themeIcon");
    const root = document.documentElement;

    // Load saved theme
    const savedTheme = localStorage.getItem("theme");
    if (savedTheme === "dark") {
        root.classList.add("dark");
        icon.classList.remove("bi-moon-stars");
        icon.classList.add("bi-sun-fill");
    }

    toggle.addEventListener("click", () => {
        root.classList.toggle("dark");

        // swap icons
        icon.classList.toggle("bi-moon-stars");
        icon.classList.toggle("bi-sun-fill");

        // save theme
        localStorage.setItem("theme", root.classList.contains("dark") ? "dark" : "light");
    });
});
</script>

</body>
</html>
