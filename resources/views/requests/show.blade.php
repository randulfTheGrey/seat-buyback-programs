@extends('web::layouts.grids.12')

@section('title', 'Buyback Request')
@section('page_header', 'Buyback Request')
@section('page_description', $buybackRequest->public_id)

@section('full')
  @php
    $statusClass = match($buybackRequest->status->value) { 'PENDING' => 'warning', 'COMPLETED' => 'success', 'REJECTED' => 'danger', 'CANCELED' => 'secondary' };
    $quote = $buybackRequest->quote;
  @endphp
  @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
  @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

  <div class="card card-outline card-success">
    <div class="card-body text-center">
      <p class="text-uppercase text-muted mb-1">Buyback Request</p>
      <h2 class="mb-3"><code id="buyback-request-reference">{{ $buybackRequest->public_id }}</code></h2>
      <button class="btn btn-outline-primary" type="button" id="copy-buyback-request-reference" data-reference="{{ $buybackRequest->public_id }}">
        <i class="fas fa-copy mr-1" aria-hidden="true"></i> Copy reference
      </button>
      <p class="mt-3 mb-0"><strong>Use this Buyback Request reference in your EVE contract description/title.</strong></p>
    </div>
  </div>

  <div class="card card-outline card-primary">
    <div class="card-header d-flex align-items-center">
      <h3 class="card-title flex-grow-1">{{ $quote->program_name_snapshot }}</h3>
      <span class="badge badge-{{ $statusClass }}">{{ ucfirst(strtolower($buybackRequest->status->value)) }}</span>
    </div>
    <div class="card-body">
      <dl class="row mb-0">
        <dt class="col-sm-3">Buyback Request</dt><dd class="col-sm-9"><code>{{ $buybackRequest->public_id }}</code></dd>
        <dt class="col-sm-3">Submitted</dt><dd class="col-sm-9"><time datetime="{{ $buybackRequest->submitted_at->toIso8601String() }}">{{ $buybackRequest->submitted_at->format('Y-m-d H:i:s T') }}</time></dd>
        <dt class="col-sm-3">Payable total</dt><dd class="col-sm-9"><strong>{{ \RandulfTheGrey\Seat\BuybackPrograms\Support\RequesterUi::decimal($quote->payable_total) }} ISK</strong></dd>
        <dt class="col-sm-3">EVE contract ID</dt><dd class="col-sm-9">{{ $buybackRequest->eve_contract_id ?? 'Not provided' }}</dd>
        <dt class="col-sm-3">Requester note</dt><dd class="col-sm-9" style="white-space: pre-wrap">{{ $buybackRequest->requester_note ?: 'No note provided.' }}</dd>
        @if($buybackRequest->status->value === 'COMPLETED')
          <dt class="col-sm-3">Completed</dt><dd class="col-sm-9"><time datetime="{{ $buybackRequest->completed_at->toIso8601String() }}">{{ $buybackRequest->completed_at->format('Y-m-d H:i:s T') }}</time></dd>
        @elseif($buybackRequest->status->value === 'REJECTED')
          <dt class="col-sm-3">Rejection reason</dt><dd class="col-sm-9"><div class="alert alert-danger mb-0">{{ $buybackRequest->rejection_reason }}</div></dd>
        @elseif($buybackRequest->status->value === 'CANCELED')
          <dt class="col-sm-3">Canceled</dt><dd class="col-sm-9"><time datetime="{{ $buybackRequest->canceled_at->toIso8601String() }}">{{ $buybackRequest->canceled_at->format('Y-m-d H:i:s T') }}</time></dd>
        @endif
      </dl>
    </div>
  </div>

  @if($buybackRequest->isPending())
    <div class="card card-outline card-info">
      <div class="card-header"><h3 class="card-title">Next: Create your EVE contract</h3></div>
      <div class="card-body">
        <ol>
          <li>Create the appropriate Item Exchange contract.</li>
          <li>Include only the items shown in this Buyback Request.</li>
          <li>Use the quoted total of <strong>{{ \RandulfTheGrey\Seat\BuybackPrograms\Support\RequesterUi::decimal($quote->payable_total) }} ISK</strong>.</li>
          <li>Put the Buyback Request reference <code>{{ $buybackRequest->public_id }}</code> in the contract description/title.</li>
          <li>Return here and enter the EVE Contract ID below.</li>
        </ol>
        @if($quote->program?->contract_instructions)
          <div class="alert alert-info mb-0">
            <h5><i class="fas fa-file-contract" aria-hidden="true"></i> Program contract instructions</h5>
            <p class="mb-0" style="white-space: pre-wrap">{{ $quote->program->contract_instructions }}</p>
          </div>
        @endif
      </div>
    </div>

    <div class="row">
      <div class="col-lg-6 d-flex">
        <div class="card flex-fill">
          <div class="card-header"><h3 class="card-title">EVE contract</h3></div>
          <form class="d-flex flex-column flex-fill" method="post" action="{{ route('buyback.requests.contract.update', $buybackRequest) }}">
            @csrf
            @method('PATCH')
            <div class="card-body">
              <label for="eve_contract_id">EVE contract ID <span class="text-muted">(optional)</span></label>
              <input class="form-control" id="eve_contract_id" name="eve_contract_id" type="number" min="1" value="{{ old('eve_contract_id', $buybackRequest->eve_contract_id) }}">
              <small class="form-text text-muted">The ID is recorded only; no ESI lookup is performed.</small>
            </div>
            <div class="card-footer text-right"><button class="btn btn-primary" type="submit">Update contract ID</button></div>
          </form>
        </div>
      </div>
      <div class="col-lg-6 d-flex">
        <div class="card flex-fill">
          <div class="card-header"><h3 class="card-title">Requester note</h3></div>
          <form class="d-flex flex-column flex-fill" method="post" action="{{ route('buyback.requests.requester-note.update', $buybackRequest) }}">
            @csrf
            @method('PATCH')
            <div class="card-body">
              <label for="requester_note">Note to the buyback team <span class="text-muted">(optional)</span></label>
              <textarea class="form-control" id="requester_note" name="requester_note" rows="4">{{ old('requester_note', $buybackRequest->requester_note) }}</textarea>
            </div>
            <div class="card-footer text-right"><button class="btn btn-primary" type="submit">Update note</button></div>
          </form>
        </div>
      </div>
    </div>

    <div class="card card-danger card-outline">
      <div class="card-header"><h3 class="card-title">Cancel Buyback</h3></div>
      <div class="card-body d-flex justify-content-between align-items-center">
        <p class="mb-0">Cancel this Request if managers should no longer process it.</p>
        <form method="post" action="{{ route('buyback.requests.cancel', $buybackRequest) }}">
          @csrf
          <button class="btn btn-danger confirmform" type="submit" data-seat-action="cancel this Buyback Request; managers will no longer process it, and any EVE contract must also be cancelled">Cancel Buyback</button>
        </form>
      </div>
    </div>
  @else
    <div class="alert alert-secondary" role="status">This Buyback Request is final and read-only.</div>
  @endif

  <div class="card">
    <div class="card-header"><h3 class="card-title">Quoted items</h3></div>
    <div class="card-body p-0">
      @include('seat-buyback-programs::partials.quote-items', ['items' => $quote->items])
    </div>
  </div>
@stop

@push('javascript')
  <script>
    $('#copy-buyback-request-reference').on('click', function () {
      const button = this;
      const reference = button.getAttribute('data-reference');

      const copied = function () {
        button.textContent = 'Copied';
        window.setTimeout(function () { button.textContent = 'Copy reference'; }, 2000);
      };

      const fallbackCopy = function () {
        const input = document.createElement('textarea');
        input.value = reference;
        input.setAttribute('readonly', '');
        input.style.position = 'absolute';
        input.style.left = '-9999px';
        document.body.appendChild(input);
        input.select();
        document.execCommand('copy');
        document.body.removeChild(input);
        copied();
      };

      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(reference).then(copied).catch(fallbackCopy);
      } else {
        fallbackCopy();
      }
    });
  </script>
@endpush
