<div class="table-responsive">
  <table class="table table-striped mb-0">
    <thead>
      <tr><th>Item</th><th class="text-right">Quantity</th><th>Reference</th><th class="text-right">Reference value</th><th>Adjustment</th><th class="text-right">Unit price</th><th class="text-right">Line total</th><th>Why?</th>@if($showPricingProvenance ?? false)<th>Historical pricing source</th>@endif</tr>
    </thead>
    <tbody>
    @foreach($items as $item)
      <tr>
        <td>{{ $item->type_name }}</td>
        <td class="text-right">{{ number_format($item->quantity) }}</td>
        <td>{{ $item->reference_mode->value }}</td>
        <td class="text-right">{{ \RandulfTheGrey\Seat\BuybackPrograms\Support\RequesterUi::decimal($item->reference_unit_price) }} ISK</td>
        <td>{{ \RandulfTheGrey\Seat\BuybackPrograms\Support\RequesterUi::modifier($item->effective_modifier_bps->value) }}</td>
        <td class="text-right">{{ \RandulfTheGrey\Seat\BuybackPrograms\Support\RequesterUi::decimal($item->final_unit_price) }} ISK</td>
        <td class="text-right"><strong>{{ \RandulfTheGrey\Seat\BuybackPrograms\Support\RequesterUi::decimal($item->line_total) }} ISK</strong></td>
        <td>
          <details>
            <summary class="text-primary">Why this price?</summary>
            <div class="mt-2" style="min-width: 18rem">
              @include('seat-buyback-programs::partials.policy-explanation', ['policy' => $item->policy_snapshot])
            </div>
          </details>
        </td>
        @if($showPricingProvenance ?? false)
          <td>
            @php($pricing = is_array($item->pricing_snapshot) ? $item->pricing_snapshot : [])
            <strong>{{ $pricing['resolution'] ?? $item->reference_resolution->value }}</strong>
            @if(isset($pricing['components']) && is_array($pricing['components']))
              <ul class="mb-0 pl-3">
                @foreach($pricing['components'] as $component)
                  @if(is_array($component))
                    <li>
                      {{ $component['mode'] ?? 'Reference' }}:
                      {{ $component['provider_instance_name'] ?? ('Provider #' . ($component['provider_instance_id'] ?? '?')) }}
                      @if(isset($component['reference_price']))
                        — {{ \RandulfTheGrey\Seat\BuybackPrograms\Support\RequesterUi::decimal((string) $component['reference_price']) }} ISK
                      @endif
                    </li>
                  @endif
                @endforeach
              </ul>
            @endif
          </td>
        @endif
      </tr>
    @endforeach
    </tbody>
  </table>
</div>
