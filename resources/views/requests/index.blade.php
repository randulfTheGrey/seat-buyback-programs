@extends('web::layouts.grids.12')

@section('title', 'My Buybacks')
@section('page_header', 'My Buybacks')
@section('page_description', 'Unsubmitted Quotes and Buyback Request history')

@section('full')
  <div class="card card-outline card-info">
    <div class="card-header">
      <h3 class="card-title">Unsubmitted Quotes</h3>
    </div>
    <div class="card-body p-0">
      <p class="text-muted px-3 pt-3">
        These Quotes are saved and still valid, but they have not been submitted as Buyback Requests.<br>
        <strong>Create a Buyback Request before creating your EVE contract.</strong>
      </p>
      @if($availableQuotes->isNotEmpty())
        <div class="table-responsive">
          <table class="table table-striped mb-0">
            <thead>
              <tr>
                <th>Program</th>
                <th class="text-right">Payable amount</th>
                <th>Expires</th>
                <th class="text-right"><span class="sr-only">Action</span></th>
              </tr>
            </thead>
            <tbody>
              @foreach($availableQuotes as $quote)
                <tr>
                  <td>{{ $quote->program_name_snapshot }}</td>
                  <td class="text-right">{{ \RandulfTheGrey\Seat\BuybackPrograms\Support\RequesterUi::decimal($quote->payable_total) }} ISK</td>
                  <td><time datetime="{{ $quote->expires_at->toIso8601String() }}">{{ $quote->expires_at->format('Y-m-d H:i:s T') }}</time></td>
                  <td class="text-right">
                    <form class="d-inline" method="post" action="{{ route('buyback.quotes.submit', $quote) }}">
                      @csrf
                      <button class="btn btn-sm btn-success" type="submit">Create Buyback Request</button>
                    </form>
                    <a class="btn btn-sm btn-outline-secondary ml-1" href="{{ route('buyback.quotes.show', $quote) }}">View Quote</a>
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @else
        <p class="px-3 pb-3 mb-0">You have no unsubmitted Quotes.</p>
      @endif
    </div>
  </div>

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
