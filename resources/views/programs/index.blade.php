@extends('web::layouts.grids.12')

@section('title', 'New Buyback Appraisal')
@section('page_header', 'New Buyback Appraisal')
@section('page_description', 'Choose a Program')

@section('full')
  @if($programs->isEmpty())
    <div class="alert alert-info" role="status">
      <i class="fas fa-info-circle" aria-hidden="true"></i>
      There are no Buyback Programs available right now.
    </div>
  @else
    <div class="row">
      @foreach($programs as $program)
        <div class="col-lg-6 d-flex">
          <div class="card card-outline card-primary flex-fill">
            <div class="card-header">
              <h3 class="card-title">{{ $program->name }}</h3>
            </div>
            <div class="card-body">
              @if($program->description)
                <p>{{ $program->description }}</p>
              @endif

              <dl class="row mb-0">
                <dt class="col-sm-5">Default policy</dt>
                <dd class="col-sm-7">
                  {{ $program->default_acceptance->value === 'ACCEPT' ? 'Accept unless excluded' : 'Exclude unless accepted' }}
                </dd>
                <dt class="col-sm-5">Default reference</dt>
                <dd class="col-sm-7">{{ $program->default_reference_mode->value }}</dd>
                <dt class="col-sm-5">Default adjustment</dt>
                <dd class="col-sm-7">{{ \RandulfTheGrey\Seat\BuybackPrograms\Support\RequesterUi::modifier($program->default_modifier_bps->value) }}</dd>
                <dt class="col-sm-5">Quote validity</dt>
                <dd class="col-sm-7">{{ $program->quote_validity_minutes }} minutes</dd>
              </dl>

              @if($program->contract_instructions)
                <p class="text-muted mt-3 mb-0">
                  <strong>Contract note:</strong> {{ \Illuminate\Support\Str::limit($program->contract_instructions, 180) }}
                </p>
              @endif
            </div>
            <div class="card-footer text-right">
              <a class="btn btn-primary" href="{{ route('buyback.appraisals.create', $program) }}">
                Select Program <i class="fas fa-arrow-right ml-1" aria-hidden="true"></i>
              </a>
            </div>
          </div>
        </div>
      @endforeach
    </div>
  @endif
@stop
