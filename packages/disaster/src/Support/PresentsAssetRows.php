<?php

namespace Lumina\Disaster\Support;

use Illuminate\Database\Eloquent\Model;

final class PresentsAssetRows
{
    /**
     * @param  list<string>  $attributes
     * @return array<string, mixed>
     */
    public static function row(Model $model, array $attributes): array
    {
        $row = $model->only($attributes);
        $coords = GeometryReader::pointCoordinates($model->getAttribute('geom'));
        if ($coords !== null) {
            $row['latitude'] = $coords['latitude'];
            $row['longitude'] = $coords['longitude'];
        }

        return $row;
    }
}
