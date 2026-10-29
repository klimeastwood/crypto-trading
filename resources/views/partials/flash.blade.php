@if (session('status'))
    <div class="flash flash-success">{{ session('status') }}</div>
@endif

@if ($errors->any())
    <div class="flash flash-error">
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
