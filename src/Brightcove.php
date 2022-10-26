<?php namespace App\Facades;

use Frc\Brightcove\BrightcoveApi;

/**
 * Class PayrixFrc
 * @package App\Facades
 * @mixin BrightcoveApi
 */
class Brightcove extends \Illuminate\Support\Facades\Facade
{
    protected static function getFacadeAccessor()
    {
        return 'brightcove';
    }

}
