@extends('web::layouts.grids.12')

@section('title', 'Buyback Quote')
@section('page_header', 'Buyback Quote')
@section('page_description', $quote->public_id)

@section('full')
  @php
    $stateClass = match($state->value) { 'AVAILABLE' => 'success', 'SUBMITTED' => 'primary', 'EXPIRED' => 'secondary' };
  @endphp
  <div class="card card-outline card-primary">
    <div class="card-header d-flex align-items-center">
      <h3 class="card-title flex-grow-1">{{ $quote->program_name_snapshot }}</h3>
      <span class="badge badge-{{ $stateClass }}">{{ ucfirst(strtolower($state->value)) }}</span>
    </div>
    <div class="card-body">
      <dl class="row mb-0">
        <dt class="col-sm-3">Quote reference</dt><dd class="col-sm-9"><code>{{ $quote->public_id }}</code></dd>
        <dt class="col-sm-3">Quoted</dt><dd class="col-sm-9"><time datetime="{{ $quote->quoted_at->toIso8601String() }}">{{ $quote->quoted_at->format('Y-m-d H:i:s T') }}</time></dd>
        <dt class="col-sm-3">Pricing completed</dt><dd class="col-sm-9"><time datetime="{{ $quote->pricing_completed_at->toIso8601String() }}">{{ $quote->pricing_completed_at->format('Y-m-d H:i:s T') }}</time></dd>
        <dt class="col-sm-3">Expires</dt><dd class="col-sm-9"><time datetime="{{ $quote->expires_at->toIso8601String() }}">{{ $quote->expires_at->format('Y-m-d H:i:s T') }}</time></dd>
        <dt class="col-sm-3">Payable total</dt><dd class="col-sm-9"><strong>{{ \RandulfTheGrey\Seat\BuybackPrograms\Support\RequesterUi::decimal($quote->payable_total) }} ISK</strong></dd>
      </dl>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3 class="card-title">Payable Quote items</h3></div>
    <div class="card-body p-0">
      @include('seat-buyback-programs::partials.quote-items', ['items' => $quote->items])
    </div>
  </div>

  @if($state->value === 'AVAILABLE')
    <div class="card card-success card-outline">
      <div class="card-header"><h3 class="card-title">Submit Buyback</h3></div>
      <form id="submit-buyback-form" method="post" action="{{ route('buyback.quotes.submit', $quote) }}">
        @csrf
        <div class="card-body">
          <p>
            You are submitting an immutable Quote for
            <strong>{{ \RandulfTheGrey\Seat\BuybackPrograms\Support\RequesterUi::decimal($quote->payable_total) }} ISK</strong>.
            Create the EVE contract with only the quoted items shown above.
          </p>
          @if($quote->program?->contract_instructions)
            <div class="alert alert-info">
              <h5><i class="fas fa-file-contract" aria-hidden="true"></i> Contract instructions</h5>
              <p class="mb-0" style="white-space: pre-wrap">{{ $quote->program->contract_instructions }}</p>
            </div>
          @endif
          <div class="form-group">
            <label for="eve_contract_id">EVE contract ID <span class="text-muted">(optional)</span></label>
            <input class="form-control" id="eve_contract_id" name="eve_contract_id" type="number" min="1" value="{{ old('eve_contract_id') }}">
          </div>
          <div class="form-group">
            <label for="requester_note">Note to the buyback team <span class="text-muted">(optional)</span></label>
            <textarea class="form-control" id="requester_note" name="requester_note" rows="4">{{ old('requester_note') }}</textarea>
          </div>
        </div>
        <div class="card-footer text-right">
          <button type="submit" class="btn btn-success">
            <i class="fas fa-paper-plane mr-1" aria-hidden="true"></i> Submit Buyback
          </button>
        </div>
      </form>
    </div>
  @elseif($state->value === 'EXPIRED')
    <div class="alert alert-warning" role="alert">
      <h5><i class="fas fa-clock" aria-hidden="true"></i> This Quote has expired.</h5>
      <p>Run a new appraisal for current pricing. This historical Quote remains unchanged.</p>
      <a class="btn btn-primary" href="{{ route('buyback.programs.index') }}">Run a new appraisal</a>
    </div>
  @else
    <div class="alert alert-info" role="status">
      This Quote was submitted as a Buyback Request.
      <a class="btn btn-primary ml-2" href="{{ route('buyback.requests.show', $quote->buybackRequest) }}">View Buyback Request</a>
    </div>
  @endif
@stop

@push('javascript')
  <script>
    $('#submit-buyback-form').on('submit', function (event) {
      if (!window.confirm('Submit this immutable Quote as a Buyback Request? Contract only the quoted items.')) {
        event.preventDefault();
        return;
      }
      $(this).find('button[type=submit]').prop('disabled', true);
    });
  </script>
@endpush
