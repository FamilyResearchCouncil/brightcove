<?php namespace Frc\Brightcove\Models;

use Frc\Brightcove\Brightcove;
use Frc\Oracle\Models\Frc\Item;
use Frc\Oracle\Models\Frc\ItemAttribute;
use Frc\Oracle\Models\Frc\ItemPublication;
use Frc\Oracle\Models\Frc\RelatedItem;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $created_at
 */
class Video extends BrightcoveModel
{
    protected $connection = 'frc';

    /**********************************************
     * methods
     **********************************************/
    /**
     * Accessor for $this->created_at
     */
    public function createdAt(): Attribute
    {
        return Attribute::get(function ($value) {
            return Carbon::parse($value);
        });
    }

    public function addToFolder($folder_id)
    {
        return Brightcove::addVideoToFolder($this->id, $folder_id);
    }

    public function isNew()
    {
        return ItemAttribute::connection($this->connection)->where([
                ['attribute_code', 'BRIGHTCOVE'],
                ['attribute_option', $this->id],
            ])->exists() === false;
    }

    public function exists()
    {
        return $this->isNew() === false;
    }


    public function save()
    {
        if ($this->exists()) {
            return;
        }

        $publication_item = $this->getPublicationItem();


        $lr_item = $publication_item->item;

        $ef_item = $this->createEfItem($lr_item);


        //  b. create item_attribute for new EF item under
        //      'brightcove' attribute code using brightcove id
        $brightcove_attribute = ItemAttribute::create([
            'item_attribute_id' => null,
            'item_code'         => $ef_item->item_code,
            'attribute_code'    => 'BRIGHTCOVE',
            'attribute_option'  => $this->id
        ]);

        ItemAttribute::create([
            'item_attribute_id' => null,
            'item_code'         => $ef_item->item_code,
            'attribute_code'    => 'CHECKOUT',
            'attribute_option'  => 'CP_DOWNLOAD_ONLY'
        ]);

        ItemAttribute::create([
            'item_attribute_id' => null,
            'item_code'         => $ef_item->item_code,
            'attribute_code'    => 'FILETYPE',
            'attribute_option'  => 'mp3'
        ]);

        // 3. add RELATED_ITEM to LR item
        //  a. set RELATED_ITEM_CODE = EF item_code
        //  a. set RELATIONSHIP_CODE = 'WEB_VIDEO_BRIGHTCOVE'
        RelatedItem::connection($this->connection)->create([
            'item_code'         => $lr_item->item_code,
            'related_item_code' => $ef_item->item_code,
            'relationship_code' => 'WEB_VIDEO_BRIGHTCOVE',
            'end_date'          => null,
        ]);

        notify("Brightcove video id stored in frc database", [
            'Brightcove Attribute' => $brightcove_attribute->toArray(),
            '---',
            'Item Publication'     => $publication_item->toArray(),
            '---',
            'EF Item Created'      => $ef_item->toArray()
        ]);
    }

    public function getPublicationItem()
    {
        // 1. find the item_publication for the date in question (date from brightcove video)
        return ItemPublication::connection($this->connection)->where([
            ['publication_date', today()],
            ['item_code', 'like', 'LR%'],
            ['publication_code', 'FRCCOM'],
        ])->sole();
    }

    /**
     * create EF item
     * use LR item code from publication as 'master_item_code'
     */
    public function createEfItem(mixed $lr_item)
    {
        return Item::connection($this->connection)->create([
            'item_type'        => 'EF',
            'item_desc'        => "(Download Video) $lr_item->item_desc",
            'master_item_code' => $lr_item->item_code
        ]);
    }
}
