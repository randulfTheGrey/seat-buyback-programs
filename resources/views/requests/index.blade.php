@extends('web::layouts.grids.12')

@section('title', 'My Buybacks')
@section('page_header', 'My Buybacks')
@section('page_description', 'Available Quotes and Submitted Buyback Requests')

@section('full')
  @if($availableQuotes->isNotEmpty())
    <div class="card card-outline card-info">
      <div class="card-header">
        <h3 class="card-title">Available Quotes</h3>
      </div>
      <div class="card-body p-0">
        <p class="text-muted px-3 pt-3">
          These unexpired Quotes have not been submitted. Continue a Quote to review it and submit your Buyback Request.
        </p>
        <div class="table-responsive">
          <table class="table table-striped mb-0">
            <thead>
              <tr>
                <th>Quote reference</th>
                <th>Program</th>
                <th class="text-right">Payable amount</th>
                <th>Expires</th>
                <th class="text-right"><span class="sr-only">Action</span></th>
              </tr>
            </thead>
            <tbody>
              @foreach($availableQuotes as $quote)
                <tr>
                  <td><code>{{ $quote->public_id }}</code></td>
                  <td>{{ $quote->program_name_snapshot }}</td>
                  <td class="text-right">{{ \RandulfTheGrey\Seat\BuybackPrograms\Support\RequesterUi::decimal($quote->payable_total) }} ISK</td>
                  <td><time datetime="{{ $quote->expires_at->toIso8601String() }}">{{ $quote->expires_at->format('Y-m-d H:i:s T') }}</time></td>
                  <td class="text-right">
                    <a class="btn btn-sm btn-primary" href="{{ route('buyback.quotes.show', $quote) }}">
                      Continue <i class="fas fa-arrow-right ml-1" aria-hidden="true"></i>
                    </a>
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </div>
    </div>
  @endif

  <div class="card">
    <div class="card-body">
      <p class="text-muted">This history contains your submitted Buyback Requests. Expired and unused Quotes are not included in the history.</p>
      {!! $dataTable->table() !!}
    </div>
  </div>
@stop

@push('javascript')
  {!! $dataTable->scripts() !!}
@endpush
