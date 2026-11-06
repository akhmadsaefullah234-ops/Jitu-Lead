<?php

namespace App\Shipping\Carriers;

/**
 * A provider that books under its own order number (Everpro prepaid: "TRD…", whatever client_order_no was sent),
 * so a booked parcel is found again in its order list, and every later call uses that number.
 */
interface ListsOrders
{
    /** @return list<RemoteOrder> the newest orders first, $pages pages of the list */
    public function recentOrders(int $pages = 2): array;

    /** The order with the provider's own number, looked for in the newest $pages pages. */
    public function findOrder(string $orderNo, int $pages = 5): ?RemoteOrder;
}
