@extends('pdf.layouts.a4_master')

@section('body')
  <!-- Top Accent Bar -->
  <div class="top-accent-bar" style="background: linear-gradient(90deg, #6B21A8, #0B462C);"></div>

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
          <span class="branch-badge" style="background: #F3E8FF; color: #6B21A8; border-color: #D8B4FE;">DIRECTOR EQUITY & CAPITAL AUDIT</span>
        </div>
      </div>
    </div>
  </header>

  <main class="content">
    @if(!empty($doc_title))
      <div class="doc-banner" style="border-left-color: #6B21A8;">
        <div class="doc-title-text" style="color: #6B21A8;">{{ $doc_title }}</div>
        @if(!empty($doc_ref) || !empty($doc_date))
          <div class="doc-meta-badge">
            {{ $doc_ref ?? '' }} {{ !empty($doc_ref) && !empty($doc_date) ? '•' : '' }} {{ $doc_date ?? date('d-M-Y') }}
          </div>
        @endif
      </div>
    @endif

    <!-- Itemized Director Expense Table -->
    <table class="table-card" style="font-size: 10px; width: 100%;">
      <thead>
        <tr style="background: #581C87; color: #FFFFFF;">
          <th style="width: 22px; text-align: center; padding: 5px 3px;">#</th>
          <th style="width: 105px; padding: 5px 6px;">Paid By Director</th>
          <th style="padding: 5px 7px;">Asset / Expense Particulars</th>
          <th style="width: 90px; padding: 5px 6px;">Category</th>
          <th style="width: 68px; padding: 5px 6px; white-space: nowrap;">Date</th>
          <th style="width: 80px; padding: 5px 6px;">Payment Method</th>
          <th style="width: 90px; text-align: right; padding: 5px 7px; white-space: nowrap;">Amount (PKR)</th>
        </tr>
      </thead>
      <tbody>
        @forelse($expenses as $index => $item)
          <tr style="border-bottom: 1px solid #E2E8F0; background: {{ $index % 2 == 0 ? '#FFFFFF' : '#FAF5FF' }};">
            <td style="text-align: center; font-weight: bold; color: #64748B; padding: 5px 3px; font-size: 9px;">{{ $index + 1 }}</td>
            <td style="padding: 5px 6px; font-weight: 800; color: #581C87; font-size: 9.5px;">
              {{ $item->director->name ?? 'Executive Director' }}
            </td>
            <td style="padding: 5px 7px;">
              <div style="font-weight: 700; font-size: 9.5px; color: #0F172A;">{{ $item->title }}</div>
              @if(!empty($item->description) && $item->description !== $item->title)
                <div style="font-size: 8px; color: #64748B; line-height: 1.2; margin-top: 1px;">{{ Str::limit($item->description, 60) }}</div>
              @endif
              @if(!empty($item->payment_reference))
                <div style="font-size: 7.5px; color: #8B5CF6; font-family: monospace;">Ref: {{ $item->payment_reference }}</div>
              @endif
            </td>
            <td style="color: #334155; font-size: 8.5px; font-weight: 600; padding: 5px 6px;">{{ $item->category }}</td>
            <td style="color: #64748B; font-size: 8.5px; padding: 5px 6px; white-space: nowrap;">{{ \Carbon\Carbon::parse($item->disbursed_at ?? $item->created_at)->format('d-M-Y') }}</td>
            <td style="color: #475569; font-size: 8.5px; padding: 5px 6px; white-space: nowrap;">
              {{ $item->payment_method ?? 'Director Funds' }}
            </td>
            <td style="text-align: right; font-weight: 900; font-size: 10px; color: #052E16; white-space: nowrap; padding: 5px 7px; font-family: monospace;">
              Rs. {{ number_format($item->amount) }}
            </td>
          </tr>
        @empty
          <tr>
            <td colspan="7" style="text-align: center; padding: 25px; color: #94A3B8;">No director-paid expenses recorded for this period.</td>
          </tr>
        @endforelse

        @if(count($expenses) > 0)
          <tr style="background: #F3E8FF; border-top: 2px solid #581C87; font-weight: 800;">
            <td colspan="4" style="padding: 6px 7px; color: #581C87; font-size: 9.5px; text-transform: uppercase;">
              TOTAL CAPITAL EXPENSES INJECTED ({{ count($expenses) }} ITEM{{ count($expenses) > 1 ? 'S' : '' }})
            </td>
            <td colspan="2" style="text-align: right; padding: 6px 6px; font-size: 9px; color: #475569;">
              Cumulative Capital Total:
            </td>
            <td style="text-align: right; padding: 6px 7px; font-size: 10.5px; color: #581C87; font-weight: 900; white-space: nowrap; font-family: monospace;">
              Rs. {{ number_format($total_amount) }}
            </td>
          </tr>
        @endif
      </tbody>
    </table>
  </main>
@endsection
