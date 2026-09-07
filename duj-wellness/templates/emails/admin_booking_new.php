<p>Dobrý den,</p>
<p>byla přijata nová rezervace wellness:</p>

<div class="info-box">
  <strong>Referenční číslo:</strong> {{reference}}<br>
  <strong>Datum:</strong> {{booking_date}}<br>
  <strong>Čas:</strong> {{slot_from}}–{{slot_to}}<br>
  <strong>Služba:</strong> {{resource}}<br>
  <strong>Zákazník:</strong> {{customer_name}}<br>
  <strong>E-mail:</strong> {{customer_email}}<br>
  <strong>Telefon:</strong> {{customer_phone}}<br>
  <strong>Platba:</strong> {{payment_method}}<br>
  <strong>Částka:</strong> {{amount}} Kč
</div>

<div class="btn-wrap">
  <a href="{{confirm_url}}" class="btn btn-primary">✅ Potvrdit</a>
  <a href="{{reject_url}}" class="btn btn-danger">❌ Zamítnout</a>
</div>

<p>Rezervaci můžete spravovat také v administraci.</p>
<p>S pozdravem,<br><strong>{{site_name}}</strong></p>
