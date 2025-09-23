<?php declare(strict_types=1);
// community.php — basic scaffold with shared sidebar
?>
<!DOCTYPE html>
<html lang="uk">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Community</title>
  <style>
    body{font-family:Inter, Arial, sans-serif; margin:0; background:#f6f7fb; color:#111}
    .container{max-width:1100px; margin:24px auto; padding:0 16px}
    .card{background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:18px; box-shadow:0 6px 16px rgba(0,0,0,0.05)}
    .title{margin:0 0 8px 0; font-size:22px; font-weight:800}
    .muted{color:#6b7280}
  </style>
  <?php /* Sidebar adds left shift via CSS var */ ?>
</head>
<body>
<?php include "elements.php"; ?>
<div class="container">
  <div class="card">
    <h1 class="title">Community</h1>
    <div class="muted">Coming soon. Here you will be able to share progress, tips, and challenges with others.</div>
  </div>
</div>
</body>
</html>
