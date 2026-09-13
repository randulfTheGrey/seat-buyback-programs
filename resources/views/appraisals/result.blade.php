@extends('web::layouts.grids.12')

@section('title', 'Appraisal Results')
@section('page_header', 'Appraisal Results')
@section('page_description', $appraisal->programName)

@section('full')
  @foreach($appraisal->warnings as $warning)
    <div class="alert alert-warning" role="status">
      <i class="fas fa-exclamation-triangle" aria-hidden="true"></i> {{ $warning }}
    </div>
  @endforeach

  <div class="card card-outline card-primary">
    <div class="card-header"><h3 class="card-title">Summary</h3></div>
    <div class="card-body">
      <div class="row text-center">
        <div class="col-sm-4 col-lg-2"><strong class="d-block h4">{{ $resolvedCount }}</strong>resolved item types</div>
        <div class="col-sm-4 col-lg-2"><strong class="d-block h4 text-success">{{ $counts['PRICED'] }}</strong>included in Quote</div>
        <div class="col-sm-4 col-lg-2"><strong class="d-block h4">{{ $counts['EXCLUDED'] }}</strong>excluded</div>
        <div class="col-sm-4 col-lg-2"><strong class="d-block h4">{{ $counts['UNPRICED'] }}</strong>unpriced</div>
        <div class="col-sm-4 col-lg-2"><strong class="d-block h4">{{ $counts['REFERENCE_UNAVAILABLE'] }}</strong>reference unavailable</div>
        <div class="col-sm-4 col-lg-2"><strong class="d-block h4">{{ $counts['UNKNOWN'] + $counts['INVALID_INPUT'] }}</strong>unrecognized or invalid</div>
      </div>
      <hr>
      <p class="lead mb-0">
        Quoteable total:
        <strong>{{ \RandulfTheGrey\Seat\BuybackPrograms\Support\RequesterUi::decimal($appraisal->quoteableTotal) }} ISK</strong>
      </p>
      <small class="text-muted">Only priced lines contribute to this total.</small>
    </div>
  </div>

  @if($counts['REFERENCE_UNAVAILABLE'] > 0)
    @foreach($linesByStatus->get('REFERENCE_UNAVAILABLE', collect())->groupBy(fn ($line) => $line->logicalReferenceMode?->value ?? 'Reference') as $mode => $lines)
      <div class="alert alert-warning" role="alert">
        <strong>{{ $mode }} reference pricing is temporarily unavailable.</strong>
        {{ $lines->count() }} accepted {{ \Illuminate\Support\Str::plural('item', $lines->count()) }} could not be priced.
        These items are not included in the Quote; run a new appraisal later.
      </div>
    @endforeach
  @endif

  @if($counts['PRICED'] > 0)
    <div class="card card-success card-outline">
      <div class="card-header"><h3 class="card-title">Priced and payable</h3></div>
      <div class="card-body table-responsive p-0">
        <table class="table table-striped mb-0">
          <thead><tr><th>Item</th><th class="text-right">Quantity</th><th>Reference</th><th class="text-right">Reference value</th><th>Adjustment</th><th class="text-right">Buyback unit</th><th class="text-right">Line total</th><th>Why?</th></tr></thead>
          <tbody>
          @foreach($linesByStatus->get('PRICED', collect()) as $line)
            <tr>
              <td>{{ $line->typeName }}</td>
              <td class="text-right">{{ number_format($line->quantity) }}</td>
              <td>{{ $line->logicalReferenceMode->value }}</td>
              <td class="text-right">{{ \RandulfTheGrey\Seat\BuybackPrograms\Support\RequesterUi::decimal($line->referenceUnitPrice) }} ISK</td>
              <td>{{ \RandulfTheGrey\Seat\BuybackPrograms\Support\RequesterUi::modifier($line->effectiveModifierBps) }}</td>
              <td class="text-right">{{ \RandulfTheGrey\Seat\BuybackPrograms\Support\RequesterUi::decimal($line->finalUnitPrice) }} ISK</td>
              <td class="text-right"><strong>{{ \RandulfTheGrey\Seat\BuybackPrograms\Support\RequesterUi::decimal($line->lineTotal) }} ISK</strong></td>
              <td>
                <details>
                  <summary class="text-primary">Why this price?</summary>
                  <div class="mt-2" style="min-width: 18rem">
                    @include('seat-buyback-programs::partials.policy-explanation', ['policy' => $line->effectivePolicy])
                  </div>
                </details>
              </td>
            </tr>
          @endforeach
          </tbody>
        </table>
      </div>
    </div>
  @endif

  @if($counts['EXCLUDED'] > 0)
    <div class="card card-secondary card-outline">
      <div class="card-header"><h3 class="card-title">Excluded by Program policy</h3></div>
      <div class="card-body">
        <p><strong>These items are not included in the Quote and should not be included in the eventual contract.</strong></p>
        @foreach($linesByStatus->get('EXCLUDED', collect()) as $line)
          <details class="mb-2">
            <summary>{{ $line->typeName }} × {{ number_format($line->quantity) }} — Excluded by effective policy</summary>
            <div class="pl-3 pt-2">
              @include('seat-buyback-programs::partials.policy-explanation', ['policy' => $line->effectivePolicy])
            </div>
          </details>
        @endforeach
      </div>
    </div>
  @endif

  @if($counts['UNPRICED'] > 0)
    <div class="card card-warning card-outline">
      <div class="card-header"><h3 class="card-title">Accepted but unpriced</h3></div>
      <div class="card-body">
        <p><strong>Accepted by policy but not currently priced.</strong> These items are not included in the Quote. You may run a new appraisal later.</p>
        <ul class="mb-0">
          @foreach($linesByStatus->get('UNPRICED', collect()) as $line)
            <li>{{ $line->typeName }} × {{ number_format($line->quantity) }}</li>
          @endforeach
        </ul>
      </div>
    </div>
  @endif

  @if($counts['REFERENCE_UNAVAILABLE'] > 0)
    <div class="card card-warning card-outline">
      <div class="card-header"><h3 class="card-title">Reference temporarily unavailable</h3></div>
      <div class="card-body">
        <ul class="mb-0">
          @foreach($linesByStatus->get('REFERENCE_UNAVAILABLE', collect()) as $line)
            <li>{{ $line->typeName }} × {{ number_format($line->quantity) }} — {{ $line->logicalReferenceMode->value }} reference</li>
          @endforeach
        </ul>
      </div>
    </div>
  @endif

  @if($counts['UNKNOWN'] > 0)
    <div class="card card-danger card-outline">
      <div class="card-header"><h3 class="card-title">Unrecognized items</h3></div>
      <div class="card-body">
        <p>These item names could not be resolved exactly. Correct the original input and run a new appraisal; no fuzzy substitution was made.</p>
        <ul class="mb-0">
          @foreach($linesByStatus->get('UNKNOWN', collect()) as $line)
            <li><code>{{ implode(' | ', $line->rawLines) }}</code></li>
          @endforeach
        </ul>
      </div>
    </div>
  @endif

  @if($counts['INVALID_INPUT'] > 0)
    <div class="card card-danger card-outline">
      <div class="card-header"><h3 class="card-title">Invalid input lines</h3></div>
      <div class="card-body">
        <ul class="mb-0">
          @foreach($linesByStatus->get('INVALID_INPUT', collect()) as $line)
            <li><code>{{ implode(' | ', $line->rawLines) }}</code> — {{ \RandulfTheGrey\Seat\BuybackPrograms\Support\RequesterUi::inputError($line->inputError) }}</li>
          @endforeach
        </ul>
      </div>
    </div>
  @endif

  <div class="card">
    <div class="card-body d-flex justify-content-between align-items-center flex-wrap">
      <a class="btn btn-default mb-2 mb-sm-0" href="{{ route('buyback.appraisals.create', $program) }}">
        Correct input / run a new appraisal
      </a>
      @if($counts['PRICED'] > 0)
        <div class="text-right">
          <p class="mb-2">
            <strong>{{ $counts['PRICED'] }} payable item types · {{ \RandulfTheGrey\Seat\BuybackPrograms\Support\RequesterUi::decimal($appraisal->quoteableTotal) }} ISK</strong><br>
            <small class="text-muted">
              Only payable lines enter the Quote; {{ array_sum($counts) - $counts['PRICED'] }} other outcomes remain appraisal-only.
            </small>
          </p>
          <form class="buyback-single-submit" method="post" action="{{ route('buyback.quotes.store') }}">
            @csrf
            <input type="hidden" name="appraisal_token" value="{{ $appraisalToken }}">
            <button type="submit" class="btn btn-success">
              <i class="fas fa-file-invoice-dollar mr-1" aria-hidden="true"></i> Create Quote
            </button>
          </form>
        </div>
      @else
        <div class="alert alert-info mb-0" role="status">
          No payable items are available, so a Quote cannot be created.
        </div>
      @endif
    </div>
  </div>
@stop

@push('javascript')
  <script>
    $('.buyback-single-submit').on('submit', function () {
      $(this).find('button[type=submit]').prop('disabled', true)
        .prepend('<i class="fas fa-spinner fa-spin mr-1" aria-hidden="true"></i>');
    });
  </script>
@endpush
