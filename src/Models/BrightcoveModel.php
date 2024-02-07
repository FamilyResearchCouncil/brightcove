<?php namespace Frc\Brightcove\Models;

use Frc\Brightcove\BrightcoveApi;
use Illuminate\Database\Eloquent\Concerns\HasAttributes;
use Illuminate\Support\Str;

class BrightcoveModel implements \ArrayAccess
{
    use HasAttributes;

    public ?BrightcoveApi $api;
    protected $data_key;

    public function __construct($attributes = [], $api_client = null)
    {
        $this->fill($attributes);

        $this->api = $api_client ?? app()->make('brightcove');
        
        $this->syncOriginal();
    }


    public function api()
    {
        return $this->api;
    }


    public function getResponseDataKey()
    {
        return $this->data_key ?? (string)Str::of(class_basename(static::class))->snake()->plural();
    }

    /*******************************************************
     * overrides for HasAttributes
     ******************************************************/

    public function __get($name)
    {
        return $this->getAttribute($name);
    }

    public function __set($name, $value)
    {
        $this->setAttribute($name, $value);
    }

    public function fill(array $attributes)
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }

        return $this;
    }

    public function refresh()
    {
        return $this->fill(
            $this->api()->videos()->get($this->id)->getAttributes()
        );
    }

    public function usesTimestamps()
    {
        return false;
    }

    public function getIncrementing()
    {
        return null;
    }

    public function getVisible()
    {
        return [];
    }

    public function getHidden()
    {
        return [];
    }

    public function relationLoaded()
    {
        return false;
    }

    public function toArray()
    {
        return $this->attributesToArray();
    }


    public function offsetExists($offset): bool
    {
        return isset($this->attributes[$offset]);
    }

    public function offsetGet($offset): mixed
    {
        return $this->attributes[$offset];
    }

    public function offsetSet($offset, $value): void
    {
        $this->attributes[$offset] = $value;
    }

    public function offsetUnset($offset): void
    {
        unset($this->attributes[$offset]);
    }
}
