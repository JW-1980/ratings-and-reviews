{{--
    <x-rr::editor name="body" :tools="['bold', 'italic', 'link']" :value="old('body')" :label="__('…')" />
    Without JavaScript: a plain textarea (the server turns plain text into paragraphs).
    With JavaScript (resources/js/blade/enhance.js): the shared Tiptap editor with the
    section's tools; the textarea stays in the form, hidden, and carries the HTML.
--}}
@props([
    'name' => 'body',
    'id' => null,
    'value' => '',
    'tools' => [],
    'locked' => [],
    'label' => null,
    'placeholder' => null,
    'describedBy' => null,
    'rows' => 5,
    'required' => false,
    'minlength' => null,
    'maxlength' => null,
    'invalid' => false,
])
@php
    $id ??= 'rr-editor-'.\Illuminate\Support\Str::random(8);
    $label ??= __('ratings-and-reviews::ui.editor.label');
@endphp
<div {{ $attributes->merge(['class' => 'rr-editor'.($invalid ? ' is-invalid' : '')]) }}
    data-rr-editor
    data-tools='@json(array_values($tools))'
    data-locked='@json((object) $locked)'
    data-label="{{ $label }}"
    @if ($placeholder) data-placeholder="{{ $placeholder }}" @endif
    @if ($describedBy) data-described-by="{{ $describedBy }}" @endif
>
    <label for="{{ $id }}" class="rr-visually-hidden">{{ $label }}</label>
    <textarea id="{{ $id }}" name="{{ $name }}" class="rr-textarea" rows="{{ $rows }}"
        @if ($placeholder) placeholder="{{ $placeholder }}" @endif
        @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
        @if ($required) required @endif
        @if ($minlength) minlength="{{ $minlength }}" @endif
        @if ($maxlength) maxlength="{{ $maxlength }}" @endif
        @if ($invalid) aria-invalid="true" @endif
    >{{ $value }}</textarea>
</div>
