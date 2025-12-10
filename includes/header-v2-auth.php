<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?? 'CaminhoIT Operations' ?></title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    
    <!-- Custom Styles -->
    <link rel="stylesheet" href="/assets/styles.css">
    
    <!-- Dark Mode Script - Load Early -->
    <script>
        // Apply dark mode class before page renders to prevent flash
        (function() {
            const theme = localStorage.getItem('theme') || 'light';
            if (theme === 'dark') {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
    
    <style>
        /* Ensure smooth theme transitions */
        :root {
            transition: background-color 0.3s ease, color 0.3s ease;
        }
        
        /* Hero Section Styles */
        .hero {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 4rem 0 6rem;
            margin-bottom: -4rem;
            margin-top: -56px;
            padding-top: calc(4rem + 56px);
            position: relative;
            overflow: hidden;
            border-bottom-left-radius: 20px;
            border-bottom-right-radius: 20px;
        }
        
        .hero-gradient {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.9) 0%, rgba(118, 75, 162, 0.9) 100%);
            z-index: 0;
            border-bottom-left-radius: 20px;
            border-bottom-right-radius: 20px;
        }
        
        .dashboard-hero-content {
            position: relative;
            z-index: 1;
            color: white;
        }
        
        .dashboard-hero-title {
            font-size: 2.5rem;
            font-weight: 700;
            color: white;
            margin-bottom: 1rem;
        }
        
        .dashboard-hero-subtitle {
            font-size: 1.1rem;
            color: rgba(255, 255, 255, 0.9);
            margin-bottom: 1.5rem;
        }
        
        .dashboard-hero-actions {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }
        
        .c-btn-ghost {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.3);
            padding: 0.5rem 1.5rem;
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .c-btn-ghost:hover {
            background: rgba(255, 255, 255, 0.3);
            color: white;
            border-color: rgba(255, 255, 255, 0.5);
        }
        
        /* Dark Mode Toggle Button */
        .theme-toggle {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 1000;
            background: var(--primary-gradient);
            color: white;
            border: none;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            transition: all 0.3s ease;
        }
        
        .theme-toggle:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.3);
        }
        
        .theme-toggle i {
            font-size: 1.25rem;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <?php 
    $nav_file = $_SERVER['DOCUMENT_ROOT'] . '/assets/nav-v2.php';
    if (file_exists($nav_file)) {
        include $nav_file;
    }
    ?>
    
    <!-- Dark Mode Toggle Button -->
    <button class="theme-toggle" onclick="toggleTheme()" title="Toggle Dark Mode">
        <i class="bi bi-moon-fill" id="theme-icon"></i>
    </button>
    
    <script>
        function toggleTheme() {
            const html = document.documentElement;
            const icon = document.getElementById('theme-icon');
            
            if (html.classList.contains('dark')) {
                html.classList.remove('dark');
                localStorage.setItem('theme', 'light');
                icon.className = 'bi bi-moon-fill';
            } else {
                html.classList.add('dark');
                localStorage.setItem('theme', 'dark');
                icon.className = 'bi bi-sun-fill';
            }
        }
        
        // Set correct icon on page load
        document.addEventListener('DOMContentLoaded', function() {
            const theme = localStorage.getItem('theme') || 'light';
            const icon = document.getElementById('theme-icon');
            if (theme === 'dark') {
                icon.className = 'bi bi-sun-fill';
            }
        });
    </script>
