<?php
require_once 'includes/config.php';

// ===== SECURITY: Apply rate limiting to public search =====
check_rate_limit($pdo);

include 'includes/header.php';
?>

<!-- AOS Library -->
<link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>

<main>
    <section class="hero-section position-relative">
        <div class="hero-overlay" aria-hidden="true"></div>
        <div class="container hero-content position-relative">
            <div class="row justify-content-center">
                <div class="col-xl-8 col-lg-9 text-center text-white">
                    <p class="hero-eyebrow" data-aos="fade-down" data-aos-delay="100">
                        <span class="eyebrow-line"></span>
                        <i class="fas fa-graduation-cap me-2" aria-hidden="true"></i> JECRC University
                        <span class="eyebrow-line"></span>
                    </p>
                    <h1 class="hero-title" data-aos="fade-up" data-aos-delay="200">
                        Your results,<br><span>right when you need them.</span>
                    </h1>
                    <p class="hero-subtitle" data-aos="fade-up" data-aos-delay="300">
                        Access your academic performance securely through the official student result portal.
                    </p>
                    <div class="search-panel text-start">
                        <div class="search-panel-heading">
                            <div class="search-icon"><i class="fas fa-id-card" aria-hidden="true"></i></div>
                            <div>
                                <h2>Find your result</h2>
                                <p>Enter your roll number to continue</p>
                            </div>
                        </div>
                        <form action="view_result.php" method="POST" class="search-form">
                            <div class="search-input-wrap">
                                <i class="fas fa-hashtag" aria-hidden="true"></i>
                                <input type="text" name="roll_no" class="form-control" placeholder="Your roll number" aria-label="Your roll number" required>
                            </div>
                            <button type="submit" class="btn btn-primary search-button">
                                Search results <i class="fas fa-arrow-right ms-2" aria-hidden="true"></i>
                            </button>
                        </form>
                        <p class="search-note"><i class="fas fa-lock me-2" aria-hidden="true"></i>Your information is handled securely</p>
                    </div>
                    <div class="home-disclaimer">
                        <?php include 'includes/disclaimer.php'; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="hero-detail hero-detail-one" aria-hidden="true"></div>
        <div class="hero-detail hero-detail-two" aria-hidden="true"></div>
    </section>

</main>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        AOS.init({
            duration: 800,
            easing: 'ease-in-out',
            once: true
        });
    });
</script>

<?php include 'includes/footer.php'; ?>