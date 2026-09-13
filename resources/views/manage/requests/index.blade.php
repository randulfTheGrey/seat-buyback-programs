@extends('web::layouts.grids.12')

@section('title', 'Manage Buybacks')
@section('page_header', 'Manage Buybacks')
@section('page_description', 'Submitted Request queue and history')

@section('full')
  <div class="card card-outline card-primary">
    <div class="card-header"><h3 class="card-title">Request filters</h3></div>
    <form method="get" action="{{ route('buyback.manage.requests.index') }}">
      <div class="card-body">
        <div class="form-row">
          <div class="form-group col-md-3">
            <label for="status">Status</label>
            <select class="form-control" id="status" name="status">
              <option value="">All statuses</option>
              @foreach(\RandulfTheGrey\Seat\BuybackPrograms\Enums\BuybackRequestStatus::cases() as $status)
                <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->value }}</option>
              @endforeach
            </select>
          </div>
          <div class="form-group col-md-3">
            <label for="program_id">Program</label>
            <select class="form-control" id="program_id" name="program_id">
              <option value="">All Programs</option>
              @foreach($programs as $program)
                <option value="{{ $program->id }}" @selected((string) request('program_id') === (string) $program->id)>{{ $program->name }}</option>
              @endforeach
            </select>
          </div>
          <div class="form-group col-md-2">
            <label for="requester">Requester</label>
            <input class="form-control" id="requester" name="requester" type="search" maxlength="100" value="{{ request('requester') }}" placeholder="Name snapshot">
          </div>
          <div class="form-group col-md-2">
            <label for="submitted_from">Submitted from</label>
            <input class="form-control" id="submitted_from" name="submitted_from" type="date" value="{{ request('submitted_from') }}">
          </div>
          <div class="form-group col-md-2">
            <label for="submitted_to">Submitted to</label>
            <input class="form-control" id="submitted_to" name="submitted_to" type="date" value="{{ request('submitted_to') }}">
          </div>
        </div>
      </div>
      <div class="card-footer text-right">
        <a class="btn btn-outline-secondary" href="{{ route('buyback.manage.requests.index') }}">Clear</a>
        <button class="btn btn-primary" type="submit"><i class="fas fa-filter" aria-hidden="true"></i> Apply filters</button>
      </div>
    </form>
  </div>

  <div class="card">
    <div class="card-body">
      <p class="text-muted">Pending Requests are listed first, followed by the most recently submitted work. All status badges include text labels.</p>
      {!! $dataTable->table() !!}
    </div>
  </div>
@stop

@push('javascript')
  {!! $dataTable->scripts() !!}
@endpush
