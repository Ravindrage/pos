<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryMovement;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::with('category')->orderBy('name');

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        if ($request->filled('barcode')) {
            $query->where('barcode', $request->string('barcode'));
        }

        if ($request->boolean('low_stock')) {
            $query->whereColumn('stock', '<=', 'min_stock');
        }

        return $query->paginate($request->integer('per_page', 25));
    }

    public function show(Product $product)
    {
        return $product->load('category');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'store_id' => ['nullable', 'exists:stores,id'],
            'sku' => ['nullable', 'string', 'max:100'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'unit' => ['nullable', 'string', 'max:50'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'tax_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'min_stock' => ['nullable', 'integer', 'min:0'],
        ]);

        $product = DB::transaction(function () use ($data) {
            $product = Product::create($data);

            // Opening stock is itself recorded as an inventory movement,
            // never just written silently into `stock`.
            if (($data['stock'] ?? 0) > 0) {
                InventoryMovement::create([
                    'store_id' => $product->store_id,
                    'product_id' => $product->id,
                    'type' => InventoryMovement::TYPE_ADJUSTMENT,
                    'quantity' => $data['stock'],
                    'reference_type' => Product::class,
                    'reference_id' => $product->id,
                    'note' => 'Opening stock',
                ]);
            }

            return $product;
        });

        return response()->json($product, 201);
    }

    public function update(Request $request, Product $product)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'store_id' => ['nullable', 'exists:stores,id'],
            'sku' => ['nullable', 'string', 'max:100'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'unit' => ['nullable', 'string', 'max:50'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'selling_price' => ['sometimes', 'numeric', 'min:0'],
            'tax_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'min_stock' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        // Note: `stock` is deliberately not editable here directly — use
        // /products/{id}/adjust-stock so every change goes through the ledger.
        $product->update($data);

        return response()->json($product);
    }

    /**
     * Adjust stock explicitly (purchase received, stock take, damage, etc.)
     * — always via the inventory_movements ledger, never a raw overwrite.
     */
    public function adjustStock(Request $request, Product $product)
    {
        $data = $request->validate([
            'type' => ['required', 'in:purchase,return,adjustment,damage'],
            'quantity' => ['required', 'integer'], // positive or negative
            'note' => ['nullable', 'string'],
        ]);

        DB::transaction(function () use ($product, $data) {
            InventoryMovement::create([
                'store_id' => $product->store_id,
                'product_id' => $product->id,
                'type' => $data['type'],
                'quantity' => $data['quantity'],
                'reference_type' => Product::class,
                'reference_id' => $product->id,
                'note' => $data['note'] ?? null,
            ]);

            $product->increment('stock', $data['quantity']);
        });

        return response()->json($product->fresh());
    }

    public function destroy(Product $product)
    {
        $product->delete();

        return response()->json(null, 204);
    }
}
