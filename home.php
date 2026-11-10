<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1"/>
  <title>Velveth Lending | Fast & Reliable Lending Solutions</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
  <style>
    :root {
      --maroon: #7a1e2e;
      --maroon-dark: #5c1520;
      --maroon-deep: #3d0d15;
      --maroon-light: #9e2d40;
      --maroon-pale: #f9eef0;
      --text: #1a0810;
      --gray: #666;
      --light: #f7f5f5;
    }
    * { margin:0; padding:0; box-sizing:border-box; }
    body { font-family:'Inter',sans-serif; color:var(--text); }

    /* ── NAVBAR ── */
    .navbar {
      display:flex; align-items:center; justify-content:space-between;
      padding:0 60px; height:60px;
      background:#1a0810;
      position:sticky; top:0; z-index:100;
    }
    .nav-brand {
      font-family:'Inter',serif; font-size:20px; color:white;
      text-decoration:none; letter-spacing:0.3px;
    }
    .nav-brand span { color:#e8a0aa; }
    .nav-links { display:flex; align-items:center; gap:30px; }
    .nav-links a {
      color:rgba(255,255,255,0.8); text-decoration:none;
      font-size:13px; font-weight:500; letter-spacing:0.5px;
      text-transform:uppercase; transition:color 0.2s;
    }
    .nav-links a:hover { color:white; }
    .nav-actions { display:flex; align-items:center; gap:10px; }
    .btn-login {
      padding:8px 20px; border:1.5px solid rgba(255,255,255,0.4);
      color:white; border-radius:4px; font-size:13px; font-weight:500;
      text-decoration:none; transition:all 0.2s; cursor:pointer;
      background:transparent; font-family:'Inter',sans-serif;
    }
    .btn-login:hover { border-color:white; background:rgba(255,255,255,0.1); }
    .btn-register {
      padding:8px 20px; background:var(--maroon); color:white;
      border:1.5px solid var(--maroon); border-radius:4px; font-size:13px;
      font-weight:600; text-decoration:none; transition:all 0.2s;
    }
    .btn-register:hover { background:var(--maroon-light); }

    /* ── HERO ── */
    .hero {
      background:var(--maroon);
      padding:80px 60px 90px;
      position:relative; overflow:hidden;
    }
    .hero::before {
      content:'';
      position:absolute; inset:0;
      background:
        radial-gradient(ellipse 70% 80% at 70% 50%, rgba(255,255,255,0.04) 0%, transparent 60%),
        radial-gradient(ellipse 40% 60% at 10% 80%, rgba(0,0,0,0.2) 0%, transparent 60%);
    }
    .hero-content { position:relative; z-index:2; max-width:560px; }
    .hero h1 {
      font-family:'Inter',serif;
      font-size:38px; font-weight:700; color:white;
      line-height:1.25; margin-bottom:14px;
    }
    .hero p {
      font-size:13px; color:rgba(255,255,255,0.7);
      letter-spacing:1.5px; text-transform:uppercase;
      margin-bottom:32px;
    }
    .btn-apply {
      display:inline-block; padding:13px 32px;
      border:2px solid white; color:white;
      font-family:'Inter',sans-serif; font-size:13px;
      font-weight:700; letter-spacing:1px; text-transform:uppercase;
      text-decoration:none; transition:all 0.2s;
    }
    .btn-apply:hover { background:white; color:var(--maroon); }

    /* ── WHY CHOOSE ── */
    .why-section {
      padding:70px 60px;
      background:var(--light);
      text-align:center;
    }
    .why-section h2 {
      font-family:'Inter',serif;
      font-size:30px; color:var(--text); margin-bottom:50px;
    }
    .features-grid {
      display:grid; grid-template-columns:repeat(3,1fr);
      gap:24px; max-width:900px; margin:0 auto;
    }
    .feature-card {
      background:white; padding:40px 28px;
      border-radius:4px; border:1px solid #e8e0e2;
      transition:box-shadow 0.3s, transform 0.3s;
    }
    .feature-card:hover {
      box-shadow:0 8px 30px rgba(122,30,46,0.12);
      transform:translateY(-4px);
    }
    .feature-icon {
      width:60px; height:60px; background:var(--maroon);
      border-radius:50%; display:flex; align-items:center;
      justify-content:center; margin:0 auto 20px; font-size:24px;
    }
    .feature-card h3 {
      font-size:16px; font-weight:700; color:var(--text);
      margin-bottom:10px;
    }
    .feature-card p { font-size:12.5px; color:var(--gray); line-height:1.7; }

    /* ── HOW IT WORKS ── */
    .how-section { padding:70px 60px; background:white; }
    .how-section h2 {
      font-family:'Inter',serif; font-size:32px;
      color:var(--maroon); text-align:center; margin-bottom:50px;
    }
    .steps-grid {
      display:grid; grid-template-columns:repeat(3,1fr);
      gap:40px; max-width:900px; margin:0 auto 40px;
    }
    .step { text-align:left; }
    .step-num {
      font-family:'Inter',serif; font-size:64px;
      font-weight:700; color:var(--maroon); line-height:1;
      margin-bottom:4px;
    }
    @media (max-width:768px) {
      .step { text-align:center; }
      .step-num { margin-left:auto; margin-right:auto; }
    }
    .step h3 {
      font-size:15px; font-weight:700; letter-spacing:1px;
      text-transform:uppercase; color:var(--text); margin-bottom:12px;
    }
    .step p { font-size:13px; color:var(--gray); line-height:1.7; }
    .how-cta { text-align:center; }
    .btn-apply-dark {
      display:inline-block; padding:14px 36px;
      background:var(--maroon); color:white;
      font-family:'Inter',sans-serif; font-size:13px;
      font-weight:700; letter-spacing:1px; text-transform:uppercase;
      text-decoration:none; border-radius:2px; transition:background 0.2s;
    }
    .btn-apply-dark:hover { background:var(--maroon-dark); }

    /* ── FOOTER ── */
    footer {
      background:#1a0810; color:rgba(255,255,255,0.7);
      padding:50px 60px 0;
    }
    .footer-grid {
      display:grid; grid-template-columns:1.5fr 1fr 1fr 1.5fr;
      gap:40px; padding-bottom:40px;
      border-bottom:1px solid rgba(255,255,255,0.1);
    }
    .footer-brand { font-family:'Inter',serif; font-size:20px; color:white; margin-bottom:12px; }
    .footer-brand span { color:#e8a0aa; }
    .footer-desc { font-size:12.5px; line-height:1.8; }
    .footer-col h4 {
      font-size:13px; font-weight:700; color:white;
      text-transform:uppercase; letter-spacing:1px; margin-bottom:16px;
    }
    .footer-col a, .footer-col p {
      display:block; font-size:12.5px; color:rgba(255,255,255,0.6);
      text-decoration:none; margin-bottom:8px; transition:color 0.2s;
    }
    .footer-col a:hover { color:white; }
    .footer-bottom {
      text-align:center; padding:16px 0;
      font-size:12px; color:rgba(255,255,255,0.4);
    }

    @media(max-width:768px) {
      .navbar { padding:0 20px; }
      .nav-links { display:none; }
      .hero { padding:60px 24px 70px; }
      .hero h1 { font-size:26px; }
      .why-section, .how-section { padding:50px 24px; }
      .features-grid, .steps-grid { grid-template-columns:1fr; }
      .footer-grid { grid-template-columns:1fr 1fr; gap:24px; }
      footer { padding:40px 24px 0; }
    }
  
    /* ── RESPONSIVE MOBILE ──────────────── */
    @media (max-width: 768px) {
      .navbar { padding: 0 16px; height: auto; min-height: 52px; flex-wrap: wrap; gap: 6px; padding-top: 8px; padding-bottom: 8px; }
      .nav-links { display: none; }
      .nav-brand { font-size: 20px; }
      .nav-cta { flex-wrap: wrap; gap: 6px; }
      .nav-cta a, .nav-cta button { font-size: 11px; padding: 6px 12px; }
      .hero { padding: 48px 20px 56px; }
      .hero h1 { font-size: clamp(26px,8vw,52px); }
      .hero-sub { font-size: 11px; }
      .hero-btns { flex-direction: column; gap: 10px; }
      .hero-btns a { width: 100%; text-align: center; }
      .why-grid { grid-template-columns: 1fr !important; gap: 14px !important; }
      .steps-row { grid-template-columns: 1fr !important; }
      .stats-banner { grid-template-columns: 1fr 1fr !important; gap: 16px !important; }
      .loan-cards { grid-template-columns: 1fr !important; }
      .footer-grid { grid-template-columns: 1fr 1fr !important; gap: 20px !important; padding: 28px 20px !important; }
      .section { padding: 40px 20px !important; }
    }
    @media (max-width: 480px) {
      .hero h1 { font-size: 26px; }
      .stats-banner { grid-template-columns: 1fr !important; }
      .footer-grid { grid-template-columns: 1fr !important; }
    }

  </style>
</head>
<body>
<?php include 'splash_screen.php'; ?>

<!-- NAVBAR -->
<nav class="navbar">
  <a href="home.php" class="nav-brand"><img src="assets/images/logo.png" alt="VLC" style="height:32px;width:auto;object-fit:contain;vertical-align:middle;margin-right:8px;border-radius:4px;"/><span>Velveth</span> Lending</a>
  <div class="nav-links">
    <a href="about.php">About</a>
    <a href="services.php">Services</a>
    <a href="contact.php">Contact</a>
  </div>
  <div class="nav-actions">
    <a href="customer_login.php" class="btn-login">Log in</a>
    <a href="customer_register.php" class="btn-register">Register</a>
  </div>
</nav>

<!-- HERO -->
<section class="hero">
  <div class="hero-content">
    <h1>Fast &amp; Reliable Lending Solutions</h1>
    <p>Get the financial support you need with our easy and secure lending process</p>
    <a href="customer_register.php" class="btn-apply">APPLY NOW</a>
  </div>
</section>

<!-- WHY CHOOSE -->
<section class="why-section">
  <h2>Why Choose Velveth Lending?</h2>
  <div class="features-grid">
    <div class="feature-card">
      <div class="feature-icon">✅</div>
      <h3>Quick Approval</h3>
      <p>Get your loan approved within 24 hours with our streamlined process.</p>
    </div>
    <div class="feature-card">
      <div class="feature-icon">🛡️</div>
      <h3>Secure System</h3>
      <p>Your data is protected and its security.</p>
    </div>
    <div class="feature-card">
      <div class="feature-icon">📈</div>
      <h3>Easy Tracking</h3>
      <p>Monitor your loan status and payments in real-time through our portal.</p>
    </div>
  </div>
</section>

<!-- HOW IT WORKS -->
<section class="how-section">
  <h2>How It Works</h2>
  <div class="steps-grid">
    <div class="step">
      <div class="step-num">1</div>
      <h3>Register</h3>
      <p>Fill out our online application form with your details.</p>
    </div>
    <div class="step">
      <div class="step-num">2</div>
      <h3>Get Approved</h3>
      <p>Our team reviews your application and approves it quickly</p>
    </div>
    <div class="step">
      <div class="step-num">3</div>
      <h3>Receive Cash</h3>
      <p>Our team reviews your application and approves it quickly</p>
    </div>
  </div>
  <div class="how-cta">
    <a href="customer_register.php" class="btn-apply-dark">APPLY NOW</a>
  </div>
</section>

<!-- FOOTER -->
<footer>
  <div class="footer-grid">
    <div>
      <div class="footer-brand"><span>Velveth</span> Lending</div>
      <p class="footer-desc">Providing reliable lending solutions since 2020</p>
    </div>
    <div class="footer-col">
      <h4>Quick Links</h4>
      <a href="about.php">About Us</a>
      <a href="faq.php">FAQ</a>
      <a href="contact.php">Contact Us</a>
    </div>
    <div class="footer-col">
      <h4>Legal</h4>
      <a href="privacy.php">Privacy Policy</a>
      <a href="terms.php">Terms &amp; Conditions</a>
    </div>
    <div class="footer-col">
      <h4>Contact Info</h4>
      <p>Email: velveth@lending.com</p>
      <p>Phone: (123) 456-7890</p>
      <p>Address: Claveria, Davao City</p>
    </div>
  </div>
  <div class="footer-bottom">© 2026 Velveth Lending Company. All rights reserved.</div>
</footer>

</body>
</html>
