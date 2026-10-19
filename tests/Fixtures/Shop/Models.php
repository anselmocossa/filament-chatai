<?php

namespace Filament\ChatAi\Tests\Fixtures\Shop;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

enum OrderStatus: string
{
    case New = 'new';
    case Shipped = 'shipped';
    case Cancelled = 'cancelled';
}

class Product extends Model
{
    protected $table = 'shop_products';

    protected $guarded = [];

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'shop_product_id');
    }
}

class Order extends Model
{
    protected $table = 'shop_orders';

    protected $guarded = [];

    protected $casts = ['status' => OrderStatus::class];

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'shop_order_id');
    }
}

class OrderItem extends Model
{
    protected $table = 'shop_order_items';

    protected $guarded = [];

    public $timestamps = false;

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'shop_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'shop_product_id');
    }
}
