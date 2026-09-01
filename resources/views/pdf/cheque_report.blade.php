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

    <!-- Cheque Register Table -->
    <table class="table-card" style="font-size: 10px; width: 100%;">
      <thead>
        <tr style="background: #0B462C; color: #FFFFFF;">
          <th style="width: 20px; text-align: center; padding: 5px 3px;">#</th>
          <th style="width: 65px; padding: 5px 6px;">Cheque #</th>
          <th style="padding: 5px 7px;">Beneficiary & Purpose</th>
          <th style="width: 65px; text-align: center; padding: 5px 4px;">Status</th>
          <th style="width: 68px; padding: 5px 6px; white-space: nowrap;">Issue Date</th>
          <th style="width: 68px; padding: 5px 6px; white-space: nowrap;">Cashed Date</th>
          <th style="width: 80px; padding: 5px 6px;">Issued By</th>
          <th style="width: 85px; text-align: right; padding: 5px 7px; white-space: nowrap;">Amount</th>
        </tr>
      </thead>
      <tbody>
        @forelse($cheques as $index => $item)
          <tr style="border-bottom: 1px solid #E2E8F0; background: {{ $index % 2 == 0 ? '#FFFFFF' : '#F8FAFC' }};">
            <td style="text-align: center; font-weight: bold; color: #64748B; padding: 5px 3px; font-size: 9px;">
              {{ $index + 1 }}
            </td>
            <td style="padding: 5px 6px; font-family: monospace; font-weight: 700; color: #0F172A; font-size: 9.5px;">
              {{ $item->cheque_no }}
            </td>
            <td style="padding: 5px 7px;">
              <div style="font-weight: 700; font-size: 9.5px; color: #0F172A;">{{ $item->beneficiary }}</div>
              <div style="font-size: 8px; color: #0284C7; font-weight: 600; text-transform: capitalize; margin-top: 1px;">
                {{ str_replace('_', ' ', $item->purpose) }}
              </div>
              @if(!empty($item->description))
                <div style="font-size: 7.5px; color: #64748B; line-height: 1.1; margin-top: 1px;">
                  {{ Str::limit($item->description, 50) }}
                </div>
              @endif
            </td>
            <td style="text-align: center; padding: 5px 4px;">
              @if($item->status === 'cashed')
                <span style="background: #ECFDF5; color: #065F46; border: 1px solid #A7F3D0; padding: 1px 4px; border-radius: 3px; font-size: 7.5px; font-weight: 700;">
                  🟢 Cashed
                </span>
              @else
                <span style="background: #FFFBEB; color: #92400E; border: 1px solid #FDE68A; padding: 1px 4px; border-radius: 3px; font-size: 7.5px; font-weight: 700;">
                  🟡 Issued
                </span>
              @endif
            </td>
            <td style="color: #475569; font-size: 8.5px; padding: 5px 6px; white-space: nowrap;">
              {{ $item->issue_date ? \Carbon\Carbon::parse($item->issue_date)->format('d-M-Y') : '—' }}
            </td>
            <td style="color: #065F46; font-size: 8.5px; font-weight: 600; padding: 5px 6px; white-space: nowrap;">
              {{ $item->cashed_date ? \Carbon\Carbon::parse($item->cashed_date)->format('d-M-Y') : '—' }}
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
            <td colspan="8" style="text-align: center; padding: 25px; color: #94A3B8;">No cheque records found for the selected period.</td>
          </tr>
        @endforelse

        @if(count($cheques) > 0)
          <tr style="background: #F0FDF4; border-top: 2px solid #0B462C; font-weight: 800;">
            <td colspan="5" style="padding: 6px 7px; color: #0B462C; font-size: 9.5px; text-transform: uppercase;">
              TOTAL CHEQUES: {{ count($cheques) }} (Cashed: {{ $summary['cashed_count'] ?? 0 }}, Pending: {{ $summary['pending_count'] ?? 0 }})
            </td>
            <td colspan="2" style="text-align: right; padding: 6px 6px; font-size: 9px; color: #475569;">
              Total Value:
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
