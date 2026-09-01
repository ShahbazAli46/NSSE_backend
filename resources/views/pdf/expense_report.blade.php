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

    <!-- Itemized Expense Table -->
    <table class="table-card" style="font-size: 10px; width: 100%;">
      <thead>
        <tr style="background: #0B462C; color: #FFFFFF;">
          <th style="width: 22px; text-align: center; padding: 5px 3px;">#</th>
          <th style="padding: 5px 7px;">Expense Particulars & Scope</th>
          <th style="width: 95px; padding: 5px 6px;">Category</th>
          <th style="width: 68px; padding: 5px 6px; white-space: nowrap;">Date</th>
          <th style="width: 55px; text-align: center; padding: 5px 4px;">Tier</th>
          <th style="width: 75px; padding: 5px 6px;">Payment Mode</th>
          <th style="width: 85px; padding: 5px 6px;">Recorded By</th>
          <th style="width: 90px; text-align: right; padding: 5px 7px; white-space: nowrap;">Amount</th>
        </tr>
      </thead>
      <tbody>
        @forelse($expenses as $index => $item)
          <tr style="border-bottom: 1px solid #E2E8F0; background: {{ $index % 2 == 0 ? '#FFFFFF' : '#F8FAFC' }};">
            <td style="text-align: center; font-weight: bold; color: #64748B; padding: 5px 3px; font-size: 9px;">{{ $index + 1 }}</td>
            <td style="padding: 5px 7px;">
              <div style="font-weight: 700; font-size: 9.5px; color: #0F172A;">{{ $item->title }}</div>
              @if(!empty($item->description))
                <div style="font-size: 8px; color: #64748B; line-height: 1.2; margin-top: 1px;">{{ Str::limit($item->description, 60) }}</div>
              @endif
            </td>
            <td style="color: #334155; font-size: 8.5px; font-weight: 600; padding: 5px 6px;">{{ $item->category }}</td>
            <td style="color: #64748B; font-size: 8.5px; padding: 5px 6px; white-space: nowrap;">{{ \Carbon\Carbon::parse($item->created_at)->format('d-M-Y') }}</td>
            <td style="text-align: center; padding: 5px 4px;">
              @if($item->amount_tier == 1)
                <span style="background: #ECFDF5; color: #065F46; border: 1px solid #A7F3D0; padding: 1px 4px; border-radius: 3px; font-size: 7.5px; font-weight: 700;">🟢 Micro</span>
              @elseif($item->amount_tier == 2)
                <span style="background: #F0F9FF; color: #075985; border: 1px solid #BAE6FD; padding: 1px 4px; border-radius: 3px; font-size: 7.5px; font-weight: 700;">🔵 Oper</span>
              @elseif($item->amount_tier == 3)
                <span style="background: #FFFBEB; color: #92400E; border: 1px solid #FDE68A; padding: 1px 4px; border-radius: 3px; font-size: 7.5px; font-weight: 700;">🟠 Mod</span>
              @else
                <span style="background: #FEF2F2; color: #991B1B; border: 1px solid #FECACA; padding: 1px 4px; border-radius: 3px; font-size: 7.5px; font-weight: 700;">🔴 High</span>
              @endif
            </td>
            <td style="color: #475569; font-size: 8.5px; padding: 5px 6px; white-space: nowrap;">
              {{ $item->payment_method ?? 'Cash' }}
              @if($item->payment_method === 'Cheque' && $item->cheque)
                <div style="font-size: 7.5px; color: #0284C7; font-family: monospace;">#{{ $item->cheque->cheque_no }}</div>
              @endif
            </td>
            <td style="color: #0F172A; font-size: 8.5px; font-weight: 600; padding: 5px 6px;">
              {{ $item->creator->name ?? 'Accountant' }}
            </td>
            <td style="text-align: right; font-weight: 900; font-size: 10px; color: #0F172A; white-space: nowrap; padding: 5px 7px; font-family: monospace;">
              Rs. {{ number_format($item->amount) }}
            </td>
          </tr>
        @empty
          <tr>
            <td colspan="8" style="text-align: center; padding: 25px; color: #94A3B8;">No records found for the selected period.</td>
          </tr>
        @endforelse

        @if(count($expenses) > 0)
          <tr style="background: #F0FDF4; border-top: 2px solid #0B462C; font-weight: 800;">
            <td colspan="5" style="padding: 6px 7px; color: #0B462C; font-size: 9.5px; text-transform: uppercase;">
              GRAND TOTAL ({{ count($expenses) }} RECORD{{ count($expenses) > 1 ? 'S' : '' }})
            </td>
            <td colspan="2" style="text-align: right; padding: 6px 6px; font-size: 9px; color: #475569;">
              Audited Net Total:
            </td>
            <td style="text-align: right; padding: 6px 7px; font-size: 10.5px; color: #0B462C; font-weight: 900; white-space: nowrap; font-family: monospace;">
              Rs. {{ number_format($total_amount) }}
            </td>
          </tr>
        @endif
      </tbody>
    </table>
  </main>
@endsection
