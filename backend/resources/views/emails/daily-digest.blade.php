<!doctype html>
<html lang="id">
<head><meta charset="utf-8"><title>Ringkasan follow-up</title></head>
<body style="margin:0;padding:24px;background:#F5F7FC;font-family:Arial,Helvetica,sans-serif;color:#0A0A24">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:20px;overflow:hidden">
    <tr><td style="background:#010088;color:#ffffff;padding:24px 28px">
      <div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#C9CCFF">OPTIMA CRM · {{ now()->translatedFormat('l, j F Y') }}</div>
      <div style="font-size:26px;font-weight:bold;font-style:italic;margin-top:6px">Halo {{ explode(' ', $user->name)[0] }}, ada {{ $items->count() }} follow-up hari ini.</div>
      @if ($late)<div style="margin-top:6px;color:#FFCE00;font-weight:bold">{{ $late }} di antaranya sudah lewat tenggat.</div>@endif
    </td></tr>
    <tr><td style="padding:20px 28px">
      @foreach ($items as $item)
        <div style="padding:12px 0;border-bottom:1px solid #ECEEF6">
          <div style="font-weight:bold">{{ $item['name'] }}</div>
          <div style="font-size:14px;color:#3A3E62">{{ $item['next'] ?: 'Follow-up' }}</div>
          <div style="font-size:13px;font-weight:bold;color:{{ $item['late'] ? '#8F1D21' : '#6A4300' }}">{{ $item['late'] ? 'Telat, jatuh tempo '.$item['due'] : 'Jatuh tempo hari ini' }}</div>
        </div>
      @endforeach
      <p style="margin:20px 0 0"><a href="{{ $url }}" style="display:inline-block;background:#010088;color:#ffffff;text-decoration:none;font-weight:bold;padding:12px 20px;border-radius:12px">Buka My Work</a></p>
      <p style="margin:18px 0 0;font-size:12px;color:#6A6E90">Email otomatis dari OPTIMA CRM. Pengingat yang sama juga ada di halaman My Work.</p>
    </td></tr>
  </table>
</body>
</html>
