{{--
    The card a saved-card request will use, filled from the selected <option>
    by the script at the bottom of the cabinet. Showing the requisites here is
    the point of a saved card: the client confirms the exact card the operator
    will check instead of retyping a number that is already on file.
--}}
<div class="saved-card" id="{{ $id }}" hidden>
    <div class="saved-card-head">
        <span class="saved-card-brand" data-card-brand></span>
        <span class="saved-card-note">Сохранённая карта</span>
    </div>

    <div class="saved-card-number" data-card-masked></div>

    <div class="saved-card-meta">
        <span data-card-holder></span>
        <span data-card-expiry></span>
    </div>
</div>
