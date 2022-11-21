<?php namespace Frc\Brightcove\Models;

use Frc\Oracle\Models\Frc\ItemAttribute;

class Playlist extends BrightcoveModel
{
    protected $data_key = 'items';


    public function videos($query = [])
    {
        $api = clone ($this->api())->playlists($this->id);

        $api->hydrateWith(\App\Models\Brightcove\Video::class, null);

        $path = $api->getPath();

        $api->path("$path/videos");

        return $api;
    }

    public function getNewVideoIds()
    {
        $existing_ids = ItemAttribute::connection('frc')
            ->where('attribute_code', 'BRIGHTCOVE')
            ->whereIn('attribute_option', $this->video_ids)
            ->select('attribute_option')
            ->get()->pluck('attribute_option')
            ->toArray();


        return array_diff($this->video_ids, $existing_ids);
    }
}
