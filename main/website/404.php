<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>404 - Page Not Found</title>
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
      font-size: 28px;
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
      margin-bottom: 20px;
    }
    .reason-list {
      list-style: none;
      padding: 0;
      margin: 0 0 24px;
    }
    .reason-list li {
      display: flex;
      align-items: flex-start;
      gap: 10px;
      padding: 10px 0;
      border-bottom: 1px solid #f3f4f6;
      font-size: 13px;
      color: #4b5563;
      line-height: 1.5;
    }
    .reason-list li:last-child { border-bottom: none; }
    .reason-list li i {
      color: #846241;
      font-size: 14px;
      margin-top: 2px;
      flex-shrink: 0;
    }
    .btn-group {
      display: flex;
      flex-direction: column;
      gap: 10px;
    }
    .btn {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      padding: 14px;
      border-radius: 12px;
      font-size: 14px;
      font-weight: 600;
      font-family: 'Poppins', sans-serif;
      text-decoration: none;
      cursor: pointer;
      border: none;
      transition: opacity 0.2s;
    }
    .btn:active { opacity: 0.85; }
    .btn-primary {
      background: linear-gradient(to right, #846241, #1a3934);
      color: #fff;
    }
    .btn-secondary {
      background: #f3f4f6;
      color: #1a1b1f;
    }
    .btn-secondary i { color: #846241; }
    .food-deco {
      position: absolute;
      font-size: 60px;
      opacity: 0.08;
      user-select: none;
      pointer-events: none;
    }
    .food-deco:nth-child(1) { top: 20px; left: 20px; transform: rotate(-15deg); }
    .food-deco:nth-child(2) { bottom: 10px; right: 20px; transform: rotate(20deg); }
    .food-deco:nth-child(3) { top: 50%; left: 60%; transform: translateY(-50%) rotate(10deg); font-size: 40px; }
  </style>
</head>
<body>
<div class="phone-frame">
  <div class="header-bar">
    <div class="food-deco">🍕</div>
    <div class="food-deco">🍔</div>
    <div class="food-deco">🥗</div>
    <div class="icon-wrap">
      <i class="fa fa-utensils"></i>
    </div>
    <h1>Oops!</h1>
    <p>This page is not on the menu</p>
  </div>
  <div class="content">
    <div class="card">
      <h2>404 — Page Not Found</h2>
      <p>The page you're looking for doesn't exist or the URL is incorrect. Let's get you back on track.</p>
      <ul class="reason-list">
        <li><i class="fa fa-search"></i> The restaurant ID or name in the URL is invalid</li>
        <li><i class="fa fa-trash-alt"></i> The restaurant may have been removed or doesn't exist</li>
        <li><i class="fa fa-keyboard"></i> The URL might be misspelled or incomplete</li>
      </ul>
      <div class="btn-group">
        <a href="javascript:history.back()" class="btn btn-secondary"><i class="fa fa-arrow-left"></i> Go Back</a>
      </div>
    </div>
  </div>
</div>
</body>
</html>
