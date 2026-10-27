<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SalesOrderLine;
use App\Models\SalesReturnLine;

final class SalesMarginReportService
{
    /**
     * @return array{
     *     from: string|null,
     *     to: string|null,
     *     group_by: string,
     *     rows: array<int, array{
     *         key: string,
     *         label: string,
     *         quantity: float,
     *         revenue: float,
     *         cost: float,
     *         margin: float,
     *         margin_pct: float|null,
     *     }>,
     *     totals: array{
     *         quantity: float,
     *         revenue: float,
     *         cost: float,
     *         margin: float,
     *         margin_pct: float|null,
     *     },
     *     lines_missing_cost: int,
     * }
     */
    public function salesMargin(
        ?string $from = null,
        ?string $to = null,
        string $groupBy = 'item',
        ?string $operatingUnitId = null,
    ): array {
        $query = SalesOrderLine::query()
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_lines.sales_order_id')
            ->leftJoin('stock_lots', 'stock_lots.id', '=', 'sales_order_lines.stock_lot_id')
            ->leftJoin('inventory_items', 'inventory_items.id', '=', 'sales_order_lines.inventory_item_id')
            ->leftJoin('item_categories', 'item_categories.id', '=', 'inventory_items.category_id')
            ->leftJoin('clients', 'clients.id', '=', 'sales_orders.client_id')
            ->leftJoin('entities', 'entities.id', '=', 'clients.entity_id')
            ->leftJoin('bundles', 'bundles.id', '=', 'sales_order_lines.bundle_id')
            ->whereNull('sales_orders.deleted_at')
            ->whereNotIn('sales_orders.status', ['pending_approval', 'rejected', 'draft'])
            ->where(function ($q): void {
                $q->whereNull('sales_orders.buyer_type')
                    ->orWhere('sales_orders.buyer_type', '!=', 'internal_unit');
            });

        if ($from) {
            $query->whereDate('sales_orders.created_at', '>=', $from);
        }

        if ($to) {
            $query->whereDate('sales_orders.created_at', '<=', $to);
        }

        if ($operatingUnitId !== null) {
            $query->where('sales_orders.operating_unit_id', $operatingUnitId);
        }

        $lines = $query->select([
            'sales_order_lines.id as line_id',
            'sales_order_lines.line_type',
            'sales_order_lines.description as line_description',
            'sales_order_lines.quantity',
            'sales_order_lines.unit_price',
            'sales_order_lines.discount_amount',
            'sales_order_lines.unit_cost_actual as line_unit_cost_actual',
            'sales_order_lines.stock_lot_id',
            'sales_order_lines.inventory_item_id',
            'sales_order_lines.bundle_id',
            'stock_lots.unit_cost as lot_unit_cost',
            'inventory_items.id as item_id',
            'inventory_items.name as item_name',
            'item_categories.id as category_id',
            'item_categories.name as category_name',
            'sales_orders.id as sales_order_id',
            'sales_orders.order_number',
            'sales_orders.invoice_discount',
            'sales_orders.client_id',
            'entities.name as client_name',
            'bundles.id as b_id',
            'bundles.name as bundle_name',
        ])->get();

        $linesMissingCost = 0;
        $totalQuantity = 0.0;
        $totalRevenue = 0.0;
        $totalCost = 0.0;
        $groups = [];

        // Revenue is what the customer actually pays for the line: its own discount
        // off, and its share of the invoice discount, allocated by line net (BR-INV-017).
        $orderSubtotals = [];
        foreach ($lines as $line) {
            $orderSubtotals[$line->sales_order_id] = ($orderSubtotals[$line->sales_order_id] ?? 0.0)
                + (float) $line->quantity * (float) $line->unit_price - (float) $line->discount_amount;
        }

        // BR-INV-013: returns net out of the line they were raised against —
        // their quantity, the refunded amount, and the returned units' cost
        // (damaged goods' cost moves to waste, so it leaves COGS too).
        $returnedByLine = $lines->isEmpty() ? collect() : SalesReturnLine::query()
            ->whereIn('sales_order_line_id', $lines->pluck('line_id')->all())
            ->selectRaw('sales_order_line_id, SUM(quantity) as qty, SUM(amount) as amt')
            ->groupBy('sales_order_line_id')
            ->get()
            ->keyBy('sales_order_line_id');

        foreach ($lines as $line) {
            $returned = $returnedByLine->get($line->line_id);
            $returnedQuantity = $returned !== null ? (float) $returned->qty : 0.0;
            $quantity = (float) $line->quantity - $returnedQuantity;
            $lineNet = (float) $line->quantity * (float) $line->unit_price - (float) $line->discount_amount;
            $subtotal = $orderSubtotals[$line->sales_order_id] ?? 0.0;
            $invoiceShare = $subtotal > 0 ? (float) $line->invoice_discount * $lineNet / $subtotal : 0.0;
            $revenue = $lineNet - $invoiceShare - ($returned !== null ? (float) $returned->amt : 0.0);

            // The line's own actual cost is what checkout recorded from the stock really issued
            // (possibly across several lots, and the only cost bundle lines carry). The lot's cost is
            // only a fallback for lines that predate it.
            $costPerUnit = null;
            if ($line->line_unit_cost_actual !== null && (float) $line->line_unit_cost_actual > 0) {
                $costPerUnit = (float) $line->line_unit_cost_actual;
            } elseif ($line->lot_unit_cost !== null && (float) $line->lot_unit_cost > 0) {
                $costPerUnit = (float) $line->lot_unit_cost;
            }

            if ($costPerUnit === null) {
                $cost = 0.0;
                $linesMissingCost++;
            } else {
                $cost = $quantity * $costPerUnit;
            }

            $totalQuantity += $quantity;
            $totalRevenue += $revenue;
            $totalCost += $cost;

            [$key, $label] = match ($groupBy) {
                'category' => [
                    $line->category_id ? (string) $line->category_id : 'uncategorized',
                    $line->category_name ?? 'بدون فئة',
                ],
                'client' => [
                    $line->client_id ? (string) $line->client_id : 'walk_in',
                    $line->client_name ?? 'عميل نقدي',
                ],
                'sale' => [
                    (string) $line->sales_order_id,
                    $line->order_number ?? 'طلب غير معروف',
                ],
                default => [
                    $line->inventory_item_id ? (string) $line->inventory_item_id : ($line->bundle_id ? (string) $line->bundle_id : ($line->line_description ?: 'unknown_item')),
                    $line->item_name ?? $line->bundle_name ?? $line->line_description ?? 'صنف غير محدد',
                ],
            };

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'key' => $key,
                    'label' => $label,
                    'quantity' => 0.0,
                    'revenue' => 0.0,
                    'cost' => 0.0,
                ];
            }

            $groups[$key]['quantity'] += $quantity;
            $groups[$key]['revenue'] += $revenue;
            $groups[$key]['cost'] += $cost;
        }

        $rows = [];
        foreach ($groups as $group) {
            $revenue = round($group['revenue'], 2);
            $cost = round($group['cost'], 2);
            $margin = round($revenue - $cost, 2);
            $marginPct = $revenue > 0 ? round(($margin / $revenue) * 100, 2) : null;

            $rows[] = [
                'key' => $group['key'],
                'label' => $group['label'],
                'quantity' => round($group['quantity'], 2),
                'revenue' => $revenue,
                'cost' => $cost,
                'margin' => $margin,
                'margin_pct' => $marginPct,
            ];
        }

        usort($rows, fn (array $a, array $b): int => $b['margin'] <=> $a['margin']);

        $totalRevenueRounded = round($totalRevenue, 2);
        $totalCostRounded = round($totalCost, 2);
        $totalMargin = round($totalRevenueRounded - $totalCostRounded, 2);
        $totalMarginPct = $totalRevenueRounded > 0
            ? round(($totalMargin / $totalRevenueRounded) * 100, 2)
            : null;

        return [
            'from' => $from,
            'to' => $to,
            'group_by' => $groupBy,
            'rows' => $rows,
            'totals' => [
                'quantity' => round($totalQuantity, 2),
                'revenue' => $totalRevenueRounded,
                'cost' => $totalCostRounded,
                'margin' => $totalMargin,
                'margin_pct' => $totalMarginPct,
            ],
            'lines_missing_cost' => $linesMissingCost,
        ];
    }
}
