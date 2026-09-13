@extends('web::layouts.grids.12')

@php
  $editing = $mode === 'edit';
  $modifier = \RandulfTheGrey\Seat\BuybackPrograms\Support\AdminUi::modifierInput($rule->modifier_bps?->value ?? 0);
  $lifecycle = $rule->archived_at ? 'ARCHIVED' : ($rule->exists && !$rule->enabled ? 'DISABLED' : 'ENABLED');
@endphp

@section('title', $editing ? 'Edit Rule' : 'Create Rule')
@section('page_header', $program->name)
@section('page_description', $editing ? 'Edit sparse rule' : 'Create sparse rule')

@section('full')
  @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
  <form method="post" action="{{ $editing ? route('buyback.admin.programs.rules.update', [$program, $rule]) : route('buyback.admin.programs.rules.store', $program) }}">
    @csrf
    @if($editing) @method('PATCH') @endif
    <div class="card">
      <div class="card-header"><h3 class="card-title">Target and lifecycle</h3></div>
      <div class="card-body">
        <div class="row">
          <div class="form-group col-md-4">
            <label for="target_type">Target scope</label>
            <select class="form-control" id="target_type" name="target_type"><option value="GROUP" @selected(old('target_type', $rule->target_type?->value ?? 'GROUP') === 'GROUP')>Group</option><option value="TYPE" @selected(old('target_type', $rule->target_type?->value) === 'TYPE')>Item type</option></select>
          </div>
          <div class="form-group col-md-8">
            <label for="target_id">Target</label>
            <select class="form-control w-100 buyback-select2-native" id="target_id" name="target_id" required>
              <option></option>
              @if(old('target_id', $rule->target_id))<option selected value="{{ old('target_id', $rule->target_id) }}" data-compression-applicable="{{ $compressionApplicable === null ? 'unknown' : ($compressionApplicable ? 'true' : 'false') }}" data-compression-qualifiers="{{ $compressionQualifiers === null ? 'unknown' : implode(',', array_map(static fn ($qualifier) => $qualifier->value, $compressionQualifiers)) }}" data-compression-state="{{ $targetCompressionState ?? 'unknown' }}">{{ $targetName ?? ('Existing target #' . old('target_id', $rule->target_id)) }}</option>@endif
            </select>
            <small class="form-text text-muted">Search by EVE name; numeric IDs are shown only as context.</small>
          </div>
        </div>
        <div class="row">
          <div class="form-group col-md-6" id="compression-qualifier-group">
            <label for="compression_qualifier">Compression qualifier</label>
            <select class="form-control" id="compression_qualifier" name="compression_qualifier">
              @foreach(['ANY' => 'All matching forms', 'COMPRESSED' => 'Compressed only', 'UNCOMPRESSED' => 'Uncompressed only'] as $value => $label)<option value="{{ $value }}" @selected(old('compression_qualifier', $rule->compression_qualifier?->value ?? 'ANY') === $value)>{{ $label }}</option>@endforeach
            </select>
            <input type="hidden" id="locked-compression-qualifier" name="compression_qualifier" disabled>
            <small class="form-text text-muted" id="compression-qualifier-help">For targets without compressible variants in CCP SDE, only All matching forms applies.</small>
          </div>
          <div class="form-group col-md-6 d-none" id="type-compression-classification-group">
            <label>Compression classification</label>
            <div class="form-control-plaintext" id="type-compression-classification">Select an EVE item type.</div>
            <small class="form-text text-muted">Intrinsic item classification from the canonical CCP SDE mapping; it does not create another rule layer.</small>
          </div>
          <div class="form-group col-md-6" id="rule-status-group">
            <label for="rule_status">Rule state</label>
            <select class="form-control" id="rule_status" name="rule_status">@foreach($rule->archived_at ? ['ARCHIVED' => 'Archived', 'ENABLED' => 'Reactivate as enabled', 'DISABLED' => 'Restore as disabled'] : ['ENABLED' => 'Enabled', 'DISABLED' => 'Disabled'] as $value => $label)<option value="{{ $value }}" @selected(old('rule_status', $lifecycle) === $value)>{{ $label }}</option>@endforeach</select>
          </div>
        </div>
        <div class="card card-outline card-secondary d-none mb-0" id="affected-items-panel">
          <div class="card-header">
            <h3 class="card-title">Affected items</h3>
          </div>
          <div class="card-body p-0">
            <div class="px-3 pt-3 text-muted" id="affected-items-status" role="status" aria-live="polite">Select a target to preview affected items.</div>
            <div class="table-responsive" id="affected-items-table-container">
              <table class="table table-sm mb-0">
                <thead><tr><th>Item type</th><th>Group</th><th>Compression</th></tr></thead>
                <tbody id="affected-items-body"></tbody>
              </table>
            </div>
          </div>
          <div class="card-footer d-flex justify-content-between align-items-center">
            <button class="btn btn-sm btn-outline-secondary" id="affected-items-previous" type="button">Previous</button>
            <span class="text-muted" id="affected-items-page"></span>
            <button class="btn btn-sm btn-outline-secondary" id="affected-items-next" type="button">Next</button>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3 class="card-title">Policy overrides</h3></div>
      <div class="card-body">
        <div class="form-group">
          <label for="acceptance">Acceptance behavior</label>
          <select class="form-control" id="acceptance" name="acceptance">@foreach(['INHERIT' => 'Inherit', 'ACCEPT' => 'Explicitly accept', 'REJECT' => 'Explicitly reject'] as $value => $label)<option value="{{ $value }}" @selected(old('acceptance', $rule->acceptance?->value ?? 'INHERIT') === $value)>{{ $label }}</option>@endforeach</select>
        </div>
        <div class="form-group">
          <label for="reference_mode_override">Reference behavior</label>
          @php($selectedReference = old('reference_mode_override', $rule->reference_mode_override?->value ?? 'INHERIT'))
          <select class="form-control" id="reference_mode_override" name="reference_mode_override">
            <option value="INHERIT" @selected($selectedReference === 'INHERIT')>Inherit</option>
            @foreach(['BUY', 'SELL', 'SPLIT'] as $value)
              <option value="{{ $value }}" @selected($selectedReference === $value) @disabled(!($referenceHealth[$value]['healthy'] ?? false) && $selectedReference !== $value)>{{ $value }}{{ ($referenceHealth[$value]['healthy'] ?? false) ? '' : ' — currently unavailable' }}</option>
            @endforeach
          </select>
          <small class="form-text text-muted">An existing unhealthy reference is preserved and shown by Program health; it is never silently rewritten.</small>
        </div>
        <div class="form-group">
          <label for="modifier_operation">Modifier behavior</label>
          <select class="form-control" id="modifier_operation" name="modifier_operation">@foreach(['INHERIT' => 'Inherit', 'REPLACE' => 'Replace inherited adjustment', 'ADJUST' => 'Adjust inherited value'] as $value => $label)<option value="{{ $value }}" @selected(old('modifier_operation', $rule->modifier_operation?->value ?? 'INHERIT') === $value)>{{ $label }}</option>@endforeach</select>
        </div>
        <div class="row" id="modifier-value">
          <div class="form-group col-md-6"><label for="modifier_direction">Adjustment type</label><select class="form-control" id="modifier_direction" name="modifier_direction">@foreach(['discount' => 'Discount / Additional discount', 'premium' => 'Premium / Additional premium', 'none' => 'None'] as $value => $label)<option value="{{ $value }}" @selected(old('modifier_direction', $modifier['direction']) === $value)>{{ $label }}</option>@endforeach</select></div>
          <div class="form-group col-md-6"><label for="modifier_percentage">Percentage points</label><div class="input-group"><input class="form-control" id="modifier_percentage" name="modifier_percentage" inputmode="decimal" value="{{ old('modifier_percentage', $modifier['percentage']) }}"><div class="input-group-append"><span class="input-group-text">percentage points</span></div></div><small class="form-text text-muted">ADJUST adds percentage points; it does not compound percentages.</small></div>
        </div>
        <div class="form-group"><label for="admin_note">Administrator note</label><textarea class="form-control" id="admin_note" name="admin_note" rows="3">{{ old('admin_note', $rule->admin_note) }}</textarea><small class="form-text text-muted">Visible to administrators only and never copied into Quote policy snapshots.</small></div>
      </div>
    </div>
    <button class="btn btn-primary" type="submit">Save Rule</button>
    <a class="btn btn-outline-secondary" href="{{ route('buyback.admin.programs.rules.index', $program) }}">Cancel</a>
    @if($editing && !$rule->archived_at)<button class="btn btn-outline-danger float-right" type="submit" formaction="{{ route('buyback.admin.programs.rules.archive', [$program, $rule]) }}" formmethod="post" onclick="return confirm('Archive this rule? It will become inactive.')">Archive Rule</button>@endif
  </form>
@stop

@push('head')
  @include('seat-buyback-programs::admin.shared.select2-native-control')
  <style>
    #affected-items-table-container {
      max-height: 24rem;
      overflow-y: auto;
    }

    #affected-items-table-container thead th {
      position: sticky;
      top: 0;
      z-index: 1;
      background-color: var(--color-background-secondary, #fff);
    }
  </style>
@endpush

@push('javascript')
<script>
  (function ($) {
    var target = $('#target_id');
    var qualifier = $('#compression_qualifier');
    var lockedQualifier = $('#locked-compression-qualifier');
    var qualifierHelp = $('#compression-qualifier-help');
    var qualifierGroup = $('#compression-qualifier-group');
    var typeCompressionGroup = $('#type-compression-classification-group');
    var typeCompressionClassification = $('#type-compression-classification');
    var ruleStatusGroup = $('#rule-status-group');
    var affectedPanel = $('#affected-items-panel');
    var affectedStatus = $('#affected-items-status');
    var affectedBody = $('#affected-items-body');
    var affectedPage = $('#affected-items-page');
    var affectedPrevious = $('#affected-items-previous');
    var affectedNext = $('#affected-items-next');
    var affectedRequest = null;
    var currentAffectedPage = 1;
    function endpoint() { return $('#target_type').val() === 'TYPE' ? @json(route('buyback.admin.selectors.types')) : @json(route('buyback.admin.selectors.groups')); }
    function targetPlaceholder() { return $('#target_type').val() === 'TYPE' ? 'Search for an EVE item type' : 'Search for an EVE group'; }
    function configureTarget() { target.select2({ width: '100%', placeholder: targetPlaceholder(), allowClear: true, minimumInputLength: 2, ajax: { url: endpoint(), delay: 250, dataType: 'json', data: function (params) { return { q: params.term, page: params.page || 1 }; } } }); }
    function isTypeTarget() { return $('#target_type').val() === 'TYPE'; }
    function configureTypeClassification(state) {
      qualifier.val('ANY').prop('disabled', true);
      lockedQualifier.val('ANY').prop('disabled', false);
      qualifierGroup.hide();
      typeCompressionGroup.removeClass('d-none');
      ruleStatusGroup.removeClass('col-md-12').addClass('col-md-6');
      if (!target.val()) {
        typeCompressionClassification.text('Select an EVE item type.');
      } else if (state) {
        typeCompressionClassification.text(state.replace('_', ' '));
      } else {
        typeCompressionClassification.text('Unavailable');
      }
    }
    function updateGroupQualifier(available) {
      typeCompressionGroup.addClass('d-none');
      qualifier.prop('disabled', false);
      lockedQualifier.prop('disabled', true);
      qualifierHelp.text('For targets without compressible variants in CCP SDE, only All matching forms applies.');
      qualifier.find('option[value="COMPRESSED"]').prop('disabled', Array.isArray(available) && available.indexOf('COMPRESSED') === -1);
      qualifier.find('option[value="UNCOMPRESSED"]').prop('disabled', Array.isArray(available) && available.indexOf('UNCOMPRESSED') === -1);
      if (!target.val() || (Array.isArray(available) && available.length === 1)) {
        qualifier.val('ANY');
        qualifierGroup.hide();
        ruleStatusGroup.removeClass('col-md-6').addClass('col-md-12');
        return;
      }
      if (Array.isArray(available) && available.indexOf(qualifier.val()) === -1) qualifier.val('ANY');
      qualifierGroup.show();
      ruleStatusGroup.removeClass('col-md-12').addClass('col-md-6');
    }
    function selectedQualifiers() {
      var value = target.find(':selected').attr('data-compression-qualifiers');
      return value && value !== 'unknown' ? value.split(',') : null;
    }
    function selectedCompressionState() {
      var value = target.find(':selected').attr('data-compression-state');
      return value && value !== 'unknown' ? value : null;
    }
    function renderAffectedItems(response) {
      updateGroupQualifier(response.compression_qualifiers);
      affectedBody.empty();
      if (response.message) {
        affectedStatus.text(response.message);
      } else if (response.pagination.total === 0) {
        affectedStatus.text('No published EVE item types match this target and qualifier.');
      } else {
        var first = ((response.pagination.page - 1) * response.pagination.per_page) + 1;
        var last = first + response.items.length - 1;
        affectedStatus.text('Showing ' + first + '–' + last + ' of ' + response.pagination.total + ' published item types.');
      }
      response.items.forEach(function (item) {
        var row = $('<tr>');
        $('<td>').text(item.name + ' (#' + item.id + ')').appendTo(row);
        $('<td>').text(item.group).appendTo(row);
        $('<td>').text(item.compression_state ? item.compression_state.replace('_', ' ') : 'Unavailable').appendTo(row);
        affectedBody.append(row);
      });
      currentAffectedPage = response.pagination.page;
      affectedPage.text('Page ' + response.pagination.page + ' of ' + response.pagination.last_page);
      affectedPrevious.prop('disabled', response.pagination.page <= 1);
      affectedNext.prop('disabled', response.pagination.page >= response.pagination.last_page);
    }
    function refreshAffectedItems(page) {
      if (affectedRequest) affectedRequest.abort();
      if (isTypeTarget()) {
        affectedPanel.addClass('d-none');
        configureTypeClassification(selectedCompressionState());
        return;
      }
      if (!target.val()) {
        affectedPanel.addClass('d-none');
        updateGroupQualifier(null);
        return;
      }
      affectedPanel.removeClass('d-none');
      affectedStatus.text('Loading affected items…');
      affectedBody.empty();
      affectedPage.text('');
      affectedPrevious.prop('disabled', true);
      affectedNext.prop('disabled', true);
      affectedRequest = $.getJSON(@json(route('buyback.admin.selectors.affected-types')), {
        target_type: $('#target_type').val(),
        target_id: target.val(),
        compression_qualifier: qualifier.val(),
        page: page || 1
      }).done(renderAffectedItems).fail(function (xhr, status) {
        if (status !== 'abort') affectedStatus.text('Affected items could not be loaded.');
      });
    }
    configureTarget();
    if (isTypeTarget()) configureTypeClassification(selectedCompressionState()); else updateGroupQualifier(selectedQualifiers());
    refreshAffectedItems(1);
    target.on('select2:select', function (event) {
      if (isTypeTarget()) {
        target.find(':selected').attr('data-compression-state', event.params.data.compression_state || 'unknown');
        configureTypeClassification(event.params.data.compression_state || null);
      }
      else {
        var available = event.params.data.compression_qualifiers;
        target.find(':selected').attr('data-compression-qualifiers', available ? available.join(',') : 'unknown');
        qualifier.val('ANY');
        updateGroupQualifier(available);
      }
      refreshAffectedItems(1);
    });
    target.on('select2:clear', function () { refreshAffectedItems(1); });
    qualifier.on('change', function () { refreshAffectedItems(1); });
    affectedPrevious.on('click', function () { refreshAffectedItems(currentAffectedPage - 1); });
    affectedNext.on('click', function () { refreshAffectedItems(currentAffectedPage + 1); });
    $('#target_type').on('change', function () { target.val(null).trigger('change'); target.select2('destroy'); configureTarget(); refreshAffectedItems(1); });
    function modifierVisibility() { $('#modifier-value').toggle($('#modifier_operation').val() !== 'INHERIT'); }
    $('#modifier_operation').on('change', modifierVisibility); modifierVisibility();
  }(jQuery));
</script>
@endpush
