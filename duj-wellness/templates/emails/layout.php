<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html($siteName ?? 'Domeček u Josefa'); ?></title>
<style>
  body, table { margin: 0; padding: 0; }
  body { background: #f4f1ed; font-family: Georgia, 'Times New Roman', serif; }
  .wrap { max-width: 620px; margin: 0 auto; padding: 24px 16px; }
  .card { background: #ffffff; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 12px rgba(0,0,0,.08); }
  /* Header */
  .hdr { background: #5b3a29; padding: 28px 36px; text-align: center; }
  .hdr img { max-height: 70px; max-width: 240px; display: block; margin: 0 auto; }
  .hdr-text { color: #f5ece4; font-size: 22px; font-weight: normal; letter-spacing: .04em; margin: 0; }
  .hdr-sub { color: #c9a98a; font-size: 13px; margin: 6px 0 0; letter-spacing: .06em; text-transform: uppercase; }
  /* Body */
  .body { padding: 36px; color: #3a2e27; font-size: 15px; line-height: 1.7; }
  .body p { margin: 0 0 1em; }
  .body strong { color: #5b3a29; }
  /* CTA buttons */
  .btn-wrap { margin: 24px 0; }
  .btn { display: inline-block; padding: 13px 30px; border-radius: 6px; font-size: 15px;
         font-family: Arial, sans-serif; font-weight: 600; text-decoration: none;
         margin: 4px 6px 4px 0; }
  .btn-primary { background: #5b3a29; color: #ffffff !important; }
  .btn-danger  { background: #a01313; color: #ffffff !important; }
  /* Info box */
  .info-box { background: #fdf8f4; border-left: 4px solid #c9a98a; border-radius: 4px;
              padding: 14px 18px; margin: 20px 0; font-size: 14px; }
  /* Divider */
  hr { border: none; border-top: 1px solid #ede8e3; margin: 24px 0; }
  /* Footer */
  .ftr { background: #3a2e27; padding: 20px 36px; text-align: center; color: #a08878; font-size: 12px; line-height: 1.6; }
  .ftr a { color: #c9a98a; text-decoration: none; }
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <div class="hdr">
      <?php if (!empty($logoUrl)): ?>
        <img src="<?php echo $logoUrl; ?>" alt="<?php echo esc_attr($siteName ?? 'Domeček u Josefa'); ?>">
      <?php else: ?>
        <p class="hdr-text"><?php echo esc_html($siteName ?? 'Domeček u Josefa'); ?></p>
        <p class="hdr-sub">Wellness &amp; Relaxace</p>
      <?php endif; ?>
    </div>
    <div class="body">
      <?php echo $content ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput -- already escaped by TemplateRenderer ?>
    </div>
  </div>
  <div class="ftr">
    &copy; <?php echo esc_html((string) date('Y')); ?> <?php echo esc_html($siteName ?? 'Domeček u Josefa'); ?><br>
    Tento e-mail byl odeslán automaticky. Prosím, neodpovídejte na něj.
  </div>
</div>
</body>
</html>
