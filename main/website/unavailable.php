<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title><?php echo htmlspecialchars($restaurant_name ?? 'Restaurant', ENT_QUOTES, 'UTF-8'); ?> - Temporarily Unavailable</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Poppins', sans-serif;
      background: #e8ecf2;
      color: #1a1b1f;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }
    .phone-frame {
      max-width: 425px;
      width: 100%;
      background: #fff;
      border-radius: 28px;
      overflow: hidden;
      box-shadow: 0 0 40px rgba(0,0,0,0.08);
      position: relative;
    }
    @media (max-width: 480px) {
      .phone-frame { border-radius: 0; min-height: 100vh; }
      body { padding: 0; }
    }
    .header-bar {
      background: linear-gradient(135deg, #1a3934 0%, #2d5a50 100%);
      padding: 40px 24px 60px;
      text-align: center;
      position: relative;
    }
    .header-bar .icon-wrap {
      width: 80px;
      height: 80px;
      background: rgba(255,255,255,0.15);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 16px;
      font-size: 36px;
      color: #fff;
    }
    .header-bar h1 {
      font-size: 24px;
      font-weight: 800;
      color: #fff;
      letter-spacing: -0.5px;
    }
    .header-bar p {
      font-size: 14px;
      color: rgba(255,255,255,0.7);
      margin-top: 6px;
    }
    .content {
      padding: 0 24px 32px;
      margin-top: -30px;
      position: relative;
      z-index: 1;
    }
    .card {
      background: #fff;
      border-radius: 20px;
      padding: 28px 24px;
      box-shadow: 0 8px 32px rgba(0,0,0,0.08);
    }
    .card h2 {
      font-size: 18px;
      font-weight: 600;
      color: #1a1b1f;
      margin-bottom: 12px;
    }
    .card p {
      font-size: 14px;
      color: #6b7280;
      line-height: 1.7;
    }
  </style>
</head>
<body>
<div class="phone-frame">
  <div class="header-bar">
    <div class="icon-wrap">
      <i class="fa fa-utensils"></i>
    </div>
    <h1><?php echo htmlspecialchars($restaurant_name ?? 'This restaurant', ENT_QUOTES, 'UTF-8'); ?></h1>
    <p>Online ordering is temporarily unavailable</p>
  </div>
  <div class="content">
    <div class="card">
      <h2>We'll be back soon</h2>
      <p>This restaurant's online ordering page is currently unavailable. Please check back later or contact the restaurant directly.</p>
    </div>
  </div>
</div>
</body>
</html>
