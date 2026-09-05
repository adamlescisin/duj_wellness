<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html($siteName ?? 'Domeček u Josefa'); ?></title>
<style>
  body { margin: 0; padding: 0; background: #f5f5f5; font-family: Arial, sans-serif; }
  .wrapper { max-width: 600px; margin: 0 auto; background: #ffffff; }
  .header { background: #2d6a4f; color: #ffffff; padding: 24px 32px; text-align: center; }
  .header h1 { margin: 0; font-size: 22px; font-weight: normal; }
  .body { padding: 32px; color: #333333; font-size: 15px; line-height: 1.6; }
  .footer { background: #f0f0f0; padding: 16px 32px; text-align: center; font-size: 12px; color: #888888; }
  .btn { display: inline-block; padding: 12px 28px; background: #2d6a4f; color: #ffffff !important;
         text-decoration: none; border-radius: 6px; font-size: 15px; margin: 8px 4px; }
  .btn-secondary { background: #c0392b; }
</style>
</head>
<body>
<div class="wrapper">
  <div class="header">
    <h1><?php echo esc_html($siteName ?? 'Domeček u Josefa'); ?></h1>
  </div>
  <div class="body">
    <?php echo $content ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput -- already escaped by TemplateRenderer ?>
  </div>
  <div class="footer">
    &copy; <?php echo esc_html(date('Y')); ?> <?php echo esc_html($siteName ?? 'Domeček u Josefa'); ?>
  </div>
</div>
</body>
</html>
