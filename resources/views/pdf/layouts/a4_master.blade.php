<!DOCTYPE html>
<html lang="{{ $lang ?? 'en' }}" dir="ltr">
<head>
  <meta charset="UTF-8">
  <title>{{ $doc_title ?? 'NSSE Official Document' }}</title>
  <style>
    @font-face {
      font-family: 'Jameel Noori Nastaleeq';
      src: url('{{ $font_base64 ?? "" }}') format('truetype');
      font-weight: normal;
      font-style: normal;
    }

    @import url('https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');

    :root {
      --primary: #0B462C;
      --primary-dark: #062E1C;
      --primary-light: #EBF5F0;
      --gold: #C5A059;
      --gold-dark: #9A7B2C;
      --gold-light: #FDF9EE;
      --slate: #1E293B;
      --muted: #64748B;
      --border: #CBD5E1;
      --bg: #FFFFFF;
      --card-bg: #F8FAFC;
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    @page {
      size: A4 portrait;
      margin: 5mm 7mm 8mm 7mm;
    }

    html, body {
      margin: 0;
      padding: 0;
      font-family: 'Plus Jakarta Sans', sans-serif;
      background-color: #FFFFFF;
      color: var(--slate);
      font-size: 11px;
      line-height: 1.35;
      direction: ltr;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }

    .doc-container {
      width: 100%;
      background: #FFFFFF;
      position: relative;
      box-sizing: border-box;
      padding: 0;
      margin: 0;
    }

    /* Fixed Page Footer at the Bottom of Every Page */
    .page-footer {
      position: fixed;
      bottom: -6mm;
      left: 0;
      right: 0;
      height: 6.5mm;
      background: #F8FAFC;
      border-top: 1.5px solid #C5A059;
      border-bottom: 3px solid #062E1C;
      padding: 2px 10px;
      font-size: 8.5px;
      color: #64748B;
      box-sizing: border-box;
    }

    .footer-table {
      width: 100%;
      border-collapse: collapse;
      border: none;
    }

    .footer-table td {
      padding: 0;
      border: none;
      vertical-align: middle;
    }

    /* Top Decorative Header Accent */
    .top-accent-bar {
      height: 4px;
      background: linear-gradient(90deg, #062E1C 0%, #0B462C 45%, #C5A059 80%, #9A7B2C 100%);
      width: 100%;
      border-radius: 3px 3px 0 0;
      margin-bottom: 2px;
    }

    /* Executive Centered Letterhead Header */
    .letterhead-header {
      padding: 4px 12px 3px 12px;
      border-bottom: 1.5px solid var(--gold);
      background: linear-gradient(180deg, #FFFFFF 0%, #F9FCFA 100%);
      margin-bottom: 6px;
    }

    .letterhead-flex {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 14px;
    }

    .logo-container {
      width: 62px;
      height: 62px;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }

    .logo-img {
      width: 100%;
      height: 100%;
      object-fit: contain;
    }

    .title-container {
      text-align: center;
    }

    .inst-motto {
      font-size: 11.5px;
      color: var(--primary);
      font-weight: bold;
      margin-bottom: -4px;
      font-family: 'Jameel Noori Nastaleeq', serif;
    }

    .inst-name-urdu {
      font-size: 21px;
      font-weight: bold;
      color: var(--primary-dark);
      line-height: 1.25;
      margin: 0;
      font-family: 'Jameel Noori Nastaleeq', serif;
    }

    .inst-name-eng {
      font-family: 'Cinzel', serif;
      font-size: 11px;
      font-weight: 800;
      color: var(--primary);
      letter-spacing: 1.5px;
      text-align: center;
      margin-top: 1px;
    }

    .inst-badge-row {
      display: flex;
      align-items: center;
      justify-content: center;
      margin-top: 1px;
    }

    .branch-badge {
      display: inline-block;
      background: var(--primary);
      color: #FFFFFF;
      font-family: 'Plus Jakarta Sans', sans-serif;
      font-size: 9px;
      font-weight: 700;
      padding: 1px 8px;
      border-radius: 10px;
      letter-spacing: 0.5px;
      border: 1px solid var(--gold);
    }

    /* Watermark */
    .watermark {
      position: fixed;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      width: 340px;
      height: 340px;
      opacity: 0.025;
      pointer-events: none;
      z-index: 0;
      background-image: url('{{ $logo_base64 ?? "" }}');
      background-repeat: no-repeat;
      background-size: contain;
      background-position: center;
    }

    /* Main Content Body */
    .content {
      padding: 0;
      position: relative;
      z-index: 1;
    }

    /* Document Main Heading Banner */
    .doc-banner {
      background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%);
      color: #FFFFFF;
      border-radius: 5px;
      padding: 5px 9px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 6px;
      border-left: 3.5px solid var(--gold);
    }

    .doc-title-text {
      font-size: 11.5px;
      font-weight: bold;
    }

    .doc-meta-badge {
      font-family: 'Plus Jakarta Sans', sans-serif;
      font-size: 9px;
      background: rgba(255,255,255,0.18);
      padding: 2px 6px;
      border-radius: 4px;
      border: 1px solid rgba(255,255,255,0.3);
      font-weight: 600;
    }

    /* Table Styles */
    .table-card {
      width: 100%;
      border-collapse: collapse;
      background: #FFFFFF;
      border-radius: 5px;
      border: 1px solid var(--border);
    }

    .table-card thead {
      display: table-header-group;
    }

    .table-card tr {
      page-break-inside: avoid;
    }

    .table-card th {
      background: var(--primary);
      color: #FFFFFF;
      font-weight: bold;
      padding: 5px 7px;
      font-size: 10px;
      border-bottom: 1.5px solid var(--gold);
      text-align: left;
    }

    .table-card td {
      padding: 4.5px 7px;
      border-bottom: 1px solid #E2E8F0;
      font-size: 10px;
      color: var(--slate);
      text-align: left;
    }
  </style>
  @yield('extra_css')
</head>
<body>
  <div class="doc-container">
    <!-- Background Watermark -->
    <div class="watermark"></div>

    <!-- Fixed Page Footer at the Bottom of Every Page -->
    <footer class="page-footer">
      <table class="footer-table">
        <tr>
          <td style="text-align: left; color: #475569; font-weight: 600;">
            📍 Chunian Campus, Kasur, Punjab
          </td>
          <td style="text-align: right; font-weight: 800; color: #0B462C; text-transform: uppercase; letter-spacing: 0.5px;">
            Official Audit Ledger • NSSE
          </td>
        </tr>
      </table>
    </footer>

    @yield('body')
  </div>
</body>
</html>
