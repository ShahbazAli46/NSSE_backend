@extends('pdf.layouts.a4_master')

@section('content')
  @if(($lang ?? 'ur') === 'en')
    <!-- English Sample Body -->
    <div style="background: #FFFFFF; border: 1px solid #CBD5E1; border-radius: 8px; padding: 14px 18px; margin-bottom: 10px;">
      <h3 style="color: #0B462C; font-size: 16px; margin-bottom: 6px; font-weight: 800;">
        Institutional Governance & Expenditure Verification
      </h3>
      <p style="font-size: 13.5px; color: #475569; line-height: 1.6;">
        This document serves as the official financial authorization record for Nawaz Sharif School of Eminence (Chunian Campus). All figures, voucher references, and statutory disbursements have been verified by the Board of Directors.
      </p>
    </div>

    <!-- Table -->
    <table class="table-card" style="margin-bottom: 12px;">
      <thead>
        <tr>
          <th>#</th>
          <th>Expense Category</th>
          <th>Scope / Description</th>
          <th style="text-align: right;">Amount (PKR)</th>
          <th style="text-align: center;">Status</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td style="font-weight: bold;">01</td>
          <td style="font-weight: bold; color: #0B462C;">Utilities & Bills</td>
          <td>Campus electricity, water filtration, and high-speed fiber internet.</td>
          <td style="text-align: right; font-weight: bold;">Rs. 28,900</td>
          <td style="text-align: center;"><span style="background: #EBF5F0; color: #0B462C; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: bold;">DISBURSED</span></td>
        </tr>
        <tr>
          <td style="font-weight: bold;">02</td>
          <td style="font-weight: bold; color: #0B462C;">Campus Maintenance</td>
          <td>Civil repairs, HVAC maintenance, and electrical works.</td>
          <td style="text-align: right; font-weight: bold;">Rs. 24,050</td>
          <td style="text-align: center;"><span style="background: #EBF5F0; color: #0B462C; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: bold;">DISBURSED</span></td>
        </tr>
        <tr>
          <td style="font-weight: bold;">03</td>
          <td style="font-weight: bold; color: #0B462C;">Academic Supplies</td>
          <td>Exam paper printing, stationery, and laboratory registers.</td>
          <td style="text-align: right; font-weight: bold;">Rs. 10,900</td>
          <td style="text-align: center;"><span style="background: #EBF5F0; color: #0B462C; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: bold;">DISBURSED</span></td>
        </tr>
      </tbody>
    </table>

  @else
    <!-- Urdu Sample Body -->
    <div style="background: #FFFFFF; border: 1px solid #CBD5E1; border-radius: 8px; padding: 14px 18px; margin-bottom: 10px;">
      <h3 style="color: #0B462C; font-size: 18px; margin-bottom: 4px; font-weight: bold;">
        مالیاتی شفافیت اور ادارہ جاتی اخراجات کا تفصیلی ریکارڈ
      </h3>
      <p style="font-size: 14.5px; color: #475569; line-height: 2.1;">
        نواز شریف اسکول آف ایمینینس (چونیاں کیمپس) کے مالیاتی معاملات کو اعلیٰ ترین شفافیت، باہمی منظوری اور ڈیجیٹل آڈٹ کے تحت چلایا جا رہا ہے۔ درج ذیل اخراجات باقاعدہ منظوری کے بعد ادا کیے جا چکے ہیں۔
      </p>
    </div>

    <!-- Table -->
    <table class="table-card" style="margin-bottom: 12px;">
      <thead>
        <tr>
          <th>نمبر</th>
          <th>اخراجات کی مد (Category)</th>
          <th>تفصیل و دائرہ کار (Scope)</th>
          <th style="text-align: left;">کل رقم (PKR)</th>
          <th style="text-align: center;">اسٹیٹس</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td style="font-weight: bold;">01</td>
          <td style="font-weight: bold; color: #0B462C;">یوٹیلیٹیز و بلز</td>
          <td>کیمپس بجلی، پانی سپلائی و انٹرنیٹ بلز۔</td>
          <td style="text-align: left; font-weight: bold;">Rs. 28,900</td>
          <td style="text-align: center;"><span style="background: #EBF5F0; color: #0B462C; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: bold;">ادا شدہ (PAID)</span></td>
        </tr>
        <tr>
          <td style="font-weight: bold;">02</td>
          <td style="font-weight: bold; color: #0B462C;">کیمپس مرمت و دیکھ بھال</td>
          <td>بلڈنگ مرمت، پلمبنگ اور الیکٹریکل کام۔</td>
          <td style="text-align: left; font-weight: bold;">Rs. 24,050</td>
          <td style="text-align: center;"><span style="background: #EBF5F0; color: #0B462C; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: bold;">ادا شدہ (PAID)</span></td>
        </tr>
        <tr>
          <td style="font-weight: bold;">03</td>
          <td style="font-weight: bold; color: #0B462C;">تعلیمی سامان و کتب</td>
          <td>امتحانی پیپرز، رجسٹرز و لائبریری سامان۔</td>
          <td style="text-align: left; font-weight: bold;">Rs. 10,900</td>
          <td style="text-align: center;"><span style="background: #EBF5F0; color: #0B462C; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: bold;">ادا شدہ (PAID)</span></td>
        </tr>
      </tbody>
    </table>
  @endif
@endsection
