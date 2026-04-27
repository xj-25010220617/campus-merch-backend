<?php

namespace App\Services\Order;

use App\Models\Product;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function checkAvailability(Product $product, int $quantity): bool
    {
        $available = $product->stock - $product->reserved_stock;
        return $available >= $quantity;
    }

    public function reserve(Product $product, int $quantity): void
    {
        if (!$this->checkAvailability($product, $quantity)) {
            throw ValidationException::withMessages([
                'quantity' => '库存不足，可用库存: ' . ($product->stock - $product->reserved_stock),
            ]);
        }

        $product->increment('reserved_stock', $quantity);
    }

    public function deduct(Product $product, int $quantity): void
    {
        $product->decrement('reserved_stock', $quantity);
        $product->decrement('stock', $quantity);
        $product->increment('sold_stock', $quantity);
    }

    public function release(Product $product, int $quantity): void
    {
        $product->decrement('reserved_stock', $quantity);
    }
}
