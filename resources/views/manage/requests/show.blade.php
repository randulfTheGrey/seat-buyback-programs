@extends('web::layouts.grids.12')

@section('title', 'Manage Buyback Request')
@section('page_header', 'Manage Buyback Request')
@section('page_description', $buybackRequest->public_id)

@section('full')
  @php
    $statusClass = match($buybackRequest->status->value) { 'PENDING' => 'warning', 'COMPLETED' => 'success', 'REJECTED' => 'danger', 'CANCELED' => 'secondary' };
    $quote = $buybackRequest->quote;
  @endphp

  @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
  @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
  @if($errors->any())
    <div class="alert alert-danger">
      <ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
  @endif

  <div class="card card-outline card-primary">
    <div class="card-header d-flex align-items-center">
      <h3 class="card-title flex-grow-1">{{ $quote->program_name_snapshot }}</h3>
      <span class="badge badge-{{ $statusClass }}">{{ $buybackRequest->status->value }}</span>
    </div>
    <div class="card-body">
      <dl class="row mb-0">
        <dt class="col-sm-3">Request reference</dt><dd class="col-sm-9"><code>{{ $buybackRequest->public_id }}</code></dd>
        <dt class="col-sm-3">Quote reference</dt><dd class="col-sm-9"><code>{{ $quote->public_id }}</code></dd>
        <dt class="col-sm-3">Requester</dt><dd class="col-sm-9">{{ $quote->requester_name_snapshot }} <span class="text-muted">(user #{{ $quote->requester_user_id }})</span></dd>
        <dt class="col-sm-3">Program</dt><dd class="col-sm-9">{{ $quote->program_name_snapshot }} <span class="text-muted">(historical Quote snapshot)</span></dd>
        <dt class="col-sm-3">Submitted</dt><dd class="col-sm-9"><time datetime="{{ $buybackRequest->submitted_at->toIso8601String() }}">{{ $buybackRequest->submitted_at->format('Y-m-d H:i:s T') }}</time></dd>
        <dt class="col-sm-3">Payable total</dt><dd class="col-sm-9"><strong>{{ \RandulfTheGrey\Seat\BuybackPrograms\Support\RequesterUi::decimal($quote->payable_total) }} ISK</strong></dd>
        <dt class="col-sm-3">EVE contract ID</dt><dd class="col-sm-9">{{ $buybackRequest->eve_contract_id ?? 'Not provided' }}</dd>
        <dt class="col-sm-3">Requester note</dt><dd class="col-sm-9" style="white-space: pre-wrap">{{ $buybackRequest->requester_note ?: 'No note provided.' }}</dd>
        <dt class="col-sm-3">Manager note</dt><dd class="col-sm-9" style="white-space: pre-wrap">{{ $buybackRequest->manager_note ?: 'No internal note.' }}</dd>
        @if($buybackRequest->status->value === 'COMPLETED')
          <dt class="col-sm-3">Completed</dt><dd class="col-sm-9"><time datetime="{{ $buybackRequest->completed_at->toIso8601String() }}">{{ $buybackRequest->completed_at->format('Y-m-d H:i:s T') }}</time> by user #{{ $buybackRequest->completed_by_user_id }}</dd>
        @elseif($buybackRequest->status->value === 'REJECTED')
          <dt class="col-sm-3">Rejection reason</dt><dd class="col-sm-9"><div class="alert alert-danger mb-0" style="white-space: pre-wrap">{{ $buybackRequest->rejection_reason }}</div></dd>
          <dt class="col-sm-3">Rejected</dt><dd class="col-sm-9"><time datetime="{{ $buybackRequest->rejected_at->toIso8601String() }}">{{ $buybackRequest->rejected_at->format('Y-m-d H:i:s T') }}</time> by user #{{ $buybackRequest->rejected_by_user_id }}</dd>
        @elseif($buybackRequest->status->value === 'CANCELED')
          <dt class="col-sm-3">Requester withdrawal</dt><dd class="col-sm-9"><div class="alert alert-secondary mb-0">This Request was canceled by the requester and must not be processed.</div></dd>
          <dt class="col-sm-3">Canceled</dt><dd class="col-sm-9"><time datetime="{{ $buybackRequest->canceled_at->toIso8601String() }}">{{ $buybackRequest->canceled_at->format('Y-m-d H:i:s T') }}</time> by user #{{ $buybackRequest->canceled_by_user_id }}</dd>
        @endif
      </dl>
    </div>
  </div>

  @if($buybackRequest->isPending())
    <div class="row">
      <div class="col-lg-6">
        <div class="card">
          <div class="card-header"><h3 class="card-title">Manual contract reference</h3></div>
          <form method="post" action="{{ route('buyback.manage.requests.contract.update', $buybackRequest) }}">
            @csrf
            @method('PATCH')
            <div class="card-body">
              <label for="eve_contract_id">EVE contract ID <span class="text-muted">(optional)</span></label>
              <input class="form-control" id="eve_contract_id" name="eve_contract_id" type="number" min="1" value="{{ old('eve_contract_id', $buybackRequest->eve_contract_id) }}">
              <small class="form-text text-muted">This is a manual reference only. No ESI lookup or reconciliation is performed.</small>
            </div>
            <div class="card-footer text-right"><button class="btn btn-primary" type="submit">Update contract ID</button></div>
          </form>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="card">
          <div class="card-header"><h3 class="card-title">Internal manager note</h3></div>
          <form method="post" action="{{ route('buyback.manage.requests.manager-note.update', $buybackRequest) }}">
            @csrf
            @method('PATCH')
            <div class="card-body">
              <label for="manager_note">Manager note <span class="text-muted">(optional, never shown to the requester)</span></label>
              <textarea class="form-control" id="manager_note" name="manager_note" rows="4" maxlength="10000">{{ old('manager_note', $buybackRequest->manager_note) }}</textarea>
            </div>
            <div class="card-footer text-right"><button class="btn btn-primary" type="submit">Update manager note</button></div>
          </form>
        </div>
      </div>
    </div>

    <div class="row">
      <div class="col-lg-5">
        <div class="card card-outline card-success">
          <div class="card-header"><h3 class="card-title">Complete Request</h3></div>
          <div class="card-body">
            @if($buybackRequest->eve_contract_id === null)
              <div class="alert alert-warning">No EVE contract ID is recorded. Completion is still allowed.</div>
            @endif
            <p>Mark the full immutable Quote obligation as completed. Partial completion and payout overrides are not available.</p>
          </div>
          <div class="card-footer text-right">
            <form method="post" action="{{ route('buyback.manage.requests.complete', $buybackRequest) }}" onsubmit="return window.confirm('Mark this Buyback Request completed? This cannot be undone.');">
              @csrf
              <button class="btn btn-success" type="submit"><i class="fas fa-check" aria-hidden="true"></i> Complete Request</button>
            </form>
          </div>
        </div>
      </div>
      <div class="col-lg-7">
        <div class="card card-outline card-danger">
          <div class="card-header"><h3 class="card-title">Reject Request</h3></div>
          <form method="post" action="{{ route('buyback.manage.requests.reject', $buybackRequest) }}" onsubmit="return window.confirm('Reject this Buyback Request? This cannot be undone.');">
            @csrf
            <div class="card-body">
              <label for="rejection_reason">Requester-visible rejection reason</label>
              <textarea class="form-control" id="rejection_reason" name="rejection_reason" rows="3" maxlength="10000" required>{{ old('rejection_reason') }}</textarea>
              <small class="form-text text-muted">The requester will see this reason. It is separate from the internal manager note.</small>
            </div>
            <div class="card-footer text-right"><button class="btn btn-danger" type="submit"><i class="fas fa-times" aria-hidden="true"></i> Reject Request</button></div>
          </form>
        </div>
      </div>
    </div>
  @else
    <div class="alert alert-secondary" role="status">This Buyback Request is final and read-only. It cannot be reopened.</div>
  @endif

  <div class="card">
    <div class="card-header"><h3 class="card-title">Immutable Quote and historical pricing</h3></div>
    <div class="card-body p-0">
      @include('seat-buyback-programs::partials.quote-items', ['items' => $quote->items, 'showPricingProvenance' => true])
    </div>
    <div class="card-footer text-muted">Amounts and explanations come from the immutable Quote snapshots. Current Program rules and live providers are not queried.</div>
  </div>

  <a class="btn btn-outline-secondary" href="{{ route('buyback.manage.requests.index') }}">Back to Request queue</a>
@stop
