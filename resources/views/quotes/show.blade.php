@extends('web::layouts.grids.12')

@section('title', 'Saved Quote')
@section('page_header', 'Saved Quote')
@section('page_description', 'Immutable financial offer')

@section('full')
  @php
    $stateClass = match($state->value) { 'AVAILABLE' => 'warning', 'SUBMITTED' => 'primary', 'EXPIRED' => 'secondary' };
    $stateLabel = match($state->value) { 'AVAILABLE' => 'Not yet submitted', 'SUBMITTED' => 'Submitted as Buyback Request', 'EXPIRED' => 'Expired — not submitted' };
  @endphp
  @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
  @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

  <div class="card card-outline card-primary">
    <div class="card-header d-flex align-items-center">
      <h3 class="card-title flex-grow-1">{{ $quote->program_name_snapshot }}</h3>
      <span class="badge badge-{{ $stateClass }}">{{ $stateLabel }}</span>
    </div>
    <div class="card-body">
      <dl class="row mb-0">
        <dt class="col-sm-3">Program</dt><dd class="col-sm-9">{{ $quote->program_name_snapshot }}</dd>
        <dt class="col-sm-3">Saved / quoted</dt><dd class="col-sm-9"><time datetime="{{ $quote->quoted_at->toIso8601String() }}">{{ $quote->quoted_at->format('Y-m-d H:i:s T') }}</time></dd>
        <dt class="col-sm-3">Expires</dt><dd class="col-sm-9"><time datetime="{{ $quote->expires_at->toIso8601String() }}">{{ $quote->expires_at->format('Y-m-d H:i:s T') }}</time></dd>
        <dt class="col-sm-3">Status</dt><dd class="col-sm-9"><strong>{{ $stateLabel }}</strong></dd>
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
    <div class="alert alert-warning" role="alert">
      <h5><i class="fas fa-exclamation-triangle" aria-hidden="true"></i> Not yet submitted</h5>
      <p class="mb-0">
        This Quote has been saved, but no Buyback Request exists yet.<br>
        <strong>Do not create your EVE contract until you create the Buyback Request.</strong>
      </p>
    </div>
    <div class="card card-success card-outline">
      <div class="card-body d-flex justify-content-between align-items-center flex-wrap">
        <a class="btn btn-outline-secondary mb-2 mb-sm-0" href="{{ route('buyback.requests.index') }}">Back to My Buybacks</a>
        <form id="submit-buyback-form" method="post" action="{{ route('buyback.quotes.submit', $quote) }}">
          @csrf
          <button type="submit" class="btn btn-success confirmform" data-seat-action="create a Buyback Request from this saved Quote">
            <i class="fas fa-paper-plane mr-1" aria-hidden="true"></i> Create Buyback Request
          </button>
        </form>
      </div>
    </div>
  @elseif($state->value === 'EXPIRED')
    <div class="alert alert-warning" role="alert">
      <h5><i class="fas fa-clock" aria-hidden="true"></i> This Quote has expired.</h5>
      <p>Run a new appraisal for current pricing. This historical Quote remains unchanged.</p>
      <a class="btn btn-primary" href="{{ route('buyback.programs.index') }}">Run a new appraisal</a>
    </div>
  @else
    <div class="alert alert-info" role="status">
      This Quote has a Buyback Request.
      <a class="btn btn-primary ml-2" href="{{ route('buyback.requests.show', $quote->buybackRequest) }}">View Buyback Request</a>
    </div>
  @endif

  @if($state->value !== 'AVAILABLE')
    <a class="btn btn-outline-secondary" href="{{ route('buyback.requests.index') }}">Back to My Buybacks</a>
  @endif
@stop

@push('javascript')
  <script>
    $('#submit-buyback-form').on('submit', function () {
      $(this).find('button[type=submit]').prop('disabled', true);
    });
  </script>
@endpush
