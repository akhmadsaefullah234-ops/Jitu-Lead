<?php

namespace App\Filament\Support;

use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\HtmlString;

/**
 * Short table cards on phones: the columns passed through secondary() hide behind a "Lihat detail" toggle (the
 * column made by toggle()) while the table is stacked into cards, under 640px. Wider screens show every column as
 * before, and the toggle column is hidden there (public/css/jitu/responsive.css, "Lihat detail").
 *
 * The toggle is a checkbox inside the card, so it keeps its state when Livewire refreshes the table and needs no
 * script.
 */
final class MobileDetails
{
    /**
     * @template T of Column
     *
     * @param  T  $column
     * @return T
     */
    public static function secondary(Column $column): Column
    {
        return $column->extraCellAttributes(['class' => 'jl-detail'], merge: true);
    }

    public static function toggle(): TextColumn
    {
        return TextColumn::make('jl_details')
            ->label('')
            ->state('detail')
            ->disabledClick()
            ->formatStateUsing(fn () => new HtmlString('<label class="jl-detail-btn"><input type="checkbox" class="jl-detail-check">'
                .'<span class="jl-detail-more">Lihat detail</span><span class="jl-detail-less">Tutup detail</span></label>'))
            ->extraHeaderAttributes(['class' => 'jl-detail-toggle'])
            ->extraCellAttributes(['class' => 'jl-detail-toggle']);
    }
}
