<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1"/>
  <title>Services | Velveth Lending</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
  <style>
    :root{--maroon:#7a1e2e;--maroon-dark:#5c1520;}
    *{margin:0;padding:0;box-sizing:border-box;}
    body{font-family:'Inter',sans-serif;color:#1a0810;background:#f5f0f1;}
    .top-nav{display:flex;align-items:center;justify-content:space-between;padding:0 40px;height:52px;background:#1a0810;}
    .nav-brand{font-family:'Inter',serif;font-size:18px;color:white;text-decoration:none;}
    .nav-brand span{color:#e8a0aa;}
    .back-link{color:rgba(255,255,255,0.7);text-decoration:none;font-size:13px;}
    .back-link:hover{color:white;}
    .page-hero{background:var(--maroon);padding:50px 40px;text-align:center;}
    .page-hero h1{font-family:'Inter',serif;font-size:34px;color:white;margin-bottom:8px;}
    .page-hero p{font-size:13px;color:rgba(255,255,255,0.6);}
    .services-body{max-width:900px;margin:0 auto;padding:50px 24px 60px;}
    h2.section-h{font-family:'Inter',serif;font-size:26px;text-align:center;color:#1a0810;margin-bottom:36px;}
    .services-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-bottom:50px;}
    .service-card{background:white;border:1px solid #e8e0e2;border-radius:4px;padding:30px 22px;text-align:center;transition:box-shadow 0.2s,transform 0.2s;}
    .service-card:hover{box-shadow:0 6px 20px rgba(122,30,46,0.1);transform:translateY(-3px);}
    .svc-icon{width:56px;height:56px;background:var(--maroon);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:22px;}
    .service-card h3{font-size:15px;font-weight:700;color:#1a0810;margin-bottom:8px;}
    .service-card p{font-size:12.5px;color:#666;line-height:1.7;margin-bottom:16px;}
    .service-card .rate{font-size:13px;font-weight:700;color:var(--maroon);}
    .cta-banner{background:var(--maroon);border-radius:4px;padding:40px;text-align:center;}
    .cta-banner h3{font-family:'Inter',serif;font-size:24px;color:white;margin-bottom:10px;}
    .cta-banner p{font-size:13px;color:rgba(255,255,255,0.7);margin-bottom:20px;}
    .cta-btn{display:inline-block;padding:13px 32px;background:white;color:var(--maroon);font-weight:700;font-size:13px;border-radius:3px;text-decoration:none;}
    .cta-btn:hover{background:#f0e8e9;}
    footer{background:#1a0810;}
    .footer-main{display:grid;grid-template-columns:1.5fr 1fr 1fr 1.5fr;gap:40px;padding:40px 60px;border-bottom:1px solid rgba(255,255,255,0.1);}
    .footer-brand-name{font-family:'Inter',serif;font-size:18px;color:white;margin-bottom:10px;}
    .footer-brand-name span{color:#e8a0aa;}
    .footer-desc{font-size:12px;color:rgba(255,255,255,0.5);line-height:1.8;}
    .footer-col h4{font-size:12px;font-weight:700;color:white;text-transform:uppercase;letter-spacing:1px;margin-bottom:14px;}
    .footer-col a,.footer-col p{display:block;font-size:12px;color:rgba(255,255,255,0.55);text-decoration:none;margin-bottom:7px;}
    .footer-col a:hover{color:white;}
    .footer-copy{text-align:center;padding:14px;font-size:11px;color:rgba(255,255,255,0.35);}
    @media(max-width:768px){.services-grid{grid-template-columns:1fr;}.footer-main{grid-template-columns:1fr 1fr;gap:20px;padding:28px 20px;}.top-nav{padding:0 20px;}}
  </style>
</head>
<body>
<nav class="top-nav">
  <a href="home.php" class="nav-brand"><span>Velveth</span> Lending</a>
  <a href="home.php" class="back-link">⊗ Back to Home</a>
</nav>
<div class="page-hero">
  <h1>Our Services</h1>
  <p>Flexible lending solutions designed to meet your needs</p>
</div>
<div class="services-body">
  <h2 class="section-h">Loan Products We Offer</h2>
  <div class="services-grid">
    <div class="service-card">
      <div class="svc-icon">💼</div>
      <h3>Personal Loan</h3>
      <p>For personal needs such as medical expenses, travel, or emergencies. Quick approval, flexible terms.</p>
      <div class="rate">From 5% p.a.</div>
    </div>
    <div class="service-card">
      <div class="svc-icon">🏪</div>
      <h3>Business Loan</h3>
      <p>Fund your business capital, expansion, or operational needs with our competitive business lending.</p>
      <div class="rate">From 5% p.a.</div>
    </div>
    <div class="service-card">
      <div class="svc-icon">🏠</div>
      <h3>Home Improvement</h3>
      <p>Renovate and improve your home with our dedicated home improvement financing solutions.</p>
      <div class="rate">From 5% p.a.</div>
    </div>
    <div class="service-card">
      <div class="svc-icon">🎓</div>
      <h3>Education Loan</h3>
      <p>Invest in education for yourself or your family with affordable and accessible education loans.</p>
      <div class="rate">From 5% p.a.</div>
    </div>
    <div class="service-card">
      <div class="svc-icon">🚗</div>
      <h3>Vehicle Loan</h3>
      <p>Get the vehicle you need with our competitive auto financing options and fast processing.</p>
      <div class="rate">From 6% p.a.</div>
    </div>
    <div class="service-card">
      <div class="svc-icon">🔄</div>
      <h3>Debt Consolidation</h3>
      <p>Simplify multiple debts into one manageable payment with better terms and lower rates.</p>
      <div class="rate">From 5% p.a.</div>
    </div>
  </div>
  <div class="cta-banner">
    <h3>Ready to Apply?</h3>
    <p>Create your account and start your loan application today. Quick approval, transparent terms.</p>
    <a href="customer_register.php" class="cta-btn">Apply Now</a>
  </div>
</div>
<footer>
  <div class="footer-main">
    <div><div class="footer-brand-name"><span>Velveth</span> Lending</div><p class="footer-desc">Providing reliable<br>lending solutions<br>since 2020</p></div>
    <div class="footer-col"><h4>Quick Links</h4><a href="about.php">About Us</a><a href="faq.php">FAQ</a><a href="contact.php">Contact Us</a></div>
    <div class="footer-col"><h4>Legal</h4><a href="privacy.php">Privacy Policy</a><a href="terms.php">Terms &amp; Conditions</a></div>
    <div class="footer-col"><h4>Contact Info</h4><p>Email: velveth@lending.com</p><p>Phone: (123) 456-7890</p><p>Address: Claveria, Davao City</p></div>
  </div>
  <div class="footer-copy">© 2026 Velveth Lending Company. All rights reserved.</div>
</footer>
</body>
</html>
