{{--
    Empty state inside a table body.

    Wraps <x-empty-state> in the full-width row a <tbody> needs, so a screen never
    has to hand-count `colspan` against a header it edited three lines earlier —
    the count is passed once and the padding/borders come from .cell-empty.

    Usage:
        @forelse ($rows as $row) … @empty
            <x-table-empty :columns="7" :title="__('lang_v1.no_sales_yet')"/>
        @endforelse

    `compact` is passed through to <x-empty-state> for a table inside a side
    panel, where the full-height version would make a three-row-tall card taller
    empty than full.
--}}
@props([
    'columns' => 1,
    'icon' => 'box',
    'title' => null,
    'text' => null,
    'compact' => false,
])

<tr>
    <td colspan="{{ $columns }}" class="cell-empty">
        <x-empty-state :icon="$icon" :title="$title" :text="$text" :compact="$compact">{{ $slot }}</x-empty-state>
    </td>
</tr>
