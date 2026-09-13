@extends('web::layouts.grids.12')

@section('title', 'Buyback Programs')
@section('page_header', 'Buyback Administration')
@section('page_description', 'Programs')

@section('full')
  @if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
  @endif
  <div class="card">
    <div class="card-header">
      <h3 class="card-title">Programs</h3>
      <div class="card-tools">
        <a class="btn btn-sm btn-primary" href="{{ route('buyback.admin.programs.create') }}">
          <i class="fas fa-plus" aria-hidden="true"></i> New Program
        </a>
      </div>
    </div>
    <div class="card-body">
      <p class="text-muted">Runtime health reflects current provider and compression dependencies; it never changes Program status automatically.</p>
      {!! $dataTable->table() !!}
    </div>
  </div>
@stop

@push('javascript')
  {!! $dataTable->scripts() !!}
@endpush
