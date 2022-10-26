<?php

namespace Frc\Brightcove\Models;

use Illuminate\Contracts\Support\Arrayable;

class Job extends BrightcoveModel implements Arrayable
{

    public function vods()
    {
        return $this->api()->live()
            ->hydrateWith(VodJob::class)
            ->get('/jobs/' . $this->id . '/vods');
    }

    public function toArray()
    {
        return $this->getAttributes();
    }
}