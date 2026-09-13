@extends('web::layouts.grids.12')

@section('title', 'Compression Reference Data')
@section('page_header', 'Buyback Administration')
@section('page_description', 'Reference Data')

@section('full')
  @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
  @if(session('status'))<div class="alert alert-info">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
  <div class="card card-outline {{ $health->status->value === 'AVAILABLE' ? 'card-success' : 'card-warning' }}">
    <div class="card-header"><h3 class="card-title">Compression classification health — {{ $health->status->value }}</h3></div>
    <div class="card-body">
      @if($health->status->value === 'STALE')<div class="alert alert-warning">Using last-known-good mapping.</div>@endif
      <dl class="row">
        <dt class="col-sm-4">Source</dt><dd class="col-sm-8">CCP Static Data Export</dd>
        <dt class="col-sm-4">Active SDE build</dt><dd class="col-sm-8">{{ $health->activeBuild ?? 'None' }}</dd>
        <dt class="col-sm-4">Imported</dt><dd class="col-sm-8">{{ $health->importedAt?->format('Y-m-d H:i:s T') ?? 'Never' }}</dd>
        <dt class="col-sm-4">Mapping count</dt><dd class="col-sm-8">{{ number_format($mappingCount) }}</dd>
        <dt class="col-sm-4">Last checked</dt><dd class="col-sm-8">{{ $health->lastCheckedAt?->format('Y-m-d H:i:s T') ?? 'Never' }}</dd>
        @if($health->lastRefreshFailed)
          <dt class="col-sm-4">Last normalized failure</dt><dd class="col-sm-8">{{ $health->lastFailureCode?->value ?? 'UNKNOWN' }} — {{ $health->lastFailureMessage }}</dd>
        @endif
      </dl>
      <form method="post" action="{{ route('buyback.admin.reference-data.sync') }}">@csrf<button class="btn btn-primary" type="submit"><i class="fas fa-sync" aria-hidden="true"></i> Sync now</button><small class="form-text text-muted">The shared production sync runs in the queue; this page does not wait for the CCP SDE download/import.</small></form>
    </div>
  </div>
@stop
