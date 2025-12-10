<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';


$page_title = "Template Page | CaminhoIT Admin";
?>
<!DOCTYPE html>
<html lang="<?= $lang; ?>">
<head>
    <meta charset="UTF-8">
    <title><?= $page_title; ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Administrative template page for CaminhoIT system management">
    
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

        .hero-btn-outline {
            background: transparent;
            color: white;
            border-color: rgba(255,255,255,0.5);
        }

        .hero-btn-outline:hover {
            background: white;
            color: #667eea;
            border-color: white;
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

        .card-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 1.5rem;
            color: white;
        }

        .card-icon.primary { background: var(--primary-gradient); }
        .card-icon.success { background: var(--success-gradient); }
        .card-icon.warning { background: var(--warning-gradient); }
        .card-icon.info { background: var(--info-gradient); }

        /* Content Sections */
        .content-section {
            padding: 3rem 0;
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

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
            margin-bottom: 3rem;
        }

        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 2rem;
            text-align: center;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: var(--primary-gradient);
            opacity: 0;
            transition: var(--transition);
        }

        .stat-card:hover::before {
            opacity: 0.05;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--card-shadow-hover);
        }

        .stat-number {
            font-size: 3rem;
            font-weight: 700;
            color: #667eea;
            margin-bottom: 0.5rem;
            position: relative;
            z-index: 1;
        }

        .stat-label {
            color: #6c757d;
            font-weight: 600;
            position: relative;
            z-index: 1;
        }

        /* Feature Grid */
        .feature-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
        }

        .feature-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 2rem;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            text-align: center;
        }

        .feature-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--card-shadow-hover);
        }

        .feature-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            margin: 0 auto 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: white;
            background: var(--primary-gradient);
        }

        .feature-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: #2c3e50;
        }

        .feature-description {
            color: #6c757d;
            line-height: 1.6;
        }

        /* Breadcrumb Enhancement */
        .breadcrumb-enhanced {
            background: rgba(255,255,255,0.9);
            backdrop-filter: blur(10px);
            border-radius: var(--border-radius);
            padding: 1rem 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        /* Loading States */
        .loading-skeleton {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: loading 1.5s infinite;
        }

        @keyframes loading {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }

        /* Responsive Enhancements */
        @media (max-width: 768px) {
            .hero-title-enhanced {
                font-size: 2.5rem;
            }
            
            .hero-actions {
                flex-direction: column;
                align-items: center;
            }
            
            .section-title {
                font-size: 2rem;
            }
            
            .stats-grid,
            .feature-grid {
                grid-template-columns: 1fr;
            }
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

        /* Enhanced Buttons */
        .btn-enhanced {
            border-radius: 50px;
            padding: 0.75rem 2rem;
            font-weight: 600;
            transition: var(--transition);
            border: none;
            position: relative;
            overflow: hidden;
        }

        .btn-enhanced::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            transition: all 0.3s ease;
            transform: translate(-50%, -50%);
        }

        .btn-enhanced:hover::before {
            width: 300px;
            height: 300px;
        }

        .btn-enhanced span {
            position: relative;
            z-index: 1;
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
                <i class="bi bi-gear-fill me-3"></i>
                Admin Template
            </h1>
            <p class="hero-subtitle-enhanced text-white">
                A comprehensive administrative interface template with modern design patterns and enhanced user experience.
            </p>
            <div class="hero-actions">
                <a href="#content" class="hero-btn hero-btn-primary">
                    <i class="bi bi-arrow-down"></i>
                    Explore Content
                </a>
                <a href="/dashboard.php" class="hero-btn hero-btn-outline">
                    <i class="bi bi-speedometer2"></i>
                    Back to Dashboard
                </a>
            </div>
        </div>
    </div>
</header>

<div class="container py-5" id="content" style="margin-top: -80px; position: relative; z-index: 10;">
    
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="breadcrumb-enhanced fade-in">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="/dashboard.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="/operations/">Operations</a></li>
            <li class="breadcrumb-item active" aria-current="page">Template Page</li>
        </ol>
    </nav>

    <!-- Stats Section -->
    <div class="content-section fade-in">
        <div class="section-header">
            <h2 class="section-title">System Overview</h2>
            <p class="section-subtitle">Key metrics and performance indicators</p>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number">1,234</div>
                <div class="stat-label">Active Users</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">567</div>
                <div class="stat-label">Open Tickets</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">89</div>
                <div class="stat-label">Companies</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">99.9%</div>
                <div class="stat-label">Uptime</div>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="content-section fade-in">
        <div class="section-header">
            <h2 class="section-title">Quick Actions</h2>
            <p class="section-subtitle">Common administrative tasks and shortcuts</p>
        </div>
        
        <div class="row g-4">
            <div class="col-md-6 col-lg-3">
                <div class="enhanced-card text-center p-4">
                    <div class="card-icon primary">
                        <i class="bi bi-people"></i>
                    </div>
                    <h5 class="mb-3">Manage Users</h5>
                    <p class="text-muted mb-3">Add, edit, or remove user accounts</p>
                    <a href="/operations/manage-users.php" class="btn btn-enhanced btn-primary">
                        <span>Open</span>
                    </a>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="enhanced-card text-center p-4">
                    <div class="card-icon success">
                        <i class="bi bi-ticket"></i>
                    </div>
                    <h5 class="mb-3">Support Tickets</h5>
                    <p class="text-muted mb-3">Monitor and respond to tickets</p>
                    <a href="/operations/staff-tickets.php" class="btn btn-enhanced btn-success">
                        <span>Open</span>
                    </a>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="enhanced-card text-center p-4">
                    <div class="card-icon warning">
                        <i class="bi bi-building"></i>
                    </div>
                    <h5 class="mb-3">Companies</h5>
                    <p class="text-muted mb-3">Manage company accounts</p>
                    <a href="/operations/manage-companies.php" class="btn btn-enhanced btn-warning">
                        <span>Open</span>
                    </a>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="enhanced-card text-center p-4">
                    <div class="card-icon info">
                        <i class="bi bi-gear"></i>
                    </div>
                    <h5 class="mb-3">System Config</h5>
                    <p class="text-muted mb-3">Configure system settings</p>
                    <a href="/admin/system-config.php" class="btn btn-enhanced btn-info">
                        <span>Open</span>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Features Section -->
    <div class="content-section fade-in">
        <div class="section-header">
            <h2 class="section-title">Template Features</h2>
            <p class="section-subtitle">Modern design elements and functionality</p>
        </div>
        
        <div class="feature-grid">
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="bi bi-palette"></i>
                </div>
                <h4 class="feature-title">Modern Design</h4>
                <p class="feature-description">
                    Clean, modern interface with gradient backgrounds, smooth animations, and responsive layouts.
                </p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="bi bi-phone"></i>
                </div>
                <h4 class="feature-title">Mobile Responsive</h4>
                <p class="feature-description">
                    Fully responsive design that works perfectly on desktop, tablet, and mobile devices.
                </p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="bi bi-lightning"></i>
                </div>
                <h4 class="feature-title">Fast Performance</h4>
                <p class="feature-description">
                    Optimized for speed with efficient CSS, minimal JavaScript, and progressive loading.
                </p>
            </div>
        </div>
    </div>

    <!-- Content Placeholder -->
    <div class="content-section fade-in">
        <div class="row">
            <div class="col-lg-8">
                <div class="enhanced-card p-4">
                    <h3 class="mb-4">
                        <i class="bi bi-code-square me-2"></i>
                        Template Content Area
                    </h3>
                    <p class="text-muted mb-4">
                        This is where your main content would go. You can replace this section with tables, forms, 
                        charts, or any other content specific to your page requirements.
                    </p>
                    
                    <!-- Example content placeholder -->
                    <div class="row g-3">
                        <div class="col-12">
                            <div class="border rounded p-3 bg-light">
                                <h6 class="mb-2">Example Content Block</h6>
                                <p class="mb-0 small text-muted">Replace this with your actual page content</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="enhanced-card p-4">
                    <h4 class="mb-3">
                        <i class="bi bi-info-circle me-2"></i>
                        Quick Info
                    </h4>
                    <div class="list-group list-group-flush">
                        <div class="list-group-item d-flex justify-content-between align-items-center border-0 px-0">
                            <span>Template Version</span>
                            <span class="badge bg-primary rounded-pill">2.0</span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between align-items-center border-0 px-0">
                            <span>Last Updated</span>
                            <span class="badge bg-success rounded-pill">Today</span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between align-items-center border-0 px-0">
                            <span>Status</span>
                            <span class="badge bg-success rounded-pill">Active</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Enhanced scroll animations
document.addEventListener('DOMContentLoaded', function() {
    // Intersection Observer for fade-in animations
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

    // Observe all fade-in elements
    document.querySelectorAll('.fade-in').forEach(el => {
        observer.observe(el);
    });

    // Smooth scrolling for anchor links
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

    // Add loading states simulation
    setTimeout(() => {
        document.querySelectorAll('.loading-skeleton').forEach(el => {
            el.classList.remove('loading-skeleton');
        });
    }, 1000);

    // Enhanced button click effects
    document.querySelectorAll('.btn-enhanced').forEach(btn => {
        btn.addEventListener('click', function(e) {
            const ripple = document.createElement('span');
            const rect = this.getBoundingClientRect();
            const size = Math.max(rect.width, rect.height);
            const x = e.clientX - rect.left - size / 2;
            const y = e.clientY - rect.top - size / 2;
            
            ripple.style.width = ripple.style.height = size + 'px';
            ripple.style.left = x + 'px';
            ripple.style.top = y + 'px';
            ripple.classList.add('ripple');
            
            this.appendChild(ripple);
            
            setTimeout(() => {
                ripple.remove();
            }, 600);
        });
    });
});

// Add CSS for ripple effect
const style = document.createElement('style');
style.textContent = `
    .ripple {
        position: absolute;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.6);
        transform: scale(0);
        animation: ripple-animation 0.6s linear;
        pointer-events: none;
    }
    
    @keyframes ripple-animation {
        to {
            transform: scale(4);
            opacity: 0;
        }
    }
`;
document.head.appendChild(style);
</script>
<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
</body>
</html>