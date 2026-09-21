<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleController extends Controller
{
    public function index(Request $request)
    {
        $query = Sale::with(['items.product', 'payments', 'customer'])->latest();

        if ($request->filled('store_id')) {
            $query->where('store_id', $request->integer('store_id'));
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->date('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->date('to'));
        }

        return $query->paginate($request->integer('per_page', 25));
    }

    public function show(Sale $sale)
    {
        return $sale->load(['items.product', 'payments', 'customer', 'cashier']);
    }

    /**
     * The core POS billing flow, exactly as laid out in the architecture doc:
     *
     *   BEGIN
     *     Create sale
     *     Create sale items
     *     Create payment
     *     Create inventory movements
     *     Update stock
     *     Generate invoice number
     *   COMMIT (or ROLLBACK on any failure — e.g. insufficient stock)
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_id' => ['nullable', 'exists:customers,id'],
            'store_id' => ['nullable', 'exists:stores,id'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['required', 'in:cash,card,upi,wallet'],
            'amount_paid' => ['required', 'numeric', 'min:0'],
            'device_id' => ['nullable', 'string'],
            'uuid' => ['nullable', 'uuid'], // client-generated, for offline sync idempotency
        ]);

        $sale = DB::transaction(function () use ($data, $request) {
            $storeId = $data['store_id'] ?? $request->user()->store_id;

            // Lock the product rows so two simultaneous sales can't both
            // pass a stock check against the same stale quantity.
            $productIds = collect($data['items'])->pluck('product_id')->unique();
            $products = Product::whereIn('id', $productIds)->lockForUpdate()->get()->keyBy('id');

            $subtotal = 0;
            $taxTotal = 0;
            $lineDiscountTotal = 0;

            foreach ($data['items'] as $item) {
                $product = $products->get($item['product_id']);

                if (! $product) {
                    throw ValidationException::withMessages([
                        'items' => ["Product {$item['product_id']} not found."],
                    ]);
                }

                if ($product->stock < $item['quantity']) {
                    throw ValidationException::withMessages([
                        'items' => ["Insufficient stock for {$product->name} (have {$product->stock}, need {$item['quantity']})."],
                    ]);
                }

                $lineSubtotal = $item['unit_price'] * $item['quantity'];
                $lineDiscount = $item['discount'] ?? 0;
                $lineTax = ($lineSubtotal - $lineDiscount) * ($product->tax_percent / 100);

                $subtotal += $lineSubtotal;
                $lineDiscountTotal += $lineDiscount;
                $taxTotal += $lineTax;
            }

            $overallDiscount = $data['discount'] ?? 0;
            $total = $subtotal - $lineDiscountTotal - $overallDiscount + $taxTotal;
            $total = max($total, 0);

            $sale = Sale::create([
                'uuid' => $data['uuid'] ?? null,
                'store_id' => $storeId,
                'user_id' => $request->user()->id,
                'customer_id' => $data['customer_id'] ?? null,
                'invoice_number' => $this->nextInvoiceNumber($request->user()->business_id),
                'subtotal' => $subtotal,
                'discount' => $lineDiscountTotal + $overallDiscount,
                'tax' => $taxTotal,
                'total' => $total,
                'amount_paid' => $data['amount_paid'],
                'balance_due' => max($total - $data['amount_paid'], 0),
                'status' => 'completed',
                'device_id' => $data['device_id'] ?? null,
            ]);

            foreach ($data['items'] as $item) {
                $product = $products->get($item['product_id']);
                $lineSubtotal = $item['unit_price'] * $item['quantity'];
                $lineDiscount = $item['discount'] ?? 0;
                $lineTax = ($lineSubtotal - $lineDiscount) * ($product->tax_percent / 100);

                $sale->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'discount' => $lineDiscount,
                    'tax' => $lineTax,
                    'total' => $lineSubtotal - $lineDiscount + $lineTax,
                ]);

                // Ledger entry — never a silent stock overwrite.
                InventoryMovement::create([
                    'store_id' => $storeId,
                    'product_id' => $product->id,
                    'type' => InventoryMovement::TYPE_SALE,
                    'quantity' => -$item['quantity'],
                    'reference_type' => Sale::class,
                    'reference_id' => $sale->id,
                ]);

                $product->decrement('stock', $item['quantity']);
            }

            $sale->payments()->create([
                'method' => $data['payment_method'],
                'amount' => $data['amount_paid'],
            ]);

            return $sale;
        });

        return response()->json($sale->load(['items.product', 'payments']), 201);
    }

    /**
     * Void a sale: reverses stock via a `return` movement rather than
     * deleting history, so the ledger stays a true audit trail.
     */
    public function void(Sale $sale)
    {
        if ($sale->status === 'void') {
            return response()->json(['message' => 'Sale is already void.'], 422);
        }

        DB::transaction(function () use ($sale) {
            foreach ($sale->items as $item) {
                InventoryMovement::create([
                    'store_id' => $sale->store_id,
                    'product_id' => $item->product_id,
                    'type' => InventoryMovement::TYPE_RETURN,
                    'quantity' => $item->quantity,
                    'reference_type' => Sale::class,
                    'reference_id' => $sale->id,
                    'note' => 'Sale voided',
                ]);

                $item->product()->increment('stock', $item->quantity);
            }

            $sale->update(['status' => 'void']);
        });

        return response()->json($sale->fresh(['items', 'payments']));
    }

    private function nextInvoiceNumber(int $businessId): string
    {
        $count = Sale::withoutGlobalScope('tenant')
            ->where('business_id', $businessId)
            ->count();

        return sprintf('INV-%06d', $count + 1);
    }
}
