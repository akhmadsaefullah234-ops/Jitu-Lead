<?php

namespace App\Shipping;

use App\Models\Property;

/** One line of an order: a catalogue product (or just a name when it is not in the catalogue), its variant and how many. */
final class ShippingItem
{
    public function __construct(
        public readonly ?Property $product,
        public readonly string $name,
        public readonly ?string $variant,
        public readonly int $quantity,
    ) {}

    public function price(): ?int
    {
        return $this->product?->price_from !== null ? (int) $this->product->price_from : null;
    }

    /** @return array{product_id: ?int, name: string, variant: ?string, quantity: int, price: ?int} */
    public function toArray(): array
    {
        return ['product_id' => $this->product?->getKey(), 'name' => $this->name, 'variant' => $this->variant, 'quantity' => $this->quantity, 'price' => $this->price()];
    }
}
