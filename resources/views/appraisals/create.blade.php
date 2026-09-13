@extends('web::layouts.grids.12')

@section('title', 'Appraise Items')
@section('page_header', 'Appraise Items')
@section('page_description', $program->name)

@section('full')
  <div class="card card-outline card-primary">
    <div class="card-header">
      <h3 class="card-title">Paste your EVE item list</h3>
    </div>
    <form id="buyback-appraisal-form" method="post" action="{{ route('buyback.appraisals.store', $program) }}">
      @csrf
      <div class="card-body">
        <p class="text-muted">
          Paste one item per line with its quantity. Lists copied from common EVE inventory views are accepted.
          Item names must match exactly; you do not need type, group, provider, or rule IDs.
        </p>
        <div class="form-group">
          <label for="inventory">Items and quantities</label>
          <textarea
            class="form-control @error('inventory') is-invalid @enderror"
            id="inventory"
            name="inventory"
            rows="16"
            required
            autofocus
            placeholder="Tritanium&#9;100000&#10;Pyerite&#9;50000"
          >{{ old('inventory') }}</textarea>
          @error('inventory')
            <span class="invalid-feedback" role="alert">{{ $message }}</span>
          @enderror
        </div>
      </div>
      <div class="card-footer d-flex justify-content-between">
        <a class="btn btn-default" href="{{ route('buyback.programs.index') }}">Choose another Program</a>
        <button id="buyback-appraise-button" type="submit" class="btn btn-primary">
          <i class="fas fa-calculator mr-1" aria-hidden="true"></i>
          Run Appraisal
        </button>
      </div>
    </form>
  </div>
@stop

@push('javascript')
  <script>
    $('#buyback-appraisal-form').on('submit', function () {
      var button = $('#buyback-appraise-button');
      button.prop('disabled', true);
      button.html('<i class="fas fa-spinner fa-spin mr-1" aria-hidden="true"></i> Pricing your item list...');
    });
  </script>
@endpush
