<?php namespace Frc\Brightcove\Models;

class Playlist extends BrightcoveModel
{
    protected $data_key = 'items';


    public function videos($query = [])
    {
         $api = clone($this->api())->playlists($this->id);

         $api->hydrateWith(\App\Models\Brightcove\Video::class, null);

         $path = $api->getPath();

         $api->path("$path/videos");

         return $api;
    }
}
