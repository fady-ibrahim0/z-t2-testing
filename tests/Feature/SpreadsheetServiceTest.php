<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Product;
use App\Jobs\ProcessProductImage;
use App\Services\SpreadsheetService;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Queue;
use Tests\Helpers\ProductImportArray;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SpreadsheetServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_rows_are_processed_and_jobs_are_dispatched()
    {
        Queue::fake();
        Storage::fake('local');

        $data = [
            ['product_code' => '9526', 'quantity' => 4],
            ['product_code' => '3107', 'quantity' => 8],
        ];

        $filePath = 'test-products.xlsx';
        Excel::store(new ProductImportArray($data), $filePath, 'local', ExcelFormat::XLSX);

        $service = new SpreadsheetService();
        $service->processSpreadsheet(storage_path("app/{$filePath}"));

        // Assert products created
        $this->assertDatabaseHas('products', ['code' => '9526']);
        $this->assertDatabaseHas('products', ['code' => '3107']);
        $this->assertDatabaseCount('products', 2);

        // Assert jobs dispatched
        Queue::assertPushed(ProcessProductImage::class, 2);
    }

    public function test_invalid_rows_are_skipped()
    {
        Queue::fake();
        Storage::fake('local');

        $data = [
            ['product_code' => '', 'quantity' => 10],     // Missing product_code
            ['product_code' => '6987', 'quantity' => 0],  // quantity < 1
        ];

        $filePath = 'invalid-products.xlsx';
        Excel::store(new ProductImportArray($data), $filePath, 'local', ExcelFormat::XLSX);

        $service = new SpreadsheetService();
        $service->processSpreadsheet(storage_path("app/{$filePath}"));

        $this->assertDatabaseCount('products', 0);
        Queue::assertNothingPushed();

        Storage::delete($filePath);
    }

    public function test_duplicate_product_code_is_rejected()
    {
        Queue::fake();
        Storage::fake('local');

        // Existing product in DB
        Product::create(['code' => '3333', 'quantity' => 20]);

        $data = [
            ['product_code' => '3333', 'quantity' => 15], // Duplicate
            ['product_code' => '4444', 'quantity' => 8],  // Valid
        ];

        $filePath = 'duplicate-products.xlsx';
        Excel::store(new ProductImportArray($data), $filePath, 'local', ExcelFormat::XLSX);

        $service = new SpreadsheetService();
        $service->processSpreadsheet(storage_path("app/{$filePath}"));

        $this->assertDatabaseHas('products', ['code' => '3333', 'quantity' => 20]); // Original
        $this->assertDatabaseHas('products', ['code' => '4444']); // New valid
        $this->assertDatabaseCount('products', 2);

        Queue::assertPushed(ProcessProductImage::class, 1); // Only for 3333

        Storage::delete($filePath);
    }
}
