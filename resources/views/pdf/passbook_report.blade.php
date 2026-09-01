@extends('pdf.layouts.a4_master')

@section('body')
  <!-- Top Accent Bar -->
  <div class="top-accent-bar"></div>

  <!-- Official Letterhead Header -->
  <header class="letterhead-header">
    <div class="letterhead-flex">
      <div class="logo-container">
        <img src="{{ $logo_base64 }}" alt="NSSE Logo" class="logo-img">
      </div>
      <div class="title-container">
        <div class="inst-motto">
          {{ $header_motto ?? 'رَبِّ زِدْنِي عِلْمًا • تعلیمی شاندار روایات کا امین' }}
        </div>
        <h1 class="inst-name-urdu">نواز شریف اسکول آف ایمینینس</h1>
        <div class="inst-name-eng">NAWAZ SHARIF SCHOOL OF EMINENCE</div>
        <div class="inst-badge-row">
          <span class="branch-badge">چونیاں کیمپس • CHUNIAN CAMPUS</span>
        </div>
      </div>
    </div>
  </header>

  <main class="content">
    @if(!empty($doc_title))
      <div class="doc-banner">
        <div class="doc-title-text">{{ $doc_title }}</div>
        @if(!empty($doc_ref) || !empty($doc_date))
          <div class="doc-meta-badge">
            {{ $doc_ref ?? '' }} {{ !empty($doc_ref) && !empty($doc_date) ? '•' : '' }} {{ $doc_date ?? date('d-M-Y') }}
          </div>
        @endif
      </div>
    @endif

    <!-- Passbook / Bank Statement Table -->
    <table class="table-card" style="font-size: 10px; width: 100%;">
      <thead>
        <tr style="background: #0B462C; color: #FFFFFF;">
          <th style="width: 22px; text-align: center; padding: 5px 3px;">#</th>
          <th style="width: 70px; padding: 5px 6px; white-space: nowrap;">Date</th>
          <th style="padding: 5px 7px;">Transaction Particulars & Reference</th>
          <th style="width: 85px; padding: 5px 6px;">Category</th>
          <th style="width: 80px; text-align: right; padding: 5px 6px; white-space: nowrap;">Credit (+)</th>
          <th style="width: 80px; text-align: right; padding: 5px 6px; white-space: nowrap;">Debit (-)</th>
          <th style="width: 90px; text-align: right; padding: 5px 7px; white-space: nowrap;">Bank Balance</th>
        </tr>
      </thead>
      <tbody>
        @forelse($statement as $index => $item)
          <tr style="border-bottom: 1px solid #E2E8F0; background: {{ $index % 2 == 0 ? '#FFFFFF' : '#F8FAFC' }};">
            <td style="text-align: center; font-weight: bold; color: #64748B; padding: 5px 3px; font-size: 9px;">
              {{ $index + 1 }}
            </td>
            <td style="color: #475569; font-size: 8.5px; padding: 5px 6px; white-space: nowrap;">
              {{ !empty($item['date']) ? \Carbon\Carbon::parse($item['date'])->format('d-M-Y') : '—' }}
            </td>
            <td style="padding: 5px 7px;">
              <div style="font-weight: 700; font-size: 9.5px; color: #0F172A;">{{ $item['title'] }}</div>
              @if(!empty($item['reference']))
                <div style="font-size: 8px; color: #64748B; font-family: monospace; margin-top: 1px;">
                  Ref / Cheque: {{ $item['reference'] }}
                </div>
              @endif
              @if(!empty($item['notes']))
                <div style="font-size: 7.5px; color: #94A3B8; margin-top: 1px;">
                  {{ Str::limit($item['notes'], 50) }}
                </div>
              @endif
            </td>
            <td style="padding: 5px 6px; white-space: nowrap;">
              @if($item['type'] === 'credit')
                <span style="background: #FAF5FF; color: #6B21A8; border: 1px solid #E9D5FF; padding: 1px 4px; border-radius: 3px; font-size: 7.5px; font-weight: 700;">
                  🏛️ {{ $item['category'] }}
                </span>
              @else
                <span style="background: #F0F9FF; color: #0369A1; border: 1px solid #BAE6FD; padding: 1px 4px; border-radius: 3px; font-size: 7.5px; font-weight: 700;">
                  💳 {{ $item['category'] }}
                </span>
              @endif
            </td>
            <td style="text-align: right; font-weight: 700; font-size: 9.5px; color: #065F46; white-space: nowrap; padding: 5px 6px; font-family: monospace;">
              {{ $item['credit'] > 0 ? '+ Rs. ' . number_format($item['credit']) : '—' }}
            </td>
            <td style="text-align: right; font-weight: 700; font-size: 9.5px; color: #BE123C; white-space: nowrap; padding: 5px 6px; font-family: monospace;">
              {{ $item['debit'] > 0 ? '- Rs. ' . number_format($item['debit']) : '—' }}
            </td>
            <td style="text-align: right; font-weight: 900; font-size: 10px; color: #0F172A; white-space: nowrap; padding: 5px 7px; font-family: monospace;">
              Rs. {{ number_format($item['running_balance']) }}
            </td>
          </tr>
        @empty
          <tr>
            <td colspan="7" style="text-align: center; padding: 25px; color: #94A3B8;">No passbook transactions found for the selected period.</td>
          </tr>
        @endforelse

        @if(count($statement) > 0)
          <tr style="background: #F0FDF4; border-top: 2px solid #0B462C; font-weight: 800;">
            <td colspan="4" style="padding: 6px 7px; color: #0B462C; font-size: 9.5px; text-transform: uppercase;">
              SUMMARY ({{ count($statement) }} TRANSACTIONS)
            </td>
            <td style="text-align: right; padding: 6px 6px; font-size: 9.5px; color: #065F46; font-weight: 900; font-family: monospace; white-space: nowrap;">
              + Rs. {{ number_format($total_credits) }}
            </td>
            <td style="text-align: right; padding: 6px 6px; font-size: 9.5px; color: #BE123C; font-weight: 900; font-family: monospace; white-space: nowrap;">
              - Rs. {{ number_format($total_debits) }}
            </td>
            <td style="text-align: right; padding: 6px 7px; font-size: 10.5px; color: #0B462C; font-weight: 900; white-space: nowrap; font-family: monospace;">
              Rs. {{ number_format($closing_balance) }}
            </td>
          </tr>
        @endif
      </tbody>
    </table>
  </main>
@endsection
