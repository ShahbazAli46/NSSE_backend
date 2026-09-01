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

    <!-- Director Profile Card -->
    <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 6px; padding: 8px 12px; margin-bottom: 12px; font-size: 9.5px;">
      <table style="width: 100%; border-collapse: collapse;">
        <tr>
          <td style="width: 60%; vertical-align: top;">
            <div style="color: #64748B; font-size: 8px; text-transform: uppercase; font-weight: bold;">Director / Partner Name</div>
            <div style="font-size: 12px; font-weight: 900; color: #0B462C; margin-top: 1px;">{{ $director->name }}</div>
            <div style="font-size: 8.5px; color: #475569; margin-top: 2px;">
              @if($director->cnic) CNIC: <span style="font-family: monospace; font-weight: bold;">{{ $director->cnic }}</span> @endif
              @if($director->phone) • Phone: {{ $director->phone }} @endif
            </div>
          </td>
          <td style="width: 40%; vertical-align: top; text-align: right;">
            <div style="color: #64748B; font-size: 8px; text-transform: uppercase; font-weight: bold;">Net Undrawn Balance</div>
            <div style="font-size: 13px; font-weight: 900; color: #0F172A; font-family: monospace; margin-top: 1px;">
              Rs. {{ number_format($closing_balance) }}
            </div>
            <div style="font-size: 7.5px; color: #059669; font-weight: bold; margin-top: 2px;">● ACTIVE ACCOUNT</div>
          </td>
        </tr>
      </table>
    </div>

    <!-- Chronological Transactions Table -->
    <table class="table-card" style="font-size: 9.5px; width: 100%;">
      <thead>
        <tr style="background: #0B462C; color: #FFFFFF;">
          <th style="width: 20px; text-align: center; padding: 5px 3px;">#</th>
          <th style="width: 65px; padding: 5px 6px; white-space: nowrap;">Date</th>
          <th style="padding: 5px 7px;">Particulars / Description</th>
          <th style="width: 85px; padding: 5px 6px;">Mode / Ref</th>
          <th style="width: 75px; text-align: right; padding: 5px 6px; white-space: nowrap;">Credit (+)</th>
          <th style="width: 75px; text-align: right; padding: 5px 6px; white-space: nowrap;">Debit (-)</th>
          <th style="width: 85px; text-align: right; padding: 5px 7px; white-space: nowrap;">Balance</th>
        </tr>
      </thead>
      <tbody>
        @forelse($statement as $index => $item)
          <tr style="border-bottom: 1px solid #E2E8F0; background: {{ $index % 2 == 0 ? '#FFFFFF' : '#F8FAFC' }};">
            <td style="text-align: center; font-weight: bold; color: #64748B; padding: 4px 3px; font-size: 8.5px;">
              {{ $index + 1 }}
            </td>
            <td style="color: #475569; font-size: 8px; padding: 4px 6px; white-space: nowrap;">
              {{ !empty($item['date']) ? \Carbon\Carbon::parse($item['date'])->format('d-M-Y') : '—' }}
            </td>
            <td style="padding: 4px 7px;">
              <div style="font-weight: 700; font-size: 9px; color: #0F172A;">
                {{ $item['title'] ?? 'Transaction' }}
              </div>
              @if(!empty($item['notes']) && $item['notes'] !== ($item['title'] ?? ''))
                <div style="font-size: 7.5px; color: #64748B; margin-top: 1px;">
                  {{ Str::limit($item['notes'], 50) }}
                </div>
              @endif
            </td>
            <td style="padding: 4px 6px; white-space: nowrap;">
              @if($item['type'] === 'capital_injection')
                <span style="background: #ECFDF5; color: #065F46; border: 1px solid #A7F3D0; padding: 1px 4px; border-radius: 3px; font-size: 7px; font-weight: 700;">
                  💎 Deposit
                </span>
              @elseif($item['type'] === 'capital_expense')
                <span style="background: #FFFBEB; color: #92400E; border: 1px solid #FDE68A; padding: 1px 4px; border-radius: 3px; font-size: 7px; font-weight: 700;">
                  🏗️ Usage
                </span>
              @elseif($item['type'] === 'profit_credit')
                <span style="background: #FAF5FF; color: #6B21A8; border: 1px solid #E9D5FF; padding: 1px 4px; border-radius: 3px; font-size: 7px; font-weight: 700;">
                  📈 Profit
                </span>
              @elseif($item['type'] === 'withdrawal')
                <span style="background: #FFF1F2; color: #BE123C; border: 1px solid #FECDD3; padding: 1px 4px; border-radius: 3px; font-size: 7px; font-weight: 700;">
                  💸 Drawing
                </span>
              @else
                <span style="background: #F1F5F9; color: #475569; padding: 1px 4px; border-radius: 3px; font-size: 7px; font-weight: 700;">
                  B/F
                </span>
              @endif
              @if(!empty($item['reference_no']))
                <div style="font-size: 7px; color: #64748B; font-family: monospace; margin-top: 1px;">{{ $item['reference_no'] }}</div>
              @endif
            </td>
            <td style="text-align: right; font-weight: 700; font-size: 9px; color: #065F46; white-space: nowrap; padding: 4px 6px; font-family: monospace;">
              {{ $item['credit'] > 0 ? '+ Rs. ' . number_format($item['credit']) : '—' }}
            </td>
            <td style="text-align: right; font-weight: 700; font-size: 9px; color: #BE123C; white-space: nowrap; padding: 4px 6px; font-family: monospace;">
              {{ $item['debit'] > 0 ? '- Rs. ' . number_format($item['debit']) : '—' }}
            </td>
            <td style="text-align: right; font-weight: 900; font-size: 9.5px; color: #0F172A; white-space: nowrap; padding: 4px 7px; font-family: monospace;">
              Rs. {{ number_format($item['running_balance']) }}
            </td>
          </tr>
        @empty
          <tr>
            <td colspan="7" style="text-align: center; padding: 25px; color: #94A3B8;">No transactions found for this director.</td>
          </tr>
        @endforelse

        @if(count($statement) > 0)
          <tr style="background: #F0FDF4; border-top: 2px solid #0B462C; font-weight: 800;">
            <td colspan="4" style="padding: 6px 7px; color: #0B462C; font-size: 9px; text-transform: uppercase;">
              TOTAL SUMMARY ({{ count($statement) }} ENTRIES)
            </td>
            <td style="text-align: right; padding: 6px 6px; font-size: 9px; color: #065F46; font-weight: 900; font-family: monospace; white-space: nowrap;">
              + Rs. {{ number_format($total_credits) }}
            </td>
            <td style="text-align: right; padding: 6px 6px; font-size: 9px; color: #BE123C; font-weight: 900; font-family: monospace; white-space: nowrap;">
              - Rs. {{ number_format($total_debits) }}
            </td>
            <td style="text-align: right; padding: 6px 7px; font-size: 10px; color: #0B462C; font-weight: 900; white-space: nowrap; font-family: monospace;">
              Rs. {{ number_format($closing_balance) }}
            </td>
          </tr>
        @endif
      </tbody>
    </table>
  </main>
@endsection
