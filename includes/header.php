<?php
$site_base_url = rtrim((string) ($base_url ?? ''), '/') . '/';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JECRC University - Result Portal</title>
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="<?php echo $site_base_url; ?>assets/images/favicon.png">

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Animate.css -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="<?php echo $site_base_url; ?>assets/css/style.css">
    <?php if (isset($_SESSION['admin_id'])): ?>
    <link rel="stylesheet" href="<?php echo $site_base_url; ?>assets/css/admin_theme.css">
    <?php endif; ?>
</head>
<body>

    <header class="site-header sticky-top">
        <nav class="navbar navbar-expand-lg navbar-light">
            <div class="container site-nav">
                <a class="navbar-brand brand-lockup d-flex align-items-center" href="<?php echo $site_base_url; ?>index.php">
                    <img src="<?php echo $site_base_url; ?>assets/images/logo.png" alt="JECRC University" class="brand-logo">
                    <span class="brand-context">Student Result Portal</span>
                </a>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse" id="navbarNav">
                    <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
                        <li class="nav-item">
                            <a class="nav-link nav-link-home fw-medium" href="<?php echo $site_base_url; ?>index.php">
                                <i class="fas fa-house me-2" aria-hidden="true"></i>Home
                            </a>
                        </li>
                        <?php if(isset($_SESSION['admin_id'])): ?>
                            <li class="nav-item">
                                <a href="<?php echo $site_base_url; ?>admin/dashboard.php" class="btn btn-primary nav-action px-4">
                                    <i class="fas fa-chart-line me-2" aria-hidden="true"></i>Dashboard
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="<?php echo $site_base_url; ?>admin/logout.php" class="btn btn-sm btn-outline-danger nav-action-outline px-3" title="Logout">
                                    <i class="fas fa-sign-out-alt"></i> Logout
                                </a>
                            </li>
                        <?php endif; ?>
                        <!-- The public "Admin Login" link has been removed from here -->
                    </ul>
                </div>
            </div>
        </nav>
    </header>