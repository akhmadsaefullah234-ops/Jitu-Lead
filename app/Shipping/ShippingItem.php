<?php

namespace App\Shipping;

use App\Models\Property;
use App\Support\PlainData;

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

    /**
     * Every constructor value as plain data, for a cache that keeps no objects: the product by its id.
     *
     * @return array{product_id: ?int, name: string, variant: ?string, quantity: int}
     */
    public function toData(): array
    {
        return ['product_id' => $this->product?->getKey(), 'name' => $this->name, 'variant' => $this->variant, 'quantity' => $this->quantity];
    }

    /**
     * The item toData() wrote. The product is taken from $products (keyed by id) when given, else looked up in the
     * current tenant's catalogue; a product deleted since leaves the item with its name only, as for a product
     * that was never in the catalogue.
     *
     * @param  array<int, Property>|null  $products
     *
     * @throws \InvalidArgumentException when a value is missing or of another type
     */
    public static function fromData(array $data, ?array $products = null): self
    {
        $id = PlainData::nullableInt($data, 'product_id');
        $product = $id === null ? null : ($products !== null ? ($products[$id] ?? null) : Property::query()->find($id));

        return new self($product, PlainData::string($data, 'name'), PlainData::nullableString($data, 'variant'), PlainData::int($data, 'quantity'));
    }

    /** @return array{product_id: ?int, name: string, variant: ?string, quantity: int, price: ?int} */
    public function toArray(): array
    {
        return ['product_id' => $this->product?->getKey(), 'name' => $this->name, 'variant' => $this->variant, 'quantity' => $this->quantity, 'price' => $this->price()];
    }
}
