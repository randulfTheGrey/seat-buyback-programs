@extends('web::layouts.grids.12')

@php
  $editing = $mode === 'edit';
  $modifier = \RandulfTheGrey\Seat\BuybackPrograms\Support\AdminUi::modifierInput($program->default_modifier_bps?->value ?? 0);
  $buy = $references->get('BUY');
  $sell = $references->get('SELL');
  $split = $references->get('SPLIT');
@endphp

@section('title', $editing ? 'Edit Buyback Program' : 'Create Buyback Program')
@section('page_header', $editing ? $program->name : 'Create Buyback Program')
@section('page_description', 'Program configuration')

@section('full')
  @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
  @if(session('warnings'))
    @foreach(session('warnings') as $warning)<div class="alert alert-warning">{{ $warning }}</div>@endforeach
  @endif
  @if($errors->any())
    <div class="alert alert-danger" role="alert">
      <strong>Configuration was not saved.</strong>
      <ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
  @endif

  @if($health)
    <div class="card card-outline {{ $health->operational() ? 'card-success' : 'card-warning' }}">
      <div class="card-header"><h3 class="card-title">Program health — {{ $health->label() }}</h3></div>
      <div class="card-body">
        <div class="row">
          @foreach($health->references as $channel => $state)
            <div class="col-md-4"><strong>{{ $channel }}</strong>: {{ $state['message'] }}</div>
          @endforeach
        </div>
        @foreach(array_merge($health->configuration->errors, $health->runtimeErrors) as $error)
          <div class="text-danger mt-2">{{ $error }}</div>
        @endforeach
        @foreach($health->warnings as $warning)<div class="text-warning mt-2">{{ $warning }}</div>@endforeach
      </div>
    </div>
  @endif

  <form method="post" action="{{ $editing ? route('buyback.admin.programs.update', $program) : route('buyback.admin.programs.store') }}">
    @csrf
    @if($editing) @method('PATCH') @endif

    <div class="row">
      <div class="col-12 col-lg-6 d-flex">
        <div class="card flex-fill">
          <div class="card-header"><h3 class="card-title">General</h3></div>
          <div class="card-body">
        <div class="form-group">
          <label for="name">Name</label>
          <input class="form-control" id="name" name="name" required maxlength="255" value="{{ old('name', $program->name) }}">
        </div>
        <div class="form-group">
          <label for="description">Description</label>
          <textarea class="form-control" id="description" name="description" rows="3">{{ old('description', $program->description) }}</textarea>
        </div>
        <div class="row">
          <div class="form-group col-md-6">
            <label for="status">Status</label>
            <select class="form-control" id="status" name="status">
              @foreach($program->status?->value === 'ARCHIVED' ? ['ARCHIVED' => 'Archived'] : ['DISABLED' => 'Disabled', 'ENABLED' => 'Enabled'] as $value => $label)
                <option value="{{ $value }}" @selected(old('status', $program->status?->value ?? 'DISABLED') === $value)>{{ $label }}</option>
              @endforeach
            </select>
            <small class="form-text text-muted">Enabling runs full operational validation. New Programs default to Disabled.</small>
          </div>
          <div class="form-group col-md-6">
            <label for="quote_validity_minutes">Quote validity (minutes)</label>
            <input class="form-control" type="number" min="1" max="525600" id="quote_validity_minutes" name="quote_validity_minutes" required value="{{ old('quote_validity_minutes', $program->quote_validity_minutes ?? 30) }}">
          </div>
        </div>
        <div class="form-group">
          <label for="contract_instructions">Contract instructions</label>
          <textarea class="form-control" id="contract_instructions" name="contract_instructions" rows="4">{{ old('contract_instructions', $program->contract_instructions) }}</textarea>
        </div>
          </div>
        </div>
      </div>

      <div class="col-12 col-lg-6 d-flex">
        <div class="card flex-fill">
          <div class="card-header"><h3 class="card-title">Default Policy</h3></div>
          <div class="card-body">
        <div class="form-group">
          <label for="default_acceptance">Default acceptance</label>
          <select class="form-control" id="default_acceptance" name="default_acceptance">
            <option value="ACCEPT" @selected(old('default_acceptance', $program->default_acceptance?->value ?? 'ACCEPT') === 'ACCEPT')>Accept items unless a rule rejects them</option>
            <option value="REJECT" @selected(old('default_acceptance', $program->default_acceptance?->value) === 'REJECT')>Reject items unless a rule accepts them</option>
          </select>
        </div>
        <div class="form-group">
          <label for="default_reference_mode">Default reference</label>
          <select class="form-control" id="default_reference_mode" name="default_reference_mode">
            @foreach(['BUY', 'SELL', 'SPLIT'] as $value)
              <option value="{{ $value }}" @selected(old('default_reference_mode', $program->default_reference_mode?->value ?? 'BUY') === $value)>{{ $value }}</option>
            @endforeach
          </select>
        </div>
        <div class="row">
          <div class="form-group col-md-6">
            <label for="default_modifier_direction">Adjustment type</label>
            <select class="form-control modifier-direction" id="default_modifier_direction" name="default_modifier_direction">
              @foreach(['discount' => 'Discount', 'premium' => 'Premium', 'none' => 'None'] as $value => $label)
                <option value="{{ $value }}" @selected(old('default_modifier_direction', $modifier['direction']) === $value)>{{ $label }}</option>
              @endforeach
            </select>
          </div>
          <div class="form-group col-md-6">
            <label for="default_modifier_percentage">Percentage</label>
            <div class="input-group"><input class="form-control" id="default_modifier_percentage" name="default_modifier_percentage" inputmode="decimal" value="{{ old('default_modifier_percentage', $modifier['percentage']) }}"><div class="input-group-append"><span class="input-group-text">%</span></div></div>
            <small class="form-text text-muted">Stored exactly as an integer adjustment; values must be between 0 and 100.00%.</small>
          </div>
        </div>
          </div>
        </div>
      </div>

      <div class="col-12 col-lg-6 d-flex">
        <div class="card flex-fill">
          <div class="card-header"><h3 class="card-title">Price References</h3></div>
          <div class="card-body">
        <p class="text-muted">Select existing configured seat-prices-core instances. Buyback assigns logical meaning and does not inspect or edit provider backend settings.</p>
        @foreach(['buy' => ['BUY', $buy], 'sell' => ['SELL', $sell]] as $field => [$label, $reference])
          <div class="form-group">
            <label for="{{ $field }}_provider_instance_id">{{ $label }} provider instance</label>
            <select class="form-control" id="{{ $field }}_provider_instance_id" name="{{ $field }}_provider_instance_id">
              <option value="">Not configured</option>
              @if($reference?->provider_instance_id && !$providers->contains('id', $reference->provider_instance_id))<option value="{{ $reference->provider_instance_id }}" selected>Missing provider instance #{{ $reference->provider_instance_id }} — preserve until repaired</option>@endif
              @foreach($providers as $provider)<option value="{{ $provider->id }}" @selected((string) old($field . '_provider_instance_id', $reference?->provider_instance_id) === (string) $provider->id)>{{ $provider->name }}</option>@endforeach
            </select>
            <small class="form-text text-muted">Select the configured seat-prices-core instance that represents this Program's {{ $label }} reference.</small>
          </div>
        @endforeach
        <div class="form-group">
          <label for="split_resolution">SPLIT resolution</label>
          <select class="form-control" id="split_resolution" name="split_resolution">
            <option value="DERIVED_MIDPOINT" @selected(old('split_resolution', $split?->resolution?->value ?? 'DERIVED_MIDPOINT') === 'DERIVED_MIDPOINT')>Derived midpoint — (BUY + SELL) / 2</option>
            <option value="PROVIDER" @selected(old('split_resolution', $split?->resolution?->value) === 'PROVIDER')>Dedicated provider instance</option>
          </select>
        </div>
        <div class="form-group" id="split-provider-group">
          <label for="split_provider_instance_id">Dedicated SPLIT provider instance</label>
          <select class="form-control" id="split_provider_instance_id" name="split_provider_instance_id">
            <option value="">Not configured</option>
            @if($split?->provider_instance_id && !$providers->contains('id', $split->provider_instance_id))<option value="{{ $split->provider_instance_id }}" selected>Missing provider instance #{{ $split->provider_instance_id }} — preserve until repaired</option>@endif
            @foreach($providers as $provider)<option value="{{ $provider->id }}" @selected((string) old('split_provider_instance_id', $split?->provider_instance_id) === (string) $provider->id)>{{ $provider->name }}</option>@endforeach
          </select>
        </div>
          </div>
        </div>
      </div>

      <div class="col-12 col-lg-6 d-flex">
        <div class="card flex-fill">
          <div class="card-header">
            <h3 class="card-title">Appraisals Landing Preview</h3>
          </div>
          <div class="card-body d-flex">
            <div class="card card-outline card-primary flex-fill mb-0">
              <div class="card-header">
                <h3 class="card-title" id="program-preview-name"></h3>
              </div>
              <div class="card-body">
                <p id="program-preview-description"></p>
                <dl class="row mb-0">
                  <dt class="col-sm-5">Default policy</dt>
                  <dd class="col-sm-7" id="program-preview-policy"></dd>
                  <dt class="col-sm-5">Default reference</dt>
                  <dd class="col-sm-7" id="program-preview-reference"></dd>
                  <dt class="col-sm-5">Default adjustment</dt>
                  <dd class="col-sm-7" id="program-preview-adjustment"></dd>
                  <dt class="col-sm-5">Quote validity</dt>
                  <dd class="col-sm-7"><span id="program-preview-validity"></span> minutes</dd>
                </dl>
                <p class="text-muted mt-3 mb-0" id="program-preview-contract-row">
                  <strong>Contract note:</strong> <span id="program-preview-contract"></span>
                </p>
              </div>
              <div class="card-footer text-right">
                <span class="btn btn-primary" aria-hidden="true">
                  Select Program <i class="fas fa-arrow-right ml-1" aria-hidden="true"></i>
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="d-flex justify-content-between mb-4">
      <div>
        <button class="btn btn-primary" type="submit">Save Program</button>
        @if($editing)<a class="btn btn-outline-secondary" href="{{ route('buyback.admin.programs.rules.index', $program) }}">Manage Rules</a>@endif
      </div>
      @if($editing && $program->status?->value !== 'ARCHIVED')
        <button class="btn btn-outline-danger" type="submit" formaction="{{ route('buyback.admin.programs.archive', $program) }}" formmethod="post" onclick="return confirm('This removes the Program from new appraisals. Existing Quotes and Buyback Requests are unaffected.')">Archive Program</button>
      @endif
    </div>
  </form>
@stop

@push('javascript')
<script>
  (function () {
    var resolution = document.getElementById('split_resolution');
    var group = document.getElementById('split-provider-group');
    function updateSplit() { group.style.display = resolution.value === 'PROVIDER' ? '' : 'none'; }
    resolution.addEventListener('change', updateSplit); updateSplit();

    function value(id) { return document.getElementById(id).value.trim(); }
    function setText(id, text) { document.getElementById(id).textContent = text; }
    function displayPercentage(raw) {
      var percentage = Number.parseFloat(raw);
      if (!Number.isFinite(percentage)) return '—';
      return percentage.toFixed(2).replace(/\.00$/, '').replace(/(\.\d)0$/, '$1');
    }
    function updatePreview() {
      var description = value('description');
      var contract = value('contract_instructions');
      var direction = value('default_modifier_direction');
      var percentage = displayPercentage(value('default_modifier_percentage'));
      var adjustment = direction === 'none' ? 'No adjustment' : percentage + '% ' + direction;

      setText('program-preview-name', value('name') || 'Program name');
      setText('program-preview-description', description);
      document.getElementById('program-preview-description').classList.toggle('d-none', description === '');
      setText('program-preview-policy', value('default_acceptance') === 'ACCEPT' ? 'Accept unless excluded' : 'Exclude unless accepted');
      setText('program-preview-reference', value('default_reference_mode'));
      setText('program-preview-adjustment', adjustment);
      setText('program-preview-validity', value('quote_validity_minutes') || '—');
      setText('program-preview-contract', contract.length > 180 ? contract.slice(0, 177) + '...' : contract);
      document.getElementById('program-preview-contract-row').classList.toggle('d-none', contract === '');
    }

    [
      'name', 'description', 'default_acceptance', 'default_reference_mode',
      'default_modifier_direction', 'default_modifier_percentage',
      'quote_validity_minutes', 'contract_instructions'
    ].forEach(function (id) {
      var input = document.getElementById(id);
      input.addEventListener('input', updatePreview);
      input.addEventListener('change', updatePreview);
    });
    updatePreview();
  }());
</script>
@endpush
