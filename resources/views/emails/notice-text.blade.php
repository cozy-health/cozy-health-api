Cozy Health
{!! $heading !!}

@foreach($paragraphs as $paragraph)
{!! $paragraph !!}

@endforeach
@foreach($actions as $action)
{!! $action['label'] !!}: {!! $action['url'] !!}

@endforeach
Take care,
The Cozy Health team
team@cozyhealth.org
