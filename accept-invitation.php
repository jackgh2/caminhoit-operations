<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';

$token = $_GET['token'] ?? '';
$error = '';
$success = '';

if (!$token) {
    $error = 'Invalid invitation link';
} else {
    // Get invitation details
    $stmt = $pdo->prepare("SELECT ui.*, c.name as company_name, u.username as invited_by_name 
        FROM user_invitations ui 
        JOIN companies c ON ui.company_id = c.id 
        JOIN users u ON ui.invited_by = u.id 
        WHERE ui.invitation_token = ? AND ui.expires_at > NOW() AND ui.accepted_at IS NULL");
    $stmt->execute([$token]);
    $invitation = $stmt->fetch();
    
    if (!$invitation) {
        $error = 'Invalid or expired invitation';
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($username) || empty($password) || empty($confirm_password)) {
        $error = 'All fields are required';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters';
    } else {
        try {
            // Check if username already exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                $error = 'Username already exists';
            } else {
                // Create user account
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                $stmt = $pdo->prepare("INSERT INTO users (username, email, password, role, company_id, is_active, provider, created_at) VALUES (?, ?, ?, 'supported_user', ?, 1, 'local', NOW())");
                $stmt->execute([$username, $invitation['email'], $hashed_password, $invitation['company_id']]);
                
                $user_id = $pdo->lastInsertId();
                
                // Mark invitation as accepted
                $stmt = $pdo->prepare("UPDATE user_invitations SET accepted_at = NOW() WHERE id = ?");
                $stmt->execute([$invitation['id']]);
                
                // Log the user in
                $_SESSION['user'] = [
                    'id' => $user_id,
                    'username' => $username,
                    'email' => $invitation['email'],
                    'role' => 'supported_user',
                    'company_id' => $invitation['company_id']
                ];
                
                $success = 'Account created successfully! You are now logged in.';
                
                // Redirect after 3 seconds
                header("refresh:3;url=/dashboard.php");
            }
        } catch (Exception $e) {
            $error = 'Error creating account: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accept Invitation - CaminhoIT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .invitation-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            padding: 2rem;
            max-width: 400px;
            width: 100%;
        }
        .logo {
            text-align: center;
            margin-bottom: 2rem;
        }
        .logo h1 {
            color: #4F46E5;
            font-weight: 700;
            margin: 0;
        }
        .invitation-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        .invitation-header h2 {
            color: #1f2937;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        .invitation-header p {
            color: #6b7280;
            margin: 0;
        }
        .company-info {
            background: #f8fafc;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 2rem;
            text-align: center;
        }
        .company-info h4 {
            color: #1f2937;
            margin-bottom: 0.5rem;
        }
        .company-info p {
            color: #6b7280;
            margin: 0;
            font-size: 0.875rem;
        }
        .form-control {
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 0.75rem;
            margin-bottom: 1rem;
        }
        .form-control:focus {
            border-color: #4F46E5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        .btn-primary {
            background: #4F46E5;
            border: none;
            border-radius: 8px;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            width: 100%;
        }
        .btn-primary:hover {
            background: #3F37C9;
        }
        .alert {
            border: none;
            border-radius: 8px;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <div class="invitation-card">
        <div class="logo">
            <h1><i class="bi bi-shield-check"></i> CaminhoIT</h1>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle me-2"></i>
                <?= htmlspecialchars($success) ?>
                <br><small>Redirecting to dashboard...</small>
            </div>
        <?php endif; ?>
        
        <?php if (!$error && !$success): ?>
            <div class="invitation-header">
                <h2>Accept Invitation</h2>
                <p>You've been invited to join a company</p>
            </div>
            
            <div class="company-info">
                <h4><?= htmlspecialchars($invitation['company_name']) ?></h4>
                <p>Invited by <?= htmlspecialchars($invitation['invited_by_name']) ?></p>
                <p>Email: <?= htmlspecialchars($invitation['email']) ?></p>
            </div>
            
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label">Username</label>
                    <input type="text" name="username" class="form-control" required>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Confirm Password</label>
                    <input type="password" name="confirm_password" class="form-control" required>
                </div>
                
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-person-plus me-2"></i>
                    Create Account & Join Company
                </button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>