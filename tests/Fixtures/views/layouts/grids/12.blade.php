<!doctype html>
<html lang="en">
<head>
@stack('head')
</head>
<body>
@if(isset($errors) && $errors->any())
<div class="alert alert-danger" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif
@yield('full')
@stack('javascript')
</body>
</html>
