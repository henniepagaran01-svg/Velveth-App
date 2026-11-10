<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1"/>
  <title>Contact | Velveth Lending</title>
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
    .page-hero h1{font-family:'Inter',serif;font-size:32px;color:white;margin-bottom:8px;}
    .page-hero p{font-size:13px;color:rgba(255,255,255,0.6);}
    .contact-body{max-width:700px;margin:0 auto;padding:48px 24px 60px;}
    .contact-card{background:white;border:1px solid #e8e0e2;border-radius:4px;padding:36px 40px;margin-bottom:32px;}
    .contact-card h3{font-size:14px;font-weight:600;text-align:center;color:#1a0810;margin-bottom:24px;}
    .form-row{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
    .form-group{margin-bottom:16px;}
    .form-label{display:block;font-size:12px;color:#666;margin-bottom:5px;}
    .form-input,.form-textarea{width:100%;padding:10px 12px;border:1px solid #ddd;border-radius:3px;font-family:'Inter',sans-serif;font-size:13.5px;color:#1a0810;transition:border-color 0.2s;background:white;}
    .form-input:focus,.form-textarea:focus{outline:none;border-color:var(--maroon);}
    .form-textarea{resize:vertical;min-height:100px;}
    .submit-btn{width:100%;padding:13px;background:var(--maroon);color:white;border:none;border-radius:3px;font-family:'Inter',sans-serif;font-size:14px;font-weight:700;cursor:pointer;transition:background 0.2s;display:flex;align-items:center;justify-content:center;gap:8px;}
    .submit-btn:hover{background:var(--maroon-dark);}
    .success-msg{background:#dcfce7;border:1px solid #86efac;color:#16a34a;padding:12px 16px;border-radius:4px;font-size:13px;margin-bottom:16px;text-align:center;}
    .contact-info-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;}
    .info-card{background:white;border:1px solid #e8e0e2;border-radius:4px;padding:20px 14px;text-align:center;}
    .info-icon{font-size:22px;margin-bottom:10px;}
    .info-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#888;margin-bottom:6px;}
    .info-text{font-size:12.5px;color:#444;line-height:1.7;}
    footer{background:#1a0810;}
    .footer-main{display:grid;grid-template-columns:1.5fr 1fr 1fr 1.5fr;gap:40px;padding:40px 60px;border-bottom:1px solid rgba(255,255,255,0.1);}
    .footer-brand-name{font-family:'Inter',serif;font-size:18px;color:white;margin-bottom:10px;}
    .footer-brand-name span{color:#e8a0aa;}
    .footer-desc{font-size:12px;color:rgba(255,255,255,0.5);line-height:1.8;}
    .footer-col h4{font-size:12px;font-weight:700;color:white;text-transform:uppercase;letter-spacing:1px;margin-bottom:14px;}
    .footer-col a,.footer-col p{display:block;font-size:12px;color:rgba(255,255,255,0.55);text-decoration:none;margin-bottom:7px;}
    .footer-col a:hover{color:white;}
    .footer-copy{text-align:center;padding:14px;font-size:11px;color:rgba(255,255,255,0.35);}
    @media(max-width:640px){.form-row{grid-template-columns:1fr;}.contact-info-grid{grid-template-columns:1fr 1fr;}.footer-main{grid-template-columns:1fr 1fr;gap:20px;padding:28px 20px;}.top-nav{padding:0 20px;}}
  </style>
</head>
<body>
<nav class="top-nav">
  <a href="home.php" class="nav-brand"><img src="assets/images/logo.png" alt="VLC" style="height:28px;width:auto;vertical-align:middle;margin-right:6px;border-radius:4px;object-fit:contain;"/><span>Velveth</span> Lending</a>
  <a href="home.php" class="back-link">⊗ Back to Home</a>
</nav>

<div class="page-hero">
  <h1>Contact Us</h1>
  <p>We're here to help. Get in touch with us today.</p>
</div>

<div class="contact-body">
  <div class="contact-card">
    <h3>Send Us a Message</h3>
    <?php if (isset($_GET['sent'])): ?>
      <div class="success-msg">✅ Your message has been sent! We'll get back to you soon.</div>
    <?php endif; ?>
    <form method="POST" action="contact_submit.php">
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Full Name</label>
          <input type="text" name="name" class="form-input" required/>
        </div>
        <div class="form-group">
          <label class="form-label">Email Address</label>
          <input type="email" name="email" class="form-input" required/>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Phone Number</label>
          <input type="text" name="phone" class="form-input"/>
        </div>
        <div class="form-group">
          <label class="form-label">Subject</label>
          <input type="text" name="subject" class="form-input" value="How Can I Help You?"/>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Message</label>
        <textarea name="message" class="form-textarea" placeholder="Tell us more about your inquiry..."></textarea>
      </div>
      <button type="submit" class="submit-btn">💌 Send Message</button>
    </form>
  </div>

  <div class="contact-info-grid">
    <div class="info-card">
      <div class="info-icon">📞</div>
      <div class="info-label">Phone</div>
      <div class="info-text">(123) 456-7890<br>(123) 456-7891</div>
    </div>
    <div class="info-card">
      <div class="info-icon">✉️</div>
      <div class="info-label">Email</div>
      <div class="info-text">velveth@lending.com<br>support@velveth<br>lending.com</div>
    </div>
    <div class="info-card">
      <div class="info-icon">📍</div>
      <div class="info-label">Office Address</div>
      <div class="info-text">123 Lending Street<br>Financial District<br>City, State 12345</div>
    </div>
    <div class="info-card">
      <div class="info-icon">🕐</div>
      <div class="info-label">Business Hours</div>
      <div class="info-text">Mon–Fri: 9:00<br>AM – 6:00 PM<br>Sat: 10:00 AM<br>– 4:00 PM<br>Sunday: Closed</div>
    </div>
  </div>
</div>

<footer>
  <div class="footer-main">
    <div>
      <div class="footer-brand-name"><span>Velveth</span> Lending</div>
      <p class="footer-desc">Providing reliable<br>lending solutions<br>since 2020</p>
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
  <div class="footer-copy">© 2026 Velveth Lending Company. All rights reserved.</div>
</footer>
</body>
</html>
