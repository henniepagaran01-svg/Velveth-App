<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1"/>
  <title>FAQ | Velveth Lending</title>
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
    .page-hero-icon{font-size:36px;margin-bottom:12px;}
    .page-hero h1{font-family:'Inter',serif;font-size:32px;color:white;margin-bottom:8px;}
    .page-hero p{font-size:11px;letter-spacing:2px;text-transform:uppercase;color:rgba(255,255,255,0.55);}
    .faq-body{max-width:760px;margin:0 auto;padding:48px 24px 60px;}
    .faq-section{margin-bottom:36px;}
    .faq-section-title{font-size:13px;font-weight:700;color:#1a0810;text-transform:uppercase;letter-spacing:1px;padding-bottom:10px;border-bottom:2px solid #e8e0e2;margin-bottom:16px;}
    .faq-item{border-bottom:1px solid #e8e0e2;}
    .faq-question{width:100%;display:flex;justify-content:space-between;align-items:center;padding:16px 0;background:none;border:none;cursor:pointer;font-family:'Inter',sans-serif;font-size:13.5px;font-weight:500;color:#1a0810;text-align:left;gap:12px;}
    .faq-question:hover{color:var(--maroon);}
    .faq-arrow{font-size:12px;color:#999;flex-shrink:0;transition:transform 0.2s;}
    .faq-item.open .faq-arrow{transform:rotate(180deg);color:var(--maroon);}
    .faq-answer{display:none;padding:0 0 16px;font-size:13px;color:#555;line-height:1.8;}
    .faq-item.open .faq-answer{display:block;}
    .cta-section{background:var(--maroon);border-radius:6px;padding:40px 30px;text-align:center;margin-top:40px;}
    .cta-icon{font-size:32px;margin-bottom:12px;}
    .cta-title{font-family:'Inter',serif;font-size:22px;color:white;margin-bottom:6px;}
    .cta-sub{font-size:11px;letter-spacing:1.5px;text-transform:uppercase;color:rgba(255,255,255,0.55);margin-bottom:20px;}
    .cta-btn{display:inline-block;padding:12px 28px;background:white;color:var(--maroon);font-weight:700;font-size:13px;border-radius:3px;text-decoration:none;transition:all 0.2s;}
    .cta-btn:hover{background:#f0e8e9;transform:translateY(-1px);}
    footer{background:#1a0810;}
    .footer-main{display:grid;grid-template-columns:1.5fr 1fr 1fr 1.5fr;gap:40px;padding:40px 60px;border-bottom:1px solid rgba(255,255,255,0.1);}
    .footer-brand-name{font-family:'Inter',serif;font-size:18px;color:white;margin-bottom:10px;}
    .footer-brand-name span{color:#e8a0aa;}
    .footer-desc{font-size:12px;color:rgba(255,255,255,0.5);line-height:1.8;}
    .footer-col h4{font-size:12px;font-weight:700;color:white;text-transform:uppercase;letter-spacing:1px;margin-bottom:14px;}
    .footer-col a,.footer-col p{display:block;font-size:12px;color:rgba(255,255,255,0.55);text-decoration:none;margin-bottom:7px;}
    .footer-col a:hover{color:white;}
    .footer-copy{text-align:center;padding:14px;font-size:11px;color:rgba(255,255,255,0.35);}
    @media(max-width:640px){.footer-main{grid-template-columns:1fr 1fr;gap:20px;padding:28px 20px;}.top-nav{padding:0 20px;}}
  </style>
</head>
<body>
<nav class="top-nav">
  <a href="home.php" class="nav-brand"><img src="assets/images/logo.png" alt="VLC" style="height:28px;width:auto;vertical-align:middle;margin-right:6px;border-radius:4px;object-fit:contain;"/><span>Velveth</span> Lending</a>
  <a href="home.php" class="back-link">⊗ Back to Home</a>
</nav>

<div class="page-hero">
  <div class="page-hero-icon">❓</div>
  <h1>Frequently Asked Questions</h1>
  <p>Find answers to common questions about our lending services</p>
</div>

<div class="faq-body">

  <div class="faq-section">
    <div class="faq-section-title">General Questions</div>
    <?php
    $faqs = [
      'General Questions' => [
        ['What is Velveth Lending Company?','Velveth Lending Company is a trusted financial institution providing accessible, reliable, and transparent loan services to individuals and businesses since 2020.'],
        ['How long has Velveth Lending been in business?','We have been proudly serving our customers since 2020, helping thousands achieve their financial goals.'],
        ['Is Velveth Lending a legitimate company?','Yes. Velveth Lending is a registered and licensed lending company operating in compliance with all applicable financial regulations.'],
      ],
      'Loan Application' => [
        ['What are the requirements for a loan?','You will need a valid government-issued ID, proof of income, proof of billing, and a completed application form.'],
        ['How long does the application process take?','Most applications are reviewed within 24 hours. Once approved, funds are typically disbursed within 1-2 business days.'],
        ['Can I apply for a loan online?','Yes! You can complete the entire application process through our online portal at any time.'],
        ['What loan amounts are available?','We offer loans ranging from ₱5,000 to ₱500,000 depending on your creditworthiness and repayment capacity.'],
        ['What is the interest rate?','Our interest rate starts at 5% per annum, depending on the loan amount and term selected.'],
      ],
      'Payments' => [
        ['What payment methods do you accept?','We accept cash, GCash, bank transfer, and check payments.'],
        ['When are payments due?','Payments are due on the same date each month as specified in your loan agreement.'],
        ['What happens if I miss a payment?','Late payments may incur penalty fees. We encourage customers to contact us immediately if they anticipate difficulty making a payment.'],
        ['Can I pay my loan early?','Yes, early repayment is allowed. Please contact our office to discuss early settlement terms.'],
        ['How can I check my payment history?','You can view your complete payment history by logging into your customer dashboard.'],
      ],
      'Account Management' => [
        ['How do I create an account?','Click the "Register" button on the homepage and fill out the application form to create your account.'],
        ['I forgot my password. What should I do?','Click the "Forgot password?" link on the login page and follow the instructions sent to your email.'],
        ['Can I update my personal information?','Yes. Log in to your account and navigate to the Profile section to update your details.'],
        ['How do I contact customer support?','You can reach us via email at velveth@lending.com or call us at (123) 456-7890.'],
      ],
      'Penalties and Issues' => [
        ['What are the penalties for late payment?','Late payment penalties are calculated based on the number of days overdue. Please refer to your loan agreement for the specific rate.'],
        ['What happens if I default on my loan?','Defaulting on a loan may result in additional charges, negative credit reporting, and potential legal action.'],
        ['Can I restructure my loan?','Yes, loan restructuring may be available under certain circumstances. Contact our team to discuss your options.'],
      ],
    ];
    foreach($faqs as $section => $items):
      if ($section !== 'General Questions'): ?>
        <div class="faq-section" style="margin-top:8px;">
        <div class="faq-section-title"><?= $section ?></div>
      <?php endif;
      foreach($items as $qa): ?>
        <div class="faq-item">
          <button class="faq-question" onclick="toggleFaq(this)">
            <?= $qa[0] ?><span class="faq-arrow">▼</span>
          </button>
          <div class="faq-answer"><?= $qa[1] ?></div>
        </div>
      <?php endforeach;
      if ($section !== 'General Questions'): ?>
        </div>
      <?php endif;
    endforeach; ?>
  </div>

  <div class="cta-section">
    <div class="cta-icon">❓</div>
    <div class="cta-title">Still Have Questions?</div>
    <p class="cta-sub">Can't find the answer you're looking for? Our support team is here to help!</p>
    <a href="contact.php" class="cta-btn">Contact Support</a>
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

<script>
function toggleFaq(btn) {
  const item = btn.closest('.faq-item');
  const isOpen = item.classList.contains('open');
  document.querySelectorAll('.faq-item.open').forEach(el => el.classList.remove('open'));
  if (!isOpen) item.classList.add('open');
}
</script>
</body>
</html>
