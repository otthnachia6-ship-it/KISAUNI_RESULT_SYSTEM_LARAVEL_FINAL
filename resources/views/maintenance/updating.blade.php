@extends('maintenance.layout')

@section('head')
    <meta http-equiv="refresh" content="60">
@endsection
@section('title', 'Scheduled maintenance')
@section('status', 'Scheduled maintenance · Matengenezo yaliyopangwa')

@section('english')
    <h1>Scheduled System Maintenance</h1>
    <p>The Kisauni Result System is currently under maintenance while scheduled improvements are carried out. During this time the system is temporarily unavailable.</p>
    <p>The service will resume shortly. Please try again in a few minutes. Thank you for your patience.</p>
@endsection

@section('swahili')
    <h2>Matengenezo Maalum ya Mfumo</h2>
    <p>Mfumo wa Matokeo wa Kisauni uko kwenye matengenezo kwa sasa ili kufanya maboresho yaliyopangwa. Katika kipindi hiki mfumo haupatikani kwa muda.</p>
    <p>Huduma itarejea hivi punde. Tafadhali jaribu tena baada ya dakika chache. Tunashukuru kwa uvumilivu wenu.</p>
@endsection

@section('safe')
    <strong>Your data is safe.</strong> Student records, examination results and user accounts have not been changed.<br>
    <strong>Taarifa zenu ziko salama.</strong> Kumbukumbu za wanafunzi, matokeo ya mitihani na akaunti za watumiaji hazijabadilishwa.
@endsection

@section('footer')
    This page refreshes automatically every minute. &middot; Ukurasa huu unajisasisha wenyewe kila dakika.
@endsection
