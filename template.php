<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';

$page_title = $translations['site_title'] ?? 'CaminhoIT – Smart, Sustainable IT & Web Solutions';
?>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2.php'; ?>

<!-- HERO -->
<header class="hero">
    <div class="hero-gradient"></div>

    <div class="container position-relative">
        <div class="row align-items-center">
            <div class="col-lg-7">
                <div class="hero-eyebrow">
                    <i class="bi bi-rocket-takeoff-fill"></i>
                    <?= $translations['hero_smart_sustainable'] ?? 'Smart, sustainable managed IT'; ?>
                </div>

                <h1 class="hero-title">
                    <span class="hero-title-line">
                        <?= $translations['hero_empowering'] ?? 'Empowering Your'; ?>
                    </span>
                    <span class="hero-title-line hero-title-highlight">
                        <?= $translations['hero_business_through'] ?? 'Business Through Smart,'; ?>
                    </span>
                    <span class="hero-title-line hero-title-highlight">
                        <?= $translations['hero_sustainable'] ?? 'Sustainable IT & Web'; ?>
                        <span class="hero-title-highlight-tail"></span>
                    </span>
                    <span class="hero-title-line">
                        <?= $translations['hero_solutions'] ?? 'Solutions'; ?>
                    </span>
                </h1>

                <p class="hero-subtitle">
                    <?= $translations['hero_subtitle'] ?? 'CaminhoIT blends modern cloud platforms, automation, and ESG-aware thinking to keep your business secure, resilient, and ready for what\'s next.'; ?>
                </p>

                <div class="hero-cta d-flex flex-wrap align-items-center gap-2">
                    <a href="/book.php" class="btn c-btn-primary">
                        <i class="bi bi-calendar-week me-1"></i>
                        <?= $translations['get_started_consultation'] ?? 'Get Started with a Consultation'; ?>
                    </a>
                    <a href="#it" class="btn c-btn-ghost">
                        <?= $translations['explore_services'] ?? 'Explore Services'; ?>
                    </a>
                </div>

                <div class="hero-meta">
                    <span><i class="bi bi-patch-check-fill"></i> <?= $translations['managed_it_cloud_web'] ?? 'Managed IT, Cloud & Web'; ?></span>
                    <span><i class="bi bi-geo-alt"></i> <?= $translations['built_uk_pt'] ?? 'Built around UK & PT businesses'; ?></span>
                </div>
            </div>

            <!-- Snapshot card -->
            <div class="col-lg-5 mt-5 mt-lg-0 d-none d-lg-block">
                <div class="snapshot-card">
                    <div class="snapshot-header">
                        <span class="snapshot-label"><?= $translations['snapshot_label'] ?? 'Snapshot of what we do'; ?></span>
                    </div>

                    <div class="snapshot-body">
                        <div class="snapshot-metric">
                            <span class="snapshot-metric-main">24/7</span>
                            <span class="snapshot-metric-sub"><?= $translations['proactive_monitoring'] ?? 'proactive monitoring'; ?></span>
                        </div>

                        <ul class="snapshot-list">
                            <li>
                                <i class="bi bi-shield-check"></i>
                                <?= $translations['cyber_hygiene'] ?? 'Cyber hygiene, audits & realistic security controls.'; ?>
                            </li>
                            <li>
                                <i class="bi bi-cloud-check"></i>
                                <?= $translations['cloud_first'] ?? 'Cloud-first setups across Microsoft 365 & Azure.'; ?>
                            </li>
                            <li>
                                <i class="bi bi-diagram-3"></i>
                                <?= $translations['integrations'] ?? 'Integrations that remove manual admin, not add more.'; ?>
                            </li>
                        </ul>

                        <a href="/health-check.php" class="snapshot-cta">
                            <?= $translations['start_5min_check'] ?? 'Start 5-minute IT health check'; ?>
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
                <div class="section-eyebrow"><?= $translations['it_solutions'] ?? 'IT Solutions'; ?></div>
                <h2 class="section-title">
                    <?= $translations['comprehensive_proactive'] ?? 'Comprehensive, proactive IT for growing teams'; ?>
                </h2>
                <p class="section-subtitle">
                    <?= $translations['day_to_day_support'] ?? 'From day-to-day support to long-term strategy, we keep your tech fast, secure, and sustainable.'; ?>
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
                            <h3 class="feature-title"><?= $translations['managed_it_title'] ?? 'Managed IT Support'; ?></h3>
                            <p class="feature-text">
                                <?= $translations['managed_it'] ?? 'Always-on monitoring, helpdesk, and endpoint management so your team can just get work done.'; ?>
                            </p>
                            <div class="feature-meta">
                                <span class="feature-tag">
                                    <i class="bi bi-lightning-charge-fill"></i>
                                    <?= $translations['sla_backed'] ?? 'SLA-backed support'; ?>
                                </span>
                                <?= $translations['remote_onsite'] ?? 'Remote & on-site options'; ?>
                            </div>
                        </div>
                    </article>

                    <!-- Cloud -->
                    <article class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-cloud-arrow-up"></i>
                        </div>
                        <div class="feature-content">
                            <h3 class="feature-title"><?= $translations['cloud_services_title'] ?? 'Cloud Services'; ?></h3>
                            <p class="feature-text">
                                <?= $translations['cloud_services'] ?? 'Microsoft 365, Azure, backups, and identity done properly – secure, governed, and documented.'; ?>
                            </p>
                            <div class="feature-meta">
                                <span class="feature-tag">
                                    <i class="bi bi-cloud-check"></i>
                                    <?= $translations['cloud_first_design'] ?? 'Cloud-first by design'; ?>
                                </span>
                                <?= $translations['migrations_optimisation'] ?? 'Migrations & optimisation'; ?>
                            </div>
                        </div>
                    </article>

                    <!-- Cyber -->
                    <article class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-shield-lock"></i>
                        </div>
                        <div class="feature-content">
                            <h3 class="feature-title"><?= $translations['cybersecurity_title'] ?? 'Cybersecurity'; ?></h3>
                            <p class="feature-text">
                                <?= $translations['cybersecurity'] ?? 'Practical cyber hygiene, audits, and tooling to reduce risk without slowing people down.'; ?>
                            </p>
                            <div class="feature-meta">
                                <span class="feature-tag">
                                    <i class="bi bi-bug-fill"></i>
                                    <?= $translations['real_world_security'] ?? 'Real-world security'; ?>
                                </span>
                                <?= $translations['policies_training_tools'] ?? 'Policies, training & tools'; ?>
                            </div>
                        </div>
                    </article>

                    <!-- Integrations -->
                    <article class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-diagram-3"></i>
                        </div>
                        <div class="feature-content">
                            <h3 class="feature-title"><?= $translations['business_systems_title'] ?? 'Business Systems & Integrations'; ?></h3>
                            <p class="feature-text">
                                <?= $translations['business_systems'] ?? 'Connect your tools, automate workflows, and reduce manual admin with smart integrations.'; ?>
                            </p>
                            <div class="feature-meta">
                                <span class="feature-tag">
                                    <i class="bi bi-gear-wide-connected"></i>
                                    <?= $translations['automation_first'] ?? 'Automation-first'; ?>
                                </span>
                                <?= $translations['line_of_business'] ?? 'Line-of-business & ERP'; ?>
                            </div>
                        </div>
                    </article>
                </div>

                <!-- Sustainability card -->
                <div class="col-lg-5">
                    <aside class="panel panel-soft">
                        <div class="panel-chip">
                            <i class="bi bi-tree"></i>
                            <?= $translations['sustainability_intelligence_title'] ?? 'ESG 2030 & Sustainability'; ?>
                        </div>
                        <h3 class="panel-title">
                            <?= $translations['it_efficient'] ?? 'IT that\'s efficient for your team and the planet'; ?>
                        </h3>
                        <p class="panel-text">
                            <?= $translations['it_efficient_desc'] ?? 'We help you make decisions on hardware, cloud, and software that balance performance, cost, and environmental impact — without getting dogmatic or impractical.'; ?>
                        </p>
                        <ul class="panel-list">
                            <li>
                                <i class="bi bi-check2-circle"></i>
                                <?= $translations['device_lifecycles'] ?? 'Guidance on device lifecycles & cloud usage.'; ?>
                            </li>
                            <li>
                                <i class="bi bi-check2-circle"></i>
                                <?= $translations['esg_reporting'] ?? 'Support for ESG reporting and audits.'; ?>
                            </li>
                            <li>
                                <i class="bi bi-check2-circle"></i>
                                <?= $translations['pragmatic_recommendations'] ?? 'Pragmatic recommendations, not guilt trips.'; ?>
                            </li>
                        </ul>
                        <a href="/sustainability.php" class="btn c-btn-secondary w-100 mt-3">
                            <i class="bi bi-calendar-event me-1"></i>
                            <?= $translations['talk_environment'] ?? 'Talk about your environment'; ?>
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
                <div class="section-eyebrow"><?= $translations['web_solutions'] ?? 'Web Solutions'; ?></div>
                <h2 class="section-title">
                    <?= $translations['hosting_sites_systems'] ?? 'Hosting, sites, and systems that feel fast, not fragile'; ?>
                </h2>
                <p class="section-subtitle">
                    <?= $translations['domains_ssl_portals'] ?? 'From domains and SSL to full custom portals, we design and host with performance and security baked in.'; ?>
                </p>
            </div>

            <div class="row g-4">
                <div class="col-md-6 col-lg-3">
                    <article class="pill-card">
                        <div class="pill-icon"><i class="bi bi-globe2"></i></div>
                        <h3 class="pill-title"><?= $translations['domains_title'] ?? 'Domains & DNS'; ?></h3>
                        <p class="pill-text">
                            <?= $translations['domains'] ?? 'Clean domain setups, DNS management, and naming that makes sense.'; ?>
                        </p>
                        <span class="pill-meta"><?= $translations['brand_aligned'] ?? 'Brand-aligned domains'; ?></span>
                    </article>
                </div>

                <div class="col-md-6 col-lg-3">
                    <article class="pill-card">
                        <div class="pill-icon"><i class="bi bi-hdd-network"></i></div>
                        <h3 class="pill-title"><?= $translations['hosting_title'] ?? 'Business Hosting'; ?></h3>
                        <p class="pill-text">
                            <?= $translations['hosting'] ?? 'Optimised hosting with backups, monitoring, and security tuned for real businesses.'; ?>
                        </p>
                        <span class="pill-meta"><?= $translations['shared_vps_cloud'] ?? 'Shared, VPS & cloud'; ?></span>
                    </article>
                </div>

                <div class="col-md-6 col-lg-3">
                    <article class="pill-card">
                        <div class="pill-icon"><i class="bi bi-columns-gap"></i></div>
                        <h3 class="pill-title"><?= $translations['websites_portals'] ?? 'Websites & Portals'; ?></h3>
                        <p class="pill-text">
                            <?= $translations['websites_portals_desc'] ?? 'Modern, responsive sites and portals for customers, staff, and partners.'; ?>
                        </p>
                        <span class="pill-meta"><?= $translations['brochure_to_saas'] ?? 'From brochure to SaaS'; ?></span>
                    </article>
                </div>

                <div class="col-md-6 col-lg-3">
                    <article class="pill-card">
                        <div class="pill-icon"><i class="bi bi-shield-check"></i></div>
                        <h3 class="pill-title"><?= $translations['ssl_title'] ?? 'SSL & Security'; ?></h3>
                        <p class="pill-text">
                            <?= $translations['ssl'] ?? 'TLS/SSL, hardening, and monitoring so your site doesn\'t become a weekend project.'; ?>
                        </p>
                        <span class="pill-meta"><?= $translations['https_everywhere'] ?? 'HTTPS everywhere'; ?></span>
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
                            <?= $translations['5min_health_check'] ?? '5-minute IT Health Check'; ?>
                        </div>
                        <h3 class="panel-title"><?= $translations['not_sure_where_start'] ?? 'Not sure where to start?'; ?></h3>
                        <p class="panel-text">
                            <?= $translations['health_check_desc'] ?? 'Run a quick, no-signup IT health check. Get a simple, prioritised view of risk, resilience, and next steps — then decide if you want a follow-up call.'; ?>
                        </p>
                        <ul class="panel-list panel-list-light">
                            <li>
                                <i class="bi bi-check2-circle"></i>
                                <?= $translations['questions_devices'] ?? 'Questions on devices, backups, security & cloud.'; ?>
                            </li>
                            <li>
                                <i class="bi bi-check2-circle"></i>
                                <?= $translations['instant_results'] ?? 'Instant on-screen results, no email required.'; ?>
                            </li>
                            <li>
                                <i class="bi bi-check2-circle"></i>
                                <?= $translations['optional_contact'] ?? 'Optional contact form after you see your results.'; ?>
                            </li>
                        </ul>

                        <div class="d-grid d-sm-flex gap-2 mt-3">
                            <a href="/health-check.php" class="btn c-btn-dark flex-fill">
                                <i class="bi bi-heart-pulse me-1"></i>
                                <?= $translations['start_health_check'] ?? 'Start IT Health Check'; ?>
                            </a>
                            <a href="/book.php" class="btn c-btn-outline-light flex-fill">
                                <?= $translations['book_followup'] ?? 'Book follow-up'; ?>
                            </a>
                        </div>
                        <p class="panel-footnote">
                            <?= $translations['takes_5mins'] ?? 'Takes around 5 minutes. No sales calls unless you actually request one.'; ?>
                        </p>
                    </section>
                </div>

                <div class="col-lg-6">
                    <header class="section-header mb-3">
                        <div class="section-eyebrow"><?= $translations['why_caminhoit'] ?? 'Why CaminhoIT'; ?></div>
                        <h2 class="section-title">
                            <?= $translations['modern_msp'] ?? 'Modern MSP thinking, without the drama'; ?>
                        </h2>
                    </header>
                    <p class="section-subtitle mb-3">
                        <?= $translations['operate_product_team'] ?? 'We operate like a product team, not a break/fix helpdesk. Clear roadmaps, honest advice, and a pragmatic view on budgets, sustainability, and risk.'; ?>
                    </p>

                    <ul class="why-list">
                        <li>
                            <i class="bi bi-check2-circle"></i>
                            <span><?= $translations['root_cause'] ?? 'Root-cause thinking, not just ticket closure.'; ?></span>
                        </li>
                        <li>
                            <i class="bi bi-check2-circle"></i>
                            <span><?= $translations['esg_aware'] ?? 'ESG-aware guidance for hardware, cloud, and software.'; ?></span>
                        </li>
                        <li>
                            <i class="bi bi-check2-circle"></i>
                            <span><?= $translations['uk_portugal'] ?? 'UK & Portugal footprint for flexible, remote-first support.'; ?></span>
                        </li>
                        <li>
                            <i class="bi bi-check2-circle"></i>
                            <span><?= $translations['clear_communication'] ?? 'Clear communication — no buzzword bingo, just straight answers.'; ?></span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

</main>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-v2.php'; ?>
