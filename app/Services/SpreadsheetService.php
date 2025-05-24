<?php

namespace App\Services;

use App\Jobs\ProcessProductImage;
use App\Models\Product;
use Illuminate\Support\Facades\Validator;

class SpreadsheetService
{
    public function processSpreadsheet($filePath)
    {
        $products_data = app('importer')->import($filePath);

        foreach ($products_data as $row) {
            $validator = Validator::make($row, [
                'product_code' => 'required|unique:products,code',
                'quantity' => 'required|integer|min:1',
            ]);

            if ($validator->fails()) {
                continue;
            }

            $product = Product::create($validator->validated());

            ProcessProductImage::dispatch($product);
        }
    }
}
