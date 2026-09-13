@php($explanationRows = \RandulfTheGrey\Seat\BuybackPrograms\Support\RequesterUi::policyExplanation($policy ?? null))

@if($explanationRows === [])
  <p class="text-muted mb-0">No policy explanation is available for this line.</p>
@else
  <div class="list-group list-group-flush">
    @foreach($explanationRows as $row)
      <div class="list-group-item px-0">
        <strong>{{ $row['label'] }}</strong>
        @if(isset($row['changes']))
          <ul class="mb-0 mt-1">
            @foreach($row['changes'] as $change)
              <li>{{ $change }}</li>
            @endforeach
          </ul>
        @else
          <span class="d-block text-muted">
            {{ $row['acceptance'] }} · {{ $row['reference'] }} · {{ $row['modifier'] }}
          </span>
        @endif
      </div>
    @endforeach
  </div>
@endif
