{{-- Strings and settings for resources/js/blade/enhance.js, printed once per page. --}}
@once
    <script type="application/json" id="rr-messages">@json(trans('ratings-and-reviews::ui'))</script>
    <script type="application/json" id="rr-config">@json($rr['clientConfig']())</script>
    <div class="rr-toast-region" data-rr-toasts aria-live="polite"></div>
@endonce
