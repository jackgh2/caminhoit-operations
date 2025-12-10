<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?? 'CaminhoIT' ?></title>
    
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
    <button class="theme-toggle" onclick="toggleTheme()" title="Toggle Dark Mode" style="position: fixed; bottom: 20px; right: 20px; z-index: 1000; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 50%; width: 50px; height: 50px; display: flex; align-items: center; justify-content: center; cursor: pointer; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);">
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
