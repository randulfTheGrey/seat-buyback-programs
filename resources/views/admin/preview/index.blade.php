@extends('web::layouts.grids.12')

@section('title', 'Rule Preview')
@section('page_header', 'Buyback Administration')
@section('page_description', 'Effective policy preview')

@section('full')
  @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
  @if($previewError)<div class="alert alert-danger">{{ $previewError }}</div>@endif
  <div class="card">
    <div class="card-body">
      <p class="text-muted">Preview answers “What policy applies?” using the production PolicyEvaluator. It does not call live pricing providers.</p>
      <form method="get" action="{{ route('buyback.admin.preview.index') }}">
        <div class="row">
          <div class="form-group col-md-5"><label for="program_id">Program</label><select class="form-control" id="program_id" name="program_id" required><option value="">Choose a Program</option>@foreach($programs as $candidate)<option value="{{ $candidate->id }}" @selected((int) request('program_id') === (int) $candidate->id)>{{ $candidate->name }}</option>@endforeach</select></div>
          <div class="form-group col-md-5"><label for="type_id">EVE item type</label><select class="form-control w-100 buyback-select2-native" id="type_id" name="type_id" required><option></option>@if($preview)<option selected value="{{ $preview->typeId }}">{{ $preview->typeName }} — {{ $preview->groupName }} (#{{ $preview->typeId }})</option>@endif</select></div>
          <div class="form-group col-md-2 d-flex align-items-end"><button class="btn btn-primary btn-block" type="submit">Preview policy</button></div>
        </div>
      </form>
    </div>
  </div>

  @if($preview)
    <div class="card card-outline card-primary">
      <div class="card-header"><h3 class="card-title">{{ $preview->compression->value === 'COMPRESSED' ? 'Compressed ' : '' }}{{ $preview->typeName }}</h3></div>
      <div class="card-body">
        <h4>Classification</h4><dl class="row"><dt class="col-sm-3">Group</dt><dd class="col-sm-9">{{ $preview->groupName }} (#{{ $preview->groupId }})</dd><dt class="col-sm-3">Compression</dt><dd class="col-sm-9">{{ $preview->compression->value }}</dd></dl>
        <h4>Matched policy</h4>
        <div class="border rounded p-3 mb-3"><strong>Program defaults / GLOBAL</strong><br>{{ $preview->policy->baseline->acceptance->value }} · {{ $preview->policy->baseline->referenceMode->value }} · {{ \RandulfTheGrey\Seat\BuybackPrograms\Support\AdminUi::modifierLabel($preview->policy->baseline->modifierBps->value) }}</div>
        @forelse($preview->policy->layers as $layer)
          <div class="border rounded p-3 mb-2"><strong>{{ str_replace('_', ' + ', $layer->source->value) }} — Rule #{{ $layer->rule->ruleId }}</strong><div class="text-muted">{{ $layer->matchReason }}</div>@if($layer->acceptanceOperation->value !== 'INHERIT')<div>Acceptance → {{ $layer->acceptanceAfter->value }}</div>@endif @if($layer->referenceModeOverride)<div>Reference → {{ $layer->referenceModeAfter->value }}</div>@endif @if($layer->modifierOperation->value !== 'INHERIT')<div>Modifier {{ $layer->modifierOperation->value }} → {{ \RandulfTheGrey\Seat\BuybackPrograms\Support\AdminUi::modifierLabel($layer->modifierAfterBps->value) }}</div>@endif</div>
        @empty
          <p class="text-muted">No GROUP or TYPE rule matched.</p>
        @endforelse
        <div class="alert alert-info mb-0"><strong>Effective</strong><br>{{ $preview->policy->acceptance->value }} · {{ $preview->policy->referenceMode->value }} · {{ \RandulfTheGrey\Seat\BuybackPrograms\Support\AdminUi::modifierLabel($preview->policy->effectiveModifierBps->value) }}</div>
      </div>
    </div>
  @endif
@stop

@push('head')
  @include('seat-buyback-programs::admin.shared.select2-native-control')
@endpush

@push('javascript')
<script>
  jQuery(function ($) {
    $('#type_id').select2({
      width: '100%',
      placeholder: 'Search for an EVE item type',
      allowClear: true,
      minimumInputLength: 2,
      ajax: {
        url: @json(route('buyback.admin.selectors.types')),
        delay: 250,
        dataType: 'json',
        data: function (params) { return { q: params.term, page: params.page || 1 }; }
      }
    });
  });
</script>
@endpush
