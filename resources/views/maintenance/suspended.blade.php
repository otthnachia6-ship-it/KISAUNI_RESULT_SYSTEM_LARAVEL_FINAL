@extends('maintenance.layout')

@section('title', 'Service suspended')
@section('accent', '#d97706')
@section('accent_soft', '#fef3c7')
@section('accent_ink', '#92400e')
@section('status', 'Service renewal required · Huduma inahitaji kuhuishwa')

@section('english')
    <h1>Service Temporarily Suspended</h1>
    <p>Access to the Kisauni Result System has been temporarily suspended because the annual renewal of the system's <strong>hosting and domain services</strong> is pending.</p>
    <p>The service will be restored as soon as the renewal has been completed. To restore the service, please contact the school administration or the system developer.</p>
@endsection

@section('swahili')
    <h2>Huduma Imesimamishwa kwa Muda</h2>
    <p>Huduma ya Mfumo wa Matokeo wa Kisauni imesimamishwa kwa muda kwa sababu malipo ya kuhuisha huduma za <strong>hosting na domain</strong> za mfumo hayajakamilika.</p>
    <p>Huduma itarejeshwa mara tu malipo ya kuhuisha yatakapokamilika. Ili kurejesha huduma, tafadhali wasiliana na uongozi wa shule au msanidi wa mfumo (Developer).</p>
@endsection

@section('safe')
    <strong>All school data is safe</strong> and will be fully available again once the service is restored.<br>
    <strong>Data zote za shule ziko salama</strong> na zitapatikana kikamilifu huduma itakaporejeshwa.
@endsection

@section('footer')
    Kisauni Result System &middot; Mfumo wa Matokeo wa Kisauni
@endsection
