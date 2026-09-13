@extends('web::layouts.grids.12')

@section('title', 'Program Rules')
@section('page_header', $program->name)
@section('page_description', 'Sparse GROUP and TYPE rules')

@section('full')
  @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
  <div class="card">
    <div class="card-header">
      <h3 class="card-title">Rules</h3>
      <div class="card-tools">
        <a class="btn btn-sm btn-outline-secondary" href="{{ route('buyback.admin.preview.index', ['program_id' => $program->id]) }}">Rule Preview</a>
        <a class="btn btn-sm btn-primary" href="{{ route('buyback.admin.programs.rules.create', $program) }}"><i class="fas fa-plus" aria-hidden="true"></i> New Rule</a>
      </div>
    </div>
    <div class="card-body">
      <p class="text-muted">Program defaults are the sole global policy. Rules are sparse, deterministic GROUP or TYPE overrides.</p>
      <div class="custom-control custom-checkbox mb-3">
        <input class="custom-control-input" id="show-archived" type="checkbox" @checked(request()->boolean('show_archived')) onchange="window.location.search = this.checked ? '?show_archived=1' : ''">
        <label class="custom-control-label" for="show-archived">Show archived rules</label>
      </div>
      {!! $dataTable->table() !!}
    </div>
  </div>
  <a class="btn btn-outline-secondary" href="{{ route('buyback.admin.programs.edit', $program) }}">Back to Program</a>
@stop

@push('javascript')
  {!! $dataTable->scripts() !!}
@endpush
